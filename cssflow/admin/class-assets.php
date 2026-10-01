<?php
/**
 * Loads CSSFlow administration assets where they are required.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Admin;

use CSSFlow\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow administration asset loader.
 */
final class Assets {

	/**
	 * Register administration asset hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue CSSFlow assets only on the administration screens that need them.
	 *
	 * WordPress's native code editor is used when available. CSSFlow's
	 * lightweight advisory problem checks remain available with either
	 * CodeMirror or the normal textarea fallback.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current administration page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		$is_snippets_screen    = $this->is_snippets_screen( $hook_suffix );
		$is_editor_screen      = $this->is_editor_screen( $hook_suffix );
		$is_tools_screen       = $this->is_tools_screen( $hook_suffix );
		$is_settings_screen    = $this->is_settings_screen( $hook_suffix );
		$is_breakpoints_screen = $this->is_breakpoints_screen( $hook_suffix );
		$is_variables_screen   = $this->is_variables_screen( $hook_suffix );
		$is_support_screen     = $this->is_support_screen( $hook_suffix );

		if ( ! $is_snippets_screen && ! $is_editor_screen && ! $is_tools_screen && ! $is_settings_screen && ! $is_breakpoints_screen && ! $is_variables_screen && ! $is_support_screen ) {
			return;
		}

		if ( $is_settings_screen || $is_breakpoints_screen || $is_variables_screen ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
		} elseif ( ! current_user_can( Capabilities::get_edit_capability() ) ) {
			return;
		}

		$admin_css_path    = CSSFLOW_PATH . 'admin/css/admin.css';
		$admin_css_version = file_exists( $admin_css_path ) ? (string) filemtime( $admin_css_path ) : CSSFLOW_VERSION;

		wp_enqueue_style(
			'cssflow-admin',
			CSSFLOW_URL . 'admin/css/admin.css',
			array(),
			$admin_css_version
		);

		$admin_rtl_css_path    = CSSFLOW_PATH . 'admin/css/admin-rtl.css';
		$admin_rtl_css_version = file_exists( $admin_rtl_css_path ) ? (string) filemtime( $admin_rtl_css_path ) : CSSFLOW_VERSION;

		if ( is_rtl() ) {
			wp_enqueue_style(
				'cssflow-admin-rtl',
				CSSFLOW_URL . 'admin/css/admin-rtl.css',
				array( 'cssflow-admin' ),
				$admin_rtl_css_version
			);
		}

		if ( $is_snippets_screen ) {
			wp_enqueue_script(
				'cssflow-snippets',
				CSSFLOW_URL . 'admin/js/snippets.js',
				array( 'jquery' ),
				CSSFLOW_VERSION,
				true
			);

			wp_localize_script(
				'cssflow-snippets',
				'CSSFlowSnippets',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'toggle'  => array(
						'action' => 'cssflow_toggle_snippet_status',
						'nonce'  => wp_create_nonce( 'cssflow_toggle_snippet_status' ),
					),
					'strings' => array(
						'active'        => __( 'Active', 'cssflow' ),
						'inactive'      => __( 'Inactive', 'cssflow' ),
						'activate'      => __( 'Activate', 'cssflow' ),
						'deactivate'    => __( 'Deactivate', 'cssflow' ),
						'toggleFailed'  => __( 'CSSFlow could not change the snippet status. Please try again.', 'cssflow' ),
						'items'         => __( 'items', 'cssflow' ),
						'dismissNotice' => __( 'Dismiss this notice.', 'cssflow' ),
					),
				)
			);

			return;
		}

		if ( $is_settings_screen || $is_breakpoints_screen || $is_variables_screen || $is_support_screen ) {
			return;
		}

		if ( $is_tools_screen ) {
			wp_enqueue_script(
				'cssflow-tools',
				CSSFLOW_URL . 'admin/js/tools.js',
				array(),
				CSSFLOW_VERSION,
				true
			);

			return;
		}

		/*
		 * Native CodeMirror lint markers are deliberately disabled.
		 *
		 * WordPress's bundled CSSLint parser can incorrectly report valid
		 * modern CSS features as errors. CSSFlow therefore performs its own
		 * conservative structural checks and selectively uses CSSLint only
		 * where its result is considered sufficiently reliable.
		 */
		$editor_settings = false;

