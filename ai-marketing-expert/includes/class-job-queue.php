<?php
/**
 * Background job queue for AI generation.
 *
 * Jobs are stored in a dedicated table and processed via WP-Cron.
 * A single event is scheduled on enqueue for fast pickup; the worker
 * reschedules itself while pending jobs remain.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JobQueue {

	public const CRON_HOOK = 'aime_run_ai_jobs';

	private const MAX_ATTEMPTS   = 3;
	private const STALE_SECONDS  = 15 * MINUTE_IN_SECONDS;
	private const WORKER_SECONDS = 20;
	private const RETENTION_DAYS = 30;

	private const TYPES = array( 'generate', 'generate_image' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aime_ai_jobs';
	}

	/**
	 * Register cron worker hook. Called once from Plugin bootstrap.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );
	}

	/**
	 * Queue a job.
	 *
	 * @param string $type    Job type: 'generate' | 'generate_image'.
	 * @param array  $payload Job arguments (prompt, task, max_tokens, options / title, post_id).
	 * @return int|\WP_Error Job ID.
	 */
	public static function enqueue( string $type, array $payload ) {
		global $wpdb;

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error( 'aime_invalid_job_type', __( 'Invalid job type.', 'ai-marketing-expert' ) );
		}

		$prompt = trim( (string) ( $payload['prompt'] ?? '' ) );
		if ( '' === $prompt ) {
			return new \WP_Error( 'aime_empty_prompt', __( 'Job prompt cannot be empty.', 'ai-marketing-expert' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'type'       => $type,
				'payload'    => wp_json_encode( $payload ),
				'status'     => 'pending',
				'attempts'   => 0,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'aime_job_insert_failed', __( 'Could not queue the job.', 'ai-marketing-expert' ) );
		}

		$job_id = (int) $wpdb->insert_id;

		// Fast pickup: run the worker as soon as cron fires next.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}

		return $job_id;
	}

	/**
	 * Count jobs that are still queued or in flight.
	 *
	 * @return int
	 */
	public static function count_active(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status IN ( %s, %s )',
				self::table(),
				'pending',
				'processing'
			)
		);
	}

	/**
	 * Fetch a job by ID.
	 */
	public static function get( int $job_id ): ?array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ), ARRAY_A );

		return $row ? self::format_job( $row ) : null;
	}

	/**
	 * Latest jobs, newest first.
	 */
	public static function get_recent( int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );

		return array_map( array( __CLASS__, 'format_job' ), (array) $rows );
	}

	/**
	 * Cron worker: claim and run pending jobs for a bounded time slice.
	 */
	public static function process_queue(): void {
		global $wpdb;

		self::recover_stale_jobs();

		$table    = self::table();
		$deadline = time() + self::WORKER_SECONDS;

		while ( time() < $deadline ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$candidate = (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT 1" );
			if ( ! $candidate ) {
				break; // Nothing pending.
			}

			// Atomic claim: only one worker can flip this row to processing.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$claimed = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table}
				SET status = 'processing', started_at = %s, attempts = attempts + 1
				WHERE id = %d AND status = 'pending'",
				current_time( 'mysql', true ),
				$candidate
			) );

			if ( ! $claimed ) {
				continue; // Another worker took it; try the next one.
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $candidate ), ARRAY_A );

			if ( ! $job ) {
				break;
			}

			self::run_job( $job );
		}

		// More work left? Chain another worker run.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
		if ( $pending > 0 && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Execute a single claimed job and persist its outcome.
	 */
	private static function run_job( array $job ): void {
		global $wpdb;

		$payload = json_decode( $job['payload'] ?? '', true );
		$payload = is_array( $payload ) ? $payload : array();

		$result = array( 'success' => false, 'message' => __( 'Unknown job type.', 'ai-marketing-expert' ) );

		try {
			if ( 'generate' === $job['type'] ) {
				$result = AiProvider::generate(
					(string) ( $payload['prompt'] ?? '' ),
					sanitize_key( $payload['task'] ?? 'text' ),
					absint( $payload['max_tokens'] ?? 2048 ),
					is_array( $payload['options'] ?? null ) ? $payload['options'] : array()
				);
			} elseif ( 'generate_image' === $job['type'] ) {
				$result = AiProvider::generate_image(
					(string) ( $payload['prompt'] ?? '' ),
					sanitize_text_field( $payload['title'] ?? '' ),
					absint( $payload['post_id'] ?? 0 )
				);
			}
		} catch ( \Throwable $e ) {
			$result = array( 'success' => false, 'message' => $e->getMessage() );
		}

		$success = ! empty( $result['success'] );
		$error   = $success ? '' : (string) ( $result['message'] ?? __( 'Generation failed.', 'ai-marketing-expert' ) );

		if ( ! $success && (int) $job['attempts'] < self::MAX_ATTEMPTS ) {
			// Leave it for another attempt.
			$status = 'pending';
		} else {
			$status = $success ? 'done' : 'failed';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			self::table(),
			array(
				'status'       => $status,
				'result'       => $success ? wp_json_encode( $result ) : null,
				'error'        => substr( $error, 0, 1000 ),
				'completed_at' => in_array( $status, array( 'done', 'failed' ), true ) ? current_time( 'mysql', true ) : null,
			),
			array( 'id' => (int) $job['id'] ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Reset jobs stuck in 'processing' (e.g. after a fatal or timeout).
	 */
	private static function recover_stale_jobs(): void {
		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'pending'
			WHERE status = 'processing' AND started_at < %s AND attempts < %d",
			$cutoff,
			self::MAX_ATTEMPTS
		) );

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			SET status = 'failed', error = 'Job timed out.', completed_at = %s
			WHERE status = 'processing' AND started_at < %s AND attempts >= %d",
			current_time( 'mysql', true ),
			$cutoff,
			self::MAX_ATTEMPTS
		) );
		// phpcs:enable
	}

	/**
	 * Delete finished jobs older than the retention window. Hooked to daily cleanup.
	 */
	public static function cleanup(): void {
		global $wpdb;

		$retention = absint( apply_filters( 'aime_ai_jobs_retention_days', self::RETENTION_DAYS ) );
		if ( $retention < 1 ) {
			return;
		}

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE status IN ('done','failed') AND created_at < %s",
			$cutoff
		) );
	}

	/**
	 * Normalize a DB row for REST output.
	 */
	private static function format_job( array $row ): array {
		$result = json_decode( $row['result'] ?? '', true );

		return array(
			'id'           => (int) $row['id'],
			'type'         => $row['type'],
			'status'       => $row['status'],
			'attempts'     => (int) $row['attempts'],
			'result'       => is_array( $result ) ? $result : null,
			'error'        => (string) ( $row['error'] ?? '' ),
			'created_at'   => $row['created_at'],
			'started_at'   => $row['started_at'],
			'completed_at' => $row['completed_at'],
		);
	}
}
