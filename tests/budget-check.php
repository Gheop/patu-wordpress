<?php
/**
 * Verifies the on-upload time budget: a tiny budget optimizes only part of an
 * attachment and does NOT mark it done, so a later full run finishes it and the
 * "done" marker (which the bulk pending query keys on) is then set.
 *
 * @package Patu
 */

$fails = 0;
function check( $label, $cond ) {
	global $fails;
	echo ( $cond ? 'PASS' : 'FAIL' ) . '  ' . $label . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

update_option( 'patu_api_key', getenv( 'PATU_KEY' ) );
update_option( 'patu_auto', '0' );
update_option( 'patu_backup', '1' );
require_once ABSPATH . 'wp-admin/includes/image.php';

$im = imagecreatetruecolor( 1400, 1000 );
for ( $y = 0; $y < 1000; $y++ ) {
	for ( $x = 0; $x < 1400; $x++ ) {
		imagesetpixel( $im, $x, $y, imagecolorallocate( $im, (int) ( $x * 255 / 1400 ), (int) ( $y * 255 / 1000 ), ( $x + $y ) % 256 ) );
	}
}
ob_start();
imagejpeg( $im, null, 95 );
$bytes = ob_get_clean();
$up    = wp_upload_bits( 'patu-budget.jpg', null, $bytes );
$id    = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_title' => 'budget' ), $up['file'] );
$meta  = wp_generate_attachment_metadata( $id, $up['file'] );
wp_update_attachment_metadata( $id, $meta );

$total_files = count( Patu_Optimizer::files_for_attachment( $id ) );
check( 'attachment has several files to process', $total_files >= 3 );

// Tiny budget: proceeds into the first file only, then stops.
$r1   = Patu_Optimizer::optimize_attachment( $id, 0.001 );
$done = get_post_meta( $id, '_patu_done', true );
$m1   = get_post_meta( $id, '_patu', true );
$processed1 = is_array( $m1 ) && ! empty( $m1['files'] ) ? count( $m1['files'] ) : 0;
check( 'tiny budget processed only part', $processed1 >= 1 && $processed1 < $total_files );
check( 'tiny budget did NOT mark done', '1' !== $done );

// Full run finishes the rest.
$r2   = Patu_Optimizer::optimize_attachment( $id, 0 );
$done = get_post_meta( $id, '_patu_done', true );
$m2   = get_post_meta( $id, '_patu', true );
check( 'full run processed every file', count( $m2['files'] ) === $total_files );
check( 'full run marked done', '1' === $done );
check( 'still optimized with real savings', Patu_Optimizer::is_optimized( $id ) && $m2['saved'] > 0 );

wp_delete_attachment( $id, true );
echo "\n" . ( 0 === $fails ? 'ALL PASSED' : $fails . ' FAILED' ) . "\n";
exit( 0 === $fails ? 0 : 1 );
