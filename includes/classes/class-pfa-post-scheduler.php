<?php

/**
 * Manages post scheduling and publishing.
 *
 * @since      1.0.0
 * @package    Product_Feed_Automation
 */

class PFA_Post_Scheduler
{

    /**
     * The single instance of the class.
     *
     * @since    1.0.0
     * @access   protected
     * @var      PFA_Post_Scheduler    $instance    The single instance of the class.
     */
    protected static $instance = null;

    /**
     * Maximum posts to publish per day.
     *
     * @since    1.0.0
     * @access   private
     * @var      int    $max_posts_per_day    Maximum posts per day.
     */
    private $max_posts_per_day;

    /**
     * Dripfeed interval in minutes.
     *
     * @since    1.0.0
     * @access   private
     * @var      int    $dripfeed_interval    Dripfeed interval.
     */
    private $dripfeed_interval;

    /**
     * API check interval.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $check_interval    API check interval.
     */
    private $check_interval;

    /**
     * API fetcher instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      PFA_API_Fetcher    $api_fetcher    API fetcher instance.
     */
    private $api_fetcher;

    /**
     * Post creator instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      PFA_Post_Creator    $post_creator    Post creator instance.
     */
    private $post_creator;

    /**
     * Queue manager instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      PFA_Queue_Manager    $queue_manager    Queue manager instance.
     */
    private $queue_manager;

    /**
     * Initialization count to prevent multiple hook registrations.
     *
     * @since    1.0.0
     * @access   private
     * @var      int    $init_count    Initialization count.
     */
    private static $init_count = 0;

