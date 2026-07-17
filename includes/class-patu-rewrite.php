<?php
/**
 * Front-end delivery: wrap each <img> that has next-gen siblings in a <picture>
 * so browsers pull the AVIF (or WebP) and everyone else keeps the original. The
 * whole page is rewritten from an output buffer, so it works no matter which
 * theme or page builder produced the HTML.
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Patu_Rewrite {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_buffer' ) );
	}

	public static function maybe_buffer() {
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		if ( ! apply_filters( 'patu_rewrite_enabled', true ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'rewrite' ) );
	}

	/** Rewrite a full HTML document. */
	public static function rewrite( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		// Leave existing <picture> blocks alone.
		$saved = array();
		$html  = preg_replace_callback(
			'#<picture\b.*?</picture>#is',
			function ( $m ) use ( &$saved ) {
				$key           = '<!--patu-pic-' . count( $saved ) . '-->';
				$saved[ $key ] = $m[0];
				return $key;
			},
			$html
		);

		$html = preg_replace_callback( '#<img\b[^>]*>#i', array( __CLASS__, 'rewrite_img' ), $html );

		if ( $saved ) {
			$html = strtr( $html, $saved );
		}
		return $html;
	}

	private static function rewrite_img( $m ) {
		$tag = $m[0];
		$src = self::attr( $tag, 'src' );
		if ( '' === $src ) {
			return $tag;
		}
		$avif = self::sibling_url( $src, 'avif' );
		if ( '' === $avif ) {
			return $tag; // no next-gen sibling — leave it exactly as it was.
		}

		$sizes  = self::attr( $tag, 'sizes' );
		$srcset = self::attr( $tag, 'srcset' );

		$sources  = self::source( 'image/avif', self::map_srcset( $srcset, 'avif', $avif ), $sizes );
		$webp     = self::sibling_url( $src, 'webp' );
		if ( '' !== $webp ) {
			$sources .= self::source( 'image/webp', self::map_srcset( $srcset, 'webp', $webp ), $sizes );
		}

		return '<picture>' . $sources . $tag . '</picture>';
	}

	private static function source( $type, $srcset, $sizes ) {
		$out = '<source type="' . esc_attr( $type ) . '" srcset="' . esc_attr( $srcset ) . '"';
		if ( '' !== $sizes ) {
			$out .= ' sizes="' . esc_attr( $sizes ) . '"';
		}
		return $out . '>';
	}

	/**
	 * Map an <img>'s srcset to the next-gen format, falling back to the single
	 * sibling when the srcset can't be fully mapped.
	 */
	private static function map_srcset( $srcset, $fmt, $single ) {
		if ( '' === $srcset ) {
			return $single;
		}
		$out = array();
		foreach ( explode( ',', $srcset ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$bits = preg_split( '/\s+/', $part, 2 );
			$sib  = self::sibling_url( $bits[0], $fmt );
			if ( '' === $sib ) {
				return $single; // one candidate has no sibling — don't risk a broken srcset.
			}
			$out[] = $sib . ( isset( $bits[1] ) ? ' ' . $bits[1] : '' );
		}
		return $out ? implode( ', ', $out ) : $single;
	}

	/** The sibling URL for an image URL, or '' when there is no sibling on disk. */
	private static function sibling_url( $url, $fmt ) {
		$clean = strtok( $url, '?' );
		$path  = self::url_to_path( $clean );
		if ( '' === $path ) {
			return '';
		}
		return Patu_FS::exists( $path . '.' . $fmt ) ? $clean . '.' . $fmt : '';
	}

	/** Map a URL under the uploads directory to its absolute path, or '' if outside. */
	private static function url_to_path( $url ) {
		static $base = null;
		static $dir  = null;
		if ( null === $base ) {
			$u    = wp_get_upload_dir();
			$base = preg_replace( '#^https?:#', '', $u['baseurl'] );
			$dir  = untrailingslashit( $u['basedir'] );
		}
		$u = preg_replace( '#^https?:#', '', $url );
		if ( 0 !== strpos( $u, $base ) ) {
			return '';
		}
		$rel = substr( $u, strlen( $base ) );
		if ( false !== strpos( $rel, '..' ) ) {
			return '';
		}
		return $dir . $rel;
	}

	private static function attr( $tag, $name ) {
		$q = preg_quote( $name, '/' );
		if ( preg_match( '/\b' . $q . '\s*=\s*"([^"]*)"/i', $tag, $m ) ) {
			return $m[1];
		}
		if ( preg_match( "/\b" . $q . "\s*=\s*'([^']*)'/i", $tag, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
