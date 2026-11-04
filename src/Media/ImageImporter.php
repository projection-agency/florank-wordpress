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
 * URLs on Florank hosts — and rewrites post content to local attachment URLs.
 *
 * - Remote URLs are fetched only over https from the hosts in
 *   florank_allowed_image_hosts; other URLs are left as they are.
 * - Only JPEG, PNG, WebP, GIF and AVIF up to florank_max_image_bytes are imported.
 * - Imported attachments remember their source URL and SHA-256, so updating
 *   a post reuses them instead of filling the Media Library with copies.
 *
 * Every import method also takes a per-request cache keyed by source URL.
 */
class ImageImporter {

	const META_SOURCE_URL   = '_florank_source_url';
	const META_SOURCE_HASH  = '_florank_source_hash';
	const DEFAULT_MAX_BYTES = 10485760;
	const ALLOWED_MIMES     = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' );

	/**
	 * Hosts Florank serves article images from: Vercel Blob storage used by
	 * the Florank backend, and Florank's own domains. "*.example.com" matches
	 * subdomains only.
	 */
	const DEFAULT_HOSTS = array( '*.blob.vercel-storage.com', 'florank.ai', 'api.florank.ai' );

	/**
	 * Allowed image hosts after the florank_allowed_image_hosts filter.
	 *
	 * @return string[]
	 */
	public static function allowed_hosts() {
		/**
		 * Filters the hosts the plugin may download article images from (https only).
		 *
		 * @param string[] $hosts Host names; "*.example.com" matches subdomains of example.com.
		 */
		$hosts = apply_filters( 'florank_allowed_image_hosts', self::DEFAULT_HOSTS );
		return is_array( $hosts ) ? array_values( array_filter( array_map( 'strval', $hosts ) ) ) : array();
	}

	/**
	 * Whether a host matches one of the patterns.
	 *
	 * @param string   $host     Host name.
	 * @param string[] $patterns Host names or "*.domain" patterns.
	 * @return bool
	 */
	public static function host_matches( $host, array $patterns ) {
		$host = strtolower( rtrim( (string) $host, '.' ) );
		if ( '' === $host ) {
			return false;
		}
		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( trim( (string) $pattern ) );
			if ( str_starts_with( $pattern, '*.' ) ) {
				$suffix = substr( $pattern, 1 );
				if ( strlen( $host ) > strlen( $suffix ) && str_ends_with( $host, $suffix ) ) {
					return true;
				}
			} elseif ( '' !== $pattern && $host === $pattern ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a remote image URL may be downloaded: https and an allowed host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_allowed_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return false;
		}
		return self::host_matches( $parts['host'], self::allowed_hosts() );
	}

