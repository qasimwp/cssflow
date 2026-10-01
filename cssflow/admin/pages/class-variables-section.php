<?php
/**
 * Renders and processes the CSSFlow Variables administration page.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Admin\Pages;

use CSSFlow\CSS\Output_Manager;
use CSSFlow\CSS\Variables;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSS Variables administration page controller.
 */
final class Variables_Section {

	/**
	 * Variables service.
	 *
	 * @var Variables
	 */
	private $variables;

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
	 * @param Variables      $variables      Variables service.
	 * @param Output_Manager $output_manager Output manager.
	 */
	public function __construct( Variables $variables, Output_Manager $output_manager ) {
		$this->variables      = $variables;
		$this->output_manager = $output_manager;
	}

	/**
	 * Register the Variables screen and state-changing actions.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_cssflow_add_variable', array( $this, 'handle_add_variable' ) );
		add_action( 'admin_post_cssflow_save_variable', array( $this, 'handle_save_variable' ) );
		add_action( 'admin_post_cssflow_delete_variable', array( $this, 'handle_delete_variable' ) );
	}

	/**
	 * Register the dedicated Variables submenu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'cssflow',
			__( 'Variables', 'cssflow' ),
			__( 'Variables', 'cssflow' ),
			'manage_options',
			'cssflow-variables',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the CSS Variables administration page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render() {
		$this->require_manage_options();

		$variables = $this->variables->get_all();
		?>
		<div class="wrap cssflow-variables-page">
			<hr class="wp-header-end" />
			<div class="cssflow-variables-shell">
				<header class="cssflow-variables-header">
					<h1><?php esc_html_e( 'Variables', 'cssflow' ); ?></h1>
					<p><?php esc_html_e( 'Create reusable global CSS values once, then use them across your CSSFlow snippets.', 'cssflow' ); ?></p>
				</header>

				<?php $this->render_notice(); ?>

				<div class="cssflow-variables-intro" role="note">
					<span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
					<div>
						<strong><?php esc_html_e( 'How variables work', 'cssflow' ); ?></strong>
						<p>
							<?php esc_html_e( 'Save a value such as a brand color or spacing size, then reference it in a snippet with', 'cssflow' ); ?>
							<code>var(--brand-color)</code>.
						</p>
					</div>
				</div>

				<?php if ( is_wp_error( $variables ) ) : ?>
					<div class="notice notice-error">
						<p><?php esc_html_e( 'The stored CSS Variables configuration is invalid and cannot be edited safely from this screen.', 'cssflow' ); ?></p>
					</div>
				<?php else : ?>
					<section class="cssflow-variables-section" aria-labelledby="cssflow-add-variable-heading">
						<div class="cssflow-variables-section-heading">
							<div>
								<h2 id="cssflow-add-variable-heading"><?php esc_html_e( 'Add variable', 'cssflow' ); ?></h2>
								<p><?php esc_html_e( 'Add a reusable global value. CSSFlow automatically adds the leading -- to the variable name.', 'cssflow' ); ?></p>
							</div>
						</div>

						<?php $this->render_add_form(); ?>
					</section>

					<section class="cssflow-variables-section" aria-labelledby="cssflow-saved-variables-heading">
						<div class="cssflow-variables-section-heading">
							<div>
								<h2 id="cssflow-saved-variables-heading"><?php esc_html_e( 'Saved variables', 'cssflow' ); ?></h2>
								<p><?php esc_html_e( 'Edit existing values or copy the variable name into any CSSFlow snippet.', 'cssflow' ); ?></p>
							</div>
						</div>

						<?php if ( empty( $variables ) ) : ?>
							<div class="cssflow-variables-empty-state">
								<span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
								<strong><?php esc_html_e( 'No saved variables yet', 'cssflow' ); ?></strong>
								<p><?php esc_html_e( 'Create your first variable above and it will appear here automatically.', 'cssflow' ); ?></p>
							</div>
						<?php else : ?>
							<div class="cssflow-variables-grid">
								<?php foreach ( $variables as $index => $variable ) : ?>
									<?php $this->render_variable_form( $index, $variable ); ?>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</section>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Add a new CSS variable.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_add_variable() {
		$this->require_manage_options();
		check_admin_referer( 'cssflow_add_variable', 'cssflow_nonce' );

		$variables = $this->variables->get_all();

		if ( is_wp_error( $variables ) ) {
			$this->redirect( 'invalid_configuration' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability verified above; Variables service performs authoritative normalization and validation.
		$request   = wp_unslash( $_POST );
		$submitted = $this->get_submitted_variable( $request );

		if ( is_wp_error( $submitted ) ) {
			$this->redirect( 'validation_error', $submitted->get_error_code(), 'add' );
		}

		foreach ( $variables as $variable ) {
			if ( $variable['name'] === $submitted['name'] ) {
				$this->redirect( 'duplicate_name', '', 'add' );
			}
		}

		$variables[] = $submitted;
		$validated   = $this->variables->validate_all( $variables );

		if ( is_wp_error( $validated ) ) {
			$this->redirect( 'validation_error', $validated->get_error_code(), 'add' );
		}

		update_option( Variables::OPTION_NAME, $validated, false );
		$this->invalidate_output_and_redirect( 'added' );
	}

	/**
	 * Save an existing CSS variable.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_save_variable() {
		$this->require_manage_options();

		$index = $this->get_submitted_index();

		if ( null === $index ) {
			$this->redirect( 'invalid_request' );
		}

		check_admin_referer( 'cssflow_save_variable_' . $index, 'cssflow_nonce' );

		$variables = $this->variables->get_all();

		if ( is_wp_error( $variables ) ) {
			$this->redirect( 'invalid_configuration' );
		}

		if ( ! isset( $variables[ $index ] ) ) {
			$this->redirect( 'not_found' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability verified above; fields are normalized/validated before persistence.
		$request       = wp_unslash( $_POST );
		$original_name = $this->get_submitted_original_name( $request );

		if ( '' === $original_name || $variables[ $index ]['name'] !== $original_name ) {
			$this->redirect( 'invalid_request' );
		}

		$submitted = $this->get_submitted_variable( $request );

		if ( is_wp_error( $submitted ) ) {
			$this->redirect( 'validation_error', $submitted->get_error_code(), (string) $index );
		}

		foreach ( $variables as $other_index => $variable ) {
			if ( $other_index !== $index && $variable['name'] === $submitted['name'] ) {
				$this->redirect( 'duplicate_name', '', (string) $index );
			}
		}

		$current             = $variables;
		$variables[ $index ] = $submitted;
		$validated           = $this->variables->validate_all( $variables );

		if ( is_wp_error( $validated ) ) {
			$this->redirect( 'validation_error', $validated->get_error_code(), (string) $index );
		}

		if ( $validated === $current ) {
			$this->redirect( 'no_changes' );
		}

		update_option( Variables::OPTION_NAME, $validated, false );
		$this->invalidate_output_and_redirect( 'saved' );
	}

	/**
	 * Delete an existing CSS variable.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_delete_variable() {
		$this->require_manage_options();

		$index = $this->get_submitted_index();

		if ( null === $index ) {
			$this->redirect( 'invalid_request' );
		}

		check_admin_referer( 'cssflow_delete_variable_' . $index, 'cssflow_nonce' );

		$variables = $this->variables->get_all();

		if ( is_wp_error( $variables ) ) {
			$this->redirect( 'invalid_configuration' );
		}

		if ( ! isset( $variables[ $index ] ) ) {
			$this->redirect( 'not_found' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability verified above; fields are normalized/validated before persistence.
		$request       = wp_unslash( $_POST );
		$original_name = $this->get_submitted_original_name( $request );

		if ( '' === $original_name || $variables[ $index ]['name'] !== $original_name ) {
			$this->redirect( 'invalid_request' );
		}

		unset( $variables[ $index ] );
		$variables = array_values( $variables );
		$validated = $this->variables->validate_all( $variables );

		if ( is_wp_error( $validated ) ) {
			$this->redirect( 'invalid_configuration' );
		}

		update_option( Variables::OPTION_NAME, $validated, false );
		$this->invalidate_output_and_redirect( 'deleted' );
	}

	/**
	 * Render one editable variable card.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $index    Stored array index.
	 * @param array $variable Normalized variable.
	 * @return void
	 */
	private function render_variable_form( $index, array $variable ) {
		$title        = '' !== $variable['label'] ? $variable['label'] : '--' . $variable['name'];
		$swatch_color = $this->get_color_swatch_value( $variable['value'] );
		$swatch_title = '';

		if ( '' !== $swatch_color ) {
			/* translators: %s: CSS color value. */
			$swatch_title = sprintf( __( 'Color: %s', 'cssflow' ), $swatch_color );
		}
		?>
		<article class="cssflow-variable-card" id="cssflow-variable-<?php echo esc_attr( (string) $index ); ?>">
			<header class="cssflow-variable-card-header">
				<div class="cssflow-variable-card-title-wrap">
					<span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
					<div>
						<h3><?php echo esc_html( $title ); ?></h3>
						<div class="cssflow-variable-meta">
							<code class="cssflow-variable-name"><?php echo esc_html( '--' . $variable['name'] ); ?></code>
							<?php if ( '' !== $swatch_color ) : ?>
								<span
									class="cssflow-variable-color-swatch"
									style="<?php echo esc_attr( '--cssflow-variable-swatch-color: ' . $swatch_color . ';' ); ?>"
									title="<?php echo esc_attr( $swatch_title ); ?>"
									aria-hidden="true"
								></span>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<div class="cssflow-variable-usage">
					<span><?php esc_html_e( 'Use as', 'cssflow' ); ?></span>
					<code><?php echo esc_html( 'var(--' . $variable['name'] . ')' ); ?></code>
				</div>
			</header>

			<form class="cssflow-variable-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cssflow_save_variable" />
				<input type="hidden" name="variable_index" value="<?php echo esc_attr( (string) $index ); ?>" />
				<input type="hidden" name="original_name" value="<?php echo esc_attr( $variable['name'] ); ?>" />
				<?php wp_nonce_field( 'cssflow_save_variable_' . $index, 'cssflow_nonce' ); ?>

				<?php $this->render_variable_fields( $variable, 'cssflow-variable-' . $index ); ?>

				<div class="cssflow-variable-card-actions">
					<?php submit_button( __( 'Save Variable', 'cssflow' ), 'primary', 'submit', false ); ?>
				</div>
			</form>

			<?php $this->render_inline_notice( (string) $index ); ?>

			<footer class="cssflow-variable-card-footer">
				<p><?php esc_html_e( 'Deleting removes this global variable from future generated CSS.', 'cssflow' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cssflow_delete_variable" />
					<input type="hidden" name="variable_index" value="<?php echo esc_attr( (string) $index ); ?>" />
					<input type="hidden" name="original_name" value="<?php echo esc_attr( $variable['name'] ); ?>" />
					<?php wp_nonce_field( 'cssflow_delete_variable_' . $index, 'cssflow_nonce' ); ?>
					<button type="submit" class="cssflow-variable-delete">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Delete', 'cssflow' ); ?></span>
					</button>
				</form>
			</footer>
		</article>
		<?php
	}


	/**
	 * Return a safe direct CSS color value for the visual swatch.
	 *
	 * The stored CSS variable value remains authoritative. This helper is
	 * intentionally conservative and returns an empty string for values that are
	 * not confidently identifiable as a direct color.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Stored variable value.
	 * @return string Validated color value or an empty string.
	 */
	private function get_color_swatch_value( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || 128 < strlen( $value ) ) {
			return '';
		}

		// Never allow declaration-breaking characters into the inline custom property.
		if ( preg_match( '/[;{}<>"\']/', $value ) ) {
			return '';
		}

		if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
			return $value;
		}

