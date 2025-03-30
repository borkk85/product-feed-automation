<?php 

class QueueManager {
    private static $instance = null;
    private $cache_key = 'pfa_queue_status_cache';
    private $cache_expiry = 300;
    private $scheduler;
    private $lock_key = 'pfa_dripfeed_lock';
    private $queue_key = 'pfa_product_queue';
    
    private function __construct() {
        $this->scheduler = PostScheduler::getInstance();
        add_action('wp_ajax_pfa_refresh_status', [$this, 'refreshStatus']);
        add_action('wp_ajax_pfa_check_dripfeed', [$this, 'checkDripfeed']);
        add_action('after_delete_post', [$this, 'clearStatusCache']);
        add_action('after_delete_post', [$this, 'handlePostDelete']);
    }
    
    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function clearStatusCache() {
        delete_transient($this->cache_key);
        delete_transient($this->lock_key);
    }
    
    protected function isDripfeedLocked() {
        if (get_option('pfa_automation_enabled') !== 'yes') {
            return true;
        }

        $current_hour = (int)current_time('G');
        if ($current_hour >= 0 && $current_hour < 6) {
            return true;
        }

        $lock = get_transient($this->lock_key);
        if (false !== $lock) {
            error_log('Dripfeed check is locked');
            return true;
        }
        
        set_transient($this->lock_key, time(), 5 * MINUTE_IN_SECONDS);
        return false;
    }

    protected function releaseDripfeedLock() {
        delete_transient($this->lock_key);
    }

    public function addToQueue($product) {
        if (get_option('pfa_automation_enabled') !== 'yes') {
            error_log('Automation is disabled. Cannot add to queue.');
            return false;
        }

        $current_hour = (int)current_time('G');
        if ($current_hour >= 0 && $current_hour < 6) {
            error_log('Cannot add to queue during restricted hours (00:00-06:00)');
            return false;
        }

        $queue = $this->getQueue();
        if (!$this->isProductInQueue($product['id'], $queue)) {
            $queue[] = $product;
            set_transient($this->queue_key, $queue, DAY_IN_SECONDS);
            error_log("Added product {$product['id']} to queue");
            return true;
        }
        return false;
    }

    public function getNextQueuedProduct() {
        if (get_option('pfa_automation_enabled') !== 'yes') {
            error_log('Automation is disabled. Cannot process queue.');
            return null;
        }
    
        $current_hour = (int)current_time('G');
        static $processed_at_six = false;
    
        if ($current_hour === 6 && !$processed_at_six) {
            error_log("Processing the first product of the day at 06:00.");
            $processed_at_six = true; 
        } elseif ($current_hour === 6) {
            error_log("Skipping extra processing at 06:00 to avoid burst.");
            return null;
        }
    
        if ($current_hour >= 0 && $current_hour < 6) {
            error_log('Cannot process queue during restricted hours (00:00-06:00)');
            return null;
        }
    
        $queue = $this->getQueue();
        if (!empty($queue)) {
            $product = array_shift($queue);
            set_transient($this->queue_key, $queue, DAY_IN_SECONDS);
            error_log("Retrieved next product from queue: {$product['id']}");
            
            return $product;
        }
        return null;
    }
    
    private function getQueue() {
        return get_transient($this->queue_key) ?: [];
    }

    private function isProductInQueue($product_id, $queue) {
        foreach ($queue as $item) {
            if ($item['id'] === $product_id) {
                return true;
            }
        }
        return false;
    }
    
