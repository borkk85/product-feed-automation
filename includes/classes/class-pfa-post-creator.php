<?php

/**
 * Handles creation and management of posts.
 *
 * @since      1.0.0
 * @package    Product_Feed_Automation
 */

class PFA_Post_Creator
{

    /**
     * The single instance of the class.
     *
     * @since    1.0.0
     * @access   protected
     * @var      PFA_Post_Creator    $instance    The single instance of the class.
     */
    protected static $instance = null;

    /**
     * Post identifier meta key.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $post_identifier   Post identifier meta key.
     */
    private const POST_IDENTIFIER = '_pfa_v2_post';

    /**
     * Main PFA_Post_Creator Instance.
     *
     * Ensures only one instance of PFA_Post_Creator is loaded or can be loaded.
     *
     * @since    1.0.0
     * @return   PFA_Post_Creator    Main instance.
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
        add_action('before_delete_post', array($this, 'cleanup_meta'));
        add_action('template_redirect', array($this, 'handle_redirect'));
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
     * Create a product post.
     *
     * @since    1.0.0
     * @param    array     $product_data      Product data.
     * @param    array     $advertiser_data   Advertiser data.
     * @param    array     $schedule_data     Schedule data (optional).
     * @return   int|false                   Post ID or false on failure.
     */
    public function create_product_post($product_data, $advertiser_data, $schedule_data = null)
    {
        $this->log_message('Starting product post creation for product: ' . print_r($product_data['id'], true));

        // Basic availability check
        if (isset($product_data['availability']) && $product_data['availability'] !== 'in_stock') {
            $this->log_message('Product not in stock, skipping');
            return false;
        }

        // Check for duplicate
        $existing_post_id = $this->check_if_already_in_db($product_data['trackingLink']);
        if ($existing_post_id) {
            $this->log_message('Product already exists as post ID: ' . $existing_post_id);
            return array('status' => 'exists', 'post_id' => $existing_post_id);
        }

        // Clear any potential object cache to ensure data consistency
        wp_cache_flush();

        try {
            $product_id = $product_data['id'];
            $encoded_id = $this->encrypt_unique($product_id);
            $dynamic_url = site_url() . '/?pfa=' . urlencode($encoded_id);
            $dynamic_esc_url = esc_url($dynamic_url);

            $title = stripslashes($product_data['title']);
            $title = trim($title, '"');
            $this->log_message('Title after cleanup: ' . $title);

            $translated_title = $this->translate_text($title);
            if ($translated_title) {
                $this->log_message('Original product title: ' . $title);
                $this->log_message('Translated product title: ' . $translated_title);
                $product_data['title'] = $translated_title;
            } else {
                $this->log_message('Product title translation failed, using original: ' . $title);
                $product_data['title'] = $title;
            }

            $this->log_message('Generating content for product');
            $ai_response = $this->generate_content_from_ai(
                $product_data['title'],
                isset($product_data['description']) ? $product_data['description'] : null,
                true
            );

            if (!$ai_response || !is_array($ai_response) || !isset($ai_response['title']) || !isset($ai_response['content'])) {
                $this->log_message('Error generating AI content: ' . print_r($ai_response, true));
                $ai_response = array(
                    'title' => $product_data['title'],
                    'content' => 'This is a product listing for ' . $product_data['title'] . '.'
                );
                $this->log_message('Using fallback content');
            }

            $price_block = $this->generate_price_block($product_data, $dynamic_esc_url);
            $amazon_link_block = $this->amazon_block($product_data, $dynamic_esc_url, $advertiser_data, null);
            $date_block = $this->date_block();
            $commission_text = '<p>**Adealsweden makes commission on any purchases through the links.</p>';

            $post_content = $ai_response['content'] . "\n\n" .
                $amazon_link_block . "\n\n" .
                $date_block . "\n\n" .
                $commission_text;

            $this->log_message('Constructed post content length: ' . strlen($post_content));

            $post_data = array(
                'post_title'    => wp_strip_all_tags($ai_response['title']),
                'post_content'  => $post_content,
                'post_excerpt'  => $price_block,
                'post_status'   => 'publish', // Default to publish
                'post_author'   => 1,
                'post_type'     => 'post'
            );

            // Apply schedule data if provided
            if ($schedule_data) {
                foreach ($schedule_data as $key => $value) {
                    $post_data[$key] = $value;
                }
                $this->log_message("Scheduling post for: " . $schedule_data['post_date']);
            }

            $this->log_message('Inserting post with data: ' . print_r($post_data, true));

            // Actually insert the post
            $post_id = wp_insert_post($post_data, true);

            if (is_wp_error($post_id)) {
                $this->log_message('Failed to create post: ' . $post_id->get_error_message());
                return false;
            }

            $this->log_message('Successfully created post with ID: ' . $post_id);

            // Add post meta
            $metas = array(
                '_Amazone_produt_baseName' => $product_id,
                '_product_url' => $product_data['trackingLink'],
                'dynamic_amazone_link' => $dynamic_esc_url,
                'dynamic_link' => $dynamic_esc_url,
                '_discount_price' => $product_data['sale_price'],
                self::POST_IDENTIFIER => 'true' // This is the critical meta for flagging as our post
            );

            foreach ($metas as $key => $value) {
                $result = update_post_meta($post_id, $key, $value);
                if (!$result) {
                    $this->log_message("Warning: Failed to set meta '{$key}' for post {$post_id}");
                }
            }

            // Set featured image if available
            if (!empty($product_data['image_link'])) {
                $image_result = $this->set_featured_image($post_id, $product_data['image_link']);
                $this->log_message('Featured image result: ' . ($image_result ? 'success' : 'failed'));
            }

            // Set store type term
            if (!empty($advertiser_data['displayName'])) {
                $store_type_term = wp_set_object_terms($post_id, $advertiser_data['displayName'], 'store_type');

                if (!is_wp_error($store_type_term) && !empty($store_type_term)) {
                    $term_id = $store_type_term[0];

                    // Set logo image if available
                    if (!empty($advertiser_data['logoImageFilename'])) {
                        $logo_url = esc_url($advertiser_data['logoImageFilename']);

                        if (!empty($logo_url)) {
                            // Load required media handling functions
                            require_once(ABSPATH . 'wp-admin/includes/media.php');
                            require_once(ABSPATH . 'wp-admin/includes/file.php');
                            require_once(ABSPATH . 'wp-admin/includes/image.php');

                            $image_id = media_sideload_image($logo_url, 0, null, 'id');
                            if (!is_wp_error($image_id)) {
                                update_term_meta($term_id, 'featured_image', $image_id);
                                update_post_meta($image_id, '_brand_logo_image', '1');
                            }
                        }
                    }
                }
            }

            // Set discount tag
            $this->set_discount_tag($post_id, $product_data['price'], $product_data['sale_price']);

            // Set active-deals category for post
            $active_cat = get_term_by('slug', 'deals', 'category');
            if ($active_cat) {
                wp_set_post_categories($post_id, array($active_cat->term_id), true);
                $this->log_message("Set 'deals' category for post");
            } else {
                $this->log_message("Warning: Could not find 'deals' category");
            }

            // Verify the post was created and has the expected properties
            $post = get_post($post_id);
            if ($post) {
                $this->log_message("Verified post exists with status: {$post->post_status} and scheduled date: {$post->post_date}");

                // Force post cache clear to ensure data is written to DB
                clean_post_cache($post_id);

                // If post is scheduled, double check it's correctly set
                if ($post->post_status === 'future') {
                    $this->log_message("Post {$post_id} is scheduled for publication at {$post->post_date}");

                    // Verify future post queue
                    wp_publish_post($post_id);
                    wp_transition_post_status('future', 'publish', $post);

                    // Set it back to future with fresh dates
                    if (isset($schedule_data['post_date'])) {
                        wp_update_post(array(
                            'ID' => $post_id,
                            'post_status' => 'future',
                            'post_date' => $schedule_data['post_date'],
                            'post_date_gmt' => isset($schedule_data['post_date_gmt']) ?
                                $schedule_data['post_date_gmt'] :
                                get_gmt_from_date($schedule_data['post_date'])
                        ));
                        $this->log_message("Re-applied future status to post {$post_id}");
                    }
                }
            } else {
                $this->log_message("WARNING: Post {$post_id} does not exist after creation");
            }

            $this->log_message('Completed post creation successfully');
            if (isset($post_data['post_status']) && $post_data['post_status'] === 'future') {
                // Verify the post is actually scheduled
                global $wpdb;

                // Force WordPress to flush its cache and get fresh data
                clean_post_cache($post_id);
                wp_cache_flush();

                // Verify directly from the database
                $scheduled_post = $wpdb->get_row($wpdb->prepare(
                    "SELECT ID, post_status, post_date 
         FROM {$wpdb->posts} 
         WHERE ID = %d AND post_status = 'future'",
                    $post_id
                ));

                if ($scheduled_post) {
                    $this->log_message("Verified post ID {$post_id} is properly scheduled for {$scheduled_post->post_date}");
                } else {
                    $this->log_message("WARNING: Post ID {$post_id} verification failed - not properly scheduled!");

                    // Check actual status
                    $actual_post = $wpdb->get_row($wpdb->prepare(
                        "SELECT ID, post_status, post_date FROM {$wpdb->posts} WHERE ID = %d",
                        $post_id
                    ));

                    if ($actual_post) {
                        $this->log_message("Actual post status: {$actual_post->post_status}, date: {$actual_post->post_date}");

                        // If it's not future, try to fix it
                        if ($actual_post->post_status !== 'future') {
                            $wpdb->update(
                                $wpdb->posts,
                                array(
                                    'post_status' => 'future',
                                    'post_date' => $post_data['post_date'],
                                    'post_date_gmt' => isset($post_data['post_date_gmt']) ?
                                        $post_data['post_date_gmt'] :
                                        get_gmt_from_date($post_data['post_date'])
                                ),
                                array('ID' => $post_id)
                            );

                            $this->log_message("Attempted to fix scheduling for post ID {$post_id}");

                            // Clear cache again
                            clean_post_cache($post_id);
                            wp_cache_flush();
                        }
                    }
                }
            }
            return $post_id;
        } catch (Exception $e) {
            $this->log_message('Exception during post creation: ' . $e->getMessage());
            $this->log_message('Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Create a manual product post.
     *
     * @since    1.0.0
     * @param    string    $title              Post title.
     * @param    string    $featured_image     Featured image URL.
     * @param    string    $product_url        Product URL.
     * @param    float     $price              Original price.
     * @param    float     $sale_price         Sale price.
     * @param    string    $brand              Brand name.
     * @param    string    $category           Product category.
     * @param    string    $brand_image        Brand image URL (optional).
     * @param    int       $category_id        Category ID (optional).
     * @return   array                         Status array.
     */
    public function create_manual_product_post($title, $featured_image, $product_url, $price, $sale_price, $brand, $category, $brand_image = '', $category_id = null)
    {
        $this->log_message('Starting manual post creation with category: ' . $category);
        $this->log_message('Starting manual post creation with:');
        $this->log_message('Title: ' . $title);
        $this->log_message('Brand Image: ' . $brand_image);
        $this->log_message('Category ID: ' . ($category_id ?? 'null'));

        $title = stripslashes($title);
        $title = trim($title, '"');
        $this->log_message('Title after cleanup: ' . $title);

        if (!empty($title)) {
            $translated_title = $this->translate_text($title);
            if ($translated_title) {
                $this->log_message('Original title: ' . $title);
                $this->log_message('Translated title: ' . $translated_title);
                $title = $translated_title;
            } else {
                $this->log_message('Title translation failed, using original: ' . $title);
            }
        } else {
            $this->log_message('Empty title provided');
            return array('status' => 'error', 'message' => 'Empty title provided');
        }

        $existing_post_id = $this->check_if_already_in_db($product_url);
        $this->log_message('Duplicate check result - Post ID: ' . ($existing_post_id ? $existing_post_id : 'none'));

        if ($existing_post_id) {
            $this->log_message('Found duplicate post - stopping creation. Post ID: ' . $existing_post_id);
            return array('status' => 'exists', 'post_id' => $existing_post_id);
        }

        $path = parse_url($product_url, PHP_URL_PATH);
        $amazone_prod_basename = basename($path);

        $encoded_id = $this->encrypt_unique($amazone_prod_basename);
        $dynamic_url = site_url() . '/?pfa=' . urlencode($encoded_id);
        $dynamic_esc_url = esc_url($dynamic_url);
        $ai_response = $this->generate_content_from_ai($title);

        $product_data = array(
            'title' => $title,
            'price' => $price,
            'sale_price' => $sale_price,
            'advertiserDisplayName' => $brand,
            'image_link' => $brand_image
        );

        $price_block = $this->generate_price_block($product_data, $dynamic_esc_url);
        $amazon_link_block = $this->amazon_block($product_data, $dynamic_esc_url, null, $brand_image);
        $date_block = $this->date_block();
        $commission_text = '<p>**Adealsweden makes commission on any purchases through the links.</p>';

        $post_content = $ai_response['content'] . "\n\n" .
            $amazon_link_block . "\n\n" .
            $date_block . "\n\n" .
            $commission_text;

        $post_data = array(
            'post_title'    => wp_strip_all_tags($ai_response['title']),
            'post_content'  => $post_content,
            'post_excerpt'  => $price_block,
            'post_status'   => 'publish',
            'post_author'   => 1,
            'post_type'     => 'post'
        );

        $this->log_message('Constructed post content length: ' . strlen($post_content));
        $this->log_message('Inserting post with data: ' . print_r($post_data, true));

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            $this->log_message('Error creating post: ' . $post_id->get_error_message());
            return array('status' => 'error', 'message' => $post_id->get_error_message());
        }

        update_post_meta($post_id, '_Amazone_produt_baseName', $amazone_prod_basename);
        update_post_meta($post_id, '_product_url', $product_url);
        update_post_meta($post_id, 'dynamic_amazone_link', $dynamic_esc_url);
        update_post_meta($post_id, 'dynamic_link', $dynamic_esc_url);
        update_post_meta($post_id, '_discount_price', $sale_price);

        if (!empty($featured_image)) {
            $this->set_featured_image($post_id, $featured_image);
        }

        if (!empty($category)) {
            $this->log_message('Creating/getting category for: ' . $category);
            $category_id = $this->create_category($category);
            $this->log_message('Got category ID: ' . $category_id);

            if ($category_id > 0) {
                // Get the category hierarchy as terms
                $category_hierarchy = array_filter(array_map('trim', explode('>', $category)));
                $terms = array();

                // Get all terms in the hierarchy
                foreach ($category_hierarchy as $cat_name) {
                    $term = get_term_by('name', $cat_name, 'product_categories');
                    if ($term) {
                        $terms[] = $term->term_id;
                    }
                }

                $this->log_message('Category terms to set: ' . print_r($terms, true));

                // Set all terms in the hierarchy
                $result = wp_set_object_terms($post_id, $terms, 'product_categories', false);
                if (is_wp_error($result)) {
                    $this->log_message('Error setting category terms: ' . $result->get_error_message());
                } else {
                    $this->log_message('Category terms set successfully: ' . print_r($result, true));

                    // Log the actual terms that were set
                    $set_terms = wp_get_object_terms($post_id, 'product_categories', array('fields' => 'names'));
                    $this->log_message('Actual categories set: ' . print_r($set_terms, true));
                }
            }
        }

        $this->set_discount_tag($post_id, $price, $sale_price);

        if (!empty($brand)) {
            $store_type_term = wp_set_object_terms($post_id, $brand, 'store_type');
            if (!is_wp_error($store_type_term) && !empty($store_type_term)) {
                $term_id = $store_type_term[0];

                if (!empty($brand_image)) {
                    $this->log_message('Attempting to set brand image: ' . $brand_image);
                    require_once(ABSPATH . 'wp-admin/includes/media.php');
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                    require_once(ABSPATH . 'wp-admin/includes/image.php');

                    $image_id = media_sideload_image($brand_image, $post_id, null, 'id');
                    if (!is_wp_error($image_id)) {
                        update_term_meta($term_id, 'featured_image', $image_id);
                        update_post_meta($image_id, '_brand_logo_image', '1');
                        $this->log_message('Successfully set brand image. Image ID: ' . $image_id);
                    } else {
                        $this->log_message('Error setting brand image: ' . $image_id->get_error_message());
                    }
                }
            }
        }

        update_post_meta($post_id, self::POST_IDENTIFIER, 'true');
        $this->log_message('Successfully created post with ID: ' . $post_id);
        return array('status' => 'success', 'post_id' => $post_id);
    }

    /**
     * Set discount tag for a post.
     *
     * @since    1.0.0
     * @access   private
     * @param    int       $post_id         Post ID.
     * @param    float     $original_price  Original price.
     * @param    float     $sale_price      Sale price.
     */
    private function set_discount_tag($post_id, $original_price, $sale_price)
    {
        $discount_percentage = $this->calculate_discount($original_price, $sale_price);
        if ($discount_percentage > 0) {
            update_post_meta($post_id, '_discount_percentage', $discount_percentage);
            $discount_tag_title = $discount_percentage . '% off';
            $this->log_message("Discount Tag Title to search/create: {$discount_tag_title}");

            $term = term_exists($discount_tag_title, 'post_tag');
            if (!$term) {
                $term = wp_insert_term($discount_tag_title, 'post_tag');
                $this->log_message("Creating new tag: {$discount_tag_title}");
            } else {
                $this->log_message("Found existing tag for: {$discount_tag_title}");
            }

            if (!is_wp_error($term)) {
                wp_set_post_terms($post_id, array($discount_tag_title), 'post_tag', true);
                $this->log_message("Assigned tag '{$discount_tag_title}' to post {$post_id}");
            } else {
                $this->log_message('Discount Tag Error: ' . $term->get_error_message());
            }
        } else {
            $this->log_message('No discount to apply or prices are incorrect.');
        }
    }

    /**
     * Generate price block HTML.
     *
     * @since    1.0.0
     * @access   private
     * @param    array     $product_data   Product data.
     * @param    string    $display_url    Display URL.
     * @return   string                    Price block HTML.
     */
    private function generate_price_block($product_data, $display_url)
    {
        $original_price = round($product_data['price']);
        $discount_price = round(isset($product_data['sale_price']) ? $product_data['sale_price'] : $product_data['sale_price']);

        return '<div class="price_block"><a target="_blank" href="' . $display_url . '" rel="nofollow sponsored noopener"><span class="original-price">' . $original_price . ' SEK</span> <span class="discount-price">' . $discount_price . ' SEK</span></a></div>';
    }

    /**
     * Check if a product already exists in the database.
     *
     * @since    1.0.0
     * @param    string    $link    Product URL.
     * @return   int|false          Post ID if exists, false otherwise.
     */
    public function check_if_already_in_db($link)
    {
        $parsed_url = parse_url($link);
        $actual_product_url = $link;

        if (isset($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_params);
            if (isset($query_params['u'])) {
                $actual_product_url = urldecode($query_params['u']);
            }
        }

        $product_path = parse_url($actual_product_url, PHP_URL_PATH);
        $amazone_prod_basename = basename($product_path);

        global $wpdb;

        // Check for duplicates using both the product URL and basename
        $query = $wpdb->prepare(
            "SELECT p.ID, p.post_title 
                FROM {$wpdb->posts} p 
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE (
                    (pm.meta_key = '_product_url' AND (pm.meta_value = %s OR pm.meta_value = %s))
                    OR
                    (pm.meta_key = '_Amazone_produt_baseName' AND pm.meta_value = %s)
                )
                AND p.post_type = 'post' 
                AND p.post_status NOT IN ('trash', 'auto-draft')
                LIMIT 1",
            $link,
            $actual_product_url,
            $amazone_prod_basename
        );

        $result = $wpdb->get_row($query);

        if ($result) {
            return intval($result->ID);
        }

        $this->log_message('No existing post found');
        return false;
    }

    /**
     * Clean up post meta when a post is deleted.
     *
     * @since    1.0.0
     * @param    int       $post_id    Post ID.
     */
    public function cleanup_meta($post_id)
    {
        global $wpdb;

        $meta_keys = array(
            '_Amazone_produt_baseName',
            '_Amazone_produt_link',
            'dynamic_amazone_link',
            'dynamic_link',
            '_discount_price'
        );

        foreach ($meta_keys as $meta_key) {
            $wpdb->delete(
                $wpdb->postmeta,
                array(
                    'post_id' => $post_id,
                    'meta_key' => $meta_key
                )
            );
        }
    }

    /**
     * Check if an image exists for a post.
     *
     * @since    1.0.0
     * @access   private
     * @param    string    $image_url    Image URL.
     * @param    int       $post_id      Post ID.
     * @return   boolean                 True if image exists, false otherwise.
     */
    private function image_exists($image_url, $post_id)
    {
        $args = array(
            'post_type' => 'attachment',
            'post_parent' => $post_id,
            'meta_query' => array(
                array(
                    'key' => '_wp_attached_file',
                    'value' => basename($image_url),
                    'compare' => 'LIKE'
                )
            )
        );
        $existing_images = get_posts($args);
        return !empty($existing_images);
    }

    /**
     * Set featured image for a post.
     *
     * @since    1.0.0
     * @param    int       $post_id      Post ID.
     * @param    string    $image_url    Image URL.
     * @param    string    $post_title   Post title (optional).
     * @return   boolean                 True on success, false on failure.
     */
    public function set_featured_image($post_id, $image_url, $post_title = '')
    {
        if (empty($image_url) || empty($post_id)) {
            return false;
        }

        if (!$this->image_exists($image_url, $post_id)) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            $image_id = media_sideload_image($image_url, $post_id, $post_title, 'id');
            if (!is_wp_error($image_id)) {
                set_post_thumbnail($post_id, $image_id);
                return true;
            } else {
                $this->log_message("Failed to set featured image for post ID $post_id: " . $image_id->get_error_message());
                return false;
            }
        } else {
            $this->log_message("Image already exists for post ID $post_id. Skipping featured image update.");
            return false;
        }
    }

    /**
     * Create a category hierarchy.
     *
     * @since    1.0.0
     * @param    string|array    $category_name    Category name or hierarchy.
     * @return   int                               Last term ID in hierarchy.
     */
    public function create_category($category_name)
    {
        if (!is_array($category_name)) {
            $category_hierarchy = array_filter(array_map('trim', explode('>', $category_name)));
        } else {
            $category_hierarchy = array_filter(array_map('trim', $category_name));
        }

        $parent_id = 0;
        $last_term_id = 0;

        foreach ($category_hierarchy as $category_name) {
            $category_slug = sanitize_title($category_name);
            $existing_term = term_exists($category_slug, 'product_categories', $parent_id);

            if (!$existing_term) {
                $new_term = wp_insert_term($category_name, 'product_categories', array(
                    'slug' => $category_slug,
                    'parent' => $parent_id
                ));

                if (is_wp_error($new_term)) {
                    $this->log_message('Error creating category: ' . $new_term->get_error_message());
                    continue;
                }

                $last_term_id = $new_term['term_id'];
            } else {
                $last_term_id = $existing_term['term_id'];
            }

            $parent_id = $last_term_id;
        }

        return $last_term_id;
    }

    /**
     * Calculate discount percentage.
     *
     * @since    1.0.0
     * @param    float     $original_price    Original price.
     * @param    float     $sale_price        Sale price.
     * @return   int                          Discount percentage.
     */
    public function calculate_discount($original_price, $sale_price)
    {
        if ($original_price <= 0 || $sale_price >= $original_price) {
            return 0;
        }
        $percentage = (($original_price - $sale_price) / $original_price) * 100;
        return round($percentage / 5) * 5;
    }

    /**
     * Generate Amazon block HTML.
     *
     * @since    1.0.0
     * @access   private
     * @param    array     $product_data      Product data.
     * @param    string    $display_url       Display URL.
     * @param    array     $advertiser_data   Advertiser data (optional).
     * @param    string    $brand_image       Brand image URL (optional).
     * @return   string                       Amazon block HTML.
     */
    private function amazon_block($product_data, $display_url, $advertiser_data = null, $brand_image = null)
    {
        $this->log_message('Product Data: ' . print_r($product_data, true));
        $this->log_message('Advertiser Data: ' . print_r($advertiser_data, true));
        $this->log_message('Manual Brand Image: ' . $brand_image);

        $original_price = round($product_data['price']);
        $discount_price = round(isset($product_data['sale_price']) ? $product_data['sale_price'] : $product_data['sale_price']);

        $default_logo = 'https://www.adealsweden.com/wp-content/uploads/2023/12/amazon_se_logo_RGB_REV-1.png';

        if (!empty($brand_image)) {
            $advertiser_logo = $brand_image;
            $this->log_message('Using manual brand image: ' . $advertiser_logo);
        } elseif (!empty($advertiser_data['logoImageFilename'])) {
            $advertiser_logo = $advertiser_data['logoImageFilename'];
            $this->log_message('Using advertiser logo: ' . $advertiser_logo);
        } else {
            $advertiser_logo = $default_logo;
            $this->log_message('Using default logo');
        }

        $advertiser_name = isset($advertiser_data['displayName']) ? $advertiser_data['displayName'] : (isset($product_data['brand']) ? $product_data['brand'] : 'Retailer');

        return '<div class="product-link_wrap" data-href="' . $display_url . '" target="_blank" rel="nofollow sponsored">
                <div class="button-block">
                    <div class="button-image"><img src="' . esc_url($advertiser_logo) . '" alt="' . esc_attr($advertiser_name) . ' Logo" class="webpexpress-processed"></div>
                    <div class="product-title"><p class="product-name">' . esc_html($product_data['title']) . '</p></div>
                    <div class="prices-container">
                        <span class="original-price">' . esc_html($original_price) . ' SEK</span>
                        <span class="discount-price">' . esc_html($discount_price) . ' SEK</span>
                    </div>
                    <div class="button-container">
                        <button class="product-button">
                            <span>Go to Product</span>
                            <svg xmlns="http://www.w3.org/2000/svg" enable-background="new 0 0 24 24" viewBox="0 0 24 24">
                                <path d="M15.5,11.3L9.9,5.6c-0.4-0.4-1-0.4-1.4,0s-0.4,1,0,1.4l4.9,4.9l-4.9,4.9c-0.2,0.2-0.3,0.4-0.3,0.7c0,0.6,0.4,1,1,1c0.3,0,0.5-0.1,0.7-0.3l5.7-5.7c0,0,0,0,0,0C15.9,12.3,15.9,11.7,15.5,11.3z"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>';
    }

    /**
     * Generate date block HTML.
     *
     * @since    1.0.0
     * @access   private
     * @return   string    Date block HTML.
     */
    private function date_block()
    {
        return '<p> **Price last checked ' . current_time('Y-m-d H:i') . ' ' . wp_timezone_string() . '</p>';
    }

    /**
     * Handle redirect for dynamic product URLs.
     *
     * @since    1.0.0
     */
    public function handle_redirect()
    {
        if (isset($_GET['pfa'])) {
            $encoded = sanitize_text_field($_GET['pfa']);
            $amazone_prod_basename = $this->decrypt_unique($encoded);

            global $wpdb;

            // Updated query to retrieve the correct product URL based on base name
            $product_url = $wpdb->get_var($wpdb->prepare(
                "SELECT pm2.meta_value 
                    FROM {$wpdb->postmeta} pm1 
                    JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id 
                    WHERE pm1.meta_key = '_Amazone_produt_baseName' 
                    AND pm1.meta_value = %s 
                    AND pm2.meta_key = '_product_url'
                    LIMIT 1",
                $amazone_prod_basename
            ));

            if ($product_url) {
                wp_redirect($product_url);
                exit;
            }
        }
    }

    /**
     * Encrypt a string for unique URL.
     *
     * @since    1.0.0
     * @access   private
     * @param    string    $string    String to encrypt.
     * @return   string               Encrypted string.
     */
    private function encrypt_unique($string)
    {
        return rtrim(strtr(base64_encode($string), '+/', '-_'), '=');
    }

    /**
     * Decrypt a unique URL string.
     *
     * @since    1.0.0
     * @access   private
     * @param    string    $string    String to decrypt.
     * @return   string               Decrypted string.
     */
    private function decrypt_unique($string)
    {
        return base64_decode(str_pad(strtr($string, '-_', '+/'), strlen($string) % 4, '=', STR_PAD_RIGHT));
    }

    /**
     * Generate a short URL.
     *
     * @since    1.0.0
     * @param    string    $amaz_url    URL to shorten.
     * @return   string                 Shortened URL.
     */
    public function generate_short_url($amaz_url)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.apilayer.com/short_url/hash",
            CURLOPT_HTTPHEADER => array(
                "Content-Type: text/plain",
                "apikey: YOUR_API_KEY" // Replace with proper API key management
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $amaz_url
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            $this->log_message("cURL Error in generate_short_url: " . $err);
            return $amaz_url;
        }

        $url_data = json_decode($response);
        return $url_data->short_url;
    }

