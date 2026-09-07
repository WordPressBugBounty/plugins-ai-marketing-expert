<?php
/**
 * SEO Audit action — run an on-page audit for a post/URL.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions;

use WPSpace\AiMarketingExpert\Modules\Seo\Services\OnPageSeoService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoAuditAction extends BaseAction {

	public static function run( array $config, array $context ): array {
		$post_id = (int) ( $config['wp_post_id'] ?? -1 );
		$url     = esc_url_raw( (string) ( $config['url'] ?? '' ) );
		$keyword = sanitize_text_field( (string) ( $config['keyword_focus'] ?? '' ) );

		// -1 = previous step (inherit post from parent action).
		// The audit service audits WordPress posts, so wp_post_id is preferred;
		// a content-module article_id is mapped to its WP post when possible.
		if ( -1 === $post_id ) {
			$parent_ref = $context['parent_output']['reference'] ?? array();
			if ( ! empty( $parent_ref['wp_post_id'] ) ) {
				$post_id = (int) $parent_ref['wp_post_id'];
			} elseif ( ! empty( $parent_ref['article_id'] ) ) {
				$post_id = self::wp_post_id_for_article( (int) $parent_ref['article_id'] );
			} else {
				$post_id = 0; // Fallback to latest published
			}
		}

		// Smart keyword detection (priority order):
		if ( '' === $keyword ) {
			$parent_ref = $context['parent_output']['reference'] ?? array();

			// 1. Try AI Brain selected topic
			if ( ! empty( $parent_ref['selected_topic'] ) ) {
				$keyword = sanitize_text_field( (string) $parent_ref['selected_topic'] );
			}
			// 2. Try AI Brain keywords list (first one)
			elseif ( ! empty( $parent_ref['keywords'] ) ) {
				$keywords_list = explode( ',', (string) $parent_ref['keywords'] );
				$keyword       = sanitize_text_field( trim( $keywords_list[0] ?? '' ) );
			}
		}

		// 0 = audit the most recently published post.
		if ( ! $post_id && '' === $url ) {
			$latest = get_posts( array(
				'numberposts' => 1,
				'post_status' => 'publish',
				'post_type'   => 'post',
				'fields'      => 'ids',
			) );
			if ( empty( $latest ) ) {
				return self::fail( __( 'No published post found to audit.', 'ai-marketing-expert' ) );
			}
			$post_id = (int) $latest[0];
		}

		// If auditing a post and still no keyword, try canonical + plugin meta via adapter.
		if ( '' === $keyword && $post_id ) {
			if ( class_exists( '\\WPSpace\\AiMarketingExpert\\Modules\\Seo\\Services\\SeoAdapterService' ) ) {
				$keyword = \WPSpace\AiMarketingExpert\Modules\Seo\Services\SeoAdapterService::get_focus_keyword( $post_id );
			} else {
				// Fallback when SEO module inactive.
				$keyword = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
				if ( ! $keyword ) {
					$keyword = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
				}
				if ( ! $keyword ) {
					$keyword = get_post_meta( $post_id, 'aime_seo_keyword', true );
				}
			}

			// Last resort: extract from title
			if ( ! $keyword ) {
				$title = get_the_title( $post_id );
				if ( $title ) {
					// Use first 2-3 meaningful words from title
					$words   = preg_split( '/\s+/', $title );
					$words   = array_filter( $words, function( $word ) {
						// Filter out common stop words
						$stop_words = array( 'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by' );
						return strlen( $word ) > 3 && ! in_array( strtolower( $word ), $stop_words, true );
					} );
					$keyword = implode( ' ', array_slice( array_values( $words ), 0, 3 ) );
				}
			}

			$keyword = sanitize_text_field( (string) $keyword );
		}

		$service = new OnPageSeoService();
		$result  = $service->run_audit( $post_id, $url, $keyword );

		if ( empty( $result['success'] ) ) {
			return self::fail( $result['message'] ?? __( 'SEO audit failed.', 'ai-marketing-expert' ) );
		}

		// OnPageSeoService returns its payload nested under 'data'; only fall
		// back to the top level for other/legacy shapes.
		$data    = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$score   = (int) ( $data['overall_score'] ?? $result['overall_score'] ?? $result['score'] ?? 0 );
		$preview = sprintf(
			/* translators: 1: post id, 2: score, 3: keyword */
			__( 'SEO audit complete for post #%1$d — score: %2$d/100 (keyword: %3$s).', 'ai-marketing-expert' ),
			$post_id,
			$score,
			$keyword
		);

		$reference = array(
			'wp_post_id' => $post_id,
			'score'      => $score,
			'keyword'    => $keyword,
			'link'       => self::module_link( 'seo', 'on-page-audit' ),
		);
		$audit_id  = (int) ( $data['id'] ?? $result['audit_id'] ?? 0 );
		if ( $audit_id > 0 ) {
			$reference['audit_id'] = $audit_id;
		}

		return self::ok( $preview, $reference );
	}

	/**
	 * Map a content-module article ID to its published WordPress post ID.
	 *
	 * The audit service operates in the WP posts namespace; auditing the raw
	 * aime_content_articles ID would silently audit whatever WP post happens
	 * to share that number. Returns 0 when unmapped (falls through to the
	 * latest-published fallback).
	 *
	 * @param int $article_id Content module article row ID.
	 * @return int WordPress post ID (0 when unknown).
	 */
	private static function wp_post_id_for_article( int $article_id ): int {
		global $wpdb;
		if ( $article_id <= 0 ) {
			return 0;
		}
		$table = $wpdb->prefix . 'aime_content_articles';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT wp_post_id FROM {$table} WHERE id = %d AND wp_post_id IS NOT NULL",
			$article_id
		) );
	}
}
