<?php
/**
 * Bulk optimize (and restore) the existing media library. The page hands the
 * browser a list of attachment IDs; the browser then walks them one at a time
 * over AJAX, so a large library never hits a PHP time limit in a single request
 * and the user sees live progress.
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Patu_Bulk {

	const PAGE = 'patu-bulk';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_patu_bulk_ids', array( __CLASS__, 'ajax_ids' ) );
		add_action( 'wp_ajax_patu_bulk_one', array( __CLASS__, 'ajax_one' ) );
	}

	public static function menu() {
		add_submenu_page( 'patu', __( 'Bulk Optimize', 'patu' ), __( 'Bulk Optimize', 'patu' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'patu-admin', PATU_URL . 'admin/css/patu-admin.css', array(), PATU_VERSION );
		wp_enqueue_script( 'patu-bulk', PATU_URL . 'admin/js/patu-bulk.js', array(), PATU_VERSION, true );
		wp_localize_script(
			'patu-bulk',
			'PatuBulk',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'patu_bulk' ),
				'i18n'  => array(
					'scanning' => __( 'Scanning…', 'patu' ),
					'none'     => __( 'Nothing to do.', 'patu' ),
					'done'     => __( 'Done.', 'patu' ),
					'saved'    => __( 'saved', 'patu' ),
				),
			)
		);
	}

	private static function supported_mimes() {
		return array( 'image/jpeg', 'image/webp' );
	}

	/** IDs of supported attachments, either not-yet-optimized or already-optimized. */
	private static function query_ids( $op ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => self::supported_mimes(),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => Patu_Optimizer::META,
					'compare' => ( 'restore' === $op ) ? 'EXISTS' : 'NOT EXISTS',
				),
			),
		);
		$q = new WP_Query( $args );
		return array_map( 'intval', $q->posts );
	}

	public static function ajax_ids() {
		self::guard();
		$op = ( isset( $_POST['op'] ) && 'restore' === $_POST['op'] ) ? 'restore' : 'optimize';
		wp_send_json_success( array( 'ids' => self::query_ids( $op ) ) );
	}

	public static function ajax_one() {
		self::guard();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$op = ( isset( $_POST['op'] ) && 'restore' === $_POST['op'] ) ? 'restore' : 'optimize';
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'bad id' ) );
		}
		$title = get_the_title( $id );
		if ( 'restore' === $op ) {
			$r = Patu_Optimizer::restore_attachment( $id );
			wp_send_json_success( array( 'id' => $id, 'title' => $title, 'restored' => $r['restored'] ) );
		}
		$r = Patu_Optimizer::optimize_attachment( $id );
		wp_send_json_success(
			array(
				'id'        => $id,
				'title'     => $title,
				'optimized' => $r['optimized'],
				'failed'    => $r['failed'],
				'saved'     => $r['saved'],
				'skipped'   => isset( $r['skipped'] ) ? $r['skipped'] : '',
			)
		);
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'patu_bulk', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'patu' ) ), 403 );
		}
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$pending    = count( self::query_ids( 'optimize' ) );
		$optimized  = count( self::query_ids( 'restore' ) );
		?>
		<div class="wrap patu-wrap">
			<h1><?php esc_html_e( 'Bulk Optimize', 'patu' ); ?></h1>
			<p class="patu-lede">
				<?php
				printf(
					/* translators: 1: pending count, 2: optimized count. */
					esc_html__( '%1$s images to optimize, %2$s already optimized. JPEG and WebP only in v1.', 'patu' ),
					'<strong>' . esc_html( number_format_i18n( $pending ) ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput
					'<strong>' . esc_html( number_format_i18n( $optimized ) ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput
				);
				?>
			</p>

			<p>
				<button class="button button-primary" id="patu-bulk-optimize" <?php disabled( 0, $pending ); ?>><?php esc_html_e( 'Optimize all', 'patu' ); ?></button>
				<button class="button" id="patu-bulk-restore" <?php disabled( 0, $optimized ); ?>><?php esc_html_e( 'Restore all', 'patu' ); ?></button>
				<button class="button" id="patu-bulk-stop" style="display:none"><?php esc_html_e( 'Stop', 'patu' ); ?></button>
			</p>

			<div class="patu-bulk-bar" style="display:none"><div class="patu-bulk-fill" id="patu-bulk-fill"></div></div>
			<p id="patu-bulk-status" class="description"></p>
			<div class="patu-bulk-log" id="patu-bulk-log" style="display:none"></div>
		</div>
		<?php
	}
}