    /**
     * Translate text using Google Translate API.
     *
     * @since    1.0.0
     * @param    string    $text             Text to translate.
     * @param    string    $target_language  Target language (default: 'en').
     * @return   string|false               Translated text or false on failure.
     */
    public function translate_text($text)
    {
        if (empty($text)) {
            $this->log_message('Empty text provided for translation');
            return false;
        }

        $text = trim(stripslashes($text));
        $text = strip_tags($text);

        $this->log_message('Translation input text: ' . $text);

        $curlSession = curl_init();
        $encoded_text = urlencode($text);
        $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=sv&tl=en&dt=t&q=' . $encoded_text;

        curl_setopt($curlSession, CURLOPT_URL, $url);
        curl_setopt($curlSession, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curlSession);

        if (curl_errno($curlSession)) {
            $this->log_message('Translation curl error: ' . curl_error($curlSession));
            curl_close($curlSession);
            return false;
        }

        $this->log_message('Translation API response: ' . $response);

        $jsonData = json_decode($response);
        curl_close($curlSession);

        if ($jsonData && isset($jsonData[0])) {
            $translated = '';

            foreach ($jsonData[0] as $part) {
                if (isset($part[0])) {
                    $translated .= $part[0];
                }
            }

            $translated = trim($translated);
            $this->log_message('Successfully translated text: ' . $translated);
            return !empty($translated) ? $translated : $text;
        }

        $this->log_message('Translation failed - Invalid JSON response');
        return $text;
    }

