<?php
/**
 * Manages product queue for processing and publishing.
 *
 * @since      1.0.0
 * @package    Product_Feed_Automation
 */

class PFA_Queue_Manager {

    /**
     * The single instance of the class.
     *
     * @since    1.0.0
     * @access   protected
     * @var      PFA_Queue_Manager    $instance    The single instance of the class.
     */
    protected static $instance = null;

    /**
     * Cache key for storing queue status.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $cache_key    Transient cache key.
     */
    private $cache_key = 'pfa_queue_status_cache';

    /**
     * Cache expiration time in seconds.
     *
     * @since    1.0.0
     * @access   private
     * @var      int    $cache_expiry    Cache expiration in seconds.
     */
    private $cache_expiry = 300;

    /**
     * Transient key for dripfeed lock.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $lock_key    Transient lock key.
     */
    private $lock_key = 'pfa_dripfeed_lock';

    /**
     * Transient key for product queue.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $queue_key    Transient queue key.
     */
    private $queue_key = 'pfa_product_queue';

    /**
     * Reference to the post scheduler.
     *
     * @since    1.0.0
     * @access   private
     * @var      PFA_Post_Scheduler    $scheduler    The post scheduler instance.
     */
    private $scheduler;

    /**
     * Main PFA_Queue_Manager Instance.
     *
     * Ensures only one instance of PFA_Queue_Manager is loaded or can be loaded.
     *
     * @since    1.0.0
     * @return PFA_Queue_Manager - Main instance.
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
        $this->scheduler = PFA_Post_Scheduler::get_instance();
        
        // Register AJAX handlers
        add_action('wp_ajax_pfa_refresh_status', array($this, 'refresh_status'));
        add_action('wp_ajax_pfa_check_dripfeed', array($this, 'check_dripfeed'));
        
        // Handle post deletion
        add_action('after_delete_post', array($this, 'clear_status_cache'));
        add_action('after_delete_post', array($this, 'handle_post_delete'));
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
     * Clear the status cache.
     *
     * @since    1.0.0
     */
    public function clear_status_cache() {
        delete_transient($this->cache_key);
        delete_transient($this->lock_key);
    }
    
    /**
     * Check if dripfeed is locked.
     *
     * @since    1.0.0
     * @access   protected
     * @return   boolean    True if locked, false otherwise.
     */
    protected function is_dripfeed_locked() {
        if (get_option('pfa_automation_enabled') !== 'yes') {
            return true;
        }

        $current_hour = (int)current_time('G');
        if ($current_hour >= 0 && $current_hour < 6) {
            return true;
        }

        $lock = get_transient($this->lock_key);
        if (false !== $lock) {
            $this->log_message('Dripfeed check is locked');
            return true;
        }
        
        set_transient($this->lock_key, time(), 5 * MINUTE_IN_SECONDS);
        return false;
    }

    /**
     * Release the dripfeed lock.
     *
     * @since    1.0.0
     * @access   protected
     */
    protected function release_dripfeed_lock() {
        delete_transient($this->lock_key);
    }

    /**
     * Add a product to the queue.
     *
     * @since    1.0.0
     * @param    array     $product    The product to add to the queue.
     * @return   boolean               True if added, false otherwise.
     */
    public function add_to_queue($product) {
        if (get_option('pfa_automation_enabled') !== 'yes') {
            $this->log_message('Automation is disabled. Cannot add to queue.');
            return false;
        }

        $current_hour = (int)current_time('G');
        if ($current_hour >= 0 && $current_hour < 6) {
            $this->log_message('Cannot add to queue during restricted hours (00:00-06:00)');
            return false;
        }

        $queue = $this->get_queue();
        if (!$this->is_product_in_queue($product['id'], $queue)) {
            $queue[] = $product;
            set_transient($this->queue_key, $queue, DAY_IN_SECONDS);
            $this->log_message("Added product {$product['id']} to queue");
            return true;
        }
        return false;
    }

