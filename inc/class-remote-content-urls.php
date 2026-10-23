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
		// Keep unsupported data URLs and quoted/parenthesized forms out of this bounded parser.
		if ( strlen( $value ) > 32768 || preg_match( '/data:|["\'()]/i', $value ) ) { return null; }
		$output = array(); $position = 0; $length = strlen( $value ); $space = " \t\n\r\f";
		while ( $position < $length ) {
			$position += strspn( $value, $space, $position );
			if ( $position === $length ) { break; }
			if ( count( $output ) >= 100 || ',' === $value[ $position ] ) { return null; }
			// HTML srcset URLs extend to whitespace. Internal commas belong to the URL.
			$end = $position + strcspn( $value, $space, $position );
			$reference = substr( $value, $position, $end - $position ); $position = $end; $descriptor = '';
			if ( str_ends_with( $reference, ',' ) ) {
				$reference = substr( $reference, 0, -1 );
				if ( str_ends_with( $reference, ',' ) ) { return null; }
			} else {
				$comma = strpos( $value, ',', $position );
				$end = false === $comma ? $length : $comma;
				$descriptor = trim( substr( $value, $position, $end - $position ), $space );
				if ( '' !== $descriptor && ! preg_match( '/^(?:[1-9][0-9]*w|(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)x)$/D', $descriptor ) ) { return null; }
				$position = false === $comma ? $length : $comma + 1;
				if ( false !== $comma && '' === trim( substr( $value, $position ), $space ) ) { return null; }
			}
			$url = self::resolve( $reference, $base );
			if ( null === $url ) { return null; }
			$output[] = $url . ( '' !== $descriptor ? ' ' . $descriptor : '' );
		}
		return $output ? implode( ', ', $output ) : null;
	}
}
