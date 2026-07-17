<?php
/**
 * Sets up a next-gen scenario for the front-end rendering test: switches to
 * next-gen mode, uploads an image (which auto-generates siblings via the hook),
 * and publishes a post containing it. Prints POST_URL / ids for the caller.
 *
 * @package Patu
 */

update_option( 'patu_api_key', getenv( 'PATU_KEY' ) );
update_option( 'patu_mode', 'nextgen' );
update_option( 'patu_ng_formats', 'avif,webp' );
update_option( 'patu_auto', '1' );
require_once ABSPATH . 'wp-admin/includes/image.php';

$im = imagecreatetruecolor( 1400, 1050 );
for ( $y = 0; $y < 1050; $y++ ) {
	for ( $x = 0; $x < 1400; $x++ ) {
		imagesetpixel( $im, $x, $y, imagecolorallocate( $im, (int) ( $x * 255 / 1400 ), (int) ( $y * 255 / 1050 ), ( $x + $y ) % 256 ) );
	}
}
ob_start();
imagejpeg( $im, null, 95 );
$up = wp_upload_bits( 'patu-front.jpg', null, ob_get_clean() );
$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_title' => 'front' ), $up['file'] );
// This metadata generation fires the upload hook, which in next-gen mode
// generates the AVIF/WebP siblings automatically.
$meta = wp_generate_attachment_metadata( $id, $up['file'] );
wp_update_attachment_metadata( $id, $meta );

echo 'AUTO_GENERATED=' . ( Patu_Nextgen::is_generated( $id ) ? 'yes' : 'no' ) . "\n";

$content = wp_get_attachment_image( $id, 'large' );
$pid     = wp_insert_post(
	array(
		'post_title'   => 'Patu front test',
		'post_content' => $content,
		'post_status'  => 'publish',
	)
);
echo 'POST_URL=' . get_permalink( $pid ) . "\n";
echo 'ATT=' . $id . ' POST=' . $pid . "\n";
