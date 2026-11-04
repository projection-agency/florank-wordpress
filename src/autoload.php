<?php
/**
 * PSR-4 autoloader for the Florank namespace.
 *
 * The release zip ships without Composer's vendor/ directory, so the plugin
 * registers its own tiny autoloader that mirrors the "Florank\\" => "src/"
 * mapping from composer.json.
 *
 * @package Florank
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Florank\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
