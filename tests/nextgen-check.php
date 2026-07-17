<?php
/**
 * Integration check for next-gen sibling generation (AVIF/WebP) and cleanup.
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

function make_attachment( $bytes, $name, $mime ) {
	$up = wp_upload_bits( $name, null, $bytes );
	$id = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_status' => 'inherit', 'post_title' => $name ), $up['file'] );
	$m  = wp_generate_attachment_metadata( $id, $up['file'] );
	wp_update_attachment_metadata( $id, $m );
	return $id;
}
function jpeg( $w, $h ) {
	$im = imagecreatetruecolor( $w, $h );
	for ( $y = 0; $y < $h; $y++ ) {
		for ( $x = 0; $x < $w; $x++ ) {
			imagesetpixel( $im, $x, $y, imagecolorallocate( $im, (int) ( $x * 255 / $w ), (int) ( $y * 255 / $h ), ( $x + $y ) % 256 ) );
		}
	}
	ob_start();
	imagejpeg( $im, null, 95 );
	return ob_get_clean();
}
function png( $w, $h ) {
	$im = imagecreatetruecolor( $w, $h );
	for ( $y = 0; $y < $h; $y++ ) {
		for ( $x = 0; $x < $w; $x++ ) {
			imagesetpixel( $im, $x, $y, imagecolorallocate( $im, (int) ( $x * 255 / $w ), 40, (int) ( $y * 255 / $h ) ) );
		}
	}
	ob_start();
	imagepng( $im );
	return ob_get_clean();
}
function is_avif( $path ) {
	$h = (string) file_get_contents( $path, false, null, 0, 32 );
	return false !== strpos( $h, 'ftyp' ) && false !== strpos( $h, 'avif' );
}
function is_webp( $path ) {
	$h = (string) file_get_contents( $path, false, null, 0, 16 );
	return 0 === strpos( $h, 'RIFF' ) && false !== strpos( $h, 'WEBP' );
}

echo "== JPEG next-gen siblings ==\n";
$id     = make_attachment( jpeg( 1000, 750 ), 'patu-ng.jpg', 'image/jpeg' );
$files  = Patu_Optimizer::files_for_attachment( $id );
$before = array();
foreach ( $files as $f ) {
	$before[ $f ] = filesize( $f );
}
$r = Patu_Nextgen::generate( $id );
echo "  generated={$r['generated']} failed={$r['failed']} saved={$r['saved']}\n";
check( 'generated at least one sibling', $r['generated'] >= 1 );
check( 'no failures', 0 === $r['failed'] );

$avif_ok        = true;
$webp_valid     = true; // any webp sibling that exists must be smaller + valid.
$orig_untouched = true;
foreach ( $files as $f ) {
	clearstatcache( true, $f );
	if ( filesize( $f ) !== $before[ $f ] ) {
		$orig_untouched = false;
	}
	$a = $f . '.avif';
	$w = $f . '.webp';
	if ( ! file_exists( $a ) || filesize( $a ) >= $before[ $f ] || ! is_avif( $a ) ) {
		$avif_ok = false;
	}
	// A webp sibling is created only when it beats the original; if present it
	// must be smaller and a real webp (a middle tier below avif).
	if ( file_exists( $w ) && ( filesize( $w ) >= $before[ $f ] || ! is_webp( $w ) ) ) {
		$webp_valid = false;
	}
}
check( 'every size got a smaller, valid .avif sibling', $avif_ok );
check( 'any .webp siblings are smaller and valid', $webp_valid );
check( 'originals left completely untouched', $orig_untouched );
check( 'marked done', Patu_Nextgen::is_generated( $id ) );

echo "== PNG works in next-gen (original untouched) ==\n";
$pid   = make_attachment( png( 600, 400 ), 'patu-ng.png', 'image/png' );
$pfile = get_attached_file( $pid );
$psize = filesize( $pfile );
$rp    = Patu_Nextgen::generate( $pid );
clearstatcache( true, $pfile );
check( 'PNG generated siblings', $rp['generated'] >= 1 );
check( 'PNG original untouched', filesize( $pfile ) === $psize );
check( 'PNG got a .avif sibling', file_exists( $pfile . '.avif' ) && is_avif( $pfile . '.avif' ) );

echo "== cleanup removes siblings ==\n";
$c = Patu_Nextgen::cleanup( $id );
echo "  deleted {$c['deleted']}\n";
$gone = true;
foreach ( $files as $f ) {
	if ( file_exists( $f . '.avif' ) || file_exists( $f . '.webp' ) ) {
		$gone = false;
	}
}
check( 'all JPEG siblings deleted', $gone );
check( 'meta cleared after cleanup', ! Patu_Nextgen::is_generated( $id ) );

wp_delete_attachment( $id, true );
wp_delete_attachment( $pid, true );
$fails = $GLOBALS['patu_fails'];
echo "\n" . ( 0 === $fails ? 'ALL PASSED' : $fails . ' FAILED' ) . "\n";
exit( 0 === $fails ? 0 : 1 );
