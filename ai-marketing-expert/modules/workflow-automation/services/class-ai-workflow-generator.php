<?php
/**
 * Text-to-Workflow AI Generator Service.
 *
 * Translates natural language instructions into a fully-structured,
 * valid workflow definition with triggers, conditions, and actions
 * rendered on the visual canvas.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Services
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Services;

use WPSpace\AiMarketingExpert\AiProvider;
use WPSpace\AiMarketingExpert\Plugin;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\ActionRegistry;
use WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Includes\TriggerRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiWorkflowGenerator {

	/**
	 * Generate a complete workflow definition from natural language prompt.
	 *
	 * @param string $user_prompt User prompt in plain English or Bengali.
	 * @param int    $brand_voice_id Optional brand voice ID.
	 * @return array|\WP_Error Structured workflow definition or error.
	 */
	public static function generate( string $user_prompt, int $brand_voice_id = 0 ) {
		$user_prompt = trim( $user_prompt );
		if ( empty( $user_prompt ) ) {
			return new \WP_Error( 'empty_prompt', __( 'Please provide a description of the workflow you want to create.', 'ai-marketing-expert' ) );
		}

		// Gather all registered triggers for the AI architect.
		$available_triggers = array();
		foreach ( TriggerRegistry::all() as $key => $def ) {
			$available_triggers[ $key ] = array(
				'label'          => $def['label'] ?? $key,
				'description'    => $def['description'] ?? '',
				'hook'           => ! empty( $def['hook'] ),
				'payload_fields' => array_column( (array) ( $def['payload_fields'] ?? array() ), 'key' ),
			);
		}

		// Gather available actions.
		$available_actions = array();
		foreach ( ActionRegistry::all() as $key => $def ) {
			$avail = true;
			if ( is_callable( $def['available'] ?? null ) ) {
				$avail = (bool) call_user_func( $def['available'] );
			}
			if ( $avail ) {
				$field_keys = array();
				foreach ( (array) ( $def['fields'] ?? array() ) as $f ) {
					$field_keys[] = array(
						'key'      => $f['key'] ?? '',
						'label'    => $f['label'] ?? '',
						'type'     => $f['type'] ?? 'text',
						'required' => ! empty( $f['required'] ),
					);
				}
				$available_actions[ $key ] = array(
					'label'       => $def['label'] ?? $key,
					'description' => $def['description'] ?? '',
					'fields'      => $field_keys,
				);
			}
		}

		// Context hints (WooCommerce, Contact Form 7, active social platforms).
		$has_wc  = class_exists( 'WooCommerce' );
		$has_cf7 = class_exists( 'WPCF7_ContactForm' ) || defined( 'WPCF7_VERSION' );
		global $wpdb;
		$social_table = $wpdb->prefix . 'aime_social_accounts';
		$connected_social = array();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $social_table ) ) === $social_table ) {
			$connected_social = (array) $wpdb->get_col( "SELECT DISTINCT platform FROM {$social_table} WHERE status = 'connected'" );
		}

		$system_prompt = "You are an expert Marketing Automation Architect for WordPress.
Your job is to translate the user's natural language goal into a strictly valid, JSON-structured automation workflow.

### ENVIRONMENT CONTEXT:
- E-Commerce (WooCommerce): AVAILABLE via 'woo_cart_abandoned' and 'woo_order_completed' triggers.
- Form Submissions: AVAILABLE via 'cf7_submission' and 'inbound_webhook' triggers.
- Social Media Publishing: AVAILABLE via 'publish_social_post' action (supports Facebook, LinkedIn, Instagram, Twitter, etc.).

### AVAILABLE TRIGGERS:
" . wp_json_encode( $available_triggers, JSON_PRETTY_PRINT ) . "

### AVAILABLE ACTIONS:
" . wp_json_encode( $available_actions, JSON_PRETTY_PRINT ) . "

### RULES & SCHEMA:
1. Decide whether the workflow is 'schedule' or 'event'.
   - If recurring or time-based (e.g. 'every Monday', 'daily', 'every week'):
     'trigger_type': 'schedule', 'schedule_type': 'weekly'|'daily'|'monthly'|'custom', 'schedule_time': '09:00', 'schedule_days': '1' (1=Mon, 2=Tue...)
   - If reacting to an event:
     'trigger_type': 'event', 'trigger_event': '<exact_trigger_key>'
     * When user mentions WooCommerce order / completed order / purchase / review request: ALWAYS use 'trigger_event': 'woo_order_completed'! NEVER use 'inbound_webhook'!
     * When user mentions abandoned cart / WooCommerce cart recovery: ALWAYS use 'trigger_event': 'woo_cart_abandoned'!
     * When user mentions Contact Form 7 / form submission: ALWAYS use 'trigger_event': 'cf7_submission'!
     * When user mentions external webhook or API: use 'trigger_event': 'inbound_webhook'!
