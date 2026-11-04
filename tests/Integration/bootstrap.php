<?php
/**
 * Integration test bootstrap: loads the WordPress PHPUnit test library
 * (provided by wp-env in WP_TESTS_DIR) and the plugin.
 *
 * @package Florank
 */

$florank_root      = dirname( __DIR__, 2 );
$florank_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $florank_tests_dir ) {
	$florank_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}
if ( ! file_exists( $florank_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test library not found in {$florank_tests_dir}. Run the tests inside wp-env (see README).\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $florank_root . '/vendor/yoast/phpunit-polyfills' );

require_once $florank_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $florank_root ) {
		require $florank_root . '/florank.php';
	}
);

require $florank_tests_dir . '/includes/bootstrap.php';
