<?php
/** Extra Free platform boundaries. Run with wp eval-file on a disposable WordPress site. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function rcs_fp_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function rcs_fp_error( $value, $code ) { rcs_fp_assert( is_wp_error( $value ) && $code === $value->get_error_code(), 'Expected error ' . $code . '.' ); }
function rcs_fp_fixture( $alias = 'platform-fixture' ) {
	return array( '_wpnonce' => wp_create_nonce( 'rcs_connections' ), 'alias' => $alias, 'auth' => 'bearer', 'origin' => 'https://example.com', 'path' => '/endpoint', 'methods' => array( 'GET' ), 'secret' => 'platform-fixture-secret' );
}
function rcs_fp_page( $tab, $post = array(), $query = array() ) {
	$saved = array( $_GET, $_POST, $_REQUEST, $_SERVER['REQUEST_METHOD'] ?? null ); $level = ob_get_level();
	try {
		$_GET = array_merge( $query, array( 'tab' => $tab ) ); $_POST = $post; $_REQUEST = array_merge( $_GET, $post ); $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
		ob_start(); Remote_Content_Shortcode_Admin::page(); return ob_get_clean();
	} finally {
		while ( ob_get_level() > $level ) { ob_end_clean(); }
		list( $_GET, $_POST, $_REQUEST, $method ) = $saved;
		if ( null === $method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $method; }
	}
}
class RCS_Platform_Die extends RuntimeException {}
$tests = array();
$tests['malformed storage never becomes a usable credential'] = static function () {
	foreach ( array( false, 19, 'broken', new stdClass() ) as $stored ) {
		update_option( Remote_Content_Shortcode_Connections::OPTION, $stored, false );
		rcs_fp_assert( array() === Remote_Content_Shortcode_Connections::definitions( null ) && array() === Remote_Content_Shortcode_Connections::all(), 'Malformed storage exposed a connection.' );
		rcs_fp_error( Remote_Content_Shortcode_Connections::secret( 'absent' ), 'secret_unavailable' );
	}
	$row = rcs_fp_fixture(); unset( $row['_wpnonce'], $row['alias'], $row['secret'] ); $row['sealed'] = array( 'malformed' );
	update_option( Remote_Content_Shortcode_Connections::OPTION, array( 4 => $row, 'bad alias' => $row, 'scalar' => 'bad', 'bad-scope' => array( 'auth' => 'bearer' ), 'valid' => $row ), false );
	$all = Remote_Content_Shortcode_Connections::all();
	rcs_fp_assert( array( 'valid' ) === array_keys( $all ) && false === $all['valid']['has_secret'], 'Malformed stored rows became editor metadata.' );
	rcs_fp_error( Remote_Content_Shortcode_Connections::secret( 'valid' ), 'secret_unavailable' );
	foreach ( array( '', 'bad alias', array(), 'missing' ) as $alias ) { rcs_fp_error( Remote_Content_Shortcode_Connections::secret( $alias ), 'secret_unavailable' ); }
};
$tests['malformed hidden rows cannot exhaust the connection inventory'] = static function () {
	update_option( Remote_Content_Shortcode_Connections::OPTION, array_fill( 0, 32, 'corrupt-row' ), false );
	rcs_fp_assert( 'recovered' === Remote_Content_Shortcode_Connections::save( rcs_fp_fixture( 'recovered' ) ), 'Corrupt hidden rows permanently consumed every connection slot.' );
};
$tests['connection mutations validate secrets scopes and nonce types'] = static function () {
	$input = rcs_fp_fixture(); $input['secret'] = '';
	rcs_fp_error( Remote_Content_Shortcode_Connections::save( $input ), 'missing_secret' );
	foreach ( array( 'basic' => 'missing-colon', 'bearer' => 'space separated' ) as $auth => $secret ) { $input = rcs_fp_fixture(); $input['auth'] = $auth; $input['secret'] = $secret; rcs_fp_error( Remote_Content_Shortcode_Connections::save( $input ), 'invalid_secret' ); }
	foreach ( array( 'origin' => str_repeat( 'a', 513 ), 'path' => '/' . str_repeat( 'a', 2048 ), 'methods' => array( 'GET', 'POST', 'GET' ), 'auth' => array(), 'header' => array() ) as $key => $value ) {
		$input = rcs_fp_fixture(); $input['auth'] = 'api_key'; $input[ $key ] = $value;
		rcs_fp_assert( is_wp_error( Remote_Content_Shortcode_Connections::save( $input ) ), 'Oversized or malformed connection field accepted.' );
	}
	foreach ( array( null, false, 1, array() ) as $nonce ) {
		$input = rcs_fp_fixture(); $input['_wpnonce'] = $nonce;
		rcs_fp_error( Remote_Content_Shortcode_Connections::save( $input ), 'invalid_nonce' );
		$_REQUEST['_wpnonce'] = $nonce; rcs_fp_error( Remote_Content_Shortcode_Connections::delete( 'platform-fixture' ), 'invalid_nonce' );
	}
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'rcs_connections' );
	rcs_fp_error( Remote_Content_Shortcode_Connections::delete( array() ), 'invalid_alias' );
	$input = rcs_fp_fixture(); $input['origin'] = 'https://EXAMPLE.com:443/'; $input['methods'] = array( 'GET', 'GET' );
	rcs_fp_assert( 'platform-fixture' === Remote_Content_Shortcode_Connections::save( $input ), 'Canonical scope rejected.' );
	$all = Remote_Content_Shortcode_Connections::all();
	rcs_fp_assert( 'https://example.com' === $all['platform-fixture']['origin'] && array( 'GET' ) === $all['platform-fixture']['methods'], 'Scope was not normalized.' );
	$input['origin'] = 'https://example.com:8443'; $input['methods'] = array( 'GET', 'POST' );
	rcs_fp_assert( 'platform-fixture' === Remote_Content_Shortcode_Connections::save( $input ), 'Explicit HTTPS port configuration rejected.' );
	$all = Remote_Content_Shortcode_Connections::all(); rcs_fp_assert( 'https://example.com:8443' === $all['platform-fixture']['origin'], 'Explicit origin port was lost.' );
};
$tests['configured aliases reveal names only and malformed filters fail closed'] = static function () {
	rcs_fp_assert( 'php-defined' === Remote_Content_Shortcode_Connections::save( rcs_fp_fixture( 'php-defined' ) ), 'Could not seed a managed alias for PHP override.' );
	$code = static function ( $sources ) {
		$sources['php-defined'] = array( 'type' => 'option', 'key' => 'private-option-name', 'origin' => 'https://private.invalid', 'path' => '/secret', 'methods' => array( 'GET' ) );
		$sources['bad alias'] = array(); $sources['not-array'] = 'invalid'; $sources[12] = array(); return $sources;
	};
	add_filter( 'remote_content_shortcode_credentials', $code, 30 );
	try {
		$all = Remote_Content_Shortcode_Connections::all();
		rcs_fp_assert( array( 'php-defined' => array( 'read_only' => true ) ) === $all, 'Configured alias metadata disclosed scope, storage name or malformed aliases.' );
		$html = rcs_fp_page( 'connections' );
		rcs_fp_assert( str_contains( $html, 'Configured in PHP, read only.' ) && ! str_contains( $html, 'private-option-name' ) && ! str_contains( $html, 'private.invalid' ), 'Configured connection form exposed code-managed scope.' );
		rcs_fp_error( Remote_Content_Shortcode_Connections::secret( 'php-defined' ), 'secret_unavailable' );
	} finally { remove_filter( 'remote_content_shortcode_credentials', $code, 30 ); }
	$bad = static fn() => false; add_filter( 'remote_content_shortcode_credentials', $bad, 30 );
	try { rcs_fp_assert( array() === Remote_Content_Shortcode_Connections::all(), 'Malformed credentials filter did not fail closed.' ); }
	finally { remove_filter( 'remote_content_shortcode_credentials', $bad, 30 ); }
	wp_set_current_user( 0 ); rcs_fp_assert( array() === Remote_Content_Shortcode_Connections::all(), 'Anonymous caller saw configured aliases.' );
};
$tests['connection headers use the saved secret only in their exact scope'] = static function () {
	foreach ( array( 'basic' => 'fixture-user:fixture-password', 'bearer' => 'fixture-token', 'api_key' => 'fixture-api-value' ) as $auth => $secret ) {
		$input = rcs_fp_fixture(); $input['auth'] = $auth; $input['secret'] = $secret; $input['header'] = 'Api-Key';
		rcs_fp_assert( 'platform-fixture' === Remote_Content_Shortcode_Connections::save( $input ), 'Credential fixture not saved.' );
		$source = Remote_Content_Shortcode_Connections::definitions( array() )['platform-fixture'];
		$headers = Remote_Content_Shortcode_Credentials::headers( $source, 'https://example.com/endpoint?public=1', 'GET' );
		$expected = 'basic' === $auth ? array( 'Authorization' => 'Basic ' . base64_encode( $secret ) ) : ( 'bearer' === $auth ? array( 'Authorization' => 'Bearer ' . $secret ) : array( 'Api-Key' => $secret ) );
		rcs_fp_assert( $expected === $headers, 'Connection authentication was not resolved.' );
		foreach ( array( 'https://other.invalid/endpoint', 'https://example.com/endpoint/extra', 'https://example.com:8443/endpoint' ) as $url ) { rcs_fp_assert( is_wp_error( Remote_Content_Shortcode_Credentials::headers( $source, $url, 'GET' ) ), 'Connection credential escaped its scope.' ); }
		rcs_fp_error( Remote_Content_Shortcode_Credentials::headers( $source, 'https://example.com/endpoint', 'POST' ), 'credential_endpoint_mismatch' );
	}
};
$tests['connection scope changes cannot send a new secret through a captured old definition'] = static function () {
	$first = rcs_fp_fixture(); $first['path'] = '/scope-a'; $first['secret'] = 'old-scope-token';
	rcs_fp_assert( 'platform-fixture' === Remote_Content_Shortcode_Connections::save( $first ), 'Initial scoped connection could not be saved.' );
	$old_definition = Remote_Content_Shortcode_Connections::definitions( array() )['platform-fixture'];
	$stored_a = get_option( Remote_Content_Shortcode_Connections::OPTION );
	$replacement = rcs_fp_fixture(); $replacement['origin'] = 'https://other.example.com'; $replacement['path'] = '/scope-b'; $replacement['auth'] = 'api_key'; $replacement['header'] = 'X-New-Key'; $replacement['secret'] = 'new-scope-secret'; $replacement['_wpnonce'] = 'invalid';
	rcs_fp_error( Remote_Content_Shortcode_Connections::save( $replacement ), 'invalid_nonce' );
	rcs_fp_assert( array( 'Authorization' => 'Bearer old-scope-token' ) === Remote_Content_Shortcode_Credentials::headers( $old_definition, 'https://example.com/scope-a', 'GET' ), 'Rejected overwrite changed the existing credential or authentication mode.' );
	$replacement['_wpnonce'] = wp_create_nonce( 'rcs_connections' );
	rcs_fp_assert( 'platform-fixture' === Remote_Content_Shortcode_Connections::save( $replacement ), 'Explicit connection scope/auth overwrite failed.' );
	rcs_fp_assert( is_wp_error( Remote_Content_Shortcode_Credentials::headers( $old_definition, 'https://example.com/scope-a', 'GET' ) ), 'Captured old scope sent the new secret to the previous endpoint.' );
	$current = Remote_Content_Shortcode_Connections::definitions( array() )['platform-fixture'];
	rcs_fp_assert( array( 'X-New-Key' => 'new-scope-secret' ) === Remote_Content_Shortcode_Credentials::headers( $current, 'https://other.example.com/scope-b', 'GET' ), 'New definition did not bind the updated secret and authentication mode.' );
	$stored_b = get_option( Remote_Content_Shortcode_Connections::OPTION );
	for ( $transition_after = 1; $transition_after <= 8; ++$transition_after ) {
		$reads = 0;
		$interleave = static function () use ( &$reads, $transition_after, $stored_a, $stored_b ) { return ++$reads <= $transition_after ? $stored_b : $stored_a; };
		add_filter( 'pre_option_' . Remote_Content_Shortcode_Connections::OPTION, $interleave, 99 );
		try { $headers = Remote_Content_Shortcode_Credentials::headers( $old_definition, 'https://example.com/scope-a', 'GET' ); }
		finally { remove_filter( 'pre_option_' . Remote_Content_Shortcode_Connections::OPTION, $interleave, 99 ); }
		rcs_fp_assert( is_wp_error( $headers ) || array( 'Authorization' => 'Bearer old-scope-token' ) === $headers, 'Mixed connection snapshots sent another endpoint\'s secret through an old definition.' );
	}
	rcs_fp_assert( 'new-scope-secret' === Remote_Content_Shortcode_Connections::secret( 'platform-fixture' ), 'Trusted PHP direct secret resolution changed unexpectedly.' );
};
$tests['every connections POST path requires its own nonce and displays safe outcomes'] = static function () {
	$input = rcs_fp_fixture(); $input['connection_action'] = 'save';
	$html = rcs_fp_page( 'connections', $input );
	rcs_fp_assert( str_contains( $html, 'Connection updated.' ) && ! str_contains( $html, 'platform-fixture-secret' ) && str_contains( $html, 'readonly' ) && str_contains( $html, 'name="delete_secret"' ), 'Connection save rendered unsafe metadata.' );
	$input['connection_action'] = 'unknown';
	rcs_fp_assert( str_contains( rcs_fp_page( 'connections', $input ), 'invalid_action' ), 'Unknown connection action was not reported.' );
	$input['connection_action'] = 'delete'; $input['alias'] = array();
	rcs_fp_assert( str_contains( rcs_fp_page( 'connections', $input ), 'invalid_action' ), 'Malformed connection delete was accepted.' );
	$input['alias'] = 'platform-fixture';
	rcs_fp_assert( str_contains( rcs_fp_page( 'connections', $input ), 'Connection updated.' ), 'Connection delete did not render success.' );
	$rows = array();
	for ( $i = 0; $i < Remote_Content_Shortcode_Connections::LIMIT; ++$i ) { $rows[ 'full-' . $i ] = array( 'auth' => 'bearer', 'origin' => 'https://example.com', 'path' => '/endpoint', 'methods' => array( 'GET' ), 'sealed' => '' ); }
	update_option( Remote_Content_Shortcode_Connections::OPTION, $rows, false );
	rcs_fp_assert( ! str_contains( rcs_fp_page( 'connections' ), 'Add connection' ), 'Full connection inventory offered a form that cannot save.' );
	$handler = static fn() => static function () { throw new RCS_Platform_Die(); };
	add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	try {
		foreach ( array( 'connections' => 'connection_action', 'builder' => 'rcs_action', 'cache' => 'rcs_action' ) as $tab => $action ) {
			$rejected = false;
			try { rcs_fp_page( $tab, array( '_wpnonce' => 'invalid', $action => 'delete' ) ); } catch ( RCS_Platform_Die $error ) { $rejected = true; }
			rcs_fp_assert( $rejected, 'A tab accepted POST without its nonce.' );
		}
	} finally { remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX ); }
};
$tests['tabs reject malformed definitions without dispatching arbitrary actions'] = static function () {
	$invalid = static fn() => false; add_filter( 'remote_content_shortcode_admin_tabs', $invalid, 90 );
	try { rcs_fp_assert( str_contains( rcs_fp_page( array() ), 'rcs-body' ), 'Malformed tab inventory prevented safe default builder.' ); }
	finally { remove_filter( 'remote_content_shortcode_admin_tabs', $invalid, 90 ); }
	$tabs = static fn( $tabs ) => $tabs + array( 7 => 'number', 'bad/slug' => 'bad', 'valid-bad-label' => array(), 'safe' => '<b>private-label</b>' );
	$action = static function () { echo '<p>safe-extension-content</p>'; };
	add_filter( 'remote_content_shortcode_admin_tabs', $tabs, 90 ); add_action( 'remote_content_shortcode_admin_tab_safe', $action );
	try { $html = rcs_fp_page( 'safe' ); rcs_fp_assert( str_contains( $html, 'safe-extension-content' ) && ! str_contains( $html, '<b>private-label</b>' ) && ! str_contains( $html, 'bad/slug' ) && ! str_contains( $html, 'valid-bad-label' ), 'Tab definitions were not bounded and escaped.' ); }
	finally { remove_filter( 'remote_content_shortcode_admin_tabs', $tabs, 90 ); remove_action( 'remote_content_shortcode_admin_tab_safe', $action ); }
};
$tests['builder enforces its budgets and preserves normal generated attributes'] = static function () {
	$input = array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => 'generate', 'url' => 'http://source.rcs.test/ok', 'body' => '', 'timeout' => '-5', 'cache_ttl' => '2.5', 'stale_ttl' => 'bad', 'cache' => 'invalid', 'strip_tags' => 'true', 'htmlentities' => 'false', 'rewrite_urls' => 'true' );
	$result = Remote_Content_Shortcode_Admin::process_action( $input );
	rcs_fp_assert( is_string( $result ) && str_contains( $result, 'timeout=' ) && ! str_contains( $result, 'stale_ttl=' ) && ! str_contains( $result, 'cache=' ) && ! str_contains( $result, '[/remote_content]' ), 'Builder accepted invalid fields or added an empty body.' );
	foreach ( array( str_repeat( 'x', 65537 ), '[/REMOTE_CONTENT]', '[remote_content]' ) as $body ) { $next = $input; $next['body'] = $body; rcs_fp_error( Remote_Content_Shortcode_Admin::process_action( $next ), 'invalid_builder_body' ); }
	foreach ( array( 'file:///etc/passwd', 'https://user@example.com/path', 'https://example.com:password@evil.invalid/' ) as $url ) { $next = $input; $next['url'] = $url; rcs_fp_error( Remote_Content_Shortcode_Admin::process_action( $next ), 'invalid_url' ); }
	$input['selector'] = str_repeat( 'a', 4097 ); rcs_fp_error( Remote_Content_Shortcode_Admin::process_action( $input ), 'invalid_attributes' );
	unset( $input['selector'] ); $input['body'] = '[remote_content]';
	rcs_fp_assert( str_contains( rcs_fp_page( 'builder', $input ), 'Use a source filter for these payloads.' ), 'Builder failure omitted its fixed recovery instruction.' );
};
$tests['usage pagination is bounded and never publishes post content'] = static function () {
	$ids = array();
	try {
		for ( $i = 0; $i < 22; ++$i ) { $ids[] = wp_insert_post( array( 'post_status' => 'publish', 'post_type' => 'page', 'post_title' => 21 === $i ? '' : 'Boundary usage <unsafe>', 'post_content' => '<!-- wp:remote-content-shortcode/remote-content {"url":"https://example.com/PRIVATE-CONTENT"} /-->' ) ); }
		$html = rcs_fp_page( 'usage', array(), array( 'usage_page' => 2 ) );
		rcs_fp_assert( str_contains( $html, 'usage_page=1' ) && str_contains( $html, '(Untitled)' ) && ! str_contains( $html, 'PRIVATE-CONTENT' ) && ! str_contains( $html, '<unsafe>' ), 'Second usage page disclosed content or lost previous/untitled labels.' );
		foreach ( array( array(), 10001 ) as $page ) { rcs_fp_assert( str_contains( rcs_fp_page( 'usage', array(), array( 'usage_page' => $page ) ), 'Usage pagination' ), 'Malformed or oversized usage page broke the page.' ); }
	} finally { foreach ( $ids as $id ) { wp_delete_post( $id, true ); } }
};
$tests['cache summary ignores malformed expired and foreign index entries'] = static function () {
	$index = array( 'foreign' => array( 'expires' => time() + 100, 'bytes' => 999 ), 'rcs_v2_' . str_repeat( 'a', 64 ) => array( 'expires' => time() + 100, 'bytes' => 17 ), 'rcs_v2_' . str_repeat( 'b', 64 ) => array( 'expires' => time() - 1, 'bytes' => 22 ), 'rcs_v2_' . str_repeat( 'c', 64 ) => array( 'expires' => time() + 100, 'bytes' => -1 ), 'rcs_v2_' . str_repeat( 'd', 64 ) => 'broken' );
	update_option( Remote_Content_Shortcode_Cache::INDEX, $index, false );
	rcs_fp_assert( str_contains( rcs_fp_page( 'cache' ), 'Active indexed entries: 1. Stored bytes: 17.' ), 'Cache summary counted malformed or unrelated entries.' );
	update_option( Remote_Content_Shortcode_Cache::INDEX, 'bad', false );
	rcs_fp_assert( str_contains( rcs_fp_page( 'cache' ), 'Active indexed entries: 0. Stored bytes: 0.' ), 'Malformed cache index broke the page.' );
};
$tests['fetch API validates bodies filters and explicit filter bypass'] = static function () {
	$plugin = RemoteContentShortcode::get_instance(); $requests = array();
	$http = static function ( $pre, $args, $url ) use ( &$requests ) { $requests[] = array( $url, $args ); return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => '{"message":"<script>unsafe()</script>"}' ); };
	add_filter( 'pre_http_request', $http, 10, 3 );
	try {
		foreach ( array( false, array(), new stdClass() ) as $body ) { rcs_fp_error( $plugin->fetch_source( array( 'url' => 'https://source.rcs.test/' ), $body ), 'invalid_post_body' ); }
		$bad_url = static fn() => array(); add_filter( 'remote_content_shortcode_url', $bad_url );
		try { rcs_fp_error( $plugin->fetch_source( array( 'url' => 'https://source.rcs.test/' ) ), 'invalid_url' ); } finally { remove_filter( 'remote_content_shortcode_url', $bad_url ); }
		$bad_body = static fn() => array(); add_filter( 'remote_content_shortcode_postfields', $bad_body );
		try { rcs_fp_error( $plugin->fetch_source( array( 'url' => 'https://source.rcs.test/' ) ), 'invalid_post_body' ); } finally { remove_filter( 'remote_content_shortcode_postfields', $bad_body ); }
		$saved_query = $_GET; $_GET['platform-param'] = array( 'malformed' );
		try { rcs_fp_error( $plugin->fetch_source( array( 'url' => 'https://source.rcs.test/', 'params' => 'platform-param' ) ), 'invalid_query_parameter' ); } finally { $_GET = $saved_query; }
		rcs_fp_assert( array() === $requests, 'Malformed fetch input reached HTTP.' );
		$url_filter = static fn() => 'file:///private-file'; $body_filter = static fn() => 'replaced-body';
		add_filter( 'remote_content_shortcode_url', $url_filter ); add_filter( 'remote_content_shortcode_postfields', $body_filter );
		try { $response = $plugin->fetch_source( array( 'url' => 'https://source.rcs.test/', 'method' => 'POST', 'body' => 'explicit-json={"x":1}' ), null, false ); }
		finally { remove_filter( 'remote_content_shortcode_url', $url_filter ); remove_filter( 'remote_content_shortcode_postfields', $body_filter ); }
		rcs_fp_assert( is_array( $response ) && 'explicit-json={"x":1}' === $requests[0][1]['body'] && str_contains( $response['body'], 'unsafe()' ), 'Filter bypass altered transport bytes or pre-rendered JSON.' );
		$bad_credentials = static fn() => false; add_filter( 'remote_content_shortcode_credentials', $bad_credentials, 90 );
		try { rcs_fp_error( $plugin->fetch_source( array( 'url' => 'https://source.rcs.test/', 'userpwd' => 'unknown' ) ), 'unconfigured_credentials' ); } finally { remove_filter( 'remote_content_shortcode_credentials', $bad_credentials, 90 ); }
		$connection = rcs_fp_fixture(); $connection['origin'] = 'https://source.rcs.test'; $connection['path'] = '/';
		rcs_fp_assert( 'platform-fixture' === Remote_Content_Shortcode_Connections::save( $connection ), 'Could not save fetch API credential fixture.' );
		$response = $plugin->fetch_source( array( 'url' => 'https://source.rcs.test/', 'userpwd' => 'platform-fixture' ), null, false );
		$authenticated = end( $requests );
		rcs_fp_assert( is_array( $response ) && array( 'Authorization' => 'Bearer platform-fixture-secret' ) === $authenticated[1]['headers'] && 0 === $authenticated[1]['redirection'], 'Successful add-on fetch did not attach its scoped credential.' );
	} finally { remove_filter( 'pre_http_request', $http, 10 ); }
};
$tests['snapshot rendering rejects invalid envelopes and applies shared transformations'] = static function () {
	$plugin = RemoteContentShortcode::get_instance(); $response = array( 'url' => 'https://source.rcs.test/path/', 'body' => '<main><a href="next">alpha</a><script>bad()</script></main>', 'status' => 200 );
	rcs_fp_assert( '' === $plugin->render_fetched( $response, array( 'selector' => array() ) ), 'Nonscalar snapshot attributes accepted.' );
	foreach ( array( array(), array( 'url' => 'file:///private', 'body' => 'private' ), array( 'url' => 'https://source.rcs.test/', 'body' => array() ) ) as $bad ) { rcs_fp_assert( '' === $plugin->render_fetched( $bad, array() ), 'Invalid snapshot envelope reached output.' ); }
	$html = $plugin->render_fetched( $response, array( 'selector' => 'main', 'remove' => 'script', 'find' => '/alpha/', 'replace' => 'beta', 'rewrite_urls' => 'true' ) );
	rcs_fp_assert( str_contains( $html, 'https://source.rcs.test/path/next' ) && str_contains( $html, 'beta' ) && ! str_contains( $html, '<script' ), 'Snapshot skipped shared transforms.' );
	rcs_fp_assert( 'alpha' === $plugin->render_fetched( array( 'url' => 'https://source.rcs.test/', 'body' => '<p>alpha</p>' ), array( 'strip_tags' => 'true' ) ), 'Snapshot did not strip tags.' );
	rcs_fp_assert( str_contains( $plugin->render_fetched( $response, array( 'htmlentities' => 'true' ) ), '&lt;main&gt;' ), 'Snapshot did not encode entities.' );
	$bad = static fn() => array(); add_filter( 'remote_content_shortcode_return', $bad );
	try { rcs_fp_assert( '' === $plugin->render_fetched( $response, array() ), 'Malformed output filter returned unsanitized data.' ); } finally { remove_filter( 'remote_content_shortcode_return', $bad ); }
};
$old_user = get_current_user_id(); $old_request = $_REQUEST;
$old_options = array(); foreach ( array( Remote_Content_Shortcode_Connections::OPTION, Remote_Content_Shortcode_Cache::INDEX ) as $option ) { $old_options[ $option ] = get_option( $option, null ); }
$admin = get_user_by( 'login', 'rcs_admin' ); $admin_id = $admin ? $admin->ID : 1; $failures = 0;
try {
	foreach ( $tests as $name => $test ) {
		wp_set_current_user( $admin_id ); delete_option( Remote_Content_Shortcode_Connections::OPTION );
		try { $test(); echo "PASS $name\n"; } catch ( Throwable $error ) { ++$failures; echo "FAIL $name: " . $error->getMessage() . "\n"; }
	}
} finally {
	foreach ( $old_options as $option => $value ) { null === $value ? delete_option( $option ) : update_option( $option, $value, false ); }
	wp_set_current_user( $old_user ); $_REQUEST = $old_request;
}
if ( $failures ) { WP_CLI::error( "$failures Free platform boundary failures" ); }
echo count( $tests ) . " Free platform boundary groups passed\n";
