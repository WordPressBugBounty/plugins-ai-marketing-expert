<?php
/**
 * SEO Module — REST Controller (Central Dispatcher).
 *
 * Registers all REST routes for the SEO module by delegating
 * to individual domain controllers.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo;

use WPSpace\AiMarketingExpert\Modules\Seo\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoRestController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private string $ns;

	public function __construct() {
		$this->ns = aime_rest_namespace();
	}

	/**
	 * Register all SEO routes.
	 */
	public function register_routes(): void {
		$this->register_keyword_routes();
		$this->register_research_routes();
		$this->register_topic_routes();
		$this->register_audit_routes();
		$this->register_calendar_routes();
		$this->register_backlink_routes();
		$this->register_rank_routes();
		$this->register_settings_routes();
		$this->register_analytics_routes();
		$this->register_automation_routes();
	}

	/* ── Permission callback ─────────────────────────────── */

	public function admin_permission(): bool|\WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to access this resource.', 'ai-marketing-expert' ),
			array( 'status' => 403 )
		);
	}

	/* ── Keyword Routes (CRUD for keyword vault) ─────────── */

	private function register_keyword_routes(): void {
		$ctrl = new Controllers\KeywordController();

		register_rest_route( $this->ns, '/seo/keywords', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'index' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $ctrl, 'store' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( $this->ns, '/seo/keywords/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'show' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $ctrl, 'update' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $ctrl, 'destroy' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( $this->ns, '/seo/keywords/bulk-delete', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'bulk_destroy' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ── AI Research Routes ───────────────────────────────── */

	private function register_research_routes(): void {
		$ctrl = new Controllers\ResearchController();

		register_rest_route( $this->ns, '/seo/research/keywords', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'keyword_research' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/research/niche-analysis', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'niche_analysis' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/research/competitor-gap', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'competitor_gap' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/research/content-brief', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'content_brief' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/research/usage', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $ctrl, 'research_usage' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ── Topical Authority Map Routes ─────────────────────── */

	private function register_topic_routes(): void {
		$ctrl = new Controllers\TopicController();

		register_rest_route( $this->ns, '/seo/topics', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'index' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $ctrl, 'store' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( $this->ns, '/seo/topics/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'show' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $ctrl, 'update' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $ctrl, 'destroy' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( $this->ns, '/seo/topics/generate-map', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'generate_map' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/topics/usage', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $ctrl, 'topic_usage' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// Topic links.
		register_rest_route( $this->ns, '/seo/topics/links', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'get_links' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $ctrl, 'store_link' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( $this->ns, '/seo/topics/links/(?P<id>\d+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => array( $ctrl, 'destroy_link' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ── On-Page Audit Routes ─────────────────────────────── */

	private function register_audit_routes(): void {
		$ctrl = new Controllers\AuditController();

		register_rest_route( $this->ns, '/seo/audits', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'index' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( $this->ns, '/seo/audits/post-types', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $ctrl, 'post_types' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/audits/posts', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $ctrl, 'posts' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/audits/run', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'run_audit' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/audits/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'show' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $ctrl, 'destroy' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );
	}

	/* ── Content Calendar Routes ──────────────────────────── */

	private function register_calendar_routes(): void {
		$ctrl = new Controllers\CalendarController();

		register_rest_route( $this->ns, '/seo/calendar', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'index' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $ctrl, 'store' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( $this->ns, '/seo/calendar/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'show' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $ctrl, 'update' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $ctrl, 'destroy' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( $this->ns, '/seo/calendar/generate', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'generate_calendar' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/calendar/usage', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $ctrl, 'calendar_usage' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ── Backlink / Link Building Routes ──────────────────── */

	private function register_backlink_routes(): void {
		$ctrl = new Controllers\BacklinkController();

		register_rest_route( $this->ns, '/seo/backlinks', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'index' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $ctrl, 'store' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( $this->ns, '/seo/backlinks/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'show' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $ctrl, 'update' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $ctrl, 'destroy' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( $this->ns, '/seo/backlinks/generate-outreach', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'generate_outreach' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/backlinks/usage', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $ctrl, 'backlink_usage' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ── Rank Tracking Routes ─────────────────────────────── */

	private function register_rank_routes(): void {
		$ctrl = new Controllers\RankController();

		register_rest_route( $this->ns, '/seo/rank/history', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $ctrl, 'history' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/rank/check', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'manual_check' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/rank/bulk-check', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'bulk_check' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ── Settings Routes ──────────────────────────────────── */

	private function register_settings_routes(): void {
		$ctrl = new Controllers\SettingsController();

		register_rest_route( $this->ns, '/seo/settings', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'get_settings' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $ctrl, 'save_settings' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );
	}

	/* ── Analytics / Dashboard Routes ─────────────────────── */

	private function register_analytics_routes(): void {
		$ctrl = new Controllers\AnalyticsController();

		register_rest_route( $this->ns, '/seo/analytics/dashboard', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $ctrl, 'dashboard' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/analytics/keyword-overview', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $ctrl, 'keyword_overview' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/analytics/audit-trends', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $ctrl, 'audit_trends' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ── Automation Routes ────────────────────────────────── */

	private function register_automation_routes(): void {
		$ctrl = new Controllers\AutomationController();

		register_rest_route( $this->ns, '/seo/automation/settings', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $ctrl, 'get_settings' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $ctrl, 'save_settings' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( $this->ns, '/seo/automation/log', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $ctrl, 'get_log' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/automation/internal-links', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $ctrl, 'get_internal_link_suggestions' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/automation/internal-links/dismiss', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'dismiss_internal_link_suggestion' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/automation/internal-links/apply', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'apply_internal_link_suggestion' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/automation/log/clear', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'clear_log' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/seo/automation/run', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $ctrl, 'run_task' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}
}
