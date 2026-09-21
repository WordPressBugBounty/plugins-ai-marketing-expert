<?php
/**
 * Main Plugin class (Singleton).
 *
 * @package WPSpace\AiMarketingExpert
 */

// phpcs:disable PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Module manager instance.
	 *
	 * @var ModuleManager
	 */
	private ModuleManager $modules;

	/**
	 * Admin handler.
	 *
	 * @var Admin
	 */
	private Admin $admin;

	/**
	 * Dashboard widget handler.
	 *
	 * @var DashboardWidget|null
	 */
	private ?DashboardWidget $dashboard_widget = null;

	/**
	 * Upgrade notice handler.
	 *
	 * @var UpgradeNotice|null
	 */
	private ?UpgradeNotice $upgrade_notice = null;

	/**
	 * WooCommerce Cart Tracker & Recovery Engine.
	 *
	 * @var CartTracker|null
	 */
	private ?CartTracker $cart_tracker = null;

	/**
	 * REST API handler.
	 *
	 * @var RestApi
	 */
	private RestApi $rest_api;

	/**
	 * Whether active modules have been initialized.
	 *
	 * @var bool
	 */
	private bool $modules_initialized = false;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - private for singleton.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {
		throw new \Exception( esc_html__( 'Cannot unserialize singleton.', 'ai-marketing-expert' ) );
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks(): void {
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		add_action( 'init', array( $this, 'on_init' ) );
		add_action( 'rest_api_init', array( $this, 'on_rest_api_init' ) );
		// The consolidated dispatcher is scheduled from core, so the interval
		// must be registered in core too (not only by the email module).
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
	}

	/**
	 * Register the every_minute cron interval used by aime_minutely_tasks.
	 *
	 * @param array $schedules Registered cron schedules.
	 * @return array
	 */
	public function add_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['every_minute'] ) ) {
			/**
			 * Filter the every_minute cron interval, in seconds.
			 *
			 * Lower it on busy queues, raise it on slow hosts.
			 *
			 * @param int $interval Interval in seconds.
			 */
			$interval = (int) apply_filters( 'aime_every_minute_interval', 60 );
			if ( $interval < 30 ) {
				$interval = 30;
			}
			$schedules['every_minute'] = array(
				'interval' => $interval,
				'display'  => __( 'Every Minute', 'ai-marketing-expert' ),
			);
		}
		return $schedules;
	}

	/**
	 * Runs on plugins_loaded.
	 */
	public function on_plugins_loaded(): void {
		// Discover modules early so admin and REST handlers can reference them.
		$this->modules = new ModuleManager();
		$this->modules->discover_modules();

		// Initialize admin if in admin context.
		if ( is_admin() ) {
			$this->admin = new Admin( $this->modules );

			// Dashboard widget (WP admin dashboard only).
			require_once AIME_PLUGIN_DIR . 'includes/admin/class-dashboard-widget.php';
			$this->dashboard_widget = new DashboardWidget( $this->modules );

			// Upgrade to Pro notice.
			require_once AIME_PLUGIN_DIR . 'includes/admin/class-upgrade-notice.php';
			$this->upgrade_notice = new UpgradeNotice();
		}

		/**
		 * Fires after the plugin is fully loaded.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'aime_loaded', $this );
	}

	/**
	 * Runs on init.
	 */
	public function on_init(): void {
		// Load text domain (WP 6.7+ requires this on init or later).
		load_plugin_textdomain(
			'ai-marketing-expert',
			false,
			dirname( AIME_PLUGIN_BASENAME ) . '/languages'
		);

		$this->init_active_modules();

		// Now check database version and run migrations (modules hooks are ready).
		$this->maybe_upgrade_db();

		SmtpProvider::init();

		// Background AI job worker + daily retention cleanup.
		JobQueue::init();
		add_action( 'aime_daily_cleanup', array( JobQueue::class, 'cleanup' ) );
		add_action( 'aime_daily_cleanup', array( UsageTracker::class, 'cleanup' ) );
		add_action( 'aime_daily_cleanup', 'aime_prune_logs' );
		add_action( 'aime_process_bounce_mailbox', array( ImapBounceService::class, 'process_mailbox' ) );

		// WooCommerce abandoned cart tracker & recovery engine.
		if ( class_exists( 'WooCommerce' ) ) {
			require_once AIME_PLUGIN_DIR . 'includes/class-cart-tracker.php';
			$this->cart_tracker = new CartTracker();
			$this->cart_tracker->init();
		}

		// Direct external server cron execution (?aime_cron=1&token=...).
		if ( isset( $_GET['aime_cron'] ) && ! empty( $_GET['token'] ) ) {
			$this->handle_external_cron_request();
		}

		// Consolidated minutely dispatcher (audit P-4).
		add_action( 'aime_minutely_tasks', array( $this, 'run_minutely_tasks' ) );
		$this->maybe_migrate_cron();

		// Background task execution on admin visits & Heartbeat ticks.
		if ( is_admin() ) {
			add_action( 'shutdown', array( $this, 'maybe_trigger_admin_cron' ) );
			add_filter( 'heartbeat_received', array( $this, 'on_heartbeat_received' ), 10, 2 );
		}

		// Claim the seed flag *before* running so concurrent requests on the
		// first admin load (page + REST + heartbeat) cannot each seed a copy.
		if ( get_transient( 'aime_seed_email_templates' ) ) {
			delete_transient( 'aime_seed_email_templates' );
			Activator::seed_email_templates();
		}

		Activator::maybe_seed_email_defaults();

		// Register public hooks (tracking, unsubscribe, etc.).
		$this->register_public_hooks();
	}

	/**
	 * Handle direct external server cron request (?aime_cron=1&token=...).
	 */
	private function handle_external_cron_request(): void {
		$token    = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		$expected = get_option( 'aime_cron_secret_token' );
		if ( empty( $expected ) ) {
			$expected = wp_generate_password( 32, false );
			update_option( 'aime_cron_secret_token', $expected, false );
		}

		if ( ! hash_equals( (string) $expected, (string) $token ) ) {
			wp_die( 'Unauthorized: invalid cron token.', 'Unauthorized', array( 'response' => 403 ) );
		}

		@set_time_limit( 120 );

		do_action( 'aime_minutely_tasks' );
		do_action( 'aime_process_email_queue' );
		do_action( 'aime_process_automations' );
		do_action( 'aime_process_bounce_mailbox' );

		wp_send_json_success( array(
			'status'      => 'executed',
			'executed_at' => current_time( 'mysql', true ),
		) );
		exit;
	}

	/**
	 * Runs on rest_api_init.
	 */
	public function on_rest_api_init(): void {
		$this->rest_api = new RestApi( $this->modules );
		$this->rest_api->register_routes();
	}

	/**
	 * Register public-facing hooks.
	 */
	private function register_public_hooks(): void {
		// Public form handling, tracking pixels, etc.
		// Each module registers its own public hooks.
	}

	/**
	 * Run the minutely background tasks under an overlap lock.
	 *
	 * Fires the email-queue and automations actions from one dispatcher so a
	 * slow run (long SMTP batch, provider latency) can't stack on top of the
	 * previous one. The lock is claimed atomically via add_option(), which
	 * fails if the row already exists; a stale lock (previous run crashed)
	 * expires after 5 minutes.
	 */
	public function run_minutely_tasks(): void {
		$lock_key = 'aime_minutely_lock';
		$now      = time();

		if ( ! add_option( $lock_key, $now, '', false ) ) {
			$held_since = (int) get_option( $lock_key );
			if ( $held_since > 0 && ( $now - $held_since ) < 5 * MINUTE_IN_SECONDS ) {
				return; // Previous run still in progress.
			}
			// Stale lock — take it over.
			update_option( $lock_key, $now, false );
		}

		try {
			do_action( 'aime_process_email_queue' );
			do_action( 'aime_process_automations' );
		} finally {
			delete_option( $lock_key );
		}
	}

	/**
	 * Trigger background tasks when an admin visits or works in wp-admin.
	 *
	 * Ensures local environments and low-traffic sites process abandoned carts,
	 * scheduled workflows, and email queues smoothly without needing frontend traffic.
	 * Runs on shutdown and flushes output immediately via fastcgi_finish_request()
	 * so it never impacts admin dashboard loading speed.
	 */
	public function maybe_trigger_admin_cron(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$last_run = (int) get_transient( 'aime_last_admin_cron' );
		if ( $last_run && ( time() - $last_run ) < 60 ) {
			return;
		}

		set_transient( 'aime_last_admin_cron', time(), 60 );

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		$this->dispatch_background_tasks();
	}

	/**
	 * Heartbeat listener to keep background tasks running while an admin tab is open.
	 *
	 * @param array $response Heartbeat response data.
	 * @param array $data     Heartbeat received data.
	 * @return array
	 */
	public function on_heartbeat_received( array $response, array $data ): array {
		$last_run = (int) get_transient( 'aime_last_admin_cron' );
		if ( ! $last_run || ( time() - $last_run ) >= 60 ) {
			set_transient( 'aime_last_admin_cron', time(), 60 );
			$this->dispatch_background_tasks();
		}
		return $response;
	}

	/**
	 * Dispatch plugin background tasks (minutely tasks, workflows, cart checks, social posts).
	 */
	public function dispatch_background_tasks(): void {
		// Wake up WordPress core cron.
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		// Process plugin minutely tasks (email queue, funnels, cart abandonment).
		do_action( 'aime_minutely_tasks' );

		// Process scheduled workflows.
		do_action( 'aime_workflow_dispatch_due' );

		// Process scheduled social media posts.
		do_action( 'aime_publish_scheduled_social_posts' );
	}

	/**
	 * One-time migration from the two pre-1.1 every-minute schedules to the
	 * consolidated dispatcher (audit P-4). Activator handles fresh installs;
	 * this covers sites updating in place without re-activation.
	 */
	private function maybe_migrate_cron(): void {
		if ( get_option( 'aime_cron_v2' ) ) {
			return;
		}

		wp_clear_scheduled_hook( 'aime_process_email_queue' );
		wp_clear_scheduled_hook( 'aime_process_automations' );

		if ( ! wp_next_scheduled( 'aime_minutely_tasks' ) ) {
			wp_schedule_event( time(), 'every_minute', 'aime_minutely_tasks' );
		}

		update_option( 'aime_cron_v2', 1, false );
	}

	/**
	 * Check and run database migrations.
	 */
	private function maybe_upgrade_db(): void {
		$current_version = get_option( 'aime_db_version', '0' );

		if ( version_compare( $current_version, AIME_DB_VERSION, '<' ) ) {
			Database::migrate();
			update_option( 'aime_db_version', AIME_DB_VERSION );
		}
	}

	/**
	 * Initialize active modules once translations are available.
	 */
	private function init_active_modules(): void {
		if ( $this->modules_initialized ) {
			return;
		}

		$this->modules->init_active_modules();
		$this->modules_initialized = true;
	}

	/**
	 * Get the module manager.
	 *
	 * @return ModuleManager
	 */
	public function modules(): ModuleManager {
		return $this->modules;
	}

	/**
	 * Get a specific module instance.
	 *
	 * @param string $module_id Module identifier.
	 * @return Module|null
	 */
	public function module( string $module_id ): ?Module {
		return $this->modules->get( $module_id );
	}
}
