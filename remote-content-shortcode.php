<?php
/**
 * Plugin Name: Remote Content Shortcode
 * Plugin URI: https://www.doublesharp.com/
 * Description: Embed remote content with a shortcode or block, CSS selectors, persistent caching, and safe HTML output.
 * Author: Justin Silver
 * Version: 2.0
 * Author URI: https://doublesharp.com/
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: remote-content-shortcode
 *
 * @package RemoteContentShortcode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.2', '<' ) || ! extension_loaded( 'dom' ) || ! extension_loaded( 'curl' ) || ! defined( 'CURLOPT_CONNECT_TO' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Remote Content Shortcode requires PHP 8.2 or later, DOM, and cURL 7.49 or later.', 'remote-content-shortcode' ) . '</p></div>';
	} );
	return;
}

if ( ! class_exists( 'RemoteContentShortcode' ) ) {
	require_once __DIR__ . '/inc/class-remote-content-cache.php';
	require_once __DIR__ . '/inc/class-remote-content-secrets.php';
	require_once __DIR__ . '/inc/class-remote-content-connections.php';
	require_once __DIR__ . '/inc/class-remote-content-credentials.php';
	require_once __DIR__ . '/inc/class-remote-content-admin.php';
	require_once __DIR__ . '/inc/class-remote-content-block.php';
	/** Fetch public remote content and render only allowed post HTML. */
	class RemoteContentShortcode {
		private static $instance;
		private const MAX_BYTES = 1048576;
		private $last_result = array();

		private function __construct() {}

		public static function init() {
			if ( ! self::$instance ) {
				self::$instance = new self();
				self::$instance->add_shortcode();
				self::$instance->add_filters();
				Remote_Content_Shortcode_Connections::register();
				Remote_Content_Shortcode_Admin::register();
				Remote_Content_Shortcode_Block::register();
			}
		}

		public static function get_instance() {
			self::init();
			return self::$instance;
		}

		/** Capability checks also protect calls outside the administrator screen. */
		public function clear_cache( $url = '' ) {
			if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden' ); }
			if ( ! is_string( $url ) ) { return new WP_Error( 'invalid_url' ); }
			$canonical = '' === $url ? '' : $this->canonical_url( $url );
			if ( '' !== $url && '' === $canonical ) { return new WP_Error( 'invalid_url' ); }
			$result = Remote_Content_Shortcode_Cache::clear( '' === $canonical ? null : hash( 'sha256', $canonical ) );
			return false === $result ? new WP_Error( 'cache_busy' ) : $result;
		}

		/** Return bounded, redacted diagnostics, never raw request errors or URLs. */
		public function diagnose( $atts, $content = null ) {
			if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden' ); }
			if ( ! is_array( $atts ) ) { return new WP_Error( 'invalid_attributes' ); }
			$atts['timeout'] = max( 1, min( 5, is_scalar( $atts['timeout'] ?? 5 ) ? (int) ( $atts['timeout'] ?? 5 ) : 5 ) );
			$html = $this->remote_content_shortcode( $atts, $content );
			return $this->last_result + array( 'html' => $html );
		}

		public function add_shortcode() {
			add_shortcode( 'remote_content', array( $this, 'remote_content_shortcode' ) );
		}

		public function add_filters() {
			add_filter( 'syntaxhighlighter_htmlresult', array( $this, 'process_shortcodes_in_syntaxhighlighter' ) );
		}

		public function process_shortcodes_in_syntaxhighlighter( $content ) {
			$content = str_replace( '&#x5B;', '[', $content );
			// Limit this integration to our shortcode, rather than executing every registered shortcode.
			$pattern = '/' . get_shortcode_regex( array( 'remote_content' ) ) . '/s';
			return wp_kses_post( preg_replace_callback( $pattern, 'do_shortcode_tag', $content ) );
		}

		public function remote_content_shortcode( $atts, $content = null ) {
			$start = microtime( true );
			$this->last_result = array( 'ok' => false, 'error_code' => '', 'http_status' => null, 'cache_status' => 'disabled', 'selector_matches' => null, 'duration_ms' => 0 );
			$fallback = is_array( $atts ) && is_string( $atts['fallback'] ?? null ) ? $atts['fallback'] : '';
			$atts = $this->normalize_attributes( $atts );
			if ( is_array( $atts ) ) { $fallback = is_string( $atts['fallback'] ?? null ) ? $atts['fallback'] : ''; }
			if ( strlen( $fallback ) > 4096 ) { return $this->fail( 'invalid_fallback' ); }
			$output = $this->render_shortcode( $atts, $content );
			if ( '' === $output && '' !== $fallback ) { $output = wp_kses_post( $fallback ); }
			$this->last_result['ok'] = '' === $this->last_result['error_code'];
			$this->last_result['duration_ms'] = round( ( microtime( true ) - $start ) * 1000, 2 );
			return $output;
		}

		private function normalize_attributes( $atts ) {
			$atts = is_array( $atts ) ? $atts : array();
			foreach ( $atts as $value ) {
				if ( ! is_scalar( $value ) ) {
					return new WP_Error( 'invalid_attributes' );
				}
			}
			if ( isset( $atts['decode_atts'] ) && 'true' === strtolower( trim( html_entity_decode( (string) $atts['decode_atts'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), '"' ) ) ) {
				foreach ( $atts as &$value ) {
					$value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					if ( strlen( $value ) >= 2 && '"' === $value[0] && '"' === substr( $value, -1 ) ) {
						$value = substr( $value, 1, -1 );
					}
				}
				unset( $value );
			}
			return $atts;
		}


		/** Shared public transport for add-ons: all destinations and credentials use the core policy. */
		public function fetch_source( $attributes, $content = null, $content_filters = true ) {
			$atts = $this->normalize_attributes( $attributes );
			if ( is_wp_error( $atts ) ) { return $atts; }
			$atts = $this->attributes( $atts );
			$method = strtoupper( (string) $atts['method'] );
			if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) { return new WP_Error( 'invalid_method' ); }
			$url = $content_filters ? $this->forward_params( str_replace( '&amp;', '&', (string) $atts['url'] ), (string) $atts['params'] ) : (string) $atts['url'];
			if ( is_wp_error( $url ) ) { return $url; }
			$url = $this->canonical_url( $content_filters ? apply_filters( 'remote_content_shortcode_url', $url ) : $url );
			if ( '' === $url ) { return new WP_Error( 'invalid_url' ); }
			$content = null === $content && isset( $atts['body'] ) ? $atts['body'] : $content;
			$body = $content_filters ? apply_filters( 'remote_content_shortcode_postfields', $content, $url ) : $content;
			if ( null !== $body && ! is_string( $body ) ) { return new WP_Error( 'invalid_post_body' ); }
			if ( strlen( (string) $body ) > self::MAX_BYTES ) { return new WP_Error( 'post_body_too_large' ); }
			$args = array( 'method' => $method, 'timeout' => max( 1, min( 30, (int) $atts['timeout'] ) ), 'sslverify' => true, 'redirection' => 0, 'limit_response_size' => self::MAX_BYTES, 'cookies' => array() );
			if ( 'POST' === $method ) { $args['body'] = (string) $body; }
			if ( '' !== (string) $atts['userpwd'] ) {
				$headers = $this->credential( (string) $atts['userpwd'], $url, $method );
				if ( is_wp_error( $headers ) ) { return $headers; }
				$args['headers'] = $headers;
			}
			return $this->request( $url, $args );
		}

		/** Render a previously verified response through the same bounded output pipeline. */
		public function render_fetched( $response, $attributes ) {
			$this->last_result = array( 'ok' => false, 'error_code' => '', 'http_status' => null, 'cache_status' => 'snapshot', 'selector_matches' => null, 'duration_ms' => 0 );
			$atts = $this->normalize_attributes( $attributes );
			if ( is_wp_error( $atts ) ) { return $this->fail( $atts ); }
			$atts = $this->attributes( $atts );
			if ( ! is_array( $response ) || ! is_string( $response['body'] ?? null ) || strlen( $response['body'] ) >= self::MAX_BYTES || ! is_string( $response['url'] ?? null ) ) { return $this->fail( 'invalid_output' ); }
			$url = $this->canonical_url( $response['url'] );
			if ( '' === $url ) { return $this->fail( 'invalid_url_base' ); }
			$response['url'] = $url;
			return $this->transform_response( $response, $atts, $url );
		}

		private function attributes( $atts ) {
			$atts = shortcode_atts( array(
				'userpwd' => '', 'method' => 'GET', 'timeout' => 10, 'url' => '',
				'selector' => '', 'remove' => '', 'find' => '', 'replace' => '',
				'htmlentities' => 'false', 'params' => '', 'strip_tags' => 'false',
				'body' => '', 'cache' => 'true', 'cache_ttl' => 3600, 'stale_ttl' => 0,
				'rewrite_urls' => 'false', 'fallback' => '',
			), $atts );
			return $atts;
		}

		private function render_shortcode( $atts, $content ) {
			if ( is_wp_error( $atts ) ) { return $this->fail( $atts ); }
			$atts = $this->attributes( $atts );
			$method = strtoupper( (string) $atts['method'] );
			if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
				return $this->fail( 'invalid_method' );
			}
			$url = str_replace( '&amp;', '&', (string) $atts['url'] );
			$url = $this->forward_params( $url, (string) $atts['params'] );
			if ( is_wp_error( $url ) ) {
				return $this->fail( $url );
			}
			$url = apply_filters( 'remote_content_shortcode_url', $url );
			$url = $this->canonical_url( $url );
			if ( '' === $url ) {
				return $this->fail( 'invalid_url' );
			}
			$body = apply_filters( 'remote_content_shortcode_postfields', $content, $url );
			if ( null !== $body && ! is_string( $body ) ) {
				return $this->fail( 'invalid_post_body', $url );
			}
			if ( strlen( (string) $body ) > self::MAX_BYTES ) {
				return $this->fail( 'post_body_too_large', $url );
			}
			$args = array(
				'method' => $method,
				'timeout' => max( 1, min( 30, (int) $atts['timeout'] ) ),
				'sslverify' => true,
				'redirection' => 0,
				'limit_response_size' => self::MAX_BYTES,
				'cookies' => array(),
			);
			if ( 'POST' === $method ) {
				$args['body'] = (string) $body;
			}
			if ( '' !== (string) $atts['userpwd'] ) {
				$credential = $this->credential( (string) $atts['userpwd'], $url, $method );
				if ( is_wp_error( $credential ) ) {
					return $this->fail( $credential, $url );
				}
				$args['headers'] = $credential;
			}
			// Cache raw public GET responses only. All transformations and output filters run on every render.
			// Editor previews and signed-in traffic cannot evict the public visitor cache.
			$cache = 'false' !== strtolower( (string) $atts['cache'] ) && 'GET' === $method && empty( $args['headers'] ) && 0 === get_current_user_id();
			$ttl = max( 1, min( DAY_IN_SECONDS, (int) $atts['cache_ttl'] ) );
			$stale_ttl = max( 0, min( DAY_IN_SECONDS, (int) $atts['stale_ttl'] ) );
			$key = 'v3_' . hash( 'sha256', wp_json_encode( array( $url, $args, get_current_blog_id(), get_current_user_id(), $ttl, $stale_ttl ) ) );
			$response = $cache ? Remote_Content_Shortcode_Cache::get( $key ) : false;
			$this->last_result['cache_status'] = $cache ? ( false === $response ? 'miss' : 'hit' ) : 'disabled';
			if ( false === $response ) {
				$response = $this->request( $url, $args );
				if ( $cache ) {
					Remote_Content_Shortcode_Cache::set( $key, $response, is_wp_error( $response ) ? min( 60, $ttl ) : $ttl, $stale_ttl, hash( 'sha256', $url ) );
				}
			}
			if ( is_wp_error( $response ) ) {
				$this->fail( $response, $url );
				$stale = $cache && $stale_ttl > 0 ? Remote_Content_Shortcode_Cache::get( $key, true ) : false;
				if ( false === $stale ) { return ''; }
				$response = $stale;
				$this->last_result['cache_status'] = 'stale';
			}
			return $this->transform_response( $response, $atts, $url );
		}

		private function transform_response( $response, $atts, $url ) {
			$effective_url = $response['url'];
			if ( 'stale' !== $this->last_result['cache_status'] ) { $this->last_result['http_status'] = $response['status'] ?? 200; }
			$response = $response['body'];
			if ( $atts['selector'] || $atts['remove'] ) {
				$response = apply_filters( 'remote_content_shortcode_phpQuery', $response, $url, $atts['selector'], $atts['remove'] );
				if ( ! is_string( $response ) || strlen( $response ) > self::MAX_BYTES || strlen( (string) $atts['selector'] ) > 2048 || strlen( (string) $atts['remove'] ) > 2048 ) {
					return $this->fail( 'invalid_html_input', $url );
				}
				require_once __DIR__ . '/inc/class-remote-content-html.php';
				$selection = array();
				$response = Remote_Content_Shortcode_HTML::transform( $response, (string) $atts['selector'], (string) $atts['remove'], $selection );
				$this->last_result['selector_matches'] = $selection['matches'] ?? null;
				if ( is_wp_error( $response ) ) {
					return $this->fail( $response, $url );
				}
				if ( '' !== (string) $atts['selector'] && 0 === ( $selection['matches'] ?? null ) ) { return $this->fail( 'selector_no_matches', $url ); }
			}
			if ( '' !== (string) $atts['find'] ) {
				$find = str_replace( '%QUOT%', '"', (string) $atts['find'] );
				$replace = str_replace( '%QUOT%', '"', (string) $atts['replace'] );
				if ( strlen( $find ) > 1024 || strlen( $replace ) > 4096 ) {
					return $this->fail( 'invalid_regex', $url );
				}
				require_once __DIR__ . '/inc/class-remote-content-regex.php';
				$response = Remote_Content_Shortcode_Regex::replace( $find, $replace, $response );
				if ( is_wp_error( $response ) ) {
					return $this->fail( $response, $url );
				}
			}
			if ( 'true' === strtolower( (string) $atts['rewrite_urls'] ) ) {
				require_once __DIR__ . '/inc/class-remote-content-urls.php';
				$response = Remote_Content_Shortcode_URLs::rewrite( $response, $effective_url );
				if ( is_wp_error( $response ) ) { return $this->fail( $response, $url ); }
			}
			if ( 'true' === strtolower( (string) $atts['strip_tags'] ) ) {
				$response = wp_strip_all_tags( $response );
			}
			if ( 'true' === strtolower( (string) $atts['htmlentities'] ) ) {
				$response = htmlentities( $response, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
			}
			$response = apply_filters( 'remote_content_shortcode_return', $response, $url );
			// This must remain the final operation, including after filters and replacements.
			return is_string( $response ) ? wp_kses_post( $response ) : $this->fail( 'invalid_output', $url );
		}

		private function forward_params( $url, $params ) {
			$query = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', $params ) ), 'strlen' ) as $param ) {
				$parts = explode( '=', $param, 2 );
				$key = trim( $parts[0] );
				if ( '' === $key ) {
					return new WP_Error( 'invalid_query_parameter' );
				}
				// Only explicitly named query-string values are forwarded. Cookies and POST data are excluded.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only forwarding of public query parameters.
				if ( isset( $_GET[ $key ] ) ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are validated as strings, then URL-encoded by add_query_arg.
					$value = wp_unslash( $_GET[ $key ] );
					if ( ! is_string( $value ) ) {
						return new WP_Error( 'invalid_query_parameter' );
					}
					$query[ rawurlencode( $key ) ] = rawurlencode( $value );
				} elseif ( isset( $parts[1] ) ) {
					$query[ rawurlencode( $key ) ] = rawurlencode( trim( $parts[1] ) );
				}
			}
			return $query ? add_query_arg( $query, $url ) : $url;
		}

		private function canonical_url( $url ) {
			if ( ! is_string( $url ) || strlen( $url ) > 8192 ) {
				return '';
			}
			$parts = wp_parse_url( $url );
			if ( ! $parts || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return '';
			}
			// Match the normalization Requests performs before its transport hooks run.
			try {
				$iri = new WpOrg\Requests\Iri( $url );
				$iri->host = WpOrg\Requests\IdnaEncoder::encode( $iri->ihost );
				return (string) $iri->uri;
			} catch ( Throwable $error ) {
				return '';
			}
		}

		private function public_address( $url ) {
			// The caller supplies canonical_url()'s string; encoding can still expand its length.
			if ( strlen( $url ) > 8192 || ! wp_http_validate_url( $url ) ) {
				return false;
			}
			$parts = wp_parse_url( $url );
			if ( ! $parts || isset( $parts['user'] ) || isset( $parts['pass'] ) || empty( $parts['host'] ) ) {
				return false;
			}
			$host = trim( $parts['host'], '[]' );
			if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
				return filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE );
			}
			// WordPress allows some same-site/private-host exceptions. This shortcode does not.
			$addresses = gethostbynamel( $host );
			if ( false === $addresses || ! $addresses ) {
				return false;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failures fail closed below.
			$ipv6 = @dns_get_record( $host, DNS_AAAA );
			if ( false === $ipv6 ) {
				return false;
			}
			foreach ( $ipv6 ?: array() as $record ) {
				if ( isset( $record['ipv6'] ) ) {
					$addresses[] = $record['ipv6'];
				}
			}
			foreach ( $addresses as $address ) {
				if ( ! filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE ) ) {
					return false;
				}
			}
			return $addresses[0];
		}

		private function request( $url, $args ) {
			$deadline = microtime( true ) + $args['timeout'];
			// The redirect guard below returns on the fourth attempt, so this loop cannot fall through.
			for ( $redirects = 0; ; ++$redirects ) {
				$url = $this->canonical_url( $url );
				$address = $this->public_address( $url );
				if ( ! $address ) {
					return new WP_Error( 'invalid_url' );
				}
				$remaining = $deadline - microtime( true );
				if ( $remaining <= 0 ) {
					return new WP_Error( 'request_timeout' );
				}
				$args['timeout'] = min( $args['timeout'], $remaining );
				$response = $this->pinned_request( $url, $args, $address );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$status = wp_remote_retrieve_response_code( $response );
				$this->last_result['http_status'] = $status;
				if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
					if ( 3 === $redirects || 'GET' !== $args['method'] || ! empty( $args['headers'] ) ) {
						return new WP_Error( 'redirect_not_allowed' );
					}
					$location = wp_remote_retrieve_header( $response, 'location' );
					if ( ! is_string( $location ) || '' === $location ) {
						return new WP_Error( 'invalid_redirect' );
					}
					$url = WP_Http::make_absolute_url( $location, $url );
					continue;
				}
				if ( $status < 200 || $status >= 300 ) {
					return new WP_Error( 'http_status_error' );
				}
				$body = wp_remote_retrieve_body( $response );
				// Reject potentially truncated responses rather than displaying incomplete content.
				if ( strlen( $body ) >= self::MAX_BYTES ) {
					return new WP_Error( 'response_too_large' );
				}
				return array( 'body' => $body, 'url' => $url, 'status' => $status );
			}
		}

		private function pinned_request( $url, $args, $address ) {
			$proxy = new WP_HTTP_Proxy();
			if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
				return new WP_Error( 'proxy_not_supported' );
			}
			$host = trim( wp_parse_url( $url, PHP_URL_HOST ), '[]' );
			$port = wp_parse_url( $url, PHP_URL_PORT ) ?: ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? 443 : 80 );
			$prepared = false;
			$pinned = false;
			$preempted = false;
			$expected_handle = null;
			$capture = static function ( $handle ) use ( &$expected_handle ) { $expected_handle = $handle; };
			$pin = static function ( $handle ) use ( $url, $host, $port, $address, &$expected_handle, &$pinned ) {
				if ( $handle !== $expected_handle ) {
					return;
				}
				// Use the checked address while retaining the original Host header, SNI, and certificate name.
				// WordPress rejects IPv6 literals; hostname resolution selects an IPv4 address.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- Configure WordPress's own cURL transport for DNS pinning.
				$configured = curl_setopt_array( $handle, array(
					CURLOPT_URL => $url,
					CURLOPT_RESOLVE => array( $host . ':' . $port . ':' . $address ),
					CURLOPT_CONNECT_TO => array( '::' . $address . ':' . $port ),
					CURLOPT_PROXY => '',
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_MAXFILESIZE_LARGE => self::MAX_BYTES - 1,
					CURLOPT_NOPROGRESS => false,
					// Returning nonzero aborts cURL itself, including unknown-length bodies on older versions.
					CURLOPT_XFERINFOFUNCTION => static function ( $curl, $download_total, $downloaded, $upload_total, $uploaded ) {
						return $downloaded >= self::MAX_BYTES ? 1 : 0;
					},
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_HTTP_CONTENT_DECODING => false,
					CURLOPT_ENCODING => 'identity',
				) );
				if ( ! $configured ) {
					throw new WpOrg\Requests\Exception( 'Unable to pin remote address.', 'remote_content_pin_failed' );
				}
				$pinned = true;
			};
			$reject_encoding = static function ( &$raw, $request_url ) use ( $url ) {
				if ( $request_url !== $url ) {
					throw new WpOrg\Requests\Exception( 'Request URL changed.', 'remote_content_url_changed' );
				}
				$headers = substr( $raw, 0, strpos( $raw, "\r\n\r\n" ) ?: 0 );
				if ( preg_match( '/(?:^|\n)content-encoding\s*:/i', $headers ) ) {
					// Requests otherwise inflates compressed bodies before their size can be checked.
					throw new WpOrg\Requests\Exception( 'Encoded responses are not supported.', 'remote_content_encoded_response' );
				}
			};
			$check_peer = static function ( &$headers, $info = array() ) use ( $address ) {
				if ( empty( $info['primary_ip'] ) || inet_pton( $info['primary_ip'] ) !== inet_pton( $address ) ) {
					throw new WpOrg\Requests\Exception( 'Unexpected remote address.', 'remote_content_peer_mismatch' );
				}
			};
			$prepare = static function ( &$request_url, &$headers, &$data, &$type, &$options ) use ( $url, $args, $capture, $reject_encoding, $check_peer, &$prepared ) {
				// WordPress's bridge retains the original URL even if another hook mutates
				// request_url. Use that context to leave unrelated nested requests alone.
				$hooks = $options['hooks'] ?? null;
				if ( $hooks instanceof WP_HTTP_Requests_Hooks && ( new ReflectionProperty( WP_HTTP_Requests_Hooks::class, 'url' ) )->getValue( $hooks ) !== $url ) { return; }
				if ( $request_url !== $url || $type !== $args['method'] ) {
					throw new WpOrg\Requests\Exception( 'Request URL or method changed.', 'remote_content_request_changed' );
				}
				$options['transport'] = WpOrg\Requests\Transport\Curl::class;
				$options['verify'] = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
				$options['verifyname'] = true;
				$options['follow_redirects'] = false;
				$options['max_bytes'] = self::MAX_BYTES;
				$options['hooks']->register( 'curl.before_send', $capture );
				$options['hooks']->register( 'curl.after_request', $check_peer );
				$options['hooks']->register( 'requests.before_parse', $reject_encoding );
				$prepared = true;
			};
			$observe_preemption = static function ( $pre, $request_args, $request_url ) use ( $url, &$preempted ) {
				if ( $request_url === $url ) {
					$preempted = false !== $pre;
				}
				return $pre;
			};
			add_filter( 'pre_http_request', $observe_preemption, PHP_INT_MAX, 3 );
			add_action( 'requests-requests.before_request', $prepare, PHP_INT_MAX, 5 );
			// Apply the pin after the legacy http_api_curl action and other normal cURL callbacks.
			add_action( 'requests-curl.before_send', $pin, PHP_INT_MAX );
			try {
				$response = wp_safe_remote_request( $url, $args );
				return ! is_wp_error( $response ) && ! $preempted && ( ! $prepared || ! $pinned ) ? new WP_Error( 'transport_not_pinned' ) : $response;
			} finally {
				remove_filter( 'pre_http_request', $observe_preemption, PHP_INT_MAX );
				remove_action( 'requests-requests.before_request', $prepare, PHP_INT_MAX );
				remove_action( 'requests-curl.before_send', $pin, PHP_INT_MAX );
			}
		}

		private function credential( $alias, $url, $method ) {
			$sources = apply_filters( 'remote_content_shortcode_credentials', array() );
			$source = is_array( $sources ) ? ( $sources[ $alias ] ?? null ) : null;
			return Remote_Content_Shortcode_Credentials::headers( $source, $url, $method );
		}

		private function fail( $error, $url = '' ) {
			$error = is_wp_error( $error ) ? $error : new WP_Error( $error );
			$code = $error->get_error_code();
			$known = array( 'invalid_attributes', 'invalid_fallback', 'invalid_method', 'invalid_url', 'invalid_query_parameter', 'invalid_post_body', 'post_body_too_large', 'unconfigured_credentials', 'credential_origin_mismatch', 'credential_endpoint_mismatch', 'invalid_credentials', 'invalid_html_input', 'html_too_complex', 'selector_too_complex', 'invalid_selector', 'selector_no_matches', 'invalid_regex', 'invalid_output', 'request_timeout', 'redirect_not_allowed', 'invalid_redirect', 'http_status_error', 'response_too_large', 'http_request_failed', 'proxy_not_supported', 'transport_not_pinned', 'html_too_large', 'invalid_html', 'invalid_url_base', 'output_too_large', 'regex_input_too_complex', 'regex_output_too_large' );
			$this->last_result['error_code'] = in_array( $code, $known, true ) ? $code : 'request_failed';
			/** Diagnostic listeners receive details; visitors receive no internal error text. */
			do_action( 'remote_content_shortcode_error', $error, $url );
			return '';
		}
	}
	RemoteContentShortcode::init();
}
