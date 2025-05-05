<?php
/**
 * Plugin Name: Store Order Sync
 * Description: Syncs WooCommerce orders with Hub website
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: store-order-sync
 */

defined('ABSPATH') || exit;

// Define constants
define('STORE_ORDER_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('STORE_ORDER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('STORE_ORDER_PLUGIN_VERSION', '1.0.0');

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>';
        _e('Store Order Sync requires WooCommerce to be installed and active!', 'store-order-sync');
        echo '</p></div>';
    });
    return;
}

// Include required files
require_once STORE_ORDER_PLUGIN_PATH . 'includes/class-order-sync.php';
require_once STORE_ORDER_PLUGIN_PATH . 'includes/class-api-handler.php';
require_once STORE_ORDER_PLUGIN_PATH . 'includes/class-settings.php';

// Initialize plugin
function store_order_plugin_init() {
    new Store_Order_Sync();
    new Store_API_Handler();
    new Store_Plugin_Settings();
}
add_action('plugins_loaded', 'store_order_plugin_init');

// Activation/deactivation hooks
register_activation_hook(__FILE__, ['Store_Order_Sync', 'activate']);
register_deactivation_hook(__FILE__, ['Store_Order_Sync', 'deactivate']);