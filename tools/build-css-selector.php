<?php
/** Bundle the locked CssSelector dependency with an isolated namespace. */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$source = $argv[1] ?? '';
if ( ! is_file( $source . '/CssSelectorConverter.php' ) || ! is_file( $source . '/LICENSE' ) ) {
	fwrite( STDERR, "Usage: php tools/build-css-selector.php /path/to/vendor/symfony/css-selector\n" );
	exit( 1 );
}

$target = dirname( __DIR__ ) . '/inc/vendor/css-selector';
$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	$relative = substr( $file->getPathname(), strlen( $source ) + 1 );
	if ( 'php' !== $file->getExtension() && 'LICENSE' !== $relative ) {
		continue;
	}
	$destination = $target . '/' . $relative;
	if ( ! is_dir( dirname( $destination ) ) ) {
		mkdir( dirname( $destination ), 0775, true );
	}
	$contents = file_get_contents( $file->getPathname() );
	if ( 'php' === $file->getExtension() ) {
		$contents = str_replace( 'Symfony\\Component\\CssSelector', 'RemoteContentShortcode\\Vendor\\Symfony\\Component\\CssSelector', $contents );
	}
	file_put_contents( $destination, $contents );
}
echo "Bundled Symfony CssSelector from composer.lock with a plugin-specific namespace.\n";
