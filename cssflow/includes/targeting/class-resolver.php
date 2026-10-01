<?php
/**
 * Resolves the current WordPress request into a CSSFlow targeting context.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Targeting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts WordPress conditional-query state into a normalized Context object.
 */
final class Resolver {

	/**
	 * Resolve the current WordPress request.
	 *
	 * @since 1.0.0
	 *
	 * @return Context
	 */
	public function resolve() {
		$is_singular = is_singular();
		$object_id   = 0;
		$post_type   = '';

		if ( $is_singular ) {
			$object_id = get_queried_object_id();

			if ( $object_id > 0 ) {
				$resolved_post_type = get_post_type( $object_id );

				if ( is_string( $resolved_post_type ) ) {
					$post_type = $resolved_post_type;
				}
			}
		}

		$special_contexts = array();

		if ( is_front_page() ) {
			$special_contexts[] = 'front_page';
		}

		if ( is_home() ) {
			$special_contexts[] = 'posts_page';
		}

		if ( is_search() ) {
			$special_contexts[] = 'search';
		}

		if ( is_404() ) {
			$special_contexts[] = '404';
		}

		return new Context(
			$is_singular,
			$object_id,
			$post_type,
			$special_contexts
		);
	}
}
