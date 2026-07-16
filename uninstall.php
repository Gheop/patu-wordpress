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
delete_post_meta_by_key( '_patu' );

$uploads = wp_get_upload_dir();
$backups = trailingslashit( $uploads['basedir'] ) . 'patu-originals';

if ( is_dir( $backups ) ) {
	$stack = array( $backups );
	$dirs  = array();
	while ( $stack ) {
		$dir = array_pop( $stack );
		$dirs[] = $dir;
		foreach ( (array) glob( $dir . '/*' ) as $item ) {
			if ( is_dir( $item ) ) {
				$stack[] = $item;
			} else {
				@unlink( $item );
			}
		}
	}
	foreach ( array_reverse( $dirs ) as $dir ) {
		@rmdir( $dir );
	}
}
