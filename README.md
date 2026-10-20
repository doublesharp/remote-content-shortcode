# Remote Content Shortcode

Embed public HTTP or HTTPS content in WordPress posts and pages using a shortcode or the Remote content block.

```text
[remote_content url="https://example.com/article" selector="main" remove="script, .advertisement" fallback="Content is temporarily unavailable."]
```

The plugin supports CSS selection, bounded text replacements, persistent public-response caching, optional relative URL rewriting, stale content during outages, and server-configured authentication aliases. Administrators can inspect imports and clear the plugin's cache under **Tools → Remote content**.

Requires WordPress 6.6 or later, PHP 8.2 or later, DOM, and cURL 7.49 or later. Imported HTML is sanitized. Only public HTTP and HTTPS destinations are accepted; local files and private networks are rejected.

See [the plugin readme](readme.txt) for all attributes, examples, compatibility changes, and configuration. See [the security reference](docs/security.md) for request restrictions and credential configuration.

WordPress.org: https://wordpress.org/plugins/remote-content-shortcode/

Licensed under [GPL-2.0-or-later](LICENSE.txt). Bundled dependencies are documented in [third-party.txt](third-party.txt).
