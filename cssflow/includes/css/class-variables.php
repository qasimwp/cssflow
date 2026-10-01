<?php
/**
 * Validates, normalizes, orders, and compiles CSSFlow global CSS variables.
 *
 * @package CSSFlow
 */

namespace CSSFlow\CSS;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global CSS Variables service.
 */
final class Variables {

	/**
	 * WordPress option that stores CSSFlow variables.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'cssflow_variables';

	/**
	 * Default maximum size of one raw variable value in bytes.
	 *
	 * This is intentionally generous for CSS values while still providing the
	 * size validation required by the frozen v1.0 specification.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_VALUE_SIZE = 65535;

	/**
	 * Get the stored variables after strict validation and normalization.
	 *
	 * The returned array preserves stored order. Sorting for output is handled
	 * separately so equal sort-order values remain deterministic.
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error Normalized variables or validation error.
	 */
	public function get_all() {
		$variables = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $variables ) ) {
			return new WP_Error(
				'cssflow_invalid_variables',
				__( 'The CSSFlow variables configuration is invalid.', 'cssflow' )
			);
		}

		return $this->validate_all( $variables );
	}

	/**
	 * Validate and normalize a complete variables configuration.
	 *
	 * Duplicate variable names are rejected. Names are case-sensitive because
	 * CSS custom-property names are case-sensitive.
	 *
	 * @since 1.0.0
	 *
	 * @param array $variables Candidate variables configuration.
	 * @return array|WP_Error Normalized variables or validation error.
	 */
	public function validate_all( array $variables ) {
		$normalized = array();
		$names      = array();

		foreach ( $variables as $variable ) {
			if ( ! is_array( $variable ) ) {
				return new WP_Error(
					'cssflow_invalid_variable',
					__( 'One or more CSS variables are invalid.', 'cssflow' )
				);
			}

			$item = $this->normalize_variable( $variable );

			if ( is_wp_error( $item ) ) {
				return $item;
			}

			if ( isset( $names[ $item['name'] ] ) ) {
				return new WP_Error(
					'cssflow_duplicate_variable_name',
					__( 'Each CSS variable must have a unique name.', 'cssflow' )
				);
			}

			$names[ $item['name'] ] = true;
			$normalized[]           = $item;
		}

		return $normalized;
	}

	/**
	 * Normalize one CSS variable.
	 *
	 * @since 1.0.0
	 *
	 * @param array $variable Candidate variable.
	 * @return array|WP_Error Normalized variable or validation error.
	 */
	public function normalize_variable( array $variable ) {
		$name = isset( $variable['name'] ) && is_scalar( $variable['name'] )
			? trim( (string) $variable['name'] )
			: '';

		if ( '' === $name || strlen( $name ) > 191 ) {
			return new WP_Error(
				'cssflow_invalid_variable_name',
				__( 'Enter a valid CSS variable name.', 'cssflow' )
			);
		}

		if ( 0 === strpos( $name, '--' ) ) {
			return new WP_Error(
				'cssflow_variable_name_has_prefix',
				__( 'Enter the variable name without the leading double hyphen.', 'cssflow' )
			);
		}

		if ( preg_match( '/[<>{};\'\"]/', $name ) ) {
			return new WP_Error(
				'cssflow_invalid_variable_name',
				__( 'The CSS variable name contains unsupported characters.', 'cssflow' )
			);
		}

		/*
		 * v1.0 intentionally uses a clear ASCII identifier subset. Prefixing this
		 * normalized value with "--" always produces a valid, predictable custom
		 * property name without needing CSS escape parsing in the admin workflow.
		 */
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $name ) ) {
			return new WP_Error(
				'cssflow_invalid_variable_name',
				__( 'Use letters, numbers, hyphens, and underscores, and begin the name with a letter or underscore.', 'cssflow' )
			);
		}

		$label = isset( $variable['label'] ) && is_scalar( $variable['label'] )
			? sanitize_text_field( (string) $variable['label'] )
			: '';

		if ( strlen( $label ) > 191 ) {
			return new WP_Error(
				'cssflow_invalid_variable_label',
				__( 'The CSS variable label is too long.', 'cssflow' )
			);
		}

		if ( ! isset( $variable['value'] ) || ! is_scalar( $variable['value'] ) ) {
			return new WP_Error(
				'cssflow_invalid_variable_value',
				__( 'Enter a value for this CSS variable.', 'cssflow' )
			);
		}

		$value    = trim( (string) $variable['value'] );
		$max_size = (int) apply_filters( 'cssflow_max_variable_value_size', self::DEFAULT_MAX_VALUE_SIZE );

		if ( $max_size < 1 ) {
			$max_size = self::DEFAULT_MAX_VALUE_SIZE;
		}

		if ( strlen( $value ) > $max_size ) {
			return new WP_Error(
				'cssflow_variable_value_too_large',
				__( 'The CSS variable value is too large.', 'cssflow' )
			);
		}

		/*
		 * Strip HTML markup without using wp_filter_nohtml_kses().
		 *
		 * wp_filter_nohtml_kses() adds slashes to quotes because it is designed
		 * for WordPress's pre-save filtering pipeline. CSS variable values may
		 * legitimately contain quoted strings such as font stacks, so adding
		 * slashes here would alter otherwise-valid CSS before validation.
		 */
		$value = wp_strip_all_tags( $value, false );
		$value = trim( $value );

		if ( '' === $value ) {
			return new WP_Error(
				'cssflow_invalid_variable_value',
				__( 'Enter a value for this CSS variable.', 'cssflow' )
			);
		}

		if ( ! $this->is_valid_css_value( $value ) ) {
			return new WP_Error(
				'cssflow_invalid_variable_value',
				__( 'This CSS variable value is not valid. Check quotes, brackets, or extra semicolons and try again.', 'cssflow' )
			);
		}

		$sort_order = isset( $variable['sort_order'] )
			? $this->normalize_sort_order( $variable['sort_order'] )
			: 10;

		if ( is_wp_error( $sort_order ) ) {
			return $sort_order;
		}

		return array(
			'name'       => $name,
			'label'      => $label,
			'value'      => $value,
			'sort_order' => $sort_order,
		);
	}

	/**
	 * Compile valid stored variables into one global :root block.
	 *
	 * Frontend generation is deliberately defensive: invalid option rows are
	 * ignored rather than preventing otherwise-valid CSS snippets from loading.
	 * Normal admin saves remain strict and never persist invalid rows.
	 *
	 * @since 1.0.0
	 *
	 * @return string Compiled :root CSS, or an empty string when none are valid.
	 */
	public function compile() {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return '';
		}

		$valid = array();
		$names = array();

		foreach ( array_values( $stored ) as $index => $variable ) {
			if ( ! is_array( $variable ) ) {
				continue;
			}

			$item = $this->normalize_variable( $variable );

			if ( is_wp_error( $item ) || isset( $names[ $item['name'] ] ) ) {
				continue;
			}

			$names[ $item['name'] ] = true;
			$item['_stored_order']  = $index;
			$valid[]                = $item;
		}

		if ( empty( $valid ) ) {
			return '';
		}

		usort(
			$valid,
			static function ( $left, $right ) {
				if ( $left['sort_order'] === $right['sort_order'] ) {
					return $left['_stored_order'] <=> $right['_stored_order'];
				}

				return $left['sort_order'] <=> $right['sort_order'];
			}
		);

		$lines = array( ':root {' );

		foreach ( $valid as $variable ) {
			$lines[] = sprintf(
				"\t--%s: %s;",
				$variable['name'],
				$variable['value']
			);
		}

		$lines[] = '}';

		return implode( "\n", $lines );
	}

	/**
	 * Normalize a variable sort order.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Candidate sort order.
	 * @return int|WP_Error Normalized value or validation error.
	 */
	private function normalize_sort_order( $value ) {
		if ( is_int( $value ) ) {
			$number = $value;
		} elseif ( is_string( $value ) && preg_match( '/^\d+$/', $value ) ) {
			$number = (int) $value;
		} else {
			return new WP_Error(
				'cssflow_invalid_variable_sort_order',
				__( 'Sort order must be a whole number from 0 to 65535.', 'cssflow' )
			);
		}

		if ( $number < 0 || $number > 65535 ) {
			return new WP_Error(
				'cssflow_invalid_variable_sort_order',
				__( 'Sort order must be a whole number from 0 to 65535.', 'cssflow' )
			);
		}

		return $number;
	}

	/**
	 * Perform conservative structure validation for one CSS value.
	 *
	 * CSSFlow is not a general CSS parser. This check only prevents a variable
	 * value from escaping its generated declaration while preserving common
	 * legitimate values such as quoted strings, gradients, calc(), var(), and
	 * data values containing semicolons inside functions or strings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Sanitized CSS value.
	 * @return bool Whether the value is structurally safe for one declaration.
	 */
	private function is_valid_css_value( $value ) {
		$length        = strlen( $value );
		$quote         = '';
		$escaped       = false;
		$comment       = false;
		$paren_depth   = 0;
		$bracket_depth = 0;

		for ( $index = 0; $index < $length; $index++ ) {
			$char = $value[ $index ];
			$next = $index + 1 < $length ? $value[ $index + 1 ] : '';

			if ( $comment ) {
				if ( '*' === $char && '/' === $next ) {
					$comment = false;
					++$index;
				}
				continue;
			}

			if ( '' !== $quote ) {
				if ( $escaped ) {
					$escaped = false;
					continue;
				}

				if ( '\\' === $char ) {
					$escaped = true;
					continue;
				}

				if ( $quote === $char ) {
					$quote = '';
				}
				continue;
			}

			if ( '/' === $char && '*' === $next ) {
				$comment = true;
				++$index;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}

			if ( '(' === $char ) {
				++$paren_depth;
				continue;
			}

			if ( ')' === $char ) {
				--$paren_depth;
				if ( $paren_depth < 0 ) {
					return false;
				}
				continue;
			}

			if ( '[' === $char ) {
				++$bracket_depth;
				continue;
			}

			if ( ']' === $char ) {
				--$bracket_depth;
				if ( $bracket_depth < 0 ) {
					return false;
				}
				continue;
			}

			if ( '{' === $char || '}' === $char ) {
				return false;
			}

			if ( ';' === $char && 0 === $paren_depth && 0 === $bracket_depth ) {
				return false;
			}
		}

		return '' === $quote
			&& ! $comment
			&& 0 === $paren_depth
			&& 0 === $bracket_depth;
	}
}
