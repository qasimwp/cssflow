<?php
/**
 * Handles CSSFlow deactivation without deleting persistent data.
 *
 * @package CSSFlow
 */

namespace CSSFlow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow deactivation handler.
 */
final class Deactivator {

	/**
	 * Perform non-destructive deactivation.
	 *
	 * CSSFlow intentionally keeps snippets, settings, breakpoints, variables,
	 * capabilities, and version data when the plugin is deactivated.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		// No non-destructive runtime cleanup is currently required.
	}
}
