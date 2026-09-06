<?php
/**
 * Widget Service — configuration helpers for the chatbot frontend widget.
 *
 * Provides theme presets, default configurations, and validation utilities.
 *
 * @package WPSpace\AiMarketingExpert\Modules\Chatbot\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Chatbot\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WidgetService {

	/**
	 * Get available theme presets.
	 *
	 * @return array
	 */
	public static function get_theme_presets(): array {
		$themes = array(
			'default' => array(
				'id'             => 'default',
				'name'           => __( 'Default', 'ai-marketing-expert' ),
				'primaryColor'   => '#4F46E5',
				'headerBg'       => '#4F46E5',
				'headerText'     => '#FFFFFF',
				'bubbleBg'       => '#4F46E5',
				'bubbleText'     => '#FFFFFF',
				'aiBubbleBg'     => '#F3F4F6',
				'aiBubbleText'   => '#1F2937',
				'userBubbleBg'   => '#4F46E5',
				'userBubbleText' => '#FFFFFF',
				'bgColor'        => '#FFFFFF',
				'inputBg'        => '#F9FAFB',
				'borderRadius'   => 16,
				'position'       => 'bottom-right',
				'avatarUrl'      => '',
				'fontFamily'     => 'inherit',
			),
			'modern'  => array(
				'id'             => 'modern',
				'name'           => __( 'Modern Dark', 'ai-marketing-expert' ),
				'primaryColor'   => '#6366F1',
				'headerBg'       => '#1E1B4B',
				'headerText'     => '#E0E7FF',
				'bubbleBg'       => '#6366F1',
				'bubbleText'     => '#FFFFFF',
				'aiBubbleBg'     => '#312E81',
				'aiBubbleText'   => '#C7D2FE',
				'userBubbleBg'   => '#6366F1',
				'userBubbleText' => '#FFFFFF',
				'bgColor'        => '#1E1B4B',
				'inputBg'        => '#312E81',
				'borderRadius'   => 20,
				'position'       => 'bottom-right',
				'avatarUrl'      => '',
				'fontFamily'     => 'inherit',
			),
			'minimal' => array(
				'id'             => 'minimal',
				'name'           => __( 'Minimal Clean', 'ai-marketing-expert' ),
				'primaryColor'   => '#059669',
				'headerBg'       => '#FFFFFF',
				'headerText'     => '#065F46',
				'bubbleBg'       => '#059669',
				'bubbleText'     => '#FFFFFF',
				'aiBubbleBg'     => '#ECFDF5',
				'aiBubbleText'   => '#064E3B',
				'userBubbleBg'   => '#059669',
				'userBubbleText' => '#FFFFFF',
				'bgColor'        => '#FFFFFF',
				'inputBg'        => '#F0FDF4',
				'borderRadius'   => 8,
				'position'       => 'bottom-right',
				'avatarUrl'      => '',
				'fontFamily'     => 'inherit',
			),
		);

		return apply_filters( 'aime_chatbot_theme_presets', $themes );
	}

	/**
	 * Get a specific theme preset.
	 *
	 * @param string $theme_id Theme ID.
	 * @return array Theme config or default.
	 */
	public static function get_theme( string $theme_id ): array {
		$themes = self::get_theme_presets();
		return $themes[ $theme_id ] ?? $themes['default'];
	}

	/**
	 * Merge user's custom theme config with the base preset.
	 *
	 * @param string $theme_id      Base theme ID.
	 * @param array  $custom_config User overrides.
	 * @return array Merged theme config.
	 */
	public static function merge_theme_config( string $theme_id, array $custom_config = array() ): array {
		$base = self::get_theme( $theme_id );
		return array_merge( $base, $custom_config );
	}

	/**
	 * Get default lead capture configuration.
	 *
	 * @return array
	 */
	public static function get_default_lead_config(): array {
		return array(
			'enabled'         => false,
			'trigger'         => 'after_messages',  // start, after_messages, ai_intent.
			'trigger_count'   => 3,                  // After N messages.
			'fields'          => array( 'name', 'email' ),
			'list_id'         => 0,
			'required_fields' => array( 'email' ),
			'heading'         => __( "Before we continue, I'd love to stay in touch!", 'ai-marketing-expert' ),
			'submit_text'     => __( 'Continue Chat', 'ai-marketing-expert' ),
			'tags'            => array(),
		);
	}
}
