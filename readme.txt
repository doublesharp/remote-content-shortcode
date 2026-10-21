=== Remote Content Shortcode ===
Contributors: doublesharp
Tags: shortcode, content, import, scraper, remote
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed remote content with a shortcode or block. Select HTML, cache imports, rewrite links, and provide fallback content.

== Description ==

Use `[remote_content url="https://example.com/"]` to fetch public HTTP or HTTPS content from your server and include it in a post or page.

Supports GET, POST, Basic/Bearer/API-key authentication through administrator-configured aliases, CSS selectors, element removal, regular expression replacement, entity encoding, tag stripping, persistent caching, relative URL rewriting, and fallback content. The Remote content block provides editor controls and a server-rendered preview using the same protected renderer as the shortcode.

Remote HTML is sanitized with WordPress's allowed post HTML rules. Scripts, event handlers, and other disallowed markup are removed even when added by a replacement or an output filter. Set `htmlentities="true"` to display source code as text.

PHP 8.2 or later, DOM, and cURL 7.49 or later are required. CSS selectors use the bundled, namespace-isolated Symfony CssSelector component. Most standard CSS3 selectors are supported; phpQuery compatibility is not complete, and jQuery-only selectors such as `:eq()` are not supported.

= Usage =

`[remote_content url="https://example.com/article" selector="main" remove="script, .advertisement" cache="true"]`

Attributes:

* `url`: Absolute public HTTP or HTTPS URL. Local files, private networks, loopback addresses, credentials embedded in URLs, and unsafe ports are rejected. Standard WordPress safe HTTP ports apply.
* `method`: GET or POST, default GET. Enclosed shortcode content is the POST body. POST responses are never cached and POST redirects are refused.
* `timeout`: Total HTTP request budget across redirects, from 1 to 30 seconds, default 10. System DNS resolution may add time outside this budget.
* `params`: Comma-separated query-string names, optionally with defaults, such as `id,language=en`. Only named GET values are forwarded. Values are URL-encoded; arrays are rejected. Missing values without defaults are omitted. Defaults may contain equals signs.
* `userpwd`: A configured credential alias, such as `docs_api`. See Authentication below. Raw passwords and arbitrary option, metadata, or constant names are no longer accepted in shortcodes.
* `selector`: Standard CSS selector for the elements to return. Defaults to the entire response. CSS processing accepts at most 2,000 elements, document depth 32, and selectors up to 256 bytes. A combined work budget accounts for generated XPath traversal, with stricter limits for descendant and sibling selectors than simple tag or class selections.
* `remove`: CSS selector for elements to remove before selection. Defaults to no removal. The same document and selector work budgets apply before removal.
* `rewrite_urls`: Set to true to resolve href, src, poster, cite, and ordinary srcset URLs against the remote page's final URL after redirects. Default false. Local fragment links stay local; imported base elements are ignored. CSS URLs are not rewritten. Ambiguous or data-URL srcset attributes are dropped.
* `fallback`: HTML to display if the import fails or produces empty output, limited to 4,096 bytes. It is sanitized and is not interpreted as a shortcode. A response containing the string "0" remains valid content.
* `find`: PHP regular expression, such as `~old-domain[.]com~`. Invalid patterns return empty output. Patterns are limited to 1,024 bytes, 32 opening parentheses, and 1,000 replacements per render. The input to this transformation must not exceed 256 KiB.
* `replace`: Replacement text, including regular expression backreferences. Defaults to an empty string and is limited to 4,096 bytes. Use `%QUOT%` in find or replace to represent a double quote.
* `htmlentities`: Set to true to encode remote source for display. Default false.
* `strip_tags`: Set to true to remove HTML, script, and style elements and return text. Default false.
* `decode_atts`: Set to true for entity-encoded shortcode attributes from SyntaxHighlighter. Default false.
* `cache`: Set to false to disable caching. Default true for unauthenticated GET requests by logged-out visitors, including public REST-rendered content. Logged-in renders and editor previews bypass caching so they cannot evict public content. Each final URL, request configuration, and site has a separate cache key. Transformations and output filters run on every render. Failed requests are cached for up to 60 seconds, or the configured cache_ttl if shorter, so a broken remote is not retried on every render.
* `cache_ttl`: Fresh cache lifetime from 1 to 86,400 seconds, default 3,600. Uses a persistent WordPress object cache when installed, otherwise expiring database transients. Each site stores at most 32 entries and 8 MiB of serialized cached payloads; older entries can be evicted before their lifetime ends.
* `stale_ttl`: Additional time to retain the last successful response for use if a refresh fails, from 0 to 86,400 seconds. Default 0 disables stale fallback. Only cached, unauthenticated GET requests qualify. Failures never extend the original stale deadline. All transformations and final sanitization still run on stale content.

