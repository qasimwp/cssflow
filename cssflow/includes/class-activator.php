<?php
/**
 * Handles CSSFlow activation and environment validation.
 *
 * @package CSSFlow
 */

namespace CSSFlow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow activation handler.
 */
final class Activator {

	/**
	 * Validate requirements and install CSSFlow data for the activated site(s).
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		global $wp_version;

		if ( version_compare( PHP_VERSION, CSSFLOW_MIN_PHP_VERSION, '<' ) ) {
			self::fail_activation(
				sprintf(
					/* translators: 1: Required PHP version, 2: Current PHP version. */
					__( 'CSSFlow requires PHP %1$s or newer. This site is running PHP %2$s.', 'cssflow' ),
					CSSFLOW_MIN_PHP_VERSION,
					PHP_VERSION
				)
			);
		}

		if ( version_compare( $wp_version, CSSFLOW_MIN_WP_VERSION, '<' ) ) {
			self::fail_activation(
				sprintf(
					/* translators: 1: Required WordPress version, 2: Current WordPress version. */
					__( 'CSSFlow requires WordPress %1$s or newer. This site is running WordPress %2$s.', 'cssflow' ),
					CSSFLOW_MIN_WP_VERSION,
					$wp_version
				)
			);
		}

		Upgrader::install( (bool) $network_wide );
	}

	/**
	 * Stop activation with a safe, user-facing message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Activation failure message.
	 * @return void
	 */
	private static function fail_activation( $message ) {
		deactivate_plugins( plugin_basename( CSSFLOW_FILE ) );

		wp_die(
			esc_html( $message ),
			esc_html__( 'CSSFlow Activation Error', 'cssflow' ),
			array(
				'back_link' => true,
			)
		);
	}
}
