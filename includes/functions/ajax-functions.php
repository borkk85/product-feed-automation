<?php
/**
 * AJAX functions for Product Feed Automation.
 *
 * @since      1.0.0
 * @package    Product_Feed_Automation
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Toggle automation status.
 *
 * @since    1.0.0
 */
function pfa_toggle_automation() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    $status = sanitize_text_field($_POST['status']);
    update_option('pfa_automation_enabled', $status);

    wp_send_json_success(array('message' => 'Automation status updated to ' . $status));
}
add_action('wp_ajax_pfa_toggle_automation', 'pfa_toggle_automation');

/**
 * Save plugin settings.
 *
 * @since    1.0.0
 */
function pfa_save_settings() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    // Get the old discount value for comparison
    $old_min_discount = get_option('min_discount', 0);
    $old_max_posts = get_option('max_posts_per_day', 10);
    $new_min_discount = (int)sanitize_text_field($_POST['min_discount']);
    $new_max_posts = (int)sanitize_text_field($_POST['max_posts_per_day']);
    
    error_log('Save settings - old discount: ' . $old_min_discount . ', new: ' . $new_min_discount);

    
    update_option('addrevenue_api_key', sanitize_text_field($_POST['addrevenue_api_key']));
    update_option('ai_api_key', sanitize_text_field($_POST['ai_api_key']));
    update_option('channel_id', sanitize_text_field($_POST['channel_id']));
    
    
    update_option('ai_model', sanitize_text_field($_POST['ai_model']));
    update_option('max_tokens', sanitize_text_field($_POST['max_tokens']));
    update_option('temperature', sanitize_text_field($_POST['temperature']));
    update_option('prompt_for_ai', sanitize_textarea_field($_POST['prompt_for_ai']));
    
    
    update_option('min_discount', $new_min_discount);
    update_option('max_posts_per_day', $new_max_posts);
    update_option('check_interval', sanitize_text_field($_POST['check_interval']));
    update_option('dripfeed_interval', sanitize_text_field($_POST['dripfeed_interval']));

    
    if ($old_min_discount != $new_min_discount) {
        error_log('Discount value changed from ' . $old_min_discount . ' to ' . $new_min_discount . '. Updating last check display.');
        
        
        $api_fetcher = PFA_API_Fetcher::get_instance();
        // $products = $api_fetcher->fetch_products(true);
        $products = $api_fetcher->fetch_products(true, $new_min_discount);
        
        if ($products) {
            // Apply new discount filter
            $post_creator = PFA_Post_Creator::get_instance();
            
            // Get all in-stock products
            $in_stock_products = array_filter($products, function($product) {
                return isset($product['availability']) && $product['availability'] === 'in_stock';
            });
            
            // Filter by discount
            $eligible_products = array_filter($in_stock_products, function($product) use ($new_min_discount, $post_creator) {
                $discount = $post_creator->calculate_discount($product['price'], $product['sale_price']);
                return $discount >= $new_min_discount;
            });
            
            // Update options that drive the "Last Check Results" display
            update_option('pfa_last_eligible_products', count($eligible_products));
            update_option('pfa_last_total_products', count($products));
            update_option('pfa_last_api_check_time', current_time('mysql'));
            
            error_log(sprintf(
                'Updated last check data - Eligible: %d, Total: %d, Discount: %d%%',
                count($eligible_products),
                count($products),
                $new_min_discount
            ));
        }
    }

    // Clear all existing schedules
    wp_clear_scheduled_hook('pfa_api_check');
    wp_clear_scheduled_hook('pfa_daily_check');
    wp_clear_scheduled_hook('pfa_dripfeed_publisher');

    // Initialize new schedules
    $scheduler = PFA_Post_Scheduler::get_instance();
    $scheduler->refresh_settings();
    $scheduler->initialize_schedules();

    // Force Queue Manager to refresh its status
    $queue_manager = PFA_Queue_Manager::get_instance();
    $queue_manager->clear_status_cache();  
    
    // Trigger queue check if settings have changed in a way that affects eligibility
    if ($old_min_discount != $new_min_discount || $old_max_posts != $new_max_posts) {
        error_log('Key settings changed - triggering immediate queue check');
        $scheduler->check_and_queue_products();
    }
    
    // Get fresh status that will include new interval settings
    $status = $queue_manager->get_status(true);

    wp_send_json_success(array(
        'message' => 'Settings saved successfully.',
        'status' => $status,
        'check_interval' => get_option('check_interval', 'daily'),
    ));
}
add_action('wp_ajax_save_ai_workflow_settings', 'pfa_save_settings');

/**
 * Create a manual post.
 *
 * @since    1.0.0
 */
