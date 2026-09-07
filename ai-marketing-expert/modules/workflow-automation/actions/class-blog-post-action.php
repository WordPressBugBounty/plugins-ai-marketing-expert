<?php
/**
 * Blog Post action — generate a full article and save it to the content module,
 * optionally publishing it.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions;

use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services\ContentGeneratorService;
use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services\PublisherService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlogPostAction extends BaseAction {

	/**
	 * @param array $config  Step config.
	 * @param array $context Workflow context.
	 * @return array
	 */
	public static function run( array $config, array $context ): array {
		// Inherit AI Brain (or Custom Prompt) output when no step-level config is set.
		// Ancestor-aware: works through intermediate steps (Brain → Audit → …).
		$parent_topic = self::resolve_from_context( $context, 'selected_topic', 'ai_brain' );
		if ( empty( $config['topic'] ) && '' !== $parent_topic ) {
			$config['topic'] = $parent_topic;
		}

		// Keywords: use AI Brain's suggestions when step has none.
		if ( empty( $config['keywords'] ) ) {
			$parent_keywords = self::resolve_from_context( $context, 'keywords', 'ai_brain' );
			if ( '' !== $parent_keywords ) {
				$config['keywords'] = $parent_keywords;
			}
		}

		// Brief: inject the full upstream strategist output as system instructions
		// for richer generation (AI Brain brief or a Custom Prompt relay).
		$ai_brain_brief = self::resolve_from_context( $context, 'full_output', 'ai_brain' );
		if ( '' === $ai_brain_brief ) {
			$ai_brain_brief = self::resolve_from_context( $context, 'full_output', 'custom_prompt' );
		}

		// Topic rotation (Pro): a topics list overrides the single topic —
		// each run picks a different one so scheduled posts stay varied.
		$topic = self::rotated_topic( $config, $context );
		if ( '' === $topic ) {
			return self::fail( __( 'No topic provided for blog post generation.', 'ai-marketing-expert' ) );
		}

		// Keywords: tokens field sends an array; legacy configs stored a comma string.
		$raw_keywords = $config['keywords'] ?? '';
		$keywords     = is_array( $raw_keywords )
			? array_values( array_filter( array_map( 'trim', array_map( 'strval', $raw_keywords ) ) ) )
			: array_values( array_filter( array_map( 'trim', explode( ',', (string) $raw_keywords ) ) ) );
		$tone     = self::tone( $context );
		// Length is a range: word_count is the floor, word_count_max the ceiling.
		// Legacy configs stored only word_count — those keep a single target
		// (ceiling 0 = no explicit upper bound).
		$words     = max( 300, (int) ( $config['word_count'] ?? 1000 ) );
		$words_max = (int) ( $config['word_count_max'] ?? 0 );
		if ( $words_max > 0 && $words_max < $words ) {
			// Swap an inverted range rather than silently ignoring it.
			list( $words, $words_max ) = array( $words_max, $words );
			$words                     = max( 300, $words );
		}
		$language = sanitize_text_field( (string) ( $config['language'] ?? 'en' ) );
		$language = '' !== $language ? $language : 'en';

		// Free tier: cap word count using the shared content limit.
		if ( ! aime_has_pro() ) {
			$limits = aime_free_limits();
			$cap    = (int) ( $limits['content_max_words'] ?? 2000 );
			$words  = min( $words, $cap );
			if ( $words_max > 0 ) {
				$words_max = min( $words_max, $cap );
			}
		}
		if ( $words_max > 0 && $words_max <= $words ) {
			$words_max = 0; // Ceiling collapsed into the floor after capping.
		}

		// Universal SEO contract: prefer Brain focus package, fall back to keywords.
		// All values sanitized here; the contract builder expects clean strings.
		$focus_keyword    = sanitize_text_field( self::resolve_from_context( $context, 'focus_keyword', 'ai_brain' ) );
		$brain_seo_title  = sanitize_text_field( self::resolve_from_context( $context, 'seo_title', 'ai_brain' ) );
		$brain_meta_desc  = sanitize_textarea_field( self::resolve_from_context( $context, 'meta_description', 'ai_brain' ) );
		$brain_slug       = sanitize_title( self::resolve_from_context( $context, 'slug', 'ai_brain' ) );
		$brain_img_queries = sanitize_text_field( self::resolve_from_context( $context, 'image_queries', 'ai_brain' ) );
		if ( '' === $focus_keyword && ! empty( $keywords ) ) {
			$focus_keyword = sanitize_text_field( (string) $keywords[0] );
		}
		if ( '' !== $focus_keyword && ! in_array( $focus_keyword, $keywords, true ) ) {
			array_unshift( $keywords, $focus_keyword );
			$keywords = array_values( array_unique( $keywords ) );
		}

		// Brand voice (Pro): resolve the workflow's voice into system instructions,
		// mirroring GenerateController's preset pattern.
		$preset    = null;
		$voice_prompt = self::brand_voice_system_prompt( $context );
		if ( '' !== $voice_prompt ) {
			$preset = (object) array(
				'prompt_template'     => '',
				'system_instructions' => $voice_prompt,
			);
		}

		// Inject AI Brain brief into system instructions (same path as brand voice).
		if ( '' !== $ai_brain_brief ) {
			$brief_instruction = "Content brief from AI strategist:\n\n" . $ai_brain_brief;
			if ( null === $preset ) {
				$preset = (object) array(
					'prompt_template'     => '',
					'system_instructions' => $brief_instruction,
				);
			} else {
				$preset->system_instructions = ( $preset->system_instructions ?? '' ) . "\n\n" . $brief_instruction;
			}
		}

		// Step-level writing brief: extra structure/angle instructions that
		// apply on top of (or without) the AI Brain brief.
		$writing_brief = sanitize_textarea_field( (string) ( $config['writing_brief'] ?? '' ) );
		if ( '' !== $writing_brief ) {
			$brief_instruction = "Additional writer instructions for this step:\n\n" . $writing_brief;
			if ( null === $preset ) {
				$preset = (object) array(
					'prompt_template'     => '',
					'system_instructions' => $brief_instruction,
				);
			} else {
				$preset->system_instructions = ( $preset->system_instructions ?? '' ) . "\n\n" . $brief_instruction;
			}
		}

		// Universal SEO contract: hard requirements appended last so they win.
		$seo_contract = ContentGeneratorService::build_seo_instructions( $focus_keyword, $brain_seo_title, $brain_meta_desc, $brain_slug );
		if ( '' !== $seo_contract ) {
			if ( null === $preset ) {
				$preset = (object) array(
					'prompt_template'     => '',
					'system_instructions' => $seo_contract,
				);
			} else {
				$preset->system_instructions = ( $preset->system_instructions ?? '' ) . "\n\n" . $seo_contract;
			}
		}

		$service = new ContentGeneratorService();

		// In-body stock images: only ask the AI for placeholders when the
		// stock provider is actually configured (fail-soft otherwise).
		$inline_images = min( 3, absint( $config['inline_images'] ?? 0 ) );
		$stock_class   = '\\WPSpace\\AiMarketingExpert\\Modules\\ContentGenerator\\Services\\StockImageService';
		if ( $inline_images > 0 && ( ! class_exists( $stock_class ) || ! $stock_class::is_configured() ) ) {
			$inline_images = 0;
		}

		$result = $service->generate_article( $topic, $keywords, $tone, $words, $language, '', $preset, false, $inline_images, $words_max );

		if ( empty( $result['success'] ) ) {
			return self::fail( $result['error'] ?? __( 'Article generation failed.', 'ai-marketing-expert' ) );
		}

		$parsed  = $result['parsed'] ?? array();
		$title   = sanitize_text_field( (string) ( $parsed['title'] ?? $topic ) );
		$body    = (string) ( $parsed['body'] ?? ( $result['content'] ?? '' ) );
		$excerpt = sanitize_textarea_field( (string) ( $parsed['excerpt'] ?? '' ) );

		if ( ! empty( $result['continued'] ) ) {
			$chain = array_map(
				static fn ( $p ) => sanitize_text_field( ( $p['provider'] ?? '?' ) . '/' . ( $p['model'] ?? '?' ) ),
				(array) ( $result['providers'] ?? array() )
			);
			aime_log( sprintf(
				'Workflow blog post: article stitched across %s%s.',
				implode( ' → ', $chain ),
				! empty( $result['truncated'] ) ? ' (still incomplete)' : ''
			), 'info', 'workflow-automation' );
		}

		// Enforce universal contract post-generation: TOC, image alt with
		// focus keyword, internal/external link minimums. Fail-soft by design.
		$body = self::enforce_seo_contract( $body, $focus_keyword, $title );

		// Single-H1 guard: drop a leading duplicate title heading if the
		// model emitted one despite the prompt (theme renders H1 already).
		$body = ContentGeneratorService::strip_duplicate_title_heading( (string) $body, (string) $title );

		// Swap AI image placeholders for stock photos (fail-soft; also strips
		// leftover placeholders when the feature is off or unconfigured).
		if ( class_exists( $stock_class ) ) {
			try {
				// Prefer Brain image queries for variety: first distinct query
				// becomes the fallback instead of the bare topic.
				$fallback_query = $topic;
				if ( '' !== $brain_img_queries ) {
					$queries = array_values( array_filter( array_map( 'trim', explode( '|', $brain_img_queries ) ) ) );
					if ( ! empty( $queries ) ) {
						$fallback_query = sanitize_text_field( $queries[0] );
					}
				}
				$body = ( new $stock_class() )->embed_inline_images( (string) $body, $inline_images, $fallback_query );
			} catch ( \Throwable $e ) {
				aime_log( 'Workflow blog post: inline images failed: ' . $e->getMessage(), 'warning', 'workflow-automation' );
			}
		}

		// Re-apply alt hardening after stock embed (imported figures need keyword alt).
		if ( '' !== $focus_keyword ) {
			$body = self::harden_image_alts( $body, $focus_keyword, $title );
		}

		// Categories: multi-select (category_ids) with legacy single-select
		// fallback. Every id is validated; empty selection = site default.
		$category_ids = array();
		if ( isset( $config['category_ids'] ) && is_array( $config['category_ids'] ) ) {
			$category_ids = array_values( array_filter( array_map( 'intval', $config['category_ids'] ) ) );
		} elseif ( ! empty( $config['category_id'] ) ) {
			$category_ids = array( (int) $config['category_id'] );
		}
		$category_ids = array_values( array_filter( $category_ids, static function ( $id ): bool {
			return $id > 0 && false !== term_exists( $id, 'category' );
		} ) );
		if ( empty( $category_ids ) ) {
			$default_cat = (int) get_option( 'default_category', 0 );
			if ( $default_cat > 0 ) {
				$category_ids = array( $default_cat );
			}
		}

		// Tags: fixed step tags + AI-suggested tags (both are name strings).
		$fixed_tags = $config['tags'] ?? array();
		$fixed_tags = is_array( $fixed_tags )
			? $fixed_tags
			: array_filter( array_map( 'trim', explode( ',', (string) $fixed_tags ) ) );
		$ai_tags    = array();
		if ( ! empty( $config['auto_tags'] ) || ! isset( $config['auto_tags'] ) ) {
			$ai_tags = is_array( $parsed['tags'] ?? null ) ? $parsed['tags'] : array();
		}
		$tags = array();
		foreach ( array_merge( $fixed_tags, $ai_tags ) as $tag ) {
			$tag = sanitize_text_field( (string) $tag );
			if ( '' !== $tag && ! in_array( $tag, $tags, true ) ) {
				$tags[] = $tag;
			}
		}
		$tags = array_slice( $tags, 0, 10 );

		// Author: step config, else the workflow creator (cron runs have no
		// logged-in user, which would otherwise leave the post authorless).
		$author_id = (int) ( $config['author_id'] ?? 0 );
		if ( $author_id <= 0 ) {
			$author_id = (int) ( $context['created_by'] ?? 0 );
		}
		if ( $author_id > 0 && ! get_userdata( $author_id ) ) {
			$author_id = 0;
		}

		// Featured image (fail-soft: image trouble never fails the post).
		$featured = self::resolve_featured_image( $config, $parsed, $topic, (string) $title, (string) $body );

		// Auto meta: workflow path never called generate_meta before, leaving
		// title/desc empty for every SEO plugin. Fail-soft, one extra AI call.
		$meta_title = $brain_seo_title;
		$meta_desc  = $brain_meta_desc;
		if ( '' === $meta_title || '' === $meta_desc ) {
			try {
				$meta_result = $service->generate_meta( (string) $title, (string) $body, array_values( $keywords ) );
				if ( ! empty( $meta_result['success'] ) && is_array( $meta_result['meta'] ?? null ) ) {
					if ( '' === $meta_title ) {
						$meta_title = sanitize_text_field( (string) ( $meta_result['meta']['meta_title'] ?? '' ) );
					}
					if ( '' === $meta_desc ) {
						$meta_desc = sanitize_textarea_field( (string) ( $meta_result['meta']['meta_description'] ?? '' ) );
					}
				}
			} catch ( \Throwable $e ) {
				aime_log( 'Workflow blog post: auto meta failed: ' . $e->getMessage(), 'warning', 'workflow-automation' );
			}
		}
		// Clamp to SEO limits: title 60, description 160.
		if ( mb_strlen( $meta_title ) > 60 ) {
			$meta_title = mb_substr( $meta_title, 0, 57 ) . '...';
		}
		if ( mb_strlen( $meta_desc ) > 160 ) {
			$meta_desc = mb_substr( $meta_desc, 0, 157 ) . '...';
		}
		if ( '' === $excerpt && '' !== $meta_desc ) {
			$excerpt = mb_substr( $meta_desc, 0, 160 );
		}

		// Slug: prefer Brain short slug (keyword in URL, <75 chars). Fall back
		// to title but truncated to 8 words so RankMath URL-length passes.
		$slug = $brain_slug;
		if ( '' === $slug ) {
			$slug = sanitize_title( (string) $title );
			$words_slug = explode( '-', $slug );
			if ( count( $words_slug ) > 8 ) {
				$slug = implode( '-', array_slice( $words_slug, 0, 8 ) );
			}
		}
		if ( mb_strlen( $slug ) > 75 ) {
			$slug = mb_substr( $slug, 0, 75 );
			$slug = rtrim( $slug, '-' );
		}

		// Persist the article row via the shared helper (mirrors GenerateController's insert shape).
		$article_fields = array(
			'title'             => sanitize_text_field( $title ),
			'slug'              => $slug,
			'content'           => wp_kses_post( $body ),
			'excerpt'           => sanitize_textarea_field( $excerpt ),
			'status'            => 'ready',
			'topic'             => sanitize_text_field( $topic ),
			'keywords'          => wp_json_encode( array_values( $keywords ) ),
			'tone'              => sanitize_text_field( $tone ),
			'language'          => sanitize_text_field( $language ),
			'word_count_target' => $words,
			'actual_word_count' => str_word_count( wp_strip_all_tags( $body ) ),
			'outline'           => wp_json_encode( $result['parsed']['outline'] ?? array() ),
			'category_ids'      => wp_json_encode( $category_ids ),
			'tag_ids'           => wp_json_encode( $tags ),
			'meta_title'        => $meta_title,
			'meta_description'  => $meta_desc,
		);
		if ( ! empty( $featured['attachment_id'] ) ) {
			$article_fields['featured_image_id'] = (int) $featured['attachment_id'];
		}
		if ( ! empty( $featured['url'] ) ) {
			$article_fields['featured_image_url'] = (string) $featured['url'];
		}
		$article_id = self::save_article( $article_fields, $result );
		if ( ! $article_id ) {
			return self::fail( __( 'Article generated but could not be saved.', 'ai-marketing-expert' ) );
		}

		$reference = array(
			'article_id'       => $article_id,
			'link'             => self::module_link( 'content', 'articles' ),
			'focus_keyword'    => $focus_keyword,
			'seo_title'        => $meta_title,
			'meta_description' => $meta_desc,
			'slug'             => $slug,
		);
		$preview   = sprintf( /* translators: %s: article title */ __( 'Generated article: %s', 'ai-marketing-expert' ), $title );

		// Publish per config: draft (default) or publish (immediate).
		$post_status = sanitize_key( (string) ( $config['post_status'] ?? 'draft' ) );
		if ( ! in_array( $post_status, array( 'draft', 'publish' ), true ) ) {
			$post_status = 'draft';
		}
		$publisher   = new PublisherService();
		$pub         = $publisher->publish( $article_id, $post_status, '', $author_id );
		if ( ! empty( $pub['success'] ) ) {
			$reference['wp_post_id'] = (int) $pub['wp_post_id'];
			$reference['edit_url']   = $pub['edit_url'] ?? '';
			if ( ! empty( $pub['edit_url'] ) ) {
				$reference['link'] = (string) $pub['edit_url'];
			}
			if ( 'publish' === $post_status ) {
				$preview = sprintf( /* translators: %s: article title */ __( 'Published article: %s', 'ai-marketing-expert' ), $title );
			} else {
				$preview = sprintf( /* translators: %s: article title */ __( 'Draft saved: %s', 'ai-marketing-expert' ), $title );
			}
		} else {
			// Non-fatal: article still exists in the content module.
			$preview .= ' ' . __( '(could not create WordPress post; article saved in Content module)', 'ai-marketing-expert' );
		}

		return self::ok( $preview, $reference );
	}

	/**
	 * Enforce minimum SEO structure when the model omits it.
	 *
	 * Adds a TOC nav for long articles, ensures at least one list exists
	 * implicitly (no forced markup), and appends internal/external hint
	 * paragraphs only when zero links exist. Never throws.
	 */
	private static function enforce_seo_contract( string $body, string $focus_keyword, string $title ): string {
		if ( '' === trim( $body ) ) {
			return $body;
		}
		// TOC for long articles missing one.
		$words = str_word_count( wp_strip_all_tags( $body ) );
		if ( $words >= 800 && false === stripos( $body, 'aime-article-toc' ) ) {
			if ( preg_match_all( '/<h2\b[^>]*>(.*?)<\/h2>/is', $body, $m ) && count( $m[1] ) >= 2 ) {
				$items = array();
				foreach ( array_slice( $m[1], 0, 6 ) as $h ) {
					$text = trim( wp_strip_all_tags( (string) $h ) );
					if ( '' === $text ) {
						continue;
					}
					$id     = sanitize_title( $text );
					$items[] = '<li><a href="#' . esc_attr( $id ) . '">' . esc_html( $text ) . '</a></li>';
				}
				if ( $items ) {
					$toc  = '<nav class="aime-article-toc"><h2>' . esc_html__( 'Table of Contents', 'ai-marketing-expert' ) . '</h2><ul>' . implode( '', $items ) . '</ul></nav>';
					$body = preg_replace( '/(<h2\b[^>]*>)/i', $toc . '$1', $body, 1 ) ?? $body;
				}
			}
		}
		// Link minimums: append resource box only when body has zero links.
		if ( ! preg_match( '/<a\s[^>]*href=/i', $body ) ) {
			$home = esc_url( home_url( '/' ) );
			$body .= '<h2>' . esc_html__( 'Further reading', 'ai-marketing-expert' ) . '</h2><ul><li><a href="' . $home . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a></li></ul>';
		}
		return $body;
	}

	/**
	 * Ensure every inline <img> carries an alt containing the focus keyword.
	 * Preserves existing meaningful alts, appends keyword when missing.
	 */
	private static function harden_image_alts( string $body, string $focus_keyword, string $title ): string {
		$focus_keyword = trim( $focus_keyword );
		if ( '' === $focus_keyword || false === stripos( $body, '<img' ) ) {
			return $body;
		}
		return (string) preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( array $matches ) use ( $focus_keyword, $title ): string {
				$img = $matches[0];
				if ( preg_match( '/alt\s*=\s*"([^"]*)"/i', $img, $am ) ) {
					$alt = trim( (string) $am[1] );
					if ( '' !== $alt && false !== stripos( $alt, $focus_keyword ) ) {
						return $img;
					}
					$new_alt = '' !== $alt ? $alt . ' - ' . $focus_keyword : $focus_keyword . ' - ' . $title;
					$new_alt = esc_attr( mb_substr( $new_alt, 0, 125 ) );
					return (string) preg_replace( '/alt\s*=\s*"[^"]*"/i', 'alt="' . $new_alt . '"', $img, 1 );
				}
				$new_alt = esc_attr( mb_substr( $focus_keyword . ' - ' . $title, 0, 125 ) );
				return str_replace( '<img', '<img alt="' . $new_alt . '"', $img );
			},
			$body
		);
	}

	/**
	 * Pick a featured image per step config ('none' | 'stock' | 'ai').
	 *
	 * Fail-soft by design: any API/key/network problem logs a warning and
	 * returns an empty result — the article itself must never be lost over
	 * an image.
	 *
	 * @return array{attachment_id:int, url:string}
	 */
	private static function resolve_featured_image( array $config, array $parsed, string $topic, string $title, string $body ): array {
		$none = array( 'attachment_id' => 0, 'url' => '' );
		$mode = sanitize_key( (string) ( $config['featured_image'] ?? 'none' ) );

		if ( 'stock' === $mode ) {
			try {
				$service_class = '\\WPSpace\\AiMarketingExpert\\Modules\\ContentGenerator\\Services\\StockImageService';
				if ( ! class_exists( $service_class ) ) {
					return $none;
				}
				$service = new $service_class();
				if ( ! $service_class::is_configured() ) {
					aime_log( 'Workflow blog post: stock image requested but no API key configured (Content → Settings → Images).', 'warning', 'workflow-automation' );
					return $none;
				}

				// AI supplies a purpose-built search query; fall back to the topic.
				$query = sanitize_text_field( (string) ( $parsed['image_search'] ?? '' ) );
				if ( '' === $query ) {
					$query = $topic;
				}

				$image = $service->random( $query );
				if ( ! $image ) {
					aime_log( "Workflow blog post: no stock image found for query \"{$query}\".", 'warning', 'workflow-automation' );
					return $none;
				}

				$import = $service->import_to_media_library( $image['full'], '' !== $image['alt'] ? $image['alt'] : $title );
				if ( empty( $import['success'] ) ) {
					aime_log( 'Workflow blog post: stock image import failed: ' . ( $import['error'] ?? 'unknown' ), 'warning', 'workflow-automation' );
					return $none;
				}

				$service_class::record_use( $image, (int) $import['attachment_id'], $query );

				return array( 'attachment_id' => (int) $import['attachment_id'], 'url' => (string) $import['url'] );
			} catch ( \Throwable $e ) {
				aime_log( 'Workflow blog post: stock image failed: ' . $e->getMessage(), 'warning', 'workflow-automation' );
				return $none;
			}
		}

		if ( 'ai' === $mode ) {
			if ( ! aime_has_pro() ) {
				aime_log( 'Workflow blog post: AI featured image requires Pro; skipping.', 'warning', 'workflow-automation' );
				return $none;
			}
			try {
				// Mirror GenerateController's prompt builder.
				$stripped = mb_substr( wp_strip_all_tags( $body ), 0, 500 );
				$prompt   = "Create a professional, high-quality featured blog image for an article titled: \"{$title}\". ";
				if ( $stripped ) {
					$prompt .= "The article is about: {$stripped}. ";
				}
				$prompt .= 'The image should be visually striking, relevant to the topic, suitable for a blog header. '
						 . 'Style: professional, modern, clean composition. No text in the image.';

				$image = \WPSpace\AiMarketingExpert\AiProvider::generate_image( $prompt, $title, 0 );
				if ( empty( $image['success'] ) ) {
					aime_log( 'Workflow blog post: AI image generation failed: ' . ( $image['message'] ?? 'unknown' ), 'warning', 'workflow-automation' );
					return $none;
				}

				return array(
					'attachment_id' => (int) ( $image['attachment_id'] ?? 0 ),
					'url'           => (string) ( $image['image_url'] ?? '' ),
				);
			} catch ( \Throwable $e ) {
				aime_log( 'Workflow blog post: AI image generation failed: ' . $e->getMessage(), 'warning', 'workflow-automation' );
				return $none;
			}
		}

		return $none;
	}
}
