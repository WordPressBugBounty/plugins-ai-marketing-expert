<?php
/**
 * SEO Module — On-Page SEO Service.
 *
 * Analyses a WordPress post or URL for on-page SEO issues
 * and generates AI suggestions.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Services;

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OnPageSeoService {

	use ParsesAiJson;

	/**
	 * Run an on-page SEO audit.
	 */
	public function run_audit( int $wp_post_id, string $url, string $keyword_focus ): array {
		$post_data = $this->gather_post_data( $wp_post_id, $url );

		if ( ! $post_data ) {
			return array(
				'success' => false,
				'message' => __( 'Could not retrieve post/page content for auditing.', 'ai-marketing-expert' ),
			);
		}

		// Run technical checks first.
		$technical_results = $this->run_technical_checks( $post_data, $keyword_focus );

		// Then get AI suggestions.
		$ai_result = $this->get_ai_suggestions( $post_data, $keyword_focus, $technical_results );

		// Calculate overall score.
		$score = $this->calculate_score( $technical_results );

		// Sync score to post meta and active SEO plugins (Rank Math, Yoast, canonical).
		if ( $wp_post_id > 0 && class_exists( '\\WPSpace\\AiMarketingExpert\\Modules\\Seo\\Services\\SeoAdapterService' ) ) {
			\WPSpace\AiMarketingExpert\Modules\Seo\Services\SeoAdapterService::set_seo_score( $wp_post_id, $score );
		}

		// Count issues.
		$issues   = 0;
		$warnings = 0;
		$passed   = 0;
		foreach ( $technical_results as $check ) {
			$status = $check['status'] ?? 'pass';
			if ( $status === 'fail' ) {
				$issues++;
			} elseif ( $status === 'warning' ) {
				$warnings++;
			} else {
				$passed++;
			}
		}

		// Save audit.
		global $wpdb;
		$p = $wpdb->prefix;


		// Prevent duplicate audits for the same target within 60 seconds.
		if ( $wp_post_id ) {
			$recent_duplicate = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$p}aime_seo_audits WHERE wp_post_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT 1",
				$wp_post_id
			) );
		} else {
			$recent_duplicate = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$p}aime_seo_audits WHERE (wp_post_id IS NULL OR wp_post_id = 0) AND url = %s AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT 1",
				$post_data['url'] ?? $url
			) );
		}

		if ( $recent_duplicate ) {
			// Update the existing recent audit instead of creating a duplicate.
			$wpdb->update(
				"{$p}aime_seo_audits",
				array(
					'url'            => $post_data['url'] ?? $url,
					'title'          => $post_data['title'] ?? '',
					'overall_score'  => $score,
					'results'        => wp_json_encode( $technical_results ),
					'keyword_focus'  => $keyword_focus,
					'issues_count'   => $issues,
					'warnings_count' => $warnings,
					'passed_count'   => $passed,
					'ai_suggestions' => wp_json_encode( $ai_result ),
				),
				array( 'id' => $recent_duplicate )
			);
			$audit_id = (int) $recent_duplicate;
		} else {
			$wpdb->insert( "{$p}aime_seo_audits", array(
				'wp_post_id'     => $wp_post_id ?: null,
				'url'            => $post_data['url'] ?? $url,
				'title'          => $post_data['title'] ?? '',
				'overall_score'  => $score,
				'results'        => wp_json_encode( $technical_results ),
				'keyword_focus'  => $keyword_focus,
				'issues_count'   => $issues,
				'warnings_count' => $warnings,
				'passed_count'   => $passed,
				'ai_suggestions' => wp_json_encode( $ai_result ),
			) );
			$audit_id = $wpdb->insert_id;
		}

		return array(
			'success'        => true,
			'data'           => array(
				'id'              => $audit_id,
				'overall_score'   => $score,
				'keyword_focus'   => $keyword_focus,
				'issues_count'    => $issues,
				'warnings_count'  => $warnings,
				'passed_count'    => $passed,
				'results'         => $technical_results,
				'ai_suggestions'  => $ai_result,
			),
		);
	}

	/**
	 * Gather post data for analysis.
	 */
	private function gather_post_data( int $wp_post_id, string $url ): ?array {
		if ( $wp_post_id ) {
			$post = get_post( $wp_post_id );
			if ( ! $post ) {
				return null;
			}

			return array(
				'wp_post_id'       => $wp_post_id,
				'title'            => $post->post_title,
				'content'          => $post->post_content,
				'excerpt'          => $post->post_excerpt,
				'url'              => get_permalink( $wp_post_id ),
				'slug'             => $post->post_name,
				'meta_title'       => get_post_meta( $wp_post_id, '_yoast_wpseo_title', true )
				                      ?: get_post_meta( $wp_post_id, 'rank_math_title', true )
				                      ?: $post->post_title,
				'meta_description' => get_post_meta( $wp_post_id, '_yoast_wpseo_metadesc', true )
				                      ?: get_post_meta( $wp_post_id, 'rank_math_description', true )
				                      ?: $post->post_excerpt,
				'word_count'       => function_exists( 'aime_count_words' ) ? aime_count_words( (string) $post->post_content ) : str_word_count( wp_strip_all_tags( $post->post_content ) ),
			);
		}

		// URL-based audit — limited analysis.
		return array(
			'wp_post_id'       => 0,
			'title'            => '',
			'content'          => '',
			'excerpt'          => '',
			'url'              => $url,
			'slug'             => wp_parse_url( $url, PHP_URL_PATH ) ?? '',
			'meta_title'       => '',
			'meta_description' => '',
			'word_count'       => 0,
		);
	}

	/**
	 * Run technical SEO checks.
	 */
	private function run_technical_checks( array $data, string $keyword ): array {
		$results = array();

		// Title length check.
		$title_len = mb_strlen( $data['meta_title'] );
		$results[] = array(
			'check'   => 'title_length',
			'label'   => __( 'Title Tag Length', 'ai-marketing-expert' ),
			'status'  => ( $title_len >= 30 && $title_len <= 65 ) ? 'pass' : ( $title_len > 0 ? 'warning' : 'fail' ),
			/* translators: %d: meta title character count */
			'message' => sprintf( __( 'Title is %d characters (recommended: 30-65).', 'ai-marketing-expert' ), $title_len ),
			'value'   => $title_len,
		);

		// Meta description length.
		$desc_len = mb_strlen( $data['meta_description'] );
		$results[] = array(
			'check'   => 'meta_description_length',
			'label'   => __( 'Meta Description Length', 'ai-marketing-expert' ),
			'status'  => ( $desc_len >= 120 && $desc_len <= 160 ) ? 'pass' : ( $desc_len > 0 ? 'warning' : 'fail' ),
			/* translators: %d: meta description character count */
			'message' => sprintf( __( 'Meta description is %d characters (recommended: 120-160).', 'ai-marketing-expert' ), $desc_len ),
			'value'   => $desc_len,
		);

		// Word count.
		$wc = $data['word_count'];
		$results[] = array(
			'check'   => 'word_count',
			'label'   => __( 'Content Length', 'ai-marketing-expert' ),
			'status'  => $wc >= 1000 ? 'pass' : ( $wc >= 300 ? 'warning' : 'fail' ),
			/* translators: %d: content word count */
			'message' => sprintf( __( 'Content has %d words (recommended: 1000+).', 'ai-marketing-expert' ), $wc ),
			'value'   => $wc,
		);

		// Keyword in title.
		if ( $keyword ) {
			$kw_lower    = mb_strtolower( $keyword );
			$title_lower = mb_strtolower( $data['meta_title'] );
			$in_title    = false !== strpos( $title_lower, $kw_lower );
			$results[]   = array(
				'check'   => 'keyword_in_title',
				'label'   => __( 'Keyword in Title', 'ai-marketing-expert' ),
				'status'  => $in_title ? 'pass' : 'fail',
				'message' => $in_title
					? __( 'Focus keyword found in title.', 'ai-marketing-expert' )
					: __( 'Focus keyword not found in title.', 'ai-marketing-expert' ),
			);

			// Keyword near beginning of title (first 50%).
			$pos = mb_stripos( $data['meta_title'], $keyword );
			$in_first_50 = false !== $pos && ( $pos / max( 1, mb_strlen( $data['meta_title'] ) ) ) <= 0.5;
			$results[] = array(
				'check'   => 'keyword_near_beginning',
				'label'   => __( 'Keyword near Beginning of Title', 'ai-marketing-expert' ),
				'status'  => $in_first_50 ? 'pass' : 'info',
				'message' => $in_first_50
					? __( 'Focus keyword appears near the beginning of the title.', 'ai-marketing-expert' )
					: __( 'Optional: Placing the focus keyword closer to the start of the title can improve CTR.', 'ai-marketing-expert' ),
			);

			// Power words in title (soft check).
			$power_words = array( 'best', 'top', 'guide', 'ultimate', 'easy', 'fast', 'proven', 'review', 'complete', 'tips', 'secrets', 'simple', 'essential', 'strategy', 'powerful', 'checklist', 'step', 'how', 'free', 'new' );
			$found_power = false;
			foreach ( $power_words as $pw ) {
				if ( preg_match( '/\b' . preg_quote( $pw, '/' ) . '\b/i', $title_lower ) ) {
					$found_power = true;
					break;
				}
			}
			$results[] = array(
				'check'   => 'title_power_words',
				'label'   => __( 'Power Word in Title', 'ai-marketing-expert' ),
				'status'  => $found_power ? 'pass' : 'info',
				'message' => $found_power
					? __( 'Title contains an engaging power word.', 'ai-marketing-expert' )
					: __( 'Optional: An engaging power word (e.g. Best, Guide, Proven) can increase clicks.', 'ai-marketing-expert' ),
			);

			// Number in title (soft check).
			$has_num = (bool) preg_match( '/\d+/', $data['meta_title'] );
			$results[] = array(
				'check'   => 'title_has_number',
				'label'   => __( 'Number in Title', 'ai-marketing-expert' ),
				'status'  => $has_num ? 'pass' : 'info',
				'message' => $has_num
					? __( 'Title contains a number (numbers can boost CTR).', 'ai-marketing-expert' )
					: __( 'Optional: For listicles, guides, or updates, numbers can increase clicks.', 'ai-marketing-expert' ),
			);

			// Keyword in meta description.
			$desc_lower = mb_strtolower( $data['meta_description'] );
			$in_desc    = false !== strpos( $desc_lower, $kw_lower );
			$results[]  = array(
				'check'   => 'keyword_in_meta_desc',
				'label'   => __( 'Keyword in Meta Description', 'ai-marketing-expert' ),
				'status'  => $in_desc ? 'pass' : 'warning',
				'message' => $in_desc
					? __( 'Focus keyword found in meta description.', 'ai-marketing-expert' )
					: __( 'Focus keyword not found in meta description.', 'ai-marketing-expert' ),
			);

			// Keyword in URL slug.
			$slug_lower = mb_strtolower( $data['slug'] );
			$kw_slug    = sanitize_title( $keyword );
			$in_slug    = false !== strpos( $slug_lower, $kw_slug );
			$results[]  = array(
				'check'   => 'keyword_in_url',
				'label'   => __( 'Keyword in URL', 'ai-marketing-expert' ),
				'status'  => $in_slug ? 'pass' : 'warning',
				'message' => $in_slug
					? __( 'Focus keyword found in URL slug.', 'ai-marketing-expert' )
					: __( 'Focus keyword not found in URL slug.', 'ai-marketing-expert' ),
			);

			// Keyword density.
			if ( $wc > 0 ) {
				$content_lower = mb_strtolower( wp_strip_all_tags( $data['content'] ) );
				$kw_count      = mb_substr_count( $content_lower, $kw_lower );
				$kw_words      = function_exists( 'aime_count_words' ) ? max( 1, aime_count_words( $keyword ) ) : max( 1, str_word_count( $keyword ) );
				$density       = round( ( $kw_count * $kw_words / $wc ) * 100, 2 );
				$results[]     = array(
					'check'   => 'keyword_density',
					'label'   => __( 'Keyword Density', 'ai-marketing-expert' ),
					'status'  => ( $density >= 0.5 && $density <= 2.5 ) ? 'pass' : 'warning',
					/* translators: %.2f: keyword density percentage */
					'message' => sprintf( __( 'Keyword density is %.2f%% (recommended: 0.5-2.5%%).', 'ai-marketing-expert' ), $density ),
					'value'   => $density,
				);
			}

			// Keyword in first paragraph.
			$content_text = wp_strip_all_tags( $data['content'] );
			$first_para   = mb_substr( $content_text, 0, 300 );
			$in_first     = false !== strpos( mb_strtolower( $first_para ), $kw_lower );
			$results[]    = array(
				'check'   => 'keyword_in_intro',
				'label'   => __( 'Keyword in Introduction', 'ai-marketing-expert' ),
				'status'  => $in_first ? 'pass' : 'warning',
				'message' => $in_first
					? __( 'Focus keyword found in first paragraph.', 'ai-marketing-expert' )
					: __( 'Focus keyword not in first 300 characters.', 'ai-marketing-expert' ),
			);
		}

		// Heading check (H2/H3).
		preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', $data['content'], $headings );
		$heading_count = count( $headings[0] );
		$results[]     = array(
			'check'   => 'heading_structure',
			'label'   => __( 'Heading Structure', 'ai-marketing-expert' ),
			'status'  => $heading_count >= 2 ? 'pass' : ( $heading_count >= 1 ? 'warning' : 'fail' ),
			/* translators: %d: number of H2/H3 subheadings found */
			'message' => sprintf( __( 'Found %d subheadings (H2/H3). Use 2+ for better structure.', 'ai-marketing-expert' ), $heading_count ),
			'value'   => $heading_count,
		);

		// Keyword in subheadings (H2/H3).
		if ( $keyword ) {
			$kw_in_heading = false;
			foreach ( $headings[1] ?? array() as $htext ) {
				if ( false !== strpos( mb_strtolower( wp_strip_all_tags( $htext ) ), $kw_lower ) ) {
					$kw_in_heading = true;
					break;
				}
			}
			$results[] = array(
				'check'   => 'keyword_in_subheadings',
				'label'   => __( 'Keyword in Subheadings', 'ai-marketing-expert' ),
				'status'  => $kw_in_heading ? 'pass' : 'warning',
				'message' => $kw_in_heading
					? __( 'Focus keyword found in H2/H3 subheading.', 'ai-marketing-expert' )
					: __( 'Focus keyword not found in any H2 or H3 subheadings.', 'ai-marketing-expert' ),
			);
		}

		// Image alt text check.
		preg_match_all( '/<img[^>]*>/i', $data['content'], $images );
		$img_count   = count( $images[0] );
		$missing_alt = 0;
		foreach ( $images[0] as $img ) {
			if ( ! preg_match( '/alt\s*=\s*"[^"]+"/i', $img ) ) {
				$missing_alt++;
			}
		}
		$results[] = array(
			'check'   => 'image_alt_text',
			'label'   => __( 'Image Alt Text', 'ai-marketing-expert' ),
			'status'  => $missing_alt === 0 ? 'pass' : 'warning',
			'message' => $img_count === 0
				? __( 'No images found. Adding images can improve engagement.', 'ai-marketing-expert' )
				/* translators: 1: images missing alt text, 2: total image count */
				: sprintf( __( '%1$d of %2$d images missing alt text.', 'ai-marketing-expert' ), $missing_alt, $img_count ),
			'value'   => array( 'total' => $img_count, 'missing_alt' => $missing_alt ),
		);

		// Keyword in image alt attribute.
		if ( $keyword && $img_count > 0 ) {
			$kw_in_alt = false;
			foreach ( $images[0] as $img ) {
				if ( preg_match( '/alt\s*=\s*"([^"]*)"/i', $img, $alt_match ) ) {
					if ( false !== strpos( mb_strtolower( $alt_match[1] ), $kw_lower ) ) {
						$kw_in_alt = true;
						break;
					}
				}
			}
			$results[] = array(
				'check'   => 'keyword_in_image_alt',
				'label'   => __( 'Keyword in Image Alt', 'ai-marketing-expert' ),
				'status'  => $kw_in_alt ? 'pass' : 'warning',
				'message' => $kw_in_alt
					? __( 'Focus keyword found in image alt text.', 'ai-marketing-expert' )
					: __( 'Focus keyword not found in any image alt attributes.', 'ai-marketing-expert' ),
			);
		}

		// Internal links check.
		$site_url = home_url();
		preg_match_all( '/<a\s[^>]*href\s*=\s*"([^"]*)"[^>]*>/i', $data['content'], $links );
		$internal = 0;
		$external = 0;
		foreach ( $links[1] ?? array() as $href ) {
			if ( 0 === strpos( $href, $site_url ) || 0 === strpos( $href, '/' ) ) {
				$internal++;
			} elseif ( 0 === strpos( $href, 'http' ) ) {
				$external++;
			}
		}
		$results[] = array(
			'check'   => 'internal_links',
			'label'   => __( 'Internal Links', 'ai-marketing-expert' ),
			'status'  => $internal >= 2 ? 'pass' : ( $internal >= 1 ? 'warning' : 'fail' ),
			/* translators: 1: internal link count, 2: external link count */
			'message' => sprintf( __( '%1$d internal links, %2$d external links found.', 'ai-marketing-expert' ), $internal, $external ),
			'value'   => array( 'internal' => $internal, 'external' => $external ),
		);

		// Outbound external links check.
		$dofollow_external = 0;
		foreach ( $links[0] ?? array() as $a_tag ) {
			if ( preg_match( '/href\s*=\s*"(https?:\/\/[^"]*)"/i', $a_tag, $href_match ) ) {
				$url_target = $href_match[1];
				if ( 0 !== strpos( $url_target, $site_url ) ) {
					if ( ! preg_match( '/rel\s*=\s*"[^"]*nofollow[^"]*"/i', $a_tag ) ) {
						$dofollow_external++;
					}
				}
			}
		}
		$results[] = array(
			'check'   => 'outbound_links',
			'label'   => __( 'Outbound External Links', 'ai-marketing-expert' ),
			'status'  => $external >= 1 ? 'pass' : 'warning',
			'message' => $external >= 1
				? sprintf( __( 'Found %1$d external links (%2$d dofollow). Good for authority.', 'ai-marketing-expert' ), $external, $dofollow_external )
				: __( 'No external outbound links found. Linking to authoritative sources helps SEO.', 'ai-marketing-expert' ),
			'value'   => array( 'external' => $external, 'dofollow' => $dofollow_external ),
		);

		// FAQ / GEO check.
		$has_faq = (bool) (
			preg_match( '/<h[23][^>]*>[^<]*(Frequently Asked Questions|FAQ)[^<]*<\/h[23]>/i', $data['content'] )
			|| preg_match( '/class=["\'][^"\']*aime-faq/i', $data['content'] )
			|| ( ! empty( $data['wp_post_id'] ) && get_post_meta( (int) $data['wp_post_id'], '_aime_faq_data', true ) )
		);
		$results[] = array(
			'check'   => 'has_faq_section',
			'label'   => __( 'FAQ & GEO Optimization', 'ai-marketing-expert' ),
			'status'  => $has_faq ? 'pass' : 'info',
			'message' => $has_faq
				? __( 'FAQ section found (optimized for AI Search Engines & Rich Snippets).', 'ai-marketing-expert' )
				: __( 'Optional: Adding an FAQ section helps ChatGPT, Perplexity, and Google cite your post.', 'ai-marketing-expert' ),
		);

		return $results;
	}

	/**
	 * Get AI suggestions for improvement.
	 */
	private function get_ai_suggestions( array $data, string $keyword, array $checks ): ?array {
		if ( empty( $data['content'] ) && empty( $data['title'] ) ) {
			return null;
		}

		$content_excerpt = mb_substr( wp_strip_all_tags( $data['content'] ), 0, 2000 );
		$check_summary   = '';
		foreach ( $checks as $c ) {
			$check_summary .= "- {$c['label']}: {$c['status']} — {$c['message']}\n";
		}

		$prompt = implode( "\n", array(
			"You are an expert SEO consultant. Review this page's SEO audit results and provide actionable suggestions.",
			'',
			"Page title: {$data['title']}",
			"URL: {$data['url']}",
			"Focus keyword: {$keyword}",
			"Content excerpt: {$content_excerpt}",
			'',
			'Audit results:',
			rtrim( $check_summary ),
			'',
			'Provide:',
			'- title_suggestion: an improved SEO title',
			'- meta_description_suggestion: an improved meta description (150-160 chars)',
			'- content_suggestions: top 5 specific content improvement recommendations',
			'- quick_wins: 3 easy fixes that can be done immediately',
			'- advanced_tips: 3 advanced SEO recommendations',
			'',
			'Return ONLY valid JSON:',
			'{',
			'  "title_suggestion": "",',
			'  "meta_description_suggestion": "",',
			'  "content_suggestions": [""],',
			'  "quick_wins": [""],',
			'  "advanced_tips": [""]',
			'}',
		) );

		$response = AiProvider::generate( $prompt, 'text', 2048 );

		if ( ! $response['success'] ) {
			return null;
		}

		return $this->parse_json_response( $response['content'] );
	}

	/**
	 * Calculate overall SEO score from check results.
	 */
	private function calculate_score( array $results ): int {
		if ( empty( $results ) ) {
			return 0;
		}

		$total  = 0;
		$points = 0;
		foreach ( $results as $r ) {
			$status = $r['status'] ?? 'fail';
			if ( 'info' === $status ) {
				// Soft bonus suggestions do not penalize the score when not needed.
				continue;
			}
			$total++;
			if ( 'pass' === $status ) {
				$points += 1.0;
			} elseif ( 'warning' === $status ) {
				$points += 0.55;
			}
		}

		if ( 0 === $total ) {
			return 100;
		}

		return max( 0, min( 100, (int) round( ( $points / $total ) * 100 ) ) );
	}
}