= Block editor and diagnostics =

Insert the Remote content block and set its source URL, selectors, removal rule, fallback, credential alias, URL rewriting, and cache options in the block settings. The block imports with GET. Use the shortcode for POST requests. Editor previews can make remote requests and use the same security restrictions as frontend rendering. Store only a configured credential alias in a block, never a password or token.

Administrators can open Tools > Remote content to test an import. Diagnostics show HTTP status, cache status, selector match count, elapsed time, and a sanitized preview. Administrator tests bypass caching and show a disabled cache status; they do not measure whether visitors have a cached response. Raw request error messages, credential values, and destination URLs are excluded from diagnostic output. Test requests use a timeout of at most five seconds, plus system DNS resolution time. Inputs are cleared after submission so URLs and aliases are not echoed back.

The same screen can clear entries for an exact source URL, including its query string, or clear all of this plugin's cache entries. Other WordPress caches are unaffected. The URL is normalized before matching; use the actual URL after any shortcode URL filter and forwarded parameters.

= POST example =

`[remote_content url="https://example.com/public-search" method="POST"]search=example[/remote_content]`

Configure authenticated POST access explicitly if an endpoint requires credentials. Use read-only service credentials wherever possible.

= Authentication =

Administrators configure aliases in trusted server-side code, for example an mu-plugin. Each alias grants shortcode authors access to one HTTPS endpoint. Its fetched content will be visible to anyone who can view the post, including logged-out visitors. Authentication does not make imported content private.

Example configuration, with a `DOCS_API_CREDENTIALS` constant set separately to a `username:password` string:

    add_filter( 'remote_content_shortcode_credentials', function ( $sources ) {
        $sources['docs_api'] = array(
            'origin' => 'https://docs.example.com',
            'path'   => '/public-summary',
            'type'   => 'constant',
            'key'    => 'DOCS_API_CREDENTIALS',
        );
        return $sources;
    } );

Then use:

`[remote_content url="https://docs.example.com/public-summary" userpwd="docs_api"]`

The origin must contain only an HTTPS scheme, host, and optional port. The path must exactly match the endpoint's normalized URL path. Query parameters may vary. Redirects and response caching are disabled for authenticated requests. Aliases allow GET by default. To grant POST as well, add `'methods' => array( 'GET', 'POST' )` to that alias.

Supported storage types are `constant`, `option`, and `post_meta`. The configured key identifies the constant, option, or protected metadata key. There is no automatic fallback between storage types. Never put a password in a shortcode.

For post_meta storage, the alias must specify a fixed integer post_id and a protected metadata key, such as `'post_id' => 123, 'key' => '_remote_api_secret'`. Metadata exposed through the WordPress REST API is refused. The current loop, widget, or synced pattern never chooses the credential's post. Existing aliases with ordinary custom-field keys or no explicit post_id must migrate; constant or option storage is preferred.

Basic authentication is the default. To send a stored token as a Bearer credential, add `'auth' => 'bearer'` to the alias. To send an API key, add `'auth' => 'api_key'` and optionally `'header' => 'X-Service-Key'`; the default header is X-API-Key. API-key header names must be X-* or Api-Key, and routing, proxy, cookie, and method-override headers are refused. The same HTTPS origin, endpoint path, and method restrictions apply to every authentication type. Authenticated responses are never cached or served stale.

= External requests and limits =

