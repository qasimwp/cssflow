<?php
/**
 * Coordinates the CSSFlow plugin bootstrap lifecycle.
 *
 * @package CSSFlow
 */

namespace CSSFlow;

use CSSFlow\Admin\Admin;
use CSSFlow\Admin\Ajax;
use CSSFlow\Admin\Pages\Breakpoints_Page;
use CSSFlow\Admin\Pages\Settings_Page;
use CSSFlow\Admin\Pages\Support_Page;
use CSSFlow\Admin\Pages\Tools_Page;
use CSSFlow\Admin\Pages\Variables_Section;
use CSSFlow\CSS\Compiler;
use CSSFlow\CSS\File_Manager;
use CSSFlow\CSS\Generator;
use CSSFlow\CSS\Output_Manager;
use CSSFlow\CSS\Variables;
use CSSFlow\Database\Snippet_Repository;
use CSSFlow\Frontend\Frontend;
use CSSFlow\Import_Export\Exporter;
use CSSFlow\Import_Export\Importer;
use CSSFlow\Integrations\WooCommerce;
use CSSFlow\Support\Site_Health;
use CSSFlow\Targeting\Resolver;
use CSSFlow\Targeting\Scope_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main CSSFlow plugin coordinator.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether runtime bootstrap has already been registered or executed.
	 *
	 * @var bool
	 */
	private $running = false;

	/**
	 * Get the plugin instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Start the CSSFlow runtime bootstrap once.
	 *
	 * If WordPress has already fired plugins_loaded, load immediately.
	 * Otherwise, register the normal plugins_loaded callback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function run() {
		if ( $this->running ) {
			return;
		}

		$this->running = true;

		if ( did_action( 'plugins_loaded' ) ) {
			$this->load();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'load' ) );
	}

	/**
	 * Complete the plugin bootstrap after plugins are available.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load() {
		$this->load_database_classes();
		$this->load_targeting_classes();
		$this->load_integration_classes();
		$this->load_css_classes();
		$this->load_import_export_classes();
		$this->load_frontend_classes();
		$this->load_admin_classes();

		Upgrader::maybe_upgrade();
		$this->register_runtime_services();
	}

	/**
	 * Load database classes required by CSSFlow.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_database_classes() {
		require_once __DIR__ . '/database/class-schema.php';
		require_once __DIR__ . '/database/class-snippet-repository.php';
	}

	/**
	 * Load targeting-engine classes introduced in Phase 3.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_targeting_classes() {
		require_once __DIR__ . '/targeting/class-context.php';
		require_once __DIR__ . '/targeting/class-resolver.php';
		require_once __DIR__ . '/targeting/class-scope-registry.php';
	}

	/**
	 * Load optional integration classes only when their dependencies are active.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_integration_classes() {
		if ( class_exists( '\WooCommerce' ) ) {
			require_once __DIR__ . '/integrations/class-woocommerce.php';
		}
	}

	/**
	 * Load responsive compiler and CSS output classes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_css_classes() {
		require_once __DIR__ . '/css/class-compiler.php';
		require_once __DIR__ . '/css/class-variables.php';
		require_once __DIR__ . '/css/class-generator.php';
		require_once __DIR__ . '/css/class-file-manager.php';
		require_once __DIR__ . '/css/class-output-manager.php';
	}

	/**
	 * Load Phase 11 data portability services.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_import_export_classes() {
		require_once __DIR__ . '/import-export/class-exporter.php';
		require_once __DIR__ . '/import-export/class-importer.php';
		require_once __DIR__ . '/support/class-links.php';
		require_once __DIR__ . '/support/class-site-health.php';
	}

	/**
	 * Load the frontend integration class introduced in Phase 5.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_frontend_classes() {
		require_once dirname( __DIR__ ) . '/public/class-frontend.php';
	}

	/**
	 * Load CSSFlow administration classes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_admin_classes() {
		require_once dirname( __DIR__ ) . '/admin/class-assets.php';
		require_once dirname( __DIR__ ) . '/admin/class-ajax.php';
		require_once dirname( __DIR__ ) . '/admin/class-snippets-list-table.php';
		require_once dirname( __DIR__ ) . '/admin/pages/class-snippets-page.php';
		require_once dirname( __DIR__ ) . '/admin/pages/class-editor-page.php';
		require_once dirname( __DIR__ ) . '/admin/pages/class-variables-section.php';
		require_once dirname( __DIR__ ) . '/admin/pages/class-breakpoints-page.php';
		require_once dirname( __DIR__ ) . '/admin/pages/class-tools-page.php';
		require_once dirname( __DIR__ ) . '/admin/pages/class-settings-page.php';
		require_once dirname( __DIR__ ) . '/admin/pages/class-support-page.php';
		require_once dirname( __DIR__ ) . '/admin/class-admin.php';
	}

	/**
	 * Build and register shared frontend/output/admin services.
	 *
	 * The administration and frontend pipelines deliberately share the same
	 * repository and output manager so successful data mutations can
	 * invalidate disposable generated CSS without duplicating output logic.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function register_runtime_services() {
		if ( class_exists( WooCommerce::class ) && WooCommerce::is_available() ) {
			$woocommerce = new WooCommerce();
			$woocommerce->register();
		}

		$repository        = new Snippet_Repository();
		$scope_registry    = new Scope_Registry();
		$compiler          = new Compiler();
		$variables         = new Variables();
		$generator         = new Generator( $repository, $scope_registry, $compiler, $variables );
		$file_manager      = new File_Manager();
		$output_manager    = new Output_Manager( $generator, $file_manager );
		$frontend          = new Frontend( new Resolver(), $output_manager );
		$admin             = new Admin( $repository, $output_manager );
		$variables_section = new Variables_Section( $variables, $output_manager );
		$breakpoints_page  = new Breakpoints_Page( $repository, $compiler, $output_manager );
		$exporter          = new Exporter( $repository, $compiler, $variables );
		$importer          = new Importer( $repository, $compiler, $variables, $output_manager );
		$tools_page        = new Tools_Page( $exporter, $importer, $output_manager, $file_manager );
		$settings_page     = new Settings_Page( $output_manager );
		$support_page      = new Support_Page();
		$site_health       = new Site_Health( $repository, $output_manager, $file_manager );
		$ajax              = new Ajax( $repository, $output_manager );

		$output_manager->register_admin_hooks();
		$frontend->register();
		$admin->register();
		$breakpoints_page->register();
		$variables_section->register();
		$tools_page->register();
		$settings_page->register();
		$support_page->register();
		$site_health->register();
		$ajax->register();
	}

	/**
	 * Prevent direct construction outside the singleton accessor.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning the singleton.
	 *
	 * @return void
	 */
	private function __clone() {}
}
