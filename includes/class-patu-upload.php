<?php
/**
 * Optimize images as they are uploaded. Hooks the metadata filter, which runs
 * after WordPress has generated every intermediate size, and optimizes them in
 * place. It never alters the metadata (same format and dimensions) and never
 * lets a failure break the upload.
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Patu_Upload {

	public static function init() {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_metadata' ), 20, 2 );
	}

	public static function on_metadata( $metadata, $attachment_id ) {
		if ( '1' !== get_option( 'patu_auto', '1' ) ) {
			return $metadata;
		}
		if ( ! Patu_Key::is_set() || ! Patu_Optimizer::is_supported( $attachment_id ) ) {
			return $metadata;
		}
		try {
			Patu_Optimizer::optimize_attachment( $attachment_id );
		} catch ( \Throwable $e ) {
			// Never break an upload: the original files are kept as they are.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Patu: optimize on upload failed: ' . $e->getMessage() );
			}
		}
		return $metadata; // Files shrank in place; the metadata is unchanged.
	}
}
