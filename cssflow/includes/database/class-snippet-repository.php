<?php
/**
 * Provides persistence operations for CSSFlow snippet records.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Database;

use CSSFlow\Integrations\WooCommerce;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes normalized CSSFlow snippet records.
 *
 * Request authorization remains outside the repository; this class owns
 * persistence-level validation, normalization, and custom-table SQL.
 */
final class Snippet_Repository {

	/**
	 * Allowed snippet scope types for v1.0.
	 *
	 * @var string[]
	 */
	private $scope_types = array(
		'global',
		'specific_content',
		'post_type',
		'special',
		'woocommerce',
	);

	/**
	 * Allowed responsive types for v1.0.
	 *
	 * @var string[]
	 */
	private $responsive_types = array(
		'all',
		'desktop',
		'tablet',
		'mobile',
		'custom',
	);

	/**
	 * Allowed status values for v1.0.
	 *
	 * @var string[]
	 */
	private $statuses = array(
		'active',
		'inactive',
	);

	/**
	 * Create a snippet.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Snippet data.
	 * @return int|WP_Error New snippet ID on success, WP_Error on failure.
	 */
	public function create( array $data ) {
		global $wpdb;

		$normalized = $this->normalize_for_storage( $data );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$now = current_time( 'mysql' );

		$normalized['created_at'] = $now;
		$normalized['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- CSSFlow owns this custom table; all writes are centralized in this repository.
		$inserted = $wpdb->insert(
			Schema::table_name(),
			$normalized,
			$this->formats_for_record( $normalized )
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'cssflow_insert_failed',
				__( 'CSSFlow could not create the CSS snippet.', 'cssflow' )
			);
		}

		return (int) $wpdb->insert_id;
	}


	/**
	 * Create an imported snippet against a supplied validated breakpoint set.
	 *
	 * @param array $data        Snippet data.
	 * @param array $breakpoints Validated breakpoint configuration.
	 * @return int|WP_Error New snippet ID or error.
	 */
	public function create_imported( array $data, array $breakpoints ) {
		global $wpdb;

		$import = $this->normalize_import_for_storage( $data, $breakpoints );
		if ( is_wp_error( $import ) ) {
			return $import;
		}

		$normalized = $import['record'];

		$now                      = current_time( 'mysql' );
		$normalized['created_at'] = $now;
		$normalized['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- CSSFlow owns this custom table; all writes are centralized in this repository.
		$inserted = $wpdb->insert(
			Schema::table_name(),
			$normalized,
			$this->formats_for_record( $normalized )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'cssflow_import_insert_failed', __( 'CSSFlow could not create an imported CSS snippet.', 'cssflow' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a conflicting destination snippet using imported data.
	 *
	 * @param int   $id          Destination snippet ID.
	 * @param array $data        Imported snippet data.
	 * @param array $breakpoints Validated breakpoint configuration.
	 * @return bool|WP_Error True on success, false if missing, or error.
	 */
	public function update_imported( $id, array $data, array $breakpoints ) {
		global $wpdb;

		$id       = absint( $id );
		$existing = $this->get( $id );
		if ( 0 === $id || null === $existing ) {
			return false;
		}

		$data['created_by'] = $existing['created_by'];
		$import             = $this->normalize_import_for_storage( $data, $breakpoints );
		if ( is_wp_error( $import ) ) {
			return $import;
		}

		$normalized = $import['record'];

		unset( $normalized['created_by'] );
		$normalized['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- CSSFlow owns this custom table; all writes are centralized here and write operations must not be cached.
		$updated = $wpdb->update(
			Schema::table_name(),
			$normalized,
			array( 'id' => $id ),
			$this->formats_for_record( $normalized ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'cssflow_import_update_failed', __( 'CSSFlow could not replace an imported CSS snippet.', 'cssflow' ) );
		}

		return true;
	}

	/**
	 * Generate a readable unique name for the "Import as new copy" action.
	 *
	 * @param string $name Original imported name.
	 * @return string|WP_Error Unique name or error.
	 */
	public function make_unique_import_name( $name ) {
		$name = is_scalar( $name ) ? sanitize_text_field( (string) $name ) : '';

		if ( '' === $name ) {
			return new WP_Error(
				'cssflow_import_invalid_name',
				__( 'The imported snippet name is invalid.', 'cssflow' )
			);
		}

		$suffix_number = 1;

		do {
			if ( 1 === $suffix_number ) {
				$suffix = __( ' (Imported Copy)', 'cssflow' );
			} else {
				$suffix = sprintf(
					/* translators: %d: Imported copy number. */
					__( ' (Imported Copy %d)', 'cssflow' ),
					$suffix_number
				);
			}

			$limit     = max( 1, 191 - strlen( $suffix ) );
			$base      = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, $limit ) : substr( $name, 0, $limit );
			$candidate = $base . $suffix;
			$existing  = $this->find_by_name( $candidate );

			if ( is_wp_error( $existing ) ) {
				return $existing;
			}

			if ( null === $existing ) {
				return $candidate;
			}

			++$suffix_number;
		} while ( $suffix_number < 10000 );

		return new WP_Error(
			'cssflow_import_unique_name_failed',
			__( 'CSSFlow could not create a unique name for an imported copy.', 'cssflow' )
		);
	}

	/**
	 * Start a database transaction.
	 *
	 * @return bool Whether the database transaction started.
	 */
	public function begin_transaction() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control is required for atomic CSSFlow import commits and cannot be cached.
		return false !== $wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Commit the active database transaction.
	 *
	 * @return bool Whether the database transaction committed.
	 */
	public function commit_transaction() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control is required for atomic CSSFlow import commits and cannot be cached.
		return false !== $wpdb->query( 'COMMIT' );
	}

	/**
	 * Roll back the active database transaction.
	 *
	 * @return void
	 */
	public function rollback_transaction() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control is required for atomic CSSFlow import commits and cannot be cached.
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Get a snippet by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Snippet ID.
	 * @return array|null Normalized snippet record, or null when not found.
	 */
	public function get( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( 0 === $id ) {
			return null;
		}

		$table_name = Schema::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- CSSFlow owns this custom table and requires the current record state.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$table_name,
				$id
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		return $this->normalize_from_storage( $row );
	}

	/**
	 * Get all snippets for CSSFlow data portability operations.
	 *
	 * Results include both active and inactive records and are returned in
	 * stable ID order. Import/export services remain responsible for deciding
	 * which fields are portable between sites.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array<string,mixed>>|WP_Error All snippets or an error.
	 */
	public function get_all() {
		global $wpdb;

		$table_name = Schema::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- CSSFlow owns this custom table and export requires the current complete dataset.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $table_name ),
			ARRAY_A
		);

		if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) ) {
			return new WP_Error(
				'cssflow_all_query_failed',
				__( 'CSSFlow could not load the CSS snippets.', 'cssflow' )
			);
		}

