<?php
/**
 * SEO Module — Research Controller (AI keyword/niche research).
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Controllers;

use WPSpace\AiMarketingExpert\Modules\Seo\SeoModule;
use WPSpace\AiMarketingExpert\Modules\Seo\Services\KeywordResearchService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ResearchController {

	/**
	 * AI keyword research.
	 */
	public function keyword_research( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit check.
		if ( ! aime_has_pro() ) {
			$limit = aime_free_limits()['seo_keyword_research_monthly'] ?? 10;
			if ( SeoModule::get_monthly_research_count() >= $limit ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: monthly keyword research query limit */
						__( 'Free plan allows %d keyword research queries per month. Upgrade to Pro for unlimited.', 'ai-marketing-expert' ),
						$limit
					),
				), 403 );
			}
		}

		$seed_keyword = sanitize_text_field( $request->get_param( 'seed_keyword' ) ?? '' );
		$niche        = sanitize_text_field( $request->get_param( 'niche' ) ?? '' );
		$language     = sanitize_text_field( $request->get_param( 'language' ) ?? 'English' );
		$country      = sanitize_text_field( $request->get_param( 'country' ) ?? 'US' );

		// Multi-country targeting is Pro-only: free plan uses the first country.
		if ( ! aime_has_pro() && false !== strpos( $country, ',' ) ) {
			$country = trim( explode( ',', $country )[0] );
		}

		if ( empty( $seed_keyword ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Seed keyword is required.', 'ai-marketing-expert' ),
			), 400 );
		}

		$service = new KeywordResearchService();
		$result  = $service->research_keywords( $seed_keyword, $niche, $language, $country );

		if ( $result['success'] ) {
			SeoModule::increment_monthly_research();
		}

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * AI niche analysis.
	 */
	public function niche_analysis( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit check.
		if ( ! aime_has_pro() ) {
			$limit = aime_free_limits()['seo_niche_analysis_monthly'] ?? 3;
			if ( SeoModule::get_monthly_feature_count( 'niche_analysis' ) >= $limit ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: monthly niche analysis limit */
						__( 'Free plan allows %d niche analyses per month. Upgrade to Pro for unlimited.', 'ai-marketing-expert' ),
						$limit
					),
				), 403 );
			}
		}

		$niche = sanitize_text_field( $request->get_param( 'niche' ) ?? '' );

		if ( empty( $niche ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Niche is required.', 'ai-marketing-expert' ),
			), 400 );
		}

		$service = new KeywordResearchService();
		$result  = $service->niche_analysis( $niche );

		if ( $result['success'] ) {
			SeoModule::increment_monthly_feature( 'niche_analysis' );
		}

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * AI competitor gap analysis.
	 */
	public function competitor_gap( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit check.
		if ( ! aime_has_pro() ) {
			$limit = aime_free_limits()['seo_competitor_gap_monthly'] ?? 3;
			if ( SeoModule::get_monthly_feature_count( 'competitor_gap' ) >= $limit ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: monthly competitor gap analysis limit */
						__( 'Free plan allows %d competitor gap analyses per month. Upgrade to Pro for unlimited.', 'ai-marketing-expert' ),
						$limit
					),
				), 403 );
			}
		}

		$my_domain         = sanitize_text_field( $request->get_param( 'my_domain' ) ?? '' );
		$competitor_domains = $request->get_param( 'competitor_domains' );

		if ( empty( $my_domain ) || empty( $competitor_domains ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Your domain and at least one competitor domain are required.', 'ai-marketing-expert' ),
			), 400 );
		}

		if ( is_array( $competitor_domains ) ) {
			$competitor_domains = array_map( 'sanitize_text_field', $competitor_domains );
		} else {
			$competitor_domains = array( sanitize_text_field( $competitor_domains ) );
		}

		$service = new KeywordResearchService();
		$result  = $service->competitor_gap( $my_domain, $competitor_domains );

		if ( $result['success'] ) {
			SeoModule::increment_monthly_feature( 'competitor_gap' );
		}

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Generate AI content brief for a keyword.
	 */
	public function content_brief( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit check.
		if ( ! aime_has_pro() ) {
			$limit = aime_free_limits()['seo_content_brief_monthly'] ?? 5;
			if ( SeoModule::get_monthly_feature_count( 'content_brief' ) >= $limit ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: monthly content brief limit */
						__( 'Free plan allows %d content briefs per month. Upgrade to Pro for unlimited.', 'ai-marketing-expert' ),
						$limit
					),
				), 403 );
			}
		}

		$keyword = sanitize_text_field( $request->get_param( 'keyword' ) ?? '' );
		$niche   = sanitize_text_field( $request->get_param( 'niche' ) ?? '' );

		if ( empty( $keyword ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Keyword is required.', 'ai-marketing-expert' ),
			), 400 );
		}

		$service = new KeywordResearchService();
		$result  = $service->generate_content_brief( $keyword, $niche );

		if ( $result['success'] ) {
			SeoModule::increment_monthly_feature( 'content_brief' );
		}

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Per-feature research usage counts and free limits.
	 */
	public function research_usage(): \WP_REST_Response {
		$is_pro = aime_has_pro();
		$limits = aime_free_limits();

		return new \WP_REST_Response( array(
			'success' => true,
			'is_pro'  => $is_pro,
			'usage'   => array(
				'keyword_research' => array(
					'used'  => SeoModule::get_monthly_research_count(),
					'limit' => $is_pro ? null : ( $limits['seo_keyword_research_monthly'] ?? 10 ),
				),
				'niche_analysis' => array(
					'used'  => SeoModule::get_monthly_feature_count( 'niche_analysis' ),
					'limit' => $is_pro ? null : ( $limits['seo_niche_analysis_monthly'] ?? 3 ),
				),
				'competitor_gap' => array(
					'used'  => SeoModule::get_monthly_feature_count( 'competitor_gap' ),
					'limit' => $is_pro ? null : ( $limits['seo_competitor_gap_monthly'] ?? 3 ),
				),
				'content_brief' => array(
					'used'  => SeoModule::get_monthly_feature_count( 'content_brief' ),
					'limit' => $is_pro ? null : ( $limits['seo_content_brief_monthly'] ?? 5 ),
				),
			),
		) );
	}
}
