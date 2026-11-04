<?php
/**
 * IndexNow key file.
 *
 * @package Florank
 */

namespace Florank\IndexNow;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the Florank IndexNow key and serves it at /{key}.txt, as the
 * protocol requires a key file at the host root.
 */
class IndexNow {

	const OPTION_KEY = 'florank_indexnow_key';

	/**
	 * Whether a value is an acceptable IndexNow key.
	 *
	 * @param mixed $key Candidate key.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[a-zA-Z0-9-]{8,128}\z/', $key );
	}

	/**
	 * Saves the key from the request when it is valid; ignores it otherwise.
	 *
	 * @param mixed $key "indexnow_key" field from the request.
	 */
	public function store_key( $key ) {
		$key = is_scalar( $key ) ? (string) $key : '';
		if ( self::is_valid_key( $key ) ) {
			update_option( self::OPTION_KEY, $key, false );
		}
	}

	/**
	 * Whether a request path asks for the key file.
	 *
	 * @param string $path Request path.
	 * @param string $key  Stored key.
	 * @return bool
	 */
	public static function is_key_path( $path, $key ) {
		return '' !== $key && '/' . $key . '.txt' === $path;
	}

	/**
	 * Hook: init (priority 0). Answers /{key}.txt with the key.
	 */
	public function maybe_serve_key() {
		$key = (string) get_option( self::OPTION_KEY, '' );
		if ( '' === $key ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! self::is_key_path( $path, $key ) ) {
			return;
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $key );
		exit;
	}
}
