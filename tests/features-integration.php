<?php
/** Feature integration against a disposable WordPress site. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$calls = array();
$response = '<p>Hello</p>';
$intercept = static function ( $pre, $args, $url ) use ( &$calls, &$response ) {
	$calls[] = array( $url, $args );
	return is_callable( $response ) ? $response( $url, $args ) : ( is_wp_error( $response ) ? $response : array( 'body' => $response, 'headers' => array(), 'response' => array( 'code' => 200 ) ) );
};
add_filter( 'pre_http_request', $intercept, 10, 3 );
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$render = static fn( $atts = array(), $body = null ) => RemoteContentShortcode::get_instance()->remote_content_shortcode( array_merge( array( 'url' => 'https://example.com/content', 'cache' => 'false' ), $atts ), $body );
$tests = array();
$tests['fallback sanitizes failures and empty selections'] = static function () use ( &$response, $render, $assert ) {
	$response = new WP_Error( 'private_error', 'secret diagnostic' );
	$out = $render( array( 'fallback' => '<b>Offline</b><img src=x onerror=alert(1)>' ) );
	$assert( str_contains( $out, '<b>Offline</b>' ) && ! str_contains( $out, 'onerror' ) && ! str_contains( $out, 'secret' ), 'Unsafe or missing fallback.' );
	$response = '<p>visible</p>';
	$assert( 'Nothing matched' === $render( array( 'selector' => '.missing', 'fallback' => 'Nothing matched' ) ), 'Empty selection fallback missing.' );
	$response = '0';
	$assert( '0' === $render( array( 'fallback' => 'Offline' ) ), 'Zero content replaced by fallback.' );
};
$tests['relative URLs use final redirected URL, including cached responses'] = static function () use ( &$response, &$calls, $render, $assert ) {
	$response = static fn( $url ) => str_ends_with( $url, '/content' ) ? array( 'body' => '', 'headers' => array( 'location' => '/dir/page' ), 'response' => array( 'code' => 302 ) ) : array( 'body' => '<a href="next">Next</a><img src="/image.png">', 'headers' => array(), 'response' => array( 'code' => 200 ) );
	$a = $render( array( 'rewrite_urls' => 'true', 'cache' => 'true' ) );
	$b = $render( array( 'rewrite_urls' => 'true', 'cache' => 'true' ) );
	$assert( $a === $b && str_contains( $a, 'https://example.com/dir/next' ) && str_contains( $a, 'https://example.com/image.png' ) && 2 === count( $calls ), 'Final URL metadata was lost.' );
	$assert( str_contains( $render(), 'href="next"' ), 'Relative rewriting unexpectedly enabled by default.' );
};
$tests['bounded stale fallback survives a negative cache and reruns sanitization'] = static function () use ( &$response, &$calls, $render, $assert ) {
	$atts = array( 'cache' => 'true', 'cache_ttl' => 1, 'stale_ttl' => 30 );
	$response = '<p>Last success</p><script>unsafe</script>';
	$render( $atts );
	sleep( 2 );
	$response = new WP_Error( 'outage' );
	$a = $render( $atts );
	$b = $render( $atts );
	$assert( str_contains( $a, 'Last success' ) && ! str_contains( $a, '<script' ) && $a === $b && 2 === count( $calls ), 'Stale fallback or negative cache failed.' );
	$assert( '' === $render( $atts + array( 'method' => 'POST' ), 'body' ), 'POST used stale GET content.' );
};
$tests['bearer and API-key aliases use scoped headers and never cache'] = static function () use ( &$calls, $render, $assert ) {
	update_option( 'rcs_feature_token', 'test-token' );
	foreach ( array( 'bearer', 'api_key' ) as $auth ) {
		$filter = static fn() => array( 'api' => array( 'origin' => 'https://example.com', 'path' => '/content', 'type' => 'option', 'key' => 'rcs_feature_token', 'auth' => $auth ) );
		add_filter( 'remote_content_shortcode_credentials', $filter );
		try {
			$before = count( $calls );
			$render( array( 'userpwd' => 'api', 'cache' => 'true', 'stale_ttl' => 30 ) );
			$render( array( 'userpwd' => 'api', 'cache' => 'true' ) );
			$headers = $calls[ $before ][1]['headers'];
			$assert( 2 === count( $calls ) - $before && ( 'bearer' === $auth ? 'Bearer test-token' === ( $headers['Authorization'] ?? '' ) : 'test-token' === ( $headers['X-API-Key'] ?? '' ) ), 'Alias headers or cache isolation failed.' );
		} finally { remove_filter( 'remote_content_shortcode_credentials', $filter ); }
	}
	delete_option( 'rcs_feature_token' );
};
$tests['diagnostics enforce capability, redact errors, and report cache and matches'] = static function () use ( &$response, $assert ) {
	$plugin = RemoteContentShortcode::get_instance();
	wp_set_current_user( 0 );
	$assert( is_wp_error( $plugin->diagnose( array() ) ) && is_wp_error( $plugin->clear_cache() ), 'Anonymous privileged operation accepted.' );
	wp_set_current_user( 1 );
	$response = '<p>one</p><p>two</p>';
	$atts = array( 'url' => 'https://example.com/content', 'selector' => 'p' );
	$a = $plugin->diagnose( $atts );
	$b = $plugin->diagnose( $atts );
	$assert( true === $a['ok'] && 2 === $a['selector_matches'] && 'disabled' === $a['cache_status'] && 'disabled' === $b['cache_status'], 'Administrator diagnostics unexpectedly persisted cache entries.' );
	$response = new WP_Error( 'http_request_failed', 'password=secret&token=private' );
	$bad = $plugin->diagnose( array( 'url' => 'https://example.com/failure?token=private', 'cache' => 'false' ) );
	$assert( false === $bad['ok'] && ! str_contains( wp_json_encode( $bad ), 'secret' ) && ! str_contains( wp_json_encode( $bad ), 'private' ), 'Diagnostic leaked secret error details.' );
};
$tests['targeted clearing preserves other URLs and unrelated caches'] = static function () use ( &$calls, $render, $assert ) {
	$render( array( 'cache' => 'true', 'url' => 'https://example.com/one' ) );
	$render( array( 'cache' => 'true', 'url' => 'https://example.com/two' ) );
	set_transient( 'rcs_feature_unrelated', 'keep', 60 );
	wp_set_current_user( 1 );
	$count = RemoteContentShortcode::get_instance()->clear_cache( 'https://example.com/one' );
	wp_set_current_user( 0 );
	$render( array( 'cache' => 'true', 'url' => 'https://example.com/one' ) );
	$render( array( 'cache' => 'true', 'url' => 'https://example.com/two' ) );
	$assert( 1 === $count && 3 === count( $calls ) && 'keep' === get_transient( 'rcs_feature_unrelated' ), 'URL clearing crossed cache boundaries.' );
	delete_transient( 'rcs_feature_unrelated' );
};
$tests['editor and logged-in renders cannot evict visitor cache entries'] = static function () use ( &$calls, $render, $assert ) {
	$atts = array( 'url' => 'https://example.com/visitor', 'cache' => 'true' );
	$render( $atts );
	wp_set_current_user( 1 );
	for ( $i = 0; $i < 35; ++$i ) { $render( array( 'url' => 'https://example.com/preview-' . $i, 'cache' => 'true' ) ); }
	wp_set_current_user( 0 );
	$render( $atts );
	$assert( 36 === count( $calls ), 'Editor requests evicted anonymous cache data.' );
};
$failures = 0;
$old_user = get_current_user_id();
foreach ( $tests as $name => $test ) {
	$calls = array(); $response = '<p>Hello</p>'; wp_set_current_user( 0 );
	if ( class_exists( 'Remote_Content_Shortcode_Cache' ) ) { Remote_Content_Shortcode_Cache::clear(); }
	try { $test(); echo "PASS $name\n"; } catch ( Throwable $error ) { ++$failures; echo "FAIL $name: " . $error->getMessage() . "\n"; }
}
wp_set_current_user( $old_user );
remove_filter( 'pre_http_request', $intercept, 10 );
if ( class_exists( 'Remote_Content_Shortcode_Cache' ) ) { Remote_Content_Shortcode_Cache::clear(); }
if ( $failures ) { WP_CLI::error( "$failures feature integration failures" ); }
echo count( $tests ) . " feature integration cases passed\n";
