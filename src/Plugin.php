<?php
/**
 * Plugin entry point: wires every component to WordPress hooks.
 *
 * @package Florank
 */

namespace Florank;

use Florank\Admin\SettingsPage;
use Florank\Auth\ApiKey;
use Florank\Auth\RunAsUser;
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
	 * API key.
	 *
	 * @var ApiKey|null
	 */
	private $api_key = null;

	/**
	 * User the requests run as.
	 *
	 * @var RunAsUser|null
	 */
	private $run_as = null;

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
	 * Hook: admin_init. Keys created by v1.x are linked to an administrator
	 * once, right after the update (or on the first request from Florank).
	 */
	public function migrate_legacy_key() {
		if ( $this->run_as && $this->api_key ) {
			$this->run_as->migrate_legacy( $this->api_key->exists() );
		}
	}

	/**
	 * Hook: init. Loads translations bundled in languages/.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'florank', false, dirname( plugin_basename( FLORANK_PLUGIN_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- bundled translations for sites not using translate.wordpress.org.
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
		$run_as    = new RunAsUser();
		$seo       = new SeoBridge();
		$json_ld   = new JsonLd( $seo );
		$index_now = new IndexNow();
		$posts     = new PostService( $seo, $json_ld, $index_now, new ImageImporter() );
		$rest      = new Controller( $api_key, $run_as, $posts, $seo );
		$settings  = new SettingsPage( $api_key, $run_as, $posts );

		$settings->register();
		add_action( 'init', array( $this, 'load_textdomain' ) );
		$this->api_key = $api_key;
		$this->run_as  = $run_as;
		add_action( 'admin_init', array( $this, 'migrate_legacy_key' ) );
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		add_action( 'wp_head', array( $json_ld, 'print_head' ) );
		add_action( 'init', array( $index_now, 'maybe_serve_key' ), 0 );
	}
}
