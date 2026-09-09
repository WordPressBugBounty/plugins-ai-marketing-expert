<?php
/**
 * Email REST Controller — central route dispatcher.
 *
 * Registers all REST routes for the Email Marketing module and delegates
 * to domain-specific controllers.
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing
 */

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing;

use WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers\SubscriberController;
use WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers\CampaignController;
use WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers\FunnelController;
use WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers\TemplateController;
use WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers\SettingsController;
use WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers\AnalyticsController;
use WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers\AiController;
use WPSpace\AiMarketingExpert\SmtpProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EmailRestController {

	private string $ns;

	public function __construct() {
		$this->ns = aime_rest_namespace();
	}

	/* ────────────────────────────────────────────────────
	 *  Register all routes
	 * ──────────────────────────────────────────────────── */

	public function register_routes(): void {
		$this->register_subscriber_routes();
		$this->register_campaign_routes();
		$this->register_funnel_routes();
		$this->register_template_routes();
		$this->register_settings_routes();
		$this->register_analytics_routes();
		$this->register_ai_routes();
		$this->register_smtp_routes();
		// Tracking is handled via init hook (query-string URLs), not REST routes.
	}

	/* ── Permission callbacks ────────────────────────── */

	public function admin_permission(): bool|\WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'ai-marketing-expert' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission callback for the public subscribe endpoint.
	 *
	 * Applies a per-IP rate limit (default 10 requests per 5 minutes) before
	 * allowing the request through. Limits are filterable via
	 * 'aime_ip_rate_limit'.
	 */
	public function subscribe_rate_limit_permission(): bool|\WP_Error {
		if ( ! aime_check_ip_rate_limit( 'email_subscribe', 10, 5 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error(
				'aime_rate_limited',
				__( 'Too many requests. Please try again later.', 'ai-marketing-expert' ),
				array( 'status' => 429 )
			);
		}
		return true;
	}

	/**
	 * Permission callback for public webhook endpoints (API-key auth happens
	 * in the handlers). Generous per-IP cap to blunt brute-force attempts on
	 * the API key.
	 */
	public function webhook_rate_limit_permission( ?\WP_REST_Request $request = null ): bool|\WP_Error {
		// Valid API key holders bypass brute-force protection
		if ( $request && \WPSpace\AiMarketingExpert\RestApi::validate_api_key( $request ) ) {
			return true;
		}

		if ( ! aime_check_ip_rate_limit( 'email_webhook', 120, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error(
				'aime_rate_limited',
				__( 'Too many requests. Please try again later.', 'ai-marketing-expert' ),
				array( 'status' => 429 )
			);
		}
		return true;
	}

	/* ══════════════════════════════════════════════════════
	 *  SUBSCRIBERS / CONTACTS
	 * ══════════════════════════════════════════════════════ */

	private function register_subscriber_routes(): void {
		$c = new SubscriberController();

		// GET /email/subscribers
		register_rest_route( $this->ns, '/email/subscribers', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'page'    => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
				'search'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'status'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'list_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				'tag_id'  => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
			),
		) );

		// GET /email/subscribers/count — recipient count for campaign builder
		register_rest_route( $this->ns, '/email/subscribers/count', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'count' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'mode'             => array( 'type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_text_field' ),
				'list_ids'         => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'integer' ) ),
				'tag_ids'          => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'integer' ) ),
				'segment_type'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'segment_days'     => array( 'type' => 'integer', 'default' => 90, 'sanitize_callback' => 'absint' ),
				'segment_limit'    => array( 'type' => 'integer', 'default' => 500, 'sanitize_callback' => 'absint' ),
				'exclude_list_ids' => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'integer' ) ),
				'exclude_tag_ids'  => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'integer' ) ),
			),
		) );

		// POST /email/subscribers
		register_rest_route( $this->ns, '/email/subscribers', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'store' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'email'      => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
				'first_name' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'last_name'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'status'     => array( 'type' => 'string', 'default' => 'subscribed', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// GET /email/subscribers/{id}
		register_rest_route( $this->ns, '/email/subscribers/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'show' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// PUT /email/subscribers/{id}
		register_rest_route( $this->ns, '/email/subscribers/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $c, 'update' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// DELETE /email/subscribers/{id}
		register_rest_route( $this->ns, '/email/subscribers/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'destroy' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /email/subscribers/bulk
		register_rest_route( $this->ns, '/email/subscribers/bulk', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'bulk' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'action' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array(
						'delete', 'subscribe', 'unsubscribe', 'pending', 'bounced', 'complained',
						'assign_tags', 'remove_tags', 'assign_lists', 'remove_lists',
					),
				),
				'ids'    => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'integer' ) ),
				'all_matching' => array( 'type' => 'boolean', 'default' => false ),
				'search' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'status' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'list_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				'tag_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				'tag_ids'  => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'integer' ) ),
				'list_ids' => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'integer' ) ),
			),
		) );

		// POST /email/subscribers/{id}/notes
		register_rest_route( $this->ns, '/email/subscribers/(?P<id>\d+)/notes', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'add_note' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'title'       => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'description' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
				'type'        => array( 'type' => 'string', 'default' => 'note', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// DELETE /email/subscribers/{id}/notes/{note_id}
		register_rest_route( $this->ns, '/email/subscribers/(?P<id>\d+)/notes/(?P<note_id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'delete_note' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /email/subscribers/import
		register_rest_route( $this->ns, '/email/subscribers/import', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'import' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'source' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'csv', 'wp_users', 'woocommerce' ),
				),
			),
		) );

		// POST /email/subscribe (public — no auth required)
		register_rest_route( $this->ns, '/email/subscribe', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'public_subscribe' ),
			'permission_callback' => array( $this, 'subscribe_rate_limit_permission' ),
			'args'                => array(
				'email'      => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
				'first_name' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'last_name'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'list_id'    => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				'aime_ts'    => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				'aime_token' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// POST /email/webhook/subscribe (public — API key auth)
		register_rest_route( $this->ns, '/email/webhook/subscribe', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'webhook_subscribe' ),
			'permission_callback' => array( $this, 'webhook_rate_limit_permission' ),
			'args'                => array(
				'email'      => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
				'first_name' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'last_name'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'list_id'    => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				'tag_ids'    => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'integer' ) ),
				'status'     => array( 'type' => 'string', 'default' => 'subscribed', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// POST /email/webhook/complaint (public — API key auth; Pro). ESP feedback-loop endpoint.
		register_rest_route( $this->ns, '/email/webhook/complaint', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'webhook_complaint' ),
			'permission_callback' => array( $this, 'webhook_rate_limit_permission' ),
			'args'                => array(
				'email'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_email' ),
				'emails' => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'string' ) ),
			),
		) );

		// POST /email/webhook/bounce (public — API key auth; Pro). ESP bounce-notification endpoint.
		register_rest_route( $this->ns, '/email/webhook/bounce', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'webhook_bounce' ),
			'permission_callback' => array( $this, 'webhook_rate_limit_permission' ),
			'args'                => array(
				'email'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_email' ),
				'emails' => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'string' ) ),
			),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  CAMPAIGNS
	 * ══════════════════════════════════════════════════════ */

	private function register_campaign_routes(): void {
		$c = new CampaignController();

		// GET /email/campaigns
		register_rest_route( $this->ns, '/email/campaigns', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'page'    => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
				'status'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'search'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// POST /email/campaigns
		register_rest_route( $this->ns, '/email/usage', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'usage' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/email/campaigns', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'store' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'title' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// GET /email/campaigns/{id}
		register_rest_route( $this->ns, '/email/campaigns/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'show' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// PUT /email/campaigns/{id}
		register_rest_route( $this->ns, '/email/campaigns/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $c, 'update' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// DELETE /email/campaigns/{id}
		register_rest_route( $this->ns, '/email/campaigns/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'destroy' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /email/campaigns/{id}/send
		register_rest_route( $this->ns, '/email/campaigns/(?P<id>\d+)/send', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'send' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'scheduled_at' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// POST /email/campaigns/{id}/test
		register_rest_route( $this->ns, '/email/campaigns/(?P<id>\d+)/test', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'send_test' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'email' => array( 'type' => 'string', 'required' => true, 'format' => 'email', 'sanitize_callback' => 'sanitize_email' ),
			),
		) );

		// POST /email/campaigns/{id}/duplicate
		register_rest_route( $this->ns, '/email/campaigns/(?P<id>\d+)/duplicate', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'duplicate' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /email/campaigns/{id}/variant
		register_rest_route( $this->ns, '/email/campaigns/(?P<id>\d+)/variant', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'create_variant' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'email_subject' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// GET /email/campaigns/{id}/progress
		register_rest_route( $this->ns, '/email/campaigns/(?P<id>\d+)/progress', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'progress' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /email/campaigns/{id}/process
		register_rest_route( $this->ns, '/email/campaigns/(?P<id>\d+)/process', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'process_tick' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'action' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  AUTOMATIONS / FUNNELS
	 * ══════════════════════════════════════════════════════ */

	private function register_funnel_routes(): void {
		$c = new FunnelController();

		// GET /email/automations
		register_rest_route( $this->ns, '/email/automations', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'page'    => array( 'type' => 'integer', 'default' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 20 ),
				'status'  => array( 'type' => 'string', 'default' => '' ),
			),
		) );

		// POST /email/automations
		register_rest_route( $this->ns, '/email/automations', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'store' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'title' => array( 'type' => 'string', 'required' => true ),
			),
		) );

		// GET /email/automations/{id}
		register_rest_route( $this->ns, '/email/automations/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'show' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// PUT /email/automations/{id}
		register_rest_route( $this->ns, '/email/automations/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $c, 'update' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// DELETE /email/automations/{id}
		register_rest_route( $this->ns, '/email/automations/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'destroy' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /email/automations/{id}/duplicate
		register_rest_route( $this->ns, '/email/automations/(?P<id>\d+)/duplicate', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'duplicate' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// PUT /email/automations/{id}/sequences
		register_rest_route( $this->ns, '/email/automations/(?P<id>\d+)/sequences', array(
			'methods'             => 'PUT',
			'callback'            => array( $c, 'save_sequences' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'sequences' => array( 'type' => 'array', 'required' => true ),
			),
		) );

		// GET /email/automations/{id}/subscribers
		register_rest_route( $this->ns, '/email/automations/(?P<id>\d+)/subscribers', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'get_subscribers' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// GET /email/automations/triggers
		register_rest_route( $this->ns, '/email/automations/triggers', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'get_triggers' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// GET /email/automations/actions
		register_rest_route( $this->ns, '/email/automations/actions', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'get_actions' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  TEMPLATES
	 * ══════════════════════════════════════════════════════ */

	private function register_template_routes(): void {
		$c = new TemplateController();

		// GET /email/templates
		register_rest_route( $this->ns, '/email/templates', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'page'     => array( 'type' => 'integer', 'default' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 20 ),
				'category' => array( 'type' => 'string', 'default' => '' ),
				'search'   => array( 'type' => 'string', 'default' => '' ),
			),
		) );

		// POST /email/templates
		register_rest_route( $this->ns, '/email/templates', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'store' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'name' => array( 'type' => 'string', 'required' => true ),
			),
		) );

		// GET /email/templates/{id}
		register_rest_route( $this->ns, '/email/templates/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'show' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// PUT /email/templates/{id}
		register_rest_route( $this->ns, '/email/templates/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $c, 'update' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// DELETE /email/templates/{id}
		register_rest_route( $this->ns, '/email/templates/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'destroy' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /email/templates/{id}/duplicate
		register_rest_route( $this->ns, '/email/templates/(?P<id>\d+)/duplicate', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'duplicate' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// GET /email/templates/categories
		register_rest_route( $this->ns, '/email/templates/categories', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'get_categories' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /email/templates/install-defaults
		register_rest_route( $this->ns, '/email/templates/install-defaults', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'install_defaults' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  SETTINGS — Tags / Lists / Custom Fields / Labels / General
	 * ══════════════════════════════════════════════════════ */

	private function register_settings_routes(): void {
		$c = new SettingsController();

		/* ── Tags ──────────────────────────────────────── */
		register_rest_route( $this->ns, '/email/tags', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $c, 'list_tags' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'create_tag' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'title' => array( 'type' => 'string', 'required' => true ),
				),
			),
		) );

		register_rest_route( $this->ns, '/email/tags/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( $c, 'update_tag' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $c, 'delete_tag' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		/* ── Lists ─────────────────────────────────────── */
		register_rest_route( $this->ns, '/email/lists', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $c, 'list_lists' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'create_list' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'title' => array( 'type' => 'string', 'required' => true ),
				),
			),
		) );

		register_rest_route( $this->ns, '/email/lists/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( $c, 'update_list' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $c, 'delete_list' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		/* ── Custom Fields ─────────────────────────────── */
		register_rest_route( $this->ns, '/email/custom-fields', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $c, 'list_custom_fields' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'save_custom_field' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'field_key'  => array( 'type' => 'string', 'required' => true ),
					'label'      => array( 'type' => 'string', 'required' => true ),
					'field_type' => array( 'type' => 'string', 'required' => true ),
				),
			),
		) );

		register_rest_route( $this->ns, '/email/custom-fields/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'delete_custom_field' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		/* ── General Settings ──────────────────────────── */
		register_rest_route( $this->ns, '/email/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $c, 'get_settings' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'save_settings' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  ANALYTICS / REPORTING
	 * ══════════════════════════════════════════════════════ */

	private function register_analytics_routes(): void {
		$c = new AnalyticsController();

		// GET /email/analytics/overview
		register_rest_route( $this->ns, '/email/analytics/overview', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'overview' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'days' => array( 'type' => 'integer', 'default' => 30 ),
			),
		) );

		// GET /email/analytics/campaigns/{id}
		register_rest_route( $this->ns, '/email/analytics/campaigns/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'campaign_report' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// GET /email/analytics/subscribers/{id}
		register_rest_route( $this->ns, '/email/analytics/subscribers/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'subscriber_activity' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// GET /email/analytics/automations/{id}
		register_rest_route( $this->ns, '/email/analytics/automations/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'funnel_report' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// GET /email/analytics/activity-log
		register_rest_route( $this->ns, '/email/analytics/activity-log', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'activity_log' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'page'    => array( 'type' => 'integer', 'default' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 50 ),
			),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  AI — intelligent marketing features
	 * ══════════════════════════════════════════════════════ */

	private function register_ai_routes(): void {
		$c = new AiController();

		// POST /email/ai/generate-template
		register_rest_route( $this->ns, '/email/ai/generate-template', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'generate_template' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'prompt'      => array( 'type' => 'string', 'required' => true ),
				'style'       => array( 'type' => 'string', 'default' => 'professional' ),
				'layout_mode' => array( 'type' => 'string', 'default' => 'table-safe' ),
			),
		) );

		// POST /email/ai/optimize-subject
		register_rest_route( $this->ns, '/email/ai/optimize-subject', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'optimize_subject' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				// Not required: with the field empty the email body is the brief
				// and the AI writes the subject line from it.
				'subject'       => array( 'type' => 'string', 'default' => '' ),
				'audience'      => array( 'type' => 'string', 'default' => 'general' ),
				'campaign_name' => array( 'type' => 'string', 'default' => '' ),
				'content'       => array( 'type' => 'string', 'default' => '' ),
			),
		) );

		// POST /email/ai/generate-preview-text
		register_rest_route( $this->ns, '/email/ai/generate-preview-text', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'generate_preview_text' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'subject'       => array( 'type' => 'string', 'default' => '' ),
				'campaign_name' => array( 'type' => 'string', 'default' => '' ),
				'content'       => array( 'type' => 'string', 'default' => '' ),
				'current'       => array( 'type' => 'string', 'default' => '' ),
			),
		) );

		// POST /email/ai/score-content
		register_rest_route( $this->ns, '/email/ai/score-content', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'score_content' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'content' => array( 'type' => 'string', 'required' => true ),
				'subject' => array( 'type' => 'string', 'default' => '' ),
			),
		) );

		// GET /email/ai/send-time
		register_rest_route( $this->ns, '/email/ai/send-time', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'optimize_send_time' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'industry' => array( 'type' => 'string', 'default' => 'general' ),
			),
		) );

		// GET /email/ai/subscriber-insights/{id}
		register_rest_route( $this->ns, '/email/ai/subscriber-insights/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'subscriber_insights' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// GET /email/ai/campaign-recommendations
		register_rest_route( $this->ns, '/email/ai/campaign-recommendations', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'campaign_recommendations' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /email/ai/spam-check
		register_rest_route( $this->ns, '/email/ai/spam-check', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'spam_check' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'subject' => array( 'type' => 'string', 'default' => '' ),
				'content' => array( 'type' => 'string', 'required' => true ),
			),
		) );

		// POST /email/ai/rewrite-without-spam
		register_rest_route( $this->ns, '/email/ai/rewrite-without-spam', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'rewrite_without_spam' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'subject'    => array( 'type' => 'string', 'default' => '' ),
				'content'    => array( 'type' => 'string', 'default' => '' ),
				'spam_words' => array( 'type' => 'array', 'default' => array() ),
			),
		) );

		// GET /email/ai/automation-suggestions
		register_rest_route( $this->ns, '/email/ai/automation-suggestions', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'automation_suggestions' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'instruction' => array( 'type' => 'string', 'default' => '' ),
				'goal'        => array( 'type' => 'string', 'default' => '' ),
				'trigger'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		// GET /email/ai/segment-suggestions
		register_rest_route( $this->ns, '/email/ai/segment-suggestions', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'segment_suggestions' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// GET /email/ai/ab-test-analysis/{id}
		register_rest_route( $this->ns, '/email/ai/ab-test-analysis/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'ab_test_analysis' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  SMTP CONNECTIONS
	 * ════════════════════════════════════════════════════ */

	private function register_smtp_routes(): void {

		// GET|POST /email/smtp
		register_rest_route( $this->ns, '/email/smtp', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_smtp_connections' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_smtp_connection' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		// DELETE /email/smtp/{id}
		register_rest_route( $this->ns, '/email/smtp/(?P<id>(?!errors$|site-mail$)[a-z0-9_]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_smtp_connection' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /email/smtp/{id}/test
		register_rest_route( $this->ns, '/email/smtp/(?P<id>(?!errors$|site-mail$)[a-z0-9_]+)/test', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'test_smtp_connection' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /email/smtp/{id}/move
		register_rest_route( $this->ns, '/email/smtp/(?P<id>(?!errors$|site-mail$)[a-z0-9_]+)/move', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'move_smtp_connection' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// GET|POST /email/smtp/site-mail
		register_rest_route( $this->ns, '/email/smtp/site-mail', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_smtp_site_mail' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_smtp_site_mail' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		// GET|DELETE /email/smtp/errors
		register_rest_route( $this->ns, '/email/smtp/errors', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_smtp_error_logs' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'clear_smtp_error_logs' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );
	}

	/**
	 * List SMTP connections (passwords masked).
	 */
	public function get_smtp_connections( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( SmtpProvider::get_connections_for_api() );
	}

	/**
	 * Get whether the primary SMTP connection handles all site emails.
	 */
	public function get_smtp_site_mail( \WP_REST_Request $request ): \WP_REST_Response {
		$detected_plugins = SmtpProvider::get_detected_smtp_plugins();

		return new \WP_REST_Response( array(
			'enabled'                 => SmtpProvider::is_site_mail_enabled(),
			'show_option'             => ! empty( $detected_plugins ),
			'detected_smtp_plugins'   => $detected_plugins,
		) );
	}

	/**
	 * Get recent site-wide SMTP mail failures.
	 */
	public function get_smtp_error_logs( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array(
			'logs'  => SmtpProvider::get_site_mail_error_logs(),
			'limit' => SmtpProvider::SITE_MAIL_ERROR_LOG_LIMIT,
		) );
	}

	/**
	 * Clear recent site-wide SMTP mail failures.
	 */
	public function clear_smtp_error_logs( \WP_REST_Request $request ): \WP_REST_Response {
		SmtpProvider::clear_site_mail_error_logs();

		return new \WP_REST_Response( array(
			'logs'    => array(),
			'message' => __( 'SMTP error logs cleared.', 'ai-marketing-expert' ),
		) );
	}

	/**
	 * Update whether the primary SMTP connection handles all site emails.
	 */
	public function update_smtp_site_mail( \WP_REST_Request $request ): \WP_REST_Response {
		$data    = $request->get_json_params();
		$data    = is_array( $data ) ? $data : array();
		$enabled = filter_var( $data['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN );

		SmtpProvider::update_site_mail_enabled( $enabled );
		$detected_plugins = SmtpProvider::get_detected_smtp_plugins();

		return new \WP_REST_Response( array(
			'enabled'                 => SmtpProvider::is_site_mail_enabled(),
			'show_option'             => ! empty( $detected_plugins ),
			'detected_smtp_plugins'   => $detected_plugins,
			'message'                 => $enabled
				? __( 'AI Marketing Expert SMTP will handle all site emails.', 'ai-marketing-expert' )
				: __( 'AI Marketing Expert SMTP will only send this plugin\'s own emails.', 'ai-marketing-expert' ),
		) );
	}

	/**
	 * Create or update an SMTP connection.
	 */
	public function save_smtp_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();

		if ( ! aime_has_pro() && empty( $data['id'] ) ) {
			$limits = aime_free_limits();
			$max    = (int) ( $limits['email_smtp_connections'] ?? 2 );
			$counted_connections = 0;
			foreach ( SmtpProvider::get_connections() as $connection ) {
				if ( 'wp_mail' !== ( $connection['provider'] ?? 'wp_mail' ) ) {
					$counted_connections++;
				}
			}
			if ( $counted_connections >= $max ) {
				return new \WP_REST_Response( array(
					'message' => sprintf(
						/* translators: %d: number of SMTP connections allowed on the free plan. */
						__( 'Free sites can create up to %d SMTP connections. Upgrade to Pro for unlimited connections.', 'ai-marketing-expert' ),
						$max
					),
				), 403 );
			}
		}

		try {
			$conn = SmtpProvider::save_connection( $data );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( array(
				'message' => $e->getMessage(),
			), 500 );
		}

		// Mask password in response.
		$conn['has_password'] = ! empty( $conn['smtp_password'] );
		$conn['smtp_password'] = $conn['has_password'] ? SmtpProvider::PASSWORD_MASK : '';

		return new \WP_REST_Response( array(
			'connection'  => $conn,
			'connections' => SmtpProvider::get_connections_for_api(),
			'message'     => __( 'Connection saved successfully.', 'ai-marketing-expert' ),
		) );
	}

	/**
	 * Delete an SMTP connection.
	 */
	public function delete_smtp_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$id = sanitize_key( $request->get_param( 'id' ) );
		SmtpProvider::delete_connection( $id );

		return new \WP_REST_Response( array(
			'message' => __( 'Connection deleted.', 'ai-marketing-expert' ),
		) );
	}

	/**
	 * Test a specific SMTP connection.
	 */
	public function test_smtp_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$id = sanitize_key( $request->get_param( 'id' ) );
		$to = sanitize_email( $request->get_param( 'to' ) ?? '' );

		$result = SmtpProvider::test_connection_by_id( $id, $to );

		return new \WP_REST_Response( $result );
	}

	/**
	 * Move an SMTP connection within fallback order.
	 */
	public function move_smtp_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$id        = sanitize_key( $request->get_param( 'id' ) );
		$data      = $request->get_json_params();
		$data      = is_array( $data ) ? $data : array();
		$direction = sanitize_key( $data['direction'] ?? '' );

		try {
			$connections = SmtpProvider::move_connection( $id, $direction );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( array( 'message' => $e->getMessage() ), 500 );
		}

		if ( null === $connections ) {
			return new \WP_REST_Response( array( 'message' => __( 'SMTP connection order could not be changed.', 'ai-marketing-expert' ) ), 400 );
		}

		return new \WP_REST_Response( array(
			'connections' => SmtpProvider::get_connections_for_api(),
			'message'     => __( 'SMTP sending order updated.', 'ai-marketing-expert' ),
		) );
	}
}
