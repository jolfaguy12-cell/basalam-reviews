<?php
/**
 * Plugin Name: Basalam Review Plugin
 * Plugin URI:  https://github.com/jolfaguy12-cell/basalam-reviews
 * Description: Receives reviews from the Basalam sync service and inserts them into WooCommerce.
 * Version:     1.2.0
 * Author:      Behdashtik
 * Text Domain: basalam-review-plugin
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'BRP_VERSION',    '1.2.0' );
define( 'BRP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BRP_OPTION_KEY', 'brp_settings' );

require_once BRP_PLUGIN_DIR . 'includes/class-brp-security.php';
require_once BRP_PLUGIN_DIR . 'includes/class-brp-settings.php';
require_once BRP_PLUGIN_DIR . 'includes/class-brp-processor.php';
require_once BRP_PLUGIN_DIR . 'includes/class-brp-api.php';

add_action( 'plugins_loaded', function () {
    BRP_Settings::init();
    BRP_API::init();
} );

register_activation_hook( __FILE__, function () {
    if ( ! get_option( BRP_OPTION_KEY ) ) {
        update_option( BRP_OPTION_KEY, BRP_Settings::defaults() );
    }
} );
