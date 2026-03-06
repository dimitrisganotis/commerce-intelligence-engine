<?php

/**
 * Plugin Name:       Commerce Intelligence Engine
 * Plugin URI:        https://woocie.com
 * Description:       The Commerce Intelligence Engine (CIE) is an on-premise, transparent, and performance-optimized recommendation and basket intelligence system for WooCommerce stores.
 * Version:           1.0.0
 * Author:            ArtisWeb
 * Author URI:        https://artisweb.gr/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       commerce-intelligence-engine
 * Domain Path:       /languages
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 */
if ( ! defined( 'CIE_VERSION' ) ) {
	define( 'CIE_VERSION', '1.0.0' );
}

/**
 * Database schema version.
 */
if ( ! defined( 'CIE_DB_VERSION' ) ) {
	define( 'CIE_DB_VERSION', '1.0.0' );
}

/**
 * Absolute plugin directory path.
 */
if ( ! defined( 'CIE_PLUGIN_DIR' ) ) {
	define( 'CIE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Plugin base URL.
 */
if ( ! defined( 'CIE_PLUGIN_URL' ) ) {
	define( 'CIE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Default template path.
 */
if ( ! defined( 'CIE_TEMPLATE_PATH' ) ) {
	define( 'CIE_TEMPLATE_PATH', CIE_PLUGIN_DIR . 'public/templates/' );
}

require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-settings.php';
require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-activator.php';
require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-deactivator.php';
require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-db-migrator.php';
require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine.php';

register_activation_hook( __FILE__, array( 'Commerce_Intelligence_Engine_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Commerce_Intelligence_Engine_Deactivator', 'deactivate' ) );

/**
 * Starts the plugin runtime coordinator.
 *
 * @return void
 */
function cie_run_plugin() {
	$plugin = new Commerce_Intelligence_Engine();
	$plugin->run();
}

cie_run_plugin();
