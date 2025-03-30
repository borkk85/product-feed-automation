<?php

class PostCreator
{
    private static $instance = null;
    private const PFA_POST_IDENTIFIER = '_pfa_v2_post';

    public function __construct()
    {
        add_action('before_delete_post', [$this, 'cleanupMeta']);
        add_action('template_redirect', [$this, 'handleRedirect']);
    }

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __clone() {}

    public function __wakeup() {}

    public function createProductPost($product_data, $advertiser_data, $schedule_data = null)
    {
        error_log('Starting product post creation for product: ' . print_r($product_data['id'], true));

    
        // Basic availability check remains as a safeguard
        if ($product_data['availability'] !== 'in_stock') {
            error_log('Product not in stock, skipping');
            return false;
        }
    
        $existing_post_id = $this->checkIfAlreadyInDb($product_data['trackingLink']);
        if ($existing_post_id) {
            error_log('Product already exists as post ID: ' . $existing_post_id);
            return array('status' => 'exists', 'post_id' => $existing_post_id);
        }
        

        $product_id = $product_data['id'];
        $encoded_id = $this->encryptUnique($product_id);
        $dynamic_url = site_url() . '/?pfa=' . urlencode($encoded_id);
        $dynamic_esc_url = esc_url($dynamic_url);

        $title = stripslashes($product_data['title']);
        $title = trim($title, '"');
        error_log('Title after cleanup: ' . $title);

        $translated_title = $this->translateText($title);
        if ($translated_title) {
            error_log('Original product title: ' . $title);
            error_log('Translated product title: ' . $translated_title);
            $product_data['title'] = $translated_title;
        } else {
            error_log('Product title translation failed, using original: ' . $title);
            $product_data['title'] = $title;
        }

        error_log('Generating content for product');
        $ai_response = $this->generateContentFromAi(
            $product_data['title'],
            $product_data['description'] ?? null,
            true
        );

        $price_block = $this->generatePriceBlock($product_data, $dynamic_esc_url);
        $amazon_link_block = $this->amazonBlock($product_data, $dynamic_esc_url, $advertiser_data, null);
        $date_block = $this->dateBlock();
        $commission_text = '<p>**Adealsweden makes commission on any purchases through the links.</p>';

        $post_content = $ai_response['content'] . "\n\n" .
            $amazon_link_block . "\n\n" .
            $date_block . "\n\n" .
            $commission_text;

        error_log('Constructed post content length: ' . strlen($post_content));

        $post_data = array(
            'post_title'    => wp_strip_all_tags($ai_response['title']),
            'post_content'  => $post_content,
            'post_excerpt'  => $price_block,
            'post_status'  => $schedule_data['post_status'],
            'post_date'    => $schedule_data['post_date'], 
            'post_author'   => 1,
            'post_type'     => 'post'
        );

        if ($schedule_data) {
            $post_data = array_merge($post_data, $schedule_data);
            error_log("Scheduling post for: " . $schedule_data['post_date']);
        } else {
            $post_data['post_status'] = 'publish';
        }

        error_log('Inserting post with data: ' . print_r($post_data, true));

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            error_log('Failed to create post: ' . $post_id->get_error_message());
            return false;
        }

        error_log('Successfully created post with ID: ' . $post_id);

        update_post_meta($post_id, '_Amazone_produt_baseName', $product_id);
        update_post_meta($post_id, '_product_url', $product_data['trackingLink']);
        update_post_meta($post_id, 'dynamic_amazone_link', $dynamic_esc_url);
        update_post_meta($post_id, 'dynamic_link', $dynamic_esc_url);
        update_post_meta($post_id, '_discount_price', $product_data['sale_price']);

        if (!empty($product_data['image_link'])) {
            $this->setFeatured($post_id, $product_data['image_link']);
        }

