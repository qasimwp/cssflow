<?php
/**
 * Resolves responsive breakpoints and compiles CSS media-query wrappers.
 *
 * @package CSSFlow
 */

namespace CSSFlow\CSS;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles CSS for CSSFlow responsive targets using the current breakpoint option.
 */
final class Compiler {

	/**
	 * Default breakpoint keys that must always exist.
	 *
	 * @var string[]
	 */
	private $default_keys = array(
		'mobile',
		'tablet',
		'desktop',
	);

	/**
	 * Compile CSS for a responsive target.
	 *
	 * The all-devices target is returned without a media-query wrapper. Other
	 * targets resolve their current breakpoint values from cssflow_breakpoints.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $css_code        Raw CSS source.
	 * @param string      $responsive_type Responsive target.
	 * @param string|null $breakpoint_key  Custom breakpoint key when required.
	 * @return string|WP_Error Compiled CSS, or an error when the target/configuration is invalid.
	 */
	public function compile( $css_code, $responsive_type, $breakpoint_key = null ) {
		if ( ! is_string( $css_code ) ) {
			return new WP_Error(
				'cssflow_invalid_css_source',
				__( 'The CSS source must be a string.', 'cssflow' )
			);
		}

		$breakpoint = $this->resolve_breakpoint( $responsive_type, $breakpoint_key );

		if ( is_wp_error( $breakpoint ) ) {
			return $breakpoint;
		}

		if ( '' === $css_code ) {
			return '';
		}

		if ( null === $breakpoint ) {
			return $css_code;
		}

		$condition = $this->build_media_condition( $breakpoint );

		if ( is_wp_error( $condition ) ) {
			return $condition;
		}

		return '@media ' . $condition . " {\n" . $this->indent_css( $css_code ) . "\n}";
	}

	/**
	 * Resolve a responsive target to its current normalized breakpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $responsive_type Responsive target.
	 * @param string|null $breakpoint_key  Custom breakpoint key when required.
	 * @return array|null|WP_Error Normalized breakpoint, null for all devices, or an error.
	 */
	public function resolve_breakpoint( $responsive_type, $breakpoint_key = null ) {
		$allowed_types = array( 'all', 'desktop', 'tablet', 'mobile', 'custom' );

		if ( ! is_string( $responsive_type ) || ! in_array( $responsive_type, $allowed_types, true ) ) {
			return new WP_Error(
				'cssflow_invalid_responsive_type',
				__( 'The CSS responsive target is invalid.', 'cssflow' )
			);
		}

		if ( 'all' === $responsive_type ) {
			return null;
		}

		$resolved_key = $responsive_type;

		if ( 'custom' === $responsive_type ) {
			if ( ! is_string( $breakpoint_key ) || '' === $breakpoint_key || sanitize_key( $breakpoint_key ) !== $breakpoint_key ) {
				return new WP_Error(
					'cssflow_invalid_breakpoint_key',
					__( 'The custom breakpoint key is invalid.', 'cssflow' )
				);
			}

			if ( in_array( $breakpoint_key, $this->default_keys, true ) ) {
				return new WP_Error(
					'cssflow_invalid_custom_breakpoint',
					__( 'A default breakpoint cannot be used as a custom breakpoint.', 'cssflow' )
				);
			}

			$resolved_key = $breakpoint_key;
		}

		$breakpoints = get_option( 'cssflow_breakpoints', array() );

		if ( ! is_array( $breakpoints ) ) {
			return new WP_Error(
				'cssflow_invalid_breakpoints',
				__( 'The CSSFlow breakpoint configuration is invalid.', 'cssflow' )
			);
		}

		$breakpoints = $this->validate_breakpoints( $breakpoints );

		if ( is_wp_error( $breakpoints ) ) {
			return $breakpoints;
		}

		if ( ! isset( $breakpoints[ $resolved_key ] ) ) {
			return new WP_Error(
				'cssflow_breakpoint_not_found',
				__( 'The requested CSSFlow breakpoint does not exist.', 'cssflow' )
			);
		}

		if ( 'custom' === $responsive_type && ! empty( $breakpoints[ $resolved_key ]['is_default'] ) ) {
			return new WP_Error(
				'cssflow_invalid_custom_breakpoint',
				__( 'The requested breakpoint is not a custom breakpoint.', 'cssflow' )
			);
		}

		return $breakpoints[ $resolved_key ];
	}

