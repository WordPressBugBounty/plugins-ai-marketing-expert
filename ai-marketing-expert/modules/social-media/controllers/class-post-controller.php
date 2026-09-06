<?php
/**
 * Post Controller — CRUD for social media posts.
 *
 * @package WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers;

use WPSpace\AiMarketingExpert\Modules\SocialMedia\Services\PlatformApiService;
use WPSpace\AiMarketingExpert\Modules\SocialMedia\Services\SocialPublisherService;
use WPSpace\AiMarketingExpert\Modules\SocialMedia\SocialMediaModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostController {
	private const CACHE_GROUP = 'aime_social_posts';
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

	private static function build_cache_key( string $prefix, array $parts = array() ): string {
		return $prefix . ':' . md5( wp_json_encode( $parts ) . ':' . self::get_cache_version() );
	}

	/**
	 * List posts with filtering and pagination.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p        = $wpdb->prefix;
		$posts_table    = $p . 'aime_social_posts';
		$accounts_table = $p . 'aime_social_accounts';
		$page     = max( 1, $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$status   = $request->get_param( 'status' );
		$account  = absint( $request->get_param( 'account_id' ) );
		$search   = $request->get_param( 'search' );
		$search_like = $search ? '%' . $wpdb->esc_like( $search ) . '%' : '';
		$cache_key = self::build_cache_key( 'index', array(
			'page'     => $page,
			'per_page' => $per_page,
			'status'   => (string) $status,
			'account'  => $account,
			'search'   => (string) $search,
		) );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return new \WP_REST_Response( $cached );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The index response is cached via wp_cache_get()/wp_cache_set() in this method.
		if ( $status && $account && $search ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i p WHERE p.status = %s AND p.account_id = %d AND p.content LIKE %s', $posts_table, $status, $account, $search_like ) );
			$items = $wpdb->get_results( $wpdb->prepare(
				'SELECT p.id, p.account_id, p.content, p.media_urls, p.hashtags, p.platform_post_id,
						p.status, p.scheduled_at, p.published_at, p.error_message, p.ai_generated,
						p.source_type, p.source_id, p.created_at, p.updated_at,
						a.platform, a.name AS account_name, a.avatar_url AS account_avatar
					 FROM %i p
					 LEFT JOIN %i a ON p.account_id = a.id
					 WHERE p.status = %s AND p.account_id = %d AND p.content LIKE %s
					 ORDER BY p.created_at DESC LIMIT %d OFFSET %d',
				$posts_table,
				$accounts_table,
				$status,
				$account,
				$search_like,
				$per_page,
				$offset
			) );
		} elseif ( $status && $account ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i p WHERE p.status = %s AND p.account_id = %d', $posts_table, $status, $account ) );
			$items = $wpdb->get_results( $wpdb->prepare(
				'SELECT p.id, p.account_id, p.content, p.media_urls, p.hashtags, p.platform_post_id,
						p.status, p.scheduled_at, p.published_at, p.error_message, p.ai_generated,
						p.source_type, p.source_id, p.created_at, p.updated_at,
						a.platform, a.name AS account_name, a.avatar_url AS account_avatar
					 FROM %i p
					 LEFT JOIN %i a ON p.account_id = a.id
					 WHERE p.status = %s AND p.account_id = %d
					 ORDER BY p.created_at DESC LIMIT %d OFFSET %d',
				$posts_table,
				$accounts_table,
				$status,
				$account,
				$per_page,
				$offset
			) );
		} elseif ( $status && $search ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i p WHERE p.status = %s AND p.content LIKE %s', $posts_table, $status, $search_like ) );
			$items = $wpdb->get_results( $wpdb->prepare(
				'SELECT p.id, p.account_id, p.content, p.media_urls, p.hashtags, p.platform_post_id,
						p.status, p.scheduled_at, p.published_at, p.error_message, p.ai_generated,
						p.source_type, p.source_id, p.created_at, p.updated_at,
						a.platform, a.name AS account_name, a.avatar_url AS account_avatar
					 FROM %i p
					 LEFT JOIN %i a ON p.account_id = a.id
					 WHERE p.status = %s AND p.content LIKE %s
					 ORDER BY p.created_at DESC LIMIT %d OFFSET %d',
				$posts_table,
				$accounts_table,
				$status,
				$search_like,
				$per_page,
				$offset
			) );
		} elseif ( $account && $search ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i p WHERE p.account_id = %d AND p.content LIKE %s', $posts_table, $account, $search_like ) );
			$items = $wpdb->get_results( $wpdb->prepare(
				'SELECT p.id, p.account_id, p.content, p.media_urls, p.hashtags, p.platform_post_id,
						p.status, p.scheduled_at, p.published_at, p.error_message, p.ai_generated,
						p.source_type, p.source_id, p.created_at, p.updated_at,
						a.platform, a.name AS account_name, a.avatar_url AS account_avatar
					 FROM %i p
					 LEFT JOIN %i a ON p.account_id = a.id
					 WHERE p.account_id = %d AND p.content LIKE %s
					 ORDER BY p.created_at DESC LIMIT %d OFFSET %d',
				$posts_table,
				$accounts_table,
				$account,
				$search_like,
				$per_page,
				$offset
			) );
		} elseif ( $status ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i p WHERE p.status = %s', $posts_table, $status ) );
			$items = $wpdb->get_results( $wpdb->prepare(
				'SELECT p.id, p.account_id, p.content, p.media_urls, p.hashtags, p.platform_post_id,
						p.status, p.scheduled_at, p.published_at, p.error_message, p.ai_generated,
						p.source_type, p.source_id, p.created_at, p.updated_at,
						a.platform, a.name AS account_name, a.avatar_url AS account_avatar
					 FROM %i p
					 LEFT JOIN %i a ON p.account_id = a.id
					 WHERE p.status = %s
					 ORDER BY p.created_at DESC LIMIT %d OFFSET %d',
				$posts_table,
				$accounts_table,
				$status,
				$per_page,
				$offset
			) );
		} elseif ( $account ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i p WHERE p.account_id = %d', $posts_table, $account ) );
			$items = $wpdb->get_results( $wpdb->prepare(
				'SELECT p.id, p.account_id, p.content, p.media_urls, p.hashtags, p.platform_post_id,
						p.status, p.scheduled_at, p.published_at, p.error_message, p.ai_generated,
						p.source_type, p.source_id, p.created_at, p.updated_at,
						a.platform, a.name AS account_name, a.avatar_url AS account_avatar
					 FROM %i p
					 LEFT JOIN %i a ON p.account_id = a.id
					 WHERE p.account_id = %d
					 ORDER BY p.created_at DESC LIMIT %d OFFSET %d',
				$posts_table,
				$accounts_table,
				$account,
				$per_page,
				$offset
			) );
		} elseif ( $search ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i p WHERE p.content LIKE %s', $posts_table, $search_like ) );
			$items = $wpdb->get_results( $wpdb->prepare(
				'SELECT p.id, p.account_id, p.content, p.media_urls, p.hashtags, p.platform_post_id,
						p.status, p.scheduled_at, p.published_at, p.error_message, p.ai_generated,
						p.source_type, p.source_id, p.created_at, p.updated_at,
						a.platform, a.name AS account_name, a.avatar_url AS account_avatar
					 FROM %i p
					 LEFT JOIN %i a ON p.account_id = a.id
					 WHERE p.content LIKE %s
					 ORDER BY p.created_at DESC LIMIT %d OFFSET %d',
				$posts_table,
				$accounts_table,
				$search_like,
				$per_page,
				$offset
			) );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i p', $posts_table ) );
			$items = $wpdb->get_results( $wpdb->prepare(
				'SELECT p.id, p.account_id, p.content, p.media_urls, p.hashtags, p.platform_post_id,
						p.status, p.scheduled_at, p.published_at, p.error_message, p.ai_generated,
						p.source_type, p.source_id, p.created_at, p.updated_at,
						a.platform, a.name AS account_name, a.avatar_url AS account_avatar
					 FROM %i p
					 LEFT JOIN %i a ON p.account_id = a.id
					 ORDER BY p.created_at DESC LIMIT %d OFFSET %d',
				$posts_table,
				$accounts_table,
				$per_page,
				$offset
			) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $items as &$item ) {
			$item->media_urls = json_decode( $item->media_urls ?: '[]', true );
		}

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

	/**
	 * Get a single post.
	 */
	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$posts_table = $p . 'aime_social_posts';
		$accounts_table = $p . 'aime_social_accounts';
		$logs_table = $p . 'aime_social_post_log';
		$id = absint( $request->get_param( 'id' ) );
		$cache_key = self::build_cache_key( 'show', array( 'id' => $id ) );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return new \WP_REST_Response( $cached );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The full show response is cached via wp_cache_get()/wp_cache_set() in this method.
		$post = $wpdb->get_row( $wpdb->prepare(
			'SELECT p.*, a.platform, a.name AS account_name, a.avatar_url AS account_avatar
			 FROM %i p
			 LEFT JOIN %i a ON p.account_id = a.id
			 WHERE p.id = %d',
			$posts_table,
			$accounts_table,
			$id
		) );

		if ( ! $post ) {
			return new \WP_REST_Response( array( 'message' => __( 'Post not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$post->media_urls = json_decode( $post->media_urls ?: '[]', true );

		// Attach log history.
		$post->history = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE post_id = %d ORDER BY created_at DESC LIMIT 50',
			$logs_table,
			$id
		) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_set( $cache_key, $post, self::CACHE_GROUP, self::CACHE_TTL );

		return new \WP_REST_Response( $post );
	}

	/**
	 * Create a new post (draft or immediate publish).
	 */
	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit check.
		if ( ! aime_has_pro() ) {
			$monthly = SocialMediaModule::get_monthly_post_count();
			$limits  = aime_free_limits();
			$limit   = $limits['social_posts_per_month'] ?? 30;
			if ( $monthly >= $limit ) {
				return new \WP_REST_Response( array(
					'message'       => __( 'Monthly post limit reached. Upgrade to Pro for unlimited posts.', 'ai-marketing-expert' ),
					'limit_reached' => true,
				), 403 );
			}
		}

		global $wpdb;
		$p   = $wpdb->prefix;
		$posts_table    = $p . 'aime_social_posts';
		$accounts_table = $p . 'aime_social_accounts';
		$logs_table     = $p . 'aime_social_post_log';
		$now = current_time( 'mysql', true );

		$account_id = absint( $request->get_param( 'account_id' ) );
		$account_cache_key = self::build_cache_key( 'account', array( 'id' => $account_id ) );
		$account = wp_cache_get( $account_cache_key, self::CACHE_GROUP );
		if ( false === $account ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The account lookup is cached via wp_cache_get()/wp_cache_set().
			$account = $wpdb->get_row( $wpdb->prepare(
				'SELECT id, platform FROM %i WHERE id = %d',
				$accounts_table,
				$account_id
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			wp_cache_set( $account_cache_key, $account ?: null, self::CACHE_GROUP, self::CACHE_TTL );
		}

		// Verify account exists.
		if ( ! $account ) {
			return new \WP_REST_Response( array( 'message' => __( 'Account not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$settings     = $this->get_social_settings();
		$content      = sanitize_textarea_field( $request->get_param( 'content' ) );
		$hashtags     = $this->prepare_hashtags( sanitize_textarea_field( $request->get_param( 'hashtags' ) ?: '' ), $settings );
		$media_urls   = $request->get_param( 'media_urls' ) ?: array();
		$scheduled_at = sanitize_text_field( $request->get_param( 'scheduled_at' ) ?: '' );
		$publish_now  = (bool) $request->get_param( 'publish_now' );
		$ai_generated = (bool) $request->get_param( 'ai_generated' );
		$source_type  = sanitize_text_field( $request->get_param( 'source_type' ) ?: 'manual' );
		$source_id    = absint( $request->get_param( 'source_id' ) ) ?: null;

		if ( $scheduled_at && ! $publish_now && $this->scheduled_limit_reached() ) {
			return new \WP_REST_Response( array(
				'message'       => __( 'Scheduled post limit reached. Free plan allows 3 scheduled posts at a time. Upgrade to Pro for unlimited scheduling.', 'ai-marketing-expert' ),
				'limit_reached' => true,
			), 403 );
		}

		// Determine status.
		$status = 'draft';
		if ( $publish_now ) {
			$status = 'publishing';
		} elseif ( $scheduled_at ) {
			$status = ! empty( $settings['approval_workflow'] ) ? 'approval_pending' : 'scheduled';
		}

		$data = array(
			'account_id'   => $account_id,
			'content'      => $content,
			'media_urls'   => wp_json_encode( array_map( 'esc_url_raw', (array) $media_urls ) ),
			'hashtags'     => $hashtags,
			'status'       => $status,
			'scheduled_at' => $scheduled_at ?: null,
			'ai_generated' => $ai_generated ? 1 : 0,
			'source_type'  => $source_type,
			'source_id'    => $source_id,
			'created_at'   => $now,
			'updated_at'   => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation invalidates caches via bump_cache_version().
		$result = $wpdb->insert( $posts_table, $data );
		if ( false === $result ) {
			return new \WP_REST_Response( array( 'message' => __( 'Failed to create post.', 'ai-marketing-expert' ) ), 500 );
		}

		$post_id = (int) $wpdb->insert_id;

		// Log creation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation invalidates caches via bump_cache_version().
		$wpdb->insert( $logs_table, array(
			'post_id'    => $post_id,
			'action'     => 'created',
			'details'    => wp_json_encode( array( 'status' => $status, 'platform' => $account->platform ) ),
			'created_at' => $now,
		) );

		// Publish immediately if requested.
		if ( $publish_now ) {
			$publisher = new SocialPublisherService();
			$pub_result = $publisher->publish_single( $post_id );
			self::bump_cache_version();

			if ( ! $pub_result['success'] ) {
				return new \WP_REST_Response( array(
					'id'      => $post_id,
					'message' => $pub_result['error'] ?? __( 'Failed to publish post.', 'ai-marketing-expert' ),
				), 200 ); // Still 200 because post was created.
			}
		}

		self::bump_cache_version();

		return new \WP_REST_Response( array(
			'id'      => $post_id,
			'message' => $publish_now
				? __( 'Post published successfully.', 'ai-marketing-expert' )
				: ( $scheduled_at ? __( 'Post scheduled.', 'ai-marketing-expert' ) : __( 'Post saved as draft.', 'ai-marketing-expert' ) ),
		), 201 );
	}

	/**
	 * Update a draft or scheduled post.
	 */
	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p   = $wpdb->prefix;
		$posts_table    = $p . 'aime_social_posts';
		$accounts_table = $p . 'aime_social_accounts';
		$logs_table     = $p . 'aime_social_post_log';
		$id  = absint( $request->get_param( 'id' ) );
		$now = current_time( 'mysql', true );
		$existing_cache_key = self::build_cache_key( 'editable_post', array( 'id' => $id ) );
		$existing = wp_cache_get( $existing_cache_key, self::CACHE_GROUP );
		if ( false === $existing ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The editable post lookup is cached via wp_cache_get()/wp_cache_set().
			$existing = $wpdb->get_row( $wpdb->prepare(
				'SELECT id, status FROM %i WHERE id = %d',
				$posts_table,
				$id
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			wp_cache_set( $existing_cache_key, $existing ?: null, self::CACHE_GROUP, self::CACHE_TTL );
		}

		if ( ! $existing ) {
			return new \WP_REST_Response( array( 'message' => __( 'Post not found.', 'ai-marketing-expert' ) ), 404 );
		}

		if ( in_array( $existing->status, array( 'publishing', 'published' ), true ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Cannot edit a published or publishing post.', 'ai-marketing-expert' ) ), 400 );
		}

		$data = array( 'updated_at' => $now );

		if ( $request->has_param( 'content' ) ) {
			$data['content'] = sanitize_textarea_field( $request->get_param( 'content' ) );
		}
		if ( $request->has_param( 'hashtags' ) ) {
			$data['hashtags'] = $this->prepare_hashtags( sanitize_textarea_field( $request->get_param( 'hashtags' ) ), $this->get_social_settings() );
		}
		if ( $request->has_param( 'media_urls' ) ) {
			$data['media_urls'] = wp_json_encode( array_map( 'esc_url_raw', (array) $request->get_param( 'media_urls' ) ) );
		}
		if ( $request->has_param( 'account_id' ) ) {
			$account_id = absint( $request->get_param( 'account_id' ) );
			$account_cache_key = self::build_cache_key( 'account_exists', array( 'id' => $account_id ) );
			$account_exists = wp_cache_get( $account_cache_key, self::CACHE_GROUP );
			if ( false === $account_exists ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The account existence check is cached via wp_cache_get()/wp_cache_set().
				$account_exists = (bool) $wpdb->get_var( $wpdb->prepare(
					'SELECT id FROM %i WHERE id = %d',
					$accounts_table,
					$account_id
				) );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				wp_cache_set( $account_cache_key, $account_exists, self::CACHE_GROUP, self::CACHE_TTL );
			}
			if ( ! $account_exists ) {
				return new \WP_REST_Response( array( 'message' => __( 'Account not found.', 'ai-marketing-expert' ) ), 404 );
			}
			$data['account_id'] = $account_id;
		}
		if ( $request->has_param( 'scheduled_at' ) ) {
			$val = sanitize_text_field( $request->get_param( 'scheduled_at' ) );
			if ( $val && $this->scheduled_limit_reached( $id ) ) {
				return new \WP_REST_Response( array(
					'message'       => __( 'Scheduled post limit reached. Free plan allows 3 scheduled posts at a time. Upgrade to Pro for unlimited scheduling.', 'ai-marketing-expert' ),
					'limit_reached' => true,
				), 403 );
			}
			$data['scheduled_at'] = $val ?: null;
			$data['status']       = $val ? ( ! empty( $this->get_social_settings()['approval_workflow'] ) ? 'approval_pending' : 'scheduled' ) : 'draft';
		}
		if ( $request->has_param( 'status' ) ) {
			$s = sanitize_text_field( $request->get_param( 'status' ) );
			if ( in_array( $s, array( 'draft', 'scheduled', 'approval_pending' ), true ) ) {
				$data['status'] = $s;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation invalidates caches via bump_cache_version().
		$wpdb->update( $posts_table, $data, array( 'id' => $id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation invalidates caches via bump_cache_version().
		$wpdb->insert( $logs_table, array(
			'post_id'    => $id,
			'action'     => 'edited',
			'details'    => wp_json_encode( array( 'fields' => array_keys( $data ) ) ),
			'created_at' => $now,
		) );

		self::bump_cache_version();

		return new \WP_REST_Response( array( 'message' => __( 'Post updated.', 'ai-marketing-expert' ) ) );
	}

	/**
	 * Delete a post.
	 */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$posts_table = $p . 'aime_social_posts';
		$logs_table  = $p . 'aime_social_post_log';
		$id = absint( $request->get_param( 'id' ) );
		$exists_cache_key = self::build_cache_key( 'exists', array( 'id' => $id ) );
		$exists = wp_cache_get( $exists_cache_key, self::CACHE_GROUP );
		if ( false === $exists ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The existence check is cached via wp_cache_get()/wp_cache_set().
			$exists = (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM %i WHERE id = %d',
				$posts_table,
				$id
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			wp_cache_set( $exists_cache_key, $exists, self::CACHE_GROUP, self::CACHE_TTL );
		}

		if ( ! $exists ) {
			return new \WP_REST_Response( array( 'message' => __( 'Post not found.', 'ai-marketing-expert' ) ), 404 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation invalidates caches via bump_cache_version().
		$wpdb->delete( $posts_table, array( 'id' => $id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation invalidates caches via bump_cache_version().
		$wpdb->delete( $logs_table, array( 'post_id' => $id ) );
		self::bump_cache_version();

		return new \WP_REST_Response( array( 'message' => __( 'Post deleted.', 'ai-marketing-expert' ) ) );
	}

	/**
	 * Publish a post immediately.
	 */
	public function publish( \WP_REST_Request $request ): \WP_REST_Response {
		$id        = absint( $request->get_param( 'id' ) );
		$publisher = new SocialPublisherService();
		$result    = $publisher->publish_single( $id );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response(
				array( 'message' => $result['error'] ?? __( 'Failed to publish post.', 'ai-marketing-expert' ) ),
				400
			);
		}

		self::bump_cache_version();

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Bulk actions (delete, publish).
	 */
	public function bulk( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p      = $wpdb->prefix;
		$posts_table = $p . 'aime_social_posts';
		$logs_table  = $p . 'aime_social_post_log';
		$action = sanitize_text_field( $request->get_param( 'action' ) );
		$ids    = array_map( 'absint', $request->get_param( 'ids' ) ?: array() );

		if ( empty( $ids ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'No items selected.', 'ai-marketing-expert' ) ), 400 );
		}

		$count = 0;

		if ( 'delete' === $action ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk delete invalidates caches via bump_cache_version().
			$wpdb->query( $wpdb->prepare(
				sprintf( 'DELETE FROM %%i WHERE post_id IN (%s)', implode( ',', array_fill( 0, count( $ids ), '%d' ) ) ),
				$logs_table,
				...$ids
			) );
			$wpdb->query( $wpdb->prepare(
				sprintf( 'DELETE FROM %%i WHERE id IN (%s)', implode( ',', array_fill( 0, count( $ids ), '%d' ) ) ),
				$posts_table,
				...$ids
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = count( $ids );
			self::bump_cache_version();
		} elseif ( 'publish' === $action ) {
			$publisher = new SocialPublisherService();
			foreach ( $ids as $id ) {
				$result = $publisher->publish_single( $id );
				if ( ! empty( $result['success'] ) ) {
					$count++;
				}
			}
			if ( $count > 0 ) {
				self::bump_cache_version();
			}
		}

		return new \WP_REST_Response( array(
			'message' => sprintf(
				/* translators: %d: number of posts processed */
				__( '%d post(s) processed.', 'ai-marketing-expert' ),
				$count
			),
		) );
	}

	private function get_social_settings(): array {
		return wp_parse_args( get_option( 'aime_social-media_settings', array() ), array(
			'default_hashtags'  => '',
			'auto_hashtags'     => false,
			'approval_workflow' => false,
		) );
	}

	private function prepare_hashtags( string $hashtags, array $settings ): string {
		if ( empty( $settings['auto_hashtags'] ) || empty( $settings['default_hashtags'] ) ) {
			return trim( $hashtags );
		}

		$existing = preg_split( '/[\s,]+/', $hashtags, -1, PREG_SPLIT_NO_EMPTY );
		$defaults = preg_split( '/[\s,]+/', (string) $settings['default_hashtags'], -1, PREG_SPLIT_NO_EMPTY );
		$merged   = array();

		foreach ( array_merge( $existing ?: array(), $defaults ?: array() ) as $tag ) {
			$tag = trim( $tag );
			if ( '' === $tag ) {
				continue;
			}
			$tag = '#' . ltrim( $tag, '#' );
			$merged[ strtolower( $tag ) ] = $tag;
		}

		return implode( ' ', array_values( $merged ) );
	}

	private function scheduled_limit_reached( int $exclude_id = 0 ): bool {
		if ( aime_has_pro() ) {
			return false;
		}

		global $wpdb;
		$posts_table = $wpdb->prefix . 'aime_social_posts';
		$limits = aime_free_limits();
		$limit = (int) ( $limits['social_scheduled_posts'] ?? 3 );

		if ( $exclude_id > 0 ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE status IN ('scheduled','approval_pending') AND id != %d",
				$posts_table,
				$exclude_id
			) );
		} else {
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE status IN ('scheduled','approval_pending')",
				$posts_table
			) );
		}

		return $count >= $limit;
	}
}
