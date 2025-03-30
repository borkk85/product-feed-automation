<?php

if (!defined('ABSPATH')) exit;

add_action('wp_ajax_pfa_toggle_automation', 'pfa_toggle_automation');
function pfa_toggle_automation() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');

    $status = sanitize_text_field($_POST['status']);
    update_option('pfa_automation_enabled', $status);

    wp_send_json_success(['message' => 'Automation status updated to ' . $status]);
}


add_action('wp_ajax_save_ai_workflow_settings', 'save_ai_workflow_settings');
function save_ai_workflow_settings() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');

    // Previous settings that were being saved
    update_option('addrevenue_api_key', sanitize_text_field($_POST['addrevenue_api_key']));
    update_option('ai_api_key', sanitize_text_field($_POST['ai_api_key']));
    update_option('channel_id', sanitize_text_field($_POST['channel_id']));
    update_option('ai_model', sanitize_text_field($_POST['ai_model']));
    update_option('min_discount', sanitize_text_field($_POST['min_discount']));
    update_option('max_posts_per_day', sanitize_text_field($_POST['max_posts_per_day']));
    update_option('max_tokens', sanitize_text_field($_POST['max_tokens']));
    update_option('temperature', sanitize_text_field($_POST['temperature']));
    update_option('prompt_for_ai', sanitize_textarea_field($_POST['prompt_for_ai']));
    update_option('check_interval', sanitize_text_field($_POST['check_interval']));
    update_option('dripfeed_interval', sanitize_text_field($_POST['dripfeed_interval']));

    // Clear all existing schedules
    wp_clear_scheduled_hook('pfa_api_check');
    wp_clear_scheduled_hook('pfa_daily_check');
    wp_clear_scheduled_hook('pfa_dripfeed_publisher');

    // Initialize new schedules
    $scheduler = PostScheduler::getInstance();
    $scheduler->initializeSchedules();

    // Force QueueManager to refresh its status
    $queueManager = QueueManager::getInstance();
    $queueManager->clearStatusCache();  // Clear the cache
    
    // Get fresh status that will include new interval settings
    $status = $queueManager->getStatus(true);

    wp_send_json_success([
        'message' => 'Settings saved successfully.',
        'status' => $status, // the refreshed status after cache clear
        'check_interval' => get_option('check_interval', 'daily'),
    ]);
}

// AJAX: Manual Post Creation
add_action('wp_ajax_pfa_create_manual_post', 'pfa_create_manual_post');
function pfa_create_manual_post() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');

    $title = sanitize_text_field($_POST['post_title']);
    $featured_image = esc_url_raw($_POST['featured_image']);
    $product_url = esc_url_raw($_POST['product_url']);
    $price = floatval($_POST['price']);
    $sale_price = floatval($_POST['sale_price']);
    $brand = sanitize_text_field($_POST['brand']);
    $brand_image = esc_url_raw($_POST['brand_image']);
    $category = sanitize_text_field($_POST['category']);
    $category_id = isset($_POST['product_category']) ? intval($_POST['product_category']) : null;

    $postCreator = PostCreator::getInstance();
    $result = $postCreator->createManualProductPost($title, $featured_image, $product_url, $price, $sale_price, $brand, $category, $brand_image, $category_id);

    if (isset($result['status']) && $result['status'] === 'success') {
        wp_send_json_success(['post_id' => $result['post_id']]);
    } else {
        wp_send_json_error(['message' => $result['message'] ?? 'Unknown error']);
    }
}

// AJAX handler to toggle automation status
function ajax_toggle_automation_status() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }
    
    $status = sanitize_text_field($_POST['status']);
    update_option('pfa_automation_enabled', $status);

    wp_send_json_success(['status' => $status === 'yes' ? 'Active' : 'Paused']);
}
add_action('wp_ajax_toggle_automation', 'ajax_toggle_automation_status');

// AJAX handler for reset processing
function pfa_reset_processing() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    // Reset all schedules
    $scheduler = PostScheduler::getInstance();
    $scheduler->clearAllSchedules();
    $scheduler->initializeSchedules();
    
    wp_send_json_success(['message' => 'Processing status reset and schedules reinitialized.']);
}
add_action('wp_ajax_pfa_reset_processing', 'pfa_reset_processing');

// AJAX handler to save plugin settings
function ajax_save_workflow_settings() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $settings = [
        'addrevenue_api_key', 'ai_api_key', 'channel_id', 'ai_model',
        'max_posts_per_day', 'check_interval', 'dripfeed_interval', 'min_discount'
    ];

    foreach ($settings as $setting) {
        if (isset($_POST[$setting])) {
            update_option($setting, sanitize_text_field($_POST[$setting]));
        }
    }

    wp_send_json_success(['message' => 'Settings saved successfully']);
}
add_action('wp_ajax_save_workflow_settings', 'ajax_save_workflow_settings');