    /**
     * Get the next product in the queue.
     *
     * @since    1.0.0
     * @return   array|null    Next queued product or null if none available.
     */
    public function get_next_queued_product() {
        if (get_option('pfa_automation_enabled') !== 'yes') {
            $this->log_message('Automation is disabled. Cannot process queue.');
            return null;
        }
    
        $current_hour = (int)current_time('G');
        static $processed_at_six = false;
    
        if ($current_hour === 6 && !$processed_at_six) {
            $this->log_message("Processing the first product of the day at 06:00.");
            $processed_at_six = true; 
        } elseif ($current_hour === 6) {
            $this->log_message("Skipping extra processing at 06:00 to avoid burst.");
            return null;
        }
    
        if ($current_hour >= 0 && $current_hour < 6) {
            $this->log_message('Cannot process queue during restricted hours (00:00-06:00)');
            return null;
        }
    
        $queue = $this->get_queue();
        if (!empty($queue)) {
            $product = array_shift($queue);
            set_transient($this->queue_key, $queue, DAY_IN_SECONDS);
            $this->log_message("Retrieved next product from queue: {$product['id']}");
            
            return $product;
        }
        return null;
    }
    
    /**
     * Get the current queue.
     *
     * @since    1.0.0
     * @access   private
     * @return   array    Current queue.
     */
    private function get_queue() {
        return get_transient($this->queue_key) ?: array();
    }

    /**
     * Check if a product is already in the queue.
     *
     * @since    1.0.0
     * @access   private
     * @param    string    $product_id    The product ID to check.
     * @param    array     $queue         The queue to check in.
     * @return   boolean                  True if product is in queue, false otherwise.
     */
    private function is_product_in_queue($product_id, $queue) {
        foreach ($queue as $item) {
            if ($item['id'] === $product_id) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * AJAX handler for checking dripfeed status.
     *
     * @since    1.0.0
     */
    public function check_dripfeed() {
        check_ajax_referer('pfa_ajax_nonce', 'nonce');
        
        try {
            if (isset($_POST['refresh_type']) && $_POST['refresh_type'] === 'api_check') {
                $this->clear_status_cache();
                do_action('pfa_api_check');
            }
            // Clear cache before getting fresh status
            $this->clear_status_cache();
            
            $timezone = new DateTimeZone(wp_timezone_string());
            $now = new DateTime('now', $timezone);
            
            // Get automation status and check current hour
            $automation_enabled = get_option('pfa_automation_enabled', 'yes') === 'yes';
            $is_restricted_time = ($now->format('G') >= 0 && $now->format('G') < 6);
            
            // Get next scheduled API check time
            $next_api_check = wp_next_scheduled('pfa_api_check');
            
            // If no next check is scheduled, create new schedule
            if (!$next_api_check) {
                $check_interval = get_option('check_interval', 'daily');
                $current_timestamp = current_time('timestamp');
                
                switch ($check_interval) {
                    case 'hourly':
                        $next_api_check = strtotime('+1 hour', $current_timestamp);
                        break;
                    case 'twicedaily':
                        $next_api_check = (date('G', $current_timestamp) < 12) ? 
                            strtotime('today 12:00') : 
                            strtotime('tomorrow 00:00');
                        break;
                    case 'daily':
                        $next_api_check = strtotime('tomorrow 06:00:00', $current_timestamp);
                        break;
                }
                
                wp_schedule_single_event($next_api_check, 'pfa_api_check');
            }
            
            // Update the next check time in options
            update_option('pfa_next_api_check', wp_date('Y-m-d H:i:s T', $next_api_check));
            
            // Get post counts
            $posts_today = $this->get_post_count_today();
            $max_posts = get_option('max_posts_per_day', 10);
            $limit_reached = $posts_today >= $max_posts;
            
            // Get scheduled posts count
            $scheduled_posts = $this->get_scheduled_posts_count();
            
            $next_dripfeed = wp_next_scheduled('pfa_dripfeed_publisher');
            $next_daily = wp_next_scheduled('pfa_daily_check');
            
            // Format times with timezone
            $next_dripfeed_time = $next_dripfeed ? 
                (new DateTime('@' . $next_dripfeed))->setTimezone($timezone)->format('Y-m-d H:i:s T') : 
                null;
            
            $next_daily_time = $next_daily ? 
                (new DateTime('@' . $next_daily))->setTimezone($timezone)->format('Y-m-d H:i:s T') : 
                null;
            
            $next_api_time = wp_date('Y-m-d H:i:s T', $next_api_check);
            
            // Status message
            $status_message = 'No scheduled posts';
            if ($limit_reached) {
                $status_message = 'Daily limit reached';
            } elseif ($is_restricted_time) {
                $status_message = 'Paused until 06:00';
            } elseif ($scheduled_posts > 0) {
                $status_message = $scheduled_posts . ' posts scheduled';
            }
            
            wp_send_json_success(array(
                'is_active' => $automation_enabled && !$is_restricted_time && !$limit_reached,
                'automation_enabled' => $automation_enabled,
                'is_restricted_time' => $is_restricted_time,
                'next_dripfeed' => $next_dripfeed_time,
                'next_daily' => $next_daily_time,
                'next_api_check' => $next_api_time,
                'queue_size' => count($this->get_queue()),
                'posts_today' => $posts_today,
                'max_posts' => $max_posts,
                'limit_reached' => $limit_reached,
                'scheduled_posts' => $scheduled_posts,
                'current_time' => $now->format('Y-m-d H:i:s T'),
                'status_message' => $status_message
            ));
            
        } catch (Exception $e) {
            $this->log_message('Error in check_dripfeed: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Internal server error'));
        } finally {
            $this->release_dripfeed_lock();
        }
    }
    

    /**
     * Get count of posts published today.
     *
     * @since    1.0.0
     * @access   private
     * @return   int    Number of posts published today.
     */
    private function get_post_count_today() {
        $timezone = new DateTimeZone(wp_timezone_string());
        $today = new DateTime('today', $timezone);
        
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'date_query' => array(
                array(
                    'after' => $today->format('Y-m-d 00:00:00'),
                    'before' => $today->format('Y-m-d 23:59:59'),
                    'inclusive' => true,
                ),
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_pfa_v2_post',
                    'compare' => 'EXISTS'  
                ),
                array(
                    'key' => '_pfa_v2_post',
                    'value' => 'true',
                    'compare' => '='  
                )
            )
        );
        
