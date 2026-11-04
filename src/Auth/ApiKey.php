<?php
/**
 * API key used by Florank to call this site's REST endpoints.
 *
 * @package Florank
 */

namespace Florank\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Generates, stores (as a password hash) and verifies the Florank API key.
 *
 * Option names are part of the upgrade contract with v1.x — existing
 * connections keep working after the update, so they must not change.
 */
class ApiKey {

	const OPTION_HASH   = 'florank_api_key_hash';
	const OPTION_HINT   = 'florank_api_key_hint';
	const TRANSIENT_NEW = 'florank_new_api_key';
	const KEY_PREFIX    = 'fl_';

	/**
	 * Creates the empty options on first activation (same as v1.x).
	 */
	public static function ensure_options() {
		if ( ! get_option( self::OPTION_HASH ) ) {
			add_option( self::OPTION_HASH, '' );
			add_option( self::OPTION_HINT, '' );
		}
	}

	/**
	 * Generates a new key, stores its hash and hint, and keeps the plain key
	 * in a short-lived transient so the settings page can show it once.
	 *
	 * @return string The plain key.
	 */
	public function generate() {
		$plain = self::KEY_PREFIX . bin2hex( random_bytes( 24 ) );
		update_option( self::OPTION_HASH, wp_hash_password( $plain ) );
		update_option( self::OPTION_HINT, self::hint_for( $plain ) );
		set_transient( self::TRANSIENT_NEW, $plain, 10 * MINUTE_IN_SECONDS );
		return $plain;
	}

	/**
	 * Revokes the active key: requests with it are rejected from now on.
	 */
	public function revoke() {
		update_option( self::OPTION_HASH, '' );
		update_option( self::OPTION_HINT, '' );
		delete_transient( self::TRANSIENT_NEW );
	}

	/**
	 * Whether a key is active.
	 *
	 * @return bool
	 */
	public function exists() {
		return '' !== (string) get_option( self::OPTION_HASH, '' );
	}

	/**
	 * Short visible prefix of a key, e.g. "fl_ab12…".
	 *
	 * @param string $plain Plain key.
	 * @return string
	 */
	public static function hint_for( $plain ) {
		return substr( $plain, 0, 7 ) . '…';
	}

	/**
	 * Plain key generated in the last few minutes, if any.
	 *
	 * @return string
	 */
	public function pending_plain_key() {
		$plain = get_transient( self::TRANSIENT_NEW );
		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Hint of the active key, or empty string.
	 *
	 * @return string
	 */
	public function hint() {
		return (string) get_option( self::OPTION_HINT, '' );
	}

	/**
	 * Extracts the key from request headers: "Authorization: Bearer <key>"
	 * first, then "X-Florank-Key".
	 *
	 * @param string|null $authorization Authorization header value.
	 * @param string|null $florank_key   X-Florank-Key header value.
	 * @return string
	 */
	public static function extract( $authorization, $florank_key ) {
		$header = (string) $authorization;
		if ( preg_match( '/^Bearer\s+(\S+)/i', $header, $m ) ) {
			return trim( $m[1] );
		}
		return trim( (string) $florank_key );
	}

	/**
	 * Checks a plain key against the stored hash.
	 *
	 * @param string $key Plain key from the request.
	 * @return bool
	 */
	public function verify( $key ) {
		$hash = (string) get_option( self::OPTION_HASH, '' );
		if ( '' === $key || '' === $hash ) {
			return false;
		}
		return (bool) wp_check_password( $key, $hash );
	}
}
