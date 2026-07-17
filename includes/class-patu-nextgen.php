<?php
/**
 * Next-gen delivery: generate AVIF (and WebP) siblings next to each image size,
 * without touching the original. The original stays as the fallback, so PNG and
 * GIF are safe here too (unlike the in-place mode). Patu_Rewrite then serves the
 * siblings via <picture> on the front end.
 *
 * A sibling lives at "<original path>.<fmt>" (e.g. photo.jpg.avif), so the
 * rewriter can find it by appending the extension to any image URL.
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Patu_Nextgen {

	const META = '_patu_ng';
	const DONE = '_patu_ng_done';

	/** Which next-gen formats to generate, from the setting. */
	public static function formats() {
		$raw = (string) get_option( 'patu_ng_formats', 'avif,webp' );
		$out = array();
		foreach ( explode( ',', $raw ) as $f ) {
			$f = trim( $f );
			if ( in_array( $f, array( 'avif', 'webp' ), true ) ) {
				$out[] = $f;
			}
		}
		return $out ? $out : array( 'avif', 'webp' );
	}

	private static function own_format( $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
				return 'jpeg';
			case 'image/png':
				return 'png';
			case 'image/webp':
				return 'webp';
			case 'image/avif':
				return 'avif';
			default:
				return null;
		}
	}

	public static function is_supported( $attachment_id ) {
		return null !== self::own_format( get_post_mime_type( $attachment_id ) );
	}

	/** Formats we should generate for this attachment (its own format excluded). */
	private static function targets_for( $mime ) {
		$own = self::own_format( $mime );
		return array_values( array_diff( self::formats(), array( $own ) ) );
	}

	/**
	 * Generate the missing next-gen siblings for one attachment.
	 *
	 * @return array{ generated:int, failed:int, saved:int, skipped?:string }
	 */
	public static function generate( $attachment_id, $budget = 0 ) {
		$mime = get_post_mime_type( $attachment_id );
		if ( ! self::is_supported( $attachment_id ) ) {
			return array( 'generated' => 0, 'failed' => 0, 'saved' => 0, 'skipped' => 'unsupported' );
		}
		$targets = self::targets_for( $mime );
		if ( empty( $targets ) ) {
			return array( 'generated' => 0, 'failed' => 0, 'saved' => 0, 'skipped' => 'no_targets' );
		}

		$meta = get_post_meta( $attachment_id, self::META, true );
		if ( ! is_array( $meta ) ) {
			$meta = array( 'files' => array(), 'saved' => 0 );
		}

		$start     = microtime( true );
		$generated = 0;
		$failed    = 0;
		$saved     = 0;
		$files     = Patu_Optimizer::files_for_attachment( $attachment_id );

		foreach ( $files as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$rel  = Patu_Optimizer::rel_key( $path );
			$orig = null;
			foreach ( $targets as $fmt ) {
				if ( isset( $meta['files'][ $rel ][ $fmt ] ) ) {
					continue; // already attempted.
				}
				if ( $budget > 0 && ( microtime( true ) - $start ) >= $budget ) {
					break 2; // out of time; the rest stays pending.
				}
				if ( null === $orig ) {
					$orig = Patu_FS::read( $path );
					if ( false === $orig ) {
						$failed++;
						continue 2;
					}
				}
				$orig_len = strlen( $orig );
				$timeout  = ( $budget > 0 ) ? max( 3, (int) ( $budget - ( microtime( true ) - $start ) ) ) : null;
				$res      = Patu_API::compress( $orig, $mime, $fmt, $timeout );
				if ( is_wp_error( $res ) ) {
					$failed++;
					continue;
				}
				// Only keep a sibling that is smaller than the original and is
				// actually the format we asked for (per the API's own header).
				if ( $res['output_bytes'] >= $orig_len || $res['format'] !== $fmt ) {
					$meta['files'][ $rel ][ $fmt ] = 0; // tried, not worth it.
					continue;
				}
				if ( ! Patu_FS::write_atomic( $path . '.' . $fmt, $res['bytes'] ) ) {
					$failed++;
					continue;
				}
				$meta['files'][ $rel ][ $fmt ]        = $res['output_bytes'];
				$meta['files'][ $rel ]['path']        = $path;
				$saved                               += ( $orig_len - $res['output_bytes'] );
				$generated++;
			}
		}

		$meta['saved'] = (int) $meta['saved'] + $saved;
		$meta['at']    = time();
		update_post_meta( $attachment_id, self::META, $meta );

		$done = true;
		foreach ( $files as $p ) {
			$rel = Patu_Optimizer::rel_key( $p );
			foreach ( $targets as $fmt ) {
				if ( ! isset( $meta['files'][ $rel ][ $fmt ] ) ) {
					$done = false;
					break 2;
				}
			}
		}
		if ( $done ) {
			update_post_meta( $attachment_id, self::DONE, '1' );
		}

		if ( $generated > 0 ) {
			Patu_Stats::add( $generated, $saved );
		}

		return array( 'generated' => $generated, 'failed' => $failed, 'saved' => $saved );
	}

	/** Delete all generated siblings and forget the attachment. */
	public static function cleanup( $attachment_id ) {
		$meta = get_post_meta( $attachment_id, self::META, true );
		if ( ! is_array( $meta ) || empty( $meta['files'] ) ) {
			return array( 'deleted' => 0 );
		}
		$deleted = 0;
		$freed   = (int) ( isset( $meta['saved'] ) ? $meta['saved'] : 0 );
		foreach ( $meta['files'] as $info ) {
			if ( empty( $info['path'] ) ) {
				continue;
			}
			foreach ( array( 'avif', 'webp' ) as $fmt ) {
				if ( ! empty( $info[ $fmt ] ) ) {
					$sib = $info['path'] . '.' . $fmt;
					if ( Patu_FS::exists( $sib ) ) {
						Patu_FS::delete( $sib );
						$deleted++;
					}
				}
			}
		}
		Patu_Stats::subtract( $deleted, $freed );
		delete_post_meta( $attachment_id, self::META );
		delete_post_meta( $attachment_id, self::DONE );
		return array( 'deleted' => $deleted );
	}

	public static function is_generated( $attachment_id ) {
		return (bool) get_post_meta( $attachment_id, self::DONE, true );
	}

	/** Summary for the media column. */
	public static function status( $attachment_id ) {
		$meta = get_post_meta( $attachment_id, self::META, true );
		if ( ! is_array( $meta ) || empty( $meta['files'] ) ) {
			return array( 'generated' => false );
		}
		$saved = (int) ( isset( $meta['saved'] ) ? $meta['saved'] : 0 );
		$fmts  = array();
		foreach ( $meta['files'] as $info ) {
			foreach ( array( 'avif', 'webp' ) as $fmt ) {
				if ( ! empty( $info[ $fmt ] ) ) {
					$fmts[ $fmt ] = true;
				}
			}
		}
		return array(
			'generated' => true,
			'saved'     => $saved,
			'formats'   => array_keys( $fmts ),
		);
	}
}
