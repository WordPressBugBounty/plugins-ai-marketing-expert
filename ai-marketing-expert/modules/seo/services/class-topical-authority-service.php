<?php
/**
 * SEO Module — Topical Authority Service.
 *
 * AI-powered topical authority map generation.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Services;

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TopicalAuthorityService {

	use ParsesAiJson;

	/**
	 * Generate a topical authority map for a niche/pillar topic.
	 *
	 * Creates pillar topics, cluster topics, and internal linking suggestions,
	 * then saves them to the database.
	 *
	 * @param int|null $max_topics Cap on saved topics (free plan), or null for unlimited.
	 */
	public function generate_topical_map( string $niche, string $pillar_topic, ?int $max_topics = null ): array {
		$focus = $pillar_topic ?: $niche;

		$prompt = implode( "\n", array(
			'You are an expert SEO topical authority strategist. Generate a comprehensive topical authority map.',
			'',
			"Focus area: {$focus}",
			"Niche: {$niche}",
			'',
			'Create a structured topical authority map with:',
			'- 3-5 pillar topics (broad, high-volume cornerstone content)',
			'- 5-8 cluster topics per pillar (more specific supporting content)',
			'- Internal linking strategy between pillars and clusters',
			'',
			'For each topic provide:',
			'- name: topic/article title',
			'- topic_type: "pillar" or "cluster"',
			'- target_keyword: the primary keyword to target',
			'- search_volume_estimate: estimated monthly search volume',
			'- difficulty_estimate: estimated difficulty (0-100)',
			'- content_type: blog_post, pillar_page, listicle, how_to, comparison',
			'- word_count_target: recommended word count',
			'- priority: 1-5 (1 = highest priority)',
			'- brief: 2-3 sentence content brief',
			'',
			'Also provide:',
			'- internal_links: recommended linking connections between topics',
			'- content_order: recommended publishing order',
			'',
			'Return ONLY valid JSON:',
			'{',
			'  "pillars": [',
			'    {',
			'      "name": "",',
			'      "target_keyword": "",',
			'      "search_volume_estimate": 0,',
			'      "difficulty_estimate": 0,',
			'      "content_type": "pillar_page",',
			'      "word_count_target": 3000,',
			'      "priority": 1,',
			'      "brief": "",',
			'      "clusters": [',
			'        {',
			'          "name": "",',
			'          "target_keyword": "",',
			'          "search_volume_estimate": 0,',
			'          "difficulty_estimate": 0,',
			'          "content_type": "blog_post",',
			'          "word_count_target": 1500,',
			'          "priority": 2,',
			'          "brief": ""',
			'        }',
			'      ]',
			'    }',
			'  ],',
			'  "internal_links": [{"from": "", "to": "", "anchor_text": ""}],',
			'  "content_order": [""]',
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

		if ( ! $data || ! isset( $data['pillars'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to parse AI response.', 'ai-marketing-expert' ),
			);
		}

		// Save to database.
		$saved = $this->save_topical_map( $data, $max_topics );

		return array(
			'success'       => true,
			'data'          => $data,
			'saved_topics'  => $saved['topic_count'],
			'saved_links'   => $saved['link_count'],
			'dropped_topics' => $saved['dropped_count'],
		);
	}

	/**
	 * Save generated topical map to the database.
	 *
	 * @param int|null $max_topics Cap on saved topics, or null for unlimited.
	 */
	private function save_topical_map( array $data, ?int $max_topics = null ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$topic_count = 0;
		$link_count  = 0;
		$requested   = 0;
		$name_to_id  = array();

		foreach ( $data['pillars'] as $pillar ) {
			$clusters   = $pillar['clusters'] ?? array();
			$requested += 1 + count( $clusters );

			if ( null !== $max_topics && $topic_count >= $max_topics ) {
				continue;
			}

			$pillar_id = $this->insert_topic( $pillar, 'pillar', null );
			if ( ! $pillar_id ) {
				continue;
			}

			$topic_count++;
			$name_to_id[ $pillar['name'] ] = $pillar_id;

			foreach ( $clusters as $cluster ) {
				if ( null !== $max_topics && $topic_count >= $max_topics ) {
					break;
				}

				$cluster_id = $this->insert_topic( $cluster, 'cluster', $pillar_id );
				if ( $cluster_id ) {
					$topic_count++;
					$name_to_id[ $cluster['name'] ] = $cluster_id;
				}
			}
		}

		// Save internal links. Links to topics dropped by the cap are skipped.
		foreach ( $data['internal_links'] ?? array() as $link ) {
			$from_name = $link['from'] ?? '';
			$to_name   = $link['to'] ?? '';

			if ( isset( $name_to_id[ $from_name ], $name_to_id[ $to_name ] ) ) {
				$wpdb->insert( "{$p}aime_seo_topic_links", array(
					'source_topic_id' => $name_to_id[ $from_name ],
					'target_topic_id' => $name_to_id[ $to_name ],
					'link_type'       => 'internal_link',
					'anchor_text'     => sanitize_text_field( $link['anchor_text'] ?? '' ),
					'status'          => 'planned',
				) );
				$link_count++;
			}
		}

		return array(
			'topic_count'   => $topic_count,
			'link_count'    => $link_count,
			'dropped_count' => max( 0, $requested - $topic_count ),
		);
	}

	/**
	 * Insert a single topic into the database.
	 */
	private function insert_topic( array $topic, string $type, ?int $parent_id ): ?int {
		global $wpdb;
		$p = $wpdb->prefix;

		$wpdb->insert( "{$p}aime_seo_topics", array(
			'name'              => sanitize_text_field( $topic['name'] ?? '' ),
			'description'       => sanitize_textarea_field( $topic['brief'] ?? '' ),
			'topic_type'        => $type,
			'parent_id'         => $parent_id,
			'status'            => 'planned',
			'content_brief'     => sanitize_textarea_field( $topic['brief'] ?? '' ),
			'word_count_target' => absint( $topic['word_count_target'] ?? 1500 ),
			'priority'          => min( 5, max( 1, absint( $topic['priority'] ?? 3 ) ) ),
			'is_pro'            => aime_has_pro() ? 1 : 0,
		) );

		return $wpdb->insert_id ?: null;
	}
}