		if ( $this->is_named_color( $value ) ) {
			return strtolower( $value );
		}

		if ( $this->is_rgb_color( $value ) || $this->is_hsl_color( $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Check a CSS named color against the standard keyword allowlist.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Candidate color value.
	 * @return bool Whether the value is a supported named color.
	 */
	private function is_named_color( $value ) {
		$colors = array(
			'aliceblue',
			'antiquewhite',
			'aqua',
			'aquamarine',
			'azure',
			'beige',
			'bisque',
			'black',
			'blanchedalmond',
			'blue',
			'blueviolet',
			'brown',
			'burlywood',
			'cadetblue',
			'chartreuse',
			'chocolate',
			'coral',
			'cornflowerblue',
			'cornsilk',
			'crimson',
			'cyan',
			'darkblue',
			'darkcyan',
			'darkgoldenrod',
			'darkgray',
			'darkgreen',
			'darkgrey',
			'darkkhaki',
			'darkmagenta',
			'darkolivegreen',
			'darkorange',
			'darkorchid',
			'darkred',
			'darksalmon',
			'darkseagreen',
			'darkslateblue',
			'darkslategray',
			'darkslategrey',
			'darkturquoise',
			'darkviolet',
			'deeppink',
			'deepskyblue',
			'dimgray',
			'dimgrey',
			'dodgerblue',
			'firebrick',
			'floralwhite',
			'forestgreen',
			'fuchsia',
			'gainsboro',
			'ghostwhite',
			'gold',
			'goldenrod',
			'gray',
			'green',
			'greenyellow',
			'grey',
			'honeydew',
			'hotpink',
			'indianred',
			'indigo',
			'ivory',
			'khaki',
			'lavender',
			'lavenderblush',
			'lawngreen',
			'lemonchiffon',
			'lightblue',
			'lightcoral',
			'lightcyan',
			'lightgoldenrodyellow',
			'lightgray',
			'lightgreen',
			'lightgrey',
			'lightpink',
			'lightsalmon',
			'lightseagreen',
			'lightskyblue',
			'lightslategray',
			'lightslategrey',
			'lightsteelblue',
			'lightyellow',
			'lime',
			'limegreen',
			'linen',
			'magenta',
			'maroon',
			'mediumaquamarine',
			'mediumblue',
			'mediumorchid',
			'mediumpurple',
			'mediumseagreen',
			'mediumslateblue',
			'mediumspringgreen',
			'mediumturquoise',
			'mediumvioletred',
			'midnightblue',
			'mintcream',
			'mistyrose',
			'moccasin',
			'navajowhite',
			'navy',
			'oldlace',
			'olive',
			'olivedrab',
			'orange',
			'orangered',
			'orchid',
			'palegoldenrod',
			'palegreen',
			'paleturquoise',
			'palevioletred',
			'papayawhip',
			'peachpuff',
			'peru',
			'pink',
			'plum',
			'powderblue',
			'purple',
			'rebeccapurple',
			'red',
			'rosybrown',
			'royalblue',
			'saddlebrown',
			'salmon',
			'sandybrown',
			'seagreen',
			'seashell',
			'sienna',
			'silver',
			'skyblue',
			'slateblue',
			'slategray',
			'slategrey',
			'snow',
			'springgreen',
			'steelblue',
			'tan',
			'teal',
			'thistle',
			'tomato',
			'turquoise',
			'violet',
			'wheat',
			'white',
			'whitesmoke',
			'yellow',
			'yellowgreen',
		);

		return in_array( strtolower( $value ), $colors, true );
	}

	/**
	 * Check supported direct rgb()/rgba() color syntax.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Candidate color value.
	 * @return bool Whether the value is a supported RGB color.
	 */
	private function is_rgb_color( $value ) {
		$number  = '[+-]?(?:\d+(?:\.\d+)?|\.\d+)';
		$channel = $number . '%?';
		$alpha   = $number . '%?';

		$legacy_rgb  = '/^rgb\(\s*' . $channel . '\s*,\s*' . $channel . '\s*,\s*' . $channel . '\s*\)$/i';
		$legacy_rgba = '/^rgba\(\s*' . $channel . '\s*,\s*' . $channel . '\s*,\s*' . $channel . '\s*,\s*' . $alpha . '\s*\)$/i';
		$modern_rgb  = '/^rgba?\(\s*' . $channel . '\s+' . $channel . '\s+' . $channel . '(?:\s*\/\s*' . $alpha . ')?\s*\)$/i';

		return 1 === preg_match( $legacy_rgb, $value )
			|| 1 === preg_match( $legacy_rgba, $value )
			|| 1 === preg_match( $modern_rgb, $value );
	}

	/**
	 * Check supported direct hsl()/hsla() color syntax.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Candidate color value.
	 * @return bool Whether the value is a supported HSL color.
	 */
	private function is_hsl_color( $value ) {
		$number     = '[+-]?(?:\d+(?:\.\d+)?|\.\d+)';
		$hue        = $number . '(?:deg|grad|rad|turn)?';
		$percentage = $number . '%';
		$alpha      = $number . '%?';

		$legacy_hsl  = '/^hsl\(\s*' . $hue . '\s*,\s*' . $percentage . '\s*,\s*' . $percentage . '\s*\)$/i';
		$legacy_hsla = '/^hsla\(\s*' . $hue . '\s*,\s*' . $percentage . '\s*,\s*' . $percentage . '\s*,\s*' . $alpha . '\s*\)$/i';
		$modern_hsl  = '/^hsla?\(\s*' . $hue . '\s+' . $percentage . '\s+' . $percentage . '(?:\s*\/\s*' . $alpha . ')?\s*\)$/i';

		return 1 === preg_match( $legacy_hsl, $value )
			|| 1 === preg_match( $legacy_hsla, $value )
			|| 1 === preg_match( $modern_hsl, $value );
	}

	/**
	 * Render the Add CSS Variable form.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_add_form() {
		$defaults = array(
			'name'       => '',
			'label'      => '',
			'value'      => '',
			'sort_order' => 10,
		);
		?>
		<div class="cssflow-variable-add-card" id="cssflow-variable-add">
			<div class="cssflow-variable-add-header">
				<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
				<div>
					<h3><?php esc_html_e( 'New variable', 'cssflow' ); ?></h3>
					<p><?php esc_html_e( 'Use a short CSS-friendly name, an optional friendly label, and the value you want to reuse.', 'cssflow' ); ?></p>
				</div>
			</div>

			<form class="cssflow-variable-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cssflow_add_variable" />
				<?php wp_nonce_field( 'cssflow_add_variable', 'cssflow_nonce' ); ?>

				<?php $this->render_variable_fields( $defaults, 'cssflow-variable-new' ); ?>

				<div class="cssflow-variable-card-actions">
					<?php submit_button( __( 'Add Variable', 'cssflow' ), 'primary', 'submit', false ); ?>
				</div>
			</form>

			<?php $this->render_inline_notice( 'add' ); ?>
		</div>
		<?php
	}

	/**
	 * Render shared variable form fields.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $variable Normalized/current variable data.
	 * @param string $id_base  Unique field ID prefix.
	 * @return void
	 */
	private function render_variable_fields( array $variable, $id_base ) {
		?>
		<div class="cssflow-variable-fields">
			<div class="cssflow-variable-field">
				<label for="<?php echo esc_attr( $id_base . '-name' ); ?>"><?php esc_html_e( 'Variable name', 'cssflow' ); ?></label>
				<div class="cssflow-variable-name-field">
					<span aria-hidden="true">--</span>
					<input type="text" id="<?php echo esc_attr( $id_base . '-name' ); ?>" name="variable_name" value="<?php echo esc_attr( $variable['name'] ); ?>" maxlength="191" pattern="[A-Za-z_][A-Za-z0-9_-]*" required />
				</div>
				<p class="description"><?php esc_html_e( 'Example: brand-color', 'cssflow' ); ?></p>
			</div>

			<div class="cssflow-variable-field">
				<label for="<?php echo esc_attr( $id_base . '-label' ); ?>"><?php esc_html_e( 'Label', 'cssflow' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $id_base . '-label' ); ?>" name="variable_label" value="<?php echo esc_attr( $variable['label'] ); ?>" maxlength="191" />
				<p class="description"><?php esc_html_e( 'Optional friendly name, such as Brand Color.', 'cssflow' ); ?></p>
			</div>

			<div class="cssflow-variable-field cssflow-variable-field-value">
				<label for="<?php echo esc_attr( $id_base . '-value' ); ?>"><?php esc_html_e( 'Value', 'cssflow' ); ?></label>
				<textarea class="code" rows="2" id="<?php echo esc_attr( $id_base . '-value' ); ?>" name="variable_value" required><?php echo esc_textarea( $variable['value'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Examples: #2271b1, 1200px, 1rem, var(--other-value), or a valid CSS function.', 'cssflow' ); ?></p>
			</div>

			<div class="cssflow-variable-field cssflow-variable-field-order">
				<label for="<?php echo esc_attr( $id_base . '-sort-order' ); ?>"><?php esc_html_e( 'Sort order', 'cssflow' ); ?></label>
				<input type="number" id="<?php echo esc_attr( $id_base . '-sort-order' ); ?>" name="sort_order" value="<?php echo esc_attr( (string) $variable['sort_order'] ); ?>" min="0" max="65535" step="1" required />
				<p class="description"><?php esc_html_e( 'Lower numbers compile first. Equal numbers keep their saved order.', 'cssflow' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Read and normalize one submitted variable using the Variables service.
	 *
	 * @since 1.0.0
	 *
	 * @param array $request Unslashed request data captured after nonce verification.
	 * @return array|WP_Error Normalized variable or validation error.
	 */
	private function get_submitted_variable( $request ) {
		$name       = isset( $request['variable_name'] ) && is_scalar( $request['variable_name'] )
			? (string) $request['variable_name']
			: '';
		$label      = isset( $request['variable_label'] ) && is_scalar( $request['variable_label'] )
			? (string) $request['variable_label']
			: '';
		$value      = isset( $request['variable_value'] ) && is_scalar( $request['variable_value'] )
			? (string) $request['variable_value']
			: '';
		$sort_order = isset( $request['sort_order'] ) && is_scalar( $request['sort_order'] )
			? (string) $request['sort_order']
			: '';

		return $this->variables->normalize_variable(
			array(
				'name'       => $name,
				'label'      => $label,
				'value'      => $value,
				'sort_order' => $sort_order,
			)
		);
	}

	/**
	 * Read a submitted variable array index without trusting hidden input.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null Valid non-negative index or null.
	 */
	private function get_submitted_index() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Index must be validated before constructing the record-specific nonce action; only digits are accepted and no mutation occurs before nonce verification.
		if ( ! isset( $_POST['variable_index'] ) || ! is_scalar( $_POST['variable_index'] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This digits-only index is needed to construct the record-specific nonce action; no mutation occurs before nonce verification.
		$raw = trim( (string) wp_unslash( $_POST['variable_index'] ) );

		if ( ! preg_match( '/^\d+$/', $raw ) ) {
			return null;
		}

		return (int) $raw;
	}

	/**
	 * Read the submitted original variable name for stale/tampered-form checks.
	 *
	 * @since 1.0.0
	 *
	 * @param array $request Unslashed request data captured after nonce verification.
	 * @return string Submitted original name or empty string.
	 */
	private function get_submitted_original_name( $request ) {
		if ( ! isset( $request['original_name'] ) || ! is_scalar( $request['original_name'] ) ) {
			return '';
		}

		return trim( (string) $request['original_name'] );
	}

	/**
	 * Invalidate disposable output after a successful variable mutation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $success_notice Success notice code.
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
	 * Require settings-level authorization for the global variables manager.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function require_manage_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage CSSFlow CSS Variables.', 'cssflow' ),
				esc_html__( 'CSSFlow', 'cssflow' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Redirect back to the Variables page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $notice Notice code.
	 * @param string $error  Optional validation error code.
	 * @param string $target Optional variable index or "add".
	 * @return void
	 */
	private function redirect( $notice, $error = '', $target = '' ) {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			set_transient(
				'cssflow_variable_notice_' . $user_id,
				array(
					'notice' => sanitize_key( $notice ),
					'error'  => sanitize_key( $error ),
					'target' => sanitize_key( $target ),
				),
				MINUTE_IN_SECONDS
			);
		}

		$url = admin_url( 'admin.php?page=cssflow-variables' );

		if ( 'add' === $target ) {
			$url .= '#cssflow-variable-add';
		} elseif ( '' !== $target ) {
			$url .= '#cssflow-variable-' . rawurlencode( sanitize_key( $target ) );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render page-level CSS Variables notices.
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
			'saved'                 => array( 'success', __( 'CSS variable updated successfully.', 'cssflow' ) ),
			'added'                 => array( 'success', __( 'CSS variable added successfully.', 'cssflow' ) ),
			'deleted'               => array( 'success', __( 'CSS variable deleted successfully.', 'cssflow' ) ),
			'no_changes'            => array( 'info', __( 'No changes were made.', 'cssflow' ) ),
			'cache_warning'         => array( 'warning', __( 'The CSS variable was saved, but CSSFlow could not refresh the generated CSS files. Please check your site files and try again.', 'cssflow' ) ),
			'invalid_request'       => array( 'error', __( 'Something was wrong with this CSS variable request. Please try again.', 'cssflow' ) ),
			'invalid_configuration' => array( 'error', __( 'The current CSS Variables settings are invalid and cannot be edited safely.', 'cssflow' ) ),
			'not_found'             => array( 'error', __( 'This CSS variable could not be found.', 'cssflow' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			$this->print_notice( $messages[ $notice ][0], $messages[ $notice ][1] );
		}
	}

	/**
	 * Render an error inside the affected variable card.
	 *
	 * @since 1.0.0
	 *
	 * @param string $target Variable index or "add".
	 * @return void
	 */
	private function render_inline_notice( $target ) {
		$notice        = $this->get_notice_code();
		$notice_target = $this->get_notice_target();

		if ( $target !== $notice_target || ! $this->is_inline_notice( $notice ) ) {
			return;
		}

		if ( 'duplicate_name' === $notice ) {
			$this->print_inline_notice( __( 'This variable name is already in use. Please choose another name.', 'cssflow' ) );
			return;
		}

		$flash = $this->get_flash_notice();
		$error = is_array( $flash ) && isset( $flash['error'] ) ? sanitize_key( (string) $flash['error'] ) : '';

		$messages = array(
			'cssflow_invalid_variable_name'       => __( 'Use a valid variable name with letters, numbers, hyphens, and underscores.', 'cssflow' ),
			'cssflow_variable_name_has_prefix'    => __( 'Enter the variable name without --. CSSFlow adds it automatically.', 'cssflow' ),
			'cssflow_invalid_variable_label'      => __( 'The variable label is too long.', 'cssflow' ),
			'cssflow_invalid_variable_value'      => __( 'Enter a valid CSS value. Check quotes, brackets, and extra semicolons.', 'cssflow' ),
			'cssflow_variable_value_too_large'    => __( 'This CSS variable value is too large.', 'cssflow' ),
			'cssflow_invalid_variable_sort_order' => __( 'Sort order must be a whole number from 0 to 65535.', 'cssflow' ),
			'cssflow_duplicate_variable_name'     => __( 'This variable name is already in use. Please choose another name.', 'cssflow' ),
		);

		$message = isset( $messages[ $error ] )
			? $messages[ $error ]
			: __( 'This CSS variable is not valid. Please check the fields and try again.', 'cssflow' );

		$this->print_inline_notice( $message );
	}

	/**
	 * Get the current CSS Variables notice code.
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
	 * Get the current CSS Variables notice target.
	 *
	 * @since 1.0.0
	 *
	 * @return string Target.
	 */
	private function get_notice_target() {
		$flash = $this->get_flash_notice();

		return is_array( $flash ) && isset( $flash['target'] ) ? sanitize_key( (string) $flash['target'] ) : '';
	}

	/**
	 * Get and consume the current user's one-time Variables notice.
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

		$this->flash_notice = get_transient( 'cssflow_variable_notice_' . $user_id );
		delete_transient( 'cssflow_variable_notice_' . $user_id );

		if ( ! is_array( $this->flash_notice ) ) {
			$this->flash_notice = false;
		}

		return $this->flash_notice;
	}

	/**
	 * Determine whether a notice belongs inside a variable card.
	 *
	 * @since 1.0.0
	 *
	 * @param string $notice Notice code.
	 * @return bool
	 */
	private function is_inline_notice( $notice ) {
		return in_array( $notice, array( 'validation_error', 'duplicate_name' ), true );
	}

	/**
	 * Print an inline error notice.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message.
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
