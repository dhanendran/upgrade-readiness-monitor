<?php
/**
 * Upgrade Readiness Monitor
 *
 * @package           D9_Upgrade_Readiness_Monitor
 * @author            D9 Labs
 * @copyright         2026 D9 Labs
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Upgrade Readiness Monitor
 * Plugin URI:        https://github.com/dhanendran/upgrade-readiness-monitor
 * Description:       Know before you upgrade. Captures deprecation notices in real time (even with WP_DEBUG off) and audits your plugins and theme for PHP/WordPress compatibility in the background — with a clear readiness verdict and a WP-CLI command for CI.
 * Version:           1.5.0
 * Author:            D9 Labs
 * Author URI:        https://d9labs.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       upgrade-readiness-monitor
 * Requires at least: 5.4
 * Requires PHP:      7.4
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

define( 'D9URM_VERSION', '1.5.0' );
define( 'D9URM_FILE', __FILE__ );
define( 'D9URM_SLUG', 'upgrade-readiness-monitor' );

define( 'D9URM_LOG_OPTION', 'd9urm_deprecation_log' );
define( 'D9URM_RESULTS_OPTION', 'd9urm_scan_results' );
define( 'D9URM_STATE_OPTION', 'd9urm_scan_state' );

define( 'D9URM_RESULTS_SCHEMA', 2 );      // Bump when the audit row shape changes (2 = adds "kind").
define( 'D9URM_LOG_CAP', 300 );          // Max distinct deprecation notices stored.
define( 'D9URM_CAPTURE_PER_REQUEST', 25 ); // Max new captures per request (perf guard).
define( 'D9URM_SCAN_CHUNK', 2 );          // Items audited per background tick (each now includes a code scan).
define( 'D9URM_SCAN_MAX_FILES', 800 );    // Max PHP files statically scanned per non-.org item.
define( 'D9URM_SCAN_MAX_FILESIZE', 524288 ); // Skip files larger than 512KB (likely minified/vendor).

define( 'D9URM_TARGETS_OPTION', 'd9urm_targets' ); // Stores the user-selected { wp, php } targets.
define( 'D9URM_DEFAULT_PHP', '8.5' );              // Fallback PHP target when none is chosen.

// D9URM_TARGET_PHP / D9URM_TARGET_WP may be defined in wp-config.php to force a
// fixed target (highest precedence). Otherwise the UI selection / latest applies.

/**
 * Core plugin class.
 *
 * @since 1.0.0
 */
class D9_Upgrade_Readiness_Monitor {

	/**
	 * Deprecation notices captured during the current request.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * How many notices we've captured this request (perf guard).
	 *
	 * @var int
	 */
	private $capture_count = 0;

	/**
	 * Re-entrancy guard so our own handler can never recurse into itself
	 * (e.g. if a call inside it triggers another deprecation/doing_it_wrong).
	 *
	 * @var bool
	 */
	private $recording = false;

	/**
	 * Boot the plugin.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		register_activation_hook( D9URM_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( D9URM_FILE, array( $this, 'deactivate' ) );
		register_uninstall_hook( D9URM_FILE, array( __CLASS__, 'uninstall' ) );

		// Real-time deprecation capture (fires regardless of WP_DEBUG).
		if ( $this->capture_enabled() ) {
			add_action( 'deprecated_function_run', array( $this, 'on_deprecated_function' ), 10, 3 );
			add_action( 'deprecated_argument_run', array( $this, 'on_deprecated_argument' ), 10, 3 );
			add_action( 'deprecated_hook_run', array( $this, 'on_deprecated_hook' ), 10, 4 );
			add_action( 'deprecated_file_included', array( $this, 'on_deprecated_file' ), 10, 4 );
			add_action( 'deprecated_constructor_run', array( $this, 'on_deprecated_constructor' ), 10, 3 );
			add_action( 'doing_it_wrong_run', array( $this, 'on_doing_it_wrong' ), 10, 3 );
			add_action( 'shutdown', array( $this, 'persist' ) );
		}

		// Background audit (WP-Cron).
		add_action( 'd9urm_run_scan_chunk', array( $this, 'run_scan_chunk' ) );
		add_action( 'd9urm_weekly_scan', array( $this, 'start_scan' ) );

		// Admin UI.
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( D9URM_FILE ), array( $this, 'action_links' ) );

		// AJAX (lightweight: start a background scan, poll status, clear log).
		add_action( 'wp_ajax_d9urm_start_scan', array( $this, 'ajax_start_scan' ) );
		add_action( 'wp_ajax_d9urm_scan_status', array( $this, 'ajax_scan_status' ) );
		add_action( 'wp_ajax_d9urm_clear', array( $this, 'ajax_clear' ) );
		add_action( 'wp_ajax_d9urm_deprecations', array( $this, 'ajax_deprecations' ) );
		add_action( 'wp_ajax_d9urm_set_target', array( $this, 'ajax_set_target' ) );
	}

	/**
	 * Whether deprecation capture is enabled.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	private function capture_enabled() {
		if ( defined( 'D9URM_DISABLE_CAPTURE' ) && D9URM_DISABLE_CAPTURE ) {
			return false;
		}
		return (bool) apply_filters( 'd9urm_capture_enabled', true );
	}

	/* ---------------------------------------------------------------------
	 * Activation / scheduling
	 * ------------------------------------------------------------------- */

	/**
	 * Schedule the weekly background scan on activation.
	 *
	 * @since 1.1.0
	 */
	public function activate() {
		if ( ! wp_next_scheduled( 'd9urm_weekly_scan' ) ) {
			wp_schedule_event( time() + 60, 'weekly', 'd9urm_weekly_scan' );
		}
	}

	/**
	 * Clear scheduled events on deactivation.
	 *
	 * @since 1.1.0
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'd9urm_weekly_scan' );
		wp_clear_scheduled_hook( 'd9urm_run_scan_chunk' );
	}

	/* ---------------------------------------------------------------------
	 * Deprecation capture (cheap + bounded)
	 * ------------------------------------------------------------------- */

	/**
	 * @since 1.0.0
	 * @param string $function    Deprecated function.
	 * @param string $replacement Replacement.
	 * @param string $version     Version.
	 */
	public function on_deprecated_function( $function, $replacement, $version ) {
		$this->record( 'function', $function, $replacement, $version );
	}

	/**
	 * @since 1.0.0
	 * @param string $function Function.
	 * @param string $message  Message.
	 * @param string $version  Version.
	 */
	public function on_deprecated_argument( $function, $message, $version ) {
		$this->record( 'argument', $function, (string) $message, $version );
	}

	/**
	 * @since 1.0.0
	 * @param string $hook        Hook.
	 * @param string $replacement Replacement.
	 * @param string $version     Version.
	 * @param string $message     Message.
	 */
	public function on_deprecated_hook( $hook, $replacement, $version, $message ) {
		$this->record( 'hook', $hook, trim( (string) $replacement . ' ' . (string) $message ), $version );
	}

	/**
	 * @since 1.0.0
	 * @param string $file        File.
	 * @param string $replacement Replacement.
	 * @param string $version     Version.
	 * @param string $message     Message.
	 */
	public function on_deprecated_file( $file, $replacement, $version, $message ) {
		$this->record( 'file', $file, trim( (string) $replacement . ' ' . (string) $message ), $version );
	}

	/**
	 * @since 1.0.0
	 * @param string $class   Class.
	 * @param string $version Version.
	 * @param string $parent  Parent class.
	 */
	public function on_deprecated_constructor( $class, $version, $parent ) {
		$this->record( 'constructor', $class, (string) $parent, $version );
	}

	/**
	 * @since 1.0.0
	 * @param string $function Function.
	 * @param string $message  Message.
	 * @param string $version  Version.
	 */
	public function on_doing_it_wrong( $function, $message, $version ) {
		$this->record( 'doing_it_wrong', $function, (string) $message, (string) $version );
	}