        if (!empty($advertiser_data['displayName'])) {
            $store_type_term = wp_set_object_terms($post_id, $advertiser_data['displayName'], 'store_type');
            if (!is_wp_error($store_type_term) && !empty($store_type_term)) {
                $term_id = $store_type_term[0];
                $current_image_id = get_term_meta($term_id, 'featured_image', true);

                if (empty($current_image_id)) {
                    $logo_url = isset($advertiser_data['logoImageFilename']) ?
                        esc_url($advertiser_data['logoImageFilename']) : '';

                    if (!empty($logo_url)) {
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

        // if (!empty($product_data['google_product_category'])) {
        //     // Skip if numeric (Google taxonomy ID)
        //     if (is_numeric($product_data['google_product_category'])) {
        //         error_log('Skipping numeric Google taxonomy ID: ' . $product_data['google_product_category']);
        //         return;
        //     }

        //     $category = $product_data['google_product_category'];
        //     error_log('Processing category string: ' . $category);

        //     $category_id = $this->createCategory($category);
        //     error_log('Got category ID: ' . $category_id);

        //     if ($category_id > 0) {
        //         // Get the category hierarchy as terms
        //         $category_hierarchy = array_filter(array_map('trim', explode('>', $category)));
        //         $terms = [];

        //         // Get all terms in the hierarchy
        //         foreach ($category_hierarchy as $cat_name) {
        //             $term = get_term_by('name', $cat_name, 'product_categories');
        //             if ($term) {
        //                 $terms[] = $term->term_id;
        //             }
        //         }

        //         error_log('Category terms to set: ' . print_r($terms, true));

        //         // Set all terms in the hierarchy
        //         $result = wp_set_object_terms($post_id, $terms, 'product_categories', false);
        //         if (is_wp_error($result)) {
        //             error_log('Error setting category terms: ' . $result->get_error_message());
        //         } else {
        //             error_log('Category terms set successfully: ' . print_r($result, true));

        //             $set_terms = wp_get_object_terms($post_id, 'product_categories', array('fields' => 'names'));
        //             error_log('Actual categories set: ' . print_r($set_terms, true));
        //         }
        //     }
        // }

        $this->setDiscountTag($post_id, $product_data['price'], $product_data['sale_price']);
        update_post_meta($post_id, self::PFA_POST_IDENTIFIER, 'true');
        error_log('Completed post creation successfully');
        return $post_id;

    }


    public function createManualProductPost($title, $featured_image, $product_url, $price, $sale_price, $brand, $category, $brand_image = '', $category_id = null)
    {
        error_log('Starting manual post creation with category: ' . $category);
        error_log('Starting manual post creation with:');
        error_log('Title: ' . $title);
        error_log('Brand Image: ' . $brand_image);
        error_log('Category ID: ' . ($category_id ?? 'null'));

        $title = stripslashes($title);
        $title = trim($title, '"');
        error_log('Title after cleanup: ' . $title);

        if (!empty($title)) {
            $translated_title = $this->translateText($title);
            if ($translated_title) {
                error_log('Original title: ' . $title);
                error_log('Translated title: ' . $translated_title);
                $title = $translated_title;
            } else {
                error_log('Title translation failed, using original: ' . $title);
            }
        } else {
            error_log('Empty title provided');
            return array('status' => 'error', 'message' => 'Empty title provided');
        }


        $existing_post_id = $this->checkIfAlreadyInDb($product_url);
        error_log('Duplicate check result - Post ID: ' . ($existing_post_id ? $existing_post_id : 'none'));

        if ($existing_post_id) {
            error_log('Found duplicate post - stopping creation. Post ID: ' . $existing_post_id);
            return array('status' => 'exists', 'post_id' => $existing_post_id);
        }

        $path = parse_url($product_url, PHP_URL_PATH);
        $amazone_prod_basename = basename($path);

        $encoded_id = $this->encryptUnique($amazone_prod_basename);
        $dynamic_url = site_url() . '/?pfa=' . urlencode($encoded_id);
        $dynamic_esc_url = esc_url($dynamic_url);
        $ai_response = $this->generateContentFromAi($title);

        $product_data = [
            'title' => $title,
            'price' => $price,
            'sale_price' => $sale_price,
            'advertiserDisplayName' => $brand,
            'image_link' => $brand_image
        ];

        $price_block = $this->generatePriceBlock($product_data, $dynamic_esc_url);
        $amazon_link_block = $this->amazonBlock($product_data, $dynamic_esc_url, null, $brand_image);
        $date_block = $this->dateBlock();
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

        error_log('Constructed post content length: ' . strlen($post_content));

        error_log('Inserting post with data: ' . print_r($post_data, true));

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            error_log('Error creating post: ' . $post_id->get_error_message());
            return array('status' => 'error', 'message' => $post_id->get_error_message());
        }

        update_post_meta($post_id, '_Amazone_produt_baseName', $amazone_prod_basename);
        update_post_meta($post_id, '_product_url', $product_url);
        update_post_meta($post_id, 'dynamic_amazone_link', $dynamic_esc_url);
        update_post_meta($post_id, 'dynamic_link', $dynamic_esc_url);
        update_post_meta($post_id, '_discount_price', $sale_price);

        if (!empty($featured_image)) {
            $this->setFeatured($post_id, $featured_image);
        }

        if (!empty($category)) {
            error_log('Creating/getting category for: ' . $category);
            $category_id = $this->createCategory($category);
            error_log('Got category ID: ' . $category_id);

            if ($category_id > 0) {
                // Get the category hierarchy as terms
                $category_hierarchy = array_filter(array_map('trim', explode('>', $category)));
                $terms = [];

                // Get all terms in the hierarchy
                foreach ($category_hierarchy as $cat_name) {
                    $term = get_term_by('name', $cat_name, 'product_categories');
                    if ($term) {
                        $terms[] = $term->term_id;
                    }
                }

                error_log('Category terms to set: ' . print_r($terms, true));

                // Set all terms in the hierarchy
                $result = wp_set_object_terms($post_id, $terms, 'product_categories', false);
                if (is_wp_error($result)) {
                    error_log('Error setting category terms: ' . $result->get_error_message());
                } else {
                    error_log('Category terms set successfully: ' . print_r($result, true));

                    // Log the actual terms that were set
                    $set_terms = wp_get_object_terms($post_id, 'product_categories', array('fields' => 'names'));
                    error_log('Actual categories set: ' . print_r($set_terms, true));
                }
            }
        }

        $this->setDiscountTag($post_id, $price, $sale_price);

        if (!empty($brand)) {
            $store_type_term = wp_set_object_terms($post_id, $brand, 'store_type');
            if (!is_wp_error($store_type_term) && !empty($store_type_term)) {
                $term_id = $store_type_term[0];

                if (!empty($brand_image)) {
                    error_log('Attempting to set brand image: ' . $brand_image);
                    require_once(ABSPATH . 'wp-admin/includes/media.php');
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                    require_once(ABSPATH . 'wp-admin/includes/image.php');

                    $image_id = media_sideload_image($brand_image, $post_id, null, 'id');
                    if (!is_wp_error($image_id)) {
                        update_term_meta($term_id, 'featured_image', $image_id);
                        update_post_meta($image_id, '_brand_logo_image', '1');
                        error_log('Successfully set brand image. Image ID: ' . $image_id);
                    } else {
                        error_log('Error setting brand image: ' . $image_id->get_error_message());
                    }
                }
            }
        }

        error_log('Successfully created post with ID: ' . $post_id);
        return array('status' => 'success', 'post_id' => $post_id);
    }

    private function setDiscountTag($post_id, $original_price, $sale_price)
    {
        $discount_percentage = $this->calculateDiscount($original_price, $sale_price);
        if ($discount_percentage > 0) {
            update_post_meta($post_id, '_discount_percentage', $discount_percentage);
            $discount_tag_title = $discount_percentage . '% off';
            error_log("Discount Tag Title to search/create: {$discount_tag_title}");

            $term = term_exists($discount_tag_title, 'post_tag');
            if (!$term) {
                $term = wp_insert_term($discount_tag_title, 'post_tag');
                error_log("Creating new tag: {$discount_tag_title}");
            } else {
                error_log("Found existing tag for: {$discount_tag_title}");
            }

            if (!is_wp_error($term)) {
                wp_set_post_terms($post_id, [$discount_tag_title], 'post_tag', true);
                error_log("Assigned tag '{$discount_tag_title}' to post {$post_id}");
            } else {
                error_log('Discount Tag Error: ' . $term->get_error_message());
            }
        } else {
            error_log('No discount to apply or prices are incorrect.');
        }
    }

    private function generatePriceBlock($product_data, $display_url)
    {
        $original_price = round($product_data['price']);
        $discount_price = round($product_data['sale_price'] ?? $product_data['sale_price']);

        return '<div class="price_block"><a target="_blank" href="' . $display_url . '" rel="nofollow sponsored noopener"><span class="original-price">' . $original_price . ' SEK</span> <span class="discount-price">' . $discount_price . ' SEK</span></a></div>';
    }

    public function checkIfAlreadyInDb($link)
    {
        // error_log('Checking for duplicate post with link: ' . $link);

        $parsed_url = parse_url($link);
        $actual_product_url = $link;

        if (isset($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_params);
            if (isset($query_params['u'])) {
                $actual_product_url = urldecode($query_params['u']);
                // error_log('Decoded actual product URL: ' . $actual_product_url);
            }
        }

        $product_path = parse_url($actual_product_url, PHP_URL_PATH);
        $amazone_prod_basename = basename($product_path);
        // error_log('Extracted basename: ' . $amazone_prod_basename);

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

        // error_log('Executing duplicate check query: ' . $query);
        $result = $wpdb->get_row($query);

        if ($result) {
            // error_log('Found existing post - ID: ' . $result->ID . ', Title: ' . $result->post_title);
            return intval($result->ID);
        }

        error_log('No existing post found');
        return false;
    }

    public function cleanupMeta($post_id)
    {
        global $wpdb;

        $meta_keys = [
            '_Amazone_produt_baseName',
            '_Amazone_produt_link',
            'dynamic_amazone_link',
            'dynamic_link',
            '_discount_price'
        ];

        foreach ($meta_keys as $meta_key) {
            $wpdb->delete(
                $wpdb->postmeta,
                [
                    'post_id' => $post_id,
                    'meta_key' => $meta_key
                ]
            );
        }
    }


    public function imageExists($image_url, $post_id)
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

    public function setFeatured($post_id, $image_url, $post_title = '')
    {
        if (empty($image_url) || empty($post_id)) {
            return false;
        }

        if (!$this->imageExists($image_url, $post_id)) {
            $image_id = media_sideload_image($image_url, $post_id, $post_title, 'id');
            if (!is_wp_error($image_id)) {
                set_post_thumbnail($post_id, $image_id);
                return true;
            } else {
                error_log("Failed to set featured image for post ID $post_id: " . $image_id->get_error_message());
                return false;
            }
        } else {
            error_log("Image already exists for post ID $post_id. Skipping featured image update.");
            return false;
        }
    }

    public function createCategory($category_name)
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
                    error_log('Error creating category: ' . $new_term->get_error_message());
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

    public function calculateDiscount($original_price, $sale_price)
    {
        if ($original_price <= 0 || $sale_price >= $original_price) {
            return 0;
        }
        $percentage = (($original_price - $sale_price) / $original_price) * 100;
        return round($percentage / 5) * 5;
    }

    public function amazonBlock($product_data, $display_url, $advertiser_data = null, $brand_image = null)
    {
        error_log('Product Data: ' . print_r($product_data, true));
        error_log('Advertiser Data: ' . print_r($advertiser_data, true));
        error_log('Manual Brand Image: ' . $brand_image);

        $original_price = round($product_data['price']);
        $discount_price = round($product_data['sale_price'] ?? $product_data['sale_price']);

        $default_logo = 'https://www.adealsweden.com/wp-content/uploads/2023/12/amazon_se_logo_RGB_REV-1.png';

        if (!empty($brand_image)) {
            $advertiser_logo = $brand_image;
            error_log('Using manual brand image: ' . $advertiser_logo);
        } elseif (!empty($advertiser_data['logoImageFilename'])) {
            $advertiser_logo = $advertiser_data['logoImageFilename'];
            error_log('Using advertiser logo: ' . $advertiser_logo);
        } else {
            $advertiser_logo = $default_logo;
            error_log('Using default logo');
        }

        $advertiser_name = $advertiser_data['displayName'] ?? $product_data['brand'] ?? 'Retailer';

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

    public function dateBlock()
    {
        return '<p> **Price last checked ' . current_time('Y-m-d H:i') . ' ' . wp_timezone_string() . '</p>';
    }


    public function handleRedirect()
    {
        if (isset($_GET['pfa'])) {
            $encoded = sanitize_text_field($_GET['pfa']);
            $amazone_prod_basename = $this->decryptUnique($encoded);

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

    public function encryptUnique($string)
    {
        return rtrim(strtr(base64_encode($string), '+/', '-_'), '=');
    }

    public function decryptUnique($string)
    {
        return base64_decode(str_pad(strtr($string, '-_', '+/'), strlen($string) % 4, '=', STR_PAD_RIGHT));
    }

    public function generateShortUrl($amaz_url)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.apilayer.com/short_url/hash",
            CURLOPT_HTTPHEADER => array(
                "Content-Type: text/plain",
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
            error_log("cURL Error in pfa_generate_short_url_using_api: " . $err);
            return $amaz_url;
        }

        $url_data = json_decode($response);
        return $url_data->short_url;
    }

    public function pfa_translate_content_with_google($content, $target_language = 'sv')
    {
        $url = 'https://translation.googleapis.com/language/translate/v2';
        $args = array(
            'body' => json_encode(array(
                'q' => $content,
                'target' => $target_language,
                'format' => 'text'
            )),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'method' => 'POST',
            'data_format' => 'body',
        );

        $response = wp_remote_post($url, $args);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['data']['translations'][0]['translatedText'])) {
            return $data['data']['translations'][0]['translatedText'];
        }

        return false;
    }

    public function translateText($text)
    {
        if (empty($text)) {
            error_log('Empty text provided for translation');
            return false;
        }


        $text = trim(stripslashes($text));
        $text = strip_tags($text);

        error_log('Translation input text: ' . $text);

        $curlSession = curl_init();
        $encoded_text = urlencode($text);
        $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=sv&tl=en&dt=t&q=' . $encoded_text;

        curl_setopt($curlSession, CURLOPT_URL, $url);
        curl_setopt($curlSession, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curlSession);

        if (curl_errno($curlSession)) {
            error_log('Translation curl error: ' . curl_error($curlSession));
            curl_close($curlSession);
            return false;
        }

        error_log('Translation API response: ' . $response);

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
            error_log('Successfully translated text: ' . $translated);
            return !empty($translated) ? $translated : $text;
        }

        error_log('Translation failed - Invalid JSON response');
        return $text;
    }

    public function generateContentFromAi($blogs_title, $product_description = null, $is_automated = false)
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
        $max_tokens = (float) get_option('max_tokens');
        $temperature = (float) get_option('temperature');
        $frequency_penalty = (float) get_option('frequency_penalty');
        $presence_penalty = (float) get_option('presence_penalty');

        if ($is_automated && $product_description) {
            $prompt_for_ai = "As an SEO-copywriter expert, translate if needed title to short and concise title, keeping as much from original input as possible (but all must be English). And write a fact based 500+ words description based on information in input, three paragraphs with no fluff in English. Format as Title: Output Description: Output. Do not treat the first words in input as brand, blacklisted topics & words are English, Note, Guide, Enhance, Block and SEO in output, do not end with a CTA or Note for. Product title: {$blogs_title}. Product description: {$product_description}";
        } else {
            $prompt_for_ai = get_option('prompt_for_ai');
            $prompt_for_ai .= ': ' . $blogs_title;
        }

        error_log('The Prompt: ' . $prompt_for_ai);

        $ai_model = get_option('ai_model');
        $url = "https://api.openai.com/v1/chat/completions";

        $headers = [
            'Authorization: Bearer ' . $ai_api_key,
            'Content-Type: application/json',
        ];

        $data = array(
            "model" => $ai_model,
            "messages" => [
                array(
                    "role" => "system",
                    "content" => "You are a helpful assistant and a professional product copywriter"
                ),
                array(
                    "role" => "user",
                    "content" => $prompt_for_ai
                )
            ],
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

            error_log('Raw AI response: ' . $generated_text);

            if (str_contains($generated_text, 'Title:')) {
                $title_parts = explode('Title:', trim($generated_text));
                $content_split = explode('Description:', end($title_parts));

                $title = trim(str_replace(['Output', '**'], '', $content_split[0]));
                $content = trim($content_split[1]);

                return [
                    'title' => $title,
                    'content' => $content
                ];
            } else {
                $content_split = explode(':', trim($generated_text));
                return [
                    'title' => trim($content_split[1]),
                    'content' => trim($content_split[2])
                ];
            }
        } else {
            $error_message = "HTTP Error: " . $http_code . "\n";
            $error_message .= "Response: " . $response . "\n";
            $error_message .= "API Key (first 5 chars): " . substr($ai_api_key, 0, 5) . "...\n";
            $error_message .= "AI Model: " . $ai_model . "\n";
            error_log($error_message);
            return $error_message;
        }
    }
}
