<?php

// Ensure direct access is restricted
if (!defined('ABSPATH')) exit;

// Include class files
require_once plugin_dir_path(__FILE__) . 'classes/class-post-scheduler.php';
require_once plugin_dir_path(__FILE__) . 'classes/class-post-creator.php';
require_once plugin_dir_path(__FILE__) . 'classes/class-api-fetcher.php';

// Include functions files
require_once plugin_dir_path(__FILE__) . 'functions/ajax-functions.php';

function initialize_plugin() {
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;
    
    $dripfeed_interval = get_option('dripfeed_interval', 30);
    
    if (!wp_next_scheduled('pfa_daily_check') && 
        !wp_next_scheduled('pfa_dripfeed_publisher') && 
        !wp_next_scheduled('pfa_api_check')) {
        
        wp_clear_scheduled_hook('pfa_daily_check');
        wp_clear_scheduled_hook('pfa_dripfeed_publisher');
        wp_clear_scheduled_hook('pfa_api_check');
        
        wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'pfa_daily_check');
        
        $check_interval = get_option('check_interval', 'daily');
        wp_schedule_event(time(), $check_interval, 'pfa_api_check');
        wp_schedule_event(time(), "minutes_{$dripfeed_interval}", 'pfa_dripfeed_publisher');
    }
    
    add_filter('cron_schedules', function($schedules) use ($dripfeed_interval) {
        $schedules["minutes_{$dripfeed_interval}"] = array(
            'interval' => $dripfeed_interval * 60,
            'display' => sprintf(__('Every %d minutes'), $dripfeed_interval)
        );
        return $schedules;
    });

    
}
add_action('init', 'initialize_plugin');



// Initialize scheduling via wp-cron
function schedule_cron_events() {
    // Schedule the daily API check if not already scheduled
    if (!wp_next_scheduled('pfa_daily_check')) {
        wp_schedule_event(time(), 'daily', 'pfa_daily_check');
    }

    // Schedule the drip-feed check with the custom interval
    $dripfeed_interval = get_option('dripfeed_interval', 30); // Default interval of 30 minutes
    if (!wp_next_scheduled('pfa_dripfeed_check')) {
        wp_schedule_event(time(), "minutes_$dripfeed_interval", 'pfa_dripfeed_check');
    }
}

// Register custom cron intervals for dripfeed timing
function pfa_custom_cron_intervals($schedules) {
    $dripfeed_interval = max(1, (int) get_option('dripfeed_interval', 30)); // Minimum 1 minute, default to 30
    
    $schedules["minutes_$dripfeed_interval"] = array(
        'interval' => $dripfeed_interval * 60, // convert to seconds
        'display' => __("Every $dripfeed_interval minutes")
    );
    return $schedules;
}
add_filter('cron_schedules', 'pfa_custom_cron_intervals');

// Add cron events only on init to avoid redundancy
add_action('init', 'schedule_cron_events');

// Schedule handlers (callbacks for the cron events)
add_action('pfa_daily_check', function() {
    $scheduler = PostScheduler::getInstance();
    $scheduler->checkAndQueueProducts();
});

add_action('pfa_dripfeed_check', function() {
    $scheduler = PostScheduler::getInstance();
    $scheduler->handleDripfeedPublish();
});


if (!has_action('pfa_api_check')) {
    add_action('pfa_api_check', function() {
        error_log('Scheduled API check starting');
        
        ApiFetcher::clearCache();
        
        $products = ApiFetcher::fetchProducts(1, true);
        
        if ($products) {

            PostScheduler::getInstance()->handleApiCheck();
        }
    });
}

add_action('pre_get_posts', function($query) {
    if (!is_admin()) {
        $query->set('post_status', 'publish'); 
    }
});


function clean_stale_identifiers() {
    global $wpdb;

    $existing_identifiers = get_option('pfa_product_identifiers', []);
    if (empty($existing_identifiers)) {
        error_log('No identifiers to clean.');
        return;
    }

    error_log('Starting identifier cleanup. Current count: ' . count($existing_identifiers));

    $cleaned_identifiers = [];

    foreach ($existing_identifiers as $identifier) {
        // Check for posts in both publish and future status
        $query = $wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id
            JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id
            JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id
            WHERE p.post_type = 'post'
            AND p.post_status IN ('publish', 'future')
            AND pm1.meta_key = '_Amazone_produt_baseName'
            AND pm2.meta_key = '_product_url'
            AND pm3.meta_key = '_discount_price'
            AND MD5(CONCAT(
                pm1.meta_value, '|',
                COALESCE((
                    SELECT meta_value 
                    FROM {$wpdb->postmeta} 
                    WHERE post_id = p.ID 
                    AND meta_key = '_product_gtin'
                ), ''), '|',
                COALESCE((
                    SELECT meta_value 
                    FROM {$wpdb->postmeta} 
                    WHERE post_id = p.ID 
                    AND meta_key = '_product_mpn'
                ), '')
            )) = %s
        ", $identifier);

        $post_id = $wpdb->get_var($query);

        if ($post_id) {
            $cleaned_identifiers[] = $identifier;
        } else {
            error_log("Removing stale identifier: $identifier");
        }
    }

    update_option('pfa_product_identifiers', $cleaned_identifiers);
    error_log(sprintf(
        'Identifier cleanup complete. Before: %d, After: %d',
        count($existing_identifiers),
        count($cleaned_identifiers)
    ));
}

// Add cleanup on a daily schedule
if (!wp_next_scheduled('pfa_clean_identifiers')) {
    wp_schedule_event(strtotime('tomorrow 05:00:00'), 'daily', 'pfa_clean_identifiers');
}
add_action('pfa_clean_identifiers', 'clean_stale_identifiers');

// Also clean on post deletion
add_action('before_delete_post', function ($post_id) {
    if (get_post_type($post_id) !== 'post') {
        return;
    }

    $basename = get_post_meta($post_id, '_Amazone_produt_baseName', true);
    $gtin = get_post_meta($post_id, '_product_gtin', true);
    $mpn = get_post_meta($post_id, '_product_mpn', true);

    if ($basename) {
        $product_identifier = md5($basename . '|' . ($gtin ?? '') . '|' . ($mpn ?? ''));

        $existing_identifiers = get_option('pfa_product_identifiers', []);
        if (($key = array_search($product_identifier, $existing_identifiers)) !== false) {
            unset($existing_identifiers[$key]);
            update_option('pfa_product_identifiers', $existing_identifiers);
            error_log("Identifier removed for deleted post ID: $post_id, Identifier: $product_identifier");
        }
    }
});

// Add cleanup when post is archived
add_action('wp_trash_post', function($post_id) {
    clean_stale_identifiers();
});

// Add cleanup before daily queue check
add_action('pfa_daily_check', function() {
    clean_stale_identifiers();
}, 5); // Priority 5 means it runs before the normal daily check
