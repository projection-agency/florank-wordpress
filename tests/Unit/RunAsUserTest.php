<?php
/**
 * @package Florank
 */

namespace Florank\Tests\Unit;

use Florank\Auth\RunAsUser;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use WP_User;

class RunAsUserTest extends TestCase {

	const ALL_CAPS = array( 'edit_posts', 'upload_files', 'publish_posts' );

	protected function set_up() {
		parent::set_up();
		florank_test_reset();
	}

	public function test_set_saves_only_users_who_can_edit_posts_and_upload_files() {
		florank_test_add_user( 2, 'editor', array( 'editor' ), self::ALL_CAPS );
		florank_test_add_user( 3, 'contributor', array( 'contributor' ), array( 'edit_posts' ) );
		$run_as = new RunAsUser();

		$this->assertTrue( $run_as->set( 2 ) );
		$this->assertSame( 2, $run_as->get_id() );
		$this->assertFalse( $run_as->set( 3 ) );
		$this->assertFalse( $run_as->set( 99 ) );
		$this->assertSame( 2, $run_as->get_id() );
	}

	public function test_legacy_key_is_linked_to_first_administrator_once() {
		florank_test_add_user( 7, 'second-admin', array( 'administrator' ), self::ALL_CAPS );
		florank_test_add_user( 4, 'first-admin', array( 'administrator' ), self::ALL_CAPS );
		florank_test_add_user( 2, 'author', array( 'author' ), self::ALL_CAPS );
		$run_as = new RunAsUser();

		$this->assertNull( $run_as->get_id() );
		$this->assertFalse( $run_as->migrate_legacy( false ), 'No key — nothing to migrate.' );
		$this->assertNull( $run_as->get_id() );

		$this->assertTrue( $run_as->migrate_legacy( true ) );
		$this->assertSame( 4, $run_as->get_id() );

		// The owner picks someone else; migration must not override it.
		$run_as->set( 2 );
		$this->assertFalse( $run_as->migrate_legacy( true ) );
		$this->assertSame( 2, $run_as->get_id() );
	}

	public function test_migration_without_administrators_saves_zero_and_resolve_fails() {
		$run_as = new RunAsUser();
		$this->assertTrue( $run_as->migrate_legacy( true ) );
		$this->assertSame( 0, $run_as->get_id() );
		$this->assertFalse( $run_as->migrate_legacy( true ), 'Runs only once.' );

		$error = $run_as->resolve();
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'florank_user_missing', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	public function test_resolve_returns_user_or_403() {
		florank_test_add_user( 5, 'admin', array( 'administrator' ), self::ALL_CAPS );
		$run_as = new RunAsUser();
		$run_as->set( 5 );
		$this->assertInstanceOf( WP_User::class, $run_as->resolve() );

		// Deleted user.
		unset( $GLOBALS['florank_test_users'][5] );
		$this->assertSame( 'florank_user_missing', $run_as->resolve()->get_error_code() );

		// User lost the capabilities.
		florank_test_add_user( 5, 'admin', array( 'subscriber' ), array( 'read' ) );
		$error = $run_as->resolve();
		$this->assertSame( 'florank_user_forbidden', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertStringContainsString( 'admin', $error->message );
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
