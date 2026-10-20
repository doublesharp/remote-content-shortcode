<?php
/**
 * CSS selection using native DOM and the isolated Symfony CssSelector component.
 *
 * @package RemoteContentShortcode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register( static function ( $class ) {
	$prefix = 'RemoteContentShortcode\\Vendor\\Symfony\\Component\\CssSelector\\';
	if ( 0 === strpos( $class, $prefix ) ) {
		$file = __DIR__ . '/vendor/css-selector/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
} );

/** Transform HTML without loading external resources or evaluating PHP. */
class Remote_Content_Shortcode_HTML {
	public static function transform( $html, $selector, $remove, &$selection = null ) {
		$selection = array( 'matches' => null );
		if ( '' === $html ) {
			return '';
		}
		$previous = libxml_use_internal_errors( true );
		try {
			$document = new DOMDocument( '1.0', 'UTF-8' );
			// Force UTF-8 without allowing remote declarations to choose an executable parser mode.
			$document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
			// Budget the document before any potentially expensive selector XPath is evaluated.
			$node = $document->documentElement;
			$level = 1;
			$elements = 0;
			$depth = 0;
			while ( $node && $level > 0 ) {
				if ( XML_ELEMENT_NODE === $node->nodeType ) {
					++$elements;
					$depth = max( $depth, $level );
					if ( $elements > 2000 || $depth > 32 ) {
						return new WP_Error( 'html_too_complex' );
					}
				}
				if ( $node->firstChild ) {
					$node = $node->firstChild;
					++$level;
					continue;
				}
				while ( $node && ! $node->nextSibling ) {
					$node = $node->parentNode;
					--$level;
				}
				$node = $node ? $node->nextSibling : null;
			}
			$work = 0;
			$queries = array();
			$converter = new RemoteContentShortcode\Vendor\Symfony\Component\CssSelector\CssSelectorConverter();
			foreach ( array( $selector, $remove ) as $expression ) {
				if ( '' === $expression ) {
					continue;
				}
				// Conservatively count combinators, groups and pseudo-classes, even inside literals.
				$steps = 1 + preg_match_all( '/[\s>+~,:()]/', $expression );
				if ( strlen( $expression ) > 256 || $steps > 16 ) {
					return new WP_Error( 'selector_too_complex' );
				}
				$query = $converter->toXPath( $expression );
				$queries[ $expression ] = $query;
				// Inspect generated axes rather than CSS punctuation, which can occur in literals.
				$axes = preg_replace( '/\'[^\']*\'|"[^"]*"/', '', $query );
				$axes = preg_replace( '/(?:^|\|)\s*descendant-or-self::/', '', $axes );
				if ( preg_match( '/(?:following|preceding)-sibling::/', $axes ) ) {
					// libxml merges overlapping sibling sets with duplicate scans: cubic worst case.
					$work += $elements * $elements * $elements * $steps;
				} elseif ( preg_match( '/(?:descendant(?:-or-self)?|ancestor(?:-or-self)?|parent|following|preceding)::/', $axes ) ) {
					$work += $elements * $elements * $depth * $steps;
				} else {
					$work += $elements * $depth * $steps + $elements * $elements * substr_count( $axes, '|' );
				}
			}
			if ( $work > 32000000 ) {
				return new WP_Error( 'selector_too_complex' );
			}
			$xpath = new DOMXPath( $document );
			if ( '' !== $remove ) {
				$nodes = $xpath->query( $queries[ $remove ] );
				if ( false === $nodes ) {
					return new WP_Error( 'invalid_selector' );
				}
				foreach ( iterator_to_array( $nodes ) as $node ) {
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			}
			if ( '' !== $selector ) {
				$output = '';
				$nodes = $xpath->query( $queries[ $selector ] );
				if ( false === $nodes ) {
					return new WP_Error( 'invalid_selector' );
				}
				$selection['matches'] = $nodes->length;
				foreach ( $nodes as $node ) {
					$fragment = $document->saveHTML( $node );
					if ( strlen( $output ) + strlen( $fragment ) > 1048576 ) {
						return new WP_Error( 'output_too_large' );
					}
					$output .= $fragment;
				}
				return $output;
			}
			// Drop our encoding processing instruction from whole-document output.
			foreach ( iterator_to_array( $document->childNodes ) as $node ) {
				if ( XML_PI_NODE === $node->nodeType ) {
					$document->removeChild( $node );
				}
			}
			$output = $document->saveHTML();
			return strlen( $output ) > 1048576 ? new WP_Error( 'output_too_large' ) : $output;
		} catch ( Throwable $error ) {
			return new WP_Error( 'invalid_selector' );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}
}
