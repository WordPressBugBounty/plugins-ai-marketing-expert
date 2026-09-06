<?php
/**
 * Condition action — evaluates a check and routes the workflow down its
 * Yes or No branch. The engine reads reference['branch'] to decide which
 * children to enqueue.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions;

use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\WorkflowTokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConditionAction extends BaseAction {

	/**
	 * @param array $config  Step config: check, field, value.
	 * @param array $context Workflow context (parent_output, event, previous).
	 * @return array
	 */
	public static function run( array $config, array $context ): array {
		$check       = (string) ( $config['check'] ?? 'previous_step_succeeded' );
		$value       = (string) ( $config['value'] ?? '' );
		$branch_error = '';

		switch ( $check ) {
			case 'previous_output_contains':
				$haystack = (string) ( $context['parent_output']['preview'] ?? '' );
				$matched  = '' !== $value && false !== mb_stripos( $haystack, $value );
				break;

			case 'event_field_contains':
				$field    = trim( (string) ( $config['field'] ?? '' ) );
				$haystack = WorkflowTokens::dot_path(
					is_array( $context['event'] ?? null ) ? $context['event'] : array(),
					$field
				);
				$matched  = '' !== $value && false !== mb_stripos( $haystack, $value );
				break;

			case 'reference_compare':
				// Numeric compare against an upstream step's structured
				// reference field — e.g. gate publishing on the SEO audit's
				// score: ref_field "score", compare ">=", value 80.
				$matched = self::reference_compare( $config, $context, $branch_error );
				break;
			case 'previous_step_succeeded':
			default:
				// The engine passes the parent's success flag through the queue;
				// root-level conditions (no parent) count as succeeded.
				$matched = (bool) ( $context['parent_success'] ?? true );
				break;
		}

		$branch = $matched ? 'yes' : 'no';

		$preview = sprintf(
			/* translators: %s: taken branch (yes/no) */
			__( 'Condition → %s', 'ai-marketing-expert' ),
			$branch
		);
		if ( '' !== $branch_error ) {
			$preview .= ' (' . $branch_error . ')';
		}

		return self::ok( $preview, array( 'branch' => $branch ) );
	}

	/**
	 * Numeric comparison of a reference field from upstream output.
	 *
	 * Source priority: parent output reference → most recent matching output
	 * in `previous`. Non-numeric or missing fields take the No branch (with a
	 * logged reason rather than silently passing).
	 *
	 * @param array  $config       Step config: ref_field, compare, value.
	 * @param array  $context      Workflow context.
	 * @param string &$error_msg   Set when the comparison could not run.
	 * @return bool
	 */
	private static function reference_compare( array $config, array $context, ?string &$error_msg = null ): bool {
		$path    = trim( (string) ( $config['ref_field'] ?? 'score' ) );
		$compare = (string) ( $config['compare'] ?? '>=' );
		$target  = (float) ( $config['value'] ?? 0 );

		$source_ref = is_array( $context['parent_output']['reference'] ?? null ) ? $context['parent_output']['reference'] : array();
		if ( ! WorkflowTokens::has_path( $source_ref, $path ) ) {
			foreach ( array_reverse( is_array( $context['previous'] ?? null ) ? $context['previous'] : array() ) as $entry ) {
				$ref = is_array( $entry['reference'] ?? null ) ? $entry['reference'] : array();
				if ( WorkflowTokens::has_path( $ref, $path ) ) {
					$source_ref = $ref;
					break;
				}
			}
		}

		if ( '' === $path || ! WorkflowTokens::has_path( $source_ref, $path ) ) {
			$error_msg = __( 'Reference field not found on any previous step output.', 'ai-marketing-expert' );
			return false;
		}

		$raw = WorkflowTokens::dot_path( $source_ref, $path );
		if ( ! is_numeric( $raw ) ) {
			/* translators: %s: reference field path. */
			$error_msg = sprintf( __( 'Reference field "%s" is not numeric.', 'ai-marketing-expert' ), $path );
			return false;
		}

		$actual = (float) $raw;
		switch ( $compare ) {
			case '>':
				return $actual > $target;
			case '<':
				return $actual < $target;
			case '<=':
				return $actual <= $target;
			case '=':
			case '==':
				return abs( $actual - $target ) < PHP_FLOAT_EPSILON;
			case '!=':
				return abs( $actual - $target ) >= PHP_FLOAT_EPSILON;
			case '>=':
			default:
				return $actual >= $target;
		}
	}
}
