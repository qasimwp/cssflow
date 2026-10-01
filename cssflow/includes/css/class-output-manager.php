<?php
/**
 * Coordinates CSSFlow frontend output modes and generated-output health state.
 *
 * @package CSSFlow
 */

namespace CSSFlow\CSS;

use CSSFlow\Targeting\Context;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues contextual CSS as a generated file or WordPress-managed inline CSS.
 */
final class Output_Manager {

	/**
	 * Frontend stylesheet handle.
	 *
	 * @var string
	 */
	const STYLE_HANDLE = 'cssflow';

	/**
	 * Generator.
	 *
	 * @var Generator
	 */
	private $generator;

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
	 * @param Generator    $generator    Contextual CSS generator.
	 * @param File_Manager $file_manager Generated-file manager.
	 */
	public function __construct( Generator $generator, File_Manager $file_manager ) {
		$this->generator    = $generator;
		$this->file_manager = $file_manager;
	}

	/**
	 * Register Phase 5 admin-side health notice handling.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_admin_hooks() {
		add_action( 'admin_notices', array( $this, 'maybe_show_safe_mode_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_fallback_notice' ) );
	}

	/**
	 * Generate and enqueue CSS for one resolved frontend request.
	 *
	 * @since 1.0.0
	 *
	 * @param Context $context Resolved frontend context.
	 * @return string|WP_Error Output state or an error.
	 */
	public function enqueue( Context $context ) {
		if ( $this->is_safe_mode() ) {
			$this->record_health_state( 'safe_mode' );
			return 'safe_mode';
		}

		$css = $this->generator->generate( $context );

		if ( is_wp_error( $css ) ) {
			return $css;
		}

		if ( '' === trim( $css ) ) {
			$this->record_health_state( 'empty' );
			return 'empty';
		}

		$output_method = $this->get_output_method();

		if ( 'inline' === $output_method ) {
			$this->enqueue_inline( $css );
			$this->record_health_state( 'inline' );
			return 'inline';
		}

		$file = $this->file_manager->write_bundle( 'context', $css );

		if ( is_wp_error( $file ) ) {
			$this->enqueue_inline( $css );
			$this->record_health_state( 'fallback' );
			return 'inline_fallback';
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$file['url'],
			array(),
			$file['version']
		);

		$this->record_health_state( 'healthy' );

		return 'file';
	}

	/**
	 * Invalidate generated files so contextual output is rebuilt from source data.
	 *
	 * Phase 5 uses deterministic lazy regeneration: generated files are removed,
	 * then the next matching frontend request recreates the required contextual
	 * bundle directly from database/options source data.
	 *
	 * Request-level capability and nonce checks belong to the future admin action
	 * that invokes this internal service.
	 *
	 * @since 1.0.0
	 *
	 * @return true|WP_Error True on success or an error.
	 */
	public function regenerate() {
		$result = $this->file_manager->clear_generated_files();

		if ( is_wp_error( $result ) ) {
			$this->record_health_state( 'fallback' );
			return $result;
		}

		$this->record_health_state( 'stale' );

		return true;
	}

	/**
	 * Show the Safe Mode state on CSSFlow administration screens.
	 *
	 * Safe Mode is intentionally configuration-only. There is no admin toggle,
	 * query-string switch, or stored option that can enable or disable it.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_show_safe_mode_notice() {
		if ( ! $this->is_safe_mode() || ! $this->is_cssflow_admin_screen() ) {
			return;
		}

		$edit_capability = apply_filters( 'cssflow_edit_capability', 'cssflow_edit_css' );
		$can_edit        = is_string( $edit_capability ) && '' !== $edit_capability && current_user_can( $edit_capability );

		if ( ! $can_edit && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'CSSFlow Safe Mode is active.', 'cssflow' ); ?></strong>
				<?php esc_html_e( 'Frontend CSS output is disabled, but your CSSFlow snippets remain saved and unchanged. Set CSSFLOW_SAFE_MODE to false or remove the constant to restore frontend output.', 'cssflow' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Show a non-blocking notice after generated-file output has fallen back.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_show_fallback_notice() {
		if ( $this->is_safe_mode() ) {
			return;
		}

		$capability = apply_filters( 'cssflow_edit_capability', 'cssflow_edit_css' );

		if ( ! is_string( $capability ) || '' === $capability || ! current_user_can( $capability ) ) {
			return;
		}

		$health = get_option( 'cssflow_generated_version', array() );

		if ( ! is_array( $health ) || ! isset( $health['status'] ) || 'fallback' !== $health['status'] ) {
			return;
		}

		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php esc_html_e( 'CSSFlow could not write its generated CSS file. Frontend CSS is currently using the inline fallback.', 'cssflow' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Determine whether CSSFlow emergency safe mode is active.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether frontend user CSS must be disabled.
	 */
	public function is_safe_mode() {
		return defined( 'CSSFLOW_SAFE_MODE' ) && true === CSSFLOW_SAFE_MODE;
	}

