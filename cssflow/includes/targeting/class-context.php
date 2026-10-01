<?php
/**
 * Represents the normalized WordPress request context used by CSSFlow targeting.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Targeting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable-style value object describing the current request context.
 *
 * This class contains request facts only. It does not query snippets,
 * compile CSS, or output frontend assets.
 */
final class Context {

	/**
	 * Whether the request represents a singular object.
	 *
	 * @var bool
	 */
	private $singular;

	/**
	 * Current queried object ID.
	 *
	 * @var int
	 */
	private $object_id;

	/**
	 * Current queried object's post type.
	 *
	 * @var string
	 */
	private $post_type;

	/**
	 * Applicable WordPress special contexts.
	 *
	 * @var string[]
	 */
	private $special_contexts;

	/**
	 * Allowed WordPress special-context keys for CSSFlow v1.0.
	 *
	 * @var string[]
	 */
	private $allowed_special_contexts = array(
		'front_page',
		'posts_page',
		'search',
		'404',
	);

	/**
	 * Create a normalized request context.
	 *
	 * @since 1.0.0
	 *
	 * @param bool     $singular         Whether this is a singular request.
	 * @param int      $object_id        Queried object ID, or zero when unavailable.
	 * @param string   $post_type        Queried object's post type, or empty string.
	 * @param string[] $special_contexts Applicable special contexts.
	 */
	public function __construct( $singular, $object_id, $post_type, array $special_contexts = array() ) {
		$this->singular  = (bool) $singular;
		$this->object_id = absint( $object_id );
		$this->post_type = sanitize_key( $post_type );

		$normalized_special_contexts = array();

		foreach ( $special_contexts as $special_context ) {
			if ( ! is_scalar( $special_context ) ) {
				continue;
			}

			$special_context = sanitize_key( (string) $special_context );

			if (
				in_array( $special_context, $this->allowed_special_contexts, true )
				&& ! in_array( $special_context, $normalized_special_contexts, true )
			) {
				$normalized_special_contexts[] = $special_context;
			}
		}

		$this->special_contexts = $normalized_special_contexts;
	}

	/**
	 * Determine whether this is a singular WordPress request.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_singular() {
		return $this->singular;
	}

	/**
	 * Get the current queried object ID.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_object_id() {
		return $this->object_id;
	}

	/**
	 * Get the current queried object's post type.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_post_type() {
		return $this->post_type;
	}

	/**
	 * Get all applicable WordPress special contexts.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_special_contexts() {
		return $this->special_contexts;
	}

	/**
	 * Determine whether a WordPress special context applies.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Special-context key.
	 * @return bool
	 */
	public function is_special( $context ) {
		$context = sanitize_key( $context );

		return in_array( $context, $this->special_contexts, true );
	}
}
