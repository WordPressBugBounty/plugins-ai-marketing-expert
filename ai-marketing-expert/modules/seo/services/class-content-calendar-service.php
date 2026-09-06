<?php
/**
 * SEO Module — Content Calendar Service.
 *
 * AI-powered content calendar generation.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Services;

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContentCalendarService {

	use ParsesAiJson;

	/**
	 * AI-generate content calendar.
	 *
	 * @param int|null $max_items Cap on saved items (free plan), or null for unlimited.
	 */
	public function generate_calendar( string $niche, int $weeks, string $frequency, ?int $max_items = null ): array {
		$freq_map = array(
			'daily'        => 'every day',
			'twice_weekly' => 'twice per week',
			'weekly'       => 'once per week',
			'biweekly'     => 'every two weeks',
		);
		$freq_label = $freq_map[ $frequency ] ?? 'twice per week';

		$start_date = gmdate( 'Y-m-d' );

		$prompt = implode( "\n", array(
			"You are an expert SEO content strategist. Generate a content calendar for the next {$weeks} weeks.",
			'',
			"Niche: {$niche}",
			"Publishing frequency: {$freq_label}",
			"Start date: {$start_date}",
			'',
			'For each content piece provide:',
			'- title: the proposed article title',
			'- keyword: the target keyword',
			'- content_type: one of blog_post, pillar_page, listicle, how_to, comparison',
			'- planned_date: YYYY-MM-DD format',
			'- priority: 1-5 (1 = highest)',
			'- brief: 1-2 sentence description of what to cover',
			'- estimated_word_count: recommended word count',
			'- search_intent: informational, navigational, transactional, or commercial',
			'',
			'Space the dates evenly according to the publishing frequency.',
			'Order by date and prioritize a mix of content types.',
			'',
			'Return ONLY valid JSON:',
			'{',
			'  "calendar": [',
			'    {',
			'      "title": "",',
			'      "keyword": "",',
			'      "content_type": "",',
			'      "planned_date": "",',
			'      "priority": 1,',
			'      "brief": "",',
			'      "estimated_word_count": 1500,',
			'      "search_intent": ""',
			'    }',
			'  ],',
			'  "strategy_notes": ""',
			'}',
		) );

		$response = AiProvider::generate( $prompt, 'text', 4096 );

		if ( ! $response['success'] ) {
			return array(
				'success' => false,
				'message' => $response['content'] ?? __( 'AI generation failed.', 'ai-marketing-expert' ),
			);
		}

		$data = $this->parse_json_response( $response['content'] );

		if ( ! $data || ! isset( $data['calendar'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to parse AI response.', 'ai-marketing-expert' ),
			);
		}

		// Save calendar items to database.
		$items   = $data['calendar'];
		$dropped = 0;

		if ( null !== $max_items && count( $items ) > $max_items ) {
			$dropped = count( $items ) - $max_items;
			$items   = array_slice( $items, 0, $max_items );
		}

		$saved = $this->save_calendar_items( $items );

		return array(
			'success'        => true,
			'data'           => $data,
			'saved_count'    => $saved,
			'dropped_count'  => $dropped,
			'strategy_notes' => $data['strategy_notes'] ?? '',
		);
	}

	/**
	 * Save generated calendar items.
	 */
	private function save_calendar_items( array $items ): int {
		global $wpdb;
		$p     = $wpdb->prefix;
		$count = 0;

		foreach ( $items as $item ) {
			// Optionally link to existing keyword if found.
			$keyword_id = null;
			$kw         = sanitize_text_field( $item['keyword'] ?? '' );
			if ( $kw ) {
				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$p}aime_seo_keywords WHERE keyword = %s LIMIT 1",
					$kw
				) );
				if ( $existing ) {
					$keyword_id = (int) $existing;
				}
			}

			$wpdb->insert( "{$p}aime_seo_calendar", array(
				'title'        => sanitize_text_field( $item['title'] ?? '' ),
				'keyword_id'   => $keyword_id,
				'content_type' => sanitize_key( $item['content_type'] ?? 'blog_post' ),
				'status'       => 'planned',
				'planned_date' => sanitize_text_field( $item['planned_date'] ?? '' ) ?: null,
				'notes'        => sanitize_textarea_field( $item['brief'] ?? '' ),
				'is_pro'       => aime_has_pro() ? 1 : 0,
			) );

			if ( $wpdb->insert_id ) {
				$count++;
			}
		}

		return $count;
	}
}
