<?php
/**
 * Registers CSSFlow administration screens and state-changing admin actions.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Admin;

use CSSFlow\CSS\Output_Manager;
use CSSFlow\Database\Snippet_Repository;
use CSSFlow\Integrations\WooCommerce;
use CSSFlow\Support\Capabilities;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow administration controller.
 */
final class Admin {

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
	 * Administration asset loader.
	 *
	 * @var Assets
	 */
	private $assets;

	/**
	 * Snippets list page.
	 *
	 * @var Pages\Snippets_Page
	 */
	private $snippets_page;

	/**
	 * Snippet editor page.
	 *
	 * @var Pages\Editor_Page
	 */
	private $editor_page;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Snippet_Repository $repository     Snippet repository.
	 * @param Output_Manager     $output_manager Output manager.
	 */
	public function __construct( Snippet_Repository $repository, Output_Manager $output_manager ) {
		$this->repository     = $repository;
		$this->output_manager = $output_manager;
		$this->assets         = new Assets();
		$this->snippets_page  = new Pages\Snippets_Page( $repository );
		$this->editor_page    = new Pages\Editor_Page( $repository );
	}

	/**
	 * Register CSSFlow administration hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		$this->assets->register();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CSSFLOW_FILE ), array( $this, 'add_plugin_action_links' ) );
		add_action( 'load-toplevel_page_cssflow', array( $this, 'add_snippets_screen_options' ) );
		add_filter( 'set_screen_option_cssflow_snippets_per_page', array( $this, 'save_snippets_per_page' ), 10, 3 );
		add_filter( 'admin_title', array( $this, 'filter_admin_title' ), 10, 2 );
		add_filter( 'submenu_file', array( $this, 'filter_submenu_file' ), 10, 2 );
		add_action( 'admin_post_cssflow_save_snippet', array( $this, 'handle_save_snippet' ) );
		add_action( 'admin_post_cssflow_toggle_snippet', array( $this, 'handle_toggle_snippet' ) );
		add_action( 'admin_post_cssflow_duplicate_snippet', array( $this, 'handle_duplicate_snippet' ) );
		add_action( 'admin_post_cssflow_delete_snippet', array( $this, 'handle_delete_snippet' ) );
		add_action( 'admin_post_cssflow_bulk_snippets', array( $this, 'handle_bulk_snippets' ) );
	}

	/**
	 * Add a direct CSSFlow management link on the Installed Plugins screen.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,string> $links Existing plugin action links.
	 * @return array<string,string> Filtered plugin action links.
	 */
	public function add_plugin_action_links( $links ) {
		if ( ! current_user_can( Capabilities::get_edit_capability() ) ) {
			return $links;
		}

		$manage_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=cssflow' ) ),
			esc_html__( 'Manage CSS', 'cssflow' )
		);

		$filtered_links = array();

		foreach ( $links as $key => $link ) {
			$filtered_links[ $key ] = $link;

			if ( 'deactivate' === $key ) {
				$filtered_links['cssflow-manage'] = $manage_link;
			}
		}

		if ( ! isset( $filtered_links['cssflow-manage'] ) ) {
			$filtered_links['cssflow-manage'] = $manage_link;
		}