    /**
     * Generate content using AI.
     *
     * @since    1.0.0
     * @param    string     $blogs_title           Blog post title.
     * @param    string     $product_description   Product description (optional).
     * @param    boolean    $is_automated          Whether this is automated (optional).
     * @return   array|string                      AI response or error message.
     */
    public function generate_content_from_ai($blogs_title, $product_description = null, $is_automated = false)
    {
        $blogs_title = strtr($blogs_title, array(
            utf8_encode('�') => "ö",
            utf8_encode('�') => "å",
            utf8_encode('�') => "ä",
            utf8_encode('�') => "A",
            utf8_encode('�') => "O",
            utf8_encode('�') => "ae",
            utf8_encode('�') => "a",
            utf8_encode('�') => "o",
            utf8_encode('�') => "r"
        ));

        $ai_api_key = get_option('ai_api_key');
        $max_tokens = (float) get_option('max_tokens', 1000);
        $temperature = (float) get_option('temperature', 0.7);
        $frequency_penalty = (float) get_option('frequency_penalty', 0);
        $presence_penalty = (float) get_option('presence_penalty', 0);

        if ($is_automated && $product_description) {
            $prompt_for_ai = "As an SEO-copywriter expert, translate if needed title to short and concise title, keeping as much from original input as possible (but all must be English). And write a fact based 500+ words description based on information in input, three paragraphs with no fluff in English. Format as Title: Output Description: Output. Do not treat the first words in input as brand, blacklisted topics & words are English, Note, Guide, Enhance, Block and SEO in output, do not end with a CTA or Note for. Product title: {$blogs_title}. Product description: {$product_description}";
        } else {
            $prompt_for_ai = get_option('prompt_for_ai', 'Write a product description for');
            $prompt_for_ai .= ': ' . $blogs_title;
        }

        $this->log_message('The Prompt: ' . $prompt_for_ai);

        $ai_model = get_option('ai_model', 'gpt-3.5-turbo');
        $url = "https://api.openai.com/v1/chat/completions";

        $headers = array(
            'Authorization: Bearer ' . $ai_api_key,
            'Content-Type: application/json',
        );

        $data = array(
            "model" => $ai_model,
            "messages" => array(
                array(
                    "role" => "system",
                    "content" => "You are a helpful assistant and a professional product copywriter"
                ),
                array(
                    "role" => "user",
                    "content" => $prompt_for_ai
                )
            ),
            "max_tokens" => $max_tokens,
            "temperature" => $temperature,
            "frequency_penalty" => $frequency_penalty,
            "presence_penalty" => $presence_penalty
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            $response_data = json_decode($response, true);
            $generated_text = $response_data['choices'][0]['message']['content'];

            $this->log_message('Raw AI response: ' . $generated_text);

            if (str_contains($generated_text, 'Title:')) {
                $title_parts = explode('Title:', trim($generated_text));
                $content_split = explode('Description:', end($title_parts));

                $title = trim(str_replace(array('Output', '**'), '', $content_split[0]));
                $content = trim($content_split[1]);

                return array(
                    'title' => $title,
                    'content' => $content
                );
            } else {
                $content_split = explode(':', trim($generated_text));
                return array(
                    'title' => trim($content_split[1]),
                    'content' => trim($content_split[2])
                );
            }
        } else {
            $error_message = "HTTP Error: " . $http_code . "\n";
            $error_message .= "Response: " . $response . "\n";
            $error_message .= "API Key (first 5 chars): " . substr($ai_api_key, 0, 5) . "...\n";
            $error_message .= "AI Model: " . $ai_model . "\n";
            $this->log_message($error_message);
            return $error_message;
        }
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
            error_log('[PFA Creator] ' . $message);
        }
    }
}
