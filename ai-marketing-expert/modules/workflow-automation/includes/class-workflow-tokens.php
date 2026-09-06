<?php
/**
 * Workflow Tokens — shared token replacement engine for workflow steps.
 *
 * Single implementation so every step resolves the same token vocabulary:
 *
 *   {topic}                 Workflow-level topic.
 *   {workflow_name}         Workflow name.
 *   {previous_preview}      Preview of the most recent successful step.
 *   {event.dot.path}        Dot-notation path into the trigger event payload.
 *   {previous.dot.path}     Dot-notation path into the most recent step's
 *                           reference (e.g. {previous.link}, {previous.score}).
 *   {action_type.dot.path}  Reference field from the most recent output of a
 *                           specific action type (e.g. {seo_audit.score},
 *                           {generate_blog_post.edit_url}). Falls back to the
 *                           parent output's reference.
 *
 * Unknown tokens are left untouched so literal brace text never breaks.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorkflowTokens {

	/**
	 * Replace supported tokens in a template string.
	 *
	 * @param string $template Raw template containing tokens.
	 * @param array  $context  Workflow context (topic/workflow_name/event/previous/parent_output).
	 * @return string Template with known tokens resolved.
	 */
	public static function replace( string $template, array $context ): string {
		if ( '' === $template || false === strpos( $template, '{' ) ) {
			return $template;
		}

		return (string) preg_replace_callback(
			'/\{([a-zA-Z0-9_.]+)\}/',
			static function ( array $m ) use ( $context ): string {
				return self::resolve_token( $m[1], $context );
			},
			$template
		);
	}

	/**
	 * Resolve a single token name against the context. Returns the raw token
	 * when it matches nothing, so unknown placeholders survive verbatim.
	 *
	 * @param string $token   Token name without braces.
	 * @param array  $context Workflow context.
	 * @return string
	 */
	private static function resolve_token( string $token, array $context ): string {
		switch ( $token ) {
			case 'topic':
				return (string) ( $context['topic'] ?? '' );
			case 'workflow_name':
				return (string) ( $context['workflow_name'] ?? '' );
		}

		if ( 'previous_preview' === $token ) {
			return self::latest_previous( $context )['preview'] ?? '';
		}

		// {event.field.sub} — dot path into the event payload.
		if ( 0 === strpos( $token, 'event.' ) ) {
			return self::dot_path( is_array( $context['event'] ?? null ) ? $context['event'] : array(), substr( $token, 6 ) );
		}

		// {previous.field.sub} — dot path into the most recent step's reference.
		if ( 0 === strpos( $token, 'previous.' ) ) {
			$latest = self::latest_previous( $context );
			$ref    = is_array( $latest['reference'] ?? null ) ? $latest['reference'] : array();
			return self::dot_path( $ref, substr( $token, 9 ) );
		}

		// {action_type.field.sub} — most recent output of a given action type,
		// falling back to the direct parent's reference.
		if ( false !== strpos( $token, '.' ) ) {
			list( $type, $path ) = explode( '.', $token, 2 );
			foreach ( array_reverse( is_array( $context['previous'] ?? null ) ? $context['previous'] : array() ) as $entry ) {
				if ( (string) ( $entry['action_type'] ?? '' ) === $type && self::has_path( is_array( $entry['reference'] ?? null ) ? $entry['reference'] : array(), $path ) ) {
					return self::dot_path( (array) $entry['reference'], $path );
				}
			}
			$parent_ref = is_array( $context['parent_output']['reference'] ?? null ) ? $context['parent_output']['reference'] : array();
			if ( self::has_path( $parent_ref, $path ) ) {
				return self::dot_path( $parent_ref, $path );
			}
		}

		return '{' . $token . '}';
	}

	/**
	 * Most recent successful previous-step output (empty shell when none).
	 *
	 * @param array $context Workflow context.
	 * @return array
	 */
	public static function latest_previous( array $context ): array {
		$previous = is_array( $context['previous'] ?? null ) ? $context['previous'] : array();
		if ( $previous ) {
			$last = end( $previous );
			if ( is_array( $last ) ) {
				return $last;
			}
		}
		$parent = is_array( $context['parent_output'] ?? null ) ? $context['parent_output'] : array();
		return $parent ?: array();
	}

	/**
	 * Walk a dot-notation path inside a nested array.
	 *
	 * @param array  $data Data to walk.
	 * @param string $path Dot-separated key path.
	 * @return string Scalar value as string; arrays JSON-encoded; '' when missing.
	 */
	public static function dot_path( array $data, string $path ): string {
		$node = $data;
		foreach ( array_filter( explode( '.', $path ), 'strlen' ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return '';
			}
			$node = $node[ $part ];
		}
		if ( is_scalar( $node ) || null === $node ) {
			return (string) $node;
		}
		return (string) wp_json_encode( $node );
	}

	/**
	 * Whether a dot path resolves inside a nested array (value may be empty).
	 *
	 * @param array  $data Data to walk.
	 * @param string $path Dot-separated key path.
	 * @return bool
	 */
	public static function has_path( array $data, string $path ): bool {
		$node = $data;
		foreach ( array_filter( explode( '.', $path ), 'strlen' ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return false;
			}
			$node = $node[ $part ];
		}
		return true;
	}
}