2. Dynamic tokens: You can use dynamic placeholders in text/email/content configs:
   - {topic}
   - {event.customer_name} or {event.name}
   - {event.email} or {event.customer_email}
   - {event.cart_total}
   - {event.recovery_url} (for abandoned carts: 1-click restore full cart)
   - {event.single_qty_url} (for abandoned carts: 1-click single-quantity checkout)
   - {event.single_item_links} (for abandoned carts: HTML 1-click checkout links for each item)
   - {event.cart_type} ('single_item', 'duplicate_qty', or 'multiple_items')
   - {event.product_names}
   - {event.order_id}
   - {event.post_title}
   - {event.message} (for form submissions)
3. Special Actions & Config Defaults:
   - 'condition': Used to branch workflow into Yes/No paths. Config: { 'check': 'event_field_equals'|'event_field_contains'|'previous_step_succeeded'|'reference_compare', 'field': 'cart_type', 'value': 'duplicate_qty' }. Steps attached to this condition MUST set 'branch': 'yes' or 'branch': 'no'.
   - 'woo_cart_abandoned' Smart Recovery: When user mentions smart cart recovery or single-item / duplicate quantity / multiple items handling, use trigger 'woo_cart_abandoned'.
     * Available cart types: 'duplicate_qty' (accidental multiple quantity of 1 product), 'multiple_items' (multiple distinct products), and 'single_item' (1 product, quantity 1).
     * For 3-Way Smart Cart Branching:
       - step_1 (action_type: 'condition'): config { 'check': 'event_field_equals', 'field': 'cart_type', 'value': 'duplicate_qty' }
       - step_2 (action_type: 'send_email', parent_key: 'step_1', branch: 'yes'): send duplicate-quantity recovery email. Empathize with mobile double clicks, offer instant 1-click single-qty checkout button inside `<div style='margin: 18px 0;'><a href='{event.single_qty_url}' style='display:inline-block;background-color:#2563eb;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;'>👉 Buy Just 1 Item (1-Click Fast Checkout)</a></div>` and full cart restore `<div style='margin: 14px 0;'><a href='{event.recovery_url}' style='display:inline-block;background-color:#0f172a;color:#ffffff;padding:10px 22px;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;text-align:center;'>👉 Restore Entire Cart &amp; Complete Order</a></div>`.
       - step_3 (action_type: 'condition', parent_key: 'step_1', branch: 'no'): config { 'check': 'event_field_equals', 'field': 'cart_type', 'value': 'multiple_items' }
       - step_4 (action_type: 'send_email', parent_key: 'step_3', branch: 'yes'): send multiple-items email. Compliment their taste, list products '{event.product_names}' ({event.cart_total} {event.currency}), provide individual 1-click links '{event.single_item_links}', and full bundle restore `<div style='margin: 18px 0;'><a href='{event.recovery_url}' style='display:inline-block;background-color:#0f172a;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;'>👉 Restore Entire Cart &amp; Complete Order &raquo;</a></div>`.
       - step_5 (action_type: 'send_email', parent_key: 'step_3', branch: 'no'): send single-item recovery email. Reassure customer item is reserved, provide 1-click order completion button inside `<div style='margin: 18px 0;'><a href='{event.recovery_url}' style='display:inline-block;background-color:#2563eb;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;'>👉 Complete My Order in 1 Click &raquo;</a></div>`, and trust badges.
   - 'publish_social_post': When the user asks for social media updates, posting to LinkedIn, Facebook, Instagram, Twitter, or social blast, ALWAYS use 'publish_social_post'! Config: { 'account_id': 0, 'topic': '', 'schedule': true }. NOTE: Leave 'topic' as empty string '' (blank) so it automatically inherits from the upstream AI Brain step or workflow! NEVER put '{ai_brain.topic}' into the topic field!
   - 'generate_blog_post': If an AI Brain step is present, leave 'topic' as empty string '' (it inherits automatically from AI Brain). NEVER put '{ai_brain.topic}' into the topic field!
   - 'run_seo_audit': Config: { 'wp_post_id': -1, 'keyword_focus': '' }. NOTE: 'wp_post_id' MUST be -1 (numeric, meaning previous step). NEVER use token strings like '{step_2.post_id}' or '{generate_blog_post.post_id}' for wp_post_id!
   - 'enroll_in_funnel': Config: { 'funnel_id': 0, 'create_if_missing': true, 'subscriber_email': '{event.email}' }. NOTE: 'funnel_id' MUST be 0. NEVER use strings like 'onboarding_funnel'.
   - 'delay': When user specifies a pause or wait between actions (e.g. 'wait 5 minutes', 'wait 2 hours'), create a 'delay' step with config: { 'delay_value': 5, 'delay_unit': 'minutes'|'hours'|'days'|'seconds' }.
   - 'send_email': Direct customer email dispatch. Config: { 'to': '{event.email}', 'subject': '...', 'body': '...' }.
     CRITICAL EMAIL QUALITY & FORMATTING RULES:
     * NEVER write lazy 1-line or 2-line emails! All emails MUST be structured, persuasive, high-converting sales emails with multiple clear paragraphs.
     * NEVER put CTA buttons directly on the same line as text! ALWAYS wrap every CTA button inside a '<div style=\"margin: 18px 0;\"><a ...>...</a></div>' container so it has generous margin-top and margin-bottom and sits on its own line.
     * Include: (1) Warm customer greeting with name token, (2) Empathic acknowledgment & cart summary ({event.product_names}, {event.cart_total} {event.currency}), (3) Solution & 1-click CTA button in a div container, (4) Trust badge checklist (100% Safe Checkout, Fast Dispatch, Easy Returns), (5) Reassurance to reply to the email with questions.
   - 'send_notification': Internal admin email alert. Config: { 'subject': '...', 'body': '...' }.
