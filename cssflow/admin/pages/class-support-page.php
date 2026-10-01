<?php
/**
 * Renders the CSSFlow Support administration screen.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Admin\Pages;

use CSSFlow\Support\Capabilities;
use CSSFlow\Support\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow Support administration page controller.
 */
final class Support_Page {

	/**
	 * Register the Support submenu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the Support submenu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'cssflow',
			__( 'Support', 'cssflow' ),
			__( 'Support', 'cssflow' ),
			Capabilities::get_edit_capability(),
			'cssflow-support',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the Support screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render() {
		$this->require_edit_capability();

		$docs_url     = Links::get_url( 'documentation' );
		$cssflow_url  = Links::get_url( 'cssflow' );
		$website_url  = Links::get_url( 'website' );
		$linkedin_url = Links::get_url( 'linkedin' );
		$youtube_url  = Links::get_url( 'youtube' );
		$email        = Links::get_email();

		$documentation_links = array(
			array(
				'label' => __( 'Getting Started', 'cssflow' ),
				'url'   => $this->build_documentation_url( $docs_url, 'getting-started' ),
			),
			array(
				'label' => __( 'Targeting & WooCommerce', 'cssflow' ),
				'url'   => $this->build_documentation_url( $docs_url, 'targeting-css' ),
			),
			array(
				'label' => __( 'Responsive CSS & Breakpoints', 'cssflow' ),
				'url'   => $this->build_documentation_url( $docs_url, 'responsive-css' ),
			),
			array(
				'label' => __( 'Troubleshooting & Site Health', 'cssflow' ),
				'url'   => $this->build_documentation_url( $docs_url, 'troubleshooting' ),
			),
		);
		?>
		<div class="wrap cssflow-tools-page cssflow-support-page">
			<hr class="wp-header-end" />

			<div class="cssflow-tools-header">
				<div>
					<h1><?php esc_html_e( 'Support', 'cssflow' ); ?></h1>
					<p class="cssflow-tools-intro">
						<?php esc_html_e( 'Find documentation, get help with CSSFlow, or report a security issue privately.', 'cssflow' ); ?>
					</p>
				</div>
			</div>

			<div class="cssflow-tools-grid cssflow-support-grid">
				<section class="cssflow-tool-card" aria-labelledby="cssflow-support-docs-title">
					<div class="cssflow-tool-card-header">
						<span class="dashicons dashicons-media-document cssflow-tool-icon" aria-hidden="true"></span>
						<div>
							<h2 id="cssflow-support-docs-title"><?php esc_html_e( 'Documentation', 'cssflow' ); ?></h2>
							<p class="cssflow-tool-card-summary">
								<?php esc_html_e( 'Step-by-step guides for setup, targeting, responsive CSS, variables, tools, troubleshooting, and developer hooks.', 'cssflow' ); ?>
							</p>
						</div>
					</div>

					<div class="cssflow-tool-card-body">
						<ul class="cssflow-support-link-list">
							<?php foreach ( $documentation_links as $documentation_link ) : ?>
								<?php if ( '' === $documentation_link['url'] ) : ?>
									<?php continue; ?>
								<?php endif; ?>
								<li>
									<a href="<?php echo esc_url( $documentation_link['url'] ); ?>" target="_blank" rel="noopener noreferrer">
										<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
										<span><?php echo esc_html( $documentation_link['label'] ); ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>

					<?php if ( '' !== $docs_url || '' !== $cssflow_url ) : ?>
						<div class="cssflow-tool-card-footer cssflow-support-actions">
							<?php $this->render_external_button( $docs_url, __( 'Read Documentation', 'cssflow' ), true ); ?>
							<?php $this->render_external_button( $cssflow_url, __( 'CSSFlow Website', 'cssflow' ) ); ?>
						</div>
					<?php endif; ?>
				</section>

				<section class="cssflow-tool-card" aria-labelledby="cssflow-support-help-title">
					<div class="cssflow-tool-card-header">
						<span class="dashicons dashicons-sos cssflow-tool-icon" aria-hidden="true"></span>
						<div>
							<h2 id="cssflow-support-help-title"><?php esc_html_e( 'Get Help', 'cssflow' ); ?></h2>
							<p class="cssflow-tool-card-summary">
								<?php esc_html_e( 'Need help with CSSFlow setup, usage, or a problem?', 'cssflow' ); ?>
							</p>
						</div>
					</div>

					<div class="cssflow-tool-card-body">
						<p>
							<?php esc_html_e( 'Send a support email with a short description of the issue and the steps that led to it.', 'cssflow' ); ?>
						</p>

						<div class="cssflow-support-guidance">
							<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
							<p><?php esc_html_e( 'For faster troubleshooting, include your WordPress version, PHP version, and any relevant error message.', 'cssflow' ); ?></p>
						</div>

						<?php if ( '' !== $email ) : ?>
							<p class="cssflow-support-email">
								<strong><?php esc_html_e( 'Support email:', 'cssflow' ); ?></strong>
								<a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a>
							</p>
						<?php endif; ?>
					</div>

					<?php if ( '' !== $email ) : ?>
						<div class="cssflow-tool-card-footer cssflow-support-actions">
							<a class="button button-primary" href="<?php echo esc_url( $this->build_mailto_url( $email, 'CSSFlow Support' ) ); ?>">
								<?php esc_html_e( 'Email Support', 'cssflow' ); ?>
							</a>
						</div>
					<?php endif; ?>
				</section>

				<section class="cssflow-tool-card cssflow-tool-card-wide cssflow-support-security-card" aria-labelledby="cssflow-support-security-title">
					<div class="cssflow-support-security-content">
						<span class="dashicons dashicons-shield-alt cssflow-tool-icon" aria-hidden="true"></span>
						<div class="cssflow-support-security-copy">
							<h2 id="cssflow-support-security-title"><?php esc_html_e( 'Security', 'cssflow' ); ?></h2>
							<p><?php esc_html_e( 'Found a security issue? Please report it privately and do not post sensitive details in a public forum.', 'cssflow' ); ?></p>
						</div>
					</div>

					<?php if ( '' !== $email ) : ?>
						<a class="button cssflow-support-security-action" href="<?php echo esc_url( $this->build_mailto_url( $email, 'CSSFlow Security Report' ) ); ?>">
							<?php esc_html_e( 'Report Security Issue', 'cssflow' ); ?>
						</a>
					<?php endif; ?>
				</section>

				<section class="cssflow-tool-card cssflow-tool-card-wide cssflow-support-developer-card" aria-labelledby="cssflow-support-development-title">
					<div class="cssflow-tool-card-header">
						<span class="dashicons dashicons-hammer cssflow-tool-icon" aria-hidden="true"></span>
						<div>
							<h2 id="cssflow-support-development-title"><?php esc_html_e( 'Built by Muhammad Qasim', 'cssflow' ); ?></h2>
							<p class="cssflow-tool-card-summary">
								<?php esc_html_e( 'Available for custom WordPress and WooCommerce development beyond CSSFlow.', 'cssflow' ); ?>
							</p>
						</div>
					</div>

					<div class="cssflow-tool-card-body">
						<ul class="cssflow-support-service-list">
							<li>
								<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
								<span><?php esc_html_e( 'Custom WordPress plugins', 'cssflow' ); ?></span>
							</li>
							<li>
								<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
								<span><?php esc_html_e( 'WooCommerce customization & integrations', 'cssflow' ); ?></span>
							</li>
							<li>
								<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
								<span><?php esc_html_e( 'Bug fixes, performance & troubleshooting', 'cssflow' ); ?></span>
							</li>
						</ul>
					</div>

					<div class="cssflow-tool-card-footer cssflow-support-developer-footer">
						<div class="cssflow-support-actions">
							<?php if ( '' !== $email ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( $this->build_mailto_url( $email, 'WordPress Development Request' ) ); ?>">
									<?php esc_html_e( 'Work With Me', 'cssflow' ); ?>
								</a>
							<?php endif; ?>

							<?php if ( '' !== $website_url ) : ?>
								<?php $this->render_external_button( $website_url, __( 'Developer Website', 'cssflow' ) ); ?>
							<?php endif; ?>
						</div>

						<?php if ( '' !== $cssflow_url || '' !== $linkedin_url || '' !== $youtube_url ) : ?>
							<div class="cssflow-support-connect" aria-label="<?php esc_attr_e( 'Connect with the developer', 'cssflow' ); ?>">
								<span class="cssflow-support-connect-label"><?php esc_html_e( 'Connect:', 'cssflow' ); ?></span>
								<span class="cssflow-support-connect-links">
									<?php $this->render_connect_link( $cssflow_url, __( 'CSSFlow Website', 'cssflow' ) ); ?>
									<?php $this->render_connect_link( $linkedin_url, __( 'LinkedIn', 'cssflow' ) ); ?>
									<?php $this->render_connect_link( $youtube_url, __( 'YouTube', 'cssflow' ) ); ?>
								</span>
							</div>
						<?php endif; ?>
					</div>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Render an external button.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url     External URL.
	 * @param string $label   Button label.
	 * @param bool   $primary Whether to use primary button styling.
	 * @return void
	 */
	private function render_external_button( $url, $label, $primary = false ) {
		if ( '' === $url ) {
			return;
		}

		$class = $primary ? 'button button-primary' : 'button';
		?>
		<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
			<?php echo esc_html( $label ); ?>
			<span class="dashicons dashicons-external" aria-hidden="true"></span>
		</a>
		<?php
	}

