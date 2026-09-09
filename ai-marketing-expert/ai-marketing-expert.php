<?php
/**
 * Plugin Name:       AI Marketing Expert
 * Plugin URI:        https://wpthemespace.com/ai-marketing-expert
 * Description:       All-in-one AI marketing: AI email campaigns, AI content generation,  AI SEO analyzer, social media scheduling, AI chatbot, and AI workflow automation.
 * Version:           1.2.4
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Noor Alam
 * Author URI:        https://wpthemespace.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-marketing-expert
 * Domain Path:       /languages
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 */
define( 'AIME_VERSION', '1.2.4' );
define( 'AIME_PLUGIN_FILE', __FILE__ );
define( 'AIME_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIME_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIME_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Schema versions. Bump only when database tables or migrations change.
// Per-module versions live here (single source of truth) — modules must not
// declare their own DB_VERSION constants.
define( 'AIME_DB_VERSION', '1.2.4' );
define( 'AIME_EMAIL_DB_VERSION', '1.2.4' );
define( 'AIME_CONTENT_DB_VERSION', '1.7.0' );
define( 'AIME_SEO_DB_VERSION', '1.1.0' );
define( 'AIME_SOCIAL_DB_VERSION', '1.0.0' );
define( 'AIME_CHATBOT_DB_VERSION', '1.2.0' );
define( 'AIME_WORKFLOW_DB_VERSION', '2.0.0' );

define( 'AIME_MIN_PHP', '8.0' );
define( 'AIME_MIN_WP', '6.2' );

/**
 * PHP version check.
 */
if ( version_compare( PHP_VERSION, AIME_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', function () {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			sprintf(
				/* translators: 1: Required PHP version, 2: Current PHP version */
				esc_html__( 'AI Marketing Expert requires PHP %1$s or higher. You are running PHP %2$s.', 'ai-marketing-expert' ),
				esc_html( AIME_MIN_PHP ),
				esc_html( PHP_VERSION )
			)
		);
	} );
	return;
}

/**
 * Autoloader.
 */
require_once AIME_PLUGIN_DIR . 'includes/autoload.php';

/**
 * Helper functions (globally available).
 */
require_once AIME_PLUGIN_DIR . 'includes/helpers.php';

/**
 * Activation hook.
 */
/**
 * Free plan limits.
 *
 * AIME_FREE_SUBSCRIBER_LIMIT is the soft cap surfaced to the UI for the free
 * tier. The free tier is actually unlimited (PHP_INT_MAX) — Pro advertises
 * additional features, not a higher subscriber ceiling. Keep the constant
 * defined so any third-party code that reads it still gets a valid number.
 */
define( 'AIME_FREE_SUBSCRIBER_LIMIT', PHP_INT_MAX );

/**
 * Dev mode: set to true to unlock all Pro features during development.
 * Set to false before release.
 */
if ( ! defined( 'AIME_DEV_MODE' ) ) {
	define( 'AIME_DEV_MODE', false );
}

register_activation_hook( __FILE__, function () {
	WPSpace\AiMarketingExpert\Activator::activate();
} );

/**
 * Deactivation hook.
 */
register_deactivation_hook( __FILE__, function () {
	WPSpace\AiMarketingExpert\Deactivator::deactivate();
} );

/**
 * Initialize the plugin.
 *
 * @return WPSpace\AiMarketingExpert\Plugin
 */
function aime(): \WPSpace\AiMarketingExpert\Plugin {
	return \WPSpace\AiMarketingExpert\Plugin::instance();
}

// Boot the plugin.
aime();
