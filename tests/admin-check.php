<?php
/**
 * Integration check for the admin surfaces + the auto-optimize-on-upload hook.
 * Run: wp --user=admin eval-file wp-content/plugins/patu/tests/admin-check.php
 * with -e PATU_KEY="$PATU_KEY".
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
update_option( 'patu_backup', '1' );

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once PATU_DIR . 'includes/class-patu-media.php';
require_once PATU_DIR . 'includes/class-patu-bulk.php';

function mk_jpeg( $name ) {
	$im = imagecreatetruecolor( 900, 700 );
	for ( $y = 0; $y < 700; $y++ ) {
		for ( $x = 0; $x < 900; $x++ ) {
			imagesetpixel( $im, $x, $y, imagecolorallocate( $im, (int) ( $x * 255 / 900 ), (int) ( $y * 255 / 700 ), ( $x + $y ) % 256 ) );
		}
	}
	ob_start();
	imagejpeg( $im, null, 95 );
	$bytes = ob_get_clean();
	$up    = wp_upload_bits( $name, null, $bytes );
	$id    = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $name, 'post_status' => 'inherit' ), $up['file'] );
	// Triggers the wp_generate_attachment_metadata filter; i.e. the auto hook.
	$meta = wp_generate_attachment_metadata( $id, $up['file'] );
	wp_update_attachment_metadata( $id, $meta );
	return $id;
}

function count_pending( $op ) {
	$q = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/webp' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => '_patu', 'compare' => ( 'restore' === $op ) ? 'EXISTS' : 'NOT EXISTS' ) ),
		)
	);
	return count( $q->posts );
}

echo "== auto-optimize on upload ==\n";
update_option( 'patu_auto', '1' );
$auto = mk_jpeg( 'patu-auto.jpg' );
check( 'new upload was auto-optimized', Patu_Optimizer::is_optimized( $auto ) );

echo "== bulk scan queries ==\n";
update_option( 'patu_auto', '0' ); // leave the next two pending
$p1      = mk_jpeg( 'patu-p1.jpg' );
$p2      = mk_jpeg( 'patu-p2.jpg' );
$pending = count_pending( 'optimize' );
$opted   = count_pending( 'restore' );
check( 'pending count includes the 2 unoptimized', $pending >= 2 );
check( 'optimized count includes the auto one', $opted >= 1 );

echo "== bulk optimize (the loop the AJAX drives) ==\n";
$r1 = Patu_Optimizer::optimize_attachment( $p1 );
$r2 = Patu_Optimizer::optimize_attachment( $p2 );
check( 'p1 optimized with savings', Patu_Optimizer::is_optimized( $p1 ) && $r1['saved'] > 0 );
check( 'p2 optimized with savings', Patu_Optimizer::is_optimized( $p2 ) && $r2['saved'] > 0 );
check( 'pending now excludes the two just done', count_pending( 'optimize' ) === $pending - 2 );

echo "== media column cells ==\n";
ob_start();
Patu_Media::cell( 'patu', $p1 );
$cell_opt = ob_get_clean();
check( 'optimized cell shows "Saved" and a Restore link', false !== strpos( $cell_opt, 'Saved' ) && false !== strpos( $cell_opt, 'Restore' ) );

update_option( 'patu_auto', '0' );
$p3 = mk_jpeg( 'patu-p3.jpg' );
ob_start();
Patu_Media::cell( 'patu', $p3 );
$cell_none = ob_get_clean();
check( 'unoptimized cell shows "Optimize now"', false !== strpos( $cell_none, 'Optimize now' ) );

echo "== bulk restore path ==\n";
$rr = Patu_Optimizer::restore_attachment( $p1 );
check( 'restore reported files', $rr['restored'] >= 1 );
check( 'p1 no longer optimized after restore', ! Patu_Optimizer::is_optimized( $p1 ) );

echo "== bulk page renders ==\n";
ob_start();
Patu_Bulk::render();
$page = ob_get_clean();
check( 'bulk page has Optimize all / Restore all', false !== strpos( $page, 'Optimize all' ) && false !== strpos( $page, 'Restore all' ) );

foreach ( array( $auto, $p1, $p2, $p3 ) as $x ) {
	wp_delete_attachment( $x, true );
}

echo "\n" . ( 0 === $fails ? 'ALL PASSED' : $fails . ' FAILED' ) . "\n";
exit( 0 === $fails ? 0 : 1 );
