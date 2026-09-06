<?php
/**
 * Plugin Activator.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	/**
	 * Run activation tasks.
	 */
	public static function activate(): void {
		// Check minimum WordPress version.
		if ( version_compare( get_bloginfo( 'version' ), AIME_MIN_WP, '<' ) ) {
			deactivate_plugins( AIME_PLUGIN_BASENAME );
			wp_die(
				sprintf(
					/* translators: %s: Minimum WordPress version */
					esc_html__( 'AI Marketing Expert requires WordPress %s or higher.', 'ai-marketing-expert' ),
					esc_html( AIME_MIN_WP )
				),
				esc_html__( 'Plugin Activation Error', 'ai-marketing-expert' ),
				array( 'back_link' => true )
			);
		}

		// Create database tables.
		Database::migrate();

		// Set default options.
		self::set_defaults();


		// Seed translated default email templates after init.
		set_transient( 'aime_seed_email_templates', true, 30 );

		// Schedule cron events.
		self::schedule_cron();

		// Set activation flag for redirect.
		set_transient( 'aime_activation_redirect', true, 30 );

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Set default plugin options.
	 */
	private static function set_defaults(): void {
		$defaults = array(
			'from_name'        => get_bloginfo( 'name' ),
			'from_email'       => get_option( 'admin_email' ),
			'unsubscribe_page' => 0,
			'double_optin'     => false,
			'track_opens'      => true,
			'track_clicks'     => true,
			'sending_method'   => 'wp_mail',     // wp_mail, smtp, api
			'batch_size'       => 50,
			'batch_interval'   => 60,             // seconds between batches
			'gdpr_enabled'     => true,
		);

		$existing = get_option( 'aime_settings', array() );
		$merged   = array_merge( $defaults, $existing );
		update_option( 'aime_settings', $merged );
	}

	/**
	 * Seed the per-field email settings once, so the General tab is not blank
	 * on a fresh install. Uses add_option() so an admin who deliberately clears
	 * a field never gets the default written back over their choice.
	 */
	public static function maybe_seed_email_defaults(): void {
		self::maybe_fix_double_optin_default();

		if ( get_option( 'aime_email_defaults_seeded' ) ) {
			return;
		}

		foreach ( self::get_email_field_defaults() as $option => $value ) {
			if ( '' === $value ) {
				continue;
			}
			add_option( $option, $value, '', false );
		}

		// Autoloaded on purpose: this guard is read on every request, and a
		// non-autoloaded option costs a separate query each time.
		update_option( 'aime_email_defaults_seeded', 1, true );
	}

	/**
	 * Double opt-in used to default to enabled. set_defaults() merges the stored
	 * settings over the defaults, so installs created before that change keep
	 * the old true forever. Flip it once — but only when the admin has never
	 * saved the field themselves, so a deliberate opt-in is never undone.
	 */
	private static function maybe_fix_double_optin_default(): void {
		if ( get_option( 'aime_double_optin_default_fixed' ) ) {
			return;
		}

		// Autoloaded: read on every request, see maybe_seed_email_defaults().
		update_option( 'aime_double_optin_default_fixed', 1, true );

		// save_settings() always writes this standalone option, so its presence
		// means the admin chose a value on the Email Settings screen.
		if ( false !== get_option( 'aime_double_optin', false ) ) {
			return;
		}

		$settings = get_option( 'aime_settings', array() );
		if ( is_array( $settings ) && ! empty( $settings['double_optin'] ) ) {
			$settings['double_optin'] = false;
			update_option( 'aime_settings', $settings );
		}

		if ( function_exists( 'aime_clear_settings_cache' ) ) {
			aime_clear_settings_cache( array( 'aime_settings', 'aime_double_optin' ) );
		}
	}

	/**
	 * Default values for the Email Settings → General fields.
	 *
	 * @return array<string,string>
	 */
	public static function get_email_field_defaults(): array {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$tagline   = wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES );
		$site_name = '' !== trim( $site_name ) ? $site_name : (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$footer = sprintf(
			/* translators: %s: site name. */
			__( 'You are receiving this email because you subscribed to %s.', 'ai-marketing-expert' ),
			$site_name
		);
		if ( '' !== trim( $tagline ) ) {
			$footer .= ' ' . $tagline;
		}

		return array(
			'aime_from_name'       => $site_name,
			'aime_from_email'      => (string) get_option( 'admin_email' ),
			'aime_reply_to'        => (string) get_option( 'admin_email' ),
			'aime_company_name'    => $site_name,
			'aime_company_address' => self::get_store_address(),
			'aime_email_footer'    => '<p>' . esc_html( $footer ) . '</p>',
			'aime_unsubscribe_text' => __( 'Unsubscribe', 'ai-marketing-expert' ),
		);
	}

	/**
	 * Best-effort postal address, taken from WooCommerce when it is installed.
	 */
	private static function get_store_address(): string {
		$street = trim( (string) get_option( 'woocommerce_store_address', '' ) );
		if ( '' === $street ) {
			return '';
		}

		$lines = array(
			$street,
			trim( (string) get_option( 'woocommerce_store_address_2', '' ) ),
			trim(
				trim( (string) get_option( 'woocommerce_store_city', '' ) ) . ' ' .
				trim( (string) get_option( 'woocommerce_store_postcode', '' ) )
			),
		);

		return implode( "\n", array_filter( $lines, 'strlen' ) );
	}

	/**
	 * Seed default email templates on activation.
	 */
	public static function seed_email_templates(): void {
		if ( class_exists( '\\WPSpace\\AiMarketingExpert\\Modules\\EmailMarketing\\Controllers\\TemplateController' ) ) {
			$controller = new \WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers\TemplateController();
			$controller->seed_defaults();
		}
	}

	/**
	 * Schedule cron events.
	 *
	 * A single minutely dispatcher (aime_minutely_tasks) fires the email-queue
	 * and automations actions under an overlap lock — see Plugin::run_minutely_tasks().
	 * Consolidated from two separate every-minute schedules (audit P-4).
	 */
	private static function schedule_cron(): void {
		// Clean up the pre-consolidation schedules if this is a re-activation.
		wp_clear_scheduled_hook( 'aime_process_email_queue' );
		wp_clear_scheduled_hook( 'aime_process_automations' );

		if ( ! wp_next_scheduled( 'aime_minutely_tasks' ) ) {
			wp_schedule_event( time(), 'every_minute', 'aime_minutely_tasks' );
		}

		if ( ! wp_next_scheduled( 'aime_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'aime_daily_cleanup' );
		}
	}
}