4. The 'steps' array must be a valid tree graph:
   - 'step_key': unique string like 'step_1', 'step_2', 'step_3'
   - 'parent_key': '' for the first step connected to the trigger, or the 'step_key' of its parent step.
   - 'branch': 'default' (or 'yes'/'no' if the parent is a 'condition' action).
   - 'action_type': must match an exact key from AVAILABLE ACTIONS.
   - 'config': key-value pairs appropriate for that action's fields.
5. Keep the workflow concise and high-converting (typically 2 to 4 steps).
6. Graceful Fallback for Unsupported Tools:
   - Social media (Facebook, LinkedIn, Instagram, Twitter) IS SUPPORTED via 'publish_social_post'. Always use 'publish_social_post' for them.
   - WooCommerce order/cart events ARE SUPPORTED via 'woo_order_completed' and 'woo_cart_abandoned'. Always use them for WooCommerce requests.
   - If user requests truly external services NOT in AVAILABLE ACTIONS (e.g. Telegram, WhatsApp, SMS, Slack, Google Sheets, Zapier):
     * NEVER hallucinate fake action keys.
     * Intelligently adapt to the closest supported action: use 'send_notification' (for admin alerts) or 'send_email' (for user messaging).
     * In the workflow 'description', add a brief note: '(Adapted external tool to Email Alert)'.
