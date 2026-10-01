<?php
/**
 * Renders CSSFlow data portability tools and secure export/import preview actions.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Admin\Pages;

use CSSFlow\CSS\File_Manager;
use CSSFlow\CSS\Output_Manager;
use CSSFlow\Import_Export\Exporter;
use CSSFlow\Import_Export\Importer;
use CSSFlow\Support\Capabilities;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow Tools administration page controller.
 */
final class Tools_Page {

	/**
	 * JSON export service.
	 *
	 * @var Exporter
	 */
	private $exporter;

	/**
	 * Import validation and preview service.
	 *
	 * @var Importer
	 */
	private $importer;

	/**
	 * Generated-output coordinator.
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
	 * One-time Tools notice payload for the current request.
	 *
	 * @var array|false|null
	 */
	private $flash_notice = null;

	/**
	 * Constructor.
	 *
	 * @param Exporter       $exporter       Export service.
	 * @param Importer       $importer       Import validation/preview service.
	 * @param Output_Manager $output_manager Existing output coordinator.
	 * @param File_Manager   $file_manager   Existing generated-file manager.
	 */
	public function __construct( Exporter $exporter, Importer $importer, Output_Manager $output_manager, File_Manager $file_manager ) {
		$this->exporter       = $exporter;
		$this->importer       = $importer;
		$this->output_manager = $output_manager;
		$this->file_manager   = $file_manager;
	}

