<?php
/** Bounded fragment URL rewriting; callers must still apply KSES. @package RemoteContentShortcode */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Remote_Content_Shortcode_URLs {
	public static function rewrite( $html, $effective_url ) {
		if ( ! is_string( $html ) || strlen( $html ) > 1048576 || substr_count( $html, '<' ) > 20000 ) { return new WP_Error( 'html_too_complex' ); }
		if ( ! is_string( $effective_url ) || strlen( $effective_url ) > 8192 || ! self::http_url( $effective_url ) ) { return new WP_Error( 'invalid_url_base' ); }
		if ( '' === $html ) { return ''; }
		$previous = libxml_use_internal_errors( true );
		try {
			$document = new DOMDocument( '1.0', 'UTF-8' );
			if ( ! $document->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) { return new WP_Error( 'invalid_html' ); }
			$elements = $document->getElementsByTagName( '*' );
			if ( $elements->length > 20000 ) { return new WP_Error( 'html_too_complex' ); }
			$remove = array();
			$output_budget = strlen( $html );
			foreach ( $elements as $element ) {
				if ( 'base' === strtolower( $element->tagName ) ) { $remove[] = $element; continue; }
				foreach ( array( 'href', 'src', 'poster', 'cite', 'srcset' ) as $attribute ) {
					if ( ! $element->hasAttribute( $attribute ) ) { continue; }
					$value = $element->getAttribute( $attribute );
					$rewritten = 'srcset' === $attribute ? self::srcset( $value, $effective_url ) : self::resolve( $value, $effective_url, 'href' === $attribute );
					if ( null === $rewritten ) { $element->removeAttribute( $attribute ); } else {
						$output_budget += max( 0, strlen( $rewritten ) - strlen( $value ) );
						if ( $output_budget > 1048576 ) { return new WP_Error( 'html_too_large' ); }
						$element->setAttribute( $attribute, $rewritten );
					}
				}
			}
			foreach ( $remove as $node ) { $node->parentNode->removeChild( $node ); }
			$output = '';
			$body = $document->getElementsByTagName( 'body' )->item( 0 );
			if ( ! $body ) { return new WP_Error( 'invalid_html' ); }
			foreach ( $body->childNodes as $node ) {
				$output .= $document->saveHTML( $node );
				if ( strlen( $output ) > 1048576 ) { return new WP_Error( 'html_too_large' ); }
			}
			return $output;
		} catch ( Throwable $error ) { return new WP_Error( 'invalid_html' ); }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
	}
	private static function http_url( $url ) {
		$parts = wp_parse_url( $url );
		return $parts && in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) && ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! preg_match( '/[\x00-\x20\x7f\\\\]/', $url );
	}
	private static function resolve( $value, $base, $allow_contact = false ) {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 8192 || preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) ) { return null; }
		if ( '#' === $value[0] ) { return $value; }
		if ( $allow_contact && preg_match( '/^(?:mailto|tel):/i', $value ) ) { return $value; }
		if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $value, $match ) && ! in_array( strtolower( $match[1] ), array( 'http', 'https' ), true ) ) { return null; }
		try {
			$absolute = WpOrg\Requests\Iri::absolutize( $base, $value );
			$url = $absolute ? (string) $absolute->uri : '';
			return strlen( $url ) <= 8192 && self::http_url( $url ) ? $url : null;
		} catch ( Throwable $error ) { return null; }
	}
	private static function srcset( $value, $base ) {
		// Commas inside data URLs or quoted URLs are ambiguous; fail closed for the whole attribute.
		if ( strlen( $value ) > 32768 || preg_match( '/data:|["\'()]/i', $value ) ) { return null; }
		$candidates = explode( ',', $value );
		if ( count( $candidates ) > 100 ) { return null; }
		$output = array();
		foreach ( $candidates as $candidate ) {
			if ( ! preg_match( '/^\s*(\S+?)(?:\s+((?:[1-9][0-9]*w)|(?:(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)x)))?\s*$/D', $candidate, $match ) ) { return null; }
			$url = self::resolve( $match[1], $base );
			if ( null === $url ) { return null; }
			$output[] = $url . ( isset( $match[2] ) && '' !== $match[2] ? ' ' . $match[2] : '' );
		}
		return implode( ', ', $output );
	}
}
