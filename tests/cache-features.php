<?php
/** wp eval-file tests/cache-features.php; run phase=write then phase=read in separate processes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$plugin_root = dirname( getenv( 'RCS_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/remote-content-shortcode.php' );
$module = $plugin_root . '/inc/class-remote-content-cache.php';
if ( ! file_exists( $module ) ) { throw new RuntimeException( 'Persistent cache module is missing.' ); }
require_once $module;
function rcs_cache_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function rcs_cache_value( $body ) { return array( 'body' => $body, 'url' => 'https://example.com/final' ); }
$phase = getenv( 'RCS_CACHE_PHASE' );
if ( 'uninstall' === $phase ) {
	Remote_Content_Shortcode_Cache::set( 'uninstall-test', rcs_cache_value( 'remove me' ), 120 );
	set_transient( 'rcs_uninstall_unrelated', 'preserved', 120 );
	$uninstall = $plugin_root . '/uninstall.php';
	rcs_cache_assert( file_exists( $uninstall ), 'Cache uninstall cleanup is missing.' );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'remote-content-shortcode/remote-content-shortcode.php' ); }
	require $uninstall;
	rcs_cache_assert( false === Remote_Content_Shortcode_Cache::get( 'uninstall-test' ), 'Uninstall preserved plugin cache payload.' );
	rcs_cache_assert( false === get_option( 'rcs_v2_cache_index', false ), 'Uninstall preserved cache metadata.' );
	rcs_cache_assert( 'preserved' === get_transient( 'rcs_uninstall_unrelated' ), 'Uninstall removed unrelated data.' );
	delete_transient( 'rcs_uninstall_unrelated' );
	echo "PASS uninstall removes only plugin cache data\n";
	return;
}
if ( 'write' === $phase ) {
	Remote_Content_Shortcode_Cache::clear();
	Remote_Content_Shortcode_Cache::set( 'cross-request', rcs_cache_value( 'persisted' ), 120, 0, 'persist' );
	echo "PASS wrote cross-request cache\n";
	return;
}
if ( 'read' === $phase ) {
	rcs_cache_assert( rcs_cache_value( 'persisted' ) === Remote_Content_Shortcode_Cache::get( 'cross-request' ), 'Cache did not survive a separate PHP request.' );
	Remote_Content_Shortcode_Cache::clear();
	echo "PASS cross-request persistence\n";
	return;
}
Remote_Content_Shortcode_Cache::clear();
set_transient( 'rcs_unrelated_test', 'untouched', 120 );
foreach ( array( '', '0', 'hello' ) as $body ) {
	Remote_Content_Shortcode_Cache::set( 'body', rcs_cache_value( $body ), 60 );
	rcs_cache_assert( rcs_cache_value( $body ) === Remote_Content_Shortcode_Cache::get( 'body' ), 'Valid body was lost.' );
}
Remote_Content_Shortcode_Cache::set( 'error', new WP_Error( 'network', 'secret-message', array( 'token' => 'secret-value' ) ), 60, 60 );
rcs_cache_assert( is_wp_error( Remote_Content_Shortcode_Cache::get( 'error' ) ), 'Negative cache missing.' );
rcs_cache_assert( false === Remote_Content_Shortcode_Cache::get( 'error', true ), 'Error served as stale.' );
Remote_Content_Shortcode_Cache::set( 'stale', rcs_cache_value( 'good' ), 1, 3 );
sleep( 2 );
rcs_cache_assert( false === Remote_Content_Shortcode_Cache::get( 'stale' ), 'Expired response served as fresh.' );
rcs_cache_assert( rcs_cache_value( 'good' ) === Remote_Content_Shortcode_Cache::get( 'stale', true ), 'Eligible stale response lost.' );
Remote_Content_Shortcode_Cache::set( 'stale', new WP_Error( 'network' ), 60, 60 );
rcs_cache_assert( rcs_cache_value( 'good' ) === Remote_Content_Shortcode_Cache::get( 'stale', true ), 'Negative cache replaced last success.' );
sleep( 3 );
rcs_cache_assert( false === Remote_Content_Shortcode_Cache::get( 'stale', true ), 'Negative cache extended stale lifetime.' );
Remote_Content_Shortcode_Cache::set( 'tag-a', rcs_cache_value( 'a' ), 60, 0, 'a' );
Remote_Content_Shortcode_Cache::set( 'tag-b', rcs_cache_value( 'b' ), 60, 0, 'b' );
rcs_cache_assert( 1 === Remote_Content_Shortcode_Cache::clear( 'a' ), 'Targeted clear count is wrong.' );
rcs_cache_assert( false === Remote_Content_Shortcode_Cache::get( 'tag-a' ) && false !== Remote_Content_Shortcode_Cache::get( 'tag-b' ), 'Targeted clear crossed tags.' );
rcs_cache_assert( 1 === Remote_Content_Shortcode_Cache::delete( 'tag-b' ), 'Delete count is wrong.' );
rcs_cache_assert( false === Remote_Content_Shortcode_Cache::get( 'tag-b' ), 'Per-key delete failed.' );
// A stale negative options cache must never allow stealing a live database lock.
global $wpdb;
$race_owner = ( time() + 60 ) . ':existing-database-owner';
$wpdb->insert( $wpdb->options, array( 'option_name' => 'rcs_v2_cache_lock', 'option_value' => $race_owner, 'autoload' => 'off' ) );
wp_cache_delete( 'rcs_v2_cache_lock', 'options' );
$missing_options = wp_cache_get( 'notoptions', 'options' );
$missing_options = is_array( $missing_options ) ? $missing_options : array();
$missing_options['rcs_v2_cache_lock'] = true;
wp_cache_set( 'notoptions', $missing_options, 'options' );
try {
rcs_cache_assert( false === Remote_Content_Shortcode_Cache::set( 'lock-race', rcs_cache_value( 'forbidden' ), 60 ), 'A stale notoptions cache stole a valid database lock.' );
rcs_cache_assert( $race_owner === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'rcs_v2_cache_lock' ) ), 'Existing database lock owner was overwritten.' );
} finally {
$wpdb->delete( $wpdb->options, array( 'option_name' => 'rcs_v2_cache_lock' ) );
wp_cache_delete( 'rcs_v2_cache_lock', 'options' );
wp_cache_delete( 'notoptions', 'options' );
}

$negative = Remote_Content_Shortcode_Cache::get( 'error' );
rcs_cache_assert( is_wp_error( $negative ) && '' === $negative->get_error_message() && null === $negative->get_error_data(), 'Negative cache retained sensitive error details.' );
// Exercise the published lock option protocol without blocking a request.
$lock = ( time() + 60 ) . ':test-owner';
add_option( 'rcs_v2_cache_lock', $lock, '', false );
$started = microtime( true );
rcs_cache_assert( false === Remote_Content_Shortcode_Cache::set( 'blocked', rcs_cache_value( 'blocked' ), 60 ), 'Contended write was accepted.' );
rcs_cache_assert( microtime( true ) - $started < 1, 'Contended write blocked the request.' );
rcs_cache_assert( false !== Remote_Content_Shortcode_Cache::get( 'body' ), 'Lock blocked reads.' );
rcs_cache_assert( $lock === get_option( 'rcs_v2_cache_lock' ), 'Contender replaced an active lock.' );
delete_option( 'rcs_v2_cache_lock' );
add_option( 'rcs_v2_cache_lock', ( time() - 1 ) . ':expired-owner', '', false );
rcs_cache_assert( Remote_Content_Shortcode_Cache::set( 'recovered', rcs_cache_value( 'recovered' ), 60 ), 'Expired lock was not recovered.' );
rcs_cache_assert( false === get_option( 'rcs_v2_cache_lock', false ), 'Writer left its lock behind.' );
Remote_Content_Shortcode_Cache::clear();
for ( $i = 0; $i < 33; ++$i ) { Remote_Content_Shortcode_Cache::set( 'count-' . $i, rcs_cache_value( 'small' ), 60 ); }
rcs_cache_assert( false === Remote_Content_Shortcode_Cache::get( 'count-0' ) && false !== Remote_Content_Shortcode_Cache::get( 'count-32' ), 'Entry limit failed to evict oldest.' );
Remote_Content_Shortcode_Cache::clear();
for ( $i = 0; $i < 3; ++$i ) { Remote_Content_Shortcode_Cache::set( 'bytes-' . $i, rcs_cache_value( str_repeat( 'x', 3 * 1024 * 1024 ) ), 60 ); }
rcs_cache_assert( false === Remote_Content_Shortcode_Cache::get( 'bytes-0' ) && false !== Remote_Content_Shortcode_Cache::get( 'bytes-2' ), 'Payload storage budget exceeded.' );
Remote_Content_Shortcode_Cache::clear();
rcs_cache_assert( 'untouched' === get_transient( 'rcs_unrelated_test' ), 'Plugin clear erased unrelated transient.' );
delete_transient( 'rcs_unrelated_test' );
echo "PASS fresh, negative, stale, expiration, invalidation, entry and byte bounds\n";
