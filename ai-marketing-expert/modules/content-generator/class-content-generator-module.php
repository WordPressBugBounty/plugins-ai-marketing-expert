<?php
/**
 * Content Generator Module — Bootstrap.
 *
 * AI-powered blog content creation, SEO optimization, and WordPress publishing.
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator;

use WPSpace\AiMarketingExpert\Module;
use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services\PublisherService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContentGeneratorModule extends Module {

	const DB_VERSION = '1.6.0';

	/* ── Module identity ────────────────────────────────── */

	public function get_id(): string {
		return 'content-generator';
	}

	public function get_name(): string {
		return __( 'Content Generator', 'ai-marketing-expert' );
	}

	public function get_description(): string {
		return __( 'AI-powered blog content creation with SEO optimization and one-click WordPress publishing.', 'ai-marketing-expert' );
	}

	public function get_icon(): string {
		return 'edit-page';
	}

	public function get_version(): string {
		return '1.0.0';
	}

	/* ── Pro features ───────────────────────────────────── */

	public function get_pro_features(): array {
		return array(
			'unlimited_articles'   => __( 'Unlimited article generation (free: 10/month)', 'ai-marketing-expert' ),
			'long_form'            => __( 'Long-form content up to 5,000 words (free: 1,500)', 'ai-marketing-expert' ),
			'custom_presets'       => __( 'Create custom generation presets', 'ai-marketing-expert' ),
			'advanced_seo'         => __( 'Advanced per-section SEO analysis', 'ai-marketing-expert' ),
			'multi_language'       => __( 'Multi-language content (20+ languages)', 'ai-marketing-expert' ),
			'image_prompts'        => __( 'AI image prompt generation', 'ai-marketing-expert' ),
			'content_calendar'     => __( 'Scheduled WordPress publishing', 'ai-marketing-expert' ),
			'custom_instructions'  => __( 'Custom system instructions per preset', 'ai-marketing-expert' ),
			'advanced_analytics'   => __( 'Advanced content analytics & trends', 'ai-marketing-expert' ),
		);
	}

	/* ── Initialisation ─────────────────────────────────── */

	public function init(): void {
		$this->maybe_create_tables();
		wp_clear_scheduled_hook( 'aime_content_queue_process' );
		add_action( 'aime_content_sync_scheduled_articles', array( $this, 'sync_scheduled_articles' ) );
		if ( ! wp_next_scheduled( 'aime_content_sync_scheduled_articles' ) ) {
			wp_schedule_event( time(), 'hourly', 'aime_content_sync_scheduled_articles' );
		}
		add_action( 'transition_post_status', array( $this, 'sync_article_on_wp_publish' ), 10, 3 );

		// Dashboard stats hook.
		add_filter( 'aime_content-generator_dashboard_stats', array( $this, 'get_stats' ) );
	}

	public function sync_scheduled_articles(): void {
		PublisherService::sync_due_scheduled_articles( 50 );
	}

	public function sync_article_on_wp_publish( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'future' !== $old_status ) {
			return;
		}

		PublisherService::mark_article_published_by_post_id( (int) $post->ID, $post->post_date );
	}

	/* ── REST routes ─────────────────────────────────────── */

	public function register_routes(): void {
		$controller = new ContentRestController();
		$controller->register_routes();
	}

	/* ── Database tables ─────────────────────────────────── */

	private function maybe_create_tables(): void {
		$installed = get_option( 'aime_content_generator_db_version', '' );
		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$this->create_tables( $charset );

		// Seed default presets.
		$this->seed_default_presets();
		$this->seed_default_brand_voices();

		// Run migrations.
		$this->run_migrations( $installed );

		update_option( 'aime_content_generator_db_version', self::DB_VERSION );
	}

	public function create_tables( string $charset_collate ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// Articles table.
		dbDelta( "CREATE TABLE {$p}aime_content_articles (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(500) NOT NULL DEFAULT '',
			slug VARCHAR(500) NOT NULL DEFAULT '',
			content LONGTEXT,
			excerpt TEXT,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			wp_post_id BIGINT UNSIGNED DEFAULT NULL,
			post_type VARCHAR(50) NOT NULL DEFAULT 'post',
			topic VARCHAR(500) NOT NULL DEFAULT '',
			keywords LONGTEXT,
			tone VARCHAR(50) NOT NULL DEFAULT 'professional',
			word_count_target INT UNSIGNED DEFAULT 1000,
			actual_word_count INT UNSIGNED DEFAULT 0,
			language VARCHAR(10) NOT NULL DEFAULT 'en',
			ai_provider VARCHAR(50) DEFAULT NULL,
			ai_model VARCHAR(100) DEFAULT NULL,
			seo_score TINYINT UNSIGNED DEFAULT 0,
			readability_score TINYINT UNSIGNED DEFAULT 0,
			meta_title VARCHAR(255) DEFAULT NULL,
			meta_description VARCHAR(500) DEFAULT NULL,
			outline LONGTEXT,
			category_ids LONGTEXT,
			tag_ids LONGTEXT,
			featured_image_url VARCHAR(1000) DEFAULT NULL,
			featured_image_id BIGINT UNSIGNED DEFAULT NULL,
			preset_id BIGINT UNSIGNED DEFAULT NULL,
			brand_voice_id BIGINT UNSIGNED DEFAULT NULL,
			is_pro TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			scheduled_publish_at DATETIME DEFAULT NULL,
			published_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_wp_post_id (wp_post_id),
			KEY idx_created (created_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$p}aime_content_versions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			article_id BIGINT UNSIGNED NOT NULL,
			label VARCHAR(255) NOT NULL DEFAULT '',
			snapshot LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_article (article_id),
			KEY idx_created (created_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$p}aime_content_brand_voices (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			description TEXT,
			tone_rules TEXT,
			style_rules TEXT,
			avoid_rules TEXT,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_default (is_default)
		) $charset_collate;" );

		// Presets table.
		dbDelta( "CREATE TABLE {$p}aime_content_presets (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			description TEXT,
			tone VARCHAR(50) NOT NULL DEFAULT 'professional',
			style VARCHAR(50) NOT NULL DEFAULT 'blog_post',
			language VARCHAR(10) NOT NULL DEFAULT 'en',
			word_count INT UNSIGNED NOT NULL DEFAULT 1000,
			prompt_template LONGTEXT,
			system_instructions LONGTEXT,
			default_category_id BIGINT UNSIGNED DEFAULT NULL,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			is_pro TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset_collate;" );

		// History / audit log table.
		dbDelta( "CREATE TABLE {$p}aime_content_history (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			article_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(50) NOT NULL,
			details LONGTEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_article (article_id),
			KEY idx_created (created_at)
		) $charset_collate;" );
	}

	/* ── Seed default presets ────────────────────────────── */

	private function seed_default_presets(): void {
		global $wpdb;
		$p     = $wpdb->prefix;
		$table = "{$p}aime_content_presets";

		// Only seed if table is empty.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		if ( $count > 0 ) {
			return;
		}

		$now     = current_time( 'mysql', true );
		$presets = array(
			array(
				'name'               => __( 'Blog Post', 'ai-marketing-expert' ),
				'description'        => __( 'A well-structured blog article with H2/H3 headings, introduction, and conclusion.', 'ai-marketing-expert' ),
				'tone'               => 'professional',
				'style'              => 'blog_post',
				'language'           => 'en',
				'word_count'         => 1200,
				'prompt_template'    => 'Write a comprehensive blog post about the given topic. Include an engaging introduction, well-organized sections with H2 and H3 headings, practical examples, and a compelling conclusion with a call to action.',
				'system_instructions' => 'You are an expert content writer specializing in informative, SEO-friendly blog posts. Write in a clear, engaging style. Use proper HTML heading tags (h2, h3). Include bullet points and numbered lists where appropriate.',
				'is_default'         => 1,
				'is_pro'             => 0,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array(
				'name'               => __( 'How-To Guide', 'ai-marketing-expert' ),
				'description'        => __( 'Step-by-step instructional guide with clear numbered steps.', 'ai-marketing-expert' ),
				'tone'               => 'instructional',
				'style'              => 'how_to',
				'language'           => 'en',
				'word_count'         => 1500,
				'prompt_template'    => 'Write a detailed how-to guide about the given topic. Include a brief intro explaining what the reader will learn, numbered step-by-step instructions, tips and warnings where relevant, and a summary of key takeaways.',
				'system_instructions' => 'You are a technical writer who creates clear, actionable how-to guides. Break complex processes into simple numbered steps. Use HTML heading tags (h2, h3). Include pro tips in bold or callout format.',
				'is_default'         => 1,
				'is_pro'             => 0,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array(
				'name'               => __( 'Product Review', 'ai-marketing-expert' ),
				'description'        => __( 'Persuasive product review with pros, cons, and a verdict.', 'ai-marketing-expert' ),
				'tone'               => 'persuasive',
				'style'              => 'product_review',
				'language'           => 'en',
				'word_count'         => 1000,
				'prompt_template'    => 'Write a thorough product review about the given topic. Include an overview, key features, detailed pros and cons, a comparison with alternatives if relevant, and a final verdict with a rating.',
				'system_instructions' => 'You are a product review expert who writes honest, detailed, and engaging reviews. Use HTML heading tags. Include a pros/cons section formatted as lists. Be balanced but persuasive when the product merits it.',
				'is_default'         => 1,
				'is_pro'             => 0,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array(
				'name'               => __( 'Listicle', 'ai-marketing-expert' ),
				'description'        => __( 'Engaging numbered list article (e.g., "10 Best Tips for...").', 'ai-marketing-expert' ),
				'tone'               => 'engaging',
				'style'              => 'listicle',
				'language'           => 'en',
				'word_count'         => 1200,
				'prompt_template'    => 'Write an engaging listicle about the given topic. Use a numbered format with each item having its own H2 heading. Include a brief introduction and conclusion. Each item should have 2-3 paragraphs of detail.',
				'system_instructions' => 'You are a content writer specializing in viral, shareable list articles. Each list item should have a compelling heading. Use HTML heading tags. Make the content scannable yet informative.',
				'is_default'         => 1,
				'is_pro'             => 0,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
		);

		foreach ( $presets as $preset ) {
			$wpdb->insert( $table, $preset );
		}
	}

	public function seed_default_brand_voices(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'aime_content_brand_voices';

		$now    = current_time( 'mysql', true );
		$voices = array(
			array(
				'name'        => __( 'Expert Advisor', 'ai-marketing-expert' ),
				'description' => __( 'Confident, practical, and educational voice for authority-building blog content.', 'ai-marketing-expert' ),
				'tone_rules'  => 'Be clear, credible, calm, and helpful. Use confident language without sounding arrogant.',
				'style_rules' => 'Explain ideas with practical examples, short paragraphs, and actionable takeaways. Prefer direct sentences over hype.',
				'avoid_rules' => 'Avoid exaggerated claims, vague advice, filler intros, and slang.',
				'is_default'  => 1,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array(
				'name'        => __( 'Friendly Educator', 'ai-marketing-expert' ),
				'description' => __( 'Warm, simple, and approachable voice for tutorials and beginner-friendly content.', 'ai-marketing-expert' ),
				'tone_rules'  => 'Sound supportive, patient, and conversational. Make readers feel guided rather than judged.',
				'style_rules' => 'Use simple explanations, step-by-step phrasing, and occasional reassuring transitions.',
				'avoid_rules' => 'Avoid technical jargon without explanation, harsh wording, and overly formal phrasing.',
				'is_default'  => 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array(
				'name'        => __( 'Conversion Focused', 'ai-marketing-expert' ),
				'description' => __( 'Persuasive but trustworthy voice for product, service, and landing-page content.', 'ai-marketing-expert' ),
				'tone_rules'  => 'Be benefit-led, specific, and confident. Keep claims grounded and useful.',
				'style_rules' => 'Lead with reader problems, connect features to outcomes, and include natural calls to action.',
				'avoid_rules' => 'Avoid pushy sales language, fake urgency, unsupported guarantees, and generic marketing buzzwords.',
				'is_default'  => 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
		);

		foreach ( $voices as $voice ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE name = %s', $table, $voice['name'] ) );
			if ( $exists > 0 ) {
				continue;
			}

			if ( ! empty( $voice['is_default'] ) ) {
				$has_default = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_default = 1', $table ) );
				if ( $has_default > 0 ) {
					$voice['is_default'] = 0;
				}
			}

			$wpdb->insert( $table, $voice );
		}
	}

	/* ── Migrations ──────────────────────────────────────── */

	private function run_migrations( string $installed_version ): void {
		// v1.6.0: Seed default brand voices for existing installs.
		if ( version_compare( $installed_version, '1.6.0', '<' ) ) {
			$this->seed_default_brand_voices();
		}

		// v1.5.0: Store scheduled WordPress publish time per article.
		if ( version_compare( $installed_version, '1.5.0', '<' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'aime_content_articles';
			$col   = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'scheduled_publish_at'" );
			if ( empty( $col ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN scheduled_publish_at DATETIME DEFAULT NULL AFTER updated_at" );
			}
		}

		// v1.4.0: Workflow tables and brand voice link.
		if ( version_compare( $installed_version, '1.4.0', '<' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'aime_content_articles';
			$col   = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'brand_voice_id'" );
			if ( empty( $col ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN brand_voice_id BIGINT UNSIGNED DEFAULT NULL AFTER preset_id" );
			}
		}

		// v1.3.0: Store target WordPress post type per article.
		if ( version_compare( $installed_version, '1.3.0', '<' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'aime_content_articles';
			$col   = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'post_type'" );
			if ( empty( $col ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN post_type VARCHAR(50) NOT NULL DEFAULT 'post' AFTER wp_post_id" );
			}
		}

		// v1.2.0: Add featured_image_id column.
		if ( version_compare( $installed_version, '1.2.0', '<' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'aime_content_articles';
			$col   = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'featured_image_id'" );
			if ( empty( $col ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN featured_image_id BIGINT UNSIGNED DEFAULT NULL AFTER featured_image_url" );
			}
		}

		// v1.1.0: Normalise preset style values from hyphens to underscores.
		if ( version_compare( $installed_version, '1.1.0', '<' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'aime_content_presets';

			$style_map = array(
				'blog-post' => 'blog_post',
				'how-to'    => 'how_to',
				'review'    => 'product_review',
			);

			foreach ( $style_map as $old_style => $new_style ) {
				$wpdb->update(
					$table,
					array( 'style' => $new_style ),
					array( 'style' => $old_style ),
					array( '%s' ),
					array( '%s' )
				);
			}
		}
	}

	/* ── Dashboard stats ─────────────────────────────────── */

	public function get_stats(): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_content_articles" ) );
		if ( ! $table_exists ) {
			return array(
				'total_articles'     => 0,
				'published_articles' => 0,
				'draft_articles'     => 0,
				'avg_seo_score'      => 0,
				'total_words'        => 0,
			);
		}

		return array(
			'total_articles'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_content_articles" ),
			'published_articles' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_content_articles WHERE status = %s", 'published' ) ),
			'draft_articles'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_content_articles WHERE status = %s", 'draft' ) ),
			'avg_seo_score'      => (float) $wpdb->get_var( "SELECT ROUND(AVG(seo_score), 1) FROM {$p}aime_content_articles WHERE seo_score > 0" ) ?: 0,
			'total_words'        => (int) $wpdb->get_var( "SELECT SUM(actual_word_count) FROM {$p}aime_content_articles" ) ?: 0,
		);
	}

	/* ── Monthly article count (for free limit check) ──── */

	public static function get_monthly_article_count(): int {
		global $wpdb;
		$p = $wpdb->prefix;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_content_articles" ) );
		if ( ! $table_exists ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_content_articles WHERE created_at >= %s",
				gmdate( 'Y-m-01 00:00:00' )
			)
		);
	}
}

/* ── Register with Module Manager ──────────────────────── */

add_action( 'aime_load_module_content-generator', function ( $manager ) {
	$manager->register( new ContentGeneratorModule() );
} );
