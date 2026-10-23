<?php
/**
 * Abstract Module base class.
 *
 * Every module must extend this class.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Module {

	/**
	 * Get unique module identifier.
	 *
	 * @return string e.g. 'email-marketing'
	 */
	abstract public function get_id(): string;

	/**
	 * Get human-readable module name.
	 *
	 * @return string e.g. 'Email Marketing'
	 */
	abstract public function get_name(): string;

	/**
	 * Get module description.
	 *
	 * @return string
	 */
	abstract public function get_description(): string;

	/**
	 * Get module icon (dashicon name without 'dashicons-' prefix).
	 *
	 * @return string e.g. 'email-alt'
	 */
	abstract public function get_icon(): string;

	/**
	 * Get module version.
	 *
	 * @return string
	 */
	abstract public function get_version(): string;

	/**
	 * Initialize the module (register hooks, etc.).
	 * Called only if the module is active.
	 */
	abstract public function init(): void;

	/**
	 * Create module database tables.
	 * Called during plugin activation and upgrades.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	abstract public function create_tables( string $charset_collate ): void;

	/**
	 * Register REST API routes for this module.
	 */
	abstract public function register_routes(): void;

	/**
	 * Check if this module has pro features available.
	 *
	 * @return bool
	 */
	public function has_pro(): bool {
		return aime_module_has_pro( $this->get_id() );
	}

	/**
	 * Get the list of pro features for this module.
	 *
	 * @return array [ 'feature_key' => 'Feature description', ... ]
	 */
	public function get_pro_features(): array {
		return array();
	}

	/**
	 * Get module settings.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get_setting( string $key, $default = null ) {
		$settings = get_option( "aime_{$this->get_id()}_settings", array() );
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Update module settings.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return bool
	 */
	public function update_setting( string $key, $value ): bool {
		$option_name       = "aime_{$this->get_id()}_settings";
		$settings         = get_option( $option_name, array() );
		$settings[ $key ] = $value;
		$updated          = update_option( $option_name, $settings, false );
		aime_clear_settings_cache( array( $option_name ) );
		return $updated;
	}

	/**
	 * Save all module settings at once.
	 *
	 * @param array $settings Settings array.
	 * @return bool
	 */
	public function save_settings( array $settings ): bool {
		$option_name = "aime_{$this->get_id()}_settings";
		$updated     = update_option( $option_name, $settings, false );
		aime_clear_settings_cache( array( $option_name ) );
		return $updated;
	}

	/**
	 * Get all module settings.
	 *
	 * @return array
	 */
	public function get_all_settings(): array {
		return get_option( "aime_{$this->get_id()}_settings", array() );
	}

	/**
	 * Get module configuration for the React dashboard.
	 *
	 * @return array
	 */
	public function get_dashboard_config(): array {
		return array(
			'id'           => $this->get_id(),
			'name'         => $this->get_name(),
			'description'  => $this->get_description(),
			'icon'         => $this->get_icon(),
			'version'      => $this->get_version(),
			'has_pro'      => $this->has_pro(),
			'pro_features' => $this->get_pro_features(),
		);
	}
}
