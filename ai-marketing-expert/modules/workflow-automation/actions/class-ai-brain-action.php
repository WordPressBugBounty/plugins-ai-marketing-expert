<?php
/**
 * AI Brain Action — intelligent topic selection with context awareness.
 *
 * Picks the best topic from a feature list, avoids recent repeats, reads
 * product context from a cached URL, and generates a structured content brief
 * for downstream steps (Blog Post, Social Post, etc.).
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions;

use WPSpace\AiMarketingExpert\AiProvider;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\ContextKnowledgeStore;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\SkillRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiBrainAction extends BaseAction {

	/**
	 * Run the AI Brain step.
	 *
	 * @param array $config  Step config.
	 * @param array $context Workflow context.
	 * @return array Action result.
	 */
	public static function run( array $config, array $context ): array {
		// 1. Get strategy prompt (v2: full user instructions).
		$strategy_prompt = trim( (string) ( $config['strategy_prompt'] ?? '' ) );
		if ( '' === $strategy_prompt ) {
			return self::fail( __( 'No strategy prompt provided. Add your content strategy instructions in the AI Brain step config.', 'ai-marketing-expert' ) );
		}

		// 2. Query recently covered topics.
		$lookback_days = max( 7, (int) ( $config['lookback_days'] ?? 30 ) );
		$recent        = self::recent_topics( $lookback_days, 30 );

		// 3. Fetch context URLs (line-separated, max 5).
		$context_urls = trim( (string) ( $config['context_urls'] ?? '' ) );
		$url_contexts = array();

		if ( '' !== $context_urls ) {
			$urls = array_values( array_filter( array_map( 'trim', explode( "\n", $context_urls ) ) ) );
			$urls = array_slice( $urls, 0, 5 ); // Max 5 URLs

			$cache_duration = max( 86400, (int) ( $config['cache_duration'] ?? 2592000 ) ); // 1-30 days, default 30

			foreach ( $urls as $url ) {
				if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
					$content = ContextKnowledgeStore::get( $url, $cache_duration );
					if ( '' !== $content ) {
						$url_contexts[] = sprintf(
							"--- Context from %s ---\n%s",
							esc_url_raw( $url ),
							mb_substr( $content, 0, 2000 )
						);
					}
				}
			}
		}

		// 4. Build the full AI prompt.
		// Custom JSON mode (Pro): request machine-readable output so downstream
		// steps can read reference['json'] directly.
		$want_json = 'json' === sanitize_key( (string) ( $config['output_format'] ?? 'brief' ) ) && aime_has_pro();

		$system_instructions = "You are a content strategist helping plan marketing content.\n\n";

		// Active skills (Option A): composable instruction blocks selected per workflow.
		// Unknown/deleted skill IDs are dropped silently so old configs never break.
		$skills            = SkillRegistry::resolve( $config['skill_ids'] ?? array() );
		$skill_instructions = SkillRegistry::merge_instructions( $skills );
		if ( '' !== $skill_instructions ) {
			$system_instructions .= $skill_instructions . "\n";
		}

		if ( $recent ) {
			$recent_list = implode( ', ', array_slice( $recent, 0, 20 ) );
			$system_instructions .= "Topics already covered recently (avoid repeating these):\n{$recent_list}\n\n";
		}

		if ( $url_contexts ) {
			$system_instructions .= "Context information:\n\n" . implode( "\n\n", $url_contexts ) . "\n\n";
		}

		$system_instructions .= "---\n\nUser's strategy instructions:\n\n{$strategy_prompt}\n\n";
		$system_instructions .= "---\n\n";

		// Build structured output schema. SEO package fields are always
		// requested so downstream steps get a universal content contract
		// (focus keyword, title, meta, slug, image queries) regardless of
		// which skills are active — skills only strengthen the instructions.
		$json_schema = null;
		if ( $want_json ) {
			$json_schema = array(
				'name'   => 'ai_brain_content_strategy',
				'strict' => true,
				'schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'topic'         => array(
							'type'        => 'string',
							'description' => 'The selected topic for content creation',
						),
						'angle'         => array(
							'type'        => 'string',
							'description' => 'Specific writing angle or hook, 1-2 sentences',
						),
						'key_points'    => array(
							'type'        => 'array',
							'description' => '3-5 bullet points the content should cover',
							'items'       => array( 'type' => 'string' ),
						),
						'target_reader' => array(
							'type'        => 'string',
							'description' => 'Who this content is for',
						),
						'keywords'      => array(
							'type'        => 'array',
							'description' => '3-5 SEO keywords',
							'items'       => array( 'type' => 'string' ),
						),
						'focus_keyword' => array(
							'type'        => 'string',
							'description' => 'Single primary focus keyword, 2-4 words',
						),
						'seo_title'     => array(
							'type'        => 'string',
							'description' => 'SEO title max 60 chars, keyword near start, include number',
						),
						'meta_description' => array(
							'type'        => 'string',
							'description' => 'Meta description 140-160 chars including keyword',
						),
						'slug'          => array(
							'type'        => 'string',
							'description' => 'Short URL slug, 3-5 lowercase words with keyword',
						),
						'image_queries' => array(
							'type'        => 'array',
							'description' => '3 distinct stock photo queries, 2-4 concrete words each',
							'items'       => array( 'type' => 'string' ),
						),
						'social_hook'   => array(
							'type'        => 'string',
							'description' => 'One-line social hook max 120 chars',
						),
						'cta'           => array(
							'type'        => 'string',
							'description' => 'Short call to action max 12 words',
						),
					),
					'required'             => array( 'topic', 'angle', 'key_points', 'target_reader', 'keywords' ),
					'additionalProperties' => false,
				),
			);

			$system_instructions .= "Analyze the strategy and return a structured content brief.";
		} else {
			$system_instructions .= "Respond with a content brief in this format:\n";
			$system_instructions .= "TOPIC: [the topic you selected]\n";
			$system_instructions .= "ANGLE: [specific writing angle or hook, 1-2 sentences]\n";
			$system_instructions .= "KEY POINTS: [3-5 bullet points the content should cover]\n";
			$system_instructions .= "TARGET READER: [who this content is for]\n";
			$system_instructions .= "KEYWORDS: [3-5 SEO keywords, comma-separated]\n";
			$system_instructions .= "FOCUS KEYWORD: [single primary keyword, 2-4 words]\n";
			$system_instructions .= "SEO TITLE: [max 60 chars, keyword near start, include number]\n";
			$system_instructions .= "META DESCRIPTION: [140-160 chars including keyword]\n";
			$system_instructions .= "SLUG: [3-5 lowercase words with keyword]\n";
			$system_instructions .= "IMAGE QUERIES: [3 distinct queries separated by | ]\n";
			$system_instructions .= "SOCIAL HOOK: [max 120 chars]\n";
			$system_instructions .= "CTA: [max 12 words]\n";
		}

		// 5. Call AI with structured output (json_schema) for reliable JSON extraction.
		$options = array();
		if ( $json_schema ) {
			$options['json_schema'] = $json_schema;
		}

		$result = AiProvider::generate( $system_instructions, 'text', $want_json ? 1200 : 1000, $options );
		if ( empty( $result['success'] ) ) {
			return self::fail( $result['message'] ?? __( 'AI Brain: generation failed.', 'ai-marketing-expert' ) );
		}

		$output = trim( (string) $result['content'] );
		if ( '' === $output ) {
			return self::fail( __( 'AI Brain returned an empty response.', 'ai-marketing-expert' ) );
		}

		// 6. Parse structured response.
		$parsed_json = null;
		if ( $want_json ) {
			// With json_schema, the model guarantees valid JSON structure.
			// Direct decode — no thinking stripping needed.
			$parsed_json = json_decode( $output, true );

			// Fallback: if provider doesn't support json_schema or decode fails,
			// use heuristic parsing as safety net.
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$output      = aime_strip_thinking_text( $output, 'json' );
				$parsed_json = aime_parse_ai_json( $output );
			}

			if ( ! is_array( $parsed_json ) || empty( $parsed_json['topic'] ) ) {
				// Malformed JSON — degrade to legacy brief parsing instead of failing.
				$parsed_json = null;
			}
		} else {
			// Text mode — strip any thinking text that may have leaked through.
			$output = aime_strip_thinking_text( $output, 'text' );
		}

		if ( is_array( $parsed_json ) ) {
			$topic        = trim( (string) $parsed_json['topic'] );
			$angle        = trim( (string) ( $parsed_json['angle'] ?? '' ) );
			$key_points   = implode( "\n", array_map( 'strval', (array) ( $parsed_json['key_points'] ?? array() ) ) );
			$target       = trim( (string) ( $parsed_json['target_reader'] ?? '' ) );
			$keywords_raw = implode( ', ', array_map( 'strval', (array) ( $parsed_json['keywords'] ?? array() ) ) );
			$focus        = sanitize_text_field( (string) ( $parsed_json['focus_keyword'] ?? '' ) );
			$seo_title    = sanitize_text_field( (string) ( $parsed_json['seo_title'] ?? '' ) );
			$meta_desc    = sanitize_textarea_field( (string) ( $parsed_json['meta_description'] ?? '' ) );
			$slug         = sanitize_title( (string) ( $parsed_json['slug'] ?? '' ) );
			$img_queries  = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $parsed_json['image_queries'] ?? array() ) ) ) );
			$social_hook  = sanitize_text_field( (string) ( $parsed_json['social_hook'] ?? '' ) );
			$cta          = sanitize_text_field( (string) ( $parsed_json['cta'] ?? '' ) );
			// Normalize: focus defaults to first keyword so downstream never has empty keyword.
			if ( '' === $focus && '' !== $keywords_raw ) {
				$parts = array_filter( array_map( 'trim', explode( ',', $keywords_raw ) ) );
				$focus = sanitize_text_field( (string) ( reset( $parts ) ?: '' ) );
			}
			$parsed_json['focus_keyword']    = $focus;
			$parsed_json['seo_title']        = $seo_title;
			$parsed_json['meta_description'] = $meta_desc;
			$parsed_json['slug']             = $slug;
			$parsed_json['image_queries']    = $img_queries;
			$parsed_json['social_hook']      = $social_hook;
			$parsed_json['cta']              = $cta;
			$output       = (string) wp_json_encode( $parsed_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} else {
			$topic        = self::extract_field( $output, 'TOPIC' );
			$angle        = self::extract_field( $output, 'ANGLE' );
			$key_points   = self::extract_field( $output, 'KEY POINTS' );
			$target       = self::extract_field( $output, 'TARGET READER' );
			$keywords_raw = self::extract_field( $output, 'KEYWORDS' );
			$focus        = sanitize_text_field( self::extract_field( $output, 'FOCUS KEYWORD' ) );
			$seo_title    = sanitize_text_field( self::extract_field( $output, 'SEO TITLE' ) );
			$meta_desc    = sanitize_textarea_field( self::extract_field( $output, 'META DESCRIPTION' ) );
			$slug         = sanitize_title( self::extract_field( $output, 'SLUG' ) );
			$img_raw      = self::extract_field( $output, 'IMAGE QUERIES' );
			$img_queries  = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( '|', $img_raw ) ) ) ) );
			$social_hook  = sanitize_text_field( self::extract_field( $output, 'SOCIAL HOOK' ) );
			$cta          = sanitize_text_field( self::extract_field( $output, 'CTA' ) );
			if ( '' === $focus && '' !== $keywords_raw ) {
				$parts = array_filter( array_map( 'trim', explode( ',', $keywords_raw ) ) );
				$focus = sanitize_text_field( (string) ( reset( $parts ) ?: '' ) );
			}

			// Fallback: if parsing failed, use first line as topic.
			if ( '' === $topic ) {
				$lines = explode( "\n", $output );
				$topic = trim( $lines[0] );
			}
		}

		aime_log(
			sprintf( 'AI Brain selected topic: "%s"', $topic ),
			'info',
			'workflow-automation'
		);

		// 7. Return structured output for downstream steps.
		// Universal content contract: every key is scalar or simple array so
		// BaseAction::resolve_from_context() can read it as {ai_brain.*}.
		$reference = array(
			'selected_topic'   => $topic,
			'angle'            => $angle,
			'key_points'       => $key_points,
			'target_reader'    => $target,
			'keywords'         => $keywords_raw,
			'focus_keyword'    => isset( $focus ) ? $focus : '',
			'seo_title'        => isset( $seo_title ) ? $seo_title : '',
			'meta_description' => isset( $meta_desc ) ? $meta_desc : '',
			'slug'             => isset( $slug ) ? $slug : '',
			'image_queries'    => isset( $img_queries ) ? implode( ' | ', $img_queries ) : '',
			'social_hook'      => isset( $social_hook ) ? $social_hook : '',
			'cta'              => isset( $cta ) ? $cta : '',
			'skill_ids'        => implode( ',', array_map( static fn ( array $s ): string => (string) ( $s['id'] ?? '' ), $skills ) ),
			'full_output'      => $output,
		);
		if ( is_array( $parsed_json ) ) {
			// Machine-readable brief for advanced downstream consumption
			// (reachable as {ai_brain.json} / {previous.json} via tokens).
			$reference['json'] = $parsed_json;
		}

		return self::ok(
			sprintf(
				/* translators: %s: selected topic name */
				__( 'AI Brain selected: %s', 'ai-marketing-expert' ),
				$topic
			),
			$reference
		);
	}

	/**
	 * Extract a named field from structured AI output.
	 *
	 * Matches "FIELDNAME: value" or "**FIELDNAME:** value" (Markdown bold).
	 *
	 * @param string $text  Full AI response.
	 * @param string $field Field name to extract.
	 * @return string Field value (empty string if not found).
	 */
	private static function extract_field( string $text, string $field ): string {
		$pattern = '/(?:\*\*)?' . preg_quote( $field, '/' ) . '(?:\*\*)?:\s*(.+?)(?:\n|$)/is';
		if ( preg_match( $pattern, $text, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}
}
