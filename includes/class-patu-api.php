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
	 * @param string   $bytes        Raw image bytes.
	 * @param string   $content_type e.g. image/jpeg.
	 * @param string   $format       Output encoder, e.g. jpeg or webp.
	 * @param int|null $timeout      Per-request timeout in seconds (null = default).
	 * @return array|WP_Error { bytes, format, output_bytes } or WP_Error.
	 */
	public static function compress( $bytes, $content_type, $format, $timeout = null ) {
		$key = Patu_Key::get();
		if ( '' === $key ) {
			return new WP_Error( 'patu_no_key', __( 'No Patu API key configured.', 'patu' ) );
		}

		$endpoint = untrailingslashit( (string) apply_filters( 'patu_endpoint', self::ENDPOINT ) );
		$url      = $endpoint . '/v1/compress?formats=' . rawurlencode( $format );
		if ( null === $timeout ) {
			$timeout = (int) apply_filters( 'patu_timeout', 30 );
		}

		$res = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'X-Api-Key'    => $key,
					'Content-Type' => $content_type,
				),
				'body'    => $bytes,
				'timeout' => max( 1, (int) $timeout ),
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
	 * A real end-to-end check for the settings "test connection" button. Sends a
	 * small embedded JPEG (so it does not depend on GD and always exercises the
	 * key against the API) and reports success or the error.
	 *
	 * @return true|WP_Error
	 */
	public static function test_connection() {
		$jpeg = base64_decode( self::SAMPLE_JPEG_B64, true );
		if ( false === $jpeg ) {
			return new WP_Error( 'patu_internal', __( 'Could not build a test image.', 'patu' ) );
		}
		$res = self::compress( $jpeg, 'image/jpeg', 'jpeg', 15 );
		return is_wp_error( $res ) ? $res : true;
	}

	/** An 8x8 JPEG used only by test_connection(). */
	const SAMPLE_JPEG_B64 = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgACAAIAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8Aw/C3w/8Aufuf0ooorzMZmuJ9o/eN+HM6xf1KPvH/2Q==';
}
