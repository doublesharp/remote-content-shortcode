<?php
/** Separate PHP workers sharing the disposable WordPress database. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once getenv( 'RCS_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/remote-content-shortcode.php';
function ac_start( $value ) {
	$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/audit-worker.php', ABSPATH, $value ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => STDERR ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Worker start failed.' ); }
	stream_set_timeout( $pipes[1], 4 ); return array( $process, $pipes );
}
function ac_read( $worker, $expected ) {
	$actual = fgets( $worker[1][1] );
	if ( $expected . "\n" !== $actual ) { throw new RuntimeException( 'Worker expected ' . $expected . ', received ' . trim( (string) $actual ) ); }
}
function ac_close( $worker ) {
	foreach ( $worker[1] as $pipe ) { fclose( $pipe ); }
	if ( 0 !== proc_close( $worker[0] ) ) { throw new RuntimeException( 'Worker failed.' ); }
}
$workers = array();
try {
	Remote_Content_Shortcode_Cache::clear();
	$first = ac_start( 'same' ); $workers[] = $first; ac_read( $first, 'READY' );
	$second = ac_start( 'same' ); $workers[] = $second; ac_read( $second, 'BUSY' );
	fwrite( $first[1][0], "release\n" ); ac_read( $first, 'PASS' );
	foreach ( $workers as $worker ) { ac_close( $worker ); } $workers = array();
	echo "PASS concurrent identical cold requests admit only one outbound fetch\n";
	Remote_Content_Shortcode_Cache::clear();
	foreach ( array( 'a', 'b', 'c' ) as $value ) { $worker = ac_start( $value ); $workers[] = $worker; ac_read( $worker, 'READY' ); }
	foreach ( $workers as $worker ) { fwrite( $worker[1][0], "release\n" ); }
	foreach ( $workers as $worker ) { ac_read( $worker, 'PASS' ); ac_close( $worker ); } $workers = array();
	echo "PASS distinct requests use independent admission slots\n";
} finally {
	foreach ( $workers as $worker ) {
		foreach ( $worker[1] as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
		if ( is_resource( $worker[0] ) ) { proc_terminate( $worker[0] ); proc_close( $worker[0] ); }
	}
}
