<?php

class PostScheduler
{
    private static $instance = null;
    private static $init_count = 0;
    private $maxPostsPerDay;
    private $dripfeedInterval;
    private $checkInterval;
    private $apiFetcher;
    private $postCreator;
    private $queueManager;

    private function __construct()
    {
        self::$init_count++;

        if (self::$init_count === 1) {
            $this->maxPostsPerDay = get_option('max_posts_per_day', 10);
            $this->dripfeedInterval = get_option('dripfeed_interval', 30);
            $this->checkInterval = get_option('check_interval', 'daily');
            $this->postCreator = PostCreator::getInstance();
            $this->queueManager = QueueManager::getInstance();
            $this->apiFetcher = new ApiFetcher();
            $this->registerHooks();
        }
    }

    private function registerHooks()
    {
        if (!has_action('pfa_dripfeed_publisher', [$this, 'handleDripfeedPublish'])) {
            add_action('pfa_dripfeed_publisher', [$this, 'handleDripfeedPublish']);
            add_action('pfa_daily_check', [$this, 'checkAndQueueProducts']);
            add_action('pfa_api_check', [$this, 'handleApiCheck']);
            add_filter('cron_schedules', [$this, 'addCustomSchedules']);
        }
    }

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function handleDripfeedPublish()
{
    error_log('=== Starting Dripfeed Publish ===');

    if (get_option('pfa_automation_enabled') !== 'yes') {
        error_log('Automation is disabled. Dripfeed publishing skipped.');
        return;
    }

    try {

        $timezone = new DateTimeZone(wp_timezone_string());
    $current_time = new DateTime('now', $timezone);

    // Check for restricted hours (00:00-06:00)
    $hour = (int)$current_time->format('H');
    if ($hour < 6) {
        error_log("Restricted hours (00:00-06:00). Pausing dripfeed.");
        return;
    }

    // New 6 AM control
    if ($current_time->format('H:i') === '06:00') {
        error_log("6 AM transition period detected");
        $posts_today = $this->getPostCountToday();
        
        if ($posts_today > 0) {
            error_log("Posts already exist for today at 6 AM, deferring to next interval");
            $this->scheduleNextDripfeed();
            return;
        }
        
        // Force a delay if microseconds are too low to prevent multiple executions
        $microseconds = (int)$current_time->format('u');
        if ($microseconds < 500000) { // If in first half second of the minute
            error_log("Too early in the minute at 6 AM, adding delay");
            usleep(1000000); // 1 second delay
        }
    }

        $posts_today = $this->getPostCountToday();
        error_log("Current post count for today: {$posts_today} (max: {$this->maxPostsPerDay})");
        if ($posts_today >= $this->maxPostsPerDay) {
            error_log('Daily post limit reached. Skipping dripfeed publish.');
            return;
        }

        // Fetch the next product in the queue
        $product = $this->queueManager->getNextQueuedProduct();
        if (!$product) {
            error_log('No products in queue. Fetching new products.');
            $this->checkAndQueueProducts(); // Add log in this method
            $product = $this->queueManager->getNextQueuedProduct();
            error_log('After checkAndQueueProducts - Product: ' . ($product ? json_encode($product) : 'null'));
        }

        if ($product) {
            $advertisers = $this->apiFetcher->fetchAdvertisers();
            $advertiser_data = $advertisers[$product['advertiserId']] ?? null;
        
            if (!$this->postCreator->checkIfAlreadyInDb($product['trackingLink'])) {
                // Calculate next time with randomized interval
                $base_interval = (int)$this->dripfeedInterval;
                $min_interval = max(1, $base_interval - 18); // Subtract up to 18 minutes
                $max_interval = $base_interval + 30; // Add up to 30 minutes
                $random_interval = rand($min_interval, $max_interval);
                
                error_log(sprintf(
                    'Calculating next time with randomized interval: base=%d, random=%d minutes',
                    $base_interval,
                    $random_interval
                ));
                
                // Temporarily set randomized interval
                $this->dripfeedInterval = $random_interval;
                $next_time = $this->calculateNextPublishTime();
                $this->dripfeedInterval = $base_interval; // Reset to original
                
                if (!$next_time) {
                    error_log('No valid publish time available. Skipping dripfeed.');
                    $this->scheduleNextDripfeed();
                    return;
                }
        
                $post_data = [
                    'post_status' => 'future',
                    'post_date' => $next_time->format('Y-m-d H:i:s'),
                    'post_date_gmt' => get_gmt_from_date($next_time->format('Y-m-d H:i:s')),
                ];
        
                error_log('Attempting to schedule post: ' . print_r($post_data, true));
                $result = $this->postCreator->createProductPost($product, $advertiser_data, $post_data);
        
                if ($result && !is_wp_error($result)) {
                    error_log("Successfully scheduled product ID: {$product['id']} for " . $next_time->format('Y-m-d H:i:s'));
                    $this->scheduleNextDripfeed();
                } else {
                    error_log('Failed to schedule product.');
                    // Since post creation failed, we should add the product back to the queue
                    $this->queueManager->addToQueue($product);
                }
            } else {
                error_log("Product {$product['id']} already exists, skipping.");
                $this->scheduleNextDripfeed();
            }
        } else {
            error_log('No eligible products found for publishing.');
            $this->scheduleNextDripfeed();
        }
    } catch (Exception $e) {
        error_log('Error in handleDripfeedPublish: ' . $e->getMessage());
        $this->scheduleNextDripfeed();
    }
}
    


public function handleApiCheck()
{
    $current_action = current_action();
    $allowed_actions = ['pfa_api_check', 'pfa_dripfeed_publisher'];
    
    if (!in_array($current_action, $allowed_actions)) {
        error_log('Unauthorized handleApiCheck call attempted from: ' . $current_action);
        return;
    }

    error_log('=== Starting API Check from: ' . $current_action . ' ===');

    try {
        // Get categories
        $active_cat = get_term_by('slug', 'active-deals', 'category');
        $archive_cat = get_term_by('slug', 'archived-deals', 'category');

        if (!$active_cat || !$archive_cat) {
            error_log('Required categories not found - deals and/or archive-deals');
            return;
        }

        // Get current minimum discount and calculate check range
        $current_min_discount = get_option('min_discount', 0);
        $check_range_min = max(0, $current_min_discount - 10);
        
        error_log(sprintf('Current minimum discount: %d%%, Checking range: %d%% to 100%%', 
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
        $products = $this->apiFetcher->fetchProducts(true);

        if (!$products) {
            error_log('No products fetched from API');
            return;
        }

        $product_lookup = [];
        foreach ($products as $product) {
            $product_lookup[$product['id']] = [
                'availability' => $product['availability'] ?? ''
            ];
        }
        
        error_log(sprintf('Built product lookup with %d products in %d%%-100%% range', 
            count($product_lookup), $check_range_min));

        // Process active posts for potential archiving
        $active_posts = get_posts([
            'post_type' => 'post',
            'post_status' => ['publish', 'future'],
            'posts_per_page' => -1,
            'category' => $active_cat->term_id,
            'tax_query' => [
                [
                    'taxonomy' => 'store_type',
                    'field' => 'name',
                    'terms' => 'Amazon',
                    'operator' => 'NOT IN'
                ]
            ],
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_product_url',
                    'compare' => 'EXISTS',
                ],
                [
                    'key' => '_Amazone_produt_baseName',
                    'compare' => 'EXISTS',
                ]
            ]
        ]);

        $archived_count = 0;
        $checked_count = 0;

        // Check active posts for archiving
        foreach ($active_posts as $post) {
            $product_id = get_post_meta($post->ID, '_Amazone_produt_baseName', true);
            $checked_count++;
            
            $should_archive = false;
            $reason = '';
            
            error_log("Checking active product ID: $product_id");
            
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
                error_log("Archiving post ID {$post->ID}: $reason");
                $this->archivePost($post->ID, $archive_cat->term_id);
                $archived_count++;
            }
        }

        // Process archived posts for potential reactivation
        $archived_posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'category' => $archive_cat->term_id,
            'tax_query' => [
                [
                    'taxonomy' => 'store_type',
                    'field' => 'name',
                    'terms' => 'Amazon',
                    'operator' => 'NOT IN'
                ]
            ],
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_pfa_v2_post',
                    'value' => 'true',
                    'compare' => '='
                ],
                [
                    'key' => '_product_url',
                    'compare' => 'EXISTS',
                ],
                [
                    'key' => '_Amazone_produt_baseName',
                    'compare' => 'EXISTS',
                ]
            ]
        ]);

        $reactivated_count = 0;

        foreach ($archived_posts as $post) {
            $product_id = get_post_meta($post->ID, '_Amazone_produt_baseName', true);
            error_log("Checking archived product ID: $product_id for reactivation");

            if (isset($product_lookup[$product_id])) {
                $product_info = $product_lookup[$product_id];
                
                if ($product_info['availability'] === 'in_stock') {
                    error_log(sprintf(
                        "Reactivating post ID %d: Product back in stock",
                        $post->ID
                    ));
                    
                    $this->reactivatePost($post->ID, $active_cat->term_id, $product_info);
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
                    ($product['gtin'] ?? '') . '|' . 
                    ($product['mpn'] ?? '')
                );
                
                if (in_array($product_identifier, get_option('pfa_product_identifiers', []))) {
                    return false;
                }
                
                if ($this->postCreator->checkIfAlreadyInDb($product['trackingLink'])) {
                    return false;
                }
                
                return true;
            });
        
            $stats = [
                'time' => current_time('mysql'),
                'total' => count($products),
                'eligible' => count($actually_eligible),
                'archived' => $archived_count,
                'reactivated' => $reactivated_count,
                'checked' => $checked_count
            ];

            update_option('pfa_last_check_stats', $stats);
            update_option('pfa_last_total_products', $stats['total']);
            update_option('pfa_last_eligible_products', $stats['eligible']);
        }

        error_log(sprintf(
            "API Check completed: Checked %d posts, archived %d, reactivated %d",
            $checked_count,
            $archived_count,
            $reactivated_count
        ));
        
        do_action('pfa_status_updated', 'api_check');

    } catch (Exception $e) {
        error_log('Error during API check: ' . $e->getMessage());
    }
}