		return $filtered_links;
	}


	/**
	 * Get the CSSFlow administration menu icon.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function get_menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20">' .
			'<path fill="#ffffff" d="M 5.8 2.0 C 3.2 2.0 1.8 3.5 1.8 5.8 V 7.1 C 1.8 8.1 1.0 8.8 0.3 9.2 C 0.1 9.3 0 9.6 0 10.0 C 0 10.4 0.1 10.7 0.3 10.8 C 1.0 11.2 1.8 11.9 1.8 12.9 V 14.2 C 1.8 16.5 3.2 18.0 5.8 18.0 H 6.0 C 6.7 18.0 7.2 17.4 7.2 16.7 C 7.2 16.0 6.7 15.5 6.0 15.5 H 5.8 C 4.3 15.5 4.2 14.8 4.2 14.2 V 12.9 C 4.2 11.0 2.9 10.3 2.0 10.0 C 2.9 9.7 4.2 9.0 4.2 7.1 V 5.8 C 4.2 5.2 4.3 4.5 5.8 4.5 H 6.0 C 6.7 4.5 7.2 3.9 7.2 3.2 C 7.2 2.5 6.7 2.0 6.0 2.0 Z" />' .
			'<path fill="#ffffff" d="M 5.8 9.0 C 7.2 6.8 8.8 6.8 10.0 8.8 C 11.2 10.8 12.8 10.8 14.2 8.6 C 14.8 9.2 14.8 10.3 14.2 10.9 C 12.8 13.1 11.2 13.1 10.0 11.1 C 8.8 9.1 7.2 9.1 5.8 11.3 C 5.2 10.7 5.2 9.6 5.8 9.0 Z" />' .
			'<path fill="#ffffff" d="M 14.2 2.0 C 13.5 2.0 13.0 2.5 13.0 3.2 C 13.0 3.9 13.5 4.5 14.2 4.5 H 14.2 C 15.7 4.5 15.8 5.2 15.8 5.8 V 7.1 C 15.8 9.0 17.1 9.7 18.0 10.0 C 17.1 10.3 15.8 11.0 15.8 12.9 V 14.2 C 15.8 14.8 15.7 15.5 14.2 15.5 H 14.2 C 13.5 15.5 13.0 16.0 13.0 16.7 C 13.0 17.4 13.5 18.0 14.2 18.0 H 14.2 C 16.8 18.0 18.2 16.5 18.2 14.2 V 12.9 C 18.2 11.9 19.0 11.2 19.7 10.8 C 19.9 10.7 20 10.4 20 10.0 C 20 9.6 19.9 9.3 19.7 9.2 C 19.0 8.8 18.2 8.1 18.2 7.1 V 5.8 C 18.2 3.5 16.8 2.0 14.2 2.0 Z" />' .
			'</svg>';

		// Base64 encoding is required for WordPress admin-menu SVG data URIs.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a static SVG admin-menu icon; no executable code is being obfuscated.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}


	/**
	 * Register CSSFlow admin menu entries.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menu() {
		$capability = Capabilities::get_edit_capability();

		add_menu_page(
			__( 'CSSFlow', 'cssflow' ),
			__( 'CSSFlow', 'cssflow' ),
			$capability,
			'cssflow',
			array( $this->snippets_page, 'render' ),
			$this->get_menu_icon(),
			58
		);

		add_submenu_page(
			'cssflow',
			__( 'All CSS', 'cssflow' ),
			__( 'All CSS', 'cssflow' ),
			$capability,
			'cssflow',
			array( $this->snippets_page, 'render' )
		);

		add_submenu_page(
			'cssflow',
			__( 'Add CSS', 'cssflow' ),
			__( 'Add CSS', 'cssflow' ),
			$capability,
			'cssflow-add-css',
			array( $this->editor_page, 'render' )
		);
	}


	/**
	 * Add the per-page Screen Option to the All CSS management screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_snippets_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'CSS snippets', 'cssflow' ),
				'default' => 20,
				'option'  => 'cssflow_snippets_per_page',
			)
		);
	}

	/**
	 * Validate the saved All CSS per-page Screen Option.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $status Current screen-option save status.
	 * @param string $option Screen-option key.
	 * @param mixed  $value  Submitted option value.
	 * @return int|mixed Sanitized per-page value or the unchanged status.
	 */
	public function save_snippets_per_page( $status, $option, $value ) {
		if ( 'cssflow_snippets_per_page' !== $option ) {
			return $status;
		}

		$value = absint( $value );

		if ( $value < 1 ) {
			return $status;
		}

		return min( $value, 999 );
	}


	/**
	 * Highlight All CSS while editing an existing snippet.
	 *
	 * The editor is registered under the Add CSS submenu so WordPress would
	 * otherwise highlight Add CSS even for an existing snippet. Editing belongs
	 * to the All CSS collection, while a genuinely new snippet keeps Add CSS
	 * highlighted.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @param string|null $parent_file  Current parent file.
	 * @return string|null Filtered submenu file.
	 */
	public function filter_submenu_file( $submenu_file, $parent_file ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing parameter; no state change occurs here.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'cssflow' !== $parent_file || 'cssflow-add-css' !== $page ) {
			return $submenu_file;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor routing parameter; no state change occurs here.
		$snippet_id = isset( $_GET['snippet_id'] ) ? absint( wp_unslash( $_GET['snippet_id'] ) ) : 0;

		if ( $snippet_id > 0 && null !== $this->repository->get( $snippet_id ) ) {
			return 'cssflow';
		}

		return $submenu_file;
	}

	/**
	 * Change the browser/admin document title when editing an existing snippet.
	 *
	 * WordPress registers the shared editor submenu as "Add CSS". The visible
	 * editor heading can change at render time, but the browser tab title is
	 * generated earlier from the registered submenu title. This filter keeps the
	 * registered Add CSS screen while presenting Edit CSS for valid edit requests.
	 *
	 * @since 1.0.0
	 *
	 * @param string $admin_title Full admin document title.
	 * @param string $title       Current admin page title.
	 * @return string Filtered admin document title.
	 */
	public function filter_admin_title( $admin_title, $title ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing parameter; no state change occurs here.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'cssflow-add-css' !== $page ) {
			return $admin_title;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor routing parameter; no state change occurs here.
		$snippet_id = isset( $_GET['snippet_id'] ) ? absint( wp_unslash( $_GET['snippet_id'] ) ) : 0;

		if ( 0 === $snippet_id || null === $this->repository->get( $snippet_id ) ) {
			return $admin_title;
		}

		$edit_title = __( 'Edit CSS', 'cssflow' );

		if ( '' !== $title && false !== strpos( $admin_title, $title ) ) {
			return preg_replace( '/^' . preg_quote( $title, '/' ) . '/', $edit_title, $admin_title, 1 );
		}

		return $edit_title . ' ' . $admin_title;
	}

	/**
	 * Create or update a CSS snippet.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_save_snippet() {
		$this->require_edit_capability();
		check_admin_referer( 'cssflow_save_snippet', 'cssflow_nonce' );

		$snippet_id = isset( $_POST['snippet_id'] ) ? absint( wp_unslash( $_POST['snippet_id'] ) ) : 0;
		$existing   = null;

		if ( $snippet_id > 0 ) {
			$existing = $this->repository->get( $snippet_id );

			if ( null === $existing ) {
				$this->redirect_to_list( 'not_found' );
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability verified above; individual fields are normalized and validated by the request helpers.
		$request = wp_unslash( $_POST );
		$data    = $this->get_submitted_snippet_data( $existing, $request );

		if ( is_wp_error( $data ) ) {
			$this->redirect_to_editor( $snippet_id, 'validation_error', $data->get_error_code() );
		}

		if ( $snippet_id > 0 ) {
			if ( $this->should_preserve_unavailable_targeting( $existing, $request, $data['status'] ) ) {
				$result = $this->repository->update_inactive_preserving_targeting( $snippet_id, $data );
			} else {
				$result = $this->repository->update( $snippet_id, $data );
			}
		} else {
			$result = $this->repository->create( $data );
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_editor( $snippet_id, 'save_error', $result->get_error_code() );
		}

		if ( false === $result ) {
			$this->redirect_to_list( 'not_found' );
		}

		if ( 0 === $snippet_id ) {
			$snippet_id = (int) $result;
		}

		$regenerated = $this->output_manager->regenerate();
		$notice      = is_wp_error( $regenerated ) ? 'saved_cache_warning' : 'saved';

		$this->redirect_to_editor( $snippet_id, $notice );
	}

	/**
	 * Enable or disable one snippet.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_toggle_snippet() {
		$this->require_edit_capability();

		$snippet_id = isset( $_GET['snippet_id'] ) ? absint( wp_unslash( $_GET['snippet_id'] ) ) : 0;
		$status     = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( 0 === $snippet_id || ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$this->redirect_to_list( 'invalid_action' );
		}

		check_admin_referer( 'cssflow_toggle_snippet_' . $snippet_id . '_' . $status );

		$snippet = $this->repository->get( $snippet_id );

		if ( null === $snippet ) {
			$this->redirect_to_list( 'not_found' );
		}

		$result = $this->repository->update_status( $snippet_id, $status );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_list( 'action_failed', $result->get_error_code() );
		}

		if ( false === $result ) {
			$this->redirect_to_list( 'not_found' );
		}

		$regenerated = $this->output_manager->regenerate();

		if ( is_wp_error( $regenerated ) ) {
			$this->redirect_to_list( 'cache_warning' );
		}

		$this->redirect_to_list( 'active' === $status ? 'enabled' : 'disabled' );
	}

	/**
	 * Duplicate one snippet as an inactive copy.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_duplicate_snippet() {
		$this->require_edit_capability();

		$snippet_id = isset( $_GET['snippet_id'] ) ? absint( wp_unslash( $_GET['snippet_id'] ) ) : 0;

		if ( 0 === $snippet_id ) {
			$this->redirect_to_list( 'invalid_action' );
		}

		check_admin_referer( 'cssflow_duplicate_snippet_' . $snippet_id );

		$snippet = $this->repository->get( $snippet_id );

		if ( null === $snippet ) {
			$this->redirect_to_list( 'not_found' );
		}

		$result = $this->repository->create(
			array(
				'name'            => sprintf(
					/* translators: %s: original snippet name. */
					__( 'Copy of %s', 'cssflow' ),
					$snippet['name']
				),
				'description'     => $snippet['description'],
				'css_code'        => $snippet['css_code'],
				'scope_type'      => $snippet['scope_type'],
				'scope_data'      => $snippet['scope_data'],
				'responsive_type' => $snippet['responsive_type'],
				'breakpoint_key'  => $snippet['breakpoint_key'],
				'priority'        => $snippet['priority'],
				'status'          => 'inactive',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_list( 'action_failed' );
		}

		$this->redirect_to_editor( (int) $result, 'duplicated' );
	}

	/**
	 * Delete one snippet permanently.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_delete_snippet() {
		$this->require_edit_capability();

		$snippet_id = isset( $_GET['snippet_id'] ) ? absint( wp_unslash( $_GET['snippet_id'] ) ) : 0;

		if ( 0 === $snippet_id ) {
			$this->redirect_to_list( 'invalid_action' );
		}

		check_admin_referer( 'cssflow_delete_snippet_' . $snippet_id );

		if ( null === $this->repository->get( $snippet_id ) ) {
			$this->redirect_to_list( 'not_found' );
		}

		if ( ! $this->repository->delete( $snippet_id ) ) {
			$this->redirect_to_list( 'action_failed' );
		}

		$regenerated = $this->output_manager->regenerate();

		if ( is_wp_error( $regenerated ) ) {
			$this->redirect_to_list( 'cache_warning' );
		}

		$this->redirect_to_list( 'deleted' );
	}

	/**
	 * Process Phase 6 bulk snippet actions.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_bulk_snippets() {
		$this->require_edit_capability();
		check_admin_referer( 'cssflow_bulk_snippets', 'cssflow_bulk_nonce' );

		$request = wp_unslash( $_POST );

		if ( isset( $request['filter_action'] ) ) {
			$this->redirect_to_filtered_list( $request );
		}

		$action = isset( $request['bulk_action'] ) && is_scalar( $request['bulk_action'] ) ? sanitize_key( (string) $request['bulk_action'] ) : '';
		$ids    = isset( $request['snippet_ids'] ) && is_array( $request['snippet_ids'] ) ? $request['snippet_ids'] : array();
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) || ! in_array( $action, array( 'enable', 'disable', 'delete' ), true ) ) {
			$this->redirect_to_list( 'invalid_bulk' );
		}

		$changed     = 0;
		$first_error = '';

		foreach ( $ids as $snippet_id ) {
			if ( null === $this->repository->get( $snippet_id ) ) {
				continue;
			}

			if ( 'delete' === $action ) {
				if ( $this->repository->delete( $snippet_id ) ) {
					++$changed;
				}
				continue;
			}

			$status = 'enable' === $action ? 'active' : 'inactive';
			$result = $this->repository->update_status( $snippet_id, $status );

			if ( true === $result ) {
				++$changed;
			} elseif ( is_wp_error( $result ) && '' === $first_error ) {
				$first_error = $result->get_error_code();
			}
		}

		if ( 0 === $changed ) {
			if ( '' !== $first_error ) {
				$this->redirect_to_list( 'action_failed', $first_error );
			}

			$this->redirect_to_list( 'bulk_no_changes' );
		}

		$regenerated = $this->output_manager->regenerate();

		if ( is_wp_error( $regenerated ) ) {
			$this->redirect_to_list( 'cache_warning' );
		}

		if ( 'enable' === $action && '' !== $first_error ) {
			$this->redirect_to_list( 'bulk_activated_partial', $first_error, $changed );
		}

		$notice = 'enable' === $action ? 'bulk_activated' : ( 'disable' === $action ? 'bulk_deactivated' : 'bulk_deleted' );

		$this->redirect_to_list( $notice, '', $changed );
	}


	/**
	 * Determine whether an inactive edit should preserve unavailable targeting.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $existing Existing snippet.
	 * @param array      $request  Unslashed request data.
	 * @param string     $status   Submitted snippet status.
	 * @return bool Whether unavailable targeting should be preserved.
	 */
	private function should_preserve_unavailable_targeting( $existing, $request, $status ) {
		if ( ! is_array( $existing ) || 'inactive' !== $status ) {
			return false;
		}

		if ( true === $this->repository->validate_targeting( $existing ) ) {
			return false;
		}

		if ( ! isset( $request['scope_type'] ) ) {
			return true;
		}

		$scope_type = is_scalar( $request['scope_type'] ) ? sanitize_key( (string) $request['scope_type'] ) : '';

		if ( $scope_type !== $existing['scope_type'] ) {
			return false;
		}

		if ( in_array( $scope_type, array( 'post_type', 'specific_content' ), true ) ) {
			$stored_post_type = isset( $existing['scope_data']['post_type'] ) ? sanitize_key( $existing['scope_data']['post_type'] ) : '';
			$posted_post_type = isset( $request['target_post_type'] ) && is_scalar( $request['target_post_type'] ) ? sanitize_key( (string) $request['target_post_type'] ) : '';

			return '' !== $stored_post_type && $stored_post_type === $posted_post_type;
		}

		if ( 'woocommerce' === $scope_type ) {
			$stored_context = isset( $existing['scope_data']['context'] ) ? sanitize_key( $existing['scope_data']['context'] ) : '';
			$posted_context = isset( $request['woocommerce_context'] ) && is_scalar( $request['woocommerce_context'] ) ? sanitize_key( (string) $request['woocommerce_context'] ) : '';

			return '' === $posted_context || $stored_context === $posted_context;
		}

		return false;
	}

	/**
	 * Build normalized snippet data from the editor request.
	 *
	 * Existing non-global targeting is deliberately preserved server-side until
	 * the dedicated targeting UI is available. New Phase 6 snippets are global.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $existing Existing snippet or null for a new snippet.
	 * @param array      $request  Unslashed request data captured after nonce verification.
	 * @return array|WP_Error Normalized request data or a request validation error.
	 */
	private function get_submitted_snippet_data( $existing, $request ) {
		$name            = isset( $request['name'] ) && is_scalar( $request['name'] ) ? sanitize_text_field( (string) $request['name'] ) : '';
		$description     = isset( $request['description'] ) && is_scalar( $request['description'] ) ? sanitize_textarea_field( (string) $request['description'] ) : '';
		$css_code        = isset( $request['css_code'] ) && is_scalar( $request['css_code'] ) ? (string) $request['css_code'] : '';
		$responsive_type = isset( $request['responsive_type'] ) && is_scalar( $request['responsive_type'] ) ? sanitize_key( (string) $request['responsive_type'] ) : 'all';
		$breakpoint_key  = isset( $request['breakpoint_key'] ) && is_scalar( $request['breakpoint_key'] ) ? sanitize_key( (string) $request['breakpoint_key'] ) : '';
		$priority_raw    = isset( $request['priority'] ) && is_scalar( $request['priority'] ) ? trim( (string) $request['priority'] ) : '10';
		$status          = isset( $request['status'] ) && is_scalar( $request['status'] ) ? sanitize_key( (string) $request['status'] ) : 'active';

		if ( '' === $name ) {
			return new WP_Error( 'cssflow_invalid_name' );
		}

		if ( 1 !== preg_match( '/^\d+$/D', $priority_raw ) ) {
			return new WP_Error( 'cssflow_invalid_priority' );
		}

		$priority = (int) $priority_raw;

		if ( $priority < 0 || $priority > 65535 ) {
			return new WP_Error( 'cssflow_invalid_priority' );
		}

		if ( ! in_array( $responsive_type, array( 'all', 'desktop', 'tablet', 'mobile', 'custom' ), true ) ) {
			return new WP_Error( 'cssflow_invalid_responsive_type' );
		}

		if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
			return new WP_Error( 'cssflow_invalid_status' );
		}

		if ( $this->should_preserve_unavailable_targeting( $existing, $request, $status ) ) {
			$targeting = array(
				'scope_type' => $existing['scope_type'],
				'scope_data' => $existing['scope_data'],
			);
		} else {
			$targeting = $this->get_submitted_targeting_data( $existing, $request );

			if ( is_wp_error( $targeting ) ) {
				return $targeting;
			}
		}

		return array(
			'name'            => $name,
			'description'     => $description,
			'css_code'        => $css_code,
			'scope_type'      => $targeting['scope_type'],
			'scope_data'      => $targeting['scope_data'],
			'responsive_type' => $responsive_type,
			'breakpoint_key'  => 'custom' === $responsive_type ? $breakpoint_key : null,
			'priority'        => $priority,
			'status'          => $status,
		);
	}

	/**
	 * Build and authorize Phase 8 targeting data from the editor request.
	 *
	 * Until the Phase 8.2 editor controls are installed, the absence of a
	 * submitted scope_type preserves an existing snippet's targeting and keeps
	 * new snippets global. Once the controls are present, submitted targeting is
	 * treated as untrusted input and rebuilt from explicit allowlisted fields.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $existing Existing snippet or null for a new snippet.
	 * @param array      $request  Unslashed request data captured after nonce verification.
	 * @return array|WP_Error Targeting data or validation error.
	 */
	private function get_submitted_targeting_data( $existing, $request ) {
		if ( ! isset( $request['scope_type'] ) ) {
			if ( is_array( $existing ) ) {
				return array(
					'scope_type' => $existing['scope_type'],
					'scope_data' => $existing['scope_data'],
				);
			}

			return array(
				'scope_type' => 'global',
				'scope_data' => array(),
			);
		}

		$scope_type_raw = $request['scope_type'];
		$scope_type     = is_scalar( $scope_type_raw ) ? sanitize_key( (string) $scope_type_raw ) : '';

		if ( ! in_array( $scope_type, array( 'global', 'specific_content', 'post_type', 'special', 'woocommerce' ), true ) ) {
			return new WP_Error( 'cssflow_invalid_scope_type' );
		}

		if ( 'global' === $scope_type ) {
			return array(
				'scope_type' => 'global',
				'scope_data' => array(),
			);
		}

		if ( 'woocommerce' === $scope_type ) {
			return $this->get_submitted_woocommerce_targeting_data( $request );
		}

		if ( 'special' === $scope_type ) {
			$context_raw = isset( $request['special_context'] ) ? $request['special_context'] : '';
			$context     = is_scalar( $context_raw ) ? sanitize_key( (string) $context_raw ) : '';

			if ( ! in_array( $context, array( 'front_page', 'posts_page', 'search', '404' ), true ) ) {
				return new WP_Error( 'cssflow_invalid_special_context' );
			}

			return array(
				'scope_type' => 'special',
				'scope_data' => array(
					'context' => $context,
				),
			);
		}

		$post_type_raw = isset( $request['target_post_type'] ) ? $request['target_post_type'] : '';
		$post_type     = is_scalar( $post_type_raw ) ? sanitize_key( (string) $post_type_raw ) : '';

		if ( ! Snippet_Repository::is_phase8_targetable_post_type( $post_type ) ) {
			$existing_post_type = is_array( $existing ) && isset( $existing['scope_data']['post_type'] )
				? sanitize_key( $existing['scope_data']['post_type'] )
				: '';

			if ( $post_type !== $existing_post_type ) {
				return new WP_Error( 'cssflow_invalid_target_post_type' );
			}

			return array(
				'scope_type' => $existing['scope_type'],
				'scope_data' => $existing['scope_data'],
			);
		}

		if ( 'post_type' === $scope_type ) {
			return array(
				'scope_type' => 'post_type',
				'scope_data' => array(
					'post_type' => $post_type,
				),
			);
		}

		$raw_ids = isset( $request['target_ids'] ) ? $request['target_ids'] : array();

		if ( ! is_array( $raw_ids ) || empty( $raw_ids ) ) {
			return new WP_Error( 'cssflow_invalid_target_ids' );
		}

		$ids = array();

		foreach ( $raw_ids as $raw_id ) {
			$object_id = $this->normalize_positive_integer( $raw_id );

			if (
				0 === $object_id
				|| get_post_type( $object_id ) !== $post_type
				|| ! current_user_can( 'edit_post', $object_id )
			) {
				return new WP_Error( 'cssflow_invalid_target_ids' );
			}

			$ids[] = $object_id;
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );

		return array(
			'scope_type' => 'specific_content',
			'scope_data' => array(
				'post_type' => $post_type,
				'ids'       => $ids,
			),
		);
	}

	/**
	 * Build and authorize WooCommerce targeting data from the editor request.
	 *
	 * @since 1.0.0
	 *
	 * @param array $request Unslashed request data captured after nonce verification.
	 * @return array|WP_Error Targeting data or validation error.
	 */
	private function get_submitted_woocommerce_targeting_data( $request ) {
		if ( ! class_exists( WooCommerce::class ) || ! WooCommerce::is_available() ) {
			return new WP_Error( 'cssflow_woocommerce_unavailable' );
		}

		$context_raw = isset( $request['woocommerce_context'] ) ? $request['woocommerce_context'] : '';
		$context     = is_scalar( $context_raw ) ? sanitize_key( (string) $context_raw ) : '';

		if ( ! in_array( $context, WooCommerce::get_contexts(), true ) ) {
			return new WP_Error( 'cssflow_invalid_woocommerce_context' );
		}

		$scope_data = array(
			'context' => $context,
		);

		if ( 'specific_products' === $context ) {
			$raw_ids = isset( $request['woocommerce_product_ids'] ) ? $request['woocommerce_product_ids'] : array();

			if ( ! is_array( $raw_ids ) || empty( $raw_ids ) ) {
				return new WP_Error( 'cssflow_invalid_woocommerce_product_ids' );
			}

			$product_ids = array();

			foreach ( $raw_ids as $raw_id ) {
				$product_id = $this->normalize_positive_integer( $raw_id );

				if (
					0 === $product_id
					|| 'product' !== get_post_type( $product_id )
					|| ! current_user_can( 'edit_post', $product_id )
				) {
					return new WP_Error( 'cssflow_invalid_woocommerce_product_ids' );
				}

				$product_ids[] = $product_id;
			}

			$product_ids = array_values( array_unique( $product_ids ) );
			sort( $product_ids, SORT_NUMERIC );
			$scope_data['product_ids'] = $product_ids;
		}

		if ( 'product_category' === $context ) {
			$raw_ids = isset( $request['woocommerce_term_ids'] ) ? $request['woocommerce_term_ids'] : array();

			if ( ! is_array( $raw_ids ) || empty( $raw_ids ) ) {
				return new WP_Error( 'cssflow_invalid_woocommerce_term_ids' );
			}

			$term_ids = array();

			foreach ( $raw_ids as $raw_id ) {
				$term_id = $this->normalize_positive_integer( $raw_id );
				$term    = $term_id > 0 ? get_term( $term_id, 'product_cat' ) : null;

				if (
					0 === $term_id
					|| ! $term
					|| is_wp_error( $term )
					|| 'product_cat' !== $term->taxonomy
				) {
					return new WP_Error( 'cssflow_invalid_woocommerce_term_ids' );
				}

				$term_ids[] = $term_id;
			}

			$term_ids = array_values( array_unique( $term_ids ) );
			sort( $term_ids, SORT_NUMERIC );
			$scope_data['term_ids'] = $term_ids;
		}

		$normalized = WooCommerce::normalize_scope_data( $scope_data );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		return array(
			'scope_type' => 'woocommerce',
			'scope_data' => $normalized,
		);
	}

	/**
	 * Normalize a positive integer without accepting partial numeric values.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Candidate value.
	 * @return int Positive integer or zero when invalid.
	 */
	private function normalize_positive_integer( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9]\d*$/D', $value ) ) {
			return 0;
		}

		$value = (int) $value;

		return $value > 0 ? $value : 0;
	}

	/**
	 * Require the current user to have CSSFlow's editing capability.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function require_edit_capability() {
		if ( ! current_user_can( Capabilities::get_edit_capability() ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage CSSFlow snippets.', 'cssflow' ),
				esc_html__( 'CSSFlow', 'cssflow' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Redirect a submitted list filter back to the All CSS GET screen.
	 *
	 * @since 1.0.0
	 *
	 * @param array $request Unslashed request data captured after nonce verification.
	 * @return void
	 */
	private function redirect_to_filtered_list( $request ) {
		$args    = array( 'page' => 'cssflow' );
		$allowed = array(
			'status'          => array( 'active', 'inactive' ),
			'scope_type'      => array( 'global', 'specific_content', 'post_type', 'special', 'woocommerce' ),
			'responsive_type' => array( 'all', 'desktop', 'tablet', 'mobile', 'custom' ),
		);

		foreach ( $allowed as $key => $values ) {
			$value = isset( $request[ $key ] ) && is_scalar( $request[ $key ] ) ? sanitize_key( (string) $request[ $key ] ) : '';

			if ( in_array( $value, $values, true ) ) {
				$args[ $key ] = $value;
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Redirect to the All CSS page with a notice code.
	 *
	 * @since 1.0.0
	 *
	 * @param string $notice Notice code.
	 * @param string $error  Optional error code.
	 * @param int    $count  Optional changed-row count.
	 * @return void
	 */
	private function redirect_to_list( $notice, $error = '', $count = 0 ) {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			set_transient(
				'cssflow_list_notice_' . $user_id,
				array(
					'notice' => sanitize_key( $notice ),
					'error'  => sanitize_key( $error ),
					'count'  => absint( $count ),
				),
				MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=cssflow' ) );
		exit;
	}

	/**
	 * Redirect to the editor page with a notice code.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $snippet_id Snippet ID, or zero for a new snippet.
	 * @param string $notice     Notice code.
	 * @param string $error      Optional error code.
	 * @return void
	 */
	private function redirect_to_editor( $snippet_id, $notice, $error = '' ) {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			set_transient(
				'cssflow_editor_notice_' . $user_id,
				array(
					'notice' => sanitize_key( $notice ),
					'error'  => sanitize_key( $error ),
				),
				MINUTE_IN_SECONDS
			);
		}

		$args = array(
			'page' => 'cssflow-add-css',
		);

		if ( $snippet_id > 0 ) {
			$args['snippet_id'] = absint( $snippet_id );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
