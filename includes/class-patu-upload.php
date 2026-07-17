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
		if ( ! Patu_Key::is_set() ) {
			return $metadata;
		}
		$nextgen = 'nextgen' === patu_mode();
		$ok      = $nextgen ? Patu_Nextgen::is_supported( $attachment_id ) : Patu_Optimizer::is_supported( $attachment_id );
		if ( ! $ok ) {
			return $metadata;
		}
		try {
			// Bound the on-upload work so it can never exhaust the PHP time
			// limit; any sizes left over are finished by a later bulk run.
			if ( $nextgen ) {
				Patu_Nextgen::generate( $attachment_id, self::budget() );
			} else {
				Patu_Optimizer::optimize_attachment( $attachment_id, self::budget() );
			}
		} catch ( \Throwable $e ) {
			// Never break an upload: the original files are kept as they are.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Patu: optimize on upload failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic only.
			}
		}
		return $metadata; // Files shrank in place; the metadata is unchanged.
	}

	/** Wall-clock budget for on-upload optimization, kept under the PHP time limit. */
	private static function budget() {
		$max    = (int) ini_get( 'max_execution_time' );
		$budget = ( $max > 0 ) ? max( 8, $max - 8 ) : 45;
		return (float) apply_filters( 'patu_upload_budget', $budget );
	}
}
