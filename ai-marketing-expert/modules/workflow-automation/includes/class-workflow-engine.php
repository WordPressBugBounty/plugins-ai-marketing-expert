<?php
/**
 * Workflow Engine — runs workflows as background jobs over a step graph.
 *
 * Steps form a tree (each step has a single parent referenced by parent_key;
 * '' means child of the trigger/root). The engine walks the tree breadth-first,
 * follows only the taken branch of condition steps, and records untaken
 * subtrees as skipped so counts always sum to steps_total.
 *
 * Never runs on a front-end page request. Guards against overlapping runs with
 * a short-lived transient lock, retries failed steps with exponential backoff,
 * and honours each workflow's failure policy.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorkflowEngine {

	private const MAX_RETRIES     = 3;
	private const LOCK_TTL        = 600; // 10 minutes.
	private const DISPATCH_LIMIT  = 10;
	private const STALE_QUEUED_MIN = 2;

	/**
	 * Loop guard: true while a workflow execution is in flight so the trigger
	 * dispatcher never re-triggers workflows from inside a running workflow
	 * (e.g. a workflow that publishes a post must not fire post_published).
	 */
	public static bool $executing = false;

	private WorkflowRepository $repo;
	private WorkflowScheduler $scheduler;

	public function __construct( ?WorkflowRepository $repo = null, ?WorkflowScheduler $scheduler = null ) {
		$this->repo      = $repo ?? new WorkflowRepository();
		$this->scheduler = $scheduler ?? new WorkflowScheduler();
	}

	/**
	 * Scan for due workflows and execute each (called on the five-minute cron).
	 * Also rescues executions stuck in 'queued' when the one-shot cron was missed.
	 */
	public function dispatch_due(): void {
		$now = current_time( 'mysql', true );
		$due = $this->repo->due( $now, self::DISPATCH_LIMIT );

		// Close out runs a fatal error left hanging, so the error log tells the
		// user something instead of showing "running" forever. The cutoff is
		// twice the lock TTL: a long but healthy run must never be rewritten to
		// "failed" underneath itself.
		$this->repo->fail_stale_running( (int) ( 2 * self::LOCK_TTL / MINUTE_IN_SECONDS ) );

		foreach ( $due as $workflow ) {
			$this->execute( (int) $workflow->id, 'schedule' );
		}

		// Stale-queued rescue: run inline anything the one-shot cron dropped.
		foreach ( $this->repo->stale_queued( self::STALE_QUEUED_MIN ) as $execution ) {
			$context = json_decode( (string) ( $execution->context ?? '' ), true );
			$this->execute(
				(int) $execution->workflow_id,
				(string) $execution->trigger_type,
				(int) $execution->id,
				is_array( $context ) ? $context : array()
			);
		}
	}

	/**
	 * Execute one workflow.
	 *
	 * @param int    $workflow_id   Workflow ID.
	 * @param string $trigger       manual|schedule|event.
	 * @param int    $execution_id  Pre-created queued execution (0 = create fresh).
	 * @param array  $event_payload Event payload exposed to steps as context['event'].
	 * @return array{success:bool,execution_id:int,status:string,error:string}
	 */
	public function execute( int $workflow_id, string $trigger = 'manual', int $execution_id = 0, array $event_payload = array() ): array {
		$workflow = $this->repo->find( $workflow_id );
		if ( ! $workflow ) {
			return $this->fail_result( 'Workflow not found.' );
		}

		// Claim the pre-created queued row first: if another consumer (cron +
		// stale rescue racing) already flipped it, this run is a duplicate.
		if ( $execution_id > 0 && ! $this->repo->mark_execution_running( $execution_id ) ) {
			return $this->fail_result( 'Execution already claimed.' );
		}

		// Free-plan monthly run cap — the one choke point every trigger type
		// passes through. The skipped row keeps the history honest, and the
		// schedule still advances so the workflow resumes by itself next month.
		if ( $this->repo->monthly_run_limit_reached( $execution_id ) ) {
			$reason = __( 'Monthly run limit reached on the free plan. Runs resume next month — upgrade to Pro for unlimited runs.', 'ai-marketing-expert' );
			if ( $execution_id > 0 ) {
				$this->repo->finish_execution( $execution_id, 'skipped', array(), $reason );
			} else {
				$this->repo->record_skipped_execution( $workflow_id, $trigger, $reason );
			}
			$this->advance_schedule_only( $workflow );
			aime_log( sprintf( 'Workflow #%d skipped: free monthly run limit reached.', $workflow_id ), 'info', 'workflow-automation' );
			return $this->fail_result( $reason );
		}

		// Concurrency guard: skip if a run is already in flight for this workflow.
		// add_option() is an atomic DB insert — two concurrent consumers cannot
		// both win it, unlike a transient check-then-set pair.
		if ( ! $this->acquire_lock( $workflow_id ) ) {
			aime_log( sprintf( 'Workflow #%d already running; skipping overlapping dispatch.', $workflow_id ), 'info', 'workflow-automation' );
			if ( $execution_id > 0 ) {
				$this->repo->finish_execution( $execution_id, 'failed', array(), __( 'Workflow already running.', 'ai-marketing-expert' ) );
			}
			return $this->fail_result( 'Workflow already running.' );
		}
		self::$executing = true;

		// Give long AI generations room to run.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$steps = $this->repo->steps_for( $workflow_id );

		if ( 0 === $execution_id ) {
			$execution_id = $this->repo->start_execution( $workflow_id, $trigger, count( $steps ), 'running', $event_payload );
		}

		$context = array(
			'topic'          => (string) $workflow->topic,
			'tone'           => (string) $workflow->tone,
			'brand_voice_id' => $workflow->brand_voice_id ? (int) $workflow->brand_voice_id : null,
			'created_by'     => ! empty( $workflow->created_by ) ? (int) $workflow->created_by : 0,
			'workflow_id'    => $workflow_id,
			'workflow_name'  => (string) $workflow->name,
			'event'          => $event_payload,
			'previous'       => array(),
		);

		$counts = array( 'success' => 0, 'failed' => 0, 'skipped' => 0 );
		$policy = (string) $workflow->failure_policy;
		$halted = false;
		// Set when the engine itself throws. Tracked separately from
		// $counts['failed'] because no step output row exists for it.
		$engine_error = false;
		// Human-readable reasons, surfaced on the execution row so the history
		// and error log explain a failure without expanding every step.
		$step_errors = array();

		// Build the children map: parent_key => steps sorted by step_order.
		$children = array();
		$by_key   = array();
		foreach ( $steps as $step ) {
			$children[ (string) $step->parent_key ][] = $step;
			$by_key[ (string) $step->step_key ]       = $step;
		}
		foreach ( $children as &$siblings ) {
			usort( $siblings, static fn ( $a, $b ) => $a->step_order <=> $b->step_order ?: $a->id <=> $b->id );
		}
		unset( $siblings );

		// BFS queue: each entry carries the step, its parent's success flag,
		// and the parent's output (exposed to the step as context['parent_output']).
		$queue = array();
		foreach ( $children[''] ?? array() as $root ) {
			$queue[] = array( 'step' => $root, 'parent_success' => true, 'parent_output' => null );
		}

		$executed = array(); // step_key => true, for the skipped-subtree sweep.

		try {
		while ( $queue ) {
			$entry = array_shift( $queue );
			$step  = $entry['step'];

			$executed[ (string) $step->step_key ] = true;

			// Run condition: 'if_previous_succeeded' means the *parent* step succeeded.
			if ( 'if_previous_succeeded' === $step->run_condition && ! $entry['parent_success'] ) {
				$this->record_output( $execution_id, $workflow_id, $step, 'skipped', __( 'Skipped: parent step did not succeed.', 'ai-marketing-expert' ), array(), '' );
				$counts['skipped']++;
				$this->enqueue_children( $queue, $children, $step, false, null, 'default' );
				continue;
			}

			$config = $this->decode_config( $step->config );

			// Pro enforcement (defense in depth behind the REST validator).
			if ( 'condition' === $step->action_type && ! aime_has_pro() ) {
				$this->record_output( $execution_id, $workflow_id, $step, 'failed', '', array(), __( 'Condition steps require Pro.', 'ai-marketing-expert' ) );
				$counts['failed']++;
				$step_errors[] = $this->label_error( $step, __( 'Condition steps require Pro.', 'ai-marketing-expert' ) );
				if ( 'stop' === $policy ) {
					$halted = true;
					break;
				}
				$this->enqueue_children( $queue, $children, $step, false, null, 'default' );
				continue;
			}

			// Per-step tone override + parent output layered onto workflow context.
			$step_context                   = $context;
			$step_context['step_id']        = (int) $step->id;
			$step_context['parent_output']  = $entry['parent_output'];
			$step_context['parent_success'] = (bool) $entry['parent_success'];
			if ( ! empty( $step->tone_override ) ) {
				$step_context['tone'] = (string) $step->tone_override;
			}

			$result = $this->run_step_with_retry( (string) $step->action_type, $config, $step_context, $policy );

			if ( ! empty( $result['skipped'] ) ) {
				$this->record_output( $execution_id, $workflow_id, $step, 'skipped', $result['error'], array(), $result['error'] );
				$counts['skipped']++;
				$this->enqueue_children( $queue, $children, $step, false, null, 'default' );
				continue;
			}

			$output = array(
				'action_type' => (string) $step->action_type,
				'preview'     => $result['preview'],
				'reference'   => $result['reference'],
			);

			if ( $result['success'] ) {
				$this->record_output( $execution_id, $workflow_id, $step, 'success', $result['preview'], $result['reference'], '' );
				$counts['success']++;
				$context['previous'][] = $output;

				if ( 'condition' === $step->action_type ) {
					// Follow only the taken branch; record the other subtree skipped.
					$taken = ( 'no' === ( $result['reference']['branch'] ?? 'yes' ) ) ? 'no' : 'yes';
					$this->enqueue_children( $queue, $children, $step, true, $output, $taken );
					$this->skip_subtree( $execution_id, $workflow_id, $children, $step, $taken, $counts, $executed );
				} else {
					$this->enqueue_children( $queue, $children, $step, true, $output, 'default' );
				}
			} else {
				$this->record_output( $execution_id, $workflow_id, $step, 'failed', $result['preview'], $result['reference'], $result['error'] );
				$counts['failed']++;
				$step_errors[] = $this->label_error( $step, $result['error'] );

				if ( 'stop' === $policy ) {
					$halted = true;
					break;
				}
				// 'skip' and 'retry' (already retried) both continue to children.
				$this->enqueue_children( $queue, $children, $step, false, $output, 'default' );
			}
		}
		} catch ( \Throwable $e ) {
			// The action registry already traps handler exceptions, so reaching
			// here means the engine itself broke. Record it rather than letting
			// the execution row hang in 'running'.
			$halted        = true;
			$engine_error  = true;
			$step_errors[] = sprintf(
				/* translators: 1: error message, 2: file, 3: line. */
				__( 'Workflow engine error: %1$s (%2$s:%3$d)', 'ai-marketing-expert' ),
				$e->getMessage(),
				basename( $e->getFile() ),
				$e->getLine()
			);
			// No counts['failed']++ here: no step output row was written, and
			// the sweep below records every unreached step as skipped, so
			// incrementing would push the totals past steps_total.
			aime_log( sprintf( 'Workflow #%d engine error: %s', $workflow_id, $e->getMessage() ), 'error', 'workflow-automation' );
		}

		// Anything never reached (halted early, or orphaned by data issues)
		// is recorded skipped so counts sum to steps_total.
		foreach ( $steps as $step ) {
			if ( ! isset( $executed[ (string) $step->step_key ] ) ) {
				$this->record_output( $execution_id, $workflow_id, $step, 'skipped', $halted ? __( 'Skipped: workflow halted.', 'ai-marketing-expert' ) : __( 'Skipped: branch not taken.', 'ai-marketing-expert' ), array(), '' );
				$counts['skipped']++;
				$executed[ (string) $step->step_key ] = true;
			}
		}

		// Determine overall status. An engine-level crash has no failed step row,
		// so it must be folded in here or a broken run reports success.
		if ( $engine_error ) {
			$status = $counts['success'] > 0 ? 'partial' : 'failed';
		} elseif ( $counts['failed'] > 0 && $counts['success'] > 0 ) {
			$status = 'partial';
		} elseif ( $counts['failed'] > 0 && 0 === $counts['success'] ) {
			$status = 'failed';
		} else {
			$status = 'success';
		}

		// Error summary: the actual step reasons, so the history row and the
		// error log answer "why did this fail?" without drilling in.
		$error_summary = implode( ' | ', array_slice( array_filter( $step_errors ), 0, 3 ) );
		if ( $halted ) {
			$suffix = __( 'Stopped after the first failure (failure policy: stop).', 'ai-marketing-expert' );
			$error_summary = '' !== $error_summary ? $error_summary . ' — ' . $suffix : $suffix;
		}
		$this->repo->finish_execution( $execution_id, $status, $counts, mb_substr( $error_summary, 0, 1000 ) );

		// Recompute the schedule.
		$this->reschedule( $workflow );

		$this->release_lock( $workflow_id );
		self::$executing = false;

		// One digest per run (not one email per failed step) so a multi-step
		// failure cannot flood the admin inbox.
		if ( $step_errors ) {
			$this->send_failure_digest( $workflow, $step_errors, $execution_id, $status );
		}

		aime_log(
			sprintf( 'Workflow #%d "%s" ran (%s): %d ok, %d failed, %d skipped.', $workflow_id, $workflow->name, $status, $counts['success'], $counts['failed'], $counts['skipped'] ),
			'failed' === $status ? 'warning' : 'info',
			'workflow-automation'
		);

		return array(
			'success'      => 'failed' !== $status,
			'execution_id' => $execution_id,
			'status'       => $status,
			'error'        => $error_summary,
		);
	}

	/* ── Graph helpers ──────────────────────────────────── */

	/**
	 * Enqueue a step's children on the given branch.
	 *
	 * @param array       $queue          BFS queue (by reference).
	 * @param array       $children       parent_key => steps map.
	 * @param object      $step           The step whose children to enqueue.
	 * @param bool        $parent_success Whether this step succeeded.
	 * @param array|null  $parent_output  This step's output for child context.
	 * @param string      $branch         'default'|'yes'|'no' — which children to follow.
	 */
	private function enqueue_children( array &$queue, array $children, object $step, bool $parent_success, ?array $parent_output, string $branch ): void {
		foreach ( $children[ (string) $step->step_key ] ?? array() as $child ) {
			// 'default' follows every child; a condition branch follows only its own.
			if ( 'default' !== $branch && (string) $child->branch !== $branch ) {
				continue;
			}
			$queue[] = array( 'step' => $child, 'parent_success' => $parent_success, 'parent_output' => $parent_output );
		}
	}

	/**
	 * Record a condition step's untaken subtree as skipped.
	 *
	 * @param array  $counts   Step counts (by reference).
	 * @param array  $executed Executed step keys (by reference).
	 */
	private function skip_subtree( int $execution_id, int $workflow_id, array $children, object $condition_step, string $taken_branch, array &$counts, array &$executed ): void {
		$stack = array();
		foreach ( $children[ (string) $condition_step->step_key ] ?? array() as $child ) {
			if ( (string) $child->branch !== $taken_branch ) {
				$stack[] = $child;
			}
		}

		while ( $stack ) {
			$step = array_pop( $stack );
			if ( isset( $executed[ (string) $step->step_key ] ) ) {
				continue;
			}
			$executed[ (string) $step->step_key ] = true;
			$this->record_output( $execution_id, $workflow_id, $step, 'skipped', __( 'Skipped: branch not taken.', 'ai-marketing-expert' ), array(), '' );
			$counts['skipped']++;
			foreach ( $children[ (string) $step->step_key ] ?? array() as $child ) {
				$stack[] = $child;
			}
		}
	}

	/* ── Step execution ─────────────────────────────────── */

	/**
	 * Run a single step, retrying with exponential backoff when policy = retry.
	 *
	 * @return array Action result (with optional 'skipped' flag).
	 */
	private function run_step_with_retry( string $action_type, array $config, array $context, string $policy ): array {
		$result   = ActionRegistry::run( $action_type, $config, $context );
		$attempts = 0;

		if ( 'retry' !== $policy ) {
			return $result;
		}

		while ( ! $result['success'] && empty( $result['skipped'] ) && $attempts < self::MAX_RETRIES ) {
			$attempts++;
			$backoff = (int) pow( 2, $attempts ); // 2s, 4s, 8s.
			sleep( min( $backoff, 8 ) );
			aime_log( sprintf( 'Retrying workflow step "%s" (attempt %d/%d).', $action_type, $attempts, self::MAX_RETRIES ), 'info', 'workflow-automation' );
			$result = ActionRegistry::run( $action_type, $config, $context );
		}

		return $result;
	}

	/**
	 * Advance a schedule-triggered workflow to its next occurrence without
	 * recording a run (used when the free plan blocks a due run: run_count
	 * and last_run_at must stay untouched, but the workflow must not stay
	 * "due" or the five-minute cron would retry it forever).
	 */
	private function advance_schedule_only( object $workflow ): void {
		if ( 'schedule' !== $workflow->trigger_type ) {
			return;
		}
		if ( 'once' === $workflow->schedule_type ) {
			$this->repo->set_next_run( (int) $workflow->id, null );
			return;
		}
		$this->repo->set_next_run( (int) $workflow->id, $this->scheduler->compute_next_run( $workflow, time() ) );
	}

	private function reschedule( object $workflow ): void {
		if ( 'schedule' !== $workflow->trigger_type ) {
			// Event/manual runs still count as a run, but have no next occurrence.
			$this->repo->mark_ran( (int) $workflow->id, $workflow->next_run_at ?: null );
			return;
		}

		if ( 'once' === $workflow->schedule_type ) {
			// One-shot: clear next_run and mark ran.
			$this->repo->mark_ran( (int) $workflow->id, null );
			return;
		}

		$next = $this->scheduler->compute_next_run( $workflow, time() );
		$this->repo->mark_ran( (int) $workflow->id, $next );
	}

	/* ── Persistence helpers ────────────────────────────── */

	private function record_output( int $execution_id, int $workflow_id, object $step, string $status, string $preview, array $reference, string $error ): void {
		$this->repo->add_output( array(
			'execution_id' => $execution_id,
			'workflow_id'  => $workflow_id,
			'step_id'      => (int) $step->id,
			'step_key'     => (string) ( $step->step_key ?? '' ),
			'branch'       => (string) ( $step->branch ?? 'default' ),
			'step_order'   => (int) $step->step_order,
			'action_type'  => (string) $step->action_type,
			'status'       => $status,
			'preview'      => mb_substr( $preview, 0, 2000 ),
			'reference'    => $reference,
			'error'        => mb_substr( $error, 0, 1000 ),
		) );
	}

	private function decode_config( ?string $json ): array {
		if ( empty( $json ) ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Prefix a step error with the action's human label so a summary line reads
	 * "Blog Post: AI provider returned no response" rather than a bare message.
	 */
	private function label_error( object $step, string $error ): string {
		$error = trim( $error );
		if ( '' === $error ) {
			$error = __( 'Step failed without reporting a reason.', 'ai-marketing-expert' );
		}
		$def   = ActionRegistry::get( (string) $step->action_type );
		$label = (string) ( $def['label'] ?? $step->action_type );

		return $label . ': ' . $error;
	}

	/**
	 * Send ONE digest email per execution summarizing every failed step.
	 * @param object            $workflow     Workflow row.
	 * @param array<int,string> $step_errors  Labeled step error messages.
	 * @param int               $execution_id Execution row ID (0 when unknown).
	 * @param string            $status       Final execution status.
	 */
	private function send_failure_digest( object $workflow, array $step_errors, int $execution_id = 0, string $status = 'failed' ): void {
		$settings = get_option( 'aime_workflow-automation_settings', array() );
		if ( isset( $settings['failure_notifications'] ) && ! $settings['failure_notifications'] ) {
			return;
		}

		$to   = get_option( 'admin_email' );
		$list = '';
		foreach ( array_slice( array_filter( $step_errors ), 0, 5 ) as $i => $err ) {
			$list .= sprintf( "%d) %s\n", $i + 1, $err );
		}
		$extra_total = max( 0, count( $step_errors ) - 5 );
		if ( $extra_total > 0 ) {
			/* translators: %d: number of additional errors not shown. */
			$list .= sprintf( __( '…and %d more.', 'ai-marketing-expert' ), $extra_total ) . "\n";
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: workflow name, 3: run status */
			__( '[%1$s] Workflow issue: %2$s (%3$s)', 'ai-marketing-expert' ),
			get_bloginfo( 'name' ),
			$workflow->name,
			$status
		);

		$body = sprintf(
			/* translators: 1: workflow name, 2: run status */
			__( "The workflow \"%1\$s\" finished with status: %2\$s.\n\nFailed steps:\n%3\$s", 'ai-marketing-expert' ),
			$workflow->name,
			$status,
			$list
		);
		if ( $execution_id > 0 ) {
			$body .= __( 'Open the workflow history for details:', 'ai-marketing-expert' ) . "\n"
				. admin_url( 'admin.php?page=ai-marketing-expert-workflow-automation' ) . "\n";
		}

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Atomic per-workflow run lock.
	 *
	 * add_option() fails when a row already exists — that INSERT is the mutex,
	 * safe against concurrent cron workers where a transient check-then-set is
	 * not. A lock older than LOCK_TTL belongs to a dead worker and is swept so
	 * one fatal error cannot wedge a workflow forever (the execution row itself
	 * is closed separately by fail_stale_running()).
	 */
	private function acquire_lock( int $workflow_id ): bool {
		global $wpdb;

		$key      = 'aime_wf_lock_' . $workflow_id;
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			$key
		) );

		if ( null !== $existing ) {
			if ( ( time() - (int) $existing ) < self::LOCK_TTL ) {
				return false;
			}
			// Stale lock from a crashed worker — clear it so add_option can win.
			delete_option( $key );
		}

		return (bool) add_option( $key, time(), '', 'no' );
	}

	private function release_lock( int $workflow_id ): void {
		delete_option( 'aime_wf_lock_' . $workflow_id );
	}

	private function fail_result( string $error ): array {
		return array(
			'success'      => false,
			'execution_id' => 0,
			'status'       => 'failed',
			'error'        => $error,
		);
	}
}
