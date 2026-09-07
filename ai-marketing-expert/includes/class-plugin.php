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

		// Consolidated minutely dispatcher (audit P-4).
		add_action( 'aime_minutely_tasks', array( $this, 'run_minutely_tasks' ) );
		$this->maybe_migrate_cron();

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
