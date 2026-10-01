<?php
/**
 * Renders and processes CSSFlow global settings.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Admin\Pages;

use CSSFlow\CSS\Output_Manager;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow Settings administration page controller.
 */
final class Settings_Page {

	/**
	 * Output manager.
	 *
	 * @var Output_Manager
	 */
	private $output_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Output_Manager $output_manager Output manager.
	 */
	public function __construct( Output_Manager $output_manager ) {
		$this->output_manager = $output_manager;
	}

	/**
	 * Register the Settings screen and secure save action.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_cssflow_save_settings', array( $this, 'handle_save_settings' ) );
	}

	/**
	 * Register the Settings submenu.
	 *
	 * Settings are site-level CSSFlow configuration and therefore deliberately
	 * retain the frozen manage_options authorization boundary.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'cssflow',
			__( 'Settings', 'cssflow' ),
			__( 'Settings', 'cssflow' ),
			'manage_options',
			'cssflow-settings',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the Settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render() {
		$this->require_manage_options();

		$settings                      = $this->get_settings();
		$profile_disables_highlighting = $this->current_user_disables_syntax_highlighting();
		?>
		<div class="wrap cssflow-tools-page">
			<hr class="wp-header-end" />
			<div class="cssflow-tools-header">
				<div>
					<h1><?php esc_html_e( 'Settings', 'cssflow' ); ?></h1>
					<p class="cssflow-tools-intro">
						<?php esc_html_e( 'Choose how CSSFlow delivers CSS and what should happen to CSSFlow data when the plugin is deleted.', 'cssflow' ); ?>
					</p>
				</div>
			</div>

			<?php $this->render_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cssflow_save_settings">
				<?php wp_nonce_field( 'cssflow_save_settings', 'cssflow_settings_nonce' ); ?>

				<div class="cssflow-tools-grid">
					<section class="cssflow-tool-card" aria-labelledby="cssflow-output-method-title">
						<div class="cssflow-tool-card-header">
							<span class="dashicons dashicons-media-code cssflow-tool-icon" aria-hidden="true"></span>

							<div>
								<h2 id="cssflow-output-method-title">
									<?php esc_html_e( 'CSS output method', 'cssflow' ); ?>
								</h2>

								<p class="cssflow-tool-card-summary">
									<?php esc_html_e( 'Choose how CSSFlow sends compiled CSS to visitors.', 'cssflow' ); ?>
								</p>
							</div>
						</div>

						<div class="cssflow-tool-card-body">
							<fieldset>
								<legend class="screen-reader-text">
									<?php esc_html_e( 'CSS output method', 'cssflow' ); ?>
								</legend>

								<p>
									<label>
										<input
											type="radio"
											name="output_method"
											value="file"
											<?php checked( 'file', $settings['output_method'] ); ?>
										>
										<strong><?php esc_html_e( 'Generated CSS files', 'cssflow' ); ?></strong>
									</label>
								</p>

								<p class="description">
									<?php esc_html_e( 'Recommended. CSSFlow writes contextual CSS files to the WordPress uploads directory and uses content-based cache busting.', 'cssflow' ); ?>
								</p>

								<p>
									<label>
										<input
											type="radio"
											name="output_method"
											value="inline"
											<?php checked( 'inline', $settings['output_method'] ); ?>
										>
										<strong><?php esc_html_e( 'Inline CSS', 'cssflow' ); ?></strong>
									</label>
								</p>

								<p class="description">
									<?php esc_html_e( 'CSSFlow attaches the compiled CSS through WordPress enqueue APIs instead of using generated CSS files.', 'cssflow' ); ?>
								</p>

								<div class="cssflow-tool-notice">
									<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
									<p>
										<?php esc_html_e( 'If generated files cannot be written safely, CSSFlow automatically falls back to inline CSS so your styling is not lost.', 'cssflow' ); ?>
									</p>
								</div>
							</fieldset>
						</div>
					</section>

					<section class="cssflow-tool-card" aria-labelledby="cssflow-uninstall-title">
						<div class="cssflow-tool-card-header">
							<span class="dashicons dashicons-trash cssflow-tool-icon" aria-hidden="true"></span>

							<div>
								<h2 id="cssflow-uninstall-title">
									<?php esc_html_e( 'Plugin deletion', 'cssflow' ); ?>
								</h2>

								<p class="cssflow-tool-card-summary">
									<?php esc_html_e( 'Control whether CSSFlow data should be removed when the plugin itself is deleted.', 'cssflow' ); ?>
								</p>
							</div>
						</div>

						<div class="cssflow-tool-card-body">
							<p>
								<label for="cssflow-delete-on-uninstall">
									<input
										type="checkbox"
										id="cssflow-delete-on-uninstall"
										name="delete_on_uninstall"
										value="1"
										<?php checked( true, $settings['delete_on_uninstall'] ); ?>
									>
									<strong>
										<?php esc_html_e( 'Delete all CSSFlow data when the plugin is deleted', 'cssflow' ); ?>
									</strong>
								</label>
							</p>

							<p class="description">
								<?php esc_html_e( 'Leave this unchecked if you want your CSS snippets and CSSFlow configuration preserved after deleting the plugin.', 'cssflow' ); ?>
							</p>

							<div class="cssflow-tool-notice">
								<span class="dashicons dashicons-warning" aria-hidden="true"></span>
								<p>
									<?php esc_html_e( 'This setting applies to plugin deletion, not normal deactivation. Deactivating CSSFlow never deletes your snippets or settings.', 'cssflow' ); ?>
								</p>
							</div>
						</div>
					</section>

					<section class="cssflow-tool-card" aria-labelledby="cssflow-code-editor-title">
						<div class="cssflow-tool-card-header">
							<span class="dashicons dashicons-editor-code cssflow-tool-icon" aria-hidden="true"></span>

							<div>
								<h2 id="cssflow-code-editor-title">
									<?php esc_html_e( 'Code editor', 'cssflow' ); ?>
								</h2>

								<p class="cssflow-tool-card-summary">
									<?php esc_html_e( 'Use WordPress\'s enhanced editor for CSS when it is available.', 'cssflow' ); ?>
								</p>
							</div>
						</div>

						<div class="cssflow-tool-card-body">
							<p>
								<label for="cssflow-enable-syntax-highlighting">
									<input
										type="checkbox"
										id="cssflow-enable-syntax-highlighting"
										name="enable_syntax_highlighting"
										value="1"
										<?php checked( true, $settings['enable_syntax_highlighting'] ); ?>
									>
									<strong>
										<?php esc_html_e( 'Use the enhanced code editor when available', 'cssflow' ); ?>
									</strong>
								</label>
							</p>

							<p class="description">
								<?php esc_html_e( 'Adds syntax highlighting and line numbers. Your WordPress profile can turn this off for your account.', 'cssflow' ); ?>
							</p>

							<?php if ( $settings['enable_syntax_highlighting'] && $profile_disables_highlighting ) : ?>
								<div class="cssflow-tool-notice">
									<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
									<p>
										<?php esc_html_e( 'Syntax highlighting is off in your WordPress profile, so CSSFlow is using the plain editor.', 'cssflow' ); ?>
										<a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php esc_html_e( 'Open Profile', 'cssflow' ); ?></a>
									</p>
								</div>
							<?php endif; ?>
						</div>
					</section>
				</div>

				<?php submit_button( __( 'Save Settings', 'cssflow' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save CSSFlow's frozen v1.0 settings.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		$this->require_manage_options();
		check_admin_referer( 'cssflow_save_settings', 'cssflow_settings_nonce' );

		$output_method = isset( $_POST['output_method'] )
			? sanitize_key( wp_unslash( $_POST['output_method'] ) )
			: '';

		if ( ! in_array( $output_method, array( 'file', 'inline' ), true ) ) {
			$this->redirect( 'invalid_output_method' );
		}

		$current_settings = $this->get_settings();
		$new_settings     = array(
			'output_method'              => $output_method,
			'delete_on_uninstall'        => isset( $_POST['delete_on_uninstall'] ),
			'enable_syntax_highlighting' => isset( $_POST['enable_syntax_highlighting'] ),
		);

		$settings_changed = $current_settings !== $new_settings;

		if ( $settings_changed ) {
			$updated = update_option( 'cssflow_settings', $new_settings, false );

			if ( ! $updated ) {
				$this->redirect( 'save_failed' );
			}
		}

		if ( $current_settings['output_method'] !== $new_settings['output_method'] ) {
			$regenerated = $this->output_manager->regenerate();

			if ( is_wp_error( $regenerated ) ) {
				$this->redirect( 'saved_refresh_failed' );
			}
		}

		$this->redirect( 'saved' );
	}

	/**
	 * Resolve normalized CSSFlow settings.
	 *
	 * Invalid stored values fall back to the frozen v1.0 defaults without
	 * silently writing new configuration during page rendering.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed> Normalized settings.
	 */
	private function get_settings() {
		$stored = get_option( 'cssflow_settings', array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$output_method = isset( $stored['output_method'] ) && is_string( $stored['output_method'] )
			? sanitize_key( $stored['output_method'] )
			: 'file';

		if ( ! in_array( $output_method, array( 'file', 'inline' ), true ) ) {
			$output_method = 'file';
		}

		$enable_syntax_highlighting = ! array_key_exists( 'enable_syntax_highlighting', $stored )
			|| ! empty( $stored['enable_syntax_highlighting'] );

		return array(
			'output_method'              => $output_method,
			'delete_on_uninstall'        => ! empty( $stored['delete_on_uninstall'] ),
			'enable_syntax_highlighting' => $enable_syntax_highlighting,
		);
	}

	/**
	 * Determine whether the current WordPress user disabled syntax highlighting.
	 *
	 * WordPress stores this as a per-user accessibility preference. CSSFlow must
	 * respect it and must not force the enhanced editor for that user.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether syntax highlighting is disabled for the current user.
	 */
	private function current_user_disables_syntax_highlighting() {
		$user = wp_get_current_user();

		return isset( $user->syntax_highlighting ) && 'false' === $user->syntax_highlighting;
	}

	/**
	 * Render Settings result notices.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_notice() {
		$user_id = get_current_user_id();
		$status  = $user_id > 0 ? get_transient( 'cssflow_settings_notice_' . $user_id ) : false;

		if ( $user_id > 0 ) {
			delete_transient( 'cssflow_settings_notice_' . $user_id );
		}

		$status = is_string( $status ) ? sanitize_key( $status ) : '';

		if ( '' === $status ) {
			return;
		}

		if ( 'saved' === $status ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'CSSFlow settings saved.', 'cssflow' ); ?></p>
			</div>
			<?php
			return;
		}

		if ( 'saved_refresh_failed' === $status ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<?php esc_html_e( 'CSSFlow settings were saved, but the existing generated CSS files could not be cleared. CSSFlow will continue to use its normal output fallback behavior.', 'cssflow' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		if ( 'invalid_output_method' === $status ) {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'The selected CSS output method is invalid.', 'cssflow' ); ?></p>
			</div>
			<?php
			return;
		}

		if ( 'save_failed' === $status ) {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'CSSFlow could not save the settings.', 'cssflow' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Require the frozen Settings capability boundary.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function require_manage_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage CSSFlow settings.', 'cssflow' ),
				esc_html__( 'CSSFlow', 'cssflow' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Redirect back to Settings after a state-changing action.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Result status.
	 * @return void
	 */
	private function redirect( $status ) {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			set_transient( 'cssflow_settings_notice_' . $user_id, sanitize_key( $status ), MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=cssflow-settings' ) );
		exit;
	}
}
