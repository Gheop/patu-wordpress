<?php
/**
 * Verifies the review-fix behaviors:
 *  - test_connection makes a real API call with an embedded JPEG (no GD needed);
 *  - restore with backups disabled restores nothing and does NOT forget the
 *    optimization record;
 *  - the backups directory is hardened with an index.php.
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
require_once ABSPATH . 'wp-admin/includes/image.php';

function mk( $name ) {
	$im = imagecreatetruecolor( 700, 500 );
	for ( $y = 0; $y < 500; $y++ ) {
		for ( $x = 0; $x < 700; $x++ ) {
			imagesetpixel( $im, $x, $y, imagecolorallocate( $im, (int) ( $x * 255 / 700 ), (int) ( $y * 255 / 500 ), ( $x + $y ) % 256 ) );
		}
	}
	ob_start();
	imagejpeg( $im, null, 95 );
	$up = wp_upload_bits( $name, null, ob_get_clean() );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_title' => $name ), $up['file'] );
	$m  = wp_generate_attachment_metadata( $id, $up['file'] );
	wp_update_attachment_metadata( $id, $m );
	return $id;
}

echo "== test_connection (embedded jpeg, no GD dependency) ==\n";
$t = Patu_API::test_connection();
check( 'test_connection returns true', true === $t );

echo "== restore with backups OFF keeps the record ==\n";
update_option( 'patu_auto', '0' );
update_option( 'patu_backup', '0' );
$id1 = mk( 'patu-fx-nobak.jpg' );
Patu_Optimizer::optimize_attachment( $id1 );
check( 'optimized with backups off', Patu_Optimizer::is_optimized( $id1 ) );
$rr = Patu_Optimizer::restore_attachment( $id1 );
check( 'restore restored 0 files (no backups)', 0 === $rr['restored'] );
check( 'optimization record NOT wiped when nothing was restorable', Patu_Optimizer::is_optimized( $id1 ) );

echo "== backups directory hardened ==\n";
update_option( 'patu_backup', '1' );
$id2 = mk( 'patu-fx-bak.jpg' );
Patu_Optimizer::optimize_attachment( $id2 );
$u = wp_get_upload_dir();
check( 'patu-originals/index.php exists', file_exists( $u['basedir'] . '/patu-originals/index.php' ) );

wp_delete_attachment( $id1, true );
wp_delete_attachment( $id2, true );
update_option( 'patu_backup', '1' );
update_option( 'patu_auto', '1' );
echo "\n" . ( 0 === $fails ? 'ALL PASSED' : $fails . ' FAILED' ) . "\n";
exit( 0 === $fails ? 0 : 1 );
