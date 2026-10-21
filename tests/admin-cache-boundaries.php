<?php
/** Boundary integration cases. Run with wp eval-file on a disposable WordPress site. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function rcs_ac_assert( $ok, $message ) {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
}
class RCS_Admin_Boundary_Die extends RuntimeException {}

$tests = array();
$tests['Tools menu registers the real WordPress management page'] = static function () {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	do_action( 'admin_menu' );
	global $submenu;
	$matches = array_filter( $submenu['tools.php'] ?? array(), static fn( $item ) => 'remote-content-shortcode' === $item[2] );
	rcs_ac_assert( 1 === count( $matches ), 'Tools menu entry is missing or duplicated.' );
	$item = reset( $matches );
	rcs_ac_assert( 'manage_options' === $item[1], 'Tools menu capability changed.' );
	$hook = get_plugin_page_hookname( 'remote-content-shortcode', 'tools.php' );
	rcs_ac_assert( false !== has_action( $hook, array( 'Remote_Content_Shortcode_Admin', 'page' ) ), 'Tools page callback is not registered.' );
};

$page = static function ( $post, $method = 'POST' ) {
	$saved = array( $_POST, $_REQUEST, $_SERVER['REQUEST_METHOD'] ?? null );
	$level = ob_get_level();
	try {
		$_POST = $post;
		$_REQUEST = $post;
		$_SERVER['REQUEST_METHOD'] = $method;
		ob_start();
		Remote_Content_Shortcode_Admin::page();
		return ob_get_clean();
	} finally {
		while ( ob_get_level() > $level ) { ob_end_clean(); }
		list( $_POST, $_REQUEST, $previous_method ) = $saved;
		if ( null === $previous_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $previous_method; }
	}
};
$tests['successful POST diagnostics render the imported preview'] = static function () use ( $page ) {
	$html = $page( array( 'rcs_action' => 'test', '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'url' => 'http://source.rcs.test/ok', 'selector' => 'p', 'rewrite_urls' => 'false', 'timeout' => '3' ) );
	rcs_ac_assert( str_contains( $html, '<dd>' . esc_html__( 'Yes', 'remote-content-shortcode' ) . '</dd>' ) && str_contains( $html, 'fixture success' ), 'Successful diagnostics or preview are missing.' );
};
$tests['POST validation errors render a safe error notice'] = static function () use ( $page ) {
	$html = $page( array( 'rcs_action' => 'test', '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'userpwd' => 'raw:password<script>' ) );
	rcs_ac_assert( str_contains( $html, 'notice-error' ) && str_contains( $html, 'invalid_alias' ) && ! str_contains( $html, 'raw:password' ), 'Validation error was omitted or leaked credentials.' );
};
$tests['POST clear returns a success notice and removes cached content'] = static function () use ( $page ) {
	rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'admin-page-clear', array( 'body' => 'cached', 'url' => 'https://example.com/' ), 60 ), 'Could not create clear fixture.' );
	$html = $page( array( 'rcs_action' => 'clear', '_wpnonce' => wp_create_nonce( 'rcs_tools' ) ) );
	rcs_ac_assert( str_contains( $html, 'notice-success' ) && false === Remote_Content_Shortcode_Cache::get( 'admin-page-clear' ), 'Admin clear failed.' );
};
$tests['GET and malformed request methods only show the form'] = static function () use ( $page ) {
	foreach ( array( 'GET', null, array( 'POST' ) ) as $method ) {
		$html = $page( array( 'rcs_action' => 'clear', '_wpnonce' => 'invalid' ), $method );
		rcs_ac_assert( str_contains( $html, '<form method="post">' ) && ! str_contains( $html, 'notice-success' ), 'Non-POST request performed an action.' );
	}
};
$tests['tools form defaults relative URL rewriting to disabled'] = static function () use ( $page ) {
	$html = $page( array(), 'GET' );
	rcs_ac_assert( str_contains( $html, '<select id="rcs-rewrite_urls" name="rewrite_urls"><option value="true">' . esc_html__( 'Yes', 'remote-content-shortcode' ) . '</option><option value="false" selected>' ), 'Rewrite URL default changed.' );
};
$tests['page denies anonymous users and rejects invalid POST nonces'] = static function () use ( $page ) {
	$handler = static fn() => static function ( $message ) { throw new RCS_Admin_Boundary_Die( is_scalar( $message ) ? (string) $message : 'wp_die' ); };
	add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	try {
		wp_set_current_user( 0 );
		$denied = false;
		try { $page( array(), 'GET' ); } catch ( RCS_Admin_Boundary_Die $error ) { $denied = true; }
		rcs_ac_assert( $denied, 'Anonymous user reached the tools form.' );
		wp_set_current_user( 1 );
		$denied = false;
		try { $page( array( '_wpnonce' => 'invalid', 'rcs_action' => 'clear' ) ); } catch ( RCS_Admin_Boundary_Die $error ) { $denied = true; }
		rcs_ac_assert( $denied, 'POST nonce verification did not terminate the request.' );
	} finally { remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX ); wp_set_current_user( 1 ); }
};
$tests['direct action rejects non-string actions and non-string nonces'] = static function () {
	foreach ( array( null, false, 3, array( 'clear' ), new stdClass() ) as $action ) {
		$result = Remote_Content_Shortcode_Admin::process_action( array( 'rcs_action' => $action, '_wpnonce' => wp_create_nonce( 'rcs_tools' ) ) );
		rcs_ac_assert( is_wp_error( $result ) && 'invalid_action' === $result->get_error_code(), 'Malformed action was accepted.' );
	}
	foreach ( array( null, false, 3, array( 'nonce' ), new stdClass() ) as $nonce ) {
		$result = Remote_Content_Shortcode_Admin::process_action( array( 'rcs_action' => 'clear', '_wpnonce' => $nonce ) );
		rcs_ac_assert( is_wp_error( $result ) && 'invalid_nonce' === $result->get_error_code(), 'Malformed nonce was accepted.' );
	}
};
$tests['admin diagnostics ignore malformed optional attributes'] = static function () {
	foreach ( array( null, false, array( 'bad' ), new stdClass() ) as $invalid ) {
		$result = Remote_Content_Shortcode_Admin::process_action( array( 'rcs_action' => 'test', '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'url' => $invalid, 'selector' => $invalid, 'remove' => $invalid, 'fallback' => $invalid, 'userpwd' => $invalid, 'rewrite_urls' => $invalid, 'timeout' => $invalid ) );
		rcs_ac_assert( is_array( $result ) && false === $result['ok'] && 'invalid_url' === $result['error_code'], 'Malformed optional fields escaped validation.' );
	}
	foreach ( array( '-3', 0, 2.5, 'not-a-number' ) as $timeout ) {
		$result = Remote_Content_Shortcode_Admin::process_action( array( 'rcs_action' => 'test', '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'timeout' => $timeout, 'rewrite_urls' => 'TRUE' ) );
		rcs_ac_assert( is_array( $result ) && false === $result['ok'], 'Timeout boundary produced an invalid diagnostic response.' );
	}
};
$tests['block accepts supported types and ignores malformed optional attributes'] = static function () {
	$plugin = RemoteContentShortcode::get_instance();
	$attributes = array( 'url' => 'http://source.rcs.test/ok', 'selector' => 'p', 'remove' => '.missing', 'fallback' => 'offline', 'userpwd' => '', 'cache' => false, 'rewriteUrls' => false, 'cacheTtl' => -1, 'staleTtl' => 1.5, 'timeout' => '2.5' );
	$expected = $plugin->remote_content_shortcode( array( 'url' => $attributes['url'], 'selector' => 'p', 'remove' => '.missing', 'fallback' => 'offline', 'userpwd' => '', 'cache' => 'false', 'rewrite_urls' => 'false', 'cache_ttl' => '0', 'stale_ttl' => '1.5', 'timeout' => '2.5' ) );
	rcs_ac_assert( str_contains( $expected, 'fixture success' ) && $expected === Remote_Content_Shortcode_Block::render( $attributes ), 'Valid block mappings differ from the shortcode.' );
	$attributes['cache'] = true;
	$attributes['rewriteUrls'] = true;
	rcs_ac_assert( str_contains( Remote_Content_Shortcode_Block::render( $attributes ), 'fixture success' ), 'Boolean true attributes did not render.' );
	foreach ( array( null, false, 'malformed', 3, array(), new stdClass() ) as $invalid ) {
		$attributes = array_fill_keys( array( 'url', 'selector', 'remove', 'userpwd', 'cache', 'rewriteUrls', 'cacheTtl', 'staleTtl', 'timeout' ), $invalid );
		$attributes['fallback'] = '<b>offline</b>';
		rcs_ac_assert( '<b>offline</b>' === Remote_Content_Shortcode_Block::render( $attributes ), 'Malformed block attributes did not use the safe fallback.' );
	}
};

$tests['cache rejects invalid payloads and nonpositive TTLs without storage'] = static function () {
	foreach ( array( null, false, 'body', array(), array( 'body' => 'x' ), array( 'body' => array(), 'url' => 'https://example.com/' ), array( 'body' => 'x', 'url' => false ) ) as $invalid ) {
		rcs_ac_assert( false === Remote_Content_Shortcode_Cache::set( 'invalid', $invalid, 60 ) && false === Remote_Content_Shortcode_Cache::get( 'invalid' ), 'Invalid payload entered the cache.' );
	}
	foreach ( array( 0, -1, '0', 'not-a-number' ) as $ttl ) {
		rcs_ac_assert( false === Remote_Content_Shortcode_Cache::set( 'ttl', array( 'body' => 'x', 'url' => 'https://example.com/' ), $ttl ), 'Nonpositive TTL was accepted.' );
	}
};
$tests['oversized entries do not displace an existing valid entry'] = static function () {
	$value = array( 'body' => 'small', 'url' => 'https://example.com/' );
	rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'oversize', $value, 60 ), 'Could not create size fixture.' );
	rcs_ac_assert( false === Remote_Content_Shortcode_Cache::set( 'oversize', array( 'body' => str_repeat( 'x', Remote_Content_Shortcode_Cache::MAX_BYTES ), 'url' => 'https://example.com/' ), 60 ), 'Oversized cache entry was accepted.' );
	rcs_ac_assert( $value === Remote_Content_Shortcode_Cache::get( 'oversize' ), 'Rejected entry displaced valid cached content.' );
};
$tests['negative entries sanitize empty and overlong error codes'] = static function () {
	foreach ( array( '!!!' => 'remote_content_error', str_repeat( 'CODE', 30 ) => str_repeat( 'code', 20 ) ) as $code => $expected ) {
		rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'negative-code', new WP_Error( $code, 'private', array( 'secret' ) ), 60 ), 'Could not cache negative result.' );
		$got = Remote_Content_Shortcode_Cache::get( 'negative-code' );
		rcs_ac_assert( is_wp_error( $got ) && $expected === $got->get_error_code() && '' === $got->get_error_message() && null === $got->get_error_data(), 'Negative cache retained unsafe error metadata.' );
	}
};
$tests['expired index rows are pruned before subsequent mutations'] = static function () {
	$key = 'expired-row';
	$id = 'rcs_v2_' . hash( 'sha256', $key );
	$entry = array( 'value' => array( 'body' => 'old', 'url' => 'https://example.com/' ), 'fresh_until' => time() - 2, 'stale_until' => time() - 1 );
	if ( wp_using_ext_object_cache() ) { wp_cache_set( $id, $entry, Remote_Content_Shortcode_Cache::GROUP, 60 ); } else { set_transient( $id, $entry, 60 ); }
	update_option( Remote_Content_Shortcode_Cache::INDEX, array( $id => array( 'expires' => time() - 1, 'bytes' => strlen( serialize( $entry ) ), 'tag' => hash( 'sha256', '' ) ) ), false );
	rcs_ac_assert( false === Remote_Content_Shortcode_Cache::get( $key ) && false === Remote_Content_Shortcode_Cache::get( $key, true ), 'Expired entry remained readable.' );
	rcs_ac_assert( 0 === Remote_Content_Shortcode_Cache::delete( 'missing-key' ), 'Pruned rows were counted as explicit deletions.' );
	$stored = wp_using_ext_object_cache() ? wp_cache_get( $id, Remote_Content_Shortcode_Cache::GROUP ) : get_transient( $id );
	rcs_ac_assert( false === $stored && array() === get_option( Remote_Content_Shortcode_Cache::INDEX ), 'Expired entry was not physically pruned.' );
};
$tests['repeated negative writes preserve a still-eligible last good payload'] = static function () {
	$id = 'rcs_v2_' . hash( 'sha256', 'repeat-negative' );
	$good = array( 'body' => 'last success', 'url' => 'https://example.com/' );
	$entry = array( 'value' => $good, 'fresh_until' => time() - 1, 'stale_until' => time() + 120 );
	if ( wp_using_ext_object_cache() ) { wp_cache_set( $id, $entry, Remote_Content_Shortcode_Cache::GROUP, 120 ); } else { set_transient( $id, $entry, 120 ); }
	for ( $i = 0; $i < 2; ++$i ) {
		rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'repeat-negative', new WP_Error( 'outage' ), 60 ), 'Negative cache write failed.' );
		rcs_ac_assert( is_wp_error( Remote_Content_Shortcode_Cache::get( 'repeat-negative' ) ) && $good === Remote_Content_Shortcode_Cache::get( 'repeat-negative', true ), 'Repeated negative cache lost last good content.' );
	}
};
$tests['corrupt payloads and scalar indexes recover through public storage APIs'] = static function () {
	$id = 'rcs_v2_' . hash( 'sha256', 'corrupt' );
	try {
		foreach ( array( 'corrupt', array(), array( 'fresh_until' => time() + 60 ), array( 'stale_until' => time() + 60 ) ) as $invalid ) {
			if ( wp_using_ext_object_cache() ) { wp_cache_set( $id, $invalid, Remote_Content_Shortcode_Cache::GROUP, 60 ); } else { set_transient( $id, $invalid, 60 ); }
			rcs_ac_assert( false === Remote_Content_Shortcode_Cache::get( 'corrupt' ) && false === Remote_Content_Shortcode_Cache::get( 'corrupt', true ), 'Corrupt payload was served.' );
		}
		update_option( Remote_Content_Shortcode_Cache::INDEX, 'corrupt-index', false );
		rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'recover-index', array( 'body' => 'safe', 'url' => 'https://example.com/' ), 60 ), 'Scalar index blocked cache recovery.' );
		rcs_ac_assert( is_array( get_option( Remote_Content_Shortcode_Cache::INDEX ) ), 'Recovered index is not an array.' );
	} finally { if ( wp_using_ext_object_cache() ) { wp_cache_delete( $id, Remote_Content_Shortcode_Cache::GROUP ); } else { delete_transient( $id ); } }
};
foreach ( array( 'fresh' => false, 'stale' => true ) as $mode => $allow_stale ) {
$tests["timestamped payloads missing their value are safe $mode cache misses"] = static function () use ( $allow_stale ) {
	$id = 'rcs_v2_' . hash( 'sha256', 'missing-value' );
	$entry = array( 'fresh_until' => time() + 60, 'stale_until' => time() + 120 );
	if ( wp_using_ext_object_cache() ) { wp_cache_set( $id, $entry, Remote_Content_Shortcode_Cache::GROUP, 120 ); } else { set_transient( $id, $entry, 120 ); }
	set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
	try {
		rcs_ac_assert( false === Remote_Content_Shortcode_Cache::get( 'missing-value', $allow_stale ), 'Missing payload value was not rejected.' );
	} finally {
		restore_error_handler();
		if ( wp_using_ext_object_cache() ) { wp_cache_delete( $id, Remote_Content_Shortcode_Cache::GROUP ); } else { delete_transient( $id ); }
	}
};
}
foreach ( array( 'stale timestamp' => array( 'value' => 'malformed' ), 'payload value' => array( 'fresh_until' => time() + 60, 'stale_until' => time() + 120 ) ) as $missing => $old_entry ) {
$tests["negative writes recover from a previous entry missing $missing"] = static function () use ( $old_entry ) {
	$id = 'rcs_v2_' . hash( 'sha256', 'missing-stale' );
	if ( wp_using_ext_object_cache() ) { wp_cache_set( $id, $old_entry, Remote_Content_Shortcode_Cache::GROUP, 120 ); } else { set_transient( $id, $old_entry, 120 ); }
	set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
	try {
		rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'missing-stale', new WP_Error( 'network' ), 60 ), 'Malformed previous payload blocked a negative write.' );
		rcs_ac_assert( is_wp_error( Remote_Content_Shortcode_Cache::get( 'missing-stale' ) ) && false === Remote_Content_Shortcode_Cache::get( 'missing-stale', true ), 'Malformed prior payload became stale content.' );
	} finally {
		restore_error_handler();
		if ( wp_using_ext_object_cache() ) { wp_cache_delete( $id, Remote_Content_Shortcode_Cache::GROUP ); } else { delete_transient( $id ); }
	}
};
}
$tests['malformed index rows are discarded without warnings during mutation'] = static function () {
	$saved = get_option( Remote_Content_Shortcode_Cache::INDEX, array() );
	$id = 'rcs_v2_' . hash( 'sha256', 'malformed-index-row' );
	$meta = array( 'expires' => time() + 60, 'bytes' => 100, 'tag' => hash( 'sha256', '' ) );
	$rows = array( 'malformed', null, array(), array( 'expires' => $meta['expires'] ), array( 'expires' => $meta['expires'], 'bytes' => 100 ), array( 'bytes' => 100, 'tag' => $meta['tag'] ), array_replace( $meta, array( 'expires' => (string) $meta['expires'] ) ), array_replace( $meta, array( 'bytes' => '100' ) ), array_replace( $meta, array( 'bytes' => -1 ) ), array_replace( $meta, array( 'tag' => array() ) ) );
	set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
	try {
		foreach ( $rows as $invalid ) {
			update_option( Remote_Content_Shortcode_Cache::INDEX, array( $id => $invalid ), false );
			rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'index-recovery', array( 'body' => 'recovered', 'url' => 'https://example.com/' ), 60 ), 'Malformed index row blocked recovery.' );
			rcs_ac_assert( ! array_key_exists( $id, get_option( Remote_Content_Shortcode_Cache::INDEX ) ), 'Malformed index row survived recovery.' );
			Remote_Content_Shortcode_Cache::clear();
		}
	} finally { restore_error_handler(); update_option( Remote_Content_Shortcode_Cache::INDEX, $saved, false ); }
};
$tests['invalid index identifiers cannot delete unrelated transients'] = static function () {
	$saved = get_option( Remote_Content_Shortcode_Cache::INDEX, array() );
	$meta = array( 'expires' => time() - 1, 'bytes' => 100, 'tag' => hash( 'sha256', '' ) );
	set_transient( 'rcs_boundary_unrelated', 'preserved', 120 );
	set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
	try {
		foreach ( array( 'rcs_boundary_unrelated', 7, 'rcs_v2_bad', 'rcs_v2_' . str_repeat( 'G', 64 ) ) as $invalid_id ) {
			update_option( Remote_Content_Shortcode_Cache::INDEX, array( $invalid_id => $meta ), false );
			rcs_ac_assert( 0 === Remote_Content_Shortcode_Cache::clear(), 'Invalid index identifier was counted as a plugin cache entry.' );
			rcs_ac_assert( 'preserved' === get_transient( 'rcs_boundary_unrelated' ), 'Invalid index identifier deleted an unrelated transient.' );
			rcs_ac_assert( array() === get_option( Remote_Content_Shortcode_Cache::INDEX ), 'Invalid identifier survived index pruning.' );
		}
	} finally { restore_error_handler(); update_option( Remote_Content_Shortcode_Cache::INDEX, $saved, false ); delete_transient( 'rcs_boundary_unrelated' ); }
};
$tests['malformed metadata cannot leave readable payloads outside cache accounting'] = static function () {
	foreach ( array( false, true ) as $external ) {
		$previous = wp_using_ext_object_cache();
		wp_using_ext_object_cache( $external );
		try {
			$key = 'malformed-owned-row';
			$id = 'rcs_v2_' . hash( 'sha256', $key );
			rcs_ac_assert( Remote_Content_Shortcode_Cache::set( $key, array( 'body' => 'owned', 'url' => 'https://example.com/' ), 60 ), 'Could not create malformed metadata fixture.' );
			update_option( Remote_Content_Shortcode_Cache::INDEX, array( $id => array() ), false );
			Remote_Content_Shortcode_Cache::clear();
			rcs_ac_assert( false === Remote_Content_Shortcode_Cache::get( $key ), 'Malformed owned metadata left a readable, unaccounted payload.' );
		} finally { Remote_Content_Shortcode_Cache::clear(); wp_using_ext_object_cache( $previous ); }
	}
};
$tests['active cache lock prevents clear and delete without destroying data'] = static function () {
	$value = array( 'body' => 'keep', 'url' => 'https://example.com/' );
	rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'locked-delete', $value, 60 ), 'Could not prepare lock fixture.' );
	$owner = ( time() + 60 ) . ':boundary-owner';
	rcs_ac_assert( add_option( Remote_Content_Shortcode_Cache::LOCK, $owner, '', false ), 'Could not acquire lock fixture.' );
	try {
		rcs_ac_assert( false === Remote_Content_Shortcode_Cache::delete( 'locked-delete' ) && false === Remote_Content_Shortcode_Cache::clear(), 'Contended mutation succeeded.' );
		rcs_ac_assert( $value === Remote_Content_Shortcode_Cache::get( 'locked-delete' ) && $owner === get_option( Remote_Content_Shortcode_Cache::LOCK ), 'Contended mutation changed payload or ownership.' );
	} finally { delete_option( Remote_Content_Shortcode_Cache::LOCK ); }
};
$tests['empty cache mutations are idempotent and leave no mutation lock'] = static function () {
	update_option( Remote_Content_Shortcode_Cache::INDEX, array(), false );
	rcs_ac_assert( 0 === Remote_Content_Shortcode_Cache::clear() && 0 === Remote_Content_Shortcode_Cache::delete( 'absent' ), 'An empty cache reported a removed payload.' );
	rcs_ac_assert( array() === get_option( Remote_Content_Shortcode_Cache::INDEX ) && false === get_option( Remote_Content_Shortcode_Cache::LOCK, false ), 'An empty mutation altered accounting or retained its lock.' );
	$value = array( 'body' => 'first payload', 'url' => 'https://example.com/' );
	rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'first-entry', $value, 60 ) && $value === Remote_Content_Shortcode_Cache::get( 'first-entry' ), 'Empty-cache mutation prevented the first write.' );
};
$tests['lock disappearing after a contended insert fails closed and permits a later retry'] = static function () {
	global $wpdb;
	$owner = ( time() + 60 ) . ':vanishing-boundary-owner';
	rcs_ac_assert( add_option( Remote_Content_Shortcode_Cache::LOCK, $owner, '', false ), 'Could not prepare disappearing lock fixture.' );
	$removed = false;
	$disappear = static function ( $sql ) use ( &$removed, $wpdb ) {
		if ( ! $removed && str_starts_with( $sql, 'SELECT option_value FROM' ) && str_contains( $sql, "'" . Remote_Content_Shortcode_Cache::LOCK . "'" ) ) {
			$removed = true;
			$wpdb->delete( $wpdb->options, array( 'option_name' => Remote_Content_Shortcode_Cache::LOCK ) );
			wp_cache_delete( Remote_Content_Shortcode_Cache::LOCK, 'options' );
		}
		return $sql;
	};
	$value = array( 'body' => 'retry payload', 'url' => 'https://example.com/' );
	add_filter( 'query', $disappear );
	try {
		rcs_ac_assert( false === Remote_Content_Shortcode_Cache::set( 'vanished-lock', $value, 60 ), 'A vanished owner granted a lock without acquisition.' );
		rcs_ac_assert( $removed && false === Remote_Content_Shortcode_Cache::get( 'vanished-lock' ) && false === get_option( Remote_Content_Shortcode_Cache::LOCK, false ), 'Vanished-lock mutation published a payload or left ownership behind.' );
	} finally { remove_filter( 'query', $disappear ); delete_option( Remote_Content_Shortcode_Cache::LOCK ); }
	rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'vanished-lock', $value, 60 ) && $value === Remote_Content_Shortcode_Cache::get( 'vanished-lock' ), 'A later caller could not acquire the released lock.' );
};
$tests['failed metadata reservations never publish an unaccounted payload'] = static function () {
	$value = array( 'body' => 'existing', 'url' => 'https://example.com/' );
	rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'reservation-existing', $value, 60 ), 'Could not prepare reservation fixture.' );
	$index = get_option( Remote_Content_Shortcode_Cache::INDEX );
	$reject = static fn( $new, $old ) => $old;
	add_filter( 'pre_update_option_' . Remote_Content_Shortcode_Cache::INDEX, $reject, 10, 2 );
	try {
		rcs_ac_assert( false === Remote_Content_Shortcode_Cache::set( 'reservation-new', $value, 60 ), 'Rejected reservation published a payload.' );
		rcs_ac_assert( false === Remote_Content_Shortcode_Cache::get( 'reservation-new' ) && $value === Remote_Content_Shortcode_Cache::get( 'reservation-existing' ), 'Reservation failure altered payloads.' );
		rcs_ac_assert( $index === get_option( Remote_Content_Shortcode_Cache::INDEX ) && false === get_option( Remote_Content_Shortcode_Cache::LOCK, false ), 'Reservation failure changed accounting or leaked its lock.' );
		rcs_ac_assert( false === Remote_Content_Shortcode_Cache::clear(), 'Rejected metadata update reported a successful clear.' );
		rcs_ac_assert( false === get_option( Remote_Content_Shortcode_Cache::LOCK, false ), 'Failed clear leaked its lock.' );
	} finally { remove_filter( 'pre_update_option_' . Remote_Content_Shortcode_Cache::INDEX, $reject, 10 ); }
};
foreach ( array( 'set', 'clear', 'payload' ) as $operation ) {
	$tests[ 'cache releases its mutation lock when a storage filter throws during ' . $operation ] = static function () use ( $operation ) {
		$value = array( 'body' => 'existing', 'url' => 'https://example.com/' );
		rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'throw-existing', $value, 60 ), 'Could not prepare throwing filter fixture.' );
		$id = 'rcs_v2_' . hash( 'sha256', 'throw-new' );
		$hook = 'payload' === $operation ? 'pre_set_transient_' . $id : 'pre_update_option_' . Remote_Content_Shortcode_Cache::INDEX;
		$throw = static function () { throw new RuntimeException( 'storage filter failure' ); };
		add_filter( $hook, $throw );
		$caught = false;
		try {
			try {
				if ( 'clear' === $operation ) { Remote_Content_Shortcode_Cache::clear(); }
				else { Remote_Content_Shortcode_Cache::set( 'throw-new', $value, 60 ); }
			} catch ( RuntimeException $error ) { $caught = 'storage filter failure' === $error->getMessage(); }
			rcs_ac_assert( $caught && false === get_option( Remote_Content_Shortcode_Cache::LOCK, false ), 'Storage exception was lost or left the mutation lock behind.' );
			rcs_ac_assert( false === Remote_Content_Shortcode_Cache::get( 'throw-new' ), 'Failed storage published an incomplete payload.' );
		} finally { remove_filter( $hook, $throw ); Remote_Content_Shortcode_Cache::clear(); }
		rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'after-exception', $value, 60 ) && $value === Remote_Content_Shortcode_Cache::get( 'after-exception' ), 'A storage exception prevented subsequent writes.' );
	};
}
$tests['negative cache entries are never returned as stale successful content'] = static function () {
	$id = 'rcs_v2_' . hash( 'sha256', 'stale-negative' );
	$entry = array( 'value' => new WP_Error( 'previous-outage' ), 'fresh_until' => time() - 1, 'stale_until' => time() + 120 );
	set_transient( $id, $entry, 120 );
	try {
		rcs_ac_assert( false === Remote_Content_Shortcode_Cache::get( 'stale-negative', true ), 'An error object was served as stale content.' );
		rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'stale-negative', new WP_Error( 'new-outage' ), 60, 120 ), 'Replacing an old error failed.' );
		rcs_ac_assert( false === Remote_Content_Shortcode_Cache::get( 'stale-negative', true ), 'Replacing an error extended its stale eligibility.' );
	} finally { delete_transient( $id ); }
};
$tests['expired-lock recovery cannot steal a replacement owner lock'] = static function () {
	global $wpdb;
	$old = ( time() - 1 ) . ':expired-boundary-owner';
	$new = ( time() + 60 ) . ':replacement-boundary-owner';
	add_option( Remote_Content_Shortcode_Cache::LOCK, $old, '', false );
	$replaced = false;
	$replace = static function ( $sql ) use ( &$replaced, $wpdb, $old, $new ) {
		if ( ! $replaced && str_starts_with( $sql, 'DELETE FROM' ) && str_contains( $sql, $old ) ) {
			$replaced = true;
			$wpdb->update( $wpdb->options, array( 'option_value' => $new ), array( 'option_name' => Remote_Content_Shortcode_Cache::LOCK ) );
		}
		return $sql;
	};
	add_filter( 'query', $replace );
	try {
		rcs_ac_assert( false === Remote_Content_Shortcode_Cache::set( 'replacement-lock', array( 'body' => 'blocked', 'url' => 'https://example.com/' ), 60 ), 'Expired-lock recovery stole the replacement lock.' );
		rcs_ac_assert( $replaced && $new === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Remote_Content_Shortcode_Cache::LOCK ) ), 'Replacement owner token was removed or changed.' );
	} finally { remove_filter( 'query', $replace ); delete_option( Remote_Content_Shortcode_Cache::LOCK ); }
};
$tests['object cache API stores reads and removes plugin entries'] = static function () {
	$previous = wp_using_ext_object_cache();
	Remote_Content_Shortcode_Cache::clear();
	wp_using_ext_object_cache( true );
	try {
		$value = array( 'body' => 'backend contract', 'url' => 'https://example.com/' );
		rcs_ac_assert( Remote_Content_Shortcode_Cache::set( 'object-cache-contract', $value, 60, 0, 'object-tag' ), 'Object cache write failed.' );
		rcs_ac_assert( $value === Remote_Content_Shortcode_Cache::get( 'object-cache-contract' ), 'Object cache read failed.' );
		rcs_ac_assert( 0 === Remote_Content_Shortcode_Cache::clear( 'unmatched-tag' ), 'Nonmatching tag removed an object cache entry.' );
		rcs_ac_assert( 1 === Remote_Content_Shortcode_Cache::delete( 'object-cache-contract' ) && false === Remote_Content_Shortcode_Cache::get( 'object-cache-contract' ), 'Object cache removal failed.' );
	} finally { Remote_Content_Shortcode_Cache::clear(); wp_using_ext_object_cache( $previous ); }
};

$old_user = get_current_user_id();
$failures = 0;
try {
	foreach ( $tests as $name => $test ) {
		wp_set_current_user( 1 );
		Remote_Content_Shortcode_Cache::clear();
		try { $test(); echo "PASS $name\n"; } catch ( Throwable $error ) { ++$failures; echo "FAIL $name: " . $error->getMessage() . "\n"; }
	}
} finally { Remote_Content_Shortcode_Cache::clear(); wp_set_current_user( $old_user ); }
if ( $failures ) { WP_CLI::error( "$failures admin/cache boundary failures" ); }
echo count( $tests ) . " admin/cache boundary cases passed\n";
