<?php
/**
 * @package Florank
 */

namespace Florank\Tests\Unit;

use Florank\IndexNow\IndexNow;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class IndexNowTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		florank_test_reset();
	}

	/**
	 * @dataProvider data_keys
	 */
	public function test_is_valid_key( $key, $expected ) {
		$this->assertSame( $expected, IndexNow::is_valid_key( $key ) );
	}

	public function data_keys() {
		return array(
			'hex 32'        => array( 'a1b2c3d4e5f60718293a4b5c6d7e8f90', true ),
			'with dashes'   => array( 'abcd-1234-efgh', true ),
			'min length 8'  => array( 'abcdefgh', true ),
			'too short'     => array( 'abc1234', false ),
			'too long'      => array( str_repeat( 'a', 129 ), false ),
			'path traverse' => array( '../../etc/passwd', false ),
			'html'          => array( '<script>x</script>', false ),
			'newline'       => array( "abcdefgh\n", false ),
			'not a string'  => array( 12345678, false ),
		);
	}

	public function test_store_key_saves_only_valid_keys() {
		$index_now = new IndexNow();

		$index_now->store_key( 'bad key' );
		$this->assertFalse( get_option( IndexNow::OPTION_KEY ) );

		$index_now->store_key( 'goodkey-123' );
		$this->assertSame( 'goodkey-123', get_option( IndexNow::OPTION_KEY ) );

		$index_now->store_key( array( 'x' ) );
		$this->assertSame( 'goodkey-123', get_option( IndexNow::OPTION_KEY ) );
	}

	public function test_is_key_path() {
		$this->assertTrue( IndexNow::is_key_path( '/goodkey-123.txt', 'goodkey-123' ) );
		$this->assertFalse( IndexNow::is_key_path( '/goodkey-123.txt/', 'goodkey-123' ) );
		$this->assertFalse( IndexNow::is_key_path( '/sub/goodkey-123.txt', 'goodkey-123' ) );
		$this->assertFalse( IndexNow::is_key_path( '/.txt', '' ) );
	}
}
