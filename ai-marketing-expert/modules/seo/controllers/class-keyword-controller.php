<?php
/**
 * SEO Module — Keyword Controller (CRUD for keyword vault).
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Controllers;

use WPSpace\AiMarketingExpert\Modules\Seo\SeoModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KeywordController {

	/**
	 * List keywords with pagination, search, and filters.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
		$status   = sanitize_text_field( $request->get_param( 'status' ) ?? '' );
		$intent   = sanitize_text_field( $request->get_param( 'intent' ) ?? '' );
		$orderby  = sanitize_key( $request->get_param( 'orderby' ) ?? 'created_at' );
		$order    = strtoupper( sanitize_key( $request->get_param( 'order' ) ?? 'DESC' ) );

		$allowed_orderby = array( 'keyword', 'search_volume', 'difficulty_score', 'created_at', 'current_rank' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'created_at';
		}
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$where  = array( '1=1' );
		$values = array();

		if ( $search ) {
			$where[]  = 'keyword LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		if ( $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}
		if ( $intent ) {
			$where[]  = 'intent = %s';
			$values[] = $intent;
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;

		if ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}aime_seo_keywords WHERE {$where_sql}",
				...$values
			) );

			$items = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$p}aime_seo_keywords WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				...array_merge( $values, array( $per_page, $offset ) )
			) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_seo_keywords" );
			$items = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$p}aime_seo_keywords ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				$per_page,
				$offset
			) );
		}

		return new \WP_REST_Response( array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		) );
	}

	/**
	 * Create a keyword.
	 */
	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit check.
		if ( ! aime_has_pro() ) {
			$limit = aime_free_limits()['seo_keywords_saved'] ?? 50;
			if ( SeoModule::get_keyword_count() >= $limit ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: saved keyword limit */
						__( 'Free plan allows up to %d saved keywords. Upgrade to Pro for unlimited.', 'ai-marketing-expert' ),
						$limit
					),
				), 403 );
			}
		}

		global $wpdb;
		$p = $wpdb->prefix;

		$data = array(
			'keyword'          => sanitize_text_field( $request->get_param( 'keyword' ) ?? '' ),
			'search_volume'    => absint( $request->get_param( 'search_volume' ) ?? 0 ),
			'difficulty_score' => min( 100, absint( $request->get_param( 'difficulty_score' ) ?? 0 ) ),
			'cpc_estimate'     => (float) ( $request->get_param( 'cpc_estimate' ) ?? 0 ),
			'intent'           => sanitize_text_field( $request->get_param( 'intent' ) ?? 'informational' ),
			'parent_topic_id'  => absint( $request->get_param( 'parent_topic_id' ) ) ?: null,
			'cluster_id'       => absint( $request->get_param( 'cluster_id' ) ) ?: null,
			'status'           => sanitize_key( $request->get_param( 'status' ) ?? 'researched' ),
			'target_url'       => esc_url_raw( $request->get_param( 'target_url' ) ?? '' ) ?: null,
			'wp_post_id'       => absint( $request->get_param( 'wp_post_id' ) ) ?: null,
			'notes'            => sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' ),
		);

		if ( empty( $data['keyword'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Keyword is required.', 'ai-marketing-expert' ),
			), 400 );
		}

		$wpdb->insert( "{$p}aime_seo_keywords", $data );
		$id = $wpdb->insert_id;

		if ( ! $id ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Failed to save keyword.', 'ai-marketing-expert' ),
			), 500 );
		}

		$keyword = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_keywords WHERE id = %d",
			$id
		) );

		return new \WP_REST_Response( array(
			'success' => true,
			'data'    => $keyword,
		), 201 );
	}

	/**
	 * Get single keyword.
	 */
	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$keyword = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_keywords WHERE id = %d",
			$id
		) );

		if ( ! $keyword ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Keyword not found.', 'ai-marketing-expert' ),
			), 404 );
		}

		// Also fetch rank history.
		$rank_history = $wpdb->get_results( $wpdb->prepare(
			"SELECT rank_position, url, search_engine, checked_at FROM {$p}aime_seo_rank_history WHERE keyword_id = %d ORDER BY checked_at DESC LIMIT 30",
			$id
		) );

		return new \WP_REST_Response( array(
			'success'      => true,
			'data'         => $keyword,
			'rank_history' => $rank_history,
		) );
	}

	/**
	 * Update keyword.
	 */
	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}aime_seo_keywords WHERE id = %d",
			$id
		) );

		if ( ! $exists ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Keyword not found.', 'ai-marketing-expert' ),
			), 404 );
		}

		$data = array( 'updated_at' => current_time( 'mysql', true ) );

		$fields = array(
			'keyword'          => 'sanitize_text_field',
			'search_volume'    => 'absint',
			'difficulty_score' => 'absint',
			'intent'           => 'sanitize_text_field',
			'status'           => 'sanitize_key',
			'target_url'       => 'esc_url_raw',
			'wp_post_id'       => 'absint',
			'notes'            => 'sanitize_textarea_field',
			'current_rank'     => 'absint',
		);

		foreach ( $fields as $field => $sanitizer ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = $sanitizer( $value );
			}
		}

		$cpc = $request->get_param( 'cpc_estimate' );
		if ( $cpc !== null ) {
			$data['cpc_estimate'] = (float) $cpc;
		}

		$wpdb->update( "{$p}aime_seo_keywords", $data, array( 'id' => $id ) );

		$keyword = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}aime_seo_keywords WHERE id = %d",
			$id
		) );

		return new \WP_REST_Response( array(
			'success' => true,
			'data'    => $keyword,
		) );
	}

	/**
	 * Delete keyword.
	 */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $request->get_param( 'id' );

		$deleted = $wpdb->delete( "{$p}aime_seo_keywords", array( 'id' => $id ) );

		if ( ! $deleted ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'Keyword not found or already deleted.', 'ai-marketing-expert' ),
			), 404 );
		}

		// Clean up rank history.
		$wpdb->delete( "{$p}aime_seo_rank_history", array( 'keyword_id' => $id ) );

		return new \WP_REST_Response( array( 'success' => true ) );
	}

	/**
	 * Bulk delete keywords.
	 */
	public function bulk_destroy( \WP_REST_Request $request ): \WP_REST_Response {
		$ids = $request->get_param( 'ids' );

		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'No keyword IDs provided.', 'ai-marketing-expert' ),
			), 400 );
		}

		global $wpdb;
		$p = $wpdb->prefix;

		$ids     = array_map( 'absint', $ids );
		$holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$p}aime_seo_keywords WHERE id IN ({$holders})",
			...$ids
		) );

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$p}aime_seo_rank_history WHERE keyword_id IN ({$holders})",
			...$ids
		) );

		return new \WP_REST_Response( array(
			'success' => true,
			'deleted' => count( $ids ),
		) );
	}
}
