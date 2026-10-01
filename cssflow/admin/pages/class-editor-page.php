<?php
/**
 * Renders the CSSFlow Add/Edit CSS administration page.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Admin\Pages;

use CSSFlow\Admin\Snippets_List_Table;
use CSSFlow\Database\Snippet_Repository;
use CSSFlow\Integrations\WooCommerce;
use CSSFlow\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSS snippet editor page controller.
 */
final class Editor_Page {

	/**
	 * Snippet repository.
	 *
	 * @var Snippet_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Snippet_Repository $repository Snippet repository.
	 */
	public function __construct( Snippet_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Render the Add/Edit CSS admin screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Capabilities::get_edit_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage CSSFlow snippets.', 'cssflow' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor display/notice parameter; no state change occurs here.
		$snippet_id = isset( $_GET['snippet_id'] ) ? absint( wp_unslash( $_GET['snippet_id'] ) ) : 0;
		$snippet    = $this->get_editor_snippet( $snippet_id );

		if ( null === $snippet ) {
			?>
			<div class="wrap">
				<hr class="wp-header-end" />
				<h1><?php esc_html_e( 'CSS snippet not found', 'cssflow' ); ?></h1>
				<p><?php esc_html_e( 'The requested CSS snippet does not exist.', 'cssflow' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=cssflow' ) ); ?>"><?php esc_html_e( 'Back to All CSS', 'cssflow' ); ?></a></p>
			</div>
			<?php
			return;
		}

		$is_editing                = $snippet_id > 0;
		$title                     = $is_editing ? __( 'Edit CSS', 'cssflow' ) : __( 'Add CSS', 'cssflow' );
		$scope_supported           = $this->is_supported_scope( $snippet['scope_type'] );
		$woocommerce_available     = class_exists( WooCommerce::class ) && WooCommerce::is_available();
		$targetable_post_types     = $this->get_targetable_post_types();
		$specific_post_type        = 'specific_content' === $snippet['scope_type'] && isset( $snippet['scope_data']['post_type'] ) ? sanitize_key( $snippet['scope_data']['post_type'] ) : '';
		$post_type_target          = 'post_type' === $snippet['scope_type'] && isset( $snippet['scope_data']['post_type'] ) ? sanitize_key( $snippet['scope_data']['post_type'] ) : '';
		$specific_type_unavailable = '' !== $specific_post_type && ! isset( $targetable_post_types[ $specific_post_type ] );
		$post_type_unavailable     = '' !== $post_type_target && ! isset( $targetable_post_types[ $post_type_target ] );

		if ( $specific_type_unavailable ) {
			$targetable_post_types[ $specific_post_type ] = sprintf(
				/* translators: %s: unavailable post type key. */
				__( '%s — unavailable', 'cssflow' ),
				$specific_post_type
			);
		}

		if ( $post_type_unavailable ) {
			$targetable_post_types[ $post_type_target ] = sprintf(
				/* translators: %s: unavailable post type key. */
				__( '%s — unavailable', 'cssflow' ),
				$post_type_target
			);
		}

		$special_context            = 'special' === $snippet['scope_type'] && isset( $snippet['scope_data']['context'] ) ? sanitize_key( $snippet['scope_data']['context'] ) : '';
		$selected_specific_items    = $this->get_selected_specific_items( $snippet );
		$woocommerce_context        = 'woocommerce' === $snippet['scope_type'] && isset( $snippet['scope_data']['context'] ) ? sanitize_key( $snippet['scope_data']['context'] ) : '';
		$selected_woo_products      = $this->get_selected_woocommerce_products( $snippet );
		$selected_woo_terms         = $this->get_selected_woocommerce_categories( $snippet );
		$show_profile_editor_notice = $this->should_show_profile_editor_notice();
		?>
		<div class="wrap">
			<hr class="wp-header-end" />
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<?php if ( $is_editing ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cssflow-add-css' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'cssflow' ); ?></a>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cssflow' ) ); ?>" class="page-title-action"><?php esc_html_e( 'All CSS', 'cssflow' ); ?></a>

			<?php $this->render_notice(); ?>

			<?php if ( $show_profile_editor_notice ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php esc_html_e( 'Syntax highlighting is off in your WordPress profile. CSSFlow is using the plain editor.', 'cssflow' ); ?>
						<a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php esc_html_e( 'Open Profile', 'cssflow' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<form class="cssflow-editor-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cssflow_save_snippet" />
				<input type="hidden" name="snippet_id" value="<?php echo esc_attr( (string) $snippet_id ); ?>" />
				<?php wp_nonce_field( 'cssflow_save_snippet', 'cssflow_nonce' ); ?>
				<input type="hidden" name="description" value="<?php echo esc_attr( $snippet['description'] ); ?>" />


				<div class="cssflow-editor-workspace">
					<main class="cssflow-editor-main">
						<div class="cssflow-editor-name-field">
							<label for="cssflow-name" class="cssflow-editor-primary-label"><?php esc_html_e( 'Name', 'cssflow' ); ?></label>
							<input name="name" type="text" id="cssflow-name" class="regular-text" maxlength="191" value="<?php echo esc_attr( $snippet['name'] ); ?>" required />
								<p class="description"><?php esc_html_e( 'A short name that helps you identify this CSS snippet.', 'cssflow' ); ?></p>
						</div>

						<section class="cssflow-editor-code-section" aria-labelledby="cssflow-code-heading">
							<h2 id="cssflow-code-heading" class="cssflow-editor-section-title"><?php esc_html_e( 'CSS Code', 'cssflow' ); ?></h2>
							<div class="cssflow-code-editor-wrap">
									<textarea name="css_code" id="cssflow-css-code" class="large-text code" rows="20" spellcheck="false" aria-describedby="cssflow-css-description cssflow-css-problems-summary"><?php echo esc_textarea( $snippet['css_code'] ); ?></textarea>
								</div>

								<p class="description" id="cssflow-css-description"><?php esc_html_e( 'Enter CSS only. Do not include <style> tags. CSS warnings are advisory and do not prevent saving.', 'cssflow' ); ?></p>

								<section class="cssflow-css-problems" id="cssflow-css-problems" aria-labelledby="cssflow-css-problems-heading">
									<h3 id="cssflow-css-problems-heading"><?php esc_html_e( 'CSS Problems', 'cssflow' ); ?></h3>
									<p class="cssflow-css-problems-summary" id="cssflow-css-problems-summary" role="status" aria-live="polite"><?php esc_html_e( 'CSS problem checks are available when the enhanced editor is enabled.', 'cssflow' ); ?></p>
									<ul class="cssflow-css-problems-list" id="cssflow-css-problems-list"></ul>
								</section>
						</section>
					</main>

					<aside class="cssflow-editor-sidebar" aria-label="<?php esc_attr_e( 'Snippet settings', 'cssflow' ); ?>">
						<section class="cssflow-sidebar-section cssflow-sidebar-status">
							<h2 class="cssflow-sidebar-heading"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><?php esc_html_e( 'Status', 'cssflow' ); ?></h2>
							<input type="hidden" name="status" value="inactive" />
								<label class="cssflow-status-switch" for="cssflow-status">
									<input
										type="checkbox"
										name="status"
										id="cssflow-status"
										value="active"
										role="switch"
										<?php checked( $snippet['status'], 'active' ); ?>
									/>
									<span class="cssflow-status-switch-control" aria-hidden="true"></span>
									<span class="cssflow-status-switch-text cssflow-status-switch-text-active"><?php esc_html_e( 'Active', 'cssflow' ); ?></span>
									<span class="cssflow-status-switch-text cssflow-status-switch-text-inactive"><?php esc_html_e( 'Inactive', 'cssflow' ); ?></span>
								</label>

								<p class="description"><?php esc_html_e( 'Inactive snippets are saved but not loaded on the frontend.', 'cssflow' ); ?></p>
							<div class="cssflow-editor-save-actions">
								<?php submit_button( $is_editing ? __( 'Save Changes', 'cssflow' ) : __( 'Create Snippet', 'cssflow' ), 'primary cssflow-primary-button', 'submit', false ); ?>
								<a class="button cssflow-back-button" href="<?php echo esc_url( admin_url( 'admin.php?page=cssflow' ) ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><span><?php esc_html_e( 'Back to All CSS', 'cssflow' ); ?></span></a>
							</div>
						</section>

						<section class="cssflow-sidebar-section cssflow-sidebar-targeting">
							<h2 class="cssflow-sidebar-heading"><span class="dashicons dashicons-location" aria-hidden="true"></span><?php esc_html_e( 'Targeting', 'cssflow' ); ?></h2>
							<?php if ( $scope_supported ) : ?>
									<label for="cssflow-scope-type" class="cssflow-field-label cssflow-field-label-first"><?php esc_html_e( 'Apply this CSS to', 'cssflow' ); ?></label>
									<select name="scope_type" id="cssflow-scope-type" aria-describedby="cssflow-scope-description">
										<?php foreach ( $this->get_scope_options( $woocommerce_available ) as $scope_value => $scope_label ) : ?>
											<option value="<?php echo esc_attr( $scope_value ); ?>" <?php selected( $snippet['scope_type'], $scope_value ); ?>><?php echo esc_html( $scope_label ); ?></option>
										<?php endforeach; ?>
									</select>

									<p class="description" id="cssflow-scope-description"><?php esc_html_e( 'Choose where this CSS should load.', 'cssflow' ); ?></p>

									<?php if ( $specific_type_unavailable || $post_type_unavailable ) : ?>
										<p class="description"><strong><?php esc_html_e( 'Target unavailable:', 'cssflow' ); ?></strong> <?php esc_html_e( 'The plugin or theme that provides this content type may be inactive. You can deactivate this snippet without changing its saved targeting, or choose another content type.', 'cssflow' ); ?></p>
									<?php endif; ?>

									<div class="cssflow-targeting-panels" id="cssflow-targeting-panels">

										<div class="cssflow-targeting-panel" data-cssflow-scope-panel="specific_content" <?php echo 'specific_content' === $snippet['scope_type'] ? '' : 'hidden'; ?>>


											<label for="cssflow-specific-post-type" class="cssflow-field-label"><?php esc_html_e( 'Content type', 'cssflow' ); ?></label>

											<select name="target_post_type" id="cssflow-specific-post-type" data-cssflow-target-control <?php disabled( 'specific_content' !== $snippet['scope_type'] ); ?>>
												<option value=""><?php esc_html_e( 'Select a content type', 'cssflow' ); ?></option>

												<?php foreach ( $targetable_post_types as $post_type => $post_type_label ) : ?>
													<option value="<?php echo esc_attr( $post_type ); ?>" <?php selected( $specific_post_type, $post_type ); ?>><?php echo esc_html( $post_type_label ); ?></option>
												<?php endforeach; ?>
											</select>

											<div class="cssflow-specific-content-details" id="cssflow-specific-content-details" <?php echo empty( $specific_post_type ) ? 'hidden' : ''; ?>>
												<div class="cssflow-content-picker" id="cssflow-content-picker">
												<label for="cssflow-content-search" class="cssflow-field-label"><?php esc_html_e( 'Find content', 'cssflow' ); ?></label>

												<div class="cssflow-content-search-row">
													<input
														type="search"
														id="cssflow-content-search"
														class="regular-text"
														autocomplete="off"
														aria-describedby="cssflow-content-search-description cssflow-content-search-status"
														data-cssflow-target-control
														<?php disabled( 'specific_content' !== $snippet['scope_type'] ); ?>
													/>

													<button
														type="button"
														class="button"
														id="cssflow-content-search-button"
														data-cssflow-target-control
														<?php disabled( 'specific_content' !== $snippet['scope_type'] ); ?>
													>
														<?php esc_html_e( 'Search', 'cssflow' ); ?>
													</button>
												</div>

												<p class="description" id="cssflow-content-search-description"><?php esc_html_e( 'Enter at least 2 characters to search.', 'cssflow' ); ?></p>

												<p class="cssflow-targeting-status" id="cssflow-content-search-status" role="status" aria-live="polite"></p>

												<ul class="cssflow-search-results" id="cssflow-content-search-results" aria-label="<?php esc_attr_e( 'Content search results', 'cssflow' ); ?>"></ul>

												<button
													type="button"
													class="button cssflow-load-more"
													id="cssflow-content-load-more"
													hidden
													data-cssflow-target-control
													<?php disabled( 'specific_content' !== $snippet['scope_type'] ); ?>
												>
													<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span><?php esc_html_e( 'Load more', 'cssflow' ); ?></span>
												</button>
											</div>

											<div class="cssflow-selected-targets">
												<h4><?php esc_html_e( 'Selected content', 'cssflow' ); ?></h4>

												<p class="description"><?php esc_html_e( 'CSS applies only to selected content.', 'cssflow' ); ?></p>

												<ul id="cssflow-selected-targets-list" class="cssflow-selected-targets-list">
													<?php foreach ( $selected_specific_items as $item ) : ?>
														<li data-cssflow-target-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
															<span class="cssflow-selected-target-title"><?php echo esc_html( $item['text'] ); ?></span>

															<input
																type="hidden"
																name="target_ids[]"
																value="<?php echo esc_attr( (string) $item['id'] ); ?>"
																data-cssflow-target-control
																<?php disabled( 'specific_content' !== $snippet['scope_type'] ); ?>
															/>

															<button
																type="button"
																class="button-link-delete cssflow-remove-target"
																data-cssflow-target-control
																<?php disabled( 'specific_content' !== $snippet['scope_type'] ); ?>
															>
																<?php esc_html_e( 'Remove', 'cssflow' ); ?>
															</button>
														</li>
													<?php endforeach; ?>
												</ul>

												<p class="cssflow-empty-selection" id="cssflow-empty-selection" <?php echo empty( $selected_specific_items ) ? '' : 'hidden'; ?>><?php esc_html_e( 'No content selected yet.', 'cssflow' ); ?></p>
											</div>
											</div>
										</div>

										<div class="cssflow-targeting-panel" data-cssflow-scope-panel="post_type" <?php echo 'post_type' === $snippet['scope_type'] ? '' : 'hidden'; ?>>


											<label for="cssflow-post-type-target" class="cssflow-field-label"><?php esc_html_e( 'Content type', 'cssflow' ); ?></label>

											<select name="target_post_type" id="cssflow-post-type-target" data-cssflow-target-control <?php disabled( 'post_type' !== $snippet['scope_type'] ); ?>>
												<option value=""><?php esc_html_e( 'Select a content type', 'cssflow' ); ?></option>

												<?php foreach ( $targetable_post_types as $post_type => $post_type_label ) : ?>
													<option value="<?php echo esc_attr( $post_type ); ?>" <?php selected( $post_type_target, $post_type ); ?>><?php echo esc_html( $post_type_label ); ?></option>
												<?php endforeach; ?>
											</select>
										</div>

										<div class="cssflow-targeting-panel" data-cssflow-scope-panel="special" <?php echo 'special' === $snippet['scope_type'] ? '' : 'hidden'; ?>>


											<label for="cssflow-special-context" class="cssflow-field-label"><?php esc_html_e( 'Context', 'cssflow' ); ?></label>

											<select name="special_context" id="cssflow-special-context" data-cssflow-target-control <?php disabled( 'special' !== $snippet['scope_type'] ); ?>>
												<option value=""><?php esc_html_e( 'Select a context', 'cssflow' ); ?></option>

												<?php foreach ( $this->get_special_context_labels() as $context_value => $context_label ) : ?>
													<option value="<?php echo esc_attr( $context_value ); ?>" <?php selected( $special_context, $context_value ); ?>><?php echo esc_html( $context_label ); ?></option>
												<?php endforeach; ?>
											</select>
										</div>


										<?php if ( $woocommerce_available ) : ?>
											<div class="cssflow-targeting-panel" data-cssflow-scope-panel="woocommerce" <?php echo 'woocommerce' === $snippet['scope_type'] ? '' : 'hidden'; ?>>

												<label for="cssflow-woocommerce-context" class="cssflow-field-label"><?php esc_html_e( 'WooCommerce target', 'cssflow' ); ?></label>
												<select name="woocommerce_context" id="cssflow-woocommerce-context" data-cssflow-target-control <?php disabled( 'woocommerce' !== $snippet['scope_type'] ); ?>>
													<option value=""><?php esc_html_e( 'Select a WooCommerce target', 'cssflow' ); ?></option>
													<?php foreach ( $this->get_woocommerce_context_labels() as $context_value => $context_label ) : ?>
														<option value="<?php echo esc_attr( $context_value ); ?>" <?php selected( $woocommerce_context, $context_value ); ?>><?php echo esc_html( $context_label ); ?></option>
													<?php endforeach; ?>
												</select>

												<div class="cssflow-woocommerce-subpanel" data-cssflow-woo-context-panel="specific_products" <?php echo 'specific_products' === $woocommerce_context ? '' : 'hidden'; ?>>
													<div class="cssflow-content-picker" data-cssflow-picker="woocommerce-products">
														<label for="cssflow-woocommerce-product-search" class="cssflow-field-label"><?php esc_html_e( 'Find Products', 'cssflow' ); ?></label>
														<div class="cssflow-content-search-row">
															<input type="search" id="cssflow-woocommerce-product-search" class="regular-text" autocomplete="off" aria-describedby="cssflow-woocommerce-product-search-description cssflow-woocommerce-product-search-status" data-cssflow-picker-search data-cssflow-target-control <?php disabled( 'woocommerce' !== $snippet['scope_type'] || 'specific_products' !== $woocommerce_context ); ?> />
															<button type="button" class="button" data-cssflow-picker-search-button data-cssflow-target-control <?php disabled( 'woocommerce' !== $snippet['scope_type'] || 'specific_products' !== $woocommerce_context ); ?>><?php esc_html_e( 'Search', 'cssflow' ); ?></button>
														</div>
														<p class="description" id="cssflow-woocommerce-product-search-description"><?php esc_html_e( 'Enter at least 2 characters to search products.', 'cssflow' ); ?></p>
														<p class="cssflow-targeting-status" id="cssflow-woocommerce-product-search-status" role="status" aria-live="polite" data-cssflow-picker-status></p>
														<ul class="cssflow-search-results" aria-label="<?php esc_attr_e( 'Product search results', 'cssflow' ); ?>" data-cssflow-picker-results></ul>
														<button type="button" class="button cssflow-load-more" hidden data-cssflow-picker-load-more data-cssflow-target-control <?php disabled( 'woocommerce' !== $snippet['scope_type'] || 'specific_products' !== $woocommerce_context ); ?>><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span><?php esc_html_e( 'Load more', 'cssflow' ); ?></span></button>
														<div class="cssflow-selected-targets">
															<h4><?php esc_html_e( 'Selected Products', 'cssflow' ); ?></h4>
															<ul class="cssflow-selected-targets-list" data-cssflow-picker-selected>
																<?php foreach ( $selected_woo_products as $item ) : ?>
																	<li data-cssflow-target-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
																		<span class="cssflow-selected-target-title"><?php echo esc_html( $item['text'] ); ?></span>
																		<input type="hidden" name="woocommerce_product_ids[]" value="<?php echo esc_attr( (string) $item['id'] ); ?>" data-cssflow-target-control <?php disabled( 'woocommerce' !== $snippet['scope_type'] || 'specific_products' !== $woocommerce_context ); ?> />
																		<button type="button" class="button-link-delete cssflow-remove-target" data-cssflow-target-control <?php disabled( 'woocommerce' !== $snippet['scope_type'] || 'specific_products' !== $woocommerce_context ); ?>><?php esc_html_e( 'Remove', 'cssflow' ); ?></button>
																	</li>
																<?php endforeach; ?>
															</ul>
															<p class="cssflow-empty-selection" data-cssflow-picker-empty <?php echo empty( $selected_woo_products ) ? '' : 'hidden'; ?>><?php esc_html_e( 'No Products selected yet.', 'cssflow' ); ?></p>
														</div>
													</div>
												</div>

												<div class="cssflow-woocommerce-subpanel" data-cssflow-woo-context-panel="product_category" <?php echo 'product_category' === $woocommerce_context ? '' : 'hidden'; ?>>
													<div class="cssflow-content-picker" data-cssflow-picker="woocommerce-categories">
														<label for="cssflow-woocommerce-category-search" class="cssflow-field-label"><?php esc_html_e( 'Find Product Categories', 'cssflow' ); ?></label>
														<div class="cssflow-content-search-row">
															<input type="search" id="cssflow-woocommerce-category-search" class="regular-text" autocomplete="off" aria-describedby="cssflow-woocommerce-category-search-description cssflow-woocommerce-category-search-status" data-cssflow-picker-search data-cssflow-target-control <?php disabled( 'woocommerce' !== $snippet['scope_type'] || 'product_category' !== $woocommerce_context ); ?> />
															<button type="button" class="button" data-cssflow-picker-search-button data-cssflow-target-control <?php disabled( 'woocommerce' !== $snippet['scope_type'] || 'product_category' !== $woocommerce_context ); ?>><?php esc_html_e( 'Search', 'cssflow' ); ?></button>
														</div>
														<p class="description" id="cssflow-woocommerce-category-search-description"><?php esc_html_e( 'Enter at least 2 characters to search categories.', 'cssflow' ); ?></p>
														<p class="cssflow-targeting-status" id="cssflow-woocommerce-category-search-status" role="status" aria-live="polite" data-cssflow-picker-status></p>
														<ul class="cssflow-search-results" aria-label="<?php esc_attr_e( 'Product Category search results', 'cssflow' ); ?>" data-cssflow-picker-results></ul>
														<button type="button" class="button cssflow-load-more" hidden data-cssflow-picker-load-more data-cssflow-target-control <?php disabled( 'woocommerce' !== $snippet['scope_type'] || 'product_category' !== $woocommerce_context ); ?>><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span><?php esc_html_e( 'Load more', 'cssflow' ); ?></span></button>
														<div class="cssflow-selected-targets">
															<h4><?php esc_html_e( 'Selected Product Categories', 'cssflow' ); ?></h4>
															<ul class="cssflow-selected-targets-list" data-cssflow-picker-selected>
																<?php foreach ( $selected_woo_terms as $item ) : ?>
																	<li data-cssflow-target-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
																		<span class="cssflow-selected-target-title"><?php echo esc_html( $item['text'] ); ?></span>
																		<input type="hidden" name="woocommerce_term_ids[]" value="<?php echo esc_attr( (string) $item['id'] ); ?>" data-cssflow-target-control <?php disabled( 'woocommerce' !== $snippet['scope_type'] || 'product_category' !== $woocommerce_context ); ?> />
																		<button type="button" class="button-link-delete cssflow-remove-target" data-cssflow-target-control <?php disabled( 'woocommerce' !== $snippet['scope_type'] || 'product_category' !== $woocommerce_context ); ?>><?php esc_html_e( 'Remove', 'cssflow' ); ?></button>
																	</li>
																<?php endforeach; ?>
															</ul>
															<p class="cssflow-empty-selection" data-cssflow-picker-empty <?php echo empty( $selected_woo_terms ) ? '' : 'hidden'; ?>><?php esc_html_e( 'No Product Categories selected yet.', 'cssflow' ); ?></p>
														</div>
													</div>
												</div>
											</div>
										<?php endif; ?>

									</div>
								<?php else : ?>
									<strong><?php echo esc_html( $this->get_scope_label( $snippet['scope_type'] ) ); ?></strong>

									<p class="description"><?php esc_html_e( 'This targeting type is currently unavailable because its required plugin or integration is not active. You can deactivate this snippet or save other fields without losing its existing targeting.', 'cssflow' ); ?></p>
								<?php endif; ?>
						</section>

						<section class="cssflow-sidebar-section cssflow-sidebar-responsive">
							<h2 class="cssflow-sidebar-heading"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span><?php esc_html_e( 'Responsive', 'cssflow' ); ?></h2>
							<label for="cssflow-responsive-type" class="cssflow-field-label cssflow-field-label-first"><?php esc_html_e( 'Responsive target', 'cssflow' ); ?></label>
							<select name="responsive_type" id="cssflow-responsive-type">
									<?php foreach ( Snippets_List_Table::get_responsive_labels() as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $snippet['responsive_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>

								<p class="description"><?php esc_html_e( 'Choose which devices should use this CSS.', 'cssflow' ); ?></p>

							<div class="cssflow-custom-breakpoint" id="cssflow-breakpoint-row" <?php echo 'custom' === $snippet['responsive_type'] ? '' : 'hidden'; ?>>
								<label for="cssflow-breakpoint-key" class="cssflow-field-label"><?php esc_html_e( 'Custom breakpoint', 'cssflow' ); ?></label>
								<select
									name="breakpoint_key"
									id="cssflow-breakpoint-key"
									<?php disabled( 'custom' !== $snippet['responsive_type'] ); ?>
								>
									<option value=""><?php esc_html_e( 'Select a custom breakpoint', 'cssflow' ); ?></option>

									<?php foreach ( $this->get_custom_breakpoints() as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $snippet['breakpoint_key'], $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>

								<p class="description"><?php esc_html_e( 'Used only when Responsive target is Custom Breakpoint.', 'cssflow' ); ?></p>
							</div>
						</section>

						<section class="cssflow-sidebar-section cssflow-sidebar-priority">
							<h2 class="cssflow-sidebar-heading"><span class="dashicons dashicons-sort" aria-hidden="true"></span><?php esc_html_e( 'Priority', 'cssflow' ); ?></h2>
							<input name="priority" type="number" id="cssflow-priority" class="small-text" min="0" max="65535" step="1" value="<?php echo esc_attr( (string) $snippet['priority'] ); ?>" required />

								<p class="description"><?php esc_html_e( 'Higher values load later.', 'cssflow' ); ?></p>
						</section>
					</aside>
				</div>

			</form>
		</div>
		<?php
	}

	/**
	 * Get an existing snippet or a default new-snippet record.
	 *
	 * @since 1.0.0
	 *
	 * @param int $snippet_id Snippet ID.
	 * @return array|null Snippet data or null when an edit target is missing.
	 */
	private function get_editor_snippet( $snippet_id ) {
		if ( $snippet_id > 0 ) {
			return $this->repository->get( $snippet_id );
		}

		return array(
			'id'              => 0,
			'name'            => '',
			'description'     => '',
			'css_code'        => '',
			'scope_type'      => 'global',
			'scope_data'      => array(),
			'responsive_type' => 'all',
			'breakpoint_key'  => null,
			'priority'        => 10,
			'status'          => 'inactive',
		);
	}

	/**
	 * Get Phase 8 editor scope labels.
	 *
	 * WooCommerce targeting is intentionally omitted until Phase 9.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $woocommerce_available Whether WooCommerce targeting is available.
	 * @return array<string,string> Scope labels.
	 */
	private function get_scope_options( $woocommerce_available ) {
		$labels = array(
			'global'           => __( 'Entire website', 'cssflow' ),
			'specific_content' => __( 'Specific content', 'cssflow' ),
			'post_type'        => __( 'Content type', 'cssflow' ),
			'special'          => __( 'Special Pages', 'cssflow' ),
		);

		if ( $woocommerce_available ) {
			$labels['woocommerce'] = __( 'WooCommerce', 'cssflow' );
		}

		return $labels;
	}

	/**
	 * Determine whether the editor can represent the current scope.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope_type Scope type.
	 * @return bool Whether the current scope can be edited.
	 */
	private function is_supported_scope( $scope_type ) {
		if ( in_array( $scope_type, array( 'global', 'specific_content', 'post_type', 'special' ), true ) ) {
			return true;
		}

		return 'woocommerce' === $scope_type && class_exists( WooCommerce::class ) && WooCommerce::is_available();
	}

	/**
	 * Get targetable public post types for Phase 8.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Labels keyed by post type.
	 */
	private function get_targetable_post_types() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$options    = array();

		if ( ! is_array( $post_types ) ) {
			return $options;
		}

		foreach ( $post_types as $post_type => $object ) {
			if ( ! Snippet_Repository::is_phase8_targetable_post_type( $post_type ) ) {
				continue;
			}

			$label = isset( $object->labels->name ) ? sanitize_text_field( $object->labels->name ) : $post_type;

			$options[ $post_type ] = '' !== $label ? $label : $post_type;
		}

		natcasesort( $options );

		return $options;
	}

	/**
	 * Resolve already-selected specific-content items for edit rendering.
	 *
	 * @since 1.0.0
	 *
	 * @param array $snippet Snippet data.
	 * @return array<int,array{id:int,text:string}> Selected item labels.
	 */
	private function get_selected_specific_items( array $snippet ) {
		if (
			'specific_content' !== $snippet['scope_type']
			|| empty( $snippet['scope_data']['post_type'] )
			|| empty( $snippet['scope_data']['ids'] )
			|| ! is_array( $snippet['scope_data']['ids'] )
		) {
			return array();
		}

		$post_type = sanitize_key( $snippet['scope_data']['post_type'] );
		$items     = array();

		if ( ! Snippet_Repository::is_phase8_targetable_post_type( $post_type ) ) {
			return $items;
		}

		foreach ( $snippet['scope_data']['ids'] as $raw_id ) {
			$object_id = absint( $raw_id );

			if ( 0 === $object_id || get_post_type( $object_id ) !== $post_type ) {
				continue;
			}

			$title = get_post_field( 'post_title', $object_id, 'raw' );
			$title = is_string( $title ) ? trim( wp_strip_all_tags( $title ) ) : '';

			if ( '' === $title ) {
				$title = __( '(no title)', 'cssflow' );
			}

			$items[] = array(
				'id'   => $object_id,
				'text' => sprintf(
					/* translators: 1: item name or title, 2: item ID. */
					__( '%1$s (#%2$d)', 'cssflow' ),
					$title,
					$object_id
				),
			);
		}

		return $items;
	}


	/**
	 * Resolve already-selected WooCommerce Products for edit rendering.
	 *
	 * @since 1.0.0
	 *
	 * @param array $snippet Snippet data.
	 * @return array<int,array{id:int,text:string}> Selected Product labels.
	 */
	private function get_selected_woocommerce_products( array $snippet ) {
		if ( 'woocommerce' !== $snippet['scope_type'] || empty( $snippet['scope_data']['context'] ) || 'specific_products' !== $snippet['scope_data']['context'] || empty( $snippet['scope_data']['product_ids'] ) || ! is_array( $snippet['scope_data']['product_ids'] ) ) {
			return array();
		}

		$items = array();

		foreach ( $snippet['scope_data']['product_ids'] as $raw_id ) {
			$product_id = absint( $raw_id );

			if ( 0 === $product_id || 'product' !== get_post_type( $product_id ) ) {
				continue;
			}

			$title = get_post_field( 'post_title', $product_id, 'raw' );
			$title = is_string( $title ) ? trim( wp_strip_all_tags( $title ) ) : '';

			if ( '' === $title ) {
				$title = __( '(no title)', 'cssflow' );
			}

			$items[] = array(
				'id'   => $product_id,
				'text' => sprintf(
					/* translators: 1: item name or title, 2: item ID. */
					__( '%1$s (#%2$d)', 'cssflow' ),
					$title,
					$product_id
				),
			);
		}

		return $items;
	}

	/**
	 * Resolve already-selected WooCommerce Product Categories for edit rendering.
	 *
	 * @since 1.0.0
	 *
	 * @param array $snippet Snippet data.
	 * @return array<int,array{id:int,text:string}> Selected category labels.
	 */
	private function get_selected_woocommerce_categories( array $snippet ) {
		if ( 'woocommerce' !== $snippet['scope_type'] || empty( $snippet['scope_data']['context'] ) || 'product_category' !== $snippet['scope_data']['context'] || empty( $snippet['scope_data']['term_ids'] ) || ! is_array( $snippet['scope_data']['term_ids'] ) ) {
			return array();
		}

		$items = array();

		foreach ( $snippet['scope_data']['term_ids'] as $raw_id ) {
			$term_id = absint( $raw_id );
			$term    = $term_id > 0 ? get_term( $term_id, 'product_cat' ) : null;

			if ( 0 === $term_id || ! $term || is_wp_error( $term ) || 'product_cat' !== $term->taxonomy ) {
				continue;
			}

			$items[] = array(
				'id'   => $term_id,
				'text' => sprintf(
					/* translators: 1: item name or title, 2: item ID. */
					__( '%1$s (#%2$d)', 'cssflow' ),
					sanitize_text_field( $term->name ),
					$term_id
				),
			);
		}

		return $items;
	}

	/**
	 * Get frozen WooCommerce context labels.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Context labels.
	 */
	private function get_woocommerce_context_labels() {
		return array(
			'shop'              => __( 'Shop', 'cssflow' ),
			'products'          => __( 'All Products', 'cssflow' ),
			'specific_products' => __( 'Specific Products', 'cssflow' ),
			'product_category'  => __( 'Product Category', 'cssflow' ),
			'cart'              => __( 'Cart', 'cssflow' ),
			'checkout'          => __( 'Checkout', 'cssflow' ),
			'my_account'        => __( 'My Account', 'cssflow' ),
		);
	}

	/**
	 * Get frozen WordPress special-context labels.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Context labels.
	 */
	private function get_special_context_labels() {
		return array(
			'front_page' => __( 'Front page / homepage', 'cssflow' ),
			'posts_page' => __( 'Posts page / blog index', 'cssflow' ),
			'search'     => __( 'Search results', 'cssflow' ),
			'404'        => __( '404 page', 'cssflow' ),
		);
	}

	/**
	 * Get non-default configured breakpoint labels.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Breakpoint labels keyed by stable key.
	 */
	private function get_custom_breakpoints() {
		$breakpoints = get_option( 'cssflow_breakpoints', array() );
		$options     = array();

		if ( ! is_array( $breakpoints ) ) {
			return $options;
		}

		foreach ( $breakpoints as $key => $breakpoint ) {
			if (
				! is_string( $key )
				|| sanitize_key( $key ) !== $key
				|| ! is_array( $breakpoint )
				|| ! empty( $breakpoint['is_default'] )
			) {
				continue;
			}

			$label = isset( $breakpoint['label'] ) ? sanitize_text_field( $breakpoint['label'] ) : $key;

			$options[ $key ] = '' !== $label ? $label : $key;
		}

		return $options;
	}

	/**
	 * Get a human-readable scope label.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope_type Scope type.
	 * @return string Label.
	 */
	private function get_scope_label( $scope_type ) {
		$labels = Snippets_List_Table::get_scope_labels();

		return isset( $labels[ $scope_type ] ) ? $labels[ $scope_type ] : $scope_type;
	}

	/**
	 * Determine whether the editor should explain the user's profile override.
	 *
	 * The notice is shown only when CSSFlow allows the enhanced editor but the
	 * current WordPress user has disabled syntax highlighting in their profile.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether to show the profile preference notice.
	 */
	private function should_show_profile_editor_notice() {
		$settings = get_option( 'cssflow_settings', array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$cssflow_allows_editor = ! array_key_exists( 'enable_syntax_highlighting', $settings )
			|| ! empty( $settings['enable_syntax_highlighting'] );

		if ( ! $cssflow_allows_editor ) {
			return false;
		}

		$user = wp_get_current_user();

		return isset( $user->syntax_highlighting ) && 'false' === $user->syntax_highlighting;
	}

	/**
	 * Render a sanitized editor notice.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_notice() {
		$user_id = get_current_user_id();
		$flash   = false;

		if ( $user_id > 0 ) {
			$transient_key = 'cssflow_editor_notice_' . $user_id;
			$flash         = get_transient( $transient_key );

			if ( false !== $flash ) {
				delete_transient( $transient_key );
			}
		}

		$notice = is_array( $flash ) && isset( $flash['notice'] ) ? sanitize_key( $flash['notice'] ) : '';
		$error  = is_array( $flash ) && isset( $flash['error'] ) ? sanitize_key( $flash['error'] ) : '';

		if ( 'saved' === $notice ) {
			$this->print_notice( 'success', __( 'CSS snippet saved.', 'cssflow' ) );
			return;
		}

		if ( 'duplicated' === $notice ) {
			$this->print_notice( 'success', __( 'CSS snippet duplicated as an inactive copy.', 'cssflow' ) );
			return;
		}

		if ( 'saved_cache_warning' === $notice ) {
			$this->print_notice(
				'warning',
				__( 'The CSS snippet was saved, but CSSFlow could not clear old generated cache files. Frontend output will still be rebuilt from current source data.', 'cssflow' )
			);

			return;
		}

		if ( in_array( $notice, array( 'validation_error', 'save_error' ), true ) ) {
			$this->print_notice( 'error', $this->get_error_message( $error ) );
		}
	}

	/**
	 * Convert a repository/request error code into an admin-safe message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Error code.
	 * @return string Message.
	 */
	private function get_error_message( $code ) {
		$messages = array(
			'cssflow_invalid_name'                    => __( 'Enter a snippet name.', 'cssflow' ),
			'cssflow_name_too_long'                   => __( 'The snippet name is too long.', 'cssflow' ),
			'cssflow_css_too_large'                   => __( 'The CSS exceeds the allowed snippet size limit.', 'cssflow' ),
			'cssflow_invalid_scope_type'              => __( 'Choose a valid targeting type.', 'cssflow' ),
			'cssflow_invalid_scope_data'              => __( 'The snippet targeting data is invalid.', 'cssflow' ),
			'cssflow_invalid_target_post_type'        => __( 'This snippet targets a content type that is currently unavailable. Activate the plugin or theme that provides it, or choose another content type before activating the snippet.', 'cssflow' ),
			'cssflow_invalid_target_ids'              => __( 'Choose at least one valid content item that you are allowed to edit.', 'cssflow' ),
			'cssflow_invalid_special_context'         => __( 'Choose a valid Special Pages.', 'cssflow' ),
			'cssflow_woocommerce_unavailable'         => __( 'WooCommerce targeting is unavailable because WooCommerce is not active.', 'cssflow' ),
			'cssflow_invalid_woocommerce_context'     => __( 'Choose a valid WooCommerce target.', 'cssflow' ),
			'cssflow_invalid_woocommerce_scope_data'  => __( 'The WooCommerce targeting data is invalid.', 'cssflow' ),
			'cssflow_invalid_woocommerce_product_ids' => __( 'Choose at least one valid WooCommerce Product that you are allowed to edit.', 'cssflow' ),
			'cssflow_invalid_woocommerce_term_ids'    => __( 'Choose at least one valid WooCommerce Product Category.', 'cssflow' ),
			'cssflow_scope_encoding_failed'           => __( 'The snippet targeting data could not be saved.', 'cssflow' ),
			'cssflow_invalid_responsive_type'         => __( 'Choose a valid responsive target.', 'cssflow' ),
			'cssflow_invalid_breakpoint'              => __( 'Choose a valid custom breakpoint.', 'cssflow' ),
			'cssflow_invalid_priority'                => __( 'Priority must be a whole number from 0 through 65535.', 'cssflow' ),
			'cssflow_invalid_status'                  => __( 'Choose a valid snippet status.', 'cssflow' ),
			'cssflow_insert_failed'                   => __( 'CSSFlow could not create the snippet.', 'cssflow' ),
			'cssflow_update_failed'                   => __( 'CSSFlow could not update the snippet.', 'cssflow' ),
		);

		return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'CSSFlow could not save the snippet.', 'cssflow' );
	}

	/**
	 * Print a WordPress admin notice.
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
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible inline">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}
}
