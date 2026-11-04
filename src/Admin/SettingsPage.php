<?php
/**
 * Settings page: connect the site to Florank in three steps.
 *
 * @package Florank
 */

namespace Florank\Admin;

use Florank\Auth\ApiKey;
use Florank\Auth\ConnectionCode;
use Florank\Auth\RunAsUser;
use Florank\Posts\PostService;
use WP_Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu page "Florank": API key, the user Florank publishes as,
 * connection status and the latest articles from Florank.
 */
class SettingsPage {

	const SLUG            = 'florank';
	const CAPABILITY      = 'manage_options';
	const HOOK_SUFFIX     = 'toplevel_page_florank';
	const GENERATE_ACTION = 'florank_generate_key';
	const REVOKE_ACTION   = 'florank_revoke_key';
	const USER_ACTION     = 'florank_set_user';
	const APP_URL         = 'https://app.florank.ai';

	/**
	 * API key.
	 *
	 * @var ApiKey
	 */
	private $api_key;

	/**
	 * User the requests run as.
	 *
	 * @var RunAsUser
	 */
	private $run_as;

	/**
	 * Post operations.
	 *
	 * @var PostService
	 */
	private $posts;

	/**
	 * Constructor.
	 *
	 * @param ApiKey      $api_key API key.
	 * @param RunAsUser   $run_as  User the requests run as.
	 * @param PostService $posts   Post operations.
	 */
	public function __construct( ApiKey $api_key, RunAsUser $run_as, PostService $posts ) {
		$this->api_key = $api_key;
		$this->run_as  = $run_as;
		$this->posts   = $posts;
	}

	/**
	 * Registers admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'admin_post_' . self::GENERATE_ACTION, array( $this, 'handle_generate_key' ) );
		add_action( 'admin_post_' . self::REVOKE_ACTION, array( $this, 'handle_revoke_key' ) );
		add_action( 'admin_post_' . self::USER_ACTION, array( $this, 'handle_set_user' ) );
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
			plugins_url( 'assets/menu-icon.svg', FLORANK_PLUGIN_FILE ),
			80
		);
	}

	/**
	 * Hook: admin_enqueue_scripts. Loads the page styles and script on this page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( self::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'florank-admin', plugins_url( 'assets/admin.css', FLORANK_PLUGIN_FILE ), array(), FLORANK_VERSION );
		wp_enqueue_script(
			'florank-admin',
			plugins_url( 'assets/admin.js', FLORANK_PLUGIN_FILE ),
			array(),
			FLORANK_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Hook: admin_body_class. Marks this page so its background can be styled.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::HOOK_SUFFIX === $screen->id ) {
			$classes .= ' florank-admin-page';
		}
		return $classes;
	}

	/**
	 * Hook: admin_post_florank_generate_key. New key; Florank will run as the
	 * administrator who created it until changed on this page.
	 */
	public function handle_generate_key() {
		$this->guard( self::GENERATE_ACTION );
		$this->api_key->generate();
		$this->run_as->set( get_current_user_id() );
		$this->run_as->reset_last_request();
		$this->back( array( 'generated' => '1' ) );
	}

	/**
	 * Hook: admin_post_florank_revoke_key.
	 */
	public function handle_revoke_key() {
		$this->guard( self::REVOKE_ACTION );
		$this->api_key->revoke();
		$this->run_as->reset_last_request();
		$this->back( array( 'florank_notice' => 'revoked' ) );
	}

