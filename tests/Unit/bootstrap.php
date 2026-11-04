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

function florank_test_reset() {
	$GLOBALS['florank_test_options']    = array();
	$GLOBALS['florank_test_transients'] = array();
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
