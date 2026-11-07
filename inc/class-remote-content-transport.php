<?php
/** cURL adapter that bounds headers before Requests retains them. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Remote_Content_Shortcode_Transport implements WpOrg\Requests\Transport {
	public function request( $url, $headers = array(), $data = array(), $options = array() ) {
		$transport = new WpOrg\Requests\Transport\Curl();
		$bytes = 0; $fields = 0;
		$options['hooks']->register( 'curl.before_send', static function ( $handle ) use ( $transport, &$bytes, &$fields ) {
			if ( ! curl_setopt( $handle, CURLOPT_HEADERFUNCTION, static function ( $curl, $line ) use ( $transport, &$bytes, &$fields ) {
				$bytes += strlen( $line ); ++$fields;
				if ( $bytes > 65536 || $fields > 128 ) { throw new WpOrg\Requests\Exception( 'Response headers exceeded the limit.', 'remote_content_headers_too_large' ); }
				if ( preg_match( '/(?:^|\n)\s*content-encoding\s*:/i', $line ) ) { throw new WpOrg\Requests\Exception( 'Encoded responses are not supported.', 'remote_content_encoded_response' ); }
				return $transport->stream_headers( $curl, $line );
			} ) ) { throw new WpOrg\Requests\Exception( 'Unable to bound response headers.', 'remote_content_header_limit_failed' ); }
		} );
		return $transport->request( $url, $headers, $data, $options );
	}
	public function request_multiple( $requests, $options ) { throw new WpOrg\Requests\Exception( 'Concurrent transport is unsupported.', 'remote_content_transport_unsupported' ); }
	public static function test( $capabilities = array() ) { return WpOrg\Requests\Transport\Curl::test( $capabilities ); }
}
