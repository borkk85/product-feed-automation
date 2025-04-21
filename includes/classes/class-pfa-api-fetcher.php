<?php
/**
 * Handles API communication for product data.
 *
 * @since      1.0.0
 * @package    Product_Feed_Automation
 */

class PFA_API_Fetcher {

    /**
     * The single instance of the class.
     *
     * @since    1.0.0
     * @access   protected
     * @var      PFA_API_Fetcher    $instance    The single instance of the class.
     */
    protected static $instance = null;

    /**
     * Excluded advertiser IDs that should be filtered out.
     *
     * @since    1.0.0
     * @access   private
     * @var      array    $excluded_advertisers    Array of advertiser IDs to exclude.
     */
    private $excluded_advertisers = array(984549, 984552, 985459);

    /**
     * Cache key for storing API products data.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $cache_key    Transient cache key.
     */
    private $cache_key = 'pfa_api_products_cache';

    /**
     * Cache expiration time in seconds.
     *
     * @since    1.0.0
     * @access   private
     * @var      int    $cache_expiry    Cache expiration in seconds.
     */
    private $cache_expiry = 3600;

    /**
     * Main PFA_API_Fetcher Instance.
     *
     * Ensures only one instance of PFA_API_Fetcher is loaded or can be loaded.
     *
     * @since    1.0.0
     * @return PFA_API_Fetcher - Main instance.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Prevent cloning.
     *
     * @since    1.0.0
     * @access   protected
     */
    protected function __clone() {}

    /**
     * Prevent unserializing.
     *
     * @since    1.0.0
     * @access   protected
     */
    public function __wakeup() {}

    /**
     * Constructor.
     *
     * @since    1.0.0
     * @access   protected
     */
    protected function __construct() {
        // Initialize if needed
    }

