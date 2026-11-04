<?php
/**
 * Bridge to SEO plugins (Yoast SEO, Rank Math).
 *
 * @package Florank
 */

namespace Florank\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the active SEO plugin and writes SEO title / description meta.
 */
class SeoBridge {

	const NONE     = 'none';
	const YOAST    = 'yoast';
	const RANKMATH = 'rankmath';

	/**
	 * Active SEO plugin as reported by /health: "yoast", "rankmath" or "none".
	 * Rank Math wins when both are active (same as v1.x).
	 *
	 * @return string
	 */
	public function detect() {
		$seo = self::NONE;
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta' ) ) {
			$seo = self::YOAST;
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$seo = self::RANKMATH;
		}
		return $seo;
	}

	/**
	 * Whether an SEO plugin already prints Article schema, so Florank's own
	 * JSON-LD output would be a duplicate.
	 *
	 * @return bool
	 */
	public function outputs_schema() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
	}

	/**
	 * Writes the SEO title and description for both Yoast and Rank Math, so
	 * the values survive switching between them.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $title       SEO title.
	 * @param string $description Meta description.
	 */
	public function write_meta( $post_id, $title, $description ) {
		update_post_meta( $post_id, '_yoast_wpseo_title', $title );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
		update_post_meta( $post_id, 'rank_math_title', $title );
		update_post_meta( $post_id, 'rank_math_description', $description );
	}
}
