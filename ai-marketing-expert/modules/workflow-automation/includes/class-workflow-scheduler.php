<?php
/**
 * Workflow Scheduler — computes next_run_at from a workflow's schedule config.
 *
 * All timestamps are computed and stored in UTC (MySQL datetime). The admin UI
 * is responsible for converting to/from the site timezone for display.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorkflowScheduler {

	/**
	 * Compute the next UTC run time for a workflow after a given reference time.
	 *
	 * @param object    $workflow Workflow row.
	 * @param int|null  $after    Reference UNIX timestamp (defaults to now). For a
	 *                            just-completed run, pass the run time so the next
	 *                            occurrence is strictly in the future.
	 * @return string|null MySQL UTC datetime, or null for a one-shot that is done.
	 */
	public function compute_next_run( object $workflow, ?int $after = null ): ?string {
		$after = $after ?? time();
		$type  = $workflow->schedule_type ?? 'weekly';

		// Parse HH:MM (site-local wall-clock the user selected).
		list( $hour, $minute ) = $this->parse_time( $workflow->schedule_time ?? '09:00' );

		switch ( $type ) {
			case 'once':
				// One-shot: next_run_at is set at save time; once fired it is cleared.
				return null;

			case 'daily':
				return $this->next_daily( $after, $hour, $minute );

			case 'weekly':
				return $this->next_weekly( $after, $hour, $minute, (string) ( $workflow->schedule_days ?? '' ) );

			case 'monthly':
				return $this->next_monthly( $after, $hour, $minute, (int) ( $workflow->schedule_day_of_month ?? 1 ) );

			case 'custom':
				return $this->next_interval( $after, (int) ( $workflow->interval_value ?? 1 ), (string) ( $workflow->interval_unit ?? 'days' ) );

			default:
				return $this->next_daily( $after, $hour, $minute );
		}
	}

	/**
	 * Compute the first run for a newly-saved workflow.
	 *
	 * For a "once" schedule the datetime is taken verbatim from schedule config
	 * (schedule_time is treated as a full datetime via next_run_at in the
	 * controller); here we just delegate to compute_next_run for recurring types.
	 *
	 * @param object $workflow Workflow row.
	 * @return string|null
	 */
	public function first_run( object $workflow ): ?string {
		if ( 'once' === ( $workflow->schedule_type ?? '' ) ) {
			// The controller stores the chosen datetime directly in next_run_at.
			return $workflow->next_run_at ?? null;
		}
		return $this->compute_next_run( $workflow );
	}

	/* ── Helpers ────────────────────────────────────────── */

	/**
	 * @return array{0:int,1:int} [hour, minute]
	 */
	private function parse_time( string $time ): array {
		$parts  = explode( ':', $time );
		$hour   = isset( $parts[0] ) ? max( 0, min( 23, (int) $parts[0] ) ) : 9;
		$minute = isset( $parts[1] ) ? max( 0, min( 59, (int) $parts[1] ) ) : 0;
		return array( $hour, $minute );
	}

	/**
	 * Format a UNIX timestamp as a UTC MySQL datetime string.
	 *
	 * Timestamps produced by local_ts() are already true epoch values (the
	 * DateTimeImmutable constructor applies the site timezone), so no offset
	 * arithmetic is needed here — gmdate() alone yields UTC.
	 *
	 * @param int $ts UNIX timestamp.
	 * @return string
	 */
	private function to_utc( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Build a site-local UNIX timestamp for a given Y-m-d and H:i.
	 */
	private function local_ts( int $year, int $month, int $day, int $hour, int $minute ): int {
		$dt = new \DateTimeImmutable(
			sprintf( '%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute ),
			wp_timezone()
		);
		return $dt->getTimestamp();
	}

	private function next_daily( int $after, int $hour, int $minute ): string {
		$now_local = $after + wp_timezone()->getOffset( new \DateTimeImmutable( '@' . $after ) );
		$y = (int) gmdate( 'Y', $now_local );
		$m = (int) gmdate( 'n', $now_local );
		$d = (int) gmdate( 'j', $now_local );

		$candidate = $this->local_ts( $y, $m, $d, $hour, $minute );
		if ( $candidate <= $after ) {
			$candidate = $this->local_ts( $y, $m, $d + 1, $hour, $minute );
		}
		return $this->to_utc( $candidate );
	}

	/**
	 * @param string $days Comma-separated weekday numbers (0=Sun … 6=Sat).
	 */
	private function next_weekly( int $after, int $hour, int $minute, string $days ): string {
		$selected = array_filter( array_map( 'intval', array_filter( explode( ',', $days ), 'strlen' ) ), function ( $d ) {
			return $d >= 0 && $d <= 6;
		} );
		if ( empty( $selected ) ) {
			$selected = array( 1 ); // default Monday
		}

		// Search the next 14 days for the soonest matching weekday+time.
		$best = null;
		for ( $i = 0; $i <= 14; $i++ ) {
			$probe_local = $after + wp_timezone()->getOffset( new \DateTimeImmutable( '@' . $after ) ) + ( $i * DAY_IN_SECONDS );
			$y = (int) gmdate( 'Y', $probe_local );
			$m = (int) gmdate( 'n', $probe_local );
			$d = (int) gmdate( 'j', $probe_local );
			$candidate = $this->local_ts( $y, $m, $d, $hour, $minute );
			$weekday   = (int) gmdate( 'w', $candidate + wp_timezone()->getOffset( new \DateTimeImmutable( '@' . $candidate ) ) );

			if ( in_array( $weekday, $selected, true ) && $candidate > $after ) {
				$best = $candidate;
				break;
			}
		}

		if ( null === $best ) {
			$best = $this->local_ts(
				(int) gmdate( 'Y', $after ),
				(int) gmdate( 'n', $after ),
				(int) gmdate( 'j', $after ) + 7,
				$hour,
				$minute
			);
		}

		return $this->to_utc( $best );
	}

	private function next_monthly( int $after, int $hour, int $minute, int $day_of_month ): string {
		$day_of_month = max( 1, min( 31, $day_of_month ) );

		$offset    = wp_timezone()->getOffset( new \DateTimeImmutable( '@' . $after ) );
		$now_local = $after + $offset;
		$y = (int) gmdate( 'Y', $now_local );
		$m = (int) gmdate( 'n', $now_local );

		for ( $i = 0; $i <= 2; $i++ ) {
			$month = $m + $i;
			$year  = $y;
			while ( $month > 12 ) {
				$month -= 12;
				$year++;
			}
			$days_in_month = (int) gmdate( 't', $this->local_ts( $year, $month, 1, 0, 0 ) );
			$day           = min( $day_of_month, $days_in_month );
			$candidate     = $this->local_ts( $year, $month, $day, $hour, $minute );
			if ( $candidate > $after ) {
				return $this->to_utc( $candidate );
			}
		}

		// Fallback (should not hit): one month out.
		return $this->to_utc( $this->local_ts( $y, $m + 1, min( $day_of_month, 28 ), $hour, $minute ) );
	}

	private function next_interval( int $after, int $value, string $unit ): string {
		$value   = max( 1, $value );
		$seconds = 'hours' === $unit ? $value * HOUR_IN_SECONDS : $value * DAY_IN_SECONDS;
		return gmdate( 'Y-m-d H:i:s', $after + $seconds );
	}
}
