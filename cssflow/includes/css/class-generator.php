<?php
/**
 * Generates deterministic contextual CSS from active CSSFlow snippets.
 *
 * @package CSSFlow
 */

namespace CSSFlow\CSS;

use CSSFlow\Database\Snippet_Repository;
use CSSFlow\Targeting\Context;
use CSSFlow\Targeting\Scope_Registry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the CSS bundle applicable to one resolved frontend context.
 */
final class Generator {

	/**
	 * Scope compilation order frozen by the CSSFlow v1.0 specification.
	 *
	 * CSS variables are compiled before snippets in Phase 10. Phase 5 establishes
	 * the snippet ordering that follows them: global, post type, special,
	 * WooCommerce, then specific content. Within each group snippets are ordered
	 * by priority ASC and then ID ASC.
	 *
	 * @var array<string,int>
	 */
	private $scope_order = array(
		'global'           => 10,
		'post_type'        => 20,
		'special'          => 30,
		'woocommerce'      => 40,
		'specific_content' => 50,
	);

	/**
	 * Snippet repository.
	 *
	 * @var Snippet_Repository
	 */
	private $repository;

	/**
	 * Scope registry.
	 *
	 * @var Scope_Registry
	 */
	private $scope_registry;

	/**
	 * Responsive compiler.
	 *
	 * @var Compiler
	 */
	private $compiler;

	/**
	 * Global CSS Variables compiler.
	 *
	 * @var Variables
	 */
	private $variables;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Snippet_Repository $repository     Snippet repository.
	 * @param Scope_Registry     $scope_registry Scope registry.
	 * @param Compiler           $compiler       Responsive compiler.
	 * @param Variables          $variables      Global CSS Variables compiler.
	 */
	public function __construct( Snippet_Repository $repository, Scope_Registry $scope_registry, Compiler $compiler, Variables $variables ) {
		$this->repository     = $repository;
		$this->scope_registry = $scope_registry;
		$this->compiler       = $compiler;
		$this->variables      = $variables;
	}

	/**
	 * Generate the CSS applicable to the supplied frontend context.
	 *
	 * Public extension points:
	 *
	 * - cssflow_snippet_before_compile: filters raw snippet CSS before responsive
	 *   compilation. Receives CSS string, normalized snippet array, and Context.
	 * - cssflow_snippet_after_compile: filters one compiled snippet. Receives
	 *   compiled CSS string, normalized snippet array, and Context.
	 * - cssflow_compiled_css: filters the final contextual bundle. Receives final
	 *   CSS string, ordered applicable snippets, and Context.
	 *
	 * @since 1.0.0
	 *
	 * @param Context $context Resolved frontend context.
	 * @return string|WP_Error Compiled CSS or an error.
	 */
	public function generate( Context $context ) {
		$snippets = $this->get_applicable_snippets( $context );

		if ( is_wp_error( $snippets ) ) {
			return $snippets;
		}

		$compiled_parts = array();
		$variable_css   = trim( $this->variables->compile() );

		if ( '' !== $variable_css ) {
			$compiled_parts[] = $variable_css;
		}

		foreach ( $snippets as $snippet ) {
			$source = apply_filters(
				'cssflow_snippet_before_compile',
				$snippet['css_code'],
				$snippet,
				$context
			);

			if ( ! is_string( $source ) ) {
				return new WP_Error(
					'cssflow_invalid_pre_compile_filter',
					__( 'A CSSFlow pre-compile filter returned an invalid CSS value.', 'cssflow' )
				);
			}

			$compiled = $this->compiler->compile(
				$source,
				$snippet['responsive_type'],
				$snippet['breakpoint_key']
			);

			if ( is_wp_error( $compiled ) ) {
				return $compiled;
			}

			$compiled = apply_filters(
				'cssflow_snippet_after_compile',
				$compiled,
				$snippet,
				$context
			);

			if ( ! is_string( $compiled ) ) {
				return new WP_Error(
					'cssflow_invalid_post_compile_filter',
					__( 'A CSSFlow post-compile filter returned an invalid CSS value.', 'cssflow' )
				);
			}

			$compiled = trim( $compiled );

			if ( '' !== $compiled ) {
				$compiled_parts[] = $compiled;
			}
		}

		$css = implode( "\n\n", $compiled_parts );

		$css = apply_filters(
			'cssflow_compiled_css',
			$css,
			$snippets,
			$context
		);

		if ( ! is_string( $css ) ) {
			return new WP_Error(
				'cssflow_invalid_compiled_css_filter',
				__( 'The CSSFlow compiled CSS filter returned an invalid value.', 'cssflow' )
			);
		}

		return $css;
	}

	/**
	 * Get active snippets that match one frontend context in deterministic order.
	 *
	 * @since 1.0.0
	 *
	 * @param Context $context Resolved frontend context.
	 * @return array<int,array<string,mixed>>|WP_Error Applicable snippets or an error.
	 */
	public function get_applicable_snippets( Context $context ) {
		$snippets = $this->repository->get_active();

		if ( is_wp_error( $snippets ) ) {
			return $snippets;
		}

		$applicable = array();

		foreach ( $snippets as $snippet ) {
			if ( ! isset( $this->scope_order[ $snippet['scope_type'] ] ) ) {
				continue;
			}

			$matches = $this->scope_registry->matches(
				$snippet['scope_type'],
				$snippet['scope_data'],
				$context
			);

			if ( is_wp_error( $matches ) ) {
				return $matches;
			}

			if ( $matches ) {
				$applicable[] = $snippet;
			}
		}

		usort( $applicable, array( $this, 'compare_snippets' ) );

		return $applicable;
	}

	/**
	 * Compare two snippets using the frozen cascade order.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $left  Left snippet.
	 * @param array<string,mixed> $right Right snippet.
	 * @return int Comparison result.
	 */
	private function compare_snippets( array $left, array $right ) {
		$left_group  = $this->scope_order[ $left['scope_type'] ];
		$right_group = $this->scope_order[ $right['scope_type'] ];

		if ( $left_group !== $right_group ) {
			return $left_group < $right_group ? -1 : 1;
		}

		if ( $left['priority'] !== $right['priority'] ) {
			return $left['priority'] < $right['priority'] ? -1 : 1;
		}

		if ( $left['id'] === $right['id'] ) {
			return 0;
		}

		return $left['id'] < $right['id'] ? -1 : 1;
	}
}
