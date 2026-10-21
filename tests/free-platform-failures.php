<?php
/** Crypto, storage and admin dependency faults. Run with wp eval-file and uopz enabled. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! extension_loaded( 'uopz' ) ) { WP_CLI::error( 'Free platform fault tests require uopz.' ); }
function rcs_ff_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function rcs_ff_error( $value, $code ) {
	rcs_ff_assert( is_wp_error( $value ) && $code === $value->get_error_code(), 'Expected contained error ' . $code . '.' );
	rcs_ff_assert( '' === $value->get_error_message(), 'Dependency exception details escaped the vault.' );
}
function rcs_ff_override( $function, $replacement, $execute, $case ) {
	rcs_ff_assert( uopz_set_return( $function, $replacement, $execute ), 'Could not install fault for ' . $function . '.' );
	try { $case(); } finally { uopz_unset_return( $function ); }
}
function rcs_ff_missing( $names, $case ) {
	$defined = get_defined_functions(); $functions = array_fill_keys( array_map( 'strtolower', array_merge( $defined['internal'], $defined['user'] ) ), true );
	$missing = array_fill_keys( $names, true );
	rcs_ff_override( 'function_exists', static function ( $name ) use ( $functions, $missing ) { $key = strtolower( $name ); return ! isset( $missing[ $key ] ) && isset( $functions[ $key ] ); }, true, $case );
}
function rcs_ff_page( $tab ) {
	$saved = array( $_GET, $_POST, $_REQUEST, $_SERVER['REQUEST_METHOD'] ?? null ); $level = ob_get_level();
	try { $_GET = array( 'tab' => $tab ); $_POST = array(); $_REQUEST = $_GET; $_SERVER['REQUEST_METHOD'] = 'GET'; ob_start(); Remote_Content_Shortcode_Admin::page(); return ob_get_clean(); }
	finally { while ( ob_get_level() > $level ) { ob_end_clean(); } list( $_GET, $_POST, $_REQUEST, $method ) = $saved; if ( null === $method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $method; } }
}
function rcs_ff_fixture() { return array( '_wpnonce' => wp_create_nonce( 'rcs_connections' ), 'alias' => 'failure-fixture', 'auth' => 'bearer', 'origin' => 'https://example.com', 'path' => '/endpoint', 'methods' => array( 'GET' ), 'secret' => 'fault-fixture-value' ); }
$tests = array();
$tests['vault rejects malformed envelopes before cryptographic operations'] = static function () {
	foreach ( array( null, array(), str_repeat( 'a', 1400001 ), 'v1:sodium:Zh==', 'v1:sodium:@@@', 'v1:unknown:Zg==', 'v1:sodium:' . base64_encode( str_repeat( 'a', 40 ) ), 'v1:gcm:' . base64_encode( str_repeat( 'a', 28 ) ) ) as $bad ) { rcs_ff_error( Remote_Content_Shortcode_Secrets::open( $bad ), 'secret_unavailable' ); }
	foreach ( array( null, array(), '', str_repeat( 'a', 1048577 ) ) as $bad ) { rcs_ff_error( Remote_Content_Shortcode_Secrets::seal( $bad ), 'invalid_secret' ); }
};
$tests['randomness and sodium encryption exceptions fail closed'] = static function () {
	foreach ( array( 'random_bytes', 'sodium_crypto_secretbox', 'wp_salt' ) as $function ) {
		rcs_ff_override( $function, static function () { throw new RuntimeException( 'private-provider-secret' ); }, true, static function () { rcs_ff_error( Remote_Content_Shortcode_Secrets::seal( 'private-value' ), 'secret_storage_unavailable' ); } );
	}
};
$tests['OpenSSL fallback uses authenticated encryption when sodium is unavailable'] = static function () {
	rcs_ff_missing( array( 'sodium_crypto_secretbox' ), static function () {
		$sealed = Remote_Content_Shortcode_Secrets::seal( 'openssl-fallback-fixture' );
		rcs_ff_assert( is_string( $sealed ) && str_starts_with( $sealed, 'v1:gcm:' ) && 'openssl-fallback-fixture' === Remote_Content_Shortcode_Secrets::open( $sealed ), 'Real AES-GCM fallback failed.' );
		$bytes = base64_decode( explode( ':', $sealed )[2], true ); $bytes[12] = chr( ord( $bytes[12] ) ^ 1 );
		rcs_ff_error( Remote_Content_Shortcode_Secrets::open( 'v1:gcm:' . base64_encode( $bytes ) ), 'secret_unavailable' );
	} );
};
$tests['no crypto and unsupported ciphers cannot store plaintext'] = static function () {
	rcs_ff_missing( array( 'sodium_crypto_secretbox', 'openssl_encrypt' ), static function () { rcs_ff_error( Remote_Content_Shortcode_Secrets::seal( 'private-value' ), 'secret_storage_unavailable' ); } );
	rcs_ff_missing( array( 'sodium_crypto_secretbox' ), static function () {
		rcs_ff_override( 'openssl_get_cipher_methods', array(), false, static function () { rcs_ff_error( Remote_Content_Shortcode_Secrets::seal( 'private-value' ), 'secret_storage_unavailable' ); } );
		foreach ( array( false, 'ciphertext-without-a-tag' ) as $result ) { rcs_ff_override( 'openssl_encrypt', $result, false, static function () { rcs_ff_error( Remote_Content_Shortcode_Secrets::seal( 'private-value' ), 'secret_storage_unavailable' ); } ); }
		rcs_ff_override( 'openssl_encrypt', static function () { throw new RuntimeException( 'private-provider-secret' ); }, true, static function () { rcs_ff_error( Remote_Content_Shortcode_Secrets::seal( 'private-value' ), 'secret_storage_unavailable' ); } );
	} );
};
$tests['missing decryptors corrupted ciphertext and decryptor exceptions stay private'] = static function () {
	$sodium = Remote_Content_Shortcode_Secrets::seal( 'decrypt-fixture' );
	rcs_ff_assert( is_string( $sodium ) && str_starts_with( $sodium, 'v1:sodium:' ), 'Baseline sodium fixture unavailable.' );
	rcs_ff_missing( array( 'sodium_crypto_secretbox_open' ), static function () use ( $sodium ) { rcs_ff_error( Remote_Content_Shortcode_Secrets::open( $sodium ), 'secret_unavailable' ); } );
	foreach ( array( false, '', str_repeat( 'a', 1048577 ) ) as $result ) { rcs_ff_override( 'sodium_crypto_secretbox_open', $result, false, static function () use ( $sodium ) { rcs_ff_error( Remote_Content_Shortcode_Secrets::open( $sodium ), 'secret_unavailable' ); } ); }
	rcs_ff_override( 'sodium_crypto_secretbox_open', static function () { throw new RuntimeException( 'private-provider-secret' ); }, true, static function () use ( $sodium ) { rcs_ff_error( Remote_Content_Shortcode_Secrets::open( $sodium ), 'secret_unavailable' ); } );
	rcs_ff_missing( array( 'sodium_crypto_secretbox' ), static function () {
		$gcm = Remote_Content_Shortcode_Secrets::seal( 'decrypt-fixture' );
		rcs_ff_missing( array( 'sodium_crypto_secretbox', 'openssl_decrypt' ), static function () use ( $gcm ) { rcs_ff_error( Remote_Content_Shortcode_Secrets::open( $gcm ), 'secret_unavailable' ); } );
		rcs_ff_override( 'openssl_decrypt', static function () { throw new RuntimeException( 'private-provider-secret' ); }, true, static function () use ( $gcm ) { rcs_ff_error( Remote_Content_Shortcode_Secrets::open( $gcm ), 'secret_unavailable' ); } );
	} );
};
$tests['connection encryption and durable write failures preserve existing state'] = static function () {
	$input = rcs_ff_fixture();
	rcs_ff_assert( 'failure-fixture' === Remote_Content_Shortcode_Connections::save( $input ), 'Could not seed durable state.' );
	$old = get_option( Remote_Content_Shortcode_Connections::OPTION ); $input['secret'] = 'replacement-value';
	rcs_ff_override( 'random_bytes', static function () { throw new RuntimeException( 'private-randomness-error' ); }, true, static function () use ( $input ) { rcs_ff_error( Remote_Content_Shortcode_Connections::save( $input ), 'secret_storage_unavailable' ); } );
	rcs_ff_assert( $old === get_option( Remote_Content_Shortcode_Connections::OPTION ), 'Encryption failure changed durable state.' );
	$prevent = static fn( $value, $old ) => $old;
	add_filter( 'pre_update_option_' . Remote_Content_Shortcode_Connections::OPTION, $prevent, 10, 2 );
	try {
		rcs_ff_error( Remote_Content_Shortcode_Connections::save( $input ), 'connection_storage_failed' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'rcs_connections' ); rcs_ff_error( Remote_Content_Shortcode_Connections::delete( 'failure-fixture' ), 'connection_storage_failed' );
		rcs_ff_assert( $old === get_option( Remote_Content_Shortcode_Connections::OPTION ), 'Failed storage lost an existing secret.' );
	} finally { remove_filter( 'pre_update_option_' . Remote_Content_Shortcode_Connections::OPTION, $prevent, 10 ); }
};
$tests['broken scope parsing cannot publish or resolve saved connections'] = static function () {
	rcs_ff_assert( 'failure-fixture' === Remote_Content_Shortcode_Connections::save( rcs_ff_fixture() ), 'Could not seed connection.' );
	rcs_ff_override( 'wp_parse_url', false, false, static function () {
		rcs_ff_assert( array() === Remote_Content_Shortcode_Connections::definitions( array() ) && array() === Remote_Content_Shortcode_Connections::all(), 'Invalid parsed scope reached metadata.' );
		rcs_ff_error( Remote_Content_Shortcode_Connections::secret( 'failure-fixture' ), 'secret_unavailable' );
	} );
};
$tests['support copy remains available without crypto DOM curl or an internal object cache'] = static function () {
	$extensions = array_fill_keys( array_map( 'strtolower', get_loaded_extensions() ), true );
	rcs_ff_missing( array( 'sodium_crypto_secretbox', 'openssl_encrypt' ), static function () use ( $extensions ) {
		rcs_ff_override( 'extension_loaded', static function ( $name ) use ( $extensions ) { return ! in_array( strtolower( $name ), array( 'dom', 'curl' ), true ) && isset( $extensions[ strtolower( $name ) ] ); }, true, static function () {
			rcs_ff_override( 'wp_using_ext_object_cache', true, false, static function () {
				$html = rcs_ff_page( 'help' );
				rcs_ff_assert( str_contains( $html, 'Authenticated encryption: unavailable' ) && str_contains( $html, 'DOM: no' ) && str_contains( $html, 'cURL: no' ) && str_contains( $html, 'External object cache: yes' ), 'Support copy broke under unavailable dependencies.' );
			} );
		} );
	} );
	rcs_ff_missing( array( 'sodium_crypto_secretbox' ), static function () { rcs_ff_assert( str_contains( rcs_ff_page( 'help' ), 'Authenticated encryption: available' ), 'OpenSSL-only support capability was missed.' ); } );
};
$tests['usage database failure renders an empty bounded scan'] = static function () {
	rcs_ff_assert( uopz_set_return( wpdb::class, 'get_results', null ), 'Could not inject usage query failure.' );
	try { $html = rcs_ff_page( 'usage' ); rcs_ff_assert( str_contains( $html, 'Usage pagination' ) && ! str_contains( $html, 'data-rcs-usage=' ), 'Database failure did not produce an empty safe usage list.' ); }
	finally { uopz_unset_return( wpdb::class, 'get_results' ); }
};
$old = get_option( Remote_Content_Shortcode_Connections::OPTION, null ); $old_user = get_current_user_id(); $request = $_REQUEST; $failures = 0;
$admin = get_user_by( 'login', 'rcs_admin' ); $admin_id = $admin ? $admin->ID : 1;
try {
	foreach ( $tests as $name => $test ) {
		wp_set_current_user( $admin_id ); delete_option( Remote_Content_Shortcode_Connections::OPTION );
		try { $test(); echo "PASS $name\n"; } catch ( Throwable $error ) { ++$failures; echo "FAIL $name: " . $error->getMessage() . "\n"; }
	}
} finally { null === $old ? delete_option( Remote_Content_Shortcode_Connections::OPTION ) : update_option( Remote_Content_Shortcode_Connections::OPTION, $old, false ); wp_set_current_user( $old_user ); $_REQUEST = $request; }
if ( $failures ) { WP_CLI::error( "$failures Free platform dependency failures" ); }
echo count( $tests ) . " Free platform dependency groups passed\n";
