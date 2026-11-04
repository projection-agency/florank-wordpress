<?php
/**
 * Article JSON-LD sent by Florank (BlogPosting / FAQPage).
 *
 * @package Florank
 */

namespace Florank\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Stores Florank JSON-LD on the post and prints it in <head> when no SEO
 * plugin already outputs Article schema.
 */
class JsonLd {

	const META_KEY = '_florank_schema_json_ld';

	/**
	 * SEO plugin bridge.
	 *
	 * @var SeoBridge
	 */
	private $seo;

	/**
	 * Constructor.
	 *
	 * @param SeoBridge $seo SEO plugin bridge.
	 */
	public function __construct( SeoBridge $seo ) {
		$this->seo = $seo;
	}

	/**
	 * Saves the schema array from the request on the post.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $schema  "schema_json_ld" field from the request.
	 */
	public function store( $post_id, $schema ) {
		if ( ! is_array( $schema ) ) {
			return;
		}
		$schema_json = wp_json_encode( $schema );
		if ( is_string( $schema_json ) && '' !== $schema_json ) {
			update_post_meta( $post_id, self::META_KEY, wp_slash( $schema_json ) );
		}
	}

	/**
	 * Decodes stored JSON into a list of schema items. A single object
	 * (with "@type") becomes a one-item list.
	 *
	 * @param mixed $raw Stored meta value.
	 * @return array<int|string, mixed>
	 */
	public static function decode_items( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$items = json_decode( $raw, true );
		if ( ! is_array( $items ) ) {
			return array();
		}
		if ( isset( $items['@type'] ) ) {
			$items = array( $items );
		}
		return $items;
	}

	/**
	 * Replaces URL, dates and image of BlogPosting items with the real
	 * WordPress values, which are more accurate than those at send time.
	 *
	 * @param array<int|string, mixed> $items     Schema items.
	 * @param string                   $permalink Post permalink.
	 * @param string                   $published Publish date (ISO 8601, GMT).
	 * @param string                   $modified  Modified date (ISO 8601, GMT).
	 * @param string|false             $thumbnail Featured image URL or false.
	 * @return array<int|string, mixed>
	 */
	public static function apply_post_facts( array $items, $permalink, $published, $modified, $thumbnail ) {
		foreach ( $items as &$item ) {
			if ( ! is_array( $item ) || ( $item['@type'] ?? '' ) !== 'BlogPosting' ) {
				continue;
			}
			$item['url']              = $permalink;
			$item['mainEntityOfPage'] = array(
				'@type' => 'WebPage',
				'@id'   => $permalink,
			);
			$item['datePublished']    = $published;
			$item['dateModified']     = $modified;
			if ( $thumbnail ) {
				$item['image'] = array( $thumbnail );
			}
		}
		unset( $item );
		return $items;
	}

	/**
	 * Encodes one schema item as a <script type="application/ld+json"> tag.
	 * JSON_HEX_TAG keeps "</script>" inside values from closing the tag.
	 *
	 * @param array<string, mixed> $item Schema item.
	 * @return string
	 */
	public static function script_tag( array $item ) {
		return '<script type="application/ld+json">'
			. wp_json_encode( $item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )
			. "</script>\n";
	}

	/**
	 * Hook: wp_head.
	 */
	public function print_head() {
		if ( ! is_singular( 'post' ) || $this->seo->outputs_schema() ) {
			return;
		}
		$post_id = get_queried_object_id();
		$raw     = $post_id ? get_post_meta( $post_id, self::META_KEY, true ) : '';
		$items   = self::decode_items( $raw );
		if ( array() === $items ) {
			return;
		}
		$items = self::apply_post_facts(
			$items,
			(string) get_permalink( $post_id ),
			(string) get_post_time( 'c', true, $post_id ),
			(string) get_post_modified_time( 'c', true, $post_id ),
			get_the_post_thumbnail_url( $post_id, 'full' )
		);
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			// JSON is produced by wp_json_encode() with JSON_HEX_TAG; HTML escaping would break it.
			echo self::script_tag( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
