<?php
/**
 * Settings Controller — module settings management.
 *
 * @package WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers;

use WPSpace\AiMarketingExpert\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsController {

	const OPTION_KEY = 'aime_social-media_settings';

	const DEFAULTS = array(
		'default_timezone'   => '',
		'default_hashtags'   => '',
		'auto_hashtags'      => false,
		'approval_workflow'  => false,
		'oauth_mode'         => 'proxy',
		'facebook_app_id'    => '',
		'facebook_app_secret' => '',
		'instagram_app_id'   => '',
		'instagram_app_secret' => '',
		'x_api_key'          => '',
		'x_api_secret'       => '',
		'x_access_token'     => '',
		'x_access_secret'    => '',
	);

	/**
	 * Get all settings.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$saved = get_option( self::OPTION_KEY, array() );
		$settings = wp_parse_args( $saved, self::DEFAULTS );

		// Mask sensitive values for display.
		$mask_keys = array(
			'facebook_app_secret', 'instagram_app_secret',
			'x_api_secret', 'x_access_token', 'x_access_secret',
		);

		foreach ( $mask_keys as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$settings[ $key . '_set' ] = true;
				$settings[ $key ] = str_repeat( '•', 8 );
			} else {
				$settings[ $key . '_set' ] = false;
			}
		}

		// Include WordPress timezone for frontend display.
		$settings['wp_timezone'] = wp_timezone_string();

		return new \WP_REST_Response( $settings );
	}

	/**
	 * Save settings.
	 */
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		$input   = $request->get_params();
		$current = get_option( self::OPTION_KEY, array() );
		$saved   = wp_parse_args( $current, self::DEFAULTS );

		// Text fields.
		$text_fields = array(
			'default_timezone',
			'default_hashtags',
			'oauth_mode',
			'facebook_app_id',
			'instagram_app_id',
			'x_api_key',
		);

		foreach ( $text_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$saved[ $field ] = sanitize_text_field( $input[ $field ] );
			}
		}

		// Booleans.
		$bool_fields = array( 'auto_hashtags', 'approval_workflow' );
		foreach ( $bool_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$saved[ $field ] = (bool) $input[ $field ];
			}
		}

		// Sensitive fields: only overwrite if non-masked value provided.
		$secret_fields = array(
			'facebook_app_secret',
			'instagram_app_secret',
			'x_api_secret',
			'x_access_token',
			'x_access_secret',
		);

		foreach ( $secret_fields as $field ) {
			if ( isset( $input[ $field ] ) && strpos( $input[ $field ], '•' ) === false && $input[ $field ] !== '' ) {
				$saved[ $field ] = Encryption::encrypt( sanitize_text_field( $input[ $field ] ) );
			}
		}

		// Validate oauth_mode.
		if ( ! in_array( $saved['oauth_mode'], array( 'proxy', 'manual' ), true ) ) {
			$saved['oauth_mode'] = 'proxy';
		}

		update_option( self::OPTION_KEY, $saved, false );
		aime_clear_settings_cache( array( self::OPTION_KEY ) );

		return new \WP_REST_Response( array( 'message' => __( 'Settings saved.', 'ai-marketing-expert' ) ) );
	}
}
