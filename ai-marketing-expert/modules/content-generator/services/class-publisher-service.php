<?php
/**
 * Publisher Service — Bridges generated articles to WordPress posts.
 *
 * @package WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

namespace WPSpace\AiMarketingExpert\Modules\ContentGenerator\Services;

use WPSpace\AiMarketingExpert\Modules\ContentGenerator\Controllers\GenerateController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PublisherService {

	public static function mark_article_published_by_post_id( int $wp_post_id, string $published_at = '' ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'aime_content_articles';
		$wpdb->update(
			$table,
			array(
				'status'       => 'published',
				'published_at' => $published_at ? get_gmt_from_date( $published_at ) : current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'wp_post_id' => $wp_post_id, 'status' => 'scheduled' ),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	public static function sync_due_scheduled_articles( int $limit = 25 ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'aime_content_articles';
		$now   = current_time( 'mysql' );

		$articles = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, wp_post_id, scheduled_publish_at FROM {$table}
			WHERE status = %s AND wp_post_id IS NOT NULL AND scheduled_publish_at IS NOT NULL AND scheduled_publish_at <= %s
			ORDER BY scheduled_publish_at ASC LIMIT %d",
			'scheduled',
			$now,
			$limit
		) );

		foreach ( $articles as $article ) {
			$post = get_post( (int) $article->wp_post_id );
			if ( ! $post ) {
				continue;
			}

			// get_post_time('U', false) returns the post date as a site-timezone
			// epoch — matching current_time('timestamp'). strtotime() would parse
			// the same wall-clock string in the SERVER timezone and fire early
			// wherever the two offsets differ.
			if ( 'future' === $post->post_status && get_post_time( 'U', false, $post ) <= current_time( 'timestamp' ) ) {
				wp_publish_post( $post );
				$post = get_post( (int) $article->wp_post_id );
			}

			if ( $post && 'publish' === $post->post_status ) {
				self::mark_article_published_by_post_id( (int) $article->wp_post_id, $post->post_date );
			}
		}
	}

	/**
	 * Publish (or update) a generated article as a WordPress post.
	 *
	 * @param int    $article_id   Row ID in aime_content_articles.
	 * @param string $post_status  WP post status: draft | publish | pending | private.
	 * @param string $scheduled_at Scheduled datetime for 'future' status.
	 * @param int    $author_id    Optional WP user ID to set as post author (0 = current user).
	 * @return array {success, wp_post_id|error}
	 */
	public function publish( int $article_id, string $post_status = 'draft', string $scheduled_at = '', int $author_id = 0 ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'aime_content_articles';
		$article = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $article_id ) );

		if ( ! $article ) {
			return array( 'success' => false, 'error' => __( 'Article not found.', 'ai-marketing-expert' ) );
		}

		$allowed = array( 'draft', 'publish', 'future', 'pending', 'private' );
		if ( ! in_array( $post_status, $allowed, true ) ) {
			$post_status = 'draft';
		}

		if ( 'future' === $post_status && ! $scheduled_at ) {
			return array( 'success' => false, 'error' => __( 'Scheduled publish date is required.', 'ai-marketing-expert' ) );
		}

		if ( 'future' === $post_status ) {
			// $scheduled_at is a site-timezone wall-clock string from the client;
			// anchor it to wp_timezone() rather than the server timezone.
			try {
				$scheduled_dt = new \DateTimeImmutable( $scheduled_at, wp_timezone() );
			} catch ( \Exception $e ) {
				return array( 'success' => false, 'error' => __( 'Scheduled publish date is invalid.', 'ai-marketing-expert' ) );
			}
			if ( $scheduled_dt <= new \DateTimeImmutable( 'now', wp_timezone() ) ) {
				return array( 'success' => false, 'error' => __( 'Scheduled publish date must be in the future.', 'ai-marketing-expert' ) );
			}
		}

		$post_type = sanitize_key( $article->post_type ?? 'post' );
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		$clean_content = GenerateController::clean_ai_body( (string) $article->content, (string) ( $article->title ?? '' ) );
		if ( $clean_content && $clean_content !== $article->content ) {
			$wpdb->update(
				$table,
				array( 'content' => aime_kses_article( $clean_content ), 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $article_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$article->content = $clean_content;
		}

		$post_data = array(
			'post_title'   => sanitize_text_field( $article->title ),
			'post_content' => aime_kses_article( $article->content ),
			'post_excerpt' => sanitize_text_field( $article->excerpt ?? '' ),
			'post_status'  => $post_status,
			'post_type'    => $post_type,
			'post_name'    => $article->slug ?: sanitize_title( $article->title ),
		);

		// Explicit author (workflow runs have no logged-in user, which would
		// otherwise leave the post authorless). Only accept a real user.
		if ( $author_id > 0 && get_userdata( $author_id ) ) {
			$post_data['post_author'] = $author_id;
		}

		if ( 'future' === $post_status ) {
			$post_data['post_date']     = $scheduled_at;
			$post_data['post_date_gmt'] = get_gmt_from_date( $scheduled_at );
		}

		// Update existing WP post or insert new one.
		if ( ! empty( $article->wp_post_id ) ) {
			$post_data['ID'] = (int) $article->wp_post_id;
			$wp_post_id      = wp_update_post( $post_data, true );
		} else {
			$wp_post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $wp_post_id ) ) {
			return array( 'success' => false, 'error' => $wp_post_id->get_error_message() );
		}

		// Categories.
		$categories = json_decode( $article->category_ids ?? '[]', true );
		if ( is_array( $categories ) && $categories && 'post' === $post_type ) {
			wp_set_post_categories( $wp_post_id, array_map( 'intval', $categories ) );
		}

		// Tags (stored as names; filter out legacy numeric zeros).
		$tags = json_decode( $article->tag_ids ?? '[]', true );
		if ( is_array( $tags ) && $tags && 'post' === $post_type ) {
			$tags = array_filter( $tags, function ( $t ) {
				return is_string( $t ) && '' !== $t;
			} );
			if ( $tags ) {
				wp_set_post_tags( $wp_post_id, $tags );
			}
		}

		// Featured image.
		if ( ! has_post_thumbnail( $wp_post_id ) ) {
			if ( ! empty( $article->featured_image_id ) ) {
				// Attachment already exists in media library — use it directly.
				set_post_thumbnail( $wp_post_id, (int) $article->featured_image_id );
			} elseif ( ! empty( $article->featured_image_url ) ) {
				$this->set_featured_image( $wp_post_id, $article->featured_image_url, $article->title );
			}
		}

		// Meta title / description (Yoast / Rank Math / All-in-One SEO support).
		$this->set_seo_meta( $wp_post_id, $article );

		// Update article row.
		$article_status = 'future' === $post_status ? 'scheduled' : 'published';

		$wpdb->update(
			$table,
			array(
				'wp_post_id'   => $wp_post_id,
				'status'       => $article_status,
				'scheduled_publish_at' => 'future' === $post_status ? $scheduled_at : null,
				'published_at' => 'future' === $post_status ? null : current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $article_id ),
			array( '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		// History entry.
		$this->log_history( $article_id, 'published', "Published as WP post #{$wp_post_id} ({$post_status})" );

		return array(
			'success'    => true,
			'wp_post_id' => $wp_post_id,
			'status'     => $article_status,
			'scheduled_publish_at' => 'future' === $post_status ? $scheduled_at : '',
			'edit_url'   => get_edit_post_link( $wp_post_id, 'raw' ),
			'view_url'   => get_permalink( $wp_post_id ),
		);
	}

	/**
	 * Unpublish — trash the WP post and revert article status.
	 */
	public function unpublish( int $article_id ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'aime_content_articles';
		$article = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $article_id ) );

		if ( ! $article || empty( $article->wp_post_id ) ) {
			return array( 'success' => false, 'error' => __( 'Article not published or not found.', 'ai-marketing-expert' ) );
		}

		wp_trash_post( (int) $article->wp_post_id );

		// Use raw query to set wp_post_id = NULL (wpdb->update cannot produce SQL NULL with %s).
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, wp_post_id = NULL, updated_at = %s WHERE id = %d",
				'draft',
				current_time( 'mysql', true ),
				$article_id
			)
		);

		$this->log_history( $article_id, 'unpublished', 'WordPress post moved to trash' );

		return array( 'success' => true );
	}

	/* ── Helpers ─────────────────────────────────────── */

	private function set_featured_image( int $wp_post_id, string $image_url, string $alt = '' ): void {
		// Validate URL scheme to prevent SSRF.
		$image_url = esc_url_raw( $image_url );
		if ( ! $image_url || ! preg_match( '#^https?://#i', $image_url ) ) {
			return;
		}

		// Check if this URL already maps to an existing attachment to avoid duplicates.
		$existing_id = attachment_url_to_postid( $image_url );
		if ( $existing_id ) {
			set_post_thumbnail( $wp_post_id, $existing_id );
			return;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $image_url, $wp_post_id, sanitize_text_field( $alt ), 'id' );

		if ( ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $wp_post_id, $attachment_id );
		}
	}

	private function set_seo_meta( int $wp_post_id, object $article ): void {
		$meta_title = sanitize_text_field( $article->meta_title ?? '' );
		$meta_desc  = sanitize_textarea_field( $article->meta_description ?? '' );

		// Focus keyword: first article keyword (Brain puts focus first).
		$focus = '';
		$kw_raw = json_decode( $article->keywords ?? '[]', true );
		if ( is_array( $kw_raw ) && ! empty( $kw_raw[0] ) ) {
			$focus = sanitize_text_field( (string) $kw_raw[0] );
		}

		if ( ! $meta_title && ! $meta_desc && '' === $focus ) {
			return;
		}

		// Canonical store + multi-plugin sync (Yoast, RankMath, AIOSEO,
		// SEOPress, Slim SEO, TSF) + aime_seo_sync hook for the long tail.
		if ( class_exists( '\\WPSpace\\AiMarketingExpert\\Modules\\Seo\\Services\\SeoAdapterService' ) ) {
			\WPSpace\AiMarketingExpert\Modules\Seo\Services\SeoAdapterService::sync( $wp_post_id, $focus, $meta_title, $meta_desc );
			return;
		}

		// Fallback when SEO module inactive: legacy direct keys only.
		if ( $meta_title ) {
			update_post_meta( $wp_post_id, '_yoast_wpseo_title', $meta_title );
		}
		if ( $meta_desc ) {
			update_post_meta( $wp_post_id, '_yoast_wpseo_metadesc', $meta_desc );
		}
		if ( $meta_title ) {
			update_post_meta( $wp_post_id, 'rank_math_title', $meta_title );
		}
		if ( $meta_desc ) {
			update_post_meta( $wp_post_id, 'rank_math_description', $meta_desc );
		}
		if ( $meta_title ) {
			update_post_meta( $wp_post_id, '_aioseo_title', $meta_title );
		}
		if ( $meta_desc ) {
			update_post_meta( $wp_post_id, '_aioseo_description', $meta_desc );
		}
		if ( '' !== $focus ) {
			update_post_meta( $wp_post_id, 'aime_seo_keyword', $focus );
		}
	}

	private function log_history( int $article_id, string $action, string $details = '' ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'aime_content_history',
			array(
				'article_id' => $article_id,
				'action'     => $action,
				'details'    => $details,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}
}