	/**
	 * Record a notice in memory (bounded), attributing it to its source.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type    Notice type.
	 * @param string $label   Function/hook/file/class name.
	 * @param string $message Replacement or message.
	 * @param string $version Version.
	 */
	private function record( $type, $label, $message, $version ) {
		// Re-entrancy guard + per-request cap. The guard is critical: nothing in
		// this method may call a function that could itself trigger another
		// deprecation/doing_it_wrong notice (which would re-enter this handler
		// and recurse). We also keep this path translation-free for the same
		// reason — values are stored raw and translated at display time.
		if ( $this->recording || $this->capture_count >= D9URM_CAPTURE_PER_REQUEST ) {
			return;
		}

		$this->recording = true;
		try {
			$source = $this->guess_source();
			$key    = md5( $type . '|' . $label . '|' . $version . '|' . $source['slug'] );

			if ( isset( $this->captured[ $key ] ) ) {
				$this->captured[ $key ]['count']++;
				return;
			}

			$this->capture_count++;
			$this->captured[ $key ] = array(
				'type'        => $type,
				'label'       => (string) $label,
				'message'     => (string) $message,
				'version'     => (string) $version,
				'source_slug' => $source['slug'],
				'source_name' => $source['name'],
				'source_type' => $source['type'],
				'count'       => 1,
			);
		} finally {
			$this->recording = false;
		}
	}

	/**
	 * Walk a frame-limited backtrace to attribute a notice to a plugin/theme.
	 *
	 * @since 1.0.0
	 *
	 * @return array { slug, name, type }
	 */
	private function guess_source() {
		// Raw values only — no translation calls in the capture hot path.
		$unknown = array(
			'slug' => 'unknown',
			'name' => '',
			'type' => 'unknown',
		);

		// Frame-limited + args ignored: cheap and bounded.
		$frames   = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$self_dir = wp_normalize_path( plugin_dir_path( D9URM_FILE ) );

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );

			if ( 0 === strpos( $file, $self_dir ) || false !== strpos( $file, '/wp-includes/' ) || false !== strpos( $file, '/wp-admin/' ) ) {
				continue;
			}

