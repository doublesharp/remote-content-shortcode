<?php
/** Focused security-boundary regressions; run in disposable WordPress. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once getenv( 'RCS_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/remote-content-shortcode.php';

$plugin = RemoteContentShortcode::get_instance();
$cases = array();
function ah_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function ah_render( $extra = array(), $body = null ) {
	return RemoteContentShortcode::get_instance()->remote_content_shortcode( $extra + array( 'url' => 'https://8.8.8.8/content', 'cache' => 'false' ), $body );
}
$body = '<p>Fixture</p>';
$requests = 0;
$transport = static function () use ( &$body, &$requests ) {
	++$requests;
	return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200 ) );
};
add_filter( 'pre_http_request', $transport );
$cases['POST rendering requires a server-side grant'] = static function () use ( &$requests ) {
	ah_assert( '' === ah_render( array( 'method' => 'POST' ), 'fixed' ) && 0 === $requests, 'Ungranted POST reached transport.' );
	$id = wp_insert_post( array( 'post_title' => 'Inert POST fixture', 'post_status' => 'publish', 'post_content' => '[remote_content url="https://8.8.8.8/content" method="POST"]fixed[/remote_content]' ) );
	try {
		$reply = rest_do_request( '/wp/v2/posts/' . $id );
		ah_assert( 200 === $reply->get_status() && 0 === $requests, 'Anonymous REST rendering replayed an ungranted POST.' );
	} finally { wp_delete_post( $id, true ); }
};
$cases['remote styling and subresources are inert by default'] = static function () use ( &$body ) {
	$body = '<div id="overlay" class="overlay" style="position:fixed;top:0;z-index:99999"><img src="/marker" srcset="http://127.0.0.1/a 1x"><video poster="https://8.8.8.8/p"></video>Text</div>';
	$html = ah_render();
	foreach ( array( 'style=', 'class=', 'id=', 'src=', 'srcset=', 'poster=' ) as $attribute ) {
		ah_assert( ! str_contains( $html, $attribute ), 'Remote authority survived: ' . $attribute );
	}
	ah_assert( str_contains( $html, 'Text' ), 'Ordinary content was lost.' );
};
$cases['forwarded parameter input is bounded before processing'] = static function () use ( &$requests ) {
	$_GET['v'] = str_repeat( 'x', 4097 );
	ah_assert( '' === ah_render( array( 'params' => 'v' ) ) && 0 === $requests, 'Oversized query value reached transport.' );
	$_GET['v'] = 'x';
	ah_assert( '' === ah_render( array( 'params' => str_repeat( 'v,', 2049 ) ) ) && 0 === $requests, 'Oversized params list reached transport.' );
};
$cases['entity expansion has a final output ceiling'] = static function () use ( &$body ) {
	$body = str_repeat( '&', 300000 );
	ah_assert( '' === ah_render( array( 'htmlentities' => 'true' ) ), 'Entity expansion exceeded the output ceiling.' );
};
$cases['render count and aggregate output are bounded on cache hits'] = static function () use ( &$body, &$requests ) {
	$body = str_repeat( 'x', 600000 );
	for ( $i = 0; $i < 6; ++$i ) { ah_assert( '' !== ah_render( array( 'cache' => 'true' ) ), 'Output budget rejected an in-budget fragment.' ); }
	ah_assert( '' === ah_render( array( 'cache' => 'true' ) ) && 1 === $requests, 'Cache hits bypassed aggregate output accounting.' );
	Remote_Content_Shortcode_Budget::reset(); $body = 'x';
	for ( $i = 0; $i < 32; ++$i ) { ah_assert( '' !== ah_render( array( 'url' => 'https://8.8.8.8/count', 'cache' => 'true' ) ), 'Render count rejected an in-budget fragment.' ); }
	ah_assert( '' === ah_render( array( 'url' => 'https://8.8.8.8/count', 'cache' => 'true' ) ), 'Render count was unbounded.' );
};
$cases['outbound request count is bounded independently of output'] = static function () use ( &$requests ) {
	for ( $i = 0; $i < 8; ++$i ) { ah_assert( '' !== ah_render(), 'An in-budget fetch was rejected.' ); }
	ah_assert( '' === ah_render() && 8 === $requests, 'Ninth outbound fetch was admitted.' );
};
$cases['resource grants are scoped, canonical and cardinality bounded'] = static function () use ( &$body ) {
	$grant = static fn() => array( 'https://images.example.com', 'https://127.0.0.1', 'https://fixture.invalid' );
	add_filter( 'remote_content_shortcode_resource_origins', $grant );
	try {
		$body = '<img src="https://127.0.0.1/inert"><img src="https://fixture.invalid/inert"><img src="/relative"><img src="http://images.example.com/inert">' . str_repeat( '<img src="https://images.example.com/inert">', 40 );
		$html = ah_render();
		ah_assert( 32 === substr_count( $html, 'src=' ) && ! str_contains( $html, '127.0.0.1' ) && ! str_contains( $html, '/relative' ) && ! str_contains( $html, 'http:' ), 'Resource grant escaped its policy.' );
	} finally { remove_filter( 'remote_content_shortcode_resource_origins', $grant ); }
};
$cases['SyntaxHighlighter rejects oversized subjects'] = static function () use ( $plugin ) {
	ah_assert( '' === $plugin->process_shortcodes_in_syntaxhighlighter( str_repeat( 'x', 262145 ) ), 'Oversized compatibility subject accepted.' );
};
$cases['SyntaxHighlighter limits callbacks and preserves unrelated delimiters'] = static function () use ( $plugin ) {
	$count = 0;
	$observe = static function ( $pre ) use ( &$count ) { ++$count; return $pre; };
	add_filter( 'pre_do_shortcode_tag', $observe );
	try {
		$html = $plugin->process_shortcodes_in_syntaxhighlighter( str_repeat( '&#x5B;remote_content url="file:///inert"]', 5 ) );
		ah_assert( '' === $html && 4 === $count, 'Compatibility integration exceeded its callback budget.' );
		ah_assert( '[unrelated]' !== $plugin->process_shortcodes_in_syntaxhighlighter( '&#x5B;unrelated]' ), 'Unrelated delimiter was decoded.' );
	} finally { remove_filter( 'pre_do_shortcode_tag', $observe ); }
};
$cases['regex uses its own match budget and preserves ambient settings'] = static function () {
	require_once dirname( __DIR__ ) . '/inc/class-remote-content-regex.php';
	$before = ini_get( 'pcre.backtrack_limit' );
	$result = Remote_Content_Shortcode_Regex::replace( '~(*NO_JIT)^(a+)+$~', '', str_repeat( 'a', 17 ) . '!' );
	ah_assert( is_wp_error( $result ), 'Match work used the larger ambient budget.' );
	$result = Remote_Content_Shortcode_Regex::replace( '~(*LIMIT_MATCH=1000000)(*NO_JIT)^(a+)+$~', '', str_repeat( 'a', 17 ) . '!' );
	ah_assert( is_wp_error( $result ), 'Pattern raised the local match budget.' );
	ah_assert( ini_get( 'pcre.backtrack_limit' ) === $before, 'Ambient PCRE setting changed.' );
};
$cases['fetch admission coalesces and bounds distinct in-flight keys'] = static function () {
	ah_assert( method_exists( Remote_Content_Shortcode_Cache::class, 'acquire_fetch' ), 'Fetch admission is missing.' );
	$leases = array();
	try {
		for ( $i = 0; $i < 4; ++$i ) {
			$leases[] = Remote_Content_Shortcode_Cache::acquire_fetch( 'key-' . $i );
			ah_assert( is_array( end( $leases ) ), 'Available fetch slot was refused.' );
		}
		ah_assert( false === Remote_Content_Shortcode_Cache::acquire_fetch( 'key-0' ), 'Duplicate key was admitted.' );
		ah_assert( false === Remote_Content_Shortcode_Cache::acquire_fetch( 'fifth' ), 'Fifth in-flight request was admitted.' );
		add_option( Remote_Content_Shortcode_Cache::LOCK, ( time() + 60 ) . ':other-cache-writer', '', false );
	} finally {
		foreach ( $leases as $lease ) { if ( is_array( $lease ) ) { Remote_Content_Shortcode_Cache::release_fetch( $lease ); } }
		delete_option( Remote_Content_Shortcode_Cache::LOCK );
		foreach ( $leases as $lease ) { ah_assert( false === get_option( Remote_Content_Shortcode_Cache::FETCH_SLOTS . $lease['id'], false ), 'Cache-lock contention prevented slot release.' ); }
	}
};
$cases['expired fetch leases recover without stale-owner release'] = static function () {
	$old = Remote_Content_Shortcode_Cache::acquire_fetch( 'old' );
	ah_assert( is_array( $old ), 'Initial lease failed.' );
	$expired = $old; $expired['expires'] = time() - 1;
	update_option( Remote_Content_Shortcode_Cache::FETCH_SLOTS . $old['id'], $expired, false );
	$new = Remote_Content_Shortcode_Cache::acquire_fetch( 'replacement' );
	ah_assert( is_array( $new ), 'Expired lease could not be recovered.' );
	try {
		Remote_Content_Shortcode_Cache::release_fetch( $old );
		ah_assert( false === Remote_Content_Shortcode_Cache::acquire_fetch( 'replacement' ), 'Stale owner released a replacement lease.' );
	} finally { Remote_Content_Shortcode_Cache::release_fetch( $new ); }
};
$failures = 0;
foreach ( $cases as $name => $case ) {
	$requests = 0; $body = '<p>Fixture</p>'; $_GET = array();
	Remote_Content_Shortcode_Cache::clear();
	if ( class_exists( 'Remote_Content_Shortcode_Budget' ) ) { Remote_Content_Shortcode_Budget::reset(); }
	try { $case(); echo "PASS $name\n"; }
	catch ( Throwable $error ) { ++$failures; echo "FAIL $name: " . $error->getMessage() . "\n"; }
}
remove_filter( 'pre_http_request', $transport );
if ( $failures ) { throw new RuntimeException( "$failures audit regressions failed." ); }
