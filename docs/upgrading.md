# Upgrading from version 1.x

Version 2 changes how destinations, remote HTML, credentials, and selectors are handled. Test existing imports on a staging copy before updating a production site. Keep a backup of the site's database and plugin files.

## Before updating

1. Confirm WordPress 6.6 or later and PHP 8.2 or later with DOM and cURL 7.49 or later.
2. Find existing `remote_content` shortcodes, including widgets, templates, and reusable content. Record the public source URLs and the parts of each page the imports should display.
3. Check those imports against the migration table below. Configure credential aliases in **Tools → Remote content → Connections** or trusted PHP before publishing pages that depend on them. Existing credential constants, external options and protected metadata can remain in place behind explicit PHP aliases.
4. Choose fallback content for imports that should show a useful message during an outage.

## What needs adjustment

| Existing behavior | Version 2 behavior and migration |
| --- | --- |
| Remote scripts, inline event handlers, or other executable markup | WordPress post-HTML sanitization removes them after every transformation. Use trusted local code for interactive functionality, or `htmlentities="true"` when displaying source. |
| Local paths, `file://`, intranet URLs, or metadata addresses | Only public HTTP and HTTPS destinations are accepted. Move the intended published content to an appropriate public endpoint. |
| Disabled TLS verification or SSL-bypass filters | Certificates are always verified. Fix the endpoint's certificate and hostname. |
| Implicit credential lookup or plaintext `userpwd` values | Save a connection with an HTTPS origin, exact endpoint path and allowed methods, or configure a PHP alias with explicit storage. See the example below. Remove passwords or tokens saved in post content and rotate exposed credentials. |
| Credential lookup using the ambient post's metadata | Configure an explicit positive integer `post_id` and a protected metadata key that is not REST-exposed, or move the credential to a constant or option. |
| jQuery selectors such as `p:eq(0)` | Use supported CSS, for example `p:first-of-type` when the intended match is the first `p` sibling. CSS sibling positions and jQuery result-set indexes are different, so verify the selected output. |
| Imported relative links and images | Set `rewrite_urls="true"` to resolve supported attributes against the final remote URL. CSS background URLs are not rewritten. |
| Compressed responses or proxy-only outbound access | Endpoints must support uncompressed responses over direct public connections. |
| Very large documents, complex selectors, or unrestricted cache lifetimes | Use smaller fragments and simpler expressions. Fresh and stale lifetimes each have a maximum of one day; storage and processing have bounded limits. |

## Keep an existing shortcode with an explicit alias

For a shortcode that already uses `userpwd="docs_api"`, an administrator can map that name to an existing option containing a Basic authentication `username:password` value:

```php
add_filter( 'remote_content_shortcode_credentials', function ( $sources ) {
    $sources['docs_api'] = array(
        'origin' => 'https://docs.example.com',
        'path'   => '/public-summary',
        'type'   => 'option',
        'key'    => 'existing_docs_credentials',
    );
    return $sources;
} );
```

The saved shortcode can remain:

```text
[remote_content url="https://docs.example.com/public-summary" userpwd="docs_api"]
```

Only configure endpoints whose responses are intended for the post's audience. A credential alias does not make imported content private. Authentication is GET-only by default; add `'methods' => array( 'GET', 'POST' )` only if that endpoint needs POST. Authenticated requests do not cache or follow redirects.

## After updating

1. Run imports through **Tools → Remote content** and check the preview, HTTP status, error code, and match count.
2. Visit representative pages while logged out. Confirm selection/removal, links, images, and fallback messages. Check editor previews separately because they bypass visitor caching.
3. Clear this plugin's cache and any page/CDN caches when checking changed content.
4. Verify authenticated imports with their configured aliases. The upgrade preserves saved posts and existing external credential storage, but does not create aliases automatically. PHP-defined aliases remain read only in Connections.

The shortcode, block, transformations, public GET cache, stale fallback, URL rewriting, Basic/Bearer/API-key authentication and all five Tools tabs remain in Free. Pro is a separate optional add-on; no Free functionality depends on a license service.

Back up the WordPress salts with the database when using encrypted managed connections. Rotating the salts makes existing sealed credentials unreadable and requires re-entering the secrets. Deactivation preserves these settings. Uninstalling Free removes its managed connections and caches on each site while retaining PHP constants, external credential options and protected metadata.

If an import fails, use the [troubleshooting guide](troubleshooting.md). Version 1.5 and earlier have known vulnerabilities; keep affected imports disabled while resolving compatibility issues instead of restoring a vulnerable version on a public site.
