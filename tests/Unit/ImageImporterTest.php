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
}
