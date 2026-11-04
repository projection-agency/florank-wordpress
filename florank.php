<?php
/**
 * Plugin Name:       Florank
 * Plugin URI:        https://florank.ai
 * Description:       Connect this WordPress site to Florank to publish articles and sync SEO fields.
 * Version:           2.1.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Florank
 * Author URI:        https://florank.ai
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       florank
 * Domain Path:       /languages
 *
 * @package Florank
 */

defined( 'ABSPATH' ) || exit;

define( 'FLORANK_VERSION', '2.1.1' );
define( 'FLORANK_PLUGIN_FILE', __FILE__ );
define( 'FLORANK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once FLORANK_PLUGIN_DIR . 'src/autoload.php';

register_activation_hook( __FILE__, array( \Florank\Plugin::class, 'activate' ) );

\Florank\Plugin::instance()->boot();
