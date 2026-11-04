<?php
/**
 * Settings page: generate and rotate the Florank API key.
 *
 * Moved as-is from v1.4; the Florank-style redesign is stage 2.
 *
 * @package Florank
 */

namespace Florank\Admin;

use Florank\Auth\ApiKey;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu page "Florank".
 */
class SettingsPage {

	const SLUG            = 'florank';
	const CAPABILITY      = 'manage_options';
	const GENERATE_ACTION = 'florank_generate_key';

	/**
	 * API key.
	 *
	 * @var ApiKey
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param ApiKey $api_key API key.
	 */
	public function __construct( ApiKey $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Hook: admin_menu.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Florank', 'florank' ),
			__( 'Florank', 'florank' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-rest-api',
			80
		);
	}

	/**
	 * Hook: admin_post_florank_generate_key.
	 */
	public function handle_generate_key() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden', 'florank' ) );
		}
		check_admin_referer( self::GENERATE_ACTION );
		$this->api_key->generate();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::SLUG,
					'generated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the page.
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$plain = $this->api_key->pending_plain_key();
		$hint  = $this->api_key->hint();

		echo '<div class="wrap"><h1>' . esc_html__( 'Florank', 'florank' ) . '</h1>';
		echo '<p>' . esc_html__( 'Generate an API key and paste it in Florank → Connect WordPress.', 'florank' ) . '</p>';
		if ( '' !== $plain ) {
			echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Copy this key now.', 'florank' ) . '</strong> ' . esc_html__( 'It will not be shown again.', 'florank' ) . '</p>';
			echo '<p><input type="text" readonly id="florank-api-key" value="' . esc_attr( $plain ) . '" class="large-text" onclick="this.select()" /></p>';
			echo '<p><button type="button" class="button button-primary" onclick="navigator.clipboard.writeText(document.getElementById(\'florank-api-key\').value)">' . esc_html__( 'Copy Key', 'florank' ) . '</button></p></div>';
		} elseif ( '' !== $hint ) {
			/* translators: %s: first characters of the active API key, e.g. "fl_ab12…". */
			echo '<p>' . esc_html( sprintf( __( 'A key is already active (%s). Generate a new one to rotate.', 'florank' ), $hint ) ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::GENERATE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::GENERATE_ACTION ) . '" />';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Generate New API Key', 'florank' ) . '</button></p>';
		echo '</form></div>';
	}
}
