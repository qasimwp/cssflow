<?php
/**
 * Renders and processes CSSFlow breakpoint management.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Admin\Pages;

use CSSFlow\CSS\Compiler;
use CSSFlow\CSS\Output_Manager;
use CSSFlow\Database\Snippet_Repository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Breakpoints administration page controller.
 */
final class Breakpoints_Page {

	/**
	 * Frozen default breakpoint keys.
	 *
	 * @var string[]
	 */
	private $default_keys = array(
		'mobile',
		'tablet',
		'desktop',
	);

	/**
	 * Snippet repository.
	 *
	 * @var Snippet_Repository
	 */
	private $repository;

	/**
	 * Responsive compiler and breakpoint validator.
	 *
	 * @var Compiler
	 */
	private $compiler;

	/**
	 * Output manager.
	 *
	 * @var Output_Manager
	 */
	private $output_manager;

	/**
	 * One-time notice payload for the current request.
	 *
	 * @var array|false|null
	 */
	private $flash_notice = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Snippet_Repository $repository        Snippet repository.
	 * @param Compiler           $compiler          Responsive compiler.
	 * @param Output_Manager     $output_manager Output manager.
	 */
	public function __construct( Snippet_Repository $repository, Compiler $compiler, Output_Manager $output_manager ) {
		$this->repository     = $repository;
		$this->compiler       = $compiler;
		$this->output_manager = $output_manager;
	}

