<?php
/** Dynamic block using the shortcode renderer. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Remote_Content_Shortcode_Block {
	public static function register() { add_action( 'init', array( __CLASS__, 'register_block' ) ); }
	public static function register_block() {
		register_block_type( dirname( __DIR__ ) . '/blocks/remote-content', array( 'render_callback' => array( __CLASS__, 'render' ) ) );
	}
	public static function render( $attributes ) {
		$atts = array();
		foreach ( array( 'url', 'selector', 'remove', 'fallback', 'userpwd' ) as $key ) {
			if ( isset( $attributes[ $key ] ) && is_string( $attributes[ $key ] ) ) { $atts[ $key ] = $attributes[ $key ]; }
		}
		foreach ( array( 'cache' => 'cache', 'rewriteUrls' => 'rewrite_urls' ) as $key => $target ) {
			if ( isset( $attributes[ $key ] ) && is_bool( $attributes[ $key ] ) ) { $atts[ $target ] = $attributes[ $key ] ? 'true' : 'false'; }
		}
		foreach ( array( 'cacheTtl' => 'cache_ttl', 'staleTtl' => 'stale_ttl', 'timeout' => 'timeout' ) as $key => $target ) {
			if ( isset( $attributes[ $key ] ) && is_numeric( $attributes[ $key ] ) ) { $atts[ $target ] = (string) max( 0, (float) $attributes[ $key ] ); }
		}
		return RemoteContentShortcode::get_instance()->remote_content_shortcode( $atts );
	}
}