7. Output MUST be ONLY valid JSON matching this schema:
{
  \"name\": \"Short descriptive workflow title\",
  \"description\": \"One-sentence explanation of what this automation accomplishes\",
  \"trigger_type\": \"schedule\" or \"event\",
  \"trigger_event\": \"trigger_key if event, otherwise empty string\",
  \"trigger_config\": {},
  \"schedule_type\": \"weekly\",
  \"schedule_time\": \"09:00\",
  \"schedule_days\": \"1\",
  \"topic\": \"Default topic if applicable\",
  \"steps\": [
    {
      \"step_key\": \"step_1\",
      \"parent_key\": \"\",
      \"branch\": \"default\",
      \"action_type\": \"exact_action_key\",
      \"config\": {}
    }
  ]
}";

		$messages_prompt = $system_prompt . "\n\n### USER REQUEST:\n" . $user_prompt . "\n\nRespond ONLY with the raw JSON object.";

		$response = AiProvider::generate( $messages_prompt, 'text', 2500, array( 'json_mode' => true ) );

		if ( is_wp_error( $response ) ) {
			$fallback = self::match_fallback_recipe( $user_prompt, $brand_voice_id );
			if ( $fallback ) {
				return $fallback;
			}
			return $response;
		}

		$raw_content = trim( (string) ( $response['content'] ?? '' ) );
		if ( empty( $raw_content ) ) {
			$fallback = self::match_fallback_recipe( $user_prompt, $brand_voice_id );
			if ( $fallback ) {
				return $fallback;
			}
			return new \WP_Error( 'ai_empty_response', __( 'AI did not return any workflow content. Please try again with more details.', 'ai-marketing-expert' ) );
		}

		// Strip markdown fences if AI included them.
		if ( 0 === strpos( $raw_content, '```' ) ) {
			$raw_content = preg_replace( '/^```(?:json)?\s*/i', '', $raw_content );
			$raw_content = preg_replace( '/\s*```$/', '', $raw_content );
			$raw_content = trim( (string) $raw_content );
		}

		$data = json_decode( $raw_content, true );
		if ( ! is_array( $data ) || empty( $data['steps'] ) ) {
			$fallback = self::match_fallback_recipe( $user_prompt, $brand_voice_id );
			if ( $fallback ) {
				return $fallback;
			}
			return new \WP_Error( 'invalid_json', __( 'Failed to parse AI-generated workflow. Please rephrase your request.', 'ai-marketing-expert' ) );
		}

		// Sanitize & enforce valid tree structure.
		$sanitized_steps = array();
		$valid_keys      = array();

		foreach ( (array) $data['steps'] as $idx => $step ) {
			if ( ! is_array( $step ) || empty( $step['action_type'] ) ) {
				continue;
			}

			$action_type = sanitize_key( (string) $step['action_type'] );
			if ( ! ActionRegistry::get( $action_type ) ) {
				continue;
			}

			$step_key = sanitize_key( (string) ( $step['step_key'] ?? ( 'step_' . ( $idx + 1 ) ) ) );
			$parent   = sanitize_key( (string) ( $step['parent_key'] ?? '' ) );

			// Ensure valid parent key.
			if ( ! empty( $parent ) && ! in_array( $parent, $valid_keys, true ) ) {
				$parent = ! empty( $valid_keys ) ? end( $valid_keys ) : '';
			}

			$valid_keys[] = $step_key;

			$config = is_array( $step['config'] ?? null ) ? $step['config'] : array();

			// Normalize specific action configs to ensure clean UI state.
			if ( 'run_seo_audit' === $action_type ) {
				// Must be -1 for previous step; AI often outputs tokens like '{step_2.post_id}'
				if ( ! isset( $config['wp_post_id'] ) || ! is_numeric( $config['wp_post_id'] ) || 0 === (int) $config['wp_post_id'] ) {
					$config['wp_post_id'] = -1;
				} else {
					$config['wp_post_id'] = (int) $config['wp_post_id'];
				}
			} elseif ( 'enroll_in_funnel' === $action_type ) {
				// funnel_id must be integer (0 = unselected)
				$config['funnel_id'] = isset( $config['funnel_id'] ) && is_numeric( $config['funnel_id'] ) ? absint( $config['funnel_id'] ) : 0;
			} elseif ( 'publish_social_post' === $action_type ) {
				// account_id must be integer (0 = unselected)
				$config['account_id'] = isset( $config['account_id'] ) && is_numeric( $config['account_id'] ) ? absint( $config['account_id'] ) : 0;
			}

			// Clean any literal dynamic tokens from topic field so it properly inherits from AI Brain or workflow topic.
			if ( isset( $config['topic'] ) ) {
				$t = trim( (string) $config['topic'] );
				if ( '{ai_brain.topic}' === $t || '{topic}' === $t || '{step_1.topic}' === $t || false !== stripos( $t, 'ai_brain' ) ) {
					$config['topic'] = '';
				}
			}

			$sanitized_steps[] = array(
				'step_key'       => $step_key,
				'parent_key'     => $parent,
				'branch'         => in_array( $step['branch'] ?? '', array( 'yes', 'no' ), true ) ? $step['branch'] : 'default',
				'action_type'    => $action_type,
				'config'         => $config,
				'position_x'     => 0,
				'position_y'     => 0,
				'run_condition'  => 'always',
			);
		}

		if ( empty( $sanitized_steps ) ) {
			return new \WP_Error( 'no_valid_steps', __( 'AI could not find matching actions for your request. Please try with different instructions.', 'ai-marketing-expert' ) );
		}

		$trigger_type  = in_array( $data['trigger_type'] ?? '', array( 'schedule', 'event' ), true ) ? $data['trigger_type'] : 'schedule';
		$trigger_event = sanitize_key( (string) ( $data['trigger_event'] ?? '' ) );

		// Failsafe normalization for trigger_event based on user prompt.
		if ( ( empty( $trigger_event ) || 'inbound_webhook' === $trigger_event ) && ( false !== stripos( $user_prompt, 'order' ) || false !== stripos( $user_prompt, 'purchase' ) ) ) {
			$trigger_event = 'woo_order_completed';
			$trigger_type  = 'event';
		} elseif ( ( empty( $trigger_event ) || 'inbound_webhook' === $trigger_event ) && false !== stripos( $user_prompt, 'cart' ) ) {
			$trigger_event = 'woo_cart_abandoned';
			$trigger_type  = 'event';
		} elseif ( ( empty( $trigger_event ) || 'inbound_webhook' === $trigger_event ) && ( false !== stripos( $user_prompt, 'contact form' ) || false !== stripos( $user_prompt, 'cf7' ) || false !== stripos( $user_prompt, 'form submission' ) ) ) {
			$trigger_event = 'cf7_submission';
			$trigger_type  = 'event';
		}

		$workflow_topic = sanitize_text_field( (string) ( $data['topic'] ?? '' ) );
		if ( false !== stripos( $workflow_topic, 'ai_brain' ) || '{topic}' === $workflow_topic ) {
			$workflow_topic = '';
		}

		return array(
			'name'           => sanitize_text_field( (string) ( $data['name'] ?? __( 'AI Autopilot Workflow', 'ai-marketing-expert' ) ) ),
			'description'    => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'trigger_type'   => $trigger_type,
			'trigger_event'  => $trigger_event,
			'trigger_config' => is_array( $data['trigger_config'] ?? null ) ? $data['trigger_config'] : array(),
			'schedule_type'  => sanitize_key( (string) ( $data['schedule_type'] ?? 'weekly' ) ),
			'schedule_time'  => sanitize_text_field( (string) ( $data['schedule_time'] ?? '09:00' ) ),
			'schedule_days'  => sanitize_text_field( (string) ( $data['schedule_days'] ?? '1' ) ),
			'topic'          => $workflow_topic,
			'brand_voice_id' => absint( $brand_voice_id ),
			'steps'          => $sanitized_steps,
		);
	}

	/**
	 * Matches fallback recipe for popular built-in prompts if AI provider is unavailable.
	 *
	 * @param string $user_prompt    User prompt.
	 * @param int    $brand_voice_id Brand voice ID.
	 * @return array|null
	 */
	public static function match_fallback_recipe( string $user_prompt, int $brand_voice_id = 0 ): ?array {
		$lower = strtolower( $user_prompt );

		// 1. Advanced 3-Way Smart Cart Recovery
		if ( ( false !== strpos( $lower, '3-way' ) || false !== strpos( $lower, 'multiple_items' ) || false !== strpos( $lower, 'multiple items' ) || false !== strpos( $lower, 'মাল্টিপল' ) || false !== strpos( $lower, 'তিনটা' ) ) && ( false !== strpos( $lower, 'cart' ) || false !== strpos( $lower, 'কার্ট' ) ) ) {
			return array(
				'name'           => __( 'WooCommerce Advanced Smart Cart Recovery (3-Way)', 'ai-marketing-expert' ),
				'description'    => __( 'Advanced 3-way abandoned cart recovery branching on duplicate quantities, multiple products, and single items.', 'ai-marketing-expert' ),
				'trigger_type'   => 'event',
				'trigger_event'  => 'woo_cart_abandoned',
				'trigger_config' => array(),
				'schedule_type'  => 'weekly',
				'schedule_time'  => '09:00',
				'schedule_days'  => '1',
				'topic'          => '',
				'brand_voice_id' => $brand_voice_id,
				'steps'          => array(
					array(
						'step_key'      => 'step_1',
						'parent_key'    => '',
						'branch'        => 'default',
						'action_type'   => 'condition',
						'config'        => array(
							'check' => 'event_field_equals',
							'field' => 'cart_type',
							'value' => 'duplicate_qty',
						),
						'position_x'    => 0,
						'position_y'    => 0,
						'run_condition' => 'always',
					),
					array(
						'step_key'      => 'step_2',
						'parent_key'    => 'step_1',
						'branch'        => 'yes',
						'action_type'   => 'send_email',
						'config'        => array(
							'to'      => '{event.email}',
							'subject' => __( '🛒 Quick question, {event.customer_name}: Did you mean to add {event.product_names} more than once?', 'ai-marketing-expert' ),
							'body'    => __( "Hi {event.customer_name},\n\nWe noticed you were checking out {event.product_names}, but left before completing your purchase.\n\nWhen reviewing your cart, we saw that multiple quantities were added (Cart Total: {event.cart_total} {event.currency}).\n\nIt happens all the time on mobile screens and fast clicks! If you only wanted to purchase a SINGLE item, you don't need to manually edit or adjust your cart — we've prepared an instant 1-click checkout for you:\n\n<div style=\"margin: 18px 0;\">\n  <a href=\"{event.single_qty_url}\" style=\"display:inline-block;background-color:#2563eb;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;\">👉 Buy Just 1 Item (1-Click Fast Checkout)</a>\n</div>\n\nPrefer to get all of them and keep the full order? No problem:\n\n<div style=\"margin: 14px 0;\">\n  <a href=\"{event.recovery_url}\" style=\"display:inline-block;background-color:#0f172a;color:#ffffff;padding:10px 22px;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;text-align:center;\">👉 Restore Entire Cart &amp; Complete Order</a>\n</div>\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🌟 Shop With Confidence:\n✓ 100% Secure & Encrypted Payment\n✓ Fast Dispatch & Reliable Tracking\n✓ Easy Returns & Dedicated Support\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\nIf you had any questions or need a hand completing your order, simply reply to this email and our team will gladly assist you!\n\nWarm regards,\n{workflow_name}", 'ai-marketing-expert' ),
						),
						'position_x'    => 0,
						'position_y'    => 0,
						'run_condition' => 'always',
					),
					array(
						'step_key'      => 'step_3',
						'parent_key'    => 'step_1',
						'branch'        => 'no',
						'action_type'   => 'condition',
						'config'        => array(
							'check' => 'event_field_equals',
							'field' => 'cart_type',
							'value' => 'multiple_items',
						),
						'position_x'    => 0,
						'position_y'    => 0,
						'run_condition' => 'always',
					),
					array(
						'step_key'      => 'step_4',
						'parent_key'    => 'step_3',
						'branch'        => 'yes',
						'action_type'   => 'send_email',
						'config'        => array(
							'to'      => '{event.email}',
							'subject' => __( 'Can\'t decide on everything, {event.customer_name}? Buy just your favorite in 1-click! 🛍️✨', 'ai-marketing-expert' ),
							'body'    => __( "Hi {event.customer_name},\n\nYou have fantastic taste! We noticed you left some amazing items in your shopping bag:\n\n📦 Your Selected Items:\n{event.product_names}\n(Total Cart Value: {event.cart_total} {event.currency})\n\nWe know how hard it can be to decide when you love multiple products, or perhaps the full basket total was a little higher than you planned for today.\n\nGood news: you don't have to buy everything! If you prefer to grab just ONE of your favorite products right now, you can check out directly in 1 click below:\n\n👇 Buy Any Single Item Directly:\n{event.single_item_links}\n\nOr if you decided you want the complete bundle after all:\n\n<div style=\"margin: 18px 0;\">\n  <a href=\"{event.recovery_url}\" style=\"display:inline-block;background-color:#0f172a;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;\">👉 Restore Entire Cart &amp; Complete Order &raquo;</a>\n</div>\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n⚡ Good to know:\n• Your items are temporarily reserved in your cart.\n• Popular products sell out quickly once reservations expire.\n• 100% Safe & Encrypted Checkout with Easy Returns.\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\nQuestions or need advice? Just hit reply to this email — we're always here for you!\n\nWarm regards,\n{workflow_name}", 'ai-marketing-expert' ),
						),
						'position_x'    => 0,
						'position_y'    => 0,
						'run_condition' => 'always',
					),
					array(
						'step_key'      => 'step_5',
						'parent_key'    => 'step_3',
						'branch'        => 'no',
						'action_type'   => 'send_email',
						'config'        => array(
							'to'      => '{event.email}',
							'subject' => __( 'Good news, {event.customer_name}: We saved your {event.product_names}! Complete your order in 1 click ⚡', 'ai-marketing-expert' ),
							'body'    => __( "Hi {event.customer_name},\n\nWe noticed you were checking out {event.product_names} (Total: {event.cart_total} {event.currency}), but didn't quite get across the finish line.\n\nLife gets busy and distractions happen — so we held your cart and reserved your item so nobody else snaps it up!\n\nYour item is packed and ready to ship as soon as you're ready. Click below to jump straight to checkout and complete your order in seconds:\n\n<div style=\"margin: 18px 0;\">\n  <a href=\"{event.recovery_url}\" style=\"display:inline-block;background-color:#2563eb;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;\">👉 Complete My Order in 1 Click &raquo;</a>\n</div>\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🌟 Shop With Confidence:\n✓ Safe, Encrypted & Seamless Checkout\n✓ Fast Dispatch Directly to Your Door\n✓ 100% Customer Satisfaction Guarantee\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\nHave a question about sizing, features, or shipping? Simply reply to this email and our team will be delighted to assist you!\n\nWarmest regards,\n{workflow_name}", 'ai-marketing-expert' ),
						),
						'position_x'    => 0,
						'position_y'    => 0,
						'run_condition' => 'always',
					),
				),
			);
		}

		// 2. Simple Smart Cart Recovery (Duplicate Qty)
		if ( ( false !== strpos( $lower, 'smart cart' ) || false !== strpos( $lower, 'duplicate_qty' ) || false !== strpos( $lower, 'duplicate' ) || false !== strpos( $lower, 'ডুপ্লিকেট' ) ) && ( false !== strpos( $lower, 'cart' ) || false !== strpos( $lower, 'কার্ট' ) ) ) {
			return array(
				'name'           => __( 'WooCommerce Smart Cart Recovery', 'ai-marketing-expert' ),
				'description'    => __( 'Smart abandoned cart recovery with automatic branching for duplicate quantities and single items.', 'ai-marketing-expert' ),
				'trigger_type'   => 'event',
				'trigger_event'  => 'woo_cart_abandoned',
				'trigger_config' => array(),
				'schedule_type'  => 'weekly',
				'schedule_time'  => '09:00',
				'schedule_days'  => '1',
				'topic'          => '',
				'brand_voice_id' => $brand_voice_id,
				'steps'          => array(
					array(
						'step_key'      => 'step_1',
						'parent_key'    => '',
						'branch'        => 'default',
						'action_type'   => 'condition',
						'config'        => array(
							'check' => 'event_field_equals',
							'field' => 'cart_type',
							'value' => 'duplicate_qty',
						),
						'position_x'    => 0,
						'position_y'    => 0,
						'run_condition' => 'always',
					),
					array(
						'step_key'      => 'step_2',
						'parent_key'    => 'step_1',
						'branch'        => 'yes',
						'action_type'   => 'send_email',
						'config'        => array(
							'to'      => '{event.email}',
							'subject' => __( '🛒 Quick question, {event.customer_name}: Did you mean to add {event.product_names} more than once?', 'ai-marketing-expert' ),
							'body'    => __( "Hi {event.customer_name},\n\nWe noticed you were checking out {event.product_names}, but left before completing your purchase.\n\nWhen reviewing your cart, we saw that multiple quantities were added (Cart Total: {event.cart_total} {event.currency}).\n\nIt happens all the time on mobile screens and fast clicks! If you only wanted to purchase a SINGLE item, you don't need to manually edit or adjust your cart — we've prepared an instant 1-click checkout for you:\n\n<div style=\"margin: 18px 0;\">\n  <a href=\"{event.single_qty_url}\" style=\"display:inline-block;background-color:#2563eb;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;\">👉 Buy Just 1 Item (1-Click Fast Checkout)</a>\n</div>\n\nPrefer to get all of them and keep the full order? No problem:\n\n<div style=\"margin: 14px 0;\">\n  <a href=\"{event.recovery_url}\" style=\"display:inline-block;background-color:#0f172a;color:#ffffff;padding:10px 22px;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;text-align:center;\">👉 Restore Entire Cart &amp; Complete Order</a>\n</div>\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🌟 Shop With Confidence:\n✓ 100% Secure & Encrypted Payment\n✓ Fast Dispatch & Reliable Tracking\n✓ Easy Returns & Dedicated Support\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\nIf you had any questions or need a hand completing your order, simply reply to this email and our team will gladly assist you!\n\nWarm regards,\n{workflow_name}", 'ai-marketing-expert' ),
						),
						'position_x'    => 0,
						'position_y'    => 0,
						'run_condition' => 'always',
					),
					array(
						'step_key'      => 'step_3',
						'parent_key'    => 'step_1',
						'branch'        => 'no',
						'action_type'   => 'send_email',
						'config'        => array(
							'to'      => '{event.email}',
							'subject' => __( 'Can\'t decide or want just 1 item, {event.customer_name}? We\'ve made it easy! 🛍️✨', 'ai-marketing-expert' ),
							'body'    => __( "Hi {event.customer_name},\n\nYou have fantastic taste! We noticed you left {event.product_names} waiting in your cart (Total: {event.cart_total} {event.currency}).\n\nDon't worry, your shopping bag is safely saved!\n\nIf you prefer to grab just ONE specific item today without committing to the full basket, click below to purchase that item directly in 1 click:\n\n{event.single_item_links}\n\nOr if you would like to take home your entire order:\n\n<div style=\"margin: 18px 0;\">\n  <a href=\"{event.recovery_url}\" style=\"display:inline-block;background-color:#0f172a;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;\">👉 Restore Entire Cart &amp; Complete Checkout &raquo;</a>\n</div>\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🌟 Why Shop With Us?\n✓ 100% Safe, Encrypted & Verified Checkout\n✓ Fast & Reliable Delivery Straight to Your Door\n✓ Friendly Customer Support Ready to Help\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\nIf you ran into any issues or have questions about your order, just reply directly to this email!\n\nWarm regards,\n{workflow_name}", 'ai-marketing-expert' ),
						),
						'position_x'    => 0,
						'position_y'    => 0,
						'run_condition' => 'always',
					),
				),
			);
		}

		// 3. Basic Abandoned Cart Recovery
		if ( false !== strpos( $lower, 'cart' ) || false !== strpos( $lower, 'কার্ট' ) ) {
			return array(
				'name'           => __( 'WooCommerce Cart Recovery', 'ai-marketing-expert' ),
				'description'    => __( 'Automated abandoned cart recovery email with 1-click restore link.', 'ai-marketing-expert' ),
				'trigger_type'   => 'event',
				'trigger_event'  => 'woo_cart_abandoned',
				'trigger_config' => array(),
				'schedule_type'  => 'weekly',
				'schedule_time'  => '09:00',
				'schedule_days'  => '1',
				'topic'          => '',
				'brand_voice_id' => $brand_voice_id,
				'steps'          => array(
					array(
						'step_key'      => 'step_1',
						'parent_key'    => '',
						'branch'        => 'default',
						'action_type'   => 'send_email',
						'config'        => array(
							'to'      => '{event.email}',
							'subject' => __( 'Still thinking it over, {event.customer_name}? Your cart is safely reserved! 🛒✨', 'ai-marketing-expert' ),
							'body'    => __( "Hi {event.customer_name},\n\nWe noticed you were checking out some fantastic items, but got interrupted before completing your order!\n\n🛒 Saved In Your Cart:\n{event.product_names}\nTotal: {event.cart_total} {event.currency}\n\nGood news — we have held your items in your reserved cart so you won't lose your selection. Life gets busy, so we made it effortless to pick up right where you left off:\n\n<div style=\"margin: 18px 0;\">\n  <a href=\"{event.recovery_url}\" style=\"display:inline-block;background-color:#2563eb;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;\">👉 Complete Your Order in 1-Click &raquo;</a>\n</div>\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🌟 Why Shop With Us?\n✓ 100% Safe, Encrypted & Verified Checkout\n✓ Fast & Reliable Delivery Straight to Your Door\n✓ Friendly Customer Support Ready to Help\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\nIf you ran into any checkout issues or have a quick question about your order, just reply directly to this email!\n\nWarmest regards,\n{workflow_name}", 'ai-marketing-expert' ),
						),
						'position_x'    => 0,
						'position_y'    => 0,
						'run_condition' => 'always',
					),
					array(
						'step_key'      => 'step_2',
						'parent_key'    => 'step_1',
						'branch'        => 'default',
						'action_type'   => 'send_notification',
						'config'        => array(
							'subject' => __( 'Abandoned Cart Alert: {event.customer_name}', 'ai-marketing-expert' ),
							'body'    => __( "Customer {event.customer_name} abandoned a cart with {event.product_names} ({event.cart_total} {event.currency}). Recovery email was dispatched.", 'ai-marketing-expert' ),
						),
						'position_x'    => 0,
						'position_y'    => 0,
						'run_condition' => 'always',
					),
				),
			);
		}

		return null;
	}
}