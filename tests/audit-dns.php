<?php
/** Real UDP DNS and end-to-end deadline regressions in the isolated local fixture. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'REMOTE_CONTENT_DNS_SERVERS' ) ) { define( 'REMOTE_CONTENT_DNS_SERVERS', array( '127.0.0.1' ) ); }
require_once getenv( 'RCS_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/remote-content-shortcode.php';
$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/audit-dns-server.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => STDERR ), $pipes );
if ( ! is_resource( $process ) ) { throw new RuntimeException( 'DNS fixture failed to start.' ); }
try {
	stream_set_timeout( $pipes[1], 3 );
	if ( "READY\n" !== fgets( $pipes[1] ) ) { throw new RuntimeException( 'DNS fixture was not ready.' ); }
	$plugin = RemoteContentShortcode::get_instance(); $address = new ReflectionMethod( $plugin, 'public_address' );
	foreach ( array( 'source.rcs.test' => '8.8.8.8', 'private.rcs.test' => false, 'mixed-stack.rcs.test' => false, 'aaaa-failure.rcs.test' => false, 'dual-stack.rcs.test' => '8.8.8.8', 'ipv6-only.rcs.test' => false, 'tcp.rcs.test' => '8.8.8.8', 'cname.rcs.test' => '8.8.8.8', 'cname-chain.rcs.test' => '8.8.8.8', 'wrong-id.rcs.test' => false ) as $host => $expected ) {
		if ( $address->invoke( $plugin, 'http://' . $host . '/', microtime( true ) + 1 ) !== $expected ) { throw new RuntimeException( 'DNS validation failed: ' . $host ); }
		echo "PASS DNS address policy $host\n";
	}
	$name = new ReflectionMethod( Remote_Content_Shortcode_DNS::class, 'name' );
	foreach ( array( "\xc0\x00", "\xc0\xff", "\x40", "\x03ab" ) as $malformed ) {
		$offset = 0; $args = array( $malformed, &$offset );
		if ( false !== $name->invokeArgs( null, $args ) ) { throw new RuntimeException( 'Malformed or cyclic name was accepted.' ); }
	}
	echo "PASS malformed and cyclic compressed DNS names\n";
	$codes = array(); $calls = 0;
	add_action( 'remote_content_shortcode_error', static function ( $e ) use ( &$codes ) { $codes[] = $e->get_error_code(); } );
	add_filter( 'pre_http_request', static function ( $pre ) use ( &$calls ) { ++$calls; return $pre; } );
	$start = microtime( true );
	$output = $plugin->remote_content_shortcode( array( 'url' => 'http://timeout.rcs.test/', 'timeout' => 1, 'cache' => 'false' ) );
	$elapsed = microtime( true ) - $start;
	if ( '' !== $output || 0 !== $calls || ! in_array( 'request_timeout', $codes, true ) || $elapsed > 1.25 ) { throw new RuntimeException( 'DNS exceeded the request deadline: ' . $elapsed ); }
	echo "PASS one-second deadline includes delayed DNS; no HTTP request was dispatched\n";
} finally { foreach ( $pipes as $pipe ) { fclose( $pipe ); } proc_terminate( $process ); proc_close( $process ); }