function pfa_create_manual_post() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    $title = sanitize_text_field($_POST['post_title']);
    $featured_image = esc_url_raw($_POST['featured_image']);
    $product_url = esc_url_raw($_POST['product_url']);
    $price = floatval($_POST['price']);
    $sale_price = floatval($_POST['sale_price']);
    $brand = sanitize_text_field($_POST['brand']);
    $brand_image = esc_url_raw($_POST['brand_image']);
    $category = sanitize_text_field($_POST['category']);
    $category_id = isset($_POST['product_category']) ? intval($_POST['product_category']) : null;

    $post_creator = PFA_Post_Creator::get_instance();
    $result = $post_creator->create_manual_product_post(
        $title, 
        $featured_image, 
        $product_url, 
        $price, 
        $sale_price, 
        $brand, 
        $category, 
        $brand_image, 
        $category_id
    );

    if (isset($result['status']) && $result['status'] === 'success') {
        wp_send_json_success(array('post_id' => $result['post_id']));
    } else {
        wp_send_json_error(array('message' => isset($result['message']) ? $result['message'] : 'Unknown error'));
    }
}
add_action('wp_ajax_pfa_create_manual_post', 'pfa_create_manual_post');

/**
 * Check dripfeed status.
 *
 * @since    1.0.0
 */
function pfa_check_dripfeed_status() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }
    
    $queue_manager = PFA_Queue_Manager::get_instance();
    $status = $queue_manager->get_status();
    
    wp_send_json_success($status);
}
add_action('wp_ajax_pfa_check_dripfeed_status', 'pfa_check_dripfeed_status');

/**
 * Reset processing schedules.
 *
 * @since    1.0.0
 */
function pfa_reset_schedules() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }
    
    // Clear schedules
    $scheduler = PFA_Post_Scheduler::get_instance();
    $scheduler->clear_all_schedules();
    
    // Initialize new schedules
    $scheduler->initialize_schedules();
    
    // Get fresh status
    $queue_manager = PFA_Queue_Manager::get_instance();
    $status = $queue_manager->get_status(true);
    
    // Clear any cached status
    $queue_manager->clear_status_cache();
    
    wp_send_json_success($status);
}
add_action('wp_ajax_setup_schedules', 'pfa_reset_schedules');

/**
 * Check discount results.
 *
 * @since    1.0.0
 */
/**
 * Check discount results without timeouts.
 *
 * @since    1.0.0
 */
// function pfa_check_discount_results() {
//     check_ajax_referer('pfa_ajax_nonce', 'nonce');
    
//     if (!current_user_can('manage_options')) {
//         wp_send_json_error(array('message' => 'Insufficient permissions'));
//         return;
//     }
    
