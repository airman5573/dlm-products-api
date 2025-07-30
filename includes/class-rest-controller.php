<?php
/**
 * REST API Controller for DLM Products
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class DLM_Products_API_REST_Controller
 */
class DLM_Products_API_REST_Controller extends WP_REST_Controller {
    
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'dlm/v1';
    
    /**
     * Route base
     *
     * @var string
     */
    protected $rest_base = 'products';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->namespace = 'dlm/v1';
        $this->rest_base = 'products';
    }
    
    /**
     * Register the routes
     */
    public function register_routes() {
        register_rest_route($this->namespace, '/' . $this->rest_base, array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_items'),
                'permission_callback' => array($this, 'get_items_permissions_check'),
                'args'                => $this->get_collection_params(),
            ),
            'schema' => array($this, 'get_public_item_schema'),
        ));
    }
    
    /**
     * Check permissions for reading products
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function get_items_permissions_check($request) {
        // 인증 없이 모든 요청 허용
        return true;
        
        /* 나중에 인증이 필요하면 아래 코드를 활성화하세요
        // Check for consumer key and secret
        if (!is_user_logged_in()) {
            // Check WooCommerce API authentication
            $consumer_key = $request->get_param('consumer_key');
            $consumer_secret = $request->get_param('consumer_secret');
            
            if (empty($consumer_key) || empty($consumer_secret)) {
                return new WP_Error(
                    'rest_forbidden',
                    __('Authentication required.', 'dlm-products-api'),
                    array('status' => 401)
                );
            }
            
            // Validate consumer key and secret
            if (!$this->validate_api_credentials($consumer_key, $consumer_secret)) {
                return new WP_Error(
                    'rest_forbidden',
                    __('Invalid authentication credentials.', 'dlm-products-api'),
                    array('status' => 401)
                );
            }
        }
        
        return true;
        */
    }
    
    /**
     * Get products list
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_items($request) {
        $page = (int) $request->get_param('page') ?: 1;
        $per_page = (int) $request->get_param('per_page') ?: 100;
        $per_page = min($per_page, 100); // Limit to 100 items per page
        
        // Query arguments for WooCommerce products
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'meta_query'     => array(
                array(
                    'key'     => 'dlm_licensed_product',
                    'value'   => '1',
                    'compare' => '='
                )
            )
        );
        
        $query = new WP_Query($args);
        $products = array();
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $product_id = get_the_ID();
                $product = wc_get_product($product_id);
                
                if (!$product) {
                    continue;
                }
                
                // Get DLM settings for this product
                $dlm_settings = $this->get_product_dlm_settings($product);
                
                // Get software ID if available (from DLM Pro)
                $software_id = get_post_meta($product_id, 'dlm_software_id', true);
                if (empty($software_id) && class_exists('\IdeoLogix\DigitalLicenseManagerPro\Database\Repositories\Software')) {
                    // Try to find software by product ID
                    $software_repo = \IdeoLogix\DigitalLicenseManagerPro\Database\Repositories\Software::instance();
                    $software = $software_repo->findBy(array('product_id' => $product_id));
                    if ($software && !empty($software)) {
                        $software_id = $software[0]->getId();
                    }
                }
                
                // Get current version from software release if available
                $current_version = '';
                $stable_release = null;
                $software_info = null;
                
                if ($software_id) {
                    // Get software entity for documentation and support URLs
                    if (class_exists('\IdeoLogix\DigitalLicenseManagerPro\Database\Repositories\Software')) {
                        $software_repo = \IdeoLogix\DigitalLicenseManagerPro\Database\Repositories\Software::instance();
                        $software_entity = $software_repo->find($software_id);
                        if ($software_entity) {
                            $software_info = array(
                                'documentation' => $software_entity->getDocumentation(),
                                'support' => $software_entity->getSupport(),
                            );
                        }
                    }
                    
                    // Get stable release info
                    if (class_exists('\IdeoLogix\DigitalLicenseManagerPro\Database\Repositories\SoftwareReleases')) {
                        $releases_repo = \IdeoLogix\DigitalLicenseManagerPro\Database\Repositories\SoftwareReleases::instance();
                        $stable_release_data = $releases_repo->findBy(array(
                            'software_id' => $software_id,
                            'is_stable' => 1
                        ));
                        
                        if ($stable_release_data && !empty($stable_release_data)) {
                            $release = $stable_release_data[0];
                            $current_version = $release->getVersion();
                            
                            // Build stable release info
                            $stable_release = array(
                                'id' => $release->getId(),
                                'version' => $release->getVersion(),
                                'download_url' => home_url('/wp-json/dlm/v1/software/download/'),
                                'changelog' => $release->getChangelog(),
                                'created_at' => $release->getCreatedAt(),
                            );
                            
                            // Try to get version requirements
                            $tested_wp = method_exists($release, 'getTestedWp') ? $release->getTestedWp() : null;
                            $requires_wp = method_exists($release, 'getRequiresWp') ? $release->getRequiresWp() : null;
                            $requires_php = method_exists($release, 'getRequiresPhp') ? $release->getRequiresPhp() : null;
                            
                            // Add to stable_release array if available
                            if ($tested_wp) $stable_release['tested_wp'] = $tested_wp;
                            if ($requires_wp) $stable_release['requires_wp'] = $requires_wp;
                            if ($requires_php) $stable_release['requires_php'] = $requires_php;
                        }
                    }
                }
                
                // Build product data
                $product_data = array(
                    'id'              => $product_id,
                    'software_id'     => $software_id ?: $product_id, // Fallback to product ID
                    'product_id'      => $product_id,
                    'slug'            => $product->get_slug(),
                    'name'            => $product->get_name(),
                    'description'     => $product->get_short_description() ?: $product->get_description(),
                    'current_version' => $current_version,
                    'type'            => 'plugin', // Default to plugin, can be extended
                    'thumbnail'       => wp_get_attachment_url($product->get_image_id()),
                    'permalink'       => $product->get_permalink(),
                    'price'           => $product->get_price(),
                    'currency'        => get_woocommerce_currency(),
                    'dlm_settings'    => $dlm_settings,
                    'stable_release'  => $stable_release,
                    'software_info'   => $software_info,
                );
                
                $products[] = $product_data;
            }
            
            wp_reset_postdata();
        }
        
        // Prepare response
        
        $response = array(
            'success' => true,
            'data'    => $products,
            'meta'    => array(
                'page'       => $page,
                'per_page'   => $per_page,
                'total'      => $query->found_posts,
                'total_pages' => $query->max_num_pages,
            )
        );
        
        return rest_ensure_response($response);
    }
    
    /**
     * Get DLM settings for a product
     *
     * @param WC_Product $product
     * @return array
     */
    protected function get_product_dlm_settings($product) {
        $product_id = $product->get_id();
        
        $settings = array(
            'licensed_product'     => get_post_meta($product_id, 'dlm_licensed_product', true) === '1',
            'licenses_source'      => get_post_meta($product_id, '_dlm_licenses_source', true) ?: 'stock',
            'activations_limit'    => (int) get_post_meta($product_id, '_dlm_activations_limit', true),
            'license_validity'     => get_post_meta($product_id, '_dlm_license_validity', true),
            'license_validity_period' => get_post_meta($product_id, '_dlm_license_validity_period', true),
            'generator_id'         => (int) get_post_meta($product_id, '_dlm_generator', true),
            'use_generator'        => get_post_meta($product_id, '_dlm_use_generator', true) === 'yes',
        );
        
        // Handle variations
        if ($product->is_type('variable')) {
            $variations = $product->get_children();
            $variations_settings = array();
            
            foreach ($variations as $variation_id) {
                $variations_settings[$variation_id] = array(
                    'licensed_product'     => get_post_meta($variation_id, 'dlm_licensed_product', true) === '1',
                    'licenses_source'      => get_post_meta($variation_id, '_dlm_licenses_source', true) ?: 'stock',
                    'activations_limit'    => (int) get_post_meta($variation_id, '_dlm_activations_limit', true),
                    'license_validity'     => get_post_meta($variation_id, '_dlm_license_validity', true),
                    'license_validity_period' => get_post_meta($variation_id, '_dlm_license_validity_period', true),
                    'generator_id'         => (int) get_post_meta($variation_id, '_dlm_generator', true),
                    'use_generator'        => get_post_meta($variation_id, '_dlm_use_generator', true) === 'yes',
                );
            }
            
            $settings['variations'] = $variations_settings;
        }
        
        return $settings;
    }
    
    /**
     * Validate API credentials
     *
     * @param string $consumer_key
     * @param string $consumer_secret
     * @return bool
     */
    protected function validate_api_credentials($consumer_key, $consumer_secret) {
        global $wpdb;
        
        // Check WooCommerce API keys table
        $key = $wpdb->get_row($wpdb->prepare("
            SELECT key_id, user_id, permissions, consumer_secret
            FROM {$wpdb->prefix}woocommerce_api_keys
            WHERE consumer_key = %s
        ", $consumer_key));
        
        if (!$key) {
            return false;
        }
        
        // Verify the consumer secret
        if (!hash_equals($key->consumer_secret, $consumer_secret)) {
            return false;
        }
        
        // Check if the key has read permissions
        if (!in_array($key->permissions, array('read', 'write', 'read_write'))) {
            return false;
        }
        
        // Set the current user for this request
        wp_set_current_user($key->user_id);
        
        return true;
    }
    
    /**
     * Get collection parameters
     *
     * @return array
     */
    public function get_collection_params() {
        return array(
            'page' => array(
                'description'       => __('Current page of the collection.', 'dlm-products-api'),
                'type'              => 'integer',
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
                'minimum'           => 1,
            ),
            'per_page' => array(
                'description'       => __('Maximum number of items to be returned in result set.', 'dlm-products-api'),
                'type'              => 'integer',
                'default'           => 100,
                'minimum'           => 1,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
            ),
            'consumer_key' => array(
                'description'       => __('WooCommerce API consumer key.', 'dlm-products-api'),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'consumer_secret' => array(
                'description'       => __('WooCommerce API consumer secret.', 'dlm-products-api'),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }
}