<?php
/**
 * Module Manager - Discovers, registers, and manages modules.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ModuleManager {
	private const CACHE_GROUP = 'aime_module_manager';
	private const CACHE_TTL   = 60;

	/**
	 * Registered modules.
	 *
	 * @var Module[]
	 */
	private array $modules = array();

	/**
	 * Active module IDs from database.
	 *
	 * @var array
	 */
	private array $active_modules = array();

	private static function get_cache_version(): string {
		$version = wp_cache_get( 'version', self::CACHE_GROUP );
		if ( false === $version ) {
			$version = '1';
			wp_cache_set( 'version', $version, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return (string) $version;
	}

	private static function bump_cache_version(): void {
		wp_cache_set( 'version', (string) microtime( true ), self::CACHE_GROUP, self::CACHE_TTL );
	}

	private static function build_cache_key( string $prefix, array $parts = array() ): string {
		return $prefix . ':' . md5( wp_json_encode( $parts ) . ':' . self::get_cache_version() );
	}

	/**
	 * Discover and register available modules.
	 */
	public function discover_modules(): void {
		$modules_dir = AIME_PLUGIN_DIR . 'modules/';

		if ( ! is_dir( $modules_dir ) ) {
			return;
		}

		// Scan modules directory.
		$dirs = glob( $modules_dir . '*', GLOB_ONLYDIR );

		foreach ( $dirs as $dir ) {
			$module_id   = basename( $dir );
			$module_file = $dir . '/class-' . $module_id . '-module.php';

			if ( file_exists( $module_file ) ) {
				require_once $module_file;

				/**
				 * Fires after a module file is loaded.
				 * The module should register itself via this hook.
				 *
				 * @param ModuleManager $manager Module manager instance.
				 */
				do_action( "aime_load_module_{$module_id}", $this );
			}
		}

		// Allow external modules to register.
		do_action( 'aime_register_modules', $this );

		// Load active states from DB.
		$this->load_active_states();
	}

	/**
	 * Register a module.
	 *
	 * @param Module $module Module instance.
	 */
	public function register( Module $module ): void {
		$this->modules[ $module->get_id() ] = $module;
	}

	/**
	 * Initialize all active modules.
	 */
	public function init_active_modules(): void {
		foreach ( $this->modules as $id => $module ) {
			if ( $this->is_active( $id ) ) {
				$module->init();

				// Register table creation hook.
				add_action( 'aime_create_tables', array( $module, 'create_tables' ) );
			}
		}
	}

	/**
	 * Load active states from database.
	 */
	private function load_active_states(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'aime_modules';
		$cache_key = self::build_cache_key( 'active_states', array( 'table' => $table ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			$this->active_modules = $cached;
			return;
		}

		// Check if the table exists.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			// Table doesn't exist yet, all modules default to active.
			$this->active_modules = array_keys( $this->modules );
			wp_cache_set( $cache_key, $this->active_modules, self::CACHE_GROUP, self::CACHE_TTL );
			return;
		}

		$results = $wpdb->get_results(
			$wpdb->prepare( 'SELECT module_id, is_active FROM %i', $table ),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			// No records yet, all modules are active by default.
			$this->active_modules = array_keys( $this->modules );

			// Insert default records.
			foreach ( $this->modules as $id => $module ) {
				$wpdb->insert(
					$table,
					array(
						'module_id'    => $id,
						'is_active'    => 1,
						'activated_at' => current_time( 'mysql', true ),
						'updated_at'   => current_time( 'mysql', true ),
					),
					array( '%s', '%d', '%s', '%s' )
				);
			}
		} else {
			$db_states = array();
			foreach ( $results as $row ) {
				$db_states[ $row['module_id'] ] = (bool) $row['is_active'];
			}

			// Merge: known modules get DB state, new modules default to active.
			foreach ( $this->modules as $id => $module ) {
				if ( isset( $db_states[ $id ] ) ) {
					if ( $db_states[ $id ] ) {
						$this->active_modules[] = $id;
					}
				} else {
					// New module - activate by default.
					$this->active_modules[] = $id;
					$wpdb->insert(
						$table,
						array(
							'module_id'    => $id,
							'is_active'    => 1,
							'activated_at' => current_time( 'mysql', true ),
							'updated_at'   => current_time( 'mysql', true ),
						),
						array( '%s', '%d', '%s', '%s' )
					);
				}
			}
		}

		wp_cache_set( $cache_key, $this->active_modules, self::CACHE_GROUP, self::CACHE_TTL );
	}

	/**
	 * Check if a module is active.
	 *
	 * @param string $module_id Module identifier.
	 * @return bool
	 */
	public function is_active( string $module_id ): bool {
		return in_array( $module_id, $this->active_modules, true );
	}

	/**
	 * Activate a module.
	 *
	 * @param string $module_id Module identifier.
	 * @return bool
	 */
	public function activate( string $module_id ): bool {
		global $wpdb;

		if ( ! isset( $this->modules[ $module_id ] ) ) {
			return false;
		}

		$table  = $wpdb->prefix . 'aime_modules';
		$result = $wpdb->update(
			$table,
			array(
				'is_active'    => 1,
				'activated_at' => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'module_id' => $module_id ),
			array( '%d', '%s', '%s' ),
			array( '%s' )
		);

		if ( 0 === $result ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT module_id FROM %i WHERE module_id = %s LIMIT 1', $table, $module_id ) );
			if ( ! $exists ) {
				$result = $wpdb->insert(
					$table,
					array(
						'module_id'    => $module_id,
						'is_active'    => 1,
						'activated_at' => current_time( 'mysql', true ),
						'updated_at'   => current_time( 'mysql', true ),
					),
					array( '%s', '%d', '%s', '%s' )
				);
			}
		}

		if ( false !== $result ) {
			$this->active_modules = array_values( array_unique( array_merge( $this->active_modules, array( $module_id ) ) ) );
			$this->modules[ $module_id ]->init();
			self::bump_cache_version();
			// Module activation can add front-end output (e.g. chatbot widget).
			aime_clear_settings_cache( array(), true );
			return true;
		}

		return false;
	}

	/**
	 * Deactivate a module.
	 *
	 * @param string $module_id Module identifier.
	 * @return bool
	 */
	public function deactivate( string $module_id ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'aime_modules';
		$result = $wpdb->update(
			$table,
			array(
				'is_active'  => 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'module_id' => $module_id ),
			array( '%d', '%s' ),
			array( '%s' )
		);

		if ( 0 === $result ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT module_id FROM %i WHERE module_id = %s LIMIT 1', $table, $module_id ) );
			if ( ! $exists ) {
				$result = $wpdb->insert(
					$table,
					array(
						'module_id'  => $module_id,
						'is_active'  => 0,
						'updated_at' => current_time( 'mysql', true ),
					),
					array( '%s', '%d', '%s' )
				);
			}
		}

		if ( false !== $result ) {
			$this->active_modules = array_values( array_diff( $this->active_modules, array( $module_id ) ) );
			self::bump_cache_version();
			// Module deactivation can remove front-end output (e.g. chatbot widget).
			aime_clear_settings_cache( array(), true );
			return true;
		}

		return false;
	}

	/**
	 * Get a module by ID.
	 *
	 * @param string $module_id Module identifier.
	 * @return Module|null
	 */
	public function get( string $module_id ): ?Module {
		return $this->modules[ $module_id ] ?? null;
	}

	/**
	 * Get all registered modules.
	 *
	 * @return Module[]
	 */
	public function get_all(): array {
		return $this->modules;
	}

	/**
	 * Get all active modules.
	 *
	 * @return Module[]
	 */
	public function get_active(): array {
		return array_filter(
			$this->modules,
			function ( $module ) {
				return $this->is_active( $module->get_id() );
			}
		);
	}

	/**
	 * Get dashboard config for all modules (for React).
	 *
	 * @return array
	 */
	public function get_dashboard_configs(): array {
		$this->active_modules = array();
		$this->load_active_states();

		$configs = array();
		foreach ( $this->modules as $module ) {
			$config              = $module->get_dashboard_config();
			$config['is_active'] = $this->is_active( $module->get_id() );
			$configs[]           = $config;
		}
		return $configs;
	}
}
