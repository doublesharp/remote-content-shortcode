<?php
/** Remove remote presentation authority and require an administrator asset grant. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Remote_Content_Shortcode_Output {
	public static function sanitize( $html, $base ) {
		if ( ! is_string( $html ) ) { return new WP_Error( 'invalid_output' ); }
		if ( strlen( $html ) > 1048576 ) { return new WP_Error( 'output_too_large' ); }
		// KSES first: the processor sees only post-safe tags and normalized attributes.
		$html = wp_kses_post( $html );
		$processor = new WP_HTML_Tag_Processor( $html );
		$origins = apply_filters( 'remote_content_shortcode_resource_origins', array(), $base );
		$origins = is_array( $origins ) ? array_slice( $origins, 0, 16 ) : array();
		$resources = 0; $tags = 0;
		while ( $processor->next_tag() ) {
			if ( ++$tags > 20000 ) { return new WP_Error( 'html_too_complex' ); }
			foreach ( array( 'style', 'class', 'id', 'srcset', 'background', 'ping' ) as $attribute ) { $processor->remove_attribute( $attribute ); }
			foreach ( array( 'src', 'poster' ) as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( null === $value ) { continue; }
				$url = is_string( $value ) ? self::resource( $value, $base, $origins ) : false;
				if ( false === $url || ++$resources > 32 ) { $processor->remove_attribute( $attribute ); }
				else { $processor->set_attribute( $attribute, $url ); }
			}
		}
		$html = $processor->get_updated_html();
		return strlen( $html ) <= 1048576 ? wp_kses_post( $html ) : new WP_Error( 'output_too_large' );
	}
	private static function resource( $value, $base, $origins ) {
		if ( ! $origins || strlen( $value ) > 8192 || preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) ) { return false; }
		try { $resolved = WpOrg\Requests\Iri::absolutize( $base, $value ); $url = $resolved ? (string) $resolved->uri : ''; }
		catch ( Throwable $error ) { return false; }
		$parts = wp_parse_url( $url );
		if ( strlen( $url ) > 8192 || ! $parts || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return false; }
		$host = strtolower( trim( $parts['host'], '[]' ) );
		if ( ! str_contains( $host, '.' ) || preg_match( '/(?:^|\.)(?:localhost|local|internal|test|invalid)$/i', $host ) || ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE ) ) || $host === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) { return false; }
		$origin = 'https://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) && 443 !== $parts['port'] ? ':' . $parts['port'] : '' );
		return in_array( $origin, $origins, true ) ? $url : false;
	}
}
