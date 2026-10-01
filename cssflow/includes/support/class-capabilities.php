<?php
/**
 * Defines CSSFlow capability names and role assignment behavior.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow capability service.
 */
final class Capabilities {

	/**
	 * CSSFlow's built-in CSS editing capability.
	 *
	 * @var string
	 */
	const EDIT_CSS = 'cssflow_edit_css';

	/**
	 * Get the capability required for CSS editing operations.
	 *
	 * The filter allows site owners to map CSS editing to another existing
	 * capability without changing settings-level manage_options boundaries.
	 *
	 * @since 1.0.0
	 *
	 * @return string Capability name.
	 */
	public static function get_edit_capability() {
		$capability = apply_filters( 'cssflow_edit_capability', self::EDIT_CSS );

		if ( ! is_string( $capability ) || '' === trim( $capability ) ) {
			return self::EDIT_CSS;
		}

		return sanitize_key( $capability );
	}

	/**
	 * Add CSSFlow's built-in CSS editing capability to the Administrator role.
	 *
	 * On Multisite this operates on the currently switched site, preserving
	 * per-site role configuration.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function add_to_administrator() {
		$administrator = get_role( 'administrator' );

		if ( null !== $administrator && ! $administrator->has_cap( self::EDIT_CSS ) ) {
			$administrator->add_cap( self::EDIT_CSS );
		}
	}
}
