<?php
/** Entity decoding applies once to fallback HTML, with final KSES and size bounds. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$render = static fn( $atts ) => RemoteContentShortcode::get_instance()->remote_content_shortcode( array_merge( array( 'url' => '', 'cache' => 'false' ), $atts ) );
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$encoded = '&quot;&lt;strong&gt;Offline&lt;/strong&gt;&lt;img src=x onerror=alert(1)&gt;&quot;';
$out = $render( array( 'decode_atts' => '&quot;true&quot;', 'fallback' => $encoded ) );
$assert( str_starts_with( $out, '<strong>Offline</strong>' ) && ! str_contains( $out, 'onerror' ) && ! str_contains( $out, '&lt;strong&gt;' ), 'Encoded fallback was not decoded, unquoted, and sanitized.' );
$out = $render( array( 'decode_atts' => 'true', 'fallback' => '&amp;lt;strong&amp;gt;Offline&amp;lt;/strong&amp;gt;' ) );
$assert( '&lt;strong&gt;Offline&lt;/strong&gt;' === $out, 'Fallback was decoded more than once.' );
$out = $render( array( 'fallback' => '&lt;strong&gt;Offline&lt;/strong&gt;' ) );
$assert( '&lt;strong&gt;Offline&lt;/strong&gt;' === $out, 'Fallback was decoded without opt-in.' );
$assert( '0' === $render( array( 'decode_atts' => 'true', 'fallback' => '&quot;0&quot;' ) ), 'Zero-valued fallback was lost.' );
$assert( str_repeat( 'x', 4096 ) === $render( array( 'decode_atts' => 'true', 'fallback' => str_repeat( '&#120;', 4096 ) ) ), 'Fallback limit was checked before normalization.' );
$assert( '' === $render( array( 'decode_atts' => 'true', 'fallback' => str_repeat( '&#120;', 4097 ) ) ), 'Oversized decoded fallback was accepted.' );
$assert( '<strong>Offline</strong>' === $render( array( 'url' => array(), 'fallback' => '<strong>Offline</strong>' ) ), 'Malformed attributes no longer use a safe fallback.' );
echo "PASS fallback attributes decode once, preserve opt-in and zero values, sanitize markup, and enforce the decoded size limit\n";
