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
    /**
 * Handle dripfeed publisher cron event.
 *
 * @since    1.0.0
 */
public function handle_dripfeed_publish() {
    $this->log_message('=== Starting Dripfeed Publish ===');
    
    // Use try/finally to ensure lock is always released
    try {
        if (get_option('pfa_automation_enabled') !== 'yes') {
            $this->log_message('Automation is disabled. Dripfeed publishing skipped.');
            return;
        }
    
        // Check for restricted hours (00:00-06:00)
        $timezone = new DateTimeZone(wp_timezone_string());
        $current_time = new DateTime('now', $timezone);
        $hour = (int)$current_time->format('H');
        
        $this->log_message("Current hour: $hour in timezone " . $timezone->getName());
        
        if ($hour >= 0 && $hour < 6) {
            $this->log_message("Restricted hours (00:00-06:00). Pausing dripfeed.");
            return;
        }
    
        // Check daily post limits
        $max_posts = $this->max_posts_per_day;
        $posts_today = $this->get_post_count_today();
        
        if ($posts_today >= $max_posts) {
            $this->log_message("Daily post limit reached ({$posts_today}/{$max_posts}). Scheduling for tomorrow.");
            
            // Schedule for tomorrow at 6 AM
            $tomorrow = new DateTime('tomorrow 06:00:00', $timezone);
            wp_clear_scheduled_hook('pfa_dripfeed_publisher');
            wp_schedule_single_event($tomorrow->getTimestamp(), 'pfa_dripfeed_publisher');
            
            return;
        }
        
        $this->log_message("Current post count: {$posts_today}/{$max_posts}");
        
        // Calculate available slots
        $slots_available = $max_posts - $posts_today;
        
        // Check the queue
        $queue = $this->queue_manager->get_queue();
        $queue_size = count($queue);
        
        // If it's 6 AM and no posts yet, or queue is empty, check for new products
        if (($hour == 6 && $posts_today == 0) || $queue_size == 0) {
            $this->log_message("Queue empty or 6 AM with no posts. Checking for new products.");
            $this->check_and_queue_products();
            
            // The check_and_queue_products method now handles scheduling directly
            // So we can just exit here as it will have scheduled posts if products were found
            return;
        }
        
        // Get current scheduled post count
        global $wpdb;
        $scheduled_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_date 
                FROM {$wpdb->posts} 
                WHERE post_type = 'post' 
                AND post_status = 'future' 
                AND post_date > %s 
                ORDER BY post_date ASC",
                $current_time->format('Y-m-d H:i:s')
            )
        );
        
        $scheduled_count = count($scheduled_posts);
        
        if ($scheduled_count > 0) {
            $last_scheduled = end($scheduled_posts);
            $last_time = new DateTime($last_scheduled->post_date, $timezone);
            
            $this->log_message("Found {$scheduled_count} scheduled posts. Last one at: " . $last_time->format('Y-m-d H:i:s'));
            
            // If we have scheduled posts, check if we need more
            if ($scheduled_count + $posts_today < $max_posts && $hour < 22) {
                $this->log_message("Only {$scheduled_count} posts scheduled. We need more.");
                
                // If queue is not empty, schedule more posts
                if ($queue_size > 0) {
                    // Start scheduling after the last scheduled post
                    $next_time = clone $last_time;
                    $next_time->modify('+30 minutes');
                    
                    // Calculate end of day
                    $end_of_day = clone $current_time;
                    $end_of_day->setTime(23, 0, 0);
                    
                    // Calculate remaining slots and time
                    $remaining_slots = $max_posts - ($posts_today + $scheduled_count);
                    $remaining_minutes = ($end_of_day->getTimestamp() - $next_time->getTimestamp()) / 60;
                    
                    // Don't schedule more than available slots or queue size
                    $to_schedule = min($remaining_slots, $queue_size);
                    
                    // Calculate interval - minimum 30 minutes, maximum 120 minutes
                    $interval_minutes = max(30, min(120, floor($remaining_minutes / $to_schedule)));
                    
                    $this->log_message("Scheduling {$to_schedule} additional posts with {$interval_minutes} minute intervals");
                    
                    $scheduled_more = 0;
                    $used_identifiers = array();
                    $advertisers = $this->api_fetcher->fetch_advertisers();
                    
                    for ($i = 0; $i < $to_schedule; $i++) {
                        $product = $this->queue_manager->get_next_queued_product();
                        
                        if (!$product) {
                            $this->log_message("Queue empty. Scheduled {$scheduled_more} additional posts.");
                            break;
                        }
                        
                        $advertiser_data = isset($product['advertiserId']) && isset($advertisers[$product['advertiserId']]) ? 
                            $advertisers[$product['advertiserId']] : null;
                        
                        if (!$this->post_creator->check_if_already_in_db($product['trackingLink'])) {
                            // Schedule for future
                            $post_data = array(
                                'post_status' => 'future',
                                'post_date' => $next_time->format('Y-m-d H:i:s'),
                                'post_date_gmt' => get_gmt_from_date($next_time->format('Y-m-d H:i:s'))
                            );
                            
                            $post_id = $this->post_creator->create_product_post($product, $advertiser_data, $post_data);
                            
                            if ($post_id && !is_wp_error($post_id)) {
                                $scheduled_more++;
                                $this->log_message("Scheduled additional post ID: {$post_id} for {$next_time->format('Y-m-d H:i:s')}");
                                
                                // Generate unique identifier to track
                                $variant_identifier = md5(
                                    $product['id'] . '|' . 
                                    (isset($product['gtin']) ? $product['gtin'] : '') . '|' . 
                                    (isset($product['mpn']) ? $product['mpn'] : '')
                                );
                                $used_identifiers[] = $variant_identifier;
                                
                                // Calculate time for next post
                                $next_time = clone $next_time;
                                $next_time->modify("+{$interval_minutes} minutes");
                                
                                // Don't schedule past 11 PM
                                if ($next_time->format('H') >= 23) {
                                    $this->log_message("Reached end of scheduling window for today");
                                    break;
                                }
                            } else {
                                $this->log_message("Failed to schedule additional post for product ID: {$product['id']}");
                                
                                // Try again with a different product
                                $i--; // Don't count this attempt
                                continue;
                            }
                        } else {
                            $this->log_message("Product already exists in database: {$product['id']}. Skipping.");
                            
                            // Try again with a different product
                            $i--; // Don't count this attempt
                            continue;
                        }
                    }
                    
                    // Update product identifiers
                    if (!empty($used_identifiers)) {
                        $existing_identifiers = get_option('pfa_product_identifiers', array());
                        $existing_identifiers = array_merge($existing_identifiers, $used_identifiers);
                        update_option('pfa_product_identifiers', $existing_identifiers);
                    }
                    
                    $this->log_message("Scheduled {$scheduled_more} additional posts");
                    
                    // Force status refresh
                    $this->queue_manager->clear_status_cache();
                    
                    // Schedule next dripfeed after the last scheduled post
                    if ($next_time->format('H') < 23) {
                        wp_clear_scheduled_hook('pfa_dripfeed_publisher');
                        wp_schedule_single_event($next_time->getTimestamp(), 'pfa_dripfeed_publisher');
                        $this->log_message("Scheduled next dripfeed for: " . $next_time->format('Y-m-d H:i:s T'));
                    } else {
                        // If we've reached the end of day, schedule for tomorrow
                        $tomorrow = new DateTime('tomorrow 06:00:00', $timezone);
                        wp_clear_scheduled_hook('pfa_dripfeed_publisher');
                        wp_schedule_single_event($tomorrow->getTimestamp(), 'pfa_dripfeed_publisher');
                        $this->log_message("End of day reached. Scheduled next check for tomorrow 6 AM.");
                    }
                } else {
                    $this->log_message("Queue is empty. Running product check.");
                    $this->check_and_queue_products();
                }
            } else {
                $this->log_message("Enough posts scheduled ({$scheduled_count}) or too late in day (hour: {$hour}). No need for more.");
                
                // Schedule next dripfeed for tomorrow
                $tomorrow = new DateTime('tomorrow 06:00:00', $timezone);
                wp_clear_scheduled_hook('pfa_dripfeed_publisher');
                wp_schedule_single_event($tomorrow->getTimestamp(), 'pfa_dripfeed_publisher');
                $this->log_message("Scheduled next check for tomorrow 6 AM.");
            }
        } else {
            // If no scheduled posts, we need to schedule some
            $this->log_message("No scheduled posts found. Need to schedule some.");
            
            // If queue has items, schedule directly
            if ($queue_size > 0) {
                $this->log_message("Queue has {$queue_size} products. Scheduling directly.");
                
                // Calculate end of day
                $end_of_day = clone $current_time;
                $end_of_day->setTime(23, 0, 0);
                $remaining_minutes = ($end_of_day->getTimestamp() - $current_time->getTimestamp()) / 60;
                
                // Don't schedule more than available slots or queue size
                $to_schedule = min($slots_available, $queue_size, 5); // Max 5 at once
                
                // Calculate interval - minimum 30 minutes, maximum 120 minutes
                $interval_minutes = max(30, min(120, floor($remaining_minutes / $to_schedule)));
                
                // Get next time for first post
                $next_time = $this->calculate_next_publish_time();
                if (!$next_time) {
                    $next_time = clone $current_time;
                    $next_time->modify('+30 minutes');
                }
                
                $this->log_message("Scheduling {$to_schedule} posts with {$interval_minutes} minute intervals");
                
                $scheduled_count = 0;
                $used_identifiers = array();
                $advertisers = $this->api_fetcher->fetch_advertisers();
                
                for ($i = 0; $i < $to_schedule; $i++) {
                    $product = $this->queue_manager->get_next_queued_product();
                    
                    if (!$product) {
                        $this->log_message("Queue empty. Scheduled {$scheduled_count} posts.");
                        break;
                    }
                    
                    $advertiser_data = isset($product['advertiserId']) && isset($advertisers[$product['advertiserId']]) ? 
                        $advertisers[$product['advertiserId']] : null;
                    
                    if (!$this->post_creator->check_if_already_in_db($product['trackingLink'])) {
                        // Schedule for future
                        $post_data = array(
                            'post_status' => 'future',
                            'post_date' => $next_time->format('Y-m-d H:i:s'),
                            'post_date_gmt' => get_gmt_from_date($next_time->format('Y-m-d H:i:s'))
                        );
                        
                        $post_id = $this->post_creator->create_product_post($product, $advertiser_data, $post_data);
                        
                        if ($post_id && !is_wp_error($post_id)) {
                            $scheduled_count++;
                            $this->log_message("Scheduled post ID: {$post_id} for {$next_time->format('Y-m-d H:i:s')}");
                            
                            // Generate unique identifier to track
                            $variant_identifier = md5(
                                $product['id'] . '|' . 
                                (isset($product['gtin']) ? $product['gtin'] : '') . '|' . 
                                (isset($product['mpn']) ? $product['mpn'] : '')
                            );
                            $used_identifiers[] = $variant_identifier;
                            
                            // Calculate time for next post
                            $next_time = clone $next_time;
                            $next_time->modify("+{$interval_minutes} minutes");
                            
                            // Don't schedule past 11 PM
                            if ($next_time->format('H') >= 23) {
                                $this->log_message("Reached end of scheduling window for today");
                                break;
                            }
                        } else {
                            $this->log_message("Failed to schedule post for product ID: {$product['id']}");
                            
                            // Try again with a different product
                            $i--; // Don't count this attempt
                            continue;
                        }
                    } else {
                        $this->log_message("Product already exists in database: {$product['id']}. Skipping.");
                        
                        // Try again with a different product
                        $i--; // Don't count this attempt
                        continue;
                    }
                }
                
                // Update product identifiers
                if (!empty($used_identifiers)) {
                    $existing_identifiers = get_option('pfa_product_identifiers', array());
                    $existing_identifiers = array_merge($existing_identifiers, $used_identifiers);
                    update_option('pfa_product_identifiers', $existing_identifiers);
                }
                
                $this->log_message("Scheduled {$scheduled_count} posts");
                
                // Force status refresh
                $this->queue_manager->clear_status_cache();
                
                // Schedule next dripfeed
                if ($next_time->format('H') < 23 && $scheduled_count + $posts_today < $max_posts) {
                    wp_clear_scheduled_hook('pfa_dripfeed_publisher');
                    wp_schedule_single_event($next_time->getTimestamp(), 'pfa_dripfeed_publisher');
                    $this->log_message("Scheduled next dripfeed for: " . $next_time->format('Y-m-d H:i:s T'));
                } else {
                    // If we've reached the end of day or max posts, schedule for tomorrow
                    $tomorrow = new DateTime('tomorrow 06:00:00', $timezone);
                    wp_clear_scheduled_hook('pfa_dripfeed_publisher');
                    wp_schedule_single_event($tomorrow->getTimestamp(), 'pfa_dripfeed_publisher');
                    $this->log_message("End of day or max posts reached. Scheduled next check for tomorrow 6 AM.");
                }
            } else {
                $this->log_message("Queue is empty. Running product check.");
                $this->check_and_queue_products();
            }
        }
    } catch (Exception $e) {
        $this->log_message('ERROR in handle_dripfeed_publish: ' . $e->getMessage());
        $this->log_message('Stack trace: ' . $e->getTraceAsString());
        
        // Schedule another run to recover from error
        wp_clear_scheduled_hook('pfa_dripfeed_publisher');
        wp_schedule_single_event(time() + 300, 'pfa_dripfeed_publisher');
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
    $max_posts = $this->max_posts_per_day;

    // Check post count first
    $posts_today = $this->get_post_count_today();
    if ($posts_today >= $max_posts) {
        $this->log_message(sprintf(
            'Daily limit reached (%d/%d posts). Cannot schedule more posts today.',
            $posts_today,
            $max_posts
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

    // Calculate the number of remaining hours in the day (from now until 11 PM)
    $end_of_day = clone $now;
    $end_of_day->setTime(23, 0, 0);
    $remaining_minutes = ($end_of_day->getTimestamp() - $now->getTimestamp()) / 60;
    
    // Calculate how many more posts we can publish today
    $remaining_posts = $max_posts - $posts_today;
    
    // Calculate a dynamic interval based on remaining time and posts
    // Minimum 30 minutes, maximum 120 minutes
    $dynamic_interval = max(30, min(120, floor($remaining_minutes / $remaining_posts)));
    
    $this->log_message(sprintf(
        'Dynamic interval calculation: %d minutes (%d posts remaining over %d minutes)',
        $dynamic_interval,
        $remaining_posts,
        $remaining_minutes
    ));

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
        
        // Calculate next time from last scheduled, using dynamic interval
        $next_time = clone $last_time;
        $next_time->modify("+{$dynamic_interval} minutes");

        // If next time would be past 23:00, we can't schedule more today
        $end_of_day = (clone $now)->setTime(23, 0, 0);
        if ($next_time > $end_of_day) {
            $this->log_message('Cannot schedule more posts today - would exceed end of day (23:00)');
            return null;
        }

        return $next_time;
    }

    // If no future posts, start from now with dynamic interval
    $next_time = clone $now;
    $next_time->modify("+{$dynamic_interval} minutes");
    
    // Check if we'd be scheduling past 23:00
    $end_of_day = (clone $now)->setTime(23, 0, 0);
    if ($next_time > $end_of_day) {
        $this->log_message('Cannot schedule more posts today - would exceed end of day (23:00)');
        return null;
    }

    $this->log_message(sprintf(
        "Next publish time calculated: %s (Dynamic Interval: %d minutes)",
        $next_time->format('Y-m-d H:i:s'),
        $dynamic_interval
    ));

    return $next_time;
}

    public function debug_scheduled_posts() {
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
                $required_meta = ['_pfa_v2_post', '_Amazone_produt_baseName', '_product_url', 'dynamic_amazone_link'];
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
        $timezone = new DateTimeZone(wp_timezone_string());
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
    public function check_and_queue_products() {
    $this->log_message('=== Starting check_and_queue_products ===');

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
        $max_posts = $this->max_posts_per_day;
        $posts_today = $this->get_post_count_today();
        $slots_available = $max_posts - $posts_today;
        $this->log_message("Available slots: {$slots_available} (max: {$max_posts}, today: {$posts_today})");
        
        if ($slots_available <= 0) {
            $this->log_message('No slots available for today. Daily limit reached or exceeded.');
            return;
        }
        
        // Get timezone and current time
        $timezone = new DateTimeZone(wp_timezone_string());
        $now = new DateTime('now', $timezone);
        $current_hour = (int)$now->format('H');
        
        // Working hours: 6 AM to 11 PM (17 hours = 1020 minutes)
        $working_minutes = 17 * 60;
        $ideal_interval = max(30, min(120, floor($working_minutes / $max_posts)));
        
        $this->log_message("Target interval: {$ideal_interval} minutes between posts");
        
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
            
            // Skip products without basic required fields
            if (!isset($product['id']) || !isset($product['advertiserId']) || 
                !isset($product['price']) || !isset($product['sale_price']) || 
                !isset($product['trackingLink'])) {
                continue;
            }
            
            // Get discount percentage
            $discount = $this->post_creator->calculate_discount($product['price'], $product['sale_price']);
            
            // Skip products with insufficient discount
            $min_discount = get_option('min_discount', 0);
            if ($discount < $min_discount) {
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
        
        // If no advertisers with eligible products, exit
        if ($advertisers_count === 0) {
            $this->log_message("No eligible products found to queue");
            return;
        }
        
        // Get list of advertiser IDs and shuffle to randomize
        $advertiser_ids = array_keys($products_by_advertiser);
        shuffle($advertiser_ids);
        $this->log_message("Shuffled advertiser IDs for diversity");
        
        // Prepare tracking arrays
        $selected_products = array();
        $advertiser_usage = array();
        
        // First pass: Try to get at least one product from each advertiser
        foreach ($advertiser_ids as $advertiser_id) {
            // Stop if we've reached the slots limit
            if (count($selected_products) >= $slots_available) {
                break;
            }
            
            $advertiser_products = $products_by_advertiser[$advertiser_id];
            
            // Sort by discount (highest first)
            usort($advertiser_products, function($a, $b) {
                $discount_a = $this->post_creator->calculate_discount($a['price'], $a['sale_price']);
                $discount_b = $this->post_creator->calculate_discount($b['price'], $b['sale_price']);
                return $discount_b - $discount_a;
            });
            
            // Find an eligible product from this advertiser
            foreach ($advertiser_products as $product) {
                // Generate unique identifier
                $variant_identifier = md5(
                    $product['id'] . '|' . 
                    (isset($product['gtin']) ? $product['gtin'] : '') . '|' . 
                    (isset($product['mpn']) ? $product['mpn'] : '')
                );
                
                // Skip if already used
                if (in_array($variant_identifier, $existing_identifiers)) {
                    continue;
                }
                
                // Skip if already in database
                if ($this->post_creator->check_if_already_in_db($product['trackingLink'])) {
                    continue;
                }
                
                // Add this product to the selection
                $selected_products[] = array(
                    'product' => $product,
                    'identifier' => $variant_identifier,
                    'advertiser_id' => $advertiser_id
                );
                
                // Track that we used this advertiser
                if (!isset($advertiser_usage[$advertiser_id])) {
                    $advertiser_usage[$advertiser_id] = 0;
                }
                $advertiser_usage[$advertiser_id]++;
                
                $this->log_message("Selected product ID: {$product['id']} from advertiser: {$advertiser_id}");
                
                // Only take one product per advertiser in first pass
                break;
            }
        }
        
        if (count($selected_products) < $slots_available && count($advertiser_usage) < count($advertiser_ids)) {
            $this->log_message("Second pass to fill remaining slots");
            
            // Get the advertisers we haven't used yet
            $unused_advertisers = array_diff($advertiser_ids, array_keys($advertiser_usage));
            
            foreach ($unused_advertisers as $advertiser_id) {
                // Stop if we've reached the slots limit
                if (count($selected_products) >= $slots_available) {
                    break;
                }
                
                $advertiser_products = $products_by_advertiser[$advertiser_id];
                
                // Sort by discount (highest first)
                usort($advertiser_products, function($a, $b) {
                    $discount_a = $this->post_creator->calculate_discount($a['price'], $a['sale_price']);
                    $discount_b = $this->post_creator->calculate_discount($b['price'], $b['sale_price']);
                    return $discount_b - $discount_a;
                });
                
                // Find an eligible product from this advertiser
                foreach ($advertiser_products as $product) {
                    // Generate unique identifier
                    $variant_identifier = md5(
                        $product['id'] . '|' . 
                        (isset($product['gtin']) ? $product['gtin'] : '') . '|' . 
                        (isset($product['mpn']) ? $product['mpn'] : '')
                    );
                    
                    // Skip if already used
                    if (in_array($variant_identifier, $existing_identifiers)) {
                        continue;
                    }
                    
                    // Skip if already in database
                    if ($this->post_creator->check_if_already_in_db($product['trackingLink'])) {
                        continue;
                    }
                    
                    // Add this product to the selection
                    $selected_products[] = array(
                        'product' => $product,
                        'identifier' => $variant_identifier,
                        'advertiser_id' => $advertiser_id
                    );
                    
                    // Track that we used this advertiser
                    if (!isset($advertiser_usage[$advertiser_id])) {
                        $advertiser_usage[$advertiser_id] = 0;
                    }
                    $advertiser_usage[$advertiser_id]++;
                    
                    $this->log_message("Selected product ID: {$product['id']} from advertiser: {$advertiser_id} (second pass)");
                    
                    // Only take one product per advertiser
                    break;
                }
            }
        }
        
        $selected_count = count($selected_products);
        $this->log_message("Selected " . $selected_count . " products for scheduling");
        
        if ($selected_count == 0) {
            $this->log_message("No eligible products found to schedule. Exiting.");
            return;
        }
        
        $end_of_day = clone $now;
        $end_of_day->setTime(23, 0, 0);
        $remaining_minutes = ($end_of_day->getTimestamp() - $now->getTimestamp()) / 60;
        
        // Calculate interval between posts - minimum 30 minutes, maximum 120 minutes
        $interval_minutes = max(30, min(120, floor($remaining_minutes / $selected_count)));
        
        $this->log_message("Scheduling {$selected_count} posts with interval of {$interval_minutes} minutes");
        
        // Get initial scheduling time
        $next_time = $this->calculate_next_publish_time();
        if (!$next_time) {
            $next_time = clone $now;
            $next_time->modify('+30 minutes');
            $this->log_message("Could not determine ideal next publish time - using {$next_time->format('Y-m-d H:i:s')}");
        }
        
        // Track identifiers that were successfully used
        $used_identifiers = array();
        $advertisers = $this->api_fetcher->fetch_advertisers();
        $scheduled_count = 0;
        
        foreach ($selected_products as $item) {
            $product = $item['product'];
            $identifier = $item['identifier'];
            $advertiser_id = $item['advertiser_id'];
            
            // Get advertiser data
            $advertiser_data = isset($advertisers[$advertiser_id]) ? $advertisers[$advertiser_id] : null;
            
            // Prepare scheduling data
            $post_data = array(
                'post_status' => 'future',
                'post_date' => $next_time->format('Y-m-d H:i:s'),
                'post_date_gmt' => get_gmt_from_date($next_time->format('Y-m-d H:i:s'))
            );
            
            // Schedule the post
            $post_id = $this->post_creator->create_product_post($product, $advertiser_data, $post_data);
            
            if ($post_id && !is_wp_error($post_id)) {
                $scheduled_count++;
                $this->log_message("Scheduled post ID: {$post_id} for {$next_time->format('Y-m-d H:i:s')}");
                
                // Track that we've used this identifier
                $used_identifiers[] = $identifier;
                
                // Calculate time for next post
                $next_time = clone $next_time;
                $next_time->modify("+{$interval_minutes} minutes");
                
                // Don't schedule past 11 PM
                if ($next_time->format('H') >= 23) {
                    $this->log_message("Reached end of scheduling window for today");
                    break;
                }
            } else {
                $this->log_message("Failed to schedule post for product ID: {$product['id']}");
            }
        }
        
        // Update our tracking of used product identifiers
        if (!empty($used_identifiers)) {
            $existing_identifiers = array_merge($existing_identifiers, $used_identifiers);
            update_option('pfa_product_identifiers', $existing_identifiers);
        }
        
        $this->log_message("Successfully scheduled {$scheduled_count} posts");
        
        // If we have more slots and it's still within schedule hours, add remaining products to queue for later
        $remaining_slots = $slots_available - $scheduled_count;
        if ($remaining_slots > 0 && $next_time->format('H') < 23) {
            $this->log_message("Still have {$remaining_slots} slots available. Adding remaining products to queue.");
            
            // Add remaining products to the queue for later processing
            $remaining_products = array_slice($selected_products, $scheduled_count);
            $added_count = 0;
            
            foreach ($remaining_products as $item) {
                if (!in_array($item['identifier'], $used_identifiers)) {
                    $added = $this->queue_manager->add_to_queue($item['product']);
                    if ($added) {
                        $added_count++;
                    }
                }
            }
            
            $this->log_message("Added {$added_count} products to queue for later processing");
            
            // Schedule next dripfeed to process queue
            if ($added_count > 0) {
                wp_clear_scheduled_hook('pfa_dripfeed_publisher');
                wp_schedule_single_event($next_time->getTimestamp(), 'pfa_dripfeed_publisher');
                $this->log_message("Scheduled next dripfeed for: " . $next_time->format('Y-m-d H:i:s T'));
            }
        } else if ($scheduled_count < $max_posts) {
            // Schedule for tomorrow at 6 AM
            $tomorrow = new DateTime('tomorrow 06:00:00', $timezone);
            wp_clear_scheduled_hook('pfa_dripfeed_publisher');
            wp_schedule_single_event($tomorrow->getTimestamp(), 'pfa_dripfeed_publisher');
            $this->log_message("Scheduling window closed for today. Scheduled next check for tomorrow 6 AM.");
        }
        
        // Force refresh of queue status
        $this->queue_manager->clear_status_cache();
        
    } catch (Exception $e) {
        $this->log_message('ERROR in check_and_queue_products: ' . $e->getMessage());
        $this->log_message('Stack trace: ' . $e->getTraceAsString());
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
    $this->log_message('=== Initializing Schedules ===');
    
    if (get_option('pfa_automation_enabled', 'yes') === 'yes') {
        // Clear existing schedules first to avoid duplicates
        $this->clear_all_schedules();
        
        // Schedule API check based on interval setting
        $check_interval = get_option('check_interval', 'daily');
        wp_schedule_event(time() + 3600, $check_interval, 'pfa_api_check');
        $this->log_message("Scheduled API check with interval: {$check_interval}");

        // Schedule daily check at midnight
        wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'pfa_daily_check');
        $this->log_message("Scheduled daily check at midnight");

        // Force clear any existing locks that might be preventing execution
        delete_transient('pfa_dripfeed_lock');
        
        // Check the current hour to determine immediate action
        $timezone = new DateTimeZone(wp_timezone_string());
        $now = new DateTime('now', $timezone);
        $current_hour = (int)$now->format('H');
        
        // Only run check_and_queue_products if we're in valid hours (6 AM - 11 PM)
        if ($current_hour >= 6 && $current_hour < 23) {
            $this->log_message("Current hour is {$current_hour}, in valid publishing window. Running immediate queue check");
            
            // Clear the queue for fresh products
            delete_transient('pfa_product_queue');
            update_option('pfa_product_queue_backup', array());
            
            // Force immediate check and queue products
            // This will now handle scheduling directly
            $this->check_and_queue_products();
            
            // Check if any posts were scheduled
            global $wpdb;
            $scheduled_count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) 
                    FROM {$wpdb->posts} 
                    WHERE post_type = 'post' 
                    AND post_status = 'future' 
                    AND post_date > %s",
                    $now->format('Y-m-d H:i:s')
                )
            );
            
            $this->log_message("After initialization, found {$scheduled_count} scheduled posts");
            
            // If no posts were scheduled, make sure there's a dripfeed scheduled soon
            if ($scheduled_count == 0) {
                wp_clear_scheduled_hook('pfa_dripfeed_publisher');
                wp_schedule_single_event(time() + 300, 'pfa_dripfeed_publisher'); // Run in 5 minutes
                $this->log_message("No posts scheduled during initialization. Running dripfeed in 5 minutes.");
            }
        } else {
            // If we're in restricted hours, schedule for 6 AM
            $this->log_message("Current hour is {$current_hour}, outside valid posting hours (6-23). Scheduling for 6 AM");
            
            if ($current_hour < 6) {
                // If before 6 AM, schedule for today at 6 AM
                $next_run = clone $now;
                $next_run->setTime(6, 0, 0);
            } else {
                // If after 11 PM, schedule for tomorrow at 6 AM
                $next_run = new DateTime('tomorrow 06:00:00', $timezone);
            }
            
            wp_schedule_single_event($next_run->getTimestamp(), 'pfa_dripfeed_publisher');
            $this->log_message("Scheduled dripfeed for: " . $next_run->format('Y-m-d H:i:s T'));
        }
    } else {
        $this->log_message("Automation is disabled. Schedules not initialized.");
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