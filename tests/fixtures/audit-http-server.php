<?php
/** Single-response harmless loopback fixture, with at most 128 KiB on the wire. */
$raw = stream_get_contents( STDIN, 131072 );
$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
if ( ! $server ) { throw new RuntimeException( 'HTTP fixture bind failed.' ); }
echo substr( strrchr( stream_socket_get_name( $server, false ), ':' ), 1 ) . "\n"; fflush( STDOUT );
$client = @stream_socket_accept( $server, 3 );
if ( $client ) {
	stream_set_timeout( $client, 2 ); $bytes = 0;
	while ( false !== ( $line = fgets( $client, 8192 ) ) ) { $bytes += strlen( $line ); if ( "\r\n" === $line || $bytes > 8192 ) { break; } }
	for ( $offset = 0; $offset < strlen( $raw ); ) { $written = @fwrite( $client, substr( $raw, $offset, 8192 ) ); if ( ! $written ) { break; } $offset += $written; }
	fclose( $client );
}
fclose( $server );
