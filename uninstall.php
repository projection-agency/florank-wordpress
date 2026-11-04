<?php
/**
 * Removes everything Florank stored when the plugin is deleted from WordPress.
 *
 * @package Florank
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes Florank options, transients and post meta for the current site.
 */
function florank_uninstall_site() {
	delete_option( 'florank_api_key_hash' );
	delete_option( 'florank_api_key_hint' );
	delete_option( 'florank_indexnow_key' );
	delete_transient( 'florank_new_api_key' );
	delete_post_meta_by_key( '_florank_schema_json_ld' );
}

if ( is_multisite() ) {
	$florank_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $florank_site_ids as $florank_site_id ) {
		switch_to_blog( (int) $florank_site_id );
		florank_uninstall_site();
		restore_current_blog();
	}
} else {
	florank_uninstall_site();
}
