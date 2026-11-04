<?php
/**
 * REST routes under florank/v1.
 *
 * @package Florank
 */

namespace Florank\Rest;

use Florank\Auth\ApiKey;
use Florank\Auth\RunAsUser;
use Florank\Posts\PostService;
use Florank\Seo\SeoBridge;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Registers florank/v1 routes. The namespace, paths, methods and response
 * shapes are the contract with the Florank backend (docs/api-contract.md)
 * and must stay compatible with v1.x.
 */
class Controller {

	const NAMESPACE_V1 = 'florank/v1';

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
	 * SEO plugin bridge.
	 *
	 * @var SeoBridge
	 */
	private $seo;

	/**
	 * Constructor.
	 *
	 * @param ApiKey      $api_key API key.
	 * @param RunAsUser   $run_as  User the requests run as.
	 * @param PostService $posts   Post operations.
	 * @param SeoBridge   $seo     SEO plugin bridge.
	 */
	public function __construct( ApiKey $api_key, RunAsUser $run_as, PostService $posts, SeoBridge $seo ) {
		$this->api_key = $api_key;
		$this->run_as  = $run_as;
		$this->posts   = $posts;
		$this->seo     = $seo;
	}

	/**
	 * Hook: rest_api_init.
	 */
	public function register_routes() {
		$auth = array( $this, 'authorize' );

		register_rest_route(
			self::NAMESPACE_V1,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'health' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/posts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_posts' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_post' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_post' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_post' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_post' ),
				'permission_callback' => $auth,
			)
		);
	}

	/**
	 * Permission callback for every route: valid Florank API key in
	 * "Authorization: Bearer" or "X-Florank-Key", then the request runs as
	 * the user linked to the key (403 if that user is gone or lacks rights).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request ) {
		$key = ApiKey::extract( $request->get_header( 'authorization' ), $request->get_header( 'x-florank-key' ) );
		if ( ! $this->api_key->verify( $key ) ) {
			return new WP_Error( 'florank_unauthorized', __( 'Invalid Florank API key', 'florank' ), array( 'status' => 401 ) );
		}
		$this->run_as->migrate_legacy( true );
		$user = $this->run_as->resolve();
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		wp_set_current_user( $user->ID );
		if ( 0 === $this->run_as->last_request() ) {
			// The key reached Florank: stop showing it on the settings page.
			delete_transient( ApiKey::TRANSIENT_NEW );
		}
		$this->run_as->touch_last_request();
		return true;
	}

	/**
	 * GET /health.
	 *
	 * @return array<string, mixed>
	 */
	public function health() {
		return array(
			'ok'        => true,
			'plugin'    => 'florank',
			'version'   => FLORANK_VERSION,
			'seoPlugin' => $this->seo->detect(),
			'siteUrl'   => home_url( '/' ),
		);
	}

	/**
	 * GET /posts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_posts() {
		return $this->posts->list_posts();
	}

	/**
	 * GET /posts/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_post( WP_REST_Request $request ) {
		return $this->posts->get( (int) $request['id'] );
	}

	/**
	 * POST /posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_post( WP_REST_Request $request ) {
		return $this->save( 0, $request );
	}

	/**
	 * POST /posts/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_post( WP_REST_Request $request ) {
		return $this->save( (int) $request['id'], $request );
	}

	/**
	 * DELETE /posts/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public function delete_post( WP_REST_Request $request ) {
		return $this->posts->delete( (int) $request['id'] );
	}

	/**
	 * Creates or updates a post and returns its serialized form.
	 *
	 * @param int             $id      Post ID, 0 to create.
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	private function save( $id, WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$post_id = $this->posts->upsert( $id, is_array( $body ) ? $body : array() );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		return $this->posts->serialize( get_post( $post_id ) );
	}
}
