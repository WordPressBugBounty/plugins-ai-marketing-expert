<?php
/**
 * SEO Module — Rank Tracker Service.
 *
 * Simulated rank checking via AI estimation and historical tracking.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Services;

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RankTrackerService {

	use ParsesAiJson;

	/**
	 * Check rank for a single keyword (AI-estimated).
	 *
	 * Note: Real SERP scraping requires external APIs. This uses
	 * AI estimation as a starting point. The rank can also be
	 * manually entered by the user for accuracy.
	 */
	public function check_rank( int $keyword_id, string $search_engine = 'google' ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$keyword = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_keywords WHERE id = %d",
			$keyword_id
		), ARRAY_A );

		if ( ! $keyword ) {
			return array(
				'success' => false,
				'message' => __( 'Keyword not found.', 'ai-marketing-expert' ),
			);
		}

		$site_url   = home_url();
		$target_url = $keyword['target_url'] ?: $site_url;
		$kw_text    = $keyword['keyword'];
		$engine     = sanitize_key( $search_engine ?: 'google' );

		$prompt = implode( "\n", array(
			"You are an SEO ranking analyst. Estimate the likely {$engine} search ranking position for this website and keyword combination.",
			'',
			"Website: {$site_url}",
			"Target URL: {$target_url}",
			"Keyword: {$kw_text}",
			"Keyword difficulty: {$keyword['difficulty_score']}/100",
			"Current known rank: {$keyword['current_rank']}",
			'',
			'Based on typical SEO patterns, estimate:',
			'- rank_position: estimated current ranking (1-100+, or null if unlikely to rank in top 100)',
			'- confidence: your confidence in this estimate (low, medium, high)',
			'- ranking_factors: brief explanation of factors influencing the ranking',
			'- improvement_tips: 3 specific tips to improve ranking for this keyword',
			'',
			'Return ONLY valid JSON:',
			'{',
			'  "rank_position": null,',
			'  "confidence": "low",',
			'  "ranking_factors": "",',
			'  "improvement_tips": [""]',
			'}',
		) );

		$response = AiProvider::generate( $prompt, 'text', 1024 );

		if ( ! $response['success'] ) {
			return array(
				'success'    => false,
				'keyword_id' => $keyword_id,
				'message'    => $response['content'] ?? __( 'AI generation failed.', 'ai-marketing-expert' ),
			);
		}

		$data = $this->parse_json_response( $response['content'] );

		$rank = $data['rank_position'] ?? null;

		// Record rank history.
		$wpdb->insert( "{$p}aime_seo_rank_history", array(
			'keyword_id'    => $keyword_id,
			'rank_position' => $rank ? absint( $rank ) : null,
			'url'           => $target_url,
			'search_engine' => $engine,
		) );

		// Update current rank on keyword.
		if ( $rank ) {
			$wpdb->update(
				"{$p}aime_seo_keywords",
				array(
					'current_rank' => absint( $rank ),
					'updated_at'   => current_time( 'mysql', true ),
				),
				array( 'id' => $keyword_id )
			);
		}

		return array(
			'success'    => true,
			'keyword_id' => $keyword_id,
			'keyword'    => $kw_text,
			'rank'       => $rank,
			'engine'     => $engine,
			'data'       => $data,
		);
	}

	/**
	 * Process daily rank checks for all targeted keywords (cron job, Pro only).
	 */
	public function process_daily_checks(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$keywords = $wpdb->get_results(
			"SELECT id FROM {$p}aime_seo_keywords WHERE status = 'targeted' AND current_rank IS NOT NULL ORDER BY updated_at ASC LIMIT 20"
		);

		$settings = get_option( 'aime_seo_settings', array() );
		$engine   = sanitize_key( $settings['rank_check_engine'] ?? 'google' );

		foreach ( $keywords as $kw ) {
			$this->check_rank( (int) $kw->id, $engine );
			// Small delay to avoid rate limiting.
			sleep( 2 );
		}
	}
}
