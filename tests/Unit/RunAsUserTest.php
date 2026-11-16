<?php
/**
 * @package Florank
 */

namespace Florank\Tests\Unit;

use Florank\Auth\RunAsUser;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use WP_User;

class RunAsUserTest extends TestCase {

	const AUTHOR_CAPS = array( 'edit_posts', 'upload_files', 'publish_posts' );
	const ADMIN_CAPS  = array( 'edit_posts', 'upload_files', 'publish_posts', 'manage_options', 'activate_plugins', 'edit_users' );

	protected function set_up() {
		parent::set_up();
		florank_test_reset();
	}

	public function test_set_saves_only_non_admin_users_who_can_edit_posts_and_upload_files() {
		florank_test_add_user( 2, 'editor', array( 'editor' ), self::AUTHOR_CAPS );
		florank_test_add_user( 3, 'contributor', array( 'contributor' ), array( 'edit_posts' ) );
		florank_test_add_user( 4, 'admin', array( 'administrator' ), self::ADMIN_CAPS );
		$run_as = new RunAsUser();

		$this->assertTrue( $run_as->set( 2 ) );
		$this->assertSame( 2, $run_as->get_id() );
		$this->assertFalse( $run_as->set( 3 ) );
		$this->assertFalse( $run_as->set( 4 ), 'Administrators can never be used.' );
		$this->assertFalse( $run_as->set( 99 ) );
		$this->assertSame( 2, $run_as->get_id() );
	}

	/**
	 * @dataProvider admin_caps
	 */
	public function test_any_administrative_capability_makes_a_user_ineligible( $cap ) {
		florank_test_add_user( 5, 'shop-manager', array( 'custom' ), array_merge( self::AUTHOR_CAPS, array( $cap ) ) );
		$this->assertFalse( RunAsUser::is_eligible( get_userdata( 5 ) ) );
	}

	public static function admin_caps() {
		return array_map(
			static function ( $cap ) {
				return array( $cap );
			},
			RunAsUser::ADMIN_CAPS
		);
	}

	public function test_service_user_is_an_author_created_once() {
		$run_as = new RunAsUser();
		$this->assertNull( $run_as->service_user() );

		$user = $run_as->ensure_service_user();
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'florank', $user->user_login );
		$this->assertSame( array( 'author' ), $user->roles );
		$this->assertTrue( RunAsUser::is_eligible( $user ) );
		$this->assertSame( $user->ID, $run_as->ensure_service_user()->ID, 'Reused, not created again.' );
		$this->assertCount( 1, $GLOBALS['florank_test_inserted_users'] );
	}

	public function test_service_user_login_avoids_existing_users() {
		florank_test_add_user( 1, 'florank', array( 'administrator' ), self::ADMIN_CAPS );
		$user = ( new RunAsUser() )->ensure_service_user();
		$this->assertSame( 'florank-publisher', $user->user_login );
	}

	public function test_legacy_key_without_user_moves_to_service_user() {
		florank_test_add_user( 4, 'first-admin', array( 'administrator' ), self::ADMIN_CAPS );
		$run_as = new RunAsUser();

		$this->assertFalse( $run_as->migrate_legacy( false ), 'No key — nothing to migrate.' );
		$this->assertNull( $run_as->get_id() );

		$this->assertTrue( $run_as->migrate_legacy( true ) );
		$this->assertSame( $run_as->service_user()->ID, $run_as->get_id() );
		$this->assertNotSame( 4, $run_as->get_id() );
	}

	public function test_key_linked_to_administrator_moves_to_service_user() {
		florank_test_add_user( 4, 'admin', array( 'administrator' ), self::ADMIN_CAPS );
		update_option( RunAsUser::OPTION_USER, 4 );
		$run_as = new RunAsUser();

		$this->assertTrue( $run_as->migrate_legacy( true ) );
		$this->assertSame( $run_as->service_user()->ID, $run_as->get_id() );
	}

	public function test_non_admin_choice_of_the_owner_is_kept() {
		florank_test_add_user( 2, 'author', array( 'author' ), self::AUTHOR_CAPS );
		$run_as = new RunAsUser();
		$run_as->set( 2 );
		$this->assertFalse( $run_as->migrate_legacy( true ) );
		$this->assertSame( 2, $run_as->get_id() );
		$this->assertNull( $run_as->service_user() );
	}

	public function test_resolve_returns_user_or_403() {
		florank_test_add_user( 5, 'writer', array( 'author' ), self::AUTHOR_CAPS );
		$run_as = new RunAsUser();
		$run_as->set( 5 );
		$this->assertInstanceOf( WP_User::class, $run_as->resolve() );

		// Deleted user.
		unset( $GLOBALS['florank_test_users'][5] );
		$this->assertSame( 'florank_user_missing', $run_as->resolve()->get_error_code() );

		// User lost the capabilities.
		florank_test_add_user( 5, 'writer', array( 'subscriber' ), array( 'read' ) );
		$error = $run_as->resolve();
		$this->assertSame( 'florank_user_forbidden', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertStringContainsString( 'writer', $error->message );

		// User was promoted to administrator.
		florank_test_add_user( 5, 'writer', array( 'administrator' ), self::ADMIN_CAPS );
		$this->assertSame( 'florank_user_forbidden', $run_as->resolve()->get_error_code() );
	}

	public function test_restricted_user_keeps_only_post_and_media_capabilities() {
		RunAsUser::restrict_capabilities( 7 );
		$allcaps  = array(
			'read'              => true,
			'edit_posts'        => true,
			'upload_files'      => true,
			'edit_others_posts' => true,
			'manage_options'    => true,
			'unfiltered_html'   => true,
			'edit_pages'        => true,
		);
		$filtered = apply_filters( 'user_has_cap', $allcaps, array(), array(), new WP_User( 7 ) );
		$this->assertSame(
			array(
				'read'         => true,
				'edit_posts'   => true,
				'upload_files' => true,
			),
			$filtered
		);
		$this->assertSame( $allcaps, apply_filters( 'user_has_cap', $allcaps, array(), array(), new WP_User( 8 ) ), 'Other users are not affected.' );
	}

	public function test_service_user_cannot_log_in() {
		$user = ( new RunAsUser() )->ensure_service_user();
		$this->assertInstanceOf( \WP_Error::class, RunAsUser::block_service_login( $user ) );
		$this->assertFalse( RunAsUser::no_app_passwords_for_service_user( true, $user ) );

		$other = new WP_User( 50, 'someone' );
		$this->assertSame( $other, RunAsUser::block_service_login( $other ) );
		$this->assertTrue( RunAsUser::no_app_passwords_for_service_user( true, $other ) );
	}

	public function test_last_request_is_recorded_and_reset() {
		$run_as = new RunAsUser();
		$this->assertSame( 0, $run_as->last_request() );
		$run_as->touch_last_request();
		$this->assertEqualsWithDelta( time(), $run_as->last_request(), 2 );
		$run_as->reset_last_request();
		$this->assertSame( 0, $run_as->last_request() );
	}
}
