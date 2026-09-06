<?php
/**
 * SEO Analyzer Service — Scores and optimizes article SEO via AI.
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoAnalyzerService {

	use ParsesAiJson;

	/**
	 * Quick score — Lightweight SEO + readability scores for a generated article.
	 * Called automatically after article generation.
	 *
	 * @param  int $article_id  Row ID in aime_content_articles.
	 * @return array {seo_score, readability_score}
	 */
	public function quick_score( int $article_id ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'aime_content_articles';
		$article = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $article_id ) );

		if ( ! $article ) {
			return array( 'seo_score' => 0, 'readability_score' => 0 );
		}

		$html     = $article->content ?? '';
		$text     = wp_strip_all_tags( $html );
		$keywords = json_decode( $article->keywords ?? '[]', true ) ?: array();

		$seo_score         = $this->calculate_seo_score( $article->title, $text, $keywords, $article->meta_title ?? '', $article->meta_description ?? '', $html );
		$readability_score = $this->calculate_readability_score( $text, $html );

		$wpdb->update(
			$table,
			array(
				'seo_score'         => $seo_score,
				'readability_score' => $readability_score,
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( 'id' => $article_id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		return array(
			'seo_score'         => $seo_score,
			'readability_score' => $readability_score,
		);
	}

	/**
	 * Analyze and optimize — AI-powered detailed SEO analysis + rewrite suggestions (PRO feature).
	 *
	 * @param  string $content   The HTML content.
	 * @param  array  $keywords  Target keywords.
	 * @param  string $title     Article title.
	 * @return array {success, analysis|error}
	 */
	public function analyze_and_optimize( string $content, array $keywords, string $title ): array {
		$text         = mb_substr( wp_strip_all_tags( $content ), 0, 3000 );
		$keywords_str = $keywords ? implode( ', ', $keywords ) : 'none specified';

		$prompt = "You are an SEO analyst. Analyze the following article and provide detailed optimization recommendations.\n\n"
			. "Title: \"{$title}\"\n"
			. "Target keywords: {$keywords_str}\n"
			. "Content excerpt:\n{$text}\n\n"
			. "Return a JSON object with:\n"
			. "- \"seo_score\": number 0-100\n"
			. "- \"readability_score\": number 0-100\n"
			. "- \"keyword_density\": object with each keyword and its percentage\n"
			. "- \"issues\": array of {severity (high|medium|low), message, suggestion}\n"
			. "- \"optimized_title\": improved SEO title (max 60 chars)\n"
			. "- \"optimized_meta_description\": improved meta description (max 160 chars)\n"
			. "- \"content_suggestions\": array of 3-5 specific improvement suggestions\n"
			. "- \"missing_keywords\": array of important keywords that should be added\n"
			. "Return ONLY the JSON object. No thinking, no reasoning, no commentary.";

		$result = AiProvider::generate( $prompt, 'text', 2048 );

		if ( ! $result['success'] ) {
			return array( 'success' => false, 'error' => $result['content'] ?? 'AI analysis failed' );
		}

		$parsed = $this->parse_json_response( $result['content'] );

		return array(
			'success'  => true,
			'analysis' => $parsed ?: array( 'raw' => $result['content'] ),
		);
	}

	/* ── Rule-based scoring (no AI call) ─────────────── */

	private function calculate_seo_score( string $title, string $text, array $keywords, string $meta_title, string $meta_desc, string $html = '' ): int {
		$score = 30; // Base score for having content.
		$words = str_word_count( $text );

		// Title length (30-65 chars ideal).
		$title_len = mb_strlen( $title );
		if ( $title_len >= 30 && $title_len <= 65 ) {
			$score += 10;
		} elseif ( $title_len > 0 ) {
			$score += 5;
		}

		// Has meta title.
		if ( ! empty( $meta_title ) ) {
			$score += 8;
		}

		// Has meta description (100-165 chars ideal).
		$desc_len = mb_strlen( $meta_desc );
		if ( $desc_len >= 100 && $desc_len <= 165 ) {
			$score += 10;
		} elseif ( $desc_len > 0 ) {
			$score += 5;
		}

		// Word count (300+ is good, 800+ is great, 1500+ is excellent).
		if ( $words >= 1500 ) {
			$score += 12;
		} elseif ( $words >= 800 ) {
			$score += 10;
		} elseif ( $words >= 300 ) {
			$score += 5;
		}

		// Has headings in content.
		if ( preg_match( '/<h[2-3]\b/i', $html ) ) {
			$score += 5;
		}

		// Keyword presence in title.
		$text_lower  = mb_strtolower( $text );
		$title_lower = mb_strtolower( $title );
		$keyword_in_title = false;
		foreach ( $keywords as $kw ) {
			$kw_lower = mb_strtolower( trim( $kw ) );
			if ( ! $kw_lower ) {
				continue;
			}
			if ( mb_strpos( $title_lower, $kw_lower ) !== false ) {
				$keyword_in_title = true;
				$score += 8;
				break;
			}
		}

		// Keyword in meta title.
		if ( ! empty( $meta_title ) && $keywords ) {
			$meta_lower = mb_strtolower( $meta_title );
			foreach ( $keywords as $kw ) {
				$kw_lower = mb_strtolower( trim( $kw ) );
				if ( $kw_lower && mb_strpos( $meta_lower, $kw_lower ) !== false ) {
					$score += 5;
					break;
				}
			}
		}

		// Keyword density (0.3% - 3% ideal for primary keyword).
		if ( $words > 0 && $keywords ) {
			$primary     = mb_strtolower( trim( $keywords[0] ) );
			$occurrences = $primary ? mb_substr_count( $text_lower, $primary ) : 0;
			$density     = ( $occurrences * str_word_count( $primary ) / $words ) * 100;
			if ( $density >= 0.3 && $density <= 3.0 ) {
				$score += 12;
			} elseif ( $density > 0 ) {
				$score += 5;
			}
		}

		return min( 100, max( 0, $score ) );
	}

	private function calculate_readability_score( string $text, string $html = '' ): int {
		$words     = str_word_count( $text );
		$sentences = max( 1, preg_match_all( '/[.!?。！？]+/u', $text ) );

		if ( $words < 10 ) {
			return 50;
		}

		// Average sentence length.
		$asl = $words / $sentences;

		// Score based on sentence length (15-20 words per sentence is ideal).
		$score = 70; // Base for readable content.

		if ( $asl >= 10 && $asl <= 20 ) {
			$score += 15; // Ideal sentence length.
		} elseif ( $asl >= 8 && $asl <= 25 ) {
			$score += 10;
		} elseif ( $asl > 30 ) {
			$score -= 15; // Too long sentences.
		}

		// Paragraph variety (check for content structure).
		$paragraphs = max( 1, preg_match_all( '/<p\b/i', $html ) ?: substr_count( $text, "\n\n" ) + 1 );
		$avg_para_len = $words / $paragraphs;
		if ( $avg_para_len <= 150 && $avg_para_len >= 20 ) {
			$score += 10; // Good paragraph length.
		} elseif ( $avg_para_len <= 200 ) {
			$score += 5;
		}

		// Has lists (good for readability).
		if ( preg_match( '/<[uo]l\b/i', $html ) ) {
			$score += 5;
		}

		return min( 100, max( 0, $score ) );
	}
}
