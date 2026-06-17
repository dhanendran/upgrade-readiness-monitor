# Upgrade Readiness Monitor
Contributors: dhanendran
Tags: deprecation, php compatibility, upgrade, developer, site health
Requires at least: 5.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Know what will break before you upgrade PHP or WordPress — real-time deprecation capture plus a plugin and theme compatibility audit.

== Description ==

Upgrade Readiness Monitor tells you whether a site is safe to upgrade **before** you bump PHP or WordPress — and exactly what to fix if it isn't.

It works in two complementary ways:

* **Real-time deprecation capture.** The plugin listens for WordPress's deprecation signals (`deprecated_function_run`, `deprecated_hook_run`, `doing_it_wrong_run`, and friends) as your site runs. Because those signals fire *regardless of `WP_DEBUG`*, you catch real deprecations on production without turning on debug mode — each one attributed to the plugin or theme that triggered it.
* **Plugin & theme audit.** A one-click scan checks every installed plugin and your active theme against WordPress.org for abandoned code (no update in 2+ years), "tested up to" lag, available updates, and declared PHP requirements — and rolls everything into a single **green / amber / red** readiness verdict.
* **Static code scan against your chosen target.** Every plugin and theme — including custom and premium code that isn't on WordPress.org — has its PHP parsed and checked against the WordPress and PHP versions you plan to upgrade **to**. Findings are graded: **Error** (will break — e.g. a function removed in the target PHP version) or **Warning** (a deprecation notice you'll start seeing). The list of functions the target WordPress version deprecates is fetched from WordPress core itself, so it reflects the actual upgrade, not your current version.

= Built for developers and agencies =

* No configuration — install, and it starts listening.
* Read-only and safe: it never changes your site, so it's low risk to run anywhere.
* **WP-CLI** included: `wp readiness check` prints the full report and exits non-zero on a red verdict, so you can gate upgrades in CI/staging.

= WP-CLI =

    # Full readiness report
    wp readiness check

    # Machine-readable output for pipelines
    wp readiness check --format=json

== External services ==

This plugin connects to WordPress.org to assess upgrade readiness. It does not contact any other third-party service and does not send any personal data.

1. **WordPress.org Plugins/Themes API** (api.wordpress.org) — for each installed plugin and theme, its slug is sent to look up the latest version, "tested up to" value, and last-updated date. Used during a scan and the weekly background scan.
2. **WordPress.org version list** (api.wordpress.org/core/stable-check) — fetches the list of available WordPress versions for the upgrade-target selector.
3. **WordPress.org core source** (core.svn.wordpress.org) — downloads the target WordPress version's public list of deprecated functions to compare against your code. Only the version number is part of the request.

All three are WordPress.org services, governed by the WordPress.org privacy policy (https://wordpress.org/about/privacy/).

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

= How accurate are the Error / Warning findings? =

"Error" findings are conservative and high-confidence: they come from a hand-verified list of PHP functions that are genuinely **removed** in the target PHP version (calling them is a fatal error). "Warning" findings are deprecations — code that still works but emits a notice on the target. The WordPress deprecation list for your target version is fetched from WordPress core itself.

The scan is precise rather than exhaustive: it reports problems it is confident about and never invents findings. It does not execute your code, so a clean result is not an absolute guarantee — always do a final test on staging — but a red verdict means there is real, specific breakage to fix first.

= What if the target version data can't be fetched? =

If WordPress.org can't be reached, the target-version deprecation check is simply skipped (you'll still get the PHP checks, metadata signals, and runtime capture). The tool never fabricates results from missing data.

== Changelog ==

= 1.6.0 =
* [Fix] Scans could appear stuck at "0%" on sites where WP-Cron doesn't fire promptly (e.g. low-traffic or local environments). The scan is now driven forward by the report page itself in bounded chunks, so it always progresses while the page is open; WP-Cron remains the fallback for unattended weekly scans. A short lock prevents the two paths from colliding.

= 1.5.0 =
* [Feature] Code is now scanned **against the version you're upgrading to** and findings are graded **Error** (will break) vs **Warning** (deprecation). Errors come from a hand-verified list of functions removed in the target PHP version; warnings include functions the target WordPress version deprecates (fetched from WordPress core).
* [Improvement] The static code scan now runs for **all** plugins and themes, not only custom/premium ones — so a removed-PHP-function call is caught wherever it lives.
* [Improvement] WordPress deprecation warnings are limited to functions *newly* deprecated by the target version, keeping the report high-signal.

= 1.4.0 =
* [Feature] Pick the version you're upgrading **to**. WordPress and PHP target selectors let you choose the version to test compatibility against; changing a target saves it and re-runs the audit. The WordPress list is fetched from WordPress.org (versions at or above your current one).
* [Fix] The WordPress target now defaults to the **latest available** version instead of the version you're already running — so the audit reflects an actual upgrade, not the status quo.

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
