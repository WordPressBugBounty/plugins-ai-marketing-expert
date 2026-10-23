<?php
/**
 * SEO Module — Link Building Service.
 *
 * AI-generated outreach emails for link building.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Services;

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LinkBuildingService {

	use ParsesAiJson;

	/**
	 * Generate AI outreach email for a backlink prospect.
	 */
	public function generate_outreach_email( int $backlink_id, string $context ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$backlink = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_backlinks WHERE id = %d",
			$backlink_id
		), ARRAY_A );

		if ( ! $backlink ) {
			return array(
				'success' => false,
				'message' => __( 'Backlink entry not found.', 'ai-marketing-expert' ),
			);
		}

		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();
		$contact   = $backlink['contact_name'] ?: 'Webmaster';
		$source    = $backlink['source_url'];
		$target    = $backlink['target_url'];
		$link_type = $backlink['link_type'];

		$context_hint = $context ? "Additional context: {$context}" : '';

		$prompt = implode( "\n", array(
			'You are an expert SEO outreach specialist. Write a personalized, professional outreach email for link building.',
			'',
			"My website: {$site_name} ({$site_url})",
			"Target page on their site: {$source}",
			"My page I want linked: {$target}",
			"Link building type: {$link_type}",
			"Contact name: {$contact}",
			$context_hint,
			'',
			'Write:',
			'- subject: compelling email subject line',
			'- body: the email body (professional, concise, value-focused, 150-250 words)',
			'- follow_up: a shorter follow-up email (50-100 words)',
			'',
			'Guidelines:',
			'- Be genuine and personalized, not spammy',
			'- Mention something specific about their content',
			'- Clearly explain the value proposition',
			'- Include a clear call to action',
			'',
			'Return ONLY valid JSON:',
			'{',
			'  "subject": "",',
			'  "body": "",',
			'  "follow_up_subject": "",',
			'  "follow_up": ""',
			'}',
		) );

		$response = AiProvider::generate( $prompt, 'text', 2048 );

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

		// Save template to backlink record.
		$template = wp_json_encode( $data );
		$wpdb->update(
			"{$p}aime_seo_backlinks",
			array(
				'outreach_template' => $template,
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( 'id' => $backlink_id )
		);

		return array(
			'success' => true,
			'data'    => $data,
		);
	}
}
