<?php
/**
 * Plugin Name:       CSSFlow
 * Plugin URI:        https://qasim-wordpress-developer.com/cssflow/
 * Description:       Manage global, page, post, product, and responsive custom CSS from one organized WordPress interface.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Muhammad Qasim
 * Author URI:        https://qasim-wordpress-developer.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cssflow
 * Domain Path:       /languages
 *
 * @package CSSFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CSSFLOW_VERSION', '1.0.0' );
define( 'CSSFLOW_DB_VERSION', 1 );
define( 'CSSFLOW_MIN_WP_VERSION', '6.4' );
define( 'CSSFLOW_MIN_PHP_VERSION', '7.4' );
define( 'CSSFLOW_FILE', __FILE__ );
define( 'CSSFLOW_PATH', plugin_dir_path( __FILE__ ) );
define( 'CSSFLOW_URL', plugin_dir_url( __FILE__ ) );

require_once CSSFLOW_PATH . 'includes/database/class-schema.php';
require_once CSSFLOW_PATH . 'includes/database/class-snippet-repository.php';
require_once CSSFLOW_PATH . 'includes/support/class-capabilities.php';
require_once CSSFLOW_PATH . 'includes/class-upgrader.php';
require_once CSSFLOW_PATH . 'includes/class-activator.php';
require_once CSSFLOW_PATH . 'includes/class-deactivator.php';
require_once CSSFLOW_PATH . 'includes/class-plugin.php';

register_activation_hook( CSSFLOW_FILE, array( 'CSSFlow\\Activator', 'activate' ) );
register_deactivation_hook( CSSFLOW_FILE, array( 'CSSFlow\\Deactivator', 'deactivate' ) );

CSSFlow\Plugin::instance()->run();
