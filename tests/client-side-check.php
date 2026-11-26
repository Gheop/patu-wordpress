<?php
/**
 * Regression checks for WordPress 7.2:
 *
 * 1. Client-side media processing. The browser uploads the original, sideloads
 *    each sub-size, then calls the finalize endpoint. The metadata filter runs
 *    twice: first with no sub-sizes, then from finalize with sub-sizes that are
 *    not yet saved. This replays that sequence and checks the create call
 *    waits and the finalize call optimizes every file.
 * 2. The front-end rewrite buffer must be removable, or WordPress's shutdown
 *    ob_end_flush() raises a notice.
 *
 * Run inside the container:
 *   wp eval-file wp-content/plugins/patu/tests/client-side-check.php
 * with -e PATU_KEY="$PATU_KEY".
 *
 * @package Patu
 */

// wp eval-file includes this file inside a function, so a plain $fails would
// be local here and global inside check(): keep the counter in $GLOBALS.
$GLOBALS['patu_fails'] = 0;
function check( $label, $cond ) {
	echo ( $cond ? 'PASS' : 'FAIL' ) . '  ' . $label . "\n";
	if ( ! $cond ) {
		$GLOBALS['patu_fails']++;
	}
}

update_option( 'patu_api_key', getenv( 'PATU_KEY' ) );
update_option( 'patu_mode', 'inplace' );
update_option( 'patu_auto', '1' );
update_option( 'patu_backup', '1' );
require_once ABSPATH . 'wp-admin/includes/image.php';

$im = imagecreatetruecolor( 1400, 1050 );
for ( $y = 0; $y < 1050; $y += 2 ) {
	for ( $x = 0; $x < 1400; $x += 2 ) {
		imagefilledrectangle( $im, $x, $y, $x + 1, $y + 1, imagecolorallocate( $im, (int) ( $x * 255 / 1400 ), (int) ( $y * 255 / 1050 ), ( $x + $y ) % 256 ) );
	}
}
ob_start();
imagejpeg( $im, null, 95 );
$up = wp_upload_bits( 'patu-client-side.jpg', null, ob_get_clean() );
$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_title' => 'client-side' ), $up['file'] );

// Step 1, POST /wp/v2/media with generate_sub_sizes=false: core short-circuits
// the sub-size list for the request and stores the original alone.
$base = array(
	'width'  => 1400,
	'height' => 1050,
	'file'   => _wp_relative_upload_path( $up['file'] ),
	'sizes'  => array(),
);
add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 100 );
$meta = apply_filters( 'wp_generate_attachment_metadata', $base, $id, 'create' );
remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 100 );
wp_update_attachment_metadata( $id, $meta );
$rec = get_post_meta( $id, '_patu', true );
check( 'create call waits for finalize', empty( $rec['files'] ) && '' === get_post_meta( $id, '_patu_done', true ) );

// Step 2, sideloads: the sub-size files land on disk, metadata is untouched.
$sizes  = array();
$editor = wp_get_image_editor( $up['file'] );
foreach ( array( 'thumbnail' => array( 150, 150, true ), 'medium' => array( 300, 300, false ), 'large' => array( 1024, 1024, false ) ) as $name => $dim ) {
	$e = wp_get_image_editor( $up['file'] );
	$e->set_quality( 95 );
	$e->resize( $dim[0], $dim[1], $dim[2] );
	$saved          = $e->save( $e->generate_filename() );
	$sizes[ $name ] = array(
		'file'      => $saved['file'],
		'width'     => $saved['width'],
		'height'    => $saved['height'],
		'mime-type' => $saved['mime-type'],
	);
}
$stored = wp_get_attachment_metadata( $id );
check( 'stored metadata has no sizes before finalize', empty( $stored['sizes'] ) );

// Step 3, finalize: the filter gets the sizes before they are saved.
$final         = $stored;
$final['sizes'] = $sizes;
$final         = apply_filters( 'wp_generate_attachment_metadata', $final, $id, 'update' );
wp_update_attachment_metadata( $id, $final );

$rec  = get_post_meta( $id, '_patu', true );
$dir  = trailingslashit( dirname( $up['file'] ) );
$all  = true;
$less = true;
foreach ( $sizes as $s ) {
	$k = Patu_Optimizer::rel_key( $dir . $s['file'] );
	if ( ! isset( $rec['files'][ $k ] ) ) {
		$all = false;
		continue;
	}
	if ( $rec['files'][ $k ]['opt'] >= $rec['files'][ $k ]['orig'] ) {
		$less = false;
	}
}
check( 'finalize call optimized every sub-size', $all );
check( 'every sub-size shrank', $less );
check( 'original + 3 sizes recorded', 4 === count( $rec['files'] ) );
check( 'marked done', '1' === get_post_meta( $id, '_patu_done', true ) );

$r = Patu_Optimizer::restore_attachment( $id );
check( 'restore puts the sub-sizes back', is_array( $r ) && ( $r['restored'] ?? 0 ) >= 4 );

// Front-end buffer flags. Read and close the buffer before printing, or the
// check lines would be swallowed by it.
$level = ob_get_level();
Patu_Rewrite::maybe_buffer();
$status = ob_get_status();
$ours   = ob_get_level() > $level && false !== strpos( (string) ( $status['name'] ?? '' ), 'Patu_Rewrite' );
$closed = $ours && ob_end_clean();
check( 'rewrite buffer opened', $ours );
check( 'rewrite buffer is removable', $closed && (bool) ( $status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) );
check( 'rewrite buffer is not flushable', $ours && ! ( $status['flags'] & PHP_OUTPUT_HANDLER_FLUSHABLE ) );

wp_delete_attachment( $id, true );
$fails = $GLOBALS['patu_fails'];
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
