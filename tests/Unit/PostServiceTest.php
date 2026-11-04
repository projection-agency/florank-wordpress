<?php
/**
 * @package Florank
 */

namespace Florank\Tests\Unit;

use Florank\Posts\PostService;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class PostServiceTest extends TestCase {

	/**
	 * @dataProvider data_statuses
	 */
	public function test_sanitize_status( $input, $expected ) {
		$this->assertSame( $expected, PostService::sanitize_status( $input ) );
	}

	public function data_statuses() {
		return array(
			'draft'      => array( 'draft', 'draft' ),
			'publish'    => array( 'publish', 'publish' ),
			'future'     => array( 'future', 'future' ),
			'upper case' => array( 'PUBLISH', 'publish' ),
			'empty'      => array( '', 'draft' ),
			'null'       => array( null, 'draft' ),
			'private'    => array( 'private', 'draft' ),
			'trash'      => array( 'trash', 'draft' ),
			'injection'  => array( 'publish"; DROP', 'draft' ),
		);
	}
}
