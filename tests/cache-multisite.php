<?php
/** Verify cache isolation and uninstall cleanup across actual WordPress sites. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! is_multisite() ) { throw new RuntimeException( 'Run in a disposable multisite installation.' ); }
$initial = get_current_blog_id();
add_filter( 'pre_wp_mail', '__return_true' );
$second = get_blog_id_from_url( get_site()->domain, '/cache-cleanup-test/' );
if ( ! $second ) { $second = wpmu_create_blog( get_site()->domain, '/cache-cleanup-test/', 'Cache cleanup', 1 ); }
if ( is_wp_error( $second ) ) { throw new RuntimeException( 'Cannot create the test site.' ); }
try {
	foreach ( array( $initial, $second ) as $id ) {
		switch_to_blog( $id );
		try {
			$value = array( 'body' => 'site-' . $id, 'url' => 'https://example.com/' );
			if ( ! Remote_Content_Shortcode_Cache::set( 'site-key', $value, 120 ) || $value !== Remote_Content_Shortcode_Cache::get( 'site-key' ) ) { throw new RuntimeException( 'Could not populate this site cache fixture.' ); }
			set_transient( 'rcs_multisite_unrelated', 'preserved', 120 );
		} finally { restore_current_blog(); }
	}
	if ( ( 'site-' . $initial ) !== Remote_Content_Shortcode_Cache::get( 'site-key' )['body'] ) { throw new RuntimeException( 'Site cache identities collided.' ); }
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'remote-content-shortcode/remote-content-shortcode.php' ); }
	require dirname( getenv( 'RCS_PLUGIN_FILE' ) ) . '/uninstall.php';
	if ( $initial !== get_current_blog_id() ) { throw new RuntimeException( 'Uninstall failed to restore the original blog.' ); }
	foreach ( array( $initial, $second ) as $id ) {
		switch_to_blog( $id );
		try {
			if ( false !== Remote_Content_Shortcode_Cache::get( 'site-key' ) || false !== get_option( 'rcs_v2_cache_index', false ) || 'preserved' !== get_transient( 'rcs_multisite_unrelated' ) ) { throw new RuntimeException( 'Multisite cleanup crossed boundaries or left data.' ); }
			delete_transient( 'rcs_multisite_unrelated' );
		} finally { restore_current_blog(); }
	}
	echo "PASS two-site cache isolation, scoped uninstall, unrelated data preservation, and blog restoration\n";
} finally {
	remove_filter( 'pre_wp_mail', '__return_true' );
	require_once ABSPATH . 'wp-admin/includes/ms.php';
	wpmu_delete_blog( $second, true );
}
