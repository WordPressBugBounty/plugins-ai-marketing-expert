<?php
/**
 * REST API base controller.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestApi {
	private const CACHE_GROUP = 'aime_rest_api';
	private const CACHE_TTL   = 30;

	/**
	 * Persistent home of the cache version counter.
	 *
	 * Kept in the options table so an object-cache miss, TTL expiry or flush
	 * cannot roll the version back and resurrect stale entries.
	 */
	private const CACHE_VERSION_OPTION = 'aime_rest_cache_version';

	/**
	 * Module Manager.
	 *
	 * @var ModuleManager
	 */
	private ModuleManager $modules;

	/**
	 * Constructor.
	 *
	 * @param ModuleManager $modules Module manager instance.
	 */
	public function __construct( ModuleManager $modules ) {
		$this->modules = $modules;
	}

	private static function get_cache_version(): string {
		$version = wp_cache_get( 'version', self::CACHE_GROUP );
		if ( false === $version ) {
			// Object cache missed — read the authoritative value from options so
			// we never fall back to a version an earlier bump already retired.
			$version = (string) get_option( self::CACHE_VERSION_OPTION, '' );
			if ( '' === $version ) {
				$version = '1';
				update_option( self::CACHE_VERSION_OPTION, $version, false );
			}
			wp_cache_set( 'version', $version, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return (string) $version;
	}

	private static function bump_cache_version(): void {
		// Monotonic counter: even if two concurrent writers read the same value
		// and land on the same bump, both retire every key built on the old one.
		$version = (string) ( (int) get_option( self::CACHE_VERSION_OPTION, '1' ) + 1 );
		update_option( self::CACHE_VERSION_OPTION, $version, false );
		wp_cache_set( 'version', $version, self::CACHE_GROUP, self::CACHE_TTL );
	}

	private static function build_cache_key( string $prefix, array $parts = array() ): string {
		return $prefix . ':' . md5( wp_json_encode( $parts ) . ':' . self::get_cache_version() );
	}

	/**
	 * Register all REST routes.
	 */
	public function register_routes(): void {
		$namespace = aime_rest_namespace();

		// Core routes.
		$this->register_core_routes( $namespace );

		// Module routes.
		foreach ( $this->modules->get_active() as $module ) {
			$module->register_routes();
		}
	}

	/**
	 * Register core API routes.
	 *
	 * @param string $namespace REST namespace.
	 */
	private function register_core_routes( string $namespace ): void {
		// GET /aime/v1/modules - List all modules.
		register_rest_route(
			$namespace,
			'/modules',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_modules' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// POST /aime/v1/modules/{id}/toggle - Toggle module active state.
		register_rest_route(
			$namespace,
			'/modules/(?P<id>[a-z0-9-]+)/toggle',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle_module' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// GET /aime/v1/settings - Get global settings.
		register_rest_route(
			$namespace,
			'/settings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// POST /aime/v1/settings - Update global settings.
		register_rest_route(
			$namespace,
			'/settings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// GET /aime/v1/debug-log - Fetch plugin logs (only when WP_DEBUG is on).
		register_rest_route(
			$namespace,
			'/debug-log',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_debug_log' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// DELETE /aime/v1/debug-log - Clear plugin logs.
		register_rest_route(
			$namespace,
			'/debug-log',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'clear_debug_log' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// GET /aime/v1/system/cron-status - Cron health for background jobs.
		register_rest_route(
			$namespace,
			'/system/cron-status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cron_status' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// POST /aime/v1/system/run-due-automations - Process due automation jobs now.
		register_rest_route(
			$namespace,
			'/system/run-due-automations',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_due_automations' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// GET /aime/v1/pro/status - Pro license status.
		register_rest_route(
			$namespace,
			'/pro/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_pro_status' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// GET /aime/v1/dashboard/stats - Overview stats.
		register_rest_route(
			$namespace,
			'/dashboard/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dashboard_stats' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// GET /aime/v1/dashboard/activity-trends - Per-module daily activity for the line chart.
		register_rest_route(
			$namespace,
			'/dashboard/activity-trends',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dashboard_activity_trends' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'days' => array(
						'default'           => 30,
						'sanitize_callback' => static function ( $value ) {
							return min( 90, max( 7, absint( $value ) ) );
						},
					),
				),
			)
		);

		// ── AI Provider Connections (connection-based repeater) ───────
		register_rest_route(
			$namespace,
			'/ai/connections',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ai_connections' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_ai_connection' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/ai/connections/(?P<id>[a-z0-9_]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_ai_connection' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/ai/connections/(?P<id>[a-z0-9_]+)/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_ai_connection' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/ai/connections/(?P<id>[a-z0-9_]+)/move',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'move_ai_connection' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// POST /aime/v1/ai/fetch-models — Fetch available models from provider API.
		register_rest_route(
			$namespace,
			'/ai/fetch-models',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'fetch_ai_models' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// ── Background AI jobs ────────────────────────────────────────
		register_rest_route(
			$namespace,
			'/ai/jobs',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_ai_jobs' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_ai_job' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/ai/jobs/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ai_job' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// ── AI usage summary ──────────────────────────────────────────
		register_rest_route(
			$namespace,
			'/ai/usage',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ai_usage' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'days' => array(
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// ── Settings import/export ────────────────────────────────────
		register_rest_route(
			$namespace,
			'/settings/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_settings' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		register_rest_route(
			$namespace,
			'/settings/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_settings' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// POST /aime/v1/api-key/generate — Generate or regenerate the global API key.
		register_rest_route(
			$namespace,
			'/api-key/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_api_key' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// DELETE /aime/v1/api-key — Revoke the API key.
		register_rest_route(
			$namespace,
			'/api-key',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'revoke_api_key' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);
	}

	/**
	 * Permission check: must be admin.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public function admin_permission_check( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this endpoint.', 'ai-marketing-expert' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /modules - List all modules.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_modules( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'modules' => $this->modules->get_dashboard_configs(),
			)
		);
	}

	/**
	 * POST /modules/{id}/toggle - Toggle module.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function toggle_module( \WP_REST_Request $request ) {
		$module_id = $request->get_param( 'id' );
		$module    = $this->modules->get( $module_id );

		if ( ! $module ) {
			return new \WP_Error(
				'module_not_found',
				__( 'Module not found.', 'ai-marketing-expert' ),
				array( 'status' => 404 )
			);
		}

		if ( $this->modules->is_active( $module_id ) ) {
			$this->modules->deactivate( $module_id );
			$is_active = false;
		} else {
			$this->modules->activate( $module_id );
			$is_active = true;
		}

		return new \WP_REST_Response(
			array(
				'module_id' => $module_id,
				'is_active' => $is_active,
				'message'   => $is_active
					? __( 'Module activated.', 'ai-marketing-expert' )
					: __( 'Module deactivated.', 'ai-marketing-expert' ),
			)
		);
	}

	/**
	 * GET /system/cron-status - Background job health.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_cron_status(): \WP_REST_Response {
		$hooks = array(
			'aime_minutely_tasks' => __( 'Background tasks (email queue + automations)', 'ai-marketing-expert' ),
		);

		$now   = time();
		$items = array();

		foreach ( $hooks as $hook => $label ) {
			$next_run     = wp_next_scheduled( $hook );
			$delay        = $next_run ? $now - $next_run : null;
			$is_overdue   = null !== $delay && $delay > 5 * MINUTE_IN_SECONDS;
			$items[]      = array(
				'hook'            => $hook,
				'label'           => $label,
				'next_run'        => $next_run ? gmdate( 'Y-m-d H:i:s', $next_run ) : null,
				'next_run_local'  => $next_run ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_run ), 'Y-m-d H:i:s' ) : null,
				'delay_seconds'   => $delay,
				'is_scheduled'    => (bool) $next_run,
				'is_overdue'      => $is_overdue,
				'status'          => ! $next_run ? 'missing' : ( $is_overdue ? 'overdue' : 'scheduled' ),
			);
		}

		return new \WP_REST_Response(
			array(
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'server_time'      => current_time( 'mysql' ),
				'gmt_time'         => current_time( 'mysql', true ),
				'items'            => $items,
				'has_overdue'      => (bool) array_filter( $items, static function ( $item ) {
					return ! empty( $item['is_overdue'] ) || 'missing' === $item['status'];
				} ),
				'documentation'    => __( 'For accurate automation timing, configure real server cron.', 'ai-marketing-expert' ),
			)
		);
	}

	/**
	 * POST /system/run-due-automations - Run due automation jobs now.
	 *
	 * @return \WP_REST_Response
	 */
	public function run_due_automations(): \WP_REST_Response {
		do_action( 'aime_process_automations' );

		return new \WP_REST_Response(
			array(
				'message' => __( 'Due automations processed.', 'ai-marketing-expert' ),
				'cron'    => $this->get_cron_status()->get_data(),
			)
		);
	}

	/**
	 * GET /settings - Get global settings.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$defaults = array(
			'from_name'        => get_bloginfo( 'name' ),
			'from_email'       => get_option( 'admin_email' ),
			'unsubscribe_page' => 0,
			'double_optin'     => (bool) get_option( 'aime_double_optin', false ),
			'track_opens'      => true,
			'track_clicks'     => true,
			'sending_method'   => 'wp_mail',
			'batch_size'       => 50,
			'batch_interval'   => 60,
			'gdpr_enabled'     => true,
			'delete_data_on_uninstall' => false,
		);
		$settings = wp_parse_args( aime_get_db_option( 'aime_settings', array() ), $defaults );

		// Remove sensitive data from response.
		unset( $settings['smtp_password'] );

		// Expose API key status (has key or not) and masked version.
		$settings['has_api_key'] = ! empty( $settings['webhook_api_key'] );
		if ( ! empty( $settings['webhook_api_key'] ) ) {
			$key = $settings['webhook_api_key'];
			$settings['api_key_masked'] = substr( $key, 0, 8 ) . str_repeat( '•', max( 0, strlen( $key ) - 12 ) ) . substr( $key, -4 );
		}
		unset( $settings['webhook_api_key'] );

		// Include the webhook URL for display.
		$settings['webhook_url'] = rest_url( aime_rest_namespace() . '/email/webhook/subscribe' );

		return new \WP_REST_Response( array( 'settings' => $settings ) );
	}

	/**
	 * POST /settings - Update global settings.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$params   = $request->get_json_params();
		$settings = get_option( 'aime_settings', array() );

		// Whitelist allowed settings.
		$allowed = array(
			'from_name',
			'from_email',
			'sending_method',
			'smtp_host',
			'smtp_port',
			'smtp_encryption',
			'smtp_username',
			'smtp_password',
			'batch_size',
			'batch_interval',
			'double_optin',
			'track_opens',
			'track_clicks',
			'gdpr_enabled',
			'delete_data_on_uninstall',
			'unsubscribe_page',
		);

		foreach ( $allowed as $key ) {
			if ( isset( $params[ $key ] ) ) {
				$settings[ $key ] = $this->sanitize_setting( $key, $params[ $key ] );
			}
		}

		if ( isset( $params['double_optin'] ) ) {
			update_option( 'aime_double_optin', (bool) $settings['double_optin'], false );
		}

		update_option( 'aime_settings', $settings, false );
		aime_clear_settings_cache( array( 'aime_settings', 'aime_double_optin' ) );

		aime_log( 'Global settings updated.', 'info', 'core' );

		return new \WP_REST_Response(
			array(
				'message'  => __( 'Settings saved successfully.', 'ai-marketing-expert' ),
				'settings' => $settings,
			)
		);
	}

	/**
	 * GET /pro/status - Pro license status.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_pro_status( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'has_pro'      => aime_has_pro(),
				'license_info' => apply_filters( 'aime_pro_license_info', array() ),
				'pro_url'      => apply_filters( 'aime_pro_url', 'https://wpthemespace.com/product/ai-marketing-expert/#aime-pricing' ),
			)
		);
	}

	/**
	 * GET /dashboard/stats - Overview dashboard stats.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_dashboard_stats( \WP_REST_Request $request ): \WP_REST_Response {
		$stats = array(
			'modules' => array(),
		);

		// Collect stats from each active module.
		foreach ( $this->modules->get_active() as $module ) {
			$stats['modules'][ $module->get_id() ] = apply_filters(
				"aime_{$module->get_id()}_dashboard_stats",
				array()
			);
		}

		return new \WP_REST_Response( $stats );
	}

	/**
	 * GET /dashboard/activity-trends - Per-module daily activity time-series.
	 *
	 * Returns an array of objects, one per day in the requested range, shaped:
	 * { date: "M/D", email: int, content: int, seo: int, chatbot: int, social: int }
	 *
	 * Only queries tables that actually exist to avoid errors on fresh installs.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_dashboard_activity_trends( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$days      = (int) $request->get_param( 'days' );
		$cache_key = self::build_cache_key( 'activity_trends', array( 'days' => $days ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return new \WP_REST_Response( $cached );
		}

		$p     = $wpdb->prefix;
		$start = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end   = gmdate( 'Y-m-d' );

		// Build a full date scaffold so every day is represented even with zero counts.
		$scaffold = array();
		$cursor   = strtotime( $start );
		$end_ts   = strtotime( $end );
		while ( $cursor <= $end_ts ) {
			$key              = gmdate( 'Y-m-d', $cursor );
			$scaffold[ $key ] = array(
				'date'    => gmdate( 'n/j', $cursor ),
				'email'   => 0,
				'content' => 0,
				'seo'     => 0,
				'chatbot' => 0,
				'social'  => 0,
			);
			$cursor += DAY_IN_SECONDS;
		}

		/**
		 * Table definitions: table name (without prefix), column to group by date, optional WHERE clause.
		 * Key in scaffold array matches the module key we store.
		 */
		$queries = array(
			'email'   => array(
				'table'  => 'aime_campaign_emails',
				'col'    => 'created_at',
				'where'  => "status = 'sent'",
			),
			'content' => array(
				'table'  => 'aime_content_articles',
				'col'    => 'created_at',
				'where'  => '',
			),
			'seo'     => array(
				'table'  => 'aime_seo_audits',
				'col'    => 'created_at',
				'where'  => '',
			),
			'chatbot' => array(
				'table'  => 'aime_chatbot_conversations',
				'col'    => 'created_at',
				'where'  => '',
			),
			'social'  => array(
				'table'  => 'aime_social_posts',
				'col'    => 'created_at',
				'where'  => '',
			),
		);

		foreach ( $queries as $module_key => $def ) {
			$table = $p . $def['table'];

			// Guard: skip if the table does not exist (module not installed/activated).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $table_exists !== $table ) {
				continue;
			}

			$col   = sanitize_key( $def['col'] );
			// Whitelist column names to prevent SQL injection.
			if ( ! in_array( $col, array( 'created_at', 'sent_at', 'updated_at' ), true ) ) {
				continue;
			}
			$where = '';
			if ( ! empty( $def['where'] ) ) {
				// The where clause is hardcoded above (not user input) — safe to use directly.
				$where = 'AND ' . $def['where'];
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE({$col}) AS day, COUNT(*) AS total FROM %i WHERE DATE({$col}) BETWEEN %s AND %s {$where} GROUP BY DATE({$col})",
					$table,
					$start,
					$end
				),
				ARRAY_A
			);

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					if ( isset( $scaffold[ $row['day'] ] ) ) {
						$scaffold[ $row['day'] ][ $module_key ] = (int) $row['total'];
					}
				}
			}
		}

		$result = array_values( $scaffold );
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return new \WP_REST_Response( $result );
	}

	/**
	 * GET /debug-log - Fetch plugin logs (only when WP_DEBUG is on).
	 */
	public function get_debug_log( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return new \WP_REST_Response( array( 'enabled' => false, 'logs' => array(), 'total' => 0 ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'aime_log';
		$table_cache_key = self::build_cache_key( 'debug_log_table', array( 'table' => $table ) );
		$table_exists    = wp_cache_get( $table_cache_key, self::CACHE_GROUP );
		if ( false === $table_exists ) {
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			wp_cache_set( $table_cache_key, $table_exists ?: '', self::CACHE_GROUP, self::CACHE_TTL );
		}

		if ( $table_exists !== $table ) {
			return new \WP_REST_Response( array( 'enabled' => true, 'logs' => array(), 'total' => 0 ) );
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = min( 100, max( 10, (int) $request->get_param( 'per_page' ) ?: 30 ) );
		$level    = sanitize_key( $request->get_param( 'level' ) ?: '' );
		$module   = sanitize_key( $request->get_param( 'module' ) ?: '' );
		$offset   = ( $page - 1 ) * $per_page;
		$cache_key = self::build_cache_key( 'debug_log_page', array(
			'page'     => $page,
			'per_page' => $per_page,
			'level'    => $level,
			'module'   => $module,
		) );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return new \WP_REST_Response( $cached );
		}

		if ( $level && $module ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE level = %s AND module_id = %s', $table, $level, $module ) );
			$logs = $wpdb->get_results( $wpdb->prepare( 'SELECT id, module_id, level, message, context, created_at FROM %i WHERE level = %s AND module_id = %s ORDER BY created_at DESC LIMIT %d OFFSET %d', $table, $level, $module, $per_page, $offset ), ARRAY_A );
		} elseif ( $level ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE level = %s', $table, $level ) );
			$logs = $wpdb->get_results( $wpdb->prepare( 'SELECT id, module_id, level, message, context, created_at FROM %i WHERE level = %s ORDER BY created_at DESC LIMIT %d OFFSET %d', $table, $level, $per_page, $offset ), ARRAY_A );
		} elseif ( $module ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE module_id = %s', $table, $module ) );
			$logs = $wpdb->get_results( $wpdb->prepare( 'SELECT id, module_id, level, message, context, created_at FROM %i WHERE module_id = %s ORDER BY created_at DESC LIMIT %d OFFSET %d', $table, $module, $per_page, $offset ), ARRAY_A );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			$logs = $wpdb->get_results( $wpdb->prepare( 'SELECT id, module_id, level, message, context, created_at FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d', $table, $per_page, $offset ), ARRAY_A );
		}

		$response = array( 'enabled' => true, 'logs' => $logs ?: array(), 'total' => $total );
		wp_cache_set( $cache_key, $response, self::CACHE_GROUP, self::CACHE_TTL );

		return new \WP_REST_Response( $response );
	}

	/**
	 * DELETE /debug-log - Clear all plugin logs.
	 */
	public function clear_debug_log( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$table = $wpdb->prefix . 'aime_log';
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
		self::bump_cache_version();
		return new \WP_REST_Response( array( 'message' => __( 'Logs cleared.', 'ai-marketing-expert' ) ) );
	}

	private function sanitize_setting( string $key, $value ) {
		switch ( $key ) {
			case 'from_email':
				return sanitize_email( $value );

			case 'smtp_username':
				return sanitize_text_field( $value );

			case 'smtp_port':
			case 'batch_size':
				return max( 1, min( 500, absint( $value ) ) );

			case 'batch_interval':
				return max( 0, min( DAY_IN_SECONDS, absint( $value ) ) );

			case 'unsubscribe_page':
				return absint( $value );

			case 'double_optin':
			case 'track_opens':
			case 'track_clicks':
			case 'gdpr_enabled':
				return (bool) $value;

			case 'sending_method':
				$valid = array( 'wp_mail', 'smtp', 'api', 'gmail', 'outlook', 'amazon_ses', 'sendgrid', 'mailgun', 'sparkpost', 'custom' );
				return in_array( $value, $valid, true ) ? $value : 'wp_mail';

			case 'smtp_encryption':
				return in_array( $value, array( 'none', 'ssl', 'tls' ), true )
					? $value
					: 'tls';

			default:
				return sanitize_text_field( $value );
		}
	}

	// ─── AI Settings ──────────────────────────────────────────────────

	/**
	 * GET /ai/connections — Get all AI connections + provider definitions.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_ai_connections( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( AiProvider::get_settings_for_api() );
	}

	/**
	 * POST /ai/connections — Create or update an AI connection.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function save_ai_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$id     = ! empty( $params['id'] ) ? sanitize_key( $params['id'] ) : '';
		$is_update = false;

		$connections = AiProvider::get_connections();
		if ( '' !== $id ) {
			foreach ( $connections as $connection ) {
				if ( $id === ( $connection['id'] ?? '' ) ) {
					$is_update = true;
					break;
				}
			}
		}

		if ( ! \aime_has_pro() && ! $is_update ) {
			$limits      = \aime_free_limits();
			$limit       = (int) ( $limits['ai_provider_connections'] ?? 2 );

			if ( count( $connections ) >= $limit ) {
				return new \WP_REST_Response(
					array(
						'message' => sprintf(
							/* translators: %d: number of free AI provider connections */
							__( 'AI provider limit reached. Free plan allows %d AI provider connections. Upgrade to Pro for unlimited providers.', 'ai-marketing-expert' ),
							$limit
						),
					),
					403
				);
			}
		}

		$conn = AiProvider::save_connection( $params );

		return new \WP_REST_Response( array(
			'message' => __( 'AI connection saved successfully.', 'ai-marketing-expert' ),
			'data'    => AiProvider::get_settings_for_api(),
		) );
	}

	/**
	 * DELETE /ai/connections/{id} — Delete an AI connection.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function delete_ai_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $request->get_param( 'id' );

		AiProvider::delete_connection( $id );

		return new \WP_REST_Response( array(
			'message' => __( 'AI connection deleted.', 'ai-marketing-expert' ),
			'data'    => AiProvider::get_settings_for_api(),
		) );
	}

	/**
	 * POST /ai/connections/{id}/test — Test an AI connection.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function test_ai_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $request->get_param( 'id' );

		$result = AiProvider::test_connection( $id );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	/**
	 * POST /ai/connections/{id}/move — Move a fallback connection up or down.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function move_ai_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$id        = sanitize_key( $request->get_param( 'id' ) );
		$params    = $request->get_json_params();
		$params    = is_array( $params ) ? $params : array();
		$direction = sanitize_key( $params['direction'] ?? '' );

		$connections = AiProvider::move_connection( $id, $direction );

		if ( null === $connections ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'AI provider order could not be changed.', 'ai-marketing-expert' ) ),
				400
			);
		}

		return new \WP_REST_Response( array(
			'message' => __( 'AI provider fallback order updated.', 'ai-marketing-expert' ),
			'data'    => AiProvider::get_settings_for_api(),
		) );
	}

	/**
	 * POST /ai/fetch-models — Fetch available models from a provider's API.
	 *
	 * Accepts either a raw api_key or a connection_id to look up the stored key.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function fetch_ai_models( \WP_REST_Request $request ): \WP_REST_Response {
		$params        = $request->get_json_params();
		$provider      = sanitize_key( $params['provider'] ?? '' );
		$api_key       = sanitize_text_field( $params['api_key'] ?? '' );
		$connection_id = sanitize_key( $params['connection_id'] ?? '' );
		$base_url      = ! empty( $params['base_url'] ) ? esc_url_raw( trim( $params['base_url'] ) ) : '';
		$api_format    = in_array( $params['api_format'] ?? '', array( 'openai', 'anthropic' ), true ) ? $params['api_format'] : '';

		// If no api_key supplied, try to use the stored key from an existing connection.
		if ( empty( $api_key ) && ! empty( $connection_id ) ) {
			$connections = AiProvider::get_connections();
			foreach ( $connections as $conn ) {
				if ( ( $conn['id'] ?? '' ) === $connection_id ) {
					$api_key = ! empty( $conn['api_key'] ) ? Encryption::decrypt( $conn['api_key'] ) : '';
					if ( empty( $provider ) ) {
						$provider = $conn['provider'] ?? '';
					}
					if ( empty( $base_url ) ) {
						$base_url = AiProvider::decrypt_maybe( $conn['base_url'] ?? '' );
					}
					if ( empty( $api_format ) ) {
						$api_format = $conn['api_format'] ?? '';
					}
					break;
				}
			}
		}

		if ( empty( $provider ) || empty( $api_key ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Provider and API key are required.', 'ai-marketing-expert' ) ),
				400
			);
		}

		// Cache the catalog per provider+key+endpoint; `refresh: true` bypasses.
		$cache_key = 'aime_models_' . md5( implode( '|', array( $provider, $base_url, $api_format, md5( $api_key ) ) ) );
		$refresh   = ! empty( $params['refresh'] );

		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached['success'] ) ) {
				$cached['cached'] = true;
				return new \WP_REST_Response( $cached, 200 );
			}
		}

		$result = AiProvider::fetch_remote_models( $provider, $api_key, $base_url, $api_format );

		if ( ! empty( $result['success'] ) ) {
			$result = $this->annotate_model_capabilities( $result );
			$result['fetched_at'] = time();
			set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
			$result['cached'] = false;
		}

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	/**
	 * Add a `capabilities` array to every model entry in a fetch-models result.
	 * A model listed under both text and image gets both capabilities.
	 *
	 * @param array $result fetch_remote_models() result with models.text / models.image.
	 * @return array
	 */
	private function annotate_model_capabilities( array $result ): array {
		$models = $result['models'] ?? array();
		$caps   = array();

		foreach ( array( 'text', 'image' ) as $type ) {
			foreach ( (array) ( $models[ $type ] ?? array() ) as $entry ) {
				$id = $entry['id'] ?? '';
				if ( '' === $id ) {
					continue;
				}
				$caps[ $id ][] = $type;
			}
		}

		foreach ( array( 'text', 'image' ) as $type ) {
			if ( empty( $models[ $type ] ) || ! is_array( $models[ $type ] ) ) {
				continue;
			}
			foreach ( $models[ $type ] as $i => $entry ) {
				$id = $entry['id'] ?? '';
				$models[ $type ][ $i ]['capabilities'] = array_values( array_unique( $caps[ $id ] ?? array( $type ) ) );
			}
		}

		$result['models'] = $models;
		return $result;
	}

	/* ── Background AI jobs ────────────────────────────────────────── */

	/**
	 * POST /ai/jobs — Queue a background AI generation job.
	 */
	public function create_ai_job( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$type   = sanitize_key( $params['type'] ?? 'generate' );

		// Free tier keeps a shallow queue; Pro is unlimited.
		if ( ! \aime_has_pro() ) {
			$limits = \aime_free_limits();
			$limit  = (int) ( $limits['ai_jobs_queued'] ?? 5 );

			if ( $limit > 0 && JobQueue::count_active() >= $limit ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => sprintf(
							/* translators: %d: number of background jobs allowed on the free plan */
							__( 'Background job limit reached. Free plan allows %d queued jobs at a time. Upgrade to Pro for unlimited jobs.', 'ai-marketing-expert' ),
							$limit
						),
					),
					403
				);
			}
		}

		$payload = array(
			'prompt'     => (string) ( $params['prompt'] ?? '' ),
			'task'       => sanitize_key( $params['task'] ?? 'text' ),
			'max_tokens' => absint( $params['max_tokens'] ?? 2048 ),
			'options'    => is_array( $params['options'] ?? null ) ? $params['options'] : array(),
			'title'      => sanitize_text_field( $params['title'] ?? '' ),
			'post_id'    => absint( $params['post_id'] ?? 0 ),
		);

		$job_id = JobQueue::enqueue( $type, $payload );

		if ( is_wp_error( $job_id ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => $job_id->get_error_message() ),
				400
			);
		}

		return new \WP_REST_Response( array(
			'success' => true,
			'job_id'  => $job_id,
			'status'  => 'pending',
		), 201 );
	}

	/**
	 * GET /ai/jobs/{id} — Job status and result.
	 */
	public function get_ai_job( \WP_REST_Request $request ) {
		$job = JobQueue::get( absint( $request->get_param( 'id' ) ) );

		if ( ! $job ) {
			return new \WP_Error( 'aime_job_not_found', __( 'Job not found.', 'ai-marketing-expert' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $job );
	}

	/**
	 * GET /ai/jobs — Recent jobs.
	 */
	public function list_ai_jobs( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array(
			'jobs' => JobQueue::get_recent( absint( $request->get_param( 'limit' ) ?: 20 ) ),
		) );
	}

	/* ── AI usage summary ──────────────────────────────────────────── */

	/**
	 * GET /ai/usage — Aggregated usage and cost estimates.
	 */
	public function get_ai_usage( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( UsageTracker::get_summary( absint( $request->get_param( 'days' ) ?: 30 ) ) );
	}

	/* ── Settings import/export ────────────────────────────────────── */

	/**
	 * Option names included in export/import. Secrets (webhook API key,
	 * AI connections with encrypted keys) are deliberately excluded.
	 */
	private function get_portable_options(): array {
		return (array) apply_filters( 'aime_portable_options', array(
			'aime_settings',
			'aime_chatbot_settings',
		) );
	}

	/**
	 * GET /settings/export — Portable JSON of plugin settings and email templates.
	 */
	public function export_settings(): \WP_REST_Response {
		global $wpdb;

		$options = array();
		foreach ( $this->get_portable_options() as $name ) {
			$value = get_option( $name, null );
			if ( null === $value ) {
				continue;
			}
			if ( 'aime_settings' === $name && is_array( $value ) ) {
				unset( $value['webhook_api_key'] ); // Never export secrets.
			}

			// Let modules strip their own secrets before export.
			if ( is_array( $value ) ) {
				$secret_keys = (array) apply_filters( 'aime_export_secret_keys', array(), $name );
				foreach ( $secret_keys as $secret_key ) {
					unset( $value[ $secret_key ] );
				}
			}

			$options[ $name ] = $value;
		}

		$templates_table = $wpdb->prefix . 'aime_templates';
		$templates       = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $templates_table ) ) === $templates_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$templates = (array) $wpdb->get_results( "SELECT * FROM {$templates_table}", ARRAY_A );
			foreach ( $templates as $i => $row ) {
				unset( $templates[ $i ]['id'] );
			}
			$templates = array_values( $templates );
		}

		return new \WP_REST_Response( array(
			'format'      => 'aime-settings-export',
			'version'     => 1,
			'plugin'      => AIME_VERSION,
			'exported_at' => gmdate( 'c' ),
			'site'        => home_url(),
			'options'     => $options,
			'templates'   => $templates,
		) );
	}

	/**
	 * POST /settings/import — Restore settings and templates from an export file.
	 */
	public function import_settings( \WP_REST_Request $request ) {
		global $wpdb;

		$data = $request->get_json_params();

		if ( ! is_array( $data ) || 'aime-settings-export' !== ( $data['format'] ?? '' ) ) {
			return new \WP_Error( 'aime_invalid_import', __( 'Invalid import file. Expected an AI Marketing Expert settings export.', 'ai-marketing-expert' ), array( 'status' => 400 ) );
		}

		$allowed  = $this->get_portable_options();
		$imported = array();

		foreach ( (array) ( $data['options'] ?? array() ) as $name => $value ) {
			if ( ! in_array( $name, $allowed, true ) || ! is_array( $value ) ) {
				continue;
			}

			if ( 'aime_settings' === $name ) {
				// Merge over existing so local secrets (webhook key) survive.
				$current = get_option( $name, array() );
				unset( $value['webhook_api_key'] );
				$value = array_merge( is_array( $current ) ? $current : array(), $value );
			}

			update_option( $name, $value );
			aime_clear_settings_cache( array( $name ), 'aime_chatbot_settings' === $name );
			$imported[] = $name;
		}

		$templates_added = 0;
		$templates_table = $wpdb->prefix . 'aime_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! empty( $data['templates'] ) && is_array( $data['templates'] )
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $templates_table ) ) === $templates_table ) {

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$columns = (array) $wpdb->get_col( "DESCRIBE {$templates_table}", 0 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing_names = (array) $wpdb->get_col( "SELECT name FROM {$templates_table}" );

			foreach ( $data['templates'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				// Only known columns; never trust an id from the file.
				$row = array_intersect_key( $row, array_flip( $columns ) );
				unset( $row['id'] );

				$name = (string) ( $row['name'] ?? '' );
				if ( '' === $name || in_array( $name, $existing_names, true ) ) {
					continue; // Skip duplicates by name.
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				if ( $wpdb->insert( $templates_table, $row ) ) {
					$existing_names[] = $name;
					++$templates_added;
				}
			}
		}

		aime_log( sprintf( 'Settings import: %d option groups, %d templates added.', count( $imported ), $templates_added ), 'info', 'core' );

		return new \WP_REST_Response( array(
			'success'          => true,
			'message'          => __( 'Import complete.', 'ai-marketing-expert' ),
			'options_imported' => $imported,
			'templates_added'  => $templates_added,
		) );
	}

	/* ── API Key Management ────────────────────────────────────────── */

	/**
	 * POST /api-key/generate — Generate or regenerate the global webhook API key.
	 *
	 * @return \WP_REST_Response
	 */
	public function generate_api_key(): \WP_REST_Response {
		$key      = 'aime_' . wp_generate_password( 32, false );
		$settings = get_option( 'aime_settings', array() );
		$settings['webhook_api_key'] = $key;
		update_option( 'aime_settings', $settings, false );
		aime_clear_settings_cache( array( 'aime_settings' ) );

		aime_log( 'Webhook API key generated.', 'info', 'core' );

		$masked = substr( $key, 0, 8 ) . str_repeat( '•', 21 ) . substr( $key, -4 );

		return new \WP_REST_Response( array(
			'message'    => __( 'API key generated.', 'ai-marketing-expert' ),
			'api_key'    => $key, // Show full key once on generation.
			'masked'     => $masked,
			'webhook_url' => rest_url( aime_rest_namespace() . '/email/webhook/subscribe' ),
			'signing'    => array(
				'description' => __( 'Recommended: sign requests instead of sending the key. Send X-Aime-Timestamp (unix seconds) and X-Aime-Signature: sha256=HEX where HEX = HMAC-SHA256("{timestamp}.{raw_body}", api_key). Plain X-API-Key header also accepted.', 'ai-marketing-expert' ),
				'headers'     => array( 'X-Aime-Timestamp', 'X-Aime-Signature' ),
				'algorithm'   => 'hmac-sha256',
				'payload'     => '{timestamp}.{raw_body}',
			),
		) );
	}

	/**
	 * DELETE /api-key — Revoke the API key.
	 *
	 * @return \WP_REST_Response
	 */
	public function revoke_api_key(): \WP_REST_Response {
		$settings = get_option( 'aime_settings', array() );
		unset( $settings['webhook_api_key'] );
		update_option( 'aime_settings', $settings, false );
		aime_clear_settings_cache( array( 'aime_settings' ) );

		aime_log( 'Webhook API key revoked.', 'info', 'core' );

		return new \WP_REST_Response( array(
			'message' => __( 'API key revoked.', 'ai-marketing-expert' ),
		) );
	}

	/**
	 * Validate webhook authentication.
	 *
	 * Two accepted schemes, sharing the same secret (the webhook API key):
	 *
	 * 1. HMAC signature (preferred — audit §11.8). The sender includes:
	 *      X-Aime-Timestamp: <unix seconds>
	 *      X-Aime-Signature: sha256=<hex hmac_sha256( "{timestamp}.{raw_body}", api_key )>
	 *    The timestamp must be within ±5 minutes and each signature is
	 *    accepted only once (replay cache), so a captured request cannot be
	 *    replayed and the key itself never crosses the wire.
	 *
	 * 2. Legacy static key via the X-API-Key header. Header-only by design:
	 *    keys passed as query/body params leak into server access logs, proxy
	 *    logs, and analytics, so the old body-param fallback was removed
	 *    (audit S-1).
	 *
	 * If a signature header is present it is authoritative — an invalid
	 * signature fails closed with no fallback to the static-key check.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool True if the request is authenticated.
	 */
	public static function validate_api_key( \WP_REST_Request $request ): bool {
		$settings   = get_option( 'aime_settings', array() );
		$stored_key = $settings['webhook_api_key'] ?? '';

		if ( empty( $stored_key ) ) {
			return false;
		}

		if ( '' !== trim( (string) $request->get_header( 'X-Aime-Signature' ) ) ) {
			return self::validate_webhook_signature( $request, $stored_key );
		}

		$provided = sanitize_text_field( $request->get_header( 'X-API-Key' ) ?? '' );

		if ( empty( $provided ) ) {
			return false;
		}

		return hash_equals( $stored_key, $provided );
	}

	/**
	 * Verify an HMAC webhook signature (X-Aime-Signature / X-Aime-Timestamp).
	 *
	 * Signed payload is "{timestamp}.{raw_body}" keyed with the webhook API
	 * key. Rejects timestamps outside a ±5 minute window and signatures seen
	 * within the last 10 minutes (replay protection).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @param string           $secret  Webhook API key (shared secret).
	 * @return bool True if the signature is valid and fresh.
	 */
	private static function validate_webhook_signature( \WP_REST_Request $request, string $secret ): bool {
		$timestamp = (int) $request->get_header( 'X-Aime-Timestamp' );
		if ( $timestamp <= 0 || abs( time() - $timestamp ) > 5 * MINUTE_IN_SECONDS ) {
			return false;
		}

		$signature = strtolower( trim( (string) $request->get_header( 'X-Aime-Signature' ) ) );
		if ( 0 === strpos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return false;
		}

		// Replay protection: a valid signature is single-use. The cache only
		// needs to outlive the ±5 minute timestamp window above.
		$replay_key = 'aime_wh_sig_' . md5( $signature );
		if ( get_transient( $replay_key ) ) {
			return false;
		}

		/**
		 * Filter how long a used webhook signature is remembered.
		 *
		 * Must stay above the ±5 minute timestamp window or replays become possible.
		 *
		 * @param int $ttl Time to live in seconds.
		 */
		$replay_ttl = (int) apply_filters( 'aime_webhook_replay_cache_ttl', 10 * MINUTE_IN_SECONDS );
		if ( $replay_ttl < 10 * MINUTE_IN_SECONDS ) {
			$replay_ttl = 10 * MINUTE_IN_SECONDS;
		}
		set_transient( $replay_key, 1, $replay_ttl );

		return true;
	}
}