			if ( preg_match( '#/wp-content/(?:mu-plugins|plugins)/([^/]+)#', $file, $m ) ) {
				return array(
					'slug' => $m[1],
					'name' => $m[1],
					'type' => 'plugin',
				);
			}
			if ( preg_match( '#/wp-content/themes/([^/]+)#', $file, $m ) ) {
				return array(
					'slug' => $m[1],
					'name' => $m[1],
					'type' => 'theme',
				);
			}
		}

		return $unknown;
	}

	/**
	 * Persist on shutdown — but only when a NEW signature appears, so there
	 * are no database writes once a site's deprecations are known.
	 *
	 * @since 1.0.0
	 */
	public function persist() {
		if ( empty( $this->captured ) ) {
			return;
		}

		$log = get_option( D9URM_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$now   = time();
		$added = false;

		foreach ( $this->captured as $key => $entry ) {
			if ( isset( $log[ $key ] ) ) {
				continue; // Already known — skip to avoid steady-state writes.
			}
			$entry['first_seen'] = $now;
			$entry['last_seen']  = $now;
			$log[ $key ]         = $entry;
			$added               = true;
		}

		if ( ! $added ) {
			return;
		}

		if ( count( $log ) > D9URM_LOG_CAP ) {
			uasort(
				$log,
				static function ( $a, $b ) {
					return ( $b['last_seen'] ?? 0 ) <=> ( $a['last_seen'] ?? 0 );
				}
			);
			$log = array_slice( $log, 0, D9URM_LOG_CAP, true );
		}

		update_option( D9URM_LOG_OPTION, $log, false );
	}

	/* ---------------------------------------------------------------------
	 * Background audit engine
	 * ------------------------------------------------------------------- */

	/**
	 * Environment summary.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function environment() {
		$php_target = self::php_target();
		return array(
			'php_current' => PHP_VERSION,
			'php_target'  => $php_target,
			'php_ok'      => version_compare( PHP_VERSION, $php_target, '>=' ),
			'wp_current'  => get_bloginfo( 'version' ),
			'wp_target'   => self::wp_target(),
			'wp_debug'    => defined( 'WP_DEBUG' ) && WP_DEBUG,
		);
	}

	/**
	 * Start (or restart) a background scan.
	 *
	 * @since 1.1.0
	 */
	public function start_scan() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$state = get_option( D9URM_STATE_OPTION, array() );
		// Don't stack scans; allow restart if a previous run stalled (>10 min).
		if ( ! empty( $state['running'] ) && isset( $state['updated'] ) && ( time() - $state['updated'] ) < 600 ) {
			return;
		}

		$total = count( self::scan_items() );
		update_option(
			D9URM_STATE_OPTION,
			array(
				'running' => true,
				'offset'  => 0,
				'total'   => $total,
				'started' => time(),
				'updated' => time(),
				'rows'    => array(),
			),
			false
		);

		$this->schedule_chunk();
	}

	/**
	 * Schedule the next background chunk and nudge cron.
	 *
	 * @since 1.1.0
	 */
	private function schedule_chunk() {
		if ( ! wp_next_scheduled( 'd9urm_run_scan_chunk' ) ) {
			wp_schedule_single_event( time(), 'd9urm_run_scan_chunk' );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Process one small chunk of plugins in the background, then reschedule.
	 *
	 * Each chunk runs in its own request and audits only D9URM_SCAN_CHUNK
	 * plugins, so memory stays low regardless of how many plugins exist.
	 *
	 * @since 1.1.0
	 */
	public function run_scan_chunk() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$state = get_option( D9URM_STATE_OPTION, array() );
		if ( empty( $state['running'] ) ) {
			return;
		}

		$items   = self::scan_items();
		$plugins = get_plugins();
		$offset  = (int) $state['offset'];
		$slice   = array_slice( $items, $offset, D9URM_SCAN_CHUNK );

		foreach ( $slice as $item ) {
			$row = self::audit_item( $item, $plugins );
			if ( $row ) {
				$state['rows'][] = $row;
			}
		}

		$offset          += count( $slice );
		$state['offset']  = $offset;
		$state['updated'] = time();

		if ( $offset < $state['total'] && ! empty( $slice ) ) {
			update_option( D9URM_STATE_OPTION, $state, false );
			$this->schedule_chunk();
			return;
		}

		// Finished — store final results, mark idle.
		$rows = $state['rows'];
		update_option(
			D9URM_RESULTS_OPTION,
			array(
				'schema'       => D9URM_RESULTS_SCHEMA,
				'rows'         => $rows,
				'completed_at' => time(),
				'environment'  => self::environment(),
				'verdict'      => self::verdict( $rows, $this->deprecation_count() ),
			),
			false
		);
		update_option(
			D9URM_STATE_OPTION,
			array(
				'running' => false,
				'offset'  => $offset,
				'total'   => $state['total'],
				'updated' => time(),
			),
			false
		);
	}

	/**
	 * Number of distinct captured deprecation notices.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	private function deprecation_count() {
		$log = get_option( D9URM_LOG_OPTION, array() );
		return is_array( $log ) ? count( $log ) : 0;
	}

	/**
	 * Fetch (and cache) minimal wordpress.org metadata for a plugin slug.
	 *
	 * Uses plugins_api() with sections/reviews/etc. disabled, so the payload
	 * is a few fields rather than the full (potentially multi-MB) plugin page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Plugin slug.
	 * @return array|null { last_updated, tested, requires_php, version } or null.
	 */
	private static function org_plugin_info( $slug ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$cache_key = 'd9urm_org_' . md5( $slug );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$response = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					// Only what we need; everything heavy is disabled.
					'last_updated'      => true,
					'tested'            => true,
					'requires'          => true,
					'requires_php'      => true,
					'version'           => true,
					'sections'          => false,
					'description'       => false,
					'short_description' => false,
					'screenshots'       => false,
					'reviews'           => false,
					'banners'           => false,
					'icons'             => false,
					'versions'          => false,
					'contributors'      => false,
					'tags'              => false,
					'ratings'           => false,
					'support_threads'   => false,
				),
			)
		);

		$info = null;
		if ( ! is_wp_error( $response ) && is_object( $response ) ) {
			$info = array(
				'last_updated' => isset( $response->last_updated ) ? $response->last_updated : '',
				'tested'       => isset( $response->tested ) ? $response->tested : '',
				'requires_php' => isset( $response->requires_php ) ? $response->requires_php : '',
				'version'      => isset( $response->version ) ? $response->version : '',
			);
		}

		// Cache hits for 12h; "not found" (custom/premium) for 6h.
		set_transient( $cache_key, null === $info ? 'none' : $info, ( null === $info ? 6 : 12 ) * HOUR_IN_SECONDS );

		return $info;
	}

	/**
	 * Audit a single plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Plugin file (basename).
	 * @param array  $data Plugin header data.
	 * @return array
	 */
	public static function audit_plugin( $file, $data ) {
		$slug = ( false !== strpos( $file, '/' ) ) ? dirname( $file ) : basename( $file, '.php' );
		$org  = self::org_plugin_info( $slug );

		// Directory (or single file) to scan locally if it's not on WordPress.org.
		$path = ( false !== strpos( $file, '/' ) ) ? WP_PLUGIN_DIR . '/' . dirname( $file ) : WP_PLUGIN_DIR . '/' . $file;

		list( $status, $reasons ) = self::evaluate( $org, $data['Version'] ?? '', $data['RequiresPHP'] ?? '', $path );

		return array(
			'kind'    => 'plugin',
			'slug'    => $slug,
			'name'    => $data['Name'] ?? $slug,
			'version' => $data['Version'] ?? '',
			'active'  => is_plugin_active( $file ),
			'status'  => $status,
			'reasons' => $reasons,
		);
	}

	/**
	 * Audit the active theme (and its parent, for a child theme).
	 *
	 * @since 1.2.0
	 *
	 * @param string $stylesheet Theme directory (stylesheet) slug.
	 * @return array|null
	 */
	public static function audit_theme( $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return null;
		}

		$org = self::org_theme_info( $stylesheet );

		list( $status, $reasons ) = self::evaluate( $org, (string) $theme->get( 'Version' ), (string) $theme->get( 'RequiresPHP' ), $theme->get_stylesheet_directory() );

		return array(
			'kind'    => 'theme',
			'slug'    => $stylesheet,
			'name'    => $theme->get( 'Name' ) ? $theme->get( 'Name' ) : $stylesheet,
			'version' => (string) $theme->get( 'Version' ),
			'active'  => ( get_stylesheet() === $stylesheet || get_template() === $stylesheet ),
			'status'  => $status,
			'reasons' => $reasons,
		);
	}

	/**
	 * Shared status evaluation for a plugin or theme.
	 *
	 * @since 1.2.0
	 *
	 * @param array|null $org              WordPress.org info, or null if not found.
	 * @param string     $installed_version Installed version.
	 * @param string     $requires_php      Declared PHP requirement.
	 * @return array [ status, reasons ]
	 */
	private static function evaluate( $org, $installed_version, $requires_php, $dir = '' ) {
		$reasons = array();
		$status  = 'green';

		// 1) Plugin/theme declares a minimum PHP higher than the target (won't run).
		if ( $requires_php && version_compare( $requires_php, self::php_target(), '>' ) ) {
			$status    = 'red';
			$reasons[] = sprintf(
				/* translators: 1: required PHP, 2: target PHP */
				__( 'Error: requires PHP %1$s, above your target of %2$s', 'upgrade-readiness-monitor' ),
				$requires_php,
				self::php_target()
			);
		}

		// 2) WordPress.org metadata signals (hosted items only).
		if ( null !== $org ) {
			if ( ! empty( $org['last_updated'] ) ) {
				$ts = strtotime( $org['last_updated'] );
				if ( $ts && $ts < strtotime( '-2 years' ) ) {
					if ( 'red' !== $status ) {
						$status = 'amber';
					}
					$reasons[] = sprintf( /* translators: %s: date */ __( 'Warning: no update since %s (possibly abandoned)', 'upgrade-readiness-monitor' ), gmdate( 'Y-m-d', $ts ) );
				}
			}
			if ( ! empty( $org['tested'] ) && version_compare( $org['tested'], self::wp_target(), '<' ) ) {
				if ( 'red' !== $status ) {
					$status = 'amber';
				}
				$reasons[] = sprintf( /* translators: %s: WP version */ __( 'Warning: author tested only up to WordPress %s', 'upgrade-readiness-monitor' ), $org['tested'] );
			}
			if ( ! empty( $org['version'] ) && version_compare( $org['version'], $installed_version ? $installed_version : '0', '>' ) ) {
				if ( 'green' === $status ) {
					$status = 'amber';
				}
				$reasons[] = sprintf( /* translators: %s: version */ __( 'Info: update available (%s)', 'upgrade-readiness-monitor' ), $org['version'] );
			}
		} else {
			$reasons[] = __( 'Not on WordPress.org (custom/premium) — checked by local code scan.', 'upgrade-readiness-monitor' );
		}

		// 3) Static code scan against the selected targets (all items).
		if ( $dir ) {
			$scan = self::static_scan( $dir );
			foreach ( $scan['errors'] as $msg ) {
				$status    = 'red';
				$reasons[] = sprintf( /* translators: %s: message */ __( 'Error: %s', 'upgrade-readiness-monitor' ), $msg );
			}
			foreach ( $scan['warnings'] as $msg ) {
				if ( 'red' !== $status ) {
					$status = 'amber';
				}
				$reasons[] = sprintf( /* translators: %s: message */ __( 'Warning: %s', 'upgrade-readiness-monitor' ), $msg );
			}
			if ( ! empty( $scan['truncated'] ) ) {
				$reasons[] = __( 'Note: local scan was partial (large codebase).', 'upgrade-readiness-monitor' );
			}
		}

		// Keep rows readable.
		if ( count( $reasons ) > 15 ) {
			$extra   = count( $reasons ) - 15;
			$reasons = array_slice( $reasons, 0, 15 );
			$reasons[] = sprintf( /* translators: %d: count */ __( '…and %d more', 'upgrade-readiness-monitor' ), $extra );
		}

		if ( empty( $reasons ) ) {
			$reasons[] = __( 'No issues detected for the selected targets.', 'upgrade-readiness-monitor' );
		}

		return array( $status, $reasons );
	}

	/**
	 * Fetch (and cache) minimal wordpress.org metadata for a theme slug.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Theme slug.
	 * @return array|null
	 */
	private static function org_theme_info( $slug ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$cache_key = 'd9urm_orgtheme_' . md5( $slug );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$response = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'last_updated' => true,
					'requires'     => true,
					'requires_php' => true,
					'version'      => true,
					'sections'     => false,
					'description'  => false,
					'screenshots'  => false,
					'screenshot_url' => false,
					'ratings'      => false,
					'rating'       => false,
					'downloaded'   => false,
					'tags'         => false,
					'versions'     => false,
				),
			)
		);

		$info = null;
		if ( ! is_wp_error( $response ) && is_object( $response ) ) {
			$info = array(
				'last_updated' => isset( $response->last_updated ) ? $response->last_updated : '',
				'tested'       => isset( $response->tested ) ? $response->tested : '',
				'requires_php' => isset( $response->requires_php ) ? $response->requires_php : '',
				'version'      => isset( $response->version ) ? $response->version : '',
			);
		}

		set_transient( $cache_key, null === $info ? 'none' : $info, ( null === $info ? 6 : 12 ) * HOUR_IN_SECONDS );

		return $info;
	}

	/**
	 * Build the ordered list of items to audit (plugins + active/parent theme).
	 *
	 * @since 1.2.0
	 *
	 * @return array[] Each: { kind: plugin|theme, id }
	 */
	public static function scan_items() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$items = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			$items[] = array(
				'kind' => 'plugin',
				'id'   => $file,
			);
		}

		$theme = wp_get_theme();
		if ( $theme->exists() ) {
			$items[] = array(
				'kind' => 'theme',
				'id'   => $theme->get_stylesheet(),
			);
			$parent = $theme->parent();
			if ( $parent && $parent->exists() ) {
				$items[] = array(
					'kind' => 'theme',
					'id'   => $parent->get_stylesheet(),
				);
			}
		}

		return $items;
	}

	/**
	 * Audit a single scan item (plugin or theme).
	 *
	 * @since 1.2.0
	 *
	 * @param array      $item    { kind, id }
	 * @param array|null $plugins Optional get_plugins() map (avoids re-reading).
	 * @return array|null
	 */
	public static function audit_item( $item, $plugins = null ) {
		if ( 'theme' === $item['kind'] ) {
			return self::audit_theme( $item['id'] );
		}

		if ( null === $plugins ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins = get_plugins();
		}

		if ( ! isset( $plugins[ $item['id'] ] ) ) {
			return null;
		}

		return self::audit_plugin( $item['id'], $plugins[ $item['id'] ] );
	}

	/**
	 * Current WordPress major.minor.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function wp_major() {
		$parts = explode( '.', get_bloginfo( 'version' ) );
		return isset( $parts[1] ) ? $parts[0] . '.' . $parts[1] : $parts[0];
	}

	/**
	 * The WordPress version compatibility is checked against.
	 *
	 * Defaults to the site's current major.minor. Override with the
	 * D9URM_TARGET_WP constant or the `d9urm_target_wp` filter to check
	 * readiness against a specific (e.g. upcoming) release.
	 *
	 * @since 1.2.2
	 *
	 * @return string
	 */
	private static function wp_target() {
		if ( defined( 'D9URM_TARGET_WP' ) && D9URM_TARGET_WP ) {
			$target = D9URM_TARGET_WP;
		} else {
			$targets = get_option( D9URM_TARGETS_OPTION, array() );
			$target  = ! empty( $targets['wp'] ) ? $targets['wp'] : self::latest_wp();
		}
		return (string) apply_filters( 'd9urm_target_wp', $target );
	}

	/**
	 * The PHP version compatibility is checked against.
	 *
	 * Precedence: D9URM_TARGET_PHP constant > saved UI selection > default.
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	private static function php_target() {
		if ( defined( 'D9URM_TARGET_PHP' ) && D9URM_TARGET_PHP ) {
			$target = D9URM_TARGET_PHP;
		} else {
			$targets = get_option( D9URM_TARGETS_OPTION, array() );
			$target  = ! empty( $targets['php'] ) ? $targets['php'] : D9URM_DEFAULT_PHP;
		}
		return (string) apply_filters( 'd9urm_target_php', $target );
	}

	/**
	 * The latest available WordPress major.minor (for the default target).
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	private static function latest_wp() {
		$list = self::available_wp_versions();
		return ! empty( $list ) ? (string) end( $list ) : self::wp_major();
	}

	/**
	 * Available WordPress major.minor versions at or above the current one,
	 * for the upgrade-target selector. Cached; falls back to the current major.
	 *
	 * @since 1.4.0
	 *
	 * @return string[]
	 */
	public static function available_wp_versions() {
		$cached = get_transient( 'd9urm_wp_versions' );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$current = self::wp_major();
		$list    = array();

		$response = wp_remote_get( 'https://api.wordpress.org/core/stable-check/1.0/', array( 'timeout' => 8 ) );
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) ) {
				$majors = array();
				foreach ( array_keys( $data ) as $ver ) {
					$parts = explode( '.', (string) $ver );
					if ( count( $parts ) < 2 ) {
						continue;
					}
					$mm = $parts[0] . '.' . $parts[1];
					if ( version_compare( $mm, $current, '>=' ) ) {
						$majors[ $mm ] = true;
					}
				}
				$list = array_keys( $majors );
				usort( $list, 'version_compare' );
			}
		}

		if ( empty( $list ) ) {
			$list = array( $current );
		}

		set_transient( 'd9urm_wp_versions', $list, 12 * HOUR_IN_SECONDS );
		return $list;
	}

	/**
	 * Selectable PHP target versions.
	 *
	 * @since 1.4.0
	 *
	 * @return string[]
	 */
	public static function available_php_versions() {
		return array( '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' );
	}

	/**
	 * Deprecated WordPress function names, parsed from core's own deprecated
	 * files so the list always matches the running version. Cached.
	 *
	 * @since 1.3.0
	 *
	 * @return array Map of lowercased function name => true.
	 */
	private static function deprecated_wp_functions() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cached = get_transient( 'd9urm_wp_deprecated' );
		if ( is_array( $cached ) ) {
			$cache = $cached;
			return $cache;
		}

		$files = array(
			ABSPATH . 'wp-includes/deprecated.php',
			ABSPATH . 'wp-includes/pluggable-deprecated.php',
			ABSPATH . 'wp-includes/ms-deprecated.php',
			ABSPATH . 'wp-admin/includes/deprecated.php',
			ABSPATH . 'wp-admin/includes/ms-deprecated.php',
		);

		$names = array();
		foreach ( array_unique( $files ) as $file ) {
			if ( is_readable( $file ) ) {
				$names += self::function_names_from_code( (string) file_get_contents( $file ) );
			}
		}

		$cache = $names;
		set_transient( 'd9urm_wp_deprecated', $names, 12 * HOUR_IN_SECONDS );
		return $cache;
	}

	/**
	 * Extract top-level function names defined in a chunk of PHP.
	 *
	 * Used to read function names out of WordPress core's deprecated.php files
	 * (every function defined there is, by definition, deprecated).
	 *
	 * @since 1.5.0
	 *
	 * @param string $code PHP source.
	 * @return array Map of lowercased function name => true.
	 */
	private static function function_names_from_code( $code ) {
		$names  = array();
		$tokens = @token_get_all( $code ); // phpcs:ignore
		$total  = count( $tokens );
		for ( $i = 0; $i < $total; $i++ ) {
			if ( is_array( $tokens[ $i ] ) && T_FUNCTION === $tokens[ $i ][0] ) {
				$j = $i + 1;
				while ( $j < $total && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					$j++;
				}
				if ( $j < $total && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
					$names[ strtolower( $tokens[ $j ][1] ) ] = true;
				}
			}
		}
		unset( $tokens );
		return $names;
	}

	/**
	 * Functions deprecated in the *target* WordPress version, fetched from
	 * WordPress core itself so we can flag what an upgrade will newly deprecate.
	 *
	 * Cached per target for a week; runs only in the background scan / CLI.
	 * Returns an empty set (graceful) if the target isn't newer or the fetch
	 * fails — we never invent findings.
	 *
	 * @since 1.5.0
	 *
	 * @return array Map of lowercased function name => true.
	 */
	private static function target_wp_deprecated_functions() {
		$target = self::wp_target();

		static $memo = array();
		if ( isset( $memo[ $target ] ) ) {
			return $memo[ $target ];
		}

		// Nothing newly deprecated if the target isn't above the current version.
		if ( ! version_compare( $target, self::wp_major(), '>' ) ) {
			$memo[ $target ] = array();
			return array();
		}

		$key    = 'd9urm_wp_dep_' . preg_replace( '/[^0-9.]/', '', $target );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			$memo[ $target ] = $cached;
			return $cached;
		}

		$names = array();
		$base  = 'https://core.svn.wordpress.org/tags/' . rawurlencode( $target ) . '/';
		$files = array(
			'wp-includes/deprecated.php',
			'wp-includes/pluggable-deprecated.php',
			'wp-admin/includes/deprecated.php',
		);
		foreach ( $files as $rel ) {
			$response = wp_remote_get( $base . $rel, array( 'timeout' => 12 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$names += self::function_names_from_code( (string) wp_remote_retrieve_body( $response ) );
		}

		// Cache for a week (released versions don't change). Empty result is
		// cached briefly so a transient network failure retries sooner.
		set_transient( $key, $names, empty( $names ) ? HOUR_IN_SECONDS : WEEK_IN_SECONDS );
		$memo[ $target ] = $names;
		return $names;
	}

	/**
	 * PHP functions removed or deprecated by version. Conservative and
	 * hand-verified against the PHP migration guides — we only assert breakage
	 * we're confident about, and clearly mark "removed" (fatal) vs "deprecated".
	 *
	 * @since 1.5.0
	 *
	 * @return array name => array( status: removed|deprecated, since: version )
	 */
	private static function php_function_map() {
		return array(
			// Removed — calling these is a fatal "undefined function" error.
			'create_function'          => array( 'status' => 'removed', 'since' => '8.0' ),
			'each'                     => array( 'status' => 'removed', 'since' => '8.0' ),
			'money_format'             => array( 'status' => 'removed', 'since' => '8.0' ),
			'convert_cyr_string'       => array( 'status' => 'removed', 'since' => '8.0' ),
			'ezmlm_hash'               => array( 'status' => 'removed', 'since' => '8.0' ),
			'get_magic_quotes_gpc'     => array( 'status' => 'removed', 'since' => '8.0' ),
			'get_magic_quotes_runtime' => array( 'status' => 'removed', 'since' => '8.0' ),
			'hebrevc'                  => array( 'status' => 'removed', 'since' => '8.0' ),
			'fgetss'                   => array( 'status' => 'removed', 'since' => '8.0' ),
			'gmp_random'               => array( 'status' => 'removed', 'since' => '8.0' ),
			'image2wbmp'               => array( 'status' => 'removed', 'since' => '8.0' ),
			'png2wbmp'                 => array( 'status' => 'removed', 'since' => '8.0' ),
			'wbmp2png'                 => array( 'status' => 'removed', 'since' => '8.0' ),
			'define_syslog_variables'  => array( 'status' => 'removed', 'since' => '8.0' ),
			'ldap_sort'                => array( 'status' => 'removed', 'since' => '8.0' ),
			'restore_include_path'     => array( 'status' => 'removed', 'since' => '8.0' ),
			// Deprecated — emits a deprecation notice on the target.
			'date_sunrise'             => array( 'status' => 'deprecated', 'since' => '8.1' ),
			'date_sunset'              => array( 'status' => 'deprecated', 'since' => '8.1' ),
			'strftime'                 => array( 'status' => 'deprecated', 'since' => '8.1' ),
			'gmstrftime'               => array( 'status' => 'deprecated', 'since' => '8.1' ),
			'strptime'                 => array( 'status' => 'deprecated', 'since' => '8.1' ),
			'utf8_encode'              => array( 'status' => 'deprecated', 'since' => '8.2' ),
			'utf8_decode'              => array( 'status' => 'deprecated', 'since' => '8.2' ),
		);
	}

	/**
	 * Statically scan a plugin/theme directory (or single file) for usage of
	 * deprecated WordPress or PHP functions. Bounded for safety.
	 *
	 * @since 1.3.0
	 *
	 * @param string $path Directory or file path.
	 * @return array { wp: string[], php: string[], files: int, truncated: bool }
	 */
	public static function static_scan( $path ) {
		$ctx = array(
			'php'        => self::php_function_map(),
			'wp_current' => self::deprecated_wp_functions(),
			'wp_target'  => self::target_wp_deprecated_functions(),
			'target_php' => self::php_target(),
			'target_wp'  => self::wp_target(),
		);

		$errors    = array();
		$warnings  = array();
		$files     = 0;
		$truncated = false;

		$targets = array();
		if ( is_file( $path ) ) {
			$targets[] = $path;
		} elseif ( is_dir( $path ) ) {
			$skip = array( 'vendor', 'node_modules', 'tests', 'test', 'dist', 'build', '.git' );
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
				);
				foreach ( $iterator as $entry ) {
					if ( $files >= D9URM_SCAN_MAX_FILES ) {
						$truncated = true;
						break;
					}
					if ( ! $entry->isFile() || 'php' !== strtolower( $entry->getExtension() ) ) {
						continue;
					}
					$full = wp_normalize_path( $entry->getPathname() );
					foreach ( $skip as $dir ) {
						if ( false !== strpos( $full, '/' . $dir . '/' ) ) {
							continue 2;
						}
					}
					$targets[] = $entry->getPathname();
					$files++;
				}
			} catch ( Exception $e ) {
				return array(
					'errors'    => array(),
					'warnings'  => array(),
					'truncated' => false,
				);
			}
		}

		foreach ( $targets as $file ) {
			if ( ! is_readable( $file ) || filesize( $file ) > D9URM_SCAN_MAX_FILESIZE ) {
				continue;
			}
			self::scan_code( (string) file_get_contents( $file ), $ctx, $errors, $warnings );
		}

		return array(
			'errors'    => array_values( $errors ),
			'warnings'  => array_values( $warnings ),
			'truncated' => $truncated,
		);
	}

	/**
	 * Tokenize code and classify deprecated/removed function *calls* against
	 * the selected targets. Populates $errors (fatal) and $warnings (notice),
	 * keyed by signature to de-duplicate.
	 *
	 * @since 1.3.0
	 *
	 * @param string $code     Source code.
	 * @param array  $ctx      Scan context (maps + targets).
	 * @param array  $errors   Accumulator (by reference).
	 * @param array  $warnings Accumulator (by reference).
	 */
	private static function scan_code( $code, $ctx, &$errors, &$warnings ) {
		$tokens    = @token_get_all( $code ); // phpcs:ignore
		$total     = count( $tokens );
		$skip_prev = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW );
		$skip_ws   = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );

		for ( $i = 0; $i < $total; $i++ ) {
			$tok = $tokens[ $i ];
			if ( ! is_array( $tok ) || T_STRING !== $tok[0] ) {
				continue;
			}
			$name = strtolower( $tok[1] );

			$in_php       = isset( $ctx['php'][ $name ] );
			$newly_wp_dep = isset( $ctx['wp_target'][ $name ] ) && ! isset( $ctx['wp_current'][ $name ] );
			if ( ! $in_php && ! $newly_wp_dep ) {
				continue;
			}

			// Previous significant token: skip method calls / definitions / new.
			$p = $i - 1;
			while ( $p >= 0 && is_array( $tokens[ $p ] ) && in_array( $tokens[ $p ][0], $skip_ws, true ) ) {
				$p--;
			}
			if ( $p >= 0 && is_array( $tokens[ $p ] ) && in_array( $tokens[ $p ][0], $skip_prev, true ) ) {
				continue;
			}

			// Next significant token must be "(" to be a function call.
			$n = $i + 1;
			while ( $n < $total && is_array( $tokens[ $n ] ) && in_array( $tokens[ $n ][0], $skip_ws, true ) ) {
				$n++;
			}
			if ( $n >= $total || '(' !== $tokens[ $n ] ) {
				continue;
			}

			if ( $in_php ) {
				$info = $ctx['php'][ $name ];
				if ( version_compare( $ctx['target_php'], $info['since'], '>=' ) ) {
					if ( 'removed' === $info['status'] ) {
						$errors[ 'php:' . $name ] = sprintf(
							/* translators: 1: function, 2: PHP version */
							__( '%1$s() was removed in PHP %2$s (will fatal)', 'upgrade-readiness-monitor' ),
							$name,
							$info['since']
						);
					} else {
						$warnings[ 'php:' . $name ] = sprintf(
							/* translators: 1: function, 2: PHP version */
							__( '%1$s() is deprecated as of PHP %2$s', 'upgrade-readiness-monitor' ),
							$name,
							$info['since']
						);
					}
				}
			}

			if ( $newly_wp_dep ) {
				$warnings[ 'wp:' . $name ] = sprintf(
					/* translators: 1: function, 2: WordPress version */
					__( '%1$s() is deprecated in WordPress %2$s', 'upgrade-readiness-monitor' ),
					$name,
					$ctx['target_wp']
				);
			}
		}
		unset( $tokens );
	}

	/**
	 * Get the deprecation log, sorted by most-recently-seen.
	 *
	 * @since 1.3.0
	 *
	 * @return array
	 */
	private static function get_deprecation_log() {
		$log = get_option( D9URM_LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		uasort(
			$log,
			static function ( $a, $b ) {
				return ( $b['last_seen'] ?? 0 ) <=> ( $a['last_seen'] ?? 0 );
			}
		);
		return $log;
	}

	/**
	 * Synchronous full audit (WP-CLI only — devs accept the runtime, and the
	 * field-restricted API keeps memory low).
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function audit_all() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$rows    = array();
		foreach ( self::scan_items() as $item ) {
			$row = self::audit_item( $item, $plugins );
			if ( $row ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Compute an overall verdict.
	 *
	 * @since 1.0.0
	 *
	 * @param array $rows         Audit rows.
	 * @param int   $deprecations Distinct deprecation notices.
	 * @return string green|amber|red
	 */
	public static function verdict( $rows, $deprecations ) {
		$verdict = self::environment()['php_ok'] ? 'green' : 'amber';

		foreach ( (array) $rows as $row ) {
			if ( 'red' === $row['status'] ) {
				return 'red';
			}
			if ( 'amber' === $row['status'] && 'green' === $verdict ) {
				$verdict = 'amber';
			}
		}

		if ( $deprecations > 0 && 'green' === $verdict ) {
			$verdict = 'amber';
		}

		return $verdict;
	}

	/* ---------------------------------------------------------------------
	 * AJAX (lightweight only)
	 * ------------------------------------------------------------------- */

	/**
	 * Kick off a background scan.
	 *
	 * @since 1.1.0
	 */
	public function ajax_start_scan() {
		check_ajax_referer( 'd9urm', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'upgrade-readiness-monitor' ) ), 403 );
		}
		$this->start_scan();
		wp_send_json_success( get_option( D9URM_STATE_OPTION, array() ) );
	}

	/**
	 * Return scan progress + results (for polling).
	 *
	 * @since 1.1.0
	 */
	public function ajax_scan_status() {
		check_ajax_referer( 'd9urm', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'upgrade-readiness-monitor' ) ), 403 );
		}
		wp_send_json_success(
			array(
				'state'   => get_option( D9URM_STATE_OPTION, array() ),
				'results' => get_option( D9URM_RESULTS_OPTION, array() ),
			)
		);
	}

	/**
	 * Clear the captured deprecation log.
	 *
	 * @since 1.0.0
	 */
	public function ajax_clear() {
		check_ajax_referer( 'd9urm', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'upgrade-readiness-monitor' ) ), 403 );
		}
		delete_option( D9URM_LOG_OPTION );
		wp_send_json_success();
	}

	/**
	 * Return the current deprecation section markup (for live refresh).
	 *
	 * @since 1.3.0
	 */
	public function ajax_deprecations() {
		check_ajax_referer( 'd9urm', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'upgrade-readiness-monitor' ) ), 403 );
		}
		wp_send_json_success( array( 'html' => $this->deprecation_section_html() ) );
	}

	/**
	 * Save the selected upgrade targets (WordPress / PHP).
	 *
	 * @since 1.4.0
	 */
	public function ajax_set_target() {
		check_ajax_referer( 'd9urm', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'upgrade-readiness-monitor' ) ), 403 );
		}

		$wp  = isset( $_POST['wp'] ) ? sanitize_text_field( wp_unslash( $_POST['wp'] ) ) : '';
		$php = isset( $_POST['php'] ) ? sanitize_text_field( wp_unslash( $_POST['php'] ) ) : '';

		$targets = get_option( D9URM_TARGETS_OPTION, array() );
		if ( ! is_array( $targets ) ) {
			$targets = array();
		}
		if ( in_array( $wp, self::available_wp_versions(), true ) ) {
			$targets['wp'] = $wp;
		}
		if ( in_array( $php, self::available_php_versions(), true ) ) {
			$targets['php'] = $php;
		}
		update_option( D9URM_TARGETS_OPTION, $targets, false );

		wp_send_json_success( $targets );
	}

	/**
	 * Build the deprecation-notices section (used on first render and refresh).
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	private function deprecation_section_html() {
		$log = self::get_deprecation_log();

		ob_start();
		if ( empty( $log ) ) {
			echo '<p id="d9urm-no-deprecations">' . esc_html__( 'No deprecation notices captured yet. Keep browsing your site and admin — anything deprecated will show up here, then use Refresh.', 'upgrade-readiness-monitor' ) . '</p>';
			echo '<p><button type="button" class="button" id="d9urm-refresh-dep">' . esc_html__( 'Refresh', 'upgrade-readiness-monitor' ) . '</button></p>';
		} else {
			echo '<p>';
			echo '<button type="button" class="button" id="d9urm-clear">' . esc_html__( 'Clear log', 'upgrade-readiness-monitor' ) . '</button> ';
			echo '<button type="button" class="button" id="d9urm-refresh-dep">' . esc_html__( 'Refresh', 'upgrade-readiness-monitor' ) . '</button>';
			echo '</p>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Source', 'upgrade-readiness-monitor' ) . '</th>';
			echo '<th>' . esc_html__( 'Type', 'upgrade-readiness-monitor' ) . '</th>';
			echo '<th>' . esc_html__( 'What', 'upgrade-readiness-monitor' ) . '</th>';
			echo '<th>' . esc_html__( 'Since', 'upgrade-readiness-monitor' ) . '</th>';
			echo '<th>' . esc_html__( 'Hits', 'upgrade-readiness-monitor' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $log as $entry ) {
				$source = ( '' !== $entry['source_name'] ) ? $entry['source_name'] : __( 'Unknown / core', 'upgrade-readiness-monitor' );
				echo '<tr>';
				echo '<td>' . esc_html( $source ) . ' <code>' . esc_html( $entry['source_type'] ) . '</code></td>';
				echo '<td>' . esc_html( $entry['type'] ) . '</td>';
				echo '<td><code>' . esc_html( $entry['label'] ) . '</code>';
				if ( ! empty( $entry['message'] ) ) {
					echo '<br /><small>' . esc_html( wp_strip_all_tags( $entry['message'] ) ) . '</small>';
				}
				echo '</td>';
				echo '<td>' . esc_html( $entry['version'] ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $entry['count'] ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Admin UI
	 * ------------------------------------------------------------------- */

	/**
	 * Quick-access link on the Plugins list row.
	 *
	 * @since 1.0.0
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'tools.php?page=' . D9URM_SLUG ) ),
			esc_html__( 'View report', 'upgrade-readiness-monitor' )
		);
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Register the Tools submenu.
	 *
	 * @since 1.0.0
	 */
	public function add_menu() {
		add_management_page(
			esc_html__( 'Upgrade Readiness', 'upgrade-readiness-monitor' ),
			esc_html__( 'Upgrade Readiness', 'upgrade-readiness-monitor' ),
			'activate_plugins',
			D9URM_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets on our page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ) {
		if ( 'tools_page_' . D9URM_SLUG !== $hook ) {
			return;
		}

		$state = get_option( D9URM_STATE_OPTION, array() );

		wp_enqueue_script( 'jquery' );
		wp_localize_script(
			'jquery',
			'd9urmData',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'd9urm' ),
				'scanRunning' => ! empty( $state['running'] ),
				'types'       => array(
					'plugin' => esc_html__( 'Plugin', 'upgrade-readiness-monitor' ),
					'theme'  => esc_html__( 'Theme', 'upgrade-readiness-monitor' ),
				),
				'i18n'        => array(
					'scanning'   => esc_html__( 'Scanning in the background…', 'upgrade-readiness-monitor' ),
					'safeToLeave' => esc_html__( 'you can safely leave or reload this page.', 'upgrade-readiness-monitor' ),
					'scan'       => esc_html__( 'Scan now', 'upgrade-readiness-monitor' ),
					'error'      => esc_html__( 'Could not start the scan. Please try again.', 'upgrade-readiness-monitor' ),
					'confirm'    => esc_html__( 'Clear all captured deprecation notices?', 'upgrade-readiness-monitor' ),
					'done'       => esc_html__( 'Scan complete.', 'upgrade-readiness-monitor' ),
				),
			)
		);
		wp_add_inline_script( 'jquery', $this->inline_js() );

		wp_register_style( 'd9urm', false, array(), D9URM_VERSION );
		wp_enqueue_style( 'd9urm' );
		wp_add_inline_style( 'd9urm', '.d9urm-pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;vertical-align:middle;}.d9urm-green{background:#00a32a;}.d9urm-amber{background:#dba617;}.d9urm-red{background:#d63638;}' );
	}

	/**
	 * Render the admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'upgrade-readiness-monitor' ) );
		}

		$env     = self::environment();
		$results = get_option( D9URM_RESULTS_OPTION, array() );
		// Ignore results from an older row schema (e.g. before the Type column),
		// so an upgrade prompts a clean re-scan instead of showing mixed data.
		$fresh       = isset( $results['schema'] ) && (int) $results['schema'] >= D9URM_RESULTS_SCHEMA;
		$result_rows = $fresh ? ( $results['rows'] ?? array() ) : array();

		$wp_versions  = self::available_wp_versions();
		$php_versions = self::available_php_versions();
		// Make sure the current targets are always selectable.
		if ( ! in_array( $env['wp_target'], $wp_versions, true ) ) {
			$wp_versions[] = $env['wp_target'];
			usort( $wp_versions, 'version_compare' );
		}
		if ( ! in_array( $env['php_target'], $php_versions, true ) ) {
			$php_versions[] = $env['php_target'];
			usort( $php_versions, 'version_compare' );
		}
		?>
		<div class="wrap d9urm">
			<h1><?php esc_html_e( 'Upgrade Readiness', 'upgrade-readiness-monitor' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'See what will break before you upgrade PHP or WordPress. Deprecations are captured automatically as your site runs; the plugin audit runs in the background so it never slows your site.', 'upgrade-readiness-monitor' ); ?>
			</p>

			<h2><?php esc_html_e( 'Environment', 'upgrade-readiness-monitor' ); ?></h2>
			<table class="widefat striped" style="max-width:640px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'PHP version', 'upgrade-readiness-monitor' ); ?></strong></td>
						<td>
							<?php echo esc_html( $env['php_current'] ); ?>
							<span class="d9urm-pill d9urm-<?php echo $env['php_ok'] ? 'green' : 'amber'; ?>">
								<?php echo $env['php_ok'] ? esc_html__( 'OK', 'upgrade-readiness-monitor' ) : esc_html( sprintf( /* translators: %s: PHP version */ __( 'Below %s', 'upgrade-readiness-monitor' ), $env['php_target'] ) ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'WordPress version', 'upgrade-readiness-monitor' ); ?></strong></td>
						<td><?php echo esc_html( $env['wp_current'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Plugin & theme compatibility', 'upgrade-readiness-monitor' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Choose the versions you plan to upgrade to. Compatibility is checked against these targets:', 'upgrade-readiness-monitor' ); ?></p>
			<p id="d9urm-targets">
				<label for="d9urm-target-wp"><strong><?php esc_html_e( 'WordPress target:', 'upgrade-readiness-monitor' ); ?></strong></label>
				<select id="d9urm-target-wp">
					<?php foreach ( $wp_versions as $v ) : ?>
						<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $env['wp_target'], $v ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
				&nbsp;&nbsp;
				<label for="d9urm-target-php"><strong><?php esc_html_e( 'PHP target:', 'upgrade-readiness-monitor' ); ?></strong></label>
				<select id="d9urm-target-php">
					<?php foreach ( $php_versions as $v ) : ?>
						<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $env['php_target'], $v ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<button type="button" class="button button-primary" id="d9urm-scan"><?php esc_html_e( 'Scan now', 'upgrade-readiness-monitor' ); ?></button>
				<span id="d9urm-scan-status" style="margin-left:10px;"></span>
			</p>
			<p class="description">
				<?php esc_html_e( 'The scan runs in the background (it reads each plugin and theme\'s code against your selected targets) and includes your active theme. You can safely leave or reload this page while it runs.', 'upgrade-readiness-monitor' ); ?>
				<br />
				<strong><?php esc_html_e( 'Error', 'upgrade-readiness-monitor' ); ?></strong> <?php esc_html_e( '= will break on the target (e.g. a function removed in the target PHP).', 'upgrade-readiness-monitor' ); ?>
				&nbsp; <strong><?php esc_html_e( 'Warning', 'upgrade-readiness-monitor' ); ?></strong> <?php esc_html_e( '= a deprecation notice or upgrade risk to review.', 'upgrade-readiness-monitor' ); ?>
			</p>
			<?php if ( $fresh && ! empty( $results['completed_at'] ) ) : ?>
				<p class="description" id="d9urm-last-scan">
					<?php
					/* translators: %s: human time diff */
					echo esc_html( sprintf( __( 'Last scanned %s ago.', 'upgrade-readiness-monitor' ), human_time_diff( $results['completed_at'] ) ) );
					?>
				</p>
			<?php endif; ?>

			<table class="widefat striped" id="d9urm-scan-table" style="<?php echo empty( $result_rows ) ? 'display:none;' : ''; ?>">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Item', 'upgrade-readiness-monitor' ); ?></th>
						<th><?php esc_html_e( 'Type', 'upgrade-readiness-monitor' ); ?></th>
						<th><?php esc_html_e( 'Version', 'upgrade-readiness-monitor' ); ?></th>
						<th><?php esc_html_e( 'Status', 'upgrade-readiness-monitor' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'upgrade-readiness-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$type_labels = array(
						'plugin' => __( 'Plugin', 'upgrade-readiness-monitor' ),
						'theme'  => __( 'Theme', 'upgrade-readiness-monitor' ),
					);
					foreach ( $result_rows as $row ) :
						$kind = $row['kind'] ?? 'plugin';
						?>
						<tr>
							<td><?php echo esc_html( $row['name'] ); ?><?php echo $row['active'] ? '' : ' <em>(' . esc_html__( 'inactive', 'upgrade-readiness-monitor' ) . ')</em>'; ?></td>
							<td><?php echo esc_html( $type_labels[ $kind ] ?? $kind ); ?></td>
							<td><?php echo esc_html( $row['version'] ); ?></td>
							<td><span class="d9urm-pill d9urm-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( strtoupper( $row['status'] ) ); ?></span></td>
							<td><?php echo esc_html( implode( ' · ', $row['reasons'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Captured deprecation notices', 'upgrade-readiness-monitor' ); ?></h2>
			<div id="d9urm-deprecations-wrap">
				<?php
				// Built with escaping inside deprecation_section_html().
				echo $this->deprecation_section_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Inline admin JS: start background scan + poll status + clear log.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function inline_js() {
		return <<<'JS'
( function ( $ ) {
	'use strict';
	var d = window.d9urmData || {};
	var i18n = d.i18n || {};
	var poll = null;

	function esc( s ) { return $( '<div>' ).text( s == null ? '' : s ).html(); }
	function pill( s ) { return '<span class="d9urm-pill d9urm-' + esc( s ) + '">' + esc( ( s || '' ).toUpperCase() ) + '</span>'; }

	function renderRows( rows ) {
		var $body = $( '#d9urm-scan-table tbody' );
		$body.empty();
		var types = d.types || {};
		( rows || [] ).forEach( function ( row ) {
			// A missing kind means an old-schema row, which was always a plugin.
			var typeLabel = row.kind ? ( types[ row.kind ] || row.kind ) : ( types.plugin || 'Plugin' );
			$body.append(
				'<tr><td>' + esc( row.name ) + ( row.active ? '' : ' <em>(inactive)</em>' ) + '</td>' +
				'<td>' + esc( typeLabel ) + '</td>' +
				'<td>' + esc( row.version ) + '</td>' +
				'<td>' + pill( row.status ) + '</td>' +
				'<td>' + esc( ( row.reasons || [] ).join( ' · ' ) ) + '</td></tr>'
			);
		} );
		if ( rows && rows.length ) { $( '#d9urm-scan-table' ).show(); }
	}

	function setStatus( txt ) { $( '#d9urm-scan-status' ).text( txt || '' ); }

	function startPolling() {
		if ( poll ) { return; }
		poll = setInterval( function () {
			$.post( d.ajaxUrl, { action: 'd9urm_scan_status', nonce: d.nonce } ).done( function ( resp ) {
				if ( ! resp || ! resp.success ) { return; }
				var state = resp.data.state || {};
				var results = resp.data.results || {};
				if ( state.running ) {
					var pct = state.total ? Math.round( ( state.offset || 0 ) / state.total * 100 ) : 0;
					setStatus( i18n.scanning + ' ' + pct + '% — ' + i18n.safeToLeave );
				} else {
					clearInterval( poll ); poll = null;
					setStatus( i18n.done );
					$( '#d9urm-scan' ).prop( 'disabled', false );
					renderRows( results.rows );
					refreshDeprecations();
				}
			} );
		}, 2500 );
	}

	function scan() {
		$( '#d9urm-scan' ).prop( 'disabled', true );
		setStatus( i18n.scanning );
		$.post( d.ajaxUrl, { action: 'd9urm_start_scan', nonce: d.nonce } ).done( function ( resp ) {
			if ( ! resp || ! resp.success ) { setStatus( i18n.error ); $( '#d9urm-scan' ).prop( 'disabled', false ); return; }
			startPolling();
		} ).fail( function () {
			setStatus( i18n.error ); $( '#d9urm-scan' ).prop( 'disabled', false );
		} );
	}

	function refreshDeprecations() {
		$.post( d.ajaxUrl, { action: 'd9urm_deprecations', nonce: d.nonce } ).done( function ( resp ) {
			if ( resp && resp.success && resp.data ) {
				$( '#d9urm-deprecations-wrap' ).html( resp.data.html );
			}
		} );
	}

	function setTarget() {
		$.post( d.ajaxUrl, {
			action: 'd9urm_set_target',
			nonce: d.nonce,
			wp: $( '#d9urm-target-wp' ).val(),
			php: $( '#d9urm-target-php' ).val()
		} ).done( function () {
			scan(); // Targets changed — re-run the audit against them.
		} );
	}

	function clearLog() {
		if ( ! window.confirm( i18n.confirm ) ) { return; }
		$.post( d.ajaxUrl, { action: 'd9urm_clear', nonce: d.nonce } ).done( function () {
			refreshDeprecations();
		} );
	}

	$( function () {
		$( '#d9urm-scan' ).on( 'click', scan );
		$( '#d9urm-target-wp, #d9urm-target-php' ).on( 'change', setTarget );
		$( document ).on( 'click', '#d9urm-clear', clearLog );
		$( document ).on( 'click', '#d9urm-refresh-dep', refreshDeprecations );

		// Pick up notices captured since the page was rendered.
		refreshDeprecations();

		// If a scan is already running (e.g. the page was reloaded mid-scan),
		// reattach to it and resume showing progress.
		if ( d.scanRunning ) {
			$( '#d9urm-scan' ).prop( 'disabled', true );
			setStatus( i18n.scanning + ' — ' + i18n.safeToLeave );
			startPolling();
		}
	} );
} )( jQuery );
JS;
	}

	/* ---------------------------------------------------------------------
	 * Uninstall
	 * ------------------------------------------------------------------- */

	/**
	 * Clean up on uninstall.
	 *
	 * @since 1.0.0
	 */
	public static function uninstall() {
		global $wpdb;
		delete_option( D9URM_LOG_OPTION );
		delete_option( D9URM_RESULTS_OPTION );
		delete_option( D9URM_STATE_OPTION );
		delete_option( D9URM_TARGETS_OPTION );
		delete_transient( 'd9urm_wp_versions' );
		delete_transient( 'd9urm_wp_deprecated' );
		wp_clear_scheduled_hook( 'd9urm_weekly_scan' );
		wp_clear_scheduled_hook( 'd9urm_run_scan_chunk' );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_d9urm_%' OR option_name LIKE '_transient_timeout_d9urm_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

$d9urm = new D9_Upgrade_Readiness_Monitor();
$d9urm->init();

/**
 * WP-CLI command.
 *
 * @since 1.0.0
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Report whether the site is ready to upgrade PHP / WordPress.
	 */
	class D9_URM_CLI {

		/**
		 * Show an upgrade-readiness report.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format for the plugin table.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - csv
		 *   - json
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp readiness check
		 *     wp readiness check --format=json
		 *
		 * @when after_wp_load
		 *
		 * @param array $args       Positional args.
		 * @param array $assoc_args Flags.
		 */
		public function check( $args, $assoc_args ) {
			$format = $assoc_args['format'] ?? 'table';

			$env = D9_Upgrade_Readiness_Monitor::environment();
			WP_CLI::log( sprintf( 'Checking compatibility against PHP %s and WordPress %s.', $env['php_target'], $env['wp_target'] ) );
			WP_CLI::log( sprintf( 'PHP: %s (target %s) — %s', $env['php_current'], $env['php_target'], $env['php_ok'] ? 'OK' : 'below recommended' ) );
			WP_CLI::log( sprintf( 'WordPress: %s', $env['wp_current'] ) );

			$rows  = D9_Upgrade_Readiness_Monitor::audit_all();
			$table = array();
			foreach ( $rows as $row ) {
				$table[] = array(
					'plugin'  => $row['name'],
					'version' => $row['version'],
					'status'  => $row['status'],
					'notes'   => implode( '; ', $row['reasons'] ),
				);
			}
			if ( ! empty( $table ) ) {
				WP_CLI\Utils\format_items( $format, $table, array( 'plugin', 'version', 'status', 'notes' ) );
			}

			$log          = get_option( D9URM_LOG_OPTION, array() );
			$deprecations = is_array( $log ) ? count( $log ) : 0;
			WP_CLI::log( sprintf( '%d distinct deprecation notice(s) captured so far.', $deprecations ) );

			$verdict = D9_Upgrade_Readiness_Monitor::verdict( $rows, $deprecations );
			if ( 'red' === $verdict ) {
				WP_CLI::error( 'Readiness: RED — issues must be resolved before upgrading.' );
			} elseif ( 'amber' === $verdict ) {
				WP_CLI::warning( 'Readiness: AMBER — review the items above before upgrading.' );
			} else {
				WP_CLI::success( 'Readiness: GREEN — no blocking issues detected.' );
			}
		}
	}

	WP_CLI::add_command( 'readiness', 'D9_URM_CLI' );
}
