<?php
/** Short standalone DOM budgets. Each process also needs the Docker watchdog. */

define( 'ABSPATH', __DIR__ );
class WP_Error {
	public function __construct( public $code ) {}
}
require dirname( __DIR__ ) . '/inc/class-remote-content-html.php';

$large = in_array( getenv( 'RCS_SELECTOR_CASE' ), array( 'large-select', 'large-remove', 'flat' ), true );
$cases = array(
	'small-select' => array( str_repeat( '<div>', 250 ) . str_repeat( '<a>x</a>', 2000 ), 'div a', '' ),
	'large-select' => array( str_repeat( '<div>', 250 ) . str_repeat( '<a>x</a>', $large ? 120000 : 1 ), 'div a', '' ),
	'large-remove' => array( str_repeat( '<div>', 250 ) . str_repeat( '<a>x</a>', $large ? 120000 : 1 ), '', 'div a' ),
	'flat' => array( str_repeat( '<p>a</p>', $large ? 130000 : 1 ), '* *', '' ),
	'complex-selector' => array( str_repeat( '<div>', 10 ) . str_repeat( '<a>x</a>', 500 ), str_repeat( 'div ', 40 ) . 'a', '' ),
	'work-budget' => array( str_repeat( '<div>', 25 ) . str_repeat( '<a>x</a>', 500 ), 'div a,div a,div a', '' ),
	'ordinary' => array( str_repeat( '<div>', 10 ) . str_repeat( '<a>x</a>', 500 ), 'div a', '' ),
	'flat-sibling' => array( str_repeat( '<p></p>', 1998 ), '*~*', '' ),
	'flat-sibling-chain' => array( str_repeat( '<p></p>', 1600 ), '*~*~*', '' ),
	'small-sibling' => array( str_repeat( '<p>x</p>', 10 ), '*~*', '' ),
	'themed-page' => array( '<main>' . str_repeat( '<div>', 17 ) . str_repeat( '<p>x</p>', 980 ) . '<script>x</script><aside class="advertisement">ad</aside>' . str_repeat( '</div>', 17 ) . '</main>', 'main', 'script, .advertisement' ),
);
$name = getenv( 'RCS_SELECTOR_CASE' ) ?: 'small-select';
if ( ! isset( $cases[ $name ] ) ) {
	throw new RuntimeException( 'Unknown selector fixture.' );
}
$start = hrtime( true );
$result = Remote_Content_Shortcode_HTML::transform( ...$cases[ $name ] );
$seconds = ( hrtime( true ) - $start ) / 1e9;
$fragment = 'ordinary' === $name ? '<a>x</a>' : '<p>x</p>';
$expected = in_array( $name, array( 'ordinary', 'themed-page', 'small-sibling' ), true ) ? is_string( $result ) && str_contains( $result, $fragment ) : $result instanceof WP_Error && in_array( $result->code, array( 'html_too_complex', 'selector_too_complex' ), true );
if ( 'themed-page' === $name && is_string( $result ) ) {
	$expected = $expected && ! str_contains( $result, '<script' ) && ! str_contains( $result, 'advertisement' );
}
if ( ! $expected || $seconds >= 2 ) {
	throw new RuntimeException( sprintf( 'Unexpected selector result: %s (%.4fs; %s)', $name, $seconds, $result instanceof WP_Error ? $result->code : 'HTML' ) );
}
echo sprintf( "PASS %s completed in %.4f seconds\n", $name, $seconds );
