<?php
/** Run in a separate WordPress process with a configured proxy and one bypass host. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WP_PROXY_HOST' ) || ! defined( 'WP_PROXY_BYPASS_HOSTS' ) ) { throw new RuntimeException( 'Configure the disposable proxy test before WordPress loads.' ); }
$previous = get_current_user_id();
$requests = 0;
$observe = static function ( $pre ) use ( &$requests ) { ++$requests; return $pre; };
add_filter( 'pre_http_request', $observe );
try {
	wp_set_current_user( 1 );
	$plugin = RemoteContentShortcode::get_instance();
	$result = $plugin->diagnose( array( 'url' => 'http://proxy.rcs.test/ok', 'cache' => 'false', 'timeout' => 2 ) );
	if ( $result['ok'] || 'proxy_not_supported' !== $result['error_code'] || '' !== $result['html'] || 0 !== $requests ) { throw new RuntimeException( 'Proxied destination was not refused before HTTP dispatch.' ); }
	$result = $plugin->diagnose( array( 'url' => 'http://source.rcs.test/ok', 'cache' => 'false', 'timeout' => 2 ) );
	if ( ! $result['ok'] || ! str_contains( $result['html'], 'fixture success' ) || 1 !== $requests ) { throw new RuntimeException( 'Explicit proxy bypass did not use the pinned transport.' ); }
} finally { remove_filter( 'pre_http_request', $observe ); wp_set_current_user( $previous ); }
echo "PASS configured proxies fail closed and explicit bypass uses pinned HTTP\n";
