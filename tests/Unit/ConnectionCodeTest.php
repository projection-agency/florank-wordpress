<?php
/**
 * @package Florank
 */

namespace Florank\Tests\Unit;

use Florank\Auth\ConnectionCode;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class ConnectionCodeTest extends TestCase {

	const KEY = 'fl_0123456789abcdef0123456789abcdef0123456789abcdef';

	protected function set_up() {
		parent::set_up();
		florank_test_reset();
	}

	public function test_build_matches_the_documented_format() {
		$code = ConnectionCode::build( 'https://site.example', self::KEY );

		$this->assertStringStartsWith( 'flc1_', $code );
		$this->assertMatchesRegularExpression( '/^flc1_[A-Za-z0-9_-]+$/', $code );
		$json = base64_decode( strtr( substr( $code, 5 ), '-_', '+/' ) );
		$this->assertSame( '{"u":"https://site.example","k":"' . self::KEY . '"}', $json );
	}

	public function test_known_vector_from_api_contract() {
		// Same vector as docs/api-contract.md and the Florank frontend tests.
		$this->assertSame(
			'flc1_eyJ1IjoiaHR0cHM6Ly9zaXRlLmV4YW1wbGUiLCJrIjoiZmxfMDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWYwMTIzNDU2Nzg5YWJjZGVmIn0',
			ConnectionCode::build( 'https://site.example', self::KEY )
		);
	}

	public function test_build_strips_trailing_slash() {
		$parsed = ConnectionCode::parse( ConnectionCode::build( 'https://site.example/blog/', self::KEY ) );
		$this->assertSame( 'https://site.example/blog', $parsed['u'] );
	}

	public function test_round_trip_for_various_addresses() {
		$addresses = array(
			'https://site.example',
			'http://127.0.0.1:8080',
			'https://site.example/sub/dir',
			'https://xn--80ak6aa92e.com',
			'https://сайт.укр',
		);
		foreach ( $addresses as $address ) {
			$parsed = ConnectionCode::parse( ConnectionCode::build( $address, self::KEY ) );
			$this->assertSame(
				array(
					'u' => $address,
					'k' => self::KEY,
				),
				$parsed,
				$address
			);
		}
	}

	public function test_site_address_is_home_url_without_trailing_slash() {
		update_option( 'home', 'https://site.example/blog/' );
		$this->assertSame( 'https://site.example/blog', ConnectionCode::site_address() );
	}

	public function test_base64url_has_no_padding_or_unsafe_chars() {
		// Lengths 1..6 cover every padding case; 0xfb 0xff produce "+" and "/" in base64.
		for ( $len = 1; $len <= 6; $len++ ) {
			$bytes   = str_repeat( "\xfb\xff", 3 ) . substr( 'abcdef', 0, $len );
			$encoded = ConnectionCode::base64url_encode( $bytes );
			$this->assertDoesNotMatchRegularExpression( '/[+\/=]/', $encoded );
			$this->assertSame( $bytes, ConnectionCode::base64url_decode( $encoded ) );
		}
		$this->assertSame( '-_8', ConnectionCode::base64url_encode( "\xfb\xff" ) );
	}

	public function test_base64url_decode_accepts_padding_and_rejects_garbage() {
		$this->assertSame( 'ab', ConnectionCode::base64url_decode( 'YWI=' ) );
		$this->assertSame( 'ab', ConnectionCode::base64url_decode( 'YWI' ) );
		$this->assertNull( ConnectionCode::base64url_decode( '' ) );
		$this->assertNull( ConnectionCode::base64url_decode( 'YW+I' ) );
		$this->assertNull( ConnectionCode::base64url_decode( 'Y' ) );
		$this->assertNull( ConnectionCode::base64url_decode( 'YW I' ) );
	}

	public function test_parse_rejects_invalid_codes() {
		$valid = ConnectionCode::build( 'https://site.example', self::KEY );
		$bad   = array(
			'',
			self::KEY,
			'flc2_' . substr( $valid, 5 ),
			'flc1_',
			'flc1_!!!',
			'flc1_' . ConnectionCode::base64url_encode( 'not json' ),
			'flc1_' . ConnectionCode::base64url_encode( '{"u":"https://site.example"}' ),
			'flc1_' . ConnectionCode::base64url_encode( '{"u":"","k":"' . self::KEY . '"}' ),
			'flc1_' . ConnectionCode::base64url_encode( '{"u":"https://site.example","k":"wrong"}' ),
			'flc1_' . ConnectionCode::base64url_encode( '{"u":1,"k":"' . self::KEY . '"}' ),
		);
		foreach ( $bad as $code ) {
			$this->assertNull( ConnectionCode::parse( $code ), $code );
		}
		$this->assertNotNull( ConnectionCode::parse( '  ' . $valid . "\n" ) );
	}
}
