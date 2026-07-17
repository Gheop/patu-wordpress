<?php
/**
 * Hardening checks for the front-end rewriter (the security-review fixes).
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

$im = imagecreatetruecolor( 900, 600 );
for ( $y = 0; $y < 600; $y++ ) {
	for ( $x = 0; $x < 900; $x++ ) {
		imagesetpixel( $im, $x, $y, imagecolorallocate( $im, (int) ( $x * 255 / 900 ), (int) ( $y * 255 / 600 ), ( $x + $y ) % 256 ) );
	}
}
ob_start();
imagejpeg( $im, null, 95 );
$up = wp_upload_bits( 'patu-hard.jpg', null, ob_get_clean() );
$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_title' => 'hard' ), $up['file'] );
$m  = wp_generate_attachment_metadata( $id, $up['file'] );
wp_update_attachment_metadata( $id, $m );
Patu_Nextgen::generate( $id );

$url = wp_get_attachment_image_url( $id, 'full' ); // absolute URL with a .avif sibling.

echo "== a '>' inside an attribute must not truncate the tag ==\n";
$html = '<p><img src="' . esc_url( $url ) . '" alt="a > b">AFTER-TEXT</p>';
$out  = Patu_Rewrite::rewrite( $html );
check( 'still wrapped in <picture>', false !== strpos( $out, '<picture>' ) );
check( 'trailing content not eaten', false !== strpos( $out, 'AFTER-TEXT</p>' ) );
check( 'alt with > preserved on the fallback img', false !== strpos( $out, 'alt="a > b"' ) );

echo "== <img> inside <script> is left alone ==\n";
$html = '<script>var t = \'<img src="' . esc_url( $url ) . '">\';</script>';
$out  = Patu_Rewrite::rewrite( $html );
check( 'no <picture> injected into script', false === strpos( $out, '<picture>' ) );
check( 'script content unchanged', $out === $html );

echo "== <img> inside a comment is left alone ==\n";
$html = '<!-- <img src="' . esc_url( $url ) . '"> -->';
$out  = Patu_Rewrite::rewrite( $html );
check( 'comment unchanged', $out === $html );

echo "== root-relative src is rewritten ==\n";
$rel  = wp_make_link_relative( $url ); // /wp-content/uploads/...
$html = '<div><img src="' . esc_url( $rel ) . '"></div>';
$out  = Patu_Rewrite::rewrite( $html );
check( 'root-relative image wrapped', false !== strpos( $out, '<picture>' ) && false !== strpos( $out, '.avif' ) );

echo "== data-src is not mistaken for src ==\n";
$html = '<img data-src="https://cdn.example.com/remote.jpg" src="' . esc_url( $url ) . '">';
$out  = Patu_Rewrite::rewrite( $html );
check( 'wrapped using the real src, not data-src', false !== strpos( $out, '<picture>' ) );
check( 'avif source points at the uploads sibling, not the CDN', false !== strpos( $out, esc_url( $url ) . '.avif' ) && false === strpos( $out, 'cdn.example.com/remote.jpg.avif' ) );

echo "== a sibling-dir prefix (uploads-staging) does not match uploads ==\n";
$fake = str_replace( '/uploads/', '/uploads-staging/', $url );
$html = '<img src="' . esc_url( $fake ) . '">';
$out  = Patu_Rewrite::rewrite( $html );
check( 'uploads-staging URL left untouched', false === strpos( $out, '<picture>' ) );

wp_delete_attachment( $id, true );
$fails = $GLOBALS['patu_fails'];
echo "\n" . ( 0 === $fails ? 'ALL PASSED' : $fails . ' FAILED' ) . "\n";
exit( 0 === $fails ? 0 : 1 );