	/**
	 * Register the Breakpoints screen and its state-changing actions.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_cssflow_save_breakpoint', array( $this, 'handle_save_breakpoint' ) );
		add_action( 'admin_post_cssflow_add_breakpoint', array( $this, 'handle_add_breakpoint' ) );
		add_action( 'admin_post_cssflow_delete_breakpoint', array( $this, 'handle_delete_breakpoint' ) );
	}

	/**
	 * Register the Breakpoints submenu required by the frozen admin structure.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'cssflow',
			__( 'Breakpoints', 'cssflow' ),
			__( 'Breakpoints', 'cssflow' ),
			'manage_options',
			'cssflow-breakpoints',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the Breakpoints administration screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render() {
		$this->require_manage_options();

		$breakpoints = $this->get_breakpoints();
		?>
		<div class="wrap cssflow-breakpoints-page">
			<hr class="wp-header-end" />
			<div class="cssflow-breakpoints-shell">
				<header class="cssflow-breakpoints-header">
					<h1><?php esc_html_e( 'Breakpoints', 'cssflow' ); ?></h1>
					<p><?php esc_html_e( 'Control the screen widths CSSFlow uses for responsive CSS snippets.', 'cssflow' ); ?></p>
				</header>

				<?php $this->render_notice(); ?>

				<div class="cssflow-breakpoints-intro" role="note">
					<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
					<p><?php esc_html_e( 'A breakpoint defines the screen-width range where responsive CSS applies. Widths are measured in pixels.', 'cssflow' ); ?></p>
				</div>

				<?php if ( is_wp_error( $breakpoints ) ) : ?>
					<div class="notice notice-error">
						<p><?php esc_html_e( 'The stored CSSFlow breakpoint configuration is invalid and cannot be edited safely from this screen.', 'cssflow' ); ?></p>
					</div>
				<?php else : ?>
					<?php $this->render_add_form(); ?>

					<section class="cssflow-breakpoints-section" aria-labelledby="cssflow-default-breakpoints-heading">
						<div class="cssflow-breakpoints-section-heading">
							<div>
								<h2 id="cssflow-default-breakpoints-heading"><?php esc_html_e( 'Default breakpoints', 'cssflow' ); ?></h2>
								<p><?php esc_html_e( 'Mobile, Tablet, and Desktop are built in. Their width ranges can be changed, but they cannot be renamed or deleted.', 'cssflow' ); ?></p>
							</div>
						</div>

						<div class="cssflow-breakpoints-grid cssflow-breakpoints-grid-defaults">
							<?php
							foreach ( $this->default_keys as $key ) {
								$this->render_breakpoint_form( $key, $breakpoints[ $key ], true );
							}
							?>
						</div>
					</section>

					<section class="cssflow-breakpoints-section" aria-labelledby="cssflow-custom-breakpoints-heading">
						<div class="cssflow-breakpoints-section-heading">
							<div>
								<h2 id="cssflow-custom-breakpoints-heading"><?php esc_html_e( 'Custom breakpoints', 'cssflow' ); ?></h2>
								<p><?php esc_html_e( 'Create additional responsive ranges when the built-in device breakpoints are not enough.', 'cssflow' ); ?></p>
							</div>
						</div>

						<?php
						$has_custom = false;
						?>
						<div class="cssflow-breakpoints-grid cssflow-breakpoints-grid-custom">
							<?php
							foreach ( $breakpoints as $key => $breakpoint ) {
								if ( in_array( $key, $this->default_keys, true ) ) {
									continue;
								}

								$has_custom = true;
								$this->render_breakpoint_form( $key, $breakpoint, false );
							}
							?>
						</div>

						<?php if ( ! $has_custom ) : ?>
							<div class="cssflow-breakpoints-empty-state">
								<span class="dashicons dashicons-screenoptions" aria-hidden="true"></span>
								<strong><?php esc_html_e( 'No custom breakpoints yet', 'cssflow' ); ?></strong>
								<p><?php esc_html_e( 'Add one when you need a responsive range beyond Mobile, Tablet, or Desktop.', 'cssflow' ); ?></p>
							</div>
						<?php endif; ?>

					</section>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Save one existing default or custom breakpoint.
	 *
	 * The complete resulting configuration is passed through the existing
	 * Phase 4 compiler validator before the option is updated.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_save_breakpoint() {
		$this->require_manage_options();

		$key = $this->get_submitted_key();

		if ( '' === $key ) {
			$this->redirect( 'invalid_request' );
		}

		check_admin_referer( 'cssflow_save_breakpoint_' . $key, 'cssflow_nonce' );

		$breakpoints = $this->get_breakpoints();

		if ( is_wp_error( $breakpoints ) ) {
			$this->redirect( 'invalid_configuration', $breakpoints->get_error_code() );
		}

		if ( ! isset( $breakpoints[ $key ] ) ) {
			$this->redirect( 'not_found' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability verified above; fields are normalized and compiler-validated by the helpers.
		$request   = wp_unslash( $_POST );
		$current   = $breakpoints;
		$submitted = $this->get_submitted_definition( $request );

		if ( is_wp_error( $submitted ) ) {
			$this->redirect( 'validation_error', $submitted->get_error_code(), 0, $key );
		}

		$label = $breakpoints[ $key ]['label'];

		if ( ! in_array( $key, $this->default_keys, true ) ) {
			$label = isset( $submitted['label'] ) ? $submitted['label'] : '';
		}

		$breakpoints[ $key ] = array(
			'label'      => $label,
			'min_width'  => $submitted['min_width'],
			'max_width'  => $submitted['max_width'],
			'is_default' => in_array( $key, $this->default_keys, true ),
		);

		$validated = $this->compiler->validate_breakpoints( $breakpoints );

		if ( is_wp_error( $validated ) ) {
			$this->redirect( 'validation_error', $validated->get_error_code(), 0, $key );
		}

		if ( $validated === $current ) {
			$this->redirect( 'no_changes' );
		}

		update_option( 'cssflow_breakpoints', $validated, false );
		$this->invalidate_output_and_redirect( 'saved' );
	}

	/**
	 * Add one new custom breakpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_add_breakpoint() {
		$this->require_manage_options();
		check_admin_referer( 'cssflow_add_breakpoint', 'cssflow_nonce' );

		$request     = wp_unslash( $_POST );
		$breakpoints = $this->get_breakpoints();

		if ( is_wp_error( $breakpoints ) ) {
			$this->redirect( 'invalid_configuration', $breakpoints->get_error_code() );
		}

		$raw_key = isset( $request['breakpoint_key'] ) && is_scalar( $request['breakpoint_key'] )
			? trim( (string) $request['breakpoint_key'] )
			: '';
		$key     = sanitize_key( $raw_key );

		if ( '' === $raw_key || strlen( $raw_key ) > 100 || $raw_key !== $key ) {
			$this->redirect( 'validation_error', 'cssflow_invalid_breakpoint_key', 0, 'add' );
		}

		if ( in_array( $key, $this->default_keys, true ) || isset( $breakpoints[ $key ] ) ) {
			$this->redirect( 'duplicate_key', '', 0, 'add' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability verified above; fields are normalized and compiler-validated by the helpers.
		$request   = wp_unslash( $_POST );
		$submitted = $this->get_submitted_definition( $request );

		if ( is_wp_error( $submitted ) ) {
			$this->redirect( 'validation_error', $submitted->get_error_code(), 0, 'add' );
		}

		$breakpoints[ $key ] = array(
			'label'      => isset( $submitted['label'] ) ? $submitted['label'] : '',
			'min_width'  => $submitted['min_width'],
			'max_width'  => $submitted['max_width'],
			'is_default' => false,
		);

		$validated = $this->compiler->validate_breakpoints( $breakpoints );

		if ( is_wp_error( $validated ) ) {
			$this->redirect( 'validation_error', $validated->get_error_code(), 0, 'add' );
		}

		update_option( 'cssflow_breakpoints', $validated, false );
		$this->invalidate_output_and_redirect( 'added' );
	}

	/**
	 * Delete one unreferenced custom breakpoint.
	 *
	 * Default breakpoints can never be deleted. CSSFlow also protects custom
	 * breakpoints referenced by any stored snippet, including inactive snippets,
	 * so re-enabling an old snippet cannot reveal a broken reference later.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_delete_breakpoint() {
		$this->require_manage_options();

		$key = $this->get_submitted_key();

		if ( '' === $key ) {
			$this->redirect( 'invalid_request' );
		}

		check_admin_referer( 'cssflow_delete_breakpoint_' . $key, 'cssflow_nonce' );

		if ( in_array( $key, $this->default_keys, true ) ) {
			$this->redirect( 'default_delete_blocked' );
		}

		$breakpoints = $this->get_breakpoints();

		if ( is_wp_error( $breakpoints ) ) {
			$this->redirect( 'invalid_configuration', $breakpoints->get_error_code() );
		}

		if ( ! isset( $breakpoints[ $key ] ) ) {
			$this->redirect( 'not_found' );
		}

		$reference_count = $this->repository->count_breakpoint_references( $key );

		if ( $reference_count > 0 ) {
			$this->redirect( 'breakpoint_in_use', '', $reference_count, $key );
		}

		unset( $breakpoints[ $key ] );

		$validated = $this->compiler->validate_breakpoints( $breakpoints );

		if ( is_wp_error( $validated ) ) {
			$this->redirect( 'validation_error', $validated->get_error_code(), 0, $key );
		}

		update_option( 'cssflow_breakpoints', $validated, false );
		$this->invalidate_output_and_redirect( 'deleted' );
	}

	/**
	 * Render one editable breakpoint form.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key          Stable breakpoint key.
	 * @param array  $breakpoint   Normalized breakpoint definition.
	 * @param bool   $is_default   Whether this is a frozen default key.
	 * @return void
	 */
	private function render_breakpoint_form( $key, array $breakpoint, $is_default ) {
		$min_value       = null === $breakpoint['min_width'] ? '' : (string) $breakpoint['min_width'];
		$max_value       = null === $breakpoint['max_width'] ? '' : (string) $breakpoint['max_width'];
		$reference_count = $is_default ? 0 : $this->repository->count_breakpoint_references( $key );
		$icon            = $this->get_breakpoint_icon( $key, $is_default );
		?>
		<article class="cssflow-breakpoint-card<?php echo $is_default ? ' is-default' : ' is-custom'; ?>" id="cssflow-breakpoint-<?php echo esc_attr( $key ); ?>">
			<div class="cssflow-breakpoint-card-header">
				<div class="cssflow-breakpoint-card-title-wrap">
					<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
					<div>
						<div class="cssflow-breakpoint-title-row">
							<h3><?php echo esc_html( $breakpoint['label'] ); ?></h3>
							<?php if ( $is_default ) : ?>
								<span class="cssflow-breakpoint-badge"><?php esc_html_e( 'Built-in', 'cssflow' ); ?></span>
							<?php endif; ?>
						</div>
						<p class="cssflow-breakpoint-range-summary"><?php echo esc_html( $this->get_range_summary( $breakpoint ) ); ?></p>
					</div>
				</div>
				<code class="cssflow-breakpoint-key"><?php echo esc_html( $key ); ?></code>
			</div>

			<form class="cssflow-breakpoint-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cssflow_save_breakpoint" />
				<input type="hidden" name="breakpoint_key" value="<?php echo esc_attr( $key ); ?>" />
				<?php wp_nonce_field( 'cssflow_save_breakpoint_' . $key, 'cssflow_nonce' ); ?>

				<?php if ( ! $is_default ) : ?>
					<div class="cssflow-breakpoint-field cssflow-breakpoint-field-full">
						<label for="cssflow-label-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Name', 'cssflow' ); ?></label>
						<input type="text" id="cssflow-label-<?php echo esc_attr( $key ); ?>" name="label" value="<?php echo esc_attr( $breakpoint['label'] ); ?>" maxlength="191" required />
					</div>
				<?php endif; ?>

				<div class="cssflow-breakpoint-width-grid">
					<div class="cssflow-breakpoint-field">
						<label for="cssflow-min-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Minimum width', 'cssflow' ); ?></label>
						<div class="cssflow-breakpoint-unit-field">
							<input type="number" id="cssflow-min-<?php echo esc_attr( $key ); ?>" name="min_width" value="<?php echo esc_attr( $min_value ); ?>" min="0" max="65535" step="1" aria-describedby="cssflow-min-help-<?php echo esc_attr( $key ); ?>" />
							<span aria-hidden="true"><?php esc_html_e( 'px', 'cssflow' ); ?></span>
						</div>
						<p class="description" id="cssflow-min-help-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Leave empty for no minimum.', 'cssflow' ); ?></p>
					</div>

					<div class="cssflow-breakpoint-field">
						<label for="cssflow-max-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Maximum width', 'cssflow' ); ?></label>
						<div class="cssflow-breakpoint-unit-field">
							<input type="number" id="cssflow-max-<?php echo esc_attr( $key ); ?>" name="max_width" value="<?php echo esc_attr( $max_value ); ?>" min="0" max="65535" step="1" aria-describedby="cssflow-max-help-<?php echo esc_attr( $key ); ?>" />
							<span aria-hidden="true"><?php esc_html_e( 'px', 'cssflow' ); ?></span>
						</div>
						<p class="description" id="cssflow-max-help-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Leave empty for no maximum.', 'cssflow' ); ?></p>
					</div>
				</div>

				<div class="cssflow-breakpoint-card-actions">
					<?php submit_button( __( 'Save changes', 'cssflow' ), 'primary cssflow-button-primary', 'submit', false ); ?>
				</div>
			</form>

			<?php $this->render_inline_notice( $key ); ?>

			<?php if ( ! $is_default ) : ?>
				<div class="cssflow-breakpoint-card-footer">
					<div class="cssflow-breakpoint-usage<?php echo $reference_count > 0 ? ' is-used' : ''; ?>">
						<span class="dashicons <?php echo $reference_count > 0 ? 'dashicons-admin-links' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span>
						<span>
							<?php
							if ( $reference_count > 0 ) {
								printf(
									/* translators: %d: number of CSS snippets using a custom breakpoint. */
									esc_html( _n( 'Used by %d snippet', 'Used by %d snippets', $reference_count, 'cssflow' ) ),
									(int) $reference_count
								);
							} else {
								esc_html_e( 'Not used by any snippets', 'cssflow' );
							}
							?>
						</span>
					</div>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="cssflow_delete_breakpoint" />
						<input type="hidden" name="breakpoint_key" value="<?php echo esc_attr( $key ); ?>" />
						<?php wp_nonce_field( 'cssflow_delete_breakpoint_' . $key, 'cssflow_nonce' ); ?>
						<button type="submit" class="cssflow-breakpoint-delete"<?php disabled( $reference_count > 0 ); ?> aria-describedby="cssflow-delete-help-<?php echo esc_attr( $key ); ?>">
							<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Delete breakpoint', 'cssflow' ); ?></span>
						</button>
						<p class="screen-reader-text" id="cssflow-delete-help-<?php echo esc_attr( $key ); ?>">
							<?php
							if ( $reference_count > 0 ) {
								esc_html_e( 'Delete is unavailable while saved snippets reference this breakpoint.', 'cssflow' );
							} else {
								esc_html_e( 'Delete this custom breakpoint.', 'cssflow' );
							}
							?>
						</p>
					</form>
				</div>
			<?php endif; ?>
		</article>
		<?php
	}

