<?php
/**
 * JSON-LD output in <head>.
 *
 * @package Florank
 */

namespace Florank\Tests\Integration;

use Florank\Seo\JsonLd;
use Florank\Seo\SeoBridge;
use WP_UnitTestCase;

class JsonLdOutputTest extends WP_UnitTestCase {

	public function test_prints_blog_posting_with_real_permalink() {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta(
			$id,
			JsonLd::META_KEY,
			wp_slash(
				wp_json_encode(
					array(
						'@type' => 'BlogPosting',
						'url'   => 'https://old.example/',
					)
				)
			)
		);
		$this->go_to( get_permalink( $id ) );

		$json_ld = new JsonLd( new SeoBridge() );
		ob_start();
		$json_ld->print_head();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '#<script type="application/ld\+json">(.+)</script>#', $html );
		preg_match( '#<script type="application/ld\+json">(.+)</script>#', $html, $m );
		$item = json_decode( $m[1], true );
		$this->assertSame( get_permalink( $id ), $item['url'] );
		$this->assertSame( get_permalink( $id ), $item['mainEntityOfPage']['@id'] );
	}

	public function test_prints_nothing_on_pages_without_schema() {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $id ) );

		ob_start();
		( new JsonLd( new SeoBridge() ) )->print_head();
		$this->assertSame( '', ob_get_clean() );
	}
}
