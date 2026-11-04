<?php
/**
 * @package Florank
 */

namespace Florank\Tests\Unit;

use Florank\Media\ImageImporter;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class ImageImporterTest extends TestCase {

	public function test_extracts_html_and_markdown_images_once() {
		$content = '<p><img class="a" src="https://cdn.example/a.png" alt=""></p>'
			. "<img src='https://cdn.example/b.jpg'>"
			. '![alt](https://cdn.example/c.webp) ![dup](https://cdn.example/a.png)'
			. '<img src="https://cdn.example/a.png">';

		$this->assertSame(
			array( 'https://cdn.example/a.png', 'https://cdn.example/b.jpg', 'https://cdn.example/c.webp' ),
			ImageImporter::extract_content_image_urls( $content )
		);
	}

	public function test_ignores_non_http_markdown_and_links() {
		$content = '![x](data:image/png;base64,AAAA) [link](https://example.com/page) <a href="https://x.example">x</a>';
		$this->assertSame( array(), ImageImporter::extract_content_image_urls( $content ) );
	}

	protected function set_up() {
		parent::set_up();
		florank_test_reset();
	}

	/**
	 * @dataProvider data_urls
	 */
	public function test_only_https_florank_hosts_are_allowed( $url, $allowed ) {
		$this->assertSame( $allowed, ImageImporter::is_allowed_url( $url ) );
	}

	public function data_urls() {
		return array(
			'vercel blob'       => array( 'https://abc123.public.blob.vercel-storage.com/sites/a/featured-0.png', true ),
			'api.florank.ai'    => array( 'https://api.florank.ai/media/x.png', true ),
			'florank.ai'        => array( 'https://florank.ai/images/x.webp', true ),
			'upper case host'   => array( 'https://API.FLORANK.AI/media/x.png', true ),
			'http'              => array( 'http://api.florank.ai/media/x.png', false ),
			'other host'        => array( 'https://evil.example/x.png', false ),
			'suffix trick'      => array( 'https://api.florank.ai.evil.example/x.png', false ),
			'prefix trick'      => array( 'https://evilflorank.ai/x.png', false ),
			'bare blob domain'  => array( 'https://blob.vercel-storage.com/x.png', false ),
			'metadata ip'       => array( 'https://169.254.169.254/latest/meta-data', false ),
			'credentials'       => array( 'https://user:pass@api.florank.ai/x.png', false ),
			'other port'        => array( 'https://api.florank.ai:8443/x.png', false ),
			'not a url'         => array( 'javascript:alert(1)', false ),
			'protocol relative' => array( '//api.florank.ai/x.png', false ),
		);
	}

	public function test_allowed_hosts_can_be_filtered() {
		$this->assertFalse( ImageImporter::is_allowed_url( 'https://cdn.example.com/x.png' ) );
		add_filter(
			'florank_allowed_image_hosts',
			static function ( $hosts ) {
				$hosts[] = 'cdn.example.com';
				return $hosts;
			}
		);
		$this->assertTrue( ImageImporter::is_allowed_url( 'https://cdn.example.com/x.png' ) );
	}

	public function test_mime_allow_list() {
		foreach ( array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ) as $mime ) {
			$this->assertTrue( ImageImporter::is_allowed_mime( $mime ), $mime );
		}
		foreach ( array( 'image/svg+xml', 'text/html', 'application/x-php', '', false, null ) as $mime ) {
			$this->assertFalse( ImageImporter::is_allowed_mime( $mime ) );
		}
	}

	public function test_max_bytes_default_and_filter() {
		$this->assertSame( 10 * 1024 * 1024, ImageImporter::max_bytes() );
		add_filter(
			'florank_max_image_bytes',
			static function () {
				return 1024;
			}
		);
		$this->assertSame( 1024, ImageImporter::max_bytes() );
	}
}
