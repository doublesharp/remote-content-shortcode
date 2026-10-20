<?php
/** Verify that repeated regex backreferences cannot allocate unbounded output. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once dirname( __DIR__ ) . '/remote-content-shortcode.php';
$seen = false;
$error = null;
add_action( 'remote_content_shortcode_error', function ( $diagnostic ) use ( &$error ) { $error = $diagnostic; } );
add_filter( 'pre_http_request', function () use ( &$seen ) {
	$seen = true;
	return array( 'headers' => array(), 'body' => str_repeat( 'x', 150000 ), 'response' => array( 'code' => 200 ) );
} );
global $shortcode_tags;
$output = call_user_func( $shortcode_tags['remote_content'], array(
	'url' => 'https://example.com/',
	'cache' => 'false',
	'find' => '~(.+)~s',
	'replace' => str_repeat( '$1', 2048 ),
) );
if ( ! $seen || '' !== $output || ! is_wp_error( $error ) || 'regex_output_too_large' !== $error->get_error_code() ) {
	throw new RuntimeException( 'Oversized backreference expansion was accepted.' );
}
echo "PASS repeated backreference expansion respects the output budget\n";
