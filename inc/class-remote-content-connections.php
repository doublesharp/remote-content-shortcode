<?php
/** Bounded endpoint connections. Secrets never enter metadata or editor content. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Remote_Content_Shortcode_Connections {
	const OPTION = 'rcs_connections_v1';
	const LOCK = 'rcs_connections_v1_lock';
	const LIMIT = 32;
	private static function insert_lock( $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic acquisition prevents concurrent inventory writes from restoring removed secrets.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK, $token ) );
		wp_cache_delete( self::LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === $inserted;
	}
	private static function unlock( $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release only this mutation's token, never a replacement owner's lock.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $token ) );
		wp_cache_delete( self::LOCK, 'options' );
	}
	private static function lock() {
		global $wpdb;
		$token = ( time() + 60 ) . ':' . wp_generate_uuid4();
		if ( ! self::insert_lock( $token ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Inspect the actual lock owner before recovering an abandoned mutation.
			$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
			if ( ! is_string( $old ) || (int) $old >= time() ) { return false; }
			self::unlock( $old );
			if ( ! self::insert_lock( $token ) ) { return false; }
		}
		// Invalidate cached option reads when a mutation begins.
		wp_cache_delete( self::OPTION, 'options' );
		return $token;
	}
	private static function inventory() {
		global $wpdb;
		// Readers can repopulate an old option cache after invalidation. Revocations and mutations must use the database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached option snapshots must never restore or resolve removed credentials.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		return is_string( $raw ) ? maybe_unserialize( $raw ) : false;
	}
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve WordPress core option hooks around the atomic storage update.
	private static function write( $rows, $token ) {
		global $wpdb;
		$old = self::inventory();
		$value = sanitize_option( self::OPTION, $rows );
		$value = apply_filters( 'pre_update_option_' . self::OPTION, $value, $old, self::OPTION );
		$value = apply_filters( 'pre_update_option', $value, self::OPTION, $old );
		if ( $value === $old ) { return $rows === $old; }
		do_action( 'update_option', self::OPTION, $old, $value );
		// Ensure the row exists without overwriting another writer's inventory.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A non-overwriting insert prepares the atomic ownership-checked update below.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::OPTION, maybe_serialize( array() ) ) );
		// The join checks the current lock owner in the same statement that writes credentials.
		// An expired writer must not publish its earlier snapshot after a replacement owner commits.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress update_option cannot condition its write on the mutation lock token.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} AS inventory INNER JOIN {$wpdb->options} AS owner ON owner.option_name = %s AND owner.option_value = %s SET inventory.option_value = %s, inventory.autoload = 'off' WHERE inventory.option_name = %s", self::LOCK, $token, maybe_serialize( $value ), self::OPTION ) );
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( $updated ) {
			do_action( 'update_option_' . self::OPTION, $old, $value, self::OPTION );
			do_action( 'updated_option', self::OPTION, $old, $value );
		}
		return false !== $updated && self::inventory() === $rows;
	}
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	public static function register() { add_filter( 'remote_content_shortcode_credentials', array( __CLASS__, 'definitions' ), 5 ); }
	private static function stored() {
		$stored = self::inventory(); $rows = array();
		if ( ! is_array( $stored ) ) { return $rows; }
		foreach ( $stored as $alias => $row ) {
			$alias = (string) $alias;
			if ( ! self::alias_valid( $alias ) || ! is_array( $row ) || is_wp_error( self::scope( $row ) ) ) { continue; }
			$rows[ $alias ] = $row;
			if ( count( $rows ) >= self::LIMIT ) { break; }
		}
		return $rows;
	}
	private static function alias_valid( $alias ) { return is_string( $alias ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,64}$/D', $alias ); }
	private static function scope( $input ) {
		foreach ( array( 'auth', 'origin', 'path' ) as $key ) { if ( ! is_string( $input[ $key ] ?? null ) ) { return new WP_Error( 'invalid_scope' ); } }
		$origin = wp_parse_url( $input['origin'] ); $path = $input['path']; $methods = $input['methods'] ?? null;
		if ( ! $origin || 'https' !== ( $origin['scheme'] ?? '' ) || empty( $origin['host'] ) || isset( $origin['user'] ) || isset( $origin['pass'] ) || isset( $origin['query'] ) || isset( $origin['fragment'] ) || ! in_array( $origin['path'] ?? '', array( '', '/' ), true ) || strlen( $input['origin'] ) > 512 || preg_match( '/[\x00-\x20\\\\]/', $input['origin'] ) ) { return new WP_Error( 'invalid_origin' ); }
		if ( '' === $path || strlen( $path ) > 2048 || '/' !== $path[0] || preg_match( '/[?#\\\\\x00-\x20]|%(?:2e|2f|5c|25)|(?:^|\/)\.{1,2}(?:\/|$)/i', $path ) || str_contains( $path, '//' ) ) { return new WP_Error( 'invalid_path' ); }
		if ( ! is_array( $methods ) || ! $methods || count( $methods ) > 2 ) { return new WP_Error( 'invalid_methods' ); }
		foreach ( $methods as $method ) { if ( ! is_string( $method ) || ! in_array( $method, array( 'GET', 'POST' ), true ) ) { return new WP_Error( 'invalid_methods' ); } }
		$auth = $input['auth']; $header = $input['header'] ?? 'X-API-Key';
		if ( ! in_array( $auth, array( 'basic', 'bearer', 'api_key' ), true ) ) { return new WP_Error( 'invalid_auth' ); }
		if ( 'api_key' === $auth && ( ! is_string( $header ) || strlen( $header ) > 64 || ! preg_match( '/^(?:X-[A-Za-z0-9-]+|Api-Key)$/iD', $header ) || preg_match( '/^X-(?:Forwarded(?:-|$)|Original(?:-|$)|Rewrite(?:-|$)|HTTP-Method(?:-|$)|Method-Override$|(?:Real|Client|Originating|Cluster-Client|True-Client|Connecting|CF-Connecting)-IP$|Remote-(?:Addr|IP)$|Via$|Host$|Cookie$|Proxy(?:-|$))/i', $header ) ) ) { return new WP_Error( 'invalid_header' ); }
		return array( 'auth' => $auth, 'origin' => 'https://' . strtolower( $origin['host'] ) . ( isset( $origin['port'] ) && 443 !== $origin['port'] ? ':' . $origin['port'] : '' ), 'path' => $path, 'methods' => array_values( array_unique( $methods ) ), 'header' => 'api_key' === $auth ? $header : 'X-API-Key' );
	}
	public static function definitions( $sources ) {
		$sources = is_array( $sources ) ? $sources : array();
		foreach ( self::stored() as $alias => $row ) {
			$alias = (string) $alias;
			if ( ! self::alias_valid( $alias ) || ! is_array( $row ) || isset( $sources[ $alias ] ) ) { continue; }
			$scope = self::scope( $row );
			if ( ! is_wp_error( $scope ) ) { $sources[ $alias ] = array( 'type' => 'connection', 'key' => $alias ) + $scope; }
		}
		return $sources;
	}
	private static function code_alias( $alias ) {
		$sources = apply_filters( 'remote_content_shortcode_credentials', array() );
		$own = self::definitions( array() );
		return is_array( $sources ) && isset( $sources[ $alias ] ) && ( ! isset( $own[ $alias ] ) || $sources[ $alias ] !== $own[ $alias ] );
	}
	public static function all() {
		if ( ! current_user_can( 'manage_options' ) ) { return array(); }
		$sources = apply_filters( 'remote_content_shortcode_credentials', array() ); $safe = array(); $own = self::definitions( array() );
		if ( ! is_array( $sources ) ) { return $safe; }
		$stored = self::stored();
		foreach ( $sources as $alias => $source ) {
			$alias = (string) $alias;
			if ( ! self::alias_valid( $alias ) || ! is_array( $source ) ) { continue; }
			$read_only = ! isset( $own[ $alias ] ) || $source !== $own[ $alias ];
			// Code aliases expose their name only. Their option/constant names and scope may be private.
			$safe[ $alias ] = array( 'read_only' => $read_only );
			if ( ! $read_only ) { $safe[ $alias ] += self::scope( $source ); $safe[ $alias ]['has_secret'] = is_string( $stored[ $alias ]['sealed'] ?? null ) && '' !== $stored[ $alias ]['sealed']; }
		}
		return $safe;
	}
	private static function authorized( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden' ); }
		return is_string( $input['_wpnonce'] ?? null ) && wp_verify_nonce( $input['_wpnonce'], 'rcs_connections' ) ? true : new WP_Error( 'invalid_nonce' );
	}
	public static function save( $input ) {
		$authorized = self::authorized( $input ); if ( is_wp_error( $authorized ) ) { return $authorized; }
		$alias = $input['alias'] ?? null;
		if ( ! self::alias_valid( $alias ) ) { return new WP_Error( 'invalid_alias' ); }
		if ( self::code_alias( $alias ) ) { return new WP_Error( 'read_only_alias' ); }
		$scope = self::scope( $input ); if ( is_wp_error( $scope ) ) { return $scope; }
		$token = self::lock(); if ( false === $token ) { return new WP_Error( 'connection_busy' ); }
		try {
			$rows = self::stored();
			if ( ! isset( $rows[ $alias ] ) && count( $rows ) >= self::LIMIT ) { return new WP_Error( 'connection_limit' ); }
			$value = $input['secret'] ?? ''; $sealed = $rows[ $alias ]['sealed'] ?? '';
			if ( ! is_string( $value ) || strlen( $value ) > 8192 || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) { return new WP_Error( 'invalid_secret' ); }
			if ( ! empty( $input['delete_secret'] ) ) { $sealed = ''; }
			elseif ( '' !== $value ) {
				if ( ( 'basic' === $scope['auth'] && ! str_contains( $value, ':' ) ) || ( 'bearer' === $scope['auth'] && preg_match( '/\s/', $value ) ) ) { return new WP_Error( 'invalid_secret' ); }
				$sealed = Remote_Content_Shortcode_Secrets::seal( $value ); if ( is_wp_error( $sealed ) ) { return $sealed; }
			} elseif ( '' === $sealed && ! isset( $rows[ $alias ] ) ) { return new WP_Error( 'missing_secret' ); }
			$rows[ $alias ] = $scope + array( 'sealed' => $sealed );
			return self::write( $rows, $token ) ? $alias : new WP_Error( 'connection_storage_failed' );
		} finally { self::unlock( $token ); }
	}
	public static function delete( $alias ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorized() verifies the connection nonce and administrator capability.
		$authorized = self::authorized( $_REQUEST ); if ( is_wp_error( $authorized ) ) { return $authorized; }
		if ( ! self::alias_valid( $alias ) ) { return new WP_Error( 'invalid_alias' ); }
		if ( self::code_alias( $alias ) ) { return new WP_Error( 'read_only_alias' ); }
		$token = self::lock(); if ( false === $token ) { return new WP_Error( 'connection_busy' ); }
		try {
			$rows = self::stored(); unset( $rows[ $alias ] );
			return self::write( $rows, $token ) ? true : new WP_Error( 'connection_storage_failed' );
		} finally { self::unlock( $token ); }
	}
	public static function secret( $alias, $expected = null ) {
		if ( ! self::alias_valid( $alias ) || self::code_alias( $alias ) ) { return new WP_Error( 'secret_unavailable' ); }
		$row = self::stored()[ $alias ] ?? null;
		if ( ! is_array( $row ) ) { return new WP_Error( 'secret_unavailable' ); }
		// Bind the value to this same validated row, even if another request edits the option.
		$definition = array( 'type' => 'connection', 'key' => $alias ) + self::scope( $row );
		if ( null !== $expected && ( ! is_array( $expected ) || $definition !== $expected ) ) { return new WP_Error( 'secret_unavailable' ); }
		return Remote_Content_Shortcode_Secrets::open( $row['sealed'] ?? '' );
	}
}
