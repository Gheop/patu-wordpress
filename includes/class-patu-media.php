<?php
/**
 * Media library integration: a "Patu" column showing each image's status and
 * savings, with per-item Optimize / Restore actions.
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Patu_Media {

	public static function init() {
		add_filter( 'manage_media_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'cell' ), 10, 2 );
		add_action( 'admin_post_patu_optimize', array( __CLASS__, 'handle_optimize' ) );
		add_action( 'admin_post_patu_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		if ( 'upload.php' === $hook ) {
			wp_enqueue_style( 'patu-admin', PATU_URL . 'admin/css/patu-admin.css', array(), PATU_VERSION );
		}
	}

	public static function column( $cols ) {
		$cols['patu'] = __( 'Patu', 'patu' );
		return $cols;
	}

	public static function cell( $col, $id ) {
		if ( 'patu' !== $col ) {
			return;
		}
		if ( ! Patu_Optimizer::is_supported( $id ) ) {
			echo '<span class="patu-col-none">' . esc_html( '—' ) . '</span>';
			return;
		}
		$can    = current_user_can( 'manage_options' );
		$status = Patu_Optimizer::status( $id );

		if ( ! empty( $status['optimized'] ) ) {
			printf(
				'<span class="patu-col-opt">%s</span>',
				esc_html( sprintf( /* translators: 1: saved size, 2: percent. */ __( 'Saved %1$s (%2$d%%)', 'patu' ), size_format( $status['saved'], 1 ), (int) $status['pct'] ) )
			);
			if ( $can && ! empty( $status['restorable'] ) ) {
				echo '<br>' . self::action_link( 'patu_restore', $id, __( 'Restore', 'patu' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		} else {
			echo '<span class="patu-col-none">' . esc_html__( 'Not optimized', 'patu' ) . '</span>';
			if ( $can ) {
				echo '<br>' . self::action_link( 'patu_optimize', $id, __( 'Optimize now', 'patu' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
	}

	private static function action_link( $action, $id, $label ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . '&id=' . $id ), $action . '_' . $id );
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	public static function handle_optimize() {
		self::handle(
			'patu_optimize',
			function ( $id ) {
				Patu_Optimizer::optimize_attachment( $id );
			}
		);
	}

	public static function handle_restore() {
		self::handle(
			'patu_restore',
			function ( $id ) {
				Patu_Optimizer::restore_attachment( $id );
			}
		);
	}

	private static function handle( $action, $fn ) {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! $id || ! wp_verify_nonce( $nonce, $action . '_' . $id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'patu' ) );
		}
		$fn( $id );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) );
		exit;
	}
}
