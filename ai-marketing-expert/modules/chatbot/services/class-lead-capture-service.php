<?php
/**
 * Lead Capture Service — processes captured leads and fires integration hooks.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Chatbot\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LeadCaptureService {

	/**
	 * Process a lead captured via the chatbot widget.
	 *
	 * @param object $conversation Conversation row (with lead_capture_config from bot).
	 * @param string $name         Visitor name.
	 * @param string $email        Visitor email.
	 */
	public function process_lead( object $conversation, string $name, string $email ): void {
		$lead_config = json_decode( $conversation->lead_capture_config ?? '{}', true ) ?: array();

		$lead_data = array(
			'email'      => $email,
			'first_name' => $name,
			'source'     => 'chatbot',
			'list_id'    => $lead_config['list_id'] ?? 0,
			'tags'       => array( 'chatbot-lead' ),
			'metadata'   => array(
				'bot_id'          => (int) $conversation->bot_id,
				'conversation_id' => (int) $conversation->id,
				'page_url'        => $conversation->page_url ?? '',
			),
		);

		// Pro: add configurable tags.
		if ( aime_has_pro() && ! empty( $lead_config['tags'] ) ) {
			$lead_data['tags'] = array_merge( $lead_data['tags'], (array) $lead_config['tags'] );
		}

		/**
		 * Fires when a lead is captured via the chatbot.
		 *
		 * The Email Marketing module (and any other module) can listen to this
		 * action to create/update subscribers, assign lists, apply tags, etc.
		 *
		 * @param array $lead_data Lead information.
		 */
		do_action( 'aime_chatbot_lead_captured', $lead_data );

		// Fire generic analytics event.
		do_action( 'aime_analytics_event', array(
			'module'     => 'chatbot',
			'event_type' => 'lead_captured',
			'data'       => $lead_data,
		) );

		aime_log(
			sprintf( 'Chatbot lead captured: %s (conversation #%d)', $email, $conversation->id ),
			'info',
			'chatbot'
		);
	}
}
