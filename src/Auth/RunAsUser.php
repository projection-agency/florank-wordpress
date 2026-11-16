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
 * Requests signed with the API key run as a non-administrative WordPress user:
 * by default a dedicated "Florank" user with the Author role that the plugin
 * creates when the key is created. The site owner may pick another user who
 * can edit posts and upload files, but never a user with administrative
 * capabilities (plugins, themes, users, settings, code). During a Florank
 * request the user is additionally limited to the post and media
 * capabilities in ALLOWED_CAPS, whatever their role, and can edit or delete
 * only posts created through Florank (plus their own).
 */
class RunAsUser {

	const OPTION_USER         = 'florank_run_as_user';
	const OPTION_LAST_REQUEST = 'florank_last_request';
	const META_SERVICE_USER   = '_florank_service_user';
	const META_MANAGED_POST   = '_florank_managed';
	const SERVICE_ROLE        = 'author';
	const SERVICE_LOGINS      = array( 'florank', 'florank-publisher' );
	const REQUIRED_CAPS       = array( 'edit_posts', 'upload_files' );

	/**
	 * Capabilities a Florank request may use. Everything else is denied while
	 * the request runs, even if the user's role has it.
	 */
	const ALLOWED_CAPS = array(
		'read',
		'edit_posts',
		'edit_published_posts',
		'publish_posts',
		'delete_posts',
		'delete_published_posts',
		'upload_files',
	);

