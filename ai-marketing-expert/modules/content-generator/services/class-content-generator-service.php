<?php
/**
 * Content Generator Service — AI prompt engineering for blog content.
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services;

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContentGeneratorService {

	use ParsesAiJson;

	/* ── GENERATE full article ───────────────────────── */

	/**
	 * Generate a full article.
	 *
	 * @param int $word_count     Minimum target length (the floor the article must reach).
	 * @param int $word_count_max Optional upper bound. 0 = no explicit ceiling
	 *                            (the model is simply asked not to pad past the floor).
	 */
	public function generate_article( string $topic, array $keywords, string $tone, int $word_count, string $language, string $outline = '', ?object $preset = null, bool $include_table_of_contents = false, int $inline_images = 0, int $word_count_max = 0 ): array {
		$system = $this->build_system_prompt( $tone, $language, $preset );

		$keywords_str = $keywords ? implode( ', ', $keywords ) : 'none specified';

		// A ceiling below the floor is meaningless — ignore it.
		$word_count_max = $word_count_max > $word_count ? $word_count_max : 0;
		$budget         = $word_count_max > 0 ? $word_count_max : $word_count;

		$length_line = $word_count_max > 0
			? "Target length: between {$word_count} and {$word_count_max} words — THIS IS CRITICAL. "
				. "The article MUST be at least {$word_count} words and MUST NOT exceed {$word_count_max} words. "
				. "Plan the section count and depth up front so the whole article, including its conclusion, fits inside that range.\n"
			: "Target word count: approximately {$word_count} words — THIS IS CRITICAL, the article MUST be at least {$word_count} words long. Write detailed, in-depth content for every section.\n";

		$prompt = "Write a comprehensive blog article about: \"{$topic}\"\n\n"
			. "Target keywords: {$keywords_str}\n"
			. $length_line
			. "Language: {$language}\n"
			. "Tone: {$tone}\n\n";

		if ( $outline ) {
			$prompt .= "Follow this outline structure:\n{$outline}\n\n";
		}

		if ( $preset && ! empty( $preset->prompt_template ) ) {
			$prompt .= "Additional instructions:\n{$preset->prompt_template}\n\n";
		}

		if ( $include_table_of_contents ) {
			$prompt .= "Table of contents requirements:\n"
				. "- Add a table of contents near the top of the body after the introduction and before the first h2 section.\n"
				. "- Use valid HTML: <nav class=\"aime-article-toc\"><h2>Table of Contents</h2><ul><li><a href=\"#section-id\">Section title</a></li></ul></nav>.\n"
				. "- Add matching id attributes to each main h2 section included in the TOC.\n"
				. "- Do not include the TOC inside JSON metadata; it must be inside the body HTML only.\n\n";
		}

		if ( $inline_images > 0 ) {
			$prompt .= "In-body image requirements:\n"
				. "- Insert exactly {$inline_images} image placeholders inside the body HTML at visually appropriate points (after a paragraph, before or after a major h2 section — never inside a heading, list, or the first paragraph).\n"
				. "- Placeholder format (HTML comment, exactly): <!--aime-img:2-4 word English stock photo search query-->\n"
				. "- Each query must be distinct, concrete, and visually relevant to the surrounding section (concrete nouns, no punctuation).\n"
				. "- Spread the placeholders evenly through the article. Do not put them in the JSON metadata keys.\n\n";
		}

		$prompt .= "Return a JSON object with these keys:\n"
			. "- \"title\": a compelling SEO-friendly title that includes the primary keyword\n"
			. "- \"body\": the full article in HTML format using h2, h3, p, ul, ol, li, strong, em tags\n"
			. "- \"excerpt\": a 1-2 sentence summary (max 160 characters)\n"
			. "- \"tags\": array of 3-5 short topical tag names (1-3 words each, lowercase, no # symbols)\n"
			. "- \"image_search\": a 2-4 word English stock-photo search query capturing the article's main visual theme (concrete nouns, no punctuation)\n"
			. "- \"outline\": array of {heading, level} objects representing the article structure (level should be a number: 2 or 3)\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary, no explanation before or after the JSON.\n"
			. "The body must be valid HTML.\n"
			. "KEYWORD REQUIREMENTS: The target keywords MUST appear naturally in the title, in headings (h2/h3), and throughout the body text. "
			. "Use the primary keyword in the first paragraph and aim for 0.5-2%% keyword density.\n"
			. "IMPORTANT: Write ALL content in full. Do NOT use placeholders like \"...\", \"[content]\", or ellipsis. Every section must contain complete, detailed text.";

		// Token budget is sized off the ceiling, not the floor, and uses ~3.5
		// tokens/word: the payload carries HTML tags plus a JSON envelope with
		// escaped quotes, all of which cost tokens the prose count ignores.
		// Under-budgeting here is what truncated articles mid-sentence.
		$max_tokens = min( 16384, max( 2048, (int) ( $budget * 3.5 ) + 512 ) );
		$result     = AiProvider::generate(
			$system . "\n\n" . $prompt,
			'text',
			$max_tokens,
			// The payload is a JSON envelope — stitching raw JSON across models is
			// fragile, so a cut-off article is recovered as HTML further below.
			array( 'continuation' => 'none' )
		);

		if ( ! $result['success'] ) {
			return array( 'success' => false, 'error' => $result['message'] ?? ( $result['content'] ?? __( 'AI generation failed.', 'ai-marketing-expert' ) ) );
		}

		$parsed = $this->parse_json_response( $result['content'] );

		// Strip any safety-classification lines (e.g. "User Safety: safe") that
		// some Gemini-family models append to the body or excerpt, and remove
		// any reasoning prefix that slipped in before the body HTML.
		if ( is_array( $parsed ) ) {
			if ( ! empty( $parsed['body'] ) ) {
				$parsed['body'] = self::strip_reasoning_before_html( (string) $parsed['body'] );
				$parsed['body'] = self::strip_safety_lines( $parsed['body'] );
			}
			if ( ! empty( $parsed['excerpt'] ) ) {
				$parsed['excerpt'] = self::strip_safety_lines( (string) $parsed['excerpt'] );
			}
		}

		// No usable body: either the model skipped the JSON wrapper, or the
		// No usable body: either the model skipped the JSON wrapper, or the
		// envelope was cut off mid-body so only the keys before it survived
		// parsing. Both cases still carry real HTML worth salvaging.
		$salvaged = false;
		if ( ! $parsed || empty( $parsed['body'] ) ) {
			$raw_html = self::salvage_body_from_raw( (string) $result['content'] );

			if ( '' !== $raw_html ) {
				$salvaged        = true;
				$parsed          = is_array( $parsed ) ? $parsed : array();
				$parsed['body']  = $raw_html;
				$parsed['title'] = ! empty( $parsed['title'] ) ? $parsed['title'] : $topic;
				if ( ! isset( $parsed['excerpt'] ) ) {
					$parsed['excerpt'] = '';
				}
				if ( ! isset( $parsed['outline'] ) ) {
					$parsed['outline'] = array();
				}
			} else {
				// No JSON and no HTML — treat as truncated/empty and let
				// continuation logic try other providers.
				aime_log( 'AI returned non-JSON, non-HTML response (possible thinking/reasoning output). Marking as truncated for continuation.', 'warning', 'content-generator' );
				$parsed = array(
					'body'    => '',
					'title'   => $topic,
					'excerpt' => '',
					'outline' => array(),
				);
				// Force continuation by marking as truncated.
				$result['truncated'] = true;
			}
		}

		// The first provider may have stopped mid-article (token budget or rate
		// limit). Keep what it wrote and let the next provider finish the job.
		$continuation = $this->complete_truncated_body(
			(string) ( $parsed['body'] ?? '' ),
			array(
				// A salvaged body that does not end on a closing tag was cut off
				// even when the provider forgot to say so.
				'truncated'     => ! empty( $result['truncated'] )
					|| ( $salvaged && ! preg_match( '/>\s*$/', (string) $parsed['body'] ) ),
				'connection_id' => (string) ( $result['connection_id'] ?? '' ),
				'topic'         => $topic,
				'keywords'      => $keywords,
				'tone'          => $tone,
				'language'      => $language,
				'word_count'     => $word_count,
				'word_count_max' => $word_count_max,
				'inline_images'  => $inline_images,
			)
		);

		$parsed['body'] = $continuation['body'];
		$providers      = array_merge( $result['providers'] ?? array(), $continuation['providers'] );

		if ( $continuation['continued'] > 0 ) {
			// Metadata lives in the truncated JSON tail, so it is usually missing
			// once an article had to be stitched — rebuild it cheaply.
			if ( empty( $parsed['title'] ) ) {
				$parsed['title'] = $topic;
			}
			if ( empty( $parsed['image_search'] ) ) {
				$parsed['image_search'] = $topic;
			}
			if ( empty( $parsed['outline'] ) ) {
				$parsed['outline'] = self::outline_from_html( $parsed['body'] );
			}
			if ( empty( $parsed['excerpt'] ) ) {
				$excerpt_result = $this->generate_excerpt( (string) $parsed['title'], $parsed['body'] );
				if ( ! empty( $excerpt_result['excerpt'] ) ) {
					$parsed['excerpt'] = $excerpt_result['excerpt'];
				}
			}
		}

		return array(
			'success'   => true,
			'content'   => $result['content'],
			'parsed'    => $parsed,
			'provider'  => $result['provider'] ?? '',
			'model'     => $result['model'] ?? '',
			'providers' => $providers,
			'continued' => $continuation['continued'],
			'truncated' => $continuation['truncated'],
		);
	}

	/**
	 * Finish an article body that a provider left incomplete.
	 *
	 * Each round hands the partial HTML to the next available AI connection
	 * (a different one when possible, the same one after its cooldown when the
	 * site has only one) and asks for the remainder as raw HTML — never JSON,
	 * so nothing depends on models agreeing about escaping.
	 *
	 * @param string $body Partial body HTML (may be empty).
	 * @param array  $ctx  Article context: truncated, connection_id, topic,
	 *                     keywords, tone, language, word_count, inline_images.
	 * @return array{body:string,providers:array,continued:int,truncated:bool}
	 */
	private function complete_truncated_body( string $body, array $ctx ): array {
		$providers = array();
		$continued = 0;
		$truncated = ! empty( $ctx['truncated'] );
		$target    = max( 1, (int) $ctx['word_count'] );
		$ceiling   = (int) ( $ctx['word_count_max'] ?? 0 );
		$ceiling   = $ceiling > $target ? $ceiling : 0;

		$word_total = '' === trim( $body ) ? 0 : str_word_count( wp_strip_all_tags( $body ) );

		// A body that is complete AND long enough needs no extra rounds. Weak
		// models often stop cleanly well under the floor, so being short is
		// itself a reason to continue — not only an explicit truncation flag.
		$too_short = $word_total > 0 && $word_total < (int) ( $target * 0.9 );

		if ( '' === trim( $body ) || ( ! $truncated && ! $too_short ) ) {
			return array(
				'body'      => '' !== trim( $body ) ? force_balance_tags( $body ) : $body,
				'providers' => $providers,
				'continued' => 0,
				'truncated' => $truncated,
			);
		}

		$used   = array_filter( array( (string) ( $ctx['connection_id'] ?? '' ) ) );
		$rounds = (int) apply_filters( 'aime_article_continuation_rounds', 3 );

		for ( $round = 1; $round <= $rounds; $round++ ) {
			$words     = str_word_count( wp_strip_all_tags( $body ) );
			$remaining = $target - $words;

			if ( $remaining <= (int) ( $target * 0.1 ) ) {
				break; // Close enough to the target to call it finished.
			}

			$missing_images = max( 0, (int) $ctx['inline_images'] - preg_match_all( '/<!--aime-img:/', $body ) );

			$instructions = "Write the remaining part of a blog article body in HTML.\n"
				. "Article topic: \"{$ctx['topic']}\"\n"
				. 'Target keywords: ' . ( $ctx['keywords'] ? implode( ', ', $ctx['keywords'] ) : 'none specified' ) . "\n"
				. "Tone: {$ctx['tone']}\n"
				. "Language: {$ctx['language']}\n";

			if ( $ceiling > 0 ) {
				$room          = $ceiling - $words;
				$instructions .= "Target total length: {$target}-{$ceiling} words. About {$words} words already exist, "
					. "so roughly {$remaining} words are still missing and at most {$room} more may be added.\n";
			} else {
				$instructions .= "Target total length: {$target} words. About {$words} words already exist, so roughly {$remaining} words are still missing.\n";
			}

			if ( $missing_images > 0 ) {
				$instructions .= "Insert exactly {$missing_images} more image placeholders, formatted exactly as "
					. "<!--aime-img:2-4 word English stock photo search query-->, at visually appropriate points "
					. "(never inside a heading or list).\n";
			}

			$instructions .= "Output ONLY additional body HTML using h2, h3, p, ul, ol, li, strong and em tags. "
				. "No JSON, no code fences, no <html> or <body> wrapper, no commentary. "
				. "Do not repeat or rewrite any part of the existing text, and do not restart the article. "
				. "End the article with a proper conclusion once the target length is reached.";

			$round_result = AiProvider::continue_text(
				$body,
				$instructions,
				min( 8192, max( 1024, (int) ( $remaining * 3.5 ) + 256 ) ),
				array(
					'task'                => 'text',
					'used_connection_ids' => $used,
					'format_hint'         => 'raw article body HTML only (h2, h3, p, ul, ol, li, strong, em) — never JSON',
				)
			);

			if ( empty( $round_result['success'] ) ) {
				aime_log( 'Article continuation stopped: ' . ( $round_result['message'] ?? 'unknown reason' ), 'warning', 'content-generator' );
				break;
			}

			$stitched = AiProvider::stitch_text( $body, (string) $round_result['content'] );
			$added    = str_word_count( wp_strip_all_tags( $stitched ) ) - $words;

			if ( $added < 10 ) {
				break; // The provider added nothing useful; stop burning quota.
			}

			$body        = $stitched;
			$used[]      = (string) $round_result['connection_id'];
			$providers[] = array(
				'provider' => $round_result['provider'],
				'model'    => $round_result['model'],
				'round'    => $round,
			);
			$continued = $round;
			$truncated = ! empty( $round_result['truncated'] );

			aime_log( sprintf(
				'Article continuation round %d written by %s / %s (+%d words).',
				$round,
				$round_result['provider'],
				$round_result['model'],
				$added
			), 'info', 'content-generator' );
		}

		return array(
			'body'      => force_balance_tags( $body ),
			'providers' => $providers,
			'continued' => $continued,
			'truncated' => $truncated,
		);
	}

	/**
	 * Pull article body HTML out of a raw AI response whose JSON envelope is
	 * unusable — either never emitted, or cut off mid-body so the closing
	 * quote and every following key are missing.
	 *
	 * @param string $raw Raw model output.
	 * @return string Body HTML, or '' when the response holds no HTML at all.
	 */
	public static function salvage_body_from_raw( string $raw ): string {
		if ( ! preg_match( '/<(?:p|h[1-6]|ul|ol|div|article|section)\b/i', $raw ) ) {
			return '';
		}

		$html = self::strip_reasoning_before_html( $raw );

		// The body was a JSON string value, so its HTML is still escaped.
		if ( false !== strpos( $html, '\\' ) ) {
			$html = str_replace( array( '\\n', '\\r', '\\t' ), array( "\n", "\r", "\t" ), $html );
			$html = stripcslashes( $html );
		}

		// Drop a dangling JSON tail when the envelope did close after the body.
		$html = (string) preg_replace(
			'/"\s*,\s*"(?:excerpt|outline|title|tags|image_search|meta_description)"\s*:[\s\S]*$/i',
			'',
			$html
		);
		$html = (string) preg_replace( '/"\s*\}?\s*$/', '', $html );

		return self::strip_safety_lines( trim( $html ) );
	}

	/**
	 * Rebuild an article outline from its headings, used when the JSON tail
	 * carrying the original outline was lost to truncation.
	 *
	 * @param string $html Article body HTML.
	 * @return array List of { heading, level }.
	 */
	public static function outline_from_html( string $html ): array {
		if ( ! preg_match_all( '/<h([23])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$outline = array();
		foreach ( $matches as $match ) {
			$heading = trim( wp_strip_all_tags( $match[2] ) );
			if ( '' !== $heading ) {
				$outline[] = array(
					'heading' => $heading,
					'level'   => (int) $match[1],
				);
			}
		}

		return $outline;
	}

	/* ── GENERATE outline ────────────────────────────── */

	public function generate_outline( string $topic, array $keywords, string $tone, string $style ): array {
		$keywords_str = $keywords ? implode( ', ', $keywords ) : 'none specified';

		$prompt = "You are a content strategist. Create a detailed article outline for the following topic.\n\n"
			. "Topic: \"{$topic}\"\n"
			. "Style: {$style}\n"
			. "Tone: {$tone}\n"
			. "Target keywords: {$keywords_str}\n\n"
			. "Return a JSON object with:\n"
			. "- \"title\": suggested article title\n"
			. "- \"sections\": array of objects with {heading, level (2 or 3), description, estimated_words}\n"
			. "- \"total_estimated_words\": total estimated word count\n"
			. "- \"key_points\": array of 3-5 key points the article should cover\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 2048 );

		if ( ! $result['success'] ) {
			return array( 'success' => false, 'error' => $result['content'] ?? '' );
		}

		$parsed = $this->parse_json_response( $result['content'] );

		return array(
			'success' => true,
			'outline' => $parsed ?: array( 'raw' => $result['content'] ),
		);
	}

	/* ── GENERATE single section ─────────────────────── */

	public function generate_section( string $topic, string $section_title, string $context, string $tone ): array {
		$prompt = "You are an expert content writer. Write a detailed section for a blog article.\n\n"
			. "Article topic: \"{$topic}\"\n"
			. "Section heading: \"{$section_title}\"\n"
			. "Tone: {$tone}\n";

		if ( $context ) {
			$prompt .= "Context (surrounding content):\n{$context}\n\n";
		}

		$prompt .= "Write 2-4 paragraphs for this section. Use HTML tags (p, ul, ol, li, strong, em). "
			. "Do NOT include the section heading itself — just the body content. "
			. "Return ONLY the HTML content, no JSON wrapper. Do NOT include any thinking, reasoning, planning, or commentary, "
			. "and do NOT append any safety-classification lines (e.g. \"User Safety: safe\").";

		$result = AiProvider::generate( $prompt, 'text', 2048 );

		if ( ! $result['success'] ) {
			return array( 'success' => false, 'error' => $result['content'] ?? '' );
		}

		return array(
			'success' => true,
			'content' => aime_strip_thinking_from_html( $result['content'] ),
		);
	}

	/* ── IMPROVE content ─────────────────────────────── */

	public function improve_content( string $content, string $instruction, string $tone ): array {
		// Swap media (images, figures, embeds) for placeholders so the AI cannot
		// rewrite or drop them while rephrasing the surrounding prose.
		$media     = array();
		$protected = self::protect_media( $content, $media );

		$word_count = str_word_count( wp_strip_all_tags( $protected ) );
		$max_tokens = min( 16384, max( 2048, (int) ( $word_count * 2.5 ) ) );

		$prompt = "You are a professional editor. Improve the following content based on this instruction.\n\n"
			. "Instruction: {$instruction}\n"
			. "Tone: {$tone}\n\n"
			. "Original content:\n{$protected}\n\n"
			. "IMPORTANT: The original content has approximately {$word_count} words. "
			. "Your output MUST have approximately the same word count. Do NOT shorten, truncate, or remove any sections. "
			. "Return the improved content in HTML format using p, h2, h3, ul, ol, li, strong, em tags. ";

		if ( $media ) {
			$prompt .= "The content contains placeholder markers such as [[AIME_MEDIA_0]]. "
				. "These represent images and embeds. You MUST copy every placeholder into your output "
				. "exactly as written, character for character, keeping them in the same order and at the "
				. "same position relative to the surrounding paragraphs. Never delete, rename, translate, "
				. "or wrap them in other tags. ";
		}

		$prompt .= "Return ONLY the improved HTML content. Do NOT include any thinking, reasoning, planning, or commentary — "
			. "do NOT prefix with phrases like \"Sure, here is...\", \"Here is the improved HTML:\", or any safety classification lines.";

		$result = AiProvider::generate( $prompt, 'text', $max_tokens );

		if ( ! $result['success'] ) {
			return array( 'success' => false, 'error' => $result['content'] ?? '' );
		}

		$improved = aime_strip_thinking_from_html( $result['content'] );

		return array(
			'success' => true,
			'content' => self::restore_media( $improved, $media ),
		);
	}

	/**
	 * Replace media nodes with opaque placeholders.
	 *
	 * @param string $content Source HTML.
	 * @param array  $media   Filled with token => original HTML.
	 * @return string HTML with media replaced by tokens.
	 */
	private static function protect_media( string $content, array &$media ): string {
		$media   = array();
		$pattern = '/<figure\b[^>]*>.*?<\/figure>'
			. '|<picture\b[^>]*>.*?<\/picture>'
			. '|<iframe\b[^>]*>.*?<\/iframe>'
			. '|<video\b[^>]*>.*?<\/video>'
			. '|<audio\b[^>]*>.*?<\/audio>'
			. '|<img\b[^>]*\/?>/is';

		$replaced = preg_replace_callback(
			$pattern,
			function ( $m ) use ( &$media ) {
				$token           = '[[AIME_MEDIA_' . count( $media ) . ']]';
				$media[ $token ] = $m[0];
				return $token;
			},
			$content
		);

		// preg_replace_callback returns null on failure (e.g. backtrack limit) —
		// fall back to the untouched content rather than losing the article.
		if ( null === $replaced ) {
			$media = array();
			return $content;
		}

		return $replaced;
	}

	/**
	 * Put protected media back into AI output.
	 *
	 * Any placeholder the model dropped is re-appended at the end so images
	 * are never silently lost.
	 *
	 * @param string $content AI output containing tokens.
	 * @param array  $media   Token => original HTML map.
	 */
	private static function restore_media( string $content, array $media ): string {
		if ( ! $media ) {
			return $content;
		}

		$missing = array();

		foreach ( $media as $token => $html ) {
			// Tolerate the model wrapping a placeholder in its own paragraph.
			$quoted  = preg_quote( $token, '/' );
			$count   = 0;
			$content = preg_replace(
				'/<p>\s*' . $quoted . '\s*<\/p>|' . $quoted . '/',
				str_replace( '$', '\$', $html ),
				$content,
				-1,
				$count
			);

			if ( ! $count ) {
				$missing[] = $html;
			}
		}

		if ( $missing ) {
			$content .= "\n" . implode( "\n", $missing );
		}

		return $content;
	}

	/* ── GENERATE meta title & description ───────────── */

	public function generate_meta( string $title, string $content, array $keywords ): array {
		$stripped     = mb_substr( wp_strip_all_tags( $content ), 0, 2000 );
		$keywords_str = $keywords ? implode( ', ', $keywords ) : 'none';

		$prompt = "You are an SEO expert. Generate optimized meta title and meta description for this article.\n\n"
			. "Article title: \"{$title}\"\n"
			. "Target keywords: {$keywords_str}\n"
			. "Article excerpt: {$stripped}\n\n"
			. "Return a JSON object:\n"
			. "- \"meta_title\": SEO-optimized title (max 60 characters, include primary keyword)\n"
			. "- \"meta_description\": compelling meta description (max 160 characters, include keywords naturally)\n"
			. "- \"title_alternatives\": array of 3 alternative title suggestions\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 1024 );

		if ( ! $result['success'] ) {
			return array( 'success' => false, 'error' => $result['content'] ?? '' );
		}

		$parsed = $this->parse_json_response( $result['content'] );

		// If parsing failed, try regex extraction for meta fields.
		if ( ! $parsed || ( empty( $parsed['meta_title'] ) && empty( $parsed['meta_description'] ) ) ) {
			$fallback = array();
			if ( preg_match( '/"meta_title"\s*:\s*"((?:[^"\\\\]|\\\\.)*?)"/s', $result['content'], $m ) ) {
				$fallback['meta_title'] = stripcslashes( $m[1] );
			}
			if ( preg_match( '/"meta_description"\s*:\s*"((?:[^"\\\\]|\\\\.)*?)"/s', $result['content'], $m ) ) {
				$fallback['meta_description'] = stripcslashes( $m[1] );
			}
			if ( ! empty( $fallback ) ) {
				$parsed = array_merge( $parsed ?: array(), $fallback );
			}
		}

		return array(
			'success' => true,
			'meta'    => $parsed ?: array( 'raw' => $result['content'] ),
		);
	}

	/* ── GENERATE excerpt ────────────────────────────── */

	public function generate_excerpt( string $title, string $content ): array {
		$stripped = mb_substr( wp_strip_all_tags( $content ), 0, 2000 );

		$prompt = "You are a professional content writer. Generate a concise, compelling excerpt for the following article.\n\n"
			. "Article title: \"{$title}\"\n"
			. "Article content:\n{$stripped}\n\n"
			. "Return a JSON object with:\n"
			. "- \"excerpt\": a 1-2 sentence summary (max 160 characters) that captures the main point and entices readers\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 512 );

		if ( ! $result['success'] ) {
			return array( 'success' => false, 'error' => $result['content'] ?? '' );
		}

		$parsed = $this->parse_json_response( $result['content'] );

		$excerpt_text = '';
		if ( is_array( $parsed ) && ! empty( $parsed['excerpt'] ) ) {
			$excerpt_text = $parsed['excerpt'];
		} elseif ( is_string( $parsed ) && strlen( $parsed ) > 0 ) {
			$excerpt_text = $parsed;
		}

		// If parsing failed but the raw response looks like a plain sentence (not JSON/thinking), use it.
		if ( empty( $excerpt_text ) && ! empty( $result['content'] ) ) {
			$raw_clean = trim( $result['content'], " \t\n\r\0\x0B\"'" );
			if ( strlen( $raw_clean ) <= 300 && ! preg_match( '/[{}\[\]]/', $raw_clean ) ) {
				$excerpt_text = $raw_clean;
			}
		}

		// Final cleanup: strip any safety-classification lines (e.g. "User Safety: safe")
		// that some Gemini-family models append even to short responses, and trim.
		if ( ! empty( $excerpt_text ) ) {
			$excerpt_text = preg_replace( '/^[ \t]*(?:User\s+Safety|Safety|Harm\s+Category|Harm_Policy|Category|Severity)[ \t]*:[ \t]*[^\n\r]*[\n\r]?/im', '', $excerpt_text );
			$excerpt_text = preg_replace( '/(?:\n|\r)[ \t]*(?:User\s+Safety|Safety|Harm\s+Category|Harm_Policy|Category|Severity)[ \t]*:[ \t]*[^\n\r]*/i', '', $excerpt_text );
			$excerpt_text = trim( $excerpt_text );
		}

		return array(
			'success' => true,
			'excerpt' => $excerpt_text,
		);
	}

	/* ── GENERATE image prompt (PRO) ─────────────────── */

	public function generate_image_prompt( string $title, string $content ): array {
		$stripped = mb_substr( wp_strip_all_tags( $content ), 0, 1000 );

		$prompt = "You are a creative director specializing in blog featured images. "
			. "Generate detailed image prompts for AI image generators (DALL-E, Midjourney, etc.).\n\n"
			. "Article title: \"{$title}\"\n"
			. "Article summary: {$stripped}\n\n"
			. "Return a JSON object:\n"
			. "- \"prompts\": array of 3 detailed image generation prompts (each 1-2 sentences, descriptive)\n"
			. "- \"style_suggestions\": array of 3 style keywords (e.g., 'photorealistic', 'illustration', 'minimalist')\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 1024 );

		if ( ! $result['success'] ) {
			return array( 'success' => false, 'error' => $result['content'] ?? '' );
		}

		$parsed = $this->parse_json_response( $result['content'] );

		return array(
			'success' => true,
			'data'    => $parsed ?: array( 'raw' => $result['content'] ),
		);
	}

	/* ── HELPERS ─────────────────────────────────────── */

	private function build_system_prompt( string $tone, string $language, ?object $preset = null ): string {
		$system = "You are an expert content writer and SEO specialist. "
			. "Write high-quality, original, engaging blog content. "
			. "Use proper HTML formatting with semantic heading tags (h2, h3), paragraphs, lists, and emphasis where appropriate. "
			. "Always write complete, detailed content — never use placeholders, ellipsis (\"...\"), or abbreviated text. "
			. "IMPORTANT: Output ONLY the requested JSON or HTML. Do NOT include any thinking, reasoning, planning, commentary, or explanation. "
			. "Tone: {$tone}. Language: {$language}.";

		if ( $preset && ! empty( $preset->system_instructions ) ) {
			$system .= "\n\nAdditional instructions from preset:\n" . $preset->system_instructions;
		}

		return $system;
	}

	/**
	 * Strip AI safety-classification lines that some Gemini-family models
	 * append to the response (e.g. "User Safety: safe", "Category: ...").
	 * These are not legitimate user content and should never be saved into
	 * the post body or excerpt.
	 *
	 * @param string $text Raw AI-generated text.
	 * @return string Cleaned text.
	 */
	public static function strip_safety_lines( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}

		// Match standalone classification lines at the start of a line, anywhere
		// in the body. Handles both Unix and Windows line endings.
		$text = preg_replace( '/^[ \t]*(?:User\s+Safety|Safety|Harm\s+Category|Harm_Policy|Category|Severity)[ \t]*:[ \t]*[^\n\r]*[\n\r]?/im', '', $text );
		$text = preg_replace( '/(?:\n|\r)[ \t]*(?:User\s+Safety|Safety|Harm\s+Category|Harm_Policy|Category|Severity)[ \t]*:[ \t]*[^\n\r]*/i', '', $text );

		return trim( $text );
	}

	/**
	 * Strip chain-of-thought / reasoning text that some models emit BEFORE
	 * the actual HTML body. Used when the model returns HTML directly
	 * (no JSON wrapper) so the long reasoning prefix never reaches the editor.
	 *
	 * Heuristic: if the first character is "<" we leave it alone. Otherwise
	 * we keep trimming leading reasoning prefixes (the standard
	 * aime_strip_thinking_text rules — <think> blocks, "Sure, …" preambles,
	 * analysis tags, etc.) until we reach a real HTML tag, or we return
	 * whatever the global helper produced if no tag is found.
	 *
	 * @param string $text Raw AI-generated HTML.
	 * @return string HTML with any reasoning prefix removed.
	 */
	public static function strip_reasoning_before_html( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}

		$text = trim( $text );
		if ( '' === $text ) {
			return $text;
		}

		// Already starts with an HTML tag — nothing to strip.
		if ( '<' === $text[0] ) {
			return $text;
		}

		// Apply the global thinking-strip helper first.
		$cleaned = aime_strip_thinking_text( $text );

		// If cleaning leaves us with text that now starts with "<", we're done.
		if ( '' !== $cleaned && '<' === $cleaned[0] ) {
			return $cleaned;
		}

		// Otherwise, walk forward: find the first <tag that looks like real
		// body content and slice from there. This catches reasoning prefixes
		// the helper didn't strip (e.g. multi-paragraph plans with no <think>).
		$pos = -1;
		if ( preg_match( '/<(?:p|h[1-6]|ul|ol|article|section|div|main|blockquote)\b/i', $cleaned, $matches, PREG_OFFSET_CAPTURE ) ) {
			$pos = (int) $matches[0][1];
		}

		if ( $pos > 0 ) {
			$cleaned = substr( $cleaned, $pos );
		}

		return $cleaned;
	}
}