add_action('wp_ajax_pfa_check_dripfeed_status', 'pfa_check_dripfeed_status');
add_action('wp_ajax_nopriv_pfa_check_dripfeed_status', 'pfa_check_dripfeed_status');

function pfa_check_dripfeed_status() {
    $queueManager = QueueManager::getInstance();
    $status = $queueManager->getStatus();
    
    wp_send_json_success($status);
}
add_action('wp_ajax_pfa_check_dripfeed_status', 'pfa_check_dripfeed_status');

// The rest of your existing handlers remain the same...

// Initialize QueueManager (if still needed - consider if this can be removed)
function initialize_queue_manager() {
    if (!wp_doing_ajax()) {
        QueueManager::getInstance();
    }
}
add_action('init', 'initialize_queue_manager', 5);

// AJAX handler to manually create a post
function ajax_create_manual_post() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');
    $postCreator = PostCreator::getInstance();

    $post_data = [
        'title' => sanitize_text_field($_POST['post_title']),
        'trackingLink' => esc_url_raw($_POST['product_url']),
        'price' => floatval($_POST['price']),
        'sale_price' => floatval($_POST['sale_price']),
        'image_link' => esc_url_raw($_POST['featured_image']),
        'google_product_category' => sanitize_text_field($_POST['category']),
    ];
    $advertiser_data = ['displayName' => sanitize_text_field($_POST['brand'])];

    $result = $postCreator->createManualProductPost($post_data['title'], $post_data['image_link'], $post_data['trackingLink'], $post_data['price'], $post_data['sale_price'], $advertiser_data['displayName'], $post_data['google_product_category']);
    
    if ($result['status'] === 'success') {
        wp_send_json_success(['post_id' => $result['post_id'], 'message' => 'Post created successfully']);
    } else {
        wp_send_json_error(['message' => $result['message'] ?? 'Error creating post']);
    }
}
add_action('wp_ajax_create_manual_post', 'ajax_create_manual_post');

add_action('wp_ajax_pfa_check_discount_results', 'ajax_check_discount_results');

function ajax_check_discount_results() {
    try {
        check_ajax_referer('pfa_ajax_nonce', 'nonce');
        
        $min_discount = isset($_POST['min_discount']) ? (int) sanitize_text_field($_POST['min_discount']) : 0;
        error_log('Minimum discount set to: ' . $min_discount);
        update_option('min_discount', $min_discount);

        $postCreator = PostCreator::getInstance();
        $products = ApiFetcher::fetchProducts();
        
        if (empty($products)) {
            wp_send_json_error(['message' => 'No products available']);
            return;
        }

        // First filter for in-stock products
        $in_stock_products = array_filter($products, function($product) {
            return isset($product['availability']) && $product['availability'] === 'in_stock';
        });

        // Then filter for discount
        $discounted_products = array_filter($in_stock_products, function($product) use ($min_discount, $postCreator) {
            $discount = $postCreator->calculateDiscount($product['price'], $product['sale_price']);
            error_log("Product {$product['id']}: Price {$product['price']}, Sale {$product['sale_price']}, Discount {$discount}%");
            return $discount >= $min_discount;
        });

        $actually_eligible = array_filter($discounted_products, function($product) use ($postCreator) {
            $product_identifier = md5($product['id'] . '|' . ($product['gtin'] ?? '') . '|' . ($product['mpn'] ?? ''));
            $exists = in_array($product_identifier, get_option('pfa_product_identifiers', []));
            $inDb = $postCreator->checkIfAlreadyInDb($product['trackingLink']);
            error_log("Product {$product['id']}: Exists in identifiers? " . ($exists ? 'Yes' : 'No') . ", In DB? " . ($inDb ? 'Yes' : 'No'));
            return !$exists && !$inDb;
        });

        $results = [
            'total_hits' => count($actually_eligible),
            'total_products' => count($products),
            'in_stock_count' => count($in_stock_products),
            'min_discount' => $min_discount,
            'last_check_time' => current_time('mysql'),
            'next_scheduled_check' => wp_next_scheduled('pfa_daily_check') 
                ? wp_date('Y-m-d H:i:s T', wp_next_scheduled('pfa_daily_check')) 
                : 'Not scheduled'
        ];

        update_option('pfa_last_total_products', $results['total_products']);
        update_option('pfa_last_eligible_products', $results['total_hits']);
        // update_option('pfa_last_discount_percentage', $results['min_discount']);
        update_option('min_discount', $results['min_discount']);
        update_option('pfa_last_check_time', $results['last_check_time']);

        error_log('Discount check results: ' . print_r($results, true));
        delete_transient('pfa_queue_status_cache');
        ApiFetcher::clearCache();
        wp_send_json_success($results);

    } catch (Exception $e) {
        error_log('Error in discount check: ' . $e->getMessage());
        wp_send_json_error([
            'message' => 'Server error occurred: ' . $e->getMessage()
        ]);
    }
}

