<?php
/**
 * Plugin entry point: wires every component to WordPress hooks.
 *
 * @package Florank
 */

namespace Florank;

use Florank\Admin\SettingsPage;
use Florank\Auth\ApiKey;
use Florank\IndexNow\IndexNow;
use Florank\Media\ImageImporter;
use Florank\Posts\PostService;
use Florank\Rest\Controller;
use Florank\Seo\JsonLd;
use Florank\Seo\SeoBridge;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the object graph once and registers hooks.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether hooks are already registered.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Returns the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Activation hook: creates empty key options on first activation.
	 */
	public static function activate() {
		ApiKey::ensure_options();
	}

	/**
	 * Registers all hooks.
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$api_key   = new ApiKey();
		$seo       = new SeoBridge();
		$json_ld   = new JsonLd( $seo );
		$index_now = new IndexNow();
		$posts     = new PostService( $seo, $json_ld, $index_now, new ImageImporter() );
		$rest      = new Controller( $api_key, $posts, $seo );
		$settings  = new SettingsPage( $api_key );

		add_action( 'admin_menu', array( $settings, 'register_menu' ) );
		add_action( 'admin_post_' . SettingsPage::GENERATE_ACTION, array( $settings, 'handle_generate_key' ) );
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		add_action( 'wp_head', array( $json_ld, 'print_head' ) );
		add_action( 'init', array( $index_now, 'maybe_serve_key' ), 0 );
	}
}