	/**
	 * Determine whether the current admin request belongs to CSSFlow.
	 *
	 * The screen ID check covers normal WordPress admin rendering while the
	 * page-query fallback keeps the notice reliable during early screen setup.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether the current admin screen belongs to CSSFlow.
	 */
	private function is_cssflow_admin_screen() {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();

			if ( null !== $screen && isset( $screen->id ) && is_string( $screen->id ) ) {
				if ( 'toplevel_page_cssflow' === $screen->id || 0 === strpos( $screen->id, 'cssflow_page_cssflow-' ) ) {
					return true;
				}
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen detection; no state change occurs here.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'cssflow' === $page || 0 === strpos( $page, 'cssflow-' );
	}

	/**
	 * Resolve the normalized site-wide output method.
	 *
	 * Invalid/missing configuration safely falls back to the frozen default:
	 * generated-file mode.
	 *
	 * @since 1.0.0
	 *
	 * @return string file or inline.
	 */
	public function get_output_method() {
		$settings = get_option( 'cssflow_settings', array() );

		if ( ! is_array( $settings ) || ! isset( $settings['output_method'] ) ) {
			return 'file';
		}

		if ( ! is_string( $settings['output_method'] ) ) {
			return 'file';
		}

		$method = sanitize_key( $settings['output_method'] );

		return in_array( $method, array( 'file', 'inline' ), true ) ? $method : 'file';
	}

	/**
	 * Get the last recorded generated-output health state.
	 *
	 * This returns diagnostic metadata only. It never returns raw CSS, generated
	 * filenames, URLs, or filesystem paths.
	 *
	 * @since 1.0.0
	 *
	 * @return array{status:string,updated_at:int} Normalized health metadata.
	 */
	public function get_health_state() {
		$health  = get_option( 'cssflow_generated_version', array() );
		$allowed = array( 'healthy', 'inline', 'fallback', 'safe_mode', 'stale', 'empty' );
		$status  = 'unknown';
		$updated = 0;

		if ( is_array( $health ) ) {
			if ( isset( $health['status'] ) && is_string( $health['status'] ) && in_array( $health['status'], $allowed, true ) ) {
				$status = $health['status'];
			}

			if ( isset( $health['updated_at'] ) ) {
				$updated = absint( $health['updated_at'] );
			}
		}

		return array(
			'status'     => $status,
			'updated_at' => $updated,
		);
	}

	/**
	 * Enqueue CSS through WordPress's managed inline-style API.
	 *
	 * @since 1.0.0
	 *
	 * @param string $css Compiled CSS.
	 * @return void
	 */
	private function enqueue_inline( $css ) {
		wp_register_style( self::STYLE_HANDLE, false, array(), CSSFLOW_VERSION );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, $css );
	}

	/**
	 * Record minimal generated-output health state without exposing paths or CSS.
	 *
	 * The option is diagnostic metadata only and never becomes a CSS source of
	 * truth. Repeated identical states do not write to the database again.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Normalized health status.
	 * @return void
	 */
	private function record_health_state( $status ) {
		$allowed = array( 'healthy', 'inline', 'fallback', 'safe_mode', 'stale', 'empty' );

		if ( ! in_array( $status, $allowed, true ) ) {
			return;
		}

		$current = get_option( 'cssflow_generated_version', array() );

		if ( is_array( $current ) && isset( $current['status'] ) && $status === $current['status'] ) {
			return;
		}

		update_option(
			'cssflow_generated_version',
			array(
				'status'     => $status,
				'updated_at' => time(),
			),
			false
		);
	}
}
