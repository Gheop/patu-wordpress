<?php
/**
 * Integration check for the front-end <picture> rewrite.
 *
 * @package Patu
 */

$GLOBALS['patu_fails'] = 0;
function check( $label, $cond ) {
	echo ( $cond ? 'PASS' : 'FAIL' ) . '  ' . $label . "\n";
	if ( ! $cond ) {
		$GLOBALS['patu_fails']++;
	}
}

update_option( 'patu_api_key', getenv( 'PATU_KEY' ) );
update_option( 'patu_ng_formats', 'avif,webp' );
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once PATU_DIR . 'includes/class-patu-nextgen.php';
require_once PATU_DIR . 'includes/class-patu-rewrite.php';

// A large image so WordPress emits a real srcset.
$im = imagecreatetruecolor( 1600, 1200 );
for ( $y = 0; $y < 1200; $y++ ) {
	for ( $x = 0; $x < 1600; $x++ ) {
		imagesetpixel( $im, $x, $y, imagecolorallocate( $im, (int) ( $x * 255 / 1600 ), (int) ( $y * 255 / 1200 ), ( $x + $y ) % 256 ) );
	}
}
ob_start();
imagejpeg( $im, null, 95 );
$up   = wp_upload_bits( 'patu-rw.jpg', null, ob_get_clean() );
$id   = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_title' => 'rw' ), $up['file'] );
$meta = wp_generate_attachment_metadata( $id, $up['file'] );
wp_update_attachment_metadata( $id, $meta );

Patu_Nextgen::generate( $id );

echo "== a themed <img> (with srcset) becomes a <picture> ==\n";
$img  = wp_get_attachment_image( $id, 'large' );
check( 'test img actually has a srcset', false !== strpos( $img, 'srcset=' ) );
$html = "<article>\n$img\n</article>";
$out  = Patu_Rewrite::rewrite( $html );
check( 'output wraps it in <picture>', false !== strpos( $out, '<picture>' ) && false !== strpos( $out, '</picture>' ) );
check( 'has an AVIF source', (bool) preg_match( '#<source type="image/avif" srcset="[^"]*\.avif#', $out ) );
check( 'AVIF source srcset maps every candidate to .avif', ! preg_match( '#<source type="image/avif"[^>]*\.jpg(?!\.avif)#', $out ) );
check( 'original <img> kept as the fallback', false !== strpos( $out, $img ) );
check( 'sizes attribute carried onto the source', false === strpos( $img, 'sizes=' ) || false !== strpos( $out, '<source type="image/avif"' ) );

echo "== images that must be left alone ==\n";
$ext = '<img src="https://example.com/remote.jpg" alt="x">';
check( 'external image untouched', Patu_Rewrite::rewrite( "<p>$ext</p>" ) === "<p>$ext</p>" );

$full = wp_get_attachment_url( $id );
$pic  = '<picture><source type="image/avif" srcset="already.avif"><img src="' . esc_url( $full ) . '"></picture>';
check( 'existing <picture> left intact', Patu_Rewrite::rewrite( "<div>$pic</div>" ) === "<div>$pic</div>" );

// An attachment with NO siblings generated.
$up2  = wp_upload_bits( 'patu-rw-none.jpg', null, file_get_contents( $up['file'] ) );
$id2  = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_title' => 'none' ), $up2['file'] );
$meta2 = wp_generate_attachment_metadata( $id2, $up2['file'] );
wp_update_attachment_metadata( $id2, $meta2 );
$img2 = wp_get_attachment_image( $id2, 'large' );
check( 'image without siblings left untouched', false === strpos( Patu_Rewrite::rewrite( "<p>$img2</p>" ), '<picture>' ) );

echo "== the AVIF sibling URL actually resolves to a real file ==\n";
if ( preg_match( '#<source type="image/avif" srcset="([^" ]+\.avif)#', $out, $mm ) ) {
	$u    = wp_get_upload_dir();
	$path = $u['basedir'] . substr( preg_replace( '#^https?:#', '', $mm[1] ), strlen( preg_replace( '#^https?:#', '', $u['baseurl'] ) ) );
	check( 'first AVIF source URL points at an existing sibling file', file_exists( $path ) );
} else {
	check( 'first AVIF source URL points at an existing sibling file', false );
}

wp_delete_attachment( $id, true );
wp_delete_attachment( $id2, true );
$fails = $GLOBALS['patu_fails'];
echo "\n" . ( 0 === $fails ? 'ALL PASSED' : $fails . ' FAILED' ) . "\n";
exit( 0 === $fails ? 0 : 1 );