The shortcode is registered in frontend, administration, and REST contexts. Requests happen when it is rendered, including editor previews and REST-rendered post content. The same request, credential, and output restrictions apply in every context. The destination is the URL configured in that shortcode, plus any explicitly forwarded query values or POST body. Credentials are sent only through a configured alias. No telemetry or developer service is contacted by the plugin.

The site owner is responsible for authorization to reproduce remote content and for checking the destination service's terms and privacy policy. Visitors' cookies and incoming Authorization headers are not forwarded.

Only public destinations are supported. Resolved addresses are checked and pinned for each request, with TLS certificate verification enabled. Public GET redirects are followed up to three times and every destination is validated. WordPress HTTP proxy routing is refused; environment proxy settings are disabled for these requests. HTTPS-only outbound proxies and private intranet imports are not supported.

WordPress's safe HTTP validator requires an IPv4 address record. IPv6-only DNS names and literal IPv6 URLs are unsupported. For hosts with IPv4 records, all returned IPv4 and IPv6 addresses are checked before connecting. URL or HTTP-method changes by late Requests hooks are rejected before the transport runs. The selected peer address is checked after connecting.

Requests advertise uncompressed responses and reject Content-Encoding responses before decompression. Responses at or above 1 MiB are rejected. CSS selector output and replacement output are limited to 1 MiB. Unsupported selectors, failed requests, invalid input, and HTTP error status codes produce empty output instead of publishing internal error messages.

CSS work limits are conservative. With no removal selector, `main p` permits at most 894 total elements at document depth 20; `h2 + p` permits at most 200 elements. Counts include HTML/body wrappers added by the parser. General and adjacent sibling selectors, and some pseudo-classes, are intended for small fragments. Selection and removal share the work budget, so combining them can lower these limits. Simple tag and class selections can use the full 2,000-element document limit, subject to depth and selector limits.

URL rewriting covers only href, src, poster, cite, and ordinary srcset attributes. It drops URL values containing literal whitespace instead of guessing an encoding. Form actions, CSS backgrounds, the background attribute, and longdesc are not rewritten.

The installed plugin includes `docs/security.md` with the full security and compatibility reference. Removing the plugin deletes its own cached data and metadata, including on multisite, without deleting credential sources or unrelated WordPress caches.

= Developer hooks =

The existing `remote_content_shortcode_url`, `remote_content_shortcode_postfields`, `remote_content_shortcode_phpQuery`, and `remote_content_shortcode_return` filters remain available. The phpQuery-named hook is a compatibility hook for the HTML string before selection; it no longer uses phpQuery. Return-filter output is always sanitized afterward.

`remote_content_shortcode_credentials` configures credential aliases. The `remote_content_shortcode_error` action receives a WP_Error and the requested URL for server-side diagnostics. Diagnostic listeners must protect any sensitive data in URLs and must not print raw errors to visitors.

The former SSL verification filters and the local draft's `REMOTE_CONTENT_ALLOW_ALL_URLS` and `REMOTE_CONTENT_ALLOW_PRIVATE_IPS` constants are not supported. TLS verification and public-destination restrictions cannot be disabled through this plugin.

The SyntaxHighlighter integration processes only remote_content shortcodes and sanitizes the resulting HTML.

== Installation ==

1. Upload the remote-content-shortcode directory to /wp-content/plugins/.
2. Verify PHP 8.2 or later with DOM and cURL 7.49 or later.
3. Activate Remote Content Shortcode in WordPress.
4. Add a shortcode with a full URL, for example `[remote_content url="https://example.com/"]`.

== Frequently Asked Questions ==

= Why is my shortcode empty? =

Check that the URL is public and accessible from the WordPress server, the remote service supports uncompressed responses, and any CSS selector matches the document. For authenticated requests, configure an alias with the correct origin, exact endpoint path, storage type, key, and allowed method. A developer can inspect the remote_content_shortcode_error action for diagnostic codes.

= Can I import private intranet content or execute remote scripts? =

No. Version 2 blocks private destinations and renders only allowed post HTML. Use entity encoding to display source rather than execute it.

= Do I need Composer on my WordPress server? =

