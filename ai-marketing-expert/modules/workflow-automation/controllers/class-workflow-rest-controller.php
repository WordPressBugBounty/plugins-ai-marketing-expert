<?php
/**
 * Workflow REST Controller — CRUD + run-now + history under aime/v1.
 *
 * All routes require manage_options.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Controllers;

use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\WorkflowRepository;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\WorkflowScheduler;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\ActionRegistry;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\TriggerRegistry;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\TemplateRegistry;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\WorkflowAutomationModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorkflowRestController {

	private string $ns;
	private WorkflowRepository $repo;

	public function __construct() {
		$this->ns   = aime_rest_namespace();
		$this->repo = new WorkflowRepository();
	}

	public function admin_permission(): bool|\WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'ai-marketing-expert' ), array( 'status' => 403 ) );
	}

	public function register_routes(): void {
		$perm = array( $this, 'admin_permission' );
		$base = '/workflow-automation';

		register_rest_route( $this->ns, $base . '/actions', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_actions' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $this->ns, $base . '/triggers', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_triggers' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $this->ns, $base . '/templates', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_templates' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $this->ns, $base . '/templates/(?P<id>[a-z0-9_-]+)/apply', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'apply_template' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $this->ns, $base . '/workflows', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $perm,
			),
		) );

		register_rest_route( $this->ns, $base . '/workflows/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'PUT, POST, PATCH',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'destroy' ),
				'permission_callback' => $perm,
			),
		) );

		register_rest_route( $this->ns, $base . '/workflows/(?P<id>\d+)/run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'run_now' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $this->ns, $base . '/workflows/(?P<id>\d+)/history', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'history' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $this->ns, $base . '/executions/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'execution' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $this->ns, $base . '/upcoming', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'upcoming' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $this->ns, $base . '/logs', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'logs' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $this->ns, $base . '/analytics', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'analytics' ),
			'permission_callback' => $perm,
			'args'                => array(
				'days' => array(
					'type'              => 'integer',
					'default'           => 30,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( $this->ns, $base . '/usage', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'usage' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( $this->ns, $base . '/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_settings' ),
				'permission_callback' => $perm,
			),
		) );

		register_rest_route( $this->ns, $base . '/skills', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_skills' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_skill' ),
				'permission_callback' => $perm,
				'args'                => array(
					'title'        => array( 'type' => 'string', 'required' => true ),
					'instructions' => array( 'type' => 'string', 'required' => true ),
					'description'  => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				),
			),
		) );

		register_rest_route( $this->ns, $base . '/skills/(?P<id>[a-z0-9_-]+)', array(
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_skill' ),
				'permission_callback' => $perm,
			),
		) );
	}

	/* ── Handlers ───────────────────────────────────────── */

	public function get_settings(): \WP_REST_Response {
		$settings = (array) get_option( 'aime_workflow-automation_settings', array() );
		return new \WP_REST_Response( array(
			'settings' => array(
				'failure_notifications' => ! isset( $settings['failure_notifications'] ) || (bool) $settings['failure_notifications'],
			),
		), 200 );
	}

	public function save_settings( \WP_REST_Request $req ): \WP_REST_Response {
		$settings = (array) get_option( 'aime_workflow-automation_settings', array() );
		$settings['failure_notifications'] = rest_sanitize_boolean( $req->get_param( 'failure_notifications' ) );
		update_option( 'aime_workflow-automation_settings', $settings );
		return new \WP_REST_Response( array( 'success' => true, 'settings' => $settings ), 200 );
	}

	public function get_actions(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'actions' => ActionRegistry::for_api() ), 200 );
	}

	public function get_skills(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'skills' => \WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\SkillRegistry::for_api() ), 200 );
	}

	public function create_skill( \WP_REST_Request $req ): \WP_REST_Response {
		// Custom skills are Pro-only (creation gate; built-in Pro skills are
		// filtered at runtime by SkillRegistry::resolve()).
		if ( class_exists( '\\WPSpace\\AiMarketingExpert\\Pro' ) ) {
			$gate = \WPSpace\AiMarketingExpert\Pro::gate( 'Custom Brain Skills' );
			if ( is_wp_error( $gate ) ) {
				return new \WP_REST_Response( array( 'message' => $gate->get_error_message(), 'pro_required' => true ), 403 );
			}
		}
		$id = \WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\SkillRegistry::create( array(
			'title'        => sanitize_text_field( (string) $req->get_param( 'title' ) ),
			'instructions' => sanitize_textarea_field( (string) $req->get_param( 'instructions' ) ),
			'description'  => sanitize_text_field( (string) $req->get_param( 'description' ) ),
		) );
		if ( is_wp_error( $id ) ) {
			return new \WP_REST_Response( array( 'message' => $id->get_error_message() ), 400 );
		}
		return new \WP_REST_Response( array( 'id' => $id, 'skills' => \WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\SkillRegistry::for_api() ), 201 );
	}

	public function delete_skill( \WP_REST_Request $req ): \WP_REST_Response {
		if ( class_exists( '\\WPSpace\\AiMarketingExpert\\Pro' ) ) {
			$gate = \WPSpace\AiMarketingExpert\Pro::gate( 'Custom Brain Skills' );
			if ( is_wp_error( $gate ) ) {
				return new \WP_REST_Response( array( 'message' => $gate->get_error_message(), 'pro_required' => true ), 403 );
			}
		}
		$ok = \WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\SkillRegistry::delete( sanitize_key( (string) $req['id'] ) );
		if ( ! $ok ) {
			return new \WP_REST_Response( array( 'message' => __( 'Skill not found or cannot be deleted.', 'ai-marketing-expert' ) ), 404 );
		}
		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	public function get_triggers(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'triggers' => TriggerRegistry::for_api() ), 200 );
	}

	public function get_templates(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'templates' => TemplateRegistry::for_api() ), 200 );
	}

	public function apply_template( \WP_REST_Request $req ): \WP_REST_Response {
		$template = TemplateRegistry::get( sanitize_key( (string) $req['id'] ) );
		if ( ! $template ) {
			return new \WP_REST_Response( array( 'message' => __( 'Template not found.', 'ai-marketing-expert' ) ), 404 );
		}

		if ( ! empty( $template['is_pro'] ) && ! aime_has_pro() ) {
			return new \WP_REST_Response( array( 'message' => __( 'This template requires a Pro plan.', 'ai-marketing-expert' ) ), 403 );
		}

		foreach ( (array) ( $template['requires_modules'] ?? array() ) as $mod ) {
			if ( ! aime()->modules()->is_active( $mod ) ) {
				return new \WP_REST_Response( array(
					/* translators: %s: module id. */
					'message' => sprintf( __( 'This template requires the "%s" module to be active.', 'ai-marketing-expert' ), $mod ),
				), 400 );
			}
		}

		$data               = $this->sanitize_workflow( (array) ( $template['workflow'] ?? array() ) );
		$data['status']     = 'draft';
		$data['created_by'] = get_current_user_id() ?: null;

		$id = $this->repo->create( $data );
		if ( ! $id ) {
			return new \WP_REST_Response( array( 'message' => __( 'Could not create workflow.', 'ai-marketing-expert' ) ), 500 );
		}

		// Re-key blueprint steps with fresh UUIDs so applied copies never collide.
		$blueprint = (array) ( $template['steps'] ?? array() );
		$key_map   = array();
		foreach ( $blueprint as $step ) {
			$key_map[ (string) ( $step['key'] ?? '' ) ] = wp_generate_uuid4();
		}

		$steps = array();
		$order = 0;
		foreach ( $blueprint as $step ) {
			$parent  = (string) ( $step['parent_key'] ?? '' );
			$steps[] = array(
				'step_key'    => $key_map[ (string) ( $step['key'] ?? '' ) ],
				'parent_key'  => isset( $key_map[ $parent ] ) ? $key_map[ $parent ] : '',
				'branch'      => $step['branch'] ?? 'default',
				'action_type' => $step['action_type'] ?? '',
				'config'      => $step['config'] ?? array(),
				'step_order'  => $order++,
				'position_x'  => (int) ( $step['position_x'] ?? 0 ),
				'position_y'  => (int) ( $step['position_y'] ?? 0 ),
			);
		}
		$this->repo->replace_steps( $id, $steps );

		return new \WP_REST_Response( array( 'workflow_id' => $id ), 201 );
	}

	public function index(): \WP_REST_Response {
		$rows = array_map( array( $this, 'shape_workflow' ), $this->repo->all() );
		return new \WP_REST_Response( array( 'workflows' => $rows ), 200 );
	}

	public function show( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$wf = $this->repo->find( $id );
		if ( ! $wf ) {
			return new \WP_REST_Response( array( 'message' => __( 'Not found.', 'ai-marketing-expert' ) ), 404 );
		}
		$data          = $this->shape_workflow( $wf );
		$data['steps'] = array_map( array( $this, 'shape_step' ), $this->repo->steps_for( $id ) );
		return new \WP_REST_Response( array( 'workflow' => $data ), 200 );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response {
		$params = $req->get_json_params() ?: $req->get_params();

		// Free-tier active-workflow limit.
		$status = sanitize_text_field( $params['status'] ?? 'draft' );
		if ( 'active' === $status && aime_limit_reached( 'workflows_active', $this->repo->count_active() ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Free plan allows up to 2 active workflows. Upgrade for unlimited.', 'ai-marketing-expert' ) ), 403 );
		}

		$plan_error = $this->validate_steps_for_plan( $params );
		if ( $plan_error ) {
			return $plan_error;
		}

		// Activating a workflow requires every step's required config to be set.
		if ( 'active' === $status ) {
			$config_error = $this->validate_required_configs( (array) ( $params['steps'] ?? array() ) );
			if ( $config_error ) {
				return $config_error;
			}
		}

		$data          = $this->sanitize_workflow( $params );
		$data['created_by'] = get_current_user_id() ?: null;
		$id            = $this->repo->create( $data );
		if ( ! $id ) {
			return new \WP_REST_Response( array( 'message' => __( 'Could not create workflow.', 'ai-marketing-expert' ) ), 500 );
		}

		$steps_error = $this->save_steps( $id, $params );
		if ( $steps_error ) {
			// Roll back the just-created workflow so a rejected payload never
			// leaves an empty draft behind.
			$this->repo->delete( $id );
			return $steps_error;
		}
		$this->recompute_next_run( $id, $params );

		$show_req = new \WP_REST_Request( 'GET', '' );
		$show_req->set_url_params( array( 'id' => $id ) );
		return $this->show( $show_req );
	}

	public function update( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$wf = $this->repo->find( $id );
		if ( ! $wf ) {
			return new \WP_REST_Response( array( 'message' => __( 'Not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$params = $req->get_json_params() ?: $req->get_params();

		$status = sanitize_text_field( $params['status'] ?? $wf->status );
		if ( 'active' === $status && 'active' !== $wf->status
			&& aime_limit_reached( 'workflows_active', $this->repo->count_active( $id ) ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Free plan allows up to 2 active workflows. Upgrade for unlimited.', 'ai-marketing-expert' ) ), 403 );
		}

		$plan_error = $this->validate_steps_for_plan( $params );
		if ( $plan_error ) {
			return $plan_error;
		}

		// Activating a workflow requires every step's required config to be set.
		// The list-screen Activate toggle sends no steps, so fall back to the
		// stored ones for validation.
		if ( 'active' === $status ) {
			$steps_to_check = isset( $params['steps'] ) && is_array( $params['steps'] )
				? $params['steps']
				: array_map(
					static fn ( object $s ): array => array(
						'action_type' => (string) $s->action_type,
						'config'      => json_decode( (string) $s->config, true ) ?: array(),
					),
					$this->repo->steps_for( $id )
				);
			$config_error = $this->validate_required_configs( $steps_to_check );
			if ( $config_error ) {
				return $config_error;
			}
		}

		$this->repo->update( $id, $this->sanitize_workflow( $params ) );

		if ( isset( $params['steps'] ) ) {
			$steps_error = $this->save_steps( $id, $params );
			if ( $steps_error ) {
				return $steps_error;
			}
		}
		$this->recompute_next_run( $id, $params );

		$show_req = new \WP_REST_Request( 'GET', '' );
		$show_req->set_url_params( array( 'id' => $id ) );
		return $this->show( $show_req );
	}

	public function destroy( \WP_REST_Request $req ): \WP_REST_Response {
		$ok = $this->repo->delete( (int) $req['id'] );
		return new \WP_REST_Response( array( 'deleted' => $ok ), $ok ? 200 : 500 );
	}

	public function run_now( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$wf = $this->repo->find( $id );
		if ( ! $wf ) {
			return new \WP_REST_Response( array( 'message' => __( 'Not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$steps = $this->repo->steps_for( $id );
		if ( empty( $steps ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Workflow has no steps.', 'ai-marketing-expert' ) ), 400 );
		}

		// Refuse to queue a run that is guaranteed to fail on missing config.
		$config_error = $this->validate_required_configs( array_map(
			static fn ( object $s ): array => array(
				'action_type' => (string) $s->action_type,
				'config'      => json_decode( (string) $s->config, true ) ?: array(),
			),
			$steps
		) );
		if ( $config_error ) {
			return $config_error;
		}

		// Free-plan monthly run cap: reject up front — clearer than queueing a
		// run the engine would only skip.
		if ( $this->repo->monthly_run_limit_reached() ) {
			$limit = (int) ( aime_free_limits()['workflow_runs_monthly'] ?? 30 );
			return new \WP_REST_Response( array(
				/* translators: %d: monthly run limit on the free plan. */
				'message' => sprintf( __( 'Free plan includes %d workflow runs per month and the limit has been reached. Runs resume next month — upgrade to Pro for unlimited runs.', 'ai-marketing-expert' ), $limit ),
			), 403 );
		}

		// Manual runs of event workflows may carry a sample payload so steps
		// that read context['event'] (e.g. funnel enrolment) can be tested.
		$event_payload = array();
		$params        = $req->get_json_params() ?: array();
		if ( 'event' === $wf->trigger_type && is_array( $params['event'] ?? null ) ) {
			foreach ( $params['event'] as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$event_payload[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
				}
			}
		}

		// Queue asynchronously — AI steps can far exceed the HTTP timeout.
		$execution_id = $this->repo->start_execution( $id, 'manual', count( $steps ), 'queued', $event_payload );
		if ( ! $execution_id ) {
			return new \WP_REST_Response( array( 'message' => __( 'Could not queue execution.', 'ai-marketing-expert' ) ), 500 );
		}

		wp_schedule_single_event( time(), WorkflowAutomationModule::HOOK_EXECUTE_SINGLE, array( $id, 'manual', $execution_id, $event_payload ) );
		spawn_cron();

		return new \WP_REST_Response( array( 'queued' => true, 'execution_id' => $execution_id ), 202 );
	}

	public function history( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req['id'];
		$rows = array_map( array( $this, 'shape_execution' ), $this->repo->executions_for( $id ) );
		return new \WP_REST_Response( array( 'executions' => $rows ), 200 );
	}

	public function execution( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req['id'];
		$exec = $this->repo->find_execution( $id );
		if ( ! $exec ) {
			return new \WP_REST_Response( array( 'message' => __( 'Not found.', 'ai-marketing-expert' ) ), 404 );
		}
		$data            = $this->shape_execution( $exec );
		$data['outputs'] = array_map( array( $this, 'shape_output' ), $this->repo->outputs_for( $id ) );
		return new \WP_REST_Response( array( 'execution' => $data ), 200 );
	}

	public function upcoming(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'upcoming' => $this->repo->upcoming( 20 ) ), 200 );
	}

	/**
	 * Module-wide error log: every run that failed, partly failed, or was
	 * skipped, with the step-level reasons attached.
	 */
	public function logs(): \WP_REST_Response {
		$rows = $this->repo->recent_problem_executions( 50 );

		$ids     = array_map( static fn ( object $e ): int => (int) $e->id, $rows );
		$outputs = $this->repo->problem_outputs_for( $ids );

		$by_execution = array();
		foreach ( $outputs as $out ) {
			$by_execution[ (int) $out->execution_id ][] = array(
				'action_type' => (string) $out->action_type,
				'action_label' => ActionRegistry::get( (string) $out->action_type )['label'] ?? (string) $out->action_type,
				'status'      => (string) $out->status,
				'error'       => (string) $out->error,
			);
		}

		$entries = array();
		foreach ( $rows as $row ) {
			$entry           = $this->shape_execution( $row );
			$entry['steps']  = $by_execution[ (int) $row->id ] ?? array();
			$entries[]       = $entry;
		}

		return new \WP_REST_Response( array( 'entries' => $entries ), 200 );
	}

	/**
	 * Module analytics: run reliability, throughput, and where runs break.
	 *
	 * The list page already answers "did it run" — a date and a lifetime count.
	 * The question it cannot answer is "is it working", which for unattended
	 * automation is the only question there is. Everything here is derived from
	 * rows the engine already writes; none of it required new instrumentation.
	 */
	public function analytics( \WP_REST_Request $req ): \WP_REST_Response {
		$days  = max( 1, min( 365, absint( $req->get_param( 'days' ) ?: 30 ) ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$outcomes = $this->repo->outcome_totals( $since );
		$steps    = $this->repo->step_totals( $since );
		$triggers = $this->repo->trigger_totals( $since );

		$succeeded = (int) ( $outcomes['success'] ?? 0 );
		$partial   = (int) ( $outcomes['partial'] ?? 0 );
		$failed    = (int) ( $outcomes['failed'] ?? 0 );
		$skipped   = (int) ( $outcomes['skipped'] ?? 0 );
		$finished  = $succeeded + $partial + $failed;

		// A partly-failed run is not a success. It produced something, so it is
		// not a total loss either — but a rate that counted it as clean would
		// report a healthy number for a workflow silently dropping half its work.
		$success_rate = $finished > 0 ? round( ( $succeeded / $finished ) * 100, 1 ) : 0.0;

		$steps_attempted = $steps['succeeded'] + $steps['failed'];
		$step_rate       = $steps_attempted > 0 ? round( ( $steps['succeeded'] / $steps_attempted ) * 100, 1 ) : 0.0;

		$manual    = (int) ( $triggers['manual'] ?? 0 );
		$unattended = max( 0, $finished - $manual );
		$hands_off = $finished > 0 ? round( ( $unattended / $finished ) * 100, 1 ) : 0.0;

		// Action keys are stable identifiers; the registry holds the human label,
		// and an action removed from the registry still has failures on record.
		$failing = array();
		foreach ( $this->repo->action_failures( $since, 6 ) as $row ) {
			$type      = (string) $row->action_type;
			$attempts  = (int) $row->attempts;
			$failures  = (int) $row->failures;
			$failing[] = array(
				'action_type'  => $type,
				'action_label' => ActionRegistry::get( $type )['label'] ?? $type,
				'attempts'     => $attempts,
				'failures'     => $failures,
				'failure_rate' => $attempts > 0 ? round( ( $failures / $attempts ) * 100, 1 ) : 0.0,
			);
		}

		$workflows = array();
		foreach ( $this->repo->workflow_reliability( $since, 10 ) as $row ) {
			$runs        = (int) $row->runs;
			$ok          = (int) $row->ok;
			$workflows[] = array(
				'id'           => (int) $row->id,
				'name'         => (string) $row->name,
				'status'       => (string) $row->status,
				'runs'         => $runs,
				'ok'           => $ok,
				'partial'      => (int) $row->partial,
				'failed'       => (int) $row->failed,
				'success_rate' => $runs > 0 ? round( ( $ok / $runs ) * 100, 1 ) : 0.0,
				'avg_seconds'  => (int) $row->avg_seconds,
				'last_run_at'  => $row->last_run_at,
			);
		}

		return new \WP_REST_Response( array(
			'days'          => $days,
			'runs_by_day'   => $this->repo->runs_by_day( $since ),
			'totals'        => array(
				'runs_finished' => $finished,
				'succeeded'     => $succeeded,
				'partial'       => $partial,
				'failed'        => $failed,
				'skipped'       => $skipped,
				'success_rate'  => $success_rate,
				'steps_succeeded' => $steps['succeeded'],
				'steps_failed'    => $steps['failed'],
				'steps_skipped'   => $steps['skipped'],
				'step_rate'       => $step_rate,
				'manual_runs'     => $manual,
				'unattended_runs' => $unattended,
				'hands_off_rate'  => $hands_off,
			)
			+ $this->repo->duration_totals( $since )
			+ $this->repo->idle_workflow_counts( $since ),
			'workflow_status' => $this->repo->workflow_status_totals(),
			'action_failures' => $failing,
			'workflows'       => $workflows,
			// Sent with the payload so the quota card does not cost a second
			// request on a page whose whole job is one round trip.
			'is_pro'          => aime_has_pro(),
			'runs_usage'      => aime_usage_payload( 'workflow_runs_monthly', $this->repo->count_runs_this_month() ),
		), 200 );
	}

	/**
	 * Free-plan usage meters for the workflow module.
	 */
	public function usage(): \WP_REST_Response {
		$limits = aime_free_limits();

		return new \WP_REST_Response( array(
			'is_pro' => aime_has_pro(),
			'usage'  => array(
				'workflows' => aime_usage_payload( 'workflows_active', $this->repo->count_active() ),
				'runs'      => aime_usage_payload( 'workflow_runs_monthly', $this->repo->count_runs_this_month() ),
			),
			// Per-workflow cap, not a running total — the builder shows it against
			// the step count of the workflow currently open.
			'step_limit' => aime_has_pro() ? null : (int) ( $limits['workflow_steps'] ?? 3 ),
		), 200 );
	}

	/* ── Sanitizers / shapers ───────────────────────────── */

	private function sanitize_workflow( array $p ): array {
		$out = array();
		$map_text = array( 'name', 'description', 'topic' );
		foreach ( $map_text as $k ) {
			if ( isset( $p[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( (string) $p[ $k ] );
			}
		}
		if ( isset( $p['status'] ) ) {
			$out['status'] = in_array( $p['status'], array( 'active', 'paused', 'draft' ), true ) ? $p['status'] : 'draft';
		}
		if ( isset( $p['trigger_type'] ) ) {
			$out['trigger_type'] = in_array( $p['trigger_type'], array( 'schedule', 'event' ), true ) ? $p['trigger_type'] : 'schedule';
		}
		if ( isset( $p['trigger_event'] ) ) {
			$key = sanitize_key( (string) $p['trigger_event'] );
			$out['trigger_event'] = ( '' !== $key && TriggerRegistry::get( $key ) ) ? $key : '';
		}
		if ( isset( $p['trigger_config'] ) ) {
			$cfg = is_array( $p['trigger_config'] ) ? $p['trigger_config'] : json_decode( (string) $p['trigger_config'], true );
			$out['trigger_config'] = wp_json_encode( is_array( $cfg ) ? $cfg : array() );
		}
		if ( isset( $p['schedule_type'] ) ) {
			$out['schedule_type'] = in_array( $p['schedule_type'], array( 'once', 'daily', 'weekly', 'monthly', 'custom' ), true ) ? $p['schedule_type'] : 'weekly';
		}
		if ( isset( $p['schedule_time'] ) ) {
			$out['schedule_time'] = preg_match( '/^\d{1,2}:\d{2}$/', (string) $p['schedule_time'] ) ? $p['schedule_time'] : '09:00';
		}
		if ( isset( $p['schedule_days'] ) ) {
			$days = is_array( $p['schedule_days'] ) ? $p['schedule_days'] : explode( ',', (string) $p['schedule_days'] );
			$out['schedule_days'] = implode( ',', array_map( 'intval', $days ) );
		}
		if ( isset( $p['schedule_day_of_month'] ) ) {
			$out['schedule_day_of_month'] = max( 1, min( 31, (int) $p['schedule_day_of_month'] ) );
		}
		if ( isset( $p['interval_value'] ) ) {
			$out['interval_value'] = max( 1, (int) $p['interval_value'] );
		}
		if ( isset( $p['interval_unit'] ) ) {
			$out['interval_unit'] = in_array( $p['interval_unit'], array( 'hours', 'days' ), true ) ? $p['interval_unit'] : 'days';
		}
		if ( isset( $p['tone'] ) ) {
			$out['tone'] = sanitize_text_field( (string) $p['tone'] );
		}
		if ( isset( $p['brand_voice_id'] ) ) {
			$out['brand_voice_id'] = $p['brand_voice_id'] ? (int) $p['brand_voice_id'] : null;
		}
		if ( isset( $p['failure_policy'] ) ) {
			$out['failure_policy'] = in_array( $p['failure_policy'], array( 'stop', 'skip', 'retry' ), true ) ? $p['failure_policy'] : 'stop';
		}
		$out['is_pro'] = aime_has_pro() ? 1 : 0;
		return $out;
	}

	private function save_steps( int $workflow_id, array $params ): ?\WP_REST_Response {
		$steps = $params['steps'] ?? array();
		if ( ! is_array( $steps ) ) {
			return null;
		}

		// The engine walks the parent_key graph unguarded at runtime; the
		// client-side validator is advisory only. Reject malformed graphs
		// (duplicate/missing keys, dangling parents, cycles) here so a bad
		// payload can never wedge a cron worker into an infinite loop.
		$graph_error = $this->validate_step_graph( $steps );
		if ( $graph_error ) {
			return $graph_error;
		}

		$this->repo->replace_steps( $workflow_id, $steps );
		return null;
	}

	/**
	 * Validate the submitted step graph before anything touches the database.
	 *
	 * @param array $steps Raw steps from the request.
	 * @return \WP_REST_Response|null Error response, or null when valid.
	 */
	private function validate_step_graph( array $steps ): ?\WP_REST_Response {
		$keys = array();

		foreach ( array_values( $steps ) as $i => $step ) {
			$key         = sanitize_text_field( (string) ( $step['step_key'] ?? '' ) );
			$action_type = sanitize_text_field( (string) ( $step['action_type'] ?? '' ) );

			if ( '' === $action_type ) {
				return new \WP_REST_Response( array(
					/* translators: %d: position of the invalid step. */
					'message' => sprintf( __( 'Step #%1$d has no action type.', 'ai-marketing-expert' ), $i + 1 ),
				), 400 );
			}
			if ( '' === $key ) {
				return new \WP_REST_Response( array(
					/* translators: 1: action type label. */
					'message' => sprintf( __( 'The "%1$s" step is missing its identifier. Reload the builder and try again.', 'ai-marketing-expert' ), ActionRegistry::get( $action_type )['label'] ?? $action_type ),
				), 400 );
			}
			if ( isset( $keys[ $key ] ) ) {
				return new \WP_REST_Response( array(
					/* translators: %s: duplicated step key. */
					'message' => sprintf( __( 'Duplicate step identifier "%s" — reload the builder and try again.', 'ai-marketing-expert' ), $key ),
				), 400 );
			}
			$keys[ $key ] = sanitize_text_field( (string) ( $step['parent_key'] ?? '' ) );
		}

		foreach ( $keys as $key => $parent ) {
			if ( '' !== $parent && ! isset( $keys[ $parent ] ) ) {
				return new \WP_REST_Response( array(
					/* translators: %s: step identifier whose parent is missing. */
					'message' => sprintf( __( 'The step "%s" points to a parent step that no longer exists. Remove and re-add the connection.', 'ai-marketing-expert' ), $parent ),
				), 400 );
			}
		}

		// Cycle detection: every chain of parents must terminate at the root
		// ('') within N hops. A revisited node means a loop.
		foreach ( $keys as $start => $unused_parent ) {
			$visited = array();
			$cursor  = $start;
			while ( '' !== $cursor ) {
				if ( isset( $visited[ $cursor ] ) ) {
					return new \WP_REST_Response( array(
						'message' => __( 'The workflow contains a circular connection. Every step must ultimately hang off the trigger.', 'ai-marketing-expert' ),
					), 400 );
				}
				$visited[ $cursor ] = true;
				$cursor             = $keys[ $cursor ] ?? '';
			}
			unset( $visited );
		}

		return null;
	}

	/**
	 * Enforce plan limits on the submitted workflow + steps. Returns an error
	 * response to send, or null when the payload is allowed.
	 */
	private function validate_steps_for_plan( array $params ): ?\WP_REST_Response {
		if ( aime_has_pro() ) {
			return null;
		}

		if ( isset( $params['schedule_type'] ) && ! in_array( $params['schedule_type'], array( 'once', 'weekly', 'monthly' ), true ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Free plan supports one-time, weekly and monthly schedules only. Upgrade for daily and custom intervals.', 'ai-marketing-expert' ) ), 403 );
		}

		$steps = $params['steps'] ?? null;
		if ( ! is_array( $steps ) ) {
			return null;
		}

		$limits = aime_free_limits();
		$max    = (int) ( $limits['workflow_steps'] ?? 3 );
		if ( count( $steps ) > $max ) {
			return new \WP_REST_Response( array(
				/* translators: %d: maximum steps on the free plan. */
				'message' => sprintf( __( 'Free plan allows up to %d steps per workflow. Upgrade for unlimited steps.', 'ai-marketing-expert' ), $max ),
			), 403 );
		}

		foreach ( $steps as $step ) {
			$action = (string) ( $step['action_type'] ?? '' );
			$branch = (string) ( $step['branch'] ?? 'default' );
			if ( 'condition' === $action || 'default' !== $branch ) {
				return new \WP_REST_Response( array( 'message' => __( 'Conditional branching requires a Pro plan.', 'ai-marketing-expert' ) ), 403 );
			}
		}

		return null;
	}

	/**
	 * Check every step's required config fields (per the action registry).
	 * Returns an error response naming the first offending step, or null.
	 *
	 * @param array $steps Steps as arrays with action_type + config.
	 */
	private function validate_required_configs( array $steps ): ?\WP_REST_Response {
		foreach ( $steps as $step ) {
			$action_type = (string) ( $step['action_type'] ?? '' );
			$def         = ActionRegistry::get( $action_type );
			if ( ! $def ) {
				continue;
			}
			$config = is_array( $step['config'] ?? null ) ? $step['config'] : array();
			foreach ( (array) ( $def['fields'] ?? array() ) as $field ) {
				if ( empty( $field['required'] ) ) {
					continue;
				}
				$value = $config[ $field['key'] ] ?? $field['default'] ?? '';
				if ( '' === $value || null === $value || 0 === $value || '0' === $value ) {
					return new \WP_REST_Response( array(
						'message' => sprintf(
							/* translators: 1: step label, 2: field label. */
							__( 'The "%1$s" step is missing its required "%2$s" setting.', 'ai-marketing-expert' ),
							$def['label'] ?? $action_type,
							$field['label'] ?? $field['key']
						),
					), 400 );
				}
			}
		}
		return null;
	}

	private function recompute_next_run( int $workflow_id, array $params ): void {
		$wf = $this->repo->find( $workflow_id );
		if ( ! $wf ) {
			return;
		}

		// A "once" schedule may supply an explicit datetime.
		if ( 'once' === $wf->schedule_type && 'schedule' === $wf->trigger_type ) {
			if ( ! empty( $params['run_at'] ) ) {
				$ts = strtotime( (string) $params['run_at'] );
				if ( $ts ) {
					$this->repo->set_next_run( $workflow_id, gmdate( 'Y-m-d H:i:s', $ts - wp_timezone()->getOffset( new \DateTimeImmutable( '@' . $ts ) ) ) );
				}
			}
			// No run_at in this request → keep the existing next_run_at untouched.
			return;
		}

		if ( 'active' === $wf->status && 'schedule' === $wf->trigger_type ) {
			$next = ( new WorkflowScheduler() )->compute_next_run( $wf, time() );
			$this->repo->set_next_run( $workflow_id, $next );
		} else {
			$this->repo->set_next_run( $workflow_id, null );
		}
	}

	private function shape_workflow( object $w ): array {
		return array(
			'id'             => (int) $w->id,
			'name'           => $w->name,
			'description'    => $w->description,
			'status'         => $w->status,
			'trigger_type'   => $w->trigger_type,
			'trigger_event'  => $w->trigger_event ?? '',
			'trigger_config' => json_decode( (string) ( $w->trigger_config ?? '' ), true ) ?: array(),
			'schedule_type'  => $w->schedule_type,
			'schedule_time'  => $w->schedule_time,
			'schedule_days'  => $w->schedule_days,
			'schedule_day_of_month' => (int) $w->schedule_day_of_month,
			'interval_value' => (int) $w->interval_value,
			'interval_unit'  => $w->interval_unit,
			'topic'          => $w->topic,
			'tone'           => $w->tone,
			'brand_voice_id' => $w->brand_voice_id ? (int) $w->brand_voice_id : null,
			'failure_policy' => $w->failure_policy,
			'next_run_at'    => $w->next_run_at,
			'last_run_at'    => $w->last_run_at,
			'run_count'      => (int) $w->run_count,
		);
	}

	private function shape_step( object $s ): array {
		return array(
			'id'            => (int) $s->id,
			'step_key'      => (string) ( $s->step_key ?? '' ),
			'parent_key'    => (string) ( $s->parent_key ?? '' ),
			'branch'        => (string) ( $s->branch ?? 'default' ),
			'step_order'    => (int) $s->step_order,
			'position_x'    => (int) ( $s->position_x ?? 0 ),
			'position_y'    => (int) ( $s->position_y ?? 0 ),
			'action_type'   => $s->action_type,
			'config'        => json_decode( (string) $s->config, true ) ?: array(),
			'tone_override' => $s->tone_override,
			'run_condition' => $s->run_condition,
		);
	}

	private function shape_execution( object $e ): array {
		return array(
			'id'              => (int) $e->id,
			'workflow_id'     => (int) $e->workflow_id,
			'workflow_name'   => $e->workflow_name ?? null,
			'trigger_type'    => $e->trigger_type,
			'status'          => $e->status,
			'steps_total'     => (int) $e->steps_total,
			'steps_succeeded' => (int) $e->steps_succeeded,
			'steps_failed'    => (int) $e->steps_failed,
			'steps_skipped'   => (int) $e->steps_skipped,
			'error'           => $e->error,
			'started_at'      => $e->started_at,
			'finished_at'     => $e->finished_at,
		);
	}

	private function shape_output( object $o ): array {
		return array(
			'id'          => (int) $o->id,
			'step_key'    => (string) ( $o->step_key ?? '' ),
			'branch'      => (string) ( $o->branch ?? 'default' ),
			'step_order'  => (int) $o->step_order,
			'action_type' => $o->action_type,
			'status'      => $o->status,
			'preview'     => $o->preview,
			'reference'   => json_decode( (string) $o->reference, true ) ?: array(),
			'error'       => $o->error,
		);
	}
}