	/**
	 * Maximum image size in bytes after the florank_max_image_bytes filter.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		/**
		 * Filters the maximum size of an imported image, in bytes.
		 *
		 * @param int $bytes Default 10 MB.
		 */
		return max( 1, (int) apply_filters( 'florank_max_image_bytes', self::DEFAULT_MAX_BYTES ) );
	}

	/**
	 * Whether a MIME type is one of the accepted image types.
	 *
	 * @param mixed $mime MIME type.
	 * @return bool
	 */
	public static function is_allowed_mime( $mime ) {
		return is_string( $mime ) && in_array( strtolower( $mime ), self::ALLOWED_MIMES, true );
	}

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
		$max      = self::max_bytes();
		if ( '' === $raw || '' === $filename || strlen( $raw ) > 4 * (int) ceil( $max / 3 ) + 4 ) {
			return null;
		}
		// Florank sends image bytes as base64 in the JSON body; this is not obfuscation.
		$bytes = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > $max ) {
			return null;
		}
		$desc = '' !== $alt ? $alt : sanitize_text_field( (string) ( $packed['alt'] ?? '' ) );
		$hash = hash( 'sha256', $bytes );

		$existing = $this->find_existing( $source, $hash );
		if ( $existing ) {
			return $this->remember( $cache, $source, $existing, $desc );
		}

		self::ensure_media_includes();
		$tmp = wp_tempnam( $filename );
		// Writing to a wp_tempnam() file that media_handle_sideload() moves into uploads.
		if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return null;
		}
		$result = $this->sideload( $post_id, $tmp, $filename, $desc, $source, $hash );
		return $result ? $this->remember( $cache, $source, $result, '' ) : null;
	}

	/**
	 * Downloads an image from an allowed Florank host into the Media Library.
	 * Other URLs are skipped without an error.
	 *
	 * @param int                                     $post_id Parent post ID.
	 * @param string                                  $url     Remote image URL.
	 * @param string                                  $alt     Alt text.
	 * @param array<string, array{id:int,url:string}> $cache  Per-request cache.
	 * @return array{id:int,url:string}|null
	 */
	public function import_remote( $post_id, $url, $alt, array &$cache ) {
		$url = esc_url_raw( $url );
		if ( '' === $url || ! self::is_allowed_url( $url ) || self::is_local_upload_url( $url ) ) {
			return null;
		}
		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}
		$existing = $this->find_existing( $url, '' );
		if ( $existing ) {
			return $this->remember( $cache, $url, $existing, $alt );
		}

		self::ensure_media_includes();
		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$filename = sanitize_file_name( wp_basename( $path ) );
		if ( '' === $filename ) {
			$filename = 'image';
		}
		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return null;
		}
		$max      = self::max_bytes();
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 30,
				'redirection'         => 0,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $max + 1,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $tmp );
			return null;
		}
		$size = (int) filesize( $tmp );
		if ( 0 === $size || $size > $max ) {
			wp_delete_file( $tmp );
			return null;
		}
		$hash     = (string) hash_file( 'sha256', $tmp );
		$existing = $this->find_existing( '', $hash );
		if ( $existing ) {
			wp_delete_file( $tmp );
			update_post_meta( $existing['id'], self::META_SOURCE_URL, $url );
			return $this->remember( $cache, $url, $existing, $alt );
		}
		$result = $this->sideload( $post_id, $tmp, $filename, $alt, $url, $hash );
		return $result ? $this->remember( $cache, $url, $result, '' ) : null;
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
	 * Attachment previously imported from the same source URL or with the
	 * same bytes, if it still exists.
	 *
	 * @param string $source Source URL ('' to skip).
	 * @param string $hash   SHA-256 of the bytes ('' to skip).
	 * @return array{id:int,url:string}|null
	 */
	private function find_existing( $source, $hash ) {
		$pairs = array_filter(
			array(
				self::META_SOURCE_URL  => $source,
				self::META_SOURCE_HASH => $hash,
			)
		);
		foreach ( $pairs as $meta_key => $meta_value ) {
			$ids = get_posts(
				array(
					'post_type'        => 'attachment',
					'post_status'      => 'inherit',
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'meta_key'         => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( empty( $ids[0] ) ) {
				continue;
			}
			$local = wp_get_attachment_url( (int) $ids[0] );
			if ( is_string( $local ) && '' !== $local ) {
				return array(
					'id'  => (int) $ids[0],
					'url' => $local,
				);
			}
		}//end foreach
		return null;
	}

	/**
	 * Checks the real file type and moves a temporary file into the Media Library.
	 *
	 * @param int    $post_id  Parent post ID.
	 * @param string $tmp      Temporary file (deleted on failure).
	 * @param string $filename File name.
	 * @param string $desc     Alt text / description.
	 * @param string $source   Source URL.
	 * @param string $hash     SHA-256 of the bytes.
	 * @return array{id:int,url:string}|null
	 */
	private function sideload( $post_id, $tmp, $filename, $desc, $source, $hash ) {
		$check = wp_check_filetype_and_ext( $tmp, $filename );
		if ( ! empty( $check['proper_filename'] ) ) {
			$filename = $check['proper_filename'];
		}
		if ( empty( $check['type'] ) || ! self::is_allowed_mime( $check['type'] ) || ! self::is_allowed_mime( wp_get_image_mime( $tmp ) ) ) {
			wp_delete_file( $tmp );
			return null;
		}
		$media_id = media_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			),
			$post_id,
			$desc
		);
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
		if ( '' !== $source ) {
			update_post_meta( (int) $media_id, self::META_SOURCE_URL, $source );
		}
		update_post_meta( (int) $media_id, self::META_SOURCE_HASH, $hash );
		return array(
			'id'  => (int) $media_id,
			'url' => $local,
		);
	}

	/**
	 * Stores a result in the per-request cache and updates the alt text.
	 *
	 * @param array<string, array{id:int,url:string}> $cache  Per-request cache.
	 * @param string                                  $source Source URL.
	 * @param array{id:int,url:string}                $result Attachment.
	 * @param string                                  $alt    Alt text to set ('' keeps the current one).
	 * @return array{id:int,url:string}
	 */
	private function remember( array &$cache, $source, array $result, $alt ) {
		if ( '' !== $alt ) {
			update_post_meta( $result['id'], '_wp_attachment_image_alt', $alt );
		}
		if ( '' !== $source ) {
			$cache[ $source ] = $result;
		}
		return $result;
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
