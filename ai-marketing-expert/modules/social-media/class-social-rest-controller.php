<?php
/**
 * Social REST Controller — central route dispatcher.
 *
 * Registers all REST routes for the Social Media module and delegates
 * to domain-specific controllers.
 *
 * @package WPSpace\AiMarketingExpert\Modules\SocialMedia
 */

namespace WPSpace\AiMarketingExpert\Modules\SocialMedia;

use WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers\AccountController;
use WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers\PostController;
use WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers\ScheduleController;
use WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers\AnalyticsController;
use WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers\SettingsController;
use WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers\AiController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SocialRestController {

	private string $ns;

	public function __construct() {
		$this->ns = aime_rest_namespace();
	}

	public function register_routes(): void {
		$this->register_account_routes();
		$this->register_post_routes();
		$this->register_schedule_routes();
		$this->register_ai_routes();
		$this->register_analytics_routes();
		$this->register_settings_routes();
	}

	public function admin_permission(): bool|\WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'ai-marketing-expert' ), array( 'status' => 403 ) );
	}

	/* ══ ACCOUNTS ══ */

	private function register_account_routes(): void {
		$c = new AccountController();

		register_rest_route( $this->ns, '/social/accounts', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/social/accounts/connect', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'connect' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'platform' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'facebook', 'instagram', 'x' ), 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $this->ns, '/social/accounts/callback', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'callback' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'platform' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'code'     => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'state'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $this->ns, '/social/accounts/connect-manual', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'connect_manual' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
					'platform'          => array( 'type' => 'string', 'required' => true, 'enum' => array( 'facebook', 'instagram', 'x' ), 'sanitize_callback' => 'sanitize_text_field' ),
					'access_token'     => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'access_secret'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'api_key'          => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'api_secret'       => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'name'             => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'platform_user_id' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $this->ns, '/social/accounts/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $c, 'update' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'name'             => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'access_token'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'access_secret'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'api_key'          => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'api_secret'       => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'platform_user_id' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $this->ns, '/social/accounts/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'disconnect' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/social/accounts/(?P<id>\d+)/refresh', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'refresh' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/social/accounts/(?P<id>\d+)/test', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'test' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/* ══ POSTS ══ */

	private function register_post_routes(): void {
		$c = new PostController();

		register_rest_route( $this->ns, '/social/posts', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'page'       => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
				'per_page'   => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
				'status'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'account_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				'search'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $this->ns, '/social/posts', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'store' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'account_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				'content'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
				'hashtags'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		) );

		register_rest_route( $this->ns, '/social/posts/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'show' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/social/posts/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $c, 'update' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/social/posts/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $c, 'destroy' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/social/posts/(?P<id>\d+)/publish', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'publish' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/social/posts/bulk', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'bulk' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'action' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'delete', 'publish' ), 'sanitize_callback' => 'sanitize_text_field' ),
				'ids'    => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'integer' ) ),
			),
		) );
	}

	/* ══ SCHEDULE ══ */

	private function register_schedule_routes(): void {
		$c = new ScheduleController();

		register_rest_route( $this->ns, '/social/schedule', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'start' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'end'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $this->ns, '/social/schedule', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'store' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'account_id'   => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				'content'      => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
				'scheduled_at' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $this->ns, '/social/schedule/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $c, 'reschedule' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'scheduled_at' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	/* ══ AI GENERATION ══ */

	private function register_ai_routes(): void {
		$c = new AiController();

		register_rest_route( $this->ns, '/social/ai/caption', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'generate_caption' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'topic'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
				'platform' => array( 'type' => 'string', 'default' => 'facebook', 'sanitize_callback' => 'sanitize_text_field' ),
				'tone'     => array( 'type' => 'string', 'default' => 'professional', 'sanitize_callback' => 'sanitize_text_field' ),
				'context'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		) );

		register_rest_route( $this->ns, '/social/ai/hashtags', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'generate_hashtags' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'content'  => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
				'platform' => array( 'type' => 'string', 'default' => 'facebook', 'sanitize_callback' => 'sanitize_text_field' ),
				'count'    => array( 'type' => 'integer', 'default' => 10, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( $this->ns, '/social/ai/repurpose', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'repurpose' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'article_id'  => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
				'wp_post_id'  => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
				'platform'    => array( 'type' => 'string', 'default' => 'facebook', 'sanitize_callback' => 'sanitize_text_field' ),
				'format'      => array( 'type' => 'string', 'default' => 'summary', 'enum' => array( 'summary', 'thread', 'quotes' ), 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $this->ns, '/social/ai/image-caption', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'image_caption' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'image_url' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw' ),
				'platform'  => array( 'type' => 'string', 'default' => 'facebook', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $this->ns, '/social/ai/generate-image', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'generate_image' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'prompt'   => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'content'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'platform' => array( 'type' => 'string', 'default' => 'facebook', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	/* ══ ANALYTICS ══ */

	private function register_analytics_routes(): void {
		$c = new AnalyticsController();

		register_rest_route( $this->ns, '/social/analytics/overview', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'overview' ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => array(
				'days' => array( 'type' => 'integer', 'default' => 30, 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	/* ══ SETTINGS ══ */

	private function register_settings_routes(): void {
		$c = new SettingsController();

		register_rest_route( $this->ns, '/social/settings', array(
			'methods'             => 'GET',
			'callback'            => array( $c, 'index' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $this->ns, '/social/settings', array(
			'methods'             => 'POST',
			'callback'            => array( $c, 'save' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}
}
