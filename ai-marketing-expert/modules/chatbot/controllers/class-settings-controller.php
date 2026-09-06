<?php
/**
 * Settings Controller — Chatbot module settings.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\Chatbot\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsController {

	private const OPTION_KEY = 'aime_chatbot_settings';

	private const DEFAULTS = array(
		'global_system_prompt'      => '',
		'default_welcome_message'   => 'Hi! How can I help you today?',
		'default_theme'             => 'default',
		'enable_sound_notification' => true,
		'poll_interval_seconds'     => 5,
		'max_message_length'        => 500,
		'consent_message'           => 'We use cookies and chat data to provide you with the best support experience. By starting this chat, you consent to our collection and use of this data in accordance with our Privacy Policy. You can end the chat at any time.',
		'gdpr_enabled'              => false,
		'data_retention_days'       => 90,
		'rate_limit_per_minute'     => 20,
		'enable_typing_indicator'   => true,
		'enable_read_receipts'      => true,
		'notify_new_chat'           => false,
		'notify_email'              => '',
	);

	private const THEMES = array( 'default', 'modern', 'minimal' );

	/* ── GET settings ────────────────────────────────── */

	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = get_option( self::OPTION_KEY, array() );
		$merged   = array_merge( self::DEFAULTS, $settings );

		// Read-only context for the UI: default notification recipient.
		$merged['admin_email'] = get_option( 'admin_email' );

		return new \WP_REST_Response( $merged );
	}

	/* ── SAVE settings ───────────────────────────────── */

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$current = get_option( self::OPTION_KEY, array() );
		$params  = $request->get_json_params();

		if ( ! aime_has_pro() ) {
			$params['poll_interval_seconds'] = self::DEFAULTS['poll_interval_seconds'];
			$params['max_message_length'] = self::DEFAULTS['max_message_length'];
			$params['data_retention_days'] = self::DEFAULTS['data_retention_days'];
			$params['rate_limit_per_minute'] = self::DEFAULTS['rate_limit_per_minute'];
			$params['enable_read_receipts'] = self::DEFAULTS['enable_read_receipts'];
		}

		$text_fields = array(
			'global_system_prompt',
			'default_welcome_message',
			'consent_message',
		);
		foreach ( $text_fields as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$current[ $field ] = sanitize_textarea_field( $params[ $field ] );
			}
		}

		if ( isset( $params['default_theme'] ) ) {
			$theme = sanitize_key( $params['default_theme'] );
			$current['default_theme'] = in_array( $theme, self::THEMES, true ) ? $theme : self::DEFAULTS['default_theme'];
		}

		$int_fields = array(
			'poll_interval_seconds',
			'max_message_length',
			'data_retention_days',
			'rate_limit_per_minute',
		);
		foreach ( $int_fields as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$current[ $field ] = $this->sanitize_int_setting( $field, $params[ $field ] );
			}
		}

		$bool_fields = array(
			'enable_sound_notification',
			'gdpr_enabled',
			'enable_typing_indicator',
			'enable_read_receipts',
			'notify_new_chat',
		);
		foreach ( $bool_fields as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$current[ $field ] = (bool) $params[ $field ];
			}
		}

		if ( isset( $params['notify_email'] ) ) {
			$email = sanitize_email( $params['notify_email'] );
			// Empty means "use the site admin email"; anything else must be valid.
			$current['notify_email'] = is_email( $email ) ? $email : '';
		}

		update_option( self::OPTION_KEY, $current, false );
		// Chatbot settings affect the public widget — purge page caches too.
		aime_clear_settings_cache( array( self::OPTION_KEY ), true );

		$merged = array_merge( self::DEFAULTS, $current );
		// Read-only context for the UI: default notification recipient.
		$merged['admin_email'] = get_option( 'admin_email' );

		return new \WP_REST_Response( array(
			'message'  => __( 'Settings saved.', 'ai-marketing-expert' ),
			'settings' => $merged,
		) );
	}

	private function sanitize_int_setting( string $field, $value ): int {
		$value = absint( $value );
		$limits = array(
			'poll_interval_seconds' => array( 2, 300 ),
			'max_message_length'    => array( 50, 4000 ),
			'data_retention_days'   => array( 0, 3650 ),
			'rate_limit_per_minute' => array( 0, 120 ),
		);

		if ( ! isset( $limits[ $field ] ) ) {
			return $value;
		}

		return max( $limits[ $field ][0], min( $limits[ $field ][1], $value ) );
	}
}
