<?php
/**
 * SEO Module — Entity SEO Service.
 *
 * AI-powered entity analysis and structured data suggestions.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Services;

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntitySeoService {

	use ParsesAiJson;

	/**
	 * Analyze entities in content and suggest schema markup.
	 */
	public function analyze_entities( int $wp_post_id, string $keyword ): array {
		$post = get_post( $wp_post_id );

		if ( ! $post ) {
			return array(
				'success' => false,
				'message' => __( 'Post not found.', 'ai-marketing-expert' ),
			);
		}

		$content = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 3000 );
		$title   = $post->post_title;

		$prompt = implode( "\n", array(
			'You are an expert in entity SEO and structured data. Analyze this content for entity optimization.',
			'',
			"Title: {$title}",
			"Focus keyword: {$keyword}",
			"Content excerpt: {$content}",
			'',
			'Provide:',
			'- entities: key entities found in the content (people, places, organizations, concepts)',
			'- missing_entities: important entities related to the topic that should be mentioned',
			'- schema_suggestions: recommended JSON-LD schema types and properties for this content',
			'- knowledge_panel_tips: tips to improve chances of appearing in knowledge panels',
			'- entity_connections: how entities in this content connect to broader knowledge graph',
			'',
			'For schema suggestions, provide actual JSON-LD examples.',
			'',
			'Return ONLY valid JSON:',
			'{',
			'  "entities": [{"name": "", "type": "", "relevance": "high|medium|low"}],',
			'  "missing_entities": [{"name": "", "type": "", "why": ""}],',
			'  "schema_suggestions": [{"type": "", "description": "", "json_ld": {}}],',
			'  "knowledge_panel_tips": [""],',
			'  "entity_connections": [{"from": "", "to": "", "relationship": ""}]',
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

		if ( ! $data ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to parse AI response.', 'ai-marketing-expert' ),
			);
		}

		return array( 'success' => true, 'data' => $data );
	}
}
