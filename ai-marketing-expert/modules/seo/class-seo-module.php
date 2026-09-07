<?php
/**
 * SEO Module — Bootstrap.
 *
 * AI-powered SEO intelligence: keyword research, topical authority mapping,
 * on-page audit, content calendar, link building, entity SEO, and rank tracking.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo;

use WPSpace\AiMarketingExpert\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoModule extends Module {

	/* ── Module identity ────────────────────────────────── */

	public function get_id(): string {
		return 'seo';
	}

	public function get_name(): string {
		return __( 'SEO', 'ai-marketing-expert' );
	}

	public function get_description(): string {
		return __( 'AI-powered SEO intelligence — keyword research, topical authority maps, on-page audits, content calendar, and rank tracking.', 'ai-marketing-expert' );
	}

	public function get_icon(): string {
		return 'chart-line';
	}

	public function get_version(): string {
		return '1.0.0';
	}

	/* ── Pro features ───────────────────────────────────── */

	public function get_pro_features(): array {
		return array(
			'unlimited_keywords'   => __( 'Unlimited keyword research queries (free: 10/month)', 'ai-marketing-expert' ),
			'unlimited_vault'      => __( 'Unlimited keywords in vault (free: 50)', 'ai-marketing-expert' ),
			'topical_authority'    => __( 'AI topical authority map generation & editing', 'ai-marketing-expert' ),
			'content_calendar'     => __( 'AI-driven content calendar planning', 'ai-marketing-expert' ),
			'link_building'        => __( 'Link building pipeline with AI outreach emails', 'ai-marketing-expert' ),
			'entity_seo'           => __( 'Entity SEO analysis & schema markup suggestions', 'ai-marketing-expert' ),
			'competitor_analysis'  => __( 'Competitor gap analysis', 'ai-marketing-expert' ),
			'unlimited_audits'     => __( 'Unlimited on-page SEO audits + site-wide audit (free: 5/month)', 'ai-marketing-expert' ),
			'unlimited_rank_track' => __( 'Unlimited rank tracking keywords + daily auto-check (free: 5, manual only)', 'ai-marketing-expert' ),
			'niche_analysis'       => __( 'AI niche analysis & opportunity scoring', 'ai-marketing-expert' ),
			'csv_export'           => __( 'Export keywords, audits, and reports to CSV/PDF', 'ai-marketing-expert' ),
		);
	}

	/* ── Initialisation ─────────────────────────────────── */

	public function init(): void {
		$this->maybe_create_tables();

		// Dashboard stats hook.
		add_filter( 'aime_seo_dashboard_stats', array( $this, 'get_stats' ) );

		// Cron hook for daily rank checks.
		add_action( 'aime_seo_rank_check', array( $this, 'process_rank_checks' ) );

		// Schedule daily rank check cron if not already scheduled.
		if ( ! wp_next_scheduled( 'aime_seo_rank_check' ) ) {
			wp_schedule_event( time(), 'daily', 'aime_seo_rank_check' );
		}

		// Cron hook for automation tasks.
		add_action( 'aime_seo_automation_process', array( $this, 'process_automations' ) );

		if ( ! wp_next_scheduled( 'aime_seo_automation_process' ) ) {
			wp_schedule_event( time(), 'hourly', 'aime_seo_automation_process' );
		}

		// save_post hook for auto-audit & auto-meta.
		add_action( 'save_post', array( $this, 'handle_post_save' ), 99, 2 );

		// Frontend fallback head tags when no SEO plugin handles output.
		( new Services\SeoFrontendService() )->init();
	}

	/**
	 * Process daily rank checks (Pro only).
	 */
	public function process_rank_checks(): void {
		if ( ! aime_has_pro() ) {
			return;
		}

		$settings = get_option( 'aime_seo_settings', array() );
		if ( empty( $settings['auto_rank_check'] ) ) {
			return;
		}

		$service = new Services\RankTrackerService();
		$service->process_daily_checks();
	}

	/**
	 * Process scheduled automation tasks (Pro only).
	 */
	public function process_automations(): void {
		if ( ! aime_has_pro() ) {
			return;
		}
		$service = new Services\AutomationService();
		$service->process_scheduled_tasks();
	}

	/**
	 * Handle post save — trigger auto-audit and auto-meta if enabled.
	 */
	public function handle_post_save( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( $post->post_status !== 'publish' ) {
			return;
		}
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		$service = new Services\AutomationService();
		$service->handle_post_publish( $post_id, $post );
	}

	/* ── REST routes ─────────────────────────────────────── */

	public function register_routes(): void {
		$controller = new SeoRestController();
		$controller->register_routes();
	}

	/* ── Database tables ─────────────────────────────────── */

	private function maybe_create_tables(): void {
		$installed = get_option( 'aime_seo_db_version', '' );
		if ( version_compare( $installed, AIME_SEO_DB_VERSION, '>=' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$this->create_tables( $charset );

		update_option( 'aime_seo_db_version', AIME_SEO_DB_VERSION );
	}

	public function create_tables( string $charset_collate ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// Keywords table.
		dbDelta( "CREATE TABLE {$p}aime_seo_keywords (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			keyword VARCHAR(500) NOT NULL DEFAULT '',
			search_volume INT UNSIGNED DEFAULT 0,
			difficulty_score TINYINT UNSIGNED DEFAULT 0,
			cpc_estimate DECIMAL(10,2) DEFAULT 0.00,
			intent VARCHAR(30) NOT NULL DEFAULT 'informational',
			parent_topic_id BIGINT UNSIGNED DEFAULT NULL,
			cluster_id BIGINT UNSIGNED DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'researched',
			current_rank INT UNSIGNED DEFAULT NULL,
			target_url VARCHAR(1000) DEFAULT NULL,
			wp_post_id BIGINT UNSIGNED DEFAULT NULL,
			notes TEXT,
			is_pro TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_intent (intent),
			KEY idx_parent_topic (parent_topic_id),
			KEY idx_cluster (cluster_id),
			KEY idx_created (created_at)
		) $charset_collate;" );

		// Topics table (topical authority map nodes).
		dbDelta( "CREATE TABLE {$p}aime_seo_topics (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(500) NOT NULL DEFAULT '',
			description TEXT,
			topic_type VARCHAR(20) NOT NULL DEFAULT 'cluster',
			parent_id BIGINT UNSIGNED DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'planned',
			target_keyword_id BIGINT UNSIGNED DEFAULT NULL,
			wp_post_id BIGINT UNSIGNED DEFAULT NULL,
			content_brief LONGTEXT,
			word_count_target INT UNSIGNED DEFAULT 1500,
			priority TINYINT UNSIGNED DEFAULT 3,
			notes TEXT,
			is_pro TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_type (topic_type),
			KEY idx_parent (parent_id),
			KEY idx_status (status),
			KEY idx_keyword (target_keyword_id)
		) $charset_collate;" );

		// Topic links (edges in the topical authority graph).
		dbDelta( "CREATE TABLE {$p}aime_seo_topic_links (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_topic_id BIGINT UNSIGNED NOT NULL,
			target_topic_id BIGINT UNSIGNED NOT NULL,
			link_type VARCHAR(30) NOT NULL DEFAULT 'internal_link',
			anchor_text VARCHAR(500) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'planned',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_source (source_topic_id),
			KEY idx_target (target_topic_id)
		) $charset_collate;" );

		// SEO audits.
		dbDelta( "CREATE TABLE {$p}aime_seo_audits (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id BIGINT UNSIGNED DEFAULT NULL,
			url VARCHAR(1000) DEFAULT NULL,
			title VARCHAR(500) DEFAULT NULL,
			overall_score TINYINT UNSIGNED DEFAULT 0,
			results LONGTEXT,
			keyword_focus VARCHAR(255) DEFAULT NULL,
			issues_count SMALLINT UNSIGNED DEFAULT 0,
			warnings_count SMALLINT UNSIGNED DEFAULT 0,
			passed_count SMALLINT UNSIGNED DEFAULT 0,
			ai_suggestions LONGTEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_wp_post (wp_post_id),
			KEY idx_score (overall_score),
			KEY idx_created (created_at)
		) $charset_collate;" );

		// Content calendar.
		dbDelta( "CREATE TABLE {$p}aime_seo_calendar (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(500) NOT NULL DEFAULT '',
			keyword_id BIGINT UNSIGNED DEFAULT NULL,
			topic_id BIGINT UNSIGNED DEFAULT NULL,
			content_type VARCHAR(30) NOT NULL DEFAULT 'blog_post',
			assigned_to BIGINT UNSIGNED DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'planned',
			planned_date DATE DEFAULT NULL,
			published_date DATE DEFAULT NULL,
			wp_post_id BIGINT UNSIGNED DEFAULT NULL,
			article_id BIGINT UNSIGNED DEFAULT NULL,
			notes TEXT,
			is_pro TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_keyword (keyword_id),
			KEY idx_topic (topic_id),
			KEY idx_status (status),
			KEY idx_planned (planned_date),
			KEY idx_created (created_at)
		) $charset_collate;" );

		// Backlinks / link building tracker.
		dbDelta( "CREATE TABLE {$p}aime_seo_backlinks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_url VARCHAR(1000) NOT NULL DEFAULT '',
			target_url VARCHAR(1000) NOT NULL DEFAULT '',
			anchor_text VARCHAR(500) DEFAULT NULL,
			rel_type VARCHAR(20) NOT NULL DEFAULT 'dofollow',
			status VARCHAR(30) NOT NULL DEFAULT 'prospect',
			domain_authority TINYINT UNSIGNED DEFAULT 0,
			contact_email VARCHAR(255) DEFAULT NULL,
			contact_name VARCHAR(255) DEFAULT NULL,
			outreach_template LONGTEXT,
			response_notes TEXT,
			link_type VARCHAR(30) NOT NULL DEFAULT 'guest_post',
			discovered_at DATETIME DEFAULT NULL,
			acquired_at DATETIME DEFAULT NULL,
			is_pro TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_rel (rel_type),
			KEY idx_link_type (link_type),
			KEY idx_created (created_at)
		) $charset_collate;" );

		// Rank history.
		dbDelta( "CREATE TABLE {$p}aime_seo_rank_history (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			keyword_id BIGINT UNSIGNED NOT NULL,
			rank_position INT UNSIGNED DEFAULT NULL,
			url VARCHAR(1000) DEFAULT NULL,
			search_engine VARCHAR(20) NOT NULL DEFAULT 'google',
			checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_keyword (keyword_id),
			KEY idx_checked (checked_at)
		) $charset_collate;" );

		// Automation log.
		dbDelta( "CREATE TABLE {$p}aime_seo_automation_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			task_type VARCHAR(50) NOT NULL DEFAULT '',
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'cron',
			wp_post_id BIGINT UNSIGNED DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'completed',
			summary TEXT,
			details LONGTEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_task_type (task_type),
			KEY idx_trigger_type (trigger_type),
			KEY idx_status (status),
			KEY idx_created (created_at)
		) $charset_collate;" );
	}

	/* ── Dashboard stats ─────────────────────────────────── */

	public function get_stats(): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_seo_keywords" ) );
		if ( ! $table_exists ) {
			return array(
				'total_keywords'   => 0,
				'tracked_keywords' => 0,
				'total_topics'     => 0,
				'total_audits'     => 0,
				'avg_audit_score'  => 0,
				'total_backlinks'  => 0,
			);
		}

		return array(
			'total_keywords'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_seo_keywords" ),
			'tracked_keywords' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_seo_keywords WHERE status = %s",
				'targeted'
			) ),
			'total_topics'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_seo_topics" ),
			'total_audits'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_seo_audits" ),
			'avg_audit_score'  => (float) $wpdb->get_var( "SELECT ROUND(AVG(overall_score), 1) FROM {$p}aime_seo_audits WHERE overall_score > 0" ) ?: 0,
			'total_backlinks'  => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_seo_backlinks WHERE status = %s",
				'acquired'
			) ),
		);
	}

	/* ── Monthly research count (for free limit check) ──── */

	public static function get_monthly_research_count(): int {
		$month_key = 'aime_seo_research_' . gmdate( 'Y_m' );
		return (int) get_option( $month_key, 0 );
	}

	public static function increment_monthly_research(): void {
		$month_key = 'aime_seo_research_' . gmdate( 'Y_m' );
		$current   = (int) get_option( $month_key, 0 );
		update_option( $month_key, $current + 1 );
	}

	/* ── Generic monthly feature counters (for free limit checks) ── */

	public static function get_monthly_feature_count( string $feature ): int {
		return (int) get_option( 'aime_seo_' . $feature . '_' . gmdate( 'Y_m' ), 0 );
	}

	public static function increment_monthly_feature( string $feature ): void {
		$month_key = 'aime_seo_' . $feature . '_' . gmdate( 'Y_m' );
		update_option( $month_key, (int) get_option( $month_key, 0 ) + 1 );
	}

	/* ── Monthly audit count ────────────────────────────── */

	public static function get_monthly_audit_count(): int {
		global $wpdb;
		$p = $wpdb->prefix;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_seo_audits" ) );
		if ( ! $table_exists ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_seo_audits WHERE created_at >= %s",
				gmdate( 'Y-m-01 00:00:00' )
			)
		);
	}

	/* ── Keyword vault count ────────────────────────────── */

	public static function get_keyword_count(): int {
		global $wpdb;
		$p = $wpdb->prefix;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_seo_keywords" ) );
		if ( ! $table_exists ) {
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_seo_keywords" );
	}

	/* ── Tracked keywords count ─────────────────────────── */

	public static function get_tracked_keyword_count(): int {
		global $wpdb;
		$p = $wpdb->prefix;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_seo_keywords" ) );
		if ( ! $table_exists ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_seo_keywords WHERE current_rank IS NOT NULL OR status = %s",
				'targeted'
			)
		);
	}

	/* ── Row counts for free-plan storage limits ────────── */

	private static function count_rows( string $table ): int {
		global $wpdb;
		$p     = $wpdb->prefix;
		$full  = $p . $table;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
		if ( ! $table_exists ) {
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$full}" );
	}

	public static function get_topic_count(): int {
		return self::count_rows( 'aime_seo_topics' );
	}

	public static function get_calendar_count(): int {
		return self::count_rows( 'aime_seo_calendar' );
	}

	public static function get_backlink_count(): int {
		return self::count_rows( 'aime_seo_backlinks' );
	}

	/**
	 * Count enabled automation toggles that count against the free-plan task
	 * limit. Cron-driven toggles are Pro-only and metered separately, so they
	 * must not be counted here or the meter locks a free user out of the
	 * publish-hook rules they are entitled to.
	 */
	public static function get_active_automation_count(): int {
		$settings = get_option( 'aime_seo_automation_settings', array() );
		$toggles  = array( 'auto_audit_on_publish', 'auto_meta_on_publish' );

		$count = 0;
		foreach ( $toggles as $toggle ) {
			if ( ! empty( $settings[ $toggle ] ) ) {
				$count++;
			}
		}

		return $count;
	}
}

/* ── Register with Module Manager ──────────────────────── */

add_action( 'aime_load_module_seo', function ( $manager ) {
	$manager->register( new SeoModule() );
} );
