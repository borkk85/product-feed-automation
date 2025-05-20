<?php

/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    Product_Feed_Automation
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

// Get saved options
$addrevenue_api_key = get_option('addrevenue_api_key');
$ai_api_key = get_option('ai_api_key');
$channel_id = get_option('channel_id');
$ai_model = get_option('ai_model', 'gpt-3.5-turbo');
$min_discount = get_option('min_discount', 0);
$max_posts_per_day = get_option('max_posts_per_day', 10);
$max_tokens = get_option('max_tokens', 1000);
$temperature = get_option('temperature', 0.7);
$prompt_for_ai = get_option('prompt_for_ai', 'Write a product description for');
$check_interval = get_option('check_interval', 'daily');
$dripfeed_interval = get_option('dripfeed_interval', 60);
$automation_enabled = get_option('pfa_automation_enabled', 'yes');

// Get queue status data
$queue_manager = PFA_Queue_Manager::get_instance();
$status_data = $queue_manager->get_status(true);
?>

<div class="wrap pfa-admin-wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="pfa-admin-container">
        <div class="pfa-columns">
            <!-- Left Column: Automation Controls -->
            <div class="pfa-column pfa-automation-panel">
                <div class="pfa-automation-status pfa-panel">
                    <h2><?php _e('Automation Status', 'product-feed-automation'); ?></h2>

                    <!-- Automation Toggle -->
                    <div class="pfa-automation-controls">
                        <p><strong><?php _e('Automation:', 'product-feed-automation'); ?></strong></p>
                        <select id="automation_status" name="automation_status">
                            <option value="yes" <?php selected($automation_enabled, 'yes'); ?>><?php _e('Enabled', 'product-feed-automation'); ?></option>
                            <option value="no" <?php selected($automation_enabled, 'no'); ?>><?php _e('Disabled', 'product-feed-automation'); ?></option>
                        </select>
                        <div id="automation_status_indicator" class="<?php echo $status_data['automation_enabled'] ? 'active' : 'inactive'; ?>">
                            <?php _e('Current Status:', 'product-feed-automation'); ?>
                            <span><?php echo $status_data['automation_enabled'] ? _e('Active', 'product-feed-automation') : _e('Paused', 'product-feed-automation'); ?></span>
                        </div>
                    </div>

                    <!-- Queue Status -->
                    <div class="pfa-queue-status">
                        <h3><?php _e('Queue Status', 'product-feed-automation'); ?></h3>
                        <div class="pfa-status-details">
                            <div class="pfa-main-status">
                                <p>
                                    <strong><?php _e('Scheduled Posts:', 'product-feed-automation'); ?></strong>
                                    <span class="pfa-scheduled-posts-count">
                                        <?php echo isset($status_data['scheduled_posts']) ? esc_html($status_data['scheduled_posts']) : '0'; ?>
                                    </span>
                                </p>

                                <p>
                                    <strong><?php _e('Posts Today:', 'product-feed-automation'); ?></strong>
                                    <span class="pfa-posts-today">
                                        <?php
                                        if (isset($status_data['posts_today']) && isset($status_data['max_posts'])) {
                                            echo esc_html($status_data['posts_today']) . ' / ' . esc_html($status_data['max_posts']);
                                        } else {
                                            echo '0 / ' . esc_html($max_posts_per_day);
                                        }
                                        ?>
                                    </span>
                                </p>

                                <p>
                                    <strong><?php _e('Queue Size:', 'product-feed-automation'); ?></strong>
                                    <span class="pfa-queue-size">
                                        <?php echo isset($status_data['queue_size']) ? esc_html($status_data['queue_size']) : '0'; ?>
                                    </span>
                                </p>
                            </div>

                            <div class="pfa-api-status">
                                <p>
                                    <strong><?php _e('Next API Check:', 'product-feed-automation'); ?></strong>
                                    <span class="pfa-next-api-check">
                                        <?php
                                        if (isset($status_data['api_check']['next_check'])) {
                                            echo esc_html($status_data['api_check']['next_check']);
                                        } else {
                                            _e('Not scheduled', 'product-feed-automation');
                                        }
                                        ?>
                                    </span>
                                    <span class="pfa-check-interval-text">(<?php echo ucfirst(esc_html($check_interval)); ?> <?php _e('checks', 'product-feed-automation'); ?>)</span>
                                </p>

                                <p>
                                    <strong><?php _e('Last Check Results:', 'product-feed-automation'); ?></strong>
                                    <span class="pfa-last-check-results">
                                        <?php if (isset($status_data['api_check']['last_check_time']) && $status_data['api_check']['last_check_time'] !== 'Not Set'): ?>
                                            <?php echo sprintf(
                                                __('Found %1$s products with %2$s%% discount (of %3$s total)', 'product-feed-automation'),
                                                esc_html($status_data['api_check']['eligible_products']),
                                                esc_html($status_data['api_check']['min_discount']),
                                                esc_html($status_data['api_check']['total_products'])
                                            ); ?>
                                            <br>
                                            <small><?php _e('Checked at:', 'product-feed-automation'); ?> <?php echo esc_html($status_data['api_check']['last_check_time']); ?></small>
                                        <?php else: ?>
                                            <?php _e('No check performed yet', 'product-feed-automation'); ?>
                                        <?php endif; ?>
                                    </span>
                                </p>

                                <p>
                                    <strong><?php _e('Archive Status:', 'product-feed-automation'); ?></strong>
                                    <span class="pfa-archive-stats">
                                        <?php
                                        if (isset($status_data['archived_stats'])) {
                                            echo esc_html($status_data['archived_stats']['total']) . ' ' . __('total archived', 'product-feed-automation');
                                            if (!empty($status_data['archived_stats']['recent'])) {
                                                echo "<br><small>" . esc_html($status_data['archived_stats']['last_24h']) . "</small>";
                                            }
                                        } else {
                                            echo '0 ' . __('total archived', 'product-feed-automation');
                                        }
                                        ?>
                                    </span>
                                </p>
                            </div>

                            <div class="pfa-system-info">
                                <p><strong><?php _e('Current Server Time:', 'product-feed-automation'); ?></strong>
                                    <?php echo isset($status_data['current_time']) ? esc_html($status_data['current_time']) : current_time('Y-m-d H:i:s T'); ?></p>
                            </div>
                        </div>

                        <div class="pfa-status-actions">
                            <button type="button" id="refresh-queue-status" class="button">
                                <?php _e('Refresh Status', 'product-feed-automation'); ?>
                            </button>
                            <?php if (!wp_next_scheduled('pfa_api_check') || !wp_next_scheduled('pfa_dripfeed_publisher')) : ?>
                                <button type="button" id="setup-schedules" class="button">
                                    <?php _e('Setup Schedules', 'product-feed-automation'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Manual Post Creation Panel -->
                <div class="pfa-manual-post-panel pfa-panel">
                    <h2><?php _e('Manual Post Creation', 'product-feed-automation'); ?></h2>

                    <div id="pfa-manual-post-message" class="notice" style="display: none;"></div>

                   <form method="post" id="pfa-manual-post-form" action="">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="post_title"><?php _e('Post Title', 'product-feed-automation'); ?></label></th>
                                <td><input type="text" id="post_title" name="post_title" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="featured_image"><?php _e('Featured Image URL', 'product-feed-automation'); ?></label></th>
                                <td><input type="url" id="featured_image" name="featured_image" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="product_url"><?php _e('Product URL', 'product-feed-automation'); ?></label></th>
                                <td><input type="url" id="product_url" name="product_url" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="price"><?php _e('Original Price', 'product-feed-automation'); ?></label></th>
                                <td><input type="number" id="price" name="price" step="0.01" min="0" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="sale_price"><?php _e('Sale Price', 'product-feed-automation'); ?></label></th>
                                <td><input type="number" id="sale_price" name="sale_price" step="0.01" min="0" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="brand"><?php _e('Brand', 'product-feed-automation'); ?></label></th>
                                <td><input type="text" id="brand" name="brand" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="brand_image"><?php _e('Brand Logo URL', 'product-feed-automation'); ?></label></th>
                                <td><input type="url" id="brand_image" name="brand_image" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="product_category"><?php _e('Check if Category Exists:', 'product-feed-automation'); ?></label></th>
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
                                        'show_option_none' => __('Select Category', 'product-feed-automation'),
                                    ));
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="category"><?php _e('Category', 'product-feed-automation'); ?></label></th>
                                <td><input type="text" id="category" name="category" class="regular-text" placeholder="<?php _e('e.g., Electronics > Computers > Laptops', 'product-feed-automation'); ?>"></td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" name="submit_manual_post" class="button-primary">
                                <?php _e('Create Post', 'product-feed-automation'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>

            <!-- Right Column: Settings -->
            <div class="pfa-column pfa-settings-panel">
                <div class="pfa-settings pfa-panel">
                    <h2><?php _e('Plugin Settings', 'product-feed-automation'); ?></h2>

                    <div id="pfa-settings-message" class="notice" style="display: none;"></div>

                    <form method="post" id="pfa-settings-form" action="">
                        <h3><?php _e('API Credentials', 'product-feed-automation'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="addrevenue_api_key"><?php _e('Addrevenue API Key', 'product-feed-automation'); ?></label></th>
                                <td><input type="password" id="addrevenue_api_key" name="addrevenue_api_key" value="<?php echo esc_attr($addrevenue_api_key); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="channel_id"><?php _e('Addrevenue Channel ID', 'product-feed-automation'); ?></label></th>
                                <td><input type="password" id="channel_id" name="channel_id" value="<?php echo esc_attr($channel_id); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ai_api_key"><?php _e('AI API Key', 'product-feed-automation'); ?></label></th>
                                <td><input type="password" id="ai_api_key" name="ai_api_key" value="<?php echo esc_attr($ai_api_key); ?>" class="regular-text"></td>
                            </tr>
                        </table>

                        <h3><?php _e('Content Generation Settings', 'product-feed-automation'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="ai_model"><?php _e('AI Model', 'product-feed-automation'); ?></label></th>
                                <td>
                                    <select id="ai_model" name="ai_model">
                                    <option value="gpt-3.5-turbo" <?php selected($ai_model, 'gpt-3.5-turbo'); ?>><?php _e('GPT-3.5 Turbo', 'product-feed-automation'); ?></option>
                                    <option value="gpt-4" <?php selected($ai_model, 'gpt-4'); ?>><?php _e('GPT-4', 'product-feed-automation'); ?></option>
                                    <option value="gpt-4o-mini" <?php selected($ai_model, 'gpt-4o-mini'); ?>><?php _e('GPT-4o Mini', 'product-feed-automation'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="max_tokens"><?php _e('Max Tokens', 'product-feed-automation'); ?></label></th>
                                <td><input type="number" id="max_tokens" name="max_tokens" value="<?php echo esc_attr($max_tokens); ?>" class="small-text" min="1"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="temperature"><?php _e('Temperature', 'product-feed-automation'); ?></label></th>
                                <td><input type="number" id="temperature" name="temperature" value="<?php echo esc_attr($temperature); ?>" class="small-text" min="0" max="1" step="0.1"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="prompt_for_ai"><?php _e('AI Prompt Template', 'product-feed-automation'); ?></label></th>
                                <td><textarea id="prompt_for_ai" name="prompt_for_ai" rows="5" cols="50"><?php echo esc_textarea($prompt_for_ai); ?></textarea></td>
                            </tr>
                        </table>

                        <h3><?php _e('Automation Settings', 'product-feed-automation'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="min_discount"><?php _e('Minimum Discount (%)', 'product-feed-automation'); ?></label></th>
                                <td>
                                    <input type="number" id="min_discount" name="min_discount" value="<?php echo esc_attr($min_discount); ?>" class="small-text" min="0" max="100">
                                    <button type="button" id="check_discount_results" class="button"><?php _e('Check Results', 'product-feed-automation'); ?></button>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="max_posts_per_day"><?php _e('Max Posts Per Day', 'product-feed-automation'); ?></label></th>
                                <td><input type="number" id="max_posts_per_day" name="max_posts_per_day" value="<?php echo esc_attr($max_posts_per_day); ?>" class="small-text" min="1"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="check_interval"><?php _e('API Check Interval', 'product-feed-automation'); ?></label></th>
                                <td>
                                    <select id="check_interval" name="check_interval">
                                        <option value="hourly" <?php selected($check_interval, 'hourly'); ?>><?php _e('Hourly', 'product-feed-automation'); ?></option>
                                        <option value="twicedaily" <?php selected($check_interval, 'twicedaily'); ?>><?php _e('Twice Daily', 'product-feed-automation'); ?></option>
                                        <option value="daily" <?php selected($check_interval, 'daily'); ?>><?php _e('Daily', 'product-feed-automation'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dripfeed_interval"><?php _e('Dripfeed Interval (minutes)', 'product-feed-automation'); ?></label></th>
                                <td><input type="number" id="dripfeed_interval" name="dripfeed_interval" value="<?php echo esc_attr($dripfeed_interval); ?>" class="small-text" min="1"></td>
                            </tr>
                        </table>

                        <p class="submit">
                            <input type="submit" name="submit_settings" id="submit_settings" class="button-primary" value="<?php _e('Save Settings', 'product-feed-automation'); ?>">
                        </p>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>