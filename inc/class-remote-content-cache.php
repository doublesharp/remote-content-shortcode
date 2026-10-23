<?php
/** Bounded, per-site cache for public remote GET responses. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Remote_Content_Shortcode_Cache {
	const GROUP = 'remote_content_shortcode';
	const INDEX = 'rcs_v2_cache_index';
	const LOCK = 'rcs_v2_cache_lock';
	const MAX_ENTRIES = 32;
	const MAX_BYTES = 8388608;

	private static function id( $key ) { return 'rcs_v2_' . hash( 'sha256', (string) $key ); }
	private static function read( $id ) {
		return wp_using_ext_object_cache() ? wp_cache_get( $id, self::GROUP ) : get_transient( $id );
	}
	private static function write( $id, $value, $ttl ) {
		return wp_using_ext_object_cache() ? wp_cache_set( $id, $value, self::GROUP, $ttl ) : set_transient( $id, $value, $ttl );
	}
	private static function remove( $id ) {
		wp_cache_delete( $id, self::GROUP );
		wp_cache_delete( $id, 'transient' );
		delete_option( '_transient_' . $id );
		delete_option( '_transient_timeout_' . $id );
	}
	private static function valid_response( $value ) {
		return is_array( $value ) && isset( $value['body'], $value['url'] ) && is_string( $value['body'] ) && is_string( $value['url'] );
	}
	private static function entry( $id ) {
		$entry = self::read( $id );
		if ( ! is_array( $entry ) || ! isset( $entry['fresh_until'], $entry['stale_until'], $entry['value'] ) || ! is_int( $entry['fresh_until'] ) || ! is_int( $entry['stale_until'] ) || ( ! is_wp_error( $entry['value'] ) && ! self::valid_response( $entry['value'] ) ) ) { return false; }
		// The database remains authoritative when a disconnected object cache returns
		// with old payloads and old option caches. Tokens distinguish replacement writes.
		$index = self::index();
		$meta = $index[ $id ] ?? null;
		if ( ! is_array( $meta ) || ! is_int( $meta['expires'] ?? null ) || $meta['expires'] <= time() || ( $entry['token'] ?? null ) !== ( $meta['token'] ?? null ) ) { return false; }
		return $entry;
	}
	/** The database owns metadata, including when an external cache evicts payloads. */
	private static function index() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Validity checks and locked mutations must bypass stale option caches across backend changes.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::INDEX ) );
		$index = is_string( $raw ) ? maybe_unserialize( $raw ) : array();
		return is_array( $index ) ? $index : array();
	}
	private static function save_index( $index ) {
		wp_cache_delete( self::INDEX, 'options' );
		update_option( self::INDEX, $index, false );
		return self::index() === $index;
	}

	/** Conditional SQL deletion cannot delete another writer's replacement lock. */
	private static function unlock( $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic token comparison prevents releasing a replacement owner lock.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $token ) );
		wp_cache_delete( self::LOCK, 'options' );
	}
	private static function insert_lock( $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE provides atomic acquisition without add_option upsert races.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK, $token ) );
		wp_cache_delete( self::LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === $inserted;
	}
	private static function lock() {
		global $wpdb;
		$token = ( time() + 60 ) . ':' . wp_generate_uuid4();
		if ( self::insert_lock( $token ) ) { return $token; }
		// Read the database directly so a cached option cannot disguise a new owner.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock recovery must inspect the database owner rather than stale option caches.
		$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		if ( is_string( $old ) && (int) $old < time() ) {
			self::unlock( $old );
			if ( self::insert_lock( $token ) ) { return $token; }
		}
		return false;
	}
	private static function prune( &$index ) {
		foreach ( $index as $id => $meta ) {
			// Invalid identifiers must never cause deletion of another plugin's payload.
			if ( ! is_string( $id ) || ! preg_match( '/^rcs_v2_[a-f0-9]{64}$/D', $id ) ) {
				unset( $index[ $id ] );
				continue;
			}
			// Remove owned payloads when their metadata cannot safely account for them.
			if ( ! is_array( $meta ) || ! isset( $meta['expires'], $meta['bytes'], $meta['tag'] ) || ! is_int( $meta['expires'] ) || ! is_int( $meta['bytes'] ) || $meta['bytes'] < 0 || ! is_string( $meta['tag'] ) || $meta['expires'] <= time() ) {
				self::remove( $id );
				unset( $index[ $id ] );
			}
		}
	}

	public static function get( $key, $allow_stale = false ) {
		$entry = self::entry( self::id( $key ) );
		if ( false === $entry ) { return false; }
		if ( $allow_stale ) {
			if ( $entry['stale_until'] <= time() ) { return false; }
			$good = isset( $entry['good'] ) ? $entry['good'] : $entry['value'];
			return self::valid_response( $good ) ? $good : false;
		}
		return $entry['fresh_until'] > time() ? $entry['value'] : false;
	}

	public static function set( $key, $value, $ttl, $stale_ttl = 0, $tag = '' ) {
		if ( ! is_wp_error( $value ) && ! self::valid_response( $value ) ) { return false; }
		if ( is_wp_error( $value ) ) {
			$code = substr( sanitize_key( (string) $value->get_error_code() ), 0, 80 );
			$value = new WP_Error( $code ?: 'remote_content_error' );
		}
		$ttl = max( 0, (int) $ttl );
		if ( ! $ttl ) { return false; }
		$token = self::lock();
		if ( false === $token ) { return false; }
		try {
			$index = self::index();
			self::prune( $index );
			$id = self::id( $key );
			$now = time();
			$entry = array( 'value' => $value, 'fresh_until' => $now + $ttl, 'stale_until' => $now + $ttl + max( 0, (int) $stale_ttl ), 'token' => wp_generate_uuid4() );
			if ( is_wp_error( $value ) ) {
				$old = self::entry( $id );
				$entry['stale_until'] = 0;
				if ( is_array( $old ) && isset( $old['stale_until'], $old['value'] ) && $old['stale_until'] > $now ) {
					$good = isset( $old['good'] ) ? $old['good'] : $old['value'];
					if ( self::valid_response( $good ) ) {
						$entry['good'] = $good;
						$entry['stale_until'] = $old['stale_until'];
					}
				}
			}
			$bytes = strlen( serialize( $entry ) );
			if ( $bytes > self::MAX_BYTES ) { self::save_index( $index ); return false; }
			self::remove( $id );
			unset( $index[ $id ] );
			while ( count( $index ) >= self::MAX_ENTRIES || array_sum( array_column( $index, 'bytes' ) ) + $bytes > self::MAX_BYTES ) {
				$oldest = key( $index );
				self::remove( $oldest );
				unset( $index[ $oldest ] );
			}
			$expires = max( $entry['fresh_until'], $entry['stale_until'] );
			$index[ $id ] = array( 'bytes' => $bytes, 'expires' => $expires, 'tag' => hash( 'sha256', (string) $tag ), 'token' => $entry['token'] );
			// Reserve the bytes before storing the payload; a failed write is a cache miss.
			if ( ! self::save_index( $index ) ) { return false; }
			return self::write( $id, $entry, max( 1, $expires - $now ) );
		} finally { self::unlock( $token ); }
	}

	public static function delete( $key ) { return self::clear_entries( null, self::id( $key ) ); }
	public static function clear( $tag = null ) { return self::clear_entries( $tag, null ); }
	private static function clear_entries( $tag, $key ) {
		$token = self::lock();
		if ( false === $token ) { return false; }
		try {
			$index = self::index();
			self::prune( $index );
			$removed = 0;
			foreach ( $index as $id => $meta ) {
				if ( null !== $key ? $id === $key : ( null === $tag || hash( 'sha256', (string) $tag ) === $meta['tag'] ) ) {
					self::remove( $id );
					unset( $index[ $id ] );
					++$removed;
				}
			}
			return self::save_index( $index ) ? $removed : false;
		} finally { self::unlock( $token ); }
	}
}
