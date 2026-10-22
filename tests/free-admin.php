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
$document = static function ( $html ) {
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	try { $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html ); }
	finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
	return new DOMXPath( $document );
};
$tests['diagnostic previews isolate remote styles and landmarks'] = static function () use ( $document ) {
	$render = new ReflectionMethod( Remote_Content_Shortcode_Admin::class, 'result' );
	ob_start();
	$render->invoke( null, array( 'html' => '<main><div id="preview-overlay" style="position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:2147483647">Remote preview</div><script>alert(1)</script></main>' ), 'builder' );
	$html = ob_get_clean(); $xpath = $document( $html ); $frame = $xpath->query( '//iframe' )->item( 0 );
	rcs_free_assert( $frame instanceof DOMElement && $frame->hasAttribute( 'sandbox' ) && '' === $frame->getAttribute( 'sandbox' ), 'Preview must use an iframe without sandbox permissions.' );
	rcs_free_assert( '' !== $frame->getAttribute( 'title' ) && 'no-referrer' === $frame->getAttribute( 'referrerpolicy' ), 'Preview needs an accessible title and referrer protection.' );
	rcs_free_assert( 0 === $xpath->query( '//main|//*[@id="preview-overlay"]|//script' )->length, 'Remote markup escaped into the admin document.' );
	$preview = $document( $frame->getAttribute( 'srcdoc' ) );
	rcs_free_assert( 1 === $preview->query( '//*[@id="preview-overlay"]' )->length && 0 === $preview->query( '//script' )->length, 'Isolated preview lost its content or bypassed sanitization.' );
};
$tests['sanitized diagnostic HTML has an accessible name'] = static function () use ( $document ) {
	$render = new ReflectionMethod( Remote_Content_Shortcode_Admin::class, 'result' );
	ob_start(); $render->invoke( null, array( 'html' => '<p>Preview</p>' ), 'builder' ); $xpath = $document( ob_get_clean() );
	$field = $xpath->query( '//details/textarea' )->item( 0 );
	rcs_free_assert( $field instanceof DOMElement && __( 'Sanitized HTML', 'remote-content-shortcode' ) === $field->getAttribute( 'aria-label' ), 'Sanitized HTML textarea is unlabeled.' );
};
$tests['builder retains public fields after generation and errors'] = static function () use ( $page, $document ) {
	$input = array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => 'generate', 'url' => 'http://source.rcs.test/ok?language=en', 'selector' => '[title="example"]', 'remove' => 'aside', 'userpwd' => 'public_alias', 'fallback' => '</textarea><b>Offline</b>', 'timeout' => '30', 'method' => 'POST', 'find' => '/old/', 'replace' => 'new', 'params' => 'language=en', 'htmlentities' => 'true', 'strip_tags' => 'true', 'cache' => 'false', 'cache_ttl' => '300', 'stale_ttl' => '600', 'rewrite_urls' => 'true', 'body' => 'q=hello&limit=5' );
	foreach ( array( 'generate', 'test' ) as $action ) {
		$input['rcs_action'] = $action; $xpath = $document( $page( 'builder', wp_slash( $input ) ) );
		foreach ( $input as $name => $value ) {
			if ( in_array( $name, array( '_wpnonce', 'rcs_action' ), true ) ) { continue; }
			$field = $xpath->query( '//*[@name="' . $name . '"]' )->item( 0 );
			$actual = $field instanceof DOMElement ? ( 'textarea' === $field->tagName ? $field->textContent : ( 'select' === $field->tagName ? $xpath->evaluate( 'string(option[@selected]/@value)', $field ) : $field->getAttribute( 'value' ) ) ) : null;
			rcs_free_assert( $value === $actual, "Builder lost $name after $action." );
		}
	}
};
$tests['failed connection creation retains scope but never the secret'] = static function () use ( $page, $document, $fixture ) {
	$input = $fixture( 'draft-connection' ); $input['connection_action'] = 'save'; $input['origin'] = 'http://example.com'; $input['path'] = '/draft'; $input['auth'] = 'api_key'; $input['header'] = 'X-Draft-Key'; $input['methods'] = array( 'POST' );
	$html = $page( 'connections', $input ); $xpath = $document( $html );
	foreach ( array( 'alias', 'origin', 'path', 'header' ) as $name ) { rcs_free_assert( $input[ $name ] === $xpath->evaluate( 'string(//input[@name="' . $name . '"]/@value)' ), "Connection lost $name after validation." ); }
	rcs_free_assert( 'api_key' === $xpath->evaluate( 'string(//select[@name="auth"]/option[@selected]/@value)' ) && 'POST' === $xpath->evaluate( 'string(//input[@name="methods[]"][@checked]/@value)' ), 'Connection lost its authentication or method selection.' );
	rcs_free_assert( ! str_contains( $html, $input['secret'] ) && '' === $xpath->evaluate( 'string(//input[@name="secret"]/@value)' ), 'Failed save echoed the connection secret.' );
};
$tests['failed connection edits retain only the submitted form'] = static function () use ( $page, $document, $fixture ) {
	$original = $fixture(); rcs_free_assert( 'free-test' === Remote_Content_Shortcode_Connections::save( $original ), 'Could not create edit fixture.' );
	$input = $original; $input['connection_action'] = 'save'; $input['origin'] = 'http://edited.example.com'; $input['path'] = '/edited'; unset( $input['methods'] );
	$xpath = $document( $page( 'connections', $input ) ); $form = $xpath->query( '//form[.//input[@name="alias"][@value="free-test"]]' )->item( 0 );
	rcs_free_assert( $form instanceof DOMElement && $input['origin'] === $xpath->evaluate( 'string(.//input[@name="origin"]/@value)', $form ) && '/edited' === $xpath->evaluate( 'string(.//input[@name="path"]/@value)', $form ), 'Failed edit did not retain the submitted scope.' );
	rcs_free_assert( 0 === $xpath->query( './/input[@name="methods[]"][@checked]', $form )->length, 'Failed edit restored a method the user unchecked.' );
	rcs_free_assert( 'https://example.com' === Remote_Content_Shortcode_Connections::all()['free-test']['origin'], 'Failed edit changed the saved connection.' );
	rcs_free_assert( '' === $xpath->evaluate( 'string(//form[.//input[@name="alias"][@value=""]]//input[@name="origin"]/@value)' ), 'Failed edit also populated the add form.' );
};
$tests['retained forms exclude inline credentials and malformed values'] = static function () use ( $page ) {
	$html = $page( 'builder', array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => 'test', 'url' => 'https://user:inline-password@example.com/?token=private-query', 'userpwd' => 'raw:inline-password', 'body' => array( 'bad' ), 'selector' => array( 'bad' ) ) );
	rcs_free_assert( ! str_contains( $html, 'inline-password' ) && ! str_contains( $html, 'private-query' ), 'Builder retained inline credentials.' );
	$html = $page( 'connections', array( '_wpnonce' => wp_create_nonce( 'rcs_connections' ), 'connection_action' => 'save', 'alias' => 'raw:inline-password', 'origin' => 'https://user:inline-password@example.com/?token=private-query', 'path' => array(), 'secret' => 'private-secret' ) );
	rcs_free_assert( ! str_contains( $html, 'inline-password' ) && ! str_contains( $html, 'private-query' ) && ! str_contains( $html, 'private-secret' ), 'Connection form retained credentials or secrets.' );
};
$tests['retained form fields stay bounded and use supported choices'] = static function () use ( $page, $document ) {
	$input = array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => 'generate', 'url' => 'https://example.com/', 'fallback' => str_repeat( 'f', 4096 ), 'body' => str_repeat( 'b', 65536 ) );
	$xpath = $document( $page( 'builder', $input ) );
	rcs_free_assert( $input['fallback'] === $xpath->evaluate( 'string(//input[@name="fallback"]/@value)' ) && $input['body'] === $xpath->evaluate( 'string(//textarea[@name="body"])' ), 'Bounded public fields were truncated.' );
	$input['fallback'] .= 'f'; $input['body'] .= 'b'; $input['cache'] = 'INVALID'; $input['method'] = 'DELETE'; $input['url'] = 'http://example.com:invalid';
	$xpath = $document( $page( 'builder', $input ) );
	foreach ( array( 'fallback', 'url' ) as $name ) { rcs_free_assert( '' === $xpath->evaluate( 'string(//input[@name="' . $name . '"]/@value)' ), 'Oversized or malformed field was retained.' ); }
	rcs_free_assert( '' === $xpath->evaluate( 'string(//textarea[@name="body"])' ) && 'true' === $xpath->evaluate( 'string(//select[@name="cache"]/option[@selected]/@value)' ) && 'GET' === $xpath->evaluate( 'string(//select[@name="method"]/option[@selected]/@value)' ), 'Oversized body or unsupported choice was retained.' );
	$html = $page( 'connections', array( '_wpnonce' => wp_create_nonce( 'rcs_connections' ), 'connection_action' => 'save', 'alias' => 'public-alias', 'origin' => 'https://example.com/?token=private-query', 'auth' => 'oauth', 'methods' => array( array( 'POST' ), 'DELETE' ) ) );
	rcs_free_assert( ! str_contains( $html, 'private-query' ), 'Invalid origin query was reflected.' );
	$xpath = $document( $html );
	rcs_free_assert( 'bearer' === $xpath->evaluate( 'string(//select[@name="auth"]/option[@selected]/@value)' ) && 0 === $xpath->query( '//input[@name="methods[]"][@checked]' )->length, 'Malformed authentication or methods reached the form.' );
};
$tests['builder allows full shortcode timeouts while diagnostics stay bounded'] = static function () use ( $page, $document ) {
	$xpath = $document( $page( 'builder' ) );
	rcs_free_assert( '30' === $xpath->evaluate( 'string(//input[@name="timeout"]/@max)' ), 'Builder still limits generated shortcodes to five seconds.' );
	$seen = array(); $observe = static function ( $preempt, $args ) use ( &$seen ) { $seen[] = $args['timeout']; return new WP_Error( 'timeout-probe' ); };
	add_filter( 'pre_http_request', $observe, PHP_INT_MAX, 2 );
	try { Remote_Content_Shortcode_Admin::process_action( array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => 'test', 'url' => 'http://source.rcs.test/ok', 'timeout' => '30' ) ); }
	finally { remove_filter( 'pre_http_request', $observe, PHP_INT_MAX ); }
	rcs_free_assert( $seen && max( $seen ) <= 5, 'Diagnostic requests exceeded five seconds.' );
};
$tests['connection text fields use WordPress control styling'] = static function () use ( $page, $document ) {
	$xpath = $document( $page( 'connections' ) );
	foreach ( $xpath->query( '//input[contains(@class,"regular-text")]' ) as $field ) { rcs_free_assert( 'text' === $field->getAttribute( 'type' ), 'Connection field omits its text input type.' ); }
};
$tests['usage is paginated and support copy omits content and secrets'] = static function () use ( $page ) {
	$ids = array();
	try {
		for ( $i = 0; $i < 23; ++$i ) { $ids[] = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Usage <unsafe> ' . $i, 'post_content' => '[remote_content url="https://example.com/?private=unique-query-secret"]' ) ); }
		$html = $page( 'usage' ); rcs_free_assert( 20 === substr_count( $html, 'data-rcs-usage=' ) && str_contains( $html, 'usage_page=2' ) && ! str_contains( $html, 'unique-query-secret' ), 'Usage is unbounded or disclosed content.' );
		$html = $page( 'help' ); rcs_free_assert( str_contains( $html, 'Support summary' ) && ! str_contains( $html, 'unique-query-secret' ) && ! str_contains( $html, 'test-secret-unique' ), 'Support copy leaked secrets.' );
	} finally { foreach ( $ids as $id ) { wp_delete_post( $id, true ); } }
};
$tests['Pro overview is restrained, accurate and makes no background requests'] = static function () use ( $page ) {
	$requests = 0; $block = static function () use ( &$requests ) { ++$requests; return new WP_Error( 'unexpected_promotion_request' ); };
	add_filter( 'pre_http_request', $block );
	try {
		foreach ( array( 'builder', 'connections', 'cache', 'usage', 'help' ) as $tab ) {
			$html = $page( $tab );
			rcs_free_assert( 1 === substr_count( $html, 'id="rcs-pro-overview"' ) && str_contains( $html, 'aria-labelledby="rcs-pro-heading"' ), 'Expected one accessible Pro overview on the plugin page.' );
			rcs_free_assert( str_contains( $html, 'OAuth' ) && str_contains( $html, 'Scheduled refreshes' ) && str_contains( $html, 'JSON templates' ) && str_contains( $html, 'remain included in Free' ), 'Pro overview obscures the Free/Pro feature boundary.' );
			rcs_free_assert( str_contains( $html, 'https://github.com/doublesharp/remote-content-shortcode#remote-content-shortcode-pro' ) && str_contains( $html, 'button button-secondary' ), 'Pro details do not have a quiet, working destination.' );
			if ( in_array( $tab, array( 'builder', 'connections', 'cache' ), true ) ) { rcs_free_assert( strpos( $html, 'id="rcs-pro-overview"' ) > strrpos( $html, '</form>' ), 'Promotion interrupted the Free controls.' ); }
		}
		rcs_free_assert( 0 === $requests, 'Pro overview phoned home while rendering.' );
	} finally { remove_filter( 'pre_http_request', $block ); }
};
$tests['Pro overview respects suppression and extension pages'] = static function () use ( $page ) {
	$hide = '__return_false'; add_filter( 'remote_content_shortcode_show_pro_overview', $hide );
	try { rcs_free_assert( ! str_contains( $page( 'builder' ), 'id="rcs-pro-overview"' ), 'Site owner could not suppress the Pro overview.' ); }
	finally { remove_filter( 'remote_content_shortcode_show_pro_overview', $hide ); }
	$tab = static function ( $tabs ) { $tabs['extension'] = 'Extension'; return $tabs; }; add_filter( 'remote_content_shortcode_admin_tabs', $tab );
	try { rcs_free_assert( ! str_contains( $page( 'extension' ), 'id="rcs-pro-overview"' ), 'Pro overview appeared on an extension page.' ); }
	finally { remove_filter( 'remote_content_shortcode_admin_tabs', $tab ); }
};
$tests['active Pro customers do not see an upgrade pitch'] = static function () use ( $page ) {
	define( 'RCS_PRO_VERSION', '2.0' );
	foreach ( array( 'builder', 'connections', 'help' ) as $tab ) { rcs_free_assert( ! str_contains( $page( $tab ), 'id="rcs-pro-overview"' ), 'Active Pro customer received an upgrade pitch.' ); }
};
$old = get_option( 'rcs_connections_v1', null ); $old_user = get_current_user_id(); $request = $_REQUEST; $failures = 0;
try { foreach ( $tests as $name => $test ) { wp_set_current_user( 1 ); delete_option( 'rcs_connections_v1' ); try { $test(); echo "PASS $name\n"; } catch ( Throwable $error ) { ++$failures; echo "FAIL $name: " . $error->getMessage() . "\n"; } } }
finally { null === $old ? delete_option( 'rcs_connections_v1' ) : update_option( 'rcs_connections_v1', $old, false ); wp_set_current_user( $old_user ); $_REQUEST = $request; }
if ( $failures ) { WP_CLI::error( "$failures Free admin failures" ); }
echo count( $tests ) . " Free admin cases passed\n";
