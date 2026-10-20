<?php
/**
 * Regular expression replacement with bounded expansion.
 *
 * @package RemoteContentShortcode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Remote_Content_Shortcode_Regex {
	public static function replace( $pattern, $replacement, $subject ) {
		// Bound capture allocation as well as expanded output. Count all opening parentheses conservatively.
		if ( strlen( $subject ) > 262144 || substr_count( $pattern, '(' ) > 32 ) {
			return new WP_Error( 'regex_input_too_complex' );
		}
		$size = strlen( $subject );
		$count = 0;
		$callback = static function ( $matches ) use ( $replacement, &$size, &$count ) {
			if ( ++$count > 1000 ) {
				throw new LengthException( 'Replacement count exceeded.' );
			}
			$available = 1048576 - ( $size - strlen( $matches[0] ) );
			$result = self::expand( $replacement, $matches, $available );
			$size += strlen( $result ) - strlen( $matches[0] );
			return $result;
		};
		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid user patterns become diagnostic errors.
			$result = @preg_replace_callback( $pattern, $callback, $subject, 1001 );
			return null === $result ? new WP_Error( 'invalid_regex' ) : $result;
		} catch ( LengthException $error ) {
			return new WP_Error( 'regex_output_too_large' );
		}
	}

	private static function expand( $replacement, $matches, $available ) {
		$result = '';
		$length = strlen( $replacement );
		for ( $i = 0; $i < $length; ++$i ) {
			$character = $replacement[ $i ];
			$next = $replacement[ $i + 1 ] ?? '';
			$piece = $character;
			if ( '\\' === $character && ( '\\' === $next || '$' === $next ) ) {
				$piece = $next;
				++$i;
			} elseif ( ( '$' === $character || '\\' === $character ) && 1 === strspn( $next, '0123456789' ) ) {
				$number = $next;
				++$i;
				if ( isset( $replacement[ $i + 1 ] ) && 1 === strspn( $replacement[ $i + 1 ], '0123456789' ) ) {
					$number .= $replacement[ ++$i ];
				}
				$piece = $matches[ (int) $number ] ?? '';
			} elseif ( '$' === $character && '{' === $next && preg_match( '/\G\$\{([0-9]{1,2})\}/', $replacement, $reference, 0, $i ) ) {
				$piece = $matches[ (int) $reference[1] ] ?? '';
				$i += strlen( $reference[0] ) - 1;
			}
			if ( strlen( $result ) + strlen( $piece ) > $available ) {
				throw new LengthException( 'Replacement output exceeded.' );
			}
			$result .= $piece;
		}
		return $result;
	}
}
