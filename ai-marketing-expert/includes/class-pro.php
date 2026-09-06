<?php
/**
 * Pro features helper.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pro {

	/**
	 * Check if a specific pro feature is available.
	 *
	 * @param string $feature Feature key (e.g., 'email_automations').
	 * @return bool
	 */
	public static function has_feature( string $feature ): bool {
		if ( ! aime_has_pro() ) {
			return false;
		}

		return (bool) apply_filters( "aime_pro_feature_{$feature}", true );
	}

	/**
	 * Get a response for when a pro feature is required.
	 *
	 * @param string $feature Feature name for display.
	 * @return \WP_Error
	 */
	public static function require_pro_error( string $feature = '' ): \WP_Error {
		$message = $feature
			? sprintf(
				/* translators: %s: Feature name */
				__( '%s is a Pro feature. Upgrade to unlock it.', 'ai-marketing-expert' ),
				$feature
			)
			: __( 'This is a Pro feature. Upgrade to unlock it.', 'ai-marketing-expert' );

		return new \WP_Error(
			'pro_required',
			$message,
			array(
				'status'  => 403,
				'pro_url' => apply_filters( 'aime_pro_url', 'https://wpthemespace.com/product/ai-marketing-expert/#aime-pricing' ),
			)
		);
	}

	/**
	 * Check pro and return error if not available.
	 * Use in REST API callbacks:
	 *   $check = Pro::gate('Automations');
	 *   if ( is_wp_error($check) ) return $check;
	 *
	 * @param string $feature Feature name.
	 * @return true|\WP_Error
	 */
	public static function gate( string $feature = '' ) {
		if ( aime_has_pro() ) {
			return true;
		}
		return self::require_pro_error( $feature );
	}

	/**
	 * Get all pro features overview.
	 *
	 * @return array
	 */
	public static function get_features_list(): array {
		return apply_filters(
			'aime_pro_features_list',
			array(
				array(
					'id'          => 'unlimited_subscribers',
					'name'        => __( 'Unlimited Subscribers', 'ai-marketing-expert' ),
					'description' => __( 'Unlock Pro automation, advanced segmentation, and other premium features.', 'ai-marketing-expert' ),
					'module'      => 'email-marketing',
				),
				array(
					'id'          => 'email_automations',
					'name'        => __( 'Email Automations', 'ai-marketing-expert' ),
					'description' => __( 'Create drip campaigns and automated sequences.', 'ai-marketing-expert' ),
					'module'      => 'email-marketing',
				),
				array(
					'id'          => 'advanced_segmentation',
					'name'        => __( 'Advanced Segmentation', 'ai-marketing-expert' ),
					'description' => __( 'Segment subscribers by behavior and custom fields.', 'ai-marketing-expert' ),
					'module'      => 'email-marketing',
				),
				array(
					'id'          => 'ab_testing',
					'name'        => __( 'A/B Testing', 'ai-marketing-expert' ),
					'description' => __( 'Test subject lines and content variations.', 'ai-marketing-expert' ),
					'module'      => 'email-marketing',
				),
				array(
					'id'          => 'drag_drop_builder',
					'name'        => __( 'Drag & Drop Email Builder', 'ai-marketing-expert' ),
					'description' => __( 'Visual email builder with drag and drop blocks.', 'ai-marketing-expert' ),
					'module'      => 'email-marketing',
				),
				array(
					'id'          => 'custom_fields',
					'name'        => __( 'Custom Subscriber Fields', 'ai-marketing-expert' ),
					'description' => __( 'Add custom data fields to subscribers.', 'ai-marketing-expert' ),
					'module'      => 'email-marketing',
				),
				array(
					'id'          => 'advanced_analytics',
					'name'        => __( 'Advanced Analytics', 'ai-marketing-expert' ),
					'description' => __( 'Detailed reports, heatmaps, and engagement analytics.', 'ai-marketing-expert' ),
					'module'      => 'email-marketing',
				),
				array(
					'id'          => 'unlimited_templates',
					'name'        => __( 'Unlimited Templates', 'ai-marketing-expert' ),
					'description' => __( 'Access 20+ premium email templates.', 'ai-marketing-expert' ),
					'module'      => 'email-marketing',
				),
				// Social Media.
				array(
					'id'          => 'unlimited_social_accounts',
					'name'        => __( 'Unlimited Social Accounts', 'ai-marketing-expert' ),
					'description' => __( 'Connect unlimited social media accounts.', 'ai-marketing-expert' ),
					'module'      => 'social-media',
				),
				array(
					'id'          => 'unlimited_social_posts',
					'name'        => __( 'Unlimited Social Posts', 'ai-marketing-expert' ),
					'description' => __( 'Publish unlimited posts per month.', 'ai-marketing-expert' ),
					'module'      => 'social-media',
				),
				array(
					'id'          => 'social_visual_calendar',
					'name'        => __( 'Visual Calendar', 'ai-marketing-expert' ),
					'description' => __( 'Plan and manage posts with a visual calendar.', 'ai-marketing-expert' ),
					'module'      => 'social-media',
				),
				array(
					'id'          => 'social_repurpose',
					'name'        => __( 'Content Repurposing', 'ai-marketing-expert' ),
					'description' => __( 'Repurpose blog articles into social media posts with AI.', 'ai-marketing-expert' ),
					'module'      => 'social-media',
				),
				array(
					'id'          => 'social_ai_image_caption',
					'name'        => __( 'AI Image Captions', 'ai-marketing-expert' ),
					'description' => __( 'Generate captions for images using AI.', 'ai-marketing-expert' ),
					'module'      => 'social-media',
				),
				array(
					'id'          => 'social_approval_workflow',
					'name'        => __( 'Approval Workflow', 'ai-marketing-expert' ),
					'description' => __( 'Review and approve posts before publishing.', 'ai-marketing-expert' ),
					'module'      => 'social-media',
				),
				array(
					'id'          => 'unlimited_social_ai',
					'name'        => __( 'Unlimited AI Captions', 'ai-marketing-expert' ),
					'description' => __( 'Generate unlimited AI captions and hashtags.', 'ai-marketing-expert' ),
					'module'      => 'social-media',
				),
				array(
					'id'          => 'social_bulk_scheduling',
					'name'        => __( 'Bulk Scheduling', 'ai-marketing-expert' ),
					'description' => __( 'Schedule multiple posts at once with bulk actions.', 'ai-marketing-expert' ),
					'module'      => 'social-media',
				),
			)
		);
	}
}
