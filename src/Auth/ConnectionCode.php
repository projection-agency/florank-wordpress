<?php
/**
 * Connection code: site address and API key in one string for Florank.
 *
 * @package Florank
 */

namespace Florank\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and parses the connection code the site owner pastes into Florank.
 *
 * Format (see docs/api-contract.md): "flc1_" + base64url( JSON {"u": site address, "k": API key} ),
 * without padding. "1" is the format version: a new format gets a new prefix.
 */
class ConnectionCode {

	const PREFIX = 'flc1_';

	/**
	 * Address Florank uses as baseUrl: requests go to {baseUrl}/wp-json/florank/v1,
	 * and WordPress serves /wp-json relative to home_url() (see get_rest_url()).
	 *
	 * @return string Site address without trailing slash.
	 */
	public static function site_address() {
		return untrailingslashit( home_url() );
	}

	/**
	 * Builds the code.
	 *
	 * @param string $site_address Site address (trailing slashes are removed).
	 * @param string $api_key      Plain API key.
	 * @return string
	 */
	public static function build( $site_address, $api_key ) {
		$json = wp_json_encode(
			array(
				'u' => untrailingslashit( (string) $site_address ),
				'k' => (string) $api_key,
			),
			JSON_UNESCAPED_SLASHES
		);
		return self::PREFIX . self::base64url_encode( (string) $json );
	}

	/**
	 * Parses a code. Used by tests and kept as the reference decoder for Florank.
	 *
	 * @param string $code Connection code.
	 * @return array{u: string, k: string}|null Null when the code is not valid.
	 */
	public static function parse( $code ) {
		$code = trim( (string) $code );
		if ( 0 !== strpos( $code, self::PREFIX ) ) {
			return null;
		}
		$json = self::base64url_decode( substr( $code, strlen( self::PREFIX ) ) );
		if ( null === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['u'], $data['k'] ) || ! is_string( $data['u'] ) || ! is_string( $data['k'] ) ) {
			return null;
		}
		if ( '' === $data['u'] || 0 !== strpos( $data['k'], ApiKey::KEY_PREFIX ) ) {
			return null;
		}
		return array(
			'u' => $data['u'],
			'k' => $data['k'],
		);
	}

	/**
	 * Base64url (RFC 4648 §5) without padding.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	public static function base64url_encode( $bytes ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data format, not obfuscation.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodes base64url with or without padding.
	 *
	 * @param string $text Base64url text.
	 * @return string|null Null when the text is not base64url.
	 */
	public static function base64url_decode( $text ) {
		if ( '' === $text || ! preg_match( '/^[A-Za-z0-9_-]+={0,2}$/', $text ) ) {
			return null;
		}
		$text = rtrim( $text, '=' );
		if ( 1 === strlen( $text ) % 4 ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- data format, not obfuscation.
		$bytes = base64_decode( strtr( $text, '-_', '+/' ), true );
		return false === $bytes ? null : $bytes;
	}
}
