<?php 

class ApiFetcher {

    private static $excluded_advertisers = [984549, 984552, 985459];
    private static $cache_key = 'pfa_api_products_cache';
    private static $cache_expiry = 3600; 
    
    public static function fetchAdvertisers() {
        $api_key = get_option('addrevenue_api_key');
        $channel_id = get_option('channel_id');
        
        error_log('=== Fetching Advertisers ===');
        $api_url = "https://addrevenue.io/api/v2/advertisers?channelId={$channel_id}";
        error_log('API URL: ' . $api_url);
        
        $response = wp_remote_get($api_url, [
            'headers' => [
                'Authorization' => "Bearer {$api_key}",
            ],
        ]);
        
        if(is_wp_error($response)) {
            error_log('Error fetching advertisers: ' . $response->get_error_message());
            return $response;
        }     
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if(!$data || !isset($data['results'])) {
            error_log('Invalid API Response for advertisers: ' . print_r($data, true));
            return new WP_Error('invalid response', 'Invalid API Response');
        }
        
        $advertisers = [];
        foreach($data['results'] as $advertiser) {
            $advertisers[$advertiser['id']] = [
                'displayName' => $advertiser['displayName'],
                'logoImageFilename' => $advertiser['logoImageFilename'],
            ];
        }
        
        error_log('Successfully fetched ' . count($advertisers) . ' advertisers');

        return $advertisers;
    }

    public static function fetchProducts($force_fetch = false) {
        error_log('=== Starting Product Fetch ===');
        error_log('Force fetch: ' . ($force_fetch ? 'true' : 'false'));
        if (!self::shouldFetchProducts() && !$force_fetch) {
            error_log('Fetch request denied - not a scheduled/required check');
            return self::getCachedProducts();
        }

        try {
            // Get cached data if exists and not forced
            if (!$force_fetch) {
                $cached_products = self::getCachedProducts();
                if ($cached_products !== false) {
                    error_log('Returning cached products');
                    return $cached_products;
                }
            }
        
            $api_key = get_option('addrevenue_api_key');
            $channel_id = get_option('channel_id');
            $min_discount = get_option('min_discount', 0); 
            $limit = 200; // Fetch up to 200 products per page
            $all_products = [];
            $offset = 0; // Start with offset 0
            $total_count = null; // Initialize total_count to be fetched on the first API call

        
        error_log('Discount Value: ' . $min_discount);
        
        do {
            error_log(sprintf(
                'Fetch Parameters - Offset: %d, Channel: %s, Min Discount: %d, Limit: %d',
                $offset,
                $channel_id,
                $min_discount,
                $limit
            ));
    
            $api_url = "https://addrevenue.io/api/v2/products?channelId={$channel_id}&market=SE&minDiscount={$min_discount}&limit={$limit}&offset={$offset}";
            error_log('API URL: ' . $api_url);
    
            $response = wp_remote_get($api_url, [
                'headers' => [
                    'Authorization' => "Bearer {$api_key}",
                ],
                'timeout' => 30,
            ]);
    
            if (is_wp_error($response)) {
                error_log('Error fetching products: ' . $response->get_error_message());
                break;
            }
    
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
    
            if (!$data || !isset($data['results'])) {
                error_log('Invalid API Response: ' . substr($body, 0, 1000));
                break;
            }
    
            // Fetch total count on the first API response
            if ($total_count === null && isset($data['totalCount'])) {
                $total_count = (int)$data['totalCount'];
                error_log('Total products in catalog: ' . $total_count);
            }
    
            // Filter out excluded advertisers
            $filtered_results = array_filter($data['results'], function($product) {
                return !isset($product['advertiserId']) || 
                       !in_array($product['advertiserId'], self::$excluded_advertisers);
            });
    
            $product_count = count($filtered_results);
    
            // Add filtered results to the main array
            $all_products = array_merge($all_products, $filtered_results);
    
            // Log progress
            error_log(sprintf(
                'Offset: %d fetched %d products (Total unique so far: %d)',
                $offset,
                $product_count,
                count($all_products)
            ));
    
            // Increment the offset for the next page
            $offset++;
    
            // Break if no more results are returned
            if ($product_count === 0) {
                error_log('No more products returned. Breaking loop.');
                break;
            }
    
            // Break if all products are fetched
            if (count($all_products) >= $total_count) {
                break;
            }
    
            usleep(250000); // Small delay to avoid overwhelming the API
    
        } while (true);
    
        error_log(sprintf('Total products fetched (after filtering): %d', count($all_products)));
        self::cacheProducts($all_products);
        return $all_products;
    
    } catch (Exception $e) {
        error_log('Error in fetchProducts: ' . $e->getMessage());
        return false;
    }
}
private static function shouldFetchProducts() {
    // Check if this is a scheduled API check
    if (current_filter() === 'pfa_api_check') {
        error_log('API fetch allowed - Scheduled check');
        return true;
    }

    // Check if this is dripfeed publishing
    if (current_filter() === 'pfa_dripfeed_publisher') {
        error_log('API fetch allowed - Dripfeed publishing');
        return true;
    }

    // Check if this is a manual discount check
    if (current_filter() === 'wp_ajax_pfa_check_discount_results') {
        error_log('API fetch allowed - Manual discount check');
        return true;
    }

    error_log('API fetch not allowed - Unauthorized context');
    return false;
}

private static function getCachedProducts() {
    $products = get_transient(self::$cache_key);
    if ($products !== false) {
        error_log('Found cached products');
        return $products;
    }
    return false;
}

private static function cacheProducts($products) {
    // Set cache expiry based on check interval
    $check_interval = get_option('check_interval', 'daily');
    switch ($check_interval) {
        case 'hourly':
            self::$cache_expiry = HOUR_IN_SECONDS;
            break;
        case 'twicedaily':
            self::$cache_expiry = 12 * HOUR_IN_SECONDS;
            break;
        case 'daily':
            self::$cache_expiry = DAY_IN_SECONDS;
            break;
    }

    set_transient(self::$cache_key, $products, self::$cache_expiry);
    error_log('Products cached for ' . self::$cache_expiry . ' seconds');
}

public static function clearCache() {
    delete_transient(self::$cache_key);
    error_log('Product cache cleared');
}

}