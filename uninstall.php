<?php
/**
 * Remove everything Patu stored when the plugin is deleted: options, the
 * per-attachment meta, and the backups directory. Restore any images you want
 * to keep as originals before deleting the plugin.
 *
 * @package Patu
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'patu_api_key' );
delete_option( 'patu_auto' );
delete_option( 'patu_backup' );
delete_option( 'patu_stats' );
delete_option( 'patu_mode' );
delete_option( 'patu_ng_formats' );

delete_post_meta_by_key( '_patu' );
delete_post_meta_by_key( '_patu_done' );
delete_post_meta_by_key( '_patu_ng' );
delete_post_meta_by_key( '_patu_ng_done' );

$patu_uploads = wp_get_upload_dir();
$patu_backups = trailingslashit( $patu_uploads['basedir'] ) . 'patu-originals';

if ( is_dir( $patu_backups ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	add_filter(
		'filesystem_method',
		function () {
			return 'direct';
		}
	);
	if ( WP_Filesystem() ) {
		global $wp_filesystem;
		$wp_filesystem->delete( $patu_backups, true ); // Recursive.
	}
}
