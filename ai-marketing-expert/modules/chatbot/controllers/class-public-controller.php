<?php
/**
 * Public Controller — visitor-facing chatbot endpoints (no auth).
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

namespace WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers;

use WPSpace\AiMarketingExpert\Modules\Chatbot\Services\ChatService;
use WPSpace\AiMarketingExpert\Modules\Chatbot\Services\LeadCaptureService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PublicController {

	private const CHAT_TOKEN_TTL = 172800;

	/* ── START conversation ──────────────────────────── */

	public function start( \WP_REST_Request $request ): \WP_REST_Response {
		$params     = $request->get_json_params();
		$bot_id     = absint( $params['bot_id'] ?? 0 );
		$visitor_id = $this->sanitize_limited_text( $params['visitor_id'] ?? '', 128 );
		$token      = $this->sanitize_limited_text( $params['conversation_token'] ?? '', 300 );

		if ( ! $bot_id || ! $visitor_id ) {
			return new \WP_REST_Response( array( 'message' => __( 'Bot ID and visitor ID are required.', 'ai-marketing-expert' ) ), 400 );
		}

		$rate_limited = $this->check_rate_limit( 'start_' . $bot_id, 20, MINUTE_IN_SECONDS );
		if ( $rate_limited ) {
			return $rate_limited;
		}

		global $wpdb;
		$p                   = $wpdb->prefix;
		$bots_table          = $p . 'aime_chatbot_bots';
		$conversations_table = $p . 'aime_chatbot_conversations';
		$messages_table      = $p . 'aime_chatbot_messages';

		// Verify bot exists and is active.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Visitor start flow needs the current bot state.
		$bot = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM %i WHERE id = %d AND status = 'active'",
			$bots_table,
			$bot_id
		) );

		if ( ! $bot ) {
			return new \WP_REST_Response( array( 'message' => __( 'Chatbot is not available.', 'ai-marketing-expert' ) ), 404 );
		}

		// Check free conversation limit.
		if ( ! aime_has_pro() ) {
			$monthly = \WPSpace\AiMarketingExpert\Modules\Chatbot\ChatbotModule::get_monthly_conversation_count();
			if ( aime_limit_reached( 'chatbot_conversations_monthly', $monthly ) ) {
				return new \WP_REST_Response( array(
					'message'       => __( 'The chatbot is currently unavailable. Please try again later.', 'ai-marketing-expert' ),
					'limit_reached' => true,
				), 429 );
			}
		}

		// Check for existing active conversation for this visitor + bot.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Visitor start flow needs the current open conversation, if any.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM %i
			 WHERE bot_id = %d AND visitor_id = %s AND status IN ('active','human_takeover')
			 ORDER BY created_at DESC LIMIT 1",
			$conversations_table,
			$bot_id,
			$visitor_id
		) );

		// Only resume an existing conversation when the caller proves ownership with
		// a valid HMAC token. visitor_id alone is a client-generated, leak-prone value,
		// so without a valid token we fall through and start a fresh conversation
		// rather than exposing another visitor's transcript.
		if ( $existing && $token && $this->validate_conversation_token( (int) $existing->id, $visitor_id, $token ) ) {
			// Resume existing conversation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Visitor resume flow needs current conversation messages.
			$messages = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, sender_type, content, content_type, metadata, is_read, created_at
				 FROM %i
				 WHERE conversation_id = %d
				 ORDER BY created_at ASC",
				$messages_table,
				$existing->id
			) );

			foreach ( $messages as &$msg ) {
				$msg->metadata = json_decode( $msg->metadata ?: '{}', true );
			}
			unset( $msg );

			return new \WP_REST_Response( array(
				'conversation_id' => (int) $existing->id,
				'conversation_token' => $this->create_conversation_token( (int) $existing->id, $visitor_id ),
				'resumed'         => true,
				'messages'        => $messages,
			) );
		}

		// Create new conversation.
		$now = current_time( 'mysql', true );

		$wpdb->insert( $conversations_table, array(
			'bot_id'     => $bot_id,
			'visitor_id' => $visitor_id,
			'visitor_ip' => $this->get_request_ip(),
			'status'     => 'active',
			'source'     => $this->sanitize_limited_text( $params['source'] ?? 'widget', 40 ),
			'page_url'   => esc_url_raw( $params['page_url'] ?? '' ),
			'user_agent' => $this->sanitize_limited_text( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 255 ),
			'started_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		) );

		$conversation_id = (int) $wpdb->insert_id;

		// Insert welcome message.
		$welcome = $bot->welcome_message ?: __( 'Hi! How can I help you today?', 'ai-marketing-expert' );

		$wpdb->insert( $messages_table, array(
			'conversation_id' => $conversation_id,
			'sender_type'     => 'ai',
			'content'         => $welcome,
			'content_type'    => 'text',
			'created_at'      => $now,
		) );

		$welcome_msg_id = (int) $wpdb->insert_id;

		$this->maybe_notify_admin( $conversation_id, $bot, $params );

		return new \WP_REST_Response( array(
			'conversation_id' => $conversation_id,
			'conversation_token' => $this->create_conversation_token( $conversation_id, $visitor_id ),
			'resumed'         => false,
			'messages'        => array(
				array(
					'id'           => $welcome_msg_id,
					'sender_type'  => 'ai',
					'content'      => $welcome,
					'content_type' => 'text',
					'metadata'     => new \stdClass(),
					'created_at'   => $now,
				),
			),
		), 201 );
	}

	/* ── ADMIN NOTIFICATION — email on new conversation ─ */

	private function maybe_notify_admin( int $conversation_id, object $bot, array $params ): void {
		$settings = get_option( 'aime_chatbot_settings', array() );

		if ( empty( $settings['notify_new_chat'] ) ) {
			return;
		}

		$to = sanitize_email( $settings['notify_email'] ?? '' );
		if ( ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$page_url  = esc_url_raw( $params['page_url'] ?? '' );

		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] New chat conversation started', 'ai-marketing-expert' ), $site_name );

		$lines = array(
			/* translators: %s: site name. */
			sprintf( __( 'A visitor just started a new chat conversation on %s.', 'ai-marketing-expert' ), $site_name ),
			'',
			/* translators: %s: chatbot name. */
			sprintf( __( 'Bot: %s', 'ai-marketing-expert' ), $bot->name ?? '' ),
			/* translators: %d: conversation ID. */
			sprintf( __( 'Conversation ID: %d', 'ai-marketing-expert' ), $conversation_id ),
			/* translators: %s: date and time. */
			sprintf( __( 'Started at: %s', 'ai-marketing-expert' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
		);

		if ( $page_url ) {
			/* translators: %s: page URL. */
			$lines[] = sprintf( __( 'Page: %s', 'ai-marketing-expert' ), $page_url );
		}

		$lines[] = '';
		/* translators: %s: admin URL of the conversations screen. */
		$lines[] = sprintf( __( 'View the conversation: %s', 'ai-marketing-expert' ), admin_url( 'admin.php?page=ai-marketing-expert-chatbot#conversation/' . $conversation_id ) );

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/* ── SEND MESSAGE — visitor sends, gets AI response ─ */

	public function message( \WP_REST_Request $request ): \WP_REST_Response {
		$params          = $request->get_json_params();
		$conversation_id = absint( $params['conversation_id'] ?? 0 );
		$visitor_id      = $this->sanitize_limited_text( $params['visitor_id'] ?? '', 128 );
		$token           = $this->sanitize_limited_text( $params['conversation_token'] ?? '', 300 );
		$settings        = get_option( 'aime_chatbot_settings', array() );
		$max_length      = max( 50, min( 4000, absint( $settings['max_message_length'] ?? 500 ) ) );
		$content         = $this->sanitize_limited_textarea( $params['content'] ?? '', $max_length );

		if ( ! $conversation_id || ! $visitor_id || ! $content ) {
			return new \WP_REST_Response( array( 'message' => __( 'Missing required fields.', 'ai-marketing-expert' ) ), 400 );
		}

		$token_error = $this->validate_conversation_token_response( $conversation_id, $visitor_id, $token );
		if ( $token_error ) {
			return $token_error;
		}

		$rate_limited = $this->check_rate_limit( 'message_' . $conversation_id, 30, MINUTE_IN_SECONDS );
		if ( $rate_limited ) {
			return $rate_limited;
		}

		$rate_limited = $this->check_rate_limit( 'message_global', 60, MINUTE_IN_SECONDS );
		if ( $rate_limited ) {
			return $rate_limited;
		}

		$rate_limited = $this->check_rate_limit( 'message_daily', 200, 86400 );
		if ( $rate_limited ) {
			return $rate_limited;
		}

		global $wpdb;
		$p                   = $wpdb->prefix;
		$bots_table          = $p . 'aime_chatbot_bots';
		$conversations_table = $p . 'aime_chatbot_conversations';
		$messages_table      = $p . 'aime_chatbot_messages';

		// Validate conversation belongs to visitor.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Visitor messaging needs the current conversation and bot state.
		$conversation = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.*, b.system_prompt, b.banned_words, b.knowledge_config, b.name AS bot_name
			 FROM %i c
			 LEFT JOIN %i b ON b.id = c.bot_id
			 WHERE c.id = %d AND c.visitor_id = %s",
			$conversations_table,
			$bots_table,
			$conversation_id,
			$visitor_id
		) );

		if ( ! $conversation ) {
			return new \WP_REST_Response( array( 'message' => __( 'Conversation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		if ( 'closed' === $conversation->status ) {
			return new \WP_REST_Response( array( 'message' => __( 'This conversation has ended.', 'ai-marketing-expert' ) ), 400 );
		}

		// Rate limiting.
		$rate_limit = absint( $settings['rate_limit_per_minute'] ?? 20 );
		if ( $rate_limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rate limiting needs a fresh one-minute message count.
			$recent_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE conversation_id = %d AND sender_type = 'visitor'
				   AND created_at > DATE_SUB( UTC_TIMESTAMP(), INTERVAL 1 MINUTE )",
				$messages_table,
				$conversation_id
			) );
			if ( $recent_count >= $rate_limit ) {
				return new \WP_REST_Response( array(
					'message' => __( 'You are sending messages too quickly. Please wait a moment.', 'ai-marketing-expert' ),
				), 429 );
			}
		}

		// Banned words check.
		if ( ! empty( $conversation->banned_words ) ) {
			$banned = array_map( 'trim', explode( "\n", $conversation->banned_words ) );
			$lower  = strtolower( $content );
			foreach ( $banned as $word ) {
				if ( $word && false !== strpos( $lower, strtolower( $word ) ) ) {
					return new \WP_REST_Response( array(
						'message' => __( 'Your message contains content that is not allowed.', 'ai-marketing-expert' ),
					), 400 );
				}
			}
		}

		// Save visitor message.
		$now = current_time( 'mysql', true );
		$wpdb->insert( $messages_table, array(
			'conversation_id' => $conversation_id,
			'sender_type'     => 'visitor',
			'sender_id'       => $visitor_id,
			'content'         => $content,
			'content_type'    => 'text',
			'created_at'      => $now,
		) );

		$visitor_msg_id = (int) $wpdb->insert_id;

		// Update conversation timestamp.
		$wpdb->update(
			$conversations_table,
			array( 'updated_at' => $now ),
			array( 'id' => $conversation_id )
		);

		// If human takeover, don't generate AI response.
		if ( 'human_takeover' === $conversation->status ) {
			return new \WP_REST_Response( array(
				'visitor_message_id' => $visitor_msg_id,
				'ai_response'        => null,
				'mode'               => 'human_takeover',
			) );
		}

		// Site-wide, IP-independent daily cap on AI generations. The per-conversation
		// and per-IP limits above do not constrain distributed abuse that rotates both
		// the conversation and the source IP, so a global budget bounds provider cost.
		$ai_budget_key = 'aime_chatbot_ai_daily_' . gmdate( 'Ymd' );
		$ai_budget     = (int) apply_filters( 'aime_chatbot_daily_ai_budget', 2000 );
		$ai_used       = (int) get_transient( $ai_budget_key );

		if ( $ai_budget > 0 && $ai_used >= $ai_budget ) {
			$busy = __( "I'm experiencing high demand right now. Please try again in a little while.", 'ai-marketing-expert' );
			$wpdb->insert( $messages_table, array(
				'conversation_id' => $conversation_id,
				'sender_type'     => 'ai',
				'content'         => $busy,
				'content_type'    => 'text',
				'created_at'      => current_time( 'mysql', true ),
			) );

			return new \WP_REST_Response( array(
				'visitor_message_id' => $visitor_msg_id,
				'mode'               => 'ai',
				'ai_response'        => array(
					'id'           => (int) $wpdb->insert_id,
					'sender_type'  => 'ai',
					'content'      => $busy,
					'content_type' => 'text',
					'metadata'     => new \stdClass(),
					'created_at'   => current_time( 'mysql', true ),
					'actions'      => array(),
				),
			), 429 );
		}

		if ( $ai_budget > 0 ) {
			set_transient( $ai_budget_key, $ai_used + 1, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		}

		// Generate AI response.
		$chat_service = new ChatService();
		$ai_result    = $chat_service->process_message( $conversation, $content );

		$response_data = array(
			'visitor_message_id' => $visitor_msg_id,
			'mode'               => 'ai',
		);

		if ( $ai_result['success'] ) {
			$response_data['ai_response'] = array(
				'id'           => $ai_result['message_id'],
				'sender_type'  => 'ai',
				'content'      => $ai_result['content'],
				'content_type' => 'text',
				'metadata'     => $ai_result['metadata'] ?? new \stdClass(),
				'created_at'   => $ai_result['created_at'],
				'actions'      => $ai_result['actions'] ?? array(),
			);
		} else {
			// Return a generic error message.
			$fallback = __( "I'm sorry, I'm having trouble processing your request right now. Please try again in a moment.", 'ai-marketing-expert' );
			$wpdb->insert( "{$p}aime_chatbot_messages", array(
				'conversation_id' => $conversation_id,
				'sender_type'     => 'ai',
				'content'         => $fallback,
				'content_type'    => 'text',
				'created_at'      => current_time( 'mysql', true ),
			) );

			$response_data['ai_response'] = array(
				'id'           => (int) $wpdb->insert_id,
				'sender_type'  => 'ai',
				'content'      => $fallback,
				'content_type' => 'text',
				'metadata'     => new \stdClass(),
				'created_at'   => current_time( 'mysql', true ),
				'actions'      => array(),
			);
		}

		return new \WP_REST_Response( $response_data );
	}

	/* ── SUBMIT LEAD ─────────────────────────────────── */

	public function submit_lead( \WP_REST_Request $request ): \WP_REST_Response {
		$params          = $request->get_json_params();
		$conversation_id = absint( $params['conversation_id'] ?? 0 );
		$visitor_id      = $this->sanitize_limited_text( $params['visitor_id'] ?? '', 128 );
		$token           = $this->sanitize_limited_text( $params['conversation_token'] ?? '', 300 );
		$name            = $this->sanitize_limited_text( $params['name'] ?? '', 120 );
		$email           = sanitize_email( $params['email'] ?? '' );
		$phone           = $this->sanitize_limited_text( $params['phone'] ?? '', 60 );
		$company         = $this->sanitize_limited_text( $params['company'] ?? '', 120 );

		if ( ! $conversation_id || ! $visitor_id || ! $email ) {
			return new \WP_REST_Response( array( 'message' => __( 'Conversation ID, visitor ID, and email are required.', 'ai-marketing-expert' ) ), 400 );
		}

		$token_error = $this->validate_conversation_token_response( $conversation_id, $visitor_id, $token );
		if ( $token_error ) {
			return $token_error;
		}

		$rate_limited = $this->check_rate_limit( 'lead_' . $conversation_id, 5, 10 * MINUTE_IN_SECONDS );
		if ( $rate_limited ) {
			return $rate_limited;
		}

		if ( ! is_email( $email ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Invalid email address.', 'ai-marketing-expert' ) ), 400 );
		}

		global $wpdb;
		$p = $wpdb->prefix;

		// Verify conversation.
		$conversation = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.*, b.lead_capture_config
			 FROM {$p}aime_chatbot_conversations c
			 LEFT JOIN {$p}aime_chatbot_bots b ON b.id = c.bot_id
			 WHERE c.id = %d AND c.visitor_id = %s",
			$conversation_id,
			$visitor_id
		) );

		if ( ! $conversation ) {
			return new \WP_REST_Response( array( 'message' => __( 'Conversation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$now = current_time( 'mysql', true );

		// Update conversation with lead info.
		$lead_meta = array();
		if ( $phone ) {
			$lead_meta['phone'] = $phone;
		}
		if ( $company ) {
			$lead_meta['company'] = $company;
		}

		$update_data = array(
			'visitor_name'  => $name,
			'visitor_email' => $email,
			'lead_captured' => 1,
			'updated_at'    => $now,
		);

		if ( ! empty( $lead_meta ) ) {
			$existing_meta = json_decode( $conversation->metadata ?: '{}', true ) ?: array();
			$update_data['metadata'] = wp_json_encode( array_merge( $existing_meta, $lead_meta ) );
		}

		$wpdb->update(
			"{$p}aime_chatbot_conversations",
			$update_data,
			array( 'id' => $conversation_id )
		);

		// Insert system message.
		$wpdb->insert( "{$p}aime_chatbot_messages", array(
			'conversation_id' => $conversation_id,
			'sender_type'     => 'system',
			'content'         => sprintf(
				/* translators: %s: visitor email */
				__( 'Contact information shared: %s', 'ai-marketing-expert' ),
				$email
			),
			'content_type'    => 'text',
			'created_at'      => $now,
		) );

		// Process lead via service.
		$lead_service = new LeadCaptureService();
		$lead_service->process_lead( $conversation, $name, $email );

		return new \WP_REST_Response( array(
			'message' => __( 'Thank you! Your information has been saved.', 'ai-marketing-expert' ),
		) );
	}

	/* ── POLL for new messages ──────────────────────── */

	public function poll( \WP_REST_Request $request ): \WP_REST_Response {
		$conversation_id = absint( $request->get_param( 'conversation_id' ) );
		$visitor_id      = $this->sanitize_limited_text( $request->get_param( 'visitor_id' ), 128 );
		$token           = $this->sanitize_limited_text( $request->get_param( 'conversation_token' ), 300 );
		$after           = absint( $request->get_param( 'after' ) );

		if ( ! $conversation_id || ! $visitor_id ) {
			return new \WP_REST_Response( array( 'message' => __( 'Missing required fields.', 'ai-marketing-expert' ) ), 400 );
		}

		$token_error = $this->validate_conversation_token_response( $conversation_id, $visitor_id, $token );
		if ( $token_error ) {
			return $token_error;
		}

		$rate_limited = $this->check_rate_limit( 'poll_' . $conversation_id, 120, MINUTE_IN_SECONDS );
		if ( $rate_limited ) {
			return $rate_limited;
		}

		global $wpdb;
		$p = $wpdb->prefix;

		// Verify visitor owns this conversation.
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}aime_chatbot_conversations WHERE id = %d AND visitor_id = %s",
			$conversation_id,
			$visitor_id
		) );

		if ( ! $exists ) {
			return new \WP_REST_Response( array( 'message' => __( 'Conversation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$messages = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, sender_type, sender_id, content, content_type, metadata, created_at
			 FROM {$p}aime_chatbot_messages
			 WHERE conversation_id = %d AND id > %d AND sender_type != 'visitor'
			 ORDER BY created_at ASC",
			$conversation_id,
			$after
		) );

		foreach ( $messages as &$msg ) {
			$msg->metadata = json_decode( $msg->metadata ?: '{}', true );
		}
		unset( $msg );

		// Get conversation status.
		$status = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$p}aime_chatbot_conversations WHERE id = %d",
			$conversation_id
		) );

		// Read receipt: highest visitor message ID that the agent has read.
		$read_receipt_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(id) FROM {$p}aime_chatbot_messages
			 WHERE conversation_id = %d AND sender_type = 'visitor' AND is_read = 1",
			$conversation_id
		) );

		$response = new \WP_REST_Response( array(
			'messages'        => $messages ?: array(),
			'status'          => $status,
			'read_receipt_id' => $read_receipt_id,
		) );

		// Prevent caching — poll must always return fresh data.
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );

		return $response;
	}

	private function check_rate_limit( string $scope, int $limit, int $window ) {
		$key  = 'aime_chatbot_public_' . md5( $scope . '|' . $this->get_request_ip() );
		$now  = time();
		$data = get_transient( $key );

		// Start a fresh fixed window if none exists or the current one has elapsed.
		// Storing the window's reset timestamp lets us cap the transient TTL to the
		// time remaining in the window, so incrementing the counter never extends it
		// (a plain set_transient() rewrites the full TTL on every call, which would
		// turn this into a never-resetting counter under continuous polling).
		if ( ! is_array( $data ) || empty( $data['reset'] ) || $data['reset'] <= $now ) {
			$data = array(
				'count' => 0,
				'reset' => $now + $window,
			);
		}

		if ( $data['count'] >= $limit ) {
			return new \WP_REST_Response( array(
				'message' => __( 'Too many requests. Please try again later.', 'ai-marketing-expert' ),
			), 429 );
		}

		$data['count']++;
		$remaining = max( 1, $data['reset'] - $now );
		set_transient( $key, $data, $remaining );
		return null;
	}

	private function create_conversation_token( int $conversation_id, string $visitor_id ): string {
		$issued = time();
		$data   = $conversation_id . '|' . $visitor_id . '|' . $issued;
		$mac    = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
		return strtr( base64_encode( $data . '|' . $mac ), '+/=', '-_~' );
	}

	private function validate_conversation_token_response( int $conversation_id, string $visitor_id, string $token ): ?\WP_REST_Response {
		if ( ! $token || ! $this->validate_conversation_token( $conversation_id, $visitor_id, $token ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Invalid conversation token.', 'ai-marketing-expert' ) ), 403 );
		}

		return null;
	}

	private function validate_conversation_token( int $conversation_id, string $visitor_id, string $token ): bool {
		$decoded = base64_decode( strtr( $token, '-_~', '+/=' ), true );
		if ( ! $decoded ) {
			return false;
		}

		$parts = explode( '|', $decoded );
		if ( count( $parts ) !== 4 ) {
			return false;
		}

		$token_conversation_id = absint( $parts[0] );
		$token_visitor_id      = (string) $parts[1];
		$issued                = absint( $parts[2] );
		$mac                   = (string) $parts[3];

		if ( $token_conversation_id !== $conversation_id || ! hash_equals( $token_visitor_id, $visitor_id ) ) {
			return false;
		}

		if ( $issued < time() - self::CHAT_TOKEN_TTL || $issued > time() + MINUTE_IN_SECONDS ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $token_conversation_id . '|' . $token_visitor_id . '|' . $issued, wp_salt( 'auth' ) );
		return hash_equals( $expected, $mac );
	}

	private function get_request_ip(): string {
		return $this->sanitize_limited_text( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ), 45 );
	}

	private function sanitize_limited_text( $value, int $max_length ): string {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );
		return substr( $value, 0, $max_length );
	}

	private function sanitize_limited_textarea( $value, int $max_length ): string {
		$value = sanitize_textarea_field( wp_unslash( (string) $value ) );
		return substr( $value, 0, $max_length );
	}
}
