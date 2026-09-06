<?php
/**
 * Article Controller — CRUD for generated articles.
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services\PublisherService;
use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers\WorkflowController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ArticleController {
	private const CACHE_GROUP = 'aime_content_articles';
	private const CACHE_TTL   = 60;

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

	private function scheduled_limit_reached( int $exclude_id = 0 ): bool {
		if ( aime_has_pro() ) {
			return false;
		}

		global $wpdb;
		$limits = aime_free_limits();
		$max    = (int) ( $limits['content_scheduled_posts'] ?? 3 );
		$count  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}aime_content_articles WHERE status = %s AND id != %d",
			'scheduled',
			$exclude_id
		) );

		return $count >= $max;
	}

	private static function build_cache_key( string $prefix, array $parts = array() ): string {
		return $prefix . ':' . md5( wp_json_encode( $parts ) . ':' . self::get_cache_version() );
	}

	/**
	 * Decode a JSON column that may have been double/triple-encoded historically.
	 */
	private static function decode_json_array( string $json ): array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		// Un-nest: if the result is a single-element array whose only element is itself a JSON string, unwrap it.
		while ( count( $decoded ) === 1 && is_string( $decoded[0] ) ) {
			$inner = json_decode( $decoded[0], true );
			if ( is_array( $inner ) ) {
				$decoded = $inner;
			} else {
				break;
			}
		}
		return $decoded;
	}

	/**
	 * Sanitize content HTML: run aime_kses_article (post tags + embeds) and
	 * strip browser-injected junk (e.g. Google Translate widgets).
	 */
	private static function clean_content( string $content ): string {
		// Strip Google Translate injected div.
		$content = preg_replace( '/<div\b[^>]*id\s*=\s*["\']gtx-trans["\'][^>]*>.*?<\/div>/si', '', $content );
		// Strip any remaining empty divs that may have been wrappers.
		$content = preg_replace( '/<div\b[^>]*class\s*=\s*["\']gtx-[^"\']*["\'][^>]*>.*?<\/div>/si', '', $content );
		return aime_kses_article( trim( $content ) );
	}

	/* ── LIST articles ───────────────────────────────── */

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		PublisherService::sync_due_scheduled_articles();

		$p        = $wpdb->prefix;
		$page     = max( 1, $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$search   = $request->get_param( 'search' );
		$status   = $request->get_param( 'status' );

		$where = 'WHERE 1=1';
		$args  = array();

		if ( $search ) {
			$where .= ' AND (title LIKE %s OR topic LIKE %s)';
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$args[] = $like;
			$args[] = $like;
		}

		if ( $status ) {
			$where .= ' AND status = %s';
			$args[] = $status;
		}

		$cache_key = self::build_cache_key( 'index', array(
			'page'     => $page,
			'per_page' => $per_page,
			'search'   => (string) $search,
			'status'   => (string) $status,
		) );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return new \WP_REST_Response( $cached );
		}

		// Count.
		$count_sql = "SELECT COUNT(*) FROM {$p}aime_content_articles {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : $wpdb->get_var( $count_sql ) );

		// Fetch.
		$query = "SELECT id, title, slug, status, topic, tone, word_count_target, actual_word_count, language, post_type,
					seo_score, readability_score, wp_post_id, preset_id, is_pro, created_at, updated_at, scheduled_publish_at, published_at
				  FROM {$p}aime_content_articles {$where}
				  ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$args[] = $per_page;
		$args[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$items = $wpdb->get_results( $wpdb->prepare( $query, ...$args ) );

		$response = array(
			'items'    => $items ?: array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		);

		wp_cache_set( $cache_key, $response, self::CACHE_GROUP, self::CACHE_TTL );

		return new \WP_REST_Response( $response );
	}

	/* ── SHOW single article ─────────────────────────── */

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		PublisherService::sync_due_scheduled_articles();

		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );
		$cache_key = self::build_cache_key( 'show', array( 'id' => $id ) );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return new \WP_REST_Response( $cached );
		}

		$article = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_content_articles WHERE id = %d",
			$id
		) );

		if ( ! $article ) {
			return new \WP_REST_Response( array( 'message' => __( 'Article not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$clean_content = GenerateController::clean_ai_body( (string) $article->content );
		if ( $clean_content && $clean_content !== $article->content ) {
			$clean_content = aime_kses_article( $clean_content );
			$wpdb->update(
				"{$p}aime_content_articles",
				array( 'content' => $clean_content, 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			if ( ! empty( $article->wp_post_id ) && get_post( (int) $article->wp_post_id ) ) {
				wp_update_post( array(
					'ID'           => (int) $article->wp_post_id,
					'post_content' => $clean_content,
				) );
			}
			$article->content = $clean_content;
			self::bump_cache_version();
		}

		// Decode JSON fields (with recursive unwrap for historically double-encoded data).
		$article->keywords     = self::decode_json_array( $article->keywords ?: '[]' );
		$article->category_ids = array_map( 'intval', self::decode_json_array( $article->category_ids ?: '[]' ) );
		$article->tag_ids      = self::decode_json_array( $article->tag_ids ?: '[]' );
		$article->outline      = json_decode( $article->outline ?: '[]', true );

		// Frontend permalink of the connected WP post (for the "View Post" button).
		$article->view_url = ( ! empty( $article->wp_post_id ) && get_post( (int) $article->wp_post_id ) )
			? (string) get_permalink( (int) $article->wp_post_id )
			: '';

		// Get history.
		$article->history = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$p}aime_content_history WHERE article_id = %d ORDER BY created_at DESC LIMIT 50",
			$id
		) );

		wp_cache_set( $cache_key, $article, self::CACHE_GROUP, self::CACHE_TTL );

		return new \WP_REST_Response( $article );
	}

	/* ── CREATE article ──────────────────────────────── */

	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		// Check free limit.
		if ( ! aime_has_pro() ) {
			$monthly = \WPSpace\AiMarketingExpert\Modules\ContentGenerator\ContentGeneratorModule::get_monthly_article_count();
			$limits  = aime_free_limits();
			$limit   = $limits['content_articles_per_month'] ?? 10;
			if ( $monthly >= $limit ) {
				return new \WP_REST_Response( array(
					'message'    => __( 'Monthly article limit reached. Upgrade to Pro for unlimited articles.', 'ai-marketing-expert' ),
					'limit_reached' => true,
				), 403 );
			}
		}

		global $wpdb;
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql', true );

		$title = sanitize_text_field( $request->get_param( 'title' ) );
		$topic = sanitize_text_field( $request->get_param( 'topic' ) ?: $title );

		$post_type = sanitize_key( $request->get_param( 'post_type' ) ?: 'post' );
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		$tone = sanitize_text_field( $request->get_param( 'tone' ) ?: 'professional' );
		if ( ! aime_has_pro() && in_array( $tone, array( 'humorous', 'formal', 'conversational' ), true ) ) {
			$tone = 'professional';
		}

		$word_count_target = absint( $request->get_param( 'word_count_target' ) ?: $request->get_param( 'word_count' ) ?: 1000 );
		if ( ! aime_has_pro() ) {
			$limits            = aime_free_limits();
			$word_count_target = min( $word_count_target, (int) ( $limits['content_max_words'] ?? 2000 ) );
		}

		$data = array(
			'title'             => $title,
			'slug'              => sanitize_title( $title ),
			'content'           => self::clean_content( $request->get_param( 'content' ) ?: '' ),
			'excerpt'           => sanitize_textarea_field( $request->get_param( 'excerpt' ) ?: '' ),
			'status'            => 'draft',
			'post_type'         => $post_type,
			'topic'             => $topic,
			'keywords'          => wp_json_encode( array_map( 'sanitize_text_field', (array) ( $request->get_param( 'keywords' ) ?: array() ) ) ),
			'tone'              => $tone,
			'word_count_target' => $word_count_target,
			'actual_word_count' => str_word_count( wp_strip_all_tags( $request->get_param( 'content' ) ?: '' ) ),
			'language'          => sanitize_text_field( $request->get_param( 'language' ) ?: 'en' ),
			'outline'           => wp_json_encode( $request->get_param( 'outline' ) ?: array() ),
			'category_ids'      => wp_json_encode( array_map( 'absint', (array) ( $request->get_param( 'category_ids' ) ?: array() ) ) ),
			'tag_ids'           => wp_json_encode( array_map( 'sanitize_text_field', (array) ( $request->get_param( 'tag_ids' ) ?: array() ) ) ),
			'featured_image_url' => esc_url_raw( $request->get_param( 'featured_image_url' ) ?: '' ),
			'featured_image_id'  => absint( $request->get_param( 'featured_image_id' ) ) ?: null,
			'preset_id'         => absint( $request->get_param( 'preset_id' ) ) ?: null,
			'brand_voice_id'    => aime_has_pro() ? ( absint( $request->get_param( 'brand_voice_id' ) ) ?: null ) : null,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$result = $wpdb->insert( "{$p}aime_content_articles", $data );

		if ( false === $result ) {
			return new \WP_REST_Response( array( 'message' => __( 'Failed to create article.', 'ai-marketing-expert' ) ), 500 );
		}

		$article_id = (int) $wpdb->insert_id;
		WorkflowController::save_article_version( $article_id, 'Created' );

		// Log creation.
		$wpdb->insert( "{$p}aime_content_history", array(
			'article_id' => $article_id,
			'action'     => 'created',
			'details'    => wp_json_encode( array( 'topic' => $topic ) ),
			'created_at' => $now,
		) );

		self::bump_cache_version();

		return new \WP_REST_Response( array(
			'id'      => $article_id,
			'message' => __( 'Article created.', 'ai-marketing-expert' ),
		), 201 );
	}

	/* ── UPDATE article ──────────────────────────────── */

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );
		$now = current_time( 'mysql', true );
		$cache_key = self::build_cache_key( 'exists', array( 'id' => $id ) );
		$existing  = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false === $existing ) {
			$existing = (bool) $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$p}aime_content_articles WHERE id = %d", $id ) );
			wp_cache_set( $cache_key, $existing, self::CACHE_GROUP, self::CACHE_TTL );
		}

		if ( ! $existing ) {
			return new \WP_REST_Response( array( 'message' => __( 'Article not found.', 'ai-marketing-expert' ) ), 404 );
		}

		WorkflowController::save_article_version( $id, 'Before edit' );

		$data = array( 'updated_at' => $now );

		$text_fields = array( 'title', 'topic', 'language', 'meta_title', 'post_type' );
		foreach ( $text_fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = sanitize_text_field( $request->get_param( $field ) );
			}
		}

		if ( $request->has_param( 'tone' ) ) {
			$tone = sanitize_text_field( $request->get_param( 'tone' ) );
			if ( ! aime_has_pro() && in_array( $tone, array( 'humorous', 'formal', 'conversational' ), true ) ) {
				$tone = 'professional';
			}
			$data['tone'] = $tone;
		}

		if ( $request->has_param( 'featured_image_url' ) ) {
			$data['featured_image_url'] = esc_url_raw( $request->get_param( 'featured_image_url' ) );
		}

		if ( $request->has_param( 'featured_image_id' ) ) {
			$data['featured_image_id'] = absint( $request->get_param( 'featured_image_id' ) ) ?: null;
		}

		if ( $request->has_param( 'title' ) ) {
			$data['slug'] = sanitize_title( $data['title'] );
		}

		if ( $request->has_param( 'content' ) ) {
			$content               = self::clean_content( $request->get_param( 'content' ) );
			$data['content']       = $content;
			$data['actual_word_count'] = str_word_count( wp_strip_all_tags( $content ) );
		}

		if ( $request->has_param( 'excerpt' ) ) {
			$data['excerpt'] = sanitize_textarea_field( $request->get_param( 'excerpt' ) );
		}

		if ( $request->has_param( 'meta_description' ) ) {
			$data['meta_description'] = sanitize_textarea_field( $request->get_param( 'meta_description' ) );
		}

		if ( $request->has_param( 'status' ) ) {
			$s = sanitize_text_field( $request->get_param( 'status' ) );
			if ( in_array( $s, array( 'draft', 'ready', 'review', 'scheduled', 'published', 'archived' ), true ) ) {
				$data['status'] = $s;
			}
		}

		if ( $request->has_param( 'scheduled_publish_at' ) ) {
			$data['scheduled_publish_at'] = $this->normalize_scheduled_datetime( (string) $request->get_param( 'scheduled_publish_at' ) );
		}

		$json_fields = array( 'keywords', 'category_ids', 'tag_ids', 'outline' );
		foreach ( $json_fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$raw = $request->get_param( $field );

				// If the value is a JSON string, decode it first to prevent double-encoding.
				if ( is_string( $raw ) ) {
					$decoded = json_decode( $raw, true );
					if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
						$raw = $decoded;
					}
				}

				if ( in_array( $field, array( 'keywords' ), true ) ) {
					$raw = array_map( 'sanitize_text_field', (array) $raw );
				} elseif ( 'category_ids' === $field ) {
					$raw = array_map( 'absint', (array) $raw );
				} elseif ( 'tag_ids' === $field ) {
					$raw = array_map( 'sanitize_text_field', (array) $raw );
				}
				$data[ $field ] = wp_json_encode( $raw );
			}
		}

		$int_fields = array( 'seo_score', 'readability_score', 'preset_id' );
		foreach ( $int_fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = absint( $request->get_param( $field ) );
			}
		}

		if ( $request->has_param( 'word_count_target' ) ) {
			$word_count_target = absint( $request->get_param( 'word_count_target' ) );
			if ( ! aime_has_pro() ) {
				$limits            = aime_free_limits();
				$word_count_target = min( $word_count_target, (int) ( $limits['content_max_words'] ?? 2000 ) );
			}
			$data['word_count_target'] = $word_count_target;
		}

		if ( $request->has_param( 'brand_voice_id' ) ) {
			$data['brand_voice_id'] = aime_has_pro() ? absint( $request->get_param( 'brand_voice_id' ) ) : null;
		}

		$wpdb->update( "{$p}aime_content_articles", $data, array( 'id' => $id ) );

		// Log edit.
		$wpdb->insert( "{$p}aime_content_history", array(
			'article_id' => $id,
			'action'     => 'edited',
			'details'    => wp_json_encode( array( 'fields' => array_keys( $data ) ) ),
			'created_at' => $now,
		) );

		self::bump_cache_version();

		return new \WP_REST_Response( array( 'message' => __( 'Article updated.', 'ai-marketing-expert' ) ) );
	}

	/* ── DELETE article ──────────────────────────────── */

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );
		$cache_key = self::build_cache_key( 'delete_target', array( 'id' => $id ) );
		$article   = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false === $article ) {
			$article = $wpdb->get_row( $wpdb->prepare( "SELECT id, wp_post_id FROM {$p}aime_content_articles WHERE id = %d", $id ) );
			wp_cache_set( $cache_key, $article ?: null, self::CACHE_GROUP, self::CACHE_TTL );
		}

		if ( ! $article ) {
			return new \WP_REST_Response( array( 'message' => __( 'Article not found.', 'ai-marketing-expert' ) ), 404 );
		}

		// Delete the linked WordPress post (move to trash).
		if ( ! empty( $article->wp_post_id ) ) {
			wp_delete_post( (int) $article->wp_post_id, true );
		}

		$wpdb->delete( "{$p}aime_content_articles", array( 'id' => $id ) );
		$wpdb->delete( "{$p}aime_content_history", array( 'article_id' => $id ) );
		self::bump_cache_version();

		return new \WP_REST_Response( array( 'message' => __( 'Article deleted.', 'ai-marketing-expert' ) ) );
	}

	/* ── PUBLISH to WordPress ────────────────────────── */

	public function publish( \WP_REST_Request $request ): \WP_REST_Response {
		$id          = absint( $request->get_param( 'id' ) );
		$post_status = sanitize_text_field( $request->get_param( 'post_status' ) ?: 'draft' );
		$scheduled_at = $this->normalize_scheduled_datetime( (string) ( $request->get_param( 'scheduled_publish_at' ) ?: '' ) );
		if ( 'future' === $post_status && $scheduled_at && $this->scheduled_limit_reached( $id ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Free sites can schedule up to 3 posts at a time. Upgrade to Pro for more scheduled posts.', 'ai-marketing-expert' ), 'pro_required' => true ), 403 );
		}

		$publisher = new PublisherService();
		$result    = $publisher->publish( $id, $post_status, $scheduled_at );
		if ( ! empty( $result['success'] ) ) {
			self::bump_cache_version();
		}
		return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	public function schedule_publish( \WP_REST_Request $request ): \WP_REST_Response {
		$id           = absint( $request->get_param( 'id' ) );
		$scheduled_at = $this->normalize_scheduled_datetime( (string) $request->get_param( 'scheduled_publish_at' ) );

		if ( ! $scheduled_at ) {
			return new \WP_REST_Response( array( 'message' => __( 'Valid schedule date and time is required.', 'ai-marketing-expert' ) ), 400 );
		}

		if ( $this->scheduled_limit_reached( $id ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Free sites can schedule up to 3 posts at a time. Upgrade to Pro for more scheduled posts.', 'ai-marketing-expert' ), 'pro_required' => true ), 403 );
		}

		$publisher = new PublisherService();
		$result    = $publisher->publish( $id, 'future', $scheduled_at );
		if ( ! empty( $result['success'] ) ) {
			self::bump_cache_version();
		}
		return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	private function normalize_scheduled_datetime( string $value ): string {
		$value = sanitize_text_field( $value );
		if ( ! $value ) {
			return '';
		}

		$value = str_replace( 'T', ' ', $value );
		$format = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
		$date   = \DateTimeImmutable::createFromFormat( $format, $value, wp_timezone() );

		return $date ? $date->format( 'Y-m-d H:i:s' ) : '';
	}

	/* ── UNPUBLISH (unlink from WP post) ─────────────── */

	public function unpublish( \WP_REST_Request $request ): \WP_REST_Response {
		$id = absint( $request->get_param( 'id' ) );

		$publisher = new PublisherService();
		$result    = $publisher->unpublish( $id );
		if ( ! empty( $result['success'] ) ) {
			self::bump_cache_version();
		}
		return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	/* ── DUPLICATE article ───────────────────────────── */

	public function duplicate( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );
		$now = current_time( 'mysql', true );
		$cache_key = self::build_cache_key( 'duplicate_source', array( 'id' => $id ) );
		$original  = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false === $original ) {
			$original = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_content_articles WHERE id = %d", $id ), ARRAY_A );
			wp_cache_set( $cache_key, $original ?: null, self::CACHE_GROUP, self::CACHE_TTL );
		}

		if ( ! $original ) {
			return new \WP_REST_Response( array( 'message' => __( 'Article not found.', 'ai-marketing-expert' ) ), 404 );
		}

		unset( $original['id'], $original['wp_post_id'], $original['published_at'] );
		$original['title']      = $original['title'] . ' (Copy)';
		$original['slug']       = sanitize_title( $original['title'] );
		$original['status']     = 'draft';
		$original['created_at'] = $now;
		$original['updated_at'] = $now;

		$wpdb->insert( "{$p}aime_content_articles", $original );
		$new_id = (int) $wpdb->insert_id;
		self::bump_cache_version();

		return new \WP_REST_Response( array(
			'id'      => $new_id,
			'message' => __( 'Article duplicated.', 'ai-marketing-expert' ),
		), 201 );
	}

	/* ── BULK action ─────────────────────────────────── */

	public function bulk( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p      = $wpdb->prefix;
		$action = sanitize_text_field( $request->get_param( 'action' ) );
		$ids    = array_map( 'absint', $request->get_param( 'ids' ) ?: array() );

		if ( empty( $ids ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'No items selected.', 'ai-marketing-expert' ) ), 400 );
		}

		$count = 0;
		if ( 'delete' === $action ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wp_post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT wp_post_id FROM {$p}aime_content_articles WHERE id IN ($placeholders) AND wp_post_id IS NOT NULL", ...$ids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}aime_content_articles WHERE id IN ($placeholders)", ...$ids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}aime_content_history WHERE article_id IN ($placeholders)", ...$ids ) );

			// Mirror single-article destroy(): removing the article also removes
			// the live WordPress post, otherwise bulk delete orphans it.
			foreach ( $wp_post_ids as $wp_post_id ) {
				if ( $wp_post_id && get_post( (int) $wp_post_id ) ) {
					wp_delete_post( (int) $wp_post_id, true );
				}
			}

			$count = count( $ids );
			self::bump_cache_version();
		}

		return new \WP_REST_Response( array(
			'message' => sprintf(
				/* translators: %d: number of items affected */
				__( '%d article(s) processed.', 'ai-marketing-expert' ),
				$count
			),
		) );
	}
}
