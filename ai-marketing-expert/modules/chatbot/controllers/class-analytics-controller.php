<?php
/**
 * Analytics Controller — chatbot analytics & reporting.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AnalyticsController {

	/* ── OVERVIEW — summary stats ────────────────────── */

	public function overview( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p                   = $wpdb->prefix;
		$analytics_table     = $p . 'aime_chatbot_analytics';
		$conversations_table = $p . 'aime_chatbot_conversations';
		$bots_table          = $p . 'aime_chatbot_bots';
		$knowledge_table     = $p . 'aime_chatbot_knowledge';
		$messages_table      = $p . 'aime_chatbot_messages';
		$days                = min( 365, max( 1, $request->get_param( 'days' ) ?: 30 ) );
		$bot_id              = absint( $request->get_param( 'bot_id' ) );
		$since               = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// Only real chats count: the visitor must have written at least one message.
		// Bot greetings alone do not make a conversation.
		$real = "EXISTS (
			SELECT 1 FROM {$messages_table} mv
			WHERE mv.conversation_id = c.id AND mv.sender_type = 'visitor'
		)";

		if ( $bot_id ) {
			$agg = $wpdb->get_row( $wpdb->prepare(
				"SELECT
					COALESCE(SUM(total_conversations), 0) AS total_conversations,
					COALESCE(SUM(total_messages), 0) AS total_messages,
					COALESCE(SUM(leads_captured), 0) AS leads_captured,
					COALESCE(SUM(human_takeovers), 0) AS human_takeovers,
					COALESCE(SUM(unique_visitors), 0) AS unique_visitors,
					COALESCE(AVG(avg_satisfaction), 0) AS avg_satisfaction,
					COALESCE(AVG(avg_response_time_ms), 0) AS avg_response_time
				 FROM %i
				 WHERE date >= %s AND bot_id = %d",
				$analytics_table,
				$since,
				$bot_id
			) );

			$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c WHERE c.status IN ('active','human_takeover') AND c.bot_id = %d AND {$real}", $conversations_table, $bot_id ) );
			$human_takeover_active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c WHERE c.status = 'human_takeover' AND c.bot_id = %d AND {$real}", $conversations_table, $bot_id ) );
			$active_status_conversations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c WHERE c.status = 'active' AND c.bot_id = %d AND {$real}", $conversations_table, $bot_id ) );
			$closed_conversations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c WHERE c.status = 'closed' AND c.bot_id = %d AND {$real}", $conversations_table, $bot_id ) );
		} else {
			$agg = $wpdb->get_row( $wpdb->prepare(
				"SELECT
					COALESCE(SUM(total_conversations), 0) AS total_conversations,
					COALESCE(SUM(total_messages), 0) AS total_messages,
					COALESCE(SUM(leads_captured), 0) AS leads_captured,
					COALESCE(SUM(human_takeovers), 0) AS human_takeovers,
					COALESCE(SUM(unique_visitors), 0) AS unique_visitors,
					COALESCE(AVG(avg_satisfaction), 0) AS avg_satisfaction,
					COALESCE(AVG(avg_response_time_ms), 0) AS avg_response_time
				 FROM %i
				 WHERE date >= %s",
				$analytics_table,
				$since
			) );

			$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c WHERE c.status IN ('active','human_takeover') AND {$real}", $conversations_table ) );
			$human_takeover_active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c WHERE c.status = 'human_takeover' AND {$real}", $conversations_table ) );
			$active_status_conversations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c WHERE c.status = 'active' AND {$real}", $conversations_table ) );
			$closed_conversations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c WHERE c.status = 'closed' AND {$real}", $conversations_table ) );
		}

		$total_bots = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $bots_table ) );

		if ( $bot_id ) {
			$knowledge_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'active' AND bot_id = %d", $knowledge_table, $bot_id ) );
		} else {
			$knowledge_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'active'", $knowledge_table ) );
		}

		$month_start = gmdate( 'Y-m-01 00:00:00' );
		$conversations_this_month = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c WHERE c.created_at >= %s AND {$real}", $conversations_table, $month_start ) );

		$bots_list = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.name, b.status, (b.status = 'active') AS is_active,
						(SELECT COUNT(*) FROM %i c WHERE c.bot_id = b.id AND {$real}) AS conversation_count
				 FROM %i b ORDER BY b.created_at DESC",
				$conversations_table,
				$bots_table
			)
		);

		if ( $bot_id ) {
			$recent_conversations = $wpdb->get_results( $wpdb->prepare(
				"SELECT c.id, c.visitor_name, c.visitor_email, c.status, c.created_at,
						b.name AS bot_name,
						(SELECT COUNT(*) FROM %i m WHERE m.conversation_id = c.id AND m.sender_type != 'system') AS message_count
				 FROM %i c
				 LEFT JOIN %i b ON b.id = c.bot_id
				 WHERE c.bot_id = %d AND {$real}
				 ORDER BY c.created_at DESC LIMIT 10",
				$messages_table,
				$conversations_table,
				$bots_table,
				$bot_id
			) );
		} else {
			$recent_conversations = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.id, c.visitor_name, c.visitor_email, c.status, c.created_at,
							b.name AS bot_name,
							(SELECT COUNT(*) FROM %i m WHERE m.conversation_id = c.id AND m.sender_type != 'system') AS message_count
					 FROM %i c
					 LEFT JOIN %i b ON b.id = c.bot_id
					 WHERE {$real}
					 ORDER BY c.created_at DESC LIMIT 10",
					$messages_table,
					$conversations_table,
					$bots_table
				)
			);
		}

		return new \WP_REST_Response( array(
			'period'                  => $days,
			'total_conversations'     => (int) ( $agg->total_conversations ?? 0 ),
			'total_messages'          => (int) ( $agg->total_messages ?? 0 ),
			'leads_captured'          => (int) ( $agg->leads_captured ?? 0 ),
			'human_takeovers'         => (int) ( $agg->human_takeovers ?? 0 ),
			'unique_visitors'         => (int) ( $agg->unique_visitors ?? 0 ),
			'avg_satisfaction'        => round( (float) ( $agg->avg_satisfaction ?? 0 ), 1 ),
			'avg_response_time_ms'    => (int) ( $agg->avg_response_time ?? 0 ),
			'active_conversations'    => $active,
			'active_status_conversations' => $active_status_conversations,
			'human_takeover_active'   => $human_takeover_active,
			'closed_conversations'    => $closed_conversations,
			'total_bots'              => $total_bots,
			'knowledge_count'         => $knowledge_count,
			'conversations_this_month' => $conversations_this_month,
			'bots'                    => $bots_list ?: array(),
			'recent_conversations'    => $recent_conversations ?: array(),
		) );
	}

	/* ── CONVERSATION TRENDS — daily breakdown ───────── */

	public function conversation_trends( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p               = $wpdb->prefix;
		$analytics_table = $p . 'aime_chatbot_analytics';
		$days            = min( 365, max( 1, $request->get_param( 'days' ) ?: 30 ) );
		$bot_id          = absint( $request->get_param( 'bot_id' ) );
		$since           = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		if ( $bot_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT date, total_conversations, total_messages, leads_captured,
						human_takeovers, unique_visitors, avg_satisfaction
				 FROM %i
				 WHERE date >= %s AND bot_id = %d
				 ORDER BY date ASC",
				$analytics_table,
				$since,
				$bot_id
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT date, total_conversations, total_messages, leads_captured,
						human_takeovers, unique_visitors, avg_satisfaction
				 FROM %i
				 WHERE date >= %s
				 ORDER BY date ASC",
				$analytics_table,
				$since
			) );
		}

		return new \WP_REST_Response( $rows ?: array() );
	}
}
