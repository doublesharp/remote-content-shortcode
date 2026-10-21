<?php
/** Run each RCS_STARTUP_CASE in a fresh PHP process, without bootstrapping WordPress. */
function sf_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$case = getenv( 'RCS_STARTUP_CASE' );
$direct_files = array(
	'direct-main' => 'remote-content-shortcode.php',
	'direct-html' => 'inc/class-remote-content-html.php',
	'direct-regex' => 'inc/class-remote-content-regex.php',
);
$missing_extensions = array( 'missing-dom' => 'dom', 'missing-curl' => 'curl' );
sf_assert( isset( $direct_files[ $case ] ) || isset( $missing_extensions[ $case ] ) || 'missing-connect-option' === $case, 'Select a supported RCS_STARTUP_CASE.' );
sf_assert( ! defined( 'ABSPATH' ), 'Startup cases require a standalone PHP process.' );

$sf_actions = array();
$sf_translations = array();
function add_action( $hook, $callback ) {
	$GLOBALS['sf_actions'][] = array( $hook, $callback );
}
function add_shortcode( ...$arguments ) {
	throw new RuntimeException( 'Unsupported startup registered a shortcode.' );
}
function add_filter( ...$arguments ) {
	throw new RuntimeException( 'Unsupported startup registered a filter.' );
}
function esc_html__( $text, $domain ) {
	$GLOBALS['sf_translations'][] = array( $text, $domain );
	// A translation can contain markup; the WordPress boundary must escape it.
	return htmlspecialchars( $text . ' <required & "dependency">', ENT_QUOTES, 'UTF-8' );
}

$root = dirname( __DIR__ );
if ( isset( $direct_files[ $case ] ) ) {
	if ( function_exists( 'uopz_allow_exit' ) ) { uopz_allow_exit( true ); }
	$target = realpath( $root . '/' . $direct_files[ $case ] );
	$included_before = get_included_files();
	$autoloaders_before = spl_autoload_functions();
	$returned = false;
	ob_start();
	register_shutdown_function( static function () use ( $case, $target, $included_before, $autoloaders_before, &$returned ) {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) { return; }
		$output = ob_get_clean();
		sf_assert( ! $returned, 'Direct access returned instead of exiting.' );
		sf_assert( '' === $output, 'Direct access produced output.' );
		sf_assert( array() === $GLOBALS['sf_actions'], 'Direct access registered hooks.' );
		sf_assert( array() === $GLOBALS['sf_translations'], 'Direct access rendered an admin notice.' );
		sf_assert( ! class_exists( 'RemoteContentShortcode', false ), 'Direct access declared the plugin runtime.' );
		sf_assert( $autoloaders_before === spl_autoload_functions(), 'Direct access registered an autoloader.' );
		sf_assert( array( $target ) === array_values( array_diff( get_included_files(), $included_before ) ), 'Direct access loaded another source file.' );
		echo 'PASS startup ' . $case . "\n";
	} );
	require $target;
	$returned = true;
	throw new RuntimeException( 'Direct access did not exit.' );
}

sf_assert( function_exists( 'uopz_set_return' ), 'Dependency startup cases require the uopz extension.' );
sf_assert( extension_loaded( 'dom' ) && extension_loaded( 'curl' ) && defined( 'CURLOPT_CONNECT_TO' ), 'The baseline runtime must support DOM and modern cURL.' );
define( 'ABSPATH', $root . '/' );
$probe = isset( $missing_extensions[ $case ] ) ? 'extension_loaded' : 'defined';
if ( 'extension_loaded' === $probe ) {
	$missing = $missing_extensions[ $case ];
	$extensions = array_fill_keys( array_map( 'strtolower', get_loaded_extensions() ), true );
	uopz_set_return( 'extension_loaded', static function ( $name ) use ( $missing, $extensions ) {
		return $missing !== strtolower( $name ) && isset( $extensions[ strtolower( $name ) ] );
	}, true );
} else {
	$constants = get_defined_constants();
	uopz_set_return( 'defined', static function ( $name ) use ( $constants ) {
		return 'CURLOPT_CONNECT_TO' !== $name && array_key_exists( $name, $constants );
	}, true );
}

$included_before = get_included_files();
ob_start();
try {
	require $root . '/remote-content-shortcode.php';
} finally {
	uopz_unset_return( $probe );
	$startup_output = ob_get_clean();
}
sf_assert( '' === $startup_output, 'Unsupported startup emitted output before the notice hook.' );
sf_assert( ! class_exists( 'RemoteContentShortcode', false ), 'Unsupported startup declared the plugin runtime.' );
sf_assert( array( realpath( $root . '/remote-content-shortcode.php' ) ) === array_values( array_diff( get_included_files(), $included_before ) ), 'Unsupported startup loaded runtime helpers.' );
sf_assert( 1 === count( $sf_actions ) && 'admin_notices' === $sf_actions[0][0] && $sf_actions[0][1] instanceof Closure, 'Unsupported startup must register one admin notice.' );
sf_assert( array() === $sf_translations, 'The notice rendered before its hook ran.' );

ob_start();
try {
	$sf_actions[0][1]();
} finally {
	$notice = ob_get_clean();
}
$message = 'Remote Content Shortcode requires PHP 8.2 or later, DOM, and cURL 7.49 or later.';
sf_assert( array( array( $message, 'remote-content-shortcode' ) ) === $sf_translations, 'The notice did not translate the dependency requirements in its text domain.' );
sf_assert( '<div class="notice notice-error"><p>' . $message . ' &lt;required &amp; &quot;dependency&quot;&gt;</p></div>' === $notice, 'The admin notice did not render escaped dependency text.' );
echo 'PASS startup ' . $case . "\n";
