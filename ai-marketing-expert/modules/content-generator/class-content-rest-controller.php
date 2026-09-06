<?php
/**
 * Content REST Controller — central route dispatcher.
 *
 * Registers all REST routes for the Content Generator module and delegates
 * to domain-specific controllers.
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator
 */

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator;

use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers\ArticleController;
use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers\GenerateController;
use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers\PresetController;
use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers\AnalyticsController;
use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers\SettingsController;
use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers\WorkflowController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContentRestController {

	private string $ns;

	public function __construct() {
		$this->ns = aime_rest_namespace();
	}

	/* ────────────────────────────────────────────────────
	 *  Register all routes
	 * ──────────────────────────────────────────────────── */

	public function register_routes(): void {
		$this->register_article_routes();
		$this->register_generate_routes();
		$this->register_preset_routes();
		$this->register_analytics_routes();
		$this->register_settings_routes();
		$this->register_workflow_routes();
	}

	/* ── Permission callbacks ────────────────────────── */

	public function admin_permission(): bool|\WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'ai-marketing-expert' ), array( 'status' => 403 ) );
	}

	/* ══════════════════════════════════════════════════════
	 *  ARTICLES
	 * ══════════════════════════════════════════════════════ */

	private function register_article_routes(): void {
		$c = new ArticleController();

		// GET /content/articles
		register_rest_route( $this->ns, '/content/articles', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
				'search'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'status'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// POST /content/articles
		register_rest_route( $this->ns, '/content/articles', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'store' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'title' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'topic' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// GET /content/articles/{id}
		register_rest_route( $this->ns, '/content/articles/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'show' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// PUT /content/articles/{id}
		register_rest_route( $this->ns, '/content/articles/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $c, 'update' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// DELETE /content/articles/{id}
		register_rest_route( $this->ns, '/content/articles/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'destroy' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /content/articles/{id}/publish
		register_rest_route( $this->ns, '/content/articles/(?P<id>\d+)/publish', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'publish' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'post_status' => array( 'type' => 'string', 'default' => 'draft', 'enum' => array( 'draft', 'publish', 'future', 'pending', 'private' ), 'sanitize_callback' => 'sanitize_text_field' ),
				'scheduled_publish_at' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $this->ns, '/content/articles/(?P<id>\d+)/schedule', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'schedule_publish' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'scheduled_publish_at' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// POST /content/articles/{id}/unpublish
		register_rest_route( $this->ns, '/content/articles/(?P<id>\d+)/unpublish', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'unpublish' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /content/articles/{id}/duplicate
		register_rest_route( $this->ns, '/content/articles/(?P<id>\d+)/duplicate', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'duplicate' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /content/articles/bulk
		register_rest_route( $this->ns, '/content/articles/bulk', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'bulk' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'action' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'delete' ) ),
				'ids'    => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'integer' ) ),
			),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  AI GENERATION
	 * ══════════════════════════════════════════════════════ */

	private function register_generate_routes(): void {
		$c = new GenerateController();

		// POST /content/generate
		register_rest_route( $this->ns, '/content/generate', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'generate' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'topic'      => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'keywords'   => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) ),
				'tone'       => array( 'type' => 'string', 'default' => 'professional', 'sanitize_callback' => 'sanitize_text_field' ),
				'word_count' => array( 'type' => 'integer', 'default' => 1000, 'sanitize_callback' => 'absint' ),
				'preset_id'  => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				'language'   => array( 'type' => 'string', 'default' => 'en', 'sanitize_callback' => 'sanitize_text_field' ),
				'outline'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'serp_context' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'include_table_of_contents' => array( 'type' => 'boolean', 'default' => false ),
				'post_type'  => array( 'type' => 'string', 'default' => 'post', 'sanitize_callback' => 'sanitize_key' ),
				'brand_voice_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				'category_ids' => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'integer' ) ),
				'tag_ids'    => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'string' ) ),
			),
		) );

		// POST /content/generate/outline
		register_rest_route( $this->ns, '/content/generate/outline', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'generate_outline' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'topic'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'keywords' => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) ),
				'tone'     => array( 'type' => 'string', 'default' => 'professional', 'sanitize_callback' => 'sanitize_text_field' ),
				'style'    => array( 'type' => 'string', 'default' => 'blog_post', 'sanitize_callback' => 'sanitize_text_field' ),
				'serp_context' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		) );

		// POST /content/generate/section
		register_rest_route( $this->ns, '/content/generate/section', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'generate_section' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'topic'         => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'section_title' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'context'       => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'tone'          => array( 'type' => 'string', 'default' => 'professional', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// POST /content/generate/improve
		register_rest_route( $this->ns, '/content/generate/improve', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'improve_content' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'content'     => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'aime_kses_article' ),
				'instruction' => array( 'type' => 'string', 'default' => 'Improve this content for clarity and engagement', 'sanitize_callback' => 'sanitize_text_field' ),
				'tone'        => array( 'type' => 'string', 'default' => 'professional', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// POST /content/generate/seo-optimize
		register_rest_route( $this->ns, '/content/generate/seo-optimize', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'seo_optimize' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'content'  => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'aime_kses_article' ),
				'keywords' => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) ),
				'title'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// POST /content/generate/meta
		register_rest_route( $this->ns, '/content/generate/meta', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'generate_meta' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'title'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'content'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'aime_kses_article' ),
				'keywords' => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) ),
			),
		) );

		// POST /content/generate/image-prompt
		register_rest_route( $this->ns, '/content/generate/image-prompt', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'generate_image_prompt' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'title'   => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'content' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'aime_kses_article' ),
			),
		) );

		// POST /content/generate/image — direct AI image generation.
		register_rest_route( $this->ns, '/content/generate/image', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'generate_image' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'title'      => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'content'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'aime_kses_article' ),
				'article_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
			),
		) );

		// GET /content/stock-images/search — search Pexels/Pixabay.
		register_rest_route( $this->ns, '/content/stock-images/search', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'stock_search' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'query'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'type' => 'integer', 'default' => 12, 'sanitize_callback' => 'absint' ),
			),
		) );

		// POST /content/stock-images/import — sideload a stock image into the media library.
		register_rest_route( $this->ns, '/content/stock-images/import', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'stock_import' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'url'        => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw' ),
				'alt'        => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'article_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  PRESETS
	 * ══════════════════════════════════════════════════════ */

	private function register_preset_routes(): void {
		$c = new PresetController();

		// GET /content/presets
		register_rest_route( $this->ns, '/content/presets', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /content/presets
		register_rest_route( $this->ns, '/content/presets', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'store' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'name'       => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'tone'       => array( 'type' => 'string', 'default' => 'professional', 'sanitize_callback' => 'sanitize_text_field' ),
				'style'      => array( 'type' => 'string', 'default' => 'blog_post', 'sanitize_callback' => 'sanitize_text_field' ),
				'word_count' => array( 'type' => 'integer', 'default' => 1000, 'sanitize_callback' => 'absint' ),
			),
		) );

		// PUT /content/presets/{id}
		register_rest_route( $this->ns, '/content/presets/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $c, 'update' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// DELETE /content/presets/{id}
		register_rest_route( $this->ns, '/content/presets/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'destroy' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  ANALYTICS
	 * ══════════════════════════════════════════════════════ */

	private function register_analytics_routes(): void {
		$c = new AnalyticsController();

		// GET /content/analytics/overview
		register_rest_route( $this->ns, '/content/analytics/overview', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'overview' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'days' => array( 'type' => 'integer', 'default' => 30, 'sanitize_callback' => 'absint' ),
			),
		) );

		// GET /content/analytics/article/{id}
		register_rest_route( $this->ns, '/content/analytics/article/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'article_report' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ══════════════════════════════════════════════════════
	 *  SETTINGS
	 * ══════════════════════════════════════════════════════ */

	private function register_settings_routes(): void {
		$c = new SettingsController();

		// GET /content/settings
		register_rest_route( $this->ns, '/content/settings', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'get_settings' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// POST /content/settings
		register_rest_route( $this->ns, '/content/settings', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'save_settings' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// GET /content/wp-taxonomies (helper: fetch WP categories & tags)
		register_rest_route( $this->ns, '/content/wp-taxonomies', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'get_wp_taxonomies' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	private function register_workflow_routes(): void {
		$c = new WorkflowController();

		register_rest_route( $this->ns, '/content/articles/(?P<id>\d+)/versions', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'get_versions' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/content/articles/(?P<id>\d+)/versions/(?P<version_id>\d+)/restore', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'restore_version' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/content/brand-voices', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $c, 'get_brand_voices' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'save_brand_voice' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( $this->ns, '/content/brand-voices/generate', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'generate_brand_voice' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'business_name' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'industry'      => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'audience'      => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'personality'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'goals'         => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		) );

		register_rest_route( $this->ns, '/content/brand-voices/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( $c, 'save_brand_voice' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $c, 'delete_brand_voice' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

	}
}
