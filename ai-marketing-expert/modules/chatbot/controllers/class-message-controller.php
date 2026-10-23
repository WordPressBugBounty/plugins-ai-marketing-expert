<?php
/**
 * Message Controller — admin agent message sending (human takeover).
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MessageController {

	/* ── AGENT SEND — admin sends message in live chat ── */

	public function agent_send( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'message' => __( 'Sending messages as an agent requires Pro.', 'ai-marketing-expert' ),
			), 403 );
		}

		global $wpdb;
		$p                   = $wpdb->prefix;
		$conversations_table = $p . 'aime_chatbot_conversations';
		$messages_table      = $p . 'aime_chatbot_messages';
		$id                  = absint( $request->get_param( 'id' ) );
		$params              = $request->get_json_params();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off status check before agent send.
		$conversation = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, status FROM %i WHERE id = %d',
			$conversations_table,
			$id
		) );

		if ( ! $conversation ) {
			return new \WP_REST_Response( array( 'message' => __( 'Conversation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		if ( 'closed' === $conversation->status ) {
			return new \WP_REST_Response( array( 'message' => __( 'Cannot send to a closed conversation.', 'ai-marketing-expert' ) ), 400 );
		}

		$now      = current_time( 'mysql', true );
		$agent_id = get_current_user_id();
		$content  = sanitize_textarea_field( $params['content'] ?? '' );

		if ( empty( $content ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Message content is required.', 'ai-marketing-expert' ) ), 400 );
		}

		// If not in takeover mode, switch to it first.
		if ( 'human_takeover' !== $conversation->status ) {
			$wpdb->update(
				$conversations_table,
				array(
					'status'     => 'human_takeover',
					'agent_id'   => $agent_id,
					'updated_at' => $now,
				),
				array( 'id' => $id )
			);
		}

		$wpdb->insert( $messages_table, array(
			'conversation_id' => $id,
			'sender_type'     => 'agent',
			'sender_id'       => (string) $agent_id,
			'content'         => $content,
			'content_type'    => 'text',
			'created_at'      => $now,
		) );

		$msg_id = (int) $wpdb->insert_id;

		// Update conversation timestamp.
		$wpdb->update(
			$conversations_table,
			array( 'updated_at' => $now ),
			array( 'id' => $id )
		);

		return new \WP_REST_Response( array(
			'id'         => $msg_id,
			'message'    => __( 'Message sent.', 'ai-marketing-expert' ),
			'created_at' => $now,
		) );
	}
}
