<?php
/** Run with REST_REQUEST=true before loading WordPress, as a logged-out client. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! wp_is_serving_rest_request() ) { throw new RuntimeException( 'Define REST_REQUEST=true before loading WordPress.' ); }
wp_set_current_user( 0 );
Remote_Content_Shortcode_Cache::clear();
$stats = static fn() => json_decode( file_get_contents( 'http://11.231.47.10/stats' ), true )['source.rcs.test/ok'] ?? 0;
$id = wp_insert_post( array( 'post_title' => 'Anonymous REST cache test', 'post_status' => 'publish', 'post_content' => '[remote_content url="http://source.rcs.test/ok" selector="p"]' ) );
try {
	$before = $stats();
	for ( $i = 0; $i < 2; ++$i ) {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) );
		if ( 200 !== $response->get_status() || ! str_contains( $response->get_data()['content']['rendered'] ?? '', 'fixture success' ) ) { throw new RuntimeException( 'Anonymous REST rendering failed.' ); }
	}
	if ( 1 !== $stats() - $before ) { throw new RuntimeException( 'Anonymous REST rendering bypassed the visitor cache.' ); }
	echo "PASS anonymous REST content uses the public cache: two renders, one real HTTP request\n";
} finally {
	wp_delete_post( $id, true );
	Remote_Content_Shortcode_Cache::clear();
}