		if ( $this->is_syntax_highlighting_enabled() ) {
			$editor_settings = wp_enqueue_code_editor(
				array(
					'type'       => 'text/css',
					'codemirror' => array(
						'lint'          => false,
						'lineNumbers'   => true,
						'matchBrackets' => true,
					),
				)
			);
		}

		/*
		 * Load WordPress's bundled CSSLint independently so the advisory
		 * Problems panel also works with the normal textarea fallback.
		 */
		wp_enqueue_script( 'csslint' );

		$editor_dependencies = array(
			'jquery',
			'csslint',
		);

		if ( false !== $editor_settings ) {
			$editor_dependencies[] = 'code-editor';
		}

		wp_enqueue_script(
			'cssflow-editor',
			CSSFLOW_URL . 'admin/js/editor.js',
			$editor_dependencies,
			CSSFLOW_VERSION,
			true
		);

		wp_localize_script(
			'cssflow-editor',
			'CSSFlowEditor',
			array(
				'editorSettings' => false === $editor_settings ? false : $editor_settings,
				'strings'        => array(
					'editorLabel'            => __( 'CSS code editor', 'cssflow' ),
					'noProblems'             => __( 'No CSS problems detected.', 'cssflow' ),
					'oneProblem'             => __( '1 CSS problem found.', 'cssflow' ),
					/* translators: %1$d: Number of CSS problems found. */
					'manyProblems'           => __( '%1$d CSS problems found.', 'cssflow' ),
					/* translators: 1: CSS editor line number, 2: CSS problem message. */
					'lineMessage'            => __( 'Line %1$d: %2$s', 'cssflow' ),
					'missingClosingBrace'    => __( 'This opening brace { does not have a matching closing brace }.', 'cssflow' ),
					'unexpectedClosingBrace' => __( 'This closing brace } does not have a matching opening brace {.', 'cssflow' ),
					'missingSelector'        => __( 'A CSS selector or at-rule is missing before this opening brace {.', 'cssflow' ),
					'missingSemicolon'       => __( 'A semicolon ; appears to be missing before the next CSS declaration.', 'cssflow' ),
					'missingColon'           => __( 'A colon : may be missing between a CSS property and its value.', 'cssflow' ),
				),
			)
		);

		wp_enqueue_script(
			'cssflow-admin',
			CSSFLOW_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			CSSFLOW_VERSION,
			true
		);

