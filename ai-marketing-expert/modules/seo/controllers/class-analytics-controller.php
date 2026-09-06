<?php
/**
 * SEO Module — Analytics Controller (Dashboard stats & trends).
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Controllers;

use WPSpace\AiMarketingExpert\Modules\Seo\SeoModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AnalyticsController {

	/**
	 * Dashboard overview stats.
	 */
	public function dashboard( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$module = new SeoModule();
		$stats  = $module->get_stats();

		// Additional dashboard-specific data.
		$recent_audits = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, overall_score, created_at FROM {$p}aime_seo_audits ORDER BY created_at DESC LIMIT %d",
			5
		) );

		$recent_keywords = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, keyword, search_volume, difficulty_score, intent, status, created_at FROM {$p}aime_seo_keywords ORDER BY created_at DESC LIMIT %d",
			5
		) );

		$upcoming_calendar = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, planned_date, status FROM {$p}aime_seo_calendar WHERE planned_date >= %s AND status != 'published' ORDER BY planned_date ASC LIMIT %d",
			gmdate( 'Y-m-d' ),
			5
		) );

		$monthly_research = SeoModule::get_monthly_research_count();
		$monthly_audits   = SeoModule::get_monthly_audit_count();

		$limits = aime_free_limits();

		return new \WP_REST_Response( array(
			'success'           => true,
			'stats'             => $stats,
			'recent_audits'     => $recent_audits,
			'recent_keywords'   => $recent_keywords,
			'upcoming_calendar' => $upcoming_calendar,
			'usage'             => array(
				'research_used'  => $monthly_research,
				'research_limit' => aime_has_pro() ? null : ( $limits['seo_keyword_research_monthly'] ?? 10 ),
				'audit_used'     => $monthly_audits,
				'audit_limit'    => aime_has_pro() ? null : ( $limits['seo_audits_monthly'] ?? 5 ),
				'keywords_saved' => SeoModule::get_keyword_count(),
				'keywords_limit' => aime_has_pro() ? null : ( $limits['seo_keywords_saved'] ?? 50 ),
				'rank_tracked'   => SeoModule::get_tracked_keyword_count(),
				'rank_limit'     => aime_has_pro() ? null : ( $limits['seo_rank_keywords'] ?? 5 ),
				'niche_used'     => SeoModule::get_monthly_feature_count( 'niche_analysis' ),
				'niche_limit'    => aime_has_pro() ? null : ( $limits['seo_niche_analysis_monthly'] ?? 3 ),
				'gap_used'       => SeoModule::get_monthly_feature_count( 'competitor_gap' ),
				'gap_limit'      => aime_has_pro() ? null : ( $limits['seo_competitor_gap_monthly'] ?? 3 ),
				'brief_used'     => SeoModule::get_monthly_feature_count( 'content_brief' ),
				'brief_limit'    => aime_has_pro() ? null : ( $limits['seo_content_brief_monthly'] ?? 5 ),
				'topics'         => aime_usage_payload( 'seo_topics', SeoModule::get_topic_count() ),
				'calendar_items' => aime_usage_payload( 'seo_calendar_items', SeoModule::get_calendar_count() ),
				'prospects'      => aime_usage_payload( 'seo_backlink_prospects', SeoModule::get_backlink_count() ),
			),
			'is_pro' => aime_has_pro(),
		) );
	}

	/**
	 * Keyword distribution overview.
	 */
	public function keyword_overview( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$by_intent = $wpdb->get_results(
			"SELECT intent, COUNT(*) as count FROM {$p}aime_seo_keywords GROUP BY intent ORDER BY count DESC"
		);

		$by_status = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM {$p}aime_seo_keywords GROUP BY status ORDER BY count DESC"
		);

		$by_difficulty = $wpdb->get_results(
			"SELECT
				CASE
					WHEN difficulty_score <= 30 THEN 'easy'
					WHEN difficulty_score <= 60 THEN 'medium'
					ELSE 'hard'
				END as difficulty_range,
				COUNT(*) as count
			FROM {$p}aime_seo_keywords GROUP BY difficulty_range ORDER BY count DESC"
		);

		return new \WP_REST_Response( array(
			'success'       => true,
			'by_intent'     => $by_intent,
			'by_status'     => $by_status,
			'by_difficulty'  => $by_difficulty,
		) );
	}

	/**
	 * Audit score trends over time.
	 */
	public function audit_trends( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p    = $wpdb->prefix;
		$days = min( 365, max( 7, absint( $request->get_param( 'days' ) ?: 30 ) ) );

		$trends = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) as date, COUNT(*) as count, ROUND(AVG(overall_score), 1) as avg_score FROM {$p}aime_seo_audits WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY date ASC",
			gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) )
		) );

		return new \WP_REST_Response( array(
			'success' => true,
			'trends'  => $trends,
		) );
	}
}
