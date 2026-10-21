<?php
/** Capture compiler metadata in the same disposable WordPress process as coverage. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( '1' !== getenv( 'RCS_DISPOSABLE_OPCODE_MAP' ) || ! extension_loaded( 'vld' ) ) { WP_CLI::error( 'Opcode capture requires the disposable coverage runner and VLD.' ); }
$root = dirname( getenv( 'RCS_PLUGIN_FILE' ) );
$hashes = array();
$closing_braces = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
	if ( $file->isFile() && 'php' === $file->getExtension() && ! str_starts_with( $relative, 'inc/vendor/' ) ) {
		$hashes[ $relative ] = hash_file( 'sha256', $file->getPathname() );
		$line = 1;
		$closing_braces[ $relative ] = array();
		foreach ( token_get_all( file_get_contents( $file->getPathname() ) ) as $token ) {
			if ( is_array( $token ) ) { $line = $token[2] + substr_count( $token[1], "\n" ); }
			else { if ( '}' === $token ) { $closing_braces[ $relative ][] = $line; } $line += substr_count( $token, "\n" ); }
		}
	}
}
ksort( $hashes );
$provenance = array( 'runtime' => array( 'php' => PHP_VERSION, 'xdebug' => phpversion( 'xdebug' ), 'uopz' => phpversion( 'uopz' ), 'uopz_disabled' => ini_get( 'uopz.disable' ), 'opcache_cli' => ini_get( 'opcache.enable_cli' ) ), 'hashes' => $hashes, 'closing_braces' => $closing_braces );
if ( false === file_put_contents( '/reports/opcode-source.json', json_encode( $provenance, JSON_THROW_ON_ERROR ) ) ) { throw new RuntimeException( 'Could not record opcode provenance.' ); }
$trigger = '/work/opcode-trigger.php';
if ( false === file_put_contents( $trigger, '<?php return;' ) ) { throw new RuntimeException( 'Could not create the disposable compiler trigger.' ); }
foreach ( array( 'vld.dump_paths' => '0', 'vld.save_paths' => '0', 'vld.format' => '0', 'vld.active' => '1' ) as $key => $value ) {
	if ( false === ini_set( $key, $value ) ) { throw new RuntimeException( 'Cannot configure ' . $key ); }
}
// Capture top-level dynamic functions as well as class methods. The main file
// safely skips registration on its second include; helpers load for the first time.
require $root . '/remote-content-shortcode.php';
foreach ( array( 'html', 'regex', 'urls' ) as $helper ) { require_once $root . '/inc/class-remote-content-' . $helper . '.php'; }
define( 'WP_UNINSTALL_PLUGIN', 'remote-content-shortcode/remote-content-shortcode.php' );
require $root . '/uninstall.php';
require $trigger;
ini_set( 'vld.active', '0' );
unlink( $trigger );
echo "PASS captured compiler metadata\n";
