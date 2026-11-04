<?php
/**
 * Unit test bootstrap: minimal in-memory stand-ins for the WordPress
 * functions the tested classes call. No WordPress core is loaded.
 *
 * @package Florank
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'FLORANK_VERSION', '2.0.0-test' );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$GLOBALS['florank_test_options']    = array();
$GLOBALS['florank_test_transients'] = array();
$GLOBALS['florank_test_users']      = array();
$GLOBALS['florank_test_filters']    = array();

function florank_test_reset() {
	$GLOBALS['florank_test_options']    = array();
	$GLOBALS['florank_test_transients'] = array();
	$GLOBALS['florank_test_users']      = array();
	$GLOBALS['florank_test_filters']    = array();
}

require_once __DIR__ . '/stubs/WP_User.php';
require_once __DIR__ . '/stubs/WP_Error.php';

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function florank_test_add_user( $id, $login, $roles, $caps ) {
	$GLOBALS['florank_test_users'][ $id ] = new WP_User( $id, $login, $roles, $caps );
}

function get_userdata( $id ) {
	return $GLOBALS['florank_test_users'][ (int) $id ] ?? false;
}

function user_can( $user, $cap ) {
	return $user instanceof WP_User && in_array( $cap, $user->caps, true );
}

function get_users( $args = array() ) {
	$users = array_values( $GLOBALS['florank_test_users'] );
	if ( isset( $args['role'] ) ) {
		$users = array_values(
			array_filter(
				$users,
				static function ( $u ) use ( $args ) {
					return in_array( $args['role'], $u->roles, true );
				}
			)
		);
	}
	usort(
		$users,
		static function ( $a, $b ) {
			return $a->ID <=> $b->ID;
		}
	);
	if ( isset( $args['number'] ) ) {
		$users = array_slice( $users, 0, $args['number'] );
	}
	if ( isset( $args['fields'] ) && 'ID' === $args['fields'] ) {
		return array_map(
			static function ( $u ) {
				return (string) $u->ID;
			},
			$users
		);
	}
	return $users;
}

function add_filter( $hook, $callback ) {
	$GLOBALS['florank_test_filters'][ $hook ][] = $callback;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['florank_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function delete_option( $name ) {
	unset( $GLOBALS['florank_test_options'][ $name ] );
	return true;
}

function delete_transient( $name ) {
	unset( $GLOBALS['florank_test_transients'][ $name ] );
	return true;
}

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['florank_test_options'] ) ? $GLOBALS['florank_test_options'][ $name ] : $default_value;
}

function add_option( $name, $value = '' ) {
	if ( array_key_exists( $name, $GLOBALS['florank_test_options'] ) ) {
		return false;
	}
	$GLOBALS['florank_test_options'][ $name ] = $value;
	return true;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['florank_test_options'][ $name ] = $value;
	return true;
}

function set_transient( $name, $value, $expiration = 0 ) {
	$GLOBALS['florank_test_transients'][ $name ] = $value;
	return true;
}

function get_transient( $name ) {
	return $GLOBALS['florank_test_transients'][ $name ] ?? false;
}

function wp_hash_password( $password ) {
	return password_hash( $password, PASSWORD_BCRYPT );
}

function wp_check_password( $password, $hash ) {
	return password_verify( $password, $hash );
}

function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
