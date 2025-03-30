<?php
/*
Plugin Name: Product Feed Automation 
Description: Product Feed Automation Workflow & SEO blog content creation plugin with flexible API integration.
Version: 1.0.0
Author: borkk
License: GPL2
Text Domain: workflows
*/

if (!defined('ABSPATH')) {
    exit;
}


require_once plugin_dir_path(__FILE__) . 'includes/functions/ajax-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/classes/class-api-fetcher.php';
require_once plugin_dir_path(__FILE__) . 'includes/classes/class-post-creator.php';
require_once plugin_dir_path(__FILE__) . 'includes/classes/class-post-scheduler.php';
require_once plugin_dir_path(__FILE__) . 'includes/classes/class-queue-manager.php';

// Include init file to load classes and cron schedules
require_once plugin_dir_path(__FILE__) . 'includes/init.php';

// Set up AJAX calls, admin settings, etc.
require_once plugin_dir_path(__FILE__) . 'includes/functions/ajax-functions.php';

function enqueue_plugin_scripts()
{
    if (is_admin()) {
        wp_enqueue_style('workflow_styles', plugin_dir_url(__FILE__) . 'assets/css/workflow_styles.css', [], time());
        wp_enqueue_script('moment', 'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js', [], null, true);
        wp_enqueue_script('moment-timezone', 'https://cdnjs.cloudflare.com/ajax/libs/moment-timezone/0.5.33/moment-timezone.min.js', ['moment'], null, true);
        wp_enqueue_script('workflow_scripts', plugin_dir_url(__FILE__) . 'assets/js/workflow_scripts.js', ['jquery', 'moment', 'moment-timezone'], time(), true);

        wp_localize_script('workflow_scripts', 'pfaAjax', [
            'nonce' => wp_create_nonce('pfa_ajax_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'wp_timezone' => wp_timezone_string()
        ]);
    }
}
add_action('admin_enqueue_scripts', 'enqueue_plugin_scripts');


add_action('admin_menu', 'add_product_feed_automation_settings_page');

function add_product_feed_automation_settings_page()
{
    add_options_page(
        'Product Feed Automation Integration',
        'Product Feed Automation Integration',
        'manage_options',
        'product_feed_automation_integration',
        'product_feed_automation_integration_page_content'
    );
}

function product_feed_automation_integration_page_content()
{
    if (isset($_POST['submit_ai_workflow_settings'])) {
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

        echo '<div id="message" class="updated fade"><p><strong>Settings saved.</strong></p></div>';
    }

    $addrevenue_api_key = get_option('addrevenue_api_key');
    $ai_api_key = get_option('ai_api_key');
    $channel_id = get_option('channel_id');
    $ai_model = get_option('ai_model');
    $min_discount = get_option('min_discount');
    $max_posts_per_day = get_option('max_posts_per_day', 10);
    $max_tokens = get_option('max_tokens', 1000);
    $temperature = get_option('temperature', 0.7);
    $prompt_for_ai = get_option('prompt_for_ai');
    $check_interval = get_option('check_interval', 'daily');
    $dripfeed_interval = get_option('dripfeed_interval', 60);

    $automation_enabled = get_option('pfa_automation_enabled', 'yes');
    // $current_time = current_time('mysql');
    // $schedules = [
    //     'daily_check' => wp_next_scheduled('pfa_daily_check'),
    //     'dripfeed_check' => wp_next_scheduled('pfa_dripfeed_check')
    // ];

    $status_data = get_queue_status_data();
    // $current_timestamp = time();

?>
    <div class="main-container">
        <div class="auto-wrap">
            <h1>AI Workflow Integration Settings</h1>
            <div class="schedule-info-wrap">
                <h3>Automated Schedule Information</h3>

                <!-- Automation Controls -->
                <div class="automation-controls section-box">
                    <p><strong>Automation Status:</strong></p>
                    <select id="automation_status" name="automation_status">
                    <option value="yes" <?php selected($automation_enabled, 'yes'); ?>>Enabled</option>
                    <option value="no" <?php selected($automation_enabled, 'no'); ?>>Disabled</option>
                    </select>
                    <div id="automation_status_indicator" class="<?php echo $status_data['automation_enabled'] === 'yes' ? 'active' : 'inactive'; ?>">
                        Current Status: <span><?php echo $status_data['automation_enabled'] === 'yes' ? 'Active' : 'Paused'; ?></span>
                    </div>
                </div>

                <!-- Queue Status -->
                <div class="queue-status section-box">
                    <h4>Queue Status</h4>

                    <div class="status-details">
                        <div class="main-status">
                            <p>
                                <strong>Scheduled Posts:</strong>
                                <span class="scheduled-posts-count">
                                    <?php echo $status_data['scheduled_posts']; ?>
                                </span>
                            </p>

                            <p>
                                <strong>Posts Today:</strong>
                                <span class="posts-today">
                                    <?php echo $status_data['posts_today']; ?> /
                                    <?php echo $status_data['max_posts']; ?>
                                </span>
                            </p>
                        </div>

                        <div class="api-status">
                            <p>
                                <strong>Next API Check:</strong>
                                <span class="next-api-check">
                                    <?php echo $status_data['api_check']['next_check']; ?>
                                </span>
                                <span>(<?php echo ucfirst($status_data['api_check']['check_interval']); ?> checks)</span>
                            </p>
                            
                            <p>
                                <strong>Last Check Results:</strong>
                                <span class="last-check-results">
                                    <?php if ($status_data['api_check']['last_check_time']): ?>
                                        Found <?php echo $status_data['api_check']['eligible_products']; ?> products with 
                                        <?php echo $status_data['api_check']['min_discount']; ?>%+ discount
                                        (of <?php echo $status_data['api_check']['total_products']; ?> total)<br>
                                        <small>Checked at: <?php echo $status_data['api_check']['last_check_time']; ?></small>
                                    <?php else: ?>
                                        No check performed yet
                                    <?php endif; ?>
                                </span>
                            </p>
                            
                            <p>
                                <strong>Archive Status:</strong>
                                <span class="archive-stats">
                                    <?php 
                                        echo $status_data['archived_stats']['total'] . ' total archived';
                                        if (!empty($status_data['archived_stats']['recent'])) {
                                            echo "<br><small>{$status_data['archived_stats']['last_24h']}</small>";
                                        }
                                    ?>
                                </span>
                            </p>
                        </div>

                        <div class="system-info">
                            <p><strong>Current Server Time:</strong> <?php echo $status_data['current_time']; ?></p>
                        </div>
                    </div>

                    <div class="status-actions">
                        <button type="button" id="refresh-queue-status" class="button">Refresh Status</button>
                        <?php if (!wp_next_scheduled('pfa_api_check') || !wp_next_scheduled('pfa_dripfeed_publisher')) : ?>
                            <button type="button" id="setup-schedules" class="button">Setup Schedules</button>
                        <?php endif; ?>
                        <!-- <button type="button" id="force-api-check" class="button">Force API check</button> -->

                    </div>
                </div>
            </div>
            <div id="settings-message" class="notice" style="display: none;"></div>
            <form method="post" id="settings-form" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="addrevenue_api_key">Addrevenue API Key</label></th>
                        <td><input type="password" id="addrevenue_api_key" name="addrevenue_api_key" value="<?php echo esc_attr($addrevenue_api_key); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="channel_id">Addrevenue Channel ID</label></th>
                        <td><input type="password" id="channel_id" name="channel_id" value="<?php echo esc_attr($channel_id); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ai_api_key">AI API Key</label></th>
                        <td><input type="password" id="ai_api_key" name="ai_api_key" value="<?php echo esc_attr($ai_api_key); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ai_model">AI Model</label></th>
                        <td><input type="text" id="ai_model" name="ai_model" value="<?php echo esc_attr($ai_model); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="min_discount">Minimum Discount (%)</label></th>
                        <td>
                            <input type="number" id="min_discount" name="min_discount" value="<?php echo esc_attr($min_discount); ?>" class="small-text" min="0" max="100">
                        </td>

                    </tr>
                    <tr>
                        <th scope="row"><label>Check & Set Discount</label></th>
                        <td>
                            <button type="button" id="check_discount_results" class="button">Check Results</button>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Current Settings</th>
                        <td>
                            <p>Current Minimum Discount: <span id="current_discount_setting"><?php echo get_option('min_discount', 0); ?>%</span></p>
                            <p>Next Scheduled Check: <span id="next_check_time"><?php echo wp_next_scheduled('pfa_daily_check') ? date('Y-m-d H:i:s', wp_next_scheduled('pfa_daily_check')) : 'Not scheduled'; ?></span></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_posts_per_day">Max Posts Per Day</label></th>
                        <td><input type="number" id="max_posts_per_day" name="max_posts_per_day" value="<?php echo esc_attr($max_posts_per_day); ?>" class="small-text" min="1"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_tokens">Max Tokens</label></th>
                        <td><input type="number" id="max_tokens" name="max_tokens" value="<?php echo esc_attr($max_tokens); ?>" class="small-text" min="1"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="temperature">Temperature</label></th>
                        <td><input type="number" id="temperature" name="temperature" value="<?php echo esc_attr($temperature); ?>" class="small-text" min="0" max="1" step="0.1"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="prompt_for_ai">AI Prompt Template</label></th>
                        <td><textarea id="prompt_for_ai" name="prompt_for_ai" rows="5" cols="50"><?php echo esc_textarea($prompt_for_ai); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="check_interval">API Check Interval</label></th>
                        <td>
                            <select id="check_interval" name="check_interval">
                                <option value="hourly" <?php selected($check_interval, 'hourly'); ?>>Hourly</option>
                                <option value="twicedaily" <?php selected($check_interval, 'twicedaily'); ?>>Twice Daily</option>
                                <option value="daily" <?php selected($check_interval, 'daily'); ?>>Daily</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dripfeed_interval">Dripfeed Interval (minutes)</label></th>
                        <td><input type="number" id="dripfeed_interval" name="dripfeed_interval" value="<?php echo esc_attr($dripfeed_interval); ?>" class="small-text" min="1"></td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="submit_ai_workflow_settings" class="button-primary" value="Save Changes">
                </p>
            </form>
        </div>

        <div class="manual-wrap">
            <h2>Manual Post Creation</h2>
            <?php ai_workflow_manual_post_creation_form(); ?>
        </div>
    </div>
<?php
}

function ai_workflow_manual_post_creation_form()
{
    if (isset($_POST['submit_manual_post'])) {

        $title = sanitize_text_field($_POST['post_title']);
        $featured_image = esc_url_raw($_POST['featured_image']);
        $product_url = esc_url_raw($_POST['product_url']);
        $price = floatval($_POST['price']);
        $sale_price = floatval($_POST['sale_price']);
        $brand = sanitize_text_field($_POST['brand']);
        $brand_image = esc_url_raw($_POST['brand_image']);
        $category = sanitize_text_field($_POST['category']);
        $category_id = isset($_POST['product_category']) ? intval($_POST['product_category']) : null;

        // Debug logging
        error_log('Form submission data:');
        error_log('Brand Image URL: ' . $brand_image);
        error_log('Category ID: ' . ($category_id ?? 'null'));
        error_log('Category: ' . $category);

        $postCreator = PostCreator::getInstance();

        $result = $postCreator->createManualProductPost(
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

        if ($result['status'] === 'success') {
            echo '<div class="notice notice-success"><p>Post created successfully!</p></div>';
        } elseif ($result['status'] === 'exists') {
            echo '<div class="notice notice-error"><p>This post already exists!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error creating post: ' . esc_html($result['message']) . '</p></div>';
        }
    }
?>
    <div id="manual-post-message" class="notice" style="display: none;"></div>
    <form method="post" id="manual-post-form" action="">
        <table class="form-table">
            <tr>
                <th scope="row"><label for="post_title">Post Title</label></th>
                <td><input type="text" id="post_title" name="post_title" class="regular-text" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="featured_image">Featured Image</label></th>
                <td><input type="url" id="featured_image" name="featured_image" class="regular-text" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="product_url">Product URL</label></th>
                <td><input type="url" id="product_url" name="product_url" class="regular-text" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="price">Original Price</label></th>
                <td><input type="number" id="price" name="price" step="0.01" min="0" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="sale_price">Sale Price</label></th>
                <td><input type="number" id="sale_price" name="sale_price" step="0.01" min="0" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="brand">Brand</label></th>
                <td><input type="text" id="brand" name="brand" class="regular-text" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="brand_image">Brand Logo</label></th>
                <td><input type="url" id="brand_image" name="brand_image" class="regular-text" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="product_category">Check if Category Exists:</label></th>
                <td><?php
                    wp_dropdown_categories(array(
                        'taxonomy'         => 'product_categories',
                        'name'             => 'product_category',
                        'orderby'          => 'name',
                        'order'            => 'ASC',
                        'show_count'       => 0,
                        'hide_empty'       => 0,
                        'child_of'         => 0,
                        'echo'             => 1,
                        'hierarchical'     => 1,
                        'depth'            => 3,
                        'show_option_none' => 'Select Category',
                    ));
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="category">Category</label></th>
                <td><input type="text" id="category" name="category" class="regular-text"></td>
            </tr>
        </table>
        <p class="submit">
            <input type="submit" name="submit_manual_post" class="button-primary" value="Create Post">
        </p>
    </form>
<?php
}
