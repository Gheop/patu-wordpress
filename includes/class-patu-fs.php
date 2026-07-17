<?php
/**
 * A thin wrapper over WP_Filesystem for the plugin's uploads-directory file
 * work. It forces the "direct" method: the uploads directory is always directly
 * writable when media upload works, and a non-interactive optimize (upload hook
 * or AJAX) cannot prompt for FTP/SSH credentials. If WP_Filesystem cannot be
 * initialized as direct, every operation degrades to a no-op (read/exists
 * return false, writes return false) so the plugin skips the work rather than
 * bypassing WP_Filesystem; it never fatals and never corrupts a file.
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Patu_FS {

	private static $fs;
	private static $init = false;

	private static function fs() {
		if ( self::$init ) {
			return self::$fs;
		}
		self::$init = true;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$force = function () {
			return 'direct';
		};
		add_filter( 'filesystem_method', $force );
		$ok = WP_Filesystem();
		remove_filter( 'filesystem_method', $force );

		global $wp_filesystem;
		self::$fs = ( $ok && $wp_filesystem && 'direct' === $wp_filesystem->method ) ? $wp_filesystem : null;
		return self::$fs;
	}

	/** @return string|false */
	public static function read( $path ) {
		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}
		$c = $fs->get_contents( $path );
		return false === $c ? false : $c;
	}

	public static function put( $path, $bytes ) {
		$fs = self::fs();
		return $fs ? (bool) $fs->put_contents( $path, $bytes, FS_CHMOD_FILE ) : false;
	}

	public static function delete( $path ) {
		$fs = self::fs();
		return $fs ? (bool) $fs->delete( $path ) : false;
	}

	public static function exists( $path ) {
		$fs = self::fs();
		return $fs ? (bool) $fs->exists( $path ) : false;
	}

	/**
	 * Write bytes to $path atomically: a unique temp file, then move-with-
	 * overwrite (a rename on the direct method, so the file is never left
	 * half-written).
	 */
	public static function write_atomic( $path, $bytes ) {
		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}
		$tmp = $path . '.' . uniqid( 'patu', true ) . '.tmp';
		if ( ! $fs->put_contents( $tmp, $bytes, FS_CHMOD_FILE ) ) {
			return false;
		}
		if ( ! $fs->move( $tmp, $path, true ) ) {
			$fs->delete( $tmp );
			return false;
		}
		clearstatcache( true, $path );
		return true;
	}
}
