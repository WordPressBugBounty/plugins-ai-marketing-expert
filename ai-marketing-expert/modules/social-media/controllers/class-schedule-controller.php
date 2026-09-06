<?php
/**
 * Schedule Controller — calendar and schedule management.
 *
 * @package WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers;

use WPSpace\AiMarketingExpert\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScheduleController {

	/**
	 * List scheduled posts for a date range.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;
		$posts_table    = $p . 'aime_social_posts';
		$accounts_table = $p . 'aime_social_accounts';

		$start = $this->normalize_date_boundary( sanitize_text_field( $request->get_param( 'start' ) ?: gmdate( 'Y-m-01' ) ), 'start' );
		$end   = $this->normalize_date_boundary( sanitize_text_field( $request->get_param( 'end' ) ?: gmdate( 'Y-m-t' ) ), 'end' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Calendar listing is a fresh admin read for the requested range.
		$items = $wpdb->get_results( $wpdb->prepare(
			'SELECT p.id, p.account_id, p.content, p.media_urls, p.hashtags, p.status,
					p.scheduled_at, p.published_at, p.ai_generated, p.created_at,
					a.platform, a.name AS account_name, a.avatar_url AS account_avatar
			 FROM %i p
			 LEFT JOIN %i a ON p.account_id = a.id
			 WHERE p.scheduled_at IS NOT NULL
			   AND p.scheduled_at >= %s
			   AND p.scheduled_at <= %s
			 ORDER BY p.scheduled_at ASC',
			$posts_table,
			$accounts_table,
			$start,
			$end
		) );

		foreach ( $items as &$item ) {
			$item->media_urls = json_decode( $item->media_urls ?: '[]', true );
		}

		return new \WP_REST_Response( array( 'items' => $items ?: array() ) );
	}

	/**
	 * Quick-schedule a post from the calendar.
	 */
	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		// Delegate to PostController::store with scheduled_at set.
		$post_ctrl = new PostController();
		return $post_ctrl->store( $request );
	}

	/**
	 * Reschedule a post to a new time.
	 */
	public function reschedule( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p   = $wpdb->prefix;
		$posts_table = $p . 'aime_social_posts';
		$logs_table  = $p . 'aime_social_post_log';
		$id  = absint( $request->get_param( 'id' ) );
		$now = current_time( 'mysql', true );
		$new_time = sanitize_text_field( $request->get_param( 'scheduled_at' ) );

		if ( ! $new_time ) {
			return new \WP_REST_Response( array( 'message' => __( 'Scheduled time is required.', 'ai-marketing-expert' ) ), 400 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off admin lookup before rescheduling.
		$post = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, status FROM %i WHERE id = %d',
			$posts_table,
			$id
		) );

		if ( ! $post ) {
			return new \WP_REST_Response( array( 'message' => __( 'Post not found.', 'ai-marketing-expert' ) ), 404 );
		}

		if ( in_array( $post->status, array( 'publishing', 'published' ), true ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Cannot reschedule a published post.', 'ai-marketing-expert' ) ), 400 );
		}

		$wpdb->update( $posts_table, array(
			'scheduled_at' => $new_time,
			'status'       => 'scheduled',
			'updated_at'   => $now,
		), array( 'id' => $id ) );

		$wpdb->insert( $logs_table, array(
			'post_id'    => $id,
			'action'     => 'rescheduled',
			'details'    => wp_json_encode( array( 'new_time' => $new_time ) ),
			'created_at' => $now,
		) );

		return new \WP_REST_Response( array( 'message' => __( 'Post rescheduled.', 'ai-marketing-expert' ) ) );
	}

	private function normalize_date_boundary( string $value, string $boundary ): string {
		$value = trim( str_replace( 'T', ' ', $value ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value . ( 'start' === $boundary ? ' 00:00:00' : ' 23:59:59' );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
			return $value . ':00';
		}

		return $value;
	}
}
