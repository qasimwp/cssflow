<?php
/**
 * Integrates the CSSFlow output engine with WordPress frontend requests.
 *
 * @package CSSFlow
 */

namespace CSSFlow\Frontend;

use CSSFlow\CSS\Output_Manager;
use CSSFlow\Targeting\Context;
use CSSFlow\Targeting\Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves each frontend request and hands it to the CSSFlow output manager.
 */
final class Frontend {

	/**
	 * Context resolver.
	 *
	 * @var Resolver
	 */
	private $resolver;

	/**
	 * Output manager.
	 *
	 * @var Output_Manager
	 */
	private $output_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Resolver       $resolver       Targeting context resolver.
	 * @param Output_Manager $output_manager CSS output manager.
	 */
	public function __construct( Resolver $resolver, Output_Manager $output_manager ) {
		$this->resolver       = $resolver;
		$this->output_manager = $output_manager;
	}

	/**
	 * Register frontend hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Resolve the current request and enqueue only its applicable CSSFlow CSS.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( is_admin() ) {
			return;
		}

		$context = $this->resolver->resolve();

		if ( ! $context instanceof Context ) {
			return;
		}

		$this->output_manager->enqueue( $context );
	}
}