	/**
	 * Render a compact external connection link.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url   External URL.
	 * @param string $label Link label.
	 * @return void
	 */
	private function render_connect_link( $url, $label ) {
		if ( '' === $url ) {
			return;
		}
		?>
		<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
			<span><?php echo esc_html( $label ); ?></span>
			<span class="dashicons dashicons-external" aria-hidden="true"></span>
		</a>
		<?php
	}

	/**
	 * Build a documentation deep link.
	 *
	 * @since 1.0.0
	 *
	 * @param string $documentation_url Documentation base URL.
	 * @param string $anchor            Documentation section anchor.
	 * @return string Documentation section URL or an empty string.
	 */
	private function build_documentation_url( $documentation_url, $anchor ) {
		if ( '' === $documentation_url ) {
			return '';
		}

		$anchor = sanitize_title( $anchor );

		if ( '' === $anchor ) {
			return $documentation_url;
		}

		return $documentation_url . '#' . $anchor;
	}

	/**
	 * Build a safe mailto URL with a subject.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email   Email address.
	 * @param string $subject Email subject.
	 * @return string Mailto URL.
	 */
	private function build_mailto_url( $email, $subject ) {
		$email = sanitize_email( $email );

		if ( '' === $email ) {
			return '';
		}

		return 'mailto:' . $email . '?subject=' . rawurlencode( $subject );
	}

	/**
	 * Require CSSFlow CSS-edit authorization.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function require_edit_capability() {
		if ( current_user_can( Capabilities::get_edit_capability() ) ) {
			return;
		}

		wp_die(
			esc_html__( 'You do not have permission to access CSSFlow support.', 'cssflow' ),
			esc_html__( 'CSSFlow', 'cssflow' ),
			array( 'response' => 403 )
		);
	}
}
