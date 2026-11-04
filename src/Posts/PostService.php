<?php
/**
 * Creates, updates, lists and deletes posts on behalf of Florank.
 *
 * @package Florank
 */

namespace Florank\Posts;

use Florank\IndexNow\IndexNow;
use Florank\Media\ImageImporter;
use Florank\Seo\JsonLd;
use Florank\Seo\SeoBridge;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Post operations behind the florank/v1 REST routes.
 */
class PostService {

	const ALLOWED_STATUSES = array( 'draft', 'publish', 'future' );

	/**
	 * SEO plugin bridge.
	 *
	 * @var SeoBridge
	 */
	private $seo;

	/**
	 * JSON-LD storage.
	 *
	 * @var JsonLd
	 */
	private $json_ld;

	/**
	 * IndexNow key storage.
	 *
	 * @var IndexNow
	 */
	private $index_now;

	/**
	 * Image importer.
	 *
	 * @var ImageImporter
	 */
	private $images;

	/**
	 * Constructor.
	 *
	 * @param SeoBridge     $seo       SEO plugin bridge.
	 * @param JsonLd        $json_ld   JSON-LD storage.
	 * @param IndexNow      $index_now IndexNow key storage.
	 * @param ImageImporter $images    Image importer.
	 */
	public function __construct( SeoBridge $seo, JsonLd $json_ld, IndexNow $index_now, ImageImporter $images ) {
		$this->seo       = $seo;
		$this->json_ld   = $json_ld;
		$this->index_now = $index_now;
		$this->images    = $images;
	}

	/**
	 * Normalises the requested status; anything unknown becomes "draft".
	 *
	 * @param mixed $status "status" field from the request.
	 * @return string
	 */
	public static function sanitize_status( $status ) {
		$status = ! empty( $status ) ? sanitize_key( $status ) : 'draft';
		return in_array( $status, self::ALLOWED_STATUSES, true ) ? $status : 'draft';
	}

	/**
	 * Last 50 posts (published, drafts, scheduled), most recently modified first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_posts() {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => self::ALLOWED_STATUSES,
				'posts_per_page' => 50,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->serialize( $post );
		}
		return $items;
	}

	/**
	 * One post by ID.
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'florank_not_found', __( 'Post not found', 'florank' ), array( 'status' => 404 ) );
		}
		return $this->serialize( $post );
	}

	/**
	 * Permanently deletes a post.
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function delete( $id ) {
		$deleted = wp_delete_post( $id, true );
		if ( ! $deleted ) {
			return new WP_Error( 'florank_delete_failed', __( 'Could not delete post', 'florank' ), array( 'status' => 400 ) );
		}
		return array(
			'ok' => true,
			'id' => $id,
		);
	}

	/**
	 * Creates ($id = 0) or updates a post from the Florank payload.
	 * See docs/api-contract.md for the fields.
	 *
	 * @param int                  $id   Post ID, 0 to create.
	 * @param array<string, mixed> $body Request JSON body.
	 * @return int|WP_Error Post ID.
	 */
	public function upsert( $id, array $body ) {
		$data = array(
			'post_type'    => 'post',
			'post_title'   => sanitize_text_field( $body['title'] ?? '' ),
			'post_name'    => sanitize_title( $body['slug'] ?? '' ),
			'post_content' => wp_kses_post( $body['content'] ?? '' ),
			'post_excerpt' => sanitize_textarea_field( $body['excerpt'] ?? '' ),
			'post_status'  => self::sanitize_status( $body['status'] ?? '' ),
		);
		if ( $id > 0 ) {
			$data['ID'] = $id;
			$post_id    = wp_update_post( $data, true );
		} else {
			$post_id = wp_insert_post( $data, true );
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->seo->write_meta(
			$post_id,
			sanitize_text_field( $body['meta_title'] ?? $body['title'] ?? '' ),
			sanitize_textarea_field( $body['meta_description'] ?? '' )
		);

		$this->index_now->store_key( $body['indexnow_key'] ?? '' );

		$author_email = sanitize_email( $body['author_email'] ?? '' );
		if ( '' !== $author_email ) {
			$author = get_user_by( 'email', $author_email );
			if ( $author ) {
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_author' => (int) $author->ID,
					)
				);
			}
		}

		$this->json_ld->store( $post_id, $body['schema_json_ld'] ?? null );

		if ( ! empty( $body['categories'] ) && is_array( $body['categories'] ) ) {
			wp_set_post_categories( $post_id, array_map( 'intval', $body['categories'] ) );
		}

		self::act_as_admin();

		$this->import_images( $post_id, $body );

		return $post_id;
	}

	/**
	 * Featured image (bytes first, then URL) and content images.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $body    Request JSON body.
	 */
	private function import_images( $post_id, array $body ) {
		$imported     = array();
		$featured_alt = sanitize_text_field( $body['featured_image_alt'] ?? '' );
		$featured     = null;
		if ( ! empty( $body['featured_image'] ) && is_array( $body['featured_image'] ) ) {
			$featured = $this->images->import_packed( $post_id, $body['featured_image'], $featured_alt, $imported );
		}
		if ( ! $featured ) {
			$featured_url = esc_url_raw( $body['featured_image_url'] ?? '' );
			if ( '' !== $featured_url ) {
				$featured = $this->images->import_remote( $post_id, $featured_url, $featured_alt, $imported );
			}
		}
		if ( $featured ) {
			set_post_thumbnail( $post_id, $featured['id'] );
		}

		$original = (string) get_post_field( 'post_content', $post_id );
		$content  = $original;
		if ( ! empty( $body['content_images'] ) && is_array( $body['content_images'] ) ) {
			foreach ( $body['content_images'] as $packed ) {
				if ( ! is_array( $packed ) ) {
					continue;
				}
				$local  = $this->images->import_packed( $post_id, $packed, '', $imported );
				$source = isset( $packed['sourceUrl'] ) ? (string) $packed['sourceUrl'] : '';
				if ( $local && '' !== $source ) {
					$content = str_replace( $source, $local['url'], $content );
				}
			}
		}
		$content = $this->images->import_content_images( $post_id, $content, $imported );
		if ( $content !== $original ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
				)
			);
		}
	}

	/**
	 * Media Library uploads need a user with upload_files; REST requests from
	 * Florank are authenticated by API key, not by a user, so v1.2+ switches
	 * to the first administrator.
	 *
	 * TODO(stage-2): remove the administrator substitution. Run Florank
	 * requests as a user chosen by the site owner (default: whoever created
	 * the key) and check capabilities. Blocker for wordpress.org review.
	 * See plan "wordpress-plugin.md", section «Безпека».
	 */
	private static function act_as_admin() {
		if ( get_current_user_id() ) {
			return;
		}
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		if ( ! empty( $admins[0] ) ) {
			wp_set_current_user( (int) $admins[0] );
		}
	}

	/**
	 * Post shape returned by every florank/v1 posts route.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public function serialize( WP_Post $post ) {
		return array(
			'id'             => $post->ID,
			'title'          => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'link'           => get_permalink( $post ),
			'modified'       => $post->post_modified_gmt,
			'featured_media' => (int) get_post_thumbnail_id( $post ),
		);
	}
}
