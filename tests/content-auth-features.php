<?php
/** wp eval-file tests/content-auth-features.php in the disposable WordPress site. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$base = dirname( getenv( 'RCS_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/remote-content-shortcode.php' );
foreach ( array( 'credentials', 'urls' ) as $helper ) {
	$file = $base . '/inc/class-remote-content-' . $helper . '.php';
	if ( file_exists( $file ) ) { require_once $file; }
}
$failures = 0;
function ca_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function ca_source( $extra = array() ) { return array_merge( array( 'origin' => 'https://example.com', 'path' => '/content', 'type' => 'option', 'key' => 'rcs_ca_secret' ), $extra ); }
$cases = array();
$cases['legacy basic and isolated storage'] = function () {
	update_option( 'rcs_ca_secret', 'alice:secret' );
	ca_check( array( 'Authorization' => 'Basic ' . base64_encode( 'alice:secret' ) ) === Remote_Content_Shortcode_Credentials::headers( ca_source(), 'https://example.com/content', 'GET' ), 'legacy Basic failed' );
	ca_check( is_wp_error( Remote_Content_Shortcode_Credentials::headers( ca_source( array( 'type' => 'other' ) ), 'https://example.com/content', 'GET' ) ), 'unknown storage accepted' );
};
$cases['constant and post metadata storage'] = function () {
	if ( ! defined( 'RCS_CA_SECRET' ) ) { define( 'RCS_CA_SECRET', 'constant:secret' ); }
	ca_check( array( 'Authorization' => 'Basic ' . base64_encode( 'constant:secret' ) ) === Remote_Content_Shortcode_Credentials::headers( ca_source( array( 'type' => 'constant', 'key' => 'RCS_CA_SECRET' ) ), 'https://example.com/content', 'GET' ), 'constant storage failed' );
	$post_id = wp_insert_post( array( 'post_title' => 'Credential scope test', 'post_status' => 'draft' ) );
	try {
		$GLOBALS['post'] = get_post( $post_id );
		update_post_meta( $post_id, '_rcs_ca_secret', 'meta:secret' );
		ca_check( array( 'Authorization' => 'Basic ' . base64_encode( 'meta:secret' ) ) === Remote_Content_Shortcode_Credentials::headers( ca_source( array( 'type' => 'post_meta', 'key' => '_rcs_ca_secret', 'post_id' => $post_id ) ), 'https://example.com/content', 'GET' ), 'metadata storage failed' );
		$source = ca_source( array( 'type' => 'post_meta', 'key' => '_rcs_ca_secret', 'post_id' => $post_id ) );
		unset( $GLOBALS['post'] );
		ca_check( array( 'Authorization' => 'Basic ' . base64_encode( 'meta:secret' ) ) === Remote_Content_Shortcode_Credentials::headers( $source, 'https://example.com/content', 'GET' ), 'ambient post changed configured source' );
		foreach ( array( array( 'key' => 'rcs_ca_secret' ), array( 'post_id' => null ), array( 'post_id' => '1' ), array( 'post_id' => 0 ) ) as $invalid ) {
			ca_check( is_wp_error( Remote_Content_Shortcode_Credentials::headers( array_merge( $source, $invalid ), 'https://example.com/content', 'GET' ) ), 'unsafe metadata source accepted' );
		}
		register_post_meta( '', '_rcs_ca_secret', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
		ca_check( is_wp_error( Remote_Content_Shortcode_Credentials::headers( $source, 'https://example.com/content', 'GET' ) ), 'REST exposed metadata accepted' );
		unregister_post_meta( '', '_rcs_ca_secret' );
		register_post_meta( 'post', '_rcs_ca_secret', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
		ca_check( is_wp_error( Remote_Content_Shortcode_Credentials::headers( $source, 'https://example.com/content', 'GET' ) ), 'post type REST exposed metadata accepted' );
		unregister_post_meta( 'post', '_rcs_ca_secret' );
	} finally { unregister_post_meta( '', '_rcs_ca_secret' ); unregister_post_meta( 'post', '_rcs_ca_secret' ); wp_delete_post( $post_id, true ); unset( $GLOBALS['post'] ); }
};
$cases['bearer and api key'] = function () {
	update_option( 'rcs_ca_secret', 'secret-token' );
	ca_check( array( 'Authorization' => 'Bearer secret-token' ) === Remote_Content_Shortcode_Credentials::headers( ca_source( array( 'auth' => 'bearer' ) ), 'https://example.com/content', 'GET' ), 'bearer failed' );
	foreach ( array( 'X-API-Key', 'Api-Key', 'X-Custom-Token' ) as $header ) {
		ca_check( array( $header => 'secret-token' ) === Remote_Content_Shortcode_Credentials::headers( ca_source( array( 'auth' => 'api_key', 'header' => $header ) ), 'https://example.com/content', 'GET' ), 'API key failed' );
	}
};
$cases['credential scope and invalid configuration'] = function () {
	update_option( 'rcs_ca_secret', 'a:b' );
	foreach ( array( 'http://example.com/content', 'https://example.org/content', 'https://example.com/other', 'https://user:pass@example.com/content' ) as $url ) {
		ca_check( is_wp_error( Remote_Content_Shortcode_Credentials::headers( ca_source(), $url, 'GET' ) ), 'scope escaped' );
	}
	ca_check( is_wp_error( Remote_Content_Shortcode_Credentials::headers( ca_source(), 'https://example.com/content', 'POST' ) ), 'method escaped' );
	foreach ( array( 'Host', 'Cookie', 'Authorization', 'Proxy-Authorization', 'X-Forwarded-Host', 'X-Client-IP', 'X-Originating-IP', 'X-Remote-Addr', 'X-Cluster-Client-IP', "X-Key\r\nOther" ) as $header ) {
		ca_check( is_wp_error( Remote_Content_Shortcode_Credentials::headers( ca_source( array( 'auth' => 'api_key', 'header' => $header ) ), 'https://example.com/content', 'GET' ) ), 'dangerous header accepted' );
	}
	foreach ( array( '', "bad\r\nvalue", 'two words', str_repeat( 'a', 8193 ) ) as $value ) {
		update_option( 'rcs_ca_secret', $value );
		ca_check( is_wp_error( Remote_Content_Shortcode_Credentials::headers( ca_source( array( 'auth' => 'bearer' ) ), 'https://example.com/content', 'GET' ) ), 'bad token accepted' );
	}
};
$cases['URL resolution uses final URL and preserves fragments and UTF8'] = function () {
	$out = Remote_Content_Shortcode_URLs::rewrite( '<base href="https://evil.test/"><a href="../one?x=1&amp;y=2">café</a><a href="#local">local</a><img src="/image"><video poster="//cdn.example.com/p"></video><q cite="?q=1">q</q>', 'https://example.com/final/dir/page' );
	ca_check( is_string( $out ) && str_contains( $out, 'https://example.com/final/one?x=1&amp;y=2' ) && str_contains( $out, 'href="#local"' ) && str_contains( $out, 'https://example.com/image' ) && str_contains( $out, 'https://cdn.example.com/p' ) && str_contains( $out, 'https://example.com/final/dir/page?q=1' ) && str_contains( $out, 'café' ), 'resolution failed' );
	ca_check( ! str_contains( $out, '<base' ) && ! str_contains( $out, '<html' ) && ! str_contains( $out, '<body' ), 'fragment/base boundary failed' );
};
$cases['URL schemes and srcset'] = function () {
	$out = Remote_Content_Shortcode_URLs::rewrite( '<a href="mailto:a@example.com">mail</a><a href="javascript:alert(1)">bad</a><img srcset="small.png 1x, ../large.png 2x"><img srcset="data:image/png;base64,AAAA 1x, next.png 2x">', 'https://example.com/dir/page' );
	ca_check( is_string( $out ) && str_contains( $out, 'mailto:a@example.com' ) && ! str_contains( $out, 'javascript:' ) && str_contains( $out, 'https://example.com/dir/small.png 1x' ) && str_contains( $out, 'https://example.com/large.png 2x' ) && ! str_contains( $out, 'data:' ), 'srcset/scheme safety failed' );
};
$cases['URL resource bounds'] = function () {
	ca_check( is_wp_error( Remote_Content_Shortcode_URLs::rewrite( str_repeat( '<a href="x">x</a>', 3000 ), 'https://example.com/' . str_repeat( 'a', 1000 ) . '/' ) ), 'output bound missing' );
	ca_check( is_wp_error( Remote_Content_Shortcode_URLs::rewrite( str_repeat( 'a', 1048577 ), 'https://example.com/' ) ), 'input bound missing' );
	ca_check( is_wp_error( Remote_Content_Shortcode_URLs::rewrite( str_repeat( '<i></i>', 20001 ), 'https://example.com/' ) ), 'DOM work bound missing' );
	ca_check( is_wp_error( Remote_Content_Shortcode_URLs::rewrite( '<a href="x">x</a>', 'javascript:bad' ) ), 'unsafe base accepted' );
};
foreach ( $cases as $name => $case ) { try { $case(); echo "PASS $name\n"; } catch ( Throwable $error ) { ++$failures; echo "FAIL $name: " . $error->getMessage() . "\n"; } }
delete_option( 'rcs_ca_secret' );
if ( $failures ) { WP_CLI::error( "$failures content/auth cases failed" ); }
echo "All content/auth cases passed\n";
