<?php
/** Public helper contracts when native DOM or Requests dependencies fail. Run with wp eval-file and uopz. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! extension_loaded( 'uopz' ) ) { WP_CLI::error( 'Dependency failure tests require the uopz extension.' ); }
$base = dirname( getenv( 'RCS_PLUGIN_FILE' ) ?: dirname( __DIR__ ) . '/remote-content-shortcode.php' );
foreach ( array( 'credentials', 'urls', 'html', 'regex' ) as $helper ) {
	require_once $base . '/inc/class-remote-content-' . $helper . '.php';
}

function df_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function df_error( $value, $code ) {
	df_assert( is_wp_error( $value ) && $code === $value->get_error_code(), 'Expected ' . $code . ', received ' . ( is_wp_error( $value ) ? $value->get_error_code() : get_debug_type( $value ) ) );
	df_assert( '' === $value->get_error_message(), 'Dependency exception details escaped through the public error.' );
}
function df_override( $class, $method, $replacement, $execute, $case ) {
	df_assert( uopz_set_return( $class, $method, $replacement, $execute ), 'Could not override ' . $class . '::' . $method );
	try { $case(); }
	finally { uopz_unset_return( $class, $method ); }
}
function df_libxml( $mode, $case ) {
	$original = libxml_use_internal_errors( true );
	try {
		// The helper owns the libxml error buffer during parsing, including on early returns.
		$probe = new DOMDocument();
		$probe->loadXML( '<unclosed>' );
		df_assert( count( libxml_get_errors() ) > 0, 'Could not seed the libxml error buffer.' );
		libxml_use_internal_errors( $mode );
		$case();
		df_assert( $mode === libxml_use_internal_errors(), 'Dependency failure leaked the libxml error mode.' );
		df_assert( array() === libxml_get_errors(), 'Dependency failure left libxml errors buffered.' );
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $original );
	}
}

// Mock only Requests construction. Public helper methods and their validation execute normally.
class RCS_Dependency_Failure_Iri extends WpOrg\Requests\Iri {
	public static $calls = 0;
	public static $fail_at = 1;
	public function __construct( $iri = null ) {
		if ( ++self::$calls === self::$fail_at ) { throw new RuntimeException( 'private dependency failure' ); }
		parent::__construct( $iri );
	}
}

$tests = array();
$tests['unmatched removal preserves content and selection metadata'] = static function () {
	$selection = array( 'matches' => 99 );
	df_assert( '<p>keep</p>' === Remote_Content_Shortcode_HTML::transform( '<p>keep</p>', 'p', '.absent', $selection ), 'Unmatched removal changed content.' );
	df_assert( array( 'matches' => 1 ) === $selection, 'Unmatched removal changed selection metadata.' );
};
$tests['overlapping ancestor and descendant removals preserve unrelated siblings'] = static function () {
	$selection = array( 'matches' => 99 );
	df_assert( '<p>keep</p>' === Remote_Content_Shortcode_HTML::transform( '<section class="drop"><p class="drop">remove</p></section><p>keep</p>', 'p', '.drop', $selection ), 'Overlapping removals left content behind or lost a sibling.' );
	df_assert( array( 'matches' => 1 ) === $selection, 'Overlapping removals reported stale matches.' );
};
$tests['removing the document element produces no selected content'] = static function () {
	$selection = array( 'matches' => 99 );
	df_assert( '' === Remote_Content_Shortcode_HTML::transform( '<p>remove</p>', 'p', 'html', $selection ), 'Root removal retained selected content.' );
	df_assert( array( 'matches' => 0 ) === $selection, 'Root removal retained stale matches.' );
};
$tests['URL rewriting returns an empty fragment after removing its only base element'] = static function () {
	df_assert( '' === Remote_Content_Shortcode_URLs::rewrite( '<base href="https://untrusted.example/">', 'https://example.com/' ), 'Base-only fragment retained content.' );
};
$tests['unexpected regex callback exceptions propagate unchanged'] = static function () {
	$expected = new RuntimeException( 'unexpected regex callback failure' );
	df_override( Remote_Content_Shortcode_Regex::class, 'expand', static function () use ( $expected ) { throw $expected; }, true, static function () use ( $expected ) {
		$caught = null;
		try { Remote_Content_Shortcode_Regex::replace( '/x/', 'replacement', 'x' ); }
		catch ( Throwable $error ) { $caught = $error; }
		df_assert( $expected === $caught, 'Unexpected regex exception was swallowed or converted to an output-budget error.' );
	} );
	df_assert( 'replacement' === Remote_Content_Shortcode_Regex::replace( '/x/', 'replacement', 'x' ), 'Regex dependency override leaked into the next call.' );
};
foreach ( array( false, true ) as $mode ) {
	$suffix = $mode ? ' with internal libxml errors' : ' with external libxml errors';
	$tests[ 'URL parser false fails closed' . $suffix ] = static function () use ( $mode ) {
		df_override( DOMDocument::class, 'loadHTML', false, false, static function () use ( $mode ) {
			df_libxml( $mode, static function () { df_error( Remote_Content_Shortcode_URLs::rewrite( '<p>private content</p>', 'https://example.com/' ), 'invalid_html' ); } );
		} );
	};
	$tests[ 'selection after a failed parser returns no matches' . $suffix ] = static function () use ( $mode ) {
		df_override( DOMDocument::class, 'loadHTML', false, false, static function () use ( $mode ) {
			df_libxml( $mode, static function () {
				$selection = array( 'matches' => 99 );
				df_assert( '' === Remote_Content_Shortcode_HTML::transform( '<p>private content</p>', 'p', '', $selection ), 'Failed parser returned selected content.' );
				df_assert( array( 'matches' => 0 ) === $selection, 'Failed parser retained stale selection metadata.' );
			} );
		} );
	};
	$tests[ 'whole document after a failed parser serializes an empty document' . $suffix ] = static function () use ( $mode ) {
		df_override( DOMDocument::class, 'loadHTML', false, false, static function () use ( $mode ) {
			df_libxml( $mode, static function () {
				$selection = array( 'matches' => 99 );
				df_assert( ( new DOMDocument() )->saveHTML() === Remote_Content_Shortcode_HTML::transform( '<p>private content</p>', '', '', $selection ), 'Failed parser serialized unparsed input.' );
				df_assert( array( 'matches' => null ) === $selection, 'Whole-document serialization retained stale selection metadata.' );
			} );
		} );
	};
	$tests[ 'URL parser success without any elements is rejected' . $suffix ] = static function () use ( $mode ) {
		df_override( DOMDocument::class, 'loadHTML', true, false, static function () use ( $mode ) {
			df_libxml( $mode, static function () { df_error( Remote_Content_Shortcode_URLs::rewrite( '<p>private content</p>', 'https://example.com/' ), 'invalid_html' ); } );
		} );
	};
	foreach ( array( 'urls', 'html' ) as $helper ) {
		$tests[ $helper . ' parser exception is contained' . $suffix ] = static function () use ( $helper, $mode ) {
			df_override( DOMDocument::class, 'loadHTML', static function () { throw new RuntimeException( 'private dependency failure' ); }, true, static function () use ( $helper, $mode ) {
				df_libxml( $mode, static function () use ( $helper ) {
					if ( 'urls' === $helper ) { df_error( Remote_Content_Shortcode_URLs::rewrite( '<p>content</p>', 'https://example.com/' ), 'invalid_html' ); }
					else {
						$selection = array( 'matches' => 99 );
						df_error( Remote_Content_Shortcode_HTML::transform( '<p>content</p>', 'p', '', $selection ), 'invalid_selector' );
						df_assert( array( 'matches' => null ) === $selection, 'Parser exception retained stale selection metadata.' );
					}
				} );
			} );
		};
	}
	$tests[ 'URL parser output without a body is rejected' . $suffix ] = static function () use ( $mode ) {
		// A degraded parser can report success while omitting the body the serializer needs.
		df_override( DOMDocument::class, 'loadHTML', function () { return $this->loadXML( '<html><head><title>incomplete</title></head></html>' ); }, true, static function () use ( $mode ) {
			df_libxml( $mode, static function () { df_error( Remote_Content_Shortcode_URLs::rewrite( '<p>content</p>', 'https://example.com/' ), 'invalid_html' ); } );
		} );
	};
	foreach ( array( 'urls', 'selected HTML', 'whole HTML' ) as $helper ) {
		$tests[ $helper . ' serializer exception is contained' . $suffix ] = static function () use ( $helper, $mode ) {
			df_override( DOMDocument::class, 'saveHTML', static function () { throw new RuntimeException( 'private dependency failure' ); }, true, static function () use ( $helper, $mode ) {
				df_libxml( $mode, static function () use ( $helper ) {
					if ( 'urls' === $helper ) { df_error( Remote_Content_Shortcode_URLs::rewrite( '<p>content</p>', 'https://example.com/' ), 'invalid_html' ); }
					else { df_error( Remote_Content_Shortcode_HTML::transform( '<p>content</p>', 'selected HTML' === $helper ? 'p' : '', '' ), 'invalid_selector' ); }
				} );
			} );
		};
	}
	$tests[ 'whole document serialization has an independent output limit' . $suffix ] = static function () use ( $mode ) {
		df_override( DOMDocument::class, 'saveHTML', str_repeat( 'x', 1048577 ), false, static function () use ( $mode ) {
			df_libxml( $mode, static function () { df_error( Remote_Content_Shortcode_HTML::transform( '<p>content</p>', '', '' ), 'output_too_large' ); } );
		} );
	};
}

foreach ( array( 'false', 'exception' ) as $failure ) {
	foreach ( array( 'href', 'srcset' ) as $attribute ) {
		$tests[ 'Requests resolution ' . $failure . ' removes the entire ' . $attribute ] = static function () use ( $failure, $attribute ) {
			$replacement = 'false' === $failure ? false : static function () { throw new RuntimeException( 'private dependency failure' ); };
			df_override( WpOrg\Requests\Iri::class, 'absolutize', $replacement, 'exception' === $failure, static function () use ( $attribute ) {
				$html = 'srcset' === $attribute ? '<img srcset="one.png 1x, two.png 2x" alt="keep">' : '<a href="next">keep</a>';
				$expected = 'srcset' === $attribute ? '<img alt="keep">' : '<a>keep</a>';
				df_assert( $expected === Remote_Content_Shortcode_URLs::rewrite( $html, 'https://example.com/dir/page' ), 'Failed resolution retained a URL or removed unrelated content.' );
			} );
			df_assert( '<a href="https://example.com/dir/next">keep</a>' === Remote_Content_Shortcode_URLs::rewrite( '<a href="next">keep</a>', 'https://example.com/dir/page' ), 'Requests resolution override leaked into the next call.' );
		};
	}
}

foreach ( array( 1, 2 ) as $fail_at ) {
	$tests[ 'credential Iri construction failure ' . $fail_at . ' prevents secret access' ] = static function () use ( $fail_at ) {
		$reads = 0;
		$secret = static function () use ( &$reads ) { ++$reads; return 'alice:private-secret'; };
		$source = array( 'origin' => 'https://example.com', 'path' => '/content', 'type' => 'option', 'key' => 'rcs_dependency_failure_secret' );
		RCS_Dependency_Failure_Iri::$calls = 0;
		RCS_Dependency_Failure_Iri::$fail_at = $fail_at;
		add_filter( 'pre_option_rcs_dependency_failure_secret', $secret );
		try {
			uopz_set_mock( WpOrg\Requests\Iri::class, RCS_Dependency_Failure_Iri::class );
			try {
				df_error( Remote_Content_Shortcode_Credentials::headers( $source, 'https://example.com/content', 'GET' ), 'credential_endpoint_mismatch' );
				df_assert( $fail_at === RCS_Dependency_Failure_Iri::$calls && 0 === $reads, 'Credential validation read a secret after endpoint parsing failed.' );
			} finally { uopz_unset_mock( WpOrg\Requests\Iri::class ); }
			df_assert( array( 'Authorization' => 'Basic ' . base64_encode( 'alice:private-secret' ) ) === Remote_Content_Shortcode_Credentials::headers( $source, 'https://example.com/content', 'GET' ), 'Iri mock leaked into the next credential call.' );
			df_assert( 1 === $reads, 'Recovered credential lookup did not read exactly one configured secret.' );
		} finally { remove_filter( 'pre_option_rcs_dependency_failure_secret', $secret ); }
	};
}

$failures = 0;
foreach ( $tests as $name => $test ) {
	try { $test(); echo "PASS $name\n"; }
	catch ( Throwable $error ) { ++$failures; echo "FAIL $name: " . $error->getMessage() . "\n"; }
}
echo count( $tests ) . " dependency failure tests, $failures failures\n";
if ( $failures ) { WP_CLI::error( "$failures dependency failure tests failed" ); }
