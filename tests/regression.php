<?php
/** Run inside a disposable WordPress site: wp eval-file tests/regression.php. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once getenv( 'RCS_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/remote-content-shortcode.php';

global $rcs_failures, $rcs_count, $rcs_requests, $rcs_response;
$rcs_failures = 0;
$rcs_count    = 0;
$rcs_requests = array();
$rcs_response = '<p>Hello</p>';

function rcs_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function rcs_render( $atts = array(), $content = null ) {
	global $shortcode_tags;
	return call_user_func( $shortcode_tags['remote_content'], array_merge( array( 'url' => 'https://example.com/content', 'cache' => 'false' ), $atts ), $content );
}

function rcs_case( $name, $test ) {
	global $rcs_count, $rcs_failures, $rcs_requests, $rcs_response;
	++$rcs_count;
	$rcs_requests = array();
	$rcs_response = '<p>Hello</p>';
	$_GET = array();
	$_REQUEST = array();
	wp_cache_flush();
	if ( class_exists( 'Remote_Content_Shortcode_Cache' ) ) { Remote_Content_Shortcode_Cache::clear(); }
	foreach ( array( 'remote_content_shortcode_credentials', 'remote_content_shortcode_return', 'remote_content_shortcode_phpQuery', 'remote_content_shortcode_url', 'remote_content_shortcode_postfields', 'remote_content_shortcode_error' ) as $hook ) {
		remove_all_filters( $hook );
	}
	try {
		$test();
		echo "PASS $name\n";
	} catch ( Throwable $e ) {
		++$rcs_failures;
		echo "FAIL $name: " . $e->getMessage() . "\n";
	}
}

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	global $rcs_requests, $rcs_response;
	$rcs_requests[] = array( 'url' => $url, 'args' => $args );
	if ( is_callable( $rcs_response ) ) {
		return $rcs_response( $args, $url );
	}
	if ( is_wp_error( $rcs_response ) ) {
		return $rcs_response;
	}
	return array( 'headers' => array(), 'body' => $rcs_response, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
}, 10, 3 );

rcs_case( 'normal shortcode parsing renders selected content', function () {
	$GLOBALS['rcs_response'] = '<div>Ignored</div><p class="keep">Kept</p>';
	$out = do_shortcode( '[remote_content url="https://example.com/content" selector=".keep" cache="false"]' );
	rcs_assert( str_contains( $out, 'Kept' ) && ! str_contains( $out, 'Ignored' ), 'shortcode parser integration failed' );
} );
rcs_case( 'remote scripts and event handlers are removed', function () {
	$GLOBALS['rcs_response'] = '<p>Keep</p><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">link</a>';
	$out = rcs_render();
	rcs_assert( str_contains( $out, '<p>Keep</p>' ) && ! str_contains( $out, '<script' ) && ! str_contains( $out, 'onerror' ) && ! str_contains( $out, 'javascript:' ), 'unsafe remote HTML survived' );
} );
rcs_case( 'regex output is sanitized', function () {
	$out = rcs_render( array( 'find' => '~Hello~', 'replace' => '<script>alert(1)</script><b>Changed</b>' ) );
	rcs_assert( ! str_contains( $out, '<script' ) && str_contains( $out, '<b>Changed</b>' ), 'unsafe regex replacement survived' );
} );
rcs_case( 'return filter output is sanitized', function () {
	add_filter( 'remote_content_shortcode_return', fn() => '<img src="x" onerror="alert(1)">' );
	rcs_assert( ! str_contains( rcs_render(), 'onerror' ), 'unsafe return filter survived' );
} );
rcs_case( 'entity encoding displays source safely', function () {
	$GLOBALS['rcs_response'] = '<script>alert(1)</script>';
	$out = rcs_render( array( 'htmlentities' => 'true' ) );
	rcs_assert( str_contains( $out, '&lt;script&gt;' ) && ! str_contains( $out, '<script>' ), 'encoded source was lost' );
} );
rcs_case( 'strip_tags retains text', function () {
	rcs_assert( 'Hello' === rcs_render( array( 'strip_tags' => 'true' ) ), 'text transformation changed' );
} );
rcs_case( 'CSS selection and removal work', function () {
	$GLOBALS['rcs_response'] = '<html><body><main><p class="keep">Café</p><p class="omit">remove</p></main><aside>ignore</aside></body></html>';
	$out = rcs_render( array( 'selector' => 'main > p:first-child', 'remove' => '.omit' ) );
	rcs_assert( str_contains( $out, 'Café' ) && ! str_contains( $out, 'ignore' ) && ! str_contains( $out, 'remove' ), 'CSS selection failed' );
} );
rcs_case( 'removal without selector preserves document content', function () {
	$GLOBALS['rcs_response'] = '<div>Keep<span>remove</span></div>';
	$out = rcs_render( array( 'remove' => 'span' ) );
	rcs_assert( str_contains( $out, 'Keep' ) && ! str_contains( $out, 'remove' ), 'remove-only output failed' );
} );
rcs_case( 'invalid selector fails quietly', function () {
	rcs_assert( '' === rcs_render( array( 'selector' => '[' ) ), 'invalid selector did not return empty output' );
} );
rcs_case( 'invalid XPath selector does not raise PHP warnings', function () {
	$warnings = array();
	set_error_handler( function ( $level, $message ) use ( &$warnings ) { $warnings[] = $message; return true; } );
	try {
		rcs_assert( '' === rcs_render( array( 'selector' => 'foo|a' ) ) && ! $warnings, 'invalid namespace selector raised a warning' );
	} finally {
		restore_error_handler();
	}
} );
rcs_case( 'overlapping selectors cannot amplify output beyond one MiB', function () {
	$error = null;
	add_action( 'remote_content_shortcode_error', function ( $failure ) use ( &$error ) { $error = $failure; } );
	$GLOBALS['rcs_response'] = str_repeat( '<div>', 26 ) . str_repeat( 'x', 40000 ) . str_repeat( '</div>', 26 );
	$within_limit = rcs_render( array( 'selector' => 'div' ) );
	rcs_assert( strlen( $within_limit ) > 1000000 && null === $error, 'positive control did not reach selector serialization' );
	$GLOBALS['rcs_response'] = str_repeat( '<div>', 27 ) . str_repeat( 'x', 40000 ) . str_repeat( '</div>', 27 );
	rcs_assert( '' === rcs_render( array( 'selector' => 'div' ) ) && 'output_too_large' === $error?->get_error_code(), 'overlapping matches did not reach the output budget' );
} );
rcs_case( 'deep documents fail before selector evaluation', function () {
	$error = null;
	add_action( 'remote_content_shortcode_error', function ( $failure ) use ( &$error ) { $error = $failure; } );
	$GLOBALS['rcs_response'] = str_repeat( '<div>', 200 ) . str_repeat( 'x', 40000 ) . str_repeat( '</div>', 200 );
	rcs_assert( '' === rcs_render( array( 'selector' => 'div' ) ) && 'html_too_complex' === $error?->get_error_code(), 'deep document did not reach the depth budget' );
} );
rcs_case( 'remove-only serialization respects the output limit', function () {
	$GLOBALS['rcs_response'] = '<p>' . str_repeat( '&', 300000 ) . '</p>';
	rcs_assert( '' === rcs_render( array( 'remove' => '.absent' ) ), 'whole-document serialization exceeded the output budget' );
} );
rcs_case( 'regex expansion has a bounded replacement count', function () {
	$GLOBALS['rcs_response'] = str_repeat( 'x', 2000 );
	rcs_assert( '' === rcs_render( array( 'find' => '~x~', 'replace' => 'y' ) ), 'replacement limit was ignored' );
} );
rcs_case( 'replacement backreferences preserve PHP semantics', function () {
	$GLOBALS['rcs_response'] = 'abc';
	foreach ( array( '$1', '${2}', '\\3', '$0', '\\0', '\\$1', '\\\\1', '${99}', 'x$99y', '$12', '${1}2', '$$1' ) as $replacement ) {
		$expected = preg_replace( '~(a)(b)(c)~', $replacement, 'abc' );
		$actual = rcs_render( array( 'find' => '~(a)(b)(c)~', 'replace' => $replacement ) );
		rcs_assert( $expected === $actual, 'replacement differs from PHP: ' . $replacement );
	}
} );
rcs_case( 'invalid regex fails quietly', function () {
	rcs_assert( '' === rcs_render( array( 'find' => '[' ) ), 'invalid regex did not return empty output' );
} );
rcs_case( 'zero response is preserved', function () {
	$GLOBALS['rcs_response'] = '0';
	rcs_assert( '0' === rcs_render(), 'zero response was lost' );
} );
rcs_case( 'HTTP errors return strings and expose diagnostic action', function () {
	$GLOBALS['rcs_response'] = new WP_Error( 'http_failure', 'private diagnostic' );
	$seen = null;
	add_action( 'remote_content_shortcode_error', function ( $error ) use ( &$seen ) { $seen = $error; } );
	rcs_assert( '' === rcs_render() && is_wp_error( $seen ), 'HTTP error was returned or not reported' );
} );
rcs_case( 'HTTP status failures are not rendered', function () {
	$GLOBALS['rcs_response'] = fn() => array( 'headers' => array(), 'body' => 'private error page', 'response' => array( 'code' => 403 ) );
	rcs_assert( '' === rcs_render(), 'error response was rendered' );
} );
rcs_case( 'failed public GET requests are cached briefly', function () {
	$GLOBALS['rcs_response'] = new WP_Error( 'http_failure', 'private diagnostic' );
	rcs_render( array( 'cache' => 'true' ) );
	rcs_render( array( 'cache' => 'true' ) );
	rcs_assert( 1 === count( $GLOBALS['rcs_requests'] ), 'failed request was repeated' );
} );
rcs_case( 'cache=false still retries failures', function () {
	$GLOBALS['rcs_response'] = new WP_Error( 'http_failure', 'private diagnostic' );
	rcs_render();
	rcs_render();
	rcs_assert( 2 === count( $GLOBALS['rcs_requests'] ), 'disabled cache suppressed a request' );
} );
rcs_case( 'cache varies with forwarded parameters', function () {
	$GLOBALS['rcs_response'] = fn( $args, $url ) => array( 'headers' => array(), 'body' => $url, 'response' => array( 'code' => 200 ) );
	$_GET['id'] = $_REQUEST['id'] = 'one';
	$a = rcs_render( array( 'params' => 'id', 'cache' => 'true' ) );
	$_GET['id'] = $_REQUEST['id'] = 'two';
	$b = rcs_render( array( 'params' => 'id', 'cache' => 'true' ) );
	rcs_assert( $a !== $b && str_contains( $b, 'two' ), 'different parameters reused cached data' );
} );
rcs_case( 'identical public GET requests are cached', function () {
	rcs_render( array( 'cache' => 'true' ) );
	rcs_render( array( 'cache' => 'true' ) );
	rcs_assert( 1 === count( $GLOBALS['rcs_requests'] ), 'public GET cache missed' );
} );
rcs_case( 'cached responses still apply each requested transformation', function () {
	$GLOBALS['rcs_response'] = '<p>First</p><div>Second</div>';
	$a = rcs_render( array( 'cache' => 'true', 'selector' => 'p' ) );
	$b = rcs_render( array( 'cache' => 'true', 'selector' => 'div' ) );
	rcs_assert( str_contains( $a, 'First' ) && str_contains( $b, 'Second' ) && 1 === count( $GLOBALS['rcs_requests'] ), 'cache bypassed transformations or reused transformed data' );
} );
rcs_case( 'cache separates WordPress users', function () {
	wp_set_current_user( 0 );
	rcs_render( array( 'cache' => 'true' ) );
	wp_set_current_user( 1 );
	rcs_render( array( 'cache' => 'true' ) );
	wp_set_current_user( 0 );
	rcs_assert( 2 === count( $GLOBALS['rcs_requests'] ), 'users shared a cache key' );
} );
rcs_case( 'cache hits rerun the return filter', function () {
	$count = 0;
	add_filter( 'remote_content_shortcode_return', function ( $response ) use ( &$count ) { ++$count; return $response; } );
	rcs_render( array( 'cache' => 'true' ) );
	rcs_render( array( 'cache' => 'true' ) );
	rcs_assert( 2 === $count && 1 === count( $GLOBALS['rcs_requests'] ), 'cache skipped a return filter' );
} );
rcs_case( 'encoded shortcode attributes and quote placeholders still work', function () {
	$GLOBALS['rcs_response'] = '<p title="old">Hello</p>';
	$out = rcs_render( array( 'decode_atts' => '&quot;true&quot;', 'find' => '&quot;~%QUOT%old%QUOT%~&quot;', 'replace' => '&quot;%QUOT%new%QUOT%&quot;' ) );
	rcs_assert( str_contains( $out, 'title="new"' ), 'attribute decoding or quote placeholder failed' );
} );
rcs_case( 'POST responses are not shared through cache', function () {
	rcs_render( array( 'cache' => 'true', 'method' => 'POST' ), 'first' );
	rcs_render( array( 'cache' => 'true', 'method' => 'POST' ), 'second' );
	rcs_assert( 2 === count( $GLOBALS['rcs_requests'] ) && 'second' === $GLOBALS['rcs_requests'][1]['args']['body'], 'POST response reused' );
} );
rcs_case( 'forwarded values cannot inject query arguments', function () {
	$_GET['id'] = $_REQUEST['id'] = 'value&admin=true';
	rcs_render( array( 'params' => 'id' ) );
	parse_str( wp_parse_url( $GLOBALS['rcs_requests'][0]['url'], PHP_URL_QUERY ), $query );
	rcs_assert( array( 'id' => 'value&admin=true' ) === $query, 'query value injected an argument' );
} );
rcs_case( 'missing query values do not inherit previous defaults', function () {
	rcs_render( array( 'params' => 'first=default,missing' ) );
	parse_str( wp_parse_url( $GLOBALS['rcs_requests'][0]['url'], PHP_URL_QUERY ), $query );
	rcs_assert( array( 'first' => 'default' ) === $query, 'missing value inherited a previous value' );
} );
rcs_case( 'array query parameters fail quietly', function () {
	$_GET['id'] = $_REQUEST['id'] = array( 'bad' );
	rcs_assert( '' === rcs_render( array( 'params' => 'id' ) ) && ! $GLOBALS['rcs_requests'], 'array parameter reached HTTP client' );
} );
rcs_case( 'array shortcode attributes fail quietly', function () {
	rcs_assert( '' === rcs_render( array( 'url' => array( 'bad' ) ) ) && ! $GLOBALS['rcs_requests'], 'array attribute was accepted' );
} );
foreach ( array( '', 'not a url', 'https://', 'https:///example.com', 'https://example.com:abc/', 'file:///etc/passwd', 'http://127.0.0.1/', 'http://[::1]/', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.1/', 'https://user:pass@example.com/', 'http://100.64.0.1/', 'http://2130706433/' ) as $unsafe_url ) {
	rcs_case( 'reject unsafe URL ' . $unsafe_url, function () use ( $unsafe_url ) {
		rcs_assert( '' === rcs_render( array( 'url' => $unsafe_url ) ) && ! $GLOBALS['rcs_requests'], 'unsafe URL reached HTTP client' );
	} );
}
rcs_case( 'URL filters cannot introduce private destinations', function () {
	add_filter( 'remote_content_shortcode_url', fn() => 'http://127.0.0.1/' );
	rcs_assert( '' === rcs_render() && ! $GLOBALS['rcs_requests'], 'URL filter bypassed validation' );
} );
rcs_case( 'redirect to private destination is rejected', function () {
	$GLOBALS['rcs_response'] = fn() => array( 'headers' => array( 'location' => 'http://127.0.0.1/private' ), 'body' => '', 'response' => array( 'code' => 302 ) );
	rcs_assert( '' === rcs_render() && 1 === count( $GLOBALS['rcs_requests'] ) && 0 === $GLOBALS['rcs_requests'][0]['args']['redirection'], 'private redirect was delegated to HTTP transport' );
} );
rcs_case( 'public GET redirects are followed', function () {
	$GLOBALS['rcs_response'] = function ( $args, $url ) {
		return str_ends_with( $url, '/content' ) ? array( 'headers' => array( 'location' => '/next' ), 'body' => '', 'response' => array( 'code' => 302 ) ) : array( 'headers' => array(), 'body' => 'redirected', 'response' => array( 'code' => 200 ) );
	};
	rcs_assert( 'redirected' === rcs_render() && 2 === count( $GLOBALS['rcs_requests'] ), 'safe redirect failed' );
} );
rcs_case( 'requests bound time and response size', function () {
	rcs_render( array( 'timeout' => '99999' ) );
	$args = $GLOBALS['rcs_requests'][0]['args'];
	rcs_assert( $args['timeout'] <= 30 && isset( $args['limit_response_size'] ) && $args['limit_response_size'] <= 1048576 && true === $args['sslverify'], 'request limits missing' );
} );
rcs_case( 'oversized remote responses are rejected', function () {
	$GLOBALS['rcs_response'] = str_repeat( 'x', 1048576 );
	rcs_assert( '' === rcs_render(), 'oversized body was accepted' );
} );
rcs_case( 'POST redirects are refused', function () {
	$GLOBALS['rcs_response'] = fn() => array( 'headers' => array( 'location' => 'https://example.org/next' ), 'body' => '', 'response' => array( 'code' => 302 ) );
	rcs_assert( '' === rcs_render( array( 'method' => 'POST' ), 'private body' ) && 1 === count( $GLOBALS['rcs_requests'] ), 'POST redirect was allowed' );
} );
rcs_case( 'unconfigured credentials are rejected', function () {
	update_option( 'rcs_test_secret', 'alice:secret' );
	rcs_assert( '' === rcs_render( array( 'userpwd' => 'rcs_test_secret' ) ) && ! $GLOBALS['rcs_requests'], 'unconfigured credential name was accepted' );
} );
function rcs_auth( $type = 'option', $key = 'rcs_test_secret', $post_id = 0 ) {
	add_filter( 'remote_content_shortcode_credentials', fn() => array( 'api' => array( 'origin' => 'https://example.com', 'path' => '/content', 'type' => $type, 'key' => $key, 'post_id' => $post_id ) ) );
}
rcs_case( 'configured option credentials work', function () {
	update_option( 'rcs_test_secret', 'alice:secret' );
	rcs_auth();
	rcs_render( array( 'userpwd' => 'api' ) );
	rcs_assert( 'Basic ' . base64_encode( 'alice:secret' ) === ( $GLOBALS['rcs_requests'][0]['args']['headers']['Authorization'] ?? null ), 'option credentials not resolved' );
} );
rcs_case( 'configured post metadata uses the correct post and key', function () {
	$id = wp_insert_post( array( 'post_title' => 'Credential test', 'post_status' => 'draft' ) );
	$GLOBALS['post'] = get_post( $id );
	update_post_meta( $id, '_rcs_test_secret', 'bob:secret' );
	rcs_auth( 'post_meta', '_rcs_test_secret', $id );
	rcs_render( array( 'userpwd' => 'api' ) );
	rcs_assert( 'Basic ' . base64_encode( 'bob:secret' ) === ( $GLOBALS['rcs_requests'][0]['args']['headers']['Authorization'] ?? null ), 'post metadata credentials not resolved' );
	wp_delete_post( $id, true );
	unset( $GLOBALS['post'] );
} );
rcs_case( 'configured constant credentials work', function () {
	define( 'RCS_TEST_SECRET', 'carol:secret' );
	rcs_auth( 'constant', 'RCS_TEST_SECRET' );
	rcs_render( array( 'userpwd' => 'api' ) );
	rcs_assert( 'Basic ' . base64_encode( 'carol:secret' ) === ( $GLOBALS['rcs_requests'][0]['args']['headers']['Authorization'] ?? null ), 'constant credentials not resolved' );
} );
rcs_case( 'credentials cannot be sent to another origin', function () {
	rcs_auth();
	rcs_assert( '' === rcs_render( array( 'url' => 'https://example.org/content', 'userpwd' => 'api' ) ) && ! $GLOBALS['rcs_requests'], 'credentials sent to another origin' );
} );
rcs_case( 'credentials cannot be sent over HTTP', function () {
	rcs_auth();
	rcs_assert( '' === rcs_render( array( 'url' => 'http://example.com/content', 'userpwd' => 'api' ) ) && ! $GLOBALS['rcs_requests'], 'credentials sent without HTTPS' );
} );
rcs_case( 'credentials cannot be used at another endpoint', function () {
	rcs_auth();
	rcs_assert( '' === rcs_render( array( 'url' => 'https://example.com/echo', 'userpwd' => 'api' ) ) && ! $GLOBALS['rcs_requests'], 'credential endpoint restriction was ignored' );
} );
rcs_case( 'credential aliases default to GET only', function () {
	update_option( 'rcs_test_secret', 'alice:secret' );
	rcs_auth();
	rcs_assert( '' === rcs_render( array( 'method' => 'POST', 'userpwd' => 'api' ), 'operation' ) && ! $GLOBALS['rcs_requests'], 'credential alias allowed an unconfigured POST' );
} );
rcs_case( 'authenticated responses are not shared through cache', function () {
	update_option( 'rcs_test_secret', 'alice:secret' );
	rcs_auth();
	rcs_render( array( 'userpwd' => 'api', 'cache' => 'true' ) );
	rcs_render( array( 'userpwd' => 'api', 'cache' => 'true' ) );
	rcs_assert( 2 === count( $GLOBALS['rcs_requests'] ), 'authenticated data cached' );
} );
rcs_case( 'authenticated redirects are refused', function () {
	update_option( 'rcs_test_secret', 'alice:secret' );
	rcs_auth();
	$GLOBALS['rcs_response'] = fn() => array( 'headers' => array( 'location' => 'https://example.org/steal' ), 'body' => '', 'response' => array( 'code' => 302 ) );
	rcs_assert( '' === rcs_render( array( 'userpwd' => 'api' ) ) && 1 === count( $GLOBALS['rcs_requests'] ) && 0 === $GLOBALS['rcs_requests'][0]['args']['redirection'], 'authenticated redirect was allowed' );
} );
rcs_case( 'SyntaxHighlighter integration does not execute unrelated shortcodes', function () {
	add_shortcode( 'rcs_unrelated', fn() => 'executed' );
	$out = apply_filters( 'syntaxhighlighter_htmlresult', '&#x5B;rcs_unrelated]' );
	rcs_assert( ! str_contains( $out, 'executed' ), 'unrelated shortcode executed' );
	remove_shortcode( 'rcs_unrelated' );
} );

delete_option( 'rcs_test_secret' );
echo sprintf( "%d tests, %d failures\n", $rcs_count, $rcs_failures );
if ( $rcs_failures ) {
	exit( 1 );
}