    /**
     * Fetch advertisers data from the API.
     *
     * @since    1.0.0
     * @return   array|WP_Error    Associative array of advertisers or WP_Error on failure.
     */
    public function fetch_advertisers() {
        $api_key = get_option('addrevenue_api_key');
        $channel_id = get_option('channel_id');
        
        $this->log_message('=== Fetching Advertisers ===');
        $api_url = "https://addrevenue.io/api/v2/advertisers?channelId={$channel_id}";
        $this->log_message('API URL: ' . $api_url);
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => "Bearer {$api_key}",
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            $this->log_message('Error fetching advertisers: ' . $response->get_error_message());
            return $response;
        }     
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['results'])) {
            $this->log_message('Invalid API Response for advertisers');
            return new WP_Error('invalid_response', 'Invalid API Response');
        }
        
        $advertisers = array();
        foreach ($data['results'] as $advertiser) {
            $advertisers[$advertiser['id']] = array(
                'displayName' => $advertiser['displayName'],
                'logoImageFilename' => $advertiser['logoImageFilename'],
            );
        }
        
        $this->log_message('Successfully fetched ' . count($advertisers) . ' advertisers');

        return $advertisers;
    }

    /**
     * Fetch products from the API.
     *
     * @since    1.0.0
     * @param    boolean    $force_fetch    Whether to force refresh the cache.
     * @return   array|false                Array of products or false on failure.
     */
    public function fetch_products($force_fetch = false) {
        $this->log_message('=== Starting Product Fetch ===');
        $this->log_message('Force fetch: ' . ($force_fetch ? 'true' : 'false'));
        
        if (!$this->should_fetch_products() && !$force_fetch) {
            $this->log_message('Fetch request denied - not a scheduled/required check');
            return $this->get_cached_products();
        }

        try {
            // Get cached data if exists and not forced
            if (!$force_fetch) {
                $cached_products = $this->get_cached_products();
                if ($cached_products !== false) {
                    $this->log_message('Returning cached products');
                    return $cached_products;
                }
            }
        
            $api_key = get_option('addrevenue_api_key');
            $channel_id = get_option('channel_id');
            $min_discount = get_option('min_discount', 0); 
            $limit = 200; // Fetch up to 200 products per page
            $all_products = array();
            $offset = 0; // Start with offset 0
            $total_count = null; // Initialize total_count to be fetched on the first API call

            $this->log_message('Discount Value: ' . $min_discount);
            
            do {
                $this->log_message(sprintf(
                    'Fetch Parameters - Offset: %d, Channel: %s, Min Discount: %d, Limit: %d',
                    $offset,
                    $channel_id,
                    $min_discount,
                    $limit
                ));
        
                $api_url = "https://addrevenue.io/api/v2/products?channelId={$channel_id}&market=SE&minDiscount={$min_discount}&limit={$limit}&offset={$offset}";
                $this->log_message('API URL: ' . $api_url);
        
                $response = wp_remote_get($api_url, array(
                    'headers' => array(
                        'Authorization' => "Bearer {$api_key}",
                    ),
                    'timeout' => 30,
                ));
        
                if (is_wp_error($response)) {
                    $this->log_message('Error fetching products: ' . $response->get_error_message());
                    break;
                }
        
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
        
                if (!$data || !isset($data['results'])) {
                    $this->log_message('Invalid API Response: ' . substr($body, 0, 1000));
                    break;
                }
        
                // Fetch total count on the first API response
                if ($total_count === null && isset($data['totalCount'])) {
                    $total_count = (int)$data['totalCount'];
                    $this->log_message('Total products in catalog: ' . $total_count);
                }
        
                // Filter out excluded advertisers
                $filtered_results = array_filter($data['results'], function($product) {
                    return !isset($product['advertiserId']) || 
                           !in_array($product['advertiserId'], $this->excluded_advertisers);
                });
        
                $product_count = count($filtered_results);
        
                // Add filtered results to the main array
                $all_products = array_merge($all_products, $filtered_results);
        
                // Log progress
                $this->log_message(sprintf(
                    'Offset: %d fetched %d products (Total unique so far: %d)',
                    $offset,
                    $product_count,
                    count($all_products)
                ));
        
                // Increment the offset for the next page
                $offset++;
        
                // Break if no more results are returned
                if ($product_count === 0) {
                    $this->log_message('No more products returned. Breaking loop.');
                    break;
                }
        
                // Break if all products are fetched
                if (count($all_products) >= $total_count) {
                    break;
                }
        
                usleep(250000); // Small delay to avoid overwhelming the API
        
            } while (true);
        
            $this->log_message(sprintf('Total products fetched (after filtering): %d', count($all_products)));
            $this->cache_products($all_products);
            return $all_products;
        
        } catch (Exception $e) {
            $this->log_message('Error in fetch_products: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Determines if the products API should be called.
     *
     * @since    1.0.0
     * @access   private
     * @return   boolean    True if API call is allowed, false otherwise.
     */
    private function should_fetch_products() {
        // Check if this is a scheduled API check
        if (current_filter() === 'pfa_api_check') {
            $this->log_message('API fetch allowed - Scheduled check');
            return true;
        }

        // Check if this is dripfeed publishing
        if (current_filter() === 'pfa_dripfeed_publisher') {
            $this->log_message('API fetch allowed - Dripfeed publishing');
            return true;
        }

        // Check if this is a manual discount check
        if (current_filter() === 'wp_ajax_pfa_check_discount_results') {
            $this->log_message('API fetch allowed - Manual discount check');
            return true;
        }

        $this->log_message('API fetch not allowed - Unauthorized context');
        return false;
    }

    /**
     * Get products from cache.
     *
     * @since    1.0.0
     * @access   private
     * @return   array|false    Cached products or false if not found.
     */
    private function get_cached_products() {
        $products = get_transient($this->cache_key);
        if ($products !== false) {
            $this->log_message('Found cached products');
            return $products;
        }
        return false;
    }

    /**
     * Store products in cache.
     *
     * @since    1.0.0
     * @access   private
     * @param    array    $products    Products to cache.
     */
    private function cache_products($products) {
        // Set cache expiry based on check interval
        $check_interval = get_option('check_interval', 'daily');
        switch ($check_interval) {
            case 'hourly':
                $this->cache_expiry = HOUR_IN_SECONDS;
                break;
            case 'twicedaily':
                $this->cache_expiry = 12 * HOUR_IN_SECONDS;
                break;
            case 'daily':
                $this->cache_expiry = DAY_IN_SECONDS;
                break;
        }

        set_transient($this->cache_key, $products, $this->cache_expiry);
        $this->log_message('Products cached for ' . $this->cache_expiry . ' seconds');
    }

    /**
     * Clear the product cache.
     *
     * @since    1.0.0
     */
    public function clear_cache() {
        delete_transient($this->cache_key);
        $this->log_message('Product cache cleared');
    }

    /**
     * Log messages to error log.
     *
     * @since    1.0.0
     * @access   private
     * @param    string    $message    Message to log.
     */
    private function log_message($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PFA API] ' . $message);
        }
    }
}