<?php
/** Public renderer boundaries. Run with wp eval-file in the isolated DNS fixture site. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once getenv( 'RCS_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/remote-content-shortcode.php';

$plugin = RemoteContentShortcode::get_instance();
$old_user = get_current_user_id();
$old_get = $_GET;
$calls = array();
$reply = '<p>Boundary content</p>';
$tests = array();
$assert = static function ( $ok, $message ) {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
$response = static fn( $status = 200, $headers = array(), $body = '' ) => array( 'headers' => $headers, 'body' => $body, 'response' => array( 'code' => $status ) );
$intercept = static function ( $pre, $args, $url ) use ( &$calls, &$reply, $response ) {
	$calls[] = array( 'url' => $url, 'args' => $args );
	return is_callable( $reply ) ? $reply( $args, $url ) : ( is_wp_error( $reply ) || is_array( $reply ) ? $reply : $response( 200, array(), $reply ) );
};
add_filter( 'pre_http_request', $intercept, 10, 3 );
$render = static fn( $atts = array(), $content = null ) => $plugin->remote_content_shortcode( array_merge( array( 'url' => 'https://fixture.rcs.test/content', 'cache' => 'false' ), $atts ), $content );
$diagnose = static function ( $atts = array() ) use ( $plugin ) {
	wp_set_current_user( 1 );
	return $plugin->diagnose( array_merge( array( 'url' => 'https://fixture.rcs.test/content', 'cache' => 'false' ), $atts ) );
};
$error_is = static function ( $value, $code ) use ( $assert ) {
	$assert( is_wp_error( $value ) && $value->get_error_code() === $code, 'Expected WP_Error ' . $code );
};
$failed = static function ( $atts, $code, $network = 0 ) use ( $diagnose, $assert, &$calls ) {
	$result = $diagnose( $atts );
	$assert( false === $result['ok'] && $code === $result['error_code'] && '' === $result['html'], 'Unexpected diagnostic: ' . wp_json_encode( $result ) );
	$assert( $network === count( $calls ), 'Unexpected HTTP request count: ' . count( $calls ) );
};

$tests['privileged APIs reject subscribers and anonymous callers'] = static function () use ( $plugin, $error_is ) {
	$id = wp_insert_user( array( 'user_login' => 'rcs_boundary_subscriber', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	try {
		foreach ( array( 0, $id ) as $user ) {
			wp_set_current_user( $user );
			$error_is( $plugin->diagnose( array() ), 'forbidden' );
			$error_is( $plugin->clear_cache(), 'forbidden' );
		}
	} finally {
		wp_set_current_user( 0 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $id );
	}
};
$tests['diagnose rejects non-array attributes before fetching'] = static function () use ( $plugin, $error_is, $assert, &$calls ) {
	wp_set_current_user( 1 );
	foreach ( array( null, false, 'url', 1, new stdClass() ) as $atts ) { $error_is( $plugin->diagnose( $atts ), 'invalid_attributes' ); }
	$assert( ! $calls, 'Invalid diagnose input reached HTTP' );
};
$tests['cache API rejects invalid URL types and structures'] = static function () use ( $plugin, $error_is ) {
	wp_set_current_user( 1 );
	foreach ( array( null, false, array(), new stdClass(), 'relative', 'https://', 'ftp://fixture.rcs.test/', 'https://user:secret@fixture.rcs.test/', str_repeat( 'x', 8193 ) ) as $url ) {
		$error_is( $plugin->clear_cache( $url ), 'invalid_url' );
	}
};
$tests['cache API reports an active mutation lock without deleting it'] = static function () use ( $plugin, $error_is, $assert ) {
	wp_set_current_user( 1 );
	$token = ( time() + 60 ) . ':renderer-boundaries';
	$assert( add_option( Remote_Content_Shortcode_Cache::LOCK, $token, '', false ), 'Could not reserve test cache lock' );
	try {
		$error_is( $plugin->clear_cache(), 'cache_busy' );
		$assert( $token === get_option( Remote_Content_Shortcode_Cache::LOCK ), 'Clear released another owner lock' );
	} finally { delete_option( Remote_Content_Shortcode_Cache::LOCK ); }
};
$tests['empty cache and canonical URL clearing return counts'] = static function () use ( $plugin, $assert ) {
	wp_set_current_user( 1 );
	$assert( 0 === $plugin->clear_cache( 'HTTPS://FIXTURE.RCS.TEST:443/content' ), 'Canonical URL clear did not return zero' );
	$assert( 0 === $plugin->clear_cache(), 'Empty global clear did not return zero' );
};
$tests['non-array shortcode input fails as a missing URL'] = static function () use ( $plugin, $assert, &$calls ) {
	foreach ( array( null, false, 'attributes', new stdClass() ) as $atts ) {
		$assert( '' === $plugin->remote_content_shortcode( $atts ), 'Invalid input produced output' );
	}
	$assert( ! $calls, 'Invalid shortcode input reached HTTP' );
};
foreach ( array( null, array(), new stdClass() ) as $i => $value ) {
	$tests[ 'non-scalar shortcode attribute ' . $i ] = static function () use ( $failed, $value ) {
		$failed( array( 'selector' => $value ), 'invalid_attributes' );
	};
}
foreach ( array( '', 'HEAD', 'PUT', 'DELETE', ' GET ' ) as $method ) {
	$tests[ 'unsupported method ' . var_export( $method, true ) ] = static function () use ( $failed, $method ) {
		$failed( array( 'method' => $method ), 'invalid_method' );
	};
}
$tests['POST normalizes case and preserves an empty string body'] = static function () use ( $render, $assert, &$calls ) {
	$assert( '<p>Boundary content</p>' === $render( array( 'method' => 'post', 'timeout' => '-10' ) ), 'Lowercase POST failed' );
	$args = $calls[0]['args'];
	$assert( 'POST' === $args['method'] && '' === $args['body'] && $args['timeout'] > 0 && $args['timeout'] <= 1, 'POST method, empty body, or timeout normalization failed' );
	$assert( true === $args['sslverify'] && 0 === $args['redirection'] && 1048576 === $args['limit_response_size'] && array() === $args['cookies'], 'Transport safety arguments changed' );
};
$tests['GET does not send enclosed content as a request body'] = static function () use ( $render, $assert, &$calls ) {
	$render( array(), 'not a GET body' );
	$assert( ! isset( $calls[0]['args']['body'] ), 'GET sent enclosed content' );
};
foreach ( array( null, false, array(), new stdClass(), 42 ) as $i => $value ) {
	if ( null === $value ) { continue; }
	$tests[ 'postfields filter rejects non-string result ' . $i ] = static function () use ( $failed, $value ) {
		add_filter( 'remote_content_shortcode_postfields', static fn() => $value );
		$failed( array( 'method' => 'POST' ), 'invalid_post_body' );
	};
}
$tests['postfields filter cannot exceed request body budget'] = static function () use ( $failed ) {
	add_filter( 'remote_content_shortcode_postfields', static fn() => str_repeat( 'x', 1048577 ) );
	$failed( array( 'method' => 'POST' ), 'post_body_too_large' );
};
$tests['POST accepts exactly one MiB of request data'] = static function () use ( $render, $assert, &$calls ) {
	$body = str_repeat( 'x', 1048576 );
	$assert( '<p>Boundary content</p>' === $render( array( 'method' => 'POST' ), $body ), 'Boundary size POST body was rejected' );
	$assert( $body === $calls[0]['args']['body'], 'POST body was truncated or modified' );
};
foreach ( array( null, false, 'secret', new stdClass(), array( 'other' => array() ) ) as $i => $registry ) {
	$tests[ 'malformed credential registry fails closed ' . $i ] = static function () use ( $failed, $registry ) {
		add_filter( 'remote_content_shortcode_credentials', static fn() => $registry );
		$failed( array( 'userpwd' => 'unconfigured' ), 'unconfigured_credentials' );
	};
}
$tests['empty forwarded query key is rejected'] = static function () use ( $failed ) { $failed( array( 'params' => '=value' ), 'invalid_query_parameter' ); };
$tests['forwarded query strings preserve zeros, quotes, and equals'] = static function () use ( $render, $assert, &$calls ) {
	$_GET = array( 'zero' => '0', 'quote' => wp_slash( 'a"b\'c' ) );
	$render( array( 'params' => ' , zero,quote,default=a=b,missing ', 'url' => 'https://fixture.rcs.test/content?existing=one&amp;two=2' ) );
	parse_str( wp_parse_url( $calls[0]['url'], PHP_URL_QUERY ), $query );
	$assert( array( 'existing' => 'one', 'two' => '2', 'zero' => '0', 'quote' => 'a"b\'c', 'default' => 'a=b' ) === $query, 'Query values were lost, double encoded, or inherited' );
};
$tests['forwarding ignores cookie and POST values'] = static function () use ( $render, $assert, &$calls ) {
	$_POST['private'] = 'post-secret';
	$_COOKIE['private'] = 'cookie-secret';
	try {
		$render( array( 'params' => 'private' ) );
		$assert( null === wp_parse_url( $calls[0]['url'], PHP_URL_QUERY ), 'Non-GET value was forwarded' );
	} finally { unset( $_POST['private'], $_COOKIE['private'] ); }
};
foreach ( array( null, false, array(), new stdClass(), str_repeat( 'x', 8193 ) ) as $i => $value ) {
	$tests[ 'URL filter rejects malformed result ' . $i ] = static function () use ( $failed, $value ) {
		add_filter( 'remote_content_shortcode_url', static fn() => $value );
		$failed( array(), 'invalid_url' );
	};
}
foreach ( array( '//fixture.rcs.test/path', '/content', 'mailto:person@example.com', 'https://fixture.rcs.test:65536/', 'https://:443/', 'https://user@fixture.rcs.test/', 'https://fixture.rcs.test/' . str_repeat( 'x', 8192 ), 'http://127.1/', 'http://0177.0.0.1/', 'http://0x7f000001/', 'http://0.0.0.0/', 'http://192.168.1.1/', 'http://172.16.0.1/', 'http://[::ffff:127.0.0.1]/', 'http://[fe80::1]/', 'http://[2001:db8::1]/' ) as $url ) {
	$tests[ 'unsafe or malformed URL ' . substr( $url, 0, 80 ) ] = static function () use ( $failed, $url ) { $failed( array( 'url' => $url ), 'invalid_url' ); };
}
$tests['public IPv4 literal uses the validated request path'] = static function () use ( $render, $assert, &$calls ) {
	$assert( '<p>Boundary content</p>' === $render( array( 'url' => 'http://11.231.47.10/content' ) ), 'Public IP literal was rejected' );
	$assert( 'http://11.231.47.10/content' === $calls[0]['url'], 'IP literal changed unexpectedly' );
};
$tests['overlong DNS labels fail IDNA normalization before HTTP'] = static function () use ( $failed ) {
	$failed( array( 'url' => 'https://' . str_repeat( 'a', 64 ) . '.rcs.test/content' ), 'invalid_url' );
};
$tests['dual-stack hostname accepts only globally routable DNS answers'] = static function () use ( $render, $assert, &$calls ) {
	$assert( '<p>Boundary content</p>' === $render( array( 'url' => 'https://dual-stack.rcs.test/content' ) ), 'Public A and AAAA records were rejected' );
	$assert( 1 === count( $calls ) && 'https://dual-stack.rcs.test/content' === $calls[0]['url'], 'Dual-stack request changed destination' );
};
$tests['public IPv4 cannot conceal a private IPv6 DNS answer'] = static function () use ( $failed ) {
	$failed( array( 'url' => 'https://mixed-stack.rcs.test/content' ), 'invalid_url' );
};
$tests['AAAA lookup failure closes the request before HTTP'] = static function () use ( $failed ) {
	$failed( array( 'url' => 'https://aaaa-failure.rcs.test/content' ), 'invalid_url' );
};
$tests['WordPress private-host exemption cannot bypass public IP validation'] = static function () use ( $failed ) {
	add_filter( 'http_request_host_is_external', '__return_true' );
	$failed( array( 'url' => 'http://10.0.0.1/content' ), 'invalid_url' );
};
$tests['WordPress private-host exemption cannot bypass private DNS validation'] = static function () use ( $failed ) {
	add_filter( 'http_request_host_is_external', '__return_true' );
	$failed( array( 'url' => 'http://private.rcs.test/content' ), 'invalid_url' );
};
$tests['same-site exception cannot accept a hostname without IPv4 resolution'] = static function () use ( $failed ) {
	$previous = get_option( 'home' );
	update_option( 'home', 'https://ipv6-only.rcs.test' );
	try { $failed( array( 'url' => 'https://ipv6-only.rcs.test/content' ), 'invalid_url' ); }
	finally { update_option( 'home', $previous ); }
};
$tests['internationalized hostname uses its canonical ASCII destination'] = static function () use ( $render, $assert, &$calls ) {
	$assert( '<p>Boundary content</p>' === $render( array( 'url' => 'https://bücher.rcs.test/content' ) ), 'Internationalized hostname was rejected' );
	$assert( 1 === count( $calls ) && 'https://xn--bcher-kva.rcs.test/content' === $calls[0]['url'], 'IDNA canonical destination was not used for HTTP' );
};
$tests['diagnostic timeout clamps scalar and malformed values'] = static function () use ( $diagnose, $assert, &$calls ) {
	foreach ( array( array(), new stdClass(), null, 100, 0, -5, '3' ) as $timeout ) {
		$result = $diagnose( array( 'timeout' => $timeout ) );
		$args = $calls[ count( $calls ) - 1 ]['args'];
		$limit = '3' === $timeout ? 3 : ( is_scalar( $timeout ) && (int) $timeout <= 0 ? 1 : 5 );
		$assert( true === $result['ok'] && $args['timeout'] > 0 && $args['timeout'] <= $limit && $args['timeout'] > $limit - 0.2, 'Diagnostic timeout was not clamped' );
	}
};
$tests['attribute decoding accepts quoted and unquoted encoded values'] = static function () use ( $render, $assert ) {
	$assert( '<p>Changed</p>' === $render( array( 'decode_atts' => 'TRUE', 'find' => '~Boundary content~', 'replace' => '&quot;Changed&quot;' ) ), 'Attribute decoding changed unquoted values' );
};
foreach ( array( array(), null, false, new stdClass() ) as $i => $value ) {
	$tests[ 'phpQuery filter rejects non-string result ' . $i ] = static function () use ( $failed, $value ) {
		add_filter( 'remote_content_shortcode_phpQuery', static fn() => $value );
		$failed( array( 'selector' => 'p' ), 'invalid_html_input', 1 );
	};
}
$tests['phpQuery filter rejects oversized HTML'] = static function () use ( $failed ) {
	add_filter( 'remote_content_shortcode_phpQuery', static fn() => str_repeat( 'x', 1048577 ) );
	$failed( array( 'selector' => 'p' ), 'invalid_html_input', 1 );
};
foreach ( array( 'selector', 'remove' ) as $attribute ) {
	$tests[ 'oversized ' . $attribute . ' rejected before parsing' ] = static function () use ( $failed, $attribute ) { $failed( array( $attribute => str_repeat( 'p', 2049 ) ), 'invalid_html_input', 1 ); };
}
foreach ( array( array( 'find' => str_repeat( 'x', 1025 ) ), array( 'find' => '~x~', 'replace' => str_repeat( 'x', 4097 ) ) ) as $i => $atts ) {
	$tests[ 'regex attribute length boundary ' . $i ] = static function () use ( $failed, $atts ) { $failed( $atts, 'invalid_regex', 1 ); };
}
foreach ( array( null, false, array(), new stdClass(), 0 ) as $i => $value ) {
	$tests[ 'return filter rejects non-string result ' . $i ] = static function () use ( $failed, $value ) {
		add_filter( 'remote_content_shortcode_return', static fn() => $value );
		$failed( array(), 'invalid_output', 1 );
	};
}
$tests['custom transport errors are redacted but actions retain details'] = static function () use ( $diagnose, $assert, &$reply ) {
	$reply = new WP_Error( 'private_provider_code', 'password=secret', array( 'token' => 'private' ) );
	$seen = null;
	$seen_url = null;
	add_action( 'remote_content_shortcode_error', static function ( $error, $url ) use ( &$seen, &$seen_url ) { $seen = $error; $seen_url = $url; }, 10, 2 );
	$result = $diagnose( array( 'url' => 'https://fixture.rcs.test/content?token=private', 'fallback' => '<b>Unavailable</b><script>unsafe</script>' ) );
	$assert( false === $result['ok'] && 'request_failed' === $result['error_code'] && str_contains( $result['html'], '<b>Unavailable</b>' ) && ! str_contains( $result['html'], '<script' ), 'Failure metadata or safe fallback changed' );
	$assert( $seen === $reply && str_contains( $seen_url, 'token=private' ), 'Diagnostic action lost original details' );
	$assert( ! str_contains( wp_json_encode( $result ), 'password' ) && ! str_contains( wp_json_encode( $result ), 'token' ), 'Public diagnostic exposed provider details' );
};
$tests['diagnostics reset error and status after a later success'] = static function () use ( $diagnose, $assert, &$reply ) {
	$reply = new WP_Error( 'http_request_failed' );
	$diagnose();
	$reply = '<p>Recovered</p>';
	$result = $diagnose();
	$assert( true === $result['ok'] && '' === $result['error_code'] && 200 === $result['http_status'] && null === $result['selector_matches'] && is_numeric( $result['duration_ms'] ), 'Previous failure contaminated diagnostics' );
};
foreach ( array( 101, 199, 300, 304, 400, 500 ) as $status ) {
	$tests[ 'non-success HTTP status ' . $status ] = static function () use ( $diagnose, $assert, &$reply, $response, $status ) {
		$reply = $response( $status, array(), 'private error page' );
		$result = $diagnose();
		$assert( false === $result['ok'] && 'http_status_error' === $result['error_code'] && $status === $result['http_status'] && '' === $result['html'], 'Non-success HTTP response leaked or lost its status' );
	};
}
foreach ( array( 201, 204, 299 ) as $status ) {
	$tests[ 'successful HTTP status ' . $status ] = static function () use ( $diagnose, $assert, &$reply, $response, $status ) {
		$reply = $response( $status, array(), 'successful body' );
		$result = $diagnose();
		$assert( true === $result['ok'] && $status === $result['http_status'] && 'successful body' === $result['html'], 'Successful HTTP status was rejected' );
	};
}
foreach ( array( 301, 302, 303, 307, 308 ) as $status ) {
	$tests[ 'GET follows redirect status ' . $status ] = static function () use ( $render, $assert, &$reply, &$calls, $response, $status ) {
		$reply = static fn( $args, $url ) => str_ends_with( $url, '/content' ) ? $response( $status, array( 'location' => '../next?value=1' ) ) : $response( 200, array(), 'redirect body' );
		$assert( 'redirect body' === $render() && 2 === count( $calls ) && 'https://fixture.rcs.test/next?value=1' === $calls[1]['url'], 'Relative redirect did not preserve GET semantics' );
	};
}
foreach ( array( array(), array( 'location' => '' ), array( 'location' => array( '/a', '/b' ) ) ) as $i => $headers ) {
	$tests[ 'missing or malformed redirect location ' . $i ] = static function () use ( $failed, &$reply, $response, $headers ) {
		$reply = $response( 302, $headers );
		$failed( array(), 'invalid_redirect', 1 );
	};
}
$tests['redirect chains stop after three followed locations'] = static function () use ( $failed, &$reply, $response ) {
	$reply = $response( 308, array( 'location' => '/loop' ) );
	$failed( array(), 'redirect_not_allowed', 4 );
};
$tests['redirect deadline is shared across hops'] = static function () use ( $failed, &$reply, $response ) {
	$reply = static function () use ( $response ) { usleep( 1100000 ); return $response( 302, array( 'location' => '/next' ) ); };
	$failed( array( 'timeout' => 1 ), 'request_timeout', 1 );
};
$tests['redirect to a non-HTTP scheme is rejected before another request'] = static function () use ( $failed, &$reply, $response ) {
	$reply = $response( 302, array( 'location' => 'file:///etc/passwd' ) );
	$failed( array(), 'invalid_url', 1 );
};
$tests['just under one MiB of response text is accepted'] = static function () use ( $render, $assert, &$reply ) {
	$reply = str_repeat( 'x', 1048575 );
	$assert( $reply === $render(), 'Valid response at size boundary was rejected or truncated' );
};
$tests['URL rewriting failures use redacted diagnostics and the safe fallback'] = static function () use ( $diagnose, $assert, &$reply ) {
	$reply = str_repeat( '&', 220000 );
	$result = $diagnose( array( 'rewrite_urls' => 'true', 'fallback' => '<b>Unavailable</b>' ) );
	$assert( false === $result['ok'] && 'html_too_large' === $result['error_code'] && '<b>Unavailable</b>' === $result['html'], 'Rewriting failure bypassed diagnostics or fallback handling' );
};

$failures = 0;
$hooks = array( 'remote_content_shortcode_url', 'remote_content_shortcode_postfields', 'remote_content_shortcode_phpQuery', 'remote_content_shortcode_return', 'remote_content_shortcode_error', 'remote_content_shortcode_credentials', 'http_request_host_is_external' );
try {
	foreach ( $tests as $name => $test ) {
		$calls = array();
		$reply = '<p>Boundary content</p>';
		$_GET = array();
		wp_set_current_user( 0 );
		Remote_Content_Shortcode_Cache::clear();
		foreach ( $hooks as $hook ) { remove_all_filters( $hook ); }
		try { $test(); echo "PASS $name\n"; }
		catch ( Throwable $error ) { ++$failures; echo "FAIL $name: " . $error->getMessage() . "\n"; }
	}
} finally {
	foreach ( $hooks as $hook ) { remove_all_filters( $hook ); }
	remove_filter( 'pre_http_request', $intercept, 10 );
	Remote_Content_Shortcode_Cache::clear();
	wp_set_current_user( $old_user );
	$_GET = $old_get;
}
echo count( $tests ) . " renderer boundary tests, $failures failures\n";
if ( $failures ) { WP_CLI::error( "$failures renderer boundary failures" ); }
