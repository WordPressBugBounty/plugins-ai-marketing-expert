<?php
/**
 * Built-in workflow templates — starter blueprints for the template picker.
 *
 * Registered on the `aime_workflow_templates` filter by the module bootstrap.
 * Step keys are placeholders; the REST apply handler re-keys them with UUIDs.
 *
 * The library showcases the current engine feature set: ancestor-aware AI
 * Brain chaining, brand voice, structured tokens ({run_seo_audit.score},
 * {generate_blog_post.edit_url}, {event.*}), numeric condition gates, and
 * the strategist's JSON output mode.
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Templates
 */

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BuiltinTemplates {

	public static function register( array $templates ): array {

		/* ── Free ─────────────────────────────────────────── */

		$templates['weekly_blog_engine'] = array(
			'name'             => __( 'Weekly Blog Engine', 'ai-marketing-expert' ),
			'description'      => __( 'AI Brain picks a fresh topic weekly, writes a blog draft, and runs an SEO audit you can act on.', 'ai-marketing-expert' ),
			'icon'             => 'edit',
			'is_pro'           => false,
			'requires_modules' => array( 'content-generator', 'seo' ),
			'workflow'         => array(
				'name'          => __( 'Weekly Blog Engine', 'ai-marketing-expert' ),
				'description'   => __( 'AI Brain topic selection + blog draft + SEO audit, once a week.', 'ai-marketing-expert' ),
				'trigger_type'  => 'schedule',
				'schedule_type' => 'weekly',
				'schedule_time' => '09:00',
				'schedule_days' => '1',
				'topic'         => '',
			),
			'steps'            => array(
			array( 'key' => 'brain', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'ai_brain', 'config' => array(
				'strategy_prompt' => __( 'Pick a fresh, useful blog topic our audience has not seen recently. Prefer practical how-to guides and listicles relevant to our niche, with a clear angle.', 'ai-marketing-expert' ),
				'context_urls'    => '',
				'lookback_days'   => 60,
				'skill_ids'       => array( 'aime-seo-strategist', 'aime-readability', 'aime-image-director', 'aime-link-planner' ),
			) ),
				array( 'key' => 'blog', 'parent_key' => 'brain', 'branch' => 'default', 'action_type' => 'generate_blog_post', 'config' => array(
					'word_count'   => 1500,
					'word_count_max' => 2500,
					'post_status'  => 'draft',
					'auto_tags'    => true,
					'inline_images'=> 1,
				) ),
				array( 'key' => 'audit', 'parent_key' => 'blog', 'branch' => 'default', 'action_type' => 'run_seo_audit', 'config' => array(
					'wp_post_id'    => -1,
					'keyword_focus' => '',
				) ),
			),
		);

		$templates['welcome_new_subscriber'] = array(
			'name'             => __( 'Welcome New Subscriber', 'ai-marketing-expert' ),
			'description'      => __( 'When someone subscribes, enroll them into your welcome funnel and give your team a heads-up.', 'ai-marketing-expert' ),
			'icon'             => 'users',
			'is_pro'           => false,
			'requires_modules' => array( 'email-marketing' ),
			'workflow'         => array(
				'name'          => __( 'Welcome New Subscriber', 'ai-marketing-expert' ),
				'description'   => __( 'Enroll every new subscriber into a funnel and notify the team. After applying, open the Enroll step and pick your funnel.', 'ai-marketing-expert' ),
				'trigger_type'  => 'event',
				'trigger_event' => 'subscriber_created',
			),
			'steps'            => array(
				array( 'key' => 'enroll', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'enroll_in_funnel', 'config' => array(
										'subscriber_email' => '',
				) ),
				array( 'key' => 'notify', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'send_notification', 'config' => array(
					'subject' => __( 'New subscriber: {event.email}', 'ai-marketing-expert' ),
					'body'    => __( "A new subscriber just joined.\n\nEmail: {event.email}\nName: {event.name}\n\nThey were enrolled into the welcome funnel automatically.", 'ai-marketing-expert' ),
				) ),
			),
		);

		$cutoff_min = (int) ( ( get_option( 'aime_settings', array() )['woo_cart_cutoff_minutes'] ?? 0 ) ?: 30 );

		$templates['woo_cart_recovery'] = array(
			'name'             => __( 'WooCommerce Cart Recovery', 'ai-marketing-expert' ),
			'description'      => sprintf(
				/* translators: %d: minutes of inactivity */
				__( 'When a customer abandons their WooCommerce cart (after %d minutes of inactivity), sends a personalized recovery email with their 1-click restore link and notifies your team.', 'ai-marketing-expert' ),
				$cutoff_min
			),
			'icon'             => 'shopping-cart',
			'is_pro'           => false,
			'requires_plugin'  => 'woocommerce',
			'requires_modules' => array( 'email-marketing' ),
			'workflow'         => array(
				'name'          => __( 'WooCommerce Cart Recovery', 'ai-marketing-expert' ),
				'description'   => sprintf(
					/* translators: %d: minutes of inactivity */
					__( 'Automatic abandoned cart recovery (triggers after %d minutes of inactivity) with 1-click restore link and store alert.', 'ai-marketing-expert' ),
					$cutoff_min
				),
				'trigger_type'  => 'event',
				'trigger_event' => 'woo_cart_abandoned',
			),
			'steps'            => array(
				array(
					'key'         => 'recovery_email',
					'parent_key'  => '',
					'branch'      => 'default',
					'action_type' => 'send_email',
					'config'      => array(
						'to'      => '{event.email}',
						'subject' => __( 'Still thinking it over, {event.customer_name}? Your cart is safely reserved! 🛒✨', 'ai-marketing-expert' ),
						'body'    => __( "Hi {event.customer_name},\n\nWe noticed you were checking out some fantastic items, but got interrupted before completing your order!\n\n🛒 Saved In Your Cart:\n{event.product_names}\nTotal: {event.cart_total} {event.currency}\n\nGood news — we have held your items in your reserved cart so you won't lose your selection. Life gets busy, so we made it effortless to pick up right where you left off:\n\n<div style=\"margin: 18px 0;\">\n  <a href=\"{event.recovery_url}\" style=\"display:inline-block;background-color:#2563eb;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;\">👉 Complete Your Order in 1-Click &raquo;</a>\n</div>\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🌟 Why Shop With Us?\n✓ 100% Safe, Encrypted & Verified Checkout\n✓ Fast & Reliable Delivery Straight to Your Door\n✓ Friendly Customer Support Ready to Help\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\nIf you ran into any checkout issues or have a quick question about your order, just reply directly to this email!\n\nWarmest regards,\n{workflow_name}", 'ai-marketing-expert' ),
					),
				),
				array(
					'key'         => 'notify_admin',
					'parent_key'  => 'recovery_email',
					'branch'      => 'default',
					'action_type' => 'send_notification',
					'config'      => array(
						'subject' => __( 'Abandoned Cart Alert: {event.customer_name} ({event.cart_total} {event.currency})', 'ai-marketing-expert' ),
						'body'    => __( "A customer abandoned their cart.\n\nCustomer: {event.customer_name}\nEmail: {event.email}\nTotal: {event.cart_total} {event.currency}\nItems: {event.product_names}\n\nA personalized recovery email with their 1-click restore link has been sent automatically.", 'ai-marketing-expert' ),
					),
				),
			),
		);

		$templates['woo_smart_cart_recovery'] = array(
			'name'             => __( 'WooCommerce Smart Cart Recovery (Branching & Single-Item Checkout)', 'ai-marketing-expert' ),
			'description'      => sprintf(
				/* translators: %d: minutes of inactivity */
				__( 'Adaptive abandoned cart recovery (triggers after %d minutes of inactivity). Detects duplicate quantities or multiple products, offering direct 1-click single-item purchase links to eliminate checkout friction.', 'ai-marketing-expert' ),
				$cutoff_min
			),
			'icon'             => 'shopping-cart',
			'is_pro'           => true,
			'requires_plugin'  => 'woocommerce',
			'requires_modules' => array( 'email-marketing' ),
			'workflow'         => array(
				'name'          => __( 'WooCommerce Smart Cart Recovery', 'ai-marketing-expert' ),
				'description'   => sprintf(
					/* translators: %d: minutes of inactivity */
					__( 'Smart abandoned cart recovery (triggers after %d minutes of inactivity) with automatic branching for duplicate quantities and 1-click single-item checkout.', 'ai-marketing-expert' ),
					$cutoff_min
				),
				'trigger_type'  => 'event',
				'trigger_event' => 'woo_cart_abandoned',
			),
			'steps'            => array(
				array(
					'key'         => 'check_cart_type',
					'parent_key'  => '',
					'branch'      => 'default',
					'action_type' => 'condition',
					'config'      => array(
						'check' => 'event_field_equals',
						'field' => 'cart_type',
						'value' => 'duplicate_qty',
					),
				),
				array(
					'key'         => 'duplicate_qty_recovery_email',
					'parent_key'  => 'check_cart_type',
					'branch'      => 'yes',
					'action_type' => 'send_email',
					'config'      => array(
						'to'      => '{event.email}',
						'subject' => __( '🛒 Quick question, {event.customer_name}: Did you mean to add {event.product_names} more than once?', 'ai-marketing-expert' ),
						'body'    => __( "Hi {event.customer_name},\n\nWe noticed you were checking out {event.product_names}, but left before completing your purchase.\n\nWhen reviewing your cart, we saw that multiple quantities were added (Cart Total: {event.cart_total} {event.currency}).\n\nIt happens all the time on mobile screens and fast clicks! If you only wanted to purchase a SINGLE item, you don't need to manually edit or adjust your cart — we've prepared an instant 1-click checkout for you:\n\n<div style=\"margin: 18px 0;\">\n  <a href=\"{event.single_qty_url}\" style=\"display:inline-block;background-color:#2563eb;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;\">👉 Buy Just 1 Item (1-Click Fast Checkout)</a>\n</div>\n\nPrefer to get all of them and keep the full order? No problem:\n\n<div style=\"margin: 14px 0;\">\n  <a href=\"{event.recovery_url}\" style=\"display:inline-block;background-color:#0f172a;color:#ffffff;padding:10px 22px;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;text-align:center;\">👉 Restore Entire Cart &amp; Complete Order</a>\n</div>\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🌟 Shop With Confidence:\n✓ 100% Secure & Encrypted Payment\n✓ Fast Dispatch & Reliable Tracking\n✓ Easy Returns & Dedicated Support\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\nIf you had any questions or need a hand completing your order, simply reply to this email and our team will gladly assist you!\n\nWarm regards,\n{workflow_name}", 'ai-marketing-expert' ),
					),
				),
				array(
					'key'         => 'multi_or_single_recovery_email',
					'parent_key'  => 'check_cart_type',
					'branch'      => 'no',
					'action_type' => 'send_email',
					'config'      => array(
						'to'      => '{event.email}',
						'subject' => __( 'Can\'t decide or want just 1 item, {event.customer_name}? We\'ve made it easy! 🛍️✨', 'ai-marketing-expert' ),
						'body'    => __( "Hi {event.customer_name},\n\nYou have fantastic taste! We noticed you left {event.product_names} waiting in your cart (Total: {event.cart_total} {event.currency}).\n\nDon't worry, your shopping bag is safely saved!\n\nIf you prefer to grab just ONE specific item today without committing to the full basket, click below to purchase that item directly in 1 click:\n\n{event.single_item_links}\n\nOr if you would like to take home your entire order:\n\n<div style=\"margin: 18px 0;\">\n  <a href=\"{event.recovery_url}\" style=\"display:inline-block;background-color:#0f172a;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;\">👉 Restore Entire Cart &amp; Complete Checkout &raquo;</a>\n</div>\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🌟 Why Shop With Us?\n✓ 100% Safe, Encrypted & Verified Checkout\n✓ Fast & Reliable Delivery Straight to Your Door\n✓ Friendly Customer Support Ready to Help\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\nIf you ran into any issues or have questions about your order, just reply directly to this email!\n\nWarm regards,\n{workflow_name}", 'ai-marketing-expert' ),
					),
				),
				array(
					'key'         => 'notify_admin_dup',
					'parent_key'  => 'duplicate_qty_recovery_email',
					'branch'      => 'default',
					'action_type' => 'send_notification',
					'config'      => array(
						'subject' => __( 'Abandoned Cart Alert (Duplicate Qty): {event.customer_name}', 'ai-marketing-expert' ),
						'body'    => __( "A customer abandoned their cart with duplicate quantities.\n\nCustomer: {event.customer_name}\nEmail: {event.email}\nTotal: {event.cart_total} {event.currency}\nItems: {event.product_names}\n\nA single-quantity 1-click recovery email was automatically sent.", 'ai-marketing-expert' ),
					),
				),
				array(
					'key'         => 'notify_admin_standard',
					'parent_key'  => 'multi_or_single_recovery_email',
					'branch'      => 'default',
					'action_type' => 'send_notification',
					'config'      => array(
						'subject' => __( 'Abandoned Cart Alert: {event.customer_name} ({event.cart_total} {event.currency})', 'ai-marketing-expert' ),
						'body'    => __( "A customer abandoned their cart.\n\nCustomer: {event.customer_name}\nEmail: {event.email}\nTotal: {event.cart_total} {event.currency}\nItems: {event.product_names}\n\nA smart recovery email with individual item restore links was automatically sent.", 'ai-marketing-expert' ),
					),
				),
			),
		);

		$templates['woo_advanced_smart_cart_recovery'] = array(
			'name'             => __( 'WooCommerce Advanced Smart Cart Recovery (3-Way)', 'ai-marketing-expert' ),
			'description'      => sprintf(
				/* translators: %d: minutes of inactivity */
				__( 'High-converting 3-way cart recovery (triggers after %d minutes of inactivity): branches on duplicate quantities (1-click single-qty checkout), multiple products (individual product buy links), and single items (direct cart restore).', 'ai-marketing-expert' ),
				$cutoff_min
			),
			'icon'             => 'shopping-cart',
			'is_pro'           => true,
			'requires_plugin'  => 'woocommerce',
			'requires_modules' => array( 'email-marketing' ),
			'workflow'         => array(
				'name'          => __( 'WooCommerce Advanced Smart Cart Recovery (3-Way)', 'ai-marketing-expert' ),
				'description'   => sprintf(
					/* translators: %d: minutes of inactivity */
					__( 'Advanced 3-way abandoned cart recovery (triggers after %d minutes of inactivity) branching on duplicate quantities, multiple products, and single items.', 'ai-marketing-expert' ),
					$cutoff_min
				),
				'trigger_type'  => 'event',
				'trigger_event' => 'woo_cart_abandoned',
			),
			'steps'            => array(
				array(
					'key'         => 'check_duplicate_qty',
					'parent_key'  => '',
					'branch'      => 'default',
					'action_type' => 'condition',
					'config'      => array(
						'check' => 'event_field_equals',
						'field' => 'cart_type',
						'value' => 'duplicate_qty',
					),
				),
				array(
					'key'         => 'duplicate_qty_recovery_email',
					'parent_key'  => 'check_duplicate_qty',
					'branch'      => 'yes',
					'action_type' => 'send_email',
					'config'      => array(
						'to'      => '{event.email}',
						'subject' => __( '🛒 Quick question, {event.customer_name}: Did you mean to add {event.product_names} more than once?', 'ai-marketing-expert' ),
						'body'    => __( "Hi {event.customer_name},\n\nWe noticed you were checking out {event.product_names}, but left before completing your purchase.\n\nWhen reviewing your cart, we saw that multiple quantities were added (Cart Total: {event.cart_total} {event.currency}).\n\nIt happens all the time on mobile screens and fast clicks! If you only wanted to purchase a SINGLE item, you don't need to manually edit or adjust your cart — we've prepared an instant 1-click checkout for you:\n\n<div style=\"margin: 18px 0;\">\n  <a href=\"{event.single_qty_url}\" style=\"display:inline-block;background-color:#2563eb;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;\">👉 Buy Just 1 Item (1-Click Fast Checkout)</a>\n</div>\n\nPrefer to get all of them and keep the full order? No problem:\n\n<div style=\"margin: 14px 0;\">\n  <a href=\"{event.recovery_url}\" style=\"display:inline-block;background-color:#0f172a;color:#ffffff;padding:10px 22px;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;text-align:center;\">👉 Restore Entire Cart &amp; Complete Order</a>\n</div>\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🌟 Shop With Confidence:\n✓ 100% Secure & Encrypted Payment\n✓ Fast Dispatch & Reliable Tracking\n✓ Easy Returns & Dedicated Support\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\nIf you had any questions or need a hand completing your order, simply reply to this email and our team will gladly assist you!\n\nWarm regards,\n{workflow_name}", 'ai-marketing-expert' ),
					),
				),
				array(
					'key'         => 'check_multiple_items',
					'parent_key'  => 'check_duplicate_qty',
					'branch'      => 'no',
					'action_type' => 'condition',
					'config'      => array(
						'check' => 'event_field_equals',
						'field' => 'cart_type',
						'value' => 'multiple_items',
					),
				),
				array(
					'key'         => 'multiple_items_recovery_email',
					'parent_key'  => 'check_multiple_items',
					'branch'      => 'yes',
					'action_type' => 'send_email',
					'config'      => array(
						'to'      => '{event.email}',
						'subject' => __( 'Can\'t decide on everything, {event.customer_name}? Buy just your favorite in 1-click! 🛍️✨', 'ai-marketing-expert' ),
						'body'    => __( "Hi {event.customer_name},\n\nYou have fantastic taste! We noticed you left some amazing items in your shopping bag:\n\n📦 Your Selected Items:\n{event.product_names}\n(Total Cart Value: {event.cart_total} {event.currency})\n\nWe know how hard it can be to decide when you love multiple products, or perhaps the full basket total was a little higher than you planned for today.\n\nGood news: you don't have to buy everything! If you prefer to grab just ONE of your favorite products right now, you can check out directly in 1 click below:\n\n👇 Buy Any Single Item Directly:\n{event.single_item_links}\n\nOr if you decided you want the complete bundle after all:\n\n<div style=\"margin: 18px 0;\">\n  <a href=\"{event.recovery_url}\" style=\"display:inline-block;background-color:#0f172a;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;\">👉 Restore Entire Cart &amp; Complete Order &raquo;</a>\n</div>\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n⚡ Good to know:\n• Your items are temporarily reserved in your cart.\n• Popular products sell out quickly once reservations expire.\n• 100% Safe & Encrypted Checkout with Easy Returns.\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\nQuestions or need advice? Just hit reply to this email — we're always here for you!\n\nWarm regards,\n{workflow_name}", 'ai-marketing-expert' ),
					),
				),
				array(
					'key'         => 'single_item_recovery_email',
					'parent_key'  => 'check_multiple_items',
					'branch'      => 'no',
					'action_type' => 'send_email',
					'config'      => array(
						'to'      => '{event.email}',
						'subject' => __( 'Good news, {event.customer_name}: We saved your {event.product_names}! Complete your order in 1 click ⚡', 'ai-marketing-expert' ),
						'body'    => __( "Hi {event.customer_name},\n\nWe noticed you were checking out {event.product_names} (Total: {event.cart_total} {event.currency}), but didn't quite get across the finish line.\n\nLife gets busy and distractions happen — so we held your cart and reserved your item so nobody else snaps it up!\n\nYour item is packed and ready to ship as soon as you're ready. Click below to jump straight to checkout and complete your order in seconds:\n\n<div style=\"margin: 18px 0;\">\n  <a href=\"{event.recovery_url}\" style=\"display:inline-block;background-color:#2563eb;color:#ffffff;padding:12px 26px;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;text-align:center;\">👉 Complete My Order in 1 Click &raquo;</a>\n</div>\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🌟 Shop With Confidence:\n✓ Safe, Encrypted & Seamless Checkout\n✓ Fast Dispatch Directly to Your Door\n✓ 100% Customer Satisfaction Guarantee\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\nHave a question about sizing, features, or shipping? Simply reply to this email and our team will be delighted to assist you!\n\nWarmest regards,\n{workflow_name}", 'ai-marketing-expert' ),
					),
				),
				array(
					'key'         => 'notify_admin_dup',
					'parent_key'  => 'duplicate_qty_recovery_email',
					'branch'      => 'default',
					'action_type' => 'send_notification',
					'config'      => array(
						'subject' => __( 'Abandoned Cart Alert (Duplicate Qty): {event.customer_name}', 'ai-marketing-expert' ),
						'body'    => __( "A customer abandoned their cart with duplicate quantities.\n\nCustomer: {event.customer_name}\nEmail: {event.email}\nTotal: {event.cart_total} {event.currency}\nItems: {event.product_names}\n\nA single-quantity 1-click recovery email was automatically sent.", 'ai-marketing-expert' ),
					),
				),
				array(
					'key'         => 'notify_admin_multi',
					'parent_key'  => 'multiple_items_recovery_email',
					'branch'      => 'default',
					'action_type' => 'send_notification',
					'config'      => array(
						'subject' => __( 'Abandoned Cart Alert (Multiple Items): {event.customer_name}', 'ai-marketing-expert' ),
						'body'    => __( "A customer abandoned their cart with multiple products.\n\nCustomer: {event.customer_name}\nEmail: {event.email}\nTotal: {event.cart_total} {event.currency}\nItems: {event.product_names}\n\nA recovery email with individual 1-click product links was automatically sent.", 'ai-marketing-expert' ),
					),
				),
				array(
					'key'         => 'notify_admin_single',
					'parent_key'  => 'single_item_recovery_email',
					'branch'      => 'default',
					'action_type' => 'send_notification',
					'config'      => array(
						'subject' => __( 'Abandoned Cart Alert (Single Item): {event.customer_name}', 'ai-marketing-expert' ),
						'body'    => __( "A customer abandoned their cart with a single item.\n\nCustomer: {event.customer_name}\nEmail: {event.email}\nTotal: {event.cart_total} {event.currency}\nItems: {event.product_names}\n\nA direct recovery email was automatically sent.", 'ai-marketing-expert' ),
					),
				),
			),
		);

		$templates['inbound_webhook_lead'] = array(
			'name'             => __( 'Inbound Webhook Lead Processor', 'ai-marketing-expert' ),
			'description'      => __( 'Receives leads from external forms or webhooks, enrolls them into an email funnel, and notifies your team.', 'ai-marketing-expert' ),
			'icon'             => 'zap',
			'is_pro'           => false,
			'requires_modules' => array( 'email-marketing' ),
			'workflow'         => array(
				'name'          => __( 'Inbound Webhook Lead Processor', 'ai-marketing-expert' ),
				'description'   => __( 'Ingest webhook leads, enroll into email funnel, and notify team.', 'ai-marketing-expert' ),
				'trigger_type'  => 'event',
				'trigger_event' => 'inbound_webhook',
				'trigger_config' => array(
					'webhook_token' => 'lead-' . wp_generate_password( 8, false ),
				),
			),
			'steps'            => array(
				array(
					'key'         => 'enroll',
					'parent_key'  => '',
					'branch'      => 'default',
					'action_type' => 'enroll_in_funnel',
					'config'      => array(
						'subscriber_email'   => '{event.email}',
						'create_if_missing'  => true,
					),
				),
				array(
					'key'         => 'notify',
					'parent_key'  => 'enroll',
					'branch'      => 'default',
					'action_type' => 'send_notification',
					'config'      => array(
						'subject' => __( 'New Webhook Lead: {event.name} ({event.email})', 'ai-marketing-expert' ),
						'body'    => __( "A new lead was received via Inbound Webhook.\n\nName: {event.name}\nEmail: {event.email}\nWebhook Token: {event.token}\n\nThey were enrolled into the email funnel automatically.", 'ai-marketing-expert' ),
					),
				),
			),
		);

		$templates['cf7_lead_auto_responder'] = array(
			'name'             => __( 'Contact Form 7 Auto-Responder & Alert', 'ai-marketing-expert' ),
			'description'      => __( 'When someone submits a Contact Form 7 form, sends an instant greeting email to the submitter and alerts your team.', 'ai-marketing-expert' ),
			'icon'             => 'mail',
			'is_pro'           => false,
			'requires_plugin'  => 'contact-form-7',
			'workflow'         => array(
				'name'          => __( 'Contact Form 7 Lead Auto-Responder', 'ai-marketing-expert' ),
				'description'   => __( 'Instant confirmation email to form submitters and internal alert.', 'ai-marketing-expert' ),
				'trigger_type'  => 'event',
				'trigger_event' => 'cf7_submission',
				'trigger_config' => array(
					'form_id' => 0,
				),
			),
			'steps'            => array(
				array(
					'key'         => 'greeting_email',
					'parent_key'  => '',
					'branch'      => 'default',
					'action_type' => 'send_email',
					'config'      => array(
						'to'      => '{event.email}',
						'subject' => __( 'Thank you for reaching out, {event.name}!', 'ai-marketing-expert' ),
						'body'    => __( "Hi {event.name},\n\nThank you for contacting us! We received your message regarding \"{event.subject}\":\n\n\"{event.message}\"\n\nOur team is reviewing your inquiry and will get back to you shortly.\n\nBest regards,\n{workflow_name}", 'ai-marketing-expert' ),
					),
				),
				array(
					'key'         => 'notify_team',
					'parent_key'  => 'greeting_email',
					'branch'      => 'default',
					'action_type' => 'send_notification',
					'config'      => array(
						'subject' => __( 'New Contact Form Lead: {event.name} ({event.email})', 'ai-marketing-expert' ),
						'body'    => __( "A new contact form was submitted.\n\nName: {event.name}\nEmail: {event.email}\nSubject: {event.subject}\nForm: {event.form_title}\n\nMessage:\n{event.message}\n\nA confirmation email has already been sent to the visitor.", 'ai-marketing-expert' ),
					),
				),
			),
		);

		$templates['daily_smart_content'] = array(
			'name'             => __( 'Daily Smart Content', 'ai-marketing-expert' ),
			'description'      => __( 'Lightweight daily pair: the Brain rotates a fresh topic, writes a short blog draft, and schedules a matching social post about it.', 'ai-marketing-expert' ),
			'icon'             => 'zap',
			'is_pro'           => true,
			'requires_modules' => array( 'content-generator', 'social-media' ),
			'workflow'         => array(
				'name'          => __( 'Daily Smart Content', 'ai-marketing-expert' ),
				'description'   => __( 'AI-powered daily blog + social post with smart topic rotation.', 'ai-marketing-expert' ),
				'trigger_type'  => 'schedule',
				'schedule_type' => 'daily',
				'schedule_time' => '08:00',
				'topic'         => '',
			),
			'steps'            => array(
			array( 'key' => 'brain', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'ai_brain', 'config' => array(
				'strategy_prompt' => __( 'Pick a short, punchy topic suited to a daily post and a matching social update. Rotate themes across the week: tips, behind-the-scenes, quick wins, customer stories.', 'ai-marketing-expert' ),
				'context_urls'    => '',
				'lookback_days'   => 30,
				'skill_ids'       => array( 'aime-seo-strategist', 'aime-image-director', 'aime-social-hook' ),
			) ),
				array( 'key' => 'blog', 'parent_key' => 'brain', 'branch' => 'default', 'action_type' => 'generate_blog_post', 'config' => array(
					'word_count'   => 1500,
					'word_count_max' => 2500,
					'post_status'   => 'draft',
					'auto_tags'     => true,
					'inline_images' => 1,
				) ),
				array( 'key' => 'social', 'parent_key' => 'brain', 'branch' => 'default', 'action_type' => 'publish_social_post', 'config' => array(
					'topic'    => '',
					'schedule' => true,
				) ),
			),
		);

		/* ── Pro ──────────────────────────────────────────── */

		$templates['seo_gatekeeper'] = array(
			'name'             => __( 'SEO Quality Gate', 'ai-marketing-expert' ),
			'description'      => __( 'Brain picks the topic and writes a draft. Audit scores it: 70+ schedules promotion, below 70 you get a fix-it email with the score.', 'ai-marketing-expert' ),
			'icon'             => 'shield',
			'is_pro'           => true,
			'requires_modules' => array( 'content-generator', 'seo', 'social-media' ),
			'workflow'         => array(
				'name'          => __( 'SEO Quality Gate', 'ai-marketing-expert' ),
				'description'   => __( 'Blog draft -> audit -> score gate: promote or get alerted.', 'ai-marketing-expert' ),
				'trigger_type'  => 'schedule',
				'schedule_type' => 'weekly',
				'schedule_time' => '08:00',
				'schedule_days' => '2',
				'topic'         => '',
			),
			'steps'            => array(
				array( 'key' => 'brain', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'ai_brain', 'config' => array(
					'strategy_prompt' => __( 'Pick a topic for a cornerstone article our niche is actively searching for. Aim for depth over breadth; the draft will be audited against a quality bar before promotion.', 'ai-marketing-expert' ),
					'context_urls'    => '',
					'lookback_days'   => 60,
					'skill_ids'       => array( 'aime-seo-strategist', 'aime-readability', 'aime-image-director', 'aime-link-planner', 'aime-wordpress-expert' ),
				) ),
				array( 'key' => 'blog', 'parent_key' => 'brain', 'branch' => 'default', 'action_type' => 'generate_blog_post', 'config' => array(
					'word_count'   => 1500,
					'word_count_max' => 2500,
					'post_status'    => 'draft',
					'auto_tags'      => true,
					'featured_image' => 'stock',
				) ),
				array( 'key' => 'audit', 'parent_key' => 'blog', 'branch' => 'default', 'action_type' => 'run_seo_audit', 'config' => array(
					'wp_post_id'    => -1,
					'keyword_focus' => '',
				) ),
				array( 'key' => 'gate', 'parent_key' => 'audit', 'branch' => 'default', 'action_type' => 'condition', 'config' => array(
					'check'     => 'reference_compare',
					'ref_field' => 'score',
					'compare'   => '>=',
					'value'     => 70,
				) ),
				array( 'key' => 'promote', 'parent_key' => 'gate', 'branch' => 'yes', 'action_type' => 'publish_social_post', 'config' => array(
					'schedule' => true,
				) ),
				array( 'key' => 'alert', 'parent_key' => 'gate', 'branch' => 'no', 'action_type' => 'send_notification', 'config' => array(
					'subject' => __( 'Draft below quality bar ({run_seo_audit.score}/100): {workflow_name}', 'ai-marketing-expert' ),
					'body'    => __( "The weekly draft did not pass the SEO gate.\n\nScore: {run_seo_audit.score}/100\nKeyword: {run_seo_audit.keyword}\nEdit the draft: {generate_blog_post.edit_url}\n\nAudit summary:\n{run_seo_audit.preview}", 'ai-marketing-expert' ),
				) ),
			),
		);

		$templates['publish_promote'] = array(
			'name'             => __( 'Publish + Promote', 'ai-marketing-expert' ),
			'description'      => __( 'The moment a post goes live, AI drafts a platform social post and a promo email about it. No manual topic entry needed.', 'ai-marketing-expert' ),
			'icon'             => 'share',
			'is_pro'           => true,
			'requires_modules' => array( 'social-media', 'email-marketing' ),
			'workflow'         => array(
				'name'          => __( 'Publish + Promote', 'ai-marketing-expert' ),
				'description'   => __( 'Promote every published post on social + email.', 'ai-marketing-expert' ),
				'trigger_type'  => 'event',
				'trigger_event' => 'post_published',
			),
			'steps'            => array(
				array( 'key' => 'social', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'publish_social_post', 'config' => array(
					'topic'    => '',
					'schedule' => true,
				) ),
				array( 'key' => 'campaign', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'send_email_campaign', 'config' => array(
					'title' => __( 'Re: {event.post_title}', 'ai-marketing-expert' ),
				) ),
			),
		);

		$templates['full_marketing_autopilot'] = array(
			'name'             => __( 'Gated Autopilot', 'ai-marketing-expert' ),
			'description'      => __( 'Daily hands-off pipeline: write, publish, audit. Score 60+ auto-promotes on social and email; a weaker piece stops and emails you instead.', 'ai-marketing-expert' ),
			'icon'             => 'trending-up',
			'is_pro'           => true,
			'requires_modules' => array( 'content-generator', 'seo', 'social-media', 'email-marketing' ),
			'workflow'         => array(
				'name'          => __( 'Gated Autopilot', 'ai-marketing-expert' ),
				'description'   => __( 'AI Brain -> blog -> publish -> audit -> quality gate -> promote or alert, daily.', 'ai-marketing-expert' ),
				'trigger_type'  => 'schedule',
				'schedule_type' => 'daily',
				'schedule_time' => '06:00',
				'topic'         => '',
			),
			'steps'            => array(
				array( 'key' => 'brain', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'ai_brain', 'config' => array(
					'strategy_prompt' => __( 'Pick a daily topic our audience will click: news reactions, quick tips, or practical guides. Avoid repeating anything from the last month.', 'ai-marketing-expert' ),
					'context_urls'    => '',
					'lookback_days'   => 30,
					'skill_ids'       => array( 'aime-seo-strategist', 'aime-image-director', 'aime-social-hook' ),
				) ),
				array( 'key' => 'blog', 'parent_key' => 'brain', 'branch' => 'default', 'action_type' => 'generate_blog_post', 'config' => array(
					'word_count'   => 1500,
					'word_count_max' => 2500,
					'post_status'  => 'publish',
					'auto_tags'    => true,
					'inline_images'=> 1,
				) ),
				array( 'key' => 'audit', 'parent_key' => 'blog', 'branch' => 'default', 'action_type' => 'run_seo_audit', 'config' => array(
					'wp_post_id'    => -1,
					'keyword_focus' => '',
				) ),
				array( 'key' => 'gate', 'parent_key' => 'audit', 'branch' => 'default', 'action_type' => 'condition', 'config' => array(
					'check'     => 'reference_compare',
					'ref_field' => 'score',
					'compare'   => '>=',
					'value'     => 60,
				) ),
				array( 'key' => 'social', 'parent_key' => 'gate', 'branch' => 'yes', 'action_type' => 'publish_social_post', 'config' => array(
					'schedule' => true,
				) ),
				array( 'key' => 'campaign', 'parent_key' => 'gate', 'branch' => 'yes', 'action_type' => 'send_email_campaign', 'config' => array(
					'title' => __( '{workflow_name}: {topic}', 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'alert', 'parent_key' => 'gate', 'branch' => 'no', 'action_type' => 'send_notification', 'config' => array(
					'subject' => __( 'Autopilot published a weak post ({run_seo_audit.score}/100)', 'ai-marketing-expert' ),
					'body'    => __( "Today's automated post went live but scored below the promotion bar, so social/email promotion was skipped.\n\nScore: {run_seo_audit.score}/100\nKeyword: {run_seo_audit.keyword}\nReview it: {generate_blog_post.edit_url}", 'ai-marketing-expert' ),
				) ),
			),
		);

		$templates['monthly_content_batch'] = array(
			'name'             => __( 'Monthly Content Batch', 'ai-marketing-expert' ),
			'description'      => __( 'One theme, four assets: the Brain locks a monthly angle (JSON mode) and turns out blog, ad copy, email draft, and social post in a single run.', 'ai-marketing-expert' ),
			'icon'             => 'layers',
			'is_pro'           => true,
			'requires_modules' => array( 'content-generator', 'email-marketing', 'social-media' ),
			'workflow'         => array(
				'name'                  => __( 'Monthly Content Batch', 'ai-marketing-expert' ),
				'description'           => __( 'AI Brain + blog + ad copy + email + social, all coordinated monthly.', 'ai-marketing-expert' ),
				'trigger_type'          => 'schedule',
				'schedule_type'         => 'monthly',
				'schedule_time'         => '07:00',
				'schedule_day_of_month' => 1,
				'topic'                 => '',
			),
			'steps'            => array(
				array( 'key' => 'brain', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'ai_brain', 'config' => array(
					'strategy_prompt' => __( 'Pick one big theme for the month and build a coordinated batch around it: article angle, ad hook, email teaser and social take. Keep every piece aligned on that single theme.', 'ai-marketing-expert' ),
					'context_urls'    => '',
					'lookback_days'   => 90,
					'output_format'   => 'json',
					'skill_ids'       => array( 'aime-seo-strategist', 'aime-readability', 'aime-social-hook' ),
				) ),
				array( 'key' => 'blog', 'parent_key' => 'brain', 'branch' => 'default', 'action_type' => 'generate_blog_post', 'config' => array(
					'word_count'   => 1500,
					'word_count_max' => 2500,
					'post_status' => 'draft',
					'auto_tags'   => true,
					'inline_images' => 1,
				) ),
				array( 'key' => 'adcopy', 'parent_key' => 'brain', 'branch' => 'default', 'action_type' => 'generate_ad_copy', 'config' => array(
					'variations' => 3,
				) ),
				array( 'key' => 'campaign', 'parent_key' => 'brain', 'branch' => 'default', 'action_type' => 'send_email_campaign', 'config' => array(
					'title' => __( '{workflow_name}: {topic}', 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'social', 'parent_key' => 'brain', 'branch' => 'default', 'action_type' => 'publish_social_post', 'config' => array(
					'schedule' => false,
				) ),
			),
		);

		$templates['smart_product_showcase'] = array(
			'name'             => __( 'Smart Product Showcase', 'ai-marketing-expert' ),
			'description'      => __( 'Rotates through your WooCommerce products weekly: showcase article (with stock imagery), five ad variations priced-and-benefit aware, and a promo email draft.', 'ai-marketing-expert' ),
			'icon'             => 'shopping-cart',
			'is_pro'           => true,
			'requires_modules' => array( 'content-generator', 'email-marketing' ),
			'workflow'         => array(
				'name'          => __( 'Smart Product Showcase', 'ai-marketing-expert' ),
				'description'   => __( 'Rotating product spotlight with blog, ads, and email campaign.', 'ai-marketing-expert' ),
				'trigger_type'  => 'schedule',
				'schedule_type' => 'weekly',
				'schedule_time' => '10:00',
				'schedule_days' => '3',
				'topic'         => '',
			),
			'steps'            => array(
				array( 'key' => 'brain', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'ai_brain', 'config' => array(
					'strategy_prompt' => __( 'Spotlight one product per run: its best benefit, who it is for, and one objection it removes. The article, ads and email should all sell that same product.', 'ai-marketing-expert' ),
					'context_urls'    => '',
					'lookback_days'   => 45,
					'skill_ids'       => array( 'aime-seo-strategist', 'aime-image-director', 'aime-wordpress-expert' ),
				) ),
				array( 'key' => 'blog', 'parent_key' => 'brain', 'branch' => 'default', 'action_type' => 'generate_blog_post', 'config' => array(
					'word_count'   => 1500,
					'word_count_max' => 2500,
					'post_status'    => 'draft',
					'featured_image' => 'stock',
					'inline_images'  => 2,
				) ),
				array( 'key' => 'ads', 'parent_key' => 'brain', 'branch' => 'default', 'action_type' => 'generate_ad_copy', 'config' => array(
					'variations' => 5,
				) ),
				array( 'key' => 'email', 'parent_key' => 'brain', 'branch' => 'default', 'action_type' => 'send_email_campaign', 'config' => array(
					'title' => __( 'Product spotlight: {topic}', 'ai-marketing-expert' ),
				) ),
			),
		);

		$templates['chatbot_lead_nurture'] = array(
			'name'             => __( 'Chatbot Lead Nurture', 'ai-marketing-expert' ),
			'description'      => __( 'Every chatbot lead lands in your nurture funnel and your team gets an instant, token-filled summary of who they are and where they came from.', 'ai-marketing-expert' ),
			'icon'             => 'message-circle',
			'is_pro'           => true,
			'requires_modules' => array( 'chatbot', 'email-marketing' ),
			'workflow'         => array(
				'name'          => __( 'Chatbot Lead Nurture', 'ai-marketing-expert' ),
				'description'   => __( 'Funnel-enroll and announce every chatbot lead.', 'ai-marketing-expert' ),
				'trigger_type'  => 'event',
				'trigger_event' => 'chatbot_lead',
			),
			'steps'            => array(
				array( 'key' => 'enroll', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'enroll_in_funnel', 'config' => array(
										'subscriber_email' => '',
				) ),
				array( 'key' => 'notify', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'send_notification', 'config' => array(
					'subject' => __( 'New chatbot lead: {event.email}', 'ai-marketing-expert' ),
					'body'    => __( "The chatbot captured a new lead.\n\nEmail: {event.email}\nName: {event.first_name}\nSource: {event.source}\n\nThey were enrolled into the nurture funnel automatically.", 'ai-marketing-expert' ),
				) ),
			),
		);

		$templates['content_repurposing_engine'] = array(
			'name'             => __( 'Content Repurposing Engine', 'ai-marketing-expert' ),
			'description'      => __( 'Every published post becomes a multi-format content pack: Twitter thread, LinkedIn post, and email newsletter intro—all AI-generated from the original article.', 'ai-marketing-expert' ),
			'icon'             => 'repeat',
			'is_pro'           => true,
			'requires_modules' => array(),
			'workflow'         => array(
				'name'          => __( 'Content Repurposing Engine', 'ai-marketing-expert' ),
				'description'   => __( 'Repurpose every blog post into Twitter thread, LinkedIn post, and email intro.', 'ai-marketing-expert' ),
				'trigger_type'  => 'event',
				'trigger_event' => 'post_published',
			),
			'steps'            => array(
				array( 'key' => 'twitter', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'custom_prompt', 'config' => array(
					'prompt' => __( "Convert this blog post into an engaging Twitter thread (5-7 tweets).\n\nRules:\n- Tweet 1: Hook (curiosity or bold claim)\n- Tweets 2-6: Key points, one per tweet\n- Final tweet: CTA or takeaway\n- Keep each under 280 characters\n- Use line breaks for readability\n\nArticle: {event.post_title}\nURL: {event.post_url}\n\nFormat as:\nTweet 1/7: [text]\nTweet 2/7: [text]\n...", 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'linkedin', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'custom_prompt', 'config' => array(
					'prompt' => __( "Convert this blog post into a professional LinkedIn post (max 1300 characters).\n\nStructure:\n- Opening hook (personal or industry insight)\n- 3-5 key points from the article\n- Call-to-action\n- Use short paragraphs and line breaks\n- Professional but conversational tone\n\nArticle: {event.post_title}\nURL: {event.post_url}", 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'newsletter', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'custom_prompt', 'config' => array(
					'prompt' => __( "Write an email newsletter introduction for this blog post (2 short paragraphs).\n\nParagraph 1: Why this topic matters now (curiosity/urgency)\nParagraph 2: What they'll learn + soft CTA to read\n\nTone: Friendly, not salesy\nLength: 80-120 words total\n\nArticle: {event.post_title}\nURL: {event.post_url}", 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'notify', 'parent_key' => 'newsletter', 'branch' => 'default', 'action_type' => 'send_notification', 'config' => array(
					'subject' => __( 'Content pack ready: {event.post_title}', 'ai-marketing-expert' ),
					'body'    => __( "Your repurposed content is ready.\n\n📝 Original: {event.post_url}\n\n🐦 Twitter Thread:\n{twitter.content}\n\n💼 LinkedIn Post:\n{linkedin.content}\n\n📧 Newsletter Intro:\n{newsletter.content}", 'ai-marketing-expert' ),
				) ),
			),
		);

		$templates['smart_product_descriptions'] = array(
			'name'             => __( 'Smart Product Descriptions', 'ai-marketing-expert' ),
			'description'      => __( 'When you publish a product, AI generates three variations: short (for cards), medium (main description), and long (SEO-focused), plus 5 benefit bullets.', 'ai-marketing-expert' ),
			'icon'             => 'tag',
			'is_pro'           => false,
			'requires_modules' => array(),
			'workflow'         => array(
				'name'          => __( 'Smart Product Descriptions', 'ai-marketing-expert' ),
				'description'   => __( 'Auto-generate product description variations when you publish products.', 'ai-marketing-expert' ),
				'trigger_type'  => 'event',
				'trigger_event' => 'post_published',
				'trigger_config' => array(
					'post_type' => 'product',
				),
			),
			'steps'            => array(
				array( 'key' => 'short', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'custom_prompt', 'config' => array(
					'prompt' => __( "Write a SHORT product description (40-60 words) for: {event.post_title}\n\nFocus on:\n- Primary benefit (what problem it solves)\n- Who it's for\n- One standout feature\n\nTone: Clear, benefit-focused, scannable\nNo fluff or marketing hype.", 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'medium', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'custom_prompt', 'config' => array(
					'prompt' => __( "Write a MEDIUM product description (120-150 words) for: {event.post_title}\n\nInclude:\n- What it is and who needs it\n- 3-4 key benefits/features\n- Use case or problem it solves\n- Subtle credibility signal\n\nStructure: 2-3 short paragraphs\nTone: Professional but approachable", 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'long', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'custom_prompt', 'config' => array(
					'prompt' => __( "Write a LONG, SEO-optimized product description (250-300 words) for: {event.post_title}\n\nInclude:\n- Detailed benefits and features\n- Multiple use cases\n- Technical specs (if applicable)\n- Social proof angle\n- Why choose this over alternatives\n\nTone: Comprehensive, trustworthy, keyword-rich (but natural)\nStructure: 3-4 paragraphs with subheadings if needed", 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'bullets', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'custom_prompt', 'config' => array(
					'prompt' => __( "Generate 5 compelling benefit bullets for: {event.post_title}\n\nEach bullet:\n- Start with a benefit (not a feature)\n- 8-12 words max\n- Action-oriented language\n- Specific, not generic\n\nFormat:\n• [Benefit 1]\n• [Benefit 2]\n• [Benefit 3]\n• [Benefit 4]\n• [Benefit 5]", 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'notify', 'parent_key' => 'bullets', 'branch' => 'default', 'action_type' => 'send_notification', 'config' => array(
					'subject' => __( 'Product descriptions ready: {event.post_title}', 'ai-marketing-expert' ),
					'body'    => __( "AI-generated description pack:\n\n📦 Product: {event.post_title}\n🔗 Edit: {event.post_url}\n\n━━━ SHORT (40-60 words) ━━━\n{short.content}\n\n━━━ MEDIUM (120-150 words) ━━━\n{medium.content}\n\n━━━ LONG (250-300 words) ━━━\n{long.content}\n\n━━━ BENEFIT BULLETS ━━━\n{bullets.content}", 'ai-marketing-expert' ),
				) ),
			),
		);

		$templates['blog_to_email_sequence'] = array(
			'name'             => __( 'Blog to Email Mini-Course', 'ai-marketing-expert' ),
			'description'      => __( 'Turn any published blog post into a 3-email mini-course: introduction, deep-dive, and action steps—perfect for nurture sequences or lead magnets.', 'ai-marketing-expert' ),
			'icon'             => 'mail',
			'is_pro'           => true,
			'requires_modules' => array(),
			'workflow'         => array(
				'name'          => __( 'Blog to Email Mini-Course', 'ai-marketing-expert' ),
				'description'   => __( 'Transform published posts into structured 3-email mini-courses.', 'ai-marketing-expert' ),
				'trigger_type'  => 'event',
				'trigger_event' => 'post_published',
				'trigger_config' => array(
					'post_type' => 'post',
				),
			),
			'steps'            => array(
				array( 'key' => 'extract', 'parent_key' => '', 'branch' => 'default', 'action_type' => 'custom_prompt', 'config' => array(
					'prompt' => __( "Read this blog post and extract its core teaching into a structured outline:\n\nArticle: {event.post_title}\nURL: {event.post_url}\n\nCreate:\n1. Main topic/problem (1 sentence)\n2. Key concepts (3-5 points)\n3. Actionable steps (3-5 steps)\n4. Common mistakes to avoid (2-3 items)\n\nFormat as a clean outline, not prose.", 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'email1', 'parent_key' => 'extract', 'branch' => 'default', 'action_type' => 'custom_prompt', 'config' => array(
					'prompt' => __( "Using this outline, write EMAIL 1 of 3: The Introduction\n\nOutline:\n{extract.content}\n\nEmail structure:\n- Subject line (curiosity-driven, under 50 chars)\n- Why this topic matters (the problem)\n- What they'll learn in this 3-email series\n- Teaser for email 2\n- Warm sign-off\n\nLength: 150-200 words\nTone: Friendly mentor\n\nFormat:\nSUBJECT: [subject line]\nBODY:\n[email body]", 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'email2', 'parent_key' => 'extract', 'branch' => 'default', 'action_type' => 'custom_prompt', 'config' => array(
					'prompt' => __( "Using this outline, write EMAIL 2 of 3: The Deep-Dive\n\nOutline:\n{extract.content}\n\nEmail structure:\n- Subject line (value promise)\n- Quick callback to email 1\n- Teach the key concepts (3-5 points from outline)\n- Mini-example or analogy for clarity\n- Teaser for email 3 (action steps coming)\n\nLength: 250-300 words\nTone: Educational, clear\n\nFormat:\nSUBJECT: [subject line]\nBODY:\n[email body]", 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'email3', 'parent_key' => 'extract', 'branch' => 'default', 'action_type' => 'custom_prompt', 'config' => array(
					'prompt' => __( "Using this outline, write EMAIL 3 of 3: The Action Plan\n\nOutline:\n{extract.content}\n\nEmail structure:\n- Subject line (action-oriented)\n- Recap the key concepts from email 2 (1 sentence)\n- Step-by-step action plan (numbered steps from outline)\n- Common mistakes to avoid\n- Encouragement + CTA to original article for reference\n- Series wrap-up\n\nLength: 200-250 words\nTone: Motivating, practical\n\nFormat:\nSUBJECT: [subject line]\nBODY:\n[email body]", 'ai-marketing-expert' ),
				) ),
				array( 'key' => 'notify', 'parent_key' => 'email3', 'branch' => 'default', 'action_type' => 'send_notification', 'config' => array(
					'subject' => __( '3-email mini-course ready: {event.post_title}', 'ai-marketing-expert' ),
					'body'    => __( "Your blog post has been transformed into a 3-email mini-course.\n\n📝 Source: {event.post_title}\n🔗 {event.post_url}\n\n━━━ EMAIL 1: Introduction ━━━\n{email1.content}\n\n━━━ EMAIL 2: Deep-Dive ━━━\n{email2.content}\n\n━━━ EMAIL 3: Action Plan ━━━\n{email3.content}\n\n💡 Use these in your nurture sequence or as a lead magnet.", 'ai-marketing-expert' ),
				) ),
			),
		);

		$templates['woo_post_purchase_review'] = array(
			'name'             => __( 'Post-Purchase Review Request', 'ai-marketing-expert' ),
			'description'      => __( 'When a WooCommerce order is completed, automatically wait 1 hour and send a personalized thank you email requesting a product review.', 'ai-marketing-expert' ),
			'icon'             => 'star',
			'is_pro'           => true,
			'requires_plugin'  => 'woocommerce',
			'requires_modules' => array( 'email-marketing' ),
			'workflow'         => array(
				'name'          => __( 'Post-Purchase Review Request', 'ai-marketing-expert' ),
				'description'   => __( 'Follow up on completed WooCommerce orders to gather product reviews.', 'ai-marketing-expert' ),
				'trigger_type'  => 'event',
				'trigger_event' => 'woo_order_completed',
			),
			'steps'            => array(
				array(
					'key'         => 'wait_delay',
					'parent_key'  => '',
					'branch'      => 'default',
					'action_type' => 'delay',
					'config'      => array(
						'delay_value' => 1,
						'delay_unit'  => 'hours',
					),
				),
				array(
					'key'         => 'review_email',
					'parent_key'  => 'wait_delay',
					'branch'      => 'default',
					'action_type' => 'send_email',
					'config'      => array(
						'to'      => '{event.email}',
						'subject' => __( 'How was your recent order #{event.order_id}, {event.name}?', 'ai-marketing-expert' ),
						'body'    => __( "Hi {event.name},\n\nThank you for shopping with us! Your order #{event.order_id} ({event.product_names}) has been completed.\n\nWe hope you love your purchase. Could you take 30 seconds to share your thoughts and review your products?\n\nYour feedback helps other shoppers and allows us to keep improving our service.\n\nThank you for being our valued customer!\n\nWarm regards,\n{workflow_name}", 'ai-marketing-expert' ),
					),
				),
			),
		);

		return $templates;
	}
}