    public function checkDripfeed() {
        check_ajax_referer('pfa_ajax_nonce', 'nonce');
        
        try {

            if (isset($_POST['refresh_type']) && $_POST['refresh_type'] === 'api_check') {
                $this->clearStatusCache();
                do_action('pfa_api_check');
            }
            // Clear cache before getting fresh status
            $this->clearStatusCache();
            
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
            $posts_today = $this->getPostCountToday();
            $max_posts = get_option('max_posts_per_day', 10);
            $limit_reached = $posts_today >= $max_posts;
            
            // Get scheduled posts count
            $scheduled_posts = (int)wp_count_posts('post')->future;
            
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
            
            wp_send_json_success([
                'is_active' => $automation_enabled && !$is_restricted_time && !$limit_reached,
                'automation_enabled' => $automation_enabled,
                'is_restricted_time' => $is_restricted_time,
                'next_dripfeed' => $next_dripfeed_time,
                'next_daily' => $next_daily_time,
                'next_api_check' => $next_api_time,
                'queue_size' => count($this->getQueue()),
                'posts_today' => $posts_today,
                'max_posts' => $max_posts,
                'limit_reached' => $limit_reached,
                'scheduled_posts' => $scheduled_posts,
                'current_time' => $now->format('Y-m-d H:i:s T'),
                'status_message' => $status_message
            ]);
            
        } catch (Exception $e) {
            error_log('Error in checkDripfeed: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Internal server error']);
        } finally {
            $this->releaseDripfeedLock();
        }
    }
    

    private function getPostCountToday() {
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
    
    public function getStatus($force_refresh = false) {
        if (!$force_refresh) {
            $cached_status = get_transient($this->cache_key);
            if (false !== $cached_status) {
                return $cached_status;
            }
        }
        
        $status = $this->generateStatus();
        set_transient($this->cache_key, $status, $this->cache_expiry);
        return $status;
    }
    
    private function generateStatus() {
    $wp_timezone = new DateTimeZone(wp_timezone_string());
    $current_time = new DateTime('now', $wp_timezone);
    $current_hour = (int)$current_time->format('G');
    
    // Get automation status
    $automation_enabled = get_option('pfa_automation_enabled', 'yes') === 'yes';
    $is_restricted_time = ($current_hour >= 0 && $current_hour < 6);
    
    // Get post counts
    $posts_today = $this->getPostCountToday();
    $max_posts = get_option('max_posts_per_day', 10);
    $limit_reached = $posts_today >= $max_posts;
    
    // Get scheduled posts
    // $scheduled_posts = (int)wp_count_posts('post')->future;
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

        error_log('Scheduled posts count from direct query: ' . $scheduled_posts);
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
        $recent_archives = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'category' => $archive_cat->term_id,
            'date_query' => [
                'after' => '24 hours ago'
            ],
            'fields' => 'ids'
        ]);
        
        $recently_archived = count($recent_archives);
    }
    
    $status = [
        'current_time' => $current_time->format('Y-m-d H:i:s T'),
        'automation_enabled' => $automation_enabled,
        'is_restricted_time' => $is_restricted_time,
        'limit_reached' => $limit_reached,
        'scheduled_posts' => $scheduled_posts,
        'posts_today' => $posts_today,
        'max_posts' => $max_posts,
        'queue_size' => count($this->getQueue()),
        'dripfeed_interval' => get_option('dripfeed_interval', 60),
        'api_check' => [
            'next_check' => $next_api_check,
            'last_check_time' => $last_check_time,
            'check_interval' => $check_interval,
            'total_products' => get_option('pfa_last_total_products', 0),
            'eligible_products' => get_option('pfa_last_eligible_products', 0),
            'min_discount' => get_option('min_discount', 0)  // Add this line
        ],
        'archived_stats' => [
            'total' => $archived_posts,
            'recent' => $recently_archived,
            'last_24h' => $recently_archived > 0 ? 
                sprintf('%d posts archived in last 24h', $recently_archived) : 
                'No posts archived recently'
        ]
    ];

    $next_dripfeed = wp_next_scheduled('pfa_dripfeed_publisher');
    $next_daily = wp_next_scheduled('pfa_daily_check');

        if ($next_dripfeed || $next_daily) {
            $status['schedules'] = [
                'daily_check' => $next_daily,
                'dripfeed_check' => $next_dripfeed,
                'dripfeed_interval' => get_option('dripfeed_interval', 69),
                'formatted' => [
                    'daily' => $next_daily ? wp_date('Y-m-d H:i:s T', $next_daily) : 'Not scheduled',
                    'dripfeed' => $next_dripfeed ? wp_date('Y-m-d H:i:s T', $next_dripfeed) : 'Not scheduled'
                ]
            ];
        }

    error_log('Generated status: ' . print_r($status, true));

    return $status;
}

    
    
    public function handlePostDelete($post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_type === 'post') {
            // Clear all caches
            $this->clearStatusCache();
            delete_transient($this->queue_key);
            
            // Force status refresh
            $posts_today = $this->getPostCountToday();
            $max_posts = get_option('max_posts_per_day', 10);
            
            if ($posts_today < $max_posts) {
                // Reset schedules to trigger new post creation
                $scheduler = PostScheduler::getInstance();
                $scheduler->clearAllSchedules();
                $scheduler->initializeSchedules();
                
                // Force immediate status refresh
                $this->getStatus(true);
            }
        }
    }

    public function refreshStatus() {
        check_ajax_referer('pfa_ajax_nonce', 'nonce');
        
        // Clear all caches
        $this->clearStatusCache();
        delete_transient($this->queue_key);
        
        // Get fresh status
        $status = $this->generateStatus();
        
        // Force immediate status update
        set_transient($this->cache_key, $status, $this->cache_expiry);
        
        wp_send_json_success($status);
    }
}