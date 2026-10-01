<?php
/**
 * Registers and evaluates CSSFlow snippet targeting scopes.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Targeting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registry for CSSFlow scope matching.
 *
 * Built-in Phase 3 providers cover global, specific-content, post-type, and
 * WordPress special targeting. WooCommerce registers separately in its own
 * later integration phase.
 */
final class Scope_Registry {

	/**
	 * Registered scope callbacks.
	 *
	 * Each callback receives the normalized scope data and current Context
	 * object and must return whether the scope applies.
	 *
	 * @var callable[]
	 */
	private $scopes = array();

	/**
	 * Register the Phase 3 built-in scopes and apply the public scope filter.
	 */
	public function __construct() {
		$scopes = array(
			'global'           => array( $this, 'matches_global' ),
			'specific_content' => array( $this, 'matches_specific_content' ),
			'post_type'        => array( $this, 'matches_post_type' ),
			'special'          => array( $this, 'matches_special' ),
		);

		/**
		 * Filters the registered CSSFlow targeting scope providers.
		 *
		 * Each entry must use a sanitized scope key and a callable accepting:
		 *
		 * 1. array   $scope_data Normalized scope configuration.
		 * 2. Context $context    Current CSSFlow request context.
		 *
		 * The callback must return true when the scope applies.
		 *
		 * @since 1.0.0
		 *
		 * @param array $scopes Registered scope callbacks keyed by scope type.
		 */
		$scopes = apply_filters( 'cssflow_registered_scopes', $scopes );

		if ( ! is_array( $scopes ) ) {
			$scopes = array();
		}

		foreach ( $scopes as $scope_type => $callback ) {
			if ( ! is_string( $scope_type ) ) {
				continue;
			}

			$scope_type = sanitize_key( $scope_type );

			if ( '' === $scope_type || ! is_callable( $callback ) ) {
				continue;
			}

			$this->scopes[ $scope_type ] = $callback;
		}
	}

	/**
	 * Get the registered scope types.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_scope_types() {
		return array_keys( $this->scopes );
	}

	/**
	 * Determine whether a scope type is registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope_type Scope type.
	 * @return bool
	 */
	public function has( $scope_type ) {
		$scope_type = sanitize_key( $scope_type );

		return isset( $this->scopes[ $scope_type ] );
	}

	/**
	 * Determine whether a snippet scope applies to the current context.
	 *
	 * @since 1.0.0
	 *
	 * @param string  $scope_type Scope type.
	 * @param array   $scope_data Normalized scope configuration.
	 * @param Context $context    Current request context.
	 * @return bool
	 */
	public function matches( $scope_type, array $scope_data, Context $context ) {
		$scope_type = sanitize_key( $scope_type );

		if ( ! isset( $this->scopes[ $scope_type ] ) ) {
			return false;
		}

		return true === (bool) call_user_func(
			$this->scopes[ $scope_type ],
			$scope_data,
			$context
		);
	}

	/**
	 * Match the global scope.
	 *
	 * Global CSS applies to every normal request context.
	 *
	 * @since 1.0.0
	 *
	 * @param array   $scope_data Scope data.
	 * @param Context $context    Current request context.
	 * @return bool
	 */
	public function matches_global( array $scope_data, Context $context ) {
		unset( $scope_data, $context );

		return true;
	}

	/**
	 * Match one or more specific WordPress content objects.
	 *
	 * @since 1.0.0
	 *
	 * @param array   $scope_data Scope configuration.
	 * @param Context $context    Current request context.
	 * @return bool
	 */
	public function matches_specific_content( array $scope_data, Context $context ) {
		if ( ! $context->is_singular() ) {
			return false;
		}

		if (
			! isset( $scope_data['post_type'] )
			|| ! is_scalar( $scope_data['post_type'] )
			|| ! isset( $scope_data['ids'] )
			|| ! is_array( $scope_data['ids'] )
		) {
			return false;
		}

		$post_type = sanitize_key( (string) $scope_data['post_type'] );

		if (
			'' === $post_type
			|| $post_type !== $context->get_post_type()
		) {
			return false;
		}

		$object_ids = array();

		foreach ( $scope_data['ids'] as $object_id ) {
			if ( ! is_scalar( $object_id ) ) {
				continue;
			}

			$object_id = absint( $object_id );

			if ( $object_id > 0 ) {
				$object_ids[] = $object_id;
			}
		}

		$object_ids = array_values( array_unique( $object_ids ) );

		return in_array( $context->get_object_id(), $object_ids, true );
	}

	/**
	 * Match all singular items of a selected post type.
	 *
	 * @since 1.0.0
	 *
	 * @param array   $scope_data Scope configuration.
	 * @param Context $context    Current request context.
	 * @return bool
	 */
	public function matches_post_type( array $scope_data, Context $context ) {
		if (
			! $context->is_singular()
			|| ! isset( $scope_data['post_type'] )
			|| ! is_scalar( $scope_data['post_type'] )
		) {
			return false;
		}

		$post_type = sanitize_key( (string) $scope_data['post_type'] );

		if ( '' === $post_type ) {
			return false;
		}

		return $post_type === $context->get_post_type();
	}

	/**
	 * Match one of CSSFlow's supported WordPress special contexts.
	 *
	 * @since 1.0.0
	 *
	 * @param array   $scope_data Scope configuration.
	 * @param Context $context    Current request context.
	 * @return bool
	 */
	public function matches_special( array $scope_data, Context $context ) {
		if (
			! isset( $scope_data['context'] )
			|| ! is_scalar( $scope_data['context'] )
		) {
			return false;
		}

		$special_context = sanitize_key( (string) $scope_data['context'] );

		if (
			! in_array(
				$special_context,
				array(
					'front_page',
					'posts_page',
					'search',
					'404',
				),
				true
			)
		) {
			return false;
		}

		return $context->is_special( $special_context );
	}
}
