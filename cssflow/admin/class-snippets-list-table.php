<?php
/**
 * WordPress list table for CSSFlow snippets.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Admin;

use CSSFlow\Database\Snippet_Repository;
use CSSFlow\Integrations\WooCommerce;
use WP_List_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the All CSS snippet table.
 */
final class Snippets_List_Table extends WP_List_Table {

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
		parent::__construct(
			array(
				'singular' => 'cssflow_snippet',
				'plural'   => 'cssflow_snippets',
				'ajax'     => false,
			)
		);

		$this->repository = $repository;
	}

	/**
	 * Render WordPress-style status views for the snippet list.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Status view links.
	 */
	protected function get_views() {
		$counts = array(
			'all'      => $this->repository->count_for_admin(),
			'active'   => $this->repository->count_for_admin( array( 'status' => 'active' ) ),
			'inactive' => $this->repository->count_for_admin( array( 'status' => 'inactive' ) ),
		);

		$current_status = $this->get_current_status_view();
		$views          = array();
		$labels         = array(
			'all'      => __( 'All', 'cssflow' ),
			'active'   => __( 'Active', 'cssflow' ),
			'inactive' => __( 'Inactive', 'cssflow' ),
		);

		foreach ( $labels as $status => $label ) {
			$is_current = $status === $current_status;
			$url        = $this->get_status_view_url( 'all' === $status ? '' : $status );

			$views[ $status ] = sprintf(
				'<a href="%1$s"%2$s%3$s data-cssflow-status-view="%4$s">%5$s <span class="count">(<span data-cssflow-status-count="%4$s">%6$s</span>)</span></a>',
				esc_url( $url ),
				$is_current ? ' class="current"' : '',
				$is_current ? ' aria-current="page"' : '',
				esc_attr( $status ),
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $status ] ) )
			);
		}

		return $views;
	}

	/**
	 * Resolve the selected status view from the request.
	 *
	 * @since 1.0.0
	 *
	 * @return string Current status view key.
	 */
	private function get_current_status_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering; no state change occurs here.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return in_array( $status, array( 'active', 'inactive' ), true ) ? $status : 'all';
	}

	/**
	 * Build a sanitized URL for a status view while preserving compatible list filters.
	 *
	 * Pagination and old notice parameters are intentionally not carried into a
	 * new status view.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Status filter, or an empty string for All.
	 * @return string Status view URL.
	 */
	private function get_status_view_url( $status ) {
		$args = array(
			'page' => 'cssflow',
		);

		// Read-only list state; no state-changing action is performed here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$scope      = isset( $_GET['scope_type'] ) ? sanitize_key( wp_unslash( $_GET['scope_type'] ) ) : '';
		$responsive = isset( $_GET['responsive_type'] ) ? sanitize_key( wp_unslash( $_GET['responsive_type'] ) ) : '';
		$orderby    = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order      = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( array_key_exists( $scope, self::get_filter_scope_labels() ) ) {
			$args['scope_type'] = $scope;
		}

		if ( array_key_exists( $responsive, self::get_responsive_labels() ) ) {
			$args['responsive_type'] = $responsive;
		}

		$allowed_orderby = array(
			'name',
			'status',
			'scope_type',
			'responsive_type',
			'priority',
			'updated_at',
		);

		if ( in_array( $orderby, $allowed_orderby, true ) ) {
			$args['orderby'] = $orderby;
		}

		if ( in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$args['order'] = strtolower( $order );
		}

		if ( in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$args['status'] = $status;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Define visible table columns.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Columns.
	 */
	public function get_columns() {
		return array(
			'cb'              => '<input type="checkbox" />',
			'name'            => __( 'Name', 'cssflow' ),
			'status'          => __( 'Status', 'cssflow' ),
			'scope_type'      => __( 'Scope', 'cssflow' ),
			'responsive_type' => __( 'Responsive', 'cssflow' ),
			'priority'        => __( 'Priority', 'cssflow' ),
			'updated_at'      => __( 'Modified', 'cssflow' ),
		);
	}

	/**
	 * Define sortable columns.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array<int,mixed>> Sortable columns.
	 */
	protected function get_sortable_columns() {
		return array(
			'name'            => array( 'name', false ),
			'status'          => array( 'status', false ),
			'scope_type'      => array( 'scope_type', false ),
			'responsive_type' => array( 'responsive_type', false ),
			'priority'        => array( 'priority', false ),
			'updated_at'      => array( 'updated_at', true ),
		);
	}

	/**
	 * Define bulk actions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Bulk actions.
	 */
	protected function get_bulk_actions() {
		return array();
	}

	/**
	 * Load paginated items from the repository.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'cssflow_snippets_per_page', 20 );
		$paged    = max( 1, $this->get_pagenum() );
		$filters  = $this->get_filters();
		$total    = $this->repository->count_for_admin( $filters );

		$this->items = $this->repository->query_for_admin(
			array_merge(
				$filters,
				array(
					'per_page' => $per_page,
					'offset'   => ( $paged - 1 ) * $per_page,
				)
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
			)
		);
	}

	/**
	 * Render additional filters above the table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $which Table position.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering; no state change occurs here.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering; no state change occurs here.
		$scope = isset( $_GET['scope_type'] ) ? sanitize_key( wp_unslash( $_GET['scope_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering; no state change occurs here.
		$responsive = isset( $_GET['responsive_type'] ) ? sanitize_key( wp_unslash( $_GET['responsive_type'] ) ) : '';
		?>
		<div class="alignleft actions cssflow-list-actions">
			<div class="cssflow-bulk-controls">
				<label class="screen-reader-text" for="cssflow-bulk-action"><?php esc_html_e( 'Bulk actions', 'cssflow' ); ?></label>
				<select name="bulk_action" id="cssflow-bulk-action">
					<option value=""><?php esc_html_e( 'Bulk actions', 'cssflow' ); ?></option>
					<option value="enable"><?php esc_html_e( 'Activate', 'cssflow' ); ?></option>
					<option value="disable"><?php esc_html_e( 'Deactivate', 'cssflow' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete permanently', 'cssflow' ); ?></option>
				</select>
				<?php submit_button( __( 'Apply', 'cssflow' ), 'secondary', 'bulk_apply', false ); ?>
			</div>

			<div class="cssflow-filter-controls">
				<label class="screen-reader-text" for="cssflow-filter-status"><?php esc_html_e( 'Filter by status', 'cssflow' ); ?></label>
				<select name="status" id="cssflow-filter-status">
					<option value=""><?php esc_html_e( 'All statuses', 'cssflow' ); ?></option>
					<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'cssflow' ); ?></option>
					<option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'cssflow' ); ?></option>
				</select>

				<label class="screen-reader-text" for="cssflow-filter-scope"><?php esc_html_e( 'Filter by scope', 'cssflow' ); ?></label>
				<select name="scope_type" id="cssflow-filter-scope">
					<option value=""><?php esc_html_e( 'All scopes', 'cssflow' ); ?></option>
					<?php foreach ( self::get_filter_scope_labels() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $scope, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="screen-reader-text" for="cssflow-filter-responsive"><?php esc_html_e( 'Filter by responsive target', 'cssflow' ); ?></label>
				<select name="responsive_type" id="cssflow-filter-responsive">
					<option value=""><?php esc_html_e( 'All responsive targets', 'cssflow' ); ?></option>
					<?php foreach ( self::get_responsive_labels() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $responsive, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>

				<?php submit_button( __( 'Filter', 'cssflow' ), 'secondary', 'filter_action', false ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render checkbox column.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Snippet item.
	 * @return string Checkbox markup.
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="snippet_ids[]" value="%d" />',
			absint( $item['id'] )
		);
	}

	/**
	 * Render snippet name and row actions.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Snippet item.
	 * @return string Name column markup.
	 */
	protected function column_name( $item ) {
		$edit_url = add_query_arg(
			array(
				'page'       => 'cssflow-add-css',
				'snippet_id' => absint( $item['id'] ),
			),
			admin_url( 'admin.php' )
		);

		$duplicate_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'cssflow_duplicate_snippet',
					'snippet_id' => absint( $item['id'] ),
				),
				admin_url( 'admin-post.php' )
			),
			'cssflow_duplicate_snippet_' . absint( $item['id'] )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'cssflow_delete_snippet',
					'snippet_id' => absint( $item['id'] ),
				),
				admin_url( 'admin-post.php' )
			),
			'cssflow_delete_snippet_' . absint( $item['id'] )
		);

		$actions = array(
			'edit'      => sprintf( '<a href="%s"><span class="dashicons dashicons-edit" aria-hidden="true"></span>%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'cssflow' ) ),
			'duplicate' => sprintf( '<a href="%s"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span>%s</a>', esc_url( $duplicate_url ), esc_html__( 'Duplicate', 'cssflow' ) ),
			'delete'    => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(%s);"><span class="dashicons dashicons-trash" aria-hidden="true"></span>%s</a>',
				esc_url( $delete_url ),
				esc_attr( wp_json_encode( __( 'Delete this CSS snippet permanently?', 'cssflow' ) ) ),
				esc_html__( 'Delete', 'cssflow' )
			),
		);

		$description = '' !== trim( (string) $item['description'] )
			? '<div class="cssflow-snippet-description">' . esc_html( $item['description'] ) . '</div>'
			: '';

		return sprintf(
			'<div class="cssflow-snippet-name"><strong><a class="row-title" href="%1$s">%2$s</a></strong>%3$s%4$s</div>',
			esc_url( $edit_url ),
			esc_html( $item['name'] ),
			$description,
			$this->row_actions( $actions )
		);
	}

	/**
	 * Render status column.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Snippet item.
	 * @return string Status label.
	 */
	protected function column_status( $item ) {
		$is_active     = 'active' === $item['status'];
		$toggle_status = $is_active ? 'inactive' : 'active';
		$state_label   = $is_active ? __( 'Active', 'cssflow' ) : __( 'Inactive', 'cssflow' );
		$action_label  = $is_active ? __( 'Deactivate', 'cssflow' ) : __( 'Activate', 'cssflow' );
		$toggle_url    = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'cssflow_toggle_snippet',
					'snippet_id' => absint( $item['id'] ),
					'status'     => $toggle_status,
				),
				admin_url( 'admin-post.php' )
			),
			'cssflow_toggle_snippet_' . absint( $item['id'] ) . '_' . $toggle_status
		);

		return sprintf(
			'<a class="cssflow-list-status-toggle%1$s" href="%2$s" aria-label="%3$s" title="%3$s" data-cssflow-snippet-toggle data-snippet-id="%4$d" data-current-status="%5$s" data-next-status="%6$s" data-snippet-name="%7$s"><span class="cssflow-list-status-control" aria-hidden="true"></span><span class="cssflow-list-status-text">%8$s</span></a>',
			$is_active ? ' is-active' : '',
			esc_url( $toggle_url ),
			esc_attr(
				sprintf(
					/* translators: 1: action, 2: snippet name. */
					__( '%1$s %2$s', 'cssflow' ),
					$action_label,
					$item['name']
				)
			),
			absint( $item['id'] ),
			esc_attr( $item['status'] ),
			esc_attr( $toggle_status ),
			esc_attr( $item['name'] ),
			esc_html( $state_label )
		);
	}

	/**
	 * Render scope column.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Snippet item.
	 * @return string Scope label.
	 */
	protected function column_scope_type( $item ) {
		$label = $this->get_scope_display_label( $item );

		return '<span class="cssflow-meta-pill cssflow-scope-pill">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Resolve the human-readable scope label for a snippet.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Snippet item.
	 * @return string Scope label.
	 */
	private function get_scope_display_label( $item ) {
		$scope_type = isset( $item['scope_type'] ) ? sanitize_key( $item['scope_type'] ) : '';
		$scope_data = isset( $item['scope_data'] ) && is_array( $item['scope_data'] ) ? $item['scope_data'] : array();

		switch ( $scope_type ) {
			case 'global':
				return __( 'Entire Website', 'cssflow' );

			case 'specific_content':
				$post_type = isset( $scope_data['post_type'] ) && is_scalar( $scope_data['post_type'] ) ? sanitize_key( (string) $scope_data['post_type'] ) : '';
				$count     = isset( $scope_data['ids'] ) && is_array( $scope_data['ids'] ) ? count( array_unique( array_map( 'absint', $scope_data['ids'] ) ) ) : 0;
				$label     = $this->get_post_type_label( $post_type );

				if ( '' !== $label && $count > 0 ) {
					return sprintf(
						/* translators: 1: post type label, 2: number of selected content items. */
						__( 'Specific Content · %1$s (%2$d)', 'cssflow' ),
						$label,
						$count
					);
				}

				return __( 'Specific Content', 'cssflow' );

			case 'post_type':
				$post_type = isset( $scope_data['post_type'] ) && is_scalar( $scope_data['post_type'] ) ? sanitize_key( (string) $scope_data['post_type'] ) : '';
				$label     = $this->get_post_type_label( $post_type );

				if ( '' !== $label ) {
					return sprintf(
						/* translators: %s: post type label. */
						__( 'Content Type · %s', 'cssflow' ),
						$label
					);
				}

				return __( 'Content Type', 'cssflow' );

			case 'special':
				$context = isset( $scope_data['context'] ) && is_scalar( $scope_data['context'] ) ? sanitize_key( (string) $scope_data['context'] ) : '';
				$labels  = self::get_special_context_labels();

				if ( isset( $labels[ $context ] ) ) {
					return sprintf(
						/* translators: %s: Special Pages-context label. */
						__( 'Special Pages · %s', 'cssflow' ),
						$labels[ $context ]
					);
				}

				return __( 'Special Pages', 'cssflow' );

			case 'woocommerce':
				$context = isset( $scope_data['context'] ) && is_scalar( $scope_data['context'] ) ? sanitize_key( (string) $scope_data['context'] ) : '';
				$labels  = self::get_woocommerce_context_labels();

				if ( 'specific_products' === $context ) {
					$count = isset( $scope_data['product_ids'] ) && is_array( $scope_data['product_ids'] ) ? count( array_unique( array_map( 'absint', $scope_data['product_ids'] ) ) ) : 0;

					if ( $count > 0 ) {
						return sprintf(
							/* translators: %d: number of selected WooCommerce Products. */
							__( 'WooCommerce · Specific Products (%d)', 'cssflow' ),
							$count
						);
					}
				}

				if ( 'product_category' === $context ) {
					$count = isset( $scope_data['term_ids'] ) && is_array( $scope_data['term_ids'] ) ? count( array_unique( array_map( 'absint', $scope_data['term_ids'] ) ) ) : 0;

					if ( $count > 0 ) {
						return sprintf(
							/* translators: %d: number of selected WooCommerce Product Categories. */
							__( 'WooCommerce · Product Category (%d)', 'cssflow' ),
							$count
						);
					}
				}

				if ( isset( $labels[ $context ] ) ) {
					return sprintf(
						/* translators: %s: WooCommerce context label. */
						__( 'WooCommerce · %s', 'cssflow' ),
						$labels[ $context ]
					);
				}

				return __( 'WooCommerce', 'cssflow' );
		}

		return $scope_type;
	}

	/**
	 * Render responsive target column.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Snippet item.
	 * @return string Responsive label.
	 */
	protected function column_responsive_type( $item ) {
		$labels = self::get_responsive_labels();
		$value  = isset( $labels[ $item['responsive_type'] ] ) ? $labels[ $item['responsive_type'] ] : $item['responsive_type'];

		if ( 'custom' === $item['responsive_type'] && ! empty( $item['breakpoint_key'] ) ) {
			$value .= ' · ' . $item['breakpoint_key'];
		}

		return '<span class="cssflow-meta-pill cssflow-responsive-pill">' . esc_html( $value ) . '</span>';
	}

	/**
	 * Render priority column.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Snippet item.
	 * @return string Priority.
	 */
	protected function column_priority( $item ) {
		return '<span class="cssflow-priority-value">' . esc_html( (string) absint( $item['priority'] ) ) . '</span>';
	}

	/**
	 * Render modified date.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Snippet item.
	 * @return string Localized date.
	 */
	protected function column_updated_at( $item ) {
		return $this->format_mysql_date( $item['updated_at'] );
	}

	/**
	 * Fallback rendering for unknown columns.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $item        Snippet item.
	 * @param string $column_name Column name.
	 * @return string Cell value.
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	/**
	 * Message shown when no snippets match.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function no_items() {
		$filters = $this->get_filters();
		$active  = '' !== $filters['search'] || '' !== $filters['status'] || '' !== $filters['scope_type'] || '' !== $filters['responsive_type'];

		if ( $active ) {
			echo '<div class="cssflow-list-empty-state cssflow-list-empty-filtered">';
			echo '<span class="dashicons dashicons-search" aria-hidden="true"></span>';
			echo '<strong>' . esc_html__( 'No matching CSS snippets', 'cssflow' ) . '</strong>';
			echo '<p>' . esc_html__( 'Try changing your search or filters.', 'cssflow' ) . '</p>';
			echo '</div>';
			return;
		}

		$add_url = admin_url( 'admin.php?page=cssflow-add-css' );

		echo '<div class="cssflow-list-empty-state">';
		echo '<span class="cssflow-list-empty-brand-icon" aria-hidden="true"><span class="cssflow-list-empty-brand-brace">{</span><span class="cssflow-list-empty-brand-wave">~</span><span class="cssflow-list-empty-brand-brace">}</span></span>';
		echo '<strong>' . esc_html__( 'No CSS snippets yet', 'cssflow' ) . '</strong>';
		echo '<p>' . esc_html__( 'Create your first snippet to start managing custom CSS with CSSFlow.', 'cssflow' ) . '</p>';
		echo '<a class="button cssflow-primary-button" href="' . esc_url( $add_url ) . '">' . esc_html__( 'Add CSS', 'cssflow' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Build sanitized query filters from the current request.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed> Repository filters.
	 */
	private function get_filters() {
		// Read-only list filtering/sorting parameters; no state-changing action is performed here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search     = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$status     = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$scope      = isset( $_REQUEST['scope_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['scope_type'] ) ) : '';
		$responsive = isset( $_REQUEST['responsive_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['responsive_type'] ) ) : '';
		$orderby    = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'updated_at';
		$order      = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'search'          => $search,
			'status'          => in_array( $status, array( 'active', 'inactive' ), true ) ? $status : '',
			'scope_type'      => array_key_exists( $scope, self::get_filter_scope_labels() ) ? $scope : '',
			'responsive_type' => array_key_exists( $responsive, self::get_responsive_labels() ) ? $responsive : '',
			'orderby'         => $orderby,
			'order'           => in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC',
		);
	}

	/**
	 * Format a stored local MySQL date using site date settings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date MySQL date.
	 * @return string Formatted date.
	 */
	private function format_mysql_date( $date ) {
		$date = (string) $date;

		if ( '' === $date || false === strtotime( $date ) ) {
			return esc_html( $date );
		}

		$date_text = mysql2date( get_option( 'date_format' ), $date );
		$time_text = mysql2date( get_option( 'time_format' ), $date );

		return sprintf(
			'<span class="cssflow-date-value"><span class="cssflow-date-day">%1$s</span><span class="cssflow-date-time">%2$s</span></span>',
			esc_html( $date_text ),
			esc_html( $time_text )
		);
	}

	/**
	 * Scope labels used by the Phase 6 management table.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Labels.
	 */
	public static function get_scope_labels() {
		return array(
			'global'           => __( 'Entire Website', 'cssflow' ),
			'specific_content' => __( 'Specific Content', 'cssflow' ),
			'post_type'        => __( 'Content Type', 'cssflow' ),
			'special'          => __( 'Special Pages', 'cssflow' ),
			'woocommerce'      => __( 'WooCommerce', 'cssflow' ),
		);
	}

	/**
	 * Scope labels exposed by the Phase 8 All CSS filter.
	 *
	 * WooCommerce remains a frozen storage value but is intentionally omitted
	 * from the user-facing filter until Phase 9 registers that integration.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Phase 8 filter labels.
	 */
	public static function get_filter_scope_labels() {
		$labels = self::get_scope_labels();

		if ( ! class_exists( WooCommerce::class ) || ! WooCommerce::is_available() ) {
			unset( $labels['woocommerce'] );
		}

		return $labels;
	}

	/**
	 * Special Pages-context labels used by list-table presentation.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Labels keyed by frozen special-context key.
	 */
	public static function get_special_context_labels() {
		return array(
			'front_page' => __( 'Front Page / Homepage', 'cssflow' ),
			'posts_page' => __( 'Posts Page / Blog Index', 'cssflow' ),
			'search'     => __( 'Search Results', 'cssflow' ),
			'404'        => __( '404 Page', 'cssflow' ),
		);
	}


	/**
	 * WooCommerce context labels used by list-table presentation.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Labels keyed by frozen WooCommerce context.
	 */
	public static function get_woocommerce_context_labels() {
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
	 * Resolve a stored post type to a human-readable plural label.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type key.
	 * @return string Post type label or key fallback.
	 */
	private function get_post_type_label( $post_type ) {
		$post_type = sanitize_key( $post_type );

		if ( '' === $post_type ) {
			return '';
		}

		$object = get_post_type_object( $post_type );

		if ( $object && isset( $object->labels->name ) ) {
			$label = sanitize_text_field( $object->labels->name );

			if ( '' !== $label ) {
				return $label;
			}
		}

		return $post_type;
	}

	/**
	 * Responsive labels used by the management table and editor.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Labels.
	 */
	public static function get_responsive_labels() {
		return array(
			'all'     => __( 'All Devices', 'cssflow' ),
			'desktop' => __( 'Desktop', 'cssflow' ),
			'tablet'  => __( 'Tablet', 'cssflow' ),
			'mobile'  => __( 'Mobile', 'cssflow' ),
			'custom'  => __( 'Custom Breakpoint', 'cssflow' ),
		);
	}
}
