<?php
/** Run with WordPress loaded via wp eval-file. Requires the source.rcs.test fixture. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function rcs_ab_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
rcs_ab_assert( class_exists( 'Remote_Content_Shortcode_Admin' ), 'Admin diagnostics module is missing.' );
rcs_ab_assert( class_exists( 'Remote_Content_Shortcode_Block' ), 'Dynamic block module is missing.' );
$block = WP_Block_Type_Registry::get_instance()->get_registered( 'remote-content-shortcode/remote-content' );
rcs_ab_assert( $block && $block->is_dynamic(), 'Dynamic block is not registered.' );
$attrs = array( 'url' => '', 'fallback' => '<strong>offline</strong><script>alert(1)</script>', 'cache' => false );
$rendered = render_block( array( 'blockName' => 'remote-content-shortcode/remote-content', 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
$shortcode = RemoteContentShortcode::get_instance()->remote_content_shortcode( array( 'url' => '', 'fallback' => $attrs['fallback'], 'cache' => 'false' ) );
rcs_ab_assert( $rendered === $shortcode, 'Block differs from shared shortcode renderer.' );
rcs_ab_assert( ! str_contains( $rendered, '<script' ), 'Block returned executable fallback HTML.' );
rcs_ab_assert( is_string( Remote_Content_Shortcode_Block::render( array( 'url' => array( 'bad' ), 'cacheTtl' => array() ) ) ), 'Malformed block attributes failed safely.' );
$fixture_attrs = array( 'url' => 'http://source.rcs.test/ok', 'selector' => 'p', 'cache' => false, 'rewriteUrls' => true );
$serialized = serialize_block( array( 'blockName' => 'remote-content-shortcode/remote-content', 'attrs' => $fixture_attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
$fixture_block = do_blocks( $serialized );
$fixture_shortcode = RemoteContentShortcode::get_instance()->remote_content_shortcode( array( 'url' => $fixture_attrs['url'], 'selector' => 'p', 'cache' => 'false', 'rewrite_urls' => 'true' ) );
rcs_ab_assert( str_contains( $fixture_block, 'fixture success' ) && $fixture_block === $fixture_shortcode, 'Saved block and shortcode did not render the same remote fixture.' );
$old_user = get_current_user_id();
wp_set_current_user( 0 );
rcs_ab_assert( is_wp_error( Remote_Content_Shortcode_Admin::process_action( array( 'rcs_action' => 'test' ) ) ), 'Anonymous admin action accepted.' );
rcs_ab_assert( is_wp_error( RemoteContentShortcode::get_instance()->diagnose( array() ) ), 'Anonymous diagnostics accepted.' );
$request = new WP_REST_Request( 'POST', '/wp/v2/block-renderer/remote-content-shortcode/remote-content' );
$request->set_param( 'context', 'edit' );
$response = rest_do_request( $request );
rcs_ab_assert( in_array( $response->get_status(), array( 401, 403 ), true ), 'Anonymous core block renderer accepted.' );
$subscriber = wp_insert_user( array( 'user_login' => 'rcs-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
rcs_ab_assert( ! is_wp_error( $subscriber ), 'Could not create subscriber fixture.' );
try {
	wp_set_current_user( $subscriber );
	$nonce = wp_create_nonce( 'rcs_tools' );
	foreach ( array( 'test', 'clear' ) as $action ) {
		rcs_ab_assert( is_wp_error( Remote_Content_Shortcode_Admin::process_action( array( 'rcs_action' => $action, '_wpnonce' => $nonce ) ) ), 'Subscriber admin action accepted.' );
	}
	rcs_ab_assert( is_wp_error( RemoteContentShortcode::get_instance()->diagnose( array() ) ), 'Subscriber diagnostics accepted.' );
} finally {
	wp_set_current_user( $old_user );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $subscriber );
}
wp_set_current_user( 1 );
rcs_ab_assert( is_wp_error( Remote_Content_Shortcode_Admin::process_action( array( 'rcs_action' => 'clear', '_wpnonce' => 'invalid' ) ) ), 'Invalid nonce accepted.' );
$diag = RemoteContentShortcode::get_instance()->diagnose( array( 'url' => 'http://user:secret@127.0.0.1/?token=sensitive', 'cache' => 'false' ) );
$encoded = wp_json_encode( $diag );
rcs_ab_assert( ! str_contains( $encoded, 'secret' ) && ! str_contains( $encoded, 'sensitive' ), 'Diagnostics exposed URL secrets.' );
$saved_post = $_POST;
$saved_request = $_REQUEST;
$saved_method = $_SERVER['REQUEST_METHOD'] ?? null;
$page_user = get_current_user_id();
$buffer_level = ob_get_level();
try {
	wp_set_current_user( 1 );
	$_POST = array( 'rcs_action' => 'test', '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'url' => 'http://name:secret@127.0.0.1/?token=private-marker', 'cache' => 'false' );
	$_REQUEST = $_POST;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	ob_start();
	Remote_Content_Shortcode_Admin::page();
	$page_html = ob_get_clean();
	rcs_ab_assert( ! str_contains( $page_html, 'secret' ) && ! str_contains( $page_html, 'private-marker' ) && ! str_contains( $page_html, '127.0.0.1' ), 'Admin page echoed source URL secrets.' );
	foreach ( array( 'Successful import', 'Error code', 'HTTP status', 'Cache', 'Matching elements', 'Elapsed time (ms)', 'Not available' ) as $label ) {
		rcs_ab_assert( str_contains( $page_html, esc_html( __( $label, 'remote-content-shortcode' ) ) ), 'Admin page omitted readable diagnostic label.' );
	}
	rcs_ab_assert( str_contains( $page_html, '<dd>' . esc_html__( 'No', 'remote-content-shortcode' ) . '</dd>' ), 'Admin page did not display failed import as No.' );
} finally {
	while ( ob_get_level() > $buffer_level ) { ob_end_clean(); }
	$_POST = $saved_post;
	$_REQUEST = $saved_request;
	if ( null === $saved_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $saved_method; }
	wp_set_current_user( $page_user );
}
set_transient( 'rcs_admin_unrelated', 'safe', 60 );
$result = Remote_Content_Shortcode_Admin::process_action( array( 'rcs_action' => 'clear', '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'url' => 'https://example.com/one' ) );
rcs_ab_assert( ! is_wp_error( $result ) && 'safe' === get_transient( 'rcs_admin_unrelated' ), 'Targeted clear affected unrelated transient.' );
delete_transient( 'rcs_admin_unrelated' );
wp_set_current_user( $old_user );
echo "PASS dynamic block equivalence, safe attributes, admin permissions, nonce and redaction\n";
