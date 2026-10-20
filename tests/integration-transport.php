<?php
/** Real WordPress Requests integration against the isolated Docker fixture. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once dirname( __DIR__ ) . '/remote-content-shortcode.php';

$failures = 0;
echo 'Runtime: PHP ' . PHP_VERSION . '; cURL ' . curl_version()['version'] . '; libxml ' . LIBXML_DOTTED_VERSION . "\n";
$count = 0;
$diagnostic = null;
add_action( 'remote_content_shortcode_error', function ( $error ) use ( &$diagnostic ) { $diagnostic = $error; } );
$render = function ( $path, $extra = array() ) {
	global $shortcode_tags;
	$url = str_starts_with( $path, 'http' ) ? $path : 'http://source.rcs.test' . $path;
	return call_user_func( $shortcode_tags['remote_content'], array_merge( array( 'url' => $url, 'cache' => 'false', 'timeout' => 3 ), $extra ) );
};
$stats = function () {
	return json_decode( file_get_contents( 'http://11.231.47.10/stats' ), true );
};
$check = function ( $name, $test ) use ( &$count, &$failures, &$diagnostic ) {
	++$count;
	$diagnostic = null;
	wp_cache_flush();
	if ( class_exists( 'Remote_Content_Shortcode_Cache' ) ) { Remote_Content_Shortcode_Cache::clear(); }
	try {
		if ( ! $test() ) {
			throw new RuntimeException( 'Unexpected result.' );
		}
		echo "PASS $name\n";
	} catch ( Throwable $error ) {
		++$failures;
		echo "FAIL $name: " . $error->getMessage() . "\n";
	}
};
$check( 'real HTTP transport', fn() => str_contains( $render( '/ok' ), 'fixture success' ) );
$check( 'Requests normalizes a missing URL path', fn() => str_contains( $render( 'http://source.rcs.test' ), 'fixture success' ) );
$check( 'valid TLS certificate', fn() => str_contains( $render( 'https://source.rcs.test/ok' ), 'fixture success' ) );
$check( 'mismatched TLS certificate', function () use ( $render, &$diagnostic ) {
	return '' === $render( 'https://wrong.rcs.test/ok' ) && is_wp_error( $diagnostic ) && str_contains( strtolower( $diagnostic->get_error_message() ), 'ssl' );
} );
$check( 'untrusted TLS certificate', function () use ( $render, &$diagnostic ) {
	return '' === $render( 'https://untrusted.rcs.test/ok' ) && is_wp_error( $diagnostic ) && str_contains( strtolower( $diagnostic->get_error_message() ), 'ssl' );
} );
$check( 'real GET redirect', fn() => str_contains( $render( '/redirect' ), 'fixture success' ) );
$check( 'private redirect is stopped before a second connection', function () use ( $render, $stats, &$diagnostic ) {
	$before = $stats();
	$output = $render( '/private-redirect' );
	$after = $stats();
	return '' === $output && 'invalid_url' === $diagnostic?->get_error_code() && ( $after['private.rcs.test/ok'] ?? 0 ) === ( $before['private.rcs.test/ok'] ?? 0 );
} );
$check( 'redirect loop has a finite request count', function () use ( $render, $stats ) {
	$before = $stats()['source.rcs.test/loop'] ?? 0;
	$output = $render( '/loop' );
	return '' === $output && 4 === ( $stats()['source.rcs.test/loop'] ?? 0 ) - $before;
} );
foreach ( array( '/encoded', '/lf-encoded' ) as $path ) {
	$check( 'real compressed response rejected ' . $path, function () use ( $render, $path, &$diagnostic ) {
		return '' === $render( $path ) && is_wp_error( $diagnostic ) && str_contains( $diagnostic->get_error_message(), 'Encoded responses' );
	} );
}
$check( 'URL mutation aborts before network I/O', function () use ( $render, $stats ) {
	$before = $stats()['source.rcs.test/ok'] ?? 0;
	$rewrite = function ( &$url ) { $url .= '?rewritten=1'; };
	add_action( 'requests-requests.before_request', $rewrite, 10 );
	try {
		$output = $render( '/ok' );
	} finally {
		remove_action( 'requests-requests.before_request', $rewrite, 10 );
	}
	return '' === $output && ( $stats()['source.rcs.test/ok'] ?? 0 ) === $before;
} );
$check( 'late cURL hooks cannot replace the checked destination', function () use ( $render ) {
	$override = function ( $handle ) { curl_setopt( $handle, CURLOPT_RESOLVE, array( 'source.rcs.test:80:127.0.0.1' ) ); };
	add_action( 'http_api_curl', $override );
	try {
		return str_contains( $render( '/ok' ), 'fixture success' );
	} finally {
		remove_action( 'http_api_curl', $override );
	}
} );
$check( 'late cURL hooks cannot disable certificate verification', function () use ( $render ) {
	$override = function ( $handle ) { curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false ); curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 0 ); };
	add_action( 'http_api_curl', $override );
	try {
		return '' === $render( 'https://untrusted.rcs.test/ok' );
	} finally {
		remove_action( 'http_api_curl', $override );
	}
} );
$check( 'checked DNS address survives a controlled rebinding window', function () use ( $render ) {
	file_get_contents( 'http://11.231.47.10/control?private=0' );
	$rebind = function () { file_get_contents( 'http://11.231.47.10/control?private=1' ); };
	add_action( 'requests-requests.before_request', $rebind, 10 );
	try {
		return str_contains( $render( 'http://rebind.rcs.test/ok' ), 'fixture success' );
	} finally {
		remove_action( 'requests-requests.before_request', $rebind, 10 );
		file_get_contents( 'http://11.231.47.10/control?private=0' );
	}
} );
$check( 'overflowing response is stopped before its delayed tail', function () use ( $render, &$diagnostic ) {
	$start = hrtime( true );
	$output = $render( '/slow-overflow', array( 'timeout' => 8 ) );
	$elapsed = ( hrtime( true ) - $start ) / 1e9;
	if ( '' !== $output || $elapsed >= 2 ) {
		throw new RuntimeException( 'Stream took ' . $elapsed . ' seconds; diagnostic: ' . $diagnostic?->get_error_message() );
	}
	return true;
} );
$check( 'unknown-length overflow is stopped before its delayed terminator', function () use ( $render, &$diagnostic ) {
	$start = hrtime( true );
	$output = $render( '/chunked-overflow', array( 'timeout' => 8 ) );
	$elapsed = ( hrtime( true ) - $start ) / 1e9;
	if ( '' !== $output || $elapsed >= 2 ) {
		throw new RuntimeException( 'Chunked stream took ' . $elapsed . ' seconds; diagnostic: ' . $diagnostic?->get_error_message() );
	}
	return true;
} );
$check( 'failed GET requests have a short negative cache', function () use ( $render, $stats ) {
	$before = $stats()['source.rcs.test/failure'] ?? 0;
	$a = $render( '/failure', array( 'cache' => 'true' ) );
	$b = $render( '/failure', array( 'cache' => 'true' ) );
	return '' === $a && '' === $b && 1 === ( $stats()['source.rcs.test/failure'] ?? 0 ) - $before;
} );
$check( 'IPv6-only DNS names fail quietly as documented', function () use ( $render, &$diagnostic ) {
	return '' === $render( 'http://ipv6-only.rcs.test/ok' ) && 'invalid_url' === $diagnostic?->get_error_code();
} );
echo "$count transport integrations, $failures failures\n";
if ( $failures ) {
	exit( 1 );
}
