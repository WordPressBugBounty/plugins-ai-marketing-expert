<?php
/**
 * Generate Controller — AI content generation endpoints.
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers;

use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services\ContentGeneratorService;
use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services\SeoAnalyzerService;
use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers\WorkflowController;
use WPSpace\AiMarketingExpert\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GenerateController {

	private ContentGeneratorService $generator;

	public function __construct() {
		$this->generator = new ContentGeneratorService();
	}

	/* ── GENERATE full article ───────────────────────── */

	public function generate( \WP_REST_Request $request ): \WP_REST_Response {
		// Check free limit.
		if ( ! aime_has_pro() ) {
			$monthly = \WPSpace\AiMarketingExpert\Modules\ContentGenerator\ContentGeneratorModule::get_monthly_article_count();
			$limits  = aime_free_limits();
			if ( $monthly >= ( $limits['content_articles_per_month'] ?? 10 ) ) {
				return new \WP_REST_Response( array(
					'message'       => __( 'Monthly article limit reached. Upgrade to Pro for unlimited articles.', 'ai-marketing-expert' ),
					'limit_reached' => true,
				), 403 );
			}
		}

		// Check word count limit.
		$word_count = absint( $request->get_param( 'word_count' ) ?: 1000 );
		if ( ! aime_has_pro() ) {
			$limits   = aime_free_limits();
			$max      = $limits['content_max_words'] ?? 2000;
			$word_count = min( $word_count, $max );
		}

		$topic    = sanitize_text_field( $request->get_param( 'topic' ) );
		$keywords = array_map( 'sanitize_text_field', $request->get_param( 'keywords' ) ?: array() );
		$tone     = sanitize_text_field( $request->get_param( 'tone' ) ?: 'professional' );
		if ( ! aime_has_pro() && in_array( $tone, array( 'humorous', 'formal', 'conversational' ), true ) ) {
			$tone = 'professional';
		}
		$language = sanitize_text_field( $request->get_param( 'language' ) ?: 'en' );
		$outline  = sanitize_textarea_field( $request->get_param( 'outline' ) ?: '' );
		$serp_context = sanitize_textarea_field( $request->get_param( 'serp_context' ) ?: '' );
		$include_table_of_contents = aime_has_pro() ? rest_sanitize_boolean( $request->get_param( 'include_table_of_contents' ) ) : false;
		if ( $serp_context ) {
			$outline = trim( $outline . "\n\nSERP-aware brief context:\n" . $serp_context );
		}
		if ( $include_table_of_contents ) {
			$outline = trim( $outline . "\n\nTable of contents requirement: Add a linked HTML table of contents near the top of the article before the first h2 section. Use a nav element with class \"aime-article-toc\", a short heading, and anchor links to the main h2 sections. Add matching id attributes to the h2 headings. Keep anchor ids readable, lowercase, and unique." );
		}
		$post_type = sanitize_key( $request->get_param( 'post_type' ) ?: 'post' );
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}
		$brand_voice_id = aime_has_pro() ? absint( $request->get_param( 'brand_voice_id' ) ) : 0;

		// Load preset if specified.
		$preset_id = absint( $request->get_param( 'preset_id' ) );
		$preset    = null;
		if ( $preset_id ) {
			global $wpdb;
			$preset = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aime_content_presets WHERE id = %d",
				$preset_id
			) );
		}

		$brand_voice_prompt = WorkflowController::get_brand_voice_prompt( $brand_voice_id );
		if ( $brand_voice_prompt ) {
			if ( ! $preset ) {
				$preset = (object) array( 'prompt_template' => '', 'system_instructions' => '' );
			}
			$preset->system_instructions = trim( ( $preset->system_instructions ?? '' ) . "\n\n" . $brand_voice_prompt );
		}

		// In-body stock images (site setting): the AI embeds placeholders,
		// which are swapped for imported stock photos after generation.
		$cg_settings   = get_option( 'aime_content-generator_settings', array() );
		$inline_images = min( 3, absint( $cg_settings['inline_images'] ?? 0 ) );
		if ( $inline_images > 0 && ! \WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services\StockImageService::is_configured() ) {
			$inline_images = 0;
		}

		$result = $this->generator->generate_article( $topic, $keywords, $tone, $word_count, $language, $outline, $preset, $include_table_of_contents, $inline_images );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array(
				'message' => __( 'AI generation failed.', 'ai-marketing-expert' ),
				'error'   => $result['error'] ?? '',
			), 500 );
		}

		// Save as article.
		global $wpdb;
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql', true );

		$content       = $result['content'];
		$generated     = $result['parsed'] ?? array();
		$article_title = $generated['title'] ?? $topic;
		$article_body  = $generated['body'] ?? $content;
		$excerpt       = $generated['excerpt'] ?? '';

		// Clean up the body: remove any residual JSON wrapper and convert literal \n to real newlines.
		$article_body = self::clean_ai_body( $article_body, sanitize_text_field( (string) $article_title ) );

		// Swap AI image placeholders for stock photos (fail-soft; also strips
		// any leftover placeholders when the feature is off).
		$stock_service = new \WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services\StockImageService();
		$article_body  = $stock_service->embed_inline_images( $article_body, $inline_images, $topic );

		$inserted = $wpdb->insert( "{$p}aime_content_articles", array(
			'title'             => sanitize_text_field( $article_title ),
			'slug'              => sanitize_title( $article_title ),
			'content'           => aime_kses_article( $article_body ),
			'excerpt'           => sanitize_textarea_field( $excerpt ),
			'status'            => 'ready',
			'post_type'         => $post_type,
			'topic'             => $topic,
			'keywords'          => wp_json_encode( $keywords ),
			'tone'              => $tone,
			'word_count_target' => $word_count,
			'actual_word_count' => str_word_count( wp_strip_all_tags( $article_body ) ),
			'language'          => $language,
			'ai_provider'       => $result['provider'] ?? '',
			'ai_model'          => $result['model'] ?? '',
			'outline'           => wp_json_encode( $generated['outline'] ?? array() ),
			'category_ids'      => wp_json_encode( array_map( 'absint', (array) ( $request->get_param( 'category_ids' ) ?: array() ) ) ),
			'tag_ids'           => wp_json_encode( array_map( 'sanitize_text_field', (array) ( $request->get_param( 'tag_ids' ) ?: array() ) ) ),
			'preset_id'         => $preset_id ?: null,
			'brand_voice_id'    => $brand_voice_id ?: null,
			'created_at'        => $now,
			'updated_at'        => $now,
		) );

		// A failed insert must not fall through: the AI cost was already paid,
		// but returning id:0 would create a phantom history row and a broken
		// 201 response. Surface the failure instead.
		if ( false === $inserted || 0 === (int) $wpdb->insert_id ) {
			return new \WP_REST_Response( array(
				'message' => __( 'The article was generated but could not be saved: ', 'ai-marketing-expert' ) . ( $wpdb->last_error ?: __( 'unknown database error.', 'ai-marketing-expert' ) ),
			), 500 );
		}

		$article_id = (int) $wpdb->insert_id;
		WorkflowController::save_article_version( $article_id, 'Generated' );

		// Load automation settings.
		$settings = get_option( 'aime_content-generator_settings', array() );
		$auto_meta    = aime_has_pro() ? ( $settings['auto_generate_meta']    ?? true ) : false;
		$auto_excerpt = aime_has_pro() ? ( $settings['auto_generate_excerpt'] ?? true ) : false;
		$auto_seo     = aime_has_pro() ? ( $settings['auto_seo_optimize']     ?? true ) : false;

		// Auto-generate meta title + description.
		if ( $auto_meta ) {
			try {
				$meta_result = $this->generator->generate_meta(
					sanitize_text_field( $article_title ),
					aime_kses_article( $article_body ),
					$keywords
				);
				if ( ! empty( $meta_result['success'] ) && ! empty( $meta_result['meta'] ) ) {
					$meta_title = sanitize_text_field( $meta_result['meta']['meta_title'] ?? '' );
					$meta_desc  = sanitize_text_field( $meta_result['meta']['meta_description'] ?? '' );
					if ( $meta_title || $meta_desc ) {
						$wpdb->update(
							"{$p}aime_content_articles",
							array_filter( array(
								'meta_title'       => $meta_title,
								'meta_description' => $meta_desc,
							) ),
							array( 'id' => $article_id )
						);
					} else {
						aime_log( 'Auto-generate meta: AI returned response but no meta_title/meta_description could be parsed. Raw: ' . wp_json_encode( $meta_result['meta'] ), 'warning', 'content-generator' );
					}
				} else {
					aime_log( 'Auto-generate meta failed for article #' . $article_id . ': ' . ( $meta_result['error'] ?? 'Unknown error' ), 'warning', 'content-generator' );
				}
			} catch ( \Throwable $e ) {
				aime_log( 'Auto-generate meta failed for article #' . $article_id . ': ' . $e->getMessage(), 'error', 'content-generator' );
			}
		}

		// Auto-generate excerpt (always when enabled, overrides article-level excerpt).
		if ( $auto_excerpt ) {
			try {
				$excerpt_result = $this->generator->generate_excerpt(
					sanitize_text_field( $article_title ),
					aime_kses_article( $article_body )
				);
				if ( ! empty( $excerpt_result['success'] ) && ! empty( $excerpt_result['excerpt'] ) ) {
					$excerpt = sanitize_textarea_field( $excerpt_result['excerpt'] );
					$wpdb->update(
						"{$p}aime_content_articles",
						array( 'excerpt' => $excerpt ),
						array( 'id' => $article_id )
					);
				} else {
					aime_log( 'Auto-generate excerpt failed for article #' . $article_id . ': ' . ( $excerpt_result['error'] ?? 'AI returned empty excerpt' ), 'warning', 'content-generator' );
				}
			} catch ( \Throwable $e ) {
				aime_log( 'Auto-generate excerpt failed for article #' . $article_id . ': ' . $e->getMessage(), 'error', 'content-generator' );
			}
		}

		// Log.
		$wpdb->insert( "{$p}aime_content_history", array(
			'article_id' => $article_id,
			'action'     => 'generated',
			'details'    => wp_json_encode( array(
				'topic'     => $topic,
				'provider'  => $result['provider'] ?? '',
				'model'     => $result['model'] ?? '',
				'providers' => $result['providers'] ?? array(),
				'continued' => (int) ( $result['continued'] ?? 0 ),
				'partial'   => ! empty( $result['truncated'] ),
				'words'     => str_word_count( wp_strip_all_tags( $article_body ) ),
			) ),
			'created_at' => $now,
		) );

		// Auto SEO score.
		if ( $auto_seo ) {
			$seo = new SeoAnalyzerService();
			$seo->quick_score( $article_id );
		}

		$article = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_content_articles WHERE id = %d", $article_id ) );

		$continued = (int) ( $result['continued'] ?? 0 );
		$partial   = ! empty( $result['truncated'] );

		if ( $continued > 0 ) {
			aime_log( sprintf(
				'Article #%d completed across %d provider(s) after the first one stopped early.',
				$article_id,
				count( $result['providers'] ?? array() )
			), 'info', 'content-generator' );
		}

		if ( $partial ) {
			$message = __( 'Article saved, but the AI providers ran out of capacity before finishing it. Review the ending in the editor and use Improve Content to extend it.', 'ai-marketing-expert' );
		} elseif ( $continued > 0 ) {
			$message = __( 'Article generated successfully (finished by a second AI provider after the first hit its limit).', 'ai-marketing-expert' );
		} else {
			$message = __( 'Article generated successfully.', 'ai-marketing-expert' );
		}

		return new \WP_REST_Response( array(
			'id'           => $article_id,
			'article'      => $article,
			'provider'     => $result['provider'] ?? '',
			'model'        => $result['model'] ?? '',
			'providers'    => $result['providers'] ?? array(),
			'continued'    => $continued,
			'partial'      => $partial,
			'image_search' => sanitize_text_field( (string) ( $generated['image_search'] ?? '' ) ),
			'message'      => $message,
		), 201 );
	}

	/* ── GENERATE outline only ───────────────────────── */

	public function generate_outline( \WP_REST_Request $request ): \WP_REST_Response {
		$check = Pro::gate( 'Generate Outline' );
		if ( is_wp_error( $check ) ) {
			return new \WP_REST_Response( array( 'message' => $check->get_error_message(), 'pro_required' => true ), 403 );
		}

		$topic    = sanitize_text_field( $request->get_param( 'topic' ) );
		$keywords = array_map( 'sanitize_text_field', $request->get_param( 'keywords' ) ?: array() );
		$tone     = sanitize_text_field( $request->get_param( 'tone' ) ?: 'professional' );
		$style    = sanitize_text_field( $request->get_param( 'style' ) ?: 'blog_post' );
		$serp_context = sanitize_textarea_field( $request->get_param( 'serp_context' ) ?: '' );
		if ( $serp_context ) {
			$topic .= "\n\nSERP-aware brief context:\n" . $serp_context;
		}

		$result = $this->generator->generate_outline( $topic, $keywords, $tone, $style );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( $result );
	}

	/* ── GENERATE single section ─────────────────────── */

	public function generate_section( \WP_REST_Request $request ): \WP_REST_Response {
		$topic         = sanitize_text_field( $request->get_param( 'topic' ) );
		$section_title = sanitize_text_field( $request->get_param( 'section_title' ) );
		$context       = sanitize_textarea_field( $request->get_param( 'context' ) ?: '' );
		$tone          = sanitize_text_field( $request->get_param( 'tone' ) ?: 'professional' );

		$result = $this->generator->generate_section( $topic, $section_title, $context, $tone );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( $result );
	}

	/* ── IMPROVE existing content ────────────────────── */

	public function improve_content( \WP_REST_Request $request ): \WP_REST_Response {
		$check = Pro::gate( 'Deep Humanize' );
		if ( is_wp_error( $check ) ) {
			return new \WP_REST_Response( array( 'message' => $check->get_error_message(), 'pro_required' => true ), 403 );
		}

		$content     = aime_kses_article( $request->get_param( 'content' ) );
		$instruction = sanitize_text_field( $request->get_param( 'instruction' ) );
		$tone        = sanitize_text_field( $request->get_param( 'tone' ) ?: 'professional' );

		$result = $this->generator->improve_content( $content, $instruction, $tone );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( $result );
	}

	/* ── SEO OPTIMIZE ────────────────────────────────── */

	public function seo_optimize( \WP_REST_Request $request ): \WP_REST_Response {
		$content  = aime_kses_article( $request->get_param( 'content' ) );
		$keywords = array_map( 'sanitize_text_field', $request->get_param( 'keywords' ) ?: array() );
		$title    = sanitize_text_field( $request->get_param( 'title' ) ?: '' );

		$seo    = new SeoAnalyzerService();
		$result = $seo->analyze_and_optimize( $content, $keywords, $title );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI analysis failed.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( $result );
	}

	/* ── GENERATE META (title + description) ─────────── */

	public function generate_meta( \WP_REST_Request $request ): \WP_REST_Response {
		$title    = sanitize_text_field( $request->get_param( 'title' ) );
		$content  = aime_kses_article( $request->get_param( 'content' ) ?: '' );
		$keywords = array_map( 'sanitize_text_field', $request->get_param( 'keywords' ) ?: array() );

		$result = $this->generator->generate_meta( $title, $content, $keywords );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( $result );
	}

	/* ── GENERATE IMAGE PROMPT (PRO) ─────────────────── */

	public function generate_image_prompt( \WP_REST_Request $request ): \WP_REST_Response {
		$check = Pro::gate( 'AI Image Prompts' );
		if ( is_wp_error( $check ) ) {
			return new \WP_REST_Response( array(
				'message' => $check->get_error_message(),
				'pro_required' => true,
			), 403 );
		}

		$title   = sanitize_text_field( $request->get_param( 'title' ) );
		$content = aime_kses_article( $request->get_param( 'content' ) ?: '' );

		$result = $this->generator->generate_image_prompt( $title, $content );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'AI generation failed.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( $result );
	}

	/**
	 * Clean up AI-generated body content.
	 *
	 * Strips leftover JSON wrappers, converts literal \n to real newlines,
	 * removes blank lines, strips AI safety-classification lines (e.g.
	 * "User Safety: safe"), drops a duplicate title heading (theme already
	 * renders H1), and ensures proper HTML.
	 *
	 * @param string $body  Raw body HTML.
	 * @param string $title Optional article title for duplicate-H1 stripping.
	 */
	public static function clean_ai_body( string $body, string $title = '' ): string {
		$body = trim( $body );

		// Remove Markdown code fences such as ```json ... ``` before JSON cleanup.
		$body = preg_replace( '/^\s*```(?:json|html)?\s*/i', '', $body );
		$body = preg_replace( '/\s*```\s*$/', '', $body );

		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) && ! empty( $decoded['body'] ) ) {
			$body = (string) $decoded['body'];
		}

		// If body starts with JSON-like prefix, strip it.
		$body = preg_replace( '/^\s*\{\s*"title"\s*:\s*"(?:[^"\\\\]|\\\\.)*"\s*,\s*"body"\s*:\s*"/si', '', $body );
		$body = preg_replace( '/^\s*\{\s*"[^"]*"\s*:\s*"(?:[^"\\\\]|\\\\.)*"\s*,\s*"body"\s*:\s*"/si', '', $body );
		// Remove trailing JSON closure.
		$body = preg_replace( '/"\s*,\s*"(excerpt|outline)"\s*:.*$/si', '', $body );
		$body = preg_replace( '/"\s*\}\s*$/s', '', $body );
		$body = preg_replace( '/\s*```\s*$/', '', $body );

		// Replace literal \n sequences with real newlines, then normalize.
		$body = str_replace( array( '\\n', '\n' ), "\n", $body );
		$body = stripcslashes( $body );

		// Strip Google Translate injected divs.
		$body = preg_replace( '/<div\b[^>]*id\s*=\s*["\']gtx-trans["\'][^>]*>.*?<\/div>/si', '', $body );
		$body = preg_replace( '/<div\b[^>]*class\s*=\s*["\']gtx-[^"\']*["\'][^>]*>.*?<\/div>/si', '', $body );

		// Strip AI safety-classification lines that some Gemini-family models
		// append to the response (e.g. "User Safety: safe", "Safety: safe",
		// "Category: ..."). Match anywhere in the body — these are not
		// legitimate user content and should never be saved into the post.
		$body = preg_replace( '/^[ \t]*(?:User\s+Safety|Safety|Harm\s+Category|Harm_Policy|Category|Severity)[ \t]*:[ \t]*[^\n\r]*[\n\r]?/im', '', $body );
		$body = preg_replace( '/(?:\n|\r)[ \t]*(?:User\s+Safety|Safety|Harm\s+Category|Harm_Policy|Category|Severity)[ \t]*:[ \t]*[^\n\r]*/i', '', $body );

		// Convert double-newline separated blocks into paragraphs if not already wrapped in tags.
		$body = trim( $body );

		// Single-H1 rule: drop a leading duplicate title heading when the
		// title is known (theme renders H1; body must not repeat it).
		if ( '' !== $title && '' !== $body ) {
			$body = ContentGeneratorService::strip_duplicate_title_heading( $body, $title );
		}

		// If any raw text remains un-wrapped, let wpautop handle it.
		if ( $body && ! preg_match( '/^\s*</', $body ) ) {
			$body = wpautop( $body );
		}

		return $body;
	}

	/* ── GENERATE IMAGE DIRECTLY (AI) ────────────────── */

	public function generate_image( \WP_REST_Request $request ): \WP_REST_Response {
		$check = Pro::gate( 'AI Featured Image' );
		if ( is_wp_error( $check ) ) {
			return new \WP_REST_Response( array( 'message' => $check->get_error_message(), 'pro_required' => true ), 403 );
		}

		$title      = sanitize_text_field( $request->get_param( 'title' ) );
		$content    = aime_kses_article( $request->get_param( 'content' ) ?: '' );
		$article_id = absint( $request->get_param( 'article_id' ) );

		// Build a descriptive prompt from the article.
		$stripped = mb_substr( wp_strip_all_tags( $content ), 0, 500 );
		$prompt   = "Create a professional, high-quality featured blog image for an article titled: \"{$title}\". ";
		if ( $stripped ) {
			$prompt .= "The article is about: {$stripped}. ";
		}
		$prompt .= "The image should be visually striking, relevant to the topic, suitable for a blog header. "
				 . "Style: professional, modern, clean composition. No text in the image.";

		$result = \WPSpace\AiMarketingExpert\AiProvider::generate_image( $prompt, $title, 0 );

		if ( ! $result['success'] ) {
			$error_msg = $result['message'] ?? __( 'Image generation failed.', 'ai-marketing-expert' );
			return new \WP_REST_Response( array(
				'message' => $error_msg,
				'code'    => 'image_generation_failed',
			), 500 );
		}

		// Update article if id is provided.
		if ( $article_id ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'aime_content_articles',
				array(
					'featured_image_url' => $result['image_url'],
					'featured_image_id'  => $result['attachment_id'] ?? null,
				),
				array( 'id' => $article_id ),
				array( '%s', '%d' ),
				array( '%d' )
			);
		}

		return new \WP_REST_Response( array(
			'image_url'     => $result['image_url'],
			'attachment_id' => $result['attachment_id'] ?? 0,
			'provider'      => $result['provider'] ?? '',
			'model'         => $result['model'] ?? '',
		) );
	}

	/* ── STOCK IMAGES (Pexels / Pixabay) ─────────────── */

	public function stock_search( \WP_REST_Request $request ): \WP_REST_Response {
		$service = new \WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services\StockImageService();

		$result = $service->search(
			sanitize_text_field( $request->get_param( 'query' ) ),
			absint( $request->get_param( 'per_page' ) ?: 12 ),
			absint( $request->get_param( 'page' ) ?: 1 )
		);

		if ( empty( $result['success'] ) ) {
			return new \WP_REST_Response( array(
				'message'        => $result['error'] ?? __( 'Stock image search failed.', 'ai-marketing-expert' ),
				'not_configured' => ! empty( $result['not_configured'] ),
			), ! empty( $result['not_configured'] ) ? 400 : 502 );
		}

		return new \WP_REST_Response( $result );
	}

	public function stock_import( \WP_REST_Request $request ): \WP_REST_Response {
		$service = new \WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services\StockImageService();

		$url        = esc_url_raw( $request->get_param( 'url' ) );
		$alt        = sanitize_text_field( $request->get_param( 'alt' ) ?: '' );
		$article_id = absint( $request->get_param( 'article_id' ) );

		$result = $service->import_to_media_library( $url, $alt, 0 );

		if ( empty( $result['success'] ) ) {
			return new \WP_REST_Response( array(
				'message' => $result['error'] ?? __( 'Image import failed.', 'ai-marketing-expert' ),
			), 400 );
		}

		// Update article if id is provided (mirrors generate_image).
		if ( $article_id ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'aime_content_articles',
				array(
					'featured_image_url' => $result['url'],
					'featured_image_id'  => $result['attachment_id'],
				),
				array( 'id' => $article_id ),
				array( '%s', '%d' ),
				array( '%d' )
			);
		}

		return new \WP_REST_Response( array(
			'attachment_id' => $result['attachment_id'],
			'url'           => $result['url'],
		) );
	}
}
