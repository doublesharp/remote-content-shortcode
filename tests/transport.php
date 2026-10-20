<?php
/** Transport configuration checks and an optional live HTTPS smoke test. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/remote-content-shortcode.php';

global $shortcode_tags;
$render = $shortcode_tags['remote_content'];
$seen = false;
$intercept = function ( $pre, $args, $url ) use ( &$seen ) {
	$options = array( 'hooks' => new WP_HTTP_Requests_Hooks( $url, $args ) );
	$headers = array();
	$data = '';
	$type = 'GET';
	do_action_ref_array( 'requests-requests.before_request', array( &$url, &$headers, &$data, &$type, &$options ) );
	if ( WpOrg\Requests\Transport\Curl::class !== ( $options['transport'] ?? null ) || true !== ( $options['verifyname'] ?? false ) || ! is_file( $options['verify'] ?? '' ) ) {
		throw new RuntimeException( 'Pinned cURL transport or certificate verification missing.' );
	}
	$handle = curl_init();
	$options['hooks']->dispatch( 'curl.before_send', array( $handle ) );
	foreach ( array( "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\n\r\n", "HTTP/1.1 200 OK\r\nX-Test: yes\nContent-Encoding: gzip\r\n\r\n" ) as $raw_headers ) {
		$raw = $raw_headers . gzencode( str_repeat( 'x', 2000000 ) );
		$rejected = false;
		try {
			$options['hooks']->dispatch( 'requests.before_parse', array( &$raw, $url ) );
		} catch ( WpOrg\Requests\Exception $error ) {
			$rejected = true;
		}
		if ( ! $rejected ) {
			throw new RuntimeException( 'Compressed response reached the unbounded parser.' );
		}
	}
	$seen = true;
	return array( 'headers' => array(), 'body' => 'transport configured', 'response' => array( 'code' => 200 ) );
};
add_filter( 'pre_http_request', $intercept, 10, 3 );
$output = call_user_func( $render, array( 'url' => 'https://example.com/', 'cache' => 'false' ) );
remove_filter( 'pre_http_request', $intercept );
if ( ! $seen || 'transport configured' !== $output || has_action( 'requests-requests.before_request' ) ) {
	throw new RuntimeException( 'Transport configuration or hook cleanup failed.' );
}
echo "PASS pinned transport, TLS configuration, pre-decompression rejection, and hook cleanup\n";

if ( getenv( 'RCS_LIVE_HTTP' ) ) {
	$diagnostic = null;
	add_action( 'remote_content_shortcode_error', function ( $error ) use ( &$diagnostic ) { $diagnostic = $error; } );
	$trace_path = getenv( 'RCS_TEST_TRACE_PATH' );
	$trace = $trace_path ? fopen( $trace_path, 'w+' ) : null;
	$capture = function ( $handle ) use ( $trace ) {
		if ( $trace ) {
			curl_setopt( $handle, CURLOPT_VERBOSE, true );
			curl_setopt( $handle, CURLOPT_STDERR, $trace );
		}
	};
	add_action( 'requests-curl.before_send', $capture );
	$output = call_user_func( $render, array( 'url' => 'https://example.com/', 'cache' => 'false' ) );
	remove_action( 'requests-curl.before_send', $capture );
	if ( $trace ) {
		rewind( $trace );
		$details = stream_get_contents( $trace );
		fclose( $trace );
		if ( ! str_contains( $details, 'DNS cache' ) || ! str_contains( $details, 'example.com' ) ) {
			throw new RuntimeException( 'Live cURL trace did not show the pinned DNS cache.' );
		}
	}
	if ( ! str_contains( $output, 'Example Domain' ) ) {
		throw new RuntimeException( 'Live HTTPS request failed: ' . ( $diagnostic ? $diagnostic->get_error_code() : 'unexpected body' ) );
	}
	echo "PASS live HTTPS fetch through WordPress with pinned DNS and certificate verification\n";
}
