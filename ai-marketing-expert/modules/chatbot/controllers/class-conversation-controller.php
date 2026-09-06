<?php
/**
 * Conversation Controller — conversation list / view / admin actions.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConversationController {

	/* ── LIST conversations ──────────────────────────── */

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p        = $wpdb->prefix;
		$page     = max( 1, $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ?: 20 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$status   = $request->get_param( 'status' );
		$bot_id   = absint( $request->get_param( 'bot_id' ) );
		$search   = $request->get_param( 'search' );
		$visitor  = sanitize_key( $request->get_param( 'visitor_type' ) ?? '' );

		$where = 'WHERE 1=1';
		$args  = array();

		if ( $status ) {
			$where .= ' AND c.status = %s';
			$args[] = $status;
		}

		// Visitor type filter: leads (captured / has email) vs anonymous.
		if ( 'lead' === $visitor ) {
			$where .= " AND (c.lead_captured = 1 OR (c.visitor_email IS NOT NULL AND c.visitor_email <> ''))";
		} elseif ( 'anonymous' === $visitor ) {
			$where .= " AND c.lead_captured = 0 AND (c.visitor_email IS NULL OR c.visitor_email = '')";
		}

		if ( $bot_id ) {
			$where .= ' AND c.bot_id = %d';
			$args[] = $bot_id;
		}

		if ( $search ) {
			$where .= ' AND (c.visitor_name LIKE %s OR c.visitor_email LIKE %s)';
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$args[] = $like;
			$args[] = $like;
		}

		// Only real chats: the visitor must have written at least one message.
		$where .= " AND EXISTS (
			SELECT 1 FROM {$p}aime_chatbot_messages mv
			WHERE mv.conversation_id = c.id AND mv.sender_type = 'visitor'
		)";

		// Free tier: limit to last 7 days.
		if ( ! aime_has_pro() ) {
			$where .= ' AND c.created_at >= %s';
			$args[] = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
		}

		$count_sql = "SELECT COUNT(*) FROM {$p}aime_chatbot_conversations c {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : $wpdb->get_var( $count_sql ) );

		$query = "SELECT c.id, c.bot_id, c.visitor_id, c.visitor_name, c.visitor_email,
					c.status, c.agent_id, c.lead_captured, c.satisfaction_rating,
					c.source, c.page_url, c.is_public, c.started_at, c.ended_at, c.created_at,
					b.name AS bot_name,
					(SELECT COUNT(*) FROM {$p}aime_chatbot_messages m WHERE m.conversation_id = c.id AND m.sender_type != 'system') AS message_count,
					(SELECT m2.content FROM {$p}aime_chatbot_messages m2 WHERE m2.conversation_id = c.id AND m2.sender_type != 'system' ORDER BY m2.id DESC LIMIT 1) AS last_message
				  FROM {$p}aime_chatbot_conversations c
				  LEFT JOIN {$p}aime_chatbot_bots b ON b.id = c.bot_id
				  {$where}
				  ORDER BY c.updated_at DESC
				  LIMIT %d OFFSET %d";
		$args[] = $per_page;
		$args[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$items = $wpdb->get_results( $wpdb->prepare( $query, ...$args ) );

		return new \WP_REST_Response( array(
			'items'    => $items ?: array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		) );
	}

	/* ── ACTIVE conversations (for live chat) ───────── */

	public function active( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$items = $wpdb->get_results(
			"SELECT c.id, c.bot_id, c.visitor_id, c.visitor_name, c.visitor_email,
				c.status, c.agent_id, c.lead_captured, c.page_url, c.updated_at,
				b.name AS bot_name,
				(SELECT COUNT(*) FROM {$p}aime_chatbot_messages m WHERE m.conversation_id = c.id AND m.is_read = 0 AND m.sender_type = 'visitor') AS unread_count,
				(SELECT m2.content FROM {$p}aime_chatbot_messages m2 WHERE m2.conversation_id = c.id AND m2.sender_type != 'system' ORDER BY m2.id DESC LIMIT 1) AS last_message
			 FROM {$p}aime_chatbot_conversations c
			 LEFT JOIN {$p}aime_chatbot_bots b ON b.id = c.bot_id
			 WHERE c.status IN ('active','human_takeover')
			 AND EXISTS (
				SELECT 1 FROM {$p}aime_chatbot_messages mv
				WHERE mv.conversation_id = c.id AND mv.sender_type = 'visitor'
			 )
			 ORDER BY c.updated_at DESC
			 LIMIT 100"
		);

		return new \WP_REST_Response( $items ?: array() );
	}

	/* ── SHOW single conversation with messages ──────── */

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		$conversation = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.*, b.name AS bot_name
			 FROM {$p}aime_chatbot_conversations c
			 LEFT JOIN {$p}aime_chatbot_bots b ON b.id = c.bot_id
			 WHERE c.id = %d",
			$id
		) );

		if ( ! $conversation ) {
			return new \WP_REST_Response( array( 'message' => __( 'Conversation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$conversation->metadata = json_decode( $conversation->metadata ?: '{}', true );

		// Get all messages except system messages.
		$conversation->messages = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, sender_type, sender_id, content, content_type, metadata, is_read, created_at
			 FROM {$p}aime_chatbot_messages
			 WHERE conversation_id = %d AND sender_type != 'system'
			 ORDER BY created_at ASC",
			$id
		) );

		foreach ( $conversation->messages as &$msg ) {
			$msg->metadata = json_decode( $msg->metadata ?: '{}', true );
		}
		unset( $msg );

		// Mark visitor messages as read.
		$wpdb->update(
			"{$p}aime_chatbot_messages",
			array( 'is_read' => 1 ),
			array(
				'conversation_id' => $id,
				'sender_type'     => 'visitor',
				'is_read'         => 0,
			)
		);

		return new \WP_REST_Response( $conversation );
	}

	/* ── HUMAN TAKEOVER (Pro) ────────────────────────── */

	public function takeover( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'message' => __( 'Human takeover requires Pro.', 'ai-marketing-expert' ),
			), 403 );
		}

		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		$conversation = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status FROM {$p}aime_chatbot_conversations WHERE id = %d",
			$id
		) );

		if ( ! $conversation ) {
			return new \WP_REST_Response( array( 'message' => __( 'Conversation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		if ( 'closed' === $conversation->status ) {
			return new \WP_REST_Response( array( 'message' => __( 'Cannot take over a closed conversation.', 'ai-marketing-expert' ) ), 400 );
		}

		$now      = current_time( 'mysql', true );
		$agent_id = get_current_user_id();
		$agent    = get_userdata( $agent_id );

		$wpdb->update(
			"{$p}aime_chatbot_conversations",
			array(
				'status'     => 'human_takeover',
				'agent_id'   => $agent_id,
				'updated_at' => $now,
			),
			array( 'id' => $id )
		);

		// Insert system message.
		$wpdb->insert( "{$p}aime_chatbot_messages", array(
			'conversation_id' => $id,
			'sender_type'     => 'system',
			'sender_id'       => (string) $agent_id,
			'content'         => sprintf(
				/* translators: %s: agent display name */
				__( '%s has joined the conversation.', 'ai-marketing-expert' ),
				$agent ? $agent->display_name : __( 'An agent', 'ai-marketing-expert' )
			),
			'content_type'    => 'text',
			'created_at'      => $now,
		) );

		return new \WP_REST_Response( array( 'message' => __( 'Takeover successful.', 'ai-marketing-expert' ) ) );
	}

	/* ── CLOSE conversation ──────────────────────────── */

	public function close( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		$conversation = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status FROM {$p}aime_chatbot_conversations WHERE id = %d",
			$id
		) );

		if ( ! $conversation ) {
			return new \WP_REST_Response( array( 'message' => __( 'Conversation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$now = current_time( 'mysql', true );
		$wpdb->update(
			"{$p}aime_chatbot_conversations",
			array(
				'status'     => 'closed',
				'ended_at'   => $now,
				'updated_at' => $now,
			),
			array( 'id' => $id )
		);

		// Insert system message.
		$wpdb->insert( "{$p}aime_chatbot_messages", array(
			'conversation_id' => $id,
			'sender_type'     => 'system',
			'content'         => __( 'Conversation has been closed.', 'ai-marketing-expert' ),
			'content_type'    => 'text',
			'created_at'      => $now,
		) );

		return new \WP_REST_Response( array( 'message' => __( 'Conversation closed.', 'ai-marketing-expert' ) ) );
	}

	/* ── DELETE conversation ─────────────────────────── */

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$p}aime_chatbot_conversations WHERE id = %d", $id ) );
		if ( ! $existing ) {
			return new \WP_REST_Response( array( 'message' => __( 'Conversation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		// Delete messages.
		$wpdb->delete( "{$p}aime_chatbot_messages", array( 'conversation_id' => $id ) );

		// Delete conversation.
		$wpdb->delete( "{$p}aime_chatbot_conversations", array( 'id' => $id ) );

		return new \WP_REST_Response( array( 'message' => __( 'Conversation deleted.', 'ai-marketing-expert' ) ) );
	}

	/* ── EXPORT conversations (Pro) ──────────────────── */

	public function export( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'message' => __( 'Exporting conversations requires Pro.', 'ai-marketing-expert' ),
			), 403 );
		}

		global $wpdb;
		$p    = $wpdb->prefix;
		$rows = array();

		$conversations = $wpdb->get_results(
			"SELECT c.id, c.visitor_name, c.visitor_email, c.status, c.source,
					c.lead_captured, c.satisfaction_rating, c.created_at, c.ended_at,
					b.name AS bot_name
			 FROM {$p}aime_chatbot_conversations c
			 LEFT JOIN {$p}aime_chatbot_bots b ON b.id = c.bot_id
			 ORDER BY c.created_at DESC"
		);

		foreach ( $conversations as $conv ) {
			$messages = $wpdb->get_results( $wpdb->prepare(
				"SELECT sender_type, content, created_at FROM {$p}aime_chatbot_messages WHERE conversation_id = %d ORDER BY created_at ASC",
				$conv->id
			) );

			$transcript = '';
			foreach ( $messages as $m ) {
				$transcript .= sprintf( "[%s] %s: %s\n", $m->created_at, $m->sender_type, $m->content );
			}

			$rows[] = array(
				'id'           => $conv->id,
				'bot'          => $conv->bot_name,
				'visitor_name' => $conv->visitor_name,
				'visitor_email' => $conv->visitor_email,
				'status'       => $conv->status,
				'source'       => $conv->source,
				'lead'         => $conv->lead_captured ? 'Yes' : 'No',
				'rating'       => $conv->satisfaction_rating ?: '',
				'started'      => $conv->created_at,
				'ended'        => $conv->ended_at ?: '',
				'transcript'   => $transcript,
			);
		}

		return new \WP_REST_Response( array(
			'columns' => array( 'id', 'bot', 'visitor_name', 'visitor_email', 'status', 'source', 'lead', 'rating', 'started', 'ended', 'transcript' ),
			'rows'    => $rows,
		) );
	}

	/* ── EXPORT SINGLE — single conversation CSV ─────── */

	public function export_single( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'message' => __( 'Exporting conversations requires Pro.', 'ai-marketing-expert' ),
			), 403 );
		}

		$id = absint( $request->get_param( 'id' ) );

		global $wpdb;
		$p = $wpdb->prefix;

		$conversation = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.*, b.name AS bot_name
			 FROM {$p}aime_chatbot_conversations c
			 LEFT JOIN {$p}aime_chatbot_bots b ON b.id = c.bot_id
			 WHERE c.id = %d",
			$id
		) );

		if ( ! $conversation ) {
			return new \WP_REST_Response( array( 'message' => __( 'Conversation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$messages = $wpdb->get_results( $wpdb->prepare(
			"SELECT sender_type, content, created_at FROM {$p}aime_chatbot_messages WHERE conversation_id = %d ORDER BY created_at ASC",
			$id
		) );

		// Build CSV string.
		$lines   = array();
		$lines[] = 'Timestamp,Sender,Message';
		foreach ( $messages as $m ) {
			$escaped = '"' . str_replace( '"', '""', $m->content ) . '"';
			$lines[] = sprintf( '%s,%s,%s', $m->created_at, $m->sender_type, $escaped );
		}

		return new \WP_REST_Response( array(
			'csv' => implode( "\n", $lines ),
		) );
	}

	/* ── BULK actions ────────────────────────────────── */

	public function bulk( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p   = $wpdb->prefix;
		$ids = array_map( 'absint', $request->get_param( 'ids' ) ?? array() );

		if ( empty( $ids ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'No conversations selected.', 'ai-marketing-expert' ) ), 400 );
		}

		$action = sanitize_text_field( $request->get_param( 'action' ) );
		if ( in_array( $action, array( 'make_public', 'make_private' ), true ) && ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'message' => __( 'Public discussions requires Pro.', 'ai-marketing-expert' ),
			), 403 );
		}

		if ( 'export_leads' === $action ) {
			if ( ! aime_has_pro() ) {
				return new \WP_REST_Response( array(
					'message' => __( 'Exporting leads requires Pro.', 'ai-marketing-expert' ),
				), 403 );
			}
			return $this->export_leads_csv( $ids );
		}

		$now    = current_time( 'mysql', true );
		$count  = count( $ids );
		$ph     = implode( ',', array_fill( 0, $count, '%d' ) );

		switch ( $action ) {
			case 'delete':
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}aime_chatbot_messages WHERE conversation_id IN ({$ph})", ...$ids ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}aime_chatbot_conversations WHERE id IN ({$ph})", ...$ids ) );
				break;

			case 'close':
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$p}aime_chatbot_conversations SET status = 'closed', ended_at = %s, updated_at = %s WHERE id IN ({$ph})",
					$now, $now, ...$ids
				) );
				// Insert system message for each.
				foreach ( $ids as $cid ) {
					$wpdb->insert( "{$p}aime_chatbot_messages", array(
						'conversation_id' => $cid,
						'sender_type'     => 'system',
						'content'         => __( 'Conversation has been closed.', 'ai-marketing-expert' ),
						'content_type'    => 'text',
						'created_at'      => $now,
					) );
				}
				break;

			case 'make_public':
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$p}aime_chatbot_conversations SET is_public = 1 WHERE id IN ({$ph})",
					...$ids
				) );
				break;

			case 'make_private':
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$p}aime_chatbot_conversations SET is_public = 0 WHERE id IN ({$ph})",
					...$ids
				) );
				break;

			default:
				return new \WP_REST_Response( array( 'message' => __( 'Unknown action.', 'ai-marketing-expert' ) ), 400 );
		}

		return new \WP_REST_Response( array(
			/* translators: %d: number of conversations affected */
			'message' => sprintf( __( '%d conversation(s) updated.', 'ai-marketing-expert' ), $count ),
		) );
	}

	/* ── EXPORT LEADS from selected conversations (Pro) ── */

	/**
	 * Build a leads CSV from the selected conversation IDs.
	 *
	 * Only conversations with a captured lead (lead_captured flag or a
	 * visitor email) are included. Phone/company come from the
	 * conversation metadata JSON written by the public lead endpoint.
	 *
	 * @param int[] $ids Conversation IDs.
	 */
	private function export_leads_csv( array $ids ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$conversations = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.visitor_name, c.visitor_email, c.metadata, c.page_url,
					c.status, c.created_at, b.name AS bot_name
			 FROM {$p}aime_chatbot_conversations c
			 LEFT JOIN {$p}aime_chatbot_bots b ON b.id = c.bot_id
			 WHERE c.id IN ({$ph})
			   AND (c.lead_captured = 1 OR (c.visitor_email IS NOT NULL AND c.visitor_email <> ''))
			 ORDER BY c.created_at DESC",
			...$ids
		) );

		if ( empty( $conversations ) ) {
			return new \WP_REST_Response( array(
				'message' => __( 'No leads found in the selected conversations.', 'ai-marketing-expert' ),
			), 400 );
		}

		$esc = static function ( $value ): string {
			return '"' . str_replace( '"', '""', (string) $value ) . '"';
		};

		$lines   = array();
		$lines[] = 'Conversation ID,Bot,Name,Email,Phone,Company,Page URL,Status,Captured At';

		foreach ( $conversations as $conv ) {
			$meta    = json_decode( $conv->metadata ?: '{}', true ) ?: array();
			$lines[] = implode( ',', array(
				(int) $conv->id,
				$esc( $conv->bot_name ),
				$esc( $conv->visitor_name ),
				$esc( $conv->visitor_email ),
				$esc( $meta['phone'] ?? '' ),
				$esc( $meta['company'] ?? '' ),
				$esc( $conv->page_url ),
				$esc( $conv->status ),
				$esc( $conv->created_at ),
			) );
		}

		return new \WP_REST_Response( array(
			'csv'      => implode( "\n", $lines ),
			'filename' => 'chatbot-leads-' . gmdate( 'Y-m-d' ) . '.csv',
			'count'    => count( $conversations ),
		) );
	}

	/* ── TOGGLE PUBLIC visibility (Pro) ──────────────── */

	public function toggle_public( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'message' => __( 'Public discussions requires Pro.', 'ai-marketing-expert' ),
			), 403 );
		}

		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		$conversation = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, is_public FROM {$p}aime_chatbot_conversations WHERE id = %d",
			$id
		) );

		if ( ! $conversation ) {
			return new \WP_REST_Response( array( 'message' => __( 'Conversation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$new_value = $conversation->is_public ? 0 : 1;
		$wpdb->update(
			"{$p}aime_chatbot_conversations",
			array( 'is_public' => $new_value ),
			array( 'id' => $id )
		);

		return new \WP_REST_Response( array(
			'is_public' => (bool) $new_value,
			'message'   => $new_value
				? __( 'Conversation is now public.', 'ai-marketing-expert' )
				: __( 'Conversation is now hidden.', 'ai-marketing-expert' ),
		) );
	}
}
