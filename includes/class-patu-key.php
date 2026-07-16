<?php
/**
 * API key resolution: a PATU_API_KEY constant (wp-config.php) wins over the
 * stored option, so a site can keep the key out of the database.
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Patu_Key {

	const OPTION = 'patu_api_key';

	/** The resolved API key, or '' when none is configured. */
	public static function get() {
		if ( defined( 'PATU_API_KEY' ) && PATU_API_KEY ) {
			$key = (string) PATU_API_KEY;
		} else {
			$key = (string) get_option( self::OPTION, '' );
		}
		/** Lets the settings "test connection" button try a not-yet-saved key. */
		return (string) apply_filters( 'patu_resolved_key', $key );
	}

	public static function is_set() {
		return '' !== self::get();
	}

	/** True when the key comes from the constant (the option field is then read-only). */
	public static function from_constant() {
		return defined( 'PATU_API_KEY' ) && PATU_API_KEY;
	}
}
