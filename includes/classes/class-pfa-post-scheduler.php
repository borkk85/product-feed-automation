<?php
/**
 * Manages post scheduling and publishing.
 *
 * @since      1.0.0
 * @package    Product_Feed_Automation
 */

class PFA_Post_Scheduler {

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
    public static function get_instance() {
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
    protected function __construct() {
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
     * Register WordPress hooks.
     *
     * @since    1.0.0
     * @access   private
     */
    private function register_hooks() {
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
    public function handle_dripfeed_publish() {
        $this->log_message('=== Starting Dripfeed Publish - SIMPLIFIED VERSION ===');
    
        if (get_option('pfa_automation_enabled') !== 'yes') {
            $this->log_message('Automation is disabled. Dripfeed publishing skipped.');
            return;
        }
    
        try {
            // Basic checks
            $timezone = new DateTimeZone(wp_timezone_string());
            $current_time = new DateTime('now', $timezone);
            $hour = (int)$current_time->format('H');
            
            // Skip during restricted hours
            if ($hour < 6) {
                $this->log_message("Restricted hours (00:00-06:00). Pausing dripfeed.");
                return;
            }
    
            // Check post limits
            $posts_today = $this->get_post_count_today();
            $max_posts = (int)get_option('max_posts_per_day', 10);
            
            $this->log_message("Current post count for today: {$posts_today} (max: {$max_posts})");
            
            if ($posts_today >= $max_posts) {
                $this->log_message('Daily post limit reached. Skipping dripfeed publish.');
                return;
            }
    
            // Fetch the next product
            $product = $this->queue_manager->get_next_queued_product();
            
            if (!$product) {
                $this->log_message('No products in queue. Checking for new products.');
                
                // Force a product check
                $api_fetcher = PFA_API_Fetcher::get_instance();
                $products = $api_fetcher->fetch_products(true);
                
                if ($products && count($products) > 0) {
                    $this->log_message('Found ' . count($products) . ' products, attempting to queue them');
                    $this->check_and_queue_products();
                    $product = $this->queue_manager->get_next_queued_product();
                } else {
                    $this->log_message('No products available from API');
                }
            }
    
            if ($product) {
                $this->log_message('Processing product ID: ' . $product['id']);
                
                // Get advertiser data
                $advertisers = $this->api_fetcher->fetch_advertisers();
                $advertiser_data = isset($product['advertiserId']) && isset($advertisers[$product['advertiserId']]) ? 
                    $advertisers[$product['advertiserId']] : null;
                
                if (!$advertiser_data) {
                    $this->log_message('No advertiser data found for ID: ' . (isset($product['advertiserId']) ? $product['advertiserId'] : 'unknown'));
                }
                
                // Check if product already exists
                $existing = $this->post_creator->check_if_already_in_db($product['trackingLink']);
                
                if ($existing) {
                    $this->log_message("Product already exists as post ID: {$existing}");
                    $this->schedule_next_dripfeed();
                    return;
                }
                
                // Calculate next publication time - simple approach
                $next_time = clone $current_time;
                $interval_minutes = (int)get_option('dripfeed_interval', 120);
                $next_time->modify("+{$interval_minutes} minutes");
                
                // Ensure not after 23:00
                $end_of_day = (clone $current_time)->setTime(23, 0, 0);
                if ($next_time > $end_of_day) {
                    $next_time = (clone $current_time)->modify('+15 minutes');
                    $this->log_message('Adjusted next time to avoid scheduling past 23:00');
                }
                
                $this->log_message('Scheduling post for: ' . $next_time->format('Y-m-d H:i:s'));
                
                // Create post data
                $post_data = array(
                    'post_status' => 'future',
                    'post_date' => $next_time->format('Y-m-d H:i:s'),
                    'post_date_gmt' => get_gmt_from_date($next_time->format('Y-m-d H:i:s')),
                );
                
                // Create the post
                $post_creator = PFA_Post_Creator::get_instance();
                
                // Detailed logging before post creation
                $this->log_message('Creating post with data: ' . json_encode($post_data));
                $this->log_message('Product data: ' . json_encode($product));
                
                // Actually create the post
                $result = $post_creator->create_product_post($product, $advertiser_data, $post_data);
                
                if ($result) {
                    if (is_array($result) && isset($result['status'])) {
                        $this->log_message("Post creation result: " . $result['status']);
                        
                        if ($result['status'] === 'exists') {
                            $this->log_message("Product already exists as post ID: " . $result['post_id']);
                        }
                    } else {
                        $post_id = is_array($result) ? $result['post_id'] : $result;
                        $this->log_message("Post created successfully. Post ID: {$post_id}");
                        
                        // Verify the post exists and is scheduled
                        $post = get_post($post_id);
                        if ($post) {
                            $this->log_message("Verified post {$post_id} exists with status: {$post->post_status} and date: {$post->post_date}");
                        } else {
                            $this->log_message("WARNING: Created post {$post_id} not found in database");
                        }
                    }
                } else {
                    $this->log_message("ERROR: Post creation failed");
                }
                
                // Always schedule the next dripfeed
                $this->schedule_next_dripfeed();
            } else {
                $this->log_message('No eligible products found for publishing.');
                $this->schedule_next_dripfeed();
            }
        } catch (Exception $e) {
            $this->log_message('Error in handle_dripfeed_publish: ' . $e->getMessage());
            $this->log_message('Stack trace: ' . $e->getTraceAsString());
            
            // Ensure next dripfeed is scheduled even on error
            try {
                $this->schedule_next_dripfeed();
            } catch (Exception $inner_e) {
                $this->log_message('Error scheduling next dripfeed: ' . $inner_e->getMessage());
                
                // Last resort - manually schedule next run
                $next_run = time() + 1800; // 30 minutes
                wp_schedule_single_event($next_run, 'pfa_dripfeed_publisher');
                $this->log_message('Forced scheduling of next dripfeed for 30 minutes from now');
            }
        }
    }

    /**
     * Handle API check cron event.
     *
     * @since    1.0.0
     */
    public function handle_api_check() {
        $current_action = current_action();
        $allowed_actions = array('pfa_api_check', 'pfa_dripfeed_publisher');
        
        if (!in_array($current_action, $allowed_actions)) {
            $this->log_message('Unauthorized handle_api_check call attempted from: ' . $current_action);
            return;
        }

        $this->log_message('=== Starting API Check from: ' . $current_action . ' ===');

        try {
            // Get categories
            $active_cat = get_term_by('slug', 'deals', 'category');
            $archive_cat = get_term_by('slug', 'archived-deals', 'category');

            if (!$active_cat || !$archive_cat) {
                $this->log_message('Required categories not found - deals and/or archive-deals');
                return;
            }

            // Get current minimum discount and calculate check range
            $current_min_discount = get_option('min_discount', 0);
            $check_range_min = max(0, $current_min_discount - 10);
            
            $this->log_message(sprintf('Current minimum discount: %d%%, Checking range: %d%% to 100%%', 
                $current_min_discount, $check_range_min));

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
            
            $this->log_message(sprintf('Built product lookup with %d products in %d%%-100%% range', 
                count($product_lookup), $check_range_min));

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
                        'key' => '_Amazone_produt_baseName',
                        'compare' => 'EXISTS',
                    )
                )
            ));

            $archived_count = 0;
            $checked_count = 0;

            // Check active posts for archiving
            foreach ($active_posts as $post) {
                $product_id = get_post_meta($post->ID, '_Amazone_produt_baseName', true);
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
                        'key' => '_Amazone_produt_baseName',
                        'compare' => 'EXISTS',
                    )
                )
            ));

            $reactivated_count = 0;

            foreach ($archived_posts as $post) {
                $product_id = get_post_meta($post->ID, '_Amazone_produt_baseName', true);
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

                $actually_eligible = array_filter($eligible_products, function($product) {
                    $product_identifier = md5(
                        $product['id'] . '|' . 
                        (isset($product['gtin']) ? $product['gtin'] : '') . '|' . 
                        (isset($product['mpn']) ? $product['mpn'] : '')
                    );
                    
                    if (in_array($product_identifier, get_option('pfa_product_identifiers', array()))) {
                        return false;
                    }
                    
                    if ($this->post_creator->check_if_already_in_db($product['trackingLink'])) {
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
    private function archive_post($post_id, $archive_category_id) {
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
    private function reactivate_post($post_id, $active_category_id, $product_info) {
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
    private function calculate_next_publish_time() {
        $timezone = new DateTimeZone(wp_timezone_string());
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

    /**
     * Check for available products and queue them for publishing.
     *
     * @since    1.0.0
     */
    public function check_and_queue_products() {
        $this->log_message('=== Starting check_and_queue_products with DETAILED LOGGING ===');
    
        if (get_option('pfa_automation_enabled') !== 'yes') {
            $this->log_message('Automation is disabled. Skipping queue check.');
            return;
        }
    
        try {
            // Fetch and analyze products
            $this->log_message('Fetching products from API...');
            $products = $this->api_fetcher->fetch_products();
            
            if (!$products || empty($products)) {
                $this->log_message('ERROR: No products returned from API. Aborting queue check.');
                return;
            }
            
            $this->log_message('API returned ' . count($products) . ' total products');
            
            // Calculate available slots
            $slots_available = $this->max_posts_per_day - $this->get_post_count_today();
            $this->log_message('Available slots: ' . $slots_available);
            
            if ($slots_available <= 0) {
                $this->log_message('No slots available for today. Daily limit reached or exceeded.');
                return;
            }
            
            // Initialize product identifiers if needed
            if (get_option('pfa_product_identifiers') === false) {
                add_option('pfa_product_identifiers', array());
                $this->log_message('Initialized product identifiers option (was missing)');
            }
    
            $existing_identifiers = get_option('pfa_product_identifiers', array());
            $this->log_message('Loaded ' . count($existing_identifiers) . ' existing product identifiers');
            
            $skipped = array('duplicate' => 0, 'exists' => 0, 'stock' => 0);
            $queued = 0;
            
            // Group products by advertiser first
           $advertiser_groups = array();
            foreach ($products as $product) {
                if (!isset($product['advertiserId'])) {
                    $this->log_message('WARNING: Product missing advertiserId: ' . json_encode($product));
                    continue;
                }
                
                $advertiser_id = $product['advertiserId'];
                if (!isset($advertiser_groups[$advertiser_id])) {
                    $advertiser_groups[$advertiser_id] = array();
                }
                $advertiser_groups[$advertiser_id][] = $product;
            }

            $this->log_message(sprintf("Found %d different advertisers", count($advertiser_groups)));

            // Get list of advertiser IDs and shuffle them for random order
            $advertiser_ids = array_keys($advertiser_groups);
            shuffle($advertiser_ids);

            // Keep track of how many products we've taken from each advertiser
            $advertiser_counts = array_fill_keys($advertiser_ids, 0);
            $max_rounds = 10; // Prevent infinite loops
            $round = 0;
            $remaining_slots = $slots_available;

            $this->log_message(sprintf("Starting distribution for %d advertisers with %d slots", 
                            count($advertiser_ids), $slots_available));

            while ($remaining_slots > 0 && $round < $max_rounds) {
                $added_this_round = false;
                
                foreach ($advertiser_ids as $key => $advertiser_id) {
                    if ($remaining_slots <= 0) break;

                    // Check if the advertiser group exists - advertisers might be removed in previous iterations
                    if (!isset($advertiser_groups[$advertiser_id]) || empty($advertiser_groups[$advertiser_id])) {
                        $this->log_message("No products left for advertiser $advertiser_id, skipping");
                        // Remove from the advertiser IDs array to prevent future iterations
                        unset($advertiser_ids[$key]);
                        continue;
                    }

                    $advertiser_products = $advertiser_groups[$advertiser_id];

                    // Get in-stock variants for this advertiser
                    $in_stock_variants = array_filter($advertiser_products, function($p) {
                        if (!isset($p['availability'])) {
                            $this->log_message('WARNING: Product missing availability: ' . (isset($p['id']) ? $p['id'] : 'unknown'));
                            return false;
                        }
                        return $p['availability'] === 'in_stock';
                    });

                    if (empty($in_stock_variants)) {
                        $skipped['stock'] += count($advertiser_products);
                        $this->log_message("No in-stock products for advertiser $advertiser_id");
                        unset($advertiser_groups[$advertiser_id]); // Remove empty advertiser
                        unset($advertiser_ids[$key]); // Also remove from IDs array
                        continue;
                    }
                    
                    // Sort by discount (highest first)
                    $post_creator = PFA_Post_Creator::get_instance();
                    usort($in_stock_variants, function($a, $b) use ($post_creator) {
                        if (!isset($a['price']) || !isset($a['sale_price']) || 
                            !isset($b['price']) || !isset($b['sale_price'])) {
                            return 0;
                        }
                        
                        $discount_a = $post_creator->calculate_discount($a['price'], $a['sale_price']);
                        $discount_b = $post_creator->calculate_discount($b['price'], $b['sale_price']);
                        
                        return $discount_b - $discount_a;
                    });

                    // Try to find a valid product from this advertiser
                    foreach ($in_stock_variants as $variant_key => $variant) {
                        if ($remaining_slots <= 0) break;
                        
                        if (!isset($variant['id'])) {
                            $this->log_message('WARNING: Product missing ID, skipping');
                            continue;
                        }
                        
                        $variant_identifier = md5(
                            $variant['id'] . '|' . 
                            (isset($variant['gtin']) ? $variant['gtin'] : '') . '|' . 
                            (isset($variant['mpn']) ? $variant['mpn'] : '')
                        );

                        if (in_array($variant_identifier, $existing_identifiers)) {
                            $skipped['duplicate']++;
                            continue;
                        }

                        if (!isset($variant['trackingLink'])) {
                            $this->log_message('WARNING: Product ID ' . $variant['id'] . ' missing trackingLink, skipping');
                            continue;
                        }
                        
                        if ($post_creator->check_if_already_in_db($variant['trackingLink'])) {
                            $skipped['exists']++;
                            continue;
                        }

                        // Found a valid variant to use - add to queue
                        $queue_manager = PFA_Queue_Manager::get_instance();
                        $added = $queue_manager->add_to_queue($variant);
                        
                        if ($added) {
                            $this->log_message(sprintf(
                                "Added product from advertiser %s (ID: %s) to queue. Remaining slots: %d",
                                $advertiser_id,
                                $variant['id'],
                                $remaining_slots - 1
                            ));
                            
                            // Add to existing identifiers to prevent duplicates
                            $existing_identifiers[] = $variant_identifier;
                            update_option('pfa_product_identifiers', $existing_identifiers);
                            
                            $queued++;
                            $remaining_slots--;
                            $advertiser_counts[$advertiser_id]++;
                            $added_this_round = true;
                            
                            // Remove the used product from the array
                            unset($advertiser_groups[$advertiser_id][$variant_key]);
                            // Re-index the array after removing an element
                            $advertiser_groups[$advertiser_id] = array_values($advertiser_groups[$advertiser_id]);
                            
                            break; // Try next advertiser
                        } else {
                            $this->log_message("WARNING: Failed to add product ID " . $variant['id'] . " to queue");
                        }
                    }
                }
                
                // Reindex advertiser_ids array after possible removals
                $advertiser_ids = array_values($advertiser_ids);
                
                $round++;
                $this->log_message(sprintf("Completed round %d with %d products queued", $round, $queued));

                // Only break if we haven't added anything AND we've done at least 2 rounds
                if (!$added_this_round && $round >= 2) {
                    $this->log_message(sprintf("No products added in round %d - breaking distribution loop", $round));
                    break;
                }
            }
        
                // Log distribution of queued posts
                foreach ($advertiser_counts as $advertiser_id => $count) {
                    if ($count > 0) {
                        $this->log_message(sprintf("Advertiser %s: %d products queued", $advertiser_id, $count));
                    }
                }
        
                $this->log_message(sprintf(
                    'Queue summary: %d queued (of %d slots), %d duplicates, %d existing, %d out of stock, %d active advertisers',
                    $queued,
                    $slots_available,
                    $skipped['duplicate'],
                    $skipped['exists'],
                    $skipped['stock'],
                    count(array_filter($advertiser_counts))
                ));
                
                // Force refresh of queue status after we add items
                $queue_manager = PFA_Queue_Manager::get_instance();
                $queue_manager->clear_status_cache();
                
                // If we successfully queued items, make sure the dripfeed is scheduled
                if ($queued > 0) {
                    $this->log_message("Successfully queued $queued products - ensuring dripfeed is scheduled");
                    $this->schedule_next_dripfeed();
                }
        
            } catch (Exception $e) {
                $this->log_message('ERROR in check_and_queue_products: ' . $e->getMessage());
                $this->log_message('Stack trace: ' . $e->getTraceAsString());
            }
        }

    /**
     * Schedule the next dripfeed event.
     *
     * @since    1.0.0
     * @access   private
     */
    private function schedule_next_dripfeed() {
        $this->log_message('=== Scheduling Next Dripfeed ===');

        try {
            wp_cache_flush();
            clean_post_cache(0);
            
            $next_time = $this->calculate_next_publish_time();
            $this->log_message('Next calculated time: ' . ($next_time ? $next_time->format('Y-m-d H:i:s') : 'null'));
            
            // If no valid time returned, schedule for tomorrow at 6 AM
            if ($next_time === null) {
                $timezone = new DateTimeZone(wp_timezone_string());
                $next_time = new DateTime('tomorrow 06:00:00', $timezone);
                $this->log_message('Cannot schedule more posts today. Scheduling for tomorrow at 6 AM');
                $this->log_message('calculate_next_publish_time returned null - checking why:');
                $this->log_message('Posts today: ' . $this->get_post_count_today());
            }
            
            wp_clear_scheduled_hook('pfa_dripfeed_publisher');
            wp_schedule_single_event(
                $next_time->getTimestamp(),
                'pfa_dripfeed_publisher'
            );

            $this->log_message("Scheduled next dripfeed for: " . $next_time->format('Y-m-d H:i:s T'));
            
            $next_scheduled = wp_next_scheduled('pfa_dripfeed_publisher');
            if ($next_scheduled) {
                $this->log_message("Verified next schedule: " . date('Y-m-d H:i:s T', $next_scheduled));
            } else {
                $this->log_message("Warning: Failed to verify next schedule");
            }
        } catch (Exception $e) {
            $this->log_message("Error scheduling next dripfeed: " . $e->getMessage());
        }
    }

    /**
     * Get count of posts published today.
     *
     * @since    1.0.0
     * @return   int    Number of posts published today.
     */
    public function get_post_count_today() {
        $timezone = new DateTimeZone(wp_timezone_string());
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
    public function add_custom_schedules($schedules) {
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
    public function initialize_schedules() {
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

            // Schedule the first dripfeed
            if (!wp_next_scheduled('pfa_dripfeed_publisher')) {
                $next_time = $this->calculate_next_publish_time();
            
                if ($next_time === null) {
                    $this->log_message("Could not determine next publish time - falling back to tomorrow at 06:00.");
                    $timezone = new DateTimeZone(wp_timezone_string());
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
    private function verify_schedules() {
        $next_daily = wp_next_scheduled('pfa_daily_check');
        $next_dripfeed = wp_next_scheduled('pfa_dripfeed_publisher');

        $this->log_message('Schedule verification:');
        $this->log_message('- Daily check: ' . ($next_daily ? date('Y-m-d H:i:s', $next_daily) : 'not scheduled'));
        $this->log_message('- Dripfeed: ' . ($next_dripfeed ? date('Y-m-d H:i:s', $next_dripfeed) : 'not scheduled'));
    }

    /**
     * Clear all cron schedules related to this plugin.
     *
     * @since    1.0.0
     */
    public function clear_all_schedules() {
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
    public function clean_stale_identifiers() {
        global $wpdb;

        $existing_identifiers = get_option('pfa_product_identifiers', array());
        if (empty($existing_identifiers)) {
            $this->log_message('No identifiers to clean.');
            return;
        }

        $this->log_message('Starting identifier cleanup. Current count: ' . count($existing_identifiers));

        $cleaned_identifiers = array();

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
                $this->log_message("Removing stale identifier: $identifier");
            }
        }

        update_option('pfa_product_identifiers', $cleaned_identifiers);
        $this->log_message(sprintf(
            'Identifier cleanup complete. Before: %d, After: %d',
            count($existing_identifiers),
            count($cleaned_identifiers)
        ));
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
            error_log('[PFA Scheduler] ' . $message);
        }
    }
}