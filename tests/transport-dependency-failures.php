<?php
/** Test the transport's fail-closed boundaries when dependency contracts break. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! extension_loaded( 'uopz' ) ) { WP_CLI::error( 'Transport dependency tests require uopz.' ); }
function td_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$tests = array();
$tests['diagnostic previews omit null sanitizer output without losing the Tools page'] = static function () {
	$saved = array( $_POST, $_REQUEST, $_SERVER['REQUEST_METHOD'] ?? null, get_current_user_id() );
	$level = ob_get_level();
	wp_set_current_user( get_user_by( 'login', 'rcs_admin' )->ID );
	$_POST = array( 'rcs_action' => 'test', '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'url' => 'http://source.rcs.test/ok' );
	$_REQUEST = $_POST;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	uopz_set_return( 'wp_kses_post', null );
	try {
		ob_start();
		Remote_Content_Shortcode_Admin::page();
		$html = ob_get_clean();
		td_assert( str_contains( $html, 'Diagnostics' ) && str_contains( $html, '<form' ) && ! str_contains( $html, '<h2>Preview</h2>' ), 'A null sanitizer result broke the page or was rendered as a preview.' );
	} finally {
		uopz_unset_return( 'wp_kses_post' );
		while ( ob_get_level() > $level ) { ob_end_clean(); }
		list( $_POST, $_REQUEST, $method, $user ) = $saved;
		if ( null === $method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $method; }
		wp_set_current_user( $user );
	}
};
$tests['global IPv6 literal URLs are rejected before HTTP dispatch'] = static function () {
	$requests = 0;
	$observe = static function ( $pre ) use ( &$requests ) { ++$requests; return $pre; };
	add_filter( 'pre_http_request', $observe );
	try {
		$output = RemoteContentShortcode::get_instance()->remote_content_shortcode( array( 'url' => 'http://[2606:4700:4700::1111]/ok', 'cache' => 'false' ) );
		td_assert( '' === $output && 0 === $requests, 'An unsupported IPv6 literal reached HTTP dispatch.' );
	} finally { remove_filter( 'pre_http_request', $observe ); }
};
$tests['native download progress aborts exactly at the response limit'] = static function () {
	$callbacks = 0;
	uopz_set_return( 'curl_setopt_array', static function ( $handle, $options ) use ( &$callbacks ) {
		if ( isset( $options[ CURLOPT_XFERINFOFUNCTION ] ) ) {
			++$callbacks;
			$progress = $options[ CURLOPT_XFERINFOFUNCTION ];
			foreach ( array( 0 => 0, 1048575 => 0, 1048576 => 1, 1048577 => 1 ) as $bytes => $expected ) {
				td_assert( $expected === $progress( $handle, 0, $bytes, 0, 0 ), 'Download progress failed at byte ' . $bytes );
			}
		}
		// Exercise the real native configuration and request after checking the callback.
		uopz_unset_return( 'curl_setopt_array' );
		return curl_setopt_array( $handle, $options );
	}, true );
	try {
		$output = RemoteContentShortcode::get_instance()->remote_content_shortcode( array( 'url' => 'http://source.rcs.test/ok', 'cache' => 'false' ) );
		td_assert( 1 === $callbacks && str_contains( $output, 'fixture success' ), 'The native limit callback was not installed on a successful pinned request.' );
	} finally { uopz_unset_return( 'curl_setopt_array' ); }
};
$tests['DNS alias metadata without an IPv6 address does not replace the checked IPv4 address'] = static function () {
	uopz_set_return( 'dns_get_record', array( array( 'host' => 'source.rcs.test', 'type' => 'CNAME', 'target' => 'alias.rcs.test' ) ) );
	try {
		$output = RemoteContentShortcode::get_instance()->remote_content_shortcode( array( 'url' => 'http://source.rcs.test/ok', 'cache' => 'false' ) );
		td_assert( str_contains( $output, 'fixture success' ), 'DNS metadata displaced the validated IPv4 destination.' );
	} finally { uopz_unset_return( 'dns_get_record' ); }
};
$tests['URL canonicalization cannot expand beyond the request URL budget'] = static function () {
	$requests = 0;
	$observe = static function ( $pre ) use ( &$requests ) { ++$requests; return $pre; };
	add_filter( 'pre_http_request', $observe );
	try {
		$url = 'http://source.rcs.test/' . str_repeat( 'é', 1500 );
		td_assert( strlen( $url ) < 8192, 'The Unicode fixture was already oversized before normalization.' );
		$output = RemoteContentShortcode::get_instance()->remote_content_shortcode( array( 'url' => $url, 'cache' => 'false' ) );
		td_assert( '' === $output && 0 === $requests, 'An oversized canonical URL reached HTTP dispatch.' );
	} finally { remove_filter( 'pre_http_request', $observe ); }
};
$tests['failed native cURL configuration never returns imported content'] = static function () {
	$errors = array();
	$observe = static function ( $error ) use ( &$errors ) { $errors[] = $error; };
	add_action( 'remote_content_shortcode_error', $observe );
	uopz_set_return( 'curl_setopt_array', false );
	try {
		$output = RemoteContentShortcode::get_instance()->remote_content_shortcode( array( 'url' => 'http://source.rcs.test/ok', 'cache' => 'false', 'timeout' => 2 ) );
		td_assert( '' === $output && 1 === count( $errors ), 'Failed pin configuration returned content or lost its diagnostic.' );
		td_assert( str_contains( $errors[0]->get_error_message(), 'Unable to pin' ), 'The native configuration failure was not observed.' );
	} finally { uopz_unset_return( 'curl_setopt_array' ); remove_action( 'remote_content_shortcode_error', $observe ); }
	td_assert( false === has_action( 'requests-requests.before_request' ) && false === has_action( 'requests-curl.before_send' ), 'Failed pin configuration left transport hooks installed.' );
};
foreach ( array( false, array( 'user' => 'untrusted', 'host' => 'source.rcs.test' ), array( 'pass' => 'untrusted', 'host' => 'source.rcs.test' ), array( 'host' => '' ) ) as $i => $parts ) {
	$tests[ 'a validator approval cannot override an invalid local URL parse ' . $i ] = static function () use ( $parts ) {
		$requests = 0;
		$observe = static function ( $pre ) use ( &$requests ) { ++$requests; return $pre; };
		add_filter( 'pre_http_request', $observe );
		// Fail the structural recheck after canonicalization, at the validator boundary.
		uopz_set_return( 'wp_http_validate_url', static function ( $url ) use ( $parts ) {
			uopz_set_return( 'wp_parse_url', $parts );
			return $url;
		}, true );
		try {
			$output = RemoteContentShortcode::get_instance()->remote_content_shortcode( array( 'url' => 'http://source.rcs.test/ok', 'cache' => 'false' ) );
			td_assert( '' === $output && 0 === $requests, 'A broken parser/validator contract reached HTTP dispatch.' );
		} finally { uopz_unset_return( 'wp_http_validate_url' ); uopz_unset_return( 'wp_parse_url' ); remove_filter( 'pre_http_request', $observe ); }
	};
}
$failures = 0;
foreach ( $tests as $name => $test ) {
	try { $test(); echo "PASS $name\n"; }
	catch ( Throwable $error ) { ++$failures; echo "FAIL $name: " . $error->getMessage() . "\n"; }
}
if ( $failures ) { WP_CLI::error( "$failures transport dependency failures" ); }
echo count( $tests ) . " transport dependency cases passed\n";
