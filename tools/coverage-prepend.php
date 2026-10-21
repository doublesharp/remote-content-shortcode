<?php
/** Collect first-party PHP coverage before WordPress or a standalone test loads. */
if ( ! extension_loaded( 'xdebug' ) || ! in_array( 'coverage', xdebug_info( 'mode' ), true ) ) { throw new RuntimeException( 'Xdebug coverage mode is required.' ); }
$rcs_coverage_files = json_decode( file_get_contents( '/coverage/files.json' ), true, 512, JSON_THROW_ON_ERROR );
$rcs_coverage_roots = array( '/wordpress/wp-content/plugins/remote-content-shortcode/', '/source/' );
if ( file_exists( '/coverage/profile.json' ) ) {
	$rcs_coverage_profile = json_decode( file_get_contents( '/coverage/profile.json' ), false, 512, JSON_THROW_ON_ERROR );
	if ( ! $rcs_coverage_profile instanceof stdClass || ! isset( $rcs_coverage_profile->prefixes ) || ! is_array( $rcs_coverage_profile->prefixes ) || ! $rcs_coverage_profile->prefixes ) {
		throw new RuntimeException( 'Coverage profile prefixes must be a nonempty list.' );
	}
	foreach ( $rcs_coverage_profile->prefixes as $root ) {
		if ( ! is_string( $root ) || ! str_starts_with( $root, '/' ) || ! str_ends_with( $root, '/' ) || str_contains( $root, '\\' ) || preg_match( '/[\x00-\x1f\x7f]/', $root ) ) {
			throw new RuntimeException( 'Coverage profile prefixes must be absolute normalized trailing-slash roots.' );
		}
		foreach ( explode( '/', substr( $root, 1, -1 ) ) as $part ) {
			if ( in_array( $part, array( '', '.', '..' ), true ) ) { throw new RuntimeException( 'Coverage profile roots cannot contain empty or relative components.' ); }
		}
	}
	if ( count( array_unique( $rcs_coverage_profile->prefixes ) ) !== count( $rcs_coverage_profile->prefixes ) ) { throw new RuntimeException( 'Coverage profile prefixes must be unique.' ); }
	$rcs_coverage_roots = $rcs_coverage_profile->prefixes;
}
$rcs_coverage_paths = array();
foreach ( $rcs_coverage_roots as $root ) {
	foreach ( $rcs_coverage_files as $file ) { $rcs_coverage_paths[] = $root . $file; }
}
xdebug_set_filter( XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, $rcs_coverage_paths );
xdebug_start_code_coverage( XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE | XDEBUG_CC_BRANCH_CHECK );
register_shutdown_function( static function () use ( $rcs_coverage_files, $rcs_coverage_roots ) {
	$coverage = array_filter( xdebug_get_code_coverage(), static function ( $path ) use ( $rcs_coverage_files, $rcs_coverage_roots ) {
		foreach ( $rcs_coverage_roots as $root ) {
			if ( str_starts_with( $path, $root ) && in_array( substr( $path, strlen( $root ) ), $rcs_coverage_files, true ) ) { return true; }
		}
		return false;
	}, ARRAY_FILTER_USE_KEY );
	xdebug_stop_code_coverage();
	foreach ( $coverage as &$file ) {
		if ( isset( $file['functions'] ) ) {
			foreach ( $file['functions'] as &$function ) { unset( $function['paths'] ); }
			unset( $function );
		}
	}
	unset( $file );
	$case = getenv( 'RCS_COVERAGE_CASE' );
	if ( ! is_string( $case ) || ! preg_match( '/^[a-z0-9-]+$/D', $case ) ) { throw new RuntimeException( 'A safe coverage case name is required.' ); }
	$record = array(
		'runtime' => array( 'php' => PHP_VERSION, 'xdebug' => phpversion( 'xdebug' ), 'uopz' => phpversion( 'uopz' ), 'uopz_disabled' => ini_get( 'uopz.disable' ), 'opcache_cli' => ini_get( 'opcache.enable_cli' ) ),
		'hashes' => array_map( static fn( $file ) => hash_file( 'sha256', $file ), array_keys( $coverage ) ),
		'coverage' => $coverage,
	);
	if ( false === file_put_contents( '/reports/raw/' . $case . '.json', json_encode( $record, JSON_THROW_ON_ERROR ) ) ) { throw new RuntimeException( 'Cannot write coverage output.' ); }
} );
