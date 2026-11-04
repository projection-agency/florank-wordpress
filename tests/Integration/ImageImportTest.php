<?php
/**
 * Image import: trusted hosts only, size and type limits, no duplicates on update.
 *
 * @package Florank
 */

namespace Florank\Tests\Integration;

use Florank\Auth\ApiKey;
use Florank\Auth\RunAsUser;
use Florank\Media\ImageImporter;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class ImageImportTest extends WP_UnitTestCase {

	const BLOB = 'https://store1.public.blob.vercel-storage.com/sites/s/articles/a/';

	/**
	 * @var string
	 */
	private $key;

	/**
	 * URLs WordPress tried to fetch.
	 *
	 * @var string[]
	 */
	private $fetched = array();

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$admin     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->key = ( new ApiKey() )->generate();
		( new RunAsUser() )->set( $admin );
		wp_set_current_user( 0 );

		$this->fetched = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		parent::tear_down();
	}

	/**
	 * Serves PNG bytes for every URL and records the request.
	 */
	public function fake_http( $preempt, $args, $url ) {
		$this->fetched[] = $url;
		$bytes           = $this->png( 10, 200, 30 );
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $bytes );
		}
		return array(
			'headers'  => array( 'content-type' => 'image/png' ),
			'body'     => ! empty( $args['stream'] ) ? '' : $bytes,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
	}

	private function png( $r, $g, $b ) {
		$image = imagecreatetruecolor( 8, 8 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, $r, $g, $b ) );
		ob_start();
		imagepng( $image );
		return ob_get_clean();
	}

	private function packed( $name, $bytes ) {
		return array(
			'sourceUrl' => self::BLOB . $name,
			'filename'  => $name,
			'mime'      => 'image/png',
			'data'      => base64_encode( $bytes ),
			'alt'       => '',
		);
	}

	private function request( $route, $body ) {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/florank/v1' . $route );
		$request->set_header( 'Authorization', 'Bearer ' . $this->key );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	private function attachment_count() {
		return count(
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}

	public function test_remote_images_from_other_hosts_are_skipped_without_error() {
		$response = $this->request(
			'/posts',
			array(
				'title'              => 'x',
				'featured_image_url' => 'https://evil.example/cover.png',
				'content'            => '<p><img src="https://evil.example/a.png"></p><p><img src="http://169.254.169.254/latest/meta-data/x.png"></p><p><img src="http://store1.public.blob.vercel-storage.com/plain-http.png"></p>',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['featured_media'] );
		$this->assertSame( array(), $this->fetched, 'No request may leave for untrusted URLs.' );
		$this->assertStringContainsString( 'https://evil.example/a.png', get_post( $response->get_data()['id'] )->post_content );
		$this->assertSame( 0, $this->attachment_count() );
	}

	public function test_remote_image_from_florank_host_is_imported() {
		$response = $this->request(
			'/posts',
			array(
				'title'              => 'x',
				'featured_image_url' => self::BLOB . 'cover.png',
			)
		);
		$this->assertSame( array( self::BLOB . 'cover.png' ), $this->fetched );
		$this->assertGreaterThan( 0, $response->get_data()['featured_media'] );
	}

	public function test_allowed_hosts_filter() {
		add_filter(
			'florank_allowed_image_hosts',
			static function () {
				return array( 'cdn.example.com' );
			}
		);
		$this->request(
			'/posts',
			array(
				'title'   => 'x',
				'content' => '<img src="https://cdn.example.com/a.png"><img src="' . self::BLOB . 'b.png">',
			)
		);
		$this->assertSame( array( 'https://cdn.example.com/a.png' ), $this->fetched );
	}

	public function test_packed_image_over_size_limit_is_skipped() {
		add_filter(
			'florank_max_image_bytes',
			static function () {
				return 50;
			}
		);
		$response = $this->request(
			'/posts',
			array(
				'title'          => 'x',
				'featured_image' => $this->packed( 'big.png', $this->png( 1, 2, 3 ) . str_repeat( "\0", 100 ) ),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['featured_media'] );
		$this->assertSame( 0, $this->attachment_count() );
	}

	public function test_packed_files_that_are_not_allowed_images_are_skipped() {
		$svg  = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
		$php  = '<?php echo "pwned";';
		$html = '<html><body>x</body></html>';

		foreach ( array(
			'a.svg' => $svg,
			'b.png' => $php,
			'c.jpg' => $html,
			'd.php' => $php,
		) as $name => $bytes ) {
			$response = $this->request(
				'/posts',
				array(
					'title'          => $name,
					'featured_image' => $this->packed( $name, $bytes ),
				)
			);
			$this->assertSame( 200, $response->get_status(), $name );
			$this->assertSame( 0, $response->get_data()['featured_media'], $name );
		}
		$this->assertSame( 0, $this->attachment_count() );
	}

	public function test_png_with_wrong_extension_gets_the_real_type() {
		$response = $this->request(
			'/posts',
			array(
				'title'          => 'x',
				'featured_image' => $this->packed( 'cover.jpg', $this->png( 4, 5, 6 ) ),
			)
		);
		$id       = $response->get_data()['featured_media'];
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
	}

	public function test_update_reuses_imported_images() {
		$inline_source = self::BLOB . 'inline-1.png';
		$body          = array(
			'title'              => 'Article',
			'content'            => '<p><img src="' . $inline_source . '"></p><p><img src="' . self::BLOB . 'remote-2.png"></p>',
			'featured_image'     => $this->packed( 'featured.png', $this->png( 255, 90, 31 ) ),
			'featured_image_alt' => 'Cover',
			'content_images'     => array( $this->packed( 'inline-1.png', $this->png( 20, 20, 20 ) ) ),
		);

		$created = $this->request( '/posts', $body );
		$id      = $created->get_data()['id'];
		$count   = $this->attachment_count();
		$this->assertSame( 3, $count, 'featured + packed inline + remote inline' );
		$content = get_post( $id )->post_content;

		$body['title'] = 'Article v2';
		$updated       = $this->request( '/posts/' . $id, $body );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( $count, $this->attachment_count(), 'Update must not create new attachments.' );
		$this->assertSame( $created->get_data()['featured_media'], $updated->get_data()['featured_media'] );
		$this->assertSame( $content, get_post( $id )->post_content );
		$this->assertCount( 1, $this->fetched, 'The remote image is downloaded once.' );

		// Same bytes under a new URL (e.g. the image was re-uploaded) are reused too.
		$body['featured_image'] = $this->packed( 'featured-renamed.png', $this->png( 255, 90, 31 ) );
		$this->request( '/posts/' . $id, $body );
		$this->assertSame( $count, $this->attachment_count() );

		$this->assertSame( 'Cover', get_post_meta( $updated->get_data()['featured_media'], '_wp_attachment_image_alt', true ) );
		$this->assertSame( self::BLOB . 'featured.png', get_post_meta( $updated->get_data()['featured_media'], ImageImporter::META_SOURCE_URL, true ) );
	}

	public function test_deleted_attachment_is_imported_again() {
		$body    = array(
			'title'          => 'x',
			'featured_image' => $this->packed( 'f.png', $this->png( 9, 9, 9 ) ),
		);
		$created = $this->request( '/posts', $body );
		wp_delete_attachment( $created->get_data()['featured_media'], true );

		$updated = $this->request( '/posts/' . $created->get_data()['id'], $body );
		$this->assertGreaterThan( 0, $updated->get_data()['featured_media'] );
		$this->assertNotSame( $created->get_data()['featured_media'], $updated->get_data()['featured_media'] );
	}
}
