<?php
/**
 * Centralizes CSSFlow support and developer links.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow support/resource link provider.
 */
final class Links {

	/**
	 * Get CSSFlow support and developer links.
	 *
	 * Empty values are intentional for resources that are not live yet. The
	 * Support screen renders only links that have a configured value.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Support/resource links.
	 */
	public static function get_all() {
		$links = array(
			'cssflow'       => 'https://qasim-wordpress-developer.com/cssflow/',
			'documentation' => 'https://qasim-wordpress-developer.com/cssflow/documentation/',
			'support_forum' => '',
			'issue_tracker' => '',
			'website'       => 'https://qasim-wordpress-developer.com/',
			'linkedin'      => 'https://www.linkedin.com/in/muhammad-qasim-a15165aa/',
			'youtube'       => 'https://www.youtube.com/@MuhammadQasim44',
			'email'         => 'qasimwpsupport@gmail.com',
		);

		/**
		 * Filter CSSFlow support and developer links.
		 *
		 * This allows site owners or downstream builds to replace public resource
		 * URLs without editing the Support page template.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $links Support/resource links.
		 */
		$links = apply_filters( 'cssflow_support_links', $links );

		return is_array( $links ) ? $links : array();
	}

	/**
	 * Get one configured URL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Link key.
	 * @return string Sanitized URL or an empty string.
	 */
	public static function get_url( $key ) {
		$links = self::get_all();
		$value = isset( $links[ $key ] ) && is_string( $links[ $key ] ) ? $links[ $key ] : '';

		if ( '' === $value ) {
			return '';
		}

		return esc_url_raw( $value );
	}

	/**
	 * Get the configured support email address.
	 *
	 * @since 1.0.0
	 *
	 * @return string Sanitized email address or an empty string.
	 */
	public static function get_email() {
		$links = self::get_all();
		$email = isset( $links['email'] ) && is_string( $links['email'] ) ? $links['email'] : '';

		return sanitize_email( $email );
	}
}
