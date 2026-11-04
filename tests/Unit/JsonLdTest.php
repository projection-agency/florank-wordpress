<?php
/**
 * @package Florank
 */

namespace Florank\Tests\Unit;

use Florank\Seo\JsonLd;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class JsonLdTest extends TestCase {

	public function test_meta_key_is_unchanged_from_v1() {
		$this->assertSame( '_florank_schema_json_ld', JsonLd::META_KEY );
	}

	public function test_decode_items_wraps_single_object() {
		$items = JsonLd::decode_items( '{"@type":"BlogPosting","headline":"A"}' );
		$this->assertCount( 1, $items );
		$this->assertSame( 'A', $items[0]['headline'] );
	}

	public function test_decode_items_keeps_list() {
		$items = JsonLd::decode_items( '[{"@type":"BlogPosting"},{"@type":"FAQPage"}]' );
		$this->assertCount( 2, $items );
	}

	/**
	 * @dataProvider data_invalid_raw
	 */
	public function test_decode_items_returns_empty_for_invalid( $raw ) {
		$this->assertSame( array(), JsonLd::decode_items( $raw ) );
	}

	public function data_invalid_raw() {
		return array(
			'empty'    => array( '' ),
			'false'    => array( false ),
			'not json' => array( '{broken' ),
			'scalar'   => array( '"text"' ),
		);
	}

	public function test_apply_post_facts_overrides_blog_posting_only() {
		$items = array(
			array(
				'@type'         => 'BlogPosting',
				'url'           => 'https://old.example/draft',
				'datePublished' => 'old',
			),
			array(
				'@type' => 'FAQPage',
				'url'   => 'https://keep.example',
			),
			'not-an-array',
		);

		$result = JsonLd::apply_post_facts( $items, 'https://site.example/post/', '2026-11-01T10:00:00+00:00', '2026-11-02T10:00:00+00:00', 'https://site.example/img.png' );

		$this->assertSame( 'https://site.example/post/', $result[0]['url'] );
		$this->assertSame(
			array(
				'@type' => 'WebPage',
				'@id'   => 'https://site.example/post/',
			),
			$result[0]['mainEntityOfPage']
		);
		$this->assertSame( '2026-11-01T10:00:00+00:00', $result[0]['datePublished'] );
		$this->assertSame( '2026-11-02T10:00:00+00:00', $result[0]['dateModified'] );
		$this->assertSame( array( 'https://site.example/img.png' ), $result[0]['image'] );
		$this->assertSame( 'https://keep.example', $result[1]['url'] );
		$this->assertSame( 'not-an-array', $result[2] );
	}

	public function test_apply_post_facts_keeps_image_without_thumbnail() {
		$items  = array(
			array(
				'@type' => 'BlogPosting',
				'image' => array( 'https://cdn.example/a.png' ),
			),
		);
		$result = JsonLd::apply_post_facts( $items, 'https://site.example/p/', 'a', 'b', false );
		$this->assertSame( array( 'https://cdn.example/a.png' ), $result[0]['image'] );
	}

	public function test_script_tag_cannot_be_closed_by_content() {
		$tag = JsonLd::script_tag(
			array(
				'@type'    => 'BlogPosting',
				'headline' => 'Хак </script><script>alert(1)</script>',
				'url'      => 'https://site.example/a/b',
			)
		);

		$this->assertStringStartsWith( '<script type="application/ld+json">', $tag );
		$this->assertSame( 1, substr_count( $tag, '</script>' ) );
		$this->assertStringContainsString( 'Хак', $tag );
		$this->assertStringContainsString( 'https://site.example/a/b', $tag );
	}
}