	/**
	 * Render the custom-breakpoint creation form.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_add_form() {
		?>
		<div class="cssflow-breakpoint-add-card" id="cssflow-breakpoint-add">
			<div class="cssflow-breakpoint-add-header">
				<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
				<div>
					<h3><?php esc_html_e( 'Add custom breakpoint', 'cssflow' ); ?></h3>
					<p><?php esc_html_e( 'Create a named width range that can be selected from the Responsive target setting when editing a CSS snippet.', 'cssflow' ); ?></p>
				</div>
			</div>

			<form class="cssflow-breakpoint-add-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cssflow_add_breakpoint" />
				<?php wp_nonce_field( 'cssflow_add_breakpoint', 'cssflow_nonce' ); ?>

				<div class="cssflow-breakpoint-add-grid">
					<div class="cssflow-breakpoint-field">
						<label for="cssflow-new-label"><?php esc_html_e( 'Name', 'cssflow' ); ?></label>
						<input type="text" id="cssflow-new-label" name="label" maxlength="191" placeholder="<?php esc_attr_e( 'Large laptop', 'cssflow' ); ?>" required />
					</div>

					<div class="cssflow-breakpoint-field">
						<label for="cssflow-new-key"><?php esc_html_e( 'Key', 'cssflow' ); ?></label>
						<input type="text" id="cssflow-new-key" name="breakpoint_key" maxlength="100" pattern="[a-z0-9_-]+" placeholder="<?php esc_attr_e( 'large-laptop', 'cssflow' ); ?>" aria-describedby="cssflow-new-key-help" required />
						<p class="description" id="cssflow-new-key-help"><?php esc_html_e( 'Lowercase letters, numbers, hyphens, and underscores only. The key is permanent after creation.', 'cssflow' ); ?></p>
					</div>

					<div class="cssflow-breakpoint-field">
						<label for="cssflow-new-min"><?php esc_html_e( 'Minimum width', 'cssflow' ); ?></label>
						<div class="cssflow-breakpoint-unit-field">
							<input type="number" id="cssflow-new-min" name="min_width" min="0" max="65535" step="1" />
							<span aria-hidden="true"><?php esc_html_e( 'px', 'cssflow' ); ?></span>
						</div>
					</div>

					<div class="cssflow-breakpoint-field">
						<label for="cssflow-new-max"><?php esc_html_e( 'Maximum width', 'cssflow' ); ?></label>
						<div class="cssflow-breakpoint-unit-field">
							<input type="number" id="cssflow-new-max" name="max_width" min="0" max="65535" step="1" aria-describedby="cssflow-new-range-help" />
							<span aria-hidden="true"><?php esc_html_e( 'px', 'cssflow' ); ?></span>
						</div>
						<p class="description" id="cssflow-new-range-help"><?php esc_html_e( 'Enter a minimum width, maximum width, or both.', 'cssflow' ); ?></p>
					</div>
				</div>

				<div class="cssflow-breakpoint-card-actions">
					<?php submit_button( __( 'Add breakpoint', 'cssflow' ), 'primary cssflow-button-primary', 'submit', false ); ?>
				</div>
			</form>

			<?php $this->render_inline_notice( 'add' ); ?>
		</div>
		<?php
	}

	/**
	 * Return a concise readable width-range summary for a breakpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param array $breakpoint Normalized breakpoint definition.
	 * @return string Human-readable range summary.
	 */
	private function get_range_summary( array $breakpoint ) {
		$min = $breakpoint['min_width'];
		$max = $breakpoint['max_width'];

		if ( null === $min && null !== $max ) {
			return sprintf(
				/* translators: %d: maximum breakpoint width in pixels. */
				__( 'Up to %dpx', 'cssflow' ),
				(int) $max
			);
		}

		if ( null !== $min && null === $max ) {
			return sprintf(
				/* translators: %d: minimum breakpoint width in pixels. */
				__( '%dpx and above', 'cssflow' ),
				(int) $min
			);
		}

		return sprintf(
			/* translators: 1: minimum breakpoint width in pixels, 2: maximum breakpoint width in pixels. */
			__( '%1$dpx – %2$dpx', 'cssflow' ),
			(int) $min,
			(int) $max
		);
	}

