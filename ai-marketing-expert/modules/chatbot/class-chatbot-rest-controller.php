<?php
/**
 * Chatbot REST Controller — central route dispatcher.
 *
 * Registers all REST routes for the Chatbot module and delegates
 * to domain-specific controllers.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot
 */

namespace WPSpace\AiMarketingExpert\Modules\Chatbot;

use WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers\BotController;
use WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers\ConversationController;
use WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers\KnowledgeController;
use WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers\AnalyticsController;
use WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers\MessageController;
use WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers\PublicController;
use WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers\SettingsController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChatbotRestController {

	private string $ns;

	public function __construct() {
		$this->ns = aime_rest_namespace();
	}

	/* ────────────────────────────────────────────────────
	 *  Register all routes
	 * ──────────────────────────────────────────────────── */

	public function register_routes(): void {
		$this->register_bot_routes();
		$this->register_conversation_routes();
		$this->register_knowledge_routes();
		$this->register_analytics_routes();
		$this->register_settings_routes();
		$this->register_public_routes();
	}

	/* ── Permission callbacks ────────────────────────── */

	public function admin_permission(): bool|\WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'ai-marketing-expert' ), array( 'status' => 403 ) );
	}

	public function public_permission(): bool {
		return true;
	}

	/* ══════════════════════════════════════════════════════
	 *  BOTS
	 * ══════════════════════════════════════════════════════ */

	private function register_bot_routes(): void {
		$c = new BotController();

		// GET /chatbot/bots
		register_rest_route( $this->ns, '/chatbot/bots', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /chatbot/bots
		register_rest_route( $this->ns, '/chatbot/bots', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'store' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'name'           => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'system_prompt'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'welcome_message' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		) );

		// GET /chatbot/bots/{id}
		register_rest_route( $this->ns, '/chatbot/bots/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'show' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// PUT /chatbot/bots/{id}
		register_rest_route( $this->ns, '/chatbot/bots/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $c, 'update' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// DELETE /chatbot/bots/{id}
		register_rest_route( $this->ns, '/chatbot/bots/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'destroy' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /chatbot/bots/{id}/duplicate
		register_rest_route( $this->ns, '/chatbot/bots/(?P<id>\d+)/duplicate', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'duplicate' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  CONVERSATIONS
	 * ══════════════════════════════════════════════════════ */

	private function register_conversation_routes(): void {
		$c  = new ConversationController();
		$mc = new MessageController();

		// GET /chatbot/conversations
		register_rest_route( $this->ns, '/chatbot/conversations', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
				'status'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'bot_id'   => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				'search'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// GET /chatbot/conversations/active
		register_rest_route( $this->ns, '/chatbot/conversations/active', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'active' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// GET /chatbot/conversations/{id}
		register_rest_route( $this->ns, '/chatbot/conversations/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'show' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /chatbot/conversations/{id}/takeover
		register_rest_route( $this->ns, '/chatbot/conversations/(?P<id>\d+)/takeover', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'takeover' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /chatbot/conversations/{id}/message (agent send)
		register_rest_route( $this->ns, '/chatbot/conversations/(?P<id>\d+)/message', array(
			'methods'             => 'POST',
			'callback'            => array( $mc, 'agent_send' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'content' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		) );

		// POST /chatbot/conversations/{id}/close
		register_rest_route( $this->ns, '/chatbot/conversations/(?P<id>\d+)/close', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'close' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// DELETE /chatbot/conversations/{id}
		register_rest_route( $this->ns, '/chatbot/conversations/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'destroy' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /chatbot/conversations/export
		register_rest_route( $this->ns, '/chatbot/conversations/export', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'export' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// GET /chatbot/conversations/{id}/export — single conversation export
		register_rest_route( $this->ns, '/chatbot/conversations/(?P<id>\d+)/export', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'export_single' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /chatbot/conversations/{id}/toggle-public — toggle public visibility
		register_rest_route( $this->ns, '/chatbot/conversations/(?P<id>\d+)/toggle-public', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'toggle_public' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /chatbot/conversations/bulk — bulk actions
		register_rest_route( $this->ns, '/chatbot/conversations/bulk', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'bulk' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  KNOWLEDGE BASE
	 * ══════════════════════════════════════════════════════ */

	private function register_knowledge_routes(): void {
		$c = new KnowledgeController();

		// GET /chatbot/bots/{id}/knowledge
		register_rest_route( $this->ns, '/chatbot/bots/(?P<bot_id>\d+)/knowledge', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'type'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'search'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'type' => 'integer', 'default' => 50, 'sanitize_callback' => 'absint' ),
			),
		) );

		// POST /chatbot/bots/{id}/knowledge
		register_rest_route( $this->ns, '/chatbot/bots/(?P<bot_id>\d+)/knowledge', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'store' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'type'     => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'question' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'answer'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'content'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		) );

		// PUT /chatbot/knowledge/{id}
		register_rest_route( $this->ns, '/chatbot/knowledge/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $c, 'update' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// DELETE /chatbot/knowledge/{id}
		register_rest_route( $this->ns, '/chatbot/knowledge/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'destroy' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /chatbot/bots/{id}/knowledge/index
		register_rest_route( $this->ns, '/chatbot/bots/(?P<bot_id>\d+)/knowledge/index', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'trigger_index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'type' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// GET /chatbot/bots/{id}/knowledge/status
		register_rest_route( $this->ns, '/chatbot/bots/(?P<bot_id>\d+)/knowledge/status', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index_status' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  ANALYTICS
	 * ══════════════════════════════════════════════════════ */

	private function register_analytics_routes(): void {
		$c = new AnalyticsController();

		// GET /chatbot/analytics/overview
		register_rest_route( $this->ns, '/chatbot/analytics/overview', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'overview' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'days'   => array( 'type' => 'integer', 'default' => 30, 'sanitize_callback' => 'absint' ),
				'bot_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
			),
		) );

		// GET /chatbot/analytics/conversations
		register_rest_route( $this->ns, '/chatbot/analytics/conversations', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'conversation_trends' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'days'   => array( 'type' => 'integer', 'default' => 30, 'sanitize_callback' => 'absint' ),
				'bot_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  SETTINGS
	 * ══════════════════════════════════════════════════════ */

	private function register_settings_routes(): void {
		$c = new SettingsController();

		// GET /chatbot/settings
		register_rest_route( $this->ns, '/chatbot/settings', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'get_settings' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /chatbot/settings
		register_rest_route( $this->ns, '/chatbot/settings', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'save_settings' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  PUBLIC (visitor-facing, no auth)
	 * ══════════════════════════════════════════════════════ */

	private function register_public_routes(): void {
		$c = new PublicController();

		// POST /chatbot/public/start
		register_rest_route( $this->ns, '/chatbot/public/start', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'start' ),
			'permission_callback' => array( $this, 'public_permission' ),
			'args'                => array(
				'bot_id'     => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				'visitor_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'page_url'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw' ),
				'source'     => array( 'type' => 'string', 'default' => 'widget', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// POST /chatbot/public/message
		register_rest_route( $this->ns, '/chatbot/public/message', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'message' ),
			'permission_callback' => array( $this, 'public_permission' ),
			'args'                => array(
				'conversation_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				'visitor_id'      => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'conversation_token' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'content'         => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		) );

		// POST /chatbot/public/lead
		register_rest_route( $this->ns, '/chatbot/public/lead', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'submit_lead' ),
			'permission_callback' => array( $this, 'public_permission' ),
			'args'                => array(
				'conversation_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				'visitor_id'      => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'conversation_token' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'name'            => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'email'           => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
				'phone'           => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'company'         => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// GET /chatbot/public/poll/{conversation_id}
		register_rest_route( $this->ns, '/chatbot/public/poll/(?P<conversation_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'poll' ),
			'permission_callback' => array( $this, 'public_permission' ),
			'args'                => array(
				'visitor_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'conversation_token' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'after'      => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
			),
		) );
	}
}
