<?php
/** Administrator-only builder, diagnostics, connections and cache controls. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Remote_Content_Shortcode_Admin {
	public static function register() { add_action( 'admin_menu', array( __CLASS__, 'menu' ) ); }
	public static function menu() { add_management_page( __( 'Remote content', 'remote-content-shortcode' ), __( 'Remote content', 'remote-content-shortcode' ), 'manage_options', 'remote-content-shortcode', array( __CLASS__, 'page' ) ); }
	private static function fields() {
		return array( 'url' => 'Source URL', 'selector' => 'CSS selector', 'remove' => 'Remove selector', 'userpwd' => 'Credential alias', 'fallback' => 'Fallback HTML', 'timeout' => 'Timeout (seconds)', 'method' => 'HTTP method', 'find' => 'Find pattern', 'replace' => 'Replacement', 'params' => 'Forward query parameters', 'htmlentities' => 'Encode HTML entities', 'strip_tags' => 'Strip HTML tags', 'cache' => 'Enable public GET cache', 'cache_ttl' => 'Cache lifetime (seconds)', 'stale_ttl' => 'Stale lifetime (seconds)', 'rewrite_urls' => 'Rewrite relative URLs' );
	}
	public static function process_action( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', __( 'Administrator access required.', 'remote-content-shortcode' ) ); }
		$nonce = isset( $input['_wpnonce'] ) && is_string( $input['_wpnonce'] ) ? $input['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, 'rcs_tools' ) ) { return new WP_Error( 'invalid_nonce', __( 'Security check failed.', 'remote-content-shortcode' ) ); }
		$atts = array();
		foreach ( self::fields() as $key => $label ) {
			if ( is_string( $input[ $key ] ?? null ) ) { $atts[ $key ] = $input[ $key ]; }
		}
		foreach ( array( 'timeout', 'cache_ttl', 'stale_ttl' ) as $key ) {
			unset( $atts[ $key ] );
			if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) { $atts[ $key ] = (string) max( 0, (float) $input[ $key ] ); }
		}
		foreach ( array( 'rewrite_urls', 'cache', 'strip_tags', 'htmlentities' ) as $key ) { if ( isset( $atts[ $key ] ) && ! in_array( $atts[ $key ], array( 'true', 'false' ), true ) ) { unset( $atts[ $key ] ); } }
		if ( isset( $atts['userpwd'] ) && ! preg_match( '/^[A-Za-z0-9_.-]{0,64}$/D', $atts['userpwd'] ) ) { return new WP_Error( 'invalid_alias', __( 'Enter a configured credential alias.', 'remote-content-shortcode' ) ); }
		$action = isset( $input['rcs_action'] ) && is_string( $input['rcs_action'] ) ? $input['rcs_action'] : '';
		$body = is_string( $input['body'] ?? null ) ? $input['body'] : null;
		if ( 'test' === $action ) { return RemoteContentShortcode::get_instance()->diagnose( $atts, $body ); }
		if ( 'clear' === $action ) { return RemoteContentShortcode::get_instance()->clear_cache( $atts['url'] ?? '' ); }
		if ( 'generate' !== $action ) { return new WP_Error( 'invalid_action', __( 'Unknown action.', 'remote-content-shortcode' ) ); }
		if ( null !== $body && ( strlen( $body ) > 65536 || preg_match( '/\[\/?remote_content\b/i', $body ) ) ) { return new WP_Error( 'invalid_builder_body', __( 'Remove remote_content shortcode delimiters from the POST body. Use a source filter for these payloads.', 'remote-content-shortcode' ) ); }
		$url = wp_parse_url( $atts['url'] ?? '' );
		if ( ! $url || isset( $url['user'] ) || isset( $url['pass'] ) || ! in_array( $url['scheme'] ?? '', array( 'https', 'http' ), true ) || empty( $url['host'] ) ) { return new WP_Error( 'invalid_url' ); }
		$shortcode = '[remote_content decode_atts="true"';
		foreach ( $atts as $key => $value ) {
			if ( strlen( $value ) > 4096 ) { return new WP_Error( 'invalid_attributes' ); }
			if ( '' !== $value ) { $encoded = str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), htmlspecialchars( '"' . $value . '"', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ) ); $shortcode .= ' ' . $key . '="' . $encoded . '"'; }
		}
		return $shortcode . ']' . ( null !== $body && '' !== $body ? $body . '[/remote_content]' : '' );
	}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Administrator access required.', 'remote-content-shortcode' ) ); }
		$tabs = apply_filters( 'remote_content_shortcode_admin_tabs', array( 'builder' => __( 'Builder', 'remote-content-shortcode' ), 'connections' => __( 'Connections', 'remote-content-shortcode' ), 'cache' => __( 'Cache', 'remote-content-shortcode' ), 'usage' => __( 'Usage', 'remote-content-shortcode' ), 'help' => __( 'Help', 'remote-content-shortcode' ) ) );
		$tabs = is_array( $tabs ) ? $tabs : array();
		foreach ( $tabs as $slug => $label ) { if ( ! is_string( $slug ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $slug ) || ! is_string( $label ) ) { unset( $tabs[ $slug ] ); } }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation uses the validated tab allowlist.
		$requested_tab = is_string( $_GET['tab'] ?? null ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$tab = isset( $tabs[ $requested_tab ] ) ? $requested_tab : 'builder';
		$result = null;
		$method = is_string( $_SERVER['REQUEST_METHOD'] ?? null ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' === $method ) {
			if ( 'connections' === $tab ) {
				check_admin_referer( 'rcs_connections' ); $input = wp_unslash( $_POST );
				if ( 'save' === ( $input['connection_action'] ?? '' ) ) { $result = Remote_Content_Shortcode_Connections::save( $input ); }
				elseif ( 'delete' === ( $input['connection_action'] ?? '' ) && is_string( $input['alias'] ?? null ) ) { $result = Remote_Content_Shortcode_Connections::delete( $input['alias'] ); }
				else { $result = new WP_Error( 'invalid_action' ); }
			} elseif ( in_array( $tab, array( 'builder', 'cache' ), true ) ) { check_admin_referer( 'rcs_tools' ); $result = self::process_action( wp_unslash( $_POST ) ); }
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Remote content', 'remote-content-shortcode' ) . '</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) { echo '<a class="nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => 'remote-content-shortcode', 'tab' => $slug ), admin_url( 'tools.php' ) ) ) . '">' . esc_html( $label ) . '</a>'; }
		echo '</nav>';
		self::result( $result, $tab );
		switch ( $tab ) {
			case 'builder': self::builder(); break;
			case 'connections': self::connections(); break;
			case 'cache': self::cache(); break;
			case 'usage': self::usage(); break;
			case 'help': self::help(); break;
			default: do_action( 'remote_content_shortcode_admin_tab_' . $tab );
		}
		echo '</div>';
	}
	private static function result( $result, $tab ) {
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Request failed.', 'remote-content-shortcode' ) . ' ' . esc_html( sanitize_key( $result->get_error_code() ) );
			if ( 'invalid_builder_body' === $result->get_error_code() ) { echo ' ' . esc_html__( 'Remove remote_content shortcode delimiters from the POST body. Use a source filter for these payloads.', 'remote-content-shortcode' ); }
			echo '</p></div>';
		} elseif ( is_int( $result ) || ( 'connections' === $tab && ( is_string( $result ) || true === $result ) ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( 'connections' === $tab ? __( 'Connection updated.', 'remote-content-shortcode' ) : __( 'Plugin cache cleared.', 'remote-content-shortcode' ) ) . '</p></div>';
		} elseif ( is_string( $result ) ) {
			echo '<h2>' . esc_html__( 'Generated shortcode', 'remote-content-shortcode' ) . '</h2><p>' . esc_html__( 'Copy this shortcode into a Shortcode block.', 'remote-content-shortcode' ) . '</p><textarea class="large-text code" rows="6" readonly aria-label="' . esc_attr__( 'Generated shortcode', 'remote-content-shortcode' ) . '">' . esc_textarea( $result ) . '</textarea>';
		} elseif ( is_array( $result ) ) {
			echo '<h2>' . esc_html__( 'Diagnostics', 'remote-content-shortcode' ) . '</h2><dl>';
			$labels = array( 'ok' => __( 'Successful import', 'remote-content-shortcode' ), 'error_code' => __( 'Error code', 'remote-content-shortcode' ), 'http_status' => __( 'HTTP status', 'remote-content-shortcode' ), 'cache_status' => __( 'Cache', 'remote-content-shortcode' ), 'selector_matches' => __( 'Matching elements', 'remote-content-shortcode' ), 'duration_ms' => __( 'Elapsed time (ms)', 'remote-content-shortcode' ) );
			foreach ( $labels as $key => $label ) {
				$value = isset( $result[ $key ] ) && is_scalar( $result[ $key ] ) ? (string) $result[ $key ] : __( 'Not available', 'remote-content-shortcode' );
				if ( 'ok' === $key && isset( $result[ $key ] ) && is_bool( $result[ $key ] ) ) { $value = $result[ $key ] ? __( 'Yes', 'remote-content-shortcode' ) : __( 'No', 'remote-content-shortcode' ); }
				echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
			}
			echo '</dl>';
			if ( is_string( $result['html'] ?? null ) ) { echo '<h2>' . esc_html__( 'Preview', 'remote-content-shortcode' ) . '</h2><div>' . wp_kses_post( $result['html'] ) . '</div><details><summary>' . esc_html__( 'Sanitized HTML', 'remote-content-shortcode' ) . '</summary><textarea class="large-text code" readonly rows="6">' . esc_textarea( wp_kses_post( $result['html'] ) ) . '</textarea></details>'; }
		}
	}
	private static function builder() {
		echo '<form method="post">'; wp_nonce_field( 'rcs_tools' );
		echo '<p>' . esc_html__( 'Enter public source settings to test. Enter a URL to clear its cache, or leave it empty to clear the plugin cache.', 'remote-content-shortcode' ) . '</p><table class="form-table">';
		$defaults = array( 'timeout' => '5', 'cache_ttl' => '3600', 'stale_ttl' => '0' );
		foreach ( self::fields() as $key => $label ) {
			echo '<tr><th><label for="rcs-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( in_array( $key, array( 'rewrite_urls', 'cache', 'strip_tags', 'htmlentities' ), true ) ) {
				echo '<select id="rcs-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '"><option value="true"' . ( 'cache' === $key ? ' selected' : '' ) . '>' . esc_html__( 'Yes', 'remote-content-shortcode' ) . '</option><option value="false"' . ( 'cache' === $key ? '' : ' selected' ) . '>' . esc_html__( 'No', 'remote-content-shortcode' ) . '</option></select>';
			} elseif ( 'method' === $key ) { echo '<select id="rcs-method" name="method"><option>GET</option><option>POST</option></select>'; }
			else {
				echo '<input class="regular-text" id="rcs-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . ( isset( $defaults[ $key ] ) ? 'number' : 'text' ) . '"';
				if ( 'timeout' === $key ) { echo ' min="1" max="5"'; } elseif ( isset( $defaults[ $key ] ) ) { echo ' min="0" max="86400"'; }
				echo ' value="' . esc_attr( $defaults[ $key ] ?? '' ) . '">';
			}
			echo '</td></tr>';
		}
		echo '<tr><th><label for="rcs-body">' . esc_html__( 'POST body', 'remote-content-shortcode' ) . '</label></th><td><textarea class="large-text code" id="rcs-body" name="body" rows="4" maxlength="65536"></textarea></td></tr></table><p>' . esc_html__( 'Test requests fetch fresh content and bypass visitor caching. Cache settings belong in the shortcode or block.', 'remote-content-shortcode' ) . '</p><p>' . esc_html__( 'Use a connection alias only. Manage secrets on the Connections tab.', 'remote-content-shortcode' ) . '</p><p><button class="button button-primary" name="rcs_action" value="generate">' . esc_html__( 'Generate shortcode', 'remote-content-shortcode' ) . '</button> <button class="button" name="rcs_action" value="test">' . esc_html__( 'Test content', 'remote-content-shortcode' ) . '</button> <button class="button" name="rcs_action" value="clear">' . esc_html__( 'Clear cache', 'remote-content-shortcode' ) . '</button></p></form>';
	}
	private static function connections() {
		echo '<h2>' . esc_html__( 'Connections', 'remote-content-shortcode' ) . '</h2><p>' . esc_html__( 'Credentials apply only to the exact HTTPS origin, path and methods below. Saved secrets are encrypted with the WordPress salts. Back up those salts before changing them.', 'remote-content-shortcode' ) . '</p>';
		$all = Remote_Content_Shortcode_Connections::all();
		foreach ( $all as $alias => $row ) {
			if ( $row['read_only'] ) { echo '<p><code>' . esc_html( $alias ) . '</code> ' . esc_html__( 'Configured in PHP, read only.', 'remote-content-shortcode' ) . '</p>'; }
			else { self::connection_form( $alias, $row ); }
		}
		if ( count( array_filter( $all, static fn( $row ) => ! $row['read_only'] ) ) < Remote_Content_Shortcode_Connections::LIMIT ) { echo '<h3>' . esc_html__( 'Add connection', 'remote-content-shortcode' ) . '</h3>'; self::connection_form( '', array() ); }
	}
	private static function connection_form( $alias, $row ) {
		echo '<form method="post">'; wp_nonce_field( 'rcs_connections' ); echo '<table class="form-table">';
		foreach ( array( 'alias' => 'Alias', 'origin' => 'HTTPS origin', 'path' => 'Exact endpoint path', 'header' => 'API key header' ) as $key => $label ) { echo '<tr><th>' . esc_html( $label ) . '</th><td><input aria-label="' . esc_attr( $label ) . '" class="regular-text" name="' . esc_attr( $key ) . '" value="' . esc_attr( 'alias' === $key ? $alias : ( $row[ $key ] ?? ( 'header' === $key ? 'X-API-Key' : '' ) ) ) . '"' . ( 'alias' === $key && '' !== $alias ? ' readonly' : '' ) . '></td></tr>'; }
		echo '<tr><th>' . esc_html__( 'Authentication', 'remote-content-shortcode' ) . '</th><td><select name="auth" aria-label="' . esc_attr__( 'Authentication', 'remote-content-shortcode' ) . '">';
		foreach ( array( 'basic' => 'Basic', 'bearer' => 'Bearer', 'api_key' => 'API key' ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $row['auth'] ?? 'bearer', $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></td></tr><tr><th>' . esc_html__( 'Allowed methods', 'remote-content-shortcode' ) . '</th><td>';
		foreach ( array( 'GET', 'POST' ) as $method ) { echo '<label><input type="checkbox" name="methods[]" value="' . esc_attr( $method ) . '"' . checked( in_array( $method, $row['methods'] ?? array( 'GET' ), true ), true, false ) . '> ' . esc_html( $method ) . '</label> '; }
		echo '</td></tr><tr><th>' . esc_html__( 'Secret', 'remote-content-shortcode' ) . '</th><td><input type="password" autocomplete="new-password" name="secret" value="" aria-label="' . esc_attr__( 'Secret', 'remote-content-shortcode' ) . '"><p class="description">' . esc_html__( 'Basic uses username:password. Leave blank to keep an existing secret.', 'remote-content-shortcode' ) . '</p>';
		if ( '' !== $alias ) { echo '<label><input type="checkbox" name="delete_secret" value="1"> ' . esc_html__( 'Remove saved secret', 'remote-content-shortcode' ) . '</label>'; }
		echo '</td></tr></table><p><button class="button button-primary" name="connection_action" value="save">' . esc_html__( 'Save connection', 'remote-content-shortcode' ) . '</button> ';
		if ( '' !== $alias ) { echo '<button class="button" name="connection_action" value="delete">' . esc_html__( 'Delete connection', 'remote-content-shortcode' ) . '</button>'; }
		echo '</p></form>';
	}
	private static function cache() {
		$index = get_option( Remote_Content_Shortcode_Cache::INDEX, array() ); $count = 0; $bytes = 0;
		foreach ( is_array( $index ) ? $index : array() as $id => $row ) { if ( is_string( $id ) && preg_match( '/^rcs_v2_[a-f0-9]{64}$/D', $id ) && is_array( $row ) && is_int( $row['expires'] ?? null ) && $row['expires'] > time() && is_int( $row['bytes'] ?? null ) && $row['bytes'] >= 0 ) { ++$count; $bytes += $row['bytes']; } }
		/* translators: 1: Number of active cache entries, 2: Stored response bytes. */
		echo '<h2>' . esc_html__( 'Cache', 'remote-content-shortcode' ) . '</h2><p>' . esc_html( sprintf( __( 'Active indexed entries: %1$d. Stored bytes: %2$d.', 'remote-content-shortcode' ), $count, $bytes ) ) . '</p><p>' . esc_html__( 'Only anonymous public GET responses are cached. Authenticated requests and POST requests bypass caching. An external cache can evict entries before their indexed expiry.', 'remote-content-shortcode' ) . '</p><form method="post">'; wp_nonce_field( 'rcs_tools' );
		echo '<p><label>' . esc_html__( 'Source URL, or blank for all plugin entries', 'remote-content-shortcode' ) . ' <input name="url" class="regular-text" type="text" value=""></label></p><p><button class="button" name="rcs_action" value="clear">' . esc_html__( 'Clear cache', 'remote-content-shortcode' ) . '</button></p></form>';
	}
	private static function usage() {
		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bounded read-only pagination does not mutate content.
		$page = is_scalar( $_GET['usage_page'] ?? null ) ? min( 10000, max( 1, absint( $_GET['usage_page'] ) ) ) : 1;
		// Query only local post/page content. Fetch one extra row instead of counting all matches.
		$shortcode = '%' . $wpdb->esc_like( '[remote_content' ) . '%'; $block = '%' . $wpdb->esc_like( '<!-- wp:remote-content-shortcode/remote-content' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh paginated ID/title lookup only, without loading or fetching remote content.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status NOT IN ('auto-draft','trash','inherit') AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID ASC LIMIT 21 OFFSET %d", $shortcode, $block, ( $page - 1 ) * 20 ) );
		$rows = is_array( $rows ) ? $rows : array();
		echo '<h2>' . esc_html__( 'Usage', 'remote-content-shortcode' ) . '</h2><p>' . esc_html__( 'Local posts and pages containing the shortcode or block. Sources are never fetched during this scan.', 'remote-content-shortcode' ) . '</p><ul>';
		foreach ( array_slice( $rows, 0, 20 ) as $row ) { echo '<li data-rcs-usage="' . esc_attr( $row->ID ) . '"><a href="' . esc_url( get_edit_post_link( $row->ID ) ) . '">' . esc_html( '' !== $row->post_title ? $row->post_title : __( '(Untitled)', 'remote-content-shortcode' ) ) . '</a> ' . esc_html( $row->post_type ) . '</li>'; }
		echo '</ul><nav aria-label="' . esc_attr__( 'Usage pagination', 'remote-content-shortcode' ) . '">';
		foreach ( array( -1 => __( 'Previous', 'remote-content-shortcode' ), 1 => __( 'Next', 'remote-content-shortcode' ) ) as $step => $label ) { if ( ( -1 === $step && $page > 1 ) || ( 1 === $step && count( $rows ) > 20 && $page < 10000 ) ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'remote-content-shortcode', 'tab' => 'usage', 'usage_page' => $page + $step ), admin_url( 'tools.php' ) ) ) . '">' . esc_html( $label ) . '</a> '; } }
		echo '</nav>';
	}
	private static function help() {
		echo '<h2>' . esc_html__( 'Help', 'remote-content-shortcode' ) . '</h2><p>' . esc_html__( 'Choose a public source URL, narrow the result with a CSS selector, then test and copy the shortcode. Use connections for private endpoints. Never paste passwords or tokens into shortcode attributes.', 'remote-content-shortcode' ) . '</p><p>' . esc_html__( 'POST bodies are stored in page content. Use them only for public payloads. Use PHP filters for private request data. Support summaries exclude source URLs, query parameters, credential names and content.', 'remote-content-shortcode' ) . '</p>';
		$summary = array( 'WordPress: ' . get_bloginfo( 'version' ), 'PHP: ' . PHP_VERSION, 'DOM: ' . ( extension_loaded( 'dom' ) ? 'yes' : 'no' ), 'cURL: ' . ( extension_loaded( 'curl' ) ? 'yes' : 'no' ), 'Authenticated encryption: ' . ( function_exists( 'sodium_crypto_secretbox' ) || ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) ? 'available' : 'unavailable' ), 'External object cache: ' . ( wp_using_ext_object_cache() ? 'yes' : 'no' ) );
		echo '<h3>' . esc_html__( 'Support summary', 'remote-content-shortcode' ) . '</h3><textarea readonly class="large-text code" rows="7" aria-label="' . esc_attr__( 'Support summary', 'remote-content-shortcode' ) . '">' . esc_textarea( implode( "\n", $summary ) ) . '</textarea>';
	}
}