function pfa_check_discount_results($job_id, $min_discount = 0) {
    try {
        // $min_discount = isset($_POST['min_discount']) ? (int) sanitize_text_field($_POST['min_discount']) : 0;
                
        $post_creator = PFA_Post_Creator::get_instance();
        $api_fetcher = PFA_API_Fetcher::get_instance();
        set_transient($job_id, array(
            'status'   => 'processing',
            'progress' => 0,
        ), 5 * MINUTE_IN_SECONDS);

        // Set a time limit for the operation (5 minutes)
        set_time_limit(300);
        
        // $products = $api_fetcher->fetch_products(true);
        $products = $api_fetcher->fetch_products(true, $min_discount);
        
        if (empty($products)) {
            // wp_send_json_error(array('message' => 'No products available'));
            set_transient($job_id, array(
                'status'  => 'error',
                'message' => 'No products available',
            ), 5 * MINUTE_IN_SECONDS);
            return;
        }

        // First filter for in-stock products
        $in_stock_products = array_filter($products, function($product) {
            return isset($product['availability']) && $product['availability'] === 'in_stock';
        });

        // Then filter for discount
        $discounted_products = array_filter($in_stock_products, function($product) use ($min_discount, $post_creator) {
            if (!isset($product['price']) || !isset($product['sale_price'])) {
                return false;
            }
            $discount = $post_creator->calculate_discount($product['price'], $product['sale_price']);
            return $discount >= $min_discount;
        });

        // Process eligibility check with progress tracking to avoid timeouts
        $total_products = count($discounted_products);
        $eligible_count = 0;
        $check_batch_size = 50; // Process in batches of 50 products
        $batch_limit = 10; // Maximum number of batches to process (adjust as needed)
        $processed_count = 0;
        $products_checked = 0;
        
        // Get existing identifiers
        $existing_identifiers = get_option('pfa_product_identifiers', array());
        
        // Sample products to show
        $sample_products = [];
        $sample_count = 0;
        
        // Process in batches to avoid timeouts
        $batches = array_chunk($discounted_products, $check_batch_size);
        
        foreach ($batches as $index => $batch) {
            if ($index >= $batch_limit) {
                // We've processed enough batches - exit the loop to avoid timeout
                break;
            }
            
            foreach ($batch as $product) {
                $products_checked++;
                $product_identifier = md5($product['id'] . '|' . 
                    (isset($product['gtin']) ? $product['gtin'] : '') . '|' . 
                    (isset($product['mpn']) ? $product['mpn'] : ''));
                $exists = in_array($product_identifier, $existing_identifiers);
                
                if (!$exists) {
                    $inDb = $post_creator->check_if_already_in_db($product['trackingLink'], $product);
                    if (!$inDb) {
                        $eligible_count++;
                        
                        // Collect a few sample products to display
                        if ($sample_count < 5) {
                            $discount = $post_creator->calculate_discount($product['price'], $product['sale_price']);
                            $sample_products[] = [
                                'title' => $product['title'],
                                'original_price' => $product['price'],
                                'sale_price' => $product['sale_price'],
                                'discount' => $discount . '%'
                            ];
                            $sample_count++;
                        }
                    }
                }
            }
            
            $processed_count += count($batch);
            
            $progress = ($total_products > 0) ? round(($processed_count / $total_products) * 100) : 100;
            set_transient($job_id, array(
                'status'   => 'processing',
                'progress' => min(99, $progress),
            ), 5 * MINUTE_IN_SECONDS);

            // Reset max execution time for each batch to prevent timeout
            set_time_limit(30);
        }
        
        // Calculate total eligible products
        // If we processed all products, use the actual count
        // Otherwise, use a projection based on the percentage of eligible products so far
        $total_eligible = $eligible_count;
        if ($processed_count < $total_products) {
            // Calculate ratio of eligible products in the processed batch
            $ratio = ($processed_count > 0) ? $eligible_count / $processed_count : 0;
            // Project total eligible products
            $total_eligible = round($ratio * $total_products);
        }

        $results = array(
            'total_hits' => $total_eligible,
            'total_products' => count($products),
            'in_stock_count' => count($in_stock_products),
            'discounted_count' => count($discounted_products),
            'min_discount' => $min_discount,
            'last_check_time' => current_time('mysql'),
            'next_scheduled_check' => wp_next_scheduled('pfa_daily_check') 
                ? wp_date('Y-m-d H:i:s T', wp_next_scheduled('pfa_daily_check')) 
                : 'Not scheduled',
            'processed_percentage' => ($total_products > 0) ? round(($processed_count / $total_products) * 100) : 100,
            'processed_count' => $processed_count,
            'products_checked' => $products_checked,
            'sample_products' => $sample_products
        );
        
        // wp_send_json_success($results);
        set_transient($job_id, array(
            'status'   => 'complete',
            'progress' => 100,
            'result'   => $results,
        ), 5 * MINUTE_IN_SECONDS);

    } catch (Exception $e) {
        // wp_send_json_error(array(
        //     'message' => 'Server error occurred: ' . $e->getMessage()
        // ));
        set_transient($job_id, array(
            'status'  => 'error',
            'message' => $e->getMessage(),
        ), 5 * MINUTE_IN_SECONDS);
    }
}
// add_action('wp_ajax_pfa_check_discount_results', 'pfa_check_discount_results');

/**
 * Start discount check job and return a job ID.
 *
 * @since 1.0.0
 */
function pfa_start_discount_check() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    $min_discount = isset($_POST['min_discount']) ? (int) sanitize_text_field($_POST['min_discount']) : 0;

    $job_id = uniqid('pfa_discount_check_', true);

    set_transient($job_id, array(
        'status'   => 'queued',
        'progress' => 0,
    ), 5 * MINUTE_IN_SECONDS);

    wp_schedule_single_event(time() + 1, 'pfa_run_discount_check', array($job_id, $min_discount));

    wp_send_json_success(array('job_id' => $job_id));
}
add_action('wp_ajax_pfa_start_discount_check', 'pfa_start_discount_check');

/**
 * Get discount check progress.
 *
 * @since 1.0.0
 */
function pfa_get_discount_check_progress() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    $job_id = isset($_GET['job_id']) ? sanitize_text_field($_GET['job_id']) : sanitize_text_field($_POST['job_id']);
    $data   = get_transient($job_id);

    if (false === $data) {
        wp_send_json_error(array('message' => 'Job not found'));
        return;
    }

    wp_send_json_success($data);
}
add_action('wp_ajax_pfa_get_discount_check_progress', 'pfa_get_discount_check_progress');

// Cron hook to run the discount check job.
add_action('pfa_run_discount_check', 'pfa_check_discount_results', 10, 2);


/**
 * Refresh queue status.
 *
 * @since    1.0.0
 */
add_action('wp_ajax_pfa_refresh_status', 'pfa_refresh_status');
function pfa_refresh_status() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }
    
    $queue_manager = PFA_Queue_Manager::get_instance();
    $status = $queue_manager->get_status(true);
    
    wp_send_json_success($status);
}
add_action('wp_ajax_pfa_refresh_status', 'pfa_refresh_status');

/**
 * Run migration to backfill `_product_id` meta for PFA posts.
 *
 * @since 1.0.0
 */
function pfa_migrate_product_ids() {
    check_ajax_referer('pfa_ajax_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    $creator = PFA_Post_Creator::get_instance();
    $creator->migrate_product_id_meta();

    wp_send_json_success(array('message' => 'Migration complete'));
}
add_action('wp_ajax_pfa_migrate_product_ids', 'pfa_migrate_product_ids');