    /**
     * Main PFA_Post_Scheduler Instance.
     *
     * Ensures only one instance of PFA_Post_Scheduler is loaded or can be loaded.
     *
     * @since    1.0.0
     * @return PFA_Post_Scheduler - Main instance.
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * @since    1.0.0
     * @access   protected
     */
    protected function __construct()
    {
        self::$init_count++;

        if (self::$init_count === 1) {
            $this->max_posts_per_day = get_option('max_posts_per_day', 10);
            $this->dripfeed_interval = get_option('dripfeed_interval', 30);
            $this->check_interval = get_option('check_interval', 'daily');
            $this->post_creator = PFA_Post_Creator::get_instance();
            $this->queue_manager = PFA_Queue_Manager::get_instance();
            $this->api_fetcher = PFA_API_Fetcher::get_instance();
            $this->register_hooks();
        }
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
     * Reload cached option values.
     *
     * Ensures that the scheduler immediately uses the latest settings
     * after they are updated.
     *
     * @since    1.0.0
     */
    public function refresh_settings()
    {
        $this->max_posts_per_day = get_option('max_posts_per_day', 10);
        $this->dripfeed_interval = get_option('dripfeed_interval', 30);
        $this->check_interval = get_option('check_interval', 'daily');
    }

    /**
     * Register WordPress hooks.
     *
     * @since    1.0.0
     * @access   private
     */
    private function register_hooks()
    {
        if (!has_action('pfa_dripfeed_publisher', array($this, 'handle_dripfeed_publish'))) {
            add_action('pfa_dripfeed_publisher', array($this, 'handle_dripfeed_publish'));
            add_action('pfa_daily_check', array($this, 'check_and_queue_products'));
            add_action('pfa_api_check', array($this, 'handle_api_check'));
            add_filter('cron_schedules', array($this, 'add_custom_schedules'));
        }
    }

    /**
     * Handle dripfeed publisher cron event.
     *
     * @since    1.0.0
     */
    public function handle_dripfeed_publish()
    {
        $this->log_message('=== Starting Dripfeed Publish ===');

        try {
            if (get_option('pfa_automation_enabled') !== 'yes') {
                $this->log_message('Automation is disabled. Dripfeed publishing skipped.');
                return;
            }

            // Check for restricted hours (00:00-06:00)
            $timezone = wp_timezone();
            $current_time = new DateTime('now', $timezone);
            $hour = (int)$current_time->format('H');

            $this->log_message("Current hour: $hour in timezone " . $timezone->getName());

            if ($hour >= 0 && $hour < 6) {
                $this->log_message("Restricted hours (00:00-06:00). Pausing dripfeed.");
                return;
            }

            // ENHANCED: Always call the batch scheduler to ensure queue population
            $this->log_message("Calling check_and_queue_products to ensure posts are scheduled...");
            $this->check_and_queue_products();

            // ENHANCED: Check if we need immediate action at 6 AM
            if ($hour == 6) {
                $posts_today = $this->get_post_count_today();
                $scheduled_posts = $this->get_scheduled_posts_count();

                $this->log_message("6 AM check - Posts today: {$posts_today}, Scheduled: {$scheduled_posts}");

                if ($posts_today == 0 && $scheduled_posts == 0) {
                    $this->log_message("6 AM with no posts today and none scheduled. Will try to process one post immediately.");

                    // Try to get products and create one post immediately
                    $products = $this->api_fetcher->fetch_products();
                    if ($products && !empty($products)) {
                        $advertisers = $this->api_fetcher->fetch_advertisers();

                        // Filter for eligible products
                        $eligible_products = array_filter($products, function ($product) {
                            return isset($product['availability']) &&
                                $product['availability'] === 'in_stock' &&
                                !$this->post_creator->check_if_already_in_db($product['trackingLink'], $product);
                        });

                        if (!empty($eligible_products)) {
                            $product = reset($eligible_products); // Get first eligible product
                            $advertiser_data = isset($product['advertiserId']) && isset($advertisers[$product['advertiserId']]) ?
                                $advertisers[$product['advertiserId']] : null;

                            $post_data = array(
                                'post_status' => 'publish', // Publish immediately
                            );

                            $result = $this->post_creator->create_product_post($product, $advertiser_data, $post_data);

                            if ($result && !is_wp_error($result)) {
                                $post_id = is_array($result) && isset($result['post_id']) ? $result['post_id'] : $result;
                                $this->log_message("Successfully published product ID: {$product['id']} immediately (Post ID: {$post_id})");

                                // Clear caches to reflect the new post
                                $this->queue_manager->clear_status_cache();
                            }
                        } else {
                            $this->log_message("No eligible products found for immediate publishing");
                        }
                    } else {
                        $this->log_message("Could not fetch products for immediate publishing");
                    }
                }
            }

            // ENHANCED: Schedule next dripfeed if none exists
            $next_dripfeed = wp_next_scheduled('pfa_dripfeed_publisher');
            if (!$next_dripfeed) {
                $posts_today = $this->get_post_count_today();
                $max_posts = get_option('max_posts_per_day', 10);

                if ($posts_today < $max_posts) {
                    // Schedule next dripfeed for tomorrow at 6 AM if we've reached daily limit
                    // or in dripfeed interval if we haven't
                    $scheduled_posts = $this->get_scheduled_posts_count();
                    $total_planned = $posts_today + $scheduled_posts;

                    if ($total_planned >= $max_posts) {
                        // Tomorrow at 6 AM
                        $next_time = new DateTime('tomorrow 06:00:00', $timezone);
                        $this->log_message("Daily limit reached. Scheduling next dripfeed for tomorrow 6 AM");
                    } else {
                        // Next interval
                        $interval_minutes = get_option('dripfeed_interval', 30);
                        $next_time = clone $current_time;
                        $next_time->modify("+{$interval_minutes} minutes");
                        $this->log_message("Scheduling next dripfeed in {$interval_minutes} minutes");
                    }

                    wp_schedule_single_event($next_time->getTimestamp(), 'pfa_dripfeed_publisher');
                    $this->log_message("Scheduled next dripfeed for: " . $next_time->format('Y-m-d H:i:s T'));
                }
            }
        } catch (Exception $e) {
            $this->log_message('ERROR in handle_dripfeed_publish: ' . $e->getMessage());
            $this->log_message('Stack trace: ' . $e->getTraceAsString());
        } finally {
            // Always release the lock
            delete_transient('pfa_dripfeed_lock');
            $this->log_message('Dripfeed lock released');
        }
    }

    /**
     * Handle API check cron event.
     *
     * @since    1.0.0
     */
    public function handle_api_check()
    {
        $current_action = current_action();
        $allowed_actions = array('pfa_api_check', 'pfa_dripfeed_publisher');

        if (!in_array($current_action, $allowed_actions)) {
            $this->log_message('Unauthorized handle_api_check call attempted from: ' . $current_action);
            return;
        }

        $this->log_message('=== Starting API Check from: ' . $current_action . ' ===');

        try {
            // Get categories
            $active_cat = get_term_by('slug', 'active-deals', 'category');
            $archive_cat = get_term_by('slug', 'archived-deals', 'category');

            if (!$active_cat || !$archive_cat) {
                $this->log_message('Required categories not found - active deals and/or archive-deals');
                return;
            }

            // Get current minimum discount and calculate check range
            $current_min_discount = get_option('min_discount', 0);
            $check_range_min = max(0, $current_min_discount - 10);

            $this->log_message(sprintf(
                'Current minimum discount: %d%%, Checking range: %d%% to 100%%',
                $current_min_discount,
                $check_range_min
            ));

            // Store current check time
            $current_time = current_time('mysql');
            update_option('pfa_last_api_check_time', $current_time);

            // Schedule next check
            $check_interval = get_option('check_interval', 'daily');
            $next_check = null;
            $now = current_time('timestamp');

            switch ($check_interval) {
                case 'hourly':
                    $next_check = strtotime('+1 hour', $now);
                    break;
                case 'twicedaily':
                    $next_check = (date('G', $now) < 12) ? strtotime('today 12:00') : strtotime('tomorrow 00:00');
                    break;
                case 'daily':
                    $next_check = strtotime('tomorrow 06:00:00', $now);
                    break;
            }

            wp_clear_scheduled_hook('pfa_api_check');
            wp_schedule_single_event($next_check, 'pfa_api_check');
            update_option('pfa_next_api_check', wp_date('Y-m-d H:i:s T', $next_check));

            // Fetch products from API for the entire discount range
            $products = $this->api_fetcher->fetch_products(true);

            if (!$products) {
                $this->log_message('No products fetched from API');
                return;
            }

            $product_lookup = array();
            foreach ($products as $product) {
                $product_lookup[$product['id']] = array(
                    'availability' => isset($product['availability']) ? $product['availability'] : ''
                );
            }

            $this->log_message(sprintf(
                'Built product lookup with %d products in %d%%-100%% range',
                count($product_lookup),
                $check_range_min
            ));

            // Process active posts for potential archiving
            $active_posts = get_posts(array(
                'post_type' => 'post',
                'post_status' => array('publish', 'future'),
                'posts_per_page' => -1,
                'category' => $active_cat->term_id,
                'tax_query' => array(
                    array(
                        'taxonomy' => 'store_type',
                        'field' => 'name',
                        'terms' => 'Amazon',
                        'operator' => 'NOT IN'
                    )
                ),
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => '_product_url',
                        'compare' => 'EXISTS',
                    ),
                    array(
                        // 'key' => '_Amazone_produt_baseName',
                        'key' => '_product_id',
                        'compare' => 'EXISTS',
                    )
                )
            ));

            $archived_count = 0;
            $checked_count = 0;

            // Check active posts for archiving
            foreach ($active_posts as $post) {
                // $product_id = get_post_meta($post->ID, '_Amazone_produt_baseName', true);
                $product_id = get_post_meta($post->ID, '_product_id', true);
                $checked_count++;

                $should_archive = false;
                $reason = '';

                $this->log_message("Checking active product ID: $product_id");

                if (!isset($product_lookup[$product_id])) {
                    $should_archive = true;
                    $reason = "Product $product_id not found in API data";
                } else {
                    $product_info = $product_lookup[$product_id];

                    if ($product_info['availability'] !== 'in_stock') {
                        $should_archive = true;
                        $reason = "Product $product_id is out of stock";
                    }
                }

                if ($should_archive) {
                    $this->log_message("Archiving post ID {$post->ID}: $reason");
                    $this->archive_post($post->ID, $archive_cat->term_id);
                    $archived_count++;
                }
            }

            // Process archived posts for potential reactivation
            $archived_posts = get_posts(array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'category' => $archive_cat->term_id,
                'tax_query' => array(
                    array(
                        'taxonomy' => 'store_type',
                        'field' => 'name',
                        'terms' => 'Amazon',
                        'operator' => 'NOT IN'
                    )
                ),
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => '_pfa_v2_post',
                        'value' => 'true',
                        'compare' => '='
                    ),
                    array(
                        'key' => '_product_url',
                        'compare' => 'EXISTS',
                    ),
                    array(
                        // 'key' => '_Amazone_produt_baseName',
                        'key' => '_product_id',
                        'compare' => 'EXISTS',
                    )
                )
            ));

            $reactivated_count = 0;

            foreach ($archived_posts as $post) {
                // $product_id = get_post_meta($post->ID, '_Amazone_produt_baseName', true);
                $product_id = get_post_meta($post->ID, '_product_id', true);
                $this->log_message("Checking archived product ID: $product_id for reactivation");

                if (isset($product_lookup[$product_id])) {
                    $product_info = $product_lookup[$product_id];

                    if ($product_info['availability'] === 'in_stock') {
                        $this->log_message(sprintf(
                            "Reactivating post ID %d: Product back in stock",
                            $post->ID
                        ));

                        $this->reactivate_post($post->ID, $active_cat->term_id, $product_info);
                        $reactivated_count++;
                    }
                }
            }

            // Update statistics
            if ($products) {
                $eligible_products = array_filter($products, function ($product) {
                    return isset($product['availability']) &&
                        $product['availability'] === 'in_stock';
                });

                $actually_eligible = array_filter($eligible_products, function ($product) {
                    $product_identifier = md5(
                        $product['id'] . '|' .
                            (isset($product['gtin']) ? $product['gtin'] : '') . '|' .
                            (isset($product['mpn']) ? $product['mpn'] : '')
                    );

                    if (in_array($product_identifier, get_option('pfa_product_identifiers', array()))) {
                        return false;
                    }

                    if ($this->post_creator->check_if_already_in_db($product['trackingLink'], $product)) {
                        return false;
                    }

                    return true;
                });

                $stats = array(
                    'time' => current_time('mysql'),
                    'total' => count($products),
                    'eligible' => count($actually_eligible),
                    'archived' => $archived_count,
                    'reactivated' => $reactivated_count,
                    'checked' => $checked_count
                );

                update_option('pfa_last_check_stats', $stats);
                update_option('pfa_last_total_products', $stats['total']);
                update_option('pfa_last_eligible_products', $stats['eligible']);
            }

            $this->log_message(sprintf(
                "API Check completed: Checked %d posts, archived %d, reactivated %d",
                $checked_count,
                $archived_count,
                $reactivated_count
            ));

            do_action('pfa_status_updated', 'api_check');
        } catch (Exception $e) {
            $this->log_message('Error during API check: ' . $e->getMessage());
        }
    }

    /**
     * Archive a post by moving it to the archive category.
     *
     * @since    1.0.0
     * @access   private
     * @param    int       $post_id             Post ID to archive.
     * @param    int       $archive_category_id  Archive category ID.
     */
    private function archive_post($post_id, $archive_category_id)
    {
        $post_content = get_post_field('post_content', $post_id);
        $price_block = get_post_field('post_excerpt', $post_id);

        if ($price_block) {
            $price_block = preg_replace(
                '/(<span class="discount-price">)([0-9.,]+\s*SEK)(<\/span>)/i',
                '$1<del>$2</del>$3',
                $price_block
            );
        }

        $updated_post_content = preg_replace(
            '/(<span class="discount-price">)([0-9.,]+\s*SEK)(<\/span>)/i',
            '$1<del>$2</del>$3',
            $post_content
        );

        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $updated_post_content,
            'post_excerpt' => $price_block
        ));

        wp_set_post_categories($post_id, array($archive_category_id), false);
    }

    /**
     * Reactivate an archived post.
     *
     * @since    1.0.0
     * @access   private
     * @param    int       $post_id             Post ID to reactivate.
     * @param    int       $active_category_id   Active category ID.
     * @param    array     $product_info         Product information.
     */
    private function reactivate_post($post_id, $active_category_id, $product_info)
    {
        $post_content = get_post_field('post_content', $post_id);
        $price_block = get_post_field('post_excerpt', $post_id);

        // Remove strikethrough from prices
        if ($price_block) {
            $price_block = preg_replace(
                '/(<span class="discount-price">)<del>([0-9.,]+\s*SEK)<\/del>(<\/span>)/i',
                '$1$2$3',
                $price_block
            );
        }

        $updated_post_content = preg_replace(
            '/(<span class="discount-price">)<del>([0-9.,]+\s*SEK)<\/del>(<\/span>)/i',
            '$1$2$3',
            $post_content
        );

        $current_time = current_time('mysql');

        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $updated_post_content,
            'post_excerpt' => $price_block,
            'post_date' => $current_time,
            'post_date_gmt' => get_gmt_from_date($current_time),
            'post_modified' => $current_time,
            'post_modified_gmt' => get_gmt_from_date($current_time)
        ));

        wp_set_post_categories($post_id, array($active_category_id), false);

        $this->log_message(sprintf(
            "Reactivated post ID %d with new date: %s",
            $post_id,
            $current_time
        ));
    }

    /**
     * Calculate the next publishing time.
     *
     * @since    1.0.0
     * @access   private
     * @return   DateTime|null    Next publishing time or null if not possible.
     */
    private function calculate_next_publish_time()
    {
        // $timezone = new DateTimeZone(wp_timezone_string());
        $timezone = wp_timezone();
        $now = new DateTime('now', $timezone);
        $interval_minutes = max(1, (int)$this->dripfeed_interval);

        // Check post count first
        $posts_today = $this->get_post_count_today();
        if ($posts_today >= $this->max_posts_per_day) {
            $this->log_message(sprintf(
                'Daily limit reached (%d/%d posts). Cannot schedule more posts today.',
                $posts_today,
                $this->max_posts_per_day
            ));
            return null;
        }

        // Handle 6 AM start time
        if ($now->format('H:i') === '06:00') {
            // If this is the first post at 6 AM, use exactly 6:00
            if ($posts_today === 0) {
                $next_time = new DateTime('today 06:00:00', $timezone);
                $this->log_message("First post of the day scheduled for 6 AM");
                return $next_time;
            }
        }

        // Get the last scheduled post
        global $wpdb;
        $last_scheduled = $wpdb->get_var($wpdb->prepare(
            "SELECT post_date FROM {$wpdb->posts}
             WHERE post_type = 'post'
             AND post_status = 'future'
             AND post_date >= %s
             ORDER BY post_date DESC
             LIMIT 1",
            $now->format('Y-m-d H:i:s')
        ));

        if ($last_scheduled) {
            $last_time = new DateTime($last_scheduled, $timezone);
            $this->log_message("Found last scheduled post at: " . $last_time->format('Y-m-d H:i:s'));

            // Calculate next time from last scheduled
            $next_time = clone $last_time;
            $next_time->modify("+{$interval_minutes} minutes");

            // If next time would be past 23:00, we can't schedule more today
            $end_of_day = (clone $now)->setTime(23, 0, 0);
            if ($next_time > $end_of_day) {
                $this->log_message('Cannot schedule more posts today - would exceed end of day (23:00)');
                return null;
            }

            return $next_time;
        }

        // If no future posts, start from now
        $next_time = clone $now;

        // Round up to next interval
        $minutes = (int)$next_time->format('i');
        $rounded_minutes = ceil($minutes / $interval_minutes) * $interval_minutes;
        $hours_to_add = floor($rounded_minutes / 60);
        $final_minutes = $rounded_minutes % 60;

        $next_time->setTime(
            (int)$next_time->format('H') + $hours_to_add,
            $final_minutes,
            0
        );

        // Check if we'd be scheduling past 23:00
        $end_of_day = (clone $now)->setTime(23, 0, 0);
        if ($next_time > $end_of_day) {
            $this->log_message('Cannot schedule more posts today - would exceed end of day (23:00)');
            return null;
        }

        $this->log_message(sprintf(
            "Next publish time calculated: %s (Interval: %d minutes)",
            $next_time->format('Y-m-d H:i:s'),
            $interval_minutes
        ));

        return $next_time;
    }

    public function debug_scheduled_posts()
    {
        global $wpdb;

        $this->log_message("=== DEBUG: Scheduled Posts Visibility Check ===");

        // 1. Check with WordPress native query
        $wp_scheduled = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'future',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));

        $this->log_message("WP Native Query: " . count($wp_scheduled) . " scheduled posts found");

        // 2. Check with direct SQL
        $direct_sql = $wpdb->get_results(
            "SELECT ID, post_title, post_date, post_status 
             FROM {$wpdb->posts}
             WHERE post_type = 'post'
             AND post_status = 'future'
             ORDER BY post_date ASC"
        );

        $this->log_message("Direct SQL Query: " . count($direct_sql) . " scheduled posts found");

        // 3. Check with the plugin's meta condition
        $with_meta = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_date, p.post_status 
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'post'
             AND p.post_status = 'future'
             AND pm.meta_key = '_pfa_v2_post'
             AND pm.meta_value = 'true'
             ORDER BY p.post_date ASC"
        );

        $this->log_message("Plugin Meta Query: " . count($with_meta) . " scheduled posts found");

        // 4. Show details of each scheduled post
        if (!empty($direct_sql)) {
            $this->log_message("Details of scheduled posts:");

            foreach ($direct_sql as $post) {
                $has_meta = get_post_meta($post->ID, '_pfa_v2_post', true) === 'true';
                $post_date = get_post_meta($post->ID, 'post_date', true);

                $this->log_message(sprintf(
                    "ID: %d, Title: %s, Date: %s, Has PFA Meta: %s",
                    $post->ID,
                    substr($post->post_title, 0, 30),
                    $post->post_date,
                    $has_meta ? 'YES' : 'NO'
                ));

                // Check if all required meta is present
                // $required_meta = ['_pfa_v2_post', '_Amazone_produt_baseName', '_product_url', 'dynamic_amazone_link'];
                $required_meta = ['_pfa_v2_post', '_product_id', '_product_url', 'dynamic_amazone_link'];

                $missing = [];

                foreach ($required_meta as $meta_key) {
                    if (!get_post_meta($post->ID, $meta_key, true)) {
                        $missing[] = $meta_key;
                    }
                }

                if (!empty($missing)) {
                    $this->log_message("  Missing required meta: " . implode(', ', $missing));
                }
            }
        }

        // 5. Check posts created today that might be incorrectly counted
        // $timezone = new DateTimeZone(wp_timezone_string());
        $timezone = wp_timezone();
        $today_start = new DateTime('today', $timezone);

        $today_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_date, post_status 
                 FROM {$wpdb->posts}
                 WHERE post_type = 'post'
                 AND post_date >= %s
                 ORDER BY post_date ASC",
                $today_start->format('Y-m-d H:i:s')
            )
        );

        $this->log_message("Posts created/dated today: " . count($today_posts));

        if (!empty($today_posts)) {
            foreach ($today_posts as $post) {
                $has_meta = get_post_meta($post->ID, '_pfa_v2_post', true) === 'true';

                $this->log_message(sprintf(
                    "Today's post - ID: %d, Title: %s, Date: %s, Status: %s, Has PFA Meta: %s",
                    $post->ID,
                    substr($post->post_title, 0, 30),
                    $post->post_date,
                    $post->post_status,
                    $has_meta ? 'YES' : 'NO'
                ));
            }
        }

        $this->log_message("=== End Scheduled Posts Debug ===");

        return [
            'wp_scheduled_count' => count($wp_scheduled),
            'direct_sql_count' => count($direct_sql),
            'with_meta_count' => count($with_meta),
            'today_posts' => count($today_posts)
        ];
    }

    /**
     * Check for available products and queue them for publishing.
     *
     * @since    1.0.0
     */
    public function check_and_queue_products()
    {
        $this->log_message('=== Starting check_and_queue_products with batch scheduling ===');

        if (get_option('pfa_automation_enabled') !== 'yes') {
            $this->log_message('Automation is disabled. Skipping queue check.');
            return;
        }

        try {
            // Get current interval setting
            $interval_minutes = (int)$this->dripfeed_interval;
            $this->log_message("Using dripfeed interval: {$interval_minutes} minutes");

            // ENHANCED: Always fetch products to ensure we have fresh data
            $this->log_message('Fetching products from API...');
            $products = $this->api_fetcher->fetch_products();

            if (!$products || empty($products)) {
                $this->log_message('ERROR: No products returned from API. Aborting queue check.');

                // ENHANCED: Schedule retry in 1 hour if API fails
                $retry_time = time() + HOUR_IN_SECONDS;
                if (!wp_next_scheduled('pfa_dripfeed_publisher', array('retry'))) {
                    wp_schedule_single_event($retry_time, 'pfa_dripfeed_publisher');
                    $this->log_message('Scheduled API retry in 1 hour due to API failure');
                }
                return;
            }

            $this->log_message('API returned ' . count($products) . ' total products');

            // FIXED: Calculate available slots properly - accounting for scheduled posts
            $max_posts = $this->max_posts_per_day;
            $posts_today = $this->get_post_count_today();
            $scheduled_posts_count = $this->get_scheduled_posts_count();
            $total_planned = $posts_today + $scheduled_posts_count;
            $slots_available = $max_posts - $total_planned;

            $this->log_message("Posts today: {$posts_today}, Scheduled: {$scheduled_posts_count}, Total planned: {$total_planned}");
            $this->log_message("Available slots: {$slots_available} (max: {$max_posts})");

            if ($slots_available <= 0) {
                $this->log_message('No slots available for today. Daily limit reached or exceeded.');

                // ENHANCED: Schedule for tomorrow if no slots today
                $timezone = wp_timezone();
                $tomorrow = new DateTime('tomorrow 06:00:00', $timezone);
                $existing_tomorrow = wp_next_scheduled('pfa_dripfeed_publisher');

                // Only schedule if no dripfeed exists or existing one is not for tomorrow
                if (!$existing_tomorrow || $existing_tomorrow < $tomorrow->getTimestamp() - 3600) {
                    wp_clear_scheduled_hook('pfa_dripfeed_publisher');
                    wp_schedule_single_event($tomorrow->getTimestamp(), 'pfa_dripfeed_publisher');
                    $this->log_message("Scheduled next dripfeed for tomorrow at 6 AM: " . $tomorrow->format('Y-m-d H:i:s T'));
                }
                return;
            }

            // Count current scheduled posts
            global $wpdb;
            $scheduled_posts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_date 
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s 
            AND p.post_status = %s
            AND pm.meta_key = %s
            AND pm.meta_value = %s
            ORDER BY p.post_date ASC",
                    'post',
                    'future',
                    '_pfa_v2_post',
                    'true'
                )
            );

            $this->log_message("Currently scheduled posts: " . count($scheduled_posts));

            // Get timezone
            $timezone = wp_timezone();
            $now = new DateTime('now', $timezone);
            $current_hour = (int)$now->format('H');
            $current_minute = (int)$now->format('i');

            // FIXED: We want to schedule up to the available slots
            $posts_to_schedule = $slots_available;

            if ($posts_to_schedule <= 0) {
                $this->log_message("No need to schedule additional posts. Already at max limit.");
                return;
            }

            $this->log_message("Will attempt to schedule {$posts_to_schedule} posts");

            // Calculate start time for the first scheduled post
            $next_time = clone $now;
            if ($current_hour < 7) {
                $next_time->setTime(6, 0, 0);
                $this->log_message("Early morning: Starting schedule at 6:00 AM");
            } elseif ($current_hour >= 22) {
                $this->log_message("Too late for scheduling today (after 10 PM)");
                return;
            } else {
                $next_time->modify("+{$interval_minutes} minutes");
                $this->log_message("Starting schedule at: " . $next_time->format('H:i'));
            }

            // Check if we have any existing scheduled posts and need to adjust start time
            if (count($scheduled_posts) > 0) {
                $last_scheduled = end($scheduled_posts);
                $last_time = new DateTime($last_scheduled->post_date, $timezone);
                $this->log_message("Last scheduled post at: " . $last_time->format('Y-m-d H:i:s'));

                // Start after the last scheduled post + interval
                $next_candidate = clone $last_time;
                $next_candidate->modify("+{$interval_minutes} minutes");

                if ($next_candidate > $next_time) {
                    $next_time = $next_candidate;
                    $this->log_message("Adjusted start time based on last scheduled post: " . $next_time->format('Y-m-d H:i:s'));
                }
            }

            // FIXED: Check if we're past scheduling window for today (10 PM)
            $end_of_day = new DateTime('today 22:00:00', $timezone);
            if ($next_time > $end_of_day) {
                $this->log_message("Too late to schedule more posts today. Next post would be after 10 PM.");
                return;
            }

            // FIXED: Calculate how many posts we can actually fit in time window
            $total_minutes_available = ($end_of_day->getTimestamp() - $next_time->getTimestamp()) / 60;
            $max_posts_by_time = floor($total_minutes_available / $interval_minutes) + 1;

            if ($posts_to_schedule > $max_posts_by_time) {
                $this->log_message("Time constraint: Can only fit {$max_posts_by_time} posts before 10 PM, reducing from {$posts_to_schedule}");
                $posts_to_schedule = $max_posts_by_time;
            }

            if ($posts_to_schedule <= 0) {
                $this->log_message("No time slots available for scheduling today");
                return;
            }

            // Initialize product identifiers if needed
            if (get_option('pfa_product_identifiers') === false) {
                add_option('pfa_product_identifiers', array());
                $this->log_message('Initialized product identifiers option (was missing)');
            }

            $existing_identifiers = get_option('pfa_product_identifiers', array());
            $this->log_message('Loaded ' . count($existing_identifiers) . ' existing product identifiers');

            // FIXED: Simplified but effective advertiser diversity
            $products_by_advertiser = array();
            foreach ($products as $product) {
                // Skip products not in stock
                if (!isset($product['availability']) || $product['availability'] !== 'in_stock') {
                    continue;
                }

                // Skip products without ID or advertiser
                if (!isset($product['id']) || !isset($product['advertiserId'])) {
                    continue;
                }

                $advertiser_id = $product['advertiserId'];

                if (!isset($products_by_advertiser[$advertiser_id])) {
                    $products_by_advertiser[$advertiser_id] = array();
                }

                $products_by_advertiser[$advertiser_id][] = $product;
            }

            $advertisers_count = count($products_by_advertiser);
            $this->log_message("Found products from {$advertisers_count} different advertisers");

            if ($advertisers_count === 0) {
                $this->log_message("No advertisers with eligible products found");
                return;
            }

            // Sort products within each advertiser by discount (highest first)
            foreach ($products_by_advertiser as $advertiser_id => &$advertiser_products) {
                usort($advertiser_products, function ($a, $b) {
                    if (
                        !isset($a['price']) || !isset($a['sale_price']) ||
                        !isset($b['price']) || !isset($b['sale_price'])
                    ) {
                        return 0;
                    }

                    $discount_a = $this->post_creator->calculate_discount($a['price'], $a['sale_price']);
                    $discount_b = $this->post_creator->calculate_discount($b['price'], $b['sale_price']);

                    return $discount_b - $discount_a;
                });
            }

            // FIXED: Simple round-robin selection for diversity
            $eligible_products = array();
            $advertiser_ids = array_keys($products_by_advertiser);
            $advertiser_index = 0;
            $used_products = array();
            $skipped = array('duplicate' => 0, 'exists' => 0);

            // Round-robin through advertisers to ensure diversity
            while (count($eligible_products) < $posts_to_schedule && $advertiser_index < 1000) { // Safety limit
                $current_advertiser = $advertiser_ids[$advertiser_index % $advertisers_count];

                // Find next eligible product from this advertiser
                $found_product = false;
                foreach ($products_by_advertiser[$current_advertiser] as $key => $product) {
                    // Skip if already used
                    if (in_array($product['id'], $used_products)) {
                        continue;
                    }

                    $variant_identifier = md5(
                        $product['id'] . '|' .
                            (isset($product['gtin']) ? $product['gtin'] : '') . '|' .
                            (isset($product['mpn']) ? $product['mpn'] : '')
                    );

                    // Skip if already processed
                    if (in_array($variant_identifier, $existing_identifiers)) {
                        $skipped['duplicate']++;
                        continue;
                    }

                    // Skip if already in database
                    if (!isset($product['trackingLink'])) {
                        continue;
                    }

                    if ($this->post_creator->check_if_already_in_db($product['trackingLink'], $product)) {
                        $skipped['exists']++;
                        continue;
                    }

                    // This product is eligible
                    $eligible_products[] = array(
                        'product' => $product,
                        'identifier' => $variant_identifier,
                        'advertiser_id' => $current_advertiser
                    );

                    $used_products[] = $product['id'];
                    $found_product = true;

                    $discount = $this->post_creator->calculate_discount($product['price'], $product['sale_price']);
                    $this->log_message("Selected product ID: {$product['id']} from advertiser: {$current_advertiser} (discount: {$discount}%)");
                    break;
                }

                // If no product found from this advertiser, remove it from rotation
                if (!$found_product) {
                    unset($advertiser_ids[array_search($current_advertiser, $advertiser_ids)]);
                    $advertiser_ids = array_values($advertiser_ids); // Re-index
                    $advertisers_count = count($advertiser_ids);

                    if ($advertisers_count === 0) {
                        $this->log_message("No more advertisers with eligible products");
                        break;
                    }

                    // Don't increment index since we removed an advertiser
                    continue;
                }

                $advertiser_index++;
            }

            $this->log_message("Selected " . count($eligible_products) . " products for scheduling");
            $this->log_message("Skipped - duplicate: {$skipped['duplicate']}, exists: {$skipped['exists']}");

            // Log advertiser distribution
            $advertiser_distribution = array();
            foreach ($eligible_products as $item) {
                $adv_id = $item['advertiser_id'];
                if (!isset($advertiser_distribution[$adv_id])) {
                    $advertiser_distribution[$adv_id] = 0;
                }
                $advertiser_distribution[$adv_id]++;
            }

            foreach ($advertiser_distribution as $adv_id => $count) {
                $this->log_message("Advertiser ID {$adv_id}: {$count} products selected");
            }

            // If no eligible products, just return
            if (empty($eligible_products)) {
                $this->log_message("No eligible products found to schedule");
                return;
            }

            // Check advertiser diversity
            if (count($advertiser_distribution) < min(3, $advertisers_count) && $advertisers_count >= 3) {
                $this->log_message("WARNING: Limited advertiser diversity. Consider lowering minimum discount to get more advertisers.");
            }

            // Fetch advertisers data
            $advertisers = $this->api_fetcher->fetch_advertisers();

            // Schedule the posts
            $scheduled_count = 0;
            $schedule_time = $next_time;

            foreach ($eligible_products as $item) {
                $product = $item['product'];
                $identifier = $item['identifier'];
                $advertiser_id = $item['advertiser_id'];

                // Check if we're past the end time
                if ($schedule_time > $end_of_day) {
                    $this->log_message("Reached end of scheduling window for today");
                    break;
                }

                // Get advertiser data
                $advertiser_data = isset($advertisers[$advertiser_id]) ? $advertisers[$advertiser_id] : null;

                $this->log_message("Scheduling product ID: {$product['id']} from advertiser: {$advertiser_id} for {$schedule_time->format('Y-m-d H:i:s T')}");

                // Prepare post data
                $post_data = array(
                    'post_status' => 'future',
                    'post_date' => $schedule_time->format('Y-m-d H:i:s'),
                    'post_date_gmt' => get_gmt_from_date($schedule_time->format('Y-m-d H:i:s')),
                );

                // Create the post
                $result = $this->post_creator->create_product_post($product, $advertiser_data, $post_data);

                if ($result && !is_wp_error($result)) {
                    // Add to existing identifiers to prevent duplicates
                    $existing_identifiers[] = $identifier;
                    update_option('pfa_product_identifiers', $existing_identifiers);

                    $post_id = is_array($result) && isset($result['post_id']) ? $result['post_id'] : $result;
                    $this->log_message("Successfully scheduled product ID: {$product['id']} from advertiser: {$advertiser_id} for {$schedule_time->format('Y-m-d H:i:s')} (Post ID: {$post_id})");

                    $scheduled_count++;

                    // Calculate next schedule time - exactly respecting the interval
                    $schedule_time = clone $schedule_time;
                    $schedule_time->modify("+{$interval_minutes} minutes");
                } else {
                    $this->log_message("Failed to schedule product ID: {$product['id']}");
                }
            }

            $this->log_message("Successfully scheduled {$scheduled_count} posts");

            // Force refresh of queue status
            $queue_manager = PFA_Queue_Manager::get_instance();
            $queue_manager->clear_status_cache();

            // ENHANCED: Schedule next dripfeed based on results
            if ($scheduled_count > 0) {
                // Schedule for tomorrow at 6 AM
                $tomorrow = new DateTime('tomorrow 06:00:00', $timezone);
                wp_clear_scheduled_hook('pfa_dripfeed_publisher');
                wp_schedule_single_event($tomorrow->getTimestamp(), 'pfa_dripfeed_publisher');
                $this->log_message("Scheduled next dripfeed for tomorrow at 6 AM: " . $tomorrow->format('Y-m-d H:i:s T'));
            } else {
                // If no posts were scheduled, retry in 2 hours
                $retry_time = time() + (2 * HOUR_IN_SECONDS);
                wp_clear_scheduled_hook('pfa_dripfeed_publisher');
                wp_schedule_single_event($retry_time, 'pfa_dripfeed_publisher');
                $this->log_message("No posts scheduled. Will retry in 2 hours");
            }
        } catch (Exception $e) {
            $this->log_message('ERROR in batch scheduling: ' . $e->getMessage());
            $this->log_message('Stack trace: ' . $e->getTraceAsString());

            // ENHANCED: Schedule retry on exception
            $retry_time = time() + (30 * MINUTE_IN_SECONDS);
            if (!wp_next_scheduled('pfa_dripfeed_publisher')) {
                wp_schedule_single_event($retry_time, 'pfa_dripfeed_publisher');
                $this->log_message('Scheduled retry in 30 minutes due to exception');
            }
        }
    }

    private function get_scheduled_posts_count()
    {
        global $wpdb;

        // Get today's date in the site timezone
        // $timezone = new DateTimeZone(wp_timezone_string());
        $timezone = wp_timezone();
        $today = new DateTime('today', $timezone);

        $scheduled_posts = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s 
            AND p.post_status = %s
            AND pm.meta_key = %s
            AND pm.meta_value = %s
            AND p.post_date > %s
            AND p.post_date >= %s",
                'post',
                'future',
                '_pfa_v2_post',
                'true',
                current_time('mysql'),
                $today->format('Y-m-d 00:00:00') // Only count posts scheduled for today onwards
            )
        );

        $this->log_message('Scheduled posts count: ' . $scheduled_posts);
        return (int) $scheduled_posts;
    }


    /**
     * Get count of posts published today.
     *
     * @since    1.0.0
     * @return   int    Number of posts published today.
     */
    public function get_post_count_today()
    {
        // $timezone = new DateTimeZone(wp_timezone_string());
        $timezone = wp_timezone();
        $today_start = new DateTime('today', $timezone);
        $today_end = clone $today_start;
        $today_end->modify('+1 day');

        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'post' 
             AND p.post_status IN ('publish', 'future') 
             AND p.post_date >= %s 
             AND p.post_date < %s
             AND pm.meta_key = '_pfa_v2_post'
             AND pm.meta_value = 'true'",
            $today_start->format('Y-m-d H:i:s'),
            $today_end->format('Y-m-d H:i:s')
        ));

        $count = (int)$count;
        $this->log_message(sprintf(
            "PFA Post count for %s: %d (between %s and %s)",
            $today_start->format('Y-m-d'),
            $count,
            $today_start->format('Y-m-d H:i:s'),
            $today_end->format('Y-m-d H:i:s')
        ));

        return $count;
    }

    /**
     * Add custom cron schedules.
     *
     * @since    1.0.0
     * @param    array     $schedules    Existing cron schedules.
     * @return   array                   Modified cron schedules.
     */
    public function add_custom_schedules($schedules)
    {
        $interval = max(30, (int)$this->dripfeed_interval);
        $key = 'every_' . $interval . '_minutes';

        if (!isset($schedules[$key])) {
            $schedules[$key] = array(
                'interval' => $interval * 60,
                'display' => sprintf(__('Every %d minutes'), $interval)
            );
        }

        return $schedules;
    }

    /**
     * Initialize cron schedules.
     *
     * @since    1.0.0
     */
    public function initialize_schedules()
    {
        if (get_option('pfa_automation_enabled', 'yes') === 'yes') {
            // Clear existing API check schedule
            wp_clear_scheduled_hook('pfa_api_check');

            // Schedule API check based on interval setting
            $check_interval = get_option('check_interval', 'daily');
            if (!wp_next_scheduled('pfa_api_check')) {
                wp_schedule_event(time(), $check_interval, 'pfa_api_check');
                $this->log_message("Scheduled API check with interval: {$check_interval}");
            }

            // Schedule daily check at midnight
            if (!wp_next_scheduled('pfa_daily_check')) {
                wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'pfa_daily_check');
            }

            // ENHANCED: Check if we need immediate queue population
            $posts_today = $this->get_post_count_today();
            $max_posts = get_option('max_posts_per_day', 10);
            $scheduled_posts = $this->get_scheduled_posts_count();
            $queue_manager = PFA_Queue_Manager::get_instance();
            $current_queue_size = count($queue_manager->get_queue());

            $this->log_message("Initialization check - Posts today: {$posts_today}, Max: {$max_posts}, Scheduled: {$scheduled_posts}, Queue size: {$current_queue_size}");

            // If we have capacity and no queue, populate immediately
            $total_planned = $posts_today + $scheduled_posts;
            $available_slots = $max_posts - $total_planned;

            if ($available_slots > 0 && $current_queue_size === 0) {
                $this->log_message("No queue found but we have {$available_slots} available slots. Triggering immediate queue population.");

                // Trigger immediate queue population via scheduled check
                wp_schedule_single_event(time() + 30, 'pfa_dripfeed_publisher');
                $this->log_message("Scheduled immediate dripfeed check in 30 seconds");
            }

            // Retrieve the most recent scheduled post
            $last_scheduled = wp_get_recent_posts(array(
                'post_type' => 'post',
                'post_status' => 'future',
                'orderby' => 'post_date',
                'order' => 'DESC',
                'numberposts' => 1,
            ));

            $last_scheduled_date = isset($last_scheduled[0]) ? $last_scheduled[0]['post_date'] : null;
            $this->log_message('Last scheduled post date for initialization: ' . ($last_scheduled_date ?: 'None'));

            // Schedule the first dripfeed if none exists
            if (!wp_next_scheduled('pfa_dripfeed_publisher')) {
                $next_time = $this->calculate_next_publish_time();

                if ($next_time === null) {
                    $this->log_message("Could not determine next publish time - falling back to tomorrow at 06:00.");
                    $timezone = wp_timezone();
                    $next_time = new DateTime('tomorrow 06:00:00', $timezone);
                }

                wp_schedule_single_event($next_time->getTimestamp(), 'pfa_dripfeed_publisher');
                $this->log_message("Scheduled dripfeed for: " . $next_time->format('Y-m-d H:i:s'));
            }
        }

        $this->verify_schedules();
    }

    /**
     * Verify that all schedules are set up correctly.
     *
     * @since    1.0.0
     * @access   private
     */
    private function verify_schedules()
    {
        $next_daily = wp_next_scheduled('pfa_daily_check');
        $next_dripfeed = wp_next_scheduled('pfa_dripfeed_publisher');
        $next_api = wp_next_scheduled('pfa_api_check');

        $this->log_message('Schedule verification (All times in local timezone):');
        $this->log_message('- Daily check: ' . ($next_daily ? wp_date('Y-m-d H:i:s T', $next_daily) . " (UTC: " . date('Y-m-d H:i:s', $next_daily) . ")" : 'not scheduled'));
        $this->log_message('- Dripfeed: ' . ($next_dripfeed ? wp_date('Y-m-d H:i:s T', $next_dripfeed) . " (UTC: " . date('Y-m-d H:i:s', $next_dripfeed) . ")" : 'not scheduled'));
        $this->log_message('- API check: ' . ($next_api ? wp_date('Y-m-d H:i:s T', $next_api) . " (UTC: " . date('Y-m-d H:i:s', $next_api) . ")" : 'not scheduled'));

        // Additional verification - check if schedules are in the past
        $current_timestamp = time();
        if ($next_dripfeed && $next_dripfeed <= $current_timestamp) {
            $this->log_message('WARNING: Dripfeed schedule is in the past! Rescheduling...');
            wp_clear_scheduled_hook('pfa_dripfeed_publisher');

            $timezone = wp_timezone();
            $next_time = $this->calculate_next_publish_time();
            if ($next_time) {
                wp_schedule_single_event($next_time->getTimestamp(), 'pfa_dripfeed_publisher');
                $this->log_message('Rescheduled dripfeed for: ' . $next_time->format('Y-m-d H:i:s T'));
            }
        }

        if ($next_daily && $next_daily <= $current_timestamp) {
            $this->log_message('WARNING: Daily check is in the past! Rescheduling...');
            wp_clear_scheduled_hook('pfa_daily_check');
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'pfa_daily_check');
            $this->log_message('Rescheduled daily check for tomorrow midnight');
        }
    }

    /**
     * Clear all cron schedules related to this plugin.
     *
     * @since    1.0.0
     */
    public function clear_all_schedules()
    {
        $hooks = array(
            'pfa_dripfeed_publisher',
            'pfa_daily_check',
            'pfa_api_check'
        );

        foreach ($hooks as $hook) {
            wp_clear_scheduled_hook($hook);
            $this->log_message("Cleared schedule: $hook");
        }
    }

    /**
     * Clean stale product identifiers.
     *
     * @since    1.0.0
     */
    public function clean_stale_identifiers()
    {
        $existing_identifiers = get_option('pfa_product_identifiers', array());
        if (empty($existing_identifiers)) {
            $this->log_message('No identifiers to clean.');
            return;
        }

        $this->log_message('Starting identifier cleanup. Current count: ' . count($existing_identifiers));

        // Get all active PFA posts with their product data
        global $wpdb;
        $active_posts = $wpdb->get_results("
        SELECT p.ID, pm_id.meta_value as product_id
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm_pfa ON p.ID = pm_pfa.post_id 
            AND pm_pfa.meta_key = '_pfa_v2_post' 
            AND pm_pfa.meta_value = 'true'
        JOIN {$wpdb->postmeta} pm_id ON p.ID = pm_id.post_id 
            AND pm_id.meta_key = '_product_id'
        WHERE p.post_type = 'post'
        AND p.post_status IN ('publish', 'future')
    ");

        // Generate identifiers for all active posts
        $active_identifiers = array();
        foreach ($active_posts as $post) {
            // Use the same logic as in scheduling: product_id + empty gtin/mpn
            $identifier = md5($post->product_id . '||');
            $active_identifiers[] = $identifier;
        }

        // Keep only identifiers that match active posts
        $cleaned_identifiers = array_intersect($existing_identifiers, $active_identifiers);

        update_option('pfa_product_identifiers', $cleaned_identifiers);
        $this->log_message(sprintf(
            'Identifier cleanup complete. Before: %d, After: %d, Removed: %d',
            count($existing_identifiers),
            count($cleaned_identifiers),
            count($existing_identifiers) - count($cleaned_identifiers)
        ));
    }

    /**
     * Log messages to error log.
     *
     * @since    1.0.0
     * @access   private
     * @param    string    $message    Message to log.
     */
    private function log_message($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PFA Scheduler] ' . $message);
        }
    }
}
