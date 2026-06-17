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
 * Description:       Know before you upgrade. Captures deprecation notices in real time (even with WP_DEBUG off) and audits your plugins and theme for PHP/WordPress compatibility, abandoned code, and available updates — with a clear readiness verdict and a WP-CLI command for CI.
 * Version:           1.0.0
 * Author:            D9 Labs
 * Author URI:        https://d9labs.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       upgrade-readiness-monitor
 * Requires at least: 5.2
 * Requires PHP:      7.4
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

define( 'D9URM_VERSION', '1.0.0' );
define( 'D9URM_FILE', __FILE__ );
define( 'D9URM_SLUG', 'upgrade-readiness-monitor' );
define( 'D9URM_LOG_OPTION', 'd9urm_deprecation_log' );
define( 'D9URM_LOG_CAP', 300 );

// The PHP version sites should be ready for (latest stable line).
if ( ! defined( 'D9URM_TARGET_PHP' ) ) {
	define( 'D9URM_TARGET_PHP', '8.5' );
}

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
	 * Whether anything new was captured this request (persist on shutdown).
	 *
	 * @var bool
	 */
	private $dirty = false;

	/**
	 * Boot the plugin.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		register_uninstall_hook( D9URM_FILE, array( __CLASS__, 'uninstall' ) );

		// Real-time deprecation capture. These actions fire regardless of
		// WP_DEBUG, so this works on production too.
		add_action( 'deprecated_function_run', array( $this, 'on_deprecated_function' ), 10, 3 );
		add_action( 'deprecated_argument_run', array( $this, 'on_deprecated_argument' ), 10, 3 );
		add_action( 'deprecated_hook_run', array( $this, 'on_deprecated_hook' ), 10, 4 );
		add_action( 'deprecated_file_included', array( $this, 'on_deprecated_file' ), 10, 4 );
		add_action( 'deprecated_constructor_run', array( $this, 'on_deprecated_constructor' ), 10, 3 );
		add_action( 'doing_it_wrong_run', array( $this, 'on_doing_it_wrong' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'persist' ) );

		// Admin UI.
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		// AJAX.
		add_action( 'wp_ajax_d9urm_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_d9urm_clear', array( $this, 'ajax_clear' ) );
	}

	/* ---------------------------------------------------------------------
	 * Deprecation capture
	 * ------------------------------------------------------------------- */

	/**
	 * @since 1.0.0
	 * @param string $function    Deprecated function.
	 * @param string $replacement Suggested replacement.
	 * @param string $version     Version deprecated.
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
		$this->record( 'hook', $hook, trim( $replacement . ' ' . (string) $message ), $version );
	}

	/**
	 * @since 1.0.0
	 * @param string $file        File.
	 * @param string $replacement Replacement.
	 * @param string $version     Version.
	 * @param string $message     Message.
	 */
	public function on_deprecated_file( $file, $replacement, $version, $message ) {
		$this->record( 'file', $file, trim( $replacement . ' ' . (string) $message ), $version );
	}

	/**
	 * @since 1.0.0
	 * @param string $class   Class name.
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
	 * Record a single notice in memory, attributing it to its source.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type    Notice type.
	 * @param string $label   Function/hook/file/class name.
	 * @param string $message Replacement or message.
	 * @param string $version Version.
	 */
	private function record( $type, $label, $message, $version ) {
		$source = $this->guess_source();
		$key    = md5( $type . '|' . $label . '|' . $version . '|' . $source['slug'] );

		if ( isset( $this->captured[ $key ] ) ) {
			$this->captured[ $key ]['count']++;
		} else {
			$this->captured[ $key ] = array(
				'type'        => $type,
				'label'       => (string) $label,
				'message'     => $message,
				'version'     => (string) $version,
				'source_slug' => $source['slug'],
				'source_name' => $source['name'],
				'source_type' => $source['type'],
				'count'       => 1,
			);
		}
		$this->dirty = true;
	}

	/**
	 * Walk the backtrace to attribute a notice to a plugin or theme.
	 *
	 * @since 1.0.0
	 *
	 * @return array { slug, name, type }
	 */
	private function guess_source() {
		$unknown = array(
			'slug' => 'unknown',
			'name' => __( 'Unknown / core', 'upgrade-readiness-monitor' ),
			'type' => 'unknown',
		);

		if ( ! function_exists( 'debug_backtrace' ) ) {
			return $unknown;
		}

		$frames    = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$self_dir  = wp_normalize_path( plugin_dir_path( D9URM_FILE ) );
		$plugin_re = '#/wp-content/(?:mu-plugins|plugins)/([^/]+)#';
		$theme_re  = '#/wp-content/themes/([^/]+)#';

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );

			// Skip our own plugin and WordPress core files.
			if ( 0 === strpos( $file, $self_dir ) || false !== strpos( $file, '/wp-includes/' ) || false !== strpos( $file, '/wp-admin/' ) ) {
				continue;
			}

			if ( preg_match( $plugin_re, $file, $m ) ) {
				return array(
					'slug' => $m[1],
					'name' => $m[1],
					'type' => 'plugin',
				);
			}
			if ( preg_match( $theme_re, $file, $m ) ) {
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
	 * Persist newly captured notices on shutdown (only if something changed).
	 *
	 * @since 1.0.0
	 */
	public function persist() {
		if ( ! $this->dirty || empty( $this->captured ) ) {
			return;
		}

		$log = get_option( D9URM_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$now = time();
		foreach ( $this->captured as $key => $entry ) {
			if ( isset( $log[ $key ] ) ) {
				$log[ $key ]['count']    += $entry['count'];
				$log[ $key ]['last_seen'] = $now;
			} else {
				$entry['first_seen'] = $now;
				$entry['last_seen']  = $now;
				$log[ $key ]         = $entry;
			}
		}

		// Cap the log; drop the least-recently-seen entries first.
		if ( count( $log ) > D9URM_LOG_CAP ) {
			uasort(
				$log,
				static function ( $a, $b ) {
					return $b['last_seen'] <=> $a['last_seen'];
				}
			);
			$log = array_slice( $log, 0, D9URM_LOG_CAP, true );
		}

		update_option( D9URM_LOG_OPTION, $log, false );
	}

	/* ---------------------------------------------------------------------
	 * Audit engine (shared by admin AJAX and WP-CLI)
	 * ------------------------------------------------------------------- */

	/**
	 * Environment summary.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function environment() {
		return array(
			'php_current'    => PHP_VERSION,
			'php_target'     => D9URM_TARGET_PHP,
			'php_ok'         => version_compare( PHP_VERSION, D9URM_TARGET_PHP, '>=' ),
			'wp_current'     => get_bloginfo( 'version' ),
			'wp_debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
		);
	}

	/**
	 * Fetch (and cache) wordpress.org metadata for a plugin slug.
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

		$response = wp_remote_get(
			'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode( $slug ),
			array( 'timeout' => 8 )
		);

		$info = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && empty( $body['error'] ) ) {
				$info = array(
					'last_updated' => isset( $body['last_updated'] ) ? $body['last_updated'] : '',
					'tested'       => isset( $body['tested'] ) ? $body['tested'] : '',
					'requires_php' => isset( $body['requires_php'] ) ? $body['requires_php'] : '',
					'version'      => isset( $body['version'] ) ? $body['version'] : '',
				);
			}
		}

		// Cache positive results for 12h, "not found" for 6h to limit lookups.
		set_transient( $cache_key, null === $info ? 'none' : $info, null === $info ? 6 * HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );

		return $info;
	}

	/**
	 * Audit a single plugin and return a row with a status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Plugin file (basename).
	 * @param array  $data Plugin header data from get_plugins().
	 * @return array
	 */
	public static function audit_plugin( $file, $data ) {
		$slug = ( false !== strpos( $file, '/' ) ) ? dirname( $file ) : basename( $file, '.php' );
		$org  = self::org_plugin_info( $slug );

		$requires_php = $data['RequiresPHP'] ?? '';
		$reasons      = array();
		$status       = 'green';

		// Header-declared PHP requirement that exceeds the target line.
		if ( $requires_php && version_compare( $requires_php, D9URM_TARGET_PHP, '>' ) ) {
			$status    = 'red';
			$reasons[] = sprintf( /* translators: %s php version */ __( 'Requires PHP %s', 'upgrade-readiness-monitor' ), $requires_php );
		}

		$last_updated = $org['last_updated'] ?? '';
		$tested       = $org['tested'] ?? '';
		$latest       = $org['version'] ?? '';

		if ( null === $org ) {
			// Not found on wordpress.org (custom/premium) — can't verify.
			$status    = ( 'red' === $status ) ? 'red' : 'amber';
			$reasons[] = __( 'Not on WordPress.org — cannot verify; test manually.', 'upgrade-readiness-monitor' );
		} else {
			// Abandoned: no update in 2+ years.
			if ( $last_updated ) {
				$ts = strtotime( $last_updated );
				if ( $ts && $ts < strtotime( '-2 years' ) ) {
					$status    = 'red';
					$reasons[] = sprintf( /* translators: %s date */ __( 'No update since %s (likely abandoned)', 'upgrade-readiness-monitor' ), gmdate( 'Y-m-d', $ts ) );
				}
			}
			// Tested-up-to behind the current WP major.
			if ( $tested && version_compare( $tested, self::wp_major(), '<' ) ) {
				$status    = ( 'red' === $status ) ? 'red' : 'amber';
				$reasons[] = sprintf( /* translators: %s wp version */ __( 'Tested only up to WordPress %s', 'upgrade-readiness-monitor' ), $tested );
			}
			// Update available.
			if ( $latest && version_compare( $latest, (string) ( $data['Version'] ?? '0' ), '>' ) ) {
				if ( 'green' === $status ) {
					$status = 'amber';
				}
				$reasons[] = sprintf( /* translators: %s version */ __( 'Update available (%s)', 'upgrade-readiness-monitor' ), $latest );
			}
		}

		if ( empty( $reasons ) ) {
			$reasons[] = __( 'No issues detected.', 'upgrade-readiness-monitor' );
		}

		return array(
			'slug'     => $slug,
			'name'     => $data['Name'] ?? $slug,
			'version'  => $data['Version'] ?? '',
			'active'   => is_plugin_active( $file ),
			'status'   => $status,
			'reasons'  => $reasons,
		);
	}

	/**
	 * Current WordPress major.minor (e.g. "7.0").
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
	 * Audit every installed plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param int $offset Offset for batching.
	 * @param int $limit  Limit for batching (0 = all).
	 * @return array { rows, total, offset, limit }
	 */
	public static function audit_plugins( $offset = 0, $limit = 0 ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all   = get_plugins();
		$files = array_keys( $all );
		$total = count( $files );

		if ( $limit > 0 ) {
			$files = array_slice( $files, $offset, $limit );
		}

		$rows = array();
		foreach ( $files as $file ) {
			$rows[] = self::audit_plugin( $file, $all[ $file ] );
		}

		return array(
			'rows'   => $rows,
			'total'  => $total,
			'offset' => $offset,
			'limit'  => $limit,
		);
	}

	/**
	 * Compute an overall verdict from audit rows + the deprecation log.
	 *
	 * @since 1.0.0
	 *
	 * @param array $rows Plugin audit rows.
	 * @param int   $deprecations Number of distinct deprecation notices.
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
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * Scan a batch of plugins.
	 *
	 * @since 1.0.0
	 */
	public function ajax_scan() {
		check_ajax_referer( 'd9urm', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'upgrade-readiness-monitor' ) ), 403 );
		}

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? max( 1, absint( $_POST['limit'] ) ) : 5;

		wp_send_json_success( self::audit_plugins( $offset, $limit ) );
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

	/* ---------------------------------------------------------------------
	 * Admin UI
	 * ------------------------------------------------------------------- */

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

		wp_enqueue_script( 'jquery' );
		wp_localize_script(
			'jquery',
			'd9urmData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'd9urm' ),
				'batchSize' => 5,
				'i18n'      => array(
					'scanning'  => esc_html__( 'Scanning…', 'upgrade-readiness-monitor' ),
					'scan'      => esc_html__( 'Scan plugins', 'upgrade-readiness-monitor' ),
					'error'     => esc_html__( 'Scan failed. Please try again.', 'upgrade-readiness-monitor' ),
					'cleared'   => esc_html__( 'Deprecation log cleared.', 'upgrade-readiness-monitor' ),
					'confirm'   => esc_html__( 'Clear all captured deprecation notices?', 'upgrade-readiness-monitor' ),
				),
			)
		);
		wp_add_inline_script( 'jquery', $this->inline_js() );

		wp_register_style( 'd9urm', false, array(), D9URM_VERSION );
		wp_enqueue_style( 'd9urm' );
		wp_add_inline_style( 'd9urm', $this->inline_css() );
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

		$env = self::environment();
		$log = get_option( D9URM_LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		// Sort the log by most-recently-seen.
		uasort(
			$log,
			static function ( $a, $b ) {
				return ( $b['last_seen'] ?? 0 ) <=> ( $a['last_seen'] ?? 0 );
			}
		);
		?>
		<div class="wrap d9urm">
			<h1><?php esc_html_e( 'Upgrade Readiness', 'upgrade-readiness-monitor' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'See what will break before you upgrade PHP or WordPress. Deprecation notices are captured automatically as your site runs (even with WP_DEBUG off).', 'upgrade-readiness-monitor' ); ?>
			</p>

			<h2><?php esc_html_e( 'Environment', 'upgrade-readiness-monitor' ); ?></h2>
			<table class="widefat striped" style="max-width:640px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'PHP version', 'upgrade-readiness-monitor' ); ?></strong></td>
						<td>
							<?php echo esc_html( $env['php_current'] ); ?>
							<?php if ( $env['php_ok'] ) : ?>
								<span class="d9urm-pill d9urm-green"><?php esc_html_e( 'OK', 'upgrade-readiness-monitor' ); ?></span>
							<?php else : ?>
								<span class="d9urm-pill d9urm-amber">
									<?php
									/* translators: %s: target PHP version */
									echo esc_html( sprintf( __( 'Below recommended %s', 'upgrade-readiness-monitor' ), $env['php_target'] ) );
									?>
								</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'WordPress version', 'upgrade-readiness-monitor' ); ?></strong></td>
						<td><?php echo esc_html( $env['wp_current'] ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'WP_DEBUG', 'upgrade-readiness-monitor' ); ?></strong></td>
						<td><?php echo $env['wp_debug'] ? esc_html__( 'On', 'upgrade-readiness-monitor' ) : esc_html__( 'Off', 'upgrade-readiness-monitor' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Plugin & theme compatibility', 'upgrade-readiness-monitor' ); ?></h2>
			<p>
				<button type="button" class="button button-primary" id="d9urm-scan"><?php esc_html_e( 'Scan plugins', 'upgrade-readiness-monitor' ); ?></button>
				<span id="d9urm-scan-status" style="margin-left:10px;"></span>
			</p>
			<div id="d9urm-scan-progress" style="display:none;max-width:400px;background:#dcdcde;border-radius:3px;margin:10px 0;overflow:hidden;">
				<div id="d9urm-scan-bar" style="width:0;background:#2271b1;color:#fff;text-align:center;padding:4px 0;font-size:12px;">0%</div>
			</div>
			<table class="widefat striped" id="d9urm-scan-table" style="display:none;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Plugin', 'upgrade-readiness-monitor' ); ?></th>
						<th><?php esc_html_e( 'Version', 'upgrade-readiness-monitor' ); ?></th>
						<th><?php esc_html_e( 'Status', 'upgrade-readiness-monitor' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'upgrade-readiness-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Captured deprecation notices', 'upgrade-readiness-monitor' ); ?></h2>
			<?php if ( empty( $log ) ) : ?>
				<p id="d9urm-no-deprecations"><?php esc_html_e( 'No deprecation notices captured yet. Keep browsing your site and admin — anything deprecated will show up here.', 'upgrade-readiness-monitor' ); ?></p>
			<?php else : ?>
				<p>
					<button type="button" class="button" id="d9urm-clear"><?php esc_html_e( 'Clear log', 'upgrade-readiness-monitor' ); ?></button>
				</p>
				<table class="widefat striped" id="d9urm-deprecations">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Source', 'upgrade-readiness-monitor' ); ?></th>
							<th><?php esc_html_e( 'Type', 'upgrade-readiness-monitor' ); ?></th>
							<th><?php esc_html_e( 'What', 'upgrade-readiness-monitor' ); ?></th>
							<th><?php esc_html_e( 'Since', 'upgrade-readiness-monitor' ); ?></th>
							<th><?php esc_html_e( 'Hits', 'upgrade-readiness-monitor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['source_name'] ); ?> <code><?php echo esc_html( $entry['source_type'] ); ?></code></td>
								<td><?php echo esc_html( $entry['type'] ); ?></td>
								<td>
									<code><?php echo esc_html( $entry['label'] ); ?></code>
									<?php if ( ! empty( $entry['message'] ) ) : ?>
										<br /><small><?php echo esc_html( wp_strip_all_tags( $entry['message'] ) ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $entry['version'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $entry['count'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Inline CSS for status pills.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function inline_css() {
		return '.d9urm-pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;vertical-align:middle;}'
			. '.d9urm-green{background:#00a32a;}.d9urm-amber{background:#dba617;}.d9urm-red{background:#d63638;}';
	}

	/**
	 * Inline admin JS for the scan + clear actions.
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

	function esc( s ) { return $( '<div>' ).text( s == null ? '' : s ).html(); }

	function pill( status ) {
		return '<span class="d9urm-pill d9urm-' + esc( status ) + '">' + esc( status.toUpperCase() ) + '</span>';
	}

	function scan() {
		var $btn = $( '#d9urm-scan' ), $status = $( '#d9urm-scan-status' );
		var $progress = $( '#d9urm-scan-progress' ), $bar = $( '#d9urm-scan-bar' );
		var $table = $( '#d9urm-scan-table' ), $body = $table.find( 'tbody' );

		$btn.prop( 'disabled', true ).text( i18n.scanning );
		$status.text( '' );
		$body.empty();
		$table.hide();
		$bar.css( 'width', '0%' ).text( '0%' );
		$progress.show();

		var offset = 0, total = null;

		function step() {
			$.post( d.ajaxUrl, {
				action: 'd9urm_scan',
				nonce: d.nonce,
				offset: offset,
				limit: d.batchSize || 5
			} ).done( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					$status.text( i18n.error );
					finish();
					return;
				}
				var data = resp.data;
				total = data.total;
				( data.rows || [] ).forEach( function ( row ) {
					var notes = ( row.reasons || [] ).map( esc ).join( '<br>' );
					$body.append(
						'<tr><td>' + esc( row.name ) + ( row.active ? '' : ' <em>(' + 'inactive' + ')</em>' ) + '</td>' +
						'<td>' + esc( row.version ) + '</td>' +
						'<td>' + pill( row.status ) + '</td>' +
						'<td>' + notes + '</td></tr>'
					);
				} );
				$table.show();
				offset += ( d.batchSize || 5 );
				var pct = total ? Math.min( 100, Math.round( offset / total * 100 ) ) : 100;
				$bar.css( 'width', pct + '%' ).text( pct + '%' );
				if ( offset < total ) {
					step();
				} else {
					finish();
				}
			} ).fail( function () {
				$status.text( i18n.error );
				finish();
			} );
		}

		function finish() {
			$progress.hide();
			$btn.prop( 'disabled', false ).text( i18n.scan );
		}

		step();
	}

	function clearLog() {
		if ( ! window.confirm( i18n.confirm ) ) { return; }
		$.post( d.ajaxUrl, { action: 'd9urm_clear', nonce: d.nonce } ).done( function () {
			$( '#d9urm-deprecations, #d9urm-clear' ).remove();
			$( '#d9urm-scan' ).closest( '.wrap' ).find( 'h2' ).last()
				.after( '<p id="d9urm-no-deprecations">' + esc( i18n.cleared ) + '</p>' );
		} );
	}

	$( function () {
		$( '#d9urm-scan' ).on( 'click', scan );
		$( document ).on( 'click', '#d9urm-clear', clearLog );
	} );
} )( jQuery );
JS;
	}

	/* ---------------------------------------------------------------------
	 * Uninstall
	 * ------------------------------------------------------------------- */

	/**
	 * Clean up options/transients on uninstall.
	 *
	 * @since 1.0.0
	 */
	public static function uninstall() {
		global $wpdb;
		delete_option( D9URM_LOG_OPTION );
		// Remove cached wordpress.org lookups.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_d9urm_org_%' OR option_name LIKE '_transient_timeout_d9urm_org_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

$d9urm = new D9_Upgrade_Readiness_Monitor();
$d9urm->init();

/**
 * WP-CLI command: readiness.
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
			WP_CLI::log( sprintf( 'PHP: %s (target %s) — %s', $env['php_current'], $env['php_target'], $env['php_ok'] ? 'OK' : 'below recommended' ) );
			WP_CLI::log( sprintf( 'WordPress: %s', $env['wp_current'] ) );

			$audit = D9_Upgrade_Readiness_Monitor::audit_plugins();
			$rows  = array();
			foreach ( $audit['rows'] as $row ) {
				$rows[] = array(
					'plugin'  => $row['name'],
					'version' => $row['version'],
					'status'  => $row['status'],
					'notes'   => implode( '; ', $row['reasons'] ),
				);
			}
			if ( ! empty( $rows ) ) {
				WP_CLI\Utils\format_items( $format, $rows, array( 'plugin', 'version', 'status', 'notes' ) );
			}

			$log          = get_option( D9URM_LOG_OPTION, array() );
			$deprecations = is_array( $log ) ? count( $log ) : 0;
			WP_CLI::log( sprintf( '%d distinct deprecation notice(s) captured so far.', $deprecations ) );

			$verdict = D9_Upgrade_Readiness_Monitor::verdict( $audit['rows'], $deprecations );
			if ( 'red' === $verdict ) {
				WP_CLI::error( 'Readiness: RED — issues must be resolved before upgrading.', false );
				WP_CLI::halt( 1 );
			} elseif ( 'amber' === $verdict ) {
				WP_CLI::warning( 'Readiness: AMBER — review the items above before upgrading.' );
			} else {
				WP_CLI::success( 'Readiness: GREEN — no blocking issues detected.' );
			}
		}
	}

	WP_CLI::add_command( 'readiness', 'D9_URM_CLI' );
}