	/**
	 * Register the Tools screen and secure download actions.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_cssflow_export_json', array( $this, 'handle_json_export' ) );
		add_action( 'admin_post_cssflow_download_css', array( $this, 'handle_css_download' ) );
		add_action( 'admin_post_cssflow_commit_json_import', array( $this, 'handle_json_import_commit' ) );
		add_action( 'admin_post_cssflow_import_additional_css', array( $this, 'handle_additional_css_import' ) );
		add_action( 'admin_post_cssflow_regenerate_css', array( $this, 'handle_regenerate_css' ) );
	}

	/**
	 * Register the Tools submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'cssflow',
			__( 'Tools', 'cssflow' ),
			__( 'Tools', 'cssflow' ),
			Capabilities::get_edit_capability(),
			'cssflow-tools',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the Phase 11 Tools screen.
	 *
	 * @return void
	 */
	public function render() {
		$this->require_edit_capability();

		$preview                     = null;
		$preview_error               = null;
		$additional_css_preview      = $this->importer->preview_additional_css();
		$show_additional_css_preview = false;

		if ( isset( $_POST['cssflow_tools_action'] ) && 'preview_json_import' === sanitize_key( wp_unslash( $_POST['cssflow_tools_action'] ) ) ) {
			check_admin_referer( 'cssflow_preview_json_import', 'cssflow_import_nonce' );

			$file = isset( $_FILES['cssflow_import_file'] ) && is_array( $_FILES['cssflow_import_file'] )
				? $_FILES['cssflow_import_file'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File metadata is validated by Importer.
				: array();

			$preview = $this->importer->preview_uploaded_file( $file );

			if ( is_wp_error( $preview ) ) {
				$preview_error = $preview;
				$preview       = null;
			}
		}

		if ( isset( $_POST['cssflow_tools_action'] ) && 'preview_additional_css' === sanitize_key( wp_unslash( $_POST['cssflow_tools_action'] ) ) ) {
			check_admin_referer( 'cssflow_preview_additional_css', 'cssflow_additional_css_nonce' );
			$show_additional_css_preview = ! is_wp_error( $additional_css_preview ) && ! empty( $additional_css_preview['has_css'] );
		}
		?>
		<div class="wrap cssflow-tools-page">
			<hr class="wp-header-end" />
			<div class="cssflow-tools-header">
				<div>
					<h1><?php esc_html_e( 'Tools', 'cssflow' ); ?></h1>
					<p class="cssflow-tools-intro">
						<?php esc_html_e( 'Back up, preview an import, or download the CSS your site is currently using.', 'cssflow' ); ?>
					</p>
				</div>
			</div>

			<?php $this->render_regenerate_notice(); ?>
			<?php $this->render_import_result_notice(); ?>

			<?php if ( $preview_error ) : ?>
				<div class="notice notice-error cssflow-tools-notice" role="alert">
					<p><strong><?php esc_html_e( 'Import preview could not be created.', 'cssflow' ); ?></strong></p>
					<p><?php echo esc_html( $preview_error->get_error_message() ); ?></p>
				</div>
			<?php endif; ?>

			<div class="cssflow-tools-grid">
				<?php $this->render_output_health_card(); ?>
				<?php $this->render_backup_card(); ?>
				<?php $this->render_css_download_card(); ?>
				<?php $this->render_import_card(); ?>
				<?php $this->render_additional_css_card( $additional_css_preview ); ?>
			</div>

			<?php if ( is_array( $preview ) ) : ?>
				<?php
				$preview_notice_class = $preview['has_blocking_errors'] || ! empty( $preview['has_warnings'] ) ? 'notice-warning' : 'notice-success';
				?>
				<div class="notice <?php echo esc_attr( $preview_notice_class ); ?> cssflow-preview-result-notice" role="status">
					<p>
						<?php if ( $preview['has_blocking_errors'] ) : ?>
							<strong><?php esc_html_e( 'Backup checked — some items need attention.', 'cssflow' ); ?></strong>
							<?php esc_html_e( ' Review the preview below and fix the highlighted issues before importing.', 'cssflow' ); ?>
						<?php elseif ( ! empty( $preview['has_warnings'] ) ) : ?>
							<strong><?php esc_html_e( 'Backup ready — some targets are currently unavailable.', 'cssflow' ); ?></strong>
							<?php esc_html_e( ' CSSFlow will preserve those targets and import those snippets as inactive so the rest of the backup can be restored safely.', 'cssflow' ); ?>
						<?php else : ?>
							<strong><?php esc_html_e( 'Backup checked successfully.', 'cssflow' ); ?></strong>
							<?php esc_html_e( ' Review the preview below before choosing how the backup should be imported.', 'cssflow' ); ?>
						<?php endif; ?>
					</p>
				</div>
				<?php
				$commit_payload = $this->importer->build_commit_payload( $preview );
				$this->render_import_preview( $preview, $commit_payload );
				?>
			<?php endif; ?>

			<?php if ( $show_additional_css_preview && is_array( $additional_css_preview ) ) : ?>
				<?php $this->render_additional_css_preview( $additional_css_preview ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render generated-output health and regeneration controls.
	 *
	 * @return void
	 */
	private function render_output_health_card() {
		$method      = $this->output_manager->get_output_method();
		$health      = $this->output_manager->get_health_state();
		$storage     = $this->file_manager->get_output_health();
		$status_text = $this->get_health_status_label( $health['status'] );
		?>
		<section class="cssflow-tool-card cssflow-tool-card-wide" aria-labelledby="cssflow-output-health-title">
			<div class="cssflow-tool-card-header">
				<span class="dashicons dashicons-dashboard cssflow-tool-icon" aria-hidden="true"></span>
				<div>
					<h2 id="cssflow-output-health-title"><?php esc_html_e( 'CSS output health', 'cssflow' ); ?></h2>
					<p class="cssflow-tool-card-summary"><?php esc_html_e( 'Check how CSSFlow is delivering CSS and refresh its disposable generated files when needed.', 'cssflow' ); ?></p>
				</div>
			</div>
			<div class="cssflow-tool-card-body cssflow-output-health-body">
				<div class="cssflow-output-health-grid">
					<div class="cssflow-output-health-item">
						<span class="cssflow-output-health-label"><?php esc_html_e( 'Output method', 'cssflow' ); ?></span>
						<strong class="cssflow-output-health-value"><?php echo 'inline' === $method ? esc_html__( 'Inline CSS', 'cssflow' ) : esc_html__( 'Generated CSS files', 'cssflow' ); ?></strong>
					</div>

					<div class="cssflow-output-health-item">
						<span class="cssflow-output-health-label"><?php esc_html_e( 'Last output status', 'cssflow' ); ?></span>
						<strong class="cssflow-output-health-value"><?php echo esc_html( $status_text ); ?></strong>
					</div>

					<div class="cssflow-output-health-item">
						<span class="cssflow-output-health-label"><?php esc_html_e( 'Generated-file storage', 'cssflow' ); ?></span>
						<strong class="cssflow-output-health-value <?php echo ! empty( $storage['writable'] ) ? 'is-healthy' : 'is-warning'; ?>">
							<span class="dashicons <?php echo ! empty( $storage['writable'] ) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
							<?php echo ! empty( $storage['writable'] ) ? esc_html__( 'Writable', 'cssflow' ) : esc_html__( 'Not writable', 'cssflow' ); ?>
						</strong>
					</div>
				</div>

				<?php if ( 'file' === $method && empty( $storage['writable'] ) ) : ?>
					<div class="cssflow-tool-notice">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<p><?php esc_html_e( 'Generated-file storage is not writable. CSSFlow will keep frontend styling available through its automatic inline fallback when file output cannot be written.', 'cssflow' ); ?></p>
					</div>
				<?php elseif ( 'inline' === $method ) : ?>
					<div class="cssflow-tool-notice">
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
						<p><?php esc_html_e( 'Inline CSS is selected in Settings. Generated-file storage is still checked here so you can see whether file mode is available if you switch back later.', 'cssflow' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			<div class="cssflow-tool-card-footer">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cssflow_regenerate_css">
					<?php wp_nonce_field( 'cssflow_regenerate_css', 'cssflow_regenerate_css_nonce' ); ?>
					<?php submit_button( __( 'Regenerate CSS', 'cssflow' ), 'secondary', 'submit', false ); ?>
				</form>
				<span class="cssflow-tool-file-type"><?php esc_html_e( 'Source data is not changed', 'cssflow' ); ?></span>
			</div>
		</section>
		<?php
	}

	/**
	 * Convert the stored health key to plain-language admin text.
	 *
	 * @param string $status Health status key.
	 * @return string Human-readable status.
	 */
	private function get_health_status_label( $status ) {
		$labels = array(
			'healthy'   => __( 'Generated CSS files are working normally', 'cssflow' ),
			'inline'    => __( 'Inline CSS is working normally', 'cssflow' ),
			'fallback'  => __( 'Inline fallback is active because a generated file could not be written', 'cssflow' ),
			'safe_mode' => __( 'Safe Mode stopped frontend CSS output', 'cssflow' ),
			'stale'     => __( 'Generated CSS was cleared and will rebuild on the next matching frontend request', 'cssflow' ),
			'empty'     => __( 'The last frontend request had no matching CSSFlow CSS to output', 'cssflow' ),
			'unknown'   => __( 'No output status has been recorded yet', 'cssflow' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['unknown'];
	}

	/**
	 * Render the JSON backup card.
	 *
	 * @return void
	 */
	private function render_backup_card() {
		?>
		<section class="cssflow-tool-card" aria-labelledby="cssflow-json-export-title">
			<div class="cssflow-tool-card-header">
				<span class="dashicons dashicons-database-export cssflow-tool-icon" aria-hidden="true"></span>
				<div>
					<h2 id="cssflow-json-export-title"><?php esc_html_e( 'Back up CSSFlow', 'cssflow' ); ?></h2>
					<p class="cssflow-tool-card-summary"><?php esc_html_e( 'Download a complete CSSFlow backup file that keeps your snippets and setup together.', 'cssflow' ); ?></p>
				</div>
			</div>
			<div class="cssflow-tool-card-body">
				<p><?php esc_html_e( 'Keep this JSON file somewhere safe. You can use it with CSSFlow Import to move or restore your CSSFlow setup on another site.', 'cssflow' ); ?></p>
				<div class="cssflow-tool-includes">
					<strong><?php esc_html_e( 'Includes:', 'cssflow' ); ?></strong>
					<ul>
						<li><?php esc_html_e( 'All CSS snippets, including inactive snippets', 'cssflow' ); ?></li>
						<li><?php esc_html_e( 'Breakpoints and CSS variables', 'cssflow' ); ?></li>
						<li><?php esc_html_e( 'CSSFlow settings included in the backup', 'cssflow' ); ?></li>
					</ul>
				</div>
			</div>
			<div class="cssflow-tool-card-footer">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cssflow_export_json">
					<?php wp_nonce_field( 'cssflow_export_json', 'cssflow_nonce' ); ?>
					<?php submit_button( __( 'Download Backup', 'cssflow' ), 'primary', 'submit', false ); ?>
				</form>
				<span class="cssflow-tool-file-type"><?php esc_html_e( 'JSON file', 'cssflow' ); ?></span>
			</div>
		</section>
		<?php
	}

	/**
	 * Render the compiled CSS download card.
	 *
	 * @return void
	 */
	private function render_css_download_card() {
		?>
		<section class="cssflow-tool-card" aria-labelledby="cssflow-css-export-title">
			<div class="cssflow-tool-card-header">
				<span class="dashicons dashicons-media-code cssflow-tool-icon" aria-hidden="true"></span>
				<div>
					<h2 id="cssflow-css-export-title"><?php esc_html_e( 'Download your CSS', 'cssflow' ); ?></h2>
					<p class="cssflow-tool-card-summary"><?php esc_html_e( 'Download one CSS file containing the active CSS that CSSFlow is currently using.', 'cssflow' ); ?></p>
				</div>
			</div>
			<div class="cssflow-tool-card-body">
				<p><?php esc_html_e( 'Use this file when you want a copy of the final CSS for a developer, another project, or your own records.', 'cssflow' ); ?></p>
				<div class="cssflow-tool-notice">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<p><strong><?php esc_html_e( 'CSS only:', 'cssflow' ); ?></strong> <?php esc_html_e( 'This file does not include snippet names, targeting rules, breakpoints, or CSSFlow settings. Use the backup option if you want to restore your CSSFlow setup later.', 'cssflow' ); ?></p>
				</div>
			</div>
			<div class="cssflow-tool-card-footer">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cssflow_download_css">
					<?php wp_nonce_field( 'cssflow_download_css', 'cssflow_nonce' ); ?>
					<?php submit_button( __( 'Download CSS File', 'cssflow' ), 'secondary', 'submit', false ); ?>
				</form>
				<span class="cssflow-tool-file-type"><?php esc_html_e( 'CSS file', 'cssflow' ); ?></span>
			</div>
		</section>
		<?php
	}

	/**
	 * Render the JSON import card.
	 *
	 * @return void
	 */
	private function render_import_card() {
		?>
		<section class="cssflow-tool-card cssflow-tool-card-wide" aria-labelledby="cssflow-json-import-title">
			<div class="cssflow-tool-card-header">
				<span class="dashicons dashicons-database-import cssflow-tool-icon" aria-hidden="true"></span>
				<div>
					<h2 id="cssflow-json-import-title"><?php esc_html_e( 'Import a CSSFlow backup', 'cssflow' ); ?></h2>
					<p class="cssflow-tool-card-summary"><?php esc_html_e( 'Choose a JSON backup and review everything before CSSFlow changes your site.', 'cssflow' ); ?></p>
				</div>
			</div>
			<div class="cssflow-tool-card-body">
				<div class="cssflow-tool-notice cssflow-tool-notice-success">
					<span class="dashicons dashicons-shield" aria-hidden="true"></span>
					<p><strong><?php esc_html_e( 'Safe preview:', 'cssflow' ); ?></strong> <?php esc_html_e( 'Uploading a file here only checks it and shows a preview. Nothing is imported or changed yet.', 'cssflow' ); ?></p>
				</div>

				<form class="cssflow-import-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?page=cssflow-tools' ) ); ?>">
					<input type="hidden" name="cssflow_tools_action" value="preview_json_import">
					<?php wp_nonce_field( 'cssflow_preview_json_import', 'cssflow_import_nonce' ); ?>

					<label class="cssflow-import-file-label" for="cssflow-import-file"><?php esc_html_e( 'CSSFlow backup file', 'cssflow' ); ?></label>
					<input id="cssflow-import-file" name="cssflow_import_file" type="file" accept=".json,application/json" required>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: Maximum import size. */
								__( 'Choose a .json backup exported by CSSFlow. Maximum file size: %s.', 'cssflow' ),
								size_format( $this->importer->get_max_import_size() )
							)
						);
						?>
					</p>

					<?php submit_button( __( 'Preview Import', 'cssflow' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
		</section>
		<?php
	}


	/**
	 * Render WordPress Additional CSS detection/import card.
	 *
	 * @param array|WP_Error $preview Additional CSS preview data.
	 * @return void
	 */
	private function render_additional_css_card( $preview ) {
		$has_error = is_wp_error( $preview );
		$has_css   = ! $has_error && ! empty( $preview['has_css'] );
		?>
		<section class="cssflow-tool-card cssflow-tool-card-wide" aria-labelledby="cssflow-additional-css-title">
			<div class="cssflow-tool-card-header">
				<span class="dashicons dashicons-editor-code cssflow-tool-icon" aria-hidden="true"></span>
				<div>
					<h2 id="cssflow-additional-css-title"><?php esc_html_e( 'Import WordPress Additional CSS', 'cssflow' ); ?></h2>
					<p class="cssflow-tool-card-summary"><?php esc_html_e( 'Move CSS from Appearance → Customize → Additional CSS into a CSSFlow snippet without changing the original.', 'cssflow' ); ?></p>
				</div>
			</div>
			<div class="cssflow-tool-card-body">
				<?php if ( $has_error ) : ?>
					<div class="cssflow-tool-notice cssflow-tool-notice-error">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<p><?php echo esc_html( $preview->get_error_message() ); ?></p>
					</div>
				<?php elseif ( ! $has_css ) : ?>
					<div class="cssflow-tool-notice">
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
						<p>
							<strong><?php esc_html_e( 'No Additional CSS found.', 'cssflow' ); ?></strong>

							<?php
							/* translators: %s: Current WordPress theme name. */
							echo esc_html( sprintf( __( ' The current theme, %s, does not have any WordPress Additional CSS to import.', 'cssflow' ), $preview['theme_name'] ) );
							?>
						</p>
					</div>
				<?php else : ?>
					<p>
						<?php
						/* translators: 1: Formatted size of the Additional CSS, 2: Current WordPress theme name. */
						echo esc_html( sprintf( __( 'CSSFlow found %1$s of Additional CSS for %2$s.', 'cssflow' ), size_format( (int) $preview['size'] ), $preview['theme_name'] ) );
						?>
					</p>
					<div class="cssflow-tool-notice cssflow-tool-notice-success">
						<span class="dashicons dashicons-shield" aria-hidden="true"></span>
						<p><strong><?php esc_html_e( 'Safe import:', 'cssflow' ); ?></strong> <?php esc_html_e( 'CSSFlow will create an inactive snippet. Your original WordPress Additional CSS will stay exactly where it is, so the CSS is not duplicated on the frontend.', 'cssflow' ); ?></p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=cssflow-tools' ) ); ?>">
						<input type="hidden" name="cssflow_tools_action" value="preview_additional_css">
						<?php wp_nonce_field( 'cssflow_preview_additional_css', 'cssflow_additional_css_nonce' ); ?>
						<?php submit_button( __( 'Preview Additional CSS', 'cssflow' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render the exact WordPress Additional CSS before importing it.
	 *
	 * @param array $preview Additional CSS preview data.
	 * @return void
	 */
	private function render_additional_css_preview( array $preview ) {
		?>
		<section id="cssflow-additional-css-preview" class="cssflow-import-preview cssflow-additional-css-preview" data-cssflow-auto-scroll="1" aria-labelledby="cssflow-additional-css-preview-title">
			<div class="cssflow-import-preview-header">
				<div>
					<span class="cssflow-preview-eyebrow"><?php esc_html_e( 'Preview only', 'cssflow' ); ?></span>
					<h2 id="cssflow-additional-css-preview-title" tabindex="-1"><?php esc_html_e( 'Review WordPress Additional CSS', 'cssflow' ); ?></h2>
					<p><?php esc_html_e( 'Nothing has been imported yet. Review the CSS and the snippet settings below.', 'cssflow' ); ?></p>
				</div>
				<span class="cssflow-preview-status is-ready"><?php esc_html_e( 'Ready to import', 'cssflow' ); ?></span>
			</div>

			<div class="cssflow-additional-css-details">
				<div><span><?php esc_html_e( 'Theme', 'cssflow' ); ?></span><strong><?php echo esc_html( $preview['theme_name'] ); ?></strong></div>
				<div><span><?php esc_html_e( 'Target', 'cssflow' ); ?></span><strong><?php esc_html_e( 'Entire site', 'cssflow' ); ?></strong></div>
				<div><span><?php esc_html_e( 'Devices', 'cssflow' ); ?></span><strong><?php esc_html_e( 'All devices', 'cssflow' ); ?></strong></div>
				<div><span><?php esc_html_e( 'Imported status', 'cssflow' ); ?></span><strong><?php esc_html_e( 'Inactive', 'cssflow' ); ?></strong></div>
			</div>

			<div class="cssflow-preview-panel cssflow-preview-panel-wide">
				<h3><?php esc_html_e( 'CSS to import', 'cssflow' ); ?></h3>
				<pre class="cssflow-additional-css-code"><code><?php echo esc_html( $preview['css'] ); ?></code></pre>
			</div>

			<div class="cssflow-preview-next-step">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<p><strong><?php esc_html_e( 'The original stays unchanged.', 'cssflow' ); ?></strong> <?php esc_html_e( 'After import, remove the original WordPress Additional CSS yourself and then activate the new CSSFlow snippet when you are ready. This avoids the same CSS loading twice.', 'cssflow' ); ?></p>
			</div>

			<div class="cssflow-import-commit-actions">
				<div>
					<strong><?php esc_html_e( 'Ready to create the snippet?', 'cssflow' ); ?></strong>
					<p><?php esc_html_e( 'CSSFlow will create one inactive Global / All Devices snippet and refresh generated output. WordPress Additional CSS will not be edited or deleted.', 'cssflow' ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cssflow_import_additional_css">
					<?php wp_nonce_field( 'cssflow_import_additional_css', 'cssflow_additional_css_import_nonce' ); ?>
					<?php submit_button( __( 'Import as Inactive Snippet', 'cssflow' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
		</section>
		<?php
	}

	/**
	 * Render a read-only import preview.
	 *
	 * @param array            $preview        Validated preview data.
	 * @param string|\WP_Error $commit_payload Commit payload or preparation error.
	 * @return void
	 */
	private function render_import_preview( array $preview, $commit_payload ) {
		$counts = $preview['counts'];
		?>
		<section id="cssflow-import-preview" class="cssflow-import-preview" data-cssflow-auto-scroll="1" aria-labelledby="cssflow-import-preview-title">
			<?php if ( ! $preview['has_blocking_errors'] && ! is_wp_error( $commit_payload ) && current_user_can( 'manage_options' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cssflow-import-commit-form">
				<input type="hidden" name="action" value="cssflow_commit_json_import">
				<?php wp_nonce_field( 'cssflow_commit_json_import', 'cssflow_import_commit_nonce' ); ?>
				<textarea name="cssflow_import_payload" hidden><?php echo esc_textarea( $commit_payload ); ?></textarea>
			<?php endif; ?>
			<div class="cssflow-import-preview-header">
				<div>
					<span class="cssflow-preview-eyebrow"><?php esc_html_e( 'Preview only', 'cssflow' ); ?></span>
					<h2 id="cssflow-import-preview-title" tabindex="-1"><?php esc_html_e( 'Review this backup before importing', 'cssflow' ); ?></h2>
					<p><?php esc_html_e( 'CSSFlow has checked the file below. No database or settings changes have been made.', 'cssflow' ); ?></p>
				</div>
				<span class="cssflow-preview-status <?php echo $preview['has_blocking_errors'] ? 'is-error' : ( ! empty( $preview['has_warnings'] ) ? 'is-warning' : 'is-ready' ); ?>">
					<?php
					echo $preview['has_blocking_errors']
						? esc_html__( 'Needs attention', 'cssflow' )
						: ( ! empty( $preview['has_warnings'] ) ? esc_html__( 'Ready with warnings', 'cssflow' ) : esc_html__( 'Ready for import choices', 'cssflow' ) );
					?>
				</span>
			</div>

			<div class="cssflow-preview-summary-grid">
				<?php $this->render_summary_stat( __( 'Snippets', 'cssflow' ), (int) $counts['snippets'] ); ?>
				<?php $this->render_summary_stat( __( 'Conflicts found', 'cssflow' ), (int) $counts['conflicts'] ); ?>
				<?php $this->render_summary_stat( __( 'Breakpoints', 'cssflow' ), (int) $counts['breakpoints'] ); ?>
				<?php $this->render_summary_stat( __( 'CSS variables', 'cssflow' ), (int) $counts['variables'] ); ?>
			</div>

			<div class="cssflow-preview-meta">
				<strong><?php echo esc_html( $preview['file']['name'] ); ?></strong>
				<span><?php echo esc_html( size_format( (int) $preview['file']['size'] ) ); ?></span>
				<span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: CSSFlow plugin version recorded in backup. */
							__( 'Created with CSSFlow %s', 'cssflow' ),
							$preview['metadata']['plugin_version']
						)
					);
					?>
				</span>
			</div>

			<div class="cssflow-preview-sections">
				<?php $this->render_configuration_preview( $preview ); ?>
				<?php $this->render_snippets_preview( $preview['snippets'], ! $preview['has_blocking_errors'] && ! is_wp_error( $commit_payload ) && current_user_can( 'manage_options' ) ); ?>
			</div>

			<div class="cssflow-preview-next-step">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<p>
					<?php if ( $preview['has_blocking_errors'] ) : ?>
						<?php esc_html_e( 'This backup cannot be imported while the issues above remain. No data has been changed.', 'cssflow' ); ?>
					<?php elseif ( ! current_user_can( 'manage_options' ) ) : ?>
						<?php esc_html_e( 'Administrator permission is required to import the backup setup, breakpoints, and CSS variables.', 'cssflow' ); ?>
					<?php elseif ( ! empty( $preview['has_warnings'] ) ) : ?>
						<?php esc_html_e( 'Unavailable targets will be preserved and imported inactive. Snippets are skipped by default; choose which ones to import, or restore the related plugin/content later.', 'cssflow' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Choose which snippets to import. Snippets are skipped by default, and you can use the bulk action to prepare a full restore quickly.', 'cssflow' ); ?>
					<?php endif; ?>
				</p>
			</div>

			<?php if ( ! $preview['has_blocking_errors'] && ! is_wp_error( $commit_payload ) && current_user_can( 'manage_options' ) ) : ?>
				<div class="cssflow-import-commit-actions">
					<div>
						<strong><?php esc_html_e( 'Ready to import?', 'cssflow' ); ?></strong>
						<p><?php esc_html_e( 'CSSFlow will re-check the backup, apply your snippet import choices, save the data safely, and refresh generated CSS.', 'cssflow' ); ?></p>
					</div>
					<?php submit_button( __( 'Import Backup', 'cssflow' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render one import-preview summary statistic.
	 *
	 * @param string $label Statistic label.
	 * @param int    $value Statistic value.
	 * @return void
	 */
	private function render_summary_stat( $label, $value ) {
		?>
		<div class="cssflow-preview-stat"><strong><?php echo esc_html( (string) $value ); ?></strong><span><?php echo esc_html( $label ); ?></span></div>
		<?php
	}

	/**
	 * Render the imported configuration preview.
	 *
	 * @param array $preview Preview data.
	 * @return void
	 */
	private function render_configuration_preview( array $preview ) {
		?>
		<div class="cssflow-preview-panel">
			<h3><?php esc_html_e( 'Backup setup', 'cssflow' ); ?></h3>
			<p class="cssflow-preview-panel-intro"><?php esc_html_e( 'CSSFlow checked the backup configuration before allowing any import action.', 'cssflow' ); ?></p>
			<ul class="cssflow-preview-check-list">
				<?php $this->render_section_check( __( 'Breakpoints', 'cssflow' ), $preview['breakpoints'] ); ?>
				<?php $this->render_section_check( __( 'CSS variables', 'cssflow' ), $preview['variables'] ); ?>
				<?php $this->render_section_check( __( 'CSSFlow settings', 'cssflow' ), $preview['settings'] ); ?>
			</ul>
			<?php if ( ! current_user_can( 'manage_options' ) ) : ?>
				<div class="cssflow-preview-warning"><strong><?php esc_html_e( 'Administrator permission required:', 'cssflow' ); ?></strong> <?php esc_html_e( 'This backup contains site-level breakpoints, variables, and settings. Completing a full import will require manage_options permission.', 'cssflow' ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render one configuration validation result.
	 *
	 * @param string $label   Section label.
	 * @param array  $section Section result.
	 * @return void
	 */
	private function render_section_check( $label, array $section ) {
		?>
		<li class="<?php echo $section['valid'] ? 'is-valid' : 'is-invalid'; ?>">
			<span class="dashicons <?php echo $section['valid'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
			<div>
				<strong><?php echo esc_html( $label ); ?></strong>
				<?php if ( ! $section['valid'] ) : ?>
					<span><?php echo esc_html( $section['error'] ); ?></span>
				<?php endif; ?>
			</div>
		</li>
		<?php
	}

	/**
	 * Render snippet rows in the import preview.
	 *
	 * @param array $snippets      Snippet preview rows.
	 * @param bool  $allow_choices Whether conflict choices are available.
	 * @return void
	 */
	private function render_snippets_preview( array $snippets, $allow_choices = false ) {
		?>
		<div class="cssflow-preview-panel cssflow-preview-panel-wide">
			<div class="cssflow-preview-snippets-heading">
				<h3><?php esc_html_e( 'CSS snippets', 'cssflow' ); ?></h3>
				<?php if ( $allow_choices && ! empty( $snippets ) ) : ?>
					<div class="cssflow-import-bulk-actions">
						<label for="cssflow-import-set-all"><?php esc_html_e( 'Set all import actions', 'cssflow' ); ?></label>
						<select id="cssflow-import-set-all" data-cssflow-import-set-all>
							<option value="skip" selected><?php esc_html_e( 'Skip all snippets', 'cssflow' ); ?></option>
							<option value="safe-import"><?php esc_html_e( 'Import all safely', 'cssflow' ); ?></option>
							<option value="replace-existing"><?php esc_html_e( 'Import all and replace matching snippets', 'cssflow' ); ?></option>
							<option value="custom" hidden><?php esc_html_e( 'Custom selection', 'cssflow' ); ?></option>
						</select>
						<?php
						/* translators: 1: selected snippet count, 2: total snippet count. */
						$selection_template = __( '%1$d of %2$d snippets selected', 'cssflow' );
						?>
						<span
							class="cssflow-import-selection-summary"
							data-cssflow-import-selection-summary
							data-cssflow-selection-template="<?php echo esc_attr( $selection_template ); ?>"
							aria-live="polite"
						></span>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( empty( $snippets ) ) : ?>
				<p><?php esc_html_e( 'This backup does not contain any CSS snippets.', 'cssflow' ); ?></p>
			<?php else : ?>
				<div class="cssflow-preview-table-wrap">
					<table class="widefat striped cssflow-preview-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Snippet', 'cssflow' ); ?></th>
								<th><?php esc_html_e( 'Target', 'cssflow' ); ?></th>
								<th><?php esc_html_e( 'Status', 'cssflow' ); ?></th>
								<th><?php esc_html_e( 'Check', 'cssflow' ); ?></th>
								<?php if ( $allow_choices ) : ?>
									<th class="cssflow-import-action-heading"><?php esc_html_e( 'Import action', 'cssflow' ); ?></th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $snippets as $snippet ) : ?>
							<tr>
								<td>
									<strong>
										<?php
										/* translators: %d: Snippet number in the import preview. */
										echo esc_html( isset( $snippet['data']['name'] ) && '' !== $snippet['data']['name'] ? $snippet['data']['name'] : sprintf( __( 'Snippet %d', 'cssflow' ), (int) $snippet['number'] ) );
										?>
									</strong>
									<?php if ( ! empty( $snippet['conflict']['exists'] ) ) : ?>
										<span class="cssflow-conflict-badge"><?php esc_html_e( 'Same name already exists', 'cssflow' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $this->get_target_summary( $snippet ) ); ?></td>
								<td><?php echo esc_html( isset( $snippet['data']['status'] ) ? ucfirst( $snippet['data']['status'] ) : '—' ); ?></td>
								<td>
									<?php if ( ! empty( $snippet['warning'] ) ) : ?>
										<span class="cssflow-row-check is-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php echo esc_html( $snippet['warning'] ); ?></span>
									<?php elseif ( $snippet['valid'] ) : ?>
										<span class="cssflow-row-check is-valid"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Valid', 'cssflow' ); ?></span>
									<?php else : ?>
										<span class="cssflow-row-check is-invalid"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php echo esc_html( $snippet['error'] ); ?></span>
									<?php endif; ?>
								</td>
								<?php if ( $allow_choices ) : ?>
								<td class="cssflow-import-action-cell">
									<?php $choice_index = (int) $snippet['number'] - 1; ?>
									<label class="screen-reader-text" for="cssflow-import-action-<?php echo esc_attr( (string) $choice_index ); ?>"><?php esc_html_e( 'Import action', 'cssflow' ); ?></label>
									<select
										id="cssflow-import-action-<?php echo esc_attr( (string) $choice_index ); ?>"
										class="cssflow-import-action-select"
										name="cssflow_conflict_choices[<?php echo esc_attr( (string) $choice_index ); ?>]"
										data-cssflow-import-action
										data-cssflow-has-conflict="<?php echo ! empty( $snippet['conflict']['exists'] ) ? '1' : '0'; ?>"
									>
										<?php if ( ! empty( $snippet['conflict']['exists'] ) ) : ?>
											<option value="copy"><?php esc_html_e( 'Import as new copy', 'cssflow' ); ?></option>
											<option value="replace"><?php esc_html_e( 'Replace existing snippet', 'cssflow' ); ?></option>
										<?php else : ?>
											<option value="import"><?php esc_html_e( 'Import', 'cssflow' ); ?></option>
										<?php endif; ?>
										<option value="skip" selected><?php esc_html_e( 'Skip this snippet', 'cssflow' ); ?></option>
									</select>
								</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build a compact target summary for an import-preview row.
	 *
	 * @param array $snippet Preview row.
	 * @return string
	 */
	private function get_target_summary( array $snippet ) {
		if ( ! $snippet['valid'] || empty( $snippet['data']['scope_type'] ) ) {
			return '—';
		}

		$scope_labels      = array(
			'global'           => __( 'Entire site', 'cssflow' ),
			'specific_content' => __( 'Specific content', 'cssflow' ),
			'post_type'        => __( 'Content type', 'cssflow' ),
			'special'          => __( 'Special page', 'cssflow' ),
			'woocommerce'      => __( 'WooCommerce', 'cssflow' ),
		);
		$responsive_labels = array(
			'all'     => __( 'All devices', 'cssflow' ),
			'desktop' => __( 'Desktop', 'cssflow' ),
			'tablet'  => __( 'Tablet', 'cssflow' ),
			'mobile'  => __( 'Mobile', 'cssflow' ),
			'custom'  => __( 'Custom breakpoint', 'cssflow' ),
		);
		$scope             = isset( $scope_labels[ $snippet['data']['scope_type'] ] ) ? $scope_labels[ $snippet['data']['scope_type'] ] : $snippet['data']['scope_type'];
		$responsive        = isset( $responsive_labels[ $snippet['data']['responsive_type'] ] ) ? $responsive_labels[ $snippet['data']['responsive_type'] ] : $snippet['data']['responsive_type'];

		return $scope . ' · ' . $responsive;
	}


	/**
	 * Import current WordPress Additional CSS as an inactive CSSFlow snippet.
	 *
	 * @return void
	 */
	public function handle_additional_css_import() {
		$this->require_edit_capability();
		check_admin_referer( 'cssflow_import_additional_css', 'cssflow_additional_css_import_nonce' );

		$result = $this->importer->import_additional_css();

		if ( is_wp_error( $result ) ) {
			$this->set_flash_notice(
				'additional_css_error',
				array( 'message' => sanitize_text_field( $result->get_error_message() ) )
			);
		} else {
			$this->set_flash_notice(
				'additional_css_success',
				array( 'name' => sanitize_text_field( $result['name'] ) )
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=cssflow-tools#cssflow-additional-css-result' ) );
		exit;
	}

	/**
	 * Commit a previously previewed JSON backup.
	 *
	 * @return void
	 */
	public function handle_json_import_commit() {
		$this->require_edit_capability();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Administrator permission is required to import CSSFlow backup settings.', 'cssflow' ), esc_html__( 'CSSFlow Import', 'cssflow' ), array( 'response' => 403 ) );
		}

		check_admin_referer( 'cssflow_commit_json_import', 'cssflow_import_commit_nonce' );

		$payload = isset( $_POST['cssflow_import_payload'] ) && is_string( $_POST['cssflow_import_payload'] )
			? wp_unslash( $_POST['cssflow_import_payload'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Revalidated as untrusted JSON by Importer.
			: '';
		$choices = isset( $_POST['cssflow_conflict_choices'] ) && is_array( $_POST['cssflow_conflict_choices'] )
			? wp_unslash( $_POST['cssflow_conflict_choices'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Individual values are allow-listed by Importer.
			: array();

		$result = $this->importer->commit_payload( $payload, $choices );

		if ( is_wp_error( $result ) ) {
			$this->set_flash_notice(
				'import_error',
				array( 'message' => sanitize_text_field( $result->get_error_message() ) )
			);
		} else {
			$this->set_flash_notice(
				'import_success',
				array(
					'created'  => absint( $result['created'] ),
					'replaced' => absint( $result['replaced'] ),
					'skipped'  => absint( $result['skipped'] ),
				)
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=cssflow-tools#cssflow-import-result' ) );
		exit;
	}

	/**
	 * Render import result feedback.
	 *
	 * @return void
	 */
	private function render_import_result_notice() {
		$flash = $this->get_flash_notice();

		if ( ! is_array( $flash ) || empty( $flash['type'] ) ) {
			return;
		}

		$type = sanitize_key( (string) $flash['type'] );

		if ( 'additional_css_success' === $type ) {
			$name = isset( $flash['name'] ) ? sanitize_text_field( (string) $flash['name'] ) : __( 'Imported WordPress Additional CSS', 'cssflow' );
			?>
			<div id="cssflow-additional-css-result" class="notice notice-success cssflow-tools-notice" role="status">
				<p><strong><?php esc_html_e( 'Additional CSS imported successfully.', 'cssflow' ); ?></strong></p>
				<p>
					<?php
					/* translators: %s: Name of the newly created CSSFlow snippet. */
					echo esc_html( sprintf( __( 'Created “%s” as an inactive CSSFlow snippet. The original WordPress Additional CSS was not changed.', 'cssflow' ), $name ) );
					?>
				</p>
			</div>
			<?php
			return;
		}

		if ( 'additional_css_error' === $type ) {
			$message = isset( $flash['message'] ) ? sanitize_text_field( (string) $flash['message'] ) : __( 'CSSFlow could not import WordPress Additional CSS.', 'cssflow' );
			?>
			<div id="cssflow-additional-css-result" class="notice notice-error cssflow-tools-notice" role="alert">
				<p><strong><?php esc_html_e( 'Additional CSS was not imported.', 'cssflow' ); ?></strong></p>
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
			return;
		}

		if ( 'import_success' === $type ) {
			$created  = isset( $flash['created'] ) ? absint( $flash['created'] ) : 0;
			$replaced = isset( $flash['replaced'] ) ? absint( $flash['replaced'] ) : 0;
			$skipped  = isset( $flash['skipped'] ) ? absint( $flash['skipped'] ) : 0;
			?>
			<div id="cssflow-import-result" class="notice notice-success cssflow-tools-notice" role="status">
				<p><strong><?php esc_html_e( 'Backup imported successfully.', 'cssflow' ); ?></strong></p>
				<p>
					<?php
					/* translators: 1: Number of new snippets added, 2: Number of snippets replaced, 3: Number of snippets skipped. */
					echo esc_html( sprintf( __( '%1$d new snippets added, %2$d replaced, %3$d skipped. CSSFlow output was refreshed.', 'cssflow' ), $created, $replaced, $skipped ) );
					?>
				</p>
			</div>
			<?php
			return;
		}

		if ( 'import_error' === $type ) {
			$message = isset( $flash['message'] ) ? sanitize_text_field( (string) $flash['message'] ) : __( 'CSSFlow could not import this backup.', 'cssflow' );
			?>
			<div id="cssflow-import-result" class="notice notice-error cssflow-tools-notice" role="alert">
				<p><strong><?php esc_html_e( 'Backup was not imported.', 'cssflow' ); ?></strong></p>
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Securely invalidate disposable generated CSS files.
	 *
	 * @return void
	 */
	public function handle_regenerate_css() {
		$this->require_edit_capability();
		check_admin_referer( 'cssflow_regenerate_css', 'cssflow_regenerate_css_nonce' );

		$result = $this->output_manager->regenerate();
		$status = is_wp_error( $result ) ? 'failed' : 'success';

		$this->set_flash_notice( 'regenerate_' . $status );

		wp_safe_redirect( admin_url( 'admin.php?page=cssflow-tools' ) );
		exit;
	}

	/**
	 * Render regeneration result feedback.
	 *
	 * @return void
	 */
	private function render_regenerate_notice() {
		$flash = $this->get_flash_notice();

		if ( ! is_array( $flash ) || empty( $flash['type'] ) ) {
			return;
		}

		$type = sanitize_key( (string) $flash['type'] );

		if ( 'regenerate_success' === $type ) {
			?>
			<div class="notice notice-success is-dismissible cssflow-tools-notice" role="status">
				<p><?php esc_html_e( 'Generated CSS was cleared successfully and will rebuild automatically when needed.', 'cssflow' ); ?></p>
			</div>
			<?php
			return;
		}

		if ( 'regenerate_failed' === $type ) {
			?>
			<div class="notice notice-error cssflow-tools-notice" role="alert">
				<p><?php esc_html_e( 'CSSFlow could not clear the generated CSS files. Frontend output can still use the inline fallback when generated-file output is unavailable.', 'cssflow' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Store a one-time Tools notice for the current user.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Notice type.
	 * @param array  $data Optional sanitized notice data.
	 * @return void
	 */
	private function set_flash_notice( $type, $data = array() ) {
		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return;
		}

		$payload         = is_array( $data ) ? $data : array();
		$payload['type'] = sanitize_key( $type );

		set_transient( 'cssflow_tools_notice_' . $user_id, $payload, MINUTE_IN_SECONDS );
	}

	/**
	 * Get and consume the current user's one-time Tools notice.
	 *
	 * @since 1.0.0
	 *
	 * @return array|false Notice payload, or false when none exists.
	 */
	private function get_flash_notice() {
		if ( null !== $this->flash_notice ) {
			return $this->flash_notice;
		}

		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			$this->flash_notice = false;
			return $this->flash_notice;
		}

		$this->flash_notice = get_transient( 'cssflow_tools_notice_' . $user_id );
		delete_transient( 'cssflow_tools_notice_' . $user_id );

		if ( ! is_array( $this->flash_notice ) ) {
			$this->flash_notice = false;
		}

		return $this->flash_notice;
	}

	/**
	 * Download a JSON backup.
	 *
	 * @return void
	 */
	public function handle_json_export() {
		$this->require_edit_capability();
		check_admin_referer( 'cssflow_export_json', 'cssflow_nonce' );
		$json = $this->exporter->build_json();
		if ( is_wp_error( $json ) ) {
			$this->render_download_error( $json );
		}
		$this->send_download_headers( 'application/json; charset=UTF-8', 'cssflow-export-' . gmdate( 'Y-m-d-His' ) . '.json' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional generated JSON download.
		exit;
	}

	/**
	 * Download the compiled CSS export.
	 *
	 * @return void
	 */
	public function handle_css_download() {
		$this->require_edit_capability();
		check_admin_referer( 'cssflow_download_css', 'cssflow_nonce' );
		$css = $this->exporter->build_flat_css();
		if ( is_wp_error( $css ) ) {
			$this->render_download_error( $css );
		}
		$this->send_download_headers( 'text/css; charset=UTF-8', 'cssflow-export-' . gmdate( 'Y-m-d-His' ) . '.css' );
		echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional compiled CSS download.
		exit;
	}

	/**
	 * Send secure file-download headers.
	 *
	 * @param string $content_type MIME content type.
	 * @param string $filename     Download filename.
	 * @return void
	 */
	private function send_download_headers( $content_type, $filename ) {
		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * Render a download error and stop execution.
	 *
	 * @param WP_Error $error Download error.
	 * @return void
	 */
	private function render_download_error( WP_Error $error ) {
		wp_die( esc_html( $error->get_error_message() ), esc_html__( 'CSSFlow Export Error', 'cssflow' ), array( 'back_link' => true ) );
	}

	/**
	 * Require CSSFlow editing capability.
	 *
	 * @return void
	 */
	private function require_edit_capability() {
		if ( current_user_can( Capabilities::get_edit_capability() ) ) {
			return;
		}
		wp_die( esc_html__( 'You do not have permission to use CSSFlow tools.', 'cssflow' ), esc_html__( 'CSSFlow', 'cssflow' ), array( 'response' => 403 ) );
	}
}
