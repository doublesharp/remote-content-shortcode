<?php
/** Authenticated encryption for administrator-managed credentials. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Remote_Content_Shortcode_Secrets {
	private static function key() {
		return hash( 'sha256', 'remote-content-shortcode:v1:' . wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . wp_salt( 'logged_in' ) . wp_salt( 'nonce' ), true );
	}
	public static function seal( $value ) {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 1048576 ) { return new WP_Error( 'invalid_secret' ); }
		try {
			if ( function_exists( 'sodium_crypto_secretbox' ) ) {
				$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				return 'v1:sodium:' . base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, self::key() ) );
			}
			if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
				$nonce = random_bytes( 12 ); $tag = '';
				$encrypted = openssl_encrypt( $value, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag, 'remote-content-shortcode:v1', 16 );
				if ( false !== $encrypted && 16 === strlen( $tag ) ) { return 'v1:gcm:' . base64_encode( $nonce . $tag . $encrypted ); }
			}
		} catch ( Throwable $error ) { return new WP_Error( 'secret_storage_unavailable' ); }
		return new WP_Error( 'secret_storage_unavailable' );
	}
	public static function open( $sealed ) {
		if ( ! is_string( $sealed ) || strlen( $sealed ) > 1400000 ) { return new WP_Error( 'secret_unavailable' ); }
		$parts = explode( ':', $sealed );
		if ( 3 !== count( $parts ) || 'v1' !== $parts[0] ) { return new WP_Error( 'secret_unavailable' ); }
		$bytes = base64_decode( $parts[2], true );
		if ( false === $bytes || base64_encode( $bytes ) !== $parts[2] ) { return new WP_Error( 'secret_unavailable' ); }
		$value = false;
		try {
			if ( 'sodium' === $parts[1] && function_exists( 'sodium_crypto_secretbox_open' ) && strlen( $bytes ) > 40 ) { $value = sodium_crypto_secretbox_open( substr( $bytes, 24 ), substr( $bytes, 0, 24 ), self::key() ); }
			if ( 'gcm' === $parts[1] && function_exists( 'openssl_decrypt' ) && strlen( $bytes ) > 28 ) { $value = openssl_decrypt( substr( $bytes, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $bytes, 0, 12 ), substr( $bytes, 12, 16 ), 'remote-content-shortcode:v1' ); }
		} catch ( Throwable $error ) { return new WP_Error( 'secret_unavailable' ); }
		return is_string( $value ) && '' !== $value && strlen( $value ) <= 1048576 ? $value : new WP_Error( 'secret_unavailable' );
	}
}