No. The CSS selector dependency is bundled in the release. Its source and MIT license are included under inc/vendor/css-selector. See third-party.txt for the version and source information.

== Upgrade Notice ==

= 2.0 =
Security update. Requires WordPress 6.6 and PHP 8.2 with DOM and cURL. HTML is sanitized; credentials need configured aliases. Private URLs, remote scripts, compressed responses, and jQuery-only selectors are unsupported. Read the migration details before upgrading.

== Changelog ==

= 2.0 =
* Fix CVE-2023-45652, local file inclusion; CVE-2024-2089, stored cross-site scripting; and CVE-2024-2090, server-side request forgery.
* Sanitize all rendered remote HTML, including replacement and filter output.
* Replace direct cURL fetches with WordPress safe HTTP requests and pin validated public addresses to prevent DNS rebinding.
* Validate every redirect, verify TLS, and limit request time, response size, output expansion, and replacement count.
* Reject compressed responses before decoding, local files, private networks, and proxy routing.
* Replace obsolete phpQuery with a scoped Symfony CssSelector dependency and native DOM.
* Fix cache context collisions and query parameter encoding. Do not cache POST or authenticated responses.
* Add bounded persistent caching without an external cache, targeted invalidation, and opt-in stale content on failed refreshes.
* Add relative URL rewriting against the final redirect URL and sanitized fallback content.
* Add administrator-only diagnostics and a dynamic Gutenberg block with a protected server-rendered preview.
* Support scoped Bearer tokens and API keys alongside Basic authentication.
* Repair credential retrieval through explicit aliases restricted by HTTPS origin, exact path, and HTTP method.
* Handle invalid selectors, patterns, input, and HTTP errors without exposing internal diagnostics.
* Preserve zero-length and zero-valued responses and register the shortcode for WordPress previews.
* Limit SyntaxHighlighter processing to this plugin's shortcode.
* Bound document and selector work before XPath evaluation, abort oversized response transfers through cURL's progress callback, and cache public GET failures briefly.
* Reject request-hook URL or method changes and enforce the pinned destination and TLS options after ordinary cURL hooks.
* Document IPv4 DNS requirements and frontend, administration, and REST rendering behavior.

= 1.5 =
* PHP7 compatibility.
  * use `explode()` instead of `split()` for `phpquery`.
* Follow redirects with `CURLOPT_FOLLOWLOCATION`

= 1.4.2 =
* Add filters for third party modification of values.
  * `remote_content_shortcode_url`, arguments: `$url`
    * Modify the URL before it is fetched
  * `remote_content_shortcode_postfields`, arguments: `$tag_content`, `$url`
    * Modify the tag content before it is POSTed
  * `remote_content_shortcode_ssl_verifyhost`, arguments: `false`, `$url`
    * Verify host SSL certificates
  * `remote_content_shortcode_ssl_verifypeer`, arguments: `false`, `$url`
    * Verify peer SSL certificates
  * `remote_content_shortcode_phpQuery`, arguments: `$response`, `$url`, `$selector`, `$remove`
    * Modify response content before it is sent to `phpQuery`
  * `remote_content_shortcode_return`, arguments: `$response`, `$url`
    * Modify response before it is returned

= 1.4 =
* Convert `&amp;` to `&` in URLs to account for Visual/Text editor encoding.

= 1.3.3 =
* Fix for multiple `params` attributes.

= 1.3.2 =
* Fix for `params` attribute.

= 1.3.1 =
* Add support for passing through querystring parameters.

= 1.3 =
* Use double quotes in find/replace by using `%QUOT%` in its place, for example `find="~ (src|href)=(%QUOT%|')?(/)?~" replace=" \\1=\\2http://www.example.com/"`
* Bug fix for regex/non-empty replace.

= 1.2 =
* Support case insensitive `true/false` and `GET/POST` values.
* Bug fix for remote content caching.

= 1.1 =
* CSS selectors for filtering and removal.
* Regular expression replacement support.
* Support for `strip_tags`.
* Added attribute to allow caching to be disabled.

= 1.0 =
Initial version.
