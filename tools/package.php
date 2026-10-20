<?php
/** Create a release ZIP from an explicit file list, without development files. */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$output = $argv[1] ?? '';
$directory = realpath( dirname( $output ) );
$runner = 'true' === getenv( 'GITHUB_ACTIONS' ) && getenv( 'RUNNER_TEMP' ) ? realpath( getenv( 'RUNNER_TEMP' ) ) : false;
$allowed = $directory && ( str_starts_with( $directory . '/', '/Volumes/Scratch/' ) || ( $runner && str_starts_with( $directory . '/', $runner . '/' ) ) );
if ( ! $allowed || ! str_ends_with( $output, '.zip' ) ) {
	fwrite( STDERR, "Output must be a ZIP under /Volumes/Scratch or GitHub Actions RUNNER_TEMP.\n" );
	exit( 1 );
}
$root = dirname( __DIR__ );
$files = array( 'remote-content-shortcode.php', 'readme.txt', 'LICENSE.txt', 'third-party.txt', 'composer.json', 'composer.lock', 'docs/security.md', 'uninstall.php' );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/inc', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( $file->isFile() && ! $file->isLink() ) {
		$files[] = substr( $file->getPathname(), strlen( $root ) + 1 );
	}
}
foreach ( array( 'blocks/remote-content/block.json', 'blocks/remote-content/editor.js', 'blocks/remote-content/editor.asset.php' ) as $file ) {
	$files[] = $file;
}
sort( $files );
$zip = new ZipArchive();
if ( true !== $zip->open( $output, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	throw new RuntimeException( 'Cannot create release ZIP.' );
}
foreach ( $files as $file ) {
	if ( ! is_file( $root . '/' . $file ) || is_link( $root . '/' . $file ) || false !== strpos( $file, '.svn' ) || 'inc/phpQuery.php' === $file ) {
		throw new RuntimeException( 'Unexpected release file: ' . $file );
	}
	$zip->addFile( $root . '/' . $file, 'remote-content-shortcode/' . $file );
}
$zip->close();
echo count( $files ) . " release files packaged.\n";