	/**
	 * Return the Dashicon used for one breakpoint card.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key        Stable breakpoint key.
	 * @param bool   $is_default Whether this is a built-in breakpoint.
	 * @return string Dashicon class.
	 */
	private function get_breakpoint_icon( $key, $is_default ) {
		if ( ! $is_default ) {
			return 'dashicons-screenoptions';
		}

		$icons = array(
			'mobile'  => 'dashicons-smartphone',
			'tablet'  => 'dashicons-tablet',
			'desktop' => 'dashicons-desktop',
		);

		return isset( $icons[ $key ] ) ? $icons[ $key ] : 'dashicons-desktop';
	}

	/**
	 * Get and validate the currently stored breakpoint option.
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error Normalized breakpoints or validation error.
	 */
	private function get_breakpoints() {
		$breakpoints = get_option( 'cssflow_breakpoints', array() );

		if ( ! is_array( $breakpoints ) ) {
			return new WP_Error(
				'cssflow_invalid_breakpoints',
				__( 'The CSSFlow breakpoint configuration is invalid.', 'cssflow' )
			);
		}

		return $this->compiler->validate_breakpoints( $breakpoints );
	}

	/**
	 * Read one submitted breakpoint definition without trusting browser values.
	 *
	 * @since 1.0.0
	 *
	 * @param array $request Unslashed request data captured after nonce verification.
	 * @return array|WP_Error Submitted definition or request validation error.
	 */
	private function get_submitted_definition( $request ) {
		$label = '';

		if ( isset( $request['label'] ) ) {
			if ( ! is_scalar( $request['label'] ) ) {
				return new WP_Error( 'cssflow_invalid_breakpoint_label' );
			}

			$label = sanitize_text_field( (string) $request['label'] );

			if ( strlen( $label ) > 191 ) {
				return new WP_Error( 'cssflow_invalid_breakpoint_label' );
			}
		}

		$min_width = $this->get_submitted_width( 'min_width', $request );

		if ( is_wp_error( $min_width ) ) {
			return $min_width;
		}

		$max_width = $this->get_submitted_width( 'max_width', $request );

		if ( is_wp_error( $max_width ) ) {
			return $max_width;
		}

		return array(
			'label'     => $label,
			'min_width' => $min_width,
			'max_width' => $max_width,
		);
	}

