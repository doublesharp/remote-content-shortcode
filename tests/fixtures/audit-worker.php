<?php
/** One anonymous worker for the isolated admission regression. */
require $argv[1] . 'wp-load.php';
require_once getenv( 'RCS_PLUGIN_FILE' ) ?: dirname( __DIR__, 2 ) . '/remote-content-shortcode.php';
wp_set_current_user( 0 ); $_GET['v'] = $argv[2]; $busy = false;
add_action( 'remote_content_shortcode_error', static function ( $error ) use ( &$busy ) { $busy = 'request_busy' === $error->get_error_code(); } );
add_filter( 'pre_http_request', static function () {
	echo "READY\n"; fflush( STDOUT ); stream_set_timeout( STDIN, 3 );
	if ( "release\n" !== fgets( STDIN ) ) { throw new RuntimeException( 'Worker barrier timed out.' ); }
	return array( 'body' => 'fixed', 'headers' => array(), 'response' => array( 'code' => 200 ) );
} );
$output = RemoteContentShortcode::get_instance()->remote_content_shortcode( array( 'url' => 'https://8.8.8.8/cold', 'params' => 'v' ) );
if ( 'fixed' === $output ) { echo "PASS\n"; }
elseif ( '' === $output && $busy ) { echo "BUSY\n"; }
else { throw new RuntimeException( 'Unexpected worker result.' ); }
