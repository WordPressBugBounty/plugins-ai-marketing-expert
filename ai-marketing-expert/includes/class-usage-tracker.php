<?php
/**
 * AI usage tracker — records token usage per generation call and
 * provides aggregated summaries with cost estimates.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UsageTracker {

	private const RETENTION_DAYS = 90;

	/**
	 * Table name (with prefix).
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aime_ai_usage';
	}

	/**
	 * Record a single generation attempt.
	 *
	 * @param array  $conn    Connection array (id, provider, name).
	 * @param string $model   Model ID used.
	 * @param string $task    Task type ('text', 'image', ...).
	 * @param array  $usage   { prompt_tokens: int, completion_tokens: int }.
	 * @param bool   $success Whether the call succeeded.
	 */
	public static function record( array $conn, string $model, string $task, array $usage, bool $success ): void {
		global $wpdb;

		/**
		 * Filter to disable AI usage tracking entirely.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'aime_track_ai_usage', true ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table(),
			array(
				'connection_id'     => sanitize_text_field( $conn['id'] ?? '' ),
				'connection_name'   => sanitize_text_field( $conn['name'] ?? '' ),
				'provider'          => sanitize_key( $conn['provider'] ?? '' ),
				'model'             => sanitize_text_field( $model ),
				'task'              => sanitize_key( $task ),
				'prompt_tokens'     => absint( $usage['prompt_tokens'] ?? 0 ),
				'completion_tokens' => absint( $usage['completion_tokens'] ?? 0 ),
				'success'           => $success ? 1 : 0,
				'created_at'        => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * Aggregated usage summary for the dashboard.
	 *
	 * @param int $days Look-back window in days (1–365).
	 * @return array { totals: array, by_model: array[], by_day: array[] }
	 */
	public static function get_summary( int $days = 30 ): array {
		global $wpdb;

		$days  = max( 1, min( 365, $days ) );
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-controlled.
		$by_model = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT connection_id, connection_name, provider, model, task,
					COUNT(*) AS calls,
					SUM(success) AS successes,
					SUM(prompt_tokens) AS prompt_tokens,
					SUM(completion_tokens) AS completion_tokens
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY connection_id, connection_name, provider, model, task
				ORDER BY calls DESC",
				$since
			),
			ARRAY_A
		);

		$by_day = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day,
					COUNT(*) AS calls,
					SUM(success) AS successes,
					SUM(prompt_tokens) AS prompt_tokens,
					SUM(completion_tokens) AS completion_tokens
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY DATE(created_at)
				ORDER BY day ASC",
				$since
			),
			ARRAY_A
		);
		// phpcs:enable

		$totals = array(
			'calls'             => 0,
			'successes'         => 0,
			'failures'          => 0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'estimated_cost'    => 0.0,
			'cost_is_partial'   => false,
		);

		$rows = array();
		foreach ( (array) $by_model as $row ) {
			$row['calls']             = absint( $row['calls'] );
			$row['successes']         = absint( $row['successes'] );
			$row['failures']          = $row['calls'] - $row['successes'];
			$row['prompt_tokens']     = absint( $row['prompt_tokens'] );
			$row['completion_tokens'] = absint( $row['completion_tokens'] );
			$row['estimated_cost']    = self::estimate_cost( $row['provider'], $row['model'], $row['prompt_tokens'], $row['completion_tokens'] );

			$totals['calls']             += $row['calls'];
			$totals['successes']         += $row['successes'];
			$totals['failures']          += $row['failures'];
			$totals['prompt_tokens']     += $row['prompt_tokens'];
			$totals['completion_tokens'] += $row['completion_tokens'];

			if ( null === $row['estimated_cost'] ) {
				$totals['cost_is_partial'] = true;
			} else {
				$totals['estimated_cost'] += $row['estimated_cost'];
			}

			$rows[] = $row;
		}

		$totals['estimated_cost'] = round( $totals['estimated_cost'], 4 );

		$daily = array();
		foreach ( (array) $by_day as $row ) {
			$daily[] = array(
				'day'               => $row['day'],
				'calls'             => absint( $row['calls'] ),
				'successes'         => absint( $row['successes'] ),
				'prompt_tokens'     => absint( $row['prompt_tokens'] ),
				'completion_tokens' => absint( $row['completion_tokens'] ),
			);
		}

		return array(
			'days'     => $days,
			'totals'   => $totals,
			'by_model' => $rows,
			'by_day'   => $daily,
		);
	}

	/**
	 * Estimate cost in USD for a token count, or null when pricing is unknown.
	 *
	 * @return float|null
	 */
	public static function estimate_cost( string $provider, string $model, int $prompt_tokens, int $completion_tokens ): ?float {
		$pricing = self::get_pricing_map();
		$model_l = strtolower( $model );

		$match = null;
		// Longest prefix wins so "gpt-4o-mini" beats "gpt-4o".
		$best_len = 0;
		foreach ( $pricing as $prefix => $rates ) {
			if ( 0 === strpos( $model_l, $prefix ) && strlen( $prefix ) > $best_len ) {
				$match    = $rates;
				$best_len = strlen( $prefix );
			}
		}

		if ( null === $match ) {
			return null;
		}

		// Rates are USD per 1M tokens: array( input, output ).
		return round( ( $prompt_tokens * $match[0] + $completion_tokens * $match[1] ) / 1000000, 6 );
	}

	/**
	 * USD per 1M tokens, keyed by lowercase model-ID prefix.
	 * Approximate public list prices; filterable for corrections and additions.
	 */
	private static function get_pricing_map(): array {
		$map = array(
			// OpenAI.
			'gpt-4o-mini'       => array( 0.15, 0.60 ),
			'gpt-4o'            => array( 2.50, 10.00 ),
			'gpt-4.1-nano'      => array( 0.10, 0.40 ),
			'gpt-4.1-mini'      => array( 0.40, 1.60 ),
			'gpt-4.1'           => array( 2.00, 8.00 ),
			'gpt-5-nano'        => array( 0.05, 0.40 ),
			'gpt-5-mini'        => array( 0.25, 2.00 ),
			'gpt-5'             => array( 1.25, 10.00 ),
			'o3-mini'           => array( 1.10, 4.40 ),
			'o4-mini'           => array( 1.10, 4.40 ),
			// Google.
			'gemini-2.0-flash-lite' => array( 0.075, 0.30 ),
			'gemini-2.0-flash'  => array( 0.10, 0.40 ),
			'gemini-2.5-flash-lite' => array( 0.10, 0.40 ),
			'gemini-2.5-flash'  => array( 0.30, 2.50 ),
			'gemini-2.5-pro'    => array( 1.25, 10.00 ),
			// Anthropic.
			'claude-3-5-haiku'  => array( 0.80, 4.00 ),
			'claude-haiku-4-5'  => array( 1.00, 5.00 ),
			'claude-sonnet-4-5' => array( 3.00, 15.00 ),
			'claude-sonnet-4'   => array( 3.00, 15.00 ),
			'claude-opus-4'     => array( 15.00, 75.00 ),
		);

		/**
		 * Filter the model pricing map used for cost estimates.
		 *
		 * @param array $map { 'model-prefix' => array( usd_per_1m_input, usd_per_1m_output ) }.
		 */
		return (array) apply_filters( 'aime_ai_model_pricing', $map );
	}

	/**
	 * Delete usage rows older than the retention window. Hooked to daily cleanup.
	 */
	public static function cleanup(): void {
		global $wpdb;

		$retention = absint( apply_filters( 'aime_ai_usage_retention_days', self::RETENTION_DAYS ) );
		if ( $retention < 1 ) {
			return;
		}

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