	/**
	 * Hook: admin_post_florank_set_user.
	 */
	public function handle_set_user() {
		$this->guard( self::USER_ACTION );
		// Nonce checked in guard() above.
		$user_id = isset( $_POST['florank_user'] ) ? absint( wp_unslash( $_POST['florank_user'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$saved   = $this->run_as->set( $user_id );
		$this->back( array( 'florank_notice' => $saved ? 'user_saved' : 'user_invalid' ) );
	}

	/**
	 * Capability and nonce check for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden', 'florank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirects back to the page and exits.
	 *
	 * @param array<string, string> $args Query args.
	 */
	private function back( array $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * URL of this page with extra query args.
	 *
	 * @param array<string, string> $args Query args.
	 * @return string
	 */
	private static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Read-only GET flag used by the no-JS fallbacks of "Revoke" and "Change".
	 *
	 * @param string $name Query arg.
	 * @return string
	 */
	private static function query_flag( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only toggles what is displayed.
		return isset( $_GET[ $name ] ) ? sanitize_key( wp_unslash( $_GET[ $name ] ) ) : '';
	}

	/**
	 * Renders the page.
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$this->run_as->migrate_legacy( $this->api_key->exists() );

		$plain     = $this->api_key->pending_plain_key();
		$has_key   = $this->api_key->exists();
		$last      = $has_key ? $this->run_as->last_request() : 0;
		$connected = $last > 0;
		$notice    = self::query_flag( 'florank_notice' );
		$messages  = array(
			'revoked'      => __( 'Key revoked', 'florank' ),
			'user_saved'   => __( 'Saved', 'florank' ),
			'user_invalid' => __( 'This user cannot edit posts or upload files.', 'florank' ),
		);
		?>
		<div class="wrap florank-admin">
			<header class="florank-head">
				<h1 class="florank-logo">
					<img src="<?php echo esc_url( plugins_url( 'assets/logo.svg', FLORANK_PLUGIN_FILE ) ); ?>" alt="<?php esc_attr_e( 'Florank', 'florank' ); ?>" width="160" height="36" />
				</h1>
				<?php $this->render_status_chip( $has_key, $connected ); ?>
			</header>
			<hr class="wp-header-end" />

			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<p class="florank-notice<?php echo 'user_invalid' === $notice ? ' is-error' : ''; ?>" role="status"><?php echo esc_html( $messages[ $notice ] ); ?></p>
			<?php endif; ?>

			<section class="florank-card">
				<h2 class="florank-card-title"><?php esc_html_e( 'Connection', 'florank' ); ?></h2>
				<ol class="florank-steps">
					<li class="florank-step<?php echo $has_key ? ' is-done' : ''; ?>">
						<span class="florank-step-num" aria-hidden="true">1</span>
						<div class="florank-step-body">
							<span class="florank-step-title"><?php esc_html_e( 'Create a key', 'florank' ); ?></span>
							<?php $this->render_key_step( $plain, $has_key ); ?>
						</div>
					</li>
					<li class="florank-step<?php echo $connected ? ' is-done' : ''; ?>">
						<span class="florank-step-num" aria-hidden="true">2</span>
						<div class="florank-step-body">
							<span class="florank-step-title"><?php esc_html_e( 'Copy the code and paste it in Florank', 'florank' ); ?></span>
							<?php $this->render_code_step( $plain, $has_key, $connected ); ?>
						</div>
					</li>
					<li class="florank-step<?php echo $connected ? ' is-done' : ''; ?>">
						<span class="florank-step-num" aria-hidden="true">3</span>
						<div class="florank-step-body">
							<span class="florank-step-title"><?php esc_html_e( 'Status', 'florank' ); ?></span>
							<?php if ( $connected ) : ?>
								<span class="florank-state is-ok"><?php esc_html_e( 'Connected', 'florank' ); ?></span>
								<span class="florank-meta">
									<?php
									/* translators: %s: date and time of the last request from Florank. */
									echo esc_html( sprintf( __( 'Last request: %s', 'florank' ), wp_date( __( 'Y-m-d H:i', 'florank' ), $last ) ) );
									?>
								</span>
							<?php elseif ( $has_key ) : ?>
								<span class="florank-state is-wait"><?php esc_html_e( 'Waiting for the first request', 'florank' ); ?></span>
							<?php else : ?>
								<span class="florank-state is-wait"><?php esc_html_e( 'Not connected', 'florank' ); ?></span>
							<?php endif; ?>
						</div>
					</li>
				</ol>
			</section>

			<?php if ( $has_key ) : ?>
				<?php $this->render_user_card(); ?>
			<?php endif; ?>

			<?php $this->render_recent_posts(); ?>
		</div>
		<?php
	}

	/**
	 * Status chip in the page header.
	 *
	 * @param bool $has_key   Whether a key is active.
	 * @param bool $connected Whether Florank already called the site.
	 */
	private function render_status_chip( $has_key, $connected ) {
		if ( $connected ) {
			$class = 'is-ok';
			$label = __( 'Connected', 'florank' );
		} elseif ( $has_key ) {
			$class = 'is-wait';
			$label = __( 'Waiting for the first request', 'florank' );
		} else {
			$class = 'is-off';
			$label = __( 'Not connected', 'florank' );
		}
		printf( '<span class="florank-chip %1$s">%2$s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	/**
	 * Step 1: create, copy, rotate or revoke the key.
	 *
	 * @param string $plain   Key generated in the last minutes ('' if none).
	 * @param bool   $has_key Whether a key is active.
	 */
	private function render_key_step( $plain, $has_key ) {
		if ( ! $has_key ) {
			$this->render_action_form( self::GENERATE_ACTION, __( 'Create key', 'florank' ), 'is-accent' );
			return;
		}
		if ( '' !== $plain ) {
			?>
			<div class="florank-actions">
				<span class="florank-tag is-mono"><?php echo esc_html( $this->api_key->hint() ); ?></span>
			</div>
			<details class="florank-details">
				<summary><?php esc_html_e( 'Show the key separately', 'florank' ); ?></summary>
				<div class="florank-snippet">
					<code class="florank-code" id="florank-api-key"><?php echo esc_html( $plain ); ?></code>
					<button type="button" class="florank-icon-btn" data-florank-copy="florank-api-key" aria-label="<?php esc_attr_e( 'Copy key', 'florank' ); ?>" data-florank-copied="<?php esc_attr_e( 'Copied', 'florank' ); ?>"><?php self::copy_icon(); ?></button>
				</div>
			</details>
			<?php
			return;
		}
		$confirm = 'revoke' === self::query_flag( 'florank_confirm' );
		?>
		<div class="florank-actions" id="florank-key-actions" <?php echo $confirm ? 'hidden' : ''; ?>>
			<span class="florank-tag is-mono"><?php echo esc_html( $this->api_key->hint() ); ?></span>
			<?php $this->render_action_form( self::GENERATE_ACTION, __( 'New key', 'florank' ), 'is-outline' ); ?>
			<a class="florank-btn is-outline" href="<?php echo esc_url( self::page_url( array( 'florank_confirm' => 'revoke' ) ) ); ?>" data-florank-open="florank-revoke" data-florank-hide="florank-key-actions"><?php esc_html_e( 'Revoke key', 'florank' ); ?></a>
		</div>
		<div class="florank-confirm" id="florank-revoke" <?php echo $confirm ? '' : 'hidden'; ?>>
			<span class="florank-confirm-text"><?php esc_html_e( 'Revoke the key? Florank will stop publishing to this site.', 'florank' ); ?></span>
			<div class="florank-actions">
				<?php $this->render_action_form( self::REVOKE_ACTION, __( 'Yes, revoke', 'florank' ), 'is-dark' ); ?>
				<a class="florank-btn is-outline" href="<?php echo esc_url( self::page_url() ); ?>" data-florank-open="florank-key-actions" data-florank-hide="florank-revoke"><?php esc_html_e( 'Cancel', 'florank' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Step 2: the connection code (site address + key) right after the key is created,
	 * a masked placeholder afterwards, and the link to Florank.
	 *
	 * @param string $plain     Key generated in the last minutes ('' if none).
	 * @param bool   $has_key   Whether a key is active.
	 * @param bool   $connected Whether Florank already called the site.
	 */
	private function render_code_step( $plain, $has_key, $connected ) {
		$open_style = ( $has_key && '' === $plain && ! $connected ) ? 'is-accent' : 'is-dark';
		if ( '' !== $plain ) {
			?>
			<span class="florank-label" id="florank-connection-code-label"><?php esc_html_e( 'Connection code', 'florank' ); ?></span>
			<div class="florank-snippet">
				<code class="florank-code is-key" id="florank-connection-code" aria-labelledby="florank-connection-code-label"><?php echo esc_html( ConnectionCode::build( ConnectionCode::site_address(), $plain ) ); ?></code>
			</div>
			<?php
		} elseif ( $has_key ) {
			?>
			<span class="florank-tag is-mono"><?php echo esc_html( ConnectionCode::PREFIX . '…' ); ?></span>
			<?php
		}
		?>
		<div class="florank-actions">
			<?php if ( '' !== $plain ) : ?>
				<button type="button" class="florank-btn is-accent" data-florank-copy="florank-connection-code" data-florank-copied="<?php esc_attr_e( 'Copied', 'florank' ); ?>"><?php esc_html_e( 'Copy', 'florank' ); ?></button>
				<span class="florank-tag"><?php esc_html_e( 'Shown once', 'florank' ); ?></span>
			<?php endif; ?>
			<a class="florank-btn <?php echo esc_attr( $open_style ); ?>" href="<?php echo esc_url( self::APP_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Florank', 'florank' ); ?></a>
		</div>
		<?php
	}

	/**
	 * A one-button form posting to admin-post.php with a nonce.
	 *
	 * @param string $action Action name.
	 * @param string $label  Button label.
	 * @param string $style  Button style class.
	 */
	private function render_action_form( $action, $label, $style ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="florank-inline-form">
			<?php wp_nonce_field( $action ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<button type="submit" class="florank-btn <?php echo esc_attr( $style ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * "Publishing as" card.
	 */
	private function render_user_card() {
		$user_id  = (int) $this->run_as->get_id();
		$user     = $user_id > 0 ? get_userdata( $user_id ) : false;
		$valid    = RunAsUser::is_eligible( $user );
		$editing  = ! $valid || 'user' === self::query_flag( 'florank_edit' );
		$eligible = $this->run_as->eligible_users();
		?>
		<section class="florank-card florank-user">
			<div class="florank-row" id="florank-user-view" <?php echo $editing && $valid ? 'hidden' : ''; ?>>
				<span class="florank-card-title"><?php esc_html_e( 'Publishing as', 'florank' ); ?></span>
				<?php if ( $user instanceof WP_User ) : ?>
					<span class="florank-user-name"><?php echo esc_html( $user->display_name ); ?>
						<?php if ( $user->display_name !== $user->user_login ) : ?>
							<span class="florank-meta is-mono"><?php echo esc_html( $user->user_login ); ?></span>
						<?php endif; ?>
					</span>
				<?php endif; ?>
				<?php if ( $valid ) : ?>
					<a class="florank-link" href="<?php echo esc_url( self::page_url( array( 'florank_edit' => 'user' ) ) ); ?>" data-florank-open="florank-user-form" data-florank-hide="florank-user-view"><?php esc_html_e( 'Change', 'florank' ); ?></a>
				<?php elseif ( $user instanceof WP_User ) : ?>
					<span class="florank-state is-error"><?php esc_html_e( 'This user cannot edit posts or upload files.', 'florank' ); ?></span>
				<?php else : ?>
					<span class="florank-state is-error"><?php esc_html_e( 'User not found. Choose another one.', 'florank' ); ?></span>
				<?php endif; ?>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="florank-row" id="florank-user-form" <?php echo $editing ? '' : 'hidden'; ?>>
				<?php wp_nonce_field( self::USER_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::USER_ACTION ); ?>" />
				<label class="florank-card-title" for="florank-user-select"><?php esc_html_e( 'Publishing as', 'florank' ); ?></label>
				<select name="florank_user" id="florank-user-select" class="florank-select">
					<?php foreach ( $eligible as $candidate ) : ?>
						<option value="<?php echo esc_attr( (string) $candidate->ID ); ?>" <?php selected( $candidate->ID, $user_id ); ?>><?php echo esc_html( $candidate->display_name . ' (' . $candidate->user_login . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<div class="florank-actions">
					<button type="submit" class="florank-btn is-dark"><?php esc_html_e( 'Save', 'florank' ); ?></button>
					<?php if ( $valid ) : ?>
						<a class="florank-btn is-outline" href="<?php echo esc_url( self::page_url() ); ?>" data-florank-open="florank-user-view" data-florank-hide="florank-user-form"><?php esc_html_e( 'Cancel', 'florank' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		</section>
		<?php
	}

	/**
	 * Latest 10 articles created or updated through Florank.
	 */
	private function render_recent_posts() {
		$posts = $this->posts->recent_managed( 10 );
		?>
		<section class="florank-block">
			<h2 class="florank-block-title"><?php esc_html_e( 'Latest articles from Florank', 'florank' ); ?></h2>
			<?php if ( empty( $posts ) ) : ?>
				<p class="florank-empty"><?php esc_html_e( 'No articles yet', 'florank' ); ?></p>
			<?php else : ?>
				<div class="florank-table" role="table">
					<div class="florank-tr is-head" role="row">
						<span role="columnheader"><?php esc_html_e( 'Title', 'florank' ); ?></span>
						<span role="columnheader"><?php esc_html_e( 'Status', 'florank' ); ?></span>
						<span role="columnheader"><?php esc_html_e( 'Updated', 'florank' ); ?></span>
						<span role="columnheader"><span class="screen-reader-text"><?php esc_html_e( 'Link', 'florank' ); ?></span></span>
					</div>
					<?php foreach ( $posts as $post ) : ?>
						<?php $this->render_post_row( $post ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * One row of the latest articles table.
	 *
	 * @param WP_Post $post Post.
	 */
	private function render_post_row( WP_Post $post ) {
		$title = get_the_title( $post );
		$title = '' !== $title ? $title : __( '(no title)', 'florank' );
		$edit  = get_edit_post_link( $post->ID, 'raw' );
		$view  = 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post );
		?>
		<div class="florank-tr" role="row">
			<span class="florank-td-title" role="cell">
				<?php if ( $edit ) : ?>
					<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $title ); ?>
				<?php endif; ?>
			</span>
			<span role="cell"><span class="florank-tag is-<?php echo esc_attr( $post->post_status ); ?>"><?php echo esc_html( self::status_label( $post->post_status ) ); ?></span></span>
			<span class="florank-meta is-mono" role="cell"><?php echo esc_html( wp_date( __( 'Y-m-d H:i', 'florank' ), (int) get_post_modified_time( 'U', true, $post ) ) ); ?></span>
			<span role="cell">
				<?php if ( $view ) : ?>
					<a class="florank-link" href="<?php echo esc_url( $view ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'florank' ); ?></a>
				<?php endif; ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Translated post status.
	 *
	 * @param string $status Post status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'publish' => __( 'Published', 'florank' ),
			'draft'   => __( 'Draft', 'florank' ),
			'future'  => __( 'Scheduled', 'florank' ),
			'pending' => __( 'Pending', 'florank' ),
			'private' => __( 'Private', 'florank' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Inline copy icon (decorative).
	 */
	private static function copy_icon() {
		echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
	}
}