	/**
	 * A user with any of these is never used for Florank requests.
	 */
	const ADMIN_CAPS = array(
		'manage_options',
		'activate_plugins',
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'switch_themes',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'edit_theme_options',
		'list_users',
		'create_users',
		'edit_users',
		'promote_users',
		'delete_users',
		'remove_users',
		'update_core',
		'edit_files',
		'unfiltered_upload',
		'import',
		'export',
		'manage_network',
	);

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
	 * Whether a user has any administrative capability.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function is_admin_like( WP_User $user ) {
		if ( function_exists( 'is_super_admin' ) && is_super_admin( $user->ID ) ) {
			return true;
		}
		foreach ( self::ADMIN_CAPS as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a user exists, can edit posts and upload files, and has no
	 * administrative capability.
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
		return ! self::is_admin_like( $user );
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
	 * The dedicated Florank user, if the plugin created one.
	 *
	 * @return WP_User|null
	 */
	public function service_user() {
		$users = get_users(
			array(
				'meta_key' => self::META_SERVICE_USER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'number'   => 1,
				'orderby'  => 'ID',
				'order'    => 'ASC',
			)
		);
		return ! empty( $users[0] ) && $users[0] instanceof WP_User ? $users[0] : null;
	}

	/**
	 * Returns the dedicated Florank user, creating it (Author role, random
	 * password, no email, login disabled) if it does not exist yet.
	 * Called only from wp-admin by an administrator, never from a REST request.
	 *
	 * @return WP_User|WP_Error
	 */
	public function ensure_service_user() {
		$user = $this->service_user();
		if ( $user ) {
			return $user;
		}
		$login = '';
		foreach ( self::SERVICE_LOGINS as $candidate ) {
			if ( ! username_exists( $candidate ) ) {
				$login = $candidate;
				break;
			}
		}
		if ( '' === $login ) {
			$login = self::SERVICE_LOGINS[1] . '-' . strtolower( wp_generate_password( 6, false ) );
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'display_name' => 'Florank',
				'nickname'     => 'Florank',
				'role'         => self::SERVICE_ROLE,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		update_user_meta( $user_id, self::META_SERVICE_USER, '1' );
		$user = get_userdata( $user_id );
		return $user instanceof WP_User ? $user : new WP_Error( 'florank_user_missing', __( 'Could not create the Florank user.', 'florank' ) );
	}

	/**
	 * Links the key to the dedicated Florank user.
	 *
	 * @return bool Whether it was saved.
	 */
	public function use_service_user() {
		$user = $this->ensure_service_user();
		return $user instanceof WP_User && $this->set( $user->ID );
	}

	/**
	 * Runs in wp-admin only. Keys created by v1.x have no user, keys created
	 * by 2.1.x may be linked to an administrator, and the linked user may have
	 * been deleted: in these cases the key is moved to the dedicated Florank
	 * user. An existing non-administrator chosen by the site owner is kept.
	 *
	 * @param bool $has_key Whether an API key is active.
	 * @return bool Whether the user was changed.
	 */
	public function migrate_legacy( $has_key ) {
		if ( ! $has_key ) {
			return false;
		}
		$id   = $this->get_id();
		$user = null !== $id && $id > 0 ? get_userdata( $id ) : false;
		if ( $user instanceof WP_User && $user->exists() && ! self::is_admin_like( $user ) ) {
			return false;
		}
		return $this->use_service_user();
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
				__( 'The WordPress user Florank publishes as does not exist. Open the Florank page in WordPress to fix it.', 'florank' ),
				array( 'status' => 403 )
			);
		}
		if ( ! self::is_eligible( $user ) ) {
			return new WP_Error(
				'florank_user_forbidden',
				sprintf(
					/* translators: %s: WordPress user login. */
					__( 'The WordPress user Florank publishes as (%s) cannot be used: it must be able to edit posts and upload files and must not be an administrator. Open the Florank page in WordPress to fix it.', 'florank' ),
					$user->user_login
				),
				array( 'status' => 403 )
			);
		}
		return $user;
	}

	/**
	 * Limits the given user to ALLOWED_CAPS for the rest of the request.
	 * Posts created through Florank (post meta _florank_managed) count as the
	 * user's own, so Florank can keep updating its articles after the author
	 * is changed to a real person; other users' posts stay out of reach
	 * because edit_others_posts and delete_others_posts are never allowed.
	 *
	 * @param int $user_id User ID.
	 */
	public static function restrict_capabilities( $user_id ) {
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $uid, $args ) use ( $user_id ) {
				if ( (int) $uid !== (int) $user_id || ! in_array( $cap, array( 'edit_post', 'delete_post' ), true ) || empty( $args[0] ) ) {
					return $caps;
				}
				$post = get_post( (int) $args[0] );
				if ( ! $post || 'post' !== $post->post_type || ! get_post_meta( $post->ID, self::META_MANAGED_POST, true ) ) {
					return $caps;
				}
				return array( 'edit_post' === $cap ? 'edit_posts' : 'delete_posts' );
			},
			10,
			4
		);
		add_filter(
			'user_has_cap',
			static function ( $allcaps, $caps, $args, $user ) use ( $user_id ) {
				if ( ! $user instanceof WP_User || (int) $user->ID !== (int) $user_id ) {
					return $allcaps;
				}
				return array_intersect_key( $allcaps, array_flip( self::ALLOWED_CAPS ) );
			},
			PHP_INT_MAX,
			4
		);
	}

	/**
	 * Users that can be chosen on the settings page (no administrators).
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
	 * Hook: authenticate (late). The dedicated Florank user cannot log in to
	 * wp-admin; it exists only to own and publish Florank articles.
	 *
	 * @param WP_User|WP_Error|null $user Authenticated user so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function block_service_login( $user ) {
		if ( $user instanceof WP_User && get_user_meta( $user->ID, self::META_SERVICE_USER, true ) ) {
			return new WP_Error( 'florank_service_user', __( 'This user is used by the Florank plugin and cannot log in.', 'florank' ) );
		}
		return $user;
	}

	/**
	 * Hook: wp_is_application_passwords_available_for_user.
	 *
	 * @param bool    $available Whether application passwords are available.
	 * @param WP_User $user      User.
	 * @return bool
	 */
	public static function no_app_passwords_for_service_user( $available, $user ) {
		if ( $user instanceof WP_User && get_user_meta( $user->ID, self::META_SERVICE_USER, true ) ) {
			return false;
		}
		return $available;
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
