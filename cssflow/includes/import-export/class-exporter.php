<?php
/**
 * Builds CSSFlow portable JSON data and flat compiled CSS exports.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Import_Export;

use CSSFlow\CSS\Compiler;
use CSSFlow\CSS\Variables;
use CSSFlow\Database\Snippet_Repository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow export service.
 */
final class Exporter {

	/**
	 * Frozen scope order used by CSSFlow v1.0.
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
	 * Responsive compiler.
	 *
	 * @var Compiler
	 */
	private $compiler;

	/**
	 * CSS Variables service.
	 *
	 * @var Variables
	 */
	private $variables;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Snippet_Repository $repository Snippet repository.
	 * @param Compiler           $compiler   Responsive compiler.
	 * @param Variables          $variables  CSS Variables service.
	 */
	public function __construct( Snippet_Repository $repository, Compiler $compiler, Variables $variables ) {
		$this->repository = $repository;
		$this->compiler   = $compiler;
		$this->variables  = $variables;
	}

	/**
	 * Build the complete portable CSSFlow JSON export document.
	 *
	 * Database IDs are included only as source metadata and are never intended
	 * to become authoritative IDs on an importing site. WordPress user IDs are
	 * deliberately excluded because they are site-specific identity data.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed>|WP_Error Export document or an error.
	 */
	public function build_json_document() {
		$snippets = $this->repository->get_all();

		if ( is_wp_error( $snippets ) ) {
			return $snippets;
		}

		$breakpoints = get_option( 'cssflow_breakpoints', array() );

		if ( ! is_array( $breakpoints ) ) {
			return new WP_Error(
				'cssflow_export_invalid_breakpoints',
				__( 'The CSSFlow breakpoint configuration is invalid and cannot be exported.', 'cssflow' )
			);
		}

		$breakpoints = $this->compiler->validate_breakpoints( $breakpoints );

		if ( is_wp_error( $breakpoints ) ) {
			return $breakpoints;
		}

		$variables = $this->variables->get_all();

		if ( is_wp_error( $variables ) ) {
			return $variables;
		}

		$portable_snippets = array();

		foreach ( $snippets as $snippet ) {
			$portable_snippets[] = $this->build_portable_snippet( $snippet );
		}

		return array(
			'generator'      => 'CSSFlow',
			'schema_version' => 1,
			'plugin_version' => CSSFLOW_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'snippets'       => $portable_snippets,
			'breakpoints'    => $breakpoints,
			'variables'      => $variables,
			'settings'       => $this->get_portable_settings(),
		);
	}

	/**
	 * Encode the complete portable CSSFlow export as JSON.
	 *
	 * @since 1.0.0
	 *
	 * @return string|WP_Error JSON string or an error.
	 */
	public function build_json() {
		$document = $this->build_json_document();

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$json = wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return new WP_Error(
				'cssflow_export_json_failed',
				__( 'CSSFlow could not encode the export data as JSON.', 'cssflow' )
			);
		}

		return $json . "\n";
	}

	/**
	 * Build a flat compiled CSS download from all active CSSFlow CSS.
	 *
	 * This output is for developer portability, not for restoring CSSFlow data.
	 * Targeting metadata cannot be represented by a plain CSS file, so all
	 * active snippets are compiled together in CSSFlow's deterministic cascade
	 * order. Responsive wrappers and CSS variables are preserved.
	 *
	 * @since 1.0.0
	 *
	 * @return string|WP_Error Compiled CSS or an error.
	 */
	public function build_flat_css() {
		$snippets = $this->repository->get_active();

		if ( is_wp_error( $snippets ) ) {
			return $snippets;
		}

		usort( $snippets, array( $this, 'compare_snippets' ) );

		$parts        = array();
		$variable_css = trim( $this->variables->compile() );

		if ( '' !== $variable_css ) {
			$parts[] = $variable_css;
		}

		foreach ( $snippets as $snippet ) {
			if ( ! isset( $this->scope_order[ $snippet['scope_type'] ] ) ) {
				continue;
			}

			$compiled = $this->compiler->compile(
				$snippet['css_code'],
				$snippet['responsive_type'],
				$snippet['breakpoint_key']
			);

			if ( is_wp_error( $compiled ) ) {
				return $compiled;
			}

			$compiled = trim( $compiled );

			if ( '' !== $compiled ) {
				$parts[] = $compiled;
			}
		}

		$css = implode( "\n\n", $parts );

		if ( '' !== $css ) {
			$css .= "\n";
		}

		return $css;
	}

	/**
	 * Build one portable snippet entry.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $snippet Normalized repository record.
	 * @return array<string,mixed> Portable snippet data.
	 */
	private function build_portable_snippet( array $snippet ) {
		return array(
			'source_id'       => (int) $snippet['id'],
			'name'            => (string) $snippet['name'],
			'description'     => (string) $snippet['description'],
			'css_code'        => (string) $snippet['css_code'],
			'scope_type'      => (string) $snippet['scope_type'],
			'scope_data'      => $snippet['scope_data'],
			'responsive_type' => (string) $snippet['responsive_type'],
			'breakpoint_key'  => $snippet['breakpoint_key'],
			'priority'        => (int) $snippet['priority'],
			'status'          => (string) $snippet['status'],
			'created_at'      => (string) $snippet['created_at'],
			'updated_at'      => (string) $snippet['updated_at'],
		);
	}

	/**
	 * Return only portable v1.0 settings.
	 *
	 * Runtime metadata, filesystem paths, URLs, nonces, and other environment-
	 * specific values are intentionally excluded from the export document.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed> Portable settings.
	 */
	private function get_portable_settings() {
		$stored = get_option( 'cssflow_settings', array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$output_method = isset( $stored['output_method'] ) && 'inline' === $stored['output_method']
			? 'inline'
			: 'file';

		$enable_syntax_highlighting = ! array_key_exists( 'enable_syntax_highlighting', $stored )
			|| ! empty( $stored['enable_syntax_highlighting'] );

		return array(
			'output_method'              => $output_method,
			'delete_on_uninstall'        => ! empty( $stored['delete_on_uninstall'] ),
			'enable_syntax_highlighting' => $enable_syntax_highlighting,
		);
	}

	/**
	 * Compare snippets using CSSFlow's frozen scope/priority/ID cascade order.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $left  Left snippet.
	 * @param array<string,mixed> $right Right snippet.
	 * @return int Sort comparison result.
	 */
	private function compare_snippets( array $left, array $right ) {
		$left_scope  = isset( $this->scope_order[ $left['scope_type'] ] ) ? $this->scope_order[ $left['scope_type'] ] : PHP_INT_MAX;
		$right_scope = isset( $this->scope_order[ $right['scope_type'] ] ) ? $this->scope_order[ $right['scope_type'] ] : PHP_INT_MAX;

		if ( $left_scope !== $right_scope ) {
			return $left_scope <=> $right_scope;
		}

		if ( (int) $left['priority'] !== (int) $right['priority'] ) {
			return (int) $left['priority'] <=> (int) $right['priority'];
		}

		return (int) $left['id'] <=> (int) $right['id'];
	}
}
