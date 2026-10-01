<?php
/**
 * Provides optional WooCommerce targeting integration for CSSFlow.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Integrations;

use CSSFlow\Targeting\Context;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and evaluates WooCommerce targeting without making WooCommerce a
 * required CSSFlow dependency.
 */
final class WooCommerce {

	/**
	 * Frozen WooCommerce context keys for CSSFlow v1.0.
	 *
	 * @var string[]
	 */
	private static $contexts = array(
		'shop',
		'products',
		'specific_products',
		'product_category',
		'cart',
		'checkout',
		'my_account',
	);

	/**
	 * Determine whether WooCommerce is available for CSSFlow integration.
	 *
	 * This check deliberately relies on the WooCommerce main class rather than
	 * WooCommerce post types or taxonomies. CSSFlow registers scope providers
	 * during plugins_loaded, while WooCommerce registers its product post type
	 * and product taxonomies later during WordPress initialization.
	 *
	 * Product/post-type/taxonomy existence is validated where those objects are
	 * actually required, after WordPress and WooCommerce have initialized them.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( '\\WooCommerce' );
	}

	/**
	 * Get the frozen WooCommerce context keys supported by CSSFlow v1.0.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] WooCommerce context keys.
	 */
	public static function get_contexts() {
		return self::$contexts;
	}

	/**
	 * Register the WooCommerce scope provider through CSSFlow's scope registry
	 * extension point.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		if ( ! self::is_available() ) {
			return;
		}

		add_filter( 'cssflow_registered_scopes', array( $this, 'register_scope' ) );
	}

	/**
	 * Add the WooCommerce provider to the registered CSSFlow scopes.
	 *
	 * @since 1.0.0
	 *
	 * @param array $scopes Existing scope callbacks.
	 * @return array
	 */
	public function register_scope( $scopes ) {
		if ( ! is_array( $scopes ) || ! self::is_available() ) {
			return is_array( $scopes ) ? $scopes : array();
		}

		$scopes['woocommerce'] = array( $this, 'matches' );

		return $scopes;
	}

	/**
	 * Match one normalized WooCommerce targeting rule against the current request.
	 *
	 * @since 1.0.0
	 *
	 * @param array   $scope_data Normalized WooCommerce scope data.
	 * @param Context $context    Current CSSFlow request context.
	 * @return bool
	 */
	public function matches( array $scope_data, Context $context ) {
		if ( ! self::is_available() ) {
			return false;
		}

		$normalized = self::normalize_scope_data( $scope_data );

		if ( is_wp_error( $normalized ) ) {
			return false;
		}

		switch ( $normalized['context'] ) {
			case 'shop':
				return function_exists( 'is_shop' ) && is_shop();

			case 'products':
				return $context->is_singular()
					&& 'product' === $context->get_post_type();

			case 'specific_products':
				return $context->is_singular()
					&& 'product' === $context->get_post_type()
					&& in_array( $context->get_object_id(), $normalized['product_ids'], true );

			case 'product_category':
				if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
					return false;
				}

				return in_array(
					absint( get_queried_object_id() ),
					$normalized['term_ids'],
					true
				);

			case 'cart':
				return function_exists( 'is_cart' ) && is_cart();

			case 'checkout':
				return function_exists( 'is_checkout' ) && is_checkout();

			case 'my_account':
				return function_exists( 'is_account_page' ) && is_account_page();
		}

