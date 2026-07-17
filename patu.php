<?php
/**
 * Plugin Name: Patu
 * Plugin URI: https://patu.dev
 * Description: Optimize your media library through the Patu API. Smaller images, same quality, never bigger, never broken.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Patu
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: patu
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PATU_VERSION', '0.1.0' );
define( 'PATU_FILE', __FILE__ );
define( 'PATU_DIR', plugin_dir_path( __FILE__ ) );
define( 'PATU_URL', plugin_dir_url( __FILE__ ) );

require_once PATU_DIR . 'includes/class-patu-key.php';
require_once PATU_DIR . 'includes/class-patu-api.php';
require_once PATU_DIR . 'includes/class-patu-stats.php';
require_once PATU_DIR . 'includes/class-patu-fs.php';
require_once PATU_DIR . 'includes/class-patu-optimizer.php';
require_once PATU_DIR . 'includes/class-patu-upload.php';

add_action(
	'plugins_loaded',
	function () {
		Patu_Upload::init();

		if ( is_admin() ) {
			foreach ( array( 'settings', 'media', 'bulk' ) as $admin ) {
				$file = PATU_DIR . "includes/class-patu-{$admin}.php";
				if ( file_exists( $file ) ) {
					require_once $file;
				}
			}
			if ( class_exists( 'Patu_Settings' ) ) {
				Patu_Settings::init();
			}
			if ( class_exists( 'Patu_Media' ) ) {
				Patu_Media::init();
			}
			if ( class_exists( 'Patu_Bulk' ) ) {
				Patu_Bulk::init();
			}
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		add_option( 'patu_auto', '1' );
		add_option( 'patu_backup', '1' );
	}
);
