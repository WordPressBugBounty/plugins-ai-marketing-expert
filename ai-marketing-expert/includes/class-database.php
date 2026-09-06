<?php
/**
 * Database migration manager.
 *
 * @package WPSpace\AiMarketingExpert
 */

namespace WPSpace\AiMarketingExpert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database {

	/**
	 * Run all database migrations.
	 */
	public static function migrate(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Core tables.
		self::create_core_tables( $charset_collate );

		// Module tables - each module handles its own.
		do_action( 'aime_create_tables', $charset_collate );
	}

	/**
	 * Create core plugin tables.
	 *
	 * @param string $charset_collate Database charset.
	 */
	private static function create_core_tables( string $charset_collate ): void {
		global $wpdb;

		$tables = array();

		// Module registry.
		$tables[] = "CREATE TABLE {$wpdb->prefix}aime_modules (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			module_id varchar(50) NOT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			settings longtext,
			activated_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_module_id (module_id)
		) {$charset_collate};";

		// Activity log.
		$tables[] = "CREATE TABLE {$wpdb->prefix}aime_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			module_id varchar(50) DEFAULT 'core',
			level varchar(20) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			context longtext,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_module (module_id),
			KEY idx_level (level),
			KEY idx_created (created_at)
		) {$charset_collate};";

		// AI usage log (token accounting per generation call).
		$tables[] = "CREATE TABLE {$wpdb->prefix}aime_ai_usage (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id varchar(64) NOT NULL DEFAULT '',
			connection_name varchar(191) NOT NULL DEFAULT '',
			provider varchar(50) NOT NULL DEFAULT '',
			model varchar(191) NOT NULL DEFAULT '',
			task varchar(50) NOT NULL DEFAULT 'text',
			prompt_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			completion_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			success tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_created (created_at),
			KEY idx_connection (connection_id)
		) {$charset_collate};";

		// Background AI job queue.
		$tables[] = "CREATE TABLE {$wpdb->prefix}aime_ai_jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(50) NOT NULL,
			payload longtext,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			result longtext,
			error text,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_created (created_at)
		) {$charset_collate};";

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Drop all plugin tables (for uninstall).
	 *
	 * Discovers tables by prefix ("{$wpdb->prefix}aime_%") — the same logic
	 * uninstall.php uses — instead of a hardcoded list that fell out of date
	 * as modules were added (audit B-5). One source of truth for teardown.
	 */
	public static function drop_all_tables(): void {
		global $wpdb;

		$tables = $wpdb->get_col(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $wpdb->prefix . 'aime_' ) . '%'
			)
		);

		/**
		 * Filter the list of tables to drop on uninstall.
		 *
		 * @param array $tables Table names.
		 */
		$tables = apply_filters( 'aime_uninstall_tables', $tables );

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from SHOW TABLES with the plugin prefix.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}
}