		return false;
	}

	/**
	 * Normalize and validate WooCommerce targeting data before persistence or use.
	 *
	 * Specific-product targeting stores product IDs in `product_ids`. The frozen
	 * specification defines the `specific_products` context but does not prescribe
	 * that inner key, so Phase 9 uses an explicit WooCommerce-specific key rather
	 * than overloading generic specific-content data.
	 *
	 * @since 1.0.0
	 *
	 * @param array $scope_data Submitted WooCommerce scope data.
	 * @return array|WP_Error Normalized scope data or validation error.
	 */
	public static function normalize_scope_data( array $scope_data ) {
		if ( ! isset( $scope_data['context'] ) || ! is_scalar( $scope_data['context'] ) ) {
			return new WP_Error(
				'cssflow_invalid_woocommerce_scope_data',
				__( 'The WooCommerce targeting data is invalid.', 'cssflow' )
			);
		}

		$context = sanitize_key( (string) $scope_data['context'] );

		if ( ! in_array( $context, self::$contexts, true ) ) {
			return new WP_Error(
				'cssflow_invalid_woocommerce_context',
				__( 'The selected WooCommerce context is invalid.', 'cssflow' )
			);
		}

		if ( 'specific_products' === $context ) {
			return self::normalize_specific_products( $scope_data );
		}

		if ( 'product_category' === $context ) {
			return self::normalize_product_categories( $scope_data );
		}

		if ( array( 'context' ) !== array_keys( $scope_data ) ) {
			return new WP_Error(
				'cssflow_invalid_woocommerce_scope_data',
				__( 'The WooCommerce targeting data contains unexpected values.', 'cssflow' )
			);
		}

		return array(
			'context' => $context,
		);
	}

	/**
	 * Normalize one or more specific WooCommerce Product IDs.
	 *
	 * @since 1.0.0
	 *
	 * @param array $scope_data Submitted scope data.
	 * @return array|WP_Error
	 */
	private static function normalize_specific_products( array $scope_data ) {
		$keys = array_keys( $scope_data );
		sort( $keys );

		if (
			array( 'context', 'product_ids' ) !== $keys
			|| ! is_array( $scope_data['product_ids'] )
			|| empty( $scope_data['product_ids'] )
		) {
			return new WP_Error(
				'cssflow_invalid_woocommerce_product_ids',
				__( 'Select at least one valid WooCommerce Product.', 'cssflow' )
			);
		}

		$product_ids = array();

		foreach ( $scope_data['product_ids'] as $raw_id ) {
			$product_id = self::normalize_positive_integer( $raw_id );

			if ( 0 === $product_id || 'product' !== get_post_type( $product_id ) ) {
				return new WP_Error(
					'cssflow_invalid_woocommerce_product_ids',
					__( 'One or more selected WooCommerce Products are invalid.', 'cssflow' )
				);
			}

			$product_ids[] = $product_id;
		}

		$product_ids = array_values( array_unique( $product_ids ) );
		sort( $product_ids, SORT_NUMERIC );

		return array(
			'context'     => 'specific_products',
			'product_ids' => $product_ids,
		);
	}

	/**
	 * Normalize one or more WooCommerce Product Category term IDs.
	 *
	 * @since 1.0.0
	 *
	 * @param array $scope_data Submitted scope data.
	 * @return array|WP_Error
	 */
	private static function normalize_product_categories( array $scope_data ) {
		$keys = array_keys( $scope_data );
		sort( $keys );

		if (
			array( 'context', 'term_ids' ) !== $keys
			|| ! is_array( $scope_data['term_ids'] )
			|| empty( $scope_data['term_ids'] )
		) {
			return new WP_Error(
				'cssflow_invalid_woocommerce_term_ids',
				__( 'Select at least one valid WooCommerce Product Category.', 'cssflow' )
			);
		}

		$term_ids = array();

		foreach ( $scope_data['term_ids'] as $raw_id ) {
			$term_id = self::normalize_positive_integer( $raw_id );
			$term    = $term_id > 0 ? get_term( $term_id, 'product_cat' ) : null;

			if (
				0 === $term_id
				|| ! $term
				|| is_wp_error( $term )
				|| 'product_cat' !== $term->taxonomy
			) {
				return new WP_Error(
					'cssflow_invalid_woocommerce_term_ids',
					__( 'One or more selected WooCommerce Product Categories are invalid.', 'cssflow' )
				);
			}

			$term_ids[] = $term_id;
		}

		$term_ids = array_values( array_unique( $term_ids ) );
		sort( $term_ids, SORT_NUMERIC );

		return array(
			'context'  => 'product_category',
			'term_ids' => $term_ids,
		);
	}

	/**
	 * Normalize a positive integer without accepting partial values.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Candidate value.
	 * @return int
	 */
	private static function normalize_positive_integer( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}

		if (
			! is_string( $value )
			|| 1 !== preg_match( '/^[1-9]\d*$/D', $value )
		) {
			return 0;
		}

		$value = (int) $value;

		return $value > 0 ? $value : 0;
	}
}
