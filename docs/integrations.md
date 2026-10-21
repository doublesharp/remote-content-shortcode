# Extension APIs and hooks

Free provides protected fetching, credential aliases, HTML transformations and administrator tab hooks for PHP integrations. Its shortcode, block and administrator tools work independently of Pro and any license service. Pro is a separate plugin for reusable sources, schedules, OAuth and source management. Installed Pro functionality does not use a remote license check to decide whether to run.

## Fetch a source

```php
$core = RemoteContentShortcode::get_instance();
$response = $core->fetch_source(
    array(
        'url'     => 'https://api.example.com/published-summary',
        'method'  => 'POST',
        'userpwd' => 'published_summary',
        'timeout' => 5,
    ),
    wp_json_encode( array( 'language' => 'en' ) ),
    false
);
```

`fetch_source($attributes, $content = null, $content_filters = true)` accepts the shortcode's scalar settings and an optional string request body. If `$content` is null, an attribute named `body` can supply the body. GET and POST are supported. The returned value is a `WP_Error` or an array with string `body`, final `url` and HTTP `status`.

The API uses the core public-destination checks, DNS pinning, TLS verification, method restrictions, credential scope, redirect policy and response-size limits. It always fetches without using the visitor response cache. It does not set a JSON Content-Type header automatically. Configure service-specific behavior in trusted code where required.

With `$content_filters` enabled, it forwards explicitly named `params` values from the current request and applies `remote_content_shortcode_url` and `remote_content_shortcode_postfields`. Pass false for stored or scheduled sources that must use their saved URL and body without ambient request context. Credential resolution and all transport restrictions still apply.

The response body contains raw transport bytes. Validate JSON before using its data, and escape values when rendering them. Do not echo a raw fetch result as HTML.

## Render verified HTML

```php
if ( ! is_wp_error( $response ) ) {
    echo $core->render_fetched(
        $response,
        array(
            'selector'     => 'main',
            'remove'       => 'script, .advertisement',
            'rewrite_urls' => 'true',
        )
    );
}
```

`render_fetched($response, $attributes)` accepts a previously verified response envelope and scalar transformation settings. It checks the body size and URL shape, then uses the shared selection/removal, replacement, URL rewriting, tag stripping, entity encoding and final `wp_kses_post()` pipeline. It does not fetch the source again or consume `fallback` as the shortcode wrapper does. Invalid envelopes or transformations return empty output. Add-ons remain responsible for permission checks, source ownership and validating any persisted envelope.

## Credentials

`remote_content_shortcode_credentials` receives the alias map. Each entry declares `origin`, exact `path`, permitted `methods`, `type`, storage `key`, and authentication settings. See [credential restrictions](security.md#credentials-and-forwarded-data) and the PHP alias examples in the main readme.

The Connections manager contributes encrypted `type=connection` entries at filter priority 5. PHP filters at the normal priority 10 can replace them. Code-managed aliases are read only in the manager. Their private storage key and scope are omitted from the list. Existing `constant`, `option` and protected `post_meta` sources remain supported.

Trusted add-ons can declare `type=provider`. After the origin, path and method checks pass, `remote_content_shortcode_credential_value($value, $source, $url, $method)` supplies a credential string or `WP_Error`. Its default value is an `invalid_credentials` error. Use the configured `key` to identify an add-on-owned credential. Returned strings must satisfy the same size, control-character and Basic/Bearer/API-key format checks as other sources. A provider must enforce its own ownership and storage policy and protect secrets in errors or logs.

`Remote_Content_Shortcode_Secrets::seal($value)` and `open($envelope)` provide authenticated encryption for bounded strings, up to 1 MiB, independently of the connection manager's 8 KiB credential limit. They return strings or `WP_Error`. Encryption derives its key from the WordPress salts; changing the salts invalidates previously sealed values. There is no plaintext fallback.

## Administrator tabs

```php
add_filter( 'remote_content_shortcode_admin_tabs', function ( $tabs ) {
    $tabs['my_sources'] = 'My sources';
    return $tabs;
} );
add_action( 'remote_content_shortcode_admin_tab_my_sources', function () {
    echo '<p>' . esc_html__( 'Manage your configured sources here.', 'my-plugin' ) . '</p>';
} );
```

The filter receives a `slug => label` map. Slugs start with a lowercase letter and contain only lowercase letters, digits, underscores or hyphens, up to 64 characters. Labels are escaped. The screen checks `manage_options` before rendering or dispatching any tab. Extension callbacks own their output escaping, POST handling, capabilities and nonces. A tab callback does not create a public endpoint.

## Existing transformation hooks

| Hook | Arguments | Use |
| --- | --- | --- |
| `remote_content_shortcode_url` | `$url` | Change the URL before canonicalization and validation. |
| `remote_content_shortcode_postfields` | `$content, $url` | Supply a string POST body. |
| `remote_content_shortcode_phpQuery` | `$html, $url, $selector, $remove` | Change HTML before bounded selection/removal. The legacy name remains; phpQuery is not bundled. |
| `remote_content_shortcode_return` | `$html, $url` | Change transformed HTML before final sanitization. |
| `remote_content_shortcode_error` | `$error, $url` | Observe a failure server-side. Protect raw error and URL data; do not print them to visitors. |

Hooks do not disable final HTML sanitization or transport restrictions. An integration must not use an HTTP preemption filter to fetch an unvalidated source outside the protected transport.
