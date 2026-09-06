<?php
/**
 * Base action — shared helpers for workflow action handlers.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseAction {

	/**
	 * Standard success result.
	 */
	protected static function ok( string $preview, array $reference = array() ): array {
		return array(
			'success'   => true,
			'preview'   => $preview,
			'reference' => $reference,
			'error'     => '',
		);
	}

	/**
	 * Standard failure result.
	 */
	protected static function fail( string $error ): array {
		return array(
			'success'   => false,
			'preview'   => '',
			'reference' => array(),
			'error'     => $error,
		);
	}

	/**
	 * Resolve a value from upstream step outputs.
	 *
	 * Walks the direct parent's reference first, then the full `previous`
	 * history newest→oldest — so chained steps (Brain → Blog → Audit → Social)
	 * still find the Brain brief several hops away, and sibling branches are
	 * visible to steps that follow them. Optionally restricted to outputs of
	 * one action type.
	 *
	 * @param array       $context     Workflow context.
	 * @param string      $ref_key     Reference key to look for (e.g. 'selected_topic').
	 * @param string|null $action_type When set, only outputs of this action type match.
	 * @return string Empty string when nothing upstream provides the key.
	 */
	protected static function resolve_from_context( array $context, string $ref_key, ?string $action_type = null ): string {
		// 1. Direct parent output (the common case).
		$parent_ref = is_array( $context['parent_output']['reference'] ?? null ) ? $context['parent_output']['reference'] : array();
		if ( ! empty( $parent_ref[ $ref_key ] ) && is_scalar( $parent_ref[ $ref_key ] )
			&& ( null === $action_type || ( $context['parent_output']['action_type'] ?? '' ) === $action_type ) ) {
			return trim( (string) $parent_ref[ $ref_key ] );
		}

		// 2. Full previous-step history, newest first.
		$previous = is_array( $context['previous'] ?? null ) ? $context['previous'] : array();
		foreach ( array_reverse( $previous ) as $entry ) {
			if ( ! is_array( $entry ) || null !== $action_type && (string) ( $entry['action_type'] ?? '' ) !== $action_type ) {
				continue;
			}
			$reference = is_array( $entry['reference'] ?? null ) ? $entry['reference'] : array();
			if ( ! empty( $reference[ $ref_key ] ) && is_scalar( $reference[ $ref_key ] ) ) {
				return trim( (string) $reference[ $ref_key ] );
			}
		}

		return '';
	}

	/**
	 * Resolve the effective topic:
	 * step config → workflow topic → any upstream AI Brain / Custom Prompt
	 * selection (ancestor-aware) → trigger-event fallback (post_title/name).
	 */
	protected static function topic( array $config, array $context, string $key = 'topic' ): string {
		$topic = trim( (string) ( $config[ $key ] ?? '' ) );
		if ( '' === $topic ) {
			$topic = trim( (string) ( $context['topic'] ?? '' ) );
		}
		if ( '' === $topic ) {
			$topic = self::resolve_from_context( $context, 'selected_topic', 'ai_brain' );
		}
		if ( '' === $topic ) {
			// Custom Prompt steps can also steer downstream topics via their output.
			$topic = self::resolve_from_context( $context, 'full_output', 'custom_prompt' );
			if ( '' !== $topic ) {
				$topic = wp_trim_words( preg_replace( '/\s+/u', ' ', $topic ), 12, '' );
			}
		}
		// Event-triggered runs without any topic config: promote from the event.
		if ( '' === $topic && is_array( $context['event'] ?? null ) ) {
			foreach ( array( 'post_title', 'name', 'title' ) as $event_key ) {
				if ( ! empty( $context['event'][ $event_key ] ) && is_scalar( $context['event'][ $event_key ] ) ) {
					$topic = trim( (string) $context['event'][ $event_key ] );
					break;
				}
			}
		}
		return $topic;
	}

	/**
	 * Resolve the effective topic with Pro rotation support.
	 *
	 * A non-empty rotation list ($list_key, Pro only) overrides the single
	 * topic: each run picks a different entry via rotate_topic(). Otherwise
	 * falls back to the single-topic resolution (step config, else workflow
	 * topic) exactly as before.
	 *
	 * @param array  $config   Step config.
	 * @param array  $context  Workflow context.
	 * @param string $key      Single-value config key ('topic', 'product', …).
	 * @param string $list_key Rotation-list config key ('topics', 'products', …).
	 */
	protected static function rotated_topic( array $config, array $context, string $key = 'topic', string $list_key = 'topics' ): string {
		$list = $config[ $list_key ] ?? array();
		$list = is_array( $list )
			? $list
			: array_filter( array_map( 'trim', explode( ',', (string) $list ) ) );

		if ( $list && aime_has_pro() ) {
			$state_key = (int) ( $context['workflow_id'] ?? 0 ) . ':' . (int) ( $context['step_id'] ?? 0 );
			$rotated   = self::rotate_topic( $list, $state_key );
			if ( '' !== $rotated ) {
				return $rotated;
			}
		}

		return self::topic( $config, $context, $key );
	}

	/**
	 * Pick the next topic from a rotation list (Pro).
	 *
	 * Random-without-repeat: tracks which entries have already been used
	 * (per state key + list fingerprint, so editing the list restarts the
	 * cycle) and picks randomly among the unused ones. When every entry has
	 * been used the cycle resets, guaranteeing even coverage.
	 *
	 * @param array  $topics    Raw rotation list from step config.
	 * @param string $state_key Rotation-state scope ("workflow_id:step_id").
	 * @return string Chosen entry ('' when the list is empty).
	 */
	protected static function rotate_topic( array $topics, string $state_key ): string {
		$topics = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $topics ) ), 'strlen' ) ) );
		if ( ! $topics ) {
			return '';
		}
		if ( 1 === count( $topics ) ) {
			return $topics[0];
		}

		$state = get_option( 'aime_wf_topic_rotation', array() );
		$state = is_array( $state ) ? $state : array();
		$key   = $state_key . ':' . md5( wp_json_encode( $topics ) );

		$used      = isset( $state[ $key ] ) && is_array( $state[ $key ] ) ? array_map( 'intval', $state[ $key ] ) : array();
		$remaining = array_values( array_diff( array_keys( $topics ), $used ) );
		if ( ! $remaining ) {
			$used      = array();
			$remaining = array_keys( $topics );
		}

		$pick   = $remaining[ array_rand( $remaining ) ];
		$used[] = $pick;

		// Drop stale state for this step (old topic lists) before saving.
		foreach ( array_keys( $state ) as $k ) {
			if ( 0 === strpos( (string) $k, $state_key . ':' ) && $k !== $key ) {
				unset( $state[ $k ] );
			}
		}
		$state[ $key ] = $used;
		update_option( 'aime_wf_topic_rotation', $state, false );

		return $topics[ $pick ];
	}

	protected static function tone( array $context ): string {
		$tone = trim( (string) ( $context['tone'] ?? '' ) );
		return '' !== $tone ? $tone : 'professional';
	}

	/**
	 * Brand-voice system instructions for the workflow (Pro).
	 *
	 * Shared by every AI-generating step so a selected brand voice applies
	 * consistently — not only to blog posts.
	 *
	 * @param array $context Workflow context (brand_voice_id).
	 * @return string Prompt text ('' when no voice applies).
	 */
	protected static function brand_voice_system_prompt( array $context ): string {
		if ( ! aime_has_pro() ) {
			return '';
		}
		$brand_voice_id = (int) ( $context['brand_voice_id'] ?? 0 );
		if ( $brand_voice_id <= 0 ) {
			return '';
		}
		$voice_controller = '\\WPSpace\\AiMarketingExpert\\Modules\\ContentGenerator\\Controllers\\WorkflowController';
		if ( ! class_exists( $voice_controller ) ) {
			return '';
		}
		return (string) $voice_controller::get_brand_voice_prompt( $brand_voice_id );
	}

	/**
	 * Build a compact context block from upstream step outputs, newest first.
	 *
	 * Used by caption/ad prompts that want to "see" what earlier steps produced
	 * (e.g. the article title a social post is promoting). Unlike the legacy
	 * `previous[0]` lookup this reflects the actual chain order and prefers
	 * article-producing steps when present.
	 *
	 * @param array  $context   Workflow context.
	 * @param int    $max_chars Output length cap.
	 * @return string '' when nothing upstream succeeded.
	 */
	protected static function ancestor_context( array $context, int $max_chars = 800 ): string {
		$previous = is_array( $context['previous'] ?? null ) ? $context['previous'] : array();
		// Article outputs first (most relevant for promo copy), then the rest newest→oldest.
		$priorities = array( 'generate_blog_post', 'custom_prompt', 'ai_brain' );
		$ordered    = array();
		foreach ( $priorities as $type ) {
			foreach ( array_reverse( $previous ) as $entry ) {
				if ( is_array( $entry ) && (string) ( $entry['action_type'] ?? '' ) === $type && ! empty( $entry['preview'] ) ) {
					$ordered[] = (string) $entry['preview'];
				}
			}
		}
		foreach ( array_reverse( $previous ) as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['preview'] ) && ! in_array( (string) $entry['preview'], $ordered, true ) ) {
				$ordered[] = (string) $entry['preview'];
			}
		}

		$out = implode( "\n", $ordered );
		return mb_strlen( $out ) > $max_chars ? mb_substr( $out, 0, $max_chars ) : $out;
	}

	/**
	 * Deep link to a plugin module admin page (used as reference['link'] so
	 * execution history can jump straight to the produced artifact).
	 *
	 * Module pages route their sub-views off the URL hash, so a link without
	 * one lands on the module's default tab rather than the artifact.
	 *
	 * @param string $page Module page suffix (content|email|seo|social|...).
	 * @param string $view Optional sub-view key routed via the hash (e.g. 'articles').
	 */
	protected static function module_link( string $page, string $view = '' ): string {
		$url = admin_url( 'admin.php?page=ai-marketing-expert-' . $page );
		return '' !== $view ? $url . '#' . $view : $url;
	}

	/**
	 * Return distinct topics covered in the last $days days.
	 *
	 * Queries the content articles table. Fail-soft — returns empty array if
	 * the table doesn't exist. Used by AI Brain to avoid repeating recent topics.
	 *
	 * @param int $days  Look-back window in days.
	 * @param int $limit Maximum topics to return.
	 * @return string[] Array of topic strings.
	 */
	protected static function recent_topics( int $days = 60, int $limit = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'aime_content_articles';

		// Guard: table must exist.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT topic FROM {$table}
			 WHERE topic != '' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			 ORDER BY created_at DESC LIMIT %d",
			$days,
			$limit
		) );

		return is_array( $rows )
			? array_values( array_filter( array_map( 'sanitize_text_field', $rows ) ) )
			: array();
	}

	/**
	 * Shared insert into the content module's articles table — the single place
	 * workflow actions persist generated article drafts.
	 *
	 * @param array $fields    Column overrides (title/content/topic/...).
	 * @param array $ai_result AiProvider result (provider/model metadata).
	 * @return int Article ID (0 on failure).
	 */
	protected static function save_article( array $fields, array $ai_result = array() ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$defaults = array(
			'title'       => '',
			'slug'        => '',
			'content'     => '',
			'status'      => 'draft',
			'post_type'   => 'post',
			'language'    => 'en',
			'ai_provider' => $ai_result['provider'] ?? '',
			'ai_model'    => $ai_result['model'] ?? '',
			'created_at'  => $now,
			'updated_at'  => $now,
		);

		$data = array_merge( $defaults, $fields );
		if ( '' === $data['slug'] ) {
			$data['slug'] = sanitize_title( $data['title'] ) . '-' . wp_generate_password( 5, false );
		}

		$ok = $wpdb->insert( $wpdb->prefix . 'aime_content_articles', $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}
}