        $posts = get_posts($args);
        return count($posts);
    }
    
    /**
     * Get the current count of scheduled posts.
     *
     * @since    1.0.0
     * @access   private 
     * @return   int    Number of scheduled posts.
     */
    private function get_scheduled_posts_count() {
        global $wpdb;
        $scheduled_posts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'post' 
            AND p.post_status = 'future'
            AND pm.meta_key = '_pfa_v2_post'
            AND pm.meta_value = 'true'"
        ));

        $this->log_message('Scheduled posts count from direct query: ' . $scheduled_posts);
        return (int) $scheduled_posts;
    }
    
    /**
     * Get queue status.
     *
     * @since    1.0.0
     * @param    boolean    $force_refresh    Whether to force refresh the status.
     * @return   array                        Queue status data.
     */
    public function get_status($force_refresh = false) {
        if (!$force_refresh) {
            $cached_status = get_transient($this->cache_key);
            if (false !== $cached_status) {
                return $cached_status;
            }
        }
        
        $status = $this->generate_status();
        set_transient($this->cache_key, $status, $this->cache_expiry);
        return $status;
    }
    
    /**
     * Generate current status data.
     *
     * @since    1.0.0
     * @access   private
     * @return   array    Status data.
     */
    private function generate_status() {
        $wp_timezone = new DateTimeZone(wp_timezone_string());
        $current_time = new DateTime('now', $wp_timezone);
        $current_hour = (int)$current_time->format('G');
        
        // Get automation status
        $automation_enabled = get_option('pfa_automation_enabled', 'yes') === 'yes';
        $is_restricted_time = ($current_hour >= 0 && $current_hour < 6);
        
        // Get post counts
        $posts_today = $this->get_post_count_today();
        $max_posts = get_option('max_posts_per_day', 10);
        $limit_reached = $posts_today >= $max_posts;
        
        // Get scheduled posts
        $scheduled_posts = $this->get_scheduled_posts_count();
        
        // Get API check time with proper interval handling
        $next_api_check = get_option('pfa_next_api_check', 'Not scheduled');
        $last_check_time = get_option('pfa_last_api_check_time', 'Not Set');
        $check_interval = get_option('check_interval', 'daily');
        
        // Get archive category stats
        $archive_cat = get_term_by('slug', 'archived-deals', 'category');
        $archived_posts = 0;
        $recently_archived = 0;
        
        if ($archive_cat) {
            $archived_posts = get_term($archive_cat->term_id)->count;
            
            // Get posts archived in the last 24 hours
            $recent_archives = get_posts(array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'category' => $archive_cat->term_id,
                'date_query' => array(
                    'after' => '24 hours ago'
                ),
                'fields' => 'ids'
            ));
            
            $recently_archived = count($recent_archives);
        }
        
        $status = array(
            'current_time' => $current_time->format('Y-m-d H:i:s T'),
            'automation_enabled' => $automation_enabled,
            'is_restricted_time' => $is_restricted_time,
            'limit_reached' => $limit_reached,
            'scheduled_posts' => $scheduled_posts,
            'posts_today' => $posts_today,
            'max_posts' => $max_posts,
            'queue_size' => count($this->get_queue()),
            'dripfeed_interval' => get_option('dripfeed_interval', 60),
            'api_check' => array(
                'next_check' => $next_api_check,
                'last_check_time' => $last_check_time,
                'check_interval' => $check_interval,
                'total_products' => get_option('pfa_last_total_products', 0),
                'eligible_products' => get_option('pfa_last_eligible_products', 0),
                'min_discount' => get_option('min_discount', 0)
            ),
            'archived_stats' => array(
                'total' => $archived_posts,
                'recent' => $recently_archived,
                'last_24h' => $recently_archived > 0 ? 
                    sprintf('%d posts archived in last 24h', $recently_archived) : 
                    'No posts archived recently'
            )
        );

        $next_dripfeed = wp_next_scheduled('pfa_dripfeed_publisher');
        $next_daily = wp_next_scheduled('pfa_daily_check');

        if ($next_dripfeed || $next_daily) {
            $status['schedules'] = array(
                'daily_check' => $next_daily,
                'dripfeed_check' => $next_dripfeed,
                'dripfeed_interval' => get_option('dripfeed_interval', 60),
                'formatted' => array(
                    'daily' => $next_daily ? wp_date('Y-m-d H:i:s T', $next_daily) : 'Not scheduled',
                    'dripfeed' => $next_dripfeed ? wp_date('Y-m-d H:i:s T', $next_dripfeed) : 'Not scheduled'
                )
            );
        }

        $this->log_message('Generated status: ' . print_r($status, true));

        return $status;
    }
    
    /**
     * Handle post deletion.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID that was deleted.
     */
    public function handle_post_delete($post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_type === 'post') {
            // Clear all caches
            $this->clear_status_cache();
            delete_transient($this->queue_key);
            
            // Force status refresh
            $posts_today = $this->get_post_count_today();
            $max_posts = get_option('max_posts_per_day', 10);
            
            if ($posts_today < $max_posts) {
                // Reset schedules to trigger new post creation
                $this->scheduler->clear_all_schedules();
                $this->scheduler->initialize_schedules();
                
                // Force immediate status refresh
                $this->get_status(true);
            }
        }
    }

    /**
     * AJAX handler for refreshing status.
     *
     * @since    1.0.0
     */
    public function refresh_status() {
        check_ajax_referer('pfa_ajax_nonce', 'nonce');
        
        // Clear all caches
        $this->clear_status_cache();
        delete_transient($this->queue_key);
        
        // Get fresh status
        $status = $this->generate_status();
        
        // Force immediate status update
        set_transient($this->cache_key, $status, $this->cache_expiry);
        
        wp_send_json_success($status);
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
            error_log('[PFA Queue] ' . $message);
        }
    }
}