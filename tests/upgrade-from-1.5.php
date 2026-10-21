<?php
/** Seed a real 1.5 installation, then verify its saved content after WP_Upgrader replaces it with v2. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$fixture = 'http://source.rcs.test/upgrade';
$cases = array(
	'whole' => '[remote_content url="' . $fixture . '" cache="false"]',
	'selector' => '[remote_content url="' . $fixture . '" selector=".keep" cache="false"]',
	'remove' => '[remote_content url="' . $fixture . '" selector="main" remove="aside" cache="false"]',
	'replace' => '[remote_content url="' . $fixture . '" find="~Legacy~" replace="Updated" cache="false"]',
	'cache' => '[remote_content url="' . $fixture . '" selector=".keep" cache_ttl="300"]',
	'credentials' => '[remote_content url="https://source.rcs.test/upgrade" userpwd="rcs_upgrade_credential" cache="false"]',
);
$phase = getenv( 'RCS_UPGRADE_PHASE' );
if ( 'prepare' === $phase ) {
	$data = get_plugin_data( WP_PLUGIN_DIR . '/remote-content-shortcode/remote-content-shortcode.php' );
	$assert( '1.5' === $data['Version'] && is_plugin_active( 'remote-content-shortcode/remote-content-shortcode.php' ), 'Expected the original active 1.5 plugin.' );
	$ids = array();
	foreach ( $cases as $name => $shortcode ) {
		$id = wp_insert_post( array( 'post_title' => 'Upgrade ' . $name, 'post_status' => 'publish', 'post_content' => $shortcode ), true );
		$assert( ! is_wp_error( $id ), 'Legacy post creation failed.' );
		$ids[ $name ] = $id;
	}
	update_option( 'rcs_upgrade_posts', $ids );
	update_option( 'rcs_upgrade_credential', 'upgrade-user:upgrade-test-password' );
	$out = apply_filters( 'the_content', $cases['whole'] );
	$assert( str_contains( $out, 'Legacy article' ) && str_contains( $out, 'Advertisement' ), 'Legacy plugin could not import the benign fixture.' );
	// Demonstrate the disclosed XSS on 1.5 without opening it in a browser.
	$unsafe = do_shortcode( '[remote_content url="http://source.rcs.test/xss.txt" cache="false"]' );
	$assert( str_contains( $unsafe, '<image src=0 onerror=alert(8)>' ), 'Legacy XSS baseline did not reproduce.' );
	echo "PASS active original 1.5 imports fixture content and reproduces the disclosed XSS baseline\n";
	echo "PASS saved six representative legacy shortcodes and a credential option for upgrade\n";
	return;
}
$data = get_plugin_data( WP_PLUGIN_DIR . '/remote-content-shortcode/remote-content-shortcode.php' );
$assert( '2.0' === $data['Version'] && is_plugin_active( 'remote-content-shortcode/remote-content-shortcode.php' ), 'Upgrade did not leave v2 active.' );
$assert( ! file_exists( WP_PLUGIN_DIR . '/remote-content-shortcode/inc/phpQuery.php' ), 'Obsolete phpQuery survived the upgrade.' );
$ids = get_option( 'rcs_upgrade_posts' );
$assert( is_array( $ids ) && count( $ids ) === count( $cases ), 'Saved posts were lost during the upgrade.' );
foreach ( $cases as $name => $shortcode ) {
	$assert( get_post_field( 'post_content', $ids[ $name ], 'raw' ) === $shortcode, 'Upgrade modified saved shortcode content.' );
}
foreach ( array( 'whole', 'selector', 'remove', 'replace' ) as $name ) {
	$out = apply_filters( 'the_content', get_post_field( 'post_content', $ids[ $name ], 'raw' ) );
	$assert( str_contains( $out, 'replace' === $name ? 'Updated article' : 'Legacy article' ), 'Migrated import failed: ' . $name );
	if ( in_array( $name, array( 'selector', 'remove' ), true ) ) { $assert( ! str_contains( $out, 'Advertisement' ), 'Selection/removal changed unexpectedly.' ); }
}
echo "PASS WordPress ZIP upgrade preserves activation and saved posts; selection/removal/replacement render correctly\n";
$stats = static fn() => json_decode( file_get_contents( 'http://11.231.47.10/stats' ), true )['source.rcs.test/upgrade'] ?? 0;
wp_set_current_user( 0 );
Remote_Content_Shortcode_Cache::clear();
$before = $stats();
$a = do_shortcode( $cases['cache'] );
$b = do_shortcode( $cases['cache'] );
$assert( str_contains( $a, 'Legacy article' ) && $a === $b && 1 === $stats() - $before, 'Migrated cache did not reuse a real request.' );
echo "PASS existing cache shortcode uses v2 persistent caching: two renders, one request\n";
$requests = 0;
$observe = static function ( $pre ) use ( &$requests ) { ++$requests; return $pre; };
add_filter( 'pre_http_request', $observe );
try {
	$assert( '' === do_shortcode( $cases['credentials'] ) && 0 === $requests, 'Legacy credential name was accepted without explicit configuration.' );
	$alias = static function ( $sources ) {
		$sources['rcs_upgrade_credential'] = array( 'origin' => 'https://source.rcs.test', 'path' => '/upgrade', 'type' => 'option', 'key' => 'rcs_upgrade_credential' );
		return $sources;
	};
	add_filter( 'remote_content_shortcode_credentials', $alias );
	try {
		$assert( str_contains( do_shortcode( $cases['credentials'] ), 'Legacy article' ) && 1 === $requests, 'Explicit credential alias did not restore the saved import.' );
	} finally { remove_filter( 'remote_content_shortcode_credentials', $alias ); }
} finally { remove_filter( 'pre_http_request', $observe ); }
$assert( 'upgrade-user:upgrade-test-password' === get_option( 'rcs_upgrade_credential' ), 'Upgrade changed credential storage.' );
echo "PASS implicit credential lookup is refused; an explicit scoped alias restores the existing shortcode\n";
foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
delete_option( 'rcs_upgrade_posts' );
delete_option( 'rcs_upgrade_credential' );
Remote_Content_Shortcode_Cache::clear();
