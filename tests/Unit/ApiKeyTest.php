<?php
/**
 * @package Florank
 */

namespace Florank\Tests\Unit;

use Florank\Auth\ApiKey;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class ApiKeyTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		florank_test_reset();
	}

	public function test_option_names_are_unchanged_from_v1() {
		$this->assertSame( 'florank_api_key_hash', ApiKey::OPTION_HASH );
		$this->assertSame( 'florank_api_key_hint', ApiKey::OPTION_HINT );
		$this->assertSame( 'florank_new_api_key', ApiKey::TRANSIENT_NEW );
	}

	public function test_generate_stores_hash_hint_and_pending_key() {
		$api_key = new ApiKey();
		$plain   = $api_key->generate();

		$this->assertMatchesRegularExpression( '/^fl_[0-9a-f]{48}$/', $plain );
		$this->assertNotSame( $plain, get_option( ApiKey::OPTION_HASH ) );
		$this->assertSame( substr( $plain, 0, 7 ) . '…', $api_key->hint() );
		$this->assertSame( $plain, $api_key->pending_plain_key() );
		$this->assertTrue( $api_key->verify( $plain ) );
	}

	public function test_rotation_invalidates_previous_key() {
		$api_key = new ApiKey();
		$old     = $api_key->generate();
		$new     = $api_key->generate();

		$this->assertFalse( $api_key->verify( $old ) );
		$this->assertTrue( $api_key->verify( $new ) );
	}

	public function test_verify_rejects_empty_key_and_missing_hash() {
		$api_key = new ApiKey();
		$this->assertFalse( $api_key->verify( 'fl_anything' ) );

		$api_key->generate();
		$this->assertFalse( $api_key->verify( '' ) );
		$this->assertFalse( $api_key->verify( 'fl_wrong' ) );
	}

	public function test_hash_from_v1_keeps_working() {
		// v1.x stored wp_hash_password( $plain ) under the same option.
		update_option( ApiKey::OPTION_HASH, wp_hash_password( 'fl_legacy_key' ) );
		$this->assertTrue( ( new ApiKey() )->verify( 'fl_legacy_key' ) );
	}

	public function test_ensure_options_creates_empty_options_once() {
		ApiKey::ensure_options();
		$this->assertSame( '', get_option( ApiKey::OPTION_HASH ) );
		$this->assertSame( '', get_option( ApiKey::OPTION_HINT ) );

		update_option( ApiKey::OPTION_HASH, 'existing-hash' );
		ApiKey::ensure_options();
		$this->assertSame( 'existing-hash', get_option( ApiKey::OPTION_HASH ) );
	}

	/**
	 * @dataProvider data_extract
	 */
	public function test_extract( $authorization, $florank_key, $expected ) {
		$this->assertSame( $expected, ApiKey::extract( $authorization, $florank_key ) );
	}

	public function data_extract() {
		return array(
			'bearer'                  => array( 'Bearer fl_abc', null, 'fl_abc' ),
			'bearer case-insensitive' => array( 'bearer   fl_abc', null, 'fl_abc' ),
			'bearer wins over header' => array( 'Bearer fl_abc', 'fl_other', 'fl_abc' ),
			'x-florank-key'           => array( null, ' fl_xyz ', 'fl_xyz' ),
			'basic auth falls back'   => array( 'Basic dXNlcjpwYXNz', 'fl_xyz', 'fl_xyz' ),
			'nothing'                 => array( null, null, '' ),
			'empty bearer'            => array( 'Bearer ', null, '' ),
		);
	}
}
