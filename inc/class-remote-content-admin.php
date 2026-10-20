<?php
/** Administrator-only diagnostics and cache controls. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Remote_Content_Shortcode_Admin {
	public static function register() { add_action( 'admin_menu', array( __CLASS__, 'menu' ) ); }
	public static function menu() {
		add_management_page( __( 'Remote content', 'remote-content-shortcode' ), __( 'Remote content', 'remote-content-shortcode' ), 'manage_options', 'remote-content-shortcode', array( __CLASS__, 'page' ) );
	}
	public static function process_action( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', __( 'Administrator access required.', 'remote-content-shortcode' ) ); }
		$nonce = isset( $input['_wpnonce'] ) && is_string( $input['_wpnonce'] ) ? $input['_wpnonce'] : '';
		// The form handler also calls check_admin_referer. Verify here so direct callers fail safely.
		if ( ! wp_verify_nonce( $nonce, 'rcs_tools' ) ) { return new WP_Error( 'invalid_nonce', __( 'Security check failed.', 'remote-content-shortcode' ) ); }
		$atts = array();
		foreach ( array( 'url', 'selector', 'remove', 'fallback', 'userpwd' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) ) { $atts[ $key ] = $input[ $key ]; }
		}
		foreach ( array( 'cache', 'rewrite_urls' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) && in_array( $input[ $key ], array( 'true', 'false' ), true ) ) { $atts[ $key ] = $input[ $key ]; }
		}
		foreach ( array( 'cache_ttl', 'stale_ttl', 'timeout' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) { $atts[ $key ] = (string) max( 0, (float) $input[ $key ] ); }
		}
		if ( isset( $atts['userpwd'] ) && ! preg_match( '/^[A-Za-z0-9_.-]*$/D', $atts['userpwd'] ) ) { return new WP_Error( 'invalid_alias', __( 'Enter a configured credential alias.', 'remote-content-shortcode' ) ); }
		$action = isset( $input['rcs_action'] ) && is_string( $input['rcs_action'] ) ? $input['rcs_action'] : '';
		if ( 'test' === $action ) { return RemoteContentShortcode::get_instance()->diagnose( $atts ); }
		if ( 'clear' === $action ) { return RemoteContentShortcode::get_instance()->clear_cache( $atts['url'] ?? '' ); }
		return new WP_Error( 'invalid_action', __( 'Unknown action.', 'remote-content-shortcode' ) );
	}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Administrator access required.', 'remote-content-shortcode' ) ); }
		$result = null;
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' === $request_method ) {
			check_admin_referer( 'rcs_tools' );
			$result = self::process_action( wp_unslash( $_POST ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Remote content', 'remote-content-shortcode' ) . '</h1>';
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Request failed.', 'remote-content-shortcode' ) . ' ' . esc_html( sanitize_key( $result->get_error_code() ) ) . '</p></div>';
		} elseif ( is_int( $result ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Plugin cache cleared.', 'remote-content-shortcode' ) . '</p></div>';
		} elseif ( is_array( $result ) ) {
			echo '<h2>' . esc_html__( 'Diagnostics', 'remote-content-shortcode' ) . '</h2><dl>';
			$labels = array(
				'ok' => __( 'Successful import', 'remote-content-shortcode' ),
				'error_code' => __( 'Error code', 'remote-content-shortcode' ),
				'http_status' => __( 'HTTP status', 'remote-content-shortcode' ),
				'cache_status' => __( 'Cache', 'remote-content-shortcode' ),
				'selector_matches' => __( 'Matching elements', 'remote-content-shortcode' ),
				'duration_ms' => __( 'Elapsed time (ms)', 'remote-content-shortcode' ),
			);
			foreach ( $labels as $key => $label ) {
				$value = isset( $result[ $key ] ) && is_scalar( $result[ $key ] ) ? (string) $result[ $key ] : __( 'Not available', 'remote-content-shortcode' );
				if ( 'ok' === $key && isset( $result[ $key ] ) && is_bool( $result[ $key ] ) ) { $value = $result[ $key ] ? __( 'Yes', 'remote-content-shortcode' ) : __( 'No', 'remote-content-shortcode' ); }
				echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
			}
			echo '</dl>';
			if ( isset( $result['html'] ) && is_string( $result['html'] ) ) { echo '<h2>' . esc_html__( 'Preview', 'remote-content-shortcode' ) . '</h2><div>' . wp_kses_post( $result['html'] ) . '</div>'; }
		}
		echo '<form method="post">';
		wp_nonce_field( 'rcs_tools' );
		echo '<p>' . esc_html__( 'Enter public source settings to test. Enter a URL to clear its cache, or leave it empty to clear the plugin cache.', 'remote-content-shortcode' ) . '</p><table class="form-table">';
		$fields = array( 'url' => __( 'Source URL', 'remote-content-shortcode' ), 'selector' => __( 'CSS selector', 'remote-content-shortcode' ), 'remove' => __( 'Remove selector', 'remote-content-shortcode' ), 'userpwd' => __( 'Credential alias', 'remote-content-shortcode' ), 'fallback' => __( 'Fallback HTML', 'remote-content-shortcode' ), 'cache_ttl' => __( 'Cache lifetime (seconds)', 'remote-content-shortcode' ), 'stale_ttl' => __( 'Stale lifetime (seconds)', 'remote-content-shortcode' ), 'timeout' => __( 'Timeout (seconds)', 'remote-content-shortcode' ) );
		foreach ( $fields as $key => $label ) {
			$default = array( 'cache_ttl' => '3600', 'stale_ttl' => '0', 'timeout' => '5' );
			$limits = array( 'cache_ttl' => array( 1, 86400 ), 'stale_ttl' => array( 0, 86400 ), 'timeout' => array( 1, 5 ) );
			// Do not echo submitted URLs, query parameters, or aliases back into the page.
			echo '<tr><th><label for="rcs-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="rcs-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . ( isset( $default[ $key ] ) ? 'number' : 'text' ) . '"';
			if ( isset( $limits[ $key ] ) ) { echo ' min="' . esc_attr( $limits[ $key ][0] ) . '" max="' . esc_attr( $limits[ $key ][1] ) . '"'; }
			echo ' value="' . esc_attr( $default[ $key ] ?? '' ) . '"></td></tr>';
		}
		foreach ( array( 'cache' => __( 'Cache response', 'remote-content-shortcode' ), 'rewrite_urls' => __( 'Rewrite relative URLs', 'remote-content-shortcode' ) ) as $key => $label ) {
			echo '<tr><th><label for="rcs-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="rcs-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '"><option value="true">' . esc_html__( 'Yes', 'remote-content-shortcode' ) . '</option><option value="false"' . ( 'rewrite_urls' === $key ? ' selected' : '' ) . '>' . esc_html__( 'No', 'remote-content-shortcode' ) . '</option></select></td></tr>';
		}
		echo '</table><p>' . esc_html__( 'Credentials must be configured outside the editor. Enter an alias only.', 'remote-content-shortcode' ) . '</p><p><button class="button button-primary" name="rcs_action" value="test">' . esc_html__( 'Test content', 'remote-content-shortcode' ) . '</button> <button class="button" name="rcs_action" value="clear">' . esc_html__( 'Clear cache', 'remote-content-shortcode' ) . '</button></p></form></div>';
	}
}
