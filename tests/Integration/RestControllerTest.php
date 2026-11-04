<?php
/**
 * REST contract tests against a real WordPress (wp-env).
 *
 * Payloads mirror WordpressAdapter.pluginBody() in audit-back; see docs/api-contract.md.
 *
 * @package Florank
 */

namespace Florank\Tests\Integration;

use Florank\Auth\ApiKey;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class RestControllerTest extends WP_UnitTestCase {

	/**
	 * @var string
	 */
	private $key;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		wp_set_current_user( 0 );
		$this->key = ( new ApiKey() )->generate();
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function request( $method, $route, $body = null, $key = null ) {
		$request = new WP_REST_Request( $method, '/florank/v1' . $route );
		$key     = null === $key ? $this->key : $key;
		if ( '' !== $key ) {
			$request->set_header( 'Authorization', 'Bearer ' . $key );
			$request->set_header( 'X-Florank-Key', $key );
		}
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function png_base64() {
		// 1x1 transparent PNG.
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
	}

	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/florank/v1/health', $routes );
		$this->assertArrayHasKey( '/florank/v1/posts', $routes );
		$this->assertArrayHasKey( '/florank/v1/posts/(?P<id>\d+)', $routes );
	}

	public function test_requests_without_valid_key_are_rejected() {
		foreach ( array( '', 'fl_wrong' ) as $key ) {
			$response = $this->request( 'GET', '/health', null, $key );
			$this->assertSame( 401, $response->get_status() );
			$this->assertSame( 'florank_unauthorized', $response->get_data()['code'] );
		}
		$this->assertSame( 401, $this->request( 'POST', '/posts', array( 'title' => 'x' ), '' )->get_status() );
		$this->assertSame( 401, $this->request( 'DELETE', '/posts/1', null, '' )->get_status() );
	}

	public function test_x_florank_key_header_alone_is_accepted() {
		$request = new WP_REST_Request( 'GET', '/florank/v1/health' );
		$request->set_header( 'X-Florank-Key', $this->key );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_health() {
		$response = $this->request( 'GET', '/health' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'ok'        => true,
				'plugin'    => 'florank',
				'version'   => FLORANK_VERSION,
				'seoPlugin' => 'none',
				'siteUrl'   => home_url( '/' ),
			),
			$response->get_data()
		);
	}

	public function test_connection_test_flow() {
		$created = $this->request(
			'POST',
			'/posts',
			array(
				'title'   => 'FLORANK connection test',
				'content' => '<!-- florank connection test -->',
				'status'  => 'draft',
				'excerpt' => 'FLORANK test',
			)
		);
		$this->assertSame( 200, $created->get_status() );
		$id = $created->get_data()['id'];
		$this->assertSame( 'draft', get_post_status( $id ) );

		$deleted = $this->request( 'DELETE', '/posts/' . $id );
		$this->assertSame( 200, $deleted->get_status() );
		$this->assertSame(
			array(
				'ok' => true,
				'id' => $id,
			),
			$deleted->get_data()
		);
		$this->assertNull( get_post( $id ) );
	}

	public function test_publish_update_and_read_back() {
		$author_id = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'writer@example.com',
			)
		);
		self::factory()->user->create( array( 'role' => 'administrator' ) );
		$source = 'https://blob.florank.example/inline.png';
		$body   = array(
			'title'            => 'AI search &amp; SEO',
			'slug'             => 'ai-search-seo',
			'content'          => '<p>Text</p><p><img src="' . $source . '" alt=""></p><script>alert(1)</script>',
			'excerpt'          => 'Short',
			'status'           => 'draft',
			'meta_title'       => 'SEO title',
			'meta_description' => 'SEO description',
			'featured_image'   => array(
				'sourceUrl' => 'https://blob.florank.example/featured.png',
				'filename'  => 'featured.png',
				'mime'      => 'image/png',
				'data'      => $this->png_base64(),
				'alt'       => 'Cover',
			),
			'content_images'   => array(
				array(
					'sourceUrl' => $source,
					'filename'  => 'inline.png',
					'mime'      => 'image/png',
					'data'      => $this->png_base64(),
					'alt'       => '',
				),
			),
			'categories'       => array( 1 ),
			'schema_json_ld'   => array(
				'@context' => 'https://schema.org',
				'@type'    => 'BlogPosting',
				'headline' => 'AI search',
			),
			'indexnow_key'     => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
			'author_email'     => 'writer@example.com',
		);

		$created = $this->request( 'POST', '/posts', $body );
		$this->assertSame( 200, $created->get_status() );
		$data = $created->get_data();
		$id   = $data['id'];

		$this->assertSame( array( 'id', 'title', 'slug', 'status', 'link', 'modified', 'featured_media' ), array_keys( $data ) );
		$this->assertSame( 'AI search & SEO', $data['title'] );
		$this->assertSame( 'ai-search-seo', $data['slug'] );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertGreaterThan( 0, $data['featured_media'] );

		$post = get_post( $id );
		$this->assertSame( $author_id, (int) $post->post_author );
		$this->assertStringNotContainsString( '<script>', $post->post_content );
		$this->assertStringNotContainsString( $source, $post->post_content );
		$this->assertSame( 'Cover', get_post_meta( $data['featured_media'], '_wp_attachment_image_alt', true ) );
		$this->assertSame( array( 1 ), wp_get_post_categories( $id ) );
		$this->assertSame( 'SEO title', get_post_meta( $id, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'SEO description', get_post_meta( $id, '_yoast_wpseo_metadesc', true ) );
		$this->assertSame( 'SEO title', get_post_meta( $id, 'rank_math_title', true ) );
		$this->assertSame( 'SEO description', get_post_meta( $id, 'rank_math_description', true ) );
		$this->assertSame( 'a1b2c3d4e5f60718293a4b5c6d7e8f90', get_option( 'florank_indexnow_key' ) );
		$schema = json_decode( get_post_meta( $id, '_florank_schema_json_ld', true ), true );
		$this->assertSame( 'BlogPosting', $schema['@type'] );

		$body['status'] = 'publish';
		$body['title']  = 'Updated';
		$updated        = $this->request( 'POST', '/posts/' . $id, $body );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( $id, $updated->get_data()['id'] );
		$this->assertSame( 'publish', get_post_status( $id ) );

		$got = $this->request( 'GET', '/posts/' . $id );
		$this->assertSame( 'Updated', $got->get_data()['title'] );
		$this->assertSame( get_permalink( $id ), $got->get_data()['link'] );

		$list = $this->request( 'GET', '/posts' );
		$this->assertContains( $id, wp_list_pluck( $list->get_data(), 'id' ) );
	}

	public function test_meta_title_falls_back_to_title_and_unknown_status_becomes_draft() {
		$response = $this->request(
			'POST',
			'/posts',
			array(
				'title'  => 'Only title',
				'status' => 'private',
			)
		);
		$id       = $response->get_data()['id'];
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'Only title', get_post_meta( $id, '_yoast_wpseo_title', true ) );
	}

	public function test_invalid_indexnow_key_is_ignored() {
		delete_option( 'florank_indexnow_key' );
		$this->request(
			'POST',
			'/posts',
			array(
				'title'        => 'x',
				'indexnow_key' => '../../etc',
			)
		);
		$this->assertFalse( get_option( 'florank_indexnow_key' ) );
	}

	public function test_get_unknown_post_is_404() {
		$response = $this->request( 'GET', '/posts/999999' );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'florank_not_found', $response->get_data()['code'] );

		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( 404, $this->request( 'GET', '/posts/' . $page )->get_status() );
	}
}
