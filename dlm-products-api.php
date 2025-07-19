<?php
/**
 * Plugin Name: DLM Products API
 * Plugin URI: https://shoplic.kr
 * Description: Provides a REST API endpoint for retrieving WooCommerce products with Digital License Manager integration
 * Version: 1.0.1
 * Author: shoplic
 * Text Domain: dlm-products-api
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('DLM_PRODUCTS_API_VERSION', '1.0.0');
define('DLM_PRODUCTS_API_FILE', __FILE__);
define('DLM_PRODUCTS_API_PATH', plugin_dir_path(__FILE__));
define('DLM_PRODUCTS_API_URL', plugin_dir_url(__FILE__));

/**
 * Check dependencies
 */
function dlm_products_api_check_dependencies() {
    $missing_dependencies = array();
    
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        $missing_dependencies[] = 'WooCommerce';
    }
    
    // Check if Digital License Manager is active
    if (!defined('DLM_PLUGIN_VERSION')) {
        $missing_dependencies[] = 'Digital License Manager';
    }
    
    // Check if Digital License Manager Pro is active (optional but recommended)
    if (!defined('DLM_PRO_VERSION')) {
        // Not a hard requirement, but we'll note it
    }
    
    return $missing_dependencies;
}

/**
 * Plugin activation hook
 */
function dlm_products_api_activate() {
    $missing = dlm_products_api_check_dependencies();
    
    if (!empty($missing)) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                __('DLM Products API requires the following plugins to be active: %s', 'dlm-products-api'),
                implode(', ', $missing)
            )
        );
    }
}
register_activation_hook(__FILE__, 'dlm_products_api_activate');

/**
 * Initialize the plugin
 */
function dlm_products_api_init() {
    // Check dependencies on each load
    $missing = dlm_products_api_check_dependencies();
    if (!empty($missing)) {
        add_action('admin_notices', function() use ($missing) {
            ?>
            <div class="notice notice-error">
                <p>
                    <?php 
                    echo sprintf(
                        __('DLM Products API requires the following plugins to be active: %s', 'dlm-products-api'),
                        implode(', ', $missing)
                    );
                    ?>
                </p>
            </div>
            <?php
        });
        return;
    }
    
    // Load the REST API controller
    require_once DLM_PRODUCTS_API_PATH . 'includes/class-rest-controller.php';
    
    // Initialize REST API
    add_action('rest_api_init', 'dlm_products_api_register_routes');
}
add_action('plugins_loaded', 'dlm_products_api_init', 20);

/**
 * Register REST API routes
 */
function dlm_products_api_register_routes() {
    $controller = new DLM_Products_API_REST_Controller();
    $controller->register_routes();
}

/**
 * Add custom DLM endpoint to the endpoints list
 */
add_filter('dlm_rest_endpoints', function($endpoints) {
    $endpoints[] = array(
        'id'         => '050',
        'name'       => 'v1/products',
        'method'     => 'GET',
        'deprecated' => false,
    );
    
    return $endpoints;
});