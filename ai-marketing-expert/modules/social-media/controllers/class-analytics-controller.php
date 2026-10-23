<?php
/**
 * Analytics Controller — activity analytics for social media.
 *
 * @package WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AnalyticsController {

	/**
	 * Overview: posts over time, status breakdown, platform breakdown.
	 */
	public function overview( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;
		$posts_table    = $p . 'aime_social_posts';
		$accounts_table = $p . 'aime_social_accounts';

		$days  = absint( $request->get_param( 'days' ) ) ?: 30;
		$start = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Analytics are deliberate fresh reads for the current dashboard request.
		// Posts over time (grouped by day).
		$posts_over_time = $wpdb->get_results( $wpdb->prepare(
			'SELECT DATE(created_at) AS day, COUNT(*) AS total
			 FROM %i
			 WHERE created_at >= %s
			 GROUP BY day
			 ORDER BY day ASC',
			$posts_table,
			$start
		) );

		// Status breakdown.
		$status_breakdown = $wpdb->get_results( $wpdb->prepare(
			'SELECT status, COUNT(*) AS count
			 FROM %i
			 WHERE created_at >= %s
			 GROUP BY status',
			$posts_table,
			$start
		) );

		// Platform breakdown.
		$platform_breakdown = $wpdb->get_results( $wpdb->prepare(
			'SELECT a.platform, COUNT(p.id) AS count
			 FROM %i p
			 INNER JOIN %i a ON p.account_id = a.id
			 WHERE p.created_at >= %s
			 GROUP BY a.platform',
			$posts_table,
			$accounts_table,
			$start
		) );

		// Summary counts.
		$totals = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total_posts,
					SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
					SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
					SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
					SUM(CASE WHEN ai_generated = 1 THEN 1 ELSE 0 END) AS ai_generated
			 FROM %i
			 WHERE created_at >= %s",
			$posts_table,
			$start
		) );

		// Account count.
		$accounts = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $accounts_table, 'connected' )
		);

		// Recent failures.
		$recent_failures = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.id, p.content, p.error_message, p.created_at,
					a.platform, a.name AS account_name
			 FROM %i p
			 LEFT JOIN %i a ON p.account_id = a.id
			 WHERE p.status = 'failed' AND p.created_at >= %s
			 ORDER BY p.created_at DESC
			 LIMIT 10",
			$posts_table,
			$accounts_table,
			$start
		) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return new \WP_REST_Response( array(
			'posts_over_time'    => $posts_over_time ?: array(),
			'status_breakdown'   => $status_breakdown ?: array(),
			'platform_breakdown' => $platform_breakdown ?: array(),
			'totals'             => $totals,
			'accounts'           => (int) $accounts,
			'recent_failures'    => $recent_failures ?: array(),
		) );
	}
}
