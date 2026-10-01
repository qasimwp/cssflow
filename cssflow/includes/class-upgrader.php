<?php
/**
 * Installs and upgrades CSSFlow data in a version-aware manner.
 *
 * @package CSSFlow
 */

namespace CSSFlow;

use CSSFlow\Database\Schema;
use CSSFlow\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow installation and upgrade coordinator.
 */
final class Upgrader {

	/**
	 * Install or upgrade CSSFlow for the current site or every site during
	 * network activation.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 * @return void
	 */
	public static function install( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_current_site();
				restore_current_blog();
			}

			return;
		}

		self::install_current_site();
	}

	/**
	 * Upgrade the current site when its stored versions are behind CSSFlow.
	 *
	 * This also handles an already-active Phase 1 installation receiving the
	 * Phase 2 files without requiring a deactivate/reactivate cycle.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::is_upgrade_required() ) {
			self::install_current_site();
		}
	}

	/**
	 * Determine whether the current site requires installation or an upgrade.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether an install or upgrade is required.
	 */
	public static function is_upgrade_required() {
		$installed_version    = get_option( 'cssflow_version', '' );
		$installed_db_version = absint( get_option( 'cssflow_db_version', 0 ) );

		if ( '' === $installed_version || 0 === $installed_db_version ) {
			return true;
		}

		return version_compare( $installed_version, CSSFLOW_VERSION, '<' )
			|| $installed_db_version < CSSFLOW_DB_VERSION;
	}

	/**
	 * Install or upgrade the data owned by the current site.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function install_current_site() {
		Schema::install();
		self::seed_options();
		Capabilities::add_to_administrator();

		update_option( 'cssflow_version', CSSFLOW_VERSION, false );
		update_option( 'cssflow_db_version', CSSFLOW_DB_VERSION, false );
	}

	/**
	 * Seed CSSFlow configuration without overwriting existing site choices.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function seed_options() {
		add_option(
			'cssflow_settings',
			array(
				'output_method'              => 'file',
				'delete_on_uninstall'        => false,
				'enable_syntax_highlighting' => true,
			),
			'',
			'false'
		);

		add_option(
			'cssflow_breakpoints',
			array(
				'mobile'  => array(
					'label'      => 'Mobile',
					'min_width'  => null,
					'max_width'  => 767,
					'is_default' => true,
				),
				'tablet'  => array(
					'label'      => 'Tablet',
					'min_width'  => 768,
					'max_width'  => 1023,
					'is_default' => true,
				),
				'desktop' => array(
					'label'      => 'Desktop',
					'min_width'  => 1024,
					'max_width'  => null,
					'is_default' => true,
				),
			),
			'',
			'false'
		);

		add_option( 'cssflow_variables', array(), '', 'false' );
	}
}
