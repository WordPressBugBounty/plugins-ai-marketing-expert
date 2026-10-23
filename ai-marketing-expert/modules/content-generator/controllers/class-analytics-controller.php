<?php
/**
 * Analytics Controller — content generation metrics & reporting.
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AnalyticsController {

	/* ── OVERVIEW ────────────────────────────────────── */

	public function overview( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p    = $wpdb->prefix;
		$articles_table = $p . 'aime_content_articles';
		$days           = min( 365, absint( $request->get_param( 'days' ) ?: 30 ) );
		$since          = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Articles over time.
		$articles_over_time = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS date, COUNT(*) AS count
				 FROM %i
				 WHERE created_at >= %s
				 GROUP BY DATE(created_at) ORDER BY date ASC",
				$articles_table,
				$since
			)
		);

		// Published over time.
		$published_over_time = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(published_at) AS date, COUNT(*) AS count
				 FROM %i
				 WHERE published_at IS NOT NULL AND published_at >= %s
				 GROUP BY DATE(published_at) ORDER BY date ASC",
				$articles_table,
				$since
			)
		);

		// Status breakdown.
		$status_breakdown = $wpdb->get_results(
			$wpdb->prepare( 'SELECT status, COUNT(*) AS count FROM %i GROUP BY status', $articles_table )
		);

		// Totals.
		$total_articles    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $articles_table ) );
		$total_published   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $articles_table, 'published' ) );
		$total_words       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(actual_word_count), 0) FROM %i', $articles_table ) );
		$avg_seo           = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(ROUND(AVG(seo_score), 1), 0) FROM %i WHERE seo_score > 0', $articles_table ) );
		$avg_readability   = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(ROUND(AVG(readability_score), 1), 0) FROM %i WHERE readability_score > 0', $articles_table ) );
		$avg_word_count    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(ROUND(AVG(actual_word_count)), 0) FROM %i WHERE actual_word_count > 0', $articles_table ) );

		// This month.
		$month_start        = gmdate( 'Y-m-01 00:00:00' );
		$articles_this_month = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', $articles_table, $month_start ) );

		// Top tones used.
		$top_tones = $wpdb->get_results(
			$wpdb->prepare( 'SELECT tone, COUNT(*) AS count FROM %i GROUP BY tone ORDER BY count DESC LIMIT 5', $articles_table )
		);

		// Recent articles.
		$recent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, status, seo_score, actual_word_count, created_at
				 FROM %i
				 WHERE created_at >= %s
				 ORDER BY created_at DESC LIMIT 10",
				$articles_table,
				$since
			)
		);

		return new \WP_REST_Response( array(
			'articles_over_time'  => $articles_over_time,
			'published_over_time' => $published_over_time,
			'status_breakdown'    => $status_breakdown,
			'top_tones'           => $top_tones,
			'recent_articles'     => $recent,
			'totals'              => array(
				'total_articles'      => $total_articles,
				'total_published'     => $total_published,
				'total_words'         => $total_words,
				'avg_seo_score'       => $avg_seo,
				'avg_readability'     => $avg_readability,
				'avg_word_count'      => $avg_word_count,
				'articles_this_month' => $articles_this_month,
			),
		) );
	}

	/* ── ARTICLE REPORT ──────────────────────────────── */

	public function article_report( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p             = $wpdb->prefix;
		$articles_table = $p . 'aime_content_articles';
		$history_table  = $p . 'aime_content_history';
		$id            = absint( $request->get_param( 'id' ) );

		$article = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, title, status, seo_score, readability_score, actual_word_count, ai_provider, ai_model, wp_post_id, created_at, published_at
			 FROM %i WHERE id = %d",
			$articles_table,
			$id
		) );

		if ( ! $article ) {
			return new \WP_REST_Response( array( 'message' => __( 'Article not found.', 'ai-marketing-expert' ) ), 404 );
		}

		// History timeline.
		$history = $wpdb->get_results( $wpdb->prepare(
			'SELECT action, details, created_at FROM %i WHERE article_id = %d ORDER BY created_at ASC',
			$history_table,
			$id
		) );

		// WP post stats if published.
		$wp_stats = null;
		if ( $article->wp_post_id ) {
			$post = get_post( $article->wp_post_id );
			if ( $post ) {
				$wp_stats = array(
					'post_id'    => $post->ID,
					'post_title' => $post->post_title,
					'post_status' => $post->post_status,
					'post_url'   => get_permalink( $post->ID ),
					'comments'   => (int) $post->comment_count,
				);
			}
		}

		return new \WP_REST_Response( array(
			'article'  => $article,
			'history'  => $history,
			'wp_stats' => $wp_stats,
		) );
	}
}
