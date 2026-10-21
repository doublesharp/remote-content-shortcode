![Remote Content Shortcode by doublesharp](.wordpress-org/banner-1544x500.png)

# Remote Content Shortcode

[![Checks](https://github.com/doublesharp/remote-content-shortcode/actions/workflows/validate.yml/badge.svg?branch=main)](https://github.com/doublesharp/remote-content-shortcode/actions/workflows/validate.yml)
![WordPress 6.6+](https://img.shields.io/badge/WordPress-6.6%2B-21759B?logo=wordpress&logoColor=white)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL_v2_or_later-blue.svg)](LICENSE.txt)

Bring selected content from another site into WordPress. Choose the elements to include, cache the response, and render sanitized HTML in a post or page using a **shortcode** or the **Remote content block**.

[Quick start](#quick-start) · [Examples](#examples) · [Settings](#settings) · [Authentication](#authentication) · [Security](#security-and-compatibility)

## What it does

- **Select content.** Import a whole response or choose elements with CSS selectors. Remove unwanted elements before selection.
- **Cache imports.** Reuse public GET responses through a persistent object cache or database transients. Optionally serve the last successful response during an outage.
- **Keep links working.** Resolve relative links and image URLs against the remote page's final URL with `rewrite_urls="true"`.
- **Control the result.** Replace text with regular expressions, strip HTML, display escaped source, or provide fallback content.
- **Connect to APIs.** Send GET or POST requests and use administrator-configured Basic, Bearer, or API-key credentials.
- **Build and inspect imports.** Generate a copyable shortcode, preview sanitized output, manage credential aliases, inspect cache totals, and find local usage under **Tools → Remote content**.

## Quick start

Download the installable `remote-content-shortcode-VERSION.zip` from [GitHub Releases](https://github.com/doublesharp/remote-content-shortcode/releases) once a release is published. Each release includes a SHA256 checksum. Upload the plugin ZIP through **Plugins → Add New → Upload Plugin**, then activate **Remote Content Shortcode**. The package includes its CSS selector dependency.

Add a Shortcode block to a post or page:

```text
[remote_content url="https://example.com/article" selector="main"]
```

Or insert the **Remote content** block and configure its URL, selectors, fallback, and cache options in the block settings. Its server-rendered preview uses the same renderer as the shortcode. The block supports GET; use the shortcode for POST requests.

Plugin directory: [Remote Content Shortcode on WordPress.org](https://wordpress.org/plugins/remote-content-shortcode/).

## Examples

### Import a section and remove unwanted elements

```text
[remote_content url="https://example.com/news" selector=".announcements" remove="script, .advertisement" rewrite_urls="true"]
```

Rewriting supports `href`, `src`, `poster`, `cite`, and ordinary `srcset` values. Fragment links such as `#details` stay local. CSS background URLs are not rewritten.

### Cache a response and handle an outage

```text
[remote_content url="https://example.com/status" selector=".status" cache_ttl="300" stale_ttl="3600" fallback="Status is temporarily unavailable."]
```

For logged-out visitors, this keeps a response fresh for five minutes. If a refresh fails, the last successful response can be used for up to one additional hour. If no eligible response exists, the fallback appears. Stale content and fallback HTML pass through sanitization.

### Forward selected query parameters

```text
[remote_content url="https://example.com/catalog" params="category,language=en"]
```

Only the named values from the current page's query string are forwarded. Here, `language` defaults to `en` when absent. Visitor cookies and incoming authorization headers are not forwarded.

### Send a POST body

```text
[remote_content url="https://example.com/public-search" method="POST"]search=example[/remote_content]
```

POST responses are never cached, and POST redirects are refused.

### Display source or plain text

```text
[remote_content url="https://example.com/snippet" htmlentities="true"]
[remote_content url="https://example.com/article" selector="main" strip_tags="true"]
```

## Settings

| Attribute | Purpose | Default |
| --- | --- | --- |
| `url` | Absolute public HTTP or HTTPS source URL | Required |
| `selector` | CSS selector for elements to return | Entire response |
| `remove` | CSS selector for elements to remove first | None |
| `method` | `GET` or `POST` | `GET` |
| `timeout` | HTTP request budget across redirects, 1–30 seconds | `10` |
| `cache` | Cache public GET responses for logged-out visitors | `true` |
| `cache_ttl` | Fresh cache lifetime, 1–86,400 seconds | `3600` |
| `stale_ttl` | Additional outage fallback window, 0–86,400 seconds | `0`, disabled |
| `fallback` | Sanitized HTML for failed or empty imports, up to 4,096 bytes | Empty |
| `rewrite_urls` | Resolve supported relative URLs against the final remote URL | `false` |
| `params` | Named query parameters to forward, with optional defaults | None |
| `userpwd` | Administrator-configured credential alias | None |
| `find` / `replace` | Regular expression and replacement text | None |
| `htmlentities` | Display remote source as escaped text | `false` |
| `strip_tags` | Return text without HTML, script, or style elements | `false` |

Logged-in visitors, editor previews, and administrator diagnostics bypass response caching. Each site stores at most **32 entries and 8 MiB** of serialized cached payloads. Cache clearing targets an exact request URL or all entries belonging to this plugin.

See [readme.txt](readme.txt) for the complete attribute reference, transformation limits, and WordPress filters and actions.

## Authentication

The `userpwd` attribute names an administrator-configured alias. Open **Tools → Remote content → Connections** to save a Basic credential, Bearer token, or API key. Each connection grants access to an exact HTTPS origin, path, and allowed HTTP methods. Password fields stay blank; leaving one blank retains its existing secret. Use the explicit removal checkbox to erase a saved secret.

Managed secrets are encrypted with authenticated encryption derived from the WordPress salts. Changing those salts makes existing sealed credentials unreadable. Back up the salts with your database, and re-enter the connection secrets after a deliberate salt rotation. If neither Sodium nor AES-256-GCM is available, credential storage fails without saving plaintext.

Aliases can also be configured in trusted PHP code. These entries appear as read only in Connections, and their storage names and scope are omitted from that list. Passwords and tokens belong outside post content.

For example, with a `DOCS_API_TOKEN` constant defined separately, add this configuration in an mu-plugin:

```php
add_filter( 'remote_content_shortcode_credentials', function ( $sources ) {
    $sources['docs_api'] = array(
        'origin' => 'https://docs.example.com',
        'path'   => '/public-summary',
        'type'   => 'constant',
        'key'    => 'DOCS_API_TOKEN',
        'auth'   => 'bearer',
    );
    return $sources;
} );
```

Then reference the alias:

```text
[remote_content url="https://docs.example.com/public-summary" userpwd="docs_api"]
```

Aliases are bound to an HTTPS origin, an exact normalized endpoint path, and permitted methods. GET is allowed by default; POST requires an explicit grant. Basic and API-key authentication and option/protected-metadata storage are also supported. Authenticated responses are never cached and cannot follow redirects.

An alias grants shortcode authors access to its endpoint. Anyone who can view the resulting post can view its imported content. Use credentials intended for publishing that data. See [credential configuration and restrictions](docs/security.md#credentials-and-forwarded-data).

## Tools and add-ons

**Tools → Remote content** has five Free tabs:

- **Builder** generates a shortcode from public settings, tests the request, and shows sanitized preview HTML. POST bodies entered here become post content when the generated shortcode is copied.
- **Connections** stores up to 32 encrypted credential aliases per site and lists read-only PHP aliases.
- **Cache** shows active indexed entry and byte totals and clears an exact source URL or all plugin entries.
- **Usage** lists local posts and pages containing the shortcode or block, 20 at a time. It does not fetch sources or display post content.
- **Help** includes a copyable environment summary without source URLs, credentials or content.

The shortcode, block, selectors, public GET cache, stale fallback, URL rewriting, Basic/Bearer/API-key connections and all five tabs are part of Free. Free works independently and has no license client or licensing-service dependency.

Remote Content Shortcode Pro is a separate add-on for reusable sources, scheduled refreshes, OAuth and source management. It uses Free's protected fetching and HTML transformations. Installed Pro features continue to run without an active update license. See [the extension APIs and hooks](docs/integrations.md) for PHP integrations.

Uninstalling Free removes its cached data and administrator-managed connection records on each site. PHP-configured credential constants, external options, protected metadata and unrelated caches are retained. Deactivation preserves the settings.

## Security and compatibility

Imported HTML passes through WordPress's `wp_kses_post()` after transformations and output filters. Scripts, event handlers, and disallowed markup are removed. Sanitization cannot be disabled through a shortcode attribute.

Requests accept only public HTTP and HTTPS destinations. Local files, private networks, loopback and link-local addresses, embedded URL credentials, and unsafe ports are rejected. DNS addresses are checked and pinned, TLS certificates are verified, and each permitted GET redirect is validated.

Resource limits include:

- Responses must be **smaller than 1 MiB**; compressed responses are refused.
- CSS processing allows up to **2,000 elements**, depth **32**, and selectors up to **256 bytes**, with additional work limits for expensive expressions.
- Invalid inputs, unsupported selectors, and failed requests produce empty output or the configured fallback.

### Requirements

| Component | Minimum |
| --- | --- |
| WordPress | 6.6 |
| PHP | 8.2 |
| PHP extensions | DOM and cURL |
| cURL | 7.49 with `CURLOPT_CONNECT_TO` support |

IPv6-only hosts, literal IPv6 URLs, private intranet imports, and proxy-only outbound networking are unsupported. System DNS resolution may add time outside the configured HTTP timeout.

### Upgrading from 1.x

Review existing imports before upgrading. Version 2 removes executable remote markup and implicit credential lookup, replaces phpQuery with a bundled Symfony CSS selector implementation, and adds bounded caching and processing. jQuery-only selectors such as `:eq()` are unsupported. Imports that rely on scripts, private URLs, compressed responses, or the former SSL-bypass settings need adjustment.

The [security and compatibility reference](docs/security.md) describes these restrictions and migration considerations in full.

Follow the [upgrade checklist](docs/upgrading.md) when moving from 1.x. For failed or empty imports, start with [troubleshooting](docs/troubleshooting.md), which explains diagnostic codes and cache behavior.

## Reporting problems

Use [GitHub Issues](https://github.com/doublesharp/remote-content-shortcode/issues) for general bugs, including a minimal example and the relevant plugin, WordPress, and PHP versions. Report suspected vulnerabilities privately using the instructions in [SECURITY.md](SECURITY.md).

## License

Licensed under [GPL-2.0-or-later](LICENSE.txt). Bundled dependencies are documented in [third-party.txt](third-party.txt).