private function archivePost($post_id, $archive_category_id) {
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
    
    wp_update_post([
        'ID' => $post_id,
        'post_content' => $updated_post_content,
        'post_excerpt' => $price_block
    ]);
    
    wp_set_post_categories($post_id, [$archive_category_id], false);
}

private function reactivatePost($post_id, $active_category_id, $product_info) {
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
    
    wp_update_post([
        'ID' => $post_id,
        'post_content' => $updated_post_content,
        'post_excerpt' => $price_block,
        'post_date' => $current_time,
        'post_date_gmt' => get_gmt_from_date($current_time),
        'post_modified' => $current_time,
        'post_modified_gmt' => get_gmt_from_date($current_time)
    ]);
    
    wp_set_post_categories($post_id, [$active_category_id], false);
    
    error_log(sprintf(
        "Reactivated post ID %d with new date: %s", 
        $post_id, 
        $current_time
    ));
}

private function calculateNextPublishTime(): ?DateTime {
    $timezone = new DateTimeZone(wp_timezone_string());
    $now = new DateTime('now', $timezone);
    $interval_minutes = max(1, (int)$this->dripfeedInterval);

    // Check post count first
    $posts_today = $this->getPostCountToday();
    if ($posts_today >= $this->maxPostsPerDay) {
        error_log(sprintf(
            'Daily limit reached (%d/%d posts). Cannot schedule more posts today.',
            $posts_today,
            $this->maxPostsPerDay
        ));
        return null;
    }

    // Handle 6 AM start time
    if ($now->format('H:i') === '06:00') {
        // If this is the first post at 6 AM, use exactly 6:00
        if ($posts_today === 0) {
            $next_time = new DateTime('today 06:00:00', $timezone);
            error_log("First post of the day scheduled for 6 AM");
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
        error_log("Found last scheduled post at: " . $last_time->format('Y-m-d H:i:s'));
        
        // Calculate next time from last scheduled
        $next_time = clone $last_time;
        $next_time->modify("+{$interval_minutes} minutes");

        // If next time would be past 23:00, we can't schedule more today
        $end_of_day = (clone $now)->setTime(23, 0, 0);
        if ($next_time > $end_of_day) {
            error_log('Cannot schedule more posts today - would exceed end of day (23:00)');
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
        error_log('Cannot schedule more posts today - would exceed end of day (23:00)');
        return null;
    }

    error_log(sprintf(
        "Next publish time calculated: %s (Interval: %d minutes)",
        $next_time->format('Y-m-d H:i:s'),
        $interval_minutes
    ));

    return $next_time;
}
    
  
public function checkAndQueueProducts()
{
    error_log('=== Starting checkAndQueueProducts ===');

    if (get_option('pfa_automation_enabled') !== 'yes') {
        error_log('Automation is disabled. Skipping queue check.');
        return;
    }

    try {
        $products = ApiFetcher::fetchProducts();
        $slots_available = $this->maxPostsPerDay - $this->getPostCountToday();
        error_log('Available slots: ' . $slots_available);
        
        if (get_option('pfa_product_identifiers') === false) {
            add_option('pfa_product_identifiers', []);
        }

        $existing_identifiers = get_option('pfa_product_identifiers', []);
        $skipped = ['duplicate' => 0, 'exists' => 0, 'stock' => 0];
        $queued = 0;
        
        // Group products by advertiser first
        $advertiser_groups = [];
        foreach ($products as $product) {
            $advertiser_id = $product['advertiserId'];
            if (!isset($advertiser_groups[$advertiser_id])) {
                $advertiser_groups[$advertiser_id] = [];
            }
            $advertiser_groups[$advertiser_id][] = $product;
        }

        error_log(sprintf("Found %d different advertisers", count($advertiser_groups)));

        // Get list of advertiser IDs and shuffle them for random order
        $advertiser_ids = array_keys($advertiser_groups);
        shuffle($advertiser_ids);

        // Keep track of how many products we've taken from each advertiser
        $advertiser_counts = array_fill_keys($advertiser_ids, 0);
        $max_rounds = 10; // Prevent infinite loops
        $round = 0;
        $remaining_slots = $slots_available;

        error_log(sprintf("Starting distribution for %d advertisers with %d slots", 
                         count($advertiser_ids), $slots_available));

        while ($remaining_slots > 0 && $round < $max_rounds) {
            $added_this_round = false;
            
            foreach ($advertiser_ids as $advertiser_id) {
                if ($remaining_slots <= 0) break;

                $products = $advertiser_groups[$advertiser_id];
                if (empty($products)) continue;

                // Get in-stock variants for this advertiser
                $in_stock_variants = array_filter($products, function($p) {
                    return $p['availability'] === 'in_stock';
                });

                if (empty($in_stock_variants)) {
                    $skipped['stock'] += count($products);
                    unset($advertiser_groups[$advertiser_id]); // Remove empty advertiser
                    continue;
                }
                
                usort($in_stock_variants, function($a, $b) {
                    $discount_a = $this->postCreator->calculateDiscount($a['price'], $a['sale_price']);
                    $discount_b = $this->postCreator->calculateDiscount($b['price'], $b['sale_price']);
                    
                    error_log(sprintf(
                        'Comparing products - ID: %s (Discount: %d%%) vs ID: %s (Discount: %d%%)',
                        $a['id'],
                        $discount_a,
                        $b['id'],
                        $discount_b
                    ));
                    
                    return $discount_b - $discount_a;
                });

                // Try to find a valid product from this advertiser
                foreach ($in_stock_variants as $key => $variant) {
                    if ($remaining_slots <= 0) break;
                    
                    $variant_identifier = md5(
                        $variant['id'] . '|' . 
                        ($variant['gtin'] ?? '') . '|' . 
                        ($variant['mpn'] ?? '')
                    );

                    if (in_array($variant_identifier, $existing_identifiers)) {
                        $skipped['duplicate']++;
                        continue;
                    }

                    if ($this->postCreator->checkIfAlreadyInDb($variant['trackingLink'])) {
                        $skipped['exists']++;
                        continue;
                    }

                    // Found a valid variant to use
                    if ($this->queueManager->addToQueue($variant)) {
                        error_log(sprintf(
                            "Added product from advertiser %s (ID: %s) to queue. Remaining slots: %d",
                            $advertiser_id,
                            $variant['id'],
                            $remaining_slots - 1
                        ));
                        
                        $existing_identifiers[] = $variant_identifier;
                        update_option('pfa_product_identifiers', $existing_identifiers);
                        
                        $queued++;
                        $remaining_slots--;
                        $advertiser_counts[$advertiser_id]++;
                        $added_this_round = true;
                        
                        // Remove the used product
                        unset($advertiser_groups[$advertiser_id][$key]);
                        
                        break; // Try next advertiser
                    }
                }
            }

            $round++;
            error_log(sprintf("Completed round %d with %d products queued", $round, $queued));

            // Only break if we haven't added anything AND we've done at least 2 rounds
            if (!$added_this_round && $round >= 2) {
                error_log(sprintf("No products added in round %d - breaking distribution loop", $round));
                break;
            }
        }

        // Log distribution of queued posts
        foreach ($advertiser_counts as $advertiser_id => $count) {
            if ($count > 0) {
                error_log(sprintf("Advertiser %s: %d products queued", $advertiser_id, $count));
            }
        }

        error_log(sprintf(
            'Queue summary: %d queued (of %d slots), %d duplicates, %d existing, %d out of stock, %d active advertisers',
            $queued,
            $slots_available,
            $skipped['duplicate'],
            $skipped['exists'],
            $skipped['stock'],
            count(array_filter($advertiser_counts))
        ));

    } catch (Exception $e) {
        error_log('Error in checkAndQueueProducts: ' . $e->getMessage());
    }
}


private function scheduleNextDripfeed()
{
    error_log('=== Scheduling Next Dripfeed ===');

    try {
        wp_cache_flush();
        clean_post_cache(0);
        
        $next_time = $this->calculateNextPublishTime();
    error_log('Next calculated time: ' . ($next_time ? $next_time->format('Y-m-d H:i:s') : 'null'));
    
        
        // If no valid time returned, schedule for tomorrow at 6 AM
        if ($next_time === null) {
            $timezone = new DateTimeZone(wp_timezone_string());
            $next_time = new DateTime('tomorrow 06:00:00', $timezone);
            error_log('Cannot schedule more posts today. Scheduling for tomorrow at 6 AM');
            error_log('calculateNextPublishTime returned null - checking why:');
            error_log('Posts today: ' . $this->getPostCountToday());
        }
        
        wp_clear_scheduled_hook('pfa_dripfeed_publisher');
        wp_schedule_single_event(
            $next_time->getTimestamp(),
            'pfa_dripfeed_publisher'
        );

        error_log("Scheduled next dripfeed for: " . $next_time->format('Y-m-d H:i:s T'));
        
        $next_scheduled = wp_next_scheduled('pfa_dripfeed_publisher');
        if ($next_scheduled) {
            error_log("Verified next schedule: " . date('Y-m-d H:i:s T', $next_scheduled));
        } else {
            error_log("Warning: Failed to verify next schedule");
        }
    } catch (Exception $e) {
        error_log("Error scheduling next dripfeed: " . $e->getMessage());
    }
}


public function getPostCountToday()
{
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
    error_log(sprintf(
        "PFA Post count for %s: %d (between %s and %s)",
        $today_start->format('Y-m-d'),
        $count,
        $today_start->format('Y-m-d H:i:s'),
        $today_end->format('Y-m-d H:i:s')
    ));

    return $count;
}

    public function addCustomSchedules($schedules)
    {
        $interval = max(30, (int)$this->dripfeedInterval);
        $key = 'every_' . $interval . '_minutes';

        if (!isset($schedules[$key])) {
            $schedules[$key] = array(
                'interval' => $interval * 60,
                'display' => sprintf(__('Every %d minutes'), $interval)
            );
        }

        return $schedules;
    }

    public function initializeSchedules()
{
    if (get_option('pfa_automation_enabled', 'yes') === 'yes') {
        // Clear existing API check schedule
        wp_clear_scheduled_hook('pfa_api_check');

        // Schedule API check based on interval setting
        $check_interval = get_option('check_interval', 'daily');
        if (!wp_next_scheduled('pfa_api_check')) {
            wp_schedule_event(time(), $check_interval, 'pfa_api_check');
            error_log("Scheduled API check with interval: {$check_interval}");
        }

        // Schedule daily check at midnight
        if (!wp_next_scheduled('pfa_daily_check')) {
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'pfa_daily_check');
        }

        // Retrieve the most recent scheduled post
        $last_scheduled = wp_get_recent_posts([
            'post_type' => 'post',
            'post_status' => 'future',
            'orderby' => 'post_date',
            'order' => 'DESC',
            'numberposts' => 1,
        ])[0]['post_date'] ?? null;

        error_log('Last scheduled post date for initialization: ' . ($last_scheduled ?: 'None'));

        // Schedule the first dripfeed
        if (!wp_next_scheduled('pfa_dripfeed_publisher')) {
            $next_time = $this->calculateNextPublishTime($last_scheduled);
        
            if ($next_time === null) {
                error_log("Could not determine next publish time - falling back to tomorrow at 06:00.");
                $timezone = new DateTimeZone(wp_timezone_string());
                $next_time = new DateTime('tomorrow 06:00:00', $timezone);
            }
        
            wp_schedule_single_event($next_time->getTimestamp(), 'pfa_dripfeed_publisher');
            error_log("Scheduled dripfeed for: " . $next_time->format('Y-m-d H:i:s'));
        }
    }

    $this->verifySchedules();
}


    private function verifySchedules()
    {
        $next_daily = wp_next_scheduled('pfa_daily_check');
        $next_dripfeed = wp_next_scheduled('pfa_dripfeed_publisher');

        error_log('Schedule verification:');
        error_log('- Daily check: ' . ($next_daily ? date('Y-m-d H:i:s', $next_daily) : 'not scheduled'));
        error_log('- Dripfeed: ' . ($next_dripfeed ? date('Y-m-d H:i:s', $next_dripfeed) : 'not scheduled'));
    }

    public function clearAllSchedules()
    {
        $hooks = [
            'pfa_dripfeed_publisher',
            'pfa_daily_check',
            'pfa_api_check'
        ];

        foreach ($hooks as $hook) {
            wp_clear_scheduled_hook($hook);
            error_log("Cleared schedule: $hook");
        }
    }
}