	/**
	 * Validate and normalize the complete breakpoint configuration.
	 *
	 * The three default keys are mandatory. Widths are integer pixels from
	 * 0 through 65535 or null, and each breakpoint must define at least one
	 * bound. Custom keys must already be sanitized stable keys.
	 *
	 * @since 1.0.0
	 *
	 * @param array $breakpoints Breakpoint configuration.
	 * @return array|WP_Error Normalized breakpoints or the first validation error.
	 */
	public function validate_breakpoints( array $breakpoints ) {
		foreach ( $this->default_keys as $default_key ) {
			if ( ! array_key_exists( $default_key, $breakpoints ) ) {
				return new WP_Error(
					'cssflow_missing_default_breakpoint',
					__( 'The CSSFlow breakpoint configuration is missing a required default breakpoint.', 'cssflow' )
				);
			}
		}

		$normalized = array();

		foreach ( $breakpoints as $key => $breakpoint ) {
			if ( ! is_string( $key ) || '' === $key || sanitize_key( $key ) !== $key ) {
				return new WP_Error(
					'cssflow_invalid_breakpoint_key',
					__( 'A CSSFlow breakpoint key is invalid.', 'cssflow' )
				);
			}

			if ( ! is_array( $breakpoint ) ) {
				return new WP_Error(
					'cssflow_invalid_breakpoint',
					__( 'A CSSFlow breakpoint definition is invalid.', 'cssflow' )
				);
			}

			$label = isset( $breakpoint['label'] ) && is_scalar( $breakpoint['label'] )
				? sanitize_text_field( (string) $breakpoint['label'] )
				: '';

			if ( '' === $label ) {
				return new WP_Error(
					'cssflow_invalid_breakpoint_label',
					__( 'Every CSSFlow breakpoint requires a label.', 'cssflow' )
				);
			}

			$min_width = array_key_exists( 'min_width', $breakpoint ) ? $breakpoint['min_width'] : null;
			$max_width = array_key_exists( 'max_width', $breakpoint ) ? $breakpoint['max_width'] : null;

			$min_width = $this->normalize_width( $min_width );

			if ( is_wp_error( $min_width ) ) {
				return $min_width;
			}

			$max_width = $this->normalize_width( $max_width );

			if ( is_wp_error( $max_width ) ) {
				return $max_width;
			}

			if ( null === $min_width && null === $max_width ) {
				return new WP_Error(
					'cssflow_breakpoint_without_range',
					__( 'A CSSFlow breakpoint must define a minimum width, a maximum width, or both.', 'cssflow' )
				);
			}

			if ( null !== $min_width && null !== $max_width && $min_width > $max_width ) {
				return new WP_Error(
					'cssflow_invalid_breakpoint_range',
					__( 'A CSSFlow breakpoint minimum width cannot be greater than its maximum width.', 'cssflow' )
				);
			}

			$normalized[ $key ] = array(
				'label'      => $label,
				'min_width'  => $min_width,
				'max_width'  => $max_width,
				'is_default' => in_array( $key, $this->default_keys, true ),
			);
		}

		return $normalized;
	}

	/**
	 * Normalize one breakpoint width.
	 *
	 * Numeric strings are accepted because future WordPress form fields submit
	 * scalar values as strings. Fractions, negative values, and values above the
	 * frozen v1.0 limit are rejected.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $width Raw width value.
	 * @return int|null|WP_Error Normalized width, null, or an error.
	 */
	private function normalize_width( $width ) {
		if ( null === $width ) {
			return null;
		}

		if ( is_int( $width ) ) {
			$normalized = $width;
		} elseif ( is_string( $width ) && preg_match( '/^\d+$/D', $width ) ) {
			$normalized = (int) $width;
		} else {
			return new WP_Error(
				'cssflow_invalid_breakpoint_width',
				__( 'CSSFlow breakpoint widths must be whole-number pixels between 0 and 65535, or empty.', 'cssflow' )
			);
		}

		if ( $normalized < 0 || $normalized > 65535 ) {
			return new WP_Error(
				'cssflow_invalid_breakpoint_width',
				__( 'CSSFlow breakpoint widths must be whole-number pixels between 0 and 65535, or empty.', 'cssflow' )
			);
		}

		return $normalized;
	}

	/**
	 * Build a media-query condition from a normalized breakpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param array $breakpoint Normalized breakpoint.
	 * @return string|WP_Error Media-query condition or an error.
	 */
	private function build_media_condition( array $breakpoint ) {
		$min_width = $breakpoint['min_width'];
		$max_width = $breakpoint['max_width'];

		if ( null === $min_width && null === $max_width ) {
			return new WP_Error(
				'cssflow_breakpoint_without_range',
				__( 'A CSSFlow breakpoint must define a minimum width, a maximum width, or both.', 'cssflow' )
			);
		}

		if ( null === $min_width ) {
			return '(max-width: ' . $max_width . 'px)';
		}

		if ( null === $max_width ) {
			return '(min-width: ' . $min_width . 'px)';
		}

		return '(min-width: ' . $min_width . 'px) and (max-width: ' . $max_width . 'px)';
	}

	/**
	 * Indent CSS consistently inside a generated media-query block.
	 *
	 * @since 1.0.0
	 *
	 * @param string $css_code Raw CSS source.
	 * @return string Indented CSS.
	 */
	private function indent_css( $css_code ) {
		$css_code = str_replace( array( "\r\n", "\r" ), "\n", $css_code );
		$css_code = rtrim( $css_code, "\n" );
		$lines    = explode( "\n", $css_code );

		foreach ( $lines as &$line ) {
			$line = "\t" . $line;
		}
		unset( $line );

		return implode( "\n", $lines );
	}
}
