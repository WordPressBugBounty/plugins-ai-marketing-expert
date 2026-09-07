<?php
/**
 * Workflow Automation Module — Bootstrap.
 *
 * A self-contained, sixth module that chains actions from the other modules
 * into scheduled workflows. It never edits another module's code or data — it
 * reaches them through the additive `aime_workflow_actions` filter registry and
 * their already-public service classes. Removing this module leaves every other
 * module and all user data untouched.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation;

use WPSpace\AiMarketingExpert\Module;
use WPSpace\AiMarketingExpert\Plugin;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\WorkflowEngine;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\WorkflowRepository;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions\BlogPostAction;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions\SeoAuditAction;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions\FunnelEnrollAction;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions\SocialPostAction;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions\EmailCampaignAction;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions\AdCopyAction;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions\CustomPromptAction;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions\AiBrainAction;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions\ConditionAction;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions\SendNotificationAction;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\TriggerDispatcher;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Templates\BuiltinTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorkflowAutomationModule extends Module {

	/** Dispatch hook — polls for due workflows on the five-minute interval. */
	const HOOK_DISPATCH = 'aime_workflow_dispatch';

	/** One-shot hook — runs a single workflow now (Run-now / event triggers). */
	const HOOK_EXECUTE_SINGLE = 'aime_workflow_execute_single';

	/* ── Module identity ────────────────────────────────── */

	public function get_id(): string {
		return 'workflow-automation';
	}

	public function get_name(): string {
		return __( 'Workflow Automation', 'ai-marketing-expert' );
	}

	public function get_description(): string {
		return __( 'Chain actions from every module into scheduled, automated marketing workflows.', 'ai-marketing-expert' );
	}

	public function get_icon(): string {
		return 'randomize';
	}

	public function get_version(): string {
		return '1.0.0';
	}

	/* ── Pro features ───────────────────────────────────── */

	public function get_pro_features(): array {
		return array(
			'unlimited_workflows' => __( 'Unlimited active workflows (free: 2)', 'ai-marketing-expert' ),
			'unlimited_steps'     => __( 'Unlimited action steps per workflow (free: 3)', 'ai-marketing-expert' ),
			'unlimited_runs'      => __( 'Unlimited workflow runs (free: 30 per month)', 'ai-marketing-expert' ),
			'advanced_schedules'  => __( 'Daily & custom-interval schedules', 'ai-marketing-expert' ),
			'conditional_steps'   => __( 'Conditional branching with Yes/No paths', 'ai-marketing-expert' ),
			'topic_rotation'      => __( 'Topic & product rotation on generation steps', 'ai-marketing-expert' ),
			'workflow_templates'  => __( 'Full workflow templates library', 'ai-marketing-expert' ),
			'brain_skills'        => __( 'Pro AI Brain skills (image, links, social) + custom skills', 'ai-marketing-expert' ),
		);
	}

	/* ── Initialisation ─────────────────────────────────── */

	public function init(): void {
		$this->maybe_create_tables();

		// Ensure the five-minute cron interval exists (registered defensively —
		// other modules register it too; isset() guards prevent collisions).
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

		// Background dispatcher: scan for due workflows every five minutes.
		add_action( self::HOOK_DISPATCH, array( $this, 'run_dispatch' ) );
		if ( ! wp_next_scheduled( self::HOOK_DISPATCH ) ) {
			wp_schedule_event( time() + 60, 'five_minutes', self::HOOK_DISPATCH );
		}

		// One-shot single execution (Run-now, event triggers).
		add_action( self::HOOK_EXECUTE_SINGLE, array( $this, 'run_single' ), 10, 4 );

		// Publish this module's built-in actions to the shared registry.
		add_filter( 'aime_workflow_actions', array( $this, 'register_builtin_actions' ) );

		// Publish this module's built-in triggers to the shared registry.
		add_filter( 'aime_workflow_triggers', array( $this, 'register_builtin_triggers' ) );

		// Publish built-in workflow templates to the shared registry.
		add_filter( 'aime_workflow_templates', array( BuiltinTemplates::class, 'register' ) );

		// Event trigger dispatcher: listens on registered hooks and queues runs.
		( new TriggerDispatcher() )->init();

		// Additive free-limit keys (never edits the core helper).
		add_filter( 'aime_free_limits', array( $this, 'register_free_limits' ) );

		// Additive uninstall cleanup for this module's own tables.
		add_filter( 'aime_uninstall_tables', array( $this, 'register_uninstall_tables' ) );

		// Prune old executions on the existing daily-cleanup cron.
		add_action( 'aime_daily_cleanup', array( $this, 'prune_history' ) );

		// Daily hygiene for the dispatcher's atomic debounce entries.
		add_action( 'aime_daily_cleanup', array( 'WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\TriggerDispatcher', 'purge_expired_debounce' ) );

		// Dashboard stats hook.
		add_filter( 'aime_workflow-automation_dashboard_stats', array( $this, 'get_stats' ) );
	}

	/**
	 * Add the five-minute cron interval if no other module has registered it.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public function add_cron_interval( array $schedules ): array {
		if ( ! isset( $schedules['five_minutes'] ) ) {
			$schedules['five_minutes'] = array(
				'interval' => 300,
				'display'  => __( 'Every 5 Minutes', 'ai-marketing-expert' ),
			);
		}
		return $schedules;
	}

	/* ── Cron callbacks ─────────────────────────────────── */

	/**
	 * Dispatcher: execute every active, schedule-triggered workflow that is due.
	 */
	public function run_dispatch(): void {
		( new WorkflowEngine() )->dispatch_due();
	}

	/**
	 * One-shot execution of a single workflow.
	 *
	 * @param int    $workflow_id   Workflow ID.
	 * @param string $trigger       Trigger label (manual|event|schedule).
	 * @param int    $execution_id  Pre-created queued execution ID (0 = create fresh).
	 * @param array  $event_payload Event payload snapshot for the run context.
	 */
	public function run_single( int $workflow_id, string $trigger = 'manual', int $execution_id = 0, array $event_payload = array() ): void {
		( new WorkflowEngine() )->execute( $workflow_id, $trigger, $execution_id, $event_payload );
	}

	/**
	 * Prune executions/outputs older than 90 days.
	 */
	public function prune_history(): void {
		( new WorkflowRepository() )->prune_executions( 90 );
	}

	/* ── Action registry ────────────────────────────────── */

	/**
	 * Register the built-in cross-module actions.
	 *
	 * Each entry is decoupled from its target module: if the module is inactive
	 * the action is hidden and any step referencing it is skipped-with-notice.
	 *
	 * @param array $actions Existing registered actions.
	 * @return array
	 */
	public function register_builtin_actions( array $actions ): array {
		$module_active = static function ( string $module_id ): bool {
			return Plugin::instance()->modules()->is_active( $module_id );
		};

		$actions['generate_blog_post'] = array(
			'label'       => __( 'Generate Blog Post', 'ai-marketing-expert' ),
			'module'      => 'content-generator',
			'description' => __( 'AI writes a full article and saves it as a draft (or publishes it).', 'ai-marketing-expert' ),
			'is_pro'      => false,
			'available'   => static fn (): bool => $module_active( 'content-generator' ),
			'fields'      => array(
				array(
					'key'          => 'topic',
					'label'        => __( 'Topic', 'ai-marketing-expert' ),
					'type'         => 'text',
					'help'         => __( 'Leave blank to inherit from AI Brain or workflow topic.', 'ai-marketing-expert' ),
					// Hidden when the direct parent is an AI Brain step (the
					// Brain provides the topic). Evaluated client-side by the
					// builder; see ActionRegistry::resolve_fields().
					'visible_rule' => array( 'type' => 'parent_not', 'action' => 'ai_brain' ),
				),
				array(
					'key'          => 'topics',
					'label'        => __( 'Topic rotation', 'ai-marketing-expert' ),
					'type'         => 'tokens',
					'is_pro'       => true,
					'help'         => __( 'Add several topics and each run picks a different one — no repeats until every topic has been used. Overrides the single topic above. Hidden when AI Brain step is parent.', 'ai-marketing-expert' ),
					'visible_rule' => array( 'type' => 'parent_not', 'action' => 'ai_brain' ),
				),
				array(
					'key'            => 'keywords',
					'label'          => __( 'Target keywords', 'ai-marketing-expert' ),
					'type'           => 'tokens',
					'help'           => __( 'Separate with commas or Enter key. Leave blank to inherit from AI Brain.', 'ai-marketing-expert' ),
					'visible_rule'   => array( 'type' => 'parent_not', 'action' => 'ai_brain' ),
				),
				array(
					'key'            => 'writing_brief',
					'label'          => __( 'Writing brief', 'ai-marketing-expert' ),
					'type'           => 'textarea',
					'help'           => __( 'Optional extra instructions for the writer: structure, angle, must-include points. Combined with the AI Brain brief when both are set.', 'ai-marketing-expert' ),
					// Shows the "Prompt library" browse button (pre-made prompts).
					'prompt_library' => true,
				),
				array(
					'key'     => 'word_count',
					'label'   => __( 'Minimum word count', 'ai-marketing-expert' ),
					'type'    => 'range',
					'default' => 1500,
					'min'     => 300,
					'max'     => 5000,
					'step'    => 100,
					'help'    => __( 'The floor: the writer is pushed to reach this length, and a short draft is extended until it does.', 'ai-marketing-expert' ),
				),
				array(
					'key'     => 'word_count_max',
					'label'   => __( 'Maximum word count', 'ai-marketing-expert' ),
					'type'    => 'range',
					'default' => 2500,
					'min'     => 0,
					'max'     => 8000,
					'step'    => 100,
					'help'    => __( 'The ceiling the writer plans against, so long-winded models finish inside the budget instead of being cut off mid-sentence. Set 0 for no upper bound.', 'ai-marketing-expert' ),
				),
				array( 'key' => 'language', 'label' => __( 'Language', 'ai-marketing-expert' ), 'type' => 'language', 'default' => 'en' ),
				array(
					'key'        => 'category_ids',
					'label'      => __( 'Categories', 'ai-marketing-expert' ),
					'type'       => 'select',
					'multiple'   => true,
					'default'    => [],
					// Pre-rename configs stored a single id here; the editor
					// seeds the multi-select from it until first change.
					'legacy_key' => 'category_id',
					'help'       => __( 'Pick one or more categories for the published post (type to search, Enter to add). Leave empty for the site default category.', 'ai-marketing-expert' ),
					'options'  => static function (): array {
						$options = array();
						$cats = get_categories( array( 'hide_empty' => false, 'number' => 200 ) );
						foreach ( $cats as $cat ) {
							$options[] = array( 'value' => (int) $cat->term_id, 'label' => $cat->name );
						}
						return $options;
					},
				),
				array(
					'key'     => 'author_id',
					'label'   => __( 'Author', 'ai-marketing-expert' ),
					'type'    => 'select',
					'default' => 0,
					'help'    => __( 'Author for the published post. Default: the user who created this workflow.', 'ai-marketing-expert' ),
					'options' => static function (): array {
						$options = array(
							array( 'value' => 0, 'label' => __( 'Workflow creator', 'ai-marketing-expert' ) ),
						);
						$users = get_users( array( 'capability' => 'edit_posts', 'number' => 100, 'orderby' => 'display_name' ) );
						foreach ( $users as $user ) {
							$options[] = array( 'value' => (int) $user->ID, 'label' => $user->display_name );
						}
						return $options;
					},
				),
				array( 'key' => 'auto_tags', 'label' => __( 'AI-generated tags', 'ai-marketing-expert' ), 'type' => 'checkbox', 'default' => true, 'help' => __( 'Let the AI suggest 3-5 relevant tags for each article.', 'ai-marketing-expert' ) ),
				array( 'key' => 'tags', 'label' => __( 'Fixed tags', 'ai-marketing-expert' ), 'type' => 'tokens', 'suggest' => 'post_tags', 'help' => __( 'Always added to every generated post, in addition to AI tags.', 'ai-marketing-expert' ) ),
				array(
					'key'     => 'featured_image',
					'label'   => __( 'Featured image', 'ai-marketing-expert' ),
					'type'    => 'select',
					'default' => 'none',
					'help'    => __( 'Stock photos need an API key under Content → Settings → Images. AI images use your image-capable AI provider.', 'ai-marketing-expert' ),
					'options' => array(
						array( 'value' => 'none', 'label' => __( 'None', 'ai-marketing-expert' ) ),
						array( 'value' => 'stock', 'label' => __( 'Stock photo (Pexels / Pixabay)', 'ai-marketing-expert' ) ),
						array( 'value' => 'ai', 'label' => __( 'AI-generated (Pro)', 'ai-marketing-expert' ) ),
					),
				),
				array(
					'key'     => 'inline_images',
					'label'   => __( 'In-body stock images', 'ai-marketing-expert' ),
					'type'    => 'select',
					'default' => 0,
					'help'    => __( 'AI places relevant stock photos inside the article text. Needs a stock API key under Content → Settings → Images.', 'ai-marketing-expert' ),
					'options' => array(
						array( 'value' => 0, 'label' => __( 'None', 'ai-marketing-expert' ) ),
						array( 'value' => 1, 'label' => __( '1 image', 'ai-marketing-expert' ) ),
						array( 'value' => 2, 'label' => __( '2 images', 'ai-marketing-expert' ) ),
						array( 'value' => 3, 'label' => __( '3 images', 'ai-marketing-expert' ) ),
					),
				),
				array(
						'key'     => 'post_status',
						'label'   => __( 'Post status', 'ai-marketing-expert' ),
						'type'    => 'select',
						'default' => 'draft',
						'help'    => __( 'Draft = save for review, Publish = go live immediately.', 'ai-marketing-expert' ),
						'options' => array(
							array( 'value' => 'draft', 'label' => __( 'Draft', 'ai-marketing-expert' ) ),
							array( 'value' => 'publish', 'label' => __( 'Publish', 'ai-marketing-expert' ) ),
						),
					),
			),
			'handler'     => array( BlogPostAction::class, 'run' ),
		);

		$actions['run_seo_audit'] = array(
			'label'       => __( 'Run SEO Audit', 'ai-marketing-expert' ),
			'module'      => 'seo',
			'description' => __( 'Audit a post/URL for on-page SEO and store the score.', 'ai-marketing-expert' ),
			'is_pro'      => false,
			'available'   => static fn (): bool => $module_active( 'seo' ),
			'fields'      => array(
				array(
					'key'     => 'wp_post_id',
					'label'   => __( 'Post to audit', 'ai-marketing-expert' ),
					'type'    => 'select',
					'default' => -1,
					'help'    => __( 'Previous step = post created by parent action (Blog Post, etc). Latest published = most recent live post.', 'ai-marketing-expert' ),
					'options' => static function (): array {
						$options = array(
							array( 'value' => -1, 'label' => __( 'Previous step (default)', 'ai-marketing-expert' ) ),
							array( 'value' => 0, 'label' => __( 'Latest published post', 'ai-marketing-expert' ) ),
						);
						$posts = get_posts(
							array(
								'numberposts' => 20,
								'post_status' => 'publish',
							)
						);
						foreach ( $posts as $post ) {
							$options[] = array(
								'value' => (int) $post->ID,
								'label' => $post->post_title ? $post->post_title : sprintf( '#%d', $post->ID ),
							);
						}
						return $options;
					},
				),
				array( 'key' => 'url', 'label' => __( 'URL (optional)', 'ai-marketing-expert' ), 'type' => 'text' ),
				array( 'key' => 'keyword_focus', 'label' => __( 'Focus keyword (optional)', 'ai-marketing-expert' ), 'type' => 'text', 'help' => __( 'Leave blank for smart detection: inherits from AI Brain, Yoast SEO, RankMath, or post title.', 'ai-marketing-expert' ) ),
			),
			'handler'     => array( SeoAuditAction::class, 'run' ),
		);

		$actions['enroll_in_funnel'] = array(
			'label'       => __( 'Enroll in Funnel', 'ai-marketing-expert' ),
			'module'      => 'email-marketing',
			'description' => __( 'Enroll a subscriber into an email funnel.', 'ai-marketing-expert' ),
			'is_pro'      => false,
			'available'   => static fn (): bool => $module_active( 'email-marketing' ),
			'fields'      => array(
				array(
					'key'      => 'funnel_id',
					'label'    => __( 'Funnel', 'ai-marketing-expert' ),
					'type'     => 'select',
					'required' => false,
					'default'  => '',
					'help'     => __( 'Optional. Email automation from Email Marketing → Automations.', 'ai-marketing-expert' ),
					'options' => static function (): array {
						global $wpdb;
						$table = $wpdb->prefix . 'aime_funnels';
						if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
							return array();
						}
						$rows    = $wpdb->get_results( "SELECT id, title FROM {$table} ORDER BY title ASC" );
						$options = array();
						foreach ( (array) $rows as $row ) {
							$options[] = array(
								'value' => (int) $row->id,
								'label' => (string) $row->title,
							);
						}
						return $options;
					},
				),
				array(
					'key'     => 'create_if_missing',
					'label'   => __( 'Create contact if missing', 'ai-marketing-expert' ),
					'type'    => 'checkbox',
					'default' => true,
					'help'    => __( 'When the email is not in Contacts yet, create it first instead of failing.', 'ai-marketing-expert' ),
				),
				array(
					'key'      => 'list_ids',
					'label'    => __( 'Lists', 'ai-marketing-expert' ),
					'type'     => 'select',
					'multiple' => true,
					'default'  => [],
					'help'     => __( 'Optional. Adds the contact to these lists without removing existing ones.', 'ai-marketing-expert' ),
					'options'  => static function (): array {
						return self::email_pivot_options( 'lists' );
					},
				),
				array(
					'key'      => 'tag_ids',
					'label'    => __( 'Tags', 'ai-marketing-expert' ),
					'type'     => 'select',
					'multiple' => true,
					'default'  => [],
					'help'     => __( 'Optional. Adds these tags to the contact without removing existing ones.', 'ai-marketing-expert' ),
					'options'  => static function (): array {
						return self::email_pivot_options( 'tags' );
					},
				),
				array( 'key' => 'subscriber_email', 'label' => __( 'Subscriber email', 'ai-marketing-expert' ), 'type' => 'text', 'help' => __( 'Blank = email from the trigger event.', 'ai-marketing-expert' ) ),
			),
			'handler'     => array( FunnelEnrollAction::class, 'run' ),
		);

		$actions['publish_social_post'] = array(
			'label'       => __( 'Publish Social Post', 'ai-marketing-expert' ),
			'module'      => 'social-media',
			'description' => __( 'AI writes a caption and schedules a post to a connected account.', 'ai-marketing-expert' ),
			'is_pro'      => false,
			'available'   => static fn (): bool => $module_active( 'social-media' ),
			'fields'      => array(
				array(
					'key'      => 'account_id',
					'label'    => __( 'Account', 'ai-marketing-expert' ),
					'type'     => 'select',
					'required' => true,
					'options'  => static function (): array {
						global $wpdb;
						$table = $wpdb->prefix . 'aime_social_accounts';
						if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
							return array();
						}
						$rows    = $wpdb->get_results( "SELECT id, name, platform FROM {$table} WHERE status = 'connected' ORDER BY name ASC" );
						$options = array();
						foreach ( (array) $rows as $row ) {
							$options[] = array(
								'value' => (int) $row->id,
								'label' => sprintf( '%s (%s)', (string) $row->name, (string) $row->platform ),
							);
						}
						return $options;
					},
				),
				array( 'key' => 'topic', 'label' => __( 'Topic (blank = workflow topic)', 'ai-marketing-expert' ), 'type' => 'text' ),
				array(
					'key'          => 'topics',
					'label'        => __( 'Topic rotation', 'ai-marketing-expert' ),
					'type'         => 'tokens',
					'is_pro'       => true,
					'help'         => __( 'Add several topics and each run picks a different one — no repeats until every topic has been used. Overrides the single topic above. Hidden when AI Brain step is parent.', 'ai-marketing-expert' ),
					'visible_rule' => array( 'type' => 'parent_not', 'action' => 'ai_brain' ),
				),
				array( 'key' => 'schedule', 'label' => __( 'Schedule (save as draft if off)', 'ai-marketing-expert' ), 'type' => 'checkbox', 'default' => true ),
			),
			'handler'     => array( SocialPostAction::class, 'run' ),
		);

		$actions['send_email_campaign'] = array(
			'label'       => __( 'Create Email Campaign (Draft)', 'ai-marketing-expert' ),
			'module'      => 'email-marketing',
			'description' => __( 'AI writes a subject + body and saves a review-ready draft campaign.', 'ai-marketing-expert' ),
			'is_pro'      => false,
			'available'   => static fn (): bool => $module_active( 'email-marketing' ),
			'fields'      => array(
				array( 'key' => 'topic', 'label' => __( 'Email topic (blank = workflow topic)', 'ai-marketing-expert' ), 'type' => 'text' ),
				array(
					'key'          => 'topics',
					'label'        => __( 'Topic rotation', 'ai-marketing-expert' ),
					'type'         => 'tokens',
					'is_pro'       => true,
					'help'         => __( 'Add several topics and each run picks a different one — no repeats until every topic has been used. Overrides the single topic above. Hidden when AI Brain step is parent.', 'ai-marketing-expert' ),
					'visible_rule' => array( 'type' => 'parent_not', 'action' => 'ai_brain' ),
				),
				array( 'key' => 'title', 'label' => __( 'Internal campaign title', 'ai-marketing-expert' ), 'type' => 'text', 'token_hints' => true, 'help' => __( 'Click a {token} below to insert it — the workflow fills it at run time.', 'ai-marketing-expert' ) ),
			),
			'handler'     => array( EmailCampaignAction::class, 'run' ),
		);

		$actions['generate_ad_copy'] = array(
			'label'       => __( 'Generate Ad Copy', 'ai-marketing-expert' ),
			'module'      => 'content-generator',
			'description' => __( 'AI writes ad-copy variations and saves them as a draft article.', 'ai-marketing-expert' ),
			'is_pro'      => false,
			'available'   => static fn (): bool => $module_active( 'content-generator' ),
			'fields'      => array(
				array( 'key' => 'product', 'label' => __( 'Product / offer (blank = workflow topic)', 'ai-marketing-expert' ), 'type' => 'text' ),
				array(
					'key'    => 'products',
					'label'  => __( 'Product rotation (manual)', 'ai-marketing-expert' ),
					'type'   => 'tokens',
					'is_pro' => true,
					'help'   => __( 'Add several products/offers and each run picks a different one — no repeats until every entry has been used.', 'ai-marketing-expert' ),
				),
				array(
					'key'          => 'wc_products',
					'label'        => __( 'WooCommerce products', 'ai-marketing-expert' ),
					'type'         => 'select',
					'multiple'     => true,
					'is_pro'       => true,
					'help'         => __( 'Pro: each run picks a different product (rotation) and the AI also receives price and short description. On the free tier this selection is ignored and the product comes from the fields below instead.', 'ai-marketing-expert' ),
					'options'      => static function(): array {
						if ( ! class_exists( 'WooCommerce' ) ) {
							return array();
						}
						$products = wc_get_products( array( 'limit' => 100, 'status' => 'publish' ) );
						$options  = array();
						foreach ( $products as $product ) {
							$options[] = array(
								'value' => $product->get_id(),
								'label' => $product->get_name() . ' (#' . $product->get_id() . ')',
							);
						}
						return $options;
					},
					// Static rule: resolved server-side at API time (ActionRegistry).
					'visible_rule' => array( 'type' => 'class_exists', 'class' => 'WooCommerce' ),
				),
				array( 'key' => 'variations', 'label' => __( 'Number of variations', 'ai-marketing-expert' ), 'type' => 'number', 'default' => 3 ),
			),
			'handler'     => array( AdCopyAction::class, 'run' ),
		);

		$actions['ai_brain'] = array(
			'label'       => __( 'AI Brain', 'ai-marketing-expert' ),
			'module'      => 'ai',
			'description' => __( 'AI strategist reads your instructions, avoids recent repeats, analyzes context URLs, and generates a content brief for the next step.', 'ai-marketing-expert' ),
			'is_pro'      => false,
			'available'   => static fn (): bool => true,
			'fields'      => array(
				array(
					'key'            => 'strategy_prompt',
					'label'          => __( 'Strategy prompt', 'ai-marketing-expert' ),
					'type'           => 'textarea',
					'required'       => true,
					'help'           => __( 'Full instructions for the AI strategist.', 'ai-marketing-expert' ),
					// Shows the "Prompt library" browse button (pre-made prompts).
					'prompt_library' => true,
				),
				array(
					'key'  => 'context_urls',
					'label' => __( 'Context URLs (optional)', 'ai-marketing-expert' ),
					'type' => 'textarea',
					'help' => __( 'One URL per line, max 5. AI reads these pages for product context. Cached 30 days by default.', 'ai-marketing-expert' ),
				),
				array(
					'key'     => 'lookback_days',
					'label'   => __( 'Avoid repeating within (days)', 'ai-marketing-expert' ),
					'type'    => 'number',
					'default' => 30,
					'help'    => __( 'Topics written in this many days are treated as recently used.', 'ai-marketing-expert' ),
				),
				array(
					'key'     => 'cache_duration',
					'label'   => __( 'URL cache duration', 'ai-marketing-expert' ),
					'type'    => 'select',
					'default' => 2592000,
					'help'    => __( 'How long to cache fetched URL content.', 'ai-marketing-expert' ),
					'options' => array(
						array( 'value' => 86400, 'label' => __( '1 day', 'ai-marketing-expert' ) ),
						array( 'value' => 604800, 'label' => __( '7 days', 'ai-marketing-expert' ) ),
						array( 'value' => 1209600, 'label' => __( '14 days', 'ai-marketing-expert' ) ),
						array( 'value' => 2592000, 'label' => __( '30 days', 'ai-marketing-expert' ) ),
					),
				),
				array(
					'key'     => 'skill_ids',
					'label'   => __( 'Skills', 'ai-marketing-expert' ),
					'type'    => 'skills',
					'help'    => __( 'Reusable rule blocks merged into the strategist prompt. Pick SEO + Image for daily blogs.', 'ai-marketing-expert' ),
				),
				array(
					'key'     => 'output_format',
					'label'   => __( 'Output format', 'ai-marketing-expert' ),
					'type'    => 'select',
					'default' => 'brief',
					'help'    => __( 'Content Brief = structured markdown for next step. Custom JSON (Pro) = machine-readable brief, also exposed as {ai_brain.json} for downstream steps.', 'ai-marketing-expert' ),
					'options' => array(
						array( 'value' => 'brief', 'label' => __( 'Content Brief', 'ai-marketing-expert' ) ),
						array( 'value' => 'json', 'label' => __( 'Custom JSON (Pro)', 'ai-marketing-expert' ) ),
					),
				),
			),
			'handler'     => array( AiBrainAction::class, 'run' ),
		);

		$actions['custom_prompt'] = array(
			'label'       => __( 'Custom AI Prompt', 'ai-marketing-expert' ),
			'module'      => 'ai',
			'description' => __( 'Run a free-form AI prompt and log the result.', 'ai-marketing-expert' ),
			'is_pro'      => false,
			'available'   => static fn (): bool => true,
			'fields'      => array(
				array(
					'key'            => 'prompt',
					'label'          => __( 'Prompt', 'ai-marketing-expert' ),
					'type'           => 'textarea',
					'required'       => true,
					'prompt_library' => true,
					'token_hints'    => true,
					'help'           => __( 'Click a {token} below to insert it — the workflow fills it at run time.', 'ai-marketing-expert' ),
				),
				array( 'key' => 'save_as_draft', 'label' => __( 'Save output as draft article', 'ai-marketing-expert' ), 'type' => 'checkbox', 'default' => false ),
			),
			'handler'     => array( CustomPromptAction::class, 'run' ),
		);

		$actions['condition'] = array(
			'label'       => __( 'Condition (If/Else)', 'ai-marketing-expert' ),
			'module'      => 'workflow-automation',
			'description' => __( 'Branch the workflow into Yes/No paths based on a check.', 'ai-marketing-expert' ),
			'is_pro'      => true,
			'available'   => static fn (): bool => true,
			'fields'      => array(
				array(
					'key'     => 'check',
					'label'   => __( 'Check', 'ai-marketing-expert' ),
					'type'    => 'select',
					'default' => 'previous_step_succeeded',
					'options' => array(
						array( 'value' => 'previous_step_succeeded', 'label' => __( 'Previous step succeeded', 'ai-marketing-expert' ) ),
						array( 'value' => 'previous_output_contains', 'label' => __( 'Previous output contains…', 'ai-marketing-expert' ) ),
						array( 'value' => 'event_field_contains', 'label' => __( 'Event field contains…', 'ai-marketing-expert' ) ),
						array( 'value' => 'reference_compare', 'label' => __( 'Numeric compare on a step result (score ≥ 80…)', 'ai-marketing-expert' ) ),
					),
				),
				array( 'key' => 'field', 'label' => __( 'Event field (for event checks)', 'ai-marketing-expert' ), 'type' => 'text' ),
				array( 'key' => 'value', 'label' => __( 'Value to look for', 'ai-marketing-expert' ), 'type' => 'text' ),
				array(
					'key'     => 'ref_field',
					'label'   => __( 'Reference field (for numeric compare)', 'ai-marketing-expert' ),
					'type'    => 'text',
					'default' => 'score',
					'help'    => __( 'Dot-path into an upstream result, e.g. "score" from the SEO audit. Falls back through previous steps automatically.', 'ai-marketing-expert' ),
				),
				array(
					'key'     => 'compare',
					'label'   => __( 'Comparison (for numeric compare)', 'ai-marketing-expert' ),
					'type'    => 'select',
					'default' => '>=',
					'options' => array(
						array( 'value' => '>=', 'label' => __( '≥ greater or equal', 'ai-marketing-expert' ) ),
						array( 'value' => '>', 'label' => __( '> greater than', 'ai-marketing-expert' ) ),
						array( 'value' => '<=', 'label' => __( '≤ less or equal', 'ai-marketing-expert' ) ),
						array( 'value' => '<', 'label' => __( '< less than', 'ai-marketing-expert' ) ),
						array( 'value' => '==', 'label' => __( '= equals', 'ai-marketing-expert' ) ),
						array( 'value' => '!=', 'label' => __( '≠ not equals', 'ai-marketing-expert' ) ),
					),
				),
			),
			'handler'     => array( ConditionAction::class, 'run' ),
		);

		$actions['send_notification'] = array(
			'label'       => __( 'Send Notification', 'ai-marketing-expert' ),
			'module'      => 'workflow-automation',
			'description' => __( 'Send an email notification with workflow tokens.', 'ai-marketing-expert' ),
			'is_pro'      => false,
			'available'   => static fn (): bool => true,
			'fields'      => array(
				array( 'key' => 'to', 'label' => __( 'To (blank = admin email)', 'ai-marketing-expert' ), 'type' => 'text', 'token_hints' => true ),
				array( 'key' => 'subject', 'label' => __( 'Subject', 'ai-marketing-expert' ), 'type' => 'text', 'token_hints' => true, 'help' => __( 'Click a {token} below to insert it — the workflow fills it at run time.', 'ai-marketing-expert' ) ),
				array( 'key' => 'body', 'label' => __( 'Body', 'ai-marketing-expert' ), 'type' => 'textarea', 'token_hints' => true, 'help' => __( 'Click a {token} below to insert it — the workflow fills it at run time. Example: {generate_blog_post.edit_url} links the new post.', 'ai-marketing-expert' ) ),
			),
			'handler'     => array( SendNotificationAction::class, 'run' ),
		);

		return $actions;
	}

	/* ── Trigger registry ───────────────────────────────── */

	/**
	 * Register the built-in event triggers.
	 *
	 * @param array $triggers Existing registered triggers.
	 * @return array
	 */
	public function register_builtin_triggers( array $triggers ): array {
		$module_active = static function ( string $module_id ): bool {
			return Plugin::instance()->modules()->is_active( $module_id );
		};

		// Pseudo-entry so the builder's trigger node lists schedule alongside events.
		$triggers['schedule'] = array(
			'label'       => __( 'Schedule', 'ai-marketing-expert' ),
			'module'      => 'workflow-automation',
			'description' => __( 'Run on a recurring or one-time schedule.', 'ai-marketing-expert' ),
			'hook'        => '',
			'hook_args'   => 0,
			'available'   => static fn (): bool => true,
			'fields'      => array(),
		);

		$triggers['post_published'] = array(
			'label'       => __( 'Post Published', 'ai-marketing-expert' ),
			'module'      => 'workflow-automation',
			'description' => __( 'Runs when a post is published for the first time.', 'ai-marketing-expert' ),
			'hook'        => 'transition_post_status',
			'hook_args'   => 3,
			'available'   => static fn (): bool => true,
			'payload_fields' => array(
				array( 'key' => 'post_id', 'label' => __( 'Post ID', 'ai-marketing-expert' ) ),
				array( 'key' => 'post_title', 'label' => __( 'Post title', 'ai-marketing-expert' ) ),
				array( 'key' => 'post_url', 'label' => __( 'Post URL', 'ai-marketing-expert' ) ),
			),
			'fields'      => array(
				array(
					'key'     => 'post_type',
					'label'   => __( 'Post type', 'ai-marketing-expert' ),
					'type'    => 'select',
					'default' => '',
					'options' => static function (): array {
						$options = array(
							array( 'value' => '', 'label' => __( 'Any post type', 'ai-marketing-expert' ) ),
						);
						foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
							if ( 'attachment' === $type->name ) {
								continue;
							}
							$options[] = array(
								'value' => (string) $type->name,
								'label' => (string) $type->labels->singular_name,
							);
						}
						return $options;
					},
				),
			),
			'match'       => static function ( array $config, ...$args ) {
				list( $new_status, $old_status, $post ) = array_pad( $args, 3, null );
				if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post ) {
					return false;
				}
				if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
					return false;
				}
				$wanted_type = trim( (string) ( $config['post_type'] ?? '' ) );
				if ( '' !== $wanted_type && $post->post_type !== $wanted_type ) {
					return false;
				}
				if ( in_array( $post->post_type, array( 'revision', 'attachment', 'nav_menu_item' ), true ) ) {
					return false;
				}
				return array(
					'post_id'    => (int) $post->ID,
					'post_title' => (string) $post->post_title,
					'post_url'   => (string) get_permalink( $post ),
					'post_type'  => (string) $post->post_type,
				);
			},
		);

		$triggers['subscriber_created'] = array(
			'label'       => __( 'New Subscriber', 'ai-marketing-expert' ),
			'module'      => 'email-marketing',
			'description' => __( 'Runs when a subscriber is added to the email list.', 'ai-marketing-expert' ),
			'hook'        => 'aime_subscriber_created',
			'hook_args'   => 2,
			'available'   => static fn (): bool => $module_active( 'email-marketing' ),
			'payload_fields' => array(
				array( 'key' => 'email', 'label' => __( 'Subscriber email', 'ai-marketing-expert' ) ),
				array( 'key' => 'name', 'label' => __( 'Subscriber name', 'ai-marketing-expert' ) ),
			),
			'fields'      => array(),
			'match'       => static function ( array $config, ...$args ) {
				list( $subscriber_id, $data ) = array_pad( $args, 2, null );
				if ( ! $subscriber_id ) {
					return false;
				}
				$data = is_array( $data ) ? $data : array();
				$name = trim( ( $data['first_name'] ?? '' ) . ' ' . ( $data['last_name'] ?? '' ) );
				return array(
					'subscriber_id' => (int) $subscriber_id,
					'email'         => (string) ( $data['email'] ?? '' ),
					'name'          => $name,
				);
			},
		);

		$triggers['chatbot_lead'] = array(
			'label'       => __( 'Chatbot Lead Captured', 'ai-marketing-expert' ),
			'module'      => 'chatbot',
			'description' => __( 'Runs when the chatbot captures a new lead.', 'ai-marketing-expert' ),
			'hook'        => 'aime_chatbot_lead_captured',
			'hook_args'   => 1,
			'available'   => static fn (): bool => $module_active( 'chatbot' ),
			'payload_fields' => array(
				array( 'key' => 'email', 'label' => __( 'Lead email', 'ai-marketing-expert' ) ),
				array( 'key' => 'first_name', 'label' => __( 'First name', 'ai-marketing-expert' ) ),
				array( 'key' => 'source', 'label' => __( 'Source', 'ai-marketing-expert' ) ),
			),
			'fields'      => array(),
			'match'       => static function ( array $config, ...$args ) {
				$lead_data = is_array( $args[0] ?? null ) ? $args[0] : array();
				if ( empty( $lead_data['email'] ) ) {
					return false;
				}
				return array(
					'email'      => (string) $lead_data['email'],
					'first_name' => (string) ( $lead_data['first_name'] ?? '' ),
					'source'     => (string) ( $lead_data['source'] ?? 'chatbot' ),
					'metadata'   => is_array( $lead_data['metadata'] ?? null ) ? $lead_data['metadata'] : array(),
				);
			},
		);

		return $triggers;
	}

	/* ── Free/Pro limits ────────────────────────────────── */

	/**
	 * Contribute this module's free-tier limits.
	 *
	 * @param array $limits Existing limits.
	 * @return array
	 */
	public function register_free_limits( array $limits ): array {
		$limits['workflows_active']     = 2;
		$limits['workflow_steps']       = 3;
		$limits['workflow_runs_monthly'] = 30;
		return $limits;
	}

	/* ── REST routes ─────────────────────────────────────── */

	public function register_routes(): void {
		( new Controllers\WorkflowRestController() )->register_routes();
	}

	/* ── Database tables ─────────────────────────────────── */

	private function maybe_create_tables(): void {
		$installed = get_option( 'aime_workflow_automation_db_version', '' );
		if ( version_compare( $installed, AIME_WORKFLOW_DB_VERSION, '>=' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$this->create_tables( $wpdb->get_charset_collate() );

		if ( '' !== $installed && version_compare( $installed, '2.0.0', '<' ) ) {
			$this->migrate_to_v2();
		}

		update_option( 'aime_workflow_automation_db_version', AIME_WORKFLOW_DB_VERSION );
	}

	/**
	 * v1 → v2 backfill: give every legacy linear step a stable step_key,
	 * chain parent_key from the previous sibling, and seed vertical positions
	 * so the canvas renders the old sequence as a straight top-down flow.
	 */
	private function migrate_to_v2(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$workflow_ids = $wpdb->get_col( "SELECT DISTINCT workflow_id FROM {$p}aime_workflow_steps WHERE step_key = ''" );

		foreach ( $workflow_ids as $workflow_id ) {
			$steps = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, step_order FROM {$p}aime_workflow_steps WHERE workflow_id = %d ORDER BY step_order ASC, id ASC",
				$workflow_id
			) );

			$parent_key = '';
			foreach ( $steps as $step ) {
				$step_key = 's' . (int) $step->id;
				$wpdb->update(
					$p . 'aime_workflow_steps',
					array(
						'step_key'   => $step_key,
						'parent_key' => $parent_key,
						'branch'     => 'default',
						'position_x' => 0,
						'position_y' => (int) $step->step_order * 140,
					),
					array( 'id' => (int) $step->id )
				);
				$parent_key = $step_key;
			}
		}
	}

	public function create_tables( string $charset_collate ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		dbDelta( "CREATE TABLE {$p}aime_workflows (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			description TEXT,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'schedule',
			trigger_event VARCHAR(60) NOT NULL DEFAULT '',
			trigger_config LONGTEXT,
			schedule_type VARCHAR(20) NOT NULL DEFAULT 'weekly',
			schedule_time VARCHAR(5) NOT NULL DEFAULT '09:00',
			schedule_days VARCHAR(40) NOT NULL DEFAULT '',
			schedule_day_of_month TINYINT UNSIGNED NOT NULL DEFAULT 1,
			interval_value INT UNSIGNED NOT NULL DEFAULT 1,
			interval_unit VARCHAR(10) NOT NULL DEFAULT 'days',
			topic VARCHAR(500) NOT NULL DEFAULT '',
			tone VARCHAR(50) NOT NULL DEFAULT 'professional',
			brand_voice_id BIGINT UNSIGNED DEFAULT NULL,
			failure_policy VARCHAR(20) NOT NULL DEFAULT 'stop',
			next_run_at DATETIME DEFAULT NULL,
			last_run_at DATETIME DEFAULT NULL,
			run_count INT UNSIGNED NOT NULL DEFAULT 0,
			is_pro TINYINT(1) NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_next_run (next_run_at),
			KEY idx_trigger (trigger_type, trigger_event),
			KEY idx_created (created_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$p}aime_workflow_steps (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			workflow_id BIGINT UNSIGNED NOT NULL,
			step_key VARCHAR(64) NOT NULL DEFAULT '',
			parent_key VARCHAR(64) NOT NULL DEFAULT '',
			branch VARCHAR(10) NOT NULL DEFAULT 'default',
			step_order INT UNSIGNED NOT NULL DEFAULT 0,
			action_type VARCHAR(60) NOT NULL DEFAULT '',
			config LONGTEXT,
			tone_override VARCHAR(50) DEFAULT NULL,
			run_condition VARCHAR(30) NOT NULL DEFAULT 'always',
			position_x INT NOT NULL DEFAULT 0,
			position_y INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_workflow (workflow_id),
			KEY idx_step_key (workflow_id, step_key),
			KEY idx_order (workflow_id, step_order)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$p}aime_workflow_executions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			workflow_id BIGINT UNSIGNED NOT NULL,
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'schedule',
			status VARCHAR(20) NOT NULL DEFAULT 'running',
			steps_total INT UNSIGNED NOT NULL DEFAULT 0,
			steps_succeeded INT UNSIGNED NOT NULL DEFAULT 0,
			steps_failed INT UNSIGNED NOT NULL DEFAULT 0,
			steps_skipped INT UNSIGNED NOT NULL DEFAULT 0,
			context LONGTEXT,
			error TEXT,
			started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			finished_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_workflow (workflow_id),
			KEY idx_status (status),
			KEY idx_started (started_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$p}aime_workflow_outputs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			execution_id BIGINT UNSIGNED NOT NULL,
			workflow_id BIGINT UNSIGNED NOT NULL,
			step_id BIGINT UNSIGNED DEFAULT NULL,
			step_key VARCHAR(64) DEFAULT NULL,
			branch VARCHAR(10) DEFAULT NULL,
			step_order INT UNSIGNED NOT NULL DEFAULT 0,
			action_type VARCHAR(60) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'success',
			preview TEXT,
			reference LONGTEXT,
			error TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_execution (execution_id),
			KEY idx_workflow (workflow_id),
			KEY idx_created (created_at)
		) $charset_collate;" );
	}

	/**
	 * Register this module's tables for uninstall cleanup.
	 *
	 * @param array $tables Existing tables.
	 * @return array
	 */
	public function register_uninstall_tables( array $tables ): array {
		global $wpdb;
		$p        = $wpdb->prefix;
		$tables[] = "{$p}aime_workflows";
		$tables[] = "{$p}aime_workflow_steps";
		$tables[] = "{$p}aime_workflow_executions";
		$tables[] = "{$p}aime_workflow_outputs";
		return $tables;
	}

	/* ── Dashboard stats ─────────────────────────────────── */

	/**
	 * Options for the Enroll-in-Funnel step's Lists/Tags multi-selects.
	 * Reads straight from the email-marketing tables; empty when the module
	 * is inactive (the whole action is unavailable then anyway).
	 *
	 * @param string $which 'lists' or 'tags'.
	 * @return array<int, array{value:int,label:string}>
	 */
	public static function email_pivot_options( string $which ): array {
		global $wpdb;
		$table = $wpdb->prefix . ( 'lists' === $which ? 'aime_lists' : 'aime_tags' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$rows    = $wpdb->get_results( "SELECT id, title FROM {$table} ORDER BY title ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$options = array();
		foreach ( (array) $rows as $row ) {
			$options[] = array(
				'value' => (int) $row->id,
				'label' => (string) $row->title,
			);
		}
		return $options;
	}

	public function get_stats(): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_workflows" ) );
		if ( ! $table_exists ) {
			return array(
				'total_workflows'  => 0,
				'active_workflows' => 0,
				'runs_last_30_days' => 0,
				'next_run_at'      => null,
			);
		}

		return array(
			'total_workflows'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_workflows" ),
			'active_workflows'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_workflows WHERE status = %s", 'active' ) ),
			'runs_last_30_days' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aime_workflow_executions WHERE started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" ),
			'runs_this_month'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aime_workflow_executions WHERE status != 'skipped' AND started_at >= %s", gmdate( 'Y-m-01 00:00:00' ) ) ),
			'runs_monthly_limit' => aime_has_pro() ? null : (int) ( aime_free_limits()['workflow_runs_monthly'] ?? 30 ),
			'next_run_at'       => $wpdb->get_var( $wpdb->prepare( "SELECT MIN(next_run_at) FROM {$p}aime_workflows WHERE status = %s AND next_run_at IS NOT NULL", 'active' ) ),
		);
	}
}

/* ── Register with Module Manager ──────────────────────── */

add_action( 'aime_load_module_workflow-automation', function ( $manager ) {
	$manager->register( new WorkflowAutomationModule() );
} );



