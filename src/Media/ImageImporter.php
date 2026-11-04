<?php
/**
 * Imports article images into the Media Library.
 *
 * @package Florank
 */

namespace Florank\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Imports images sent by Florank — as base64 bytes ("packed" images) or as
 * remote URLs — and rewrites post content to local attachment URLs.
 *
 * Every import method takes a per-request cache keyed by source URL, so the
 * same image is imported only once per request.
 */
class ImageImporter {

	/**
	 * Image URLs found in post content: HTML <img src> and Markdown ![](...).
	 *
	 * @param string $content Post content.
	 * @return string[]
	 */
	public static function extract_content_image_urls( $content ) {
		$urls = array();
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $html ) ) {
			$urls = array_merge( $urls, $html[1] );
		}
		if ( preg_match_all( '/!\[[^\]]*\]\((https?:\/\/[^)\s]+)\)/', $content, $md ) ) {
			$urls = array_merge( $urls, $md[1] );
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Imports an image passed as bytes:
	 * { sourceUrl, filename, mime, data (base64), alt }.
	 *
	 * @param int                                     $post_id Parent post ID.
	 * @param array<string, mixed>                    $packed  Packed image.
	 * @param string                                  $alt     Alt text override.
	 * @param array<string, array{id:int,url:string}> $cache  Per-request cache.
	 * @return array{id:int,url:string}|null
	 */
	public function import_packed( $post_id, array $packed, $alt, array &$cache ) {
		$source = isset( $packed['sourceUrl'] ) ? esc_url_raw( (string) $packed['sourceUrl'] ) : '';
		if ( '' !== $source && isset( $cache[ $source ] ) ) {
			return $cache[ $source ];
		}
		$raw      = isset( $packed['data'] ) ? (string) $packed['data'] : '';
		$filename = sanitize_file_name( (string) ( $packed['filename'] ?? 'image.png' ) );
		if ( '' === $raw || '' === $filename ) {
			return null;
		}
		// Florank sends image bytes as base64 in the JSON body; this is not obfuscation.
		$bytes = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bytes || '' === $bytes ) {
			return null;
		}
		// TODO(stage-2): limit size and MIME types of packed images. See plan "wordpress-plugin.md", section «Безпека».
		self::ensure_media_includes();
		$tmp = wp_tempnam( $filename );
		// Writing to a wp_tempnam() file that media_handle_sideload() moves into uploads.
		if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return null;
		}
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);
		$desc       = '' !== $alt ? $alt : sanitize_text_field( (string) ( $packed['alt'] ?? '' ) );
		$media_id   = media_handle_sideload( $file_array, $post_id, $desc );
		if ( is_wp_error( $media_id ) ) {
			wp_delete_file( $tmp );
			return null;
		}
		$local = wp_get_attachment_url( (int) $media_id );
		if ( ! is_string( $local ) || '' === $local ) {
			return null;
		}
		if ( '' !== $desc ) {
			update_post_meta( (int) $media_id, '_wp_attachment_image_alt', $desc );
		}
		$result = array(
			'id'  => (int) $media_id,
			'url' => $local,
		);
		if ( '' !== $source ) {
			$cache[ $source ] = $result;
		}
		return $result;
	}

	/**
	 * Downloads an image by URL into the Media Library.
	 *
	 * TODO(stage-2): accept only trusted Florank hosts (Florank blob storage)
	 * and limit size / types; today any http(s) URL is fetched. Florank
	 * already sends bytes ("featured_image" / "content_images"), so this path
	 * is a fallback. See plan "wordpress-plugin.md", section «Безпека».
	 *
	 * @param int                                     $post_id Parent post ID.
	 * @param string                                  $url     Remote image URL.
	 * @param string                                  $alt     Alt text.
	 * @param array<string, array{id:int,url:string}> $cache  Per-request cache.
	 * @return array{id:int,url:string}|null
	 */
	public function import_remote( $post_id, $url, $alt, array &$cache ) {
		$url = esc_url_raw( $url );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}
		if ( self::is_local_upload_url( $url ) ) {
			return null;
		}
		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		self::ensure_media_includes();
		$media_id = media_sideload_image( $url, $post_id, $alt, 'id' );
		if ( is_wp_error( $media_id ) ) {
			return null;
		}
		$local = wp_get_attachment_url( (int) $media_id );
		if ( ! is_string( $local ) || '' === $local ) {
			return null;
		}
		if ( '' !== $alt ) {
			update_post_meta( (int) $media_id, '_wp_attachment_image_alt', $alt );
		}
		$cache[ $url ] = array(
			'id'  => (int) $media_id,
			'url' => $local,
		);
		return $cache[ $url ];
	}

	/**
	 * Imports every remote image referenced in content and returns content
	 * with local URLs.
	 *
	 * @param int                                     $post_id Parent post ID.
	 * @param string                                  $content Post content.
	 * @param array<string, array{id:int,url:string}> $cache  Per-request cache.
	 * @return string
	 */
	public function import_content_images( $post_id, $content, array &$cache ) {
		foreach ( self::extract_content_image_urls( $content ) as $remote ) {
			$imported = $this->import_remote( $post_id, $remote, '', $cache );
			if ( ! $imported ) {
				continue;
			}
			$content = str_replace( $remote, $imported['url'], $content );
		}
		return $content;
	}

	/**
	 * Whether a URL already points to this site's uploads directory.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_local_upload_url( $url ) {
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		return '' !== $base && str_starts_with( $url, $base );
	}

	/**
	 * Loads admin media functions, which are not available in REST requests.
	 */
	private static function ensure_media_includes() {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
