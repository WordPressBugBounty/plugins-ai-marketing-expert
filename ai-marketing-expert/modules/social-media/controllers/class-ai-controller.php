<?php
/**
 * AI Controller — AI-powered social content generation.
 *
 * @package WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\SocialMedia\Controllers;

use WPSpace\AiMarketingExpert\Pro;
use WPSpace\AiMarketingExpert\AiProvider;
use WPSpace\AiMarketingExpert\Modules\SocialMedia\Services\AiSocialService;
use WPSpace\AiMarketingExpert\Modules\SocialMedia\SocialMediaModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiController {

	/**
	 * Generate a caption for a post.
	 */
	public function generate_caption( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit check.
		if ( ! aime_has_pro() ) {
			$monthly = SocialMediaModule::get_monthly_ai_count();
			$limits  = aime_free_limits();
			$limit   = $limits['social_ai_captions'] ?? 30;
			if ( $monthly >= $limit ) {
				return new \WP_REST_Response( array(
					'message'       => __( 'Monthly AI caption limit reached. Upgrade to Pro for unlimited.', 'ai-marketing-expert' ),
					'limit_reached' => true,
				), 403 );
			}
		}

		$platform = sanitize_text_field( $request->get_param( 'platform' ) ?: 'facebook' );
		$topic    = sanitize_textarea_field( $request->get_param( 'topic' ) ?: '' );
		$tone     = sanitize_text_field( $request->get_param( 'tone' ) ?: 'professional' );
		$context  = sanitize_textarea_field( $request->get_param( 'context' ) ?: '' );

		if ( empty( $topic ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Topic is required.', 'ai-marketing-expert' ) ), 400 );
		}

		$service = new AiSocialService();
		$result  = $service->generate_caption( $platform, $topic, $tone, $context );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => $result['error'] ), 500 );
		}

		return new \WP_REST_Response( $result );
	}

	/**
	 * Generate hashtags.
	 */
	public function generate_hashtags( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit (shared with captions).
		if ( ! aime_has_pro() ) {
			$monthly = SocialMediaModule::get_monthly_ai_count();
			$limits  = aime_free_limits();
			$limit   = $limits['social_ai_captions'] ?? 30;
			if ( $monthly >= $limit ) {
				return new \WP_REST_Response( array(
					'message'       => __( 'Monthly AI caption limit reached. Upgrade to Pro for unlimited.', 'ai-marketing-expert' ),
					'limit_reached' => true,
				), 403 );
			}
		}

		$content  = sanitize_textarea_field( $request->get_param( 'content' ) ?: '' );
		$platform = sanitize_text_field( $request->get_param( 'platform' ) ?: 'facebook' );
		$count    = min( 30, max( 1, absint( $request->get_param( 'count' ) ) ) ) ?: 10;

		if ( empty( $content ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Content is required.', 'ai-marketing-expert' ) ), 400 );
		}

		$service = new AiSocialService();
		$result  = $service->generate_hashtags( $content, $platform, $count );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => $result['error'] ), 500 );
		}

		return new \WP_REST_Response( $result );
	}

	/**
	 * Repurpose content from Content Generator articles — Pro only.
	 */
	public function repurpose( \WP_REST_Request $request ): \WP_REST_Response {
		$gate = Pro::gate( 'AI Social Repurpose' );
		if ( is_wp_error( $gate ) ) {
			return new \WP_REST_Response( array(
				'message'      => $gate->get_error_message(),
				'pro_required' => true,
			), 403 );
		}

		$article_id  = absint( $request->get_param( 'article_id' ) );
		$wp_post_id  = absint( $request->get_param( 'wp_post_id' ) );
		$platform    = sanitize_text_field( $request->get_param( 'platform' ) ?: 'facebook' );
		$format      = sanitize_text_field( $request->get_param( 'format' ) ?: 'summary' );

		if ( ! $article_id && ! $wp_post_id ) {
			return new \WP_REST_Response( array( 'message' => __( 'Article ID or WordPress Post ID is required.', 'ai-marketing-expert' ) ), 400 );
		}

		$service = new AiSocialService();

		if ( $wp_post_id ) {
			$result = $service->repurpose_wp_post( $wp_post_id, $platform, $format );
		} else {
			$result = $service->repurpose_article( $article_id, $platform, $format );
		}

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => $result['error'] ), 500 );
		}

		return new \WP_REST_Response( $result );
	}

	/**
	 * Generate a caption for an image — Pro only.
	 */
	public function image_caption( \WP_REST_Request $request ): \WP_REST_Response {
		$gate = Pro::gate( 'AI Image Captions' );
		if ( is_wp_error( $gate ) ) {
			return new \WP_REST_Response( array(
				'message'      => $gate->get_error_message(),
				'pro_required' => true,
			), 403 );
		}

		$image_url = esc_url_raw( $request->get_param( 'image_url' ) ?: '' );
		$platform  = sanitize_text_field( $request->get_param( 'platform' ) ?: 'facebook' );
		$tone      = sanitize_text_field( $request->get_param( 'tone' ) ?: 'professional' );

		if ( empty( $image_url ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Image URL is required.', 'ai-marketing-expert' ) ), 400 );
		}

		$service = new AiSocialService();
		$result  = $service->generate_image_caption( $image_url, $platform, $tone );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => $result['error'] ), 500 );
		}

		return new \WP_REST_Response( $result );
	}

	/**
	 * Generate an image using AI from a text prompt.
	 */
	public function generate_image( \WP_REST_Request $request ): \WP_REST_Response {
		// Free limit check (shared with captions).
		if ( ! aime_has_pro() ) {
			$monthly = SocialMediaModule::get_monthly_ai_count();
			$limits  = aime_free_limits();
			$limit   = $limits['social_ai_captions'] ?? 30;
			if ( $monthly >= $limit ) {
				return new \WP_REST_Response( array(
					'message'       => __( 'Monthly AI limit reached. Upgrade to Pro for unlimited.', 'ai-marketing-expert' ),
					'limit_reached' => true,
				), 403 );
			}
		}

		$prompt      = sanitize_textarea_field( $request->get_param( 'prompt' ) ?: '' );
		$content     = sanitize_textarea_field( $request->get_param( 'content' ) ?: '' );
		$platform    = sanitize_text_field( $request->get_param( 'platform' ) ?: 'facebook' );

		if ( empty( $prompt ) && empty( $content ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'A prompt or post content is required to generate an image.', 'ai-marketing-expert' ) ), 400 );
		}

		// Build a descriptive image prompt from the input.
		$image_prompt = $prompt;
		if ( empty( $image_prompt ) && ! empty( $content ) ) {
			$image_prompt = sprintf(
				'Create a visually appealing social media image for %s that represents this post: %s. '
				. 'The image should be clean, professional, eye-catching, and suitable for social media. '
				. 'Do NOT include any text or words in the image.',
				$platform,
				wp_trim_words( $content, 60, '...' )
			);
		}

		$result = AiProvider::generate_image( $image_prompt, __( 'Social Media Post Image', 'ai-marketing-expert' ) );

		if ( ! $result['success'] ) {
			return new \WP_REST_Response( array( 'message' => $result['message'] ), 500 );
		}

		// Track AI usage for monthly limit.
		$month_key = 'aime_social_ai_' . gmdate( 'Y_m' );
		$count     = (int) get_option( $month_key, 0 );
		update_option( $month_key, $count + 1, false );

		return new \WP_REST_Response( array(
			'success'       => true,
			'image_url'     => $result['image_url'],
			'attachment_id' => $result['attachment_id'],
			'provider'      => $result['provider'] ?? '',
			'model'         => $result['model'] ?? '',
		) );
	}
}
