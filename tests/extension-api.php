<?php
/** Shared Free services used by independent add-ons. Run in disposable WordPress. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function ea_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$plugin = RemoteContentShortcode::get_instance();
ea_assert( method_exists( $plugin, 'fetch_source' ) && method_exists( $plugin, 'render_fetched' ), 'Shared protected fetch and rendering services are missing.' );
$requests = array();
$offline = static function ( $pre, $args, $url ) use ( &$requests ) {
	$requests[] = array( $url, $args );
	return array( 'headers' => array(), 'body' => '<main><a href="next">safe</a><script>bad()</script></main>', 'response' => array( 'code' => 200 ) );
};
add_filter( 'pre_http_request', $offline, 10, 3 );
try {
	$response = $plugin->fetch_source( array( 'url' => 'https://source.rcs.test/page' ) );
	ea_assert( is_array( $response ) && 200 === $response['status'] && 'https://source.rcs.test/page' === $response['url'], 'Fetch did not return the verified response envelope.' );
	ea_assert( true === $requests[0][1]['sslverify'] && 0 === $requests[0][1]['redirection'] && array() === $requests[0][1]['cookies'], 'Add-on fetch weakened transport policy.' );
	$before = count( $requests );
	$html = $plugin->render_fetched( $response, array( 'selector' => 'main', 'remove' => 'script', 'rewrite_urls' => 'true' ) );
	ea_assert( str_contains( $html, 'https://source.rcs.test/next' ) && ! str_contains( $html, '<script' ) && $before === count( $requests ), 'Snapshot rendering fetched again or skipped the safe transformation pipeline.' );
	$inject = static fn() => '<img src=x onerror=alert(1)><script>bad()</script><p>filtered</p>';
	add_filter( 'remote_content_shortcode_return', $inject );
	try { $html = $plugin->render_fetched( $response, array() ); }
	finally { remove_filter( 'remote_content_shortcode_return', $inject ); }
	ea_assert( str_contains( $html, 'filtered' ) && ! str_contains( $html, 'onerror' ) && ! str_contains( $html, '<script' ), 'Add-on output filters bypassed final sanitization.' );
	foreach ( array( 'file:///etc/passwd', 'http://169.254.169.254/metadata', 'http://private.rcs.test/' ) as $url ) {
		$before = count( $requests );
		ea_assert( is_wp_error( $plugin->fetch_source( array( 'url' => $url ) ) ) && $before === count( $requests ), 'Add-on fetch reached an unsafe URL.' );
	}
	ea_assert( is_wp_error( $plugin->fetch_source( array( 'url' => 'https://source.rcs.test/', 'method' => 'DELETE' ) ) ), 'Unsupported method reached add-on fetch.' );
	ea_assert( is_wp_error( $plugin->fetch_source( array( 'url' => array() ) ) ), 'Nonscalar fetch attributes were accepted.' );
	ea_assert( is_wp_error( $plugin->fetch_source( array( 'url' => 'https://source.rcs.test/', 'userpwd' => 'missing' ) ) ), 'Unknown credentials reached add-on fetch.' );
	ea_assert( is_wp_error( $plugin->fetch_source( array( 'url' => 'https://source.rcs.test/' ), str_repeat( 'x', 1048577 ) ) ), 'Oversized add-on POST body was accepted.' );
	$response = $plugin->fetch_source( array( 'url' => 'https://source.rcs.test/', 'method' => 'POST' ), 'key=value' );
	$last = end( $requests );
	ea_assert( is_array( $response ) && 'POST' === $last[1]['method'] && 'key=value' === $last[1]['body'], 'Add-on POST did not preserve its explicit body.' );
	foreach ( array( array(), array( 'url' => 'https://source.rcs.test/', 'body' => array() ), array( 'url' => 'file:///etc/passwd', 'body' => 'content' ), array( 'url' => 'https://source.rcs.test/', 'body' => str_repeat( 'x', 1048576 ) ) ) as $invalid ) {
		ea_assert( '' === $plugin->render_fetched( $invalid, array() ), 'Invalid snapshot data reached output.' );
	}
	$user = get_current_user_id();
	wp_set_current_user( get_user_by( 'login', 'rcs_admin' )->ID );
	try { $diagnosis = $plugin->diagnose( array( 'url' => 'https://source.rcs.test/', 'method' => 'POST' ), 'diagnostic=body' ); }
	finally { wp_set_current_user( $user ); }
	$last = end( $requests );
	ea_assert( is_array( $diagnosis ) && 'diagnostic=body' === $last[1]['body'], 'Diagnostics dropped the builder POST body.' );
} finally { remove_filter( 'pre_http_request', $offline, 10 ); }
echo "PASS protected add-on fetch, shared sanitization, snapshot bounds and POST diagnostics\n";
