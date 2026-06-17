# Upgrade Readiness Monitor
Contributors: dhanendran
Tags: deprecation, php compatibility, upgrade, developer, site health
Requires at least: 5.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Know what will break before you upgrade PHP or WordPress — real-time deprecation capture plus a plugin and theme compatibility audit.

== Description ==

Upgrade Readiness Monitor tells you whether a site is safe to upgrade **before** you bump PHP or WordPress — and exactly what to fix if it isn't.

It works in two complementary ways:

* **Real-time deprecation capture.** The plugin listens for WordPress's deprecation signals (`deprecated_function_run`, `deprecated_hook_run`, `doing_it_wrong_run`, and friends) as your site runs. Because those signals fire *regardless of `WP_DEBUG`*, you catch real deprecations on production without turning on debug mode — each one attributed to the plugin or theme that triggered it.
* **Plugin & theme audit.** A one-click scan checks every installed plugin and your active theme against WordPress.org for abandoned code (no update in 2+ years), "tested up to" lag, available updates, and declared PHP requirements — and rolls everything into a single **green / amber / red** readiness verdict.
* **Local scan for custom & premium code.** Plugins and themes that aren't on WordPress.org (custom builds, premium products) can't be checked against the directory — so they're scanned **locally** instead: their PHP is parsed for usage of deprecated WordPress functions (matched against your WordPress version's own deprecation list) and functions removed in recent PHP. This catches the very code most likely to break on an upgrade.

= Built for developers and agencies =

* No configuration — install, and it starts listening.
* Read-only and safe: it never changes your site, so it's low risk to run anywhere.
* **WP-CLI** included: `wp readiness check` prints the full report and exits non-zero on a red verdict, so you can gate upgrades in CI/staging.

= WP-CLI =

    # Full readiness report
    wp readiness check

    # Machine-readable output for pipelines
    wp readiness check --format=json

== Installation ==

1. Upload the `upgrade-readiness-monitor` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin.
3. Go to **Tools → Upgrade Readiness**.

== Frequently Asked Questions ==

= Does it change anything on my site? =

No. It only reads and reports. Deprecation notices are captured passively and stored locally; the plugin audit only reads plugin headers and public WordPress.org data.

= Do I need WP_DEBUG on? =

No. WordPress fires its deprecation hooks whether or not `WP_DEBUG` is enabled, so the monitor works on production.

= Will it catch everything? =

It catches deprecations that actually run, plus header/registry-based compatibility signals. As with any tool, runtime-only issues still warrant testing on staging — but you'll start every upgrade knowing far more than before.

== Changelog ==

= 1.3.0 =
* [Feature] Local code scan for plugins and themes that aren't on WordPress.org (custom/premium). Their PHP is statically scanned for deprecated WordPress functions (built from your WP version's own deprecation files) and functions removed in recent PHP — so the code most likely to break is no longer a blind spot.
* [Improvement] The captured deprecation notices now refresh live — automatically when a scan completes, on page load, and via a Refresh button — instead of only on a manual page reload.

= 1.2.2 =
* [Improvement] The report now states which versions compatibility is checked against (PHP target and WordPress target), shown in the admin and the WP-CLI output. The WordPress target can be overridden via the D9URM_TARGET_WP constant or the d9urm_target_wp filter.

= 1.2.1 =
* [Fix] The Type column could appear blank for some rows when results predated the 1.2.0 row format. Results are now schema-stamped (stale results trigger a clean re-scan) and a missing type safely falls back to "Plugin".

= 1.2.0 =
* [Feature] The audit now includes your active theme (and its parent, for child themes), not just plugins. Results show a Type column.
* [Improvement] Reloading the page while a scan is running now reattaches to the in-progress scan and resumes the progress display — the scan always continues in the background regardless, and the screen makes clear it's safe to leave or reload.

= 1.1.1 =
* [Fix] Fixed a fatal memory-exhaustion error on sites that emit many deprecation notices. The capture handler no longer calls translation functions (which could trigger a "translation loaded too early" notice and recurse into the handler), and a re-entrancy guard now makes recursion impossible.

= 1.1.0 =
* [Fix] Resolved a memory-exhaustion risk: the plugin audit now requests only the minimal fields from WordPress.org (no full changelog/sections payloads) via `plugins_api()`.
* [Improvement] The plugin audit now runs entirely in the background via WP-Cron, in small chunks, so it never blocks or slows a page load — the admin screen polls for progress. A weekly background re-scan keeps results fresh.
* [Improvement] Deprecation capture is now bounded (frame-limited backtrace, a per-request cap) and only writes to the database when a new deprecation signature appears — no steady-state writes on normal traffic.
* [Improvement] Added a kill switch: define `D9URM_DISABLE_CAPTURE` (or filter `d9urm_capture_enabled`) to turn capture off.

= 1.0.0 =
* Initial release: real-time deprecation capture, plugin/theme compatibility audit with a readiness verdict, and a `wp readiness check` WP-CLI command.
