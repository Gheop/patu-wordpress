<?php
/**
 * Front-end delivery: wrap each <img> that has next-gen siblings in a <picture>
 * so browsers pull the AVIF (or WebP) and everyone else keeps the original. The
 * whole page is rewritten from an output buffer, so it works no matter which
 * theme or page builder produced the HTML.
 *
 * The rewriter only touches real <img> tags in HTML flow content: it runs only
 * for text/html responses, masks <script>/<style>/<textarea>/<noscript>/comment
 * blocks and existing <picture> tags first, and matches <img> with a
 * quote-aware pattern so a "greater-than" inside an attribute can't truncate a
 * tag. Placeholders use an unguessable token so page content can't forge one.
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
		// Flags 0: not flushable, so the callback sees the whole document once at
		// the end rather than partial chunks on an early flush().
		ob_start( array( __CLASS__, 'rewrite' ), 0, 0 );
	}

	/** Rewrite a full HTML document. */
	public static function rewrite( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<img' ) ) {
			return $html;
		}
		if ( ! self::is_html_response() ) {
			return $html;
		}

		$token = uniqid( '', true );
		$store = array();

		// Mask everything the <img> pass must not touch. Comments first so our
		// own placeholder comments (if any theme uses that pattern) are already
		// out; then raw-text and existing <picture> blocks.
		$mask = static function ( $pattern ) use ( &$html, &$store, $token ) {
			$html = preg_replace_callback(
				$pattern,
				static function ( $m ) use ( &$store, $token ) {
					$key           = "\x01patu-{$token}-" . count( $store ) . "\x01";
					$store[ $key ] = $m[0];
					return $key;
				},
				$html
			);
		};
		$mask( '#<!--.*?-->#s' );
		$mask( '#<script\b.*?</script>#is' );
		$mask( '#<style\b.*?</style>#is' );
		$mask( '#<textarea\b.*?</textarea>#is' );
		$mask( '#<noscript\b.*?</noscript>#is' );
		$mask( '#<picture\b.*?</picture>#is' );

		// Quote-aware: a ">" inside a "quoted" or 'quoted' attribute value is part
		// of the value, not the end of the tag.
		$html = preg_replace_callback( '#<img\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>#i', array( __CLASS__, 'rewrite_img' ), $html );

		if ( $store ) {
			$html = strtr( $html, $store );
		}
		return $html;
	}

	/** Only rewrite a text/html response (default when no Content-Type is set). */
	private static function is_html_response() {
		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'content-type:' ) ) {
				return false !== stripos( $h, 'text/html' );
			}
		}
		return true;
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

		$sources = self::source( 'image/avif', self::map_srcset( $srcset, 'avif', $avif ), $sizes );
		$webp    = self::sibling_url( $src, 'webp' );
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

	/**
	 * Map a URL under the uploads directory to its absolute path, or '' if it is
	 * outside. Handles host-absolute and root-relative URLs, and requires a "/"
	 * boundary so a sibling dir like "uploads-staging" cannot match "uploads".
	 */
	private static function url_to_path( $url ) {
		static $abs = null;
		static $rootpath = null;
		static $dir = null;
		if ( null === $abs ) {
			$u        = wp_get_upload_dir();
			$abs      = preg_replace( '#^https?:#', '', $u['baseurl'] );          // //host/wp-content/uploads
			$rootpath = (string) wp_parse_url( $u['baseurl'], PHP_URL_PATH );     // /wp-content/uploads
			$dir      = untrailingslashit( $u['basedir'] );
		}

		$rel     = null;
		$no_prot = preg_replace( '#^https?:#', '', $url );
		if ( 0 === strpos( $no_prot, $abs . '/' ) ) {
			$rel = substr( $no_prot, strlen( $abs ) );
		} elseif ( '' !== $rootpath && 0 === strpos( $url, $rootpath . '/' ) ) {
			$rel = substr( $url, strlen( $rootpath ) );
		}
		if ( null === $rel || false !== strpos( $rel, '..' ) ) {
			return '';
		}
		return $dir . $rel;
	}

	private static function attr( $tag, $name ) {
		$q = preg_quote( $name, '/' );
		// (?<![-\w]) so "src" does not match inside "data-src".
		if ( preg_match( '/(?<![-\w])' . $q . '\s*=\s*"([^"]*)"/i', $tag, $m ) ) {
			return $m[1];
		}
		if ( preg_match( "/(?<![-\\w])" . $q . "\s*=\s*'([^']*)'/i", $tag, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
