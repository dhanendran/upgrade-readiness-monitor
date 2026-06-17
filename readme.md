# Upgrade Readiness Monitor
Contributors: dhanendran
Tags: deprecation, php compatibility, upgrade, developer, site health
Requires at least: 5.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Know what will break before you upgrade PHP or WordPress — real-time deprecation capture plus a plugin and theme compatibility audit.

== Description ==

Upgrade Readiness Monitor tells you whether a site is safe to upgrade **before** you bump PHP or WordPress — and exactly what to fix if it isn't.

It works in two complementary ways:

* **Real-time deprecation capture.** The plugin listens for WordPress's deprecation signals (`deprecated_function_run`, `deprecated_hook_run`, `doing_it_wrong_run`, and friends) as your site runs. Because those signals fire *regardless of `WP_DEBUG`*, you catch real deprecations on production without turning on debug mode — each one attributed to the plugin or theme that triggered it.
* **Plugin & theme audit.** A one-click scan checks every installed plugin against WordPress.org for abandoned code (no update in 2+ years), "tested up to" lag, available updates, and declared PHP requirements — and rolls everything into a single **green / amber / red** readiness verdict.

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

= 1.1.0 =
* [Fix] Resolved a memory-exhaustion risk: the plugin audit now requests only the minimal fields from WordPress.org (no full changelog/sections payloads) via `plugins_api()`.
* [Improvement] The plugin audit now runs entirely in the background via WP-Cron, in small chunks, so it never blocks or slows a page load — the admin screen polls for progress. A weekly background re-scan keeps results fresh.
* [Improvement] Deprecation capture is now bounded (frame-limited backtrace, a per-request cap) and only writes to the database when a new deprecation signature appears — no steady-state writes on normal traffic.
* [Improvement] Added a kill switch: define `D9URM_DISABLE_CAPTURE` (or filter `d9urm_capture_enabled`) to turn capture off.

= 1.0.0 =
* Initial release: real-time deprecation capture, plugin/theme compatibility audit with a readiness verdict, and a `wp readiness check` WP-CLI command.
