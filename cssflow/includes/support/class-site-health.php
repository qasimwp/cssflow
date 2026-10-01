<?php
/**
 * Adds CSSFlow diagnostics to WordPress Site Health information.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Support;

use CSSFlow\CSS\File_Manager;
use CSSFlow\CSS\Output_Manager;
use CSSFlow\Database\Snippet_Repository;
use CSSFlow\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides privacy-safe CSSFlow diagnostic information to Site Health.
 */
final class Site_Health {

	/**
	 * Snippet repository.
	 *
	 * @var Snippet_Repository
	 */
	private $repository;

	/**
	 * Output manager.
	 *
	 * @var Output_Manager
	 */
	private $output_manager;

	/**
	 * Generated-file manager.
	 *
	 * @var File_Manager
	 */
	private $file_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Snippet_Repository $repository     Snippet repository.
	 * @param Output_Manager     $output_manager CSS output manager.
	 * @param File_Manager       $file_manager   Generated-file manager.
	 */
	public function __construct( Snippet_Repository $repository, Output_Manager $output_manager, File_Manager $file_manager ) {
		$this->repository     = $repository;
		$this->output_manager = $output_manager;
		$this->file_manager   = $file_manager;
	}

	/**
	 * Register Site Health integration.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'debug_information', array( $this, 'add_debug_information' ) );
	}

	/**
	 * Add the frozen CSSFlow diagnostic fields to Site Health -> Info.
	 *
	 * Values are deliberately limited to operational metadata. Raw CSS,
	 * nonces, personal information, URLs, and filesystem paths are excluded.
	 *
	 * @since 1.0.0
	 *
	 * @param array $info Existing WordPress Site Health information.
	 * @return array Filtered Site Health information.
	 */
	public function add_debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			$info = array();
		}

		$output_health = $this->output_manager->get_health_state();
		$file_health   = $this->file_manager->get_output_health();
		$breakpoints   = get_option( 'cssflow_breakpoints', array() );

		$info['cssflow'] = array(
			'label'       => __( 'CSSFlow', 'cssflow' ),
			'description' => __( 'Operational information for CSSFlow. This section does not include CSS code, personal information, nonces, or filesystem paths.', 'cssflow' ),
			'fields'      => array(
				'plugin_version'     => array(
					'label' => __( 'CSSFlow version', 'cssflow' ),
					'value' => defined( 'CSSFLOW_VERSION' ) ? CSSFLOW_VERSION : __( 'Unknown', 'cssflow' ),
				),
				'db_schema_version'  => array(
					'label' => __( 'Database schema version', 'cssflow' ),
					'value' => (string) absint( get_option( 'cssflow_db_version', 0 ) ),
				),
				'active_snippets'    => array(
					'label' => __( 'Active snippets', 'cssflow' ),
					'value' => (string) $this->repository->count_for_admin( array( 'status' => 'active' ) ),
				),
				'inactive_snippets'  => array(
					'label' => __( 'Inactive snippets', 'cssflow' ),
					'value' => (string) $this->repository->count_for_admin( array( 'status' => 'inactive' ) ),
				),
				'output_method'      => array(
					'label' => __( 'Output method', 'cssflow' ),
					'value' => $this->get_output_method_label(),
				),
				'generated_status'   => array(
					'label' => __( 'Generated-output status', 'cssflow' ),
					'value' => $this->get_output_status_label( $output_health['status'] ),
				),
				'output_writable'    => array(
					'label' => __( 'Output/uploads directory writable', 'cssflow' ),
					'value' => ! empty( $file_health['available'] ) && ! empty( $file_health['writable'] ) ? __( 'Yes', 'cssflow' ) : __( 'No', 'cssflow' ),
				),
				'safe_mode'          => array(
					'label' => __( 'Safe Mode status', 'cssflow' ),
					'value' => $this->output_manager->is_safe_mode() ? __( 'Active', 'cssflow' ) : __( 'Inactive', 'cssflow' ),
				),
				'woocommerce'        => array(
					'label' => __( 'WooCommerce integration status', 'cssflow' ),
					'value' => $this->is_woocommerce_integration_active() ? __( 'Active', 'cssflow' ) : __( 'Inactive', 'cssflow' ),
				),
				'custom_breakpoints' => array(
					'label' => __( 'Custom breakpoints', 'cssflow' ),
					'value' => (string) $this->count_custom_breakpoints( $breakpoints ),
				),
			),
		);

		return $info;
	}

	/**
	 * Get a readable output-method label.
	 *
	 * @since 1.0.0
	 *
	 * @return string Output-method label.
	 */
	private function get_output_method_label() {
		return 'inline' === $this->output_manager->get_output_method()
			? __( 'Inline CSS', 'cssflow' )
			: __( 'Generated CSS files', 'cssflow' );
	}

	/**
	 * Convert an internal generated-output state to a safe diagnostic label.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Internal output health state.
	 * @return string Diagnostic label.
	 */
	private function get_output_status_label( $status ) {
		$labels = array(
			'healthy'   => __( 'Healthy', 'cssflow' ),
			'inline'    => __( 'Inline CSS active', 'cssflow' ),
			'fallback'  => __( 'Inline fallback active', 'cssflow' ),
			'safe_mode' => __( 'Safe Mode active', 'cssflow' ),
			'stale'     => __( 'Rebuild pending', 'cssflow' ),
			'empty'     => __( 'No matching CSS on last request', 'cssflow' ),
			'unknown'   => __( 'Not checked yet', 'cssflow' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['unknown'];
	}

	/**
	 * Determine whether the optional WooCommerce integration is active.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether CSSFlow's WooCommerce integration is available.
	 */
	private function is_woocommerce_integration_active() {
		return class_exists( WooCommerce::class ) && WooCommerce::is_available();
	}

	/**
	 * Count configured non-default breakpoints.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $breakpoints Stored breakpoint option.
	 * @return int Number of valid custom breakpoint entries.
	 */
	private function count_custom_breakpoints( $breakpoints ) {
		if ( ! is_array( $breakpoints ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $breakpoints as $key => $breakpoint ) {
			if (
				is_string( $key )
				&& sanitize_key( $key ) === $key
				&& is_array( $breakpoint )
				&& empty( $breakpoint['is_default'] )
			) {
				++$count;
			}
		}

		return $count;
	}
}
