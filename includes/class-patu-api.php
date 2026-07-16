<?php
/**
 * Thin client over the Patu HTTP API using WordPress's own HTTP layer. Every
 * method returns a result array or a WP_Error; it never throws, so a caller
 * optimizing many files never has one failure abort the batch. The API key is
 * sent only in the X-Api-Key header and is never logged.
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Patu_API {

	const ENDPOINT = 'https://patu.dev';

	/**
	 * Compress raw image bytes, requesting a specific output format (same-format
	 * optimization: jpeg -> jpeg, webp -> webp).
	 *
	 * @param string $bytes        Raw image bytes.
	 * @param string $content_type e.g. image/jpeg.
	 * @param string $format       Output encoder, e.g. jpeg or webp.
	 * @return array|WP_Error { bytes, format, output_bytes } or WP_Error.
	 */
	public static function compress( $bytes, $content_type, $format ) {
		$key = Patu_Key::get();
		if ( '' === $key ) {
			return new WP_Error( 'patu_no_key', __( 'No Patu API key configured.', 'patu' ) );
		}

		$endpoint = untrailingslashit( (string) apply_filters( 'patu_endpoint', self::ENDPOINT ) );
		$url      = $endpoint . '/v1/compress?formats=' . rawurlencode( $format );

		$res = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'X-Api-Key'    => $key,
					'Content-Type' => $content_type,
				),
				'body'    => $bytes,
				'timeout' => (int) apply_filters( 'patu_timeout', 30 ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$body = wp_strip_all_tags( (string) wp_remote_retrieve_body( $res ) );
			return new WP_Error(
				'patu_http_' . $code,
				/* translators: 1: HTTP status code, 2: response body. */
				sprintf( __( 'Patu API returned HTTP %1$d: %2$s', 'patu' ), $code, mb_substr( $body, 0, 200 ) )
			);
		}

		$out = (string) wp_remote_retrieve_body( $res );
		if ( '' === $out ) {
			return new WP_Error( 'patu_empty', __( 'Patu API returned an empty body.', 'patu' ) );
		}

		return array(
			'bytes'        => $out,
			'format'       => (string) wp_remote_retrieve_header( $res, 'x-patu-format' ),
			'output_bytes' => strlen( $out ),
		);
	}

	/**
	 * A tiny end-to-end check for the settings "test connection" button. Sends a
	 * small generated JPEG and reports success or the error.
	 *
	 * @return true|WP_Error
	 */
	public static function test_connection() {
		$jpeg = self::sample_jpeg();
		if ( '' === $jpeg ) {
			// No GD to build a sample; fall back to a key-presence check.
			return Patu_Key::is_set() ? true : new WP_Error( 'patu_no_key', __( 'No Patu API key configured.', 'patu' ) );
		}
		$res = self::compress( $jpeg, 'image/jpeg', 'jpeg' );
		return is_wp_error( $res ) ? $res : true;
	}

	/** A small gradient JPEG via GD, or '' when GD is unavailable. */
	private static function sample_jpeg() {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagejpeg' ) ) {
			return '';
		}
		$im = imagecreatetruecolor( 64, 64 );
		for ( $y = 0; $y < 64; $y++ ) {
			for ( $x = 0; $x < 64; $x++ ) {
				imagesetpixel( $im, $x, $y, imagecolorallocate( $im, $x * 4, $y * 4, 128 ) );
			}
		}
		ob_start();
		imagejpeg( $im, null, 90 );
		$bytes = (string) ob_get_clean();
		imagedestroy( $im );
		return $bytes;
	}
}