function get_queue_status_data() {
    $queueManager = QueueManager::getInstance();
    $status = $queueManager->getStatus(true);
    
    // Get the API check times and data directly from the same options used in generateStatus
    $next_api_check = get_option('pfa_next_api_check', 'Not scheduled');
    $last_check_time = get_option('pfa_last_api_check_time', 'Not Set');
    $check_interval = get_option('check_interval', 'daily');
    
    $queue_status_data = [
        'automation_enabled' => get_option('pfa_automation_enabled', 'yes'),
        'current_time' => current_time('mysql'),
        'schedules' => [
            'daily_check' => wp_next_scheduled('pfa_daily_check'),
            'dripfeed_check' => wp_next_scheduled('pfa_dripfeed_publisher'),
            'dripfeed_interval' => get_option('dripfeed_interval', 60),
        ],
        'queue_status' => [
            'scheduled_posts' => $status['scheduled_posts'] ?? 0,
            'posts_today' => $status['posts_today'] ?? 0,
            'max_posts' => get_option('max_posts_per_day', 10)
        ],
        'api_check' => [
            'next_check' => $next_api_check,
            'last_check_time' => $last_check_time,
            'check_interval' => $check_interval,
            'total_products' => get_option('pfa_last_total_products', 0),
            'eligible_products' => get_option('pfa_last_eligible_products', 0),
            'min_discount' => get_option('min_discount', 0)  // Changed from min_discount to pfa_last_discount_percentage
        ],
        'archived_stats' => $status['archived_stats'] ?? [
            'total' => 0,
            'recent' => 0,
            'last_24h' => 'No posts archived recently'
        ]
    ];

    // Ensure API check timing is correct based on interval
    if ($next_api_check) {
        $last_check_time = strtotime(get_option('pfa_last_api_check_time'));
        $expected_next_check = null;
        
        switch($check_interval) {
            case 'hourly':
                $expected_next_check = $last_check_time + HOUR_IN_SECONDS;
                break;
            case 'twicedaily':
                $expected_next_check = $last_check_time + 12 * HOUR_IN_SECONDS;
                break;
            case 'daily':
                $expected_next_check = strtotime('tomorrow 06:00:00', $last_check_time);
                break;
        }
        
        if ($expected_next_check && $next_api_check !== $expected_next_check) {
            wp_clear_scheduled_hook('pfa_api_check');
            wp_schedule_single_event($expected_next_check, 'pfa_api_check');
            $queue_status_data['api_check']['next_check'] = wp_date('Y-m-d H:i:s T', $expected_next_check);
        }
    }

  

    error_log('Queue status data: ' . print_r($queue_status_data, true));
    
    return $queue_status_data;
}

function refresh_queue_status() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');
    
    $queueManager = QueueManager::getInstance();
    $status = $queueManager->getStatus(false); 
    
    if (isset($_POST['refresh_type']) && $_POST['refresh_type'] === 'api_check') {
        $queueManager->clearStatusCache();
        $status = $queueManager->getStatus(true);
    }
    
    wp_send_json_success($status);
}
add_action('wp_ajax_refresh_queue_status', 'refresh_queue_status');
add_action('wp_ajax_nopriv_refresh_queue_status', 'refresh_queue_status');


function pfa_reset_schedules() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }
    
    // Clear schedules
    $scheduler = PostScheduler::getInstance();
    $scheduler->clearAllSchedules();
    
    // Initialize new schedules
    $scheduler->initializeSchedules();
    
    // Get fresh status
    $queueManager = QueueManager::getInstance();
    $status = $queueManager->getStatus(true);
    
    // Clear any cached status
    $queueManager->clearStatusCache();
    
    wp_send_json_success($status);
}
add_action('wp_ajax_setup_schedules', 'pfa_reset_schedules');

// add_action('wp_ajax_pfa_force_api_check', function() {
//     check_ajax_referer('pfa_ajax_nonce', 'nonce');
    
//     // Clear any existing caches
//     ApiFetcher::clearCache();
    
//     // Trigger the allowed action instead of calling handleApiCheck directly
//     do_action('pfa_api_check');
    
//     wp_send_json_success(['message' => 'API check completed']);
// });