<?php
/** Verify registration and rendering in admin and REST contexts. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once dirname( __DIR__ ) . '/remote-content-shortcode.php';

if ( ! is_admin() || ! shortcode_exists( 'remote_content' ) ) {
	throw new RuntimeException( 'Run with WP_ADMIN=true before loading WordPress.' );
}
wp_set_current_user( 1 );
$id = wp_insert_post( array(
	'post_title' => 'Shortcode context regression',
	'post_status' => 'publish',
	'post_content' => '[remote_content url="http://source.rcs.test/ok" selector="p" cache="false"]',
) );
try {
	if ( ! str_contains( apply_filters( 'the_content', get_post_field( 'post_content', $id ) ), 'fixture success' ) ) {
		throw new RuntimeException( 'Admin rendering failed.' );
	}
	echo "PASS admin rendering\n";
	$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) );
	$data = $response->get_data();
	if ( 200 !== $response->get_status() || ! str_contains( $data['content']['rendered'] ?? '', 'fixture success' ) ) {
		throw new RuntimeException( 'REST-rendered content failed.' );
	}
	echo "PASS REST-rendered post content\n";
} finally {
	wp_delete_post( $id, true );
}
