<?php
/**
 * SEO Module — Keyword Research Service.
 *
 * AI-powered keyword research, niche analysis, competitor gap, and content briefs.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Services;

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KeywordResearchService {

	use ParsesAiJson;

	/**
	 * AI keyword research — returns keyword suggestions with metrics.
	 */
	public function research_keywords( string $seed_keyword, string $niche, string $language, string $country ): array {
		$niche_hint = $niche ? "The website niche is: {$niche}." : '';

		$prompt = implode( "\n", array(
			'You are an expert SEO keyword researcher. Respond with ONLY a raw JSON object — no markdown, no code fences, no explanation, no commentary.',
			'',
			"Seed keyword: {$seed_keyword}",
			$niche_hint,
			"Target language: {$language}",
			"Target country/countries: {$country}",
			'When multiple countries are given, blend search-volume estimates across all of them and note country-specific differences in keyword selection.',
			'',
			'Return a JSON object with EXACTLY these keys (no extras):',
			'{',
			'  "seed_metrics": {',
			'    "keyword": "...", "search_volume": 0, "difficulty_score": 0,',
			'    "cpc_estimate": 0.00, "intent": "informational|navigational|transactional|commercial",',
			'    "trend": "up|down|stable", "competition": 0.00, "serp_features": [], "results_count": 0',
			'  },',
			'  "related_keywords": [',
			'    10 items — each: {"keyword":"","search_volume":0,"difficulty_score":0,"cpc_estimate":0.00,"intent":"","trend":"","competition":0.00,"serp_features":[],"content_type":"blog_post|pillar_page|listicle|how_to|comparison|product_page|landing_page","opportunity_score":0}',
			'  ],',
			'  "long_tail_keywords": [',
			'    8 items — each: {"keyword":"","search_volume":0,"difficulty_score":0,"cpc_estimate":0.00,"intent":"","trend":"","opportunity_score":0}',
			'  ],',
			'  "questions": [',
			'    6 items — each: {"keyword":"","search_volume":0,"difficulty_score":0,"intent":"informational"}',
			'  ],',
			'  "cluster_suggestions": [',
			'    3 items — each: {"name":"","pillar_keyword":"","keywords":["kw1","kw2","kw3"]}',
			'  ],',
			'  "summary": {"total_keywords":0,"avg_volume":0,"avg_difficulty":0,"avg_cpc":0.00,"easy_wins_count":0}',
			'}',
			'',
			'Use realistic estimated values. Do NOT copy the schema template literally. Fill every field.',
		) );

		$response = AiProvider::generate( $prompt, 'text', 8192 );

		if ( ! $response['success'] ) {
			return array(
				'success' => false,
				'message' => $response['content'] ?? __( 'AI generation failed.', 'ai-marketing-expert' ),
			);
		}

		$data = $this->parse_json_response( $response['content'] );

		if ( ! $data || ! is_array( $data ) ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to parse AI response. Please try again.', 'ai-marketing-expert' ),
				'raw'     => $response['content'],
			);
		}

		return array(
			'success'  => true,
			'data'     => $data,
			'provider' => $response['provider'] ?? '',
			'model'    => $response['model'] ?? '',
		);
	}

	/**
	 * AI niche analysis (Pro).
	 */
	public function niche_analysis( string $niche ): array {
		$prompt = implode( "\n", array(
			'You are an expert SEO strategist. Respond with ONLY a raw JSON object — no markdown, no code fences, no explanation.',
			'',
			"Niche: {$niche}",
			'',
			'Provide:',
			'- overview: brief overview of the niche landscape',
			'- opportunity_score: 1-10 rating of SEO opportunity',
			'- competition_level: low, medium, or high',
			'- monetization_potential: list of top monetization methods',
			'- top_keywords: 10 seed keywords to start with (each with estimated volume and difficulty)',
			'- content_gaps: 5 content gaps/opportunities competitors are missing',
			'- recommended_strategy: step-by-step SEO strategy recommendation',
			'- estimated_timeline: realistic timeline to see results',
			'',
			'Return ONLY raw JSON (no markdown fences):',
			'{',
			'  "overview": "",',
			'  "opportunity_score": 0,',
			'  "competition_level": "",',
			'  "monetization_potential": [""],',
			'  "top_keywords": [{"keyword": "", "search_volume": 0, "difficulty_score": 0}],',
			'  "content_gaps": [""],',
			'  "recommended_strategy": [""],',
			'  "estimated_timeline": ""',
			'}',
		) );

		$response = AiProvider::generate( $prompt, 'text', 6000 );

		if ( ! $response['success'] ) {
			return array(
				'success' => false,
				'message' => $response['content'] ?? __( 'AI generation failed.', 'ai-marketing-expert' ),
			);
		}

		$data = $this->parse_json_response( $response['content'] );

		if ( ! $data ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to parse AI response.', 'ai-marketing-expert' ),
			);
		}

		return array( 'success' => true, 'data' => $data );
	}

	/**
	 * AI competitor gap analysis (Pro).
	 */
	public function competitor_gap( string $my_domain, array $competitors ): array {
		$competitors_list = implode( ', ', $competitors );

		$prompt = implode( "\n", array(
			'You are an expert SEO analyst. Respond with ONLY a raw JSON object — no markdown, no code fences, no explanation.',
			'',
			"My domain: {$my_domain}",
			"Competitor domains: {$competitors_list}",
			'',
			'Analyze and provide:',
			"- gap_keywords: 15 keywords competitors rank for but my site likely doesn't",
			'- shared_keywords: 5 keywords all sites likely compete on',
			'- my_advantages: potential keywords/topics where my site could have an advantage',
			'- competitor_strengths: key SEO strengths of each competitor',
			'- recommendations: prioritized action items to close the gaps',
			'',
			'For each gap keyword include: keyword, estimated volume, difficulty, which competitor ranks for it, suggested content type.',
			'',
			'Return ONLY raw JSON (no markdown fences):',
			'{',
			'  "gap_keywords": [{"keyword": "", "search_volume": 0, "difficulty_score": 0, "competitor": "", "content_type": ""}],',
			'  "shared_keywords": [{"keyword": "", "search_volume": 0, "difficulty_score": 0}],',
			'  "my_advantages": [""],',
			'  "competitor_strengths": [{"domain": "", "strengths": [""]}],',
			'  "recommendations": [""]',
			'}',
		) );

		$response = AiProvider::generate( $prompt, 'text', 6000 );

		if ( ! $response['success'] ) {
			return array(
				'success' => false,
				'message' => $response['content'] ?? __( 'AI generation failed.', 'ai-marketing-expert' ),
			);
		}

		$data = $this->parse_json_response( $response['content'] );

		if ( ! $data ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to parse AI response.', 'ai-marketing-expert' ),
			);
		}

		return array( 'success' => true, 'data' => $data );
	}

	/**
	 * AI content brief generation (Pro).
	 */
	public function generate_content_brief( string $keyword, string $niche ): array {
		$niche_hint = $niche ? "Website niche: {$niche}." : '';

		$prompt = implode( "\n", array(
			'You are an expert SEO content strategist. Respond with ONLY a raw JSON object — no markdown, no code fences, no explanation.',
			'',
			"Keyword: {$keyword}",
			$niche_hint,
			'',
			'Provide a comprehensive content brief including:',
			'- title_suggestions: 3 SEO-optimized title options',
			'- meta_description: suggested meta description (150-160 chars)',
			'- target_word_count: recommended word count',
			'- content_type: recommended format (blog_post, pillar_page, listicle, how_to, comparison)',
			'- search_intent: the primary search intent',
			'- outline: detailed H2/H3 heading outline with brief notes for each section',
			'- primary_keywords: main keywords to target',
			'- secondary_keywords: LSI/related keywords to include',
			'- internal_link_suggestions: types of pages to link to internally',
			'- competing_content_analysis: what top-ranking content typically covers',
			'- unique_angle: suggested unique angle to differentiate',
			'- cta_suggestions: recommended calls to action',
			'',
			'Return ONLY raw JSON (no markdown fences):',
			'{',
			'  "title_suggestions": [""],',
			'  "meta_description": "",',
			'  "target_word_count": 0,',
			'  "content_type": "",',
			'  "search_intent": "",',
			'  "outline": [{"heading": "", "level": "h2", "notes": "", "sub_headings": [{"heading": "", "notes": ""}]}],',
			'  "primary_keywords": [""],',
			'  "secondary_keywords": [""],',
			'  "internal_link_suggestions": [""],',
			'  "competing_content_analysis": "",',
			'  "unique_angle": "",',
			'  "cta_suggestions": [""]',
			'}',
		) );

		$response = AiProvider::generate( $prompt, 'text', 6000 );

		if ( ! $response['success'] ) {
			return array(
				'success' => false,
				'message' => $response['content'] ?? __( 'AI generation failed.', 'ai-marketing-expert' ),
			);
		}

		$data = $this->parse_json_response( $response['content'] );

		if ( ! $data ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to parse AI response.', 'ai-marketing-expert' ),
			);
		}

		return array( 'success' => true, 'data' => $data );
	}
}
