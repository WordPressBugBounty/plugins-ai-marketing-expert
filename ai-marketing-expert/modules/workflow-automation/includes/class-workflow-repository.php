<?php
/**
 * Workflow Repository — all database access for the Workflow Automation module.
 *
 * Isolated so controllers and the engine never touch $wpdb directly.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorkflowRepository {

	private string $workflows;
	private string $steps;
	private string $executions;
	private string $outputs;

	public function __construct() {
		global $wpdb;
		$p                = $wpdb->prefix;
		$this->workflows  = $p . 'aime_workflows';
		$this->steps      = $p . 'aime_workflow_steps';
		$this->executions = $p . 'aime_workflow_executions';
		$this->outputs    = $p . 'aime_workflow_outputs';
	}

	/* ── Workflows ──────────────────────────────────────── */

	/**
	 * @return array<int,object>
	 */
	public function all(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$this->workflows} ORDER BY created_at DESC" ) ?: array();
	}

	public function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->workflows} WHERE id = %d", $id ) );
		return $row ?: null;
	}

	/**
	 * Active, schedule-triggered workflows whose next_run_at is due.
	 *
	 * @param string $now   MySQL UTC datetime.
	 * @param int    $limit Max workflows per tick.
	 * @return array<int,object>
	 */
	public function due( string $now, int $limit = 10 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->workflows}
				 WHERE status = 'active'
				   AND trigger_type = 'schedule'
				   AND next_run_at IS NOT NULL
				   AND next_run_at <= %s
				 ORDER BY next_run_at ASC
				 LIMIT %d",
				$now,
				$limit
			)
		) ?: array();
	}

	public function count_active( ?int $exclude_id = null ): int {
		global $wpdb;
		if ( $exclude_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->workflows} WHERE status = 'active' AND id != %d",
				$exclude_id
			) );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->workflows} WHERE status = 'active'" );
	}

	/**
	 * @param array $data Column => value (pre-sanitized).
	 * @return int New workflow ID (0 on failure).
	 */
	public function create( array $data ): int {
		global $wpdb;
		$now             = current_time( 'mysql', true );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$ok = $wpdb->insert( $this->workflows, $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		return false !== $wpdb->update( $this->workflows, $data, array( 'id' => $id ) );
	}

	public function delete( int $id ): bool {
		global $wpdb;
		$wpdb->delete( $this->steps, array( 'workflow_id' => $id ) );
		$wpdb->delete( $this->outputs, array( 'workflow_id' => $id ) );
		$wpdb->delete( $this->executions, array( 'workflow_id' => $id ) );
		return false !== $wpdb->delete( $this->workflows, array( 'id' => $id ) );
	}

	public function set_next_run( int $id, ?string $next_run_at ): void {
		global $wpdb;
		// wpdb->update cannot emit SQL NULL for a string column, so branch.
		if ( null === $next_run_at ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$this->workflows} SET next_run_at = NULL, updated_at = %s WHERE id = %d",
				current_time( 'mysql', true ),
				$id
			) );
			return;
		}
		$wpdb->update(
			$this->workflows,
			array( 'next_run_at' => $next_run_at, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id )
		);
	}

	/**
	 * Record a completed run and advance the schedule in ONE statement.
	 *
	 * Previously two queries (run counter, then next_run_at): a crash between
	 * them left next_run_at in the past and caused a duplicate run on the next
	 * tick. A single UPDATE is atomic regardless of storage engine.
	 */
	public function mark_ran( int $id, ?string $next_run_at ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );

		if ( null === $next_run_at ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$this->workflows}
				    SET last_run_at = %s, run_count = run_count + 1, next_run_at = NULL, updated_at = %s
				  WHERE id = %d",
				$now,
				$now,
				$id
			) );
			return;
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$this->workflows}
			    SET last_run_at = %s, run_count = run_count + 1, next_run_at = %s, updated_at = %s
			  WHERE id = %d",
			$now,
			$next_run_at,
			$now,
			$id
		) );
	}

	/* ── Steps ──────────────────────────────────────────── */

	/**
	 * @return array<int,object>
	 */
	public function steps_for( int $workflow_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->steps} WHERE workflow_id = %d ORDER BY step_order ASC, id ASC",
			$workflow_id
		) ) ?: array();
	}

	public function count_steps( int $workflow_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->steps} WHERE workflow_id = %d",
			$workflow_id
		) );
	}

	/**
	 * Replace all steps for a workflow with the given list.
	 *
	 * @param int   $workflow_id Workflow ID.
	 * @param array $steps       Each: [step_key, parent_key, branch, action_type,
	 *                           config(array), tone_override, run_condition,
	 *                           position_x, position_y].
	 */
	public function replace_steps( int $workflow_id, array $steps ): void {
		global $wpdb;
		$wpdb->delete( $this->steps, array( 'workflow_id' => $workflow_id ) );

		$order = 0;
		$now   = current_time( 'mysql', true );
		foreach ( $steps as $step ) {
			$action_type = sanitize_text_field( $step['action_type'] ?? '' );
			if ( '' === $action_type ) {
				continue;
			}
			$branch = sanitize_key( $step['branch'] ?? 'default' );
			$wpdb->insert( $this->steps, array(
				'workflow_id'   => $workflow_id,
				'step_key'      => sanitize_text_field( $step['step_key'] ?? '' ),
				'parent_key'    => sanitize_text_field( $step['parent_key'] ?? '' ),
				'branch'        => in_array( $branch, array( 'default', 'yes', 'no' ), true ) ? $branch : 'default',
				'step_order'    => isset( $step['step_order'] ) ? (int) $step['step_order'] : $order,
				'action_type'   => $action_type,
				'config'        => wp_json_encode( is_array( $step['config'] ?? null ) ? $step['config'] : array() ),
				'tone_override' => ! empty( $step['tone_override'] ) ? sanitize_text_field( $step['tone_override'] ) : null,
				'run_condition' => sanitize_text_field( $step['run_condition'] ?? 'always' ),
				'position_x'    => (int) ( $step['position_x'] ?? 0 ),
				'position_y'    => (int) ( $step['position_y'] ?? 0 ),
				'created_at'    => $now,
				'updated_at'    => $now,
			) );
			$order++;
		}
	}

	/* ── Executions ─────────────────────────────────────── */

	public function start_execution( int $workflow_id, string $trigger, int $steps_total, string $status = 'running', array $context = array() ): int {
		global $wpdb;
		$wpdb->insert( $this->executions, array(
			'workflow_id'  => $workflow_id,
			'trigger_type' => $trigger,
			'status'       => in_array( $status, array( 'queued', 'running' ), true ) ? $status : 'running',
			'steps_total'  => $steps_total,
			'context'      => $context ? wp_json_encode( $context ) : null,
			'started_at'   => current_time( 'mysql', true ),
		) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Runs counted against the free monthly cap: successful and partly
	 * successful executions this calendar month (UTC).
	 *
	 * Excluded on purpose:
	 *  - 'skipped' rows — the record of runs the cap itself blocked;
	 *  - 'failed' rows — a debugging session retrying broken steps must not
	 *    burn the same budget as real runs.
	 * Queued/running rows are not finished yet; they are counted the moment
	 * they land in an included state.
	 *
	 * @param int $exclude_id Execution row to leave out of the count — pass the
	 *                        current run's own pre-created row so it does not
	 *                        count against itself.
	 */
	public function count_runs_this_month( int $exclude_id = 0 ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->executions}
			  WHERE status IN ('success','partial','queued','running')
			    AND started_at >= %s AND id != %d",
			gmdate( 'Y-m-01 00:00:00' ),
			$exclude_id
		) );
	}

	/**
	 * True when the free plan's monthly run budget is exhausted (always false on Pro).
	 *
	 * @param int $exclude_execution_id Current run's own row, if pre-created.
	 */
	public function monthly_run_limit_reached( int $exclude_execution_id = 0 ): bool {
		return aime_limit_reached( 'workflow_runs_monthly', $this->count_runs_this_month( $exclude_execution_id ) );
	}

	/**
	 * Record a run the free plan blocked, so the history explains the silence.
	 */
	public function record_skipped_execution( int $workflow_id, string $trigger, string $reason ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( $this->executions, array(
			'workflow_id'  => $workflow_id,
			'trigger_type' => $trigger,
			'status'       => 'skipped',
			'steps_total'  => 0,
			'error'        => $reason,
			'started_at'   => $now,
			'finished_at'  => $now,
		) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Flip a queued execution to running (returns false if it was not queued —
	 * a second consumer already claimed it).
	 */
	public function mark_execution_running( int $execution_id ): bool {
		global $wpdb;
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$this->executions} SET status = 'running', started_at = %s WHERE id = %d AND status = 'queued'",
			current_time( 'mysql', true ),
			$execution_id
		) );
		return (bool) $updated;
	}

	public function finish_execution( int $execution_id, string $status, array $counts, string $error = '' ): void {
		global $wpdb;
		$wpdb->update(
			$this->executions,
			array(
				'status'          => $status,
				'steps_succeeded' => (int) ( $counts['success'] ?? 0 ),
				'steps_failed'    => (int) ( $counts['failed'] ?? 0 ),
				'steps_skipped'   => (int) ( $counts['skipped'] ?? 0 ),
				'error'           => $error,
				'finished_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $execution_id )
		);
	}

	/**
	 * @return array<int,object>
	 */
	public function executions_for( int $workflow_id, int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->executions} WHERE workflow_id = %d ORDER BY started_at DESC LIMIT %d",
			$workflow_id,
			$limit
		) ) ?: array();
	}

	/**
	 * Active workflows listening for a given event trigger.
	 *
	 * @return array<int,object>
	 */
	public function active_for_event( string $trigger_event ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->workflows}
			 WHERE status = 'active' AND trigger_type = 'event' AND trigger_event = %s",
			$trigger_event
		) ) ?: array();
	}

	/**
	 * Whether any active event-triggered workflow exists (per-request cached).
	 */
	public function has_event_workflows(): bool {
		global $wpdb;
		static $cached = null;
		if ( null === $cached ) {
			$cached = (bool) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->workflows} WHERE status = 'active' AND trigger_type = 'event'"
			);
		}
		return $cached;
	}

	/**
	 * Executions stuck in 'queued' longer than the given minutes (cron missed
	 * the one-shot event) — picked up by the dispatcher for inline rescue.
	 *
	 * @return array<int,object>
	 */
	public function stale_queued( int $minutes = 2 ): array {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * MINUTE_IN_SECONDS ) );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->executions} WHERE status = 'queued' AND started_at < %s ORDER BY started_at ASC LIMIT 5",
			$cutoff
		) ) ?: array();
	}

	public function find_execution( int $execution_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->executions} WHERE id = %d", $execution_id ) );
		return $row ?: null;
	}

	/**
	 * Executions that did not fully succeed, newest first, across all workflows.
	 * Feeds the module-wide error log so a user never has to open each workflow's
	 * history to find out what broke.
	 *
	 * @return array<int,object>
	 */
	public function recent_problem_executions( int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT e.*, w.name AS workflow_name
			   FROM {$this->executions} e
			   LEFT JOIN {$this->workflows} w ON w.id = e.workflow_id
			  WHERE e.status IN ('failed', 'partial', 'skipped')
			  ORDER BY e.started_at DESC
			  LIMIT %d",
			$limit
		) ) ?: array();
	}

	/**
	 * Failed/skipped step outputs for a set of executions, so the error log can
	 * show the actual step-level reason without an extra request per run.
	 *
	 * @param array<int,int> $execution_ids Execution IDs.
	 * @return array<int,object>
	 */
	public function problem_outputs_for( array $execution_ids ): array {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $execution_ids ) );
		if ( ! $ids ) {
			return array();
		}
		$in = implode( ',', $ids );
		return $wpdb->get_results(
			"SELECT * FROM {$this->outputs}
			  WHERE execution_id IN ({$in})
			    AND status IN ('failed', 'skipped')
			    AND error != ''
			  ORDER BY execution_id DESC, step_order ASC, id ASC"
		) ?: array();
	}

	/**
	 * Close out runs stuck in 'running' past the engine lock TTL. A PHP fatal
	 * (memory limit, timeout, a provider SDK dying mid-request) kills the worker
	 * before finish_execution() runs, leaving a row that says "running" forever.
	 * Flipping it to failed with a reason is what makes those visible at all.
	 *
	 * @param int $minutes Age after which a running row is considered dead.
	 * @return int Rows closed.
	 */
	public function fail_stale_running( int $minutes ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * MINUTE_IN_SECONDS ) );
		$reason = __( 'Run stopped unexpectedly and never reported back. Usual causes: the AI request timed out, the site hit its PHP time or memory limit, or the server killed the background job. Try a shorter workflow or fewer AI steps per run.', 'ai-marketing-expert' );

		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$this->executions}
			    SET status = 'failed', error = %s, finished_at = %s
			  WHERE status = 'running' AND started_at < %s",
			$reason,
			current_time( 'mysql', true ),
			$cutoff
		) );
	}

	/* ── Outputs ────────────────────────────────────────── */

	public function add_output( array $data ): void {
		global $wpdb;
		$data['created_at'] = current_time( 'mysql', true );
		if ( isset( $data['reference'] ) && is_array( $data['reference'] ) ) {
			$data['reference'] = wp_json_encode( $data['reference'] );
		}
		$wpdb->insert( $this->outputs, $data );
	}

	/**
	 * @return array<int,object>
	 */
	public function outputs_for( int $execution_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->outputs} WHERE execution_id = %d ORDER BY step_order ASC, id ASC",
			$execution_id
		) ) ?: array();
	}

	/* ── Upcoming schedule ──────────────────────────────── */

	/**
	 * Active workflows with an upcoming next_run_at, soonest first.
	 *
	 * @return array<int,object>
	 */
	public function upcoming( int $limit = 20 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, next_run_at, schedule_type FROM {$this->workflows}
			 WHERE status = 'active' AND next_run_at IS NOT NULL
			 ORDER BY next_run_at ASC
			 LIMIT %d",
			$limit
		) ) ?: array();
	}

	/* ── Analytics ──────────────────────────────────────── */

	/*
	 * Everything below reads `started_at`, which is stored UTC, and groups by
	 * UTC days — the same convention the other modules' analytics use, so a
	 * cross-module comparison never has to reconcile two calendars.
	 *
	 * `queued` and `running` rows are excluded throughout: a run still in
	 * flight has no outcome, and counting it as anything but "unfinished"
	 * would move a rate that has not been earned yet.
	 *
	 * `skipped` is kept apart from the reliability figures on purpose. A
	 * skipped run is the free-plan cap refusing to start it — the automation
	 * did not fail, it was never given the chance, and folding those into a
	 * failure rate would tell a user their workflows are broken when the only
	 * thing broken is their plan.
	 */

	/**
	 * Run outcomes per UTC day, one row per (day, status) pair.
	 *
	 * @return array<int,object>
	 */
	public function runs_by_day( string $since ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(started_at) AS date, status, COUNT(*) AS count
			 FROM {$this->executions}
			 WHERE started_at >= %s AND status IN ('success','partial','failed','skipped')
			 GROUP BY DATE(started_at), status
			 ORDER BY date ASC",
			$since
		) ) ?: array();
	}

	/**
	 * Run counts by outcome across the whole window.
	 *
	 * @return array<string,int> Keyed by status.
	 */
	public function outcome_totals( string $since ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) AS count
			 FROM {$this->executions}
			 WHERE started_at >= %s
			 GROUP BY status",
			$since
		) ) ?: array();

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row->status ] = (int) $row->count;
		}
		return $out;
	}

	/**
	 * Run counts by what started them — schedule, event, or a manual Run now.
	 *
	 * @return array<string,int> Keyed by trigger_type.
	 */
	public function trigger_totals( string $since ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT trigger_type, COUNT(*) AS count
			 FROM {$this->executions}
			 WHERE started_at >= %s AND status IN ('success','partial','failed')
			 GROUP BY trigger_type",
			$since
		) ) ?: array();

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row->trigger_type ] = (int) $row->count;
		}
		return $out;
	}

	/**
	 * Step-level tallies summed over every finished run in the window.
	 *
	 * A run-level rate cannot tell "died on step 1 of 8" apart from "seven of
	 * eight steps landed"; this is what separates them.
	 *
	 * @return array<string,int>
	 */
	public function step_totals( string $since ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(SUM(steps_succeeded), 0) AS succeeded,
			        COALESCE(SUM(steps_failed), 0)    AS failed,
			        COALESCE(SUM(steps_skipped), 0)   AS skipped
			 FROM {$this->executions}
			 WHERE started_at >= %s AND status IN ('success','partial','failed')",
			$since
		) );

		return array(
			'succeeded' => (int) ( $row->succeeded ?? 0 ),
			'failed'    => (int) ( $row->failed ?? 0 ),
			'skipped'   => (int) ( $row->skipped ?? 0 ),
		);
	}

	/**
	 * How long finished runs took, in seconds.
	 *
	 * @return array<string,int>
	 */
	public function duration_totals( string $since ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(ROUND(AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at))), 0) AS avg_seconds,
			        COALESCE(MAX(TIMESTAMPDIFF(SECOND, started_at, finished_at)), 0)         AS max_seconds
			 FROM {$this->executions}
			 WHERE started_at >= %s
			   AND finished_at IS NOT NULL
			   AND status IN ('success','partial','failed')",
			$since
		) );

		return array(
			'avg_seconds' => (int) ( $row->avg_seconds ?? 0 ),
			'max_seconds' => (int) ( $row->max_seconds ?? 0 ),
		);
	}

	/**
	 * Actions that failed at least once, with the attempt count they failed out
	 * of — a rate, not just a tally. Five failures from a hundred attempts and
	 * two from two are very different problems, and the raw count calls the
	 * wrong one urgent.
	 *
	 * @return array<int,object>
	 */
	public function action_failures( string $since, int $limit = 6 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT action_type,
			        COUNT(*) AS attempts,
			        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failures
			 FROM {$this->outputs}
			 WHERE created_at >= %s
			 GROUP BY action_type
			 HAVING failures > 0
			 ORDER BY failures DESC, attempts DESC
			 LIMIT %d",
			$since,
			$limit
		) ) ?: array();
	}

	/**
	 * Per-workflow reliability over the window.
	 *
	 * Inner join on purpose: a workflow that did not run has no reliability to
	 * report, and a table of dashes is not a finding. `no_run_workflows()`
	 * counts those separately so the card can still say they exist.
	 *
	 * @return array<int,object>
	 */
	public function workflow_reliability( string $since, int $limit = 10 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT w.id, w.name, w.status,
			        COUNT(e.id) AS runs,
			        SUM(CASE WHEN e.status = 'success' THEN 1 ELSE 0 END) AS ok,
			        SUM(CASE WHEN e.status = 'partial' THEN 1 ELSE 0 END) AS partial,
			        SUM(CASE WHEN e.status = 'failed'  THEN 1 ELSE 0 END) AS failed,
			        COALESCE(ROUND(AVG(CASE WHEN e.finished_at IS NOT NULL
			            THEN TIMESTAMPDIFF(SECOND, e.started_at, e.finished_at) END)), 0) AS avg_seconds,
			        MAX(e.started_at) AS last_run_at
			 FROM {$this->workflows} w
			 INNER JOIN {$this->executions} e
			         ON e.workflow_id = w.id
			        AND e.started_at >= %s
			        AND e.status IN ('success','partial','failed')
			 GROUP BY w.id, w.name, w.status
			 ORDER BY runs DESC, w.name ASC
			 LIMIT %d",
			$since,
			$limit
		) ) ?: array();
	}

	/**
	 * Workflows that did not run at all in the window, split by whether that is
	 * expected. A paused workflow being quiet is the system working; an *active*
	 * one being quiet is the finding.
	 *
	 * @return array<string,int>
	 */
	public function idle_workflow_counts( string $since ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
			    SUM(CASE WHEN w.status = 'active' THEN 1 ELSE 0 END) AS idle_active,
			    COUNT(*) AS idle_total
			 FROM {$this->workflows} w
			 WHERE NOT EXISTS (
			     SELECT 1 FROM {$this->executions} e
			      WHERE e.workflow_id = w.id
			        AND e.started_at >= %s
			        AND e.status IN ('success','partial','failed')
			 )",
			$since
		) );

		return array(
			'idle_active' => (int) ( $row->idle_active ?? 0 ),
			'idle_total'  => (int) ( $row->idle_total ?? 0 ),
		);
	}

	/**
	 * Workflow counts by status, all time — the denominator for "how much of
	 * what you built is actually switched on".
	 *
	 * @return array<string,int>
	 */
	public function workflow_status_totals(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS count FROM {$this->workflows} GROUP BY status"
		) ?: array();

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row->status ] = (int) $row->count;
		}
		return $out;
	}

	/* ── Maintenance ────────────────────────────────────── */

	public function prune_executions( int $days ): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$old_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$this->executions} WHERE started_at < %s",
			$cutoff
		) );

		if ( empty( $old_ids ) ) {
			return;
		}

		$in = implode( ',', array_map( 'intval', $old_ids ) );
		$wpdb->query( "DELETE FROM {$this->outputs} WHERE execution_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$this->executions} WHERE id IN ({$in})" );
	}
}
