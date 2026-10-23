<?php
/**
 * Workflow Controller — article versions and brand voices.
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers;

use WPSpace\AiMarketingExpert\AiProvider;
use WPSpace\AiMarketingExpert\Pro;
use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services\ParsesAiJson;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorkflowController {

	use ParsesAiJson;

	private const CACHE_GROUP = 'aime_content_workflow';
	private const CACHE_TTL   = 300;

	private static function get_cache_version(): string {
		$version = wp_cache_get( 'version', self::CACHE_GROUP );
		if ( false === $version ) {
			$version = '1';
			wp_cache_set( 'version', $version, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return (string) $version;
	}

	private static function bump_cache_version(): void {
		wp_cache_set( 'version', (string) microtime( true ), self::CACHE_GROUP, self::CACHE_TTL );
	}

	private static function build_cache_key( string $prefix, array $parts = array() ): string {
		return $prefix . ':' . md5( wp_json_encode( $parts ) . ':' . self::get_cache_version() );
	}

	/**
	 * Save a restorable version snapshot for an article.
	 */
	public static function save_article_version( int $article_id, string $label = 'Manual save' ): void {
		global $wpdb;
		$articles_table = $wpdb->prefix . 'aime_content_articles';
		$versions_table = $wpdb->prefix . 'aime_content_versions';
		$article_cache_key = self::build_cache_key( 'article_snapshot', array( 'article_id' => $article_id ) );
		$article           = wp_cache_get( $article_cache_key, self::CACHE_GROUP );
		if ( false === $article ) {
			$article = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $articles_table, $article_id ), ARRAY_A );
			wp_cache_set( $article_cache_key, $article ?: null, self::CACHE_GROUP, self::CACHE_TTL );
		}

		if ( ! $article ) {
			return;
		}

		$table_exists_cache_key = self::build_cache_key( 'versions_table_exists', array( 'table' => $versions_table ) );
		$table_exists           = wp_cache_get( $table_exists_cache_key, self::CACHE_GROUP );
		if ( false === $table_exists ) {
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $versions_table ) );
			wp_cache_set( $table_exists_cache_key, $table_exists ?: '', self::CACHE_GROUP, self::CACHE_TTL );
		}
		if ( ! $table_exists ) {
			return;
		}

		$wpdb->insert( $versions_table, array(
			'article_id' => $article_id,
			'label'      => sanitize_text_field( $label ),
			'snapshot'   => wp_json_encode( $article ),
			'created_at' => current_time( 'mysql', true ),
		) );
		self::bump_cache_version();
	}

	public function get_versions( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$versions_table = $wpdb->prefix . 'aime_content_versions';
		$article_id     = absint( $request->get_param( 'id' ) );
		$cache_key      = self::build_cache_key( 'versions', array( 'article_id' => $article_id ) );
		$cached         = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return new \WP_REST_Response( array( 'items' => $cached ) );
		}

		$items = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, article_id, label, created_at FROM %i WHERE article_id = %d ORDER BY created_at DESC LIMIT 25',
			$versions_table,
			$article_id
		), ARRAY_A );
		wp_cache_set( $cache_key, $items ?: array(), self::CACHE_GROUP, self::CACHE_TTL );

		return new \WP_REST_Response( array( 'items' => $items ?: array() ) );
	}

	public function restore_version( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$articles_table = $wpdb->prefix . 'aime_content_articles';
		$versions_table = $wpdb->prefix . 'aime_content_versions';
		$article_id     = absint( $request->get_param( 'id' ) );
		$version_id     = absint( $request->get_param( 'version_id' ) );
		$cache_key      = self::build_cache_key( 'restore_version', array(
			'article_id' => $article_id,
			'version_id' => $version_id,
		) );
		$version        = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false === $version ) {
			$version = $wpdb->get_row( $wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND article_id = %d',
				$versions_table,
				$version_id,
				$article_id
			), ARRAY_A );
			wp_cache_set( $cache_key, $version ?: null, self::CACHE_GROUP, self::CACHE_TTL );
		}

		if ( ! $version ) {
			return new \WP_REST_Response( array( 'message' => __( 'Version not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$snapshot = json_decode( $version['snapshot'], true );
		if ( ! is_array( $snapshot ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Version snapshot is invalid.', 'ai-marketing-expert' ) ), 400 );
		}

		self::save_article_version( $article_id, 'Before restore' );

		$allowed = array(
			'title', 'slug', 'content', 'excerpt', 'topic', 'keywords', 'tone', 'word_count_target',
			'actual_word_count', 'language', 'seo_score', 'readability_score', 'meta_title',
			'meta_description', 'outline', 'category_ids', 'tag_ids', 'featured_image_url',
			'featured_image_id', 'preset_id', 'post_type', 'brand_voice_id',
		);
		$data = array();
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $snapshot ) ) {
				$data[ $field ] = $snapshot[ $field ];
			}
		}
		$data['updated_at'] = current_time( 'mysql', true );

		$wpdb->update( $articles_table, $data, array( 'id' => $article_id ) );
		self::bump_cache_version();

		return new \WP_REST_Response( array( 'message' => __( 'Article version restored.', 'ai-marketing-expert' ) ) );
	}

	public function get_brand_voices( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$brand_voices_table = $wpdb->prefix . 'aime_content_brand_voices';
		$this->ensure_default_brand_voices();
		$cache_key = self::build_cache_key( 'brand_voices' );
		$items = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false === $items ) {
			$items = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY is_default DESC, name ASC', $brand_voices_table ), ARRAY_A );
			wp_cache_set( $cache_key, $items ?: array(), self::CACHE_GROUP, self::CACHE_TTL );
		}
		return new \WP_REST_Response( array( 'items' => $items ?: array() ) );
	}

	private function ensure_default_brand_voices(): void {
		$module = new \WPSpace\AiMarketingExpert\Modules\ContentGenerator\ContentGeneratorModule();
		$module->seed_default_brand_voices();
	}

	public function save_brand_voice( \WP_REST_Request $request ): \WP_REST_Response {
		$check = Pro::gate( 'Brand Voices' );
		if ( is_wp_error( $check ) ) {
			return new \WP_REST_Response( array( 'message' => $check->get_error_message(), 'pro_required' => true ), 403 );
		}

		global $wpdb;
		$brand_voices_table = $wpdb->prefix . 'aime_content_brand_voices';
		$id                 = absint( $request->get_param( 'id' ) );
		$now                = current_time( 'mysql', true );

		$data = array(
			'name'         => sanitize_text_field( $request->get_param( 'name' ) ),
			'description'  => sanitize_textarea_field( $request->get_param( 'description' ) ?: '' ),
			'tone_rules'   => sanitize_textarea_field( $request->get_param( 'tone_rules' ) ?: '' ),
			'style_rules'  => sanitize_textarea_field( $request->get_param( 'style_rules' ) ?: '' ),
			'avoid_rules'  => sanitize_textarea_field( $request->get_param( 'avoid_rules' ) ?: '' ),
			'is_default'   => ! empty( $request->get_param( 'is_default' ) ) ? 1 : 0,
			'updated_at'   => $now,
		);

		if ( empty( $data['name'] ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Brand voice name is required.', 'ai-marketing-expert' ) ), 400 );
		}

		if ( $data['is_default'] ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET is_default = 0', $brand_voices_table ) );
		}

		if ( $id ) {
			$wpdb->update( $brand_voices_table, $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $brand_voices_table, $data );
			$id = (int) $wpdb->insert_id;
		}
		self::bump_cache_version();

		return new \WP_REST_Response( array( 'id' => $id, 'message' => __( 'Brand voice saved.', 'ai-marketing-expert' ) ) );
	}

	public function delete_brand_voice( \WP_REST_Request $request ): \WP_REST_Response {
		$check = Pro::gate( 'Brand Voices' );
		if ( is_wp_error( $check ) ) {
			return new \WP_REST_Response( array( 'message' => $check->get_error_message(), 'pro_required' => true ), 403 );
		}

		global $wpdb;
		$brand_voices_table = $wpdb->prefix . 'aime_content_brand_voices';
		$id                 = absint( $request->get_param( 'id' ) );
		$wpdb->delete( $brand_voices_table, array( 'id' => $id ) );
		self::bump_cache_version();
		return new \WP_REST_Response( array( 'message' => __( 'Brand voice deleted.', 'ai-marketing-expert' ) ) );
	}

	public function generate_brand_voice( \WP_REST_Request $request ): \WP_REST_Response {
		$check = Pro::gate( 'AI Brand Voice' );
		if ( is_wp_error( $check ) ) {
			return new \WP_REST_Response( array( 'message' => $check->get_error_message(), 'pro_required' => true ), 403 );
		}

		$business_name = sanitize_text_field( $request->get_param( 'business_name' ) ?: '' );
		$industry      = sanitize_text_field( $request->get_param( 'industry' ) ?: '' );
		$audience      = sanitize_textarea_field( $request->get_param( 'audience' ) ?: '' );
		$personality   = sanitize_textarea_field( $request->get_param( 'personality' ) ?: '' );
		$goals         = sanitize_textarea_field( $request->get_param( 'goals' ) ?: '' );

		if ( ! $business_name && ! $industry && ! $audience && ! $personality ) {
			return new \WP_REST_Response( array( 'message' => __( 'Add business, industry, audience, or personality details first.', 'ai-marketing-expert' ) ), 400 );
		}

		$prompt = "Create a reusable brand voice profile for AI article generation.\n\n"
			. "Business name: {$business_name}\n"
			. "Industry: {$industry}\n"
			. "Audience: {$audience}\n"
			. "Desired personality: {$personality}\n"
			. "Content goals: {$goals}\n\n"
			. "Return ONLY a valid JSON object with exactly these string keys: name, description, tone_rules, style_rules, avoid_rules. "
			. "Every key must be present and every value must be filled. "
			. "Make rules specific, practical, and reusable across blog posts, product reviews, how-to guides, and SEO content. "
			. "Do not include markdown, commentary, or explanations.";

		$result = AiProvider::generate( $prompt, 'text', 1400 );
		if ( empty( $result['success'] ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI brand voice generation failed.', 'ai-marketing-expert' ), 'error' => $result['content'] ?? '' ), 500 );
		}

		$parsed = $this->parse_json_response( $result['content'] ?? '' );
		if ( ! is_array( $parsed ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI returned an invalid brand voice profile.', 'ai-marketing-expert' ) ), 500 );
		}

		$parsed = $this->normalize_generated_brand_voice( $parsed, $result['content'] ?? '' );

		$name = ! empty( $parsed['name'] ) ? $parsed['name'] : ( $business_name ?: __( 'Generated Brand Voice', 'ai-marketing-expert' ) );

		$voice = array(
			'name'        => sanitize_text_field( $name ),
			'description' => sanitize_textarea_field( $parsed['description'] ?? '' ),
			'tone_rules'  => sanitize_textarea_field( $parsed['tone_rules'] ?? '' ),
			'style_rules' => sanitize_textarea_field( $parsed['style_rules'] ?? '' ),
			'avoid_rules' => sanitize_textarea_field( $parsed['avoid_rules'] ?? '' ),
			'is_default'  => false,
		);

		return new \WP_REST_Response( array( 'voice' => $voice ) );
	}

	private function normalize_generated_brand_voice( array $parsed, string $raw ): array {
		$fields = array( 'name', 'description', 'tone_rules', 'style_rules', 'avoid_rules' );
		foreach ( $fields as $field ) {
			if ( ! empty( $parsed[ $field ] ) ) {
				continue;
			}
			if ( preg_match( '/"' . preg_quote( $field, '/' ) . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*?)"/s', $raw, $match ) ) {
				$parsed[ $field ] = stripcslashes( $match[1] );
			}
		}

		$parsed['tone_rules']  = $parsed['tone_rules'] ?? 'Use a clear, consistent tone that matches the audience and brand promise.';
		$parsed['style_rules'] = $parsed['style_rules'] ?? 'Write with practical examples, concise paragraphs, and useful takeaways.';
		$parsed['avoid_rules'] = $parsed['avoid_rules'] ?? 'Avoid vague claims, filler text, unsupported promises, and off-brand language.';

		return $parsed;
	}

	public static function get_brand_voice_prompt( int $brand_voice_id = 0 ): string {
		global $wpdb;
		$brand_voices_table = $wpdb->prefix . 'aime_content_brand_voices';
		$cache_key          = self::build_cache_key( 'brand_voice_prompt', array( 'brand_voice_id' => $brand_voice_id ) );
		$cached             = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		if ( $brand_voice_id ) {
			$voice = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $brand_voices_table, $brand_voice_id ) );
		} else {
			$voice = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE is_default = 1 ORDER BY id ASC LIMIT 1', $brand_voices_table ) );
		}

		if ( ! $voice ) {
			return '';
		}

		$prompt = trim( sprintf(
			"Brand voice: %s\nDescription: %s\nTone rules: %s\nStyle rules: %s\nAvoid: %s",
			$voice->name,
			$voice->description,
			$voice->tone_rules,
			$voice->style_rules,
			$voice->avoid_rules
		) );

		wp_cache_set( $cache_key, $prompt, self::CACHE_GROUP, self::CACHE_TTL );

		return $prompt;
	}
}
