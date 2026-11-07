<?php
/** Actual pinned cURL/Requests checks against bounded isolated-loopback responses. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once getenv( 'RCS_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/remote-content-shortcode.php';
$plugin = RemoteContentShortcode::get_instance(); $request = new ReflectionMethod( $plugin, 'pinned_request' );
$gzip = gzencode( str_repeat( 'x', 1048576 ) );
$encoded = "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: " . strlen( $gzip ) . "\r\nConnection: close\r\n\r\n" . $gzip;
$cases = array(
	'ordinary response' => array( "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok", '' ),
	'encoded response' => array( $encoded, 'Encoded responses' ),
	'informational and encoded response' => array( "HTTP/1.1 103 Early Hints\r\nLink: </inert>\r\n\r\n" . $encoded, 'Encoded responses' ),
	'aggregate header bytes' => array( "HTTP/1.1 200 OK\r\n" . str_repeat( 'X-Inert: ' . str_repeat( 'x', 1000 ) . "\r\n", 70 ) . "Content-Length: 1\r\n\r\nx", 'Response headers exceeded' ),
	'header field count' => array( "HTTP/1.1 200 OK\r\n" . str_repeat( "X-Inert: x\r\n", 129 ) . "Content-Length: 1\r\n\r\nx", 'Response headers exceeded' ),
);
foreach ( $cases as $name => list( $raw, $error ) ) {
	$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/audit-http-server.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => STDERR ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'HTTP fixture did not start.' ); }
	try {
		fwrite( $pipes[0], $raw ); fclose( $pipes[0] ); stream_set_timeout( $pipes[1], 3 ); $port = (int) fgets( $pipes[1] );
		if ( ! $port ) { throw new RuntimeException( 'HTTP fixture did not bind.' ); }
		$result = $request->invoke( $plugin, 'http://fixture.invalid:' . $port . '/', array( 'method' => 'GET', 'timeout' => 2 ), '127.0.0.1', microtime( true ) + 2 );
		if ( '' === $error ? ( is_wp_error( $result ) || 'ok' !== $result['body'] ) : ( ! is_wp_error( $result ) || ! str_contains( $result->get_error_message(), $error ) ) ) {
			throw new RuntimeException( 'Transport case failed: ' . $name . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'unexpected response' ) );
		}
		echo "PASS $name\n";
	} finally { fclose( $pipes[1] ); proc_terminate( $process ); proc_close( $process ); }
}
