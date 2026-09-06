<?php
/**
 * Plugin Deactivator.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	/**
	 * Run deactivation tasks.
	 */
	public static function deactivate(): void {
		// Clear scheduled cron events.
		wp_clear_scheduled_hook( 'aime_minutely_tasks' );
		wp_clear_scheduled_hook( 'aime_process_email_queue' );
		wp_clear_scheduled_hook( 'aime_run_email_queue' );
		wp_clear_scheduled_hook( 'aime_process_automations' );
		wp_clear_scheduled_hook( 'aime_daily_cleanup' );
		wp_clear_scheduled_hook( 'aime_chatbot_daily_cleanup' );
		wp_clear_scheduled_hook( 'aime_chatbot_index_post' );
		wp_clear_scheduled_hook( 'aime_seo_rank_check' );
		wp_clear_scheduled_hook( 'aime_seo_automation_process' );
		wp_clear_scheduled_hook( 'aime_publish_scheduled_social_posts' );

		// Clear transients.
		delete_transient( 'aime_activation_redirect' );

		/*
		 * Data deletion intentionally does NOT happen here. The
		 * `delete_data_on_uninstall` option only applies on uninstall
		 * (see uninstall.php) — deactivation must never destroy data.
		 */

		// Flush rewrite rules.
		flush_rewrite_rules();
	}
}