		$normalized = array();

		foreach ( $rows as $row ) {
			$normalized[] = $this->normalize_from_storage( $row );
		}

		return $normalized;
	}

	/**
	 * Validate and normalize one imported snippet without writing it.
	 *
	 * Import preview passes the already-validated breakpoint configuration from
	 * the uploaded backup so custom breakpoint references can be checked against
	 * the data that would be imported rather than only the destination options.
	 * Destination content IDs are still validated against the current site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data        Portable snippet data.
	 * @param array $breakpoints Validated breakpoint configuration from the import.
	 * @return array|WP_Error Normalized portable snippet data or validation error.
	 */
	public function validate_for_import( array $data, array $breakpoints ) {
		$import = $this->normalize_import_for_storage( $data, $breakpoints );

		if ( is_wp_error( $import ) ) {
			return $import;
		}

		$normalized = $import['record'];
		$scope_data = json_decode( (string) $normalized['scope_data'], true );

		if ( ! is_array( $scope_data ) ) {
			return new WP_Error(
				'cssflow_import_scope_decode_failed',
				__( 'The imported CSS snippet targeting data could not be normalized.', 'cssflow' )
			);
		}

		return array(
			'name'                => (string) $normalized['name'],
			'description'         => null === $normalized['description'] ? '' : (string) $normalized['description'],
			'css_code'            => (string) $normalized['css_code'],
			'scope_type'          => (string) $normalized['scope_type'],
			'scope_data'          => $scope_data,
			'responsive_type'     => (string) $normalized['responsive_type'],
			'breakpoint_key'      => $normalized['breakpoint_key'],
			'priority'            => (int) $normalized['priority'],
			'status'              => (string) $normalized['status'],
			'_target_unavailable' => ! empty( $import['target_unavailable'] ),
		);
	}


	/**
	 * Normalize imported data while safely preserving unavailable targeting.
	 *
	 * Environment-dependent targets may be unavailable because a plugin, custom
	 * post type, product, category, or content item is not currently present.
	 * Those records remain structurally valid backup data, so CSSFlow preserves
	 * the target and forces the imported snippet inactive instead of blocking the
	 * complete backup. Malformed targeting remains a blocking validation error.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data        Portable snippet data.
	 * @param array $breakpoints Validated breakpoint configuration.
	 * @return array|WP_Error Import normalization result or validation error.
	 */
	private function normalize_import_for_storage( array $data, array $breakpoints ) {
		$normalized = $this->normalize_for_storage( $data, $breakpoints );

		if ( ! is_wp_error( $normalized ) ) {
			return array(
				'record'             => $normalized,
				'target_unavailable' => false,
			);
		}

		$scope_type = isset( $data['scope_type'] ) && is_scalar( $data['scope_type'] ) ? sanitize_key( (string) $data['scope_type'] ) : '';
		$scope_data = isset( $data['scope_data'] ) && is_array( $data['scope_data'] ) ? $data['scope_data'] : null;

		if ( ! $this->is_environment_dependent_import_error( $normalized ) || null === $scope_data ) {
			return $normalized;
		}

		$preserved_scope = $this->normalize_unavailable_import_scope_data( $scope_type, $scope_data );

		if ( is_wp_error( $preserved_scope ) ) {
			return $preserved_scope;
		}

		$validation_data               = $data;
		$validation_data['scope_type'] = 'global';
		$validation_data['scope_data'] = array();
		$validation_data['status']     = 'inactive';
		$safe_normalized               = $this->normalize_for_storage( $validation_data, $breakpoints );

		if ( is_wp_error( $safe_normalized ) ) {
			return $safe_normalized;
		}

		$scope_json = empty( $preserved_scope ) ? '{}' : wp_json_encode( $preserved_scope );

		if ( false === $scope_json ) {
			return new WP_Error(
				'cssflow_import_scope_encode_failed',
				__( 'The imported CSS snippet targeting data could not be encoded.', 'cssflow' )
			);
		}

		$safe_normalized['scope_type'] = $scope_type;
		$safe_normalized['scope_data'] = $scope_json;
		$safe_normalized['status']     = 'inactive';

		return array(
			'record'             => $safe_normalized,
			'target_unavailable' => true,
		);
	}

	/**
	 * Determine whether import validation failed only because the destination
	 * environment cannot currently resolve otherwise structured targeting data.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Error $error Validation error.
	 * @return bool
	 */
	private function is_environment_dependent_import_error( WP_Error $error ) {
		return in_array(
			$error->get_error_code(),
			array(
				'cssflow_invalid_target_post_type',
				'cssflow_invalid_target_ids',
				'cssflow_woocommerce_unavailable',
				'cssflow_invalid_woocommerce_product_ids',
				'cssflow_invalid_woocommerce_term_ids',
			),
			true
		);
	}

	/**
	 * Validate the structure of a currently unavailable imported target without
	 * requiring the referenced plugin, post type, object, product, or term to
	 * exist on the destination site.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope_type Scope type.
	 * @param array  $scope_data Raw imported scope data.
	 * @return array|WP_Error Preserved normalized scope data or validation error.
	 */
	private function normalize_unavailable_import_scope_data( $scope_type, array $scope_data ) {
		switch ( $scope_type ) {
			case 'post_type':
				if ( array( 'post_type' ) !== array_keys( $scope_data ) || ! is_scalar( $scope_data['post_type'] ) ) {
					return new WP_Error( 'cssflow_invalid_scope_data', __( 'The content-type targeting data is invalid.', 'cssflow' ) );
				}

				$post_type = sanitize_key( (string) $scope_data['post_type'] );
				if ( '' === $post_type || 'attachment' === $post_type || 'product' === $post_type ) {
					return new WP_Error( 'cssflow_invalid_target_post_type', __( 'The selected content type cannot be used for this CSSFlow targeting rule.', 'cssflow' ) );
				}

				return array( 'post_type' => $post_type );

			case 'specific_content':
				$keys = array_keys( $scope_data );
				sort( $keys );
				if ( array( 'ids', 'post_type' ) !== $keys || ! is_scalar( $scope_data['post_type'] ) || ! is_array( $scope_data['ids'] ) || empty( $scope_data['ids'] ) ) {
					return new WP_Error( 'cssflow_invalid_scope_data', __( 'The specific-content targeting data is invalid.', 'cssflow' ) );
				}

				$post_type = sanitize_key( (string) $scope_data['post_type'] );
				if ( '' === $post_type || 'attachment' === $post_type || 'product' === $post_type ) {
					return new WP_Error( 'cssflow_invalid_target_post_type', __( 'The selected content type cannot be used for this CSSFlow targeting rule.', 'cssflow' ) );
				}

				$ids = $this->normalize_unavailable_import_ids( $scope_data['ids'] );
				if ( is_wp_error( $ids ) ) {
					return $ids;
				}

				return array(
					'post_type' => $post_type,
					'ids'       => $ids,
				);

			case 'woocommerce':
				return $this->normalize_unavailable_woocommerce_import_scope( $scope_data );
		}

		return new WP_Error( 'cssflow_invalid_scope_data', __( 'The imported CSS snippet targeting data is invalid.', 'cssflow' ) );
	}

	/**
	 * Normalize positive imported IDs without checking destination existence.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_ids Raw IDs.
	 * @return array|WP_Error Normalized IDs or validation error.
	 */
	private function normalize_unavailable_import_ids( array $raw_ids ) {
		$ids = array();

		foreach ( $raw_ids as $raw_id ) {
			$id = $this->normalize_positive_integer( $raw_id );
			if ( 0 === $id ) {
				return new WP_Error( 'cssflow_invalid_target_ids', __( 'One or more imported target IDs are invalid.', 'cssflow' ) );
			}
			$ids[] = $id;
		}

		if ( empty( $ids ) ) {
			return new WP_Error( 'cssflow_invalid_target_ids', __( 'The imported targeting data does not contain a usable target ID.', 'cssflow' ) );
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * Structurally validate unavailable WooCommerce targeting for safe inactive
	 * import without requiring WooCommerce objects to exist right now.
	 *
	 * @since 1.0.0
	 *
	 * @param array $scope_data Raw WooCommerce scope data.
	 * @return array|WP_Error Preserved normalized scope data or validation error.
	 */
	private function normalize_unavailable_woocommerce_import_scope( array $scope_data ) {
		if ( ! isset( $scope_data['context'] ) || ! is_scalar( $scope_data['context'] ) ) {
			return new WP_Error( 'cssflow_invalid_woocommerce_scope_data', __( 'The WooCommerce targeting data is invalid.', 'cssflow' ) );
		}

		$context = sanitize_key( (string) $scope_data['context'] );
		$simple  = array( 'shop', 'products', 'cart', 'checkout', 'my_account' );

		if ( in_array( $context, $simple, true ) ) {
			if ( array( 'context' ) !== array_keys( $scope_data ) ) {
				return new WP_Error( 'cssflow_invalid_woocommerce_scope_data', __( 'The WooCommerce targeting data contains unexpected values.', 'cssflow' ) );
			}
			return array( 'context' => $context );
		}

		if ( 'specific_products' === $context ) {
			$keys = array_keys( $scope_data );
			sort( $keys );
			if ( array( 'context', 'product_ids' ) !== $keys || ! is_array( $scope_data['product_ids'] ) || empty( $scope_data['product_ids'] ) ) {
				return new WP_Error( 'cssflow_invalid_woocommerce_product_ids', __( 'The imported WooCommerce Product targeting data is invalid.', 'cssflow' ) );
			}
			$ids = $this->normalize_unavailable_import_ids( $scope_data['product_ids'] );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			return array(
				'context'     => $context,
				'product_ids' => $ids,
			);
		}

		if ( 'product_category' === $context ) {
			$keys = array_keys( $scope_data );
			sort( $keys );
			if ( array( 'context', 'term_ids' ) !== $keys || ! is_array( $scope_data['term_ids'] ) || empty( $scope_data['term_ids'] ) ) {
				return new WP_Error( 'cssflow_invalid_woocommerce_term_ids', __( 'The imported WooCommerce Product Category targeting data is invalid.', 'cssflow' ) );
			}
			$ids = $this->normalize_unavailable_import_ids( $scope_data['term_ids'] );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			return array(
				'context'  => $context,
				'term_ids' => $ids,
			);
		}

		return new WP_Error( 'cssflow_invalid_woocommerce_context', __( 'The selected WooCommerce context is invalid.', 'cssflow' ) );
	}

	/**
	 * Find the first destination snippet with an exact normalized name match.
	 *
	 * Numeric IDs from an exported backup are deliberately not used as portable
	 * identity. The import workflow uses the snippet name only to surface a
	 * possible conflict for the administrator to resolve before commit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Imported snippet name.
	 * @return array|null|WP_Error Matching snippet, null when none exists, or error.
	 */
	public function find_by_name( $name ) {
		global $wpdb;

		$name = is_scalar( $name ) ? sanitize_text_field( (string) $name ) : '';

		if ( '' === $name ) {
			return null;
		}

		$table_name = Schema::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conflict detection must use the current CSSFlow custom-table state.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE name = %s ORDER BY id ASC LIMIT 1',
				$table_name,
				$name
			),
			ARRAY_A
		);

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error(
				'cssflow_import_conflict_query_failed',
				__( 'CSSFlow could not check the destination site for snippet conflicts.', 'cssflow' )
			);
		}

		if ( null === $row ) {
			return null;
		}

		return $this->normalize_from_storage( $row );
	}

	/**
	 * Get all active snippets for frontend output processing.
	 *
	 * Results are normalized and pre-ordered by priority ASC, then ID ASC.
	 * The output generator applies the frozen scope-group ordering afterward.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array<string,mixed>>|WP_Error Active snippets or an error.
	 */
	public function get_active() {
		global $wpdb;

		$table_name = Schema::table_name();
		$query      = $wpdb->prepare(
			'SELECT * FROM %i WHERE status = %s ORDER BY priority ASC, id ASC',
			$table_name,
			'active'
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above; active output must reflect the current custom-table state.
		$rows = $wpdb->get_results( $query, ARRAY_A );

		if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) ) {
			return new WP_Error(
				'cssflow_active_query_failed',
				__( 'CSSFlow could not load the active CSS snippets.', 'cssflow' )
			);
		}

		$normalized = array();

		foreach ( $rows as $row ) {
			$normalized[] = $this->normalize_from_storage( $row );
		}

		return $normalized;
	}

	/**
	 * Query snippets for the Phase 6 administration list.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Query arguments.
	 * @return array<int,array<string,mixed>> Snippet rows.
	 */
	public function query_for_admin( array $args = array() ) {
		global $wpdb;

		$defaults        = array(
			'search'          => '',
			'status'          => '',
			'scope_type'      => '',
			'responsive_type' => '',
			'orderby'         => 'updated_at',
			'order'           => 'DESC',
			'per_page'        => 20,
			'offset'          => 0,
		);
		$args            = wp_parse_args( $args, $defaults );
		$where           = $this->build_admin_where_clauses( $args );
		$allowed_orderby = array(
			'id',
			'name',
			'status',
			'scope_type',
			'responsive_type',
			'priority',
			'created_at',
			'updated_at',
		);
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'updated_at';
		$order           = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page        = max( 1, min( 100, absint( $args['per_page'] ) ) );
		$offset          = absint( $args['offset'] );
		$table_name      = Schema::table_name();
		$sql             = "SELECT * FROM %i{$where['sql']} ORDER BY %i {$order}, id ASC LIMIT %d OFFSET %d";
		$values          = array_merge( array( $table_name ), $where['values'], array( $orderby, $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- SQL consists only of repository-owned placeholder fragments; all values are passed to prepare(), and order direction is reduced to ASC or DESC above.
		$prepared_sql = $wpdb->prepare( $sql, $values );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared immediately above; Plugin Check cannot trace the prepared SQL variable across assignments.
		$rows = $wpdb->get_results( $prepared_sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $rows as $row ) {
			$normalized[] = $this->normalize_from_storage( $row );
		}

		return $normalized;
	}

	/**
	 * Count snippets for the Phase 6 administration list.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Query arguments.
	 * @return int Matching snippet count.
	 */
	public function count_for_admin( array $args = array() ) {
		global $wpdb;

		$where      = $this->build_admin_where_clauses( $args );
		$table_name = Schema::table_name();
		$sql        = "SELECT COUNT(*) FROM %i{$where['sql']}";
		$values     = array_merge( array( $table_name ), $where['values'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- SQL consists only of repository-owned placeholder fragments and all dynamic values are passed to prepare().
		$prepared_sql = $wpdb->prepare( $sql, $values );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared immediately above; Plugin Check cannot trace the prepared SQL variable across assignments.
		return (int) $wpdb->get_var( $prepared_sql );
	}

	/**
	 * Update an existing snippet.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $id   Snippet ID.
	 * @param array $data Fields to update.
	 * @return bool|WP_Error True on success, false when the snippet does not exist,
	 *                       or WP_Error on validation/database failure.
	 */
	public function update( $id, array $data ) {
		global $wpdb;

		$id       = absint( $id );
		$existing = $this->get( $id );

		if ( 0 === $id || null === $existing ) {
			return false;
		}

		$editable_fields = array(
			'name',
			'description',
			'css_code',
			'scope_type',
			'scope_data',
			'responsive_type',
			'breakpoint_key',
			'priority',
			'status',
		);

		$merged = array();

		foreach ( $editable_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$merged[ $field ] = $data[ $field ];
			} else {
				$merged[ $field ] = $existing[ $field ];
			}
		}

		$merged['created_by'] = $existing['created_by'];
		$normalized           = $this->normalize_for_storage( $merged );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		unset( $normalized['created_by'] );

		$normalized['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- CSSFlow owns this custom table; all writes are centralized here and write operations must not be cached.
		$updated = $wpdb->update(
			Schema::table_name(),
			$normalized,
			array( 'id' => $id ),
			$this->formats_for_record( $normalized ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'cssflow_update_failed',
				__( 'CSSFlow could not update the CSS snippet.', 'cssflow' )
			);
		}

		return true;
	}


	/**
	 * Validate whether a stored snippet's targeting is currently usable.
	 *
	 * This intentionally re-checks environment-dependent targets such as
	 * custom post types and WooCommerce without modifying the stored snippet.
	 *
	 * @since 1.0.0
	 *
	 * @param array $snippet Normalized snippet record.
	 * @return true|WP_Error True when targeting is currently usable, otherwise WP_Error.
	 */
	public function validate_targeting( array $snippet ) {
		$scope_type = isset( $snippet['scope_type'] ) ? sanitize_key( $snippet['scope_type'] ) : '';
		$scope_data = isset( $snippet['scope_data'] ) && is_array( $snippet['scope_data'] ) ? $snippet['scope_data'] : array();

		if ( ! in_array( $scope_type, $this->scope_types, true ) ) {
			return new WP_Error(
				'cssflow_invalid_scope_type',
				__( 'The CSS snippet scope is invalid.', 'cssflow' )
			);
		}

		$normalized = $this->normalize_scope_data( $scope_type, $scope_data );

		return is_wp_error( $normalized ) ? $normalized : true;
	}

	/**
	 * Update only a snippet's Active/Inactive status.
	 *
	 * Deactivation must remain possible even when an optional plugin, custom
	 * post type, or previously selected object is no longer available. Activation
	 * revalidates the stored targeting against the current environment first.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id     Snippet ID.
	 * @param string $status active or inactive.
	 * @return bool|WP_Error True on success, false when not found, or WP_Error.
	 */
	public function update_status( $id, $status ) {
		global $wpdb;

		$id     = absint( $id );
		$status = is_scalar( $status ) ? sanitize_key( (string) $status ) : '';

		if ( 0 === $id || ! in_array( $status, $this->statuses, true ) ) {
			return new WP_Error(
				'cssflow_invalid_status',
				__( 'The CSS snippet status is invalid.', 'cssflow' )
			);
		}

		$existing = $this->get( $id );

		if ( null === $existing ) {
			return false;
		}

		if ( 'active' === $status ) {
			$validation = $this->normalize_for_storage( $existing );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- CSSFlow owns this custom table; this status-only write intentionally avoids unrelated targeting revalidation during deactivation.
		$updated = $wpdb->update(
			Schema::table_name(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'cssflow_update_failed',
				__( 'CSSFlow could not update the CSS snippet.', 'cssflow' )
			);
		}

		return true;
	}

	/**
	 * Update an inactive snippet while preserving unavailable stored targeting.
	 *
	 * This allows administrators to deactivate or edit non-targeting fields on a
	 * snippet whose custom post type, WooCommerce integration, or selected object
	 * is temporarily unavailable. The original targeting is preserved verbatim.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $id   Snippet ID.
	 * @param array $data Submitted snippet data.
	 * @return bool|WP_Error True on success, false when not found, or WP_Error.
	 */
	public function update_inactive_preserving_targeting( $id, array $data ) {
		global $wpdb;

		$id       = absint( $id );
		$existing = $this->get( $id );

		if ( 0 === $id || null === $existing ) {
			return false;
		}

		if ( ! isset( $data['status'] ) || 'inactive' !== sanitize_key( (string) $data['status'] ) ) {
			return new WP_Error(
				'cssflow_invalid_status',
				__( 'Unavailable targeting can only be preserved while the snippet is inactive.', 'cssflow' )
			);
		}

		$targeting = $this->validate_targeting( $existing );

		if ( true === $targeting ) {
			return $this->update( $id, $data );
		}

		$validation_data               = $data;
		$validation_data['scope_type'] = 'global';
		$validation_data['scope_data'] = array();
		$normalized                    = $this->normalize_for_storage( $validation_data );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$scope_json = empty( $existing['scope_data'] ) ? '{}' : wp_json_encode( $existing['scope_data'] );

		if ( false === $scope_json ) {
			return new WP_Error(
				'cssflow_scope_encoding_failed',
				__( 'The CSS snippet targeting data could not be encoded.', 'cssflow' )
			);
		}

		unset( $normalized['created_by'] );
		$normalized['scope_type'] = $existing['scope_type'];
		$normalized['scope_data'] = $scope_json;
		$normalized['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- CSSFlow owns this custom table; validated non-target fields are saved while existing unavailable targeting is deliberately preserved.
		$updated = $wpdb->update(
			Schema::table_name(),
			$normalized,
			array( 'id' => $id ),
			$this->formats_for_record( $normalized ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'cssflow_update_failed',
				__( 'CSSFlow could not update the CSS snippet.', 'cssflow' )
			);
		}

		return true;
	}

	/**
	 * Count snippets that reference one custom breakpoint key.
	 *
	 * Both active and inactive snippets are counted so deleting a breakpoint
	 * cannot leave an inactive snippet with a broken reference that appears
	 * later when the snippet is re-enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param string $breakpoint_key Stable custom breakpoint key.
	 * @return int Number of stored snippets using the breakpoint.
	 */
	public function count_breakpoint_references( $breakpoint_key ) {
		global $wpdb;

		if ( ! is_string( $breakpoint_key ) || '' === $breakpoint_key || sanitize_key( $breakpoint_key ) !== $breakpoint_key ) {
			return 0;
		}

		$table_name = Schema::table_name();
		$query      = $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE responsive_type = %s AND breakpoint_key = %s',
			$table_name,
			'custom',
			$breakpoint_key
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; breakpoint guards must use current custom-table references.
		return (int) $wpdb->get_var( $query );
	}

	/**
	 * Delete a snippet by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Snippet ID.
	 * @return bool True when a row was deleted, otherwise false.
	 */
	public function delete( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( 0 === $id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- CSSFlow owns this custom table; all writes are centralized here and write operations must not be cached.
		$deleted = $wpdb->delete(
			Schema::table_name(),
			array( 'id' => $id ),
			array( '%d' )
		);

		return 1 === $deleted;
	}

	/**
	 * Normalize and validate a snippet before storage.
	 *
	 * @since 1.0.0
	 *
	 * @param array      $data                 Snippet data.
	 * @param array|null $breakpoints_override Optional breakpoint configuration override.
	 * @return array|WP_Error Normalized database record or validation error.
	 */
	private function normalize_for_storage( array $data, $breakpoints_override = null ) {
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error(
				'cssflow_invalid_name',
				__( 'A CSS snippet name is required.', 'cssflow' )
			);
		}

		if ( strlen( $name ) > 191 ) {
			return new WP_Error(
				'cssflow_name_too_long',
				__( 'The CSS snippet name is too long.', 'cssflow' )
			);
		}

		$description = isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '';
		$css_code    = isset( $data['css_code'] ) && is_scalar( $data['css_code'] ) ? (string) $data['css_code'] : '';
		$max_size    = (int) apply_filters( 'cssflow_max_snippet_size', 400 * 1024 );

		if ( $max_size < 1 ) {
			$max_size = 400 * 1024;
		}

		if ( strlen( $css_code ) > $max_size ) {
			return new WP_Error(
				'cssflow_css_too_large',
				__( 'The CSS snippet exceeds the allowed size limit.', 'cssflow' )
			);
		}

		/*
		 * Sanitize HTML-like markup without adding slashes to valid CSS.
		 *
		 * wp_filter_nohtml_kses() is intended for use as a WordPress filter and
		 * returns slashed data. Storing that result would turn valid CSS such as
		 * content: \"\\f217\"; into escaped/slashed CSS and can trigger false
		 * editor validation errors. Calling wp_kses() directly preserves quotes
		 * and CSS escape sequences while retaining the existing no-HTML policy.
		 */
		$css_code = wp_kses( $css_code, array() );

		$scope_type = isset( $data['scope_type'] ) ? sanitize_key( $data['scope_type'] ) : '';

		if ( ! in_array( $scope_type, $this->scope_types, true ) ) {
			return new WP_Error(
				'cssflow_invalid_scope_type',
				__( 'The CSS snippet scope is invalid.', 'cssflow' )
			);
		}

		$scope_data = isset( $data['scope_data'] ) ? $data['scope_data'] : array();

		if ( is_string( $scope_data ) ) {
			$scope_data = json_decode( $scope_data, true );
		}

		if ( ! is_array( $scope_data ) ) {
			return new WP_Error(
				'cssflow_invalid_scope_data',
				__( 'The CSS snippet targeting data is invalid.', 'cssflow' )
			);
		}

		$scope_data = $this->normalize_scope_data( $scope_type, $scope_data );

		if ( is_wp_error( $scope_data ) ) {
			return $scope_data;
		}

		$scope_json = empty( $scope_data ) ? '{}' : wp_json_encode( $scope_data );

		if ( false === $scope_json ) {
			return new WP_Error(
				'cssflow_scope_encoding_failed',
				__( 'The CSS snippet targeting data could not be encoded.', 'cssflow' )
			);
		}

		$responsive_type = isset( $data['responsive_type'] ) ? sanitize_key( $data['responsive_type'] ) : 'all';

		if ( ! in_array( $responsive_type, $this->responsive_types, true ) ) {
			return new WP_Error(
				'cssflow_invalid_responsive_type',
				__( 'The CSS snippet responsive target is invalid.', 'cssflow' )
			);
		}

		$breakpoint_key = null;

		if ( 'custom' === $responsive_type ) {
			$breakpoint_key = isset( $data['breakpoint_key'] ) ? sanitize_key( $data['breakpoint_key'] ) : '';
			$breakpoints    = is_array( $breakpoints_override )
				? $breakpoints_override
				: get_option( 'cssflow_breakpoints', array() );

			if (
				'' === $breakpoint_key
				|| ! isset( $breakpoints[ $breakpoint_key ] )
				|| ! is_array( $breakpoints[ $breakpoint_key ] )
				|| ! empty( $breakpoints[ $breakpoint_key ]['is_default'] )
			) {
				return new WP_Error(
					'cssflow_invalid_breakpoint',
					__( 'The CSS snippet custom breakpoint is invalid.', 'cssflow' )
				);
			}
		}

		$priority_raw = isset( $data['priority'] ) ? $data['priority'] : 10;

		if ( is_int( $priority_raw ) ) {
			$priority = $priority_raw;
		} elseif ( is_string( $priority_raw ) && 1 === preg_match( '/^\d+$/D', $priority_raw ) ) {
			$priority = (int) $priority_raw;
		} else {
			return new WP_Error(
				'cssflow_invalid_priority',
				__( 'The CSS snippet priority must be between 0 and 65535.', 'cssflow' )
			);
		}

		if ( $priority < 0 || $priority > 65535 ) {
			return new WP_Error(
				'cssflow_invalid_priority',
				__( 'The CSS snippet priority must be between 0 and 65535.', 'cssflow' )
			);
		}

		$status = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'active';

		if ( ! in_array( $status, $this->statuses, true ) ) {
			return new WP_Error(
				'cssflow_invalid_status',
				__( 'The CSS snippet status is invalid.', 'cssflow' )
			);
		}

		$created_by = null;

		if ( array_key_exists( 'created_by', $data ) && null !== $data['created_by'] ) {
			$created_by = absint( $data['created_by'] );
			$created_by = $created_by > 0 ? $created_by : null;
		} else {
			$current_user_id = get_current_user_id();
			$created_by      = $current_user_id > 0 ? $current_user_id : null;
		}

		return array(
			'name'            => $name,
			'description'     => '' !== $description ? $description : null,
			'css_code'        => $css_code,
			'scope_type'      => $scope_type,
			'scope_data'      => $scope_json,
			'responsive_type' => $responsive_type,
			'breakpoint_key'  => $breakpoint_key,
			'priority'        => $priority,
			'status'          => $status,
			'created_by'      => $created_by,
		);
	}

	/**
	 * Determine whether a post type belongs to generic WordPress targeting.
	 *
	 * WooCommerce Products remain excluded because they use the dedicated
	 * WooCommerce scope introduced in Phase 9.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type key.
	 * @return bool
	 */
	public static function is_phase8_targetable_post_type( $post_type ) {
		if ( ! is_scalar( $post_type ) ) {
			return false;
		}

		$post_type = sanitize_key( (string) $post_type );

		if (
			'' === $post_type
			|| 'attachment' === $post_type
			|| 'product' === $post_type
		) {
			return false;
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object || empty( $post_type_object->public ) ) {
			return false;
		}

		if ( ! empty( $post_type_object->_builtin ) ) {
			return in_array(
				$post_type,
				array(
					'post',
					'page',
				),
				true
			);
		}

		return true;
	}

	/**
	 * Normalize scope data according to the selected scope type.
	 *
	 * Generic WordPress scopes are validated here. WooCommerce scope data is
	 * delegated to the optional Phase 9 integration when that integration is
	 * available, keeping WooCommerce-specific validation out of the base
	 * repository implementation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope_type Scope type.
	 * @param array  $scope_data Submitted scope data.
	 * @return array|WP_Error Normalized scope data or validation error.
	 */
	private function normalize_scope_data( $scope_type, array $scope_data ) {
		switch ( $scope_type ) {
			case 'global':
				if ( ! empty( $scope_data ) ) {
					return new WP_Error(
						'cssflow_invalid_scope_data',
						__( 'Global targeting must not contain additional targeting data.', 'cssflow' )
					);
				}

				return array();

			case 'specific_content':
				return $this->normalize_specific_content_scope_data( $scope_data );

			case 'post_type':
				return $this->normalize_post_type_scope_data( $scope_data );

			case 'special':
				return $this->normalize_special_scope_data( $scope_data );

			case 'woocommerce':
				if ( ! class_exists( WooCommerce::class ) || ! WooCommerce::is_available() ) {
					return new WP_Error(
						'cssflow_woocommerce_unavailable',
						__( 'WooCommerce targeting is unavailable because WooCommerce is not active.', 'cssflow' )
					);
				}

				return WooCommerce::normalize_scope_data( $scope_data );
		}

		return new WP_Error(
			'cssflow_invalid_scope_type',
			__( 'The CSS snippet scope is invalid.', 'cssflow' )
		);
	}

	/**
	 * Normalize specific-content scope data.
	 *
	 * @since 1.0.0
	 *
	 * @param array $scope_data Submitted scope data.
	 * @return array|WP_Error Normalized scope data or validation error.
	 */
	private function normalize_specific_content_scope_data( array $scope_data ) {
		if (
			array_keys( $scope_data ) !== array( 'post_type', 'ids' )
			&& array_keys( $scope_data ) !== array( 'ids', 'post_type' )
		) {
			return new WP_Error(
				'cssflow_invalid_scope_data',
				__( 'The specific-content targeting data is invalid.', 'cssflow' )
			);
		}

		$post_type = is_scalar( $scope_data['post_type'] ) ? sanitize_key( (string) $scope_data['post_type'] ) : '';

		if ( ! self::is_phase8_targetable_post_type( $post_type ) ) {
			return new WP_Error(
				'cssflow_invalid_target_post_type',
				__( 'The selected content type cannot be used for this CSSFlow targeting rule.', 'cssflow' )
			);
		}

		if ( ! is_array( $scope_data['ids'] ) || empty( $scope_data['ids'] ) ) {
			return new WP_Error(
				'cssflow_invalid_target_ids',
				__( 'Select at least one content item for specific-content targeting.', 'cssflow' )
			);
		}

		$ids = array();

		foreach ( $scope_data['ids'] as $raw_id ) {
			$object_id = $this->normalize_positive_integer( $raw_id );

			if ( 0 === $object_id || get_post_type( $object_id ) !== $post_type ) {
				return new WP_Error(
					'cssflow_invalid_target_ids',
					__( 'One or more selected content items are invalid for this targeting rule.', 'cssflow' )
				);
			}

			$ids[] = $object_id;
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );

		return array(
			'post_type' => $post_type,
			'ids'       => $ids,
		);
	}

	/**
	 * Normalize post-type scope data.
	 *
	 * @since 1.0.0
	 *
	 * @param array $scope_data Submitted scope data.
	 * @return array|WP_Error Normalized scope data or validation error.
	 */
	private function normalize_post_type_scope_data( array $scope_data ) {
		if ( array( 'post_type' ) !== array_keys( $scope_data ) ) {
			return new WP_Error(
				'cssflow_invalid_scope_data',
				__( 'The content-type targeting data is invalid.', 'cssflow' )
			);
		}

		$post_type = is_scalar( $scope_data['post_type'] ) ? sanitize_key( (string) $scope_data['post_type'] ) : '';

		if ( ! self::is_phase8_targetable_post_type( $post_type ) ) {
			return new WP_Error(
				'cssflow_invalid_target_post_type',
				__( 'The selected content type cannot be used for this CSSFlow targeting rule.', 'cssflow' )
			);
		}

		return array(
			'post_type' => $post_type,
		);
	}

	/**
	 * Normalize WordPress special-context scope data.
	 *
	 * @since 1.0.0
	 *
	 * @param array $scope_data Submitted scope data.
	 * @return array|WP_Error Normalized scope data or validation error.
	 */
	private function normalize_special_scope_data( array $scope_data ) {
		if ( array( 'context' ) !== array_keys( $scope_data ) ) {
			return new WP_Error(
				'cssflow_invalid_scope_data',
				__( 'The WordPress special-context targeting data is invalid.', 'cssflow' )
			);
		}

		$context = is_scalar( $scope_data['context'] ) ? sanitize_key( (string) $scope_data['context'] ) : '';

		if ( ! in_array( $context, array( 'front_page', 'posts_page', 'search', '404' ), true ) ) {
			return new WP_Error(
				'cssflow_invalid_special_context',
				__( 'The selected WordPress special context is invalid.', 'cssflow' )
			);
		}

		return array(
			'context' => $context,
		);
	}

	/**
	 * Normalize a positive integer without accepting partial or mixed values.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Candidate integer value.
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
	 * Build validated SQL WHERE fragments for admin list queries.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Query arguments.
	 * @return array{sql:string,values:array<int,string>} SQL fragment and values.
	 */
	private function build_admin_where_clauses( array $args ) {
		global $wpdb;

		$clauses    = array();
		$values     = array();
		$search     = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';
		$status     = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$scope      = isset( $args['scope_type'] ) ? sanitize_key( (string) $args['scope_type'] ) : '';
		$responsive = isset( $args['responsive_type'] ) ? sanitize_key( (string) $args['responsive_type'] ) : '';

		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = '(name LIKE %s OR description LIKE %s)';
			$values[]  = $like;
			$values[]  = $like;
		}

		if ( in_array( $status, $this->statuses, true ) ) {
			$clauses[] = 'status = %s';
			$values[]  = $status;
		}

		if ( in_array( $scope, $this->scope_types, true ) ) {
			$clauses[] = 'scope_type = %s';
			$values[]  = $scope;
		}

		if ( in_array( $responsive, $this->responsive_types, true ) ) {
			$clauses[] = 'responsive_type = %s';
			$values[]  = $responsive;
		}

		return array(
			'sql'    => empty( $clauses ) ? '' : ' WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * Normalize database values into predictable PHP values.
	 *
	 * @since 1.0.0
	 *
	 * @param array $row Raw database row.
	 * @return array Normalized snippet record.
	 */
	private function normalize_from_storage( array $row ) {
		$scope_data = json_decode( (string) $row['scope_data'], true );

		if ( ! is_array( $scope_data ) ) {
			$scope_data = array();
		}

		$row['id']             = (int) $row['id'];
		$row['scope_data']     = $scope_data;
		$row['priority']       = (int) $row['priority'];
		$row['created_by']     = null !== $row['created_by'] ? (int) $row['created_by'] : null;
		$row['description']    = null !== $row['description'] ? (string) $row['description'] : '';
		$row['breakpoint_key'] = null !== $row['breakpoint_key'] ? (string) $row['breakpoint_key'] : null;

		return $row;
	}

	/**
	 * Build wpdb format placeholders for a record.
	 *
	 * @since 1.0.0
	 *
	 * @param array $record Database record.
	 * @return string[] Format placeholders in record order.
	 */
	private function formats_for_record( array $record ) {
		$formats = array();

		foreach ( array_keys( $record ) as $field ) {
			if ( in_array( $field, array( 'priority', 'created_by' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		return $formats;
	}
}
