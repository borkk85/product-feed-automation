<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package    Product_Feed_Automation
 */

// If uninstall is not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clean up all plugin options
$options = array(
    // API settings
    'addrevenue_api_key',
    'ai_api_key',
    'channel_id',
    
    // Automation settings
    'pfa_automation_enabled',
    'min_discount',
    'max_posts_per_day',
    'check_interval',
    'dripfeed_interval',
    
    // AI settings
    'ai_model',
    'max_tokens',
    'temperature',
    'prompt_for_ai',
    
    // Status options
    'pfa_queue_status_cache',
    'pfa_last_api_check_time',
    'pfa_next_api_check',
    'pfa_last_total_products',
    'pfa_last_eligible_products',
    'pfa_product_identifiers',
    'pfa_last_check_stats'
);

// Delete options
foreach ($options as $option) {
    delete_option($option);
}

// Clear scheduled events
wp_clear_scheduled_hook('pfa_daily_check');
wp_clear_scheduled_hook('pfa_dripfeed_publisher');
wp_clear_scheduled_hook('pfa_api_check');
wp_clear_scheduled_hook('pfa_clean_identifiers');

// Delete transients
$transients = array(
    'pfa_queue_status_cache',
    'pfa_dripfeed_lock',
    'pfa_product_queue',
    'pfa_api_products_cache'
);

foreach ($transients as $transient) {
    delete_transient($transient);
}

// Optional: Remove posts created by this plugin
// Uncomment if you want to delete all posts created by this plugin
/*
global $wpdb;

// Get all post IDs with our custom meta
$post_ids = $wpdb->get_col("
    SELECT post_id 
    FROM {$wpdb->postmeta} 
    WHERE meta_key = '_pfa_v2_post' 
    AND meta_value = 'true'
");

// Delete each post and its metadata
foreach ($post_ids as $post_id) {
    wp_delete_post($post_id, true); // true = force delete (bypass trash)
}
*/