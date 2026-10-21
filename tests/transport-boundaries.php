<?php
/** Exercise transport-hook failures against the isolated Docker HTTP fixture. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$plugin = RemoteContentShortcode::get_instance();
$failures = 0;
$cases = array();
function tb_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function tb_render() {
	return RemoteContentShortcode::get_instance()->remote_content_shortcode( array( 'url' => 'http://source.rcs.test/ok', 'cache' => 'false', 'timeout' => 2 ) );
}
$cases['redirect canonicalization cannot expand beyond the URL budget'] = static function () {
	$location = 'http://source.rcs.test/' . str_repeat( 'é', 1500 );
	$requests = 0;
	$intercept = static function () use ( &$requests, $location ) {
		++$requests;
		return array( 'headers' => array( 'location' => $location ), 'body' => '', 'response' => array( 'code' => 302 ) );
	};
	add_filter( 'pre_http_request', $intercept );
	try {
		tb_assert( strlen( $location ) < 8192 && '' === tb_render() && 1 === $requests, 'An oversized normalized redirect reached another HTTP request.' );
	} finally { remove_filter( 'pre_http_request', $intercept ); }
};
$cases['method mutation aborts before transport'] = static function () {
	$mutate = static function ( &$url, &$headers, &$data, &$type ) { $type = 'POST'; };
	add_action( 'requests-requests.before_request', $mutate, 10, 4 );
	try { tb_assert( '' === tb_render(), 'Changed HTTP method was accepted.' ); }
	finally { remove_action( 'requests-requests.before_request', $mutate, 10 ); }
};
foreach ( array( '', '127.0.0.1' ) as $peer ) {
	$cases['missing or mismatched peer rejected ' . $peer] = static function () use ( $peer ) {
		$mutate = static function ( &$headers, &$info ) use ( $peer ) { $info['primary_ip'] = $peer; };
		// Register before the plugin's peer check, which runs before the WordPress bridge action.
		$register = static function ( &$url, &$headers, &$data, &$type, &$options ) use ( $mutate ) { $options['hooks']->register( 'curl.after_request', $mutate ); };
		add_action( 'requests-requests.before_request', $register, 10, 5 );
		try { tb_assert( '' === tb_render(), 'Unverified peer reached the renderer.' ); }
		finally { remove_action( 'requests-requests.before_request', $register, 10 ); }
	};
}
$cases['parser hook rejects a response associated with another URL'] = static function () {
	$rejected = false;
	$intercept = static function ( $pre, $args, $url ) use ( &$rejected ) {
		$options = array( 'hooks' => new WP_HTTP_Requests_Hooks( $url, $args ) );
		$headers = array(); $data = ''; $type = 'GET';
		do_action_ref_array( 'requests-requests.before_request', array( &$url, &$headers, &$data, &$type, &$options ) );
		$raw = "HTTP/1.1 200 OK\r\n\r\nwrong URL";
		try { $options['hooks']->dispatch( 'requests.before_parse', array( &$raw, $url . '?different=1' ) ); }
		catch ( WpOrg\Requests\Exception $error ) { $rejected = 'remote_content_url_changed' === $error->getType(); }
		return array( 'headers' => array(), 'body' => 'hook checked', 'response' => array( 'code' => 200 ) );
	};
	add_filter( 'pre_http_request', $intercept, 10, 3 );
	try { tb_assert( 'hook checked' === tb_render() && $rejected, 'Parser hook accepted a response for another URL.' ); }
	finally { remove_filter( 'pre_http_request', $intercept, 10 ); }
};
foreach ( array( 'requests-requests.before_request', 'requests-curl.before_send' ) as $hook ) {
	$cases['missing security hook fails closed ' . $hook] = static function () use ( $hook ) {
		$remove = static function ( $pre ) use ( $hook ) { remove_all_actions( $hook ); return $pre; };
		$code = null;
		$error = static function ( $failure ) use ( &$code ) { $code = $failure->get_error_code(); };
		add_filter( 'pre_http_request', $remove );
		add_action( 'remote_content_shortcode_error', $error );
		try { tb_assert( '' === tb_render() && 'transport_not_pinned' === $code, 'Unpinned transport result was accepted.' ); }
		finally { remove_filter( 'pre_http_request', $remove ); remove_action( 'remote_content_shortcode_error', $error ); }
	};
}
$cases['unrelated curl handles are left untouched'] = static function () {
	$other = curl_init( 'http://127.0.0.1/' );
	$nested = static function () use ( $other, &$nested ) {
		// Avoid recursively calling this test hook for the unrelated handle.
		remove_action( 'requests-curl.before_send', $nested, 20 );
		do_action( 'requests-curl.before_send', $other );
	};
	add_action( 'requests-curl.before_send', $nested, 20 );
	try {
		tb_assert( str_contains( tb_render(), 'fixture success' ), 'Unrelated handle disrupted the pinned request.' );
		tb_assert( 'http://127.0.0.1/' === curl_getinfo( $other, CURLINFO_EFFECTIVE_URL ), 'The plugin changed an unrelated handle URL.' );
	} finally { remove_action( 'requests-curl.before_send', $nested, 20 ); curl_close( $other ); }
};
$cases['exceptions from other HTTP filters still remove plugin hooks'] = static function () {
	$throw = static function () { throw new RuntimeException( 'test HTTP filter failure' ); };
	add_filter( 'pre_http_request', $throw );
	$caught = false;
	try { tb_render(); }
	catch ( RuntimeException $error ) { $caught = 'test HTTP filter failure' === $error->getMessage(); }
	finally { remove_filter( 'pre_http_request', $throw ); }
	tb_assert( $caught, 'Filter exception did not propagate.' );
	tb_assert( false === has_action( 'requests-requests.before_request' ) && false === has_action( 'requests-curl.before_send' ), 'Transport hooks leaked after an exception.' );
	tb_assert( str_contains( tb_render(), 'fixture success' ), 'Next request failed after exception cleanup.' );
};
$cases['a nested preempted request cannot change the outer request state'] = static function () {
	$nested = null;
	$intercept = static function ( $pre, $args, $url ) use ( &$nested ) {
		if ( 'http://nested.rcs.test/ok' === $url ) {
			return array( 'headers' => array(), 'body' => 'nested response', 'response' => array( 'code' => 200 ) );
		}
		$nested = wp_safe_remote_get( 'http://nested.rcs.test/ok' );
		return $pre;
	};
	add_filter( 'pre_http_request', $intercept, 10, 3 );
	try {
		tb_assert( str_contains( tb_render(), 'fixture success' ), 'A nested request marked the outer request as preempted or disrupted its transport.' );
		tb_assert( is_array( $nested ) && 'nested response' === $nested['body'], 'The nested request did not pass through its own HTTP filter.' );
	} finally { remove_filter( 'pre_http_request', $intercept, 10 ); }
	tb_assert( empty( $GLOBALS['wp_filter']['pre_http_request']->callbacks[ PHP_INT_MAX ] ), 'The outer preemption observer leaked after a nested request.' );
};
foreach ( $cases as $name => $case ) {
	try { $case(); echo "PASS $name\n"; }
	catch ( Throwable $error ) { ++$failures; echo "FAIL $name: " . $error->getMessage() . "\n"; }
}
if ( $failures ) { WP_CLI::error( "$failures transport boundary cases failed" ); }