	/**
	 * Read one submitted width while preserving invalid scalar data for the
	 * compiler's authoritative validation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $field   Request field name.
	 * @param array  $request Unslashed request data captured after nonce verification.
	 * @return string|null|WP_Error Submitted width, null for empty, or error.
	 */
	private function get_submitted_width( $field, $request ) {
		if ( ! isset( $request[ $field ] ) ) {
			return null;
		}

		if ( ! is_scalar( $request[ $field ] ) ) {
			return new WP_Error( 'cssflow_invalid_breakpoint_width' );
		}

		$value = trim( (string) $request[ $field ] );

		return '' === $value ? null : $value;
	}

	/**
	 * Read and strictly validate a submitted existing breakpoint key.
	 *
	 * @since 1.0.0
	 *
	 * @return string Sanitized stable key, or an empty string when invalid.
	 */
	private function get_submitted_key() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The stable key must be strictly normalized before it can be used to construct the record-specific nonce action; no mutation occurs before check_admin_referer().
		if ( ! isset( $_POST['breakpoint_key'] ) || ! is_scalar( $_POST['breakpoint_key'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This strictly validated key is needed to construct the record-specific nonce action; no mutation occurs before nonce verification.
		$raw_key = trim( (string) wp_unslash( $_POST['breakpoint_key'] ) );
		$key     = sanitize_key( $raw_key );

		if ( '' === $raw_key || strlen( $raw_key ) > 100 || $raw_key !== $key ) {
			return '';
		}

		return $key;
	}

	/**
	 * Invalidate disposable generated output after a successful configuration save.
	 *
	 * @since 1.0.0
	 *
	 * @param string $success_notice Notice code for a successful save.
	 * @return void
	 */
	private function invalidate_output_and_redirect( $success_notice ) {
		$regenerated = $this->output_manager->regenerate();

		if ( is_wp_error( $regenerated ) ) {
			$this->redirect( 'cache_warning' );
		}

		$this->redirect( $success_notice );
	}

	/**
	 * Require settings-level authorization for breakpoint changes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function require_manage_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage CSSFlow breakpoints.', 'cssflow' ),
				esc_html__( 'CSSFlow', 'cssflow' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Redirect back to the Breakpoints screen with a sanitized notice code.
	 *
	 * Error redirects can include a target so the message appears inside the
	 * affected breakpoint card and the browser returns directly to that card.
	 *
	 * @since 1.0.0
	 *
	 * @param string $notice Notice code.
	 * @param string $error  Optional error code.
	 * @param int    $count  Optional reference count.
	 * @param string $target Optional breakpoint key or "add".
	 * @return void
	 */
	private function redirect( $notice, $error = '', $count = 0, $target = '' ) {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			set_transient(
				'cssflow_breakpoint_notice_' . $user_id,
				array(
					'notice' => sanitize_key( $notice ),
					'error'  => sanitize_key( $error ),
					'count'  => absint( $count ),
					'target' => sanitize_key( $target ),
				),
				MINUTE_IN_SECONDS
			);
		}

		$url = admin_url( 'admin.php?page=cssflow-breakpoints' );

		if ( 'add' === $target ) {
			$url .= '#cssflow-breakpoint-add';
		} elseif ( '' !== $target ) {
			$url .= '#cssflow-breakpoint-' . rawurlencode( sanitize_key( $target ) );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render page-level notices using only allowlisted messages.
	 *
	 * Validation and breakpoint-specific errors are rendered inline inside the
	 * affected card instead of at the top of the page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_notice() {
		$notice = $this->get_notice_code();
		$target = $this->get_notice_target();

		if ( '' !== $target && $this->is_inline_notice( $notice ) ) {
			return;
		}

		$messages = array(
			'saved'                  => array( 'success', __( 'Breakpoint updated successfully.', 'cssflow' ) ),
			'added'                  => array( 'success', __( 'Custom breakpoint added successfully.', 'cssflow' ) ),
			'deleted'                => array( 'success', __( 'Custom breakpoint deleted successfully.', 'cssflow' ) ),
			'no_changes'             => array( 'info', __( 'No changes were made.', 'cssflow' ) ),
			'cache_warning'          => array( 'warning', __( 'The breakpoint was saved, but CSSFlow could not refresh the generated CSS files. Please check your site files and try again.', 'cssflow' ) ),
			'invalid_request'        => array( 'error', __( 'Something was wrong with this request. Please try again.', 'cssflow' ) ),
			'invalid_configuration'  => array( 'error', __( 'The current breakpoint settings are invalid and cannot be edited safely.', 'cssflow' ) ),
			'not_found'              => array( 'error', __( 'This breakpoint could not be found.', 'cssflow' ) ),
			'duplicate_key'          => array( 'error', __( 'This breakpoint key is already in use. Please choose another key.', 'cssflow' ) ),
			'default_delete_blocked' => array( 'error', __( 'The Mobile, Tablet, and Desktop breakpoints cannot be deleted.', 'cssflow' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			$this->print_notice( $messages[ $notice ][0], $messages[ $notice ][1] );
		}
	}

	/**
	 * Render an error directly inside the affected breakpoint card.
	 *
	 * @since 1.0.0
	 *
	 * @param string $target Breakpoint key or "add".
	 * @return void
	 */
	private function render_inline_notice( $target ) {
		$notice        = $this->get_notice_code();
		$notice_target = $this->get_notice_target();

		if ( $target !== $notice_target || ! $this->is_inline_notice( $notice ) ) {
			return;
		}

		$flash = $this->get_flash_notice();
		$error = is_array( $flash ) && isset( $flash['error'] ) ? sanitize_key( (string) $flash['error'] ) : '';
		$count = is_array( $flash ) && isset( $flash['count'] ) ? absint( $flash['count'] ) : 0;

		if ( 'breakpoint_in_use' === $notice ) {
			$message = sprintf(
				/* translators: %d: number of snippets using the breakpoint. */
				_n(
					'This breakpoint is currently being used by %d CSS snippet. Remove it from the snippet before deleting the breakpoint.',
					'This breakpoint is currently being used by %d CSS snippets. Remove it from those snippets before deleting the breakpoint.',
					$count,
					'cssflow'
				),
				$count
			);

			$this->print_inline_notice( $message );
			return;
		}

		if ( 'duplicate_key' === $notice ) {
			$this->print_inline_notice( __( 'This breakpoint key is already in use. Please choose another key.', 'cssflow' ) );
			return;
		}

		if ( 'validation_error' === $notice ) {
			$validation_messages = array(
				'cssflow_invalid_breakpoint_key'   => __( 'Use only lowercase letters, numbers, hyphens, and underscores for the key.', 'cssflow' ),
				'cssflow_invalid_breakpoint_label' => __( 'Please enter a breakpoint name.', 'cssflow' ),
				'cssflow_invalid_breakpoint_width' => __( 'Widths must be whole numbers from 0 to 65535, or left empty.', 'cssflow' ),
				'cssflow_breakpoint_without_range' => __( 'Enter a minimum width, maximum width, or both.', 'cssflow' ),
				'cssflow_invalid_breakpoint_range' => __( 'Minimum width cannot be greater than maximum width.', 'cssflow' ),
			);

			$message = isset( $validation_messages[ $error ] )
				? $validation_messages[ $error ]
				: __( 'These breakpoint values are not valid. Please check them and try again.', 'cssflow' );

			$this->print_inline_notice( $message );
		}
	}

	/**
	 * Get the current notice code from the request.
	 *
	 * @since 1.0.0
	 *
	 * @return string Notice code.
	 */
	private function get_notice_code() {
		$flash = $this->get_flash_notice();

		return is_array( $flash ) && isset( $flash['notice'] ) ? sanitize_key( (string) $flash['notice'] ) : '';
	}

	/**
	 * Get the current inline notice target from the request.
	 *
	 * @since 1.0.0
	 *
	 * @return string Breakpoint key, "add", or empty string.
	 */
	private function get_notice_target() {
		$flash = $this->get_flash_notice();

		return is_array( $flash ) && isset( $flash['target'] ) ? sanitize_key( (string) $flash['target'] ) : '';
	}

	/**
	 * Get and consume the current user's one-time Breakpoints notice.
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

		$this->flash_notice = get_transient( 'cssflow_breakpoint_notice_' . $user_id );
		delete_transient( 'cssflow_breakpoint_notice_' . $user_id );

		if ( ! is_array( $this->flash_notice ) ) {
			$this->flash_notice = false;
		}

		return $this->flash_notice;
	}

	/**
	 * Determine whether a notice belongs inside a breakpoint card.
	 *
	 * @since 1.0.0
	 *
	 * @param string $notice Notice code.
	 * @return bool
	 */
	private function is_inline_notice( $notice ) {
		return in_array(
			$notice,
			array(
				'validation_error',
				'duplicate_key',
				'breakpoint_in_use',
			),
			true
		);
	}

	/**
	 * Print one inline error notice inside a breakpoint card.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Notice message.
	 * @return void
	 */
	private function print_inline_notice( $message ) {
		?>
		<div class="notice notice-error inline">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Print one WordPress admin notice.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 * @return void
	 */
	private function print_notice( $type, $message ) {
		$allowed = array( 'success', 'warning', 'error', 'info' );
		$type    = in_array( $type, $allowed, true ) ? $type : 'info';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}
}
