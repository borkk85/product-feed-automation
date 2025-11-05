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
            // Hook registration is handled centrally via the plugin loader to avoid duplicates
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
        $this->log_message('=== Starting Dripfeed Publish (Queue Processing) ===');

        $queue_manager = PFA_Queue_Manager::get_instance();
        $lock_acquired = false;

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

            if (!$queue_manager->acquire_dripfeed_lock('dripfeed_publish')) {
                $this->log_message('Another dripfeed run is already in progress. Skipping.');
                return;
            }

            $lock_acquired = true;

            // Check daily posting limits
            $posts_today = $this->get_post_count_today();
            $max_posts = get_option('max_posts_per_day', 10);
            $scheduled_posts = $this->get_scheduled_posts_count();

            $this->log_message("Posts today: {$posts_today}, Max: {$max_posts}, Scheduled: {$scheduled_posts}");

            if ($posts_today >= $max_posts) {
                $this->log_message("Daily limit reached ({$posts_today}/{$max_posts}). No more posts today.");

                // Schedule for tomorrow at 6 AM
                $tomorrow = new DateTime('tomorrow 06:00:00', $timezone);
                wp_clear_scheduled_hook('pfa_dripfeed_publisher');
                wp_schedule_single_event($tomorrow->getTimestamp(), 'pfa_dripfeed_publisher');
                $this->log_message("Scheduled next dripfeed for tomorrow at 6 AM");
                return;
            }

            // Check queue contents
            $queue_size = count($queue_manager->get_queue());

            $this->log_message("Current queue size: {$queue_size}");

            // If queue is empty, try to populate it first
            if ($queue_size === 0) {
                $this->log_message("Queue is empty. Attempting to populate queue...");
                $this->check_and_queue_products();

                // Check queue size again after population attempt
                $queue_size = count($queue_manager->get_queue());
                $this->log_message("Queue size after population attempt: {$queue_size}");

                if ($queue_size === 0) {
                    $this->log_message("No products available in queue after population attempt");

                    // Schedule retry in 1 hour
                    $retry_time = time() + HOUR_IN_SECONDS;
                    wp_schedule_single_event($retry_time, 'pfa_dripfeed_publisher');
                    $this->log_message("Scheduled retry in 1 hour");
                    return;
                }
            }

            // Process one item from queue
            $product = $queue_manager->get_next_queued_product();

            if (!$product) {
                $this->log_message("No product retrieved from queue");
                return;
            }

            $this->log_message("Processing product ID: {$product['id']} from queue");

            // Determine best URL to validate: prefer direct link, else decoded tracking 'u', else trackingLink
            $validation_url = '';
            if (!empty($product['link']) && function_exists('wp_http_validate_url') && wp_http_validate_url($product['link'])) {
                $validation_url = $product['link'];
            } elseif (!empty($product['trackingLink'])) {
                $validation_url = $product['trackingLink'];
                $parsed = wp_parse_url($product['trackingLink']);
                if (!empty($parsed['query'])) {
                    parse_str($parsed['query'], $q);
                    if (!empty($q['u'])) {
                        $decoded = urldecode($q['u']);
                        if (function_exists('wp_http_validate_url') && wp_http_validate_url($decoded)) {
                            $validation_url = $decoded;
                        }
                    }
                }
            }

            // Validate destination URL before any scheduling effort
            if (empty($validation_url) || !$this->is_destination_live($validation_url)) {
                $this->log_message("Skipping product ID: {$product['id']} due to invalid/unreachable tracking link");

                // Ensure future queue refills may reconsider this product later by clearing its identifier
                $identifier_hashes = $queue_manager->get_identifier_hashes($product);
                $existing_identifiers = get_option('pfa_product_identifiers', array());
                $modified = false;

                foreach ($identifier_hashes as $hash) {
                    $key = array_search($hash, $existing_identifiers, true);
                    if ($key !== false) {
                        unset($existing_identifiers[$key]);
                        $modified = true;
                    }
                }

                if ($modified) {
                    update_option('pfa_product_identifiers', array_values($existing_identifiers));
                    $this->log_message("Removed identifier(s) for skipped product {$product['id']} to allow future reconsideration");
                }

                // Schedule the next dripfeed attempt based on interval
                $interval_minutes = (int) get_option('dripfeed_interval', 30);
                $next_dripfeed = clone $current_time;
                $next_dripfeed->modify("+{$interval_minutes} minutes");
                wp_schedule_single_event($next_dripfeed->getTimestamp(), 'pfa_dripfeed_publisher');
                $this->log_message("Scheduled next dripfeed after skip for: " . $next_dripfeed->format('Y-m-d H:i:s T'));
                return;
            }

            // Calculate next publishing time
            $next_time = $this->calculate_next_publish_time();

            if ($next_time === null) {
                $this->log_message("Cannot determine next publish time - likely reached end of day");

                // Put product back in queue (add to front)
                $current_queue = $queue_manager->get_queue();
                array_unshift($current_queue, $product);
                set_transient('pfa_product_queue', $current_queue, DAY_IN_SECONDS);

                // Schedule for tomorrow
                $tomorrow = new DateTime('tomorrow 06:00:00', $timezone);
                wp_schedule_single_event($tomorrow->getTimestamp(), 'pfa_dripfeed_publisher');
                $this->log_message("Product returned to queue, scheduled for tomorrow");
                return;
            }

            // Get advertiser data
            $advertisers = $this->api_fetcher->fetch_advertisers();
            $advertiser_data = isset($product['advertiserId']) && isset($advertisers[$product['advertiserId']]) ?
                $advertisers[$product['advertiserId']] : null;

            // Prepare post data for scheduling
            $post_data = array(
                'post_status' => 'future',
                'post_date' => $next_time->format('Y-m-d H:i:s'),
                'post_date_gmt' => get_gmt_from_date($next_time->format('Y-m-d H:i:s')),
            );

            $this->log_message("Scheduling product ID: {$product['id']} for {$next_time->format('Y-m-d H:i:s T')}");

            // Create the scheduled post
            $result = $this->post_creator->create_product_post($product, $advertiser_data, $post_data);

            if ($result && !is_wp_error($result)) {
                $post_id = is_array($result) && isset($result['post_id']) ? $result['post_id'] : $result;
                $this->log_message("Successfully scheduled product ID: {$product['id']} (Post ID: {$post_id})");

                // Clear cache to reflect changes
                $queue_manager->clear_status_cache();

                // Schedule next dripfeed
                $remaining_slots = $max_posts - ($posts_today + 1); // +1 for the post we just scheduled
                $remaining_queue = count($queue_manager->get_queue());

                if ($remaining_slots > 0 && $remaining_queue > 0) {
                    // Schedule next dripfeed based on interval
                    $interval_minutes = get_option('dripfeed_interval', 30);
                    $next_dripfeed = clone $current_time;
                    $next_dripfeed->modify("+{$interval_minutes} minutes");

                    // Don't schedule past 23:00
                    $end_of_day = new DateTime('today 23:00:00', $timezone);
                    if ($next_dripfeed <= $end_of_day) {
                        wp_schedule_single_event($next_dripfeed->getTimestamp(), 'pfa_dripfeed_publisher');
                        $this->log_message("Scheduled next dripfeed for: " . $next_dripfeed->format('Y-m-d H:i:s T'));
                    } else {
                        // Schedule for tomorrow
                        $tomorrow = new DateTime('tomorrow 06:00:00', $timezone);
                        wp_schedule_single_event($tomorrow->getTimestamp(), 'pfa_dripfeed_publisher');
                        $this->log_message("End of day reached. Scheduled next dripfeed for tomorrow 6 AM");
                    }
                } else {
                    // Daily limit reached or queue empty
                    if ($remaining_slots <= 0) {
                        $this->log_message("Daily limit will be reached. Scheduling for tomorrow.");
                    } else {
                        $this->log_message("Queue is empty. Scheduling for tomorrow to allow refill.");
                    }

                    $tomorrow = new DateTime('tomorrow 06:00:00', $timezone);
                    wp_schedule_single_event($tomorrow->getTimestamp(), 'pfa_dripfeed_publisher');
                    $this->log_message("Scheduled next dripfeed for tomorrow at 6 AM");
                }
            } else {
                $this->log_message("Failed to schedule product ID: {$product['id']}");

                // Don't put failed product back in queue to avoid infinite loops
                // But schedule next dripfeed to try other products
                $interval_minutes = get_option('dripfeed_interval', 30);
                $retry_time = time() + ($interval_minutes * 60);
                wp_schedule_single_event($retry_time, 'pfa_dripfeed_publisher');
                $this->log_message("Scheduled retry dripfeed in {$interval_minutes} minutes");
            }
        } catch (Exception $e) {
            $this->log_message('ERROR in handle_dripfeed_publish: ' . $e->getMessage());
            $this->log_message('Stack trace: ' . $e->getTraceAsString());

            // Schedule retry on exception
            $retry_time = time() + (30 * MINUTE_IN_SECONDS);
            wp_schedule_single_event($retry_time, 'pfa_dripfeed_publisher');
            $this->log_message('Scheduled retry in 30 minutes due to exception');
        } finally {
            if ($lock_acquired && $queue_manager) {
                $queue_manager->release_dripfeed_lock();
            }
        }
    }

    /**
     * Validate a product destination URL using WP HTTP API (HEAD then GET fallback).
     * Caches results briefly to avoid repeated network calls.
     *
     * @since    1.0.0
     * @access   private
     * @param    string    $url    Destination URL to validate.
     * @return   bool               True if URL seems live (2xx/3xx), false otherwise.
     */
    private function is_destination_live($url)
    {
        if (empty($url) || !function_exists('wp_http_validate_url') || !wp_http_validate_url($url)) {
            return false;
        }

        $cache_key = 'pfa_url_status_' . md5($url);
        $cached = get_transient($cache_key);
        if ($cached === 'live') {
            return true;
        }
        if ($cached === 'dead') {
            return false;
        }

        $args = array(
            'timeout'     => 6,
            'redirection' => 5,
            'sslverify'   => true,
            'user-agent'  => 'PFA/1.0; ' . home_url('/'),
        );

        // Try HEAD first
        $response = wp_remote_head($url, $args);
        $code = 0;
        if (!is_wp_error($response)) {
            $code = (int) wp_remote_retrieve_response_code($response);
        }

        // Fallback to GET when HEAD fails or returns non 2xx/3xx
        if ($code < 200 || $code >= 400) {
            $response = wp_remote_get($url, $args);
            if (!is_wp_error($response)) {
                $code = (int) wp_remote_retrieve_response_code($response);
            } else {
                $code = 0;
            }
        }

        $ok = ($code >= 200 && $code < 400);
        set_transient($cache_key, $ok ? 'live' : 'dead', $ok ? HOUR_IN_SECONDS : 6 * HOUR_IN_SECONDS);
        $this->log_message(sprintf('URL check for destination returned code %d (%s)', $code, $ok ? 'live' : 'dead'));
        return $ok;
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
            $product_map = array(); // full product data keyed by id for re-post queueing
            foreach ($products as $product) {
                $product_lookup[$product['id']] = array(
                    'availability' => isset($product['availability']) ? $product['availability'] : ''
                );
                $product_map[$product['id']] = $product;
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
            $reposted_count = 0;

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
                        // Queue a brand-new post for this product instead of reactivating the old post
                        if (isset($product_map[$product_id])) {
                            $queue_manager = PFA_Queue_Manager::get_instance();
                            if ($queue_manager->add_to_queue($product_map[$product_id])) {
                                $reposted_count++;
                                $this->log_message(sprintf(
                                    "Queued new post for restocked product ID %s (archived post ID %d)",
                                    $product_id,
                                    $post->ID
                                ));
                            } else {
                                $this->log_message(sprintf(
                                    "Skipped queueing restocked product ID %s (already in queue)",
                                    $product_id
                                ));
                            }
                        }
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
                    'reactivated' => $reposted_count, // reuse existing key for UI, counting queued re-posts
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

    // public function debug_scheduled_posts()
    // {
    //     global $wpdb;

    //     $this->log_message("=== DEBUG: Scheduled Posts Visibility Check ===");

    //     // 1. Check with WordPress native query
    //     $wp_scheduled = get_posts(array(
    //         'post_type' => 'post',
    //         'post_status' => 'future',
    //         'posts_per_page' => -1,
    //         'fields' => 'ids'
    //     ));

    //     $this->log_message("WP Native Query: " . count($wp_scheduled) . " scheduled posts found");

    //     // 2. Check with direct SQL
    //     $direct_sql = $wpdb->get_results(
    //         "SELECT ID, post_title, post_date, post_status 
    //          FROM {$wpdb->posts}
    //          WHERE post_type = 'post'
    //          AND post_status = 'future'
    //          ORDER BY post_date ASC"
    //     );

    //     $this->log_message("Direct SQL Query: " . count($direct_sql) . " scheduled posts found");

    //     // 3. Check with the plugin's meta condition
    //     $with_meta = $wpdb->get_results(
    //         "SELECT p.ID, p.post_title, p.post_date, p.post_status 
    //          FROM {$wpdb->posts} p
    //          JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
    //          WHERE p.post_type = 'post'
    //          AND p.post_status = 'future'
    //          AND pm.meta_key = '_pfa_v2_post'
    //          AND pm.meta_value = 'true'
    //          ORDER BY p.post_date ASC"
    //     );

    //     $this->log_message("Plugin Meta Query: " . count($with_meta) . " scheduled posts found");

    //     // 4. Show details of each scheduled post
    //     if (!empty($direct_sql)) {
    //         $this->log_message("Details of scheduled posts:");

    //         foreach ($direct_sql as $post) {
    //             $has_meta = get_post_meta($post->ID, '_pfa_v2_post', true) === 'true';
    //             $post_date = get_post_meta($post->ID, 'post_date', true);

    //             $this->log_message(sprintf(
    //                 "ID: %d, Title: %s, Date: %s, Has PFA Meta: %s",
    //                 $post->ID,
    //                 substr($post->post_title, 0, 30),
    //                 $post->post_date,
    //                 $has_meta ? 'YES' : 'NO'
    //             ));

    //             // Check if all required meta is present
    //             // $required_meta = ['_pfa_v2_post', '_Amazone_produt_baseName', '_product_url', 'dynamic_amazone_link'];
    //             $required_meta = ['_pfa_v2_post', '_product_id', '_product_url', 'dynamic_amazone_link'];

    //             $missing = [];

    //             foreach ($required_meta as $meta_key) {
    //                 if (!get_post_meta($post->ID, $meta_key, true)) {
    //                     $missing[] = $meta_key;
    //                 }
    //             }

    //             if (!empty($missing)) {
    //                 $this->log_message("  Missing required meta: " . implode(', ', $missing));
    //             }
    //         }
    //     }

    //     // 5. Check posts created today that might be incorrectly counted
    //     // $timezone = new DateTimeZone(wp_timezone_string());
    //     $timezone = wp_timezone();
    //     $today_start = new DateTime('today', $timezone);

    //     $today_posts = $wpdb->get_results(
    //         $wpdb->prepare(
    //             "SELECT ID, post_title, post_date, post_status 
    //              FROM {$wpdb->posts}
    //              WHERE post_type = 'post'
    //              AND post_date >= %s
    //              ORDER BY post_date ASC",
    //             $today_start->format('Y-m-d H:i:s')
    //         )
    //     );

    //     $this->log_message("Posts created/dated today: " . count($today_posts));

    //     if (!empty($today_posts)) {
    //         foreach ($today_posts as $post) {
    //             $has_meta = get_post_meta($post->ID, '_pfa_v2_post', true) === 'true';

    //             $this->log_message(sprintf(
    //                 "Today's post - ID: %d, Title: %s, Date: %s, Status: %s, Has PFA Meta: %s",
    //                 $post->ID,
    //                 substr($post->post_title, 0, 30),
    //                 $post->post_date,
    //                 $post->post_status,
    //                 $has_meta ? 'YES' : 'NO'
    //             ));
    //         }
    //     }

    //     $this->log_message("=== End Scheduled Posts Debug ===");

    //     return [
    //         'wp_scheduled_count' => count($wp_scheduled),
    //         'direct_sql_count' => count($direct_sql),
    //         'with_meta_count' => count($with_meta),
    //         'today_posts' => count($today_posts)
    //     ];
    // }

    /**
     * Check for available products and queue them for publishing.
     *
     * @since    1.0.0
     */
    public function check_and_queue_products()
    {
        $this->log_message('=== Starting check_and_queue_products with queue population ===');

        if (get_option('pfa_automation_enabled') !== 'yes') {
            $this->log_message('Automation is disabled. Skipping queue check.');
            return;
        }

        try {
            // Get current interval setting
            $interval_minutes = (int)$this->dripfeed_interval;
            $this->log_message("Using dripfeed interval: {$interval_minutes} minutes");

            // Always fetch products to ensure we have fresh data
            $this->log_message('Fetching products from API...');
            $products = $this->api_fetcher->fetch_products(true);

            if (!$products || empty($products)) {
                $this->log_message('ERROR: No products returned from API. Aborting queue check.');
                return;
            }

            $this->log_message('API returned ' . count($products) . ' total products');

            // Calculate available capacity for queue population
            $max_posts = $this->max_posts_per_day;
            $posts_today = $this->get_post_count_today();
            $scheduled_posts_count = $this->get_scheduled_posts_count();
            $total_planned = $posts_today + $scheduled_posts_count;
            $slots_available = $max_posts - $total_planned;

            $this->log_message("Posts today: {$posts_today}, Scheduled: {$scheduled_posts_count}, Total planned: {$total_planned}");
            $this->log_message("Available slots: {$slots_available} (max: {$max_posts})");

            if ($slots_available <= 0) {
                $this->log_message('No slots available for today. Daily limit reached or exceeded.');
                return;
            }

            // Get current queue size
            $queue_manager = PFA_Queue_Manager::get_instance();
            $current_queue = $queue_manager->get_queue();
            $current_queue_size = count($current_queue);

            $this->log_message("Current queue size: {$current_queue_size}");

            // Calculate how many products we need to add to queue
            // We want to maintain a reasonable queue size (e.g., 2-3 days worth of posts)
            $target_queue_size = min($slots_available * 2, 50); // Max 50 items in queue
            $products_needed = max(0, $target_queue_size - $current_queue_size);

            if ($products_needed <= 0) {
                $this->log_message("Queue is adequately populated. Current: {$current_queue_size}, Target: {$target_queue_size}");
                return;
            }

            $this->log_message("Need to add {$products_needed} products to queue");

            // Initialize product identifiers if needed
            if (get_option('pfa_product_identifiers') === false) {
                add_option('pfa_product_identifiers', array());
                $this->log_message('Initialized product identifiers option (was missing)');
            }

            $existing_identifiers = get_option('pfa_product_identifiers', array());
            $this->log_message('Loaded ' . count($existing_identifiers) . ' existing product identifiers');

            // Group products by advertiser for diversity
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

            // Select products for queue using round-robin for diversity
            $eligible_products = array();
            $advertiser_ids = array_keys($products_by_advertiser);
            $advertiser_index = 0;
            $used_products = array();
            $skipped = array('duplicate' => 0, 'exists' => 0);

            // Round-robin through advertisers to ensure diversity
            while (count($eligible_products) < $products_needed && $advertiser_index < 1000) { // Safety limit
                $current_advertiser = $advertiser_ids[$advertiser_index % $advertisers_count];

                // Find next eligible product from this advertiser
                $found_product = false;
                foreach ($products_by_advertiser[$current_advertiser] as $key => $product) {
                    // Skip if already used
                    if (in_array($product['id'], $used_products)) {
                        continue;
                    }

                    $identifier_hashes = $queue_manager->get_identifier_hashes($product);
                    if (empty($identifier_hashes)) {
                        continue;
                    }

                    $already_processed = false;
                    foreach ($identifier_hashes as $hash) {
                        if (in_array($hash, $existing_identifiers, true)) {
                            $already_processed = true;
                            break;
                        }
                    }

                    if ($already_processed) {
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

                    // This product is eligible for queue
                    $eligible_products[] = array(
                        'product' => $product,
                        'hashes' => $identifier_hashes,
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

            $this->log_message("Selected " . count($eligible_products) . " products for queue");
            $this->log_message("Skipped - duplicate: {$skipped['duplicate']}, exists: {$skipped['exists']}");

            if (empty($eligible_products)) {
                $this->log_message("No eligible products found to add to queue");
                return;
            }

            // Add products to queue
            $added_count = 0;
            foreach ($eligible_products as $item) {
                $product = $item['product'];
                $identifier_hashes = $item['hashes'];

                $this->log_message("Adding product ID: {$product['id']} to queue");

                if ($queue_manager->add_to_queue($product)) {
                    // Add to in-memory identifiers to prevent duplicates during this run
                    $existing_identifiers = array_merge($existing_identifiers, $identifier_hashes);
                    $existing_identifiers = array_values(array_unique($existing_identifiers));
                    $added_count++;
                } else {
                    $this->log_message("Failed to add product ID: {$product['id']} to queue");
                }
            }

            $this->log_message("Successfully added {$added_count} products to queue");

            // Force refresh of queue status
            $queue_manager->clear_status_cache();

            // Schedule next queue population check if needed
            $final_queue_size = count($queue_manager->get_queue());
            if ($final_queue_size < $target_queue_size) {
                // Schedule retry in 1 hour if queue is still not full
                $retry_time = time() + HOUR_IN_SECONDS;
                wp_schedule_single_event($retry_time, 'pfa_daily_check');
                $this->log_message("Scheduled queue refill check in 1 hour");
            }
        } catch (Exception $e) {
            $this->log_message('ERROR in queue population: ' . $e->getMessage());
            $this->log_message('Stack trace: ' . $e->getTraceAsString());
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
        // Skip cleanup to avoid removing valid dedupe entries we cannot
        // safely reconstruct from post meta (queue uses id|gtin|mpn).
        $existing_identifiers = get_option('pfa_product_identifiers', array());
        $count = is_array($existing_identifiers) ? count($existing_identifiers) : 0;
        $this->log_message('Identifier cleanup skipped to preserve dedupe list (current count: ' . $count . ').');
        return;
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
