<?php
/**
 * Analytics Service — daily aggregation of chatbot statistics.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Chatbot\Services;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AnalyticsService {

	/**
	 * Aggregate daily stats for all bots on a given date.
	 *
	 * Called by the daily cleanup cron in ChatbotModule.
	 *
	 * @param string $date Date string (Y-m-d).
	 */
	public static function aggregate_daily( string $date ): void {
		global $wpdb;
		$p                   = $wpdb->prefix;
		$bots_table          = $p . 'aime_chatbot_bots';
		$conversations_table = $p . 'aime_chatbot_conversations';
		$messages_table      = $p . 'aime_chatbot_messages';
		$analytics_table     = $p . 'aime_chatbot_analytics';

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conversations_table ) );
		if ( ! $table_exists ) {
			return;
		}

		$date_start = $date . ' 00:00:00';
		$date_end   = $date . ' 23:59:59';

		// Only real chats count: the visitor must have written at least one message.
		// Bot greetings alone do not make a conversation.
		$real = "EXISTS (
			SELECT 1 FROM {$messages_table} mv
			WHERE mv.conversation_id = c.id AND mv.sender_type = 'visitor'
		)";

		// Get all bots.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily aggregation must read the current bot list.
		$bots = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i', $bots_table ) );
		if ( ! $bots ) {
			return;
		}

		foreach ( $bots as $bot_id ) {
			$bot_id = (int) $bot_id;

			// Total conversations started on this date.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily aggregation intentionally reads fresh conversation totals.
			$total_conversations = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i c WHERE c.bot_id = %d AND c.created_at BETWEEN %s AND %s AND {$real}",
				$conversations_table,
				$bot_id, $date_start, $date_end
			) );

			// Get conversation IDs for this bot on this date.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily aggregation intentionally reads fresh conversation ids.
			$conv_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT c.id FROM %i c WHERE c.bot_id = %d AND c.created_at BETWEEN %s AND %s AND {$real}",
				$conversations_table,
				$bot_id, $date_start, $date_end
			) );

			$total_messages   = 0;
			$ai_messages      = 0;
			$human_messages   = 0;
			$visitor_messages = 0;

			if ( $conv_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily aggregation intentionally reads fresh message aggregates.
				$msg_stats = $wpdb->get_row( $wpdb->prepare(
					sprintf( "SELECT
						COUNT(*) AS total_messages,
						SUM(CASE WHEN sender_type = 'ai' THEN 1 ELSE 0 END) AS ai_messages,
						SUM(CASE WHEN sender_type = 'agent' THEN 1 ELSE 0 END) AS human_messages,
						SUM(CASE WHEN sender_type = 'visitor' THEN 1 ELSE 0 END) AS visitor_messages
					 FROM %%i
					 WHERE conversation_id IN (%s) AND sender_type != 'system'", $placeholders ),
					$messages_table,
					...$conv_ids
				) );

				if ( $msg_stats ) {
					$total_messages   = (int) $msg_stats->total_messages;
					$ai_messages      = (int) $msg_stats->ai_messages;
					$human_messages   = (int) $msg_stats->human_messages;
					$visitor_messages = (int) $msg_stats->visitor_messages;
				}
			}

			// Leads captured.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily aggregation intentionally reads fresh lead totals.
			$leads_captured = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i c WHERE c.bot_id = %d AND c.lead_captured = 1 AND c.created_at BETWEEN %s AND %s AND {$real}",
				$conversations_table,
				$bot_id, $date_start, $date_end
			) );

			// Human takeovers.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily aggregation intentionally reads fresh takeover totals.
			$human_takeovers = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i c WHERE c.bot_id = %d AND c.agent_id IS NOT NULL AND c.created_at BETWEEN %s AND %s AND {$real}",
				$conversations_table,
				$bot_id, $date_start, $date_end
			) );

			// Average satisfaction.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily aggregation intentionally reads fresh satisfaction data.
			$avg_satisfaction = $wpdb->get_var( $wpdb->prepare(
				"SELECT AVG(c.satisfaction_rating) FROM %i c WHERE c.bot_id = %d AND c.satisfaction_rating IS NOT NULL AND c.created_at BETWEEN %s AND %s AND {$real}",
				$conversations_table,
				$bot_id, $date_start, $date_end
			) );

			// Unique visitors.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily aggregation intentionally reads fresh visitor totals.
			$unique_visitors = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT c.visitor_id) FROM %i c WHERE c.bot_id = %d AND c.created_at BETWEEN %s AND %s AND {$real}",
				$conversations_table,
				$bot_id, $date_start, $date_end
			) );

			// Upsert analytics row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Upsert check needs the current row state.
			$existing = $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM %i WHERE bot_id = %d AND date = %s',
				$analytics_table,
				$bot_id, $date
			) );

			$data = array(
				'bot_id'              => $bot_id,
				'date'                => $date,
				'total_conversations' => $total_conversations,
				'total_messages'      => $total_messages,
				'ai_messages'         => $ai_messages,
				'human_messages'      => $human_messages,
				'visitor_messages'    => $visitor_messages,
				'leads_captured'      => $leads_captured,
				'human_takeovers'     => $human_takeovers,
				'avg_satisfaction'    => $avg_satisfaction ? round( (float) $avg_satisfaction, 2 ) : null,
				'unique_visitors'     => $unique_visitors,
			);

			if ( $existing ) {
				$wpdb->update( $analytics_table, $data, array( 'id' => $existing ) );
			} else {
				$data['created_at'] = current_time( 'mysql', true );
				$wpdb->insert( $analytics_table, $data );
			}
		}
	}
}
