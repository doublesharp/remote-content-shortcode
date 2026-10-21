<?php
/** Free admin boundary cases. Run with wp eval-file on a disposable WordPress site. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function rcs_free_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
rcs_free_assert( class_exists( 'Remote_Content_Shortcode_Secrets' ), 'Authenticated secret storage is missing.' );
rcs_free_assert( class_exists( 'Remote_Content_Shortcode_Connections' ), 'Scoped UI connections are missing.' );
$tests = array();
$fixture = static fn( $alias = 'free-test' ) => array( '_wpnonce' => wp_create_nonce( 'rcs_connections' ), 'alias' => $alias, 'auth' => 'bearer', 'origin' => 'https://example.com', 'path' => '/endpoint', 'methods' => array( 'GET' ), 'header' => 'X-API-Key', 'secret' => 'test-secret-unique' );
$tests['vault authenticates and rejects tampering'] = static function () {
	$sealed = Remote_Content_Shortcode_Secrets::seal( 'secret-value' );
	rcs_free_assert( is_string( $sealed ) && ! str_contains( $sealed, 'secret-value' ) && 'secret-value' === Remote_Content_Shortcode_Secrets::open( $sealed ), 'Vault round trip failed.' );
	foreach ( array( '', 'v9:broken', $sealed . '!', substr_replace( $sealed, 'AAAA', -8, 4 ) ) as $bad ) { rcs_free_assert( is_wp_error( Remote_Content_Shortcode_Secrets::open( $bad ) ), 'Corrupt envelope opened.' ); }
	$change = static fn() => 'changed-salt'; add_filter( 'salt', $change );
	try { rcs_free_assert( is_wp_error( Remote_Content_Shortcode_Secrets::open( $sealed ) ), 'Changed salts opened old secret.' ); } finally { remove_filter( 'salt', $change ); }
};
$tests['vault supports bounded encrypted payloads independently of credential limits'] = static function () {
	$value = str_repeat( 'a', 1048576 ); $sealed = Remote_Content_Shortcode_Secrets::seal( $value );
	rcs_free_assert( is_string( $sealed ) && $value === Remote_Content_Shortcode_Secrets::open( $sealed ), 'One MiB authenticated payload failed.' );
	rcs_free_assert( is_wp_error( Remote_Content_Shortcode_Secrets::seal( $value . 'a' ) ) && is_wp_error( Remote_Content_Shortcode_Secrets::open( str_repeat( 'x', 1400001 ) ) ), 'Vault exceeded payload bounds.' );
};
$tests['connections require permission and nonce'] = static function () use ( $fixture ) {
	wp_set_current_user( 0 );
	rcs_free_assert( is_wp_error( Remote_Content_Shortcode_Connections::save( $fixture() ) ) && is_wp_error( Remote_Content_Shortcode_Connections::delete( 'free-test' ) ), 'Anonymous mutation allowed.' );
	wp_set_current_user( 1 ); $data = $fixture(); $data['_wpnonce'] = 'bad';
	rcs_free_assert( is_wp_error( Remote_Content_Shortcode_Connections::save( $data ) ), 'Bad save nonce accepted.' );
	$_REQUEST['_wpnonce'] = 'bad'; rcs_free_assert( is_wp_error( Remote_Content_Shortcode_Connections::delete( 'free-test' ) ), 'Bad delete nonce accepted.' );
};
$tests['connections retain replace and delete encrypted secrets'] = static function () use ( $fixture ) {
	$data = $fixture(); rcs_free_assert( 'free-test' === Remote_Content_Shortcode_Connections::save( $data ), 'Save failed.' );
	$data['secret'] = ''; rcs_free_assert( 'free-test' === Remote_Content_Shortcode_Connections::save( $data ) && 'test-secret-unique' === Remote_Content_Shortcode_Connections::secret( 'free-test' ), 'Blank secret did not retain.' );
	$data['secret'] = 'replacement'; rcs_free_assert( 'free-test' === Remote_Content_Shortcode_Connections::save( $data ) && 'replacement' === Remote_Content_Shortcode_Connections::secret( 'free-test' ), 'Replacement failed.' );
	rcs_free_assert( ! str_contains( wp_json_encode( Remote_Content_Shortcode_Connections::all() ), 'replacement' ) && ! str_contains( wp_json_encode( get_option( 'rcs_connections_v1' ) ), 'replacement' ), 'Secret leaked into metadata or option.' );
	$data['delete_secret'] = '1'; rcs_free_assert( 'free-test' === Remote_Content_Shortcode_Connections::save( $data ) && is_wp_error( Remote_Content_Shortcode_Connections::secret( 'free-test' ) ), 'Explicit secret removal failed.' );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'rcs_connections' ); rcs_free_assert( true === Remote_Content_Shortcode_Connections::delete( 'free-test' ) && true === Remote_Content_Shortcode_Connections::delete( 'free-test' ), 'Delete is not idempotent.' );
};
$tests['connections reject malformed scopes and reserved headers'] = static function () use ( $fixture ) {
	foreach ( array( 'alias' => array( '', 'bad alias', str_repeat( 'a', 65 ), array() ), 'auth' => array( 'oauth', array() ), 'origin' => array( 'http://example.com', 'https://user:pass@example.com', 'https://example.com/a', 'https://example.com?key=secret' ), 'path' => array( '', 'endpoint', '/a?b', '/a\\b', '/a/../b', '/%2e%2e/b', '/a b' ), 'methods' => array( array(), array( 'DELETE' ), array( array( 'GET' ) ), array( new stdClass() ), 'GET' ), 'secret' => array( "bad\nvalue", str_repeat( 'a', 8193 ), array() ) ) as $field => $values ) {
		foreach ( $values as $value ) { $data = $fixture(); $data[ $field ] = $value; rcs_free_assert( is_wp_error( Remote_Content_Shortcode_Connections::save( $data ) ), "Invalid $field accepted." ); }
	}
	foreach ( array( 'Authorization', 'X-Forwarded-For', 'X-Original-URL', 'X-HTTP-Method-Override', 'X-Host', "X-Key\r\nBad" ) as $header ) { $data = $fixture(); $data['auth'] = 'api_key'; $data['header'] = $header; rcs_free_assert( is_wp_error( Remote_Content_Shortcode_Connections::save( $data ) ), 'Unsafe header accepted.' ); }
	foreach ( array( 'basic' => 'user:password', 'bearer' => 'token', 'api_key' => 'value' ) as $auth => $secret ) { $data = $fixture(); $data['auth'] = $auth; $data['secret'] = $secret; rcs_free_assert( 'free-test' === Remote_Content_Shortcode_Connections::save( $data ), 'Supported authentication rejected.' ); }
};
$tests['code aliases win and are read only'] = static function () use ( $fixture ) {
	$code = static function ( $sources ) { $sources['configured'] = array( 'type' => 'constant', 'key' => 'CODE_KEY', 'origin' => 'https://example.com', 'path' => '/endpoint', 'methods' => array( 'GET' ) ); return $sources; };
	add_filter( 'remote_content_shortcode_credentials', $code, 10 );
	try {
		rcs_free_assert( is_wp_error( Remote_Content_Shortcode_Connections::save( $fixture( 'configured' ) ) ), 'Code alias overwritten.' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rcs_connections' ); rcs_free_assert( is_wp_error( Remote_Content_Shortcode_Connections::delete( 'configured' ) ), 'Code alias deleted.' );
		$all = Remote_Content_Shortcode_Connections::all(); rcs_free_assert( ! empty( $all['configured']['read_only'] ) && ! isset( $all['configured']['key'] ), 'Code metadata unsafe.' );
	} finally { remove_filter( 'remote_content_shortcode_credentials', $code, 10 ); }
};
$tests['connections have a bounded inventory and emit scoped definitions'] = static function () use ( $fixture ) {
	delete_option( 'rcs_connections_v1' );
	for ( $i = 0; $i < 32; ++$i ) { rcs_free_assert( 'limit-' . $i === Remote_Content_Shortcode_Connections::save( $fixture( 'limit-' . $i ) ), 'Inventory filled early.' ); }
	rcs_free_assert( is_wp_error( Remote_Content_Shortcode_Connections::save( $fixture( 'overflow' ) ) ), 'Inventory limit ignored.' );
	$sources = apply_filters( 'remote_content_shortcode_credentials', array() );
	rcs_free_assert( 'connection' === $sources['limit-0']['type'] && 'limit-0' === $sources['limit-0']['key'] && '/endpoint' === $sources['limit-0']['path'] && array( 'GET' ) === $sources['limit-0']['methods'], 'Scoped definition missing.' );
};
$page = static function ( $tab, $post = array() ) {
	$saved = array( $_GET, $_POST, $_REQUEST, $_SERVER['REQUEST_METHOD'] ?? null ); $level = ob_get_level();
	try { $_GET = array( 'tab' => $tab ); $_POST = $post; $_REQUEST = array_merge( $_GET, $post ); $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET'; ob_start(); Remote_Content_Shortcode_Admin::page(); return ob_get_clean(); }
	finally { while ( ob_get_level() > $level ) { ob_end_clean(); } list( $_GET, $_POST, $_REQUEST, $_SERVER['REQUEST_METHOD'] ) = $saved; }
};
$tests['native tabs render escaped extensions and every built in page'] = static function () use ( $page ) {
	foreach ( array( 'builder', 'connections', 'cache', 'usage', 'help', 'unknown', array( 'bad' ) ) as $tab ) { $html = $page( $tab ); rcs_free_assert( str_contains( $html, 'nav-tab-wrapper' ) && str_contains( $html, 'Remote content' ), 'Tab did not render.' ); }
	$tabs = static function ( $tabs ) { $tabs['extension'] = '<script>bad</script>'; $tabs['bad/slug'] = 'ignored'; return $tabs; }; $render = static function () { echo 'extension content'; };
	add_filter( 'remote_content_shortcode_admin_tabs', $tabs ); add_action( 'remote_content_shortcode_admin_tab_extension', $render );
	try { $html = $page( 'extension' ); rcs_free_assert( str_contains( $html, 'extension content' ) && ! str_contains( $html, '<script>bad' ) && ! str_contains( $html, 'bad/slug' ), 'Unsafe extension tabs.' ); } finally { remove_filter( 'remote_content_shortcode_admin_tabs', $tabs ); remove_action( 'remote_content_shortcode_admin_tab_extension', $render ); }
};
$tests['builder encodes shortcode delimiters and rejects inline secrets'] = static function () use ( $page ) {
	$input = array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => 'generate', 'url' => 'https://example.com/?x="[evil]"&y=1', 'selector' => '[title="hi"]', 'fallback' => '</textarea><script>alert(1)</script>', 'body' => '[remote_content url="https://evil.invalid"]', 'method' => 'POST' );
	$blocked = Remote_Content_Shortcode_Admin::process_action( $input ); rcs_free_assert( is_wp_error( $blocked ), 'Nested shortcode body accepted.' ); $input['body'] = 'field=value';
	$result = Remote_Content_Shortcode_Admin::process_action( $input ); rcs_free_assert( is_string( $result ) && str_contains( $result, 'decode_atts="true"' ) && str_contains( $result, '&#91;' ) && ! str_contains( $result, '[evil]' ) && ! str_contains( $result, 'evil.invalid' ), 'Unsafe shortcode generation.' );
	$html = $page( 'builder', $input ); rcs_free_assert( ! str_contains( $html, '<script>alert' ) && str_contains( $html, 'Generated shortcode' ), 'Builder output escaped its textarea.' );
	$input['userpwd'] = 'raw:password'; rcs_free_assert( is_wp_error( Remote_Content_Shortcode_Admin::process_action( $input ) ), 'Inline credentials accepted.' );
};
$tests['builder preserves quoted edge values through the real parser'] = static function () {
	$input = array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => 'generate', 'url' => 'http://source.rcs.test/ok', 'selector' => '.no-such-element', 'fallback' => '"quoted [edge]"' );
	$generated = Remote_Content_Shortcode_Admin::process_action( $input );
	rcs_free_assert( is_string( $generated ) && '"quoted [edge]"' === do_shortcode( $generated ), 'Builder lost literal edge quotes or brackets.' );
};
$tests['usage is paginated and support copy omits content and secrets'] = static function () use ( $page ) {
	$ids = array();
	try {
		for ( $i = 0; $i < 23; ++$i ) { $ids[] = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Usage <unsafe> ' . $i, 'post_content' => '[remote_content url="https://example.com/?private=unique-query-secret"]' ) ); }
		$html = $page( 'usage' ); rcs_free_assert( 20 === substr_count( $html, 'data-rcs-usage=' ) && str_contains( $html, 'usage_page=2' ) && ! str_contains( $html, 'unique-query-secret' ), 'Usage is unbounded or disclosed content.' );
		$html = $page( 'help' ); rcs_free_assert( str_contains( $html, 'Support summary' ) && ! str_contains( $html, 'unique-query-secret' ) && ! str_contains( $html, 'test-secret-unique' ), 'Support copy leaked secrets.' );
	} finally { foreach ( $ids as $id ) { wp_delete_post( $id, true ); } }
};
$old = get_option( 'rcs_connections_v1', null ); $old_user = get_current_user_id(); $request = $_REQUEST; $failures = 0;
try { foreach ( $tests as $name => $test ) { wp_set_current_user( 1 ); delete_option( 'rcs_connections_v1' ); try { $test(); echo "PASS $name\n"; } catch ( Throwable $error ) { ++$failures; echo "FAIL $name: " . $error->getMessage() . "\n"; } } }
finally { null === $old ? delete_option( 'rcs_connections_v1' ) : update_option( 'rcs_connections_v1', $old, false ); wp_set_current_user( $old_user ); $_REQUEST = $request; }
if ( $failures ) { WP_CLI::error( "$failures Free admin failures" ); }
echo count( $tests ) . " Free admin cases passed\n";
