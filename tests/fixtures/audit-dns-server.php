<?php
/** Tiny numeric-address DNS fixture; run only inside the isolated audit namespace. */
$socket = stream_socket_server( 'udp://127.0.0.1:53', $errno, $error, STREAM_SERVER_BIND );
if ( ! $socket ) { throw new RuntimeException( 'DNS fixture bind failed.' ); }
$tcp = stream_socket_server( 'tcp://127.0.0.1:53', $errno, $error );
if ( ! $tcp ) { throw new RuntimeException( 'TCP DNS fixture bind failed.' ); }
stream_set_timeout( $socket, 8 ); echo "READY\n"; fflush( STDOUT );
for ( $request = 0; $request < 512; ++$request ) {
	$read = array( $socket, $tcp ); $write = array(); $except = array();
	if ( ! stream_select( $read, $write, $except, 8 ) ) { break; }
	$client = null; $peer = '';
	if ( in_array( $tcp, $read, true ) ) {
		$client = stream_socket_accept( $tcp, 1 ); stream_set_timeout( $client, 2 );
		$length = unpack( 'n', fread( $client, 2 ) )[1]; $query = '';
		while ( strlen( $query ) < $length ) { $part = fread( $client, $length - strlen( $query ) ); if ( ! $part ) { break; } $query .= $part; }
	} else { $query = stream_socket_recvfrom( $socket, 4096, 0, $peer ); }
	if ( ! $query || strlen( $query ) < 17 ) { break; }
	$offset = 12; $labels = array();
	while ( isset( $query[ $offset ] ) && ord( $query[ $offset ] ) ) {
		$size = ord( $query[ $offset++ ] ); $labels[] = substr( $query, $offset, $size ); $offset += $size;
	}
	$name = implode( '.', $labels ); $type = unpack( 'n', substr( $query, $offset + 1, 2 ) )[1];
	if ( 'timeout.rcs.test' === $name ) { usleep( 1500000 ); }
	$address = 'private.rcs.test' === $name ? '127.0.0.1' : '8.8.8.8';
	if ( 28 === $type && in_array( $name, array( 'mixed-stack.rcs.test', 'dual-stack.rcs.test', 'ipv6-only.rcs.test' ), true ) ) { $address = 'mixed-stack.rcs.test' === $name ? '::1' : '2606:4700:4700::1111'; }
	$has_answer = ( 1 === $type && 'ipv6-only.rcs.test' !== $name ) || ( 28 === $type && str_contains( $address, ':' ) );
	$answer = $has_answer ? "\xc0\x0c" . pack( 'nnNn', $type, 1, 0, strlen( inet_pton( $address ) ) ) . inet_pton( $address ) : '';
	$flags = 'aaaa-failure.rcs.test' === $name && 28 === $type ? 33154 : 33152;
	$count = $has_answer ? 1 : 0;
	if ( 'cname.rcs.test' === $name || 'cname-chain.rcs.test' === $name ) {
		$alias = "\x06source\x03rcs\x04test\x00";
		$answer = "\xc0\x0c" . pack( 'nnNn', 5, 1, 0, strlen( $alias ) ) . $alias;
		$count = 1;
		if ( 'cname.rcs.test' === $name && 1 === $type ) { $answer .= $alias . pack( 'nnNn', 1, 1, 0, 4 ) . inet_pton( $address ); ++$count; }
	}
	if ( 'tcp.rcs.test' === $name && ! $client ) { $flags |= 512; $answer = ''; $count = 0; }
	$packet = substr( $query, 0, 2 ) . pack( 'nnnnn', $flags, 1, $count, 0, 0 ) . substr( $query, 12 ) . $answer;
	if ( 'wrong-id.rcs.test' === $name ) { $packet[0] = chr( ord( $packet[0] ) ^ 1 ); }
	if ( $client ) { fwrite( $client, pack( 'n', strlen( $packet ) ) . $packet ); fclose( $client ); }
	else { stream_socket_sendto( $socket, $packet, 0, $peer ); }
}
fclose( $socket ); fclose( $tcp );
