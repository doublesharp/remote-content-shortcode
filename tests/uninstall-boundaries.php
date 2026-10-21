<?php
/** Uninstall must handle orphan batches and malformed metadata without touching other plugins. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function ub_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$root = dirname( getenv( 'RCS_PLUGIN_FILE' ) );
$valid = 'rcs_v2_' . hash( 'sha256', 'uninstall-boundary' );
set_transient( $valid, 'owned', 120 );
set_transient( 'uninstall_other_plugin', 'preserved', 120 );
update_option( 'rcs_v2_cache_index', array( $valid => array(), 'uninstall_other_plugin' => array(), 7 => array() ), false );
// More than 100 orphan rows require another SQL discovery batch.
for ( $i = 0; $i < 110; ++$i ) { add_option( '_transient_rcs_v2_orphan_' . $i, 'owned', '', false ); }
define( 'WP_UNINSTALL_PLUGIN', 'remote-content-shortcode/remote-content-shortcode.php' );
require $root . '/uninstall.php';
ub_assert( false === get_transient( $valid ), 'Indexed payload survived uninstall.' );
for ( $i = 0; $i < 110; ++$i ) { ub_assert( false === get_option( '_transient_rcs_v2_orphan_' . $i, false ), 'An orphan batch survived uninstall.' ); }
ub_assert( 'preserved' === get_transient( 'uninstall_other_plugin' ), 'Malformed index deleted another plugin transient.' );
// A prior version or failed write can leave non-array metadata.
update_option( 'rcs_v2_cache_index', 'invalid-metadata', false );
remote_content_shortcode_uninstall_cache_site();
ub_assert( false === get_option( 'rcs_v2_cache_index', false ), 'Malformed index survived uninstall.' );
// Database deletion can fail. A full, unchanged batch must terminate rather than spin forever.
global $wpdb;
$blocked_prefix = '_transient_rcs_v2_blocked_';
for ( $i = 0; $i < 100; ++$i ) { add_option( $blocked_prefix . $i, 'owned', '', false ); }
$blocked_deletes = 0;
$block_deletes = static function ( $sql ) use ( &$blocked_deletes, $blocked_prefix ) {
	if ( str_starts_with( $sql, 'DELETE FROM' ) && str_contains( $sql, $blocked_prefix ) ) {
		++$blocked_deletes;
		if ( $blocked_deletes > 100 ) { throw new RuntimeException( 'Uninstall retried a batch with no deletion progress.' ); }
		return $sql . ' AND 1 = 0';
	}
	return $sql;
};
add_filter( 'query', $block_deletes );
try {
	remote_content_shortcode_uninstall_cache_site();
	ub_assert( 100 === $blocked_deletes, 'Uninstall did not attempt exactly one blocked batch.' );
	// delete_option clears option caches even when its SQL deletion fails.
	for ( $i = 0; $i < 100; ++$i ) {
		ub_assert( 'owned' === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $blocked_prefix . $i ) ), 'Blocked deletion unexpectedly removed a database row.' );
		// A later uninstall must be able to retry without stale negative-cache entries.
		wp_cache_delete( $blocked_prefix . $i, 'options' );
	}
	wp_cache_delete( 'notoptions', 'options' );
} finally { remove_filter( 'query', $block_deletes ); }
remote_content_shortcode_uninstall_cache_site();
for ( $i = 0; $i < 100; ++$i ) { ub_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $blocked_prefix . $i ) ), 'Retry did not remove a previously blocked database row.' ); }
delete_transient( 'uninstall_other_plugin' );
echo "PASS uninstall orphan batches, invalid IDs, malformed index, blocked deletion termination, retry, and unrelated data preservation\n";
