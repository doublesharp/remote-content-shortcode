<?php
/** Server-configured endpoint credentials. @package RemoteContentShortcode */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Remote_Content_Shortcode_Credentials {
	public static function headers( $source, $url, $method ) {
		if ( ! is_array( $source ) || ! is_string( $source['origin'] ?? null ) || ! is_string( $url ) ) { return new WP_Error( 'unconfigured_credentials' ); }
		$expected = wp_parse_url( $source['origin'] );
		$actual = wp_parse_url( $url );
		try {
			foreach ( array( &$expected, &$actual ) as &$parts ) {
				if ( is_array( $parts ) && ! empty( $parts['host'] ) ) { $parts['host'] = WpOrg\Requests\IdnaEncoder::encode( strtolower( $parts['host'] ) ); }
			}
			unset( $parts );
		} catch ( Throwable $error ) { return new WP_Error( 'credential_origin_mismatch' ); }
		if ( ! $expected || ! $actual || 'https' !== ( $expected['scheme'] ?? '' ) || 'https' !== ( $actual['scheme'] ?? '' ) || empty( $expected['host'] ) || empty( $actual['host'] ) || isset( $expected['user'] ) || isset( $expected['pass'] ) || isset( $actual['user'] ) || isset( $actual['pass'] ) || strtolower( $expected['host'] ) !== strtolower( $actual['host'] ) || ( $expected['port'] ?? 443 ) !== ( $actual['port'] ?? 443 ) || isset( $expected['query'] ) || isset( $expected['fragment'] ) || ! in_array( $expected['path'] ?? '', array( '', '/' ), true ) ) { return new WP_Error( 'credential_origin_mismatch' ); }
		$path = $source['path'] ?? null;
		$methods = $source['methods'] ?? array( 'GET' );
		if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] || preg_match( '/[?#\\\\\x00-\x20]/', $path ) || ! is_array( $methods ) || ! in_array( $method, $methods, true ) ) { return new WP_Error( 'credential_endpoint_mismatch' ); }
		try {
			$configured = new WpOrg\Requests\Iri( 'https://endpoint.invalid' . $path );
			$endpoint = new WpOrg\Requests\Iri( $url );
			if ( $configured->path !== $endpoint->path ) { return new WP_Error( 'credential_endpoint_mismatch' ); }
		} catch ( Throwable $error ) { return new WP_Error( 'credential_endpoint_mismatch' ); }
		$key = $source['key'] ?? null;
		if ( ! is_string( $key ) || '' === $key ) { return new WP_Error( 'invalid_credentials' ); }
		switch ( $source['type'] ?? '' ) {
			case 'connection': $value = Remote_Content_Shortcode_Connections::secret( $key, $source ); break;
			case 'provider': $value = apply_filters( 'remote_content_shortcode_credential_value', new WP_Error( 'invalid_credentials' ), $source, $url, $method ); break;
			case 'constant': $value = defined( $key ) ? constant( $key ) : false; break;
			case 'option': $value = get_option( $key ); break;
			case 'post_meta':
				// Secrets must belong to a fixed administrator-configured post, never the rendering context.
				$post_id = $source['post_id'] ?? null;
				if ( ! is_int( $post_id ) || $post_id < 1 || ! is_protected_meta( $key, 'post' ) ) { return new WP_Error( 'invalid_credentials' ); }
				$post_type = get_post_type( $post_id );
				if ( ! $post_type ) { return new WP_Error( 'invalid_credentials' ); }
				foreach ( array( '', $post_type ) as $subtype ) {
					$registered = get_registered_meta_keys( 'post', $subtype );
					if ( ! empty( $registered[ $key ]['show_in_rest'] ) ) { return new WP_Error( 'invalid_credentials' ); }
				}
				$value = get_post_meta( $post_id, $key, true );
				break;
			default: return new WP_Error( 'invalid_credentials' );
		}
		if ( is_wp_error( $value ) ) { return $value; }
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 8192 || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) { return new WP_Error( 'invalid_credentials' ); }
		switch ( $source['auth'] ?? 'basic' ) {
			case 'basic': return str_contains( $value, ':' ) ? array( 'Authorization' => 'Basic ' . base64_encode( $value ) ) : new WP_Error( 'invalid_credentials' );
			case 'bearer': return ! preg_match( '/\s/', $value ) ? array( 'Authorization' => 'Bearer ' . $value ) : new WP_Error( 'invalid_credentials' );
			case 'api_key':
				$header = $source['header'] ?? 'X-API-Key';
				if ( ! is_string( $header ) || strlen( $header ) > 64 || ! preg_match( '/^(?:X-[A-Za-z0-9-]+|Api-Key)$/iD', $header ) || preg_match( '/^X-(?:Forwarded(?:-|$)|Original(?:-|$)|Rewrite(?:-|$)|HTTP-Method(?:-|$)|Method-Override$|(?:Real|Client|Originating|Cluster-Client|True-Client|Connecting|CF-Connecting)-IP$|Remote-(?:Addr|IP)$|Via$|Host$|Cookie$|Proxy(?:-|$))/i', $header ) ) { return new WP_Error( 'invalid_credentials' ); }
				return array( $header => $value );
			default: return new WP_Error( 'invalid_credentials' );
		}
	}
}
