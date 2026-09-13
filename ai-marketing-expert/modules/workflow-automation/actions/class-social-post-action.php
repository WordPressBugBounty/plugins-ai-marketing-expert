<?php
/**
 * Social Post action — AI caption + a social post row (draft or scheduled) that
 * the Social Media module's existing 5-minute publisher cron will process.
 *
 * Only rows with status = 'scheduled' are auto-published by that cron; 'draft'
 * rows are left untouched — keeping unattended sends opt-in and safe.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions;

use WPSpace\AiMarketingExpert\Modules\SocialMedia\Services\AiSocialService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SocialPostAction extends BaseAction {

	public static function run( array $config, array $context ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$account_id = (int) ( $config['account_id'] ?? 0 );
		$platform   = sanitize_text_field( (string) ( $config['platform'] ?? 'facebook' ) );
		$topic      = self::rotated_topic( $config, $context );
		$tone       = self::tone( $context );
		$schedule   = ! empty( $config['schedule'] );

		if ( '' === $topic ) {
			return self::fail( __( 'No topic provided for the social post.', 'ai-marketing-expert' ) );
		}

		// Validate the account exists and is connected.
		$account = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, platform, status, name FROM {$p}aime_social_accounts WHERE id = %d",
			$account_id
		) );
		if ( ! $account ) {
			return self::fail( __( 'Connected social account not found.', 'ai-marketing-expert' ) );
		}
		if ( 'connected' !== $account->status ) {
			/* translators: %s: social media account name. */
			return self::fail( sprintf( __( 'Account "%s" is not connected.', 'ai-marketing-expert' ), $account->name ) );
		}
		$platform = $account->platform ?: $platform;

		// Generate the caption. Context = what upstream steps produced
		// (article title when chained after Blog Post), not an arbitrary step.
		$ai      = new AiSocialService();
		$caption = $ai->generate_caption(
			$platform,
			$topic,
			$tone,
			self::ancestor_context( $context, 600 ),
			self::brand_voice_system_prompt( $context )
		);
		if ( empty( $caption['success'] ) ) {
			return self::fail( $caption['error'] ?? __( 'Caption generation failed.', 'ai-marketing-expert' ) );
		}
		$content = (string) $caption['content'];

		// Resolve media URLs. If config provides media, use it.
		$media_urls = array();
		if ( ! empty( $config['media_urls'] ) && is_array( $config['media_urls'] ) ) {
			$media_urls = array_filter( array_map( 'esc_url_raw', $config['media_urls'] ) );
		} elseif ( ! empty( $config['image_url'] ) ) {
			$media_urls[] = esc_url_raw( (string) $config['image_url'] );
		}

		// Check context for an upstream generated blog post's featured image.
		$source_wp_post_id = self::resolve_from_context( $context, 'wp_post_id', 'generate_blog_post' );
		if ( empty( $media_urls ) && ! empty( $source_wp_post_id ) ) {
			$thumb_url = get_the_post_thumbnail_url( (int) $source_wp_post_id, 'full' );
			if ( $thumb_url ) {
				$media_urls[] = esc_url_raw( $thumb_url );
			} else {
				// Fallback to first attached image if no featured thumbnail set yet.
				$attached = get_attached_media( 'image', (int) $source_wp_post_id );
				if ( ! empty( $attached ) ) {
					$first_img = reset( $attached );
					$img_src   = wp_get_attachment_image_url( $first_img->ID, 'full' );
					if ( $img_src ) {
						$media_urls[] = esc_url_raw( $img_src );
					}
				}
			}
		}

		// Check if an upstream step produced a generic image_url (e.g. from an image generation step).
		if ( empty( $media_urls ) ) {
			$upstream_img = self::resolve_from_context( $context, 'image_url' );
			if ( ! empty( $upstream_img ) ) {
				$media_urls[] = esc_url_raw( $upstream_img );
			}
		}

		// Instagram Graph API strictly requires at least one image.
		if ( empty( $media_urls ) && 'instagram' === $platform ) {
			return self::fail( __( 'Instagram publishing requires an image. Ensure the upstream blog post has a featured image or attach a media URL.', 'ai-marketing-expert' ) );
		}

		// Insert a social post row for the existing publisher cron.
		$now    = current_time( 'mysql', true );
		$status = $schedule ? 'scheduled' : 'draft';
		// Schedule ~5 minutes out so the publisher cron picks it up on its next tick.
		$scheduled_at = $schedule ? gmdate( 'Y-m-d H:i:s', time() + 300 ) : null;

		$post_data = array(
			'account_id'   => $account_id,
			'content'      => $content,
			'media_urls'   => ! empty( $media_urls ) ? wp_json_encode( array_values( $media_urls ) ) : null,
			'status'       => $status,
			'scheduled_at' => $scheduled_at,
			'ai_generated' => 1,
			'source_type'  => 'workflow',
			'source_id'    => (int) ( $context['workflow_id'] ?? 0 ),
			'created_at'   => $now,
			'updated_at'   => $now,
		);

		$ok = $wpdb->insert( "{$p}aime_social_posts", $post_data );

		if ( ! $ok ) {
			return self::fail( __( 'Social post could not be saved.', 'ai-marketing-expert' ) );
		}

		$post_id = (int) $wpdb->insert_id;
		$preview = $schedule
			? sprintf( /* translators: %s: platform */ __( 'Scheduled %s post (publisher cron will send it).', 'ai-marketing-expert' ), $platform )
			: sprintf( /* translators: %s: platform */ __( 'Saved %s post as draft for review.', 'ai-marketing-expert' ), $platform );

		$reference = array( 'social_post_id' => $post_id, 'status' => $status, 'link' => self::module_link( 'social' ) );
		// Carry the promoted article's identifiers downstream (tokens/conditions)
		// when this step follows a Blog Post step.
		if ( '' !== $source_wp_post_id ) {
			$reference['source_post_id'] = (int) $source_wp_post_id;
		}
		$source_edit_url = self::resolve_from_context( $context, 'edit_url', 'generate_blog_post' );
		if ( '' !== $source_edit_url ) {
			$reference['source_edit_url'] = $source_edit_url;
		}

		return self::ok( $preview, $reference );
	}
}
