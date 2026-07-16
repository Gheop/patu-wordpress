<?php
/**
 * Optimizes an attachment's files in place (same-format: jpeg -> jpeg,
 * webp -> webp) and can restore them from a backup of the originals.
 *
 * Guarantees: never bigger (a file is only overwritten when the optimized bytes
 * are strictly smaller AND are a valid same-format image), and never broken
 * (any failure is recorded and skipped, never fatal; the write is atomic; and a
 * per-attachment time budget keeps the on-upload path from ever exceeding the
 * PHP time limit). PNG/GIF are left untouched in v1 because Patu has no
 * same-format output for them.
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Patu_Optimizer {

	const META       = '_patu';       // per-file data.
	const DONE       = '_patu_done';  // set once every file has been processed.
	const BACKUP_DIR = 'patu-originals';

	/** The Patu output encoder for a mime type, or null when we don't optimize it in place. */
	public static function format_for_mime( $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
				return 'jpeg';
			case 'image/webp':
				return 'webp';
			default:
				return null; // png / gif / etc. — v1 leaves them untouched.
		}
	}

	public static function is_supported( $attachment_id ) {
		return null !== self::format_for_mime( get_post_mime_type( $attachment_id ) );
	}

	/** Absolute paths of the original file plus every generated size. */
	public static function files_for_attachment( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return array();
		}
		$files = array( $file );
		$dir   = trailingslashit( dirname( $file ) );
		$meta  = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . $size['file'];
				}
			}
		}
		return array_values( array_unique( $files ) );
	}

	/**
	 * Optimize one attachment across all its sizes.
	 *
	 * @param int   $attachment_id Attachment.
	 * @param float $budget        Wall-clock seconds to spend before stopping and
	 *                             leaving the rest for a later run (0 = no limit).
	 * @return array{ optimized:int, failed:int, saved:int, skipped?:string }
	 */
	public static function optimize_attachment( $attachment_id, $budget = 0 ) {
		$mime   = get_post_mime_type( $attachment_id );
		$format = self::format_for_mime( $mime );
		if ( null === $format ) {
			return array( 'optimized' => 0, 'failed' => 0, 'saved' => 0, 'skipped' => 'unsupported' );
		}

		$do_backup = self::backups_enabled();
		$meta      = get_post_meta( $attachment_id, self::META, true );
		if ( ! is_array( $meta ) ) {
			$meta = array( 'files' => array(), 'saved' => 0 );
		}

		$start     = microtime( true );
		$saved_now = 0;
		$optimized = 0;
		$failed    = 0;
		$files     = self::files_for_attachment( $attachment_id );

		foreach ( $files as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$rel = self::rel( $path );
			if ( isset( $meta['files'][ $rel ] ) ) {
				continue; // already processed — don't re-hit the API.
			}
			if ( $budget > 0 && ( microtime( true ) - $start ) >= $budget ) {
				break; // out of time; the remaining files stay pending for a later run.
			}

			$orig = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $orig ) {
				$failed++;
				continue;
			}
			$orig_len = strlen( $orig );

			$timeout = ( $budget > 0 ) ? max( 3, (int) ( $budget - ( microtime( true ) - $start ) ) ) : null;
			$res     = Patu_API::compress( $orig, $mime, $format, $timeout );
			if ( is_wp_error( $res ) ) {
				$failed++;
				continue; // never break: leave the original, move on.
			}

			if ( $res['output_bytes'] >= $orig_len ) {
				// Never bigger: keep the original, but remember we tried.
				$meta['files'][ $rel ] = array( 'orig' => $orig_len, 'opt' => $orig_len, 'kept' => true, 'path' => $path );
				continue;
			}

			// Never broken: only overwrite with a valid same-format image.
			$info = @getimagesizefromstring( $res['bytes'] );
			if ( ! $info || ! isset( $info['mime'] ) || $info['mime'] !== $mime ) {
				$failed++;
				continue;
			}

			if ( $do_backup && ! self::backup( $path, $orig ) ) {
				$failed++;
				continue; // backup required but failed: stay safe.
			}
			if ( ! self::write_atomic( $path, $res['bytes'] ) ) {
				$failed++;
				continue;
			}

			$meta['files'][ $rel ] = array( 'orig' => $orig_len, 'opt' => $res['output_bytes'], 'path' => $path );
			$saved_now            += ( $orig_len - $res['output_bytes'] );
			$optimized++;
		}

		$meta['saved'] = (int) $meta['saved'] + $saved_now;
		$meta['at']    = time();
		update_post_meta( $attachment_id, self::META, $meta );

		// Mark fully done only when every file now has an entry (so a partial run
		// stays pending and a later bulk run finishes it).
		$done = true;
		foreach ( $files as $p ) {
			if ( ! isset( $meta['files'][ self::rel( $p ) ] ) ) {
				$done = false;
				break;
			}
		}
		if ( $done ) {
			update_post_meta( $attachment_id, self::DONE, '1' );
		}

		if ( $optimized > 0 ) {
			Patu_Stats::add( $optimized, $saved_now );
		}

		return array( 'optimized' => $optimized, 'failed' => $failed, 'saved' => $saved_now );
	}

	/**
	 * Restore an attachment's files from their backups. Files that were only
	 * "kept" (never overwritten) need no restore; when a shrunk file cannot be
	 * restored (e.g. backups were disabled), the record is left intact rather
	 * than silently forgotten.
	 *
	 * @return array{ restored:int }
	 */
	public static function restore_attachment( $attachment_id ) {
		$meta = get_post_meta( $attachment_id, self::META, true );
		if ( ! is_array( $meta ) || empty( $meta['files'] ) ) {
			return array( 'restored' => 0 );
		}
		$restored = 0;
		$freed    = 0;
		$missing  = false;
		foreach ( $meta['files'] as $rel => $info ) {
			if ( ! empty( $info['kept'] ) ) {
				continue; // never changed.
			}
			$backup = self::backup_path_from_rel( $rel );
			$target = isset( $info['path'] ) ? $info['path'] : self::path_from_rel( $rel );
			if ( file_exists( $backup ) ) {
				$bytes = @file_get_contents( $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false !== $bytes && self::write_atomic( $target, $bytes ) ) {
					@unlink( $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					$restored++;
					$freed += max( 0, (int) ( isset( $info['orig'] ) ? $info['orig'] : 0 ) - (int) ( isset( $info['opt'] ) ? $info['opt'] : 0 ) );
					continue;
				}
			}
			$missing = true; // a shrunk file we could not restore.
		}
		if ( ! $missing ) {
			delete_post_meta( $attachment_id, self::META );
			delete_post_meta( $attachment_id, self::DONE );
		}
		Patu_Stats::subtract( $restored, $freed );
		return array( 'restored' => $restored );
	}

	/** Summary for the media column / row actions. */
	public static function status( $attachment_id ) {
		$meta = get_post_meta( $attachment_id, self::META, true );
		if ( ! is_array( $meta ) || empty( $meta['files'] ) ) {
			return array( 'optimized' => false );
		}
		$orig = 0;
		$opt  = 0;
		foreach ( $meta['files'] as $f ) {
			$orig += (int) ( isset( $f['orig'] ) ? $f['orig'] : 0 );
			$opt  += (int) ( isset( $f['opt'] ) ? $f['opt'] : 0 );
		}
		$saved = (int) ( isset( $meta['saved'] ) ? $meta['saved'] : max( 0, $orig - $opt ) );
		$pct   = $orig > 0 ? (int) round( ( ( $orig - $opt ) / $orig ) * 100 ) : 0;
		return array(
			'optimized'  => true,
			'saved'      => $saved,
			'pct'        => $pct,
			'files'      => count( $meta['files'] ),
			'restorable' => self::has_backups( $meta ),
		);
	}

	public static function is_optimized( $attachment_id ) {
		$meta = get_post_meta( $attachment_id, self::META, true );
		return is_array( $meta ) && ! empty( $meta['files'] );
	}

	// --- helpers ---------------------------------------------------------

	public static function backups_enabled() {
		return (bool) apply_filters( 'patu_backup', '1' === get_option( 'patu_backup', '1' ) );
	}

	private static function has_backups( $meta ) {
		foreach ( array_keys( (array) $meta['files'] ) as $rel ) {
			if ( file_exists( self::backup_path_from_rel( $rel ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function uploads_basedir() {
		$u = wp_get_upload_dir();
		return untrailingslashit( $u['basedir'] );
	}

	/**
	 * A file's key relative to the uploads dir. Files outside it, or any path
	 * that would escape via "..", get a safe hashed slot so a backup can never
	 * be written outside the backups directory.
	 */
	private static function rel( $path ) {
		$base = self::uploads_basedir();
		if ( 0 === strpos( $path, $base . '/' ) ) {
			$rel = ltrim( substr( $path, strlen( $base ) ), '/' );
			if ( false === strpos( $rel, '..' ) ) {
				return $rel;
			}
		}
		return 'external/' . md5( $path ) . '/' . sanitize_file_name( basename( $path ) );
	}

	private static function path_from_rel( $rel ) {
		return self::uploads_basedir() . '/' . $rel;
	}

	private static function backup_path_from_rel( $rel ) {
		return self::uploads_basedir() . '/' . self::BACKUP_DIR . '/' . $rel;
	}

	/** Back up the original bytes once. Returns true if a backup exists afterwards. */
	private static function backup( $path, $bytes ) {
		$bp = self::backup_path_from_rel( self::rel( $path ) );
		if ( file_exists( $bp ) ) {
			return true;
		}
		if ( ! wp_mkdir_p( dirname( $bp ) ) ) {
			return false;
		}
		self::harden_backup_dir();
		return false !== @file_put_contents( $bp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** Drop a silent index.php at the backups root so the directory can't be listed. */
	private static function harden_backup_dir() {
		$index = self::uploads_basedir() . '/' . self::BACKUP_DIR . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/** Write bytes, preserving the file's permissions, via a unique temp file + rename. */
	private static function write_atomic( $path, $bytes ) {
		$tmp = $path . '.' . uniqid( 'patu', true ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		$perms = @fileperms( $path );
		if ( $perms ) {
			@chmod( $tmp, $perms & 0777 );
		}
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		clearstatcache( true, $path );
		return true;
	}
}