		wp_localize_script(
			'cssflow-admin',
			'CSSFlowAdmin',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'minSearchLength' => 2,
				'contentSearch'   => array(
					'action' => 'cssflow_search_content',
					'nonce'  => wp_create_nonce( 'cssflow_search_content' ),
				),
				'wooSearch'       => array(
					'action' => 'cssflow_search_woocommerce_targets',
					'nonce'  => wp_create_nonce( 'cssflow_search_woocommerce_targets' ),
				),
				'strings'         => array(
					'enterSearch'         => __( 'Enter at least 2 characters to search.', 'cssflow' ),
					'selectPostType'      => __( 'Select a content type before searching.', 'cssflow' ),
					'searching'           => __( 'Searching…', 'cssflow' ),
					'noResults'           => __( 'No matching items were found.', 'cssflow' ),
					'searchFailed'        => __( 'CSSFlow could not complete the search. Please try again.', 'cssflow' ),
					'oneResult'           => __( '1 result found.', 'cssflow' ),
					/* translators: %1$d: Number of search results found. */
					'manyResults'         => __( '%1$d results found.', 'cssflow' ),
					'addedTarget'         => __( 'Target added.', 'cssflow' ),
					'alreadySelected'     => __( 'That target is already selected.', 'cssflow' ),
					'removedTarget'       => __( 'Target removed.', 'cssflow' ),
					'selectionCleared'    => __( 'Selected content was cleared because the content type changed.', 'cssflow' ),
					'wooSelectionCleared' => __( 'Selected WooCommerce targets were cleared because the WooCommerce context changed.', 'cssflow' ),
					'noSelection'         => __( 'No targets selected yet.', 'cssflow' ),
					'remove'              => __( 'Remove', 'cssflow' ),
				),
			)
		);
	}

	/**
	 * Determine whether CSSFlow syntax highlighting is enabled globally.
	 *
	 * Existing installations that predate the setting default to enabled. The
	 * current user's WordPress profile preference remains authoritative because
	 * wp_enqueue_code_editor() returns false when syntax highlighting is disabled
	 * for that user.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether CSSFlow may request the WordPress code editor.
	 */
	private function is_syntax_highlighting_enabled() {
		$settings = get_option( 'cssflow_settings', array() );

		if ( ! is_array( $settings ) || ! array_key_exists( 'enable_syntax_highlighting', $settings ) ) {
			return true;
		}

		return ! empty( $settings['enable_syntax_highlighting'] );
	}

	/**
	 * Determine whether the current request is the CSSFlow All CSS screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current administration page hook suffix.
	 * @return bool Whether All CSS screen styles should load.
	 */
	private function is_snippets_screen( $hook_suffix ) {
		if ( 'toplevel_page_cssflow' === $hook_suffix ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only CSSFlow screen detection; no state change occurs here.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'cssflow' === $page;
	}

	/**
	 * Determine whether the current request is the CSSFlow Tools screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current administration page hook suffix.
	 * @return bool Whether Tools screen styles should load.
	 */
	private function is_tools_screen( $hook_suffix ) {
		if ( 'cssflow_page_cssflow-tools' === $hook_suffix ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only CSSFlow screen detection; no state change occurs here.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'cssflow-tools' === $page;
	}

	/**
	 * Determine whether the current request is the CSSFlow Settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current administration page hook suffix.
	 * @return bool Whether Settings screen styles should load.
	 */
	private function is_settings_screen( $hook_suffix ) {
		if ( 'cssflow_page_cssflow-settings' === $hook_suffix ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only CSSFlow screen detection; no state change occurs here.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'cssflow-settings' === $page;
	}

	/**
	 * Determine whether the current request is the CSSFlow Breakpoints screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current administration page hook suffix.
	 * @return bool Whether Breakpoints screen styles should load.
	 */
	private function is_breakpoints_screen( $hook_suffix ) {
		if ( 'cssflow_page_cssflow-breakpoints' === $hook_suffix ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only CSSFlow screen detection; no state change occurs here.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'cssflow-breakpoints' === $page;
	}


	/**
	 * Determine whether the current request is the CSSFlow Variables screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current administration page hook suffix.
	 * @return bool Whether Variables screen styles should load.
	 */
	private function is_variables_screen( $hook_suffix ) {
		if ( 'cssflow_page_cssflow-variables' === $hook_suffix ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only CSSFlow screen detection; no state change occurs here.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'cssflow-variables' === $page;
	}

	/**
	 * Determine whether the current request is the CSSFlow Add/Edit CSS screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current administration page hook suffix.
	 * @return bool Whether editor assets should load.
	 */
	private function is_editor_screen( $hook_suffix ) {
		if ( 'cssflow_page_cssflow-add-css' === $hook_suffix ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only CSSFlow screen detection; no state change occurs here.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'cssflow-add-css' === $page;
	}
	/**
	 * Determine whether the current request is the CSSFlow Support screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current administration page hook suffix.
	 * @return bool Whether Support screen styles should load.
	 */
	private function is_support_screen( $hook_suffix ) {
		if ( 'cssflow_page_cssflow-support' === $hook_suffix ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only CSSFlow screen detection; no state change occurs here.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'cssflow-support' === $page;
	}
}
