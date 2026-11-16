<?php
/**
 * Requests run as a non-administrative user linked to the API key
 * (by default the dedicated "Florank" Author user), never as an administrator.
 *
 * @package Florank
 */

namespace Florank\Tests\Integration;

use Florank\Admin\SettingsPage;
use Florank\Auth\ApiKey;
use Florank\Auth\ConnectionCode;
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

	private function service_user_id() {
		$user = ( new RunAsUser() )->service_user();
		return $user ? $user->ID : 0;
	}

	public function test_creating_a_key_links_the_dedicated_author_user() {
		wp_set_current_user( $this->admin_id );
		update_option( RunAsUser::OPTION_LAST_REQUEST, time() );

		$location = $this->call_handler( 'handle_generate_key', SettingsPage::GENERATE_ACTION );

		$this->assertStringContainsString( 'generated=1', $location );
		$service = get_userdata( ( new RunAsUser() )->get_id() );
		$this->assertSame( $this->service_user_id(), $service->ID );
		$this->assertNotSame( $this->admin_id, $service->ID );
		$this->assertSame( array( 'author' ), $service->roles );
		$this->assertFalse( user_can( $service, 'manage_options' ) );
		$this->assertSame( 0, ( new RunAsUser() )->last_request(), 'A new key waits for its first request.' );

		// A second key reuses the same user.
		$this->call_handler( 'handle_generate_key', SettingsPage::GENERATE_ACTION );
		$this->assertSame( $service->ID, ( new RunAsUser() )->get_id() );
		$this->assertCount( 1, get_users( array( 'meta_key' => RunAsUser::META_SERVICE_USER ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}

	public function test_dedicated_user_cannot_log_in() {
		$service = ( new RunAsUser() )->ensure_service_user();
		wp_set_password( 'known-password', $service->ID );
		$result = wp_authenticate( $service->user_login, 'known-password' );
		$this->assertWPError( $result );
		$this->assertFalse( wp_is_application_passwords_available_for_user( $service ) );
	}

	public function test_owner_can_choose_another_non_admin_user_with_edit_and_upload_rights() {
		wp_set_current_user( $this->admin_id );
		$author      = self::factory()->user->create( array( 'role' => 'author' ) );
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );

		$_POST['florank_user'] = (string) $this->admin_id;
		$this->assertStringContainsString( 'florank_notice=user_invalid', $this->call_handler( 'handle_set_user', SettingsPage::USER_ACTION ) );
		$this->assertNotSame( $this->admin_id, ( new RunAsUser() )->get_id() );

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

	public function test_rest_requests_never_create_users_or_pick_an_administrator() {
		$this->assertNull( ( new RunAsUser() )->get_id(), 'A 1.4 key has no linked user.' );
		$users_before = count_users()['total_users'];

		$response = $this->request( 'GET', '/health' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'florank_user_missing', $response->get_data()['code'] );
		$this->assertNull( ( new RunAsUser() )->get_id() );
		$this->assertSame( $users_before, count_users()['total_users'] );
	}

	public function test_key_linked_to_an_administrator_is_refused() {
		update_option( RunAsUser::OPTION_USER, $this->admin_id );
		$response = $this->request( 'POST', '/posts', array( 'title' => 'x' ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'florank_user_forbidden', $response->get_data()['code'] );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_admin_init_moves_legacy_and_administrator_keys_to_the_dedicated_user() {
		$plugin = \Florank\Plugin::instance();
		$this->assertSame( 10, has_action( 'admin_init', array( $plugin, 'migrate_legacy_key' ) ) );

		// Not an administrator: nothing happens.
		$plugin->migrate_legacy_key();
		$this->assertNull( ( new RunAsUser() )->get_id() );

		wp_set_current_user( $this->admin_id );
		$plugin->migrate_legacy_key();
		$this->assertSame( $this->service_user_id(), ( new RunAsUser() )->get_id() );
		$this->assertSame( 200, $this->request( 'GET', '/health' )->get_status() );

		// Key linked to an administrator by 2.1.x.
		update_option( RunAsUser::OPTION_USER, $this->admin_id );
		wp_set_current_user( $this->admin_id );
		$plugin->migrate_legacy_key();
		$this->assertSame( $this->service_user_id(), ( new RunAsUser() )->get_id() );

		// A non-administrator chosen by the owner is kept.
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		( new RunAsUser() )->set( $author );
		wp_set_current_user( $this->admin_id );
		$plugin->migrate_legacy_key();
		$this->assertSame( $author, ( new RunAsUser() )->get_id() );
	}

	public function test_requests_are_limited_to_florank_posts_even_for_an_editor() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new RunAsUser() )->set( $editor );
		$other_post = self::factory()->post->create( array( 'post_author' => $this->admin_id ) );
		$page       = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $this->admin_id,
			)
		);

		$this->assertSame( 403, $this->request( 'POST', '/posts/' . $other_post, array( 'title' => 'Hijack' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/posts/' . $page, array( 'title' => 'Hijack' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'DELETE', '/posts/' . $other_post )->get_status() );
		$this->assertNotSame( 'Hijack', get_post( $other_post )->post_title );
		$this->assertNotNull( get_post( $page ) );

		// During the request the editor has none of the non-post capabilities.
		$this->request( 'GET', '/health' );
		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertFalse( current_user_can( 'edit_others_posts' ) );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$this->assertFalse( current_user_can( 'edit_pages' ) );
		$this->assertFalse( current_user_can( 'manage_categories' ) );
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

		// Own posts not created through Florank cannot be deleted either.
		$own_post = self::factory()->post->create( array( 'post_author' => $author ) );
		$this->assertSame( 403, $this->request( 'DELETE', '/posts/' . $own_post )->get_status() );
		$this->assertNotNull( get_post( $own_post ) );

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

	public function test_author_email_sets_an_existing_writer_and_florank_keeps_editing_the_post() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$writer = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'writer@example.com',
			)
		);
		self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			)
		);
		( new RunAsUser() )->set( $author );

		$response = $this->request(
			'POST',
			'/posts',
			array(
				'title'        => 'x',
				'author_email' => 'writer@example.com',
				'status'       => 'publish',
			)
		);
		$id       = $response->get_data()['id'];
		$this->assertSame( $writer, (int) get_post( $id )->post_author );

		$update = $this->request( 'POST', '/posts/' . $id, array( 'title' => 'Updated' ) );
		$this->assertSame( 200, $update->get_status() );
		$this->assertSame( 'Updated', get_post( $id )->post_title );

		$reader = $this->request(
			'POST',
			'/posts',
			array(
				'title'        => 'y',
				'author_email' => 'reader@example.com',
			)
		);
		$this->assertSame( $author, (int) get_post( $reader->get_data()['id'] )->post_author, 'A subscriber is not set as author.' );

		$this->assertSame( 200, $this->request( 'DELETE', '/posts/' . $id )->get_status() );
	}

	public function test_settings_page_lists_articles_from_florank_with_one_accent() {
		( new RunAsUser() )->use_service_user();
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
		$this->assertMatchesRegularExpression( '/<option value="' . $this->service_user_id() . '"\s+selected/', $html );
		$this->assertStringNotContainsString( '<option value="' . $this->admin_id . '"', $html, 'Administrators are not offered.' );
		$this->assertStringNotContainsString( 'id="florank-api-key"', $html, 'The plain key is hidden after the first request.' );
		$this->assertLessThanOrEqual( 1, substr_count( $html, 'is-accent' ) );
		$this->assertStringNotContainsString( 'onclick', $html );
	}

	public function test_settings_page_shows_the_connection_code_once() {
		( new RunAsUser() )->use_service_user();
		wp_set_current_user( $this->admin_id );
		ob_start();
		$this->settings_page()->render();
		$html = ob_get_clean();

		$this->assertSame( 1, preg_match( '/id="florank-connection-code"[^>]*>(flc1_[A-Za-z0-9_-]+)</', $html, $m ) );
		$this->assertSame(
			array(
				'u' => untrailingslashit( home_url() ),
				'k' => $this->key,
			),
			ConnectionCode::parse( $m[1] )
		);
		$this->assertStringContainsString( 'id="florank-api-key"', $html, 'The bare key stays available for older Florank versions.' );
		$this->assertStringContainsString( '<details class="florank-details">', $html );
		$this->assertStringNotContainsString( 'florank-site-url', $html );
		$this->assertSame( 1, substr_count( $html, 'is-accent' ) );

		delete_transient( ApiKey::TRANSIENT_NEW );
		ob_start();
		$this->settings_page()->render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'id="florank-connection-code"', $html );
		$this->assertStringNotContainsString( $this->key, $html );
		$this->assertStringContainsString( 'flc1_…', $html );
	}
}
