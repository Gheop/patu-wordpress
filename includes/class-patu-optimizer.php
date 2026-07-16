<?php
/**
 * Optimizes an attachment's files in place (same-format: jpeg -> jpeg,
 * webp -> webp) and can restore them from a backup of the originals.
 *
 * Guarantees: never bigger (a file is only overwritten when the optimized bytes
 * are strictly smaller), and never broken (any failure is recorded and skipped,
 * never fatal, and the write is atomic). PNG/GIF are left untouched in v1
 * because Patu has no same-format output for them.
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Patu_Optimizer {

	const META       = '_patu';
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
	 * @return array{ optimized:int, failed:int, saved:int, skipped?:string }
	 */
	public static function optimize_attachment( $attachment_id ) {
		$mime   = get_post_mime_type( $attachment_id );
		$format = self::format_for_mime( $mime );
		if ( null === $format ) {
			return array( 'optimized' => 0, 'failed' => 0, 'saved' => 0, 'skipped' => 'unsupported' );
		}

		$backup = self::backups_enabled();
		$meta   = get_post_meta( $attachment_id, self::META, true );
		if ( ! is_array( $meta ) ) {
			$meta = array( 'files' => array(), 'saved' => 0 );
		}

		$saved_now = 0;
		$optimized = 0;
		$failed    = 0;

		foreach ( self::files_for_attachment( $attachment_id ) as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$rel = self::rel( $path );
			if ( isset( $meta['files'][ $rel ] ) ) {
				continue; // already processed (optimized or kept) — don't re-hit the API.
			}

			$orig = @file_get_contents( $path );
			if ( false === $orig ) {
				$failed++;
				continue;
			}
			$orig_len = strlen( $orig );

			$res = Patu_API::compress( $orig, $mime, $format );
			if ( is_wp_error( $res ) ) {
				$failed++;
				continue; // never break: leave the original, record nothing, move on.
			}

			if ( $res['output_bytes'] >= $orig_len ) {
				// Never bigger: keep the original, but remember we tried so bulk
				// runs don't re-request it forever.
				$meta['files'][ $rel ] = array(
					'orig' => $orig_len,
					'opt'  => $orig_len,
					'kept' => true,
				);
				continue;
			}

			if ( $backup && ! self::backup( $path, $orig ) ) {
				// If we can't back up but backups are required, skip to stay safe.
				$failed++;
				continue;
			}
			if ( ! self::write_atomic( $path, $res['bytes'] ) ) {
				$failed++;
				continue;
			}

			$meta['files'][ $rel ] = array(
				'orig' => $orig_len,
				'opt'  => $res['output_bytes'],
			);
			$saved_now += ( $orig_len - $res['output_bytes'] );
			$optimized++;
		}

		$meta['saved'] = (int) $meta['saved'] + $saved_now;
		$meta['at']    = time();
		update_post_meta( $attachment_id, self::META, $meta );

		if ( $optimized > 0 ) {
			Patu_Stats::add( $optimized, $saved_now );
		}

		return array( 'optimized' => $optimized, 'failed' => $failed, 'saved' => $saved_now );
	}

	/**
	 * Restore an attachment's files from their backups and forget the
	 * optimization.
	 *
	 * @return array{ restored:int }
	 */
	public static function restore_attachment( $attachment_id ) {
		$meta = get_post_meta( $attachment_id, self::META, true );
		if ( ! is_array( $meta ) || empty( $meta['files'] ) ) {
			return array( 'restored' => 0 );
		}
		$restored = 0;
		foreach ( array_keys( $meta['files'] ) as $rel ) {
			$backup = self::backup_path_from_rel( $rel );
			$path   = self::path_from_rel( $rel );
			if ( ! file_exists( $backup ) ) {
				continue;
			}
			$bytes = @file_get_contents( $backup );
			if ( false !== $bytes && self::write_atomic( $path, $bytes ) ) {
				@unlink( $backup );
				$restored++;
			}
		}
		Patu_Stats::subtract( $restored, (int) ( isset( $meta['saved'] ) ? $meta['saved'] : 0 ) );
		delete_post_meta( $attachment_id, self::META );
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
			'optimized' => true,
			'saved'     => $saved,
			'pct'       => $pct,
			'files'     => count( $meta['files'] ),
			'restorable' => self::backups_enabled() || self::has_backups( $meta ),
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

	/** A file's key relative to the uploads dir (files outside it get a hashed slot). */
	private static function rel( $path ) {
		$base = self::uploads_basedir();
		if ( 0 === strpos( $path, $base . '/' ) ) {
			return ltrim( substr( $path, strlen( $base ) ), '/' );
		}
		return 'external/' . md5( $path ) . '/' . basename( $path );
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
		return false !== @file_put_contents( $bp, $bytes );
	}

	/** Write bytes, preserving the file's permissions, via a temp file + rename. */
	private static function write_atomic( $path, $bytes ) {
		$tmp = $path . '.patu-tmp';
		if ( false === @file_put_contents( $tmp, $bytes ) ) {
			return false;
		}
		$perms = @fileperms( $path );
		if ( $perms ) {
			@chmod( $tmp, $perms & 0777 );
		}
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return false;
		}
		clearstatcache( true, $path );
		return true;
	}
}
