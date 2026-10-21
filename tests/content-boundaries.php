<?php
/** Run with wp eval-file tests/content-boundaries.php in a disposable WordPress site. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$base = dirname( getenv( 'RCS_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/remote-content-shortcode.php' );
foreach ( array( 'credentials', 'urls', 'html', 'regex' ) as $helper ) {
	require_once $base . '/inc/class-remote-content-' . $helper . '.php';
}
$cb_cases = array();
$cb_failures = 0;
function cb_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function cb_error( $actual, $code ) {
	cb_check( is_wp_error( $actual ) && $code === $actual->get_error_code(), 'Expected ' . $code . ', received ' . ( is_wp_error( $actual ) ? $actual->get_error_code() : get_debug_type( $actual ) ) );
}
function cb_source( $changes = array() ) {
	return array_merge( array( 'origin' => 'https://example.com', 'path' => '/content', 'type' => 'option', 'key' => 'rcs_cb_secret' ), $changes );
}
function cb_headers( $changes = array(), $url = 'https://example.com/content', $method = 'GET' ) {
	return Remote_Content_Shortcode_Credentials::headers( cb_source( $changes ), $url, $method );
}
function cb_attribute( $attribute, $value, $base = 'https://example.com/dir/page' ) {
	return Remote_Content_Shortcode_URLs::rewrite( '<a ' . $attribute . '="' . htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) . '">link</a>', $base );
}

foreach ( array( 'non-array source' => false, 'missing origin' => array(), 'non-string origin' => array( 'origin' => array() ) ) as $label => $source ) {
	$cb_cases[ 'credential configuration: ' . $label ] = function () use ( $source ) {
		cb_error( Remote_Content_Shortcode_Credentials::headers( $source, 'https://example.com/content', 'GET' ), 'unconfigured_credentials' );
	};
}
$cb_cases['credentials reject non-string destination'] = function () {
	cb_error( cb_headers( array(), array() ), 'unconfigured_credentials' );
};
foreach ( array(
	'malformed origin' => array( array( 'origin' => 'https://example.com:invalid' ), 'https://example.com/content' ),
	'malformed destination' => array( array(), 'https://example.com:invalid' ),
	'origin scheme' => array( array( 'origin' => 'http://example.com' ), 'https://example.com/content' ),
	'destination scheme' => array( array(), 'http://example.com/content' ),
	'origin scheme missing' => array( array( 'origin' => '//example.com' ), 'https://example.com/content' ),
	'destination scheme missing' => array( array(), '//example.com/content' ),
	'origin host missing' => array( array( 'origin' => 'https:/content' ), 'https://example.com/content' ),
	'destination host missing' => array( array(), 'https:/content' ),
	'origin username' => array( array( 'origin' => 'https://user@example.com' ), 'https://example.com/content' ),
	'origin password' => array( array( 'origin' => 'https://user:pass@example.com' ), 'https://example.com/content' ),
	'destination username' => array( array(), 'https://user@example.com/content' ),
	'destination password' => array( array(), 'https://user:pass@example.com/content' ),
	'different host' => array( array(), 'https://example.org/content' ),
	'different port' => array( array(), 'https://example.com:8443/content' ),
	'origin query' => array( array( 'origin' => 'https://example.com?x=1' ), 'https://example.com/content' ),
	'origin fragment' => array( array( 'origin' => 'https://example.com#x' ), 'https://example.com/content' ),
	'origin endpoint path' => array( array( 'origin' => 'https://example.com/content' ), 'https://example.com/content' ),
) as $label => $arguments ) {
	$cb_cases[ 'credential origin: ' . $label ] = function () use ( $arguments ) {
		cb_error( cb_headers( ...$arguments ), 'credential_origin_mismatch' );
	};
}
foreach ( array(
	'missing path' => array( 'path' => null ), 'non-string path' => array( 'path' => array() ), 'empty path' => array( 'path' => '' ),
	'relative path' => array( 'path' => 'content' ), 'query in path' => array( 'path' => '/content?x=1' ),
	'fragment in path' => array( 'path' => '/content#x' ), 'backslash in path' => array( 'path' => '/content\\extra' ),
	'whitespace in path' => array( 'path' => '/content extra' ), 'non-array methods' => array( 'methods' => 'GET' ),
	'empty methods' => array( 'methods' => array() ), 'wrong method' => array( 'methods' => array( 'POST' ) ),
	'non-string method entry' => array( 'methods' => array( 0 ) ), 'different endpoint' => array( 'path' => '/different' ),
) as $label => $changes ) {
	$cb_cases[ 'credential endpoint: ' . $label ] = function () use ( $changes ) {
		cb_error( cb_headers( $changes ), 'credential_endpoint_mismatch' );
	};
}
$cb_cases['credentials accept normalized hosts ports paths and explicit methods'] = function () {
	update_option( 'rcs_cb_secret', 'alice:secret' );
	$expected = array( 'Authorization' => 'Basic ' . base64_encode( 'alice:secret' ) );
	cb_check( $expected === cb_headers( array( 'origin' => 'https://EXAMPLE.com:443/' ), 'https://example.com/content?x=1#fragment' ), 'Equivalent origins or destination query rejected' );
	cb_check( $expected === cb_headers( array( 'path' => '/other/../content', 'methods' => array( 'POST' ) ), 'https://example.com/content', 'POST' ), 'Normalized configured endpoint or explicit POST rejected' );
	cb_check( $expected === cb_headers( array( 'origin' => 'https://example.com:8443' ), 'https://example.com:8443/content' ), 'Matching nondefault port rejected' );
};
foreach ( array( 'missing key' => array( 'key' => null ), 'key type' => array( 'key' => array() ), 'empty key' => array( 'key' => '' ), 'missing constant' => array( 'type' => 'constant', 'key' => 'RCS_CB_UNDEFINED_SECRET' ), 'missing option' => array( 'key' => 'rcs_cb_nonexistent_secret' ), 'missing storage' => array( 'type' => null ), 'unknown storage' => array( 'type' => 'unknown' ), 'unknown auth' => array( 'auth' => 'digest' ), 'missing post' => array( 'type' => 'post_meta', 'key' => '_rcs_cb_secret', 'post_id' => PHP_INT_MAX ) ) as $label => $changes ) {
	$cb_cases[ 'credential storage: ' . $label ] = function () use ( $changes ) {
		update_option( 'rcs_cb_secret', 'alice:secret' );
		cb_error( cb_headers( $changes ), 'invalid_credentials' );
	};
}
foreach ( array( 'array' => array( 'secret' ), 'empty' => '', 'oversized' => str_repeat( 'x', 8193 ), 'newline' => "secret\n", 'NUL' => "secret\0", 'DEL' => "secret\x7f", 'basic without colon' => 'secret' ) as $label => $value ) {
	$cb_cases[ 'credential value: ' . $label ] = function () use ( $value ) {
		update_option( 'rcs_cb_secret', $value );
		cb_error( cb_headers(), 'invalid_credentials' );
	};
}
$cb_cases['credential values accept the maximum length and reject bearer spaces'] = function () {
	$value = str_repeat( 'x', 8192 );
	update_option( 'rcs_cb_secret', $value );
	cb_check( array( 'Authorization' => 'Bearer ' . $value ) === cb_headers( array( 'auth' => 'bearer' ) ), '8192-byte bearer rejected' );
	update_option( 'rcs_cb_secret', ':' );
	cb_check( array( 'Authorization' => 'Basic ' . base64_encode( ':' ) ) === cb_headers(), 'Empty Basic components rejected' );
	update_option( 'rcs_cb_secret', 'two words' );
	cb_error( cb_headers( array( 'auth' => 'bearer' ) ), 'invalid_credentials' );
	cb_check( array( 'X-API-Key' => 'two words' ) === cb_headers( array( 'auth' => 'api_key' ) ), 'Spaces in API key value rejected' );
};
foreach ( array( 'non-string' => array(), 'too long' => 'X-' . str_repeat( 'a', 63 ), 'bad syntax' => 'X-Key_Name', 'forwarded' => 'X-Forwarded', 'original' => 'X-Original-URL', 'rewrite' => 'X-Rewrite-URL', 'method' => 'X-HTTP-Method-Override', 'method override' => 'X-Method-Override', 'real IP' => 'X-Real-IP', 'true client IP' => 'X-True-Client-IP', 'connecting IP' => 'X-Connecting-IP', 'CF connecting IP' => 'X-CF-Connecting-IP', 'remote IP' => 'X-Remote-IP', 'via' => 'X-Via', 'host' => 'X-Host', 'cookie' => 'X-Cookie', 'proxy' => 'X-Proxy-Token' ) as $label => $header ) {
	$cb_cases[ 'credential API header: ' . $label ] = function () use ( $header ) {
		update_option( 'rcs_cb_secret', 'secret' );
		cb_error( cb_headers( array( 'auth' => 'api_key', 'header' => $header ) ), 'invalid_credentials' );
	};
}
$cb_cases['API header accepts 64 characters and case insensitive allowed names'] = function () {
	update_option( 'rcs_cb_secret', 'secret' );
	foreach ( array( 'X-' . str_repeat( 'a', 62 ), 'api-key', 'x-token', 'X-Originality', 'X-Forwardedness' ) as $header ) {
		cb_check( array( $header => 'secret' ) === cb_headers( array( 'auth' => 'api_key', 'header' => $header ) ), 'Allowed header rejected: ' . $header );
	}
};

foreach ( array( 'non-string HTML' => array(), 'HTML bytes' => str_repeat( 'x', 1048577 ), 'markup count' => str_repeat( '<br>', 20001 ), 'DOM count' => str_repeat( '<br>', 19999 ) ) as $label => $html ) {
	$cb_cases[ 'URL input budget: ' . $label ] = function () use ( $html ) {
		cb_error( Remote_Content_Shortcode_URLs::rewrite( $html, 'https://example.com/' ), 'html_too_complex' );
	};
}
foreach ( array( 'non-string' => array(), 'too long' => 'https://example.com/' . str_repeat( 'x', 8192 ), 'malformed' => 'https://example.com:bad', 'scheme' => 'ftp://example.com/', 'scheme missing' => '//example.com/', 'host missing' => 'https:/path', 'username' => 'https://user@example.com/', 'password' => 'https://user:pass@example.com/', 'space' => 'https://example.com/a b', 'backslash' => 'https://example.com/a\\b', 'DEL' => "https://example.com/a\x7f" ) as $label => $base_url ) {
	$cb_cases[ 'URL base: ' . $label ] = function () use ( $base_url ) {
		cb_error( Remote_Content_Shortcode_URLs::rewrite( '<p>text</p>', $base_url ), 'invalid_url_base' );
	};
}
$cb_cases['URL empty input and exact input budget preserve content'] = function () {
	cb_check( '' === Remote_Content_Shortcode_URLs::rewrite( '', 'http://example.com/' ), 'Empty fragment changed' );
	$html = str_repeat( 'x', 1048576 );
	cb_check( $html === Remote_Content_Shortcode_URLs::rewrite( $html, 'https://example.com/' ), 'One MiB plain text rejected' );
};
foreach ( array( 'empty' => '', 'too long' => str_repeat( 'x', 8193 ), 'embedded whitespace' => 'two words', 'backslash' => 'a\\b', 'DEL' => "a\x7fb", 'scheme' => 'ftp://example.com/file', 'userinfo' => 'https://user@example.com/file' ) as $label => $value ) {
	$cb_cases[ 'URL attribute: ' . $label ] = function () use ( $value, $label ) {
		$out = cb_attribute( 'href', $value );
		cb_check( '<a>link</a>' === $out, 'Unsafe href survived: ' . $label );
	};
}
$cb_cases['URL normalization produces a parseable HTTP authority'] = function () {
	// Requests can normalize a malformed port. Check the URL emitted to the caller.
	$out = cb_attribute( 'href', 'https://example.com:bad/file' );
	cb_check( is_string( $out ), 'URL normalization failed' );
	$document = new DOMDocument();
	$document->loadHTML( $out, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
	$anchor = $document->getElementsByTagName( 'a' )->item( 0 );
	$href = $anchor ? $anchor->getAttribute( 'href' ) : '';
	$parts = wp_parse_url( $href );
	cb_check( $parts && 'https' === ( $parts['scheme'] ?? '' ) && 'example.com' === ( $parts['host'] ?? '' ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! preg_match( '/[\x00-\x20\x7f\\\\]/', $href ), 'Unsafe normalized authority: ' . $out );
};
$cb_cases['URL resolution accepts fragments contacts HTTP and trimmed references'] = function () {
	foreach ( array( '#local' => '#local', 'mailto:person@example.com' => 'mailto:person@example.com', 'TEL:+123' => 'TEL:+123', 'http://example.org/file' => 'http://example.org/file', ' next ' => 'https://example.com/dir/next', '//cdn.example.com/file' => 'https://cdn.example.com/file' ) as $value => $expected ) {
		cb_check( str_contains( cb_attribute( 'href', $value ), 'href="' . $expected . '"' ), 'Reference changed: ' . $value );
	}
	cb_check( '<a>link</a>' === cb_attribute( 'src', 'mailto:person@example.com' ), 'Contact src survived' );
	cb_check( '<a>link</a>' === cb_attribute( 'poster', 'tel:+123' ), 'Contact poster survived' );
};
$cb_cases['URL length includes the resolved base directory'] = function () {
	$base = 'https://example.com/' . str_repeat( 'd', 8171 ) . '/';
	cb_check( 8192 === strlen( $base ), 'Invalid boundary fixture' );
	cb_check( '<a>link</a>' === cb_attribute( 'href', 'x', $base ), 'Resolved URL exceeded 8192 bytes' );
	$absolute = 'https://example.com/' . str_repeat( 'd', 8171 ) . '/';
	cb_check( str_contains( cb_attribute( 'href', $absolute ), 'href="' . $absolute . '"' ), '8192-byte URL rejected' );
};
foreach ( array( 'too long' => str_repeat( 'x', 32769 ), 'data' => 'data:image/png;base64,AAAA 1x', 'quote' => '"a.png" 1x', 'parenthesis' => 'a(b).png 1x', 'too many candidates' => implode( ',', array_fill( 0, 101, 'x' ) ), 'empty candidate' => 'a.png 1x,', 'zero width' => 'a.png 0w', 'bad descriptor' => 'a.png 2q', 'multiple descriptors' => 'a.png 1x 2x', 'unsafe scheme' => 'javascript:alert 1x', 'unsafe second candidate' => 'a.png 1x, ftp://example.com/a 2x' ) as $label => $value ) {
	$cb_cases[ 'URL srcset: ' . $label ] = function () use ( $value, $label ) {
		cb_check( '<a>link</a>' === cb_attribute( 'srcset', $value ), 'Unsafe srcset survived: ' . $label );
	};
}
$cb_cases['URL srcset supports descriptorless width and fractional density candidates'] = function () {
	$out = cb_attribute( 'srcset', 'plain.png, small.png 1w, medium.png .5x, large.png 1.5x' );
	cb_check( '<a srcset="https://example.com/dir/plain.png, https://example.com/dir/small.png 1w, https://example.com/dir/medium.png .5x, https://example.com/dir/large.png 1.5x">link</a>' === $out, 'Valid srcset changed' );
	$out = cb_attribute( 'srcset', implode( ',', array_fill( 0, 100, 'x' ) ) );
	cb_check( is_string( $out ) && 100 === substr_count( $out, 'https://example.com/dir/x' ), '100 candidates rejected' );
};
$cb_cases['URL serialization expansion enforces its own output budget'] = function () {
	cb_error( Remote_Content_Shortcode_URLs::rewrite( str_repeat( '&', 220000 ), 'https://example.com/' ), 'html_too_large' );
};
$cb_cases['URL rewriting restores both libxml error modes'] = function () {
	$original = libxml_use_internal_errors( false );
	try {
		foreach ( array( false, true ) as $mode ) {
			libxml_use_internal_errors( $mode );
			cb_check( '<p>text</p>' === Remote_Content_Shortcode_URLs::rewrite( '<p>text</p>', 'https://example.com/' ), 'Valid fragment changed' );
			cb_check( $mode === libxml_use_internal_errors(), 'libxml mode leaked' );
		}
	} finally { libxml_use_internal_errors( $original ); }
};

$cb_cases['HTML empty input initializes selection metadata'] = function () {
	$selection = array( 'stale' => true );
	cb_check( '' === Remote_Content_Shortcode_HTML::transform( '', 'p', '', $selection ), 'Empty HTML changed' );
	cb_check( array( 'matches' => null ) === $selection, 'Empty input left stale selection metadata' );
};
$cb_cases['selector autoloading leaves unrelated and nonexistent classes unresolved'] = function () {
	cb_check( ! class_exists( 'RCS_Unrelated_Vendor\\Missing_Class' ), 'Selector autoloader claimed an unrelated namespace' );
	cb_check( ! class_exists( 'Symfony\\Component\\CssSelector\\RCS_Missing_Class' ), 'Selector autoloader loaded a nonexistent class' );
};
$cb_cases['HTML reports zero matches and removes overlapping ancestors'] = function () {
	$selection = null;
	cb_check( '' === Remote_Content_Shortcode_HTML::transform( '<div><span>gone</span></div><p>keep</p>', '.missing', 'div,span', $selection ), 'Missing selector returned content' );
	cb_check( array( 'matches' => 0 ) === $selection, 'Missing selector did not record zero matches' );
	cb_check( '<p>keep</p>' === Remote_Content_Shortcode_HTML::transform( '<div><span>gone</span></div><p>keep</p>', 'p', 'div,span' ), 'Overlapping removal changed surviving node' );
};
$cb_cases['HTML handles an empty document and strips encoding instructions'] = function () {
	$out = Remote_Content_Shortcode_HTML::transform( '<!DOCTYPE html>', '', '' );
	cb_check( is_string( $out ) && str_contains( $out, '<!DOCTYPE html>' ) && ! str_contains( $out, '<?xml' ), 'Empty document serialization failed' );
};
foreach ( array( 'remove' => array( '', 'foo|a' ), 'select' => array( 'foo|a', '' ) ) as $label => $selectors ) {
	$cb_cases[ 'HTML invalid XPath namespace in ' . $label ] = function () use ( $selectors ) {
		// libxml warns for undeclared namespaces. The helper must still return its documented error.
		set_error_handler( static function () { return true; } );
		try { cb_error( Remote_Content_Shortcode_HTML::transform( '<p>text</p>', ...$selectors ), 'invalid_selector' ); }
		finally { restore_error_handler(); }
	};
}
$cb_cases['HTML selector length boundary applies to selection and removal'] = function () {
	$selector = '.' . str_repeat( 'a', 255 );
	cb_check( '' === Remote_Content_Shortcode_HTML::transform( '<p>text</p>', $selector, '' ), '256-byte selector rejected' );
	cb_error( Remote_Content_Shortcode_HTML::transform( '<p>text</p>', $selector . 'a', '' ), 'selector_too_complex' );
	cb_error( Remote_Content_Shortcode_HTML::transform( '<p>text</p>', '', $selector . 'a' ), 'selector_too_complex' );
};
$cb_cases['HTML selection returns exactly one MiB and rejects one extra byte'] = function () {
	$html = '<p>' . str_repeat( 'x', 1048576 - 7 ) . '</p>';
	cb_check( $html === Remote_Content_Shortcode_HTML::transform( $html, 'p', '' ), 'One MiB selected output rejected' );
	cb_error( Remote_Content_Shortcode_HTML::transform( str_replace( '</p>', 'x</p>', $html ), 'p', '' ), 'output_too_large' );
};
$cb_cases['HTML restores libxml mode after selector failure'] = function () {
	$original = libxml_use_internal_errors( false );
	try {
		cb_error( Remote_Content_Shortcode_HTML::transform( '<p>text</p>', '[', '' ), 'invalid_selector' );
		cb_check( false === libxml_use_internal_errors(), 'Selector failure leaked libxml mode' );
	} finally { libxml_use_internal_errors( $original ); }
};

$cb_cases['regex subject and capture count boundaries'] = function () {
	$subject = str_repeat( 'x', 262144 );
	cb_check( $subject === Remote_Content_Shortcode_Regex::replace( '~z~', '', $subject ), 'Maximum subject rejected' );
	cb_error( Remote_Content_Shortcode_Regex::replace( '~z~', '', $subject . 'x' ), 'regex_input_too_complex' );
	$pattern = '~' . str_repeat( '(x)', 32 ) . '~';
	cb_check( 'x' === Remote_Content_Shortcode_Regex::replace( $pattern, '${32}', str_repeat( 'x', 32 ) ), '32 captures rejected' );
	cb_error( Remote_Content_Shortcode_Regex::replace( '~' . str_repeat( '(x)', 33 ) . '~', '', str_repeat( 'x', 33 ) ), 'regex_input_too_complex' );
};
$cb_cases['regex match count boundary is 1000'] = function () {
	cb_check( str_repeat( 'y', 1000 ) === Remote_Content_Shortcode_Regex::replace( '~x~', 'y', str_repeat( 'x', 1000 ) ), '1000 replacements rejected' );
	cb_error( Remote_Content_Shortcode_Regex::replace( '~x~', 'y', str_repeat( 'x', 1001 ) ), 'regex_output_too_large' );
};
$cb_cases['regex output boundary accounts for unmatched content'] = function () {
	$replacement = str_repeat( 'x', 1048575 );
	cb_check( $replacement . 'b' === Remote_Content_Shortcode_Regex::replace( '~a~', $replacement, 'ab' ), 'Exact maximum regex output rejected' );
	cb_error( Remote_Content_Shortcode_Regex::replace( '~a~', $replacement . 'x', 'ab' ), 'regex_output_too_large' );
};
$cb_cases['regex replacement syntax follows PHP for escapes malformed and unmatched references'] = function () {
	foreach ( array( '', '$', '\\', 'literal', '$x', '\\x', '${x}', '${123}', '${1', '${1}', '${2}', '$2', '\\2', '$99', '\\99', '\\$', '\\\\', '$1x', '$$1', '$01', '${01}' ) as $replacement ) {
		$pattern = '~(a)(b)?~';
		$expected = preg_replace( $pattern, $replacement, 'a' );
		cb_check( $expected === Remote_Content_Shortcode_Regex::replace( $pattern, $replacement, 'a' ), 'Replacement differs from PHP: ' . $replacement );
	}
};
$cb_cases['regex errors include invalid UTF8 and malformed patterns'] = function () {
	cb_error( Remote_Content_Shortcode_Regex::replace( '~.~u', '', "\xff" ), 'invalid_regex' );
	cb_error( Remote_Content_Shortcode_Regex::replace( '[', '', 'text' ), 'invalid_regex' );
	cb_check( '' === Remote_Content_Shortcode_Regex::replace( '~x~', 'y', '' ), 'Empty subject changed' );
};

foreach ( $cb_cases as $name => $case ) {
	try { $case(); echo "PASS $name\n"; }
	catch ( Throwable $error ) { ++$cb_failures; echo 'FAIL ' . $name . ': ' . $error->getMessage() . "\n"; }
}
delete_option( 'rcs_cb_secret' );
echo sprintf( "%d content boundary cases, %d failures\n", count( $cb_cases ), $cb_failures );
if ( $cb_failures ) { WP_CLI::error( "$cb_failures content boundary cases failed" ); }
