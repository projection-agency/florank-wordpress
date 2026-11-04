<?php
/**
 * WordPress user that Florank requests run as.
 *
 * @package Florank
 */

namespace Florank\Auth;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Requests signed with the API key run as one WordPress user chosen by the
 * site owner (by default: the administrator who created the key). The user
 * must be able to edit posts and upload files; WordPress capability checks
 * then apply to everything Florank does.
 */
class RunAsUser {

	const OPTION_USER         = 'florank_run_as_user';
	const OPTION_LAST_REQUEST = 'florank_last_request';
	const REQUIRED_CAPS       = array( 'edit_posts', 'upload_files' );

	/**
	 * Saved user ID; null when none was ever saved (keys created by v1.x).
	 *
	 * @return int|null
	 */
	public function get_id() {
		$value = get_option( self::OPTION_USER, null );
		return null === $value || false === $value ? null : (int) $value;
	}

	/**
	 * Whether a user exists and has every capability Florank needs.
	 *
	 * @param WP_User|false|null $user User.
	 * @return bool
	 */
	public static function is_eligible( $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}
		foreach ( self::REQUIRED_CAPS as $cap ) {
			if ( ! user_can( $user, $cap ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Saves the user if eligible.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether it was saved.
	 */
	public function set( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! self::is_eligible( get_userdata( $user_id ) ) ) {
			return false;
		}
		update_option( self::OPTION_USER, $user_id );
		return true;
	}

	/**
	 * Keys created by v1.x have no user. Picks the first administrator once
	 * and saves it, so later requests and the settings page use that user
	 * until the site owner changes it. Does nothing when a user (or 0, when
	 * no administrator was found) is already saved or no key exists.
	 *
	 * @param bool $has_key Whether an API key is active.
	 * @return bool Whether the migration ran.
	 */
	public function migrate_legacy( $has_key ) {
		if ( ! $has_key || null !== $this->get_id() ) {
			return false;
		}
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);
		update_option( self::OPTION_USER, empty( $admins[0] ) ? 0 : (int) $admins[0] );
		return true;
	}

	/**
	 * The saved user, or a 403 error explaining what the site owner must fix.
	 *
	 * @return WP_User|WP_Error
	 */
	public function resolve() {
		$id   = (int) $this->get_id();
		$user = $id > 0 ? get_userdata( $id ) : false;
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return new WP_Error(
				'florank_user_missing',
				__( 'The WordPress user Florank publishes as does not exist. Choose another user on the Florank page in WordPress.', 'florank' ),
				array( 'status' => 403 )
			);
		}
		if ( ! self::is_eligible( $user ) ) {
			return new WP_Error(
				'florank_user_forbidden',
				sprintf(
					/* translators: %s: WordPress user login. */
					__( 'The WordPress user Florank publishes as (%s) cannot edit posts or upload files. Choose another user on the Florank page in WordPress.', 'florank' ),
					$user->user_login
				),
				array( 'status' => 403 )
			);
		}
		return $user;
	}

	/**
	 * Users that can be chosen on the settings page.
	 *
	 * @return WP_User[]
	 */
	public function eligible_users() {
		$users = get_users(
			array(
				'capability__in' => self::REQUIRED_CAPS,
				'orderby'        => 'display_name',
				'number'         => 200,
			)
		);
		return array_values( array_filter( $users, array( self::class, 'is_eligible' ) ) );
	}

	/**
	 * Remembers the time of the last authorized request from Florank.
	 * Written at most once a minute.
	 */
	public function touch_last_request() {
		$now = time();
		if ( $now - $this->last_request() >= MINUTE_IN_SECONDS ) {
			update_option( self::OPTION_LAST_REQUEST, $now );
		}
	}

	/**
	 * Unix time of the last authorized request, 0 if none since the key was created.
	 *
	 * @return int
	 */
	public function last_request() {
		return (int) get_option( self::OPTION_LAST_REQUEST, 0 );
	}

	/**
	 * Forgets the last request (new or revoked key).
	 */
	public function reset_last_request() {
		delete_option( self::OPTION_LAST_REQUEST );
	}
}
