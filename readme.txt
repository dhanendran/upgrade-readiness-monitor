=== Upgrade Readiness Monitor ===
Contributors: dhanendran
Tags: deprecation, php compatibility, upgrade, developer, site health
Requires at least: 5.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Know what will break before you upgrade PHP or WordPress — real-time deprecation capture plus a plugin and theme compatibility audit.

== Description ==

Upgrade Readiness Monitor tells you whether a site is safe to upgrade **before** you bump PHP or WordPress — and exactly what to fix if it isn't.

It works in three complementary ways:

* **Real-time deprecation capture.** The plugin listens for WordPress's deprecation signals (`deprecated_function_run`, `deprecated_hook_run`, `doing_it_wrong_run`, and friends) as your site runs. Because those signals fire *regardless of `WP_DEBUG`*, you catch real deprecations on production without turning on debug mode — each one attributed to the plugin or theme that triggered it.

* **Plugin & theme audit.** A one-click scan checks every installed plugin and your active theme against WordPress.org for abandoned code (no update in 2+ years), "tested up to" lag, available updates, and declared PHP requirements.

* **Static code scan against your chosen target.** Every plugin and theme — including custom and premium code that isn't on WordPress.org — has its PHP parsed and checked against the WordPress and PHP versions you plan to upgrade **to**. Findings are graded **Error** (will break — e.g. a function removed in the target PHP version) or **Warning** (a deprecation notice you'll start seeing). The functions the target WordPress version deprecates are read from WordPress core itself, so results reflect the actual upgrade.

Everything rolls up into a single **green / amber / red** readiness verdict, and a target selector lets you choose exactly which WordPress and PHP versions you're upgrading to.

= Built for developers and agencies =

* No configuration — install, choose your target, and scan.
* Read-only and safe: it never changes your site.
* The audit runs in the background in small chunks, so it never blocks or slows a page load.
* **WP-CLI** included: `wp readiness check` prints the full report and exits non-zero on a red verdict, so you can gate upgrades in CI/staging.

= WP-CLI =

    # Full readiness report
    wp readiness check

    # Machine-readable output for pipelines
    wp readiness check --format=json

== External services ==

This plugin connects to WordPress.org to assess upgrade readiness. It does not contact any other third-party service and does not send any personal data.

1. **WordPress.org Plugins/Themes API** (api.wordpress.org) — for each installed plugin and theme, its slug is sent to look up the latest version, "tested up to" value, and last-updated date. Used during a scan and the weekly background scan.
2. **WordPress.org version list** (api.wordpress.org/core/stable-check) — fetches the list of available WordPress versions to populate the upgrade-target selector.
3. **WordPress.org core source** (core.svn.wordpress.org) — downloads the target WordPress version's public list of deprecated functions to compare against your code. Only the version number is part of the request.

All three are WordPress.org services, governed by the WordPress.org privacy policy (https://wordpress.org/about/privacy/).

== Installation ==

1. Upload the `upgrade-readiness-monitor` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin.
3. Go to **Tools → Upgrade Readiness**, choose your target WordPress/PHP versions, and click **Scan now**.

== Frequently Asked Questions ==

= Does it change anything on my site? =

No. It only reads and reports. Deprecation notices are captured passively and stored locally; the audit reads plugin/theme headers, public WordPress.org data, and your plugins'/theme's own PHP files.

= Do I need WP_DEBUG on? =

No. WordPress fires its deprecation hooks whether or not `WP_DEBUG` is enabled, so the monitor works on production.

= How accurate are the Error / Warning findings? =

"Error" findings are conservative and high-confidence: they come from a hand-verified list of PHP functions genuinely **removed** in the target PHP version (calling them is a fatal error). "Warning" findings are deprecations — code that still works but emits a notice on the target. The WordPress deprecation list for your target version is read from WordPress core itself.

The scan is precise rather than exhaustive: it reports problems it is confident about and never invents findings. It does not execute your code, so a clean result is not an absolute guarantee — always do a final test on staging — but a red verdict means there is real, specific breakage to fix first.

= What if the target version data can't be fetched? =

If WordPress.org can't be reached, the target-version deprecation check is simply skipped (you still get the PHP checks, metadata signals, and runtime capture). The plugin never fabricates results from missing data.

== Changelog ==

= 1.6.2 =
* [Fix] Renamed the main class to the D9URM prefix for full Plugin Check naming compliance; no functional change.

= 1.6.1 =
* [Fix] Renamed the WP-CLI class to use the plugin prefix (Plugin Check naming compliance); no functional change.

= 1.6.0 =
* [Fix] Scans could appear stuck at "0%" where WP-Cron doesn't fire promptly (low-traffic or local environments). The scan is now driven forward by the report page in bounded chunks, so it always progresses while the page is open; WP-Cron remains the unattended/weekly fallback.

= 1.5.0 =
* [Feature] Code is scanned against the version you're upgrading to, with findings graded Error (will break) vs Warning (deprecation). The static scan now runs for all plugins and themes.

= 1.4.0 =
* [Feature] WordPress and PHP target selectors — choose the version you're upgrading to. Defaults to the latest available WordPress version.

= 1.3.0 =
* [Feature] Local code scan for custom/premium plugins and themes. Live-refreshing deprecation list.

= 1.2.0 =
* [Feature] Theme auditing and a Type column. Reload-safe background scanning.

= 1.1.0 =
* [Fix] Memory and performance hardening: background processing, minimal API payloads, bounded deprecation capture.

= 1.0.0 =
* Initial release: real-time deprecation capture, plugin/theme compatibility audit, readiness verdict, and a `wp readiness check` WP-CLI command.

== Upgrade Notice ==

= 1.6.0 =
Scans now always progress on low-traffic and local sites.
