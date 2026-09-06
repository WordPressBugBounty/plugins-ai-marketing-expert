<?php
/**
 * Custom Prompt action — free-form AI prompt; logs the output, optionally saves
 * it as a draft article.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions;

use WPSpace\AiMarketingExpert\AiProvider;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\ContextKnowledgeStore;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\WorkflowTokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CustomPromptAction extends BaseAction {

	public static function run( array $config, array $context ): array {
		// Tokens ({topic}, {event.*}, {previous.*}, …) resolve before the
		// prompt is sent, matching the notification/email token vocabulary.
		$prompt = trim( WorkflowTokens::replace( (string) ( $config['prompt'] ?? '' ), $context ) );
		if ( '' === $prompt ) {
			return self::fail( __( 'No prompt provided.', 'ai-marketing-expert' ) );
		}

		// Inject workflow topic/context so the prompt can reference it.
		$topic = (string) ( $context['topic'] ?? '' );
		if ( '' !== $topic ) {
			$prompt .= "\n\nWorkflow topic/context: " . $topic;
		}
		// Allow chaining: expose the previous step's preview.
		$prev = $context['previous'][ count( $context['previous'] ) - 1 ] ?? null;
		if ( $prev && ! empty( $prev['preview'] ) ) {
			$prompt .= "\n\nPrevious step output: " . $prev['preview'];
		}

		// Auto-fetch URLs in the prompt so the AI receives page content.
		$prompt = self::inject_url_contents( $prompt );

		// Brand voice (Pro) keeps free-form output consistent with the batch.
		$voice_prompt = self::brand_voice_system_prompt( $context );
		if ( '' !== $voice_prompt ) {
			$prompt .= "\n\nBrand voice guidelines:\n" . mb_substr( $voice_prompt, 0, 800 );
		}

		$result = AiProvider::generate( $prompt, 'text', 2048 );
		if ( empty( $result['success'] ) ) {
			return self::fail( $result['message'] ?? __( 'AI generation failed.', 'ai-marketing-expert' ) );
		}

		// Free-form output shown to the user — strip leaked reasoning in
		// conversational mode (no JSON-prefix truncation).
		$output = trim( aime_strip_thinking_text( (string) $result['content'], 'text' ) );
		if ( '' === $output ) {
			return self::fail( __( 'AI returned an empty response.', 'ai-marketing-expert' ) );
		}

		$reference = array();
		if ( ! empty( $config['save_as_draft'] ) ) {
			$title      = wp_trim_words( wp_strip_all_tags( $output ), 8, '' ) ?: __( 'Custom AI Output', 'ai-marketing-expert' );
			$article_id = self::save_article( array(
				'title'   => sanitize_text_field( $title ),
				'content' => wp_kses_post( wpautop( $output ) ),
			), $result );
			if ( $article_id ) {
				$reference['article_id'] = $article_id;
				$reference['link']       = self::module_link( 'content', 'articles' );
			}
		}

		// Store full output so downstream steps can read it.
		$reference['full_output'] = $output;

		aime_log( 'Workflow custom prompt executed.', 'info', 'workflow-automation' );

		return self::ok( wp_trim_words( wp_strip_all_tags( $output ), 30, '…' ), $reference );
	}

	/**
	 * Detect URLs in a prompt and inject their fetched content.
	 *
	 * Max 3 URLs to avoid timeouts. Uses ContextKnowledgeStore for caching.
	 *
	 * @param string $prompt Original prompt text.
	 * @return string Prompt with URL content appended.
	 */
	private static function inject_url_contents( string $prompt ): string {
		$pattern = '/https?:\/\/[^\s\'"<>]+/i';
		if ( ! preg_match_all( $pattern, $prompt, $matches ) ) {
			return $prompt;
		}

		$urls     = array_unique( $matches[0] );
		$injected = array();

		foreach ( array_slice( $urls, 0, 3 ) as $url ) {
			$text = ContextKnowledgeStore::get( $url, 604800 ); // 7 days
			if ( '' !== $text ) {
				$injected[] = sprintf(
					"--- Content from %s ---\n%s\n---",
					esc_url_raw( $url ),
					$text
				);
			}
		}

		if ( empty( $injected ) ) {
			return $prompt;
		}

		return $prompt . "\n\n" . __( 'URL content fetched for context:', 'ai-marketing-expert' ) . "\n" . implode( "\n\n", $injected );
	}
}
