<?php
/**
 * Knowledge Controller — knowledge base CRUD + indexing triggers.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

use WPSpace\AiMarketingExpert\Modules\Chatbot\Services\KnowledgeIndexer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KnowledgeController {

	/* ── LIST knowledge entries for a bot ─────────────── */

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p               = $wpdb->prefix;
		$knowledge_table = $p . 'aime_chatbot_knowledge';
		$bot_id          = absint( $request->get_param( 'bot_id' ) );
		$type            = $request->get_param( 'type' );
		$search          = $request->get_param( 'search' );
		$page            = max( 1, $request->get_param( 'page' ) );
		$per_page        = min( 100, max( 1, $request->get_param( 'per_page' ) ?: 50 ) );
		$offset          = ( $page - 1 ) * $per_page;

		$where = 'WHERE bot_id = %d';
		$args  = array( $bot_id );

		if ( $type ) {
			$where .= ' AND type = %s';
			$args[] = $type;
		}

		if ( $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (question LIKE %s OR answer LIKE %s OR content LIKE %s OR metadata LIKE %s)';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}

		$count_sql  = 'SELECT COUNT(*) FROM %i ' . $where;
		$count_args = array_merge( array( $knowledge_table ), $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic admin listing query is prepared at execution and intentionally uncached.
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$count_args ) );

		$query = 'SELECT id, bot_id, type, source_id, question, answer, content, metadata, status, last_indexed_at, created_at, updated_at
				  FROM %i ' . $where . '
				  ORDER BY created_at DESC LIMIT %d OFFSET %d';
		$args[] = $per_page;
		$args[] = $offset;
		$query_args = array_merge( array( $knowledge_table ), $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic admin listing query is prepared at execution and intentionally uncached.
		$items = $wpdb->get_results( $wpdb->prepare( $query, ...$query_args ) );

		return new \WP_REST_Response( array(
			'items'    => $items ?: array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		) );
	}

	/* ── ADD knowledge entry ─────────────────────────── */

	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p               = $wpdb->prefix;
		$knowledge_table = $p . 'aime_chatbot_knowledge';
		$bot_id          = absint( $request->get_param( 'bot_id' ) );
		$params          = $request->get_json_params();
		$type            = sanitize_text_field( $params['type'] ?? 'qa_pair' );

		// Free limit checks.
		if ( ! aime_has_pro() ) {
			$limits = aime_free_limits();

			if ( 'qa_pair' === $type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Limit enforcement needs the current knowledge count.
				$count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE bot_id = %d AND type = 'qa_pair'",
					$knowledge_table,
					$bot_id
				) );
				$limit = $limits['chatbot_knowledge_qa'] ?? 50;
				if ( $count >= $limit ) {
					return new \WP_REST_Response( array(
						'message'       => __( 'Q&A pair limit reached. Upgrade to Pro for unlimited.', 'ai-marketing-expert' ),
						'limit_reached' => true,
					), 403 );
				}
			}

			if ( 'wp_content' === $type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Limit enforcement needs the current knowledge count.
				$count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE bot_id = %d AND type = 'wp_content'",
					$knowledge_table,
					$bot_id
				) );
				$limit = $limits['chatbot_knowledge_pages'] ?? 50;
				if ( $count >= $limit ) {
					return new \WP_REST_Response( array(
						'message'       => __( 'Page/post limit reached. Upgrade to Pro for unlimited.', 'ai-marketing-expert' ),
						'limit_reached' => true,
					), 403 );
				}
			}

			// Pro-only types.
			$pro_types = array( 'woo_product', 'document', 'url' );
			if ( in_array( $type, $pro_types, true ) ) {
				return new \WP_REST_Response( array(
					'message' => __( 'This knowledge source type requires Pro.', 'ai-marketing-expert' ),
				), 403 );
			}
		}

		$now  = current_time( 'mysql', true );
		$data = array(
			'bot_id'     => $bot_id,
			'type'       => $type,
			'source_id'  => absint( $params['source_id'] ?? 0 ) ?: null,
			'question'   => sanitize_textarea_field( $params['question'] ?? '' ),
			'answer'     => sanitize_textarea_field( $params['answer'] ?? '' ),
			'content'    => sanitize_textarea_field( $params['content'] ?? '' ),
			'status'     => 'active',
			'metadata'   => wp_json_encode( $params['metadata'] ?? new \stdClass() ),
			'created_at' => $now,
			'updated_at' => $now,
		);

		$result = $wpdb->insert( $knowledge_table, $data );

		if ( false === $result ) {
			return new \WP_REST_Response( array( 'message' => __( 'Failed to add knowledge entry.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( array(
			'id'      => (int) $wpdb->insert_id,
			'message' => __( 'Knowledge entry added.', 'ai-marketing-expert' ),
		), 201 );
	}

	/* ── UPDATE knowledge entry ──────────────────────── */

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p               = $wpdb->prefix;
		$knowledge_table = $p . 'aime_chatbot_knowledge';
		$id              = absint( $request->get_param( 'id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off existence check before update.
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $knowledge_table, $id ) );
		if ( ! $existing ) {
			return new \WP_REST_Response( array( 'message' => __( 'Knowledge entry not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$params = $request->get_json_params();
		$data   = array( 'updated_at' => current_time( 'mysql', true ) );

		$text_fields = array( 'question', 'answer', 'content', 'status' );
		foreach ( $text_fields as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$data[ $field ] = sanitize_textarea_field( $params[ $field ] );
			}
		}

		$wpdb->update( $knowledge_table, $data, array( 'id' => $id ) );

		return new \WP_REST_Response( array( 'message' => __( 'Knowledge entry updated.', 'ai-marketing-expert' ) ) );
	}

	/* ── DELETE knowledge entry ──────────────────────── */

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p               = $wpdb->prefix;
		$knowledge_table = $p . 'aime_chatbot_knowledge';
		$id              = absint( $request->get_param( 'id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off existence check before delete.
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $knowledge_table, $id ) );
		if ( ! $existing ) {
			return new \WP_REST_Response( array( 'message' => __( 'Knowledge entry not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$wpdb->delete( $knowledge_table, array( 'id' => $id ) );

		return new \WP_REST_Response( array( 'message' => __( 'Knowledge entry deleted.', 'ai-marketing-expert' ) ) );
	}

	/* ── TRIGGER index (bulk import WP content/products) */

	public function trigger_index( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$bot_id = absint( $request->get_param( 'bot_id' ) );
		$type   = sanitize_text_field( $params['type'] ?? '' );

		$allowed = array( 'wp_content', 'woo_product', 'wp_posts' );
		if ( ! in_array( $type, $allowed, true ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Invalid index type.', 'ai-marketing-expert' ) ), 400 );
		}

		// Pro check for WooCommerce.
		if ( 'woo_product' === $type && ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'message' => __( 'WooCommerce indexing requires Pro.', 'ai-marketing-expert' ),
			), 403 );
		}

		// Map wp_posts to wp_content with post_type filter.
		$post_type = sanitize_text_field( $params['post_type'] ?? '' );
		if ( 'wp_posts' === $type ) {
			$type      = 'wp_content';
			$post_type = 'post';
		}

		$indexer = new KnowledgeIndexer();
		$result  = $indexer->bulk_index( $bot_id, $type, $post_type );

		return new \WP_REST_Response( array(
			'message' => sprintf(
				/* translators: %d: number of items indexed */
				__( 'Indexed %d items.', 'ai-marketing-expert' ),
				$result['indexed'] ?? 0
			),
			'result' => $result,
		) );
	}

	/* ── INDEX STATUS ────────────────────────────────── */

	public function index_status( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p               = $wpdb->prefix;
		$knowledge_table = $p . 'aime_chatbot_knowledge';
		$bot_id          = absint( $request->get_param( 'bot_id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Status summary is a fresh admin aggregate.
		$stats = $wpdb->get_results( $wpdb->prepare(
			"SELECT type, COUNT(*) AS total,
				SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
				MAX(last_indexed_at) AS last_indexed
			 FROM %i
			 WHERE bot_id = %d
			 GROUP BY type",
			$knowledge_table,
			$bot_id
		) );

		return new \WP_REST_Response( $stats ?: array() );
	}
}
