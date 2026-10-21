# Troubleshooting imports

Open **Tools → Remote content** as an administrator. Enter the source URL and the same selection, removal, rewriting, fallback, and credential alias settings used in your post, then select **Test content**.

The result shows the error code, HTTP status when available, matching element count, elapsed time, and a sanitized preview. Test requests always fetch fresh content and bypass visitor caching. Their timeout is limited to five seconds, plus system DNS resolution time. A post can therefore succeed with a longer timeout while the diagnostic request times out.

## Common results

| Error code | What to check |
| --- | --- |
| `invalid_url` | Use a complete public HTTP or HTTPS URL. Remove embedded credentials. Private, loopback, link-local, and IPv6-only destinations are unsupported. Check the hostname's public IPv4 records and WordPress's safe HTTP port restrictions. |
| `selector_no_matches` | Test without a selector to confirm the response contains the expected HTML, then try a simple tag or class selector. Content inserted by the remote site's JavaScript is not present in the fetched HTML. |
| `invalid_selector` | Use a supported CSS selector. Replace jQuery-only expressions such as `:eq()` with CSS equivalents, checking which sibling elements are counted. |
| `html_too_complex` / `selector_too_complex` | Use a smaller source fragment or simpler selectors. CSS processing permits up to 2,000 elements and depth 32, with stricter work limits for descendant and sibling selectors. The limit applies to the document before removal. |
| `http_status_error` | Inspect the HTTP status. The endpoint must return a successful 2xx response. A 401/403 may need a configured credential alias; a 404 usually indicates a changed source path. |
| `http_request_failed` / `request_failed` | Check outbound networking, DNS, the remote TLS certificate, and whether the endpoint supports uncompressed responses. Transport errors use a generic public code to avoid disclosing internal details. |
| `request_timeout` | Reduce the amount of remote work or use a faster endpoint. The HTTP budget spans redirects; DNS resolution can take additional time. |
| `redirect_not_allowed` / `invalid_redirect` | Use the endpoint's final URL directly. Public GET requests allow at most three redirects. Authenticated requests and POST requests cannot redirect. |
| `proxy_not_supported` | The endpoint requires a WordPress HTTP proxy. Imports require direct public connectivity. |
| `response_too_large` / `html_too_large` | Use an endpoint returning a smaller fragment. Responses must be smaller than 1 MiB, and transformed output also has limits. |
| `unconfigured_credentials` | Ask an administrator to configure the alias in trusted server-side code. A shortcode cannot directly select an arbitrary option, constant, or metadata key. |
| `credential_origin_mismatch` / `credential_endpoint_mismatch` | Match the alias's HTTPS origin, normalized endpoint path, and allowed HTTP method. Query values can vary. |
| `invalid_credentials` | Check the configured storage type, key, and authentication format. Basic authentication expects `username:password`. Metadata storage needs a fixed post ID and a protected key that is not exposed through REST. |
| `invalid_regex` / `regex_input_too_complex` / `regex_output_too_large` | Test without `find` and `replace`, then simplify the pattern or replacement. Regex input is limited to 256 KiB, with additional pattern, capture, replacement-count, and output limits. |
| `invalid_fallback` | Shorten the fallback to at most 4,096 bytes. If `decode_atts` is enabled, this limit applies after attribute decoding. |
| `cache_busy` | Wait briefly and retry clearing. Cache mutations skip work when another writer holds the lock; an abandoned lock expires after 60 seconds. |

## The preview works but visitors see old content

Administrator previews bypass caching. Open the post while logged out to check visitor behavior. Clear the exact source URL under **Tools → Remote content**, including any query values added by `params` or a URL filter. Leave the URL empty to clear all entries belonging to this plugin. Other WordPress caches are unaffected.

Check page caches or a CDN separately, since they may retain the rendered page after the plugin cache is cleared. If `stale_ttl` is enabled, a failed refresh can intentionally keep the last successful import visible until its original stale deadline.

## The import is empty without an error

A successful endpoint can return an empty response, or sanitization can remove all its markup. Confirm the endpoint returns useful HTML without requiring JavaScript. Use `htmlentities="true"` to display source as escaped text when appropriate, or configure `fallback`. A response containing `0` is valid and does not trigger fallback.

## Collecting a useful bug report

Include the plugin, WordPress, and PHP versions; the diagnostic error code and status; and a minimal shortcode or block configuration. Use a public sample endpoint or a small reproducible fixture. Remove tokens, passwords, and sensitive query values before sharing settings.

Developers can inspect the `remote_content_shortcode_error` action server-side. Its arguments can contain sensitive transport details and URLs, so diagnostic listeners must not print them to visitors. Report suspected vulnerabilities through the [private reporting instructions](../SECURITY.md).
