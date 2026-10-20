<?php
/** Remove only Remote Content Shortcode's bounded public-response cache. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

/** Backend changes can leave transient rows behind, so remove both representations. */
function remote_content_shortcode_uninstall_cache_site() {
	global $wpdb;
	$index = get_option( 'rcs_v2_cache_index', array() );
	if ( is_array( $index ) ) {
		foreach ( array_keys( $index ) as $id ) {
			if ( ! is_string( $id ) || ! preg_match( '/^rcs_v2_[a-f0-9]{64}$/D', $id ) ) { continue; }
			wp_cache_delete( $id, 'remote_content_shortcode' );
			wp_cache_delete( $id, 'transient' );
			delete_option( '_transient_' . $id );
			delete_option( '_transient_timeout_' . $id );
		}
	}
	// Include expired or previously abandoned payload rows without loading their bodies.
	$payload_prefix = $wpdb->esc_like( '_transient_rcs_v2_' ) . '%';
	$timeout_prefix = $wpdb->esc_like( '_transient_timeout_rcs_v2_' ) . '%';
	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must discover each remaining batch after deletion without cached query results.
		$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 100", $payload_prefix, $timeout_prefix ) );
		$removed = 0;
		foreach ( $names as $name ) { if ( delete_option( $name ) ) { ++$removed; } }
	} while ( 100 === count( $names ) && $removed > 0 );
	delete_option( 'rcs_v2_cache_index' );
	delete_option( 'rcs_v2_cache_lock' );
}

if ( is_multisite() ) {
	$remote_content_shortcode_offset = 0;
	do {
		$remote_content_shortcode_sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $remote_content_shortcode_offset, 'orderby' => 'id', 'order' => 'ASC' ) );
		foreach ( $remote_content_shortcode_sites as $remote_content_shortcode_site_id ) {
			switch_to_blog( $remote_content_shortcode_site_id );
			try { remote_content_shortcode_uninstall_cache_site(); }
			finally { restore_current_blog(); }
		}
		$remote_content_shortcode_offset += count( $remote_content_shortcode_sites );
	} while ( 100 === count( $remote_content_shortcode_sites ) );
} else {
	remote_content_shortcode_uninstall_cache_site();
}
