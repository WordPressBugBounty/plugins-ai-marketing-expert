<?php
/**
 * SEO Module — Automation Service.
 *
 * Handles "set it & forget it" SEO automations:
 *   - Auto on-page audit on post publish
 *   - Auto meta title/description generation
 *   - Auto internal link suggestions (via cron)
 *   - Auto rank checks (via cron, delegates to RankTrackerService)
 *
 * @package WPSpace\AiMarketingExpert\Modules\Seo\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\Seo\Services;

use WPSpace\AiMarketingExpert\AiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AutomationService {

	use ParsesAiJson;

	const OPTION_KEY = 'aime_seo_automation_settings';

	/**
	 * Ceiling on how many suggestion-carrying posts we will index in one request.
	 *
	 * Counting suggestions is inherently proportional to the number of posts
	 * carrying the meta, so the query has to be bounded somewhere. It is bounded
	 * here rather than in the page query, and when the ceiling is hit the
	 * response says so — a truncated list that admits it is truncated is a
	 * different thing from one that quietly stops.
	 */
	const MAX_INDEXED_POSTS = 500;

	const DEFAULTS = array(
		'auto_audit_on_publish'    => false,
		'auto_meta_on_publish'     => false,
		'auto_internal_links'      => false,
		'auto_rank_check'          => false,
		'internal_link_frequency'  => 'weekly',
		'rank_check_frequency'     => 'daily',
		'auto_audit_post_types'    => array( 'post', 'page' ),
		'auto_meta_post_types'     => array( 'post', 'page' ),
	);

	/**
	 * Get automation settings merged with defaults.
	 */
	public function get_settings(): array {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::DEFAULTS );
	}

	/**
	 * Save automation settings.
	 */
	public function save_settings( array $data ): array {
		$current  = $this->get_settings();
		$settings = $current;

		$bool_fields = array( 'auto_audit_on_publish', 'auto_meta_on_publish', 'auto_internal_links', 'auto_rank_check' );
		foreach ( $bool_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$settings[ $field ] = (bool) $data[ $field ];
			}
		}

		$text_fields = array( 'internal_link_frequency', 'rank_check_frequency' );
		foreach ( $text_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$settings[ $field ] = sanitize_key( $data[ $field ] );
			}
		}

		$array_fields = array( 'auto_audit_post_types', 'auto_meta_post_types' );
		foreach ( $array_fields as $field ) {
			if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
				$settings[ $field ] = array_map( 'sanitize_key', $data[ $field ] );
			}
		}

		update_option( self::OPTION_KEY, $settings, false );
		aime_clear_settings_cache( array( self::OPTION_KEY ) );

		return $settings;
	}

	/**
	 * Handle post publish — trigger auto-audit & auto-meta if enabled.
	 */
	public function handle_post_publish( int $post_id, \WP_Post $post ): void {
		$settings = $this->get_settings();

		// Skip if no automations are enabled.
		if ( ! $settings['auto_audit_on_publish'] && ! $settings['auto_meta_on_publish'] ) {
			return;
		}

		// Auto audit on publish.
		if ( $settings['auto_audit_on_publish'] && in_array( $post->post_type, $settings['auto_audit_post_types'], true ) ) {
			$this->run_auto_audit( $post_id );
		}

		// Auto meta on publish.
		if ( $settings['auto_meta_on_publish'] && in_array( $post->post_type, $settings['auto_meta_post_types'], true ) ) {
			$this->run_auto_meta( $post_id );
		}
	}

	/**
	 * Run auto on-page audit for a post.
	 */
	private function run_auto_audit( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		try {
			$service = new OnPageSeoService();
			$result  = $service->run_audit( $post_id, '', '' );

			$this->log_action(
				'auto_audit',
				'publish',
				$post_id,
				$result['success'] ? 'completed' : 'failed',
				$result['success']
					? sprintf( 'Auto audit score: %d%%', $result['data']['overall_score'] ?? 0 )
					: ( $result['message'] ?? 'Audit failed.' ),
				$result['data'] ?? null
			);
		} catch ( \Throwable $e ) {
			$this->log_action( 'auto_audit', 'publish', $post_id, 'failed', $e->getMessage() );
		}
	}

	/**
	 * Run auto meta title/description generation for a post.
	 */
	private function run_auto_meta( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Only fill if meta is currently empty.
		$existing_title = get_post_meta( $post_id, '_yoast_wpseo_title', true )
		                  ?: get_post_meta( $post_id, 'rank_math_title', true );
		$existing_desc  = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true )
		                  ?: get_post_meta( $post_id, 'rank_math_description', true );

		if ( $existing_title && $existing_desc ) {
			return; // Both already set, skip.
		}

		$content_excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 300 );

		$prompt = sprintf(
			"You are an SEO expert. Generate an optimised meta title and meta description for the following article.\n\n" .
			"Title: %s\n" .
			"Content excerpt: %s\n\n" .
			"Requirements:\n" .
			"- Meta title: 50-60 characters, compelling, includes primary keyword naturally\n" .
			"- Meta description: 120-155 characters, includes a call-to-action, summarises the article\n\n" .
			"Return valid JSON:\n" .
			"{\"meta_title\": \"...\", \"meta_description\": \"...\"}",
			$post->post_title,
			$content_excerpt
		);

		try {
			$ai_result = AiProvider::generate( $prompt, 'text', 500 );

			if ( ! $ai_result['success'] ) {
				$this->log_action( 'auto_meta', 'publish', $post_id, 'failed', 'AI provider error.' );
				return;
			}

			$parsed = $this->parse_json_response( $ai_result['content'] ?? '' );

			if ( ! $parsed || ( empty( $parsed['meta_title'] ) && empty( $parsed['meta_description'] ) ) ) {
				$this->log_action( 'auto_meta', 'publish', $post_id, 'failed', 'Could not parse AI response.' );
				return;
			}

			$updated = array();

			if ( ! $existing_title && ! empty( $parsed['meta_title'] ) ) {
				update_post_meta( $post_id, '_aime_seo_meta_title', sanitize_text_field( $parsed['meta_title'] ) );
				$updated[] = 'title';
			}

			if ( ! $existing_desc && ! empty( $parsed['meta_description'] ) ) {
				update_post_meta( $post_id, '_aime_seo_meta_description', sanitize_text_field( $parsed['meta_description'] ) );
				$updated[] = 'description';
			}

			$this->log_action(
				'auto_meta',
				'publish',
				$post_id,
				'completed',
				sprintf( 'Generated meta %s for "%s"', implode( ' & ', $updated ), $post->post_title ),
				$parsed
			);
		} catch ( \Throwable $e ) {
			$this->log_action( 'auto_meta', 'publish', $post_id, 'failed', $e->getMessage() );
		}
	}

	/**
	 * Process scheduled automation tasks (called by WP-Cron hourly).
	 */
	public function process_scheduled_tasks( bool $force = false ): void {
		$settings = $this->get_settings();

		// Auto internal link suggestions.
		if ( $settings['auto_internal_links'] ) {
			$last_run = get_option( 'aime_seo_last_internal_link_run', 0 );
			$interval = $settings['internal_link_frequency'] === 'daily' ? DAY_IN_SECONDS : WEEK_IN_SECONDS;

			if ( $force || ( time() - $last_run ) >= $interval ) {
				$this->run_internal_link_scan();
				update_option( 'aime_seo_last_internal_link_run', time() );
			}
		}
	}

	/**
	 * Scan recent posts for internal linking opportunities.
	 */
	private function run_internal_link_scan(): void {
		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		if ( empty( $posts ) ) {
			$this->log_action( 'internal_links_batch', 'cron', null, 'completed', 'Internal link scan completed. No published posts found.' );
			return;
		}

		// Gather titles of all published posts as potential link targets.
		$all_posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'fields'         => 'ids',
		) );

		$targets = array();
		foreach ( $all_posts as $pid ) {
			$targets[] = array(
				'id'    => $pid,
				'title' => get_the_title( $pid ),
				'url'   => get_permalink( $pid ),
			);
		}

		$targets_text = '';
		foreach ( array_slice( $targets, 0, 50 ) as $t ) {
			$targets_text .= sprintf( "- [ID:%d] %s (%s)\n", $t['id'], $t['title'], $t['url'] );
		}

		$found_count = 0;

		foreach ( $posts as $post ) {
			$content_excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 200 );

			$prompt = sprintf(
				"You are an SEO internal linking expert. Analyse this article and suggest internal links to other pages on the same site.\n\n" .
				"Article: %s\nExcerpt: %s\n\n" .
				"Available pages to link to:\n%s\n" .
				"Suggest 1-5 internal link opportunities. For each, specify which phrase in the article should be the anchor text and which target page it should link to.\n\n" .
				"Return valid JSON array:\n" .
				"[{\"anchor_text\": \"...\", \"target_post_id\": 123, \"target_url\": \"...\", \"reason\": \"...\"}]",
				$post->post_title,
				$content_excerpt,
				$targets_text
			);

			try {
				$ai_result = AiProvider::generate( $prompt, 'text', 1000 );

				if ( ! $ai_result['success'] ) {
					continue;
				}

				$suggestions = $this->parse_json_response( $ai_result['content'] ?? '' );

				if ( ! $suggestions || ! is_array( $suggestions ) ) {
					continue;
				}

				// Only keep suggestions that are a list of objects.
				if ( isset( $suggestions[0] ) && is_array( $suggestions[0] ) ) {
					$count = count( $suggestions );
					$found_count += $count;

					// Store suggestions as post meta for review and optional application.
					update_post_meta( $post->ID, '_aime_seo_internal_links', $this->prepare_internal_link_suggestions( $post->ID, $suggestions ) );

					$this->log_action(
						'internal_links',
						'cron',
						$post->ID,
						'completed',
						sprintf( 'Found %d internal link suggestions for "%s"', $count, $post->post_title ),
						$suggestions
					);
				}
			} catch ( \Throwable $e ) {
				// Continue with next post.
				continue;
			}
		}

		if ( $found_count > 0 ) {
			$this->log_action(
				'internal_links_batch',
				'cron',
				null,
				'completed',
				sprintf( 'Internal link scan completed. Found %d suggestions across %d posts.', $found_count, count( $posts ) )
			);
		} else {
			$this->log_action(
				'internal_links_batch',
				'cron',
				null,
				'completed',
				sprintf( 'Internal link scan completed. No suggestions found across %d posts.', count( $posts ) )
			);
		}
	}

	/**
	 * Get stored internal link suggestions grouped by source post.
	 *
	 * Paginated by *post*, not by suggestion: a post's suggestions are reviewed
	 * together, so splitting one post's group across a page boundary would be
	 * worse than a long page.
	 *
	 * Two passes on purpose. The first walks every indexed post to count and
	 * filter, using only meta that has been primed into cache in a single query
	 * — cheap. The second hydrates titles and permalinks for the current page
	 * only, which is where the per-post query cost actually lives.
	 */
	public function get_internal_link_suggestions( string $status = 'pending', int $page = 1, int $per_page = 10 ): array {
		$allowed_statuses = array( 'all', 'pending', 'applied', 'dismissed' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'pending';
		}

		$page     = max( 1, $page );
		$per_page = min( 50, max( 1, $per_page ) );

		$post_ids = get_posts( array(
			'post_type'              => array( 'post', 'page' ),
			'post_status'            => 'any',
			'posts_per_page'         => self::MAX_INDEXED_POSTS,
			'meta_key'               => '_aime_seo_internal_links',
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		) );

		$truncated = count( $post_ids ) >= self::MAX_INDEXED_POSTS;

		// One query for every suggestion blob, so the counting pass below hits
		// cache instead of the database once per post.
		if ( ! empty( $post_ids ) ) {
			update_meta_cache( 'post', $post_ids );
		}

		// Pass one: which posts have at least one suggestion in this status, and
		// how many overall. Pagination has to run over the *filtered* set —
		// paging the raw post list would hand back empty pages whenever the
		// selected status is sparse.
		$matched = array();
		$total   = 0;

		foreach ( $post_ids as $post_id ) {
			$filtered = array();

			foreach ( $this->get_post_internal_link_suggestions( $post_id ) as $suggestion ) {
				if ( 'all' !== $status && ( $suggestion['status'] ?? 'pending' ) !== $status ) {
					continue;
				}

				$filtered[] = $suggestion;
			}

			if ( empty( $filtered ) ) {
				continue;
			}

			$matched[ $post_id ] = $filtered;
			$total              += count( $filtered );
		}

		$total_posts = count( $matched );
		$total_pages = $total_posts > 0 ? (int) ceil( $total_posts / $per_page ) : 0;
		$page        = $total_pages > 0 ? min( $page, $total_pages ) : 1;

		$page_ids = array_slice( array_keys( $matched ), ( $page - 1 ) * $per_page, $per_page );

		// Pass two: hydrate only what this page renders.
		$items = array();

		if ( ! empty( $page_ids ) ) {
			$posts = get_posts( array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'any',
				'post__in'       => $page_ids,
				'orderby'        => 'post__in',
				'posts_per_page' => count( $page_ids ),
				'no_found_rows'  => true,
			) );

			foreach ( $posts as $post ) {
				$suggestions = array();

				foreach ( $matched[ $post->ID ] as $suggestion ) {
					$target_post_id = absint( $suggestion['target_post_id'] ?? 0 );
					$suggestions[]  = array_merge( $suggestion, array(
						'target_title' => $target_post_id ? get_the_title( $target_post_id ) : '',
					) );
				}

				$items[] = array(
					'post_id'     => $post->ID,
					'post_title'  => get_the_title( $post ),
					'edit_link'   => get_edit_post_link( $post->ID, 'raw' ),
					'view_link'   => get_permalink( $post ),
					'post_status' => $post->post_status,
					'suggestions' => $suggestions,
					'updated_at'  => get_post_modified_time( 'Y-m-d H:i:s', false, $post ),
				);
			}
		}

		return array(
			'items'         => $items,
			'total'         => $total,
			'total_posts'   => $total_posts,
			'page'          => $page,
			'per_page'      => $per_page,
			'total_pages'   => $total_pages,
			'truncated'     => $truncated,
			'index_ceiling' => self::MAX_INDEXED_POSTS,
		);
	}


	/**
	 * Dismiss one stored internal link suggestion.
	 */
	public function dismiss_internal_link_suggestion( int $post_id, string $suggestion_id ): array|
	\WP_Error {
		return $this->update_internal_link_suggestion_status( $post_id, $suggestion_id, 'dismissed' );
	}

	/**
	 * Apply one internal link suggestion to post content.
	 */
	public function apply_internal_link_suggestion( int $post_id, string $suggestion_id ): array|
	\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'aime_seo_post_not_found', __( 'Post not found.', 'ai-marketing-expert' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'aime_seo_cannot_edit_post', __( 'You do not have permission to edit this post.', 'ai-marketing-expert' ), array( 'status' => 403 ) );
		}

		$suggestions = $this->get_post_internal_link_suggestions( $post_id );
		$index       = $this->find_internal_link_suggestion_index( $suggestions, $suggestion_id );

		if ( null === $index ) {
			return new \WP_Error( 'aime_seo_suggestion_not_found', __( 'Suggestion not found.', 'ai-marketing-expert' ), array( 'status' => 404 ) );
		}

		$suggestion = $suggestions[ $index ];
		if ( 'applied' === ( $suggestion['status'] ?? 'pending' ) ) {
			return new \WP_Error( 'aime_seo_suggestion_applied', __( 'This suggestion has already been applied.', 'ai-marketing-expert' ), array( 'status' => 409 ) );
		}

		$anchor_text = trim( wp_strip_all_tags( (string) ( $suggestion['anchor_text'] ?? '' ) ) );
		$target_url  = esc_url_raw( (string) ( $suggestion['target_url'] ?? '' ) );

		if ( '' === $anchor_text || '' === $target_url ) {
			return new \WP_Error( 'aime_seo_invalid_suggestion', __( 'Suggestion is missing anchor text or target URL.', 'ai-marketing-expert' ), array( 'status' => 400 ) );
		}

		if ( false !== strpos( $post->post_content, $target_url ) ) {
			return new \WP_Error( 'aime_seo_link_exists', __( 'This target URL is already present in the post content.', 'ai-marketing-expert' ), array( 'status' => 409 ) );
		}

		$replacement = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $target_url ),
			esc_html( $anchor_text )
		);

		$new_content = $this->replace_first_safe_anchor_text( $post->post_content, $anchor_text, $replacement );
		if ( $new_content === $post->post_content ) {
			return new \WP_Error( 'aime_seo_anchor_not_found', __( 'Anchor text was not found as plain content in the post. Open the editor and add this link manually.', 'ai-marketing-expert' ), array( 'status' => 409 ) );
		}

		wp_save_post_revision( $post_id );

		$result = wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => wp_slash( $new_content ),
		), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$suggestions[ $index ]['status']     = 'applied';
		$suggestions[ $index ]['applied_at'] = current_time( 'mysql' );
		update_post_meta( $post_id, '_aime_seo_internal_links', $suggestions );

		$this->log_action(
			'internal_links',
			'manual',
			$post_id,
			'completed',
			sprintf( 'Applied internal link suggestion to "%s"', get_the_title( $post_id ) ),
			$suggestions[ $index ]
		);

		return array(
			'success'    => true,
			'suggestion' => $suggestions[ $index ],
			'message'    => __( 'Internal link applied.', 'ai-marketing-expert' ),
		);
	}

	/**
	 * Normalize AI suggestions for storage.
	 */
	private function prepare_internal_link_suggestions( int $post_id, array $suggestions ): array {
		$prepared = array();
		$existing = array();

		foreach ( $this->get_post_internal_link_suggestions( $post_id ) as $stored_suggestion ) {
			$existing[ $stored_suggestion['id'] ] = $stored_suggestion;
		}

		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}

			$anchor_text    = sanitize_text_field( $suggestion['anchor_text'] ?? '' );
			$target_post_id = absint( $suggestion['target_post_id'] ?? 0 );
			$target_url     = esc_url_raw( $suggestion['target_url'] ?? '' );

			if ( '' === $anchor_text || ( ! $target_post_id && '' === $target_url ) ) {
				continue;
			}

			if ( $target_post_id && ! $target_url ) {
				$target_url = get_permalink( $target_post_id );
			}

			$id       = $this->generate_internal_link_suggestion_id( $post_id, $anchor_text, $target_post_id, $target_url );
			$previous = $existing[ $id ] ?? array();

			$prepared[] = array(
				'id'             => $id,
				'anchor_text'    => $anchor_text,
				'target_post_id' => $target_post_id,
				'target_url'     => esc_url_raw( $target_url ),
				'reason'         => sanitize_text_field( $suggestion['reason'] ?? '' ),
				'status'         => sanitize_key( $previous['status'] ?? $suggestion['status'] ?? 'pending' ),
				'created_at'     => sanitize_text_field( $previous['created_at'] ?? current_time( 'mysql' ) ),
				'applied_at'     => sanitize_text_field( $previous['applied_at'] ?? '' ),
			);
		}

		return $prepared;
	}

	/**
	 * Get normalized suggestions for one post, upgrading old stored data as needed.
	 */
	private function get_post_internal_link_suggestions( int $post_id ): array {
		$suggestions = get_post_meta( $post_id, '_aime_seo_internal_links', true );
		if ( ! is_array( $suggestions ) ) {
			return array();
		}

		$normalized = array();
		$changed    = false;

		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				$changed = true;
				continue;
			}

			$anchor_text    = sanitize_text_field( $suggestion['anchor_text'] ?? '' );
			$target_post_id = absint( $suggestion['target_post_id'] ?? 0 );
			$target_url     = esc_url_raw( $suggestion['target_url'] ?? '' );

			if ( '' === $anchor_text || ( ! $target_post_id && '' === $target_url ) ) {
				$changed = true;
				continue;
			}

			if ( $target_post_id && ! $target_url ) {
				$target_url = get_permalink( $target_post_id );
				$changed    = true;
			}

			$normalized[] = array(
				'id'             => sanitize_key( $suggestion['id'] ?? $this->generate_internal_link_suggestion_id( $post_id, $anchor_text, $target_post_id, $target_url ) ),
				'anchor_text'    => $anchor_text,
				'target_post_id' => $target_post_id,
				'target_url'     => esc_url_raw( $target_url ),
				'reason'         => sanitize_text_field( $suggestion['reason'] ?? '' ),
				'status'         => sanitize_key( $suggestion['status'] ?? 'pending' ),
				'created_at'     => sanitize_text_field( $suggestion['created_at'] ?? '' ),
				'applied_at'     => sanitize_text_field( $suggestion['applied_at'] ?? '' ),
			);

			if ( empty( $suggestion['id'] ) || empty( $suggestion['status'] ) ) {
				$changed = true;
			}
		}

		if ( $changed ) {
			update_post_meta( $post_id, '_aime_seo_internal_links', $normalized );
		}

		return $normalized;
	}

	/**
	 * Update the status for one internal link suggestion.
	 */
	private function update_internal_link_suggestion_status( int $post_id, string $suggestion_id, string $status ): array|\WP_Error {
		$suggestions = $this->get_post_internal_link_suggestions( $post_id );
		$index       = $this->find_internal_link_suggestion_index( $suggestions, $suggestion_id );

		if ( null === $index ) {
			return new \WP_Error( 'aime_seo_suggestion_not_found', __( 'Suggestion not found.', 'ai-marketing-expert' ), array( 'status' => 404 ) );
		}

		$suggestions[ $index ]['status'] = sanitize_key( $status );
		update_post_meta( $post_id, '_aime_seo_internal_links', $suggestions );

		return array(
			'success'    => true,
			'suggestion' => $suggestions[ $index ],
		);
	}

	/**
	 * Find a suggestion index by ID.
	 */
	private function find_internal_link_suggestion_index( array $suggestions, string $suggestion_id ): ?int {
		$suggestion_id = sanitize_key( $suggestion_id );

		foreach ( $suggestions as $index => $suggestion ) {
			if ( sanitize_key( $suggestion['id'] ?? '' ) === $suggestion_id ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Build a stable ID for a stored suggestion.
	 */
	private function generate_internal_link_suggestion_id( int $post_id, string $anchor_text, int $target_post_id, string $target_url ): string {
		return substr( md5( $post_id . '|' . strtolower( $anchor_text ) . '|' . $target_post_id . '|' . $target_url ), 0, 16 );
	}

	/**
	 * Replace the first plain-text occurrence outside existing links and HTML tags.
	 */
	private function replace_first_safe_anchor_text( string $content, string $anchor_text, string $replacement ): string {
		$pattern = '/' . preg_quote( $anchor_text, '/' ) . '/iu';

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}

		foreach ( $matches[0] as $match ) {
			$offset = $match[1];
			$before = substr( $content, 0, $offset );

			$last_open_tag  = strrpos( $before, '<' );
			$last_close_tag = strrpos( $before, '>' );
			if ( false !== $last_open_tag && ( false === $last_close_tag || $last_open_tag > $last_close_tag ) ) {
				continue;
			}

			$last_link_open  = strripos( $before, '<a ' );
			$last_link_close = strripos( $before, '</a>' );
			if ( false !== $last_link_open && ( false === $last_link_close || $last_link_open > $last_link_close ) ) {
				continue;
			}

			return substr_replace( $content, $replacement, $offset, strlen( $match[0] ) );
		}

		return $content;
	}

	/**
	 * Get automation log entries.
	 */
	public function get_log( int $page = 1, int $per_page = 20, string $task_type = '' ): array {
		global $wpdb;
		$p     = $wpdb->prefix;
		$table = "{$p}aime_seo_automation_log";

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return array( 'items' => array(), 'total' => 0 );
		}

		$offset   = ( $page - 1 ) * $per_page;

		if ( $task_type ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE task_type = %s", $task_type ) );
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE task_type = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$task_type,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		foreach ( $items as &$item ) {
			if ( ! empty( $item['details'] ) ) {
				$item['details'] = json_decode( $item['details'], true );
			}
		}

		return array( 'items' => $items, 'total' => $total );
	}

	/**
	 * Clear all automation log entries.
	 */
	public function clear_log(): bool {
		global $wpdb;
		$p     = $wpdb->prefix;
		$table = "{$p}aime_seo_automation_log";

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return false;
		}

		$wpdb->query( "TRUNCATE TABLE {$table}" );
		return true;
	}

	/**
	 * Log an automation action.
	 */
	private function log_action( string $task_type, string $trigger_type, ?int $wp_post_id, string $status, string $summary, $details = null ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_seo_automation_log" ) );
		if ( ! $table_exists ) {
			return;
		}

		$wpdb->insert( "{$p}aime_seo_automation_log", array(
			'task_type'    => sanitize_key( $task_type ),
			'trigger_type' => sanitize_key( $trigger_type ),
			'wp_post_id'   => $wp_post_id,
			'status'       => sanitize_key( $status ),
			'summary'      => sanitize_text_field( $summary ),
			'details'      => $details ? wp_json_encode( $details ) : null,
		) );
	}
}
