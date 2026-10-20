<?php
/** Separate PHP processes stress the actual cache storage and invalidation bounds. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$phase = getenv( 'RCS_CACHE_CONCURRENCY' );
if ( 'prepare' === $phase ) {
	Remote_Content_Shortcode_Cache::clear();
	echo "PASS cache cleared for concurrent writers\n";
	return;
}
if ( is_string( $phase ) && ctype_digit( $phase ) ) {
	for ( $i = 0; $i < 12; ++$i ) {
		Remote_Content_Shortcode_Cache::set( 'parallel-' . $phase . '-' . $i, array( 'body' => str_repeat( 'x', 262144 ), 'url' => 'https://example.com/concurrent' ), 120, 0, 'concurrent' );
		usleep( 1000 );
	}
	echo "PASS writer $phase finished\n";
	return;
}
global $wpdb;
$prefix = $wpdb->esc_like( '_transient_rcs_v2_' ) . '%';
$rows = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS entries, COALESCE(SUM(OCTET_LENGTH(option_value)),0) AS bytes FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix ), ARRAY_A );
if ( (int) $rows['entries'] < 1 || (int) $rows['entries'] > 32 || (int) $rows['bytes'] > 8388608 ) { throw new RuntimeException( 'Concurrent storage exceeded actual row/byte limits.' ); }
$removed = Remote_Content_Shortcode_Cache::clear();
for ( $writer = 0; $writer < 4; ++$writer ) {
	for ( $i = 0; $i < 12; ++$i ) {
		if ( false !== Remote_Content_Shortcode_Cache::get( 'parallel-' . $writer . '-' . $i ) ) { throw new RuntimeException( 'Concurrent cache payload survived invalidation.' ); }
	}
}
if ( 0 !== (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix ) ) ) { throw new RuntimeException( 'Unindexed cache payload remained in the database.' ); }
echo 'PASS four writers; ' . (int) $rows['entries'] . ' entries, ' . (int) $rows['bytes'] . ' serialized bytes; all ' . $removed . " entries invalidated\n";
