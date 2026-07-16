<?php
/**
 * The Patu admin menu and settings page: API key, toggles, stats and a
 * "test connection" button.
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Patu_Settings {

	const PAGE = 'patu';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_patu_test_connection', array( __CLASS__, 'ajax_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PATU_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Patu', 'patu' ),
			__( 'Patu', 'patu' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-images-alt2',
			81
		);
		add_submenu_page( self::PAGE, __( 'Patu Settings', 'patu' ), __( 'Settings', 'patu' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting( 'patu_settings', 'patu_api_key', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
		register_setting( 'patu_settings', 'patu_auto', array( 'sanitize_callback' => array( __CLASS__, 'bool' ), 'default' => '1' ) );
		register_setting( 'patu_settings', 'patu_backup', array( 'sanitize_callback' => array( __CLASS__, 'bool' ), 'default' => '1' ) );
	}

	public static function bool( $v ) {
		return $v ? '1' : '0';
	}

	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'patu' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'patu-admin', PATU_URL . 'admin/css/patu-admin.css', array(), PATU_VERSION );
		wp_enqueue_script( 'patu-admin', PATU_URL . 'admin/js/patu-admin.js', array(), PATU_VERSION, true );
		wp_localize_script(
			'patu-admin',
			'PatuAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'patu_test' ),
				'i18n'  => array(
					'testing' => __( 'Testing…', 'patu' ),
					'ok'      => __( 'Connection OK.', 'patu' ),
				),
			)
		);
	}

	public static function ajax_test() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'patu_test', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'patu' ) ), 403 );
		}
		$posted = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( '' !== $posted ) {
			add_filter( 'patu_resolved_key', function () use ( $posted ) {
				return $posted;
			} );
		}
		$res = Patu_API::test_connection();
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Connection OK.', 'patu' ) ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$stats     = Patu_Stats::get();
		$from_const = Patu_Key::from_constant();
		?>
		<div class="wrap patu-wrap">
			<h1><span class="dashicons dashicons-images-alt2"></span> <?php esc_html_e( 'Patu', 'patu' ); ?></h1>
			<p class="patu-lede"><?php esc_html_e( 'Optimize your media library through the Patu API. Smaller images, same quality, never bigger, never broken.', 'patu' ); ?></p>

			<div class="patu-stats">
				<div class="patu-stat"><span class="patu-stat-val"><?php echo esc_html( size_format( $stats['saved'], 1 ) ); ?></span><span class="patu-stat-key"><?php esc_html_e( 'saved', 'patu' ); ?></span></div>
				<div class="patu-stat"><span class="patu-stat-val"><?php echo esc_html( number_format_i18n( $stats['images'] ) ); ?></span><span class="patu-stat-key"><?php esc_html_e( 'images optimized', 'patu' ); ?></span></div>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'patu_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="patu_api_key"><?php esc_html_e( 'API key', 'patu' ); ?></label></th>
						<td>
							<?php if ( $from_const ) : ?>
								<input type="text" class="regular-text" value="<?php esc_attr_e( 'Set via the PATU_API_KEY constant', 'patu' ); ?>" disabled>
								<p class="description"><?php esc_html_e( 'The key comes from the PATU_API_KEY constant in wp-config.php.', 'patu' ); ?></p>
							<?php else : ?>
								<input type="password" id="patu_api_key" name="patu_api_key" class="regular-text" autocomplete="off" value="<?php echo esc_attr( get_option( 'patu_api_key', '' ) ); ?>">
								<button type="button" class="button" id="patu-test"><?php esc_html_e( 'Test connection', 'patu' ); ?></button>
								<span id="patu-test-result" class="patu-test-result"></span>
								<p class="description">
									<?php
									printf(
										/* translators: %s: patu.dev signup URL. */
										wp_kses( __( 'Get a free key at <a href="%s" target="_blank" rel="noopener">patu.dev</a>.', 'patu' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ),
										'https://patu.dev'
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Optimize on upload', 'patu' ); ?></th>
						<td><label><input type="checkbox" name="patu_auto" value="1" <?php checked( '1', get_option( 'patu_auto', '1' ) ); ?>> <?php esc_html_e( 'Automatically optimize new images as they are uploaded.', 'patu' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Keep originals', 'patu' ); ?></th>
						<td><label><input type="checkbox" name="patu_backup" value="1" <?php checked( '1', get_option( 'patu_backup', '1' ) ); ?>> <?php esc_html_e( 'Back up original images so they can be restored. Recommended.', 'patu' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=patu-bulk' ) ); ?>"><?php esc_html_e( 'Bulk optimize your library →', 'patu' ); ?></a></p>
			<p class="description"><?php esc_html_e( 'v1 optimizes JPEG and WebP images in place. PNG and GIF, and WebP/AVIF delivery, are coming next.', 'patu' ); ?></p>
		</div>
		<?php
	}
}
