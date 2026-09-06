<?php
/**
 * Social Publisher Service — cron-driven queue processor.
 *
 * Processes scheduled posts and publishes them to their target platforms.
 * Runs on the `aime_publish_scheduled_social_posts` WP-Cron hook.
 *
 * @package WPSpace\AiMarketingExpert\Modules\SocialMedia\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\SocialMedia\Services;

use WPSpace\AiMarketingExpert\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SocialPublisherService {

	/**
	 * Maximum posts to process per cron run.
	 */
	const BATCH_LIMIT = 10;

	/**
	 * Process the scheduled posts queue.
	 * Called by WP-Cron.
	 */
	public function process_queue(): void {
		global $wpdb;
		$p   = $wpdb->prefix;
		$posts_table    = $p . 'aime_social_posts';
		$accounts_table = $p . 'aime_social_accounts';
		$now = $this->get_scheduler_now();

		// Reclaim posts stuck in 'publishing' (a crash or timeout between the
		// status flip and the API response). After a 10-minute grace period
		// they are re-queued; three strikes mark them failed for good so a
		// deterministically crashing publish cannot loop forever.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron queue maintenance needs current rows.
		$wpdb->query( $wpdb->prepare(
			"UPDATE %i SET
				status        = IF( retry_count >= 2, 'failed', 'scheduled' ),
				error_message = IF( retry_count >= 2, %s, error_message ),
				retry_count   = retry_count + 1,
				updated_at    = %s
			 WHERE status = 'publishing'
			   AND updated_at < %s",
			$posts_table,
			__( 'Publishing timed out repeatedly; giving up after 3 attempts.', 'ai-marketing-expert' ),
			current_time( 'mysql', true ),
			gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS )
		) );

		// Fetch due posts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron queue processing needs current scheduled posts.
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.*, a.platform, a.access_token AS account_access_token,
					a.refresh_token AS account_refresh_token,
					a.platform_user_id, a.meta AS account_meta, a.status AS account_status, a.name AS account_name
			 FROM %i p
			 INNER JOIN %i a ON p.account_id = a.id
			 WHERE p.status = 'scheduled'
			   AND p.scheduled_at <= %s
			 ORDER BY p.scheduled_at ASC
			 LIMIT %d",
			$posts_table,
			$accounts_table,
			$now,
			self::BATCH_LIMIT
		) );

		if ( empty( $posts ) ) {
			return;
		}

		$api = new PlatformApiService();

		foreach ( $posts as $post ) {
			$this->publish_post( $post, $api );
		}
	}

	/**
	 * Publish a single post by ID.
	 *
	 * @param int $post_id
	 * @return array { success: bool, message?: string, error?: string }
	 */
	public function publish_single( int $post_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;
		$posts_table    = $p . 'aime_social_posts';
		$accounts_table = $p . 'aime_social_accounts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Manual publish needs the current post and account state.
		$post = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.*, a.platform, a.access_token AS account_access_token,
					a.refresh_token AS account_refresh_token,
					a.platform_user_id, a.meta AS account_meta, a.status AS account_status, a.name AS account_name
			 FROM %i p
			 INNER JOIN %i a ON p.account_id = a.id
			 WHERE p.id = %d",
			$posts_table,
			$accounts_table,
			$post_id
		) );

		if ( ! $post ) {
			return array( 'success' => false, 'error' => __( 'Post not found.', 'ai-marketing-expert' ) );
		}

		if ( 'published' === $post->status ) {
			return array( 'success' => false, 'error' => __( 'Post is already published.', 'ai-marketing-expert' ) );
		}

		if ( 'connected' !== $post->account_status ) {
			$this->mark_failed( $post->id, __( 'Account is disconnected or expired.', 'ai-marketing-expert' ) );
			return array( 'success' => false, 'error' => __( 'Account is not connected.', 'ai-marketing-expert' ) );
		}

		$api = new PlatformApiService();
		return $this->publish_post( $post, $api );
	}

	/**
	 * Publish a single post row using the API service.
	 *
	 * @param object              $post Full post+account row.
	 * @param PlatformApiService  $api
	 * @return array
	 */
	private function publish_post( object $post, PlatformApiService $api ): array {
		global $wpdb;
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql', true );

		// Mark as publishing.
		$wpdb->update( "{$p}aime_social_posts", array( 'status' => 'publishing', 'updated_at' => $now ), array( 'id' => $post->id ) );

		// Build account object for the API service.
		$account = (object) array(
			'id'               => $post->account_id,
			'platform'         => $post->platform,
			'access_token'     => $post->account_access_token,
			'refresh_token'    => $post->account_refresh_token,
			'platform_user_id' => $post->platform_user_id,
			'meta'             => $post->account_meta ?? '{}',
		);

		$media_urls = json_decode( $post->media_urls ?: '[]', true );
		$content    = $post->content;

		// Append hashtags.
		if ( ! empty( $post->hashtags ) ) {
			$content .= "\n\n" . $post->hashtags;
		}

		$validation = $this->validate_before_publish( $post, $content, is_array( $media_urls ) ? $media_urls : array() );
		if ( ! $validation['success'] ) {
			$error = $validation['message'];
			$this->mark_failed( $post->id, $error );
			return array( 'success' => false, 'error' => $error );
		}

		// Attempt publish.
		$result = $api->publish( $account, $content, $media_urls );

		if ( $result['success'] ) {
			$wpdb->update( "{$p}aime_social_posts", array(
				'status'           => 'published',
				'platform_post_id' => $result['platform_post_id'] ?? '',
				'published_at'     => $now,
				'error_message'    => null,
				'updated_at'       => $now,
			), array( 'id' => $post->id ) );

			$wpdb->insert( "{$p}aime_social_post_log", array(
				'post_id'    => $post->id,
				'action'     => 'published',
				'details'    => wp_json_encode( array(
					'platform'         => $post->platform,
					'platform_post_id' => $result['platform_post_id'] ?? '',
					'account'          => $post->account_name ?? '',
				) ),
				'created_at' => $now,
			) );

			return array(
				'success'          => true,
				'message'          => __( 'Post published successfully.', 'ai-marketing-expert' ),
				'platform_post_id' => $result['platform_post_id'] ?? '',
			);
		}

		// Failed.
		$error = $result['message'] ?? __( 'Unknown publish error.', 'ai-marketing-expert' );
		$this->mark_failed( $post->id, $error );

		return array( 'success' => false, 'error' => $error );
	}

	private function validate_before_publish( object $post, string $content, array $media_urls ): array {
		if ( 'instagram' === $post->platform && empty( $media_urls ) ) {
			return array( 'success' => false, 'message' => __( 'Instagram requires at least one image before publishing.', 'ai-marketing-expert' ) );
		}

		foreach ( $media_urls as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
				return array( 'success' => false, 'message' => __( 'Social platforms cannot access localhost media URLs. Use a publicly accessible image URL before publishing.', 'ai-marketing-expert' ) );
			}
		}

		if ( 'x' === $post->platform && mb_strlen( $content ) > 280 ) {
			return array( 'success' => false, 'message' => __( 'X posts must be 280 characters or fewer, including hashtags.', 'ai-marketing-expert' ) );
		}

		return array( 'success' => true );
	}

	/**
	 * Mark a post as failed.
	 */
	private function mark_failed( int $post_id, string $error ): void {
		global $wpdb;
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql', true );

		$wpdb->update( "{$p}aime_social_posts", array(
			'status'        => 'failed',
			'error_message' => $error,
			'updated_at'    => $now,
		), array( 'id' => $post_id ) );

		$wpdb->insert( "{$p}aime_social_post_log", array(
			'post_id'    => $post_id,
			'action'     => 'failed',
			'details'    => wp_json_encode( array( 'error' => $error ) ),
			'created_at' => $now,
		) );

		aime_log( 'Social post #' . $post_id . ' failed: ' . $error, 'error', 'social-media' );
	}

	private function get_scheduler_now(): string {
		$settings = wp_parse_args( get_option( 'aime_social-media_settings', array() ), array(
			'default_timezone' => '',
		) );

		$timezone = ! empty( $settings['default_timezone'] ) ? $settings['default_timezone'] : wp_timezone_string();
		try {
			return ( new \DateTimeImmutable( 'now', new \DateTimeZone( $timezone ) ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return current_time( 'mysql' );
		}
	}
}
