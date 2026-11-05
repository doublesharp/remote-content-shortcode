# Local WordPress plugin lab

Run from the Remote Content Shortcode checkout with Docker Desktop running:

```sh
python3 -B tools/wordpress/site.py up
python3 -B tools/wordpress/site.py install-free
python3 -B tools/wordpress/site.py seed
python3 -B tools/wordpress/site.py check
```

Open http://localhost:8088/wp-admin/ as `admin`. The generated password is in
`.local-wordpress/admin-password`. The site binds only to the local machine.
WordPress 7.1.4/PHP 8.3, WP-CLI and MariaDB images are pinned by digest.
`stop` stops the containers; `up` resumes the existing installation.

WordPress files, the database, salts and private credentials stay in the ignored
`.local-wordpress/` directory on the Data volume. Keep that directory to preserve
the lab, license installation identity and encrypted source snapshots. PHP temp
files, WP-CLI caches and generated ZIPs use
`/Volumes/Scratch/remote-content-shortcode/tmp/wordpress-local/`. Scratch must be
mounted. Docker Desktop manages its own existing image cache and VM disk.

The package installer copies the runtime files into WordPress. Source checkouts
are never mounted over installed plugins, so WordPress updates really replace
the installed package. After editing Free, rerun `install-free`. To install Pro:

```sh
python3 -B ../remote-content-shortcode-suite/tools/package.py \
  --free-dir "$PWD" \
  --pro-dir ../remote-content-shortcode-suite/plugins/pro \
  --output-dir /Volumes/Scratch/remote-content-shortcode/tmp/wordpress-local/packages
python3 -B tools/wordpress/site.py install-pro \
  /Volumes/Scratch/remote-content-shortcode/tmp/wordpress-local/packages/remote-content-shortcode-pro-2.0.zip
python3 -B tools/wordpress/site.py seed
python3 -B tools/wordpress/site.py check
```

Seeding updates the example posts without duplicating them. Published examples:

- http://localhost:8088/free-shortcode/ selects the product site's live heading.
- http://localhost:8088/free-block/ renders the same source through the block.
- http://localhost:8088/free-fallback/ safely refuses a private destination.
- http://localhost:8088/pro-source/ uses a saved source with a five-minute refresh.

`check` uses real HTTPS fetching and checks anonymous HTTP pages. It does not
intercept requests or mock the licensing service. Pro checks include saved-source
refresh, encrypted snapshot rendering and the registered schedule. WordPress
cron is visitor driven; use `wp cron event run --due-now` when testing it manually.

## Hosted Entitle

Production will use a single **Doublesharp** Entitle workspace, with Remote
Content Shortcode Pro and Validated Field as separate products. Another agent
is configuring that workspace. Its tenant API URL will be recorded here once
confirmed; the workspace name alone does not establish its slug or endpoint.

This local lab continues to use the existing sandbox below for test licenses,
update releases and expiry checks. Sandbox releases and credentials stay scoped
to that workspace.

Pro's license screen is at **Tools → Remote content → Pro license**. Enter the
workspace's tenant API, an issued key, and consent to activation. The equivalent
private CLI flow is:

```sh
python3 -B tools/wordpress/site.py activate \
  https://entitle.doublesharp.com/api/v1/tenants/WORKSPACE \
  /absolute/path/to/owner-only-license-key-file
python3 -B tools/wordpress/site.py wp plugin update remote-content-shortcode-pro
```

Hosted publisher keys stay outside WordPress and the ZIPs. Pro stores only the
installation token after activation. Expired licenses must deny metadata and
package access, including a previously offered download, while installed Free
and Pro features keep working. Use a separate test workspace for arbitrary
version/marker releases, leaving customer releases separate.

The normal WordPress plugin installer can later add ACF and Validated Field to
this same site. Their setup is future work; this lab currently exercises Remote
Content Shortcode and Pro.

## Configured sandbox and repeatable update checks

The hosted **Remote Content Shortcode → Sandbox** workspace is
`sandbox-f8ae9cec-32c4-4bb4-bc50-e67f4b6dccd6`. Its API is:

```text
https://entitle.doublesharp.com/api/v1/tenants/sandbox-f8ae9cec-32c4-4bb4-bc50-e67f4b6dccd6
```

The customer page is
https://entitle.doublesharp.com/app/t/sandbox-f8ae9cec-32c4-4bb4-bc50-e67f4b6dccd6.
Use `jsilver@doublesharp.com` for the complimentary test licenses. The ongoing
lab license code is in `.local-wordpress/license-key`. Its lifetime is 30 days
from November 3, 2026. The scoped publisher key is stored privately in
`.local-wordpress/entitle-publisher.json` and also expires after 30 days.

The sandbox product slug is `remote-content-shortcode-pro`, with basename
`remote-content-shortcode-pro/remote-content-shortcode-pro.php`. It has no Stripe
plans. Releases `2.0.1` and `2.0.2` add `RCS_LAB_RELEASE_MARKER`; `2.0.3` includes
the expiry-status fix. These are sandbox fixtures. Entitle requires stable
three-part release versions; the initial offline `2.0` package cannot be
published unchanged as an Entitle release.

Build another test release from a separately packaged Pro ZIP:

```sh
python3 -B tools/wordpress/build-test-release.py \
  /Volumes/Scratch/remote-content-shortcode/tmp/wordpress-local/packages/remote-content-shortcode-pro-2.0.zip \
  2.0.4 /Volumes/Scratch/remote-content-shortcode/tmp/wordpress-local/packages/remote-content-shortcode-pro-2.0.4.zip
```

Publish it to this sandbox using Entitle's seller Release screen or scoped
publisher CLI. Keep the release version, plugin header, `RCS_PRO_VERSION` and
readme stable tag identical. Use minimum WordPress `6.6`, PHP `8.2` and Free `2.0`.
Then run the native update command above and inspect `RCS_LAB_RELEASE_MARKER`.

For expiry, issue a separate short-lived complimentary license, activate it,
then cache the current offer **before** its actual expiry:

```sh
python3 -B tools/wordpress/site.py wp --user=admin eval-file \
  /workspace-tools/verify-license.php offer VERSION
# After server time passes the license expiry, the native update must fail.
python3 -B tools/wordpress/site.py wp --user=admin plugin update remote-content-shortcode-pro
python3 -B tools/wordpress/site.py wp --user=admin eval-file \
  /workspace-tools/verify-license.php expired
python3 -B tools/wordpress/site.py check
```

The expired check requires HTTP 403 for the previously offered package, denied
update metadata, no remaining update offer, and loaded Pro features. Restore
the ongoing license afterward using `activate` and `.local-wordpress/license-key`.
`test-license-status.php` separately checks the license screen's expiry wording
and restores the local expiry value in a `finally` block.

Validated on November 3, 2026: native `2.0 → 2.0.1` delivery, natural license
expiry rejecting the cached `2.0.2` package with HTTP 403, removal of the denied
update offer, continued Pro/Free rendering, and restored access updating to
`2.0.3`. The lab remains on its ongoing license. The Pro updater regression passed
on single-site and multisite WordPress; the broader coverage run reached its
five-minute deadline before coverage export completed.
