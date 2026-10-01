<?php
/**
 * Handles authenticated CSSFlow administration AJAX requests.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Admin;

use CSSFlow\CSS\Output_Manager;
use CSSFlow\Database\Snippet_Repository;
use CSSFlow\Integrations\WooCommerce;
use CSSFlow\Support\Capabilities;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSSFlow AJAX controller.
 */
final class Ajax {

	/**
	 * Snippet repository.
	 *
	 * @var Snippet_Repository|null
	 */
	private $repository;

	/**
	 * Output manager.
	 *
	 * @var Output_Manager|null
	 */
	private $output_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Snippet_Repository|null $repository     Snippet repository.
	 * @param Output_Manager|null     $output_manager Output manager.
	 */
	public function __construct( Snippet_Repository $repository = null, Output_Manager $output_manager = null ) {
		$this->repository     = $repository;
		$this->output_manager = $output_manager;
	}

	/**
	 * Minimum search characters for targeting pickers.
	 *
	 * @var int
	 */
	const MIN_SEARCH_LENGTH = 2;

	/**
	 * Maximum search results returned per request.
	 *
	 * @var int
	 */
	const RESULTS_PER_PAGE = 20;

	/**
	 * Register authenticated AJAX actions.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_cssflow_search_content', array( $this, 'search_content' ) );
		add_action( 'wp_ajax_cssflow_search_woocommerce_targets', array( $this, 'search_woocommerce_targets' ) );
		add_action( 'wp_ajax_cssflow_toggle_snippet_status', array( $this, 'toggle_snippet_status' ) );
	}

	/**
	 * Toggle one snippet status from the All CSS screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function toggle_snippet_status() {
		if ( ! current_user_can( Capabilities::get_edit_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to change CSSFlow snippet status.', 'cssflow' ),
				),
				403
			);
		}

		if ( false === check_ajax_referer( 'cssflow_toggle_snippet_status', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The CSSFlow status request could not be verified. Refresh the page and try again.', 'cssflow' ),
				),
				403
			);
		}

		if ( ! $this->repository instanceof Snippet_Repository || ! $this->output_manager instanceof Output_Manager ) {
			wp_send_json_error(
				array(
					'message' => __( 'CSSFlow could not complete the status change.', 'cssflow' ),
				),
				500
			);
		}

		$snippet_id = isset( $_POST['snippet_id'] ) ? absint( wp_unslash( $_POST['snippet_id'] ) ) : 0;
		$status     = isset( $_POST['status'] ) && is_scalar( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '';

		if ( 0 === $snippet_id || ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The requested CSSFlow status change is invalid.', 'cssflow' ),
				),
				400
			);
		}

		$snippet = $this->repository->get( $snippet_id );

		if ( null === $snippet ) {
			wp_send_json_error(
				array(
					'message' => __( 'The requested CSS snippet could not be found.', 'cssflow' ),
				),
				404
			);
		}

		$result = $this->repository->update_status( $snippet_id, $status );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $this->get_status_error_message( $result->get_error_code(), $status ),
				),
				400
			);
		}

		if ( false === $result ) {
			wp_send_json_error(
				array(
					'message' => __( 'The requested CSS snippet could not be found.', 'cssflow' ),
				),
				404
			);
		}

		$regenerated = $this->output_manager->regenerate();
		$is_active   = 'active' === $status;
		$counts      = array(
			'all'      => $this->repository->count_for_admin(),
			'active'   => $this->repository->count_for_admin( array( 'status' => 'active' ) ),
			'inactive' => $this->repository->count_for_admin( array( 'status' => 'inactive' ) ),
		);

		$response = array(
			'snippet_id'   => $snippet_id,
			'status'       => $status,
			'is_active'    => $is_active,
			'state_label'  => $is_active ? __( 'Active', 'cssflow' ) : __( 'Inactive', 'cssflow' ),
			'action_label' => $is_active ? __( 'Deactivate', 'cssflow' ) : __( 'Activate', 'cssflow' ),
			'counts'       => $counts,
		);

		if ( is_wp_error( $regenerated ) ) {
			$response['notice_type'] = 'warning';
			$response['message']     = __( 'The snippet status was saved, but CSSFlow could not clear old generated cache files. Frontend output will still rebuild from current source data.', 'cssflow' );

			wp_send_json_success( $response );
		}

		$response['notice_type'] = 'success';
		$response['message']     = $is_active ? __( 'CSS snippet activated.', 'cssflow' ) : __( 'CSS snippet deactivated.', 'cssflow' );

		wp_send_json_success( $response );
	}


	/**
	 * Convert a status-update validation error into an actionable admin message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code   WP_Error code.
	 * @param string $status Requested status.
	 * @return string User-facing message.
	 */
	private function get_status_error_message( $code, $status ) {
		if ( 'active' !== $status ) {
			return __( 'CSSFlow could not save the status change. Please try again.', 'cssflow' );
		}

		$messages = array(
			'cssflow_invalid_target_post_type'        => __( 'This snippet targets a content type that is currently unavailable. Activate the plugin or theme that provides it, or edit the snippet and choose another target before activating it.', 'cssflow' ),
			'cssflow_invalid_target_ids'              => __( 'One or more content items targeted by this snippet are no longer available. Edit the snippet and choose valid content before activating it.', 'cssflow' ),
			'cssflow_woocommerce_unavailable'         => __( 'This snippet uses WooCommerce targeting, but WooCommerce is not active. Activate WooCommerce or edit the snippet before activating it.', 'cssflow' ),
			'cssflow_invalid_woocommerce_product_ids' => __( 'One or more WooCommerce Products targeted by this snippet are no longer available. Edit the snippet before activating it.', 'cssflow' ),
			'cssflow_invalid_woocommerce_term_ids'    => __( 'One or more WooCommerce Product Categories targeted by this snippet are no longer available. Edit the snippet before activating it.', 'cssflow' ),
			'cssflow_invalid_breakpoint'              => __( 'This snippet uses a custom breakpoint that is no longer available. Edit the snippet and choose a valid responsive target before activating it.', 'cssflow' ),
		);

		return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'This snippet cannot be activated because its saved targeting is currently unavailable or invalid. Edit the snippet and correct its targeting, then try again.', 'cssflow' );
	}

	/**
	 * Search targetable WordPress content for the generic picker.
	 *
	 * Search results intentionally match titles beginning with the submitted
	 * phrase instead of using WordPress's broad full-text "s" search.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function search_content() {
		if ( ! current_user_can( Capabilities::get_edit_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to search CSSFlow targeting content.', 'cssflow' ),
				),
				403
			);
		}

		if ( false === check_ajax_referer( 'cssflow_search_content', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The CSSFlow targeting search request could not be verified.', 'cssflow' ),
				),
				403
			);
		}

		$post_type = isset( $_GET['post_type'] ) && is_scalar( $_GET['post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) : '';
		$search    = isset( $_GET['search'] ) && is_scalar( $_GET['search'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['search'] ) ) : '';
		$page      = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;

		if ( ! Snippet_Repository::is_phase8_targetable_post_type( $post_type ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The selected content type cannot be searched for CSSFlow targeting.', 'cssflow' ),
				),
				400
			);
		}

		if ( self::string_length( $search ) < self::MIN_SEARCH_LENGTH ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: minimum number of search characters. */
						__( 'Enter at least %d characters to search.', 'cssflow' ),
						self::MIN_SEARCH_LENGTH
					),
				),
				400
			);
		}

		$results = $this->search_posts_by_title_prefix( $post_type, $search, $page );

		wp_send_json_success( $results );
	}

	/**
	 * Search WooCommerce Products or Product Categories for Phase 9 targeting.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function search_woocommerce_targets() {
		if ( ! current_user_can( Capabilities::get_edit_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to search WooCommerce targeting data.', 'cssflow' ),
				),
				403
			);
		}

		if ( false === check_ajax_referer( 'cssflow_search_woocommerce_targets', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The CSSFlow WooCommerce targeting search request could not be verified.', 'cssflow' ),
				),
				403
			);
		}

		if ( ! class_exists( WooCommerce::class ) || ! WooCommerce::is_available() ) {
			wp_send_json_error(
				array(
					'message' => __( 'WooCommerce targeting is unavailable because WooCommerce is not active.', 'cssflow' ),
				),
				400
			);
		}

		$target = isset( $_GET['target'] ) && is_scalar( $_GET['target'] ) ? sanitize_key( wp_unslash( (string) $_GET['target'] ) ) : '';
		$search = isset( $_GET['search'] ) && is_scalar( $_GET['search'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['search'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;

		if ( ! in_array( $target, array( 'products', 'product_categories' ), true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The selected WooCommerce target type is invalid.', 'cssflow' ),
				),
				400
			);
		}

		if ( self::string_length( $search ) < self::MIN_SEARCH_LENGTH ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: minimum number of search characters. */
						__( 'Enter at least %d characters to search.', 'cssflow' ),
						self::MIN_SEARCH_LENGTH
					),
				),
				400
			);
		}

		if ( 'products' === $target ) {
			wp_send_json_success( $this->search_posts_by_title_prefix( 'product', $search, $page ) );
		}

		wp_send_json_success( $this->search_product_categories( $search, $page ) );
	}

	/**
	 * Search a post type using CSSFlow's title-prefix behavior.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type.
	 * @param string $search    Search phrase.
	 * @param int    $page      Result page.
	 * @return array<string,mixed> AJAX response data.
	 */
	private function search_posts_by_title_prefix( $post_type, $search, $page ) {
		add_filter( 'posts_where', array( $this, 'filter_title_prefix' ), 10, 2 );

		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array(
					'publish',
					'private',
					'draft',
					'pending',
					'future',
				),
				'posts_per_page'         => self::RESULTS_PER_PAGE,
				'paged'                  => $page,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'perm'                   => 'editable',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'cssflow_title_prefix'   => $search,
				'suppress_filters'       => false,
			)
		);

		remove_filter( 'posts_where', array( $this, 'filter_title_prefix' ), 10 );

		$results = array();

		foreach ( $query->posts as $object_id ) {
			$object_id = absint( $object_id );

			if (
				0 === $object_id
				|| get_post_type( $object_id ) !== $post_type
				|| ! current_user_can( 'edit_post', $object_id )
			) {
				continue;
			}

			$title = get_post_field( 'post_title', $object_id, 'raw' );
			$title = is_string( $title ) ? trim( wp_strip_all_tags( $title ) ) : '';

			if ( '' === $title ) {
				$title = __( '(no title)', 'cssflow' );
			}

			$results[] = array(
				'id'   => $object_id,
				'text' => sprintf(
					/* translators: 1: item name or title, 2: item ID. */
					__( '%1$s (#%2$d)', 'cssflow' ),
					$title,
					$object_id
				),
			);
		}

		return array(
			'results' => $results,
			'page'    => $page,
			'more'    => $page < (int) $query->max_num_pages,
		);
	}

	/**
	 * Search WooCommerce Product Categories without preloading the taxonomy.
	 *
	 * One extra term is requested so pagination can be reported without a second
	 * count query.
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Search phrase.
	 * @param int    $page   Result page.
	 * @return array<string,mixed> AJAX response data.
	 */
	private function search_product_categories( $search, $page ) {
		$offset = ( $page - 1 ) * self::RESULTS_PER_PAGE;
		$terms  = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'name__like' => $search,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => self::RESULTS_PER_PAGE + 1,
				'offset'     => $offset,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array(
				'results' => array(),
				'page'    => $page,
				'more'    => false,
			);
		}

		$more = count( $terms ) > self::RESULTS_PER_PAGE;

		if ( $more ) {
			array_pop( $terms );
		}

		$results = array();

		foreach ( $terms as $term ) {
			if ( ! isset( $term->term_id, $term->taxonomy, $term->name ) || 'product_cat' !== $term->taxonomy ) {
				continue;
			}

			$term_id = absint( $term->term_id );

			if ( 0 === $term_id ) {
				continue;
			}

			$results[] = array(
				'id'   => $term_id,
				'text' => sprintf(
					/* translators: 1: item name or title, 2: item ID. */
					__( '%1$s (#%2$d)', 'cssflow' ),
					sanitize_text_field( $term->name ),
					$term_id
				),
			);
		}

		return array(
			'results' => $results,
			'page'    => $page,
			'more'    => $more,
		);
	}

	/**
	 * Restrict a CSSFlow targeting query to titles beginning with the phrase.
	 *
	 * This filter activates only when the private CSSFlow query variable is
	 * present, and is added/removed immediately around the AJAX WP_Query.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $where SQL WHERE clause.
	 * @param WP_Query $query WordPress query instance.
	 * @return string Filtered WHERE clause.
	 */
	public function filter_title_prefix( $where, $query ) {
		global $wpdb;

		$prefix = $query->get( 'cssflow_title_prefix' );

		if ( ! is_string( $prefix ) || '' === $prefix ) {
			return $where;
		}

		$where .= $wpdb->prepare(
			" AND {$wpdb->posts}.post_title LIKE %s",
			$wpdb->esc_like( $prefix ) . '%'
		);

		return $where;
	}

	/**
	 * Get a multibyte-safe string length when the extension is available.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value String value.
	 * @return int
	 */
	private static function string_length( $value ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value );
		}

		return strlen( $value );
	}
}
