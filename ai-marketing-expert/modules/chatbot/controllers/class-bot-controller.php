<?php
/**
 * Bot Controller — CRUD for chatbot instances.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotController {

	private const STATUSES = array( 'active', 'inactive' );
	private const THEMES = array( 'default', 'modern', 'minimal' );

	/* ── LIST all bots ───────────────────────────────── */

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p                   = $wpdb->prefix;
		$bots_table          = $p . 'aime_chatbot_bots';
		$conversations_table = $p . 'aime_chatbot_conversations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin bot listing should reflect current conversation totals.
		$bots = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, name, status, theme_id, is_pro, created_at, updated_at
			 FROM %i ORDER BY created_at DESC',
			$bots_table
		) );

		// Attach conversation counts.
		foreach ( $bots as &$bot ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Per-bot counts are intentional fresh admin metrics.
			$bot->conversation_count = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE bot_id = %d',
				$conversations_table,
				$bot->id
			) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Per-bot counts are intentional fresh admin metrics.
			$bot->active_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE bot_id = %d AND status = 'active'",
				$conversations_table,
				$bot->id
			) );
		}
		unset( $bot );

		return new \WP_REST_Response( $bots ?: array() );
	}

	/* ── SHOW single bot ─────────────────────────────── */

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p               = $wpdb->prefix;
		$bots_table      = $p . 'aime_chatbot_bots';
		$knowledge_table = $p . 'aime_chatbot_knowledge';
		$id              = absint( $request->get_param( 'id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin detail view needs the current bot record.
		$bot = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			$bots_table,
			$id
		) );

		if ( ! $bot ) {
			return new \WP_REST_Response( array( 'message' => __( 'Bot not found.', 'ai-marketing-expert' ) ), 404 );
		}

		// Decode JSON fields.
		$bot->theme_config        = json_decode( $bot->theme_config ?: '{}', true );
		$bot->lead_capture_config = json_decode( $bot->lead_capture_config ?: '{}', true );
		$bot->knowledge_config    = json_decode( $bot->knowledge_config ?: '{}', true );
		$bot->business_hours      = json_decode( $bot->business_hours ?: '{}', true );
		$bot->page_rules          = json_decode( $bot->page_rules ?: '[]', true );

		// Knowledge stats.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin detail view needs the current knowledge count.
		$bot->knowledge_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE bot_id = %d AND status = 'active'",
			$knowledge_table,
			$id
		) );

		return new \WP_REST_Response( $bot );
	}

	/* ── CREATE bot ──────────────────────────────────── */

	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit check.
		if ( ! aime_has_pro() ) {
			global $wpdb;
			$p          = $wpdb->prefix;
			$bots_table = $p . 'aime_chatbot_bots';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Limit enforcement needs the current bot count.
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $bots_table ) );
			$limit = aime_free_limits()['chatbot_bots'] ?? 1;
			if ( $count >= $limit ) {
				return new \WP_REST_Response( array(
					'message'       => __( 'Bot limit reached. Upgrade to Pro for unlimited bots.', 'ai-marketing-expert' ),
					'limit_reached' => true,
				), 403 );
			}
		}

		global $wpdb;
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql', true );

		$params = $request->get_json_params();

		$params = $this->sanitize_free_params( $params );
		$status = sanitize_key( $params['status'] ?? 'active' );
		$theme  = sanitize_key( $params['theme_id'] ?? 'default' );

		$data = array(
			'name'                => sanitize_text_field( $params['name'] ?? __( 'My Chatbot', 'ai-marketing-expert' ) ),
			'status'              => in_array( $status, self::STATUSES, true ) ? $status : 'active',
			'system_prompt'       => sanitize_textarea_field( $params['system_prompt'] ?? '' ),
			'welcome_message'     => sanitize_textarea_field( $params['welcome_message'] ?? __( 'Hi! How can I help you today?', 'ai-marketing-expert' ) ),
			'theme_id'            => in_array( $theme, self::THEMES, true ) ? $theme : 'default',
			'theme_config'        => wp_json_encode( $this->sanitize_theme_config( $params['theme_config'] ?? array() ) ),
			'lead_capture_config' => wp_json_encode( $params['lead_capture_config'] ?? new \stdClass() ),
			'knowledge_config'    => wp_json_encode( $params['knowledge_config'] ?? new \stdClass() ),
			'offline_message'     => sanitize_textarea_field( $params['offline_message'] ?? '' ),
			'business_hours'      => wp_json_encode( $params['business_hours'] ?? new \stdClass() ),
			'banned_words'        => sanitize_textarea_field( $params['banned_words'] ?? '' ),
			'page_rules'          => wp_json_encode( $params['page_rules'] ?? new \stdClass() ),
			'is_pro'              => 0,
			'created_at'          => $now,
			'updated_at'          => $now,
		);

		$result = $wpdb->insert( "{$p}aime_chatbot_bots", $data );

		if ( false === $result ) {
			return new \WP_REST_Response( array( 'message' => __( 'Failed to create bot.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( array(
			'id'      => (int) $wpdb->insert_id,
			'message' => __( 'Bot created successfully.', 'ai-marketing-expert' ),
		), 201 );
	}

	/* ── UPDATE bot ──────────────────────────────────── */

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p          = $wpdb->prefix;
		$bots_table = $p . 'aime_chatbot_bots';
		$id         = absint( $request->get_param( 'id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off existence check before update.
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $bots_table, $id ) );
		if ( ! $existing ) {
			return new \WP_REST_Response( array( 'message' => __( 'Bot not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$params = $this->sanitize_free_params( $request->get_json_params() );
		$data   = array( 'updated_at' => current_time( 'mysql', true ) );

		$text_fields = array( 'name' );
		foreach ( $text_fields as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$data[ $field ] = sanitize_text_field( $params[ $field ] );
			}
		}

		if ( isset( $params['status'] ) ) {
			$status = sanitize_key( $params['status'] );
			$data['status'] = in_array( $status, self::STATUSES, true ) ? $status : 'inactive';
		}

		if ( isset( $params['theme_id'] ) ) {
			$theme = sanitize_key( $params['theme_id'] );
			$data['theme_id'] = in_array( $theme, self::THEMES, true ) ? $theme : 'default';
		}

		$textarea_fields = array( 'system_prompt', 'welcome_message', 'offline_message', 'banned_words' );
		foreach ( $textarea_fields as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$data[ $field ] = sanitize_textarea_field( $params[ $field ] );
			}
		}

		$json_fields = array( 'theme_config', 'lead_capture_config', 'knowledge_config', 'business_hours', 'page_rules' );
		foreach ( $json_fields as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$data[ $field ] = wp_json_encode( 'theme_config' === $field ? $this->sanitize_theme_config( $params[ $field ] ) : $params[ $field ] );
			}
		}

		$wpdb->update( $bots_table, $data, array( 'id' => $id ) );

		return new \WP_REST_Response( array( 'message' => __( 'Bot updated successfully.', 'ai-marketing-expert' ) ) );
	}

	private function sanitize_free_params( array $params ): array {
		if ( aime_has_pro() ) {
			return $params;
		}

		$params['theme_id'] = 'default';
		$params['theme_config'] = array(
			'position'      => sanitize_key( $params['theme_config']['position'] ?? 'bottom-right' ),
			'offset_y'      => $this->clamp_offset( $params['theme_config']['offset_y'] ?? 0 ),
			'offset_x'      => $this->clamp_offset( $params['theme_config']['offset_x'] ?? 0 ),
			'primary_color' => '#4f46e5',
			'bubble_icon'   => 'chat',
		);

		$lead_config = $params['lead_capture_config'] ?? array();
		if ( ! is_array( $lead_config ) ) {
			$lead_config = array();
		}
		$fields = $lead_config['fields'] ?? array( 'email', 'name' );
		if ( ! is_array( $fields ) ) {
			$fields = array( 'email', 'name' );
		}

		$free_trigger = sanitize_key( $lead_config['trigger'] ?? 'after_messages' );

		$params['lead_capture_config'] = array(
			'enabled'       => ! empty( $lead_config['enabled'] ),
			'trigger'       => in_array( $free_trigger, array( 'after_messages', 'start' ), true ) ? $free_trigger : 'after_messages',
			'trigger_count' => max( 1, absint( $lead_config['trigger_count'] ?? 3 ) ),
			'fields'        => array_values( array_intersect( array( 'email', 'name' ), array_map( 'sanitize_key', $fields ) ) ),
			'heading'       => sanitize_text_field( $lead_config['heading'] ?? __( 'Get in touch', 'ai-marketing-expert' ) ),
		);

		$params['page_rules'] = array();
		$params['business_hours'] = new \stdClass();
		$params['offline_message'] = '';
		$params['banned_words'] = '';
		$params['system_prompt'] = '';
		$knowledge_config = $params['knowledge_config'] ?? array();
		if ( ! is_array( $knowledge_config ) ) {
			$knowledge_config = array();
		}

		$params['knowledge_config'] = array(
			'max_history_messages'  => 20,
			'response_tone'         => sanitize_key( $knowledge_config['response_tone'] ?? 'friendly' ),
			'response_length'       => 'short',
			'response_style'        => 'confident',
			'sales_focused'         => false,
			'custom_response_style' => sanitize_textarea_field( $knowledge_config['custom_response_style'] ?? '' ),
		);

		return $params;
	}

	private function sanitize_theme_config( $theme_config ): array {
		if ( ! is_array( $theme_config ) ) {
			$theme_config = array();
		}

		if ( isset( $theme_config['position'] ) ) {
			$theme_config['position'] = sanitize_key( $theme_config['position'] );
		}

		$theme_config['offset_y'] = $this->clamp_offset( $theme_config['offset_y'] ?? 0 );
		$theme_config['offset_x'] = $this->clamp_offset( $theme_config['offset_x'] ?? 0 );

		return $theme_config;
	}

	private function clamp_offset( $value ): int {
		return max( -200, min( 200, (int) $value ) );
	}

	/* ── DELETE bot ───────────────────────────────────── */

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p                   = $wpdb->prefix;
		$bots_table          = $p . 'aime_chatbot_bots';
		$conversations_table = $p . 'aime_chatbot_conversations';
		$messages_table      = $p . 'aime_chatbot_messages';
		$knowledge_table     = $p . 'aime_chatbot_knowledge';
		$analytics_table     = $p . 'aime_chatbot_analytics';
		$id                  = absint( $request->get_param( 'id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off existence check before delete.
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $bots_table, $id ) );
		if ( ! $existing ) {
			return new \WP_REST_Response( array( 'message' => __( 'Bot not found.', 'ai-marketing-expert' ) ), 404 );
		}

		// Get conversation IDs for this bot.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete workflow needs the current conversation ids.
		$conv_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM %i WHERE bot_id = %d',
			$conversations_table,
			$id
		) );

		if ( $conv_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );

			// Delete messages.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bulk delete is intentional cleanup for the removed bot.
			$wpdb->query( $wpdb->prepare(
				sprintf( 'DELETE FROM %%i WHERE conversation_id IN (%s)', $placeholders ),
				$messages_table,
				...$conv_ids
			) );

			// Delete conversations.
			$wpdb->delete( $conversations_table, array( 'bot_id' => $id ) );
		}

		// Delete knowledge.
		$wpdb->delete( $knowledge_table, array( 'bot_id' => $id ) );

		// Delete analytics.
		$wpdb->delete( $analytics_table, array( 'bot_id' => $id ) );

		// Delete bot.
		$wpdb->delete( $bots_table, array( 'id' => $id ) );

		return new \WP_REST_Response( array( 'message' => __( 'Bot deleted successfully.', 'ai-marketing-expert' ) ) );
	}

	/* ── DUPLICATE bot (Pro) ─────────────────────────── */

	public function duplicate( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'message' => __( 'Duplicating bots requires Pro.', 'ai-marketing-expert' ),
			), 403 );
		}

		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );

		$original = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}aime_chatbot_bots WHERE id = %d", $id ), ARRAY_A );
		if ( ! $original ) {
			return new \WP_REST_Response( array( 'message' => __( 'Bot not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$now = current_time( 'mysql', true );
		unset( $original['id'] );
		$original['name']       = $original['name'] . ' ' . __( '(Copy)', 'ai-marketing-expert' );
		$original['status']     = 'inactive';
		$original['created_at'] = $now;
		$original['updated_at'] = $now;

		$wpdb->insert( "{$p}aime_chatbot_bots", $original );
		$new_id = (int) $wpdb->insert_id;

		// Duplicate knowledge.
		$knowledge_items = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$p}aime_chatbot_knowledge WHERE bot_id = %d",
			$id
		), ARRAY_A );

		foreach ( $knowledge_items as $item ) {
			unset( $item['id'] );
			$item['bot_id']     = $new_id;
			$item['created_at'] = $now;
			$item['updated_at'] = $now;
			$wpdb->insert( "{$p}aime_chatbot_knowledge", $item );
		}

		return new \WP_REST_Response( array(
			'id'      => $new_id,
			'message' => __( 'Bot duplicated successfully.', 'ai-marketing-expert' ),
		), 201 );
	}
}
