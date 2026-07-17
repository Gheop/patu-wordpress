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
		$nextgen   = 'nextgen' === patu_mode();
		$supported = $nextgen ? Patu_Nextgen::is_supported( $id ) : Patu_Optimizer::is_supported( $id );
		if ( ! $supported ) {
			echo '<span class="patu-col-none">' . esc_html( '—' ) . '</span>';
			return;
		}
		$can = current_user_can( 'manage_options' );

		if ( $nextgen ) {
			$status = Patu_Nextgen::status( $id );
			$done   = ! empty( $status['generated'] );
			$label  = $done
				? sprintf( /* translators: %s: saved size. */ __( 'AVIF/WebP ready, saving %s', 'patu' ), size_format( (int) $status['saved'], 1 ) )
				: __( 'No next-gen versions', 'patu' );
			$restorable = $done;
		} else {
			$status = Patu_Optimizer::status( $id );
			$done   = ! empty( $status['optimized'] );
			$label  = $done
				? sprintf( /* translators: 1: saved size, 2: percent. */ __( 'Saved %1$s (%2$d%%)', 'patu' ), size_format( (int) $status['saved'], 1 ), (int) $status['pct'] )
				: __( 'Not optimized', 'patu' );
			$restorable = $done && ! empty( $status['restorable'] );
		}

		if ( $done ) {
			echo '<span class="patu-col-opt">' . esc_html( $label ) . '</span>';
			if ( $can && $restorable ) {
				echo '<br>' . self::action_link( 'patu_restore', $id, __( 'Restore', 'patu' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		} else {
			echo '<span class="patu-col-none">' . esc_html( $label ) . '</span>';
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
				if ( 'nextgen' === patu_mode() ) {
					Patu_Nextgen::generate( $id );
				} else {
					Patu_Optimizer::optimize_attachment( $id );
				}
			}
		);
	}

	public static function handle_restore() {
		self::handle(
			'patu_restore',
			function ( $id ) {
				if ( 'nextgen' === patu_mode() ) {
					Patu_Nextgen::cleanup( $id );
				} else {
					Patu_Optimizer::restore_attachment( $id );
				}
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
