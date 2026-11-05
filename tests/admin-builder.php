<?php
/** Builder disclosure and form-retention regression checks with WordPress loaded. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function rcs_builder_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function rcs_builder_page( $input = array(), $tab = 'builder' ) {
	$saved = array( $_GET, $_POST, $_REQUEST, $_SERVER['REQUEST_METHOD'] ?? null );
	$level = ob_get_level();
	try {
		$_GET = array( 'tab' => $tab ); $_POST = wp_slash( $input ); $_REQUEST = array_merge( $_GET, $_POST ); $_SERVER['REQUEST_METHOD'] = $input ? 'POST' : 'GET';
		ob_start(); Remote_Content_Shortcode_Admin::page(); $html = ob_get_clean();
		$document = new DOMDocument(); $errors = libxml_use_internal_errors( true );
		try { $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html ); }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $errors ); }
		return new DOMXPath( $document );
	} finally {
		while ( ob_get_level() > $level ) { ob_end_clean(); }
		list( $_GET, $_POST, $_REQUEST, $_SERVER['REQUEST_METHOD'] ) = $saved;
	}
}
$user = get_current_user_id(); wp_set_current_user( 1 );
try {
	$page = rcs_builder_page();
	rcs_builder_assert( 3 === $page->query( '//details[.//input[@name]]' )->length, 'Optional builder settings need three discoverable disclosure sections.' );
	rcs_builder_assert( 0 === $page->query( '//details[@open][.//input[@name]]' )->length, 'Default optional settings should start collapsed.' );
	rcs_builder_assert( 0 === $page->query( '//details//input[@name="url" or @name="selector"]' )->length, 'Source URL and CSS selector must remain directly available.' );
	foreach ( array( 'generate', 'test' ) as $action ) {
		rcs_builder_assert( 3 === $page->query( '//button[@value="' . $action . '"]/following::details[.//input[@name]]' )->length, 'The basic actions should precede optional settings.' );
	}
	rcs_builder_assert( 1 === $page->query( '//details[.//input[@name="cache_ttl"]]//button[@value="clear"]' )->length, 'Cache clearing belongs with the cache controls.' );
	foreach ( array( 'url', 'selector', 'remove', 'userpwd', 'fallback', 'timeout', 'method', 'find', 'replace', 'params', 'htmlentities', 'strip_tags', 'cache', 'cache_ttl', 'stale_ttl', 'rewrite_urls', 'body' ) as $name ) {
		rcs_builder_assert( 1 === $page->query( '//*[@name="' . $name . '"]' )->length, 'A builder field was lost or duplicated: ' . $name );
	}
	echo "PASS essential fields and actions precede discoverable optional controls\n";
	$input = array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => 'generate', 'url' => 'https://example.com/articles', 'selector' => 'main', 'method' => 'POST', 'body' => 'q=hello&limit=5', 'cache_ttl' => '300', 'fallback' => '<b>Offline</b>' );
	foreach ( array( false, true ) as $invalid ) {
		if ( $invalid ) { $input['body'] = '[remote_content url="https://example.com"]'; }
		$page = rcs_builder_page( $input );
		foreach ( array( 'method', 'body', 'cache_ttl', 'fallback' ) as $name ) {
			$control = $page->query( '//*[@name="' . $name . '"]' )->item( 0 );
			rcs_builder_assert( 1 === $page->query( 'ancestor::details[@open]', $control )->length, 'A changed setting must stay visible after submission: ' . $name );
			$actual = 'textarea' === $control->tagName ? $control->textContent : ( 'select' === $control->tagName ? $page->evaluate( 'string(option[@selected]/@value)', $control ) : $control->getAttribute( 'value' ) );
			rcs_builder_assert( $input[ $name ] === $actual, 'A changed setting was not retained: ' . $name );
		}
		if ( ! $invalid ) {
			$shortcode = $page->evaluate( 'string(//textarea[@aria-label="Generated shortcode"])' );
			rcs_builder_assert( str_contains( $shortcode, 'cache_ttl="&quot;300&quot;"' ) && str_ends_with( $shortcode, 'q=hello&limit=5[/remote_content]' ), 'The reorganized form changed shortcode generation.' );
		}
	}
	echo "PASS changed optional values stay visible after generation and validation errors\n";
	$url_state = static function ( $page ) {
		$field = $page->query( '//input[@id="rcs-url"][@name="url"]' )->item( 0 );
		rcs_builder_assert( null !== $field, 'Source URL needs a stable field id.' );
		return array( $field, preg_split( '/\s+/', trim( $field->getAttribute( 'aria-describedby' ) ), -1, PREG_SPLIT_NO_EMPTY ) );
	};
	foreach ( array( rcs_builder_page(), rcs_builder_page( array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => 'generate', 'url' => 'https://example.com/articles' ) ) ) as $page ) {
		list( $field, $described ) = $url_state( $page );
		rcs_builder_assert( ! $field->hasAttribute( 'aria-invalid' ) && array( 'rcs-help-url' ) === $described && 0 === $page->query( '//*[@id="rcs-error-url"]' )->length && 0 === $page->query( '//a[@href="#rcs-url"]' )->length, 'Initial and valid Source URL states must not carry stale error markup.' );
	}
	foreach ( array( 'generate', 'test' ) as $action ) {
		$input = array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => $action, 'url' => 'example dot com/news', 'selector' => 'h1', 'cache_ttl' => '300', 'fallback' => '<b>Offline</b>' );
		$page = rcs_builder_page( $input );
		list( $field, $described ) = $url_state( $page );
		rcs_builder_assert( 1 === $page->query( '//*[contains(concat(" ", normalize-space(@class), " "), " notice ")]' )->length, 'Invalid Source URL must produce exactly one notice after ' . $action );
		rcs_builder_assert( 'true' === $field->getAttribute( 'aria-invalid' ), 'Invalid Source URL must be marked aria-invalid after ' . $action );
		rcs_builder_assert( in_array( 'rcs-error-url', $described, true ) && in_array( 'rcs-help-url', $described, true ), 'Invalid Source URL must reference its inline error and helper after ' . $action );
		$error = $page->query( '//*[@id="rcs-error-url"]' )->item( 0 );
		rcs_builder_assert( null !== $error && ! $error->hasAttribute( 'hidden' ) && 1 === $page->query( 'ancestor::td', $error )->length && str_contains( $error->textContent, 'https://' ), 'The inline Source URL error must be visible beside the field with HTTP(S) guidance after ' . $action );
		$link = $page->query( '//div[contains(@class,"notice-error")]//a[@href="#rcs-url"]' )->item( 0 );
		rcs_builder_assert( null !== $link && str_contains( $link->textContent, 'Source URL' ), 'The failure notice must link to the Source URL field after ' . $action );
		rcs_builder_assert( 'example dot com/news' === $field->getAttribute( 'value' ) && 'h1' === $page->evaluate( 'string(//input[@name="selector"]/@value)' ), 'Entered URL and selector must be kept after ' . $action );
		foreach ( array( 'cache_ttl', 'fallback' ) as $name ) {
			$control = $page->query( '//*[@name="' . $name . '"]' )->item( 0 );
			rcs_builder_assert( 1 === $page->query( 'ancestor::details[@open]', $control )->length && $input[ $name ] === $control->getAttribute( 'value' ), 'Changed advanced settings must stay open and filled after ' . $action . ': ' . $name );
		}
		if ( 'test' === $action ) {
			rcs_builder_assert( 'invalid_url' === $page->evaluate( 'string(//dt[.="Error code"]/following-sibling::dd[1])' ), 'Diagnostics must keep the error code label.' );
		}
	}
	echo "PASS malformed Source URL errors are linked to the field with recovery guidance\n";
	$page = rcs_builder_page( array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => 'test', 'url' => 'http://127.0.0.1/private', 'selector' => 'h1' ) );
	$guidance = $page->evaluate( 'string(//*[@id="rcs-error-url"])' );
	rcs_builder_assert( str_contains( strtolower( $guidance ), 'public' ) && str_contains( strtolower( $guidance ), 'private' ), 'A blocked private URL needs public-address guidance, not just a format correction.' );
	echo "PASS URL recovery guidance also explains blocked private addresses\n";
	$words = static function ( $text ) { return trim( preg_replace( '/\s+/', ' ', $text ) ); };
	$page = rcs_builder_page();
	$lead = '';
	foreach ( $page->query( '//p[contains(@class,"rcs-form-actions")][.//button[@value="test"]]/following-sibling::*[not(self::details)][following-sibling::details]' ) as $node ) { $lead .= ' ' . $node->textContent; }
	rcs_builder_assert( strlen( $words( $lead ) ) <= 60, 'Supporting copy between the actions and optional settings must stay a short reminder, found: ' . $words( $lead ) );
	$request = $words( $page->evaluate( 'string(//details[.//*[@name="timeout"]])' ) );
	foreach ( array( 'Test requests fetch fresh content and bypass visitor caching. Cache settings belong in the shortcode or block.', '30 seconds', 'five seconds' ) as $needle ) {
		rcs_builder_assert( str_contains( $request, $needle ), 'Request settings must keep the full test, cache and timeout explanation: ' . $needle );
	}
	foreach ( array( 'generate', 'test' ) as $action ) {
		$page = rcs_builder_page( array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => $action, 'url' => 'example dot com/news', 'selector' => 'h1' ) );
		$notice = $page->query( '//div[contains(@class,"rcs-url-notice")]' )->item( 0 );
		rcs_builder_assert( null !== $notice, 'The URL failure notice is missing after ' . $action );
		$summary = '';
		foreach ( $page->query( './/text()[not(ancestor::details)]', $notice ) as $text ) { $summary .= $text->nodeValue; }
		rcs_builder_assert( strlen( $words( $summary ) ) <= 60 && str_contains( $summary, 'Correct the Source URL' ) && ! str_contains( $summary, 'invalid_url' ), 'The URL failure summary must be a short recovery action with diagnostics tucked away after ' . $action . ', found: ' . $words( $summary ) );
		rcs_builder_assert( 1 === $page->query( './/details[summary]//code[.="invalid_url"]', $notice )->length, 'The invalid_url diagnostic code must stay available in a native disclosure after ' . $action );
		$inline = $words( $page->evaluate( 'string(//*[@id="rcs-error-url"])' ) );
		rcs_builder_assert( strlen( $inline ) <= 115 && str_contains( $inline, 'https://' ) && str_contains( $inline, 'public' ) && str_contains( $inline, 'private' ) && str_contains( $inline, 'password' ), 'The inline URL error must be concise yet keep the public HTTP(S), private-address and embedded-login rules, found: ' . $inline );
	}
	echo "PASS supporting copy stays short while full guidance and diagnostics remain available\n";
	$page = rcs_builder_page();
	$switcher = $page->query( '//form[contains(@class,"rcs-section-switcher")]' )->item( 0 );
	rcs_builder_assert( null !== $switcher, 'Narrow screens need a compact section navigation form.' );
	rcs_builder_assert( 'get' === $switcher->getAttribute( 'method' ) && 1 === $page->query( './/input[@name="page"][@value="remote-content-shortcode"]', $switcher )->length && str_ends_with( $switcher->getAttribute( 'action' ), '/tools.php' ), 'Section navigation must use the existing read-only admin route.' );
	rcs_builder_assert( 1 === $page->query( './/label[@for="rcs-section"]', $switcher )->length && 1 === $page->query( './/select[@id="rcs-section"][@name="tab"][not(@onchange)]', $switcher )->length && 1 === $page->query( './/button[@type="submit"]', $switcher )->length, 'The section selector needs a label and explicit navigation button.' );
	$links = array(); $options = array();
	foreach ( $page->query( '//nav[contains(@class,"rcs-tabs")]/a' ) as $link ) { parse_str( wp_parse_url( $link->getAttribute( 'href' ), PHP_URL_QUERY ), $query ); $links[ $query['tab'] ] = $link->textContent; }
	foreach ( $page->query( './/select/option', $switcher ) as $option ) { $options[ $option->getAttribute( 'value' ) ] = $option->textContent; }
	rcs_builder_assert( $links && $links === $options, 'Compact navigation must retain every section label and destination in order.' );
	foreach ( array( 'builder', 'connections', 'cache', 'usage', 'help', 'unknown' ) as $tab ) {
		$page = rcs_builder_page( array(), $tab ); $expected = 'unknown' === $tab ? 'builder' : $tab;
		rcs_builder_assert( $expected === $page->evaluate( 'string(//select[@name="tab"]/option[@selected]/@value)' ), 'Compact navigation must identify the current section.' );
		rcs_builder_assert( 1 === $page->query( '//nav[contains(@class,"rcs-tabs")]/a[@aria-current="page"]' )->length, 'Desktop navigation must identify one current page.' );
	}
	echo "PASS compact navigation preserves every destination and explicit keyboard-friendly submission\n";
	$page = rcs_builder_page();
	$actions = $page->query( '//form[contains(@class,"rcs-builder")]//p[contains(@class,"rcs-form-actions")][.//button[@value="test"]]/button' );
	rcs_builder_assert( 2 === $actions->length && 'test' === $actions->item( 0 )->getAttribute( 'value' ) && str_contains( $actions->item( 0 )->getAttribute( 'class' ), 'button-primary' ) && 'generate' === $actions->item( 1 )->getAttribute( 'value' ), 'The primary actions must follow the taught Test then Generate sequence.' );
	$input = array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => 'generate', 'url' => 'https://example.com/articles?language=en', 'selector' => '[title="example"]', 'method' => 'POST', 'body' => 'q=hello&limit=5' );
	$expected = Remote_Content_Shortcode_Admin::process_action( $input ); $page = rcs_builder_page( $input );
	$field = $page->query( '//textarea[@aria-label="Generated shortcode"][@readonly]' )->item( 0 );
	$copy = $page->query( '//button[@data-rcs-copy][@type="button"]' )->item( 0 );
	rcs_builder_assert( null !== $field && $expected === $field->textContent && '' !== $field->getAttribute( 'id' ), 'Copying must use the exact generated shortcode, including encoded quotes and POST body.' );
	rcs_builder_assert( null !== $copy && $field->getAttribute( 'id' ) === $copy->getAttribute( 'aria-controls' ), 'The Copy shortcode action must target the generated readonly field.' );
	$status = $page->query( '//*[@id="' . $copy->getAttribute( 'data-status' ) . '"][@role="status"][@aria-live="polite"]' )->item( 0 );
	rcs_builder_assert( null !== $status && '' === trim( $status->textContent ), 'Copy feedback must have a live status region without claiming success before copying.' );
	rcs_builder_assert( wp_script_is( 'remote-content-shortcode-admin', 'enqueued' ) && str_ends_with( wp_scripts()->registered['remote-content-shortcode-admin']->src, '/inc/admin.js' ), 'The generated result must enqueue the packaged copy behavior.' );
	echo "PASS Test-to-Generate order and exact copy target with accessible feedback\n";
	$page = rcs_builder_page();
	rcs_builder_assert( 1 === $page->query( '//*[@class="rcs-workspace"][@aria-busy="false"]' )->length && 1 === $page->query( '//*[@id="rcs-preview-panel"]' )->length && 1 === $page->query( '//*[@id="rcs-shortcode-panel"]' )->length, 'The Builder must offer a stable configuration, preview and shortcode workspace before any submission.' );
	$input = array( '_wpnonce' => wp_create_nonce( 'rcs_tools' ), 'rcs_action' => 'test', 'url' => 'http://127.0.0.1/private', 'selector' => 'h1', 'cache_ttl' => '300', 'fallback' => '<h1>Offline fallback</h1>' );
	$page = rcs_builder_page( $input );
	$input['rcs_action'] = 'generate'; $expected = Remote_Content_Shortcode_Admin::process_action( $input );
	rcs_builder_assert( $expected === $page->evaluate( 'string(//*[@id="rcs-shortcode-panel"]//textarea[@aria-label="Generated shortcode"])' ), 'Test must also generate the exact shortcode for the same settings, without requiring another request.' );
	rcs_builder_assert( 1 === $page->query( '//*[@id="rcs-preview-content"]//iframe[@sandbox=""]' )->length && 1 === $page->query( '//*[@id="rcs-preview-content"]//details[.//dt[.="Error code"]]' )->length, 'Test must retain its sandboxed preview and available diagnostics beside the shortcode.' );
	rcs_builder_assert( 'error' === $page->evaluate( 'string(//*[@id="rcs-preview-panel"]/@data-state)' ), 'A fallback preview must not claim a successful remote import.' );
	echo "PASS persistent workspace keeps preview, diagnostics and exact shortcode together\n";
} finally { wp_set_current_user( $user ); }
