<?php
/**
 * Requests run as the user linked to the API key (no administrator substitution).
 *
 * @package Florank
 */

namespace Florank\Tests\Integration;

use Florank\Admin\SettingsPage;
use Florank\Auth\ApiKey;
use Florank\Auth\RunAsUser;
use Florank\IndexNow\IndexNow;
use Florank\Media\ImageImporter;
use Florank\Posts\PostService;
use Florank\Seo\JsonLd;
use Florank\Seo\SeoBridge;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class RunAsUserTest extends WP_UnitTestCase {

	/**
	 * @var string
	 */
	private $key;

	/**
	 * @var int
	 */
	private $admin_id;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );
		$this->key = ( new ApiKey() )->generate();
		delete_option( RunAsUser::OPTION_USER );
		delete_option( RunAsUser::OPTION_LAST_REQUEST );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_REQUEST['_wpnonce'], $_POST['florank_user'] );
		parent::tear_down();
	}

	private function request( $method, $route, $body = null ) {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( $method, '/florank/v1' . $route );
		$request->set_header( 'Authorization', 'Bearer ' . $this->key );
		$request->set_header( 'X-Florank-Key', $this->key );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function settings_page() {
		$seo = new SeoBridge();
		return new SettingsPage(
			new ApiKey(),
			new RunAsUser(),
			new PostService( $seo, new JsonLd( $seo ), new IndexNow(), new ImageImporter() )
		);
	}

	/**
	 * Calls an admin-post handler; wp_safe_redirect() is turned into an exception instead of exit.
	 */
	private function call_handler( $method, $action ) {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		$redirect             = static function ( $location ) {
			throw new RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only control flow.
		};
		add_filter( 'wp_redirect', $redirect );
		try {
			$this->settings_page()->$method();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $redirect );
		}
		return '';
	}

	private function lowest_admin_id() {
		$ids = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);
		return (int) $ids[0];
	}

	public function test_creating_a_key_links_the_current_administrator() {
		$other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other_admin );
		update_option( RunAsUser::OPTION_LAST_REQUEST, time() );

		$location = $this->call_handler( 'handle_generate_key', SettingsPage::GENERATE_ACTION );

		$this->assertStringContainsString( 'generated=1', $location );
		$this->assertSame( $other_admin, ( new RunAsUser() )->get_id() );
		$this->assertSame( 0, ( new RunAsUser() )->last_request(), 'A new key waits for its first request.' );
	}

	public function test_owner_can_choose_another_user_with_edit_and_upload_rights() {
		wp_set_current_user( $this->admin_id );
		$author      = self::factory()->user->create( array( 'role' => 'author' ) );
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );

		$_POST['florank_user'] = (string) $author;
		$this->assertStringContainsString( 'florank_notice=user_saved', $this->call_handler( 'handle_set_user', SettingsPage::USER_ACTION ) );
		$this->assertSame( $author, ( new RunAsUser() )->get_id() );

		$_POST['florank_user'] = (string) $contributor;
		$this->assertStringContainsString( 'florank_notice=user_invalid', $this->call_handler( 'handle_set_user', SettingsPage::USER_ACTION ) );
		$this->assertSame( $author, ( new RunAsUser() )->get_id() );
	}

	public function test_requests_run_as_the_linked_user() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new RunAsUser() )->set( $editor );

		$response = $this->request(
			'POST',
			'/posts',
			array(
				'title'  => 'From Florank',
				'status' => 'publish',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $editor, get_current_user_id() );
		$post = get_post( $response->get_data()['id'] );
		$this->assertSame( $editor, (int) $post->post_author );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( '1', get_post_meta( $post->ID, PostService::META_MANAGED, true ) );
		$this->assertGreaterThan( 0, ( new RunAsUser() )->last_request() );
	}

	public function test_legacy_key_is_linked_to_first_administrator_on_first_request() {
		$this->assertNull( ( new RunAsUser() )->get_id(), 'A 1.4 key has no linked user.' );

		$this->assertSame( 200, $this->request( 'GET', '/health' )->get_status() );
		$this->assertSame( $this->lowest_admin_id(), ( new RunAsUser() )->get_id() );

		// Migration happens once: a later choice is kept.
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		( new RunAsUser() )->set( $author );
		$this->assertSame( 200, $this->request( 'GET', '/health' )->get_status() );
		$this->assertSame( $author, ( new RunAsUser() )->get_id() );
	}

	public function test_legacy_key_is_linked_on_admin_init_after_update() {
		$plugin = \Florank\Plugin::instance();
		$this->assertSame( 10, has_action( 'admin_init', array( $plugin, 'migrate_legacy_key' ) ) );
		$plugin->migrate_legacy_key();
		$this->assertSame( $this->lowest_admin_id(), ( new RunAsUser() )->get_id() );
	}

	public function test_deleted_user_gets_403() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new RunAsUser() )->set( $editor );
		wp_delete_user( $editor );

		$response = $this->request( 'POST', '/posts', array( 'title' => 'x' ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'florank_user_missing', $response->get_data()['code'] );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_user_without_rights_gets_403() {
		$subscriber = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'reader',
			)
		);
		update_option( RunAsUser::OPTION_USER, $subscriber );

		$response = $this->request( 'GET', '/posts' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'florank_user_forbidden', $response->get_data()['code'] );
		$this->assertStringContainsString( 'reader', $response->get_data()['message'] );
	}

	public function test_capabilities_of_the_linked_user_apply() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		( new RunAsUser() )->set( $author );
		$admin_post = self::factory()->post->create( array( 'post_author' => $this->admin_id ) );

		$update = $this->request( 'POST', '/posts/' . $admin_post, array( 'title' => 'Hijack' ) );
		$this->assertSame( 403, $update->get_status() );
		$this->assertSame( 'florank_forbidden', $update->get_data()['code'] );

		$delete = $this->request( 'DELETE', '/posts/' . $admin_post );
		$this->assertSame( 403, $delete->get_status() );
		$this->assertNotNull( get_post( $admin_post ) );

		// Unknown post IDs keep the 1.4 behaviour (500 invalid_post), not 403.
		$this->assertSame( 500, $this->request( 'POST', '/posts/999999', array( 'title' => 'x' ) )->get_status() );
	}

	public function test_user_without_publish_posts_can_only_create_drafts() {
		add_role(
			'florank_writer',
			'Writer',
			array(
				'read'         => true,
				'edit_posts'   => true,
				'upload_files' => true,
			)
		);
		$writer = self::factory()->user->create( array( 'role' => 'florank_writer' ) );
		( new RunAsUser() )->set( $writer );

		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/posts',
				array(
					'title'  => 'x',
					'status' => 'publish',
				)
			)->get_status()
		);
		$draft = $this->request(
			'POST',
			'/posts',
			array(
				'title'  => 'x',
				'status' => 'draft',
			)
		);
		$this->assertSame( 200, $draft->get_status() );
		remove_role( 'florank_writer' );
	}

	public function test_author_email_is_applied_only_with_edit_others_posts() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'writer@example.com',
			)
		);
		( new RunAsUser() )->set( $author );

		$response = $this->request(
			'POST',
			'/posts',
			array(
				'title'        => 'x',
				'author_email' => 'writer@example.com',
			)
		);
		$this->assertSame( $author, (int) get_post( $response->get_data()['id'] )->post_author );
	}

	public function test_settings_page_lists_articles_from_florank_with_one_accent() {
		( new RunAsUser() )->set( $this->admin_id );
		$this->request(
			'POST',
			'/posts',
			array(
				'title'  => 'Florank article',
				'status' => 'publish',
			)
		);
		self::factory()->post->create( array( 'post_title' => 'Hand-written post' ) );

		wp_set_current_user( $this->admin_id );
		ob_start();
		$this->settings_page()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Florank article', $html );
		$this->assertStringNotContainsString( 'Hand-written post', $html );
		$this->assertStringContainsString( 'Connected', $html );
		$this->assertStringContainsString( 'florank-user-select', $html );
		$this->assertMatchesRegularExpression( '/<option value="' . $this->admin_id . '"\s+selected/', $html );
		$this->assertStringNotContainsString( 'id="florank-api-key"', $html, 'The plain key is hidden after the first request.' );
		$this->assertLessThanOrEqual( 1, substr_count( $html, 'is-accent' ) );
		$this->assertStringNotContainsString( 'onclick', $html );
	}
}
