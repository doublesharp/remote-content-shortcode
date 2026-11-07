<?php
/** Accounting shared by shortcode, block, compatibility and snapshot renders. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Remote_Content_Shortcode_Budget {
	private static $renders = 0;
	private static $fetches = 0;
	private static $bytes = 0;
	private static $deadline = null;
	/** Long-lived workers must reset this at an actual request boundary. */
	public static function reset() { self::$renders = 0; self::$fetches = 0; self::$bytes = 0; self::$deadline = null; }
	public static function render() {
		self::$deadline = self::$deadline ?? ( microtime( true ) + 30 );
		return ++self::$renders <= 32 && microtime( true ) < self::$deadline;
	}
	public static function fetch( $timeout ) {
		self::$deadline = self::$deadline ?? ( microtime( true ) + 30 );
		return ++self::$fetches <= 8 ? min( self::$deadline, microtime( true ) + $timeout ) : false;
	}
	public static function output( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) > 1048576 || self::$bytes + strlen( $html ) > 4194304 ) { return false; }
		self::$bytes += strlen( $html );
		return true;
	}
}
