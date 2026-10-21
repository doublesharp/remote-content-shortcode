<?php
/** The bundled selector autoloader must remain inside its isolated namespace. */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/inc/class-remote-content-html.php';
if ( ! class_exists( 'RemoteContentShortcode\\Vendor\\Symfony\\Component\\CssSelector\\CssSelectorConverter' ) ) { throw new RuntimeException( 'Bundled selector class was not loaded.' ); }
foreach ( array( 'RCS_Unrelated_Vendor\\Missing_Class', 'Symfony\\Component\\CssSelector\\RCS_Missing_Class', 'RemoteContentShortcode\\Vendor\\Symfony\\Component\\CssSelector\\RCS_Missing_Class' ) as $class ) {
	if ( class_exists( $class ) ) { throw new RuntimeException( 'Selector autoloader claimed a missing class: ' . $class ); }
}
echo "PASS bundled selector autoloading, namespace isolation, and missing class handling\n";
