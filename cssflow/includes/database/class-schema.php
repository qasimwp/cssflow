<?php
/**
 * Owns the CSSFlow custom database table schema.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the CSSFlow snippets table.
 */
final class Schema {

	/**
	 * Get the snippets table name for the current WordPress site.
	 *
	 * @since 1.0.0
	 *
	 * @return string Fully prefixed table name.
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'cssflow_snippets';
	}

	/**
	 * Create or upgrade the snippets table using WordPress dbDelta().
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			description text NULL,
			css_code longtext NOT NULL,
			scope_type varchar(32) NOT NULL,
			scope_data longtext NULL,
			responsive_type varchar(20) NOT NULL DEFAULT 'all',
			breakpoint_key varchar(100) NULL,
			priority smallint(5) unsigned NOT NULL DEFAULT 10,
			status varchar(10) NOT NULL DEFAULT 'active',
			created_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY scope_type (scope_type),
			KEY responsive_type (responsive_type),
			KEY priority (priority)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );
	}
}
