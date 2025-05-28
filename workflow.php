<?php
/*
Plugin Name: Product Feed Automation 
Description: Product Feed Automation Workflow & SEO blog content creation plugin with flexible API integration.
Version: 1.0.0
Author: borkk
License: GPL2
Text Domain: product-feed-automation
*/

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PFA_VERSION', '1.0.0');
define('PFA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PFA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PFA_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * The main plugin class.
 */
class Product_Feed_Automation {

    /**
     * The single instance of the class.
     *
     * @var Product_Feed_Automation
     */
    protected static $instance = null;

    /**
     * The loader responsible for maintaining and registering all hooks.
     *
     * @var PFA_Loader
     */
    protected $loader;

    /**
     * Define the core functionality of the plugin.
     */
    protected function __construct() {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_cron_hooks();
        $this->initialize_plugin();
        $this->run();
    }

    /**
     * Main instance.
     *
     * @return Product_Feed_Automation Main instance.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load the required dependencies.
     */
    private function load_dependencies() {
        // Load Loader class
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-pfa-loader.php';
        
        // Core plugin classes
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-pfa-api-fetcher.php';
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-pfa-post-creator.php';
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-pfa-post-scheduler.php';
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-pfa-queue-manager.php';
        
        // Admin functionality
        require_once PFA_PLUGIN_DIR . 'admin/class-pfa-admin.php';
        
        // Functions
        require_once PFA_PLUGIN_DIR . 'includes/functions/ajax-functions.php';
        
        $this->loader = new PFA_Loader();
    }

    /**
     * Initialize plugin settings and schedules.
     */
    private function initialize_plugin() {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;
        
        $dripfeed_interval = get_option('dripfeed_interval', 30);
        
        if (!wp_next_scheduled('pfa_daily_check') && 
            !wp_next_scheduled('pfa_dripfeed_publisher') && 
            !wp_next_scheduled('pfa_api_check')) {
            
            wp_clear_scheduled_hook('pfa_daily_check');
            wp_clear_scheduled_hook('pfa_dripfeed_publisher');
            wp_clear_scheduled_hook('pfa_api_check');
            
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'pfa_daily_check');
            
            $check_interval = get_option('check_interval', 'daily');
            wp_schedule_event(time(), $check_interval, 'pfa_api_check');
            wp_schedule_event(time(), "minutes_{$dripfeed_interval}", 'pfa_dripfeed_publisher');
        }
        
        add_filter('cron_schedules', function($schedules) use ($dripfeed_interval) {
            $schedules["minutes_{$dripfeed_interval}"] = array(
                'interval' => $dripfeed_interval * 60,
                'display' => sprintf(__('Every %d minutes'), $dripfeed_interval)
            );
            return $schedules;
        });

        // Add additional functionality from init.php
        add_action('pre_get_posts', function($query) {
            if (!is_admin()) {
                $query->set('post_status', 'publish'); 
            }
        });

        // Set up clean stale identifiers functionality
        if (!wp_next_scheduled('pfa_clean_identifiers')) {
            wp_schedule_event(strtotime('tomorrow 05:00:00'), 'daily', 'pfa_clean_identifiers');
        }
        
        // Hook for post deletion
        add_action('before_delete_post', function ($post_id) {
            if (get_post_type($post_id) !== 'post') {
                return;
            }
            
            $basename = get_post_meta($post_id, '_Amazone_produt_baseName', true);
            $gtin = get_post_meta($post_id, '_product_gtin', true);
            $mpn = get_post_meta($post_id, '_product_mpn', true);
            
            if ($basename) {
                $product_identifier = md5($basename . '|' . ($gtin ?? '') . '|' . ($mpn ?? ''));
                
                $existing_identifiers = get_option('pfa_product_identifiers', []);
                if (($key = array_search($product_identifier, $existing_identifiers)) !== false) {
                    unset($existing_identifiers[$key]);
                    update_option('pfa_product_identifiers', $existing_identifiers);
                    error_log("Identifier removed for deleted post ID: $post_id, Identifier: $product_identifier");
                }
            }
        });
        
        // Add cleanup when post is archived
        add_action('wp_trash_post', function($post_id) {
            $scheduler = PFA_Post_Scheduler::get_instance();
            $scheduler->clean_stale_identifiers();
        });
        
        // Add cleanup before daily queue check
        add_action('pfa_daily_check', function() {
            $scheduler = PFA_Post_Scheduler::get_instance();
            $scheduler->clean_stale_identifiers();
        }, 5); // Priority 5 means it runs before the normal daily check
    }

    /**
     * Register admin hooks.
     */
    private function define_admin_hooks() {
        // Admin page
        add_action('admin_menu', array($this, 'add_settings_page'));
        
        // Admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Register cron hooks.
     */
    private function define_cron_hooks() {
        $scheduler = PFA_Post_Scheduler::get_instance();
        
        // Register custom cron schedules
        $this->loader->add_filter('cron_schedules', $scheduler, 'add_custom_schedules');
        
        // Schedule events on activation
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        
        // Hook into cron events
        $this->loader->add_action('pfa_daily_check', $scheduler, 'check_and_queue_products');
        $this->loader->add_action('pfa_dripfeed_publisher', $scheduler, 'handle_dripfeed_publish');
        $this->loader->add_action('pfa_api_check', $scheduler, 'handle_api_check');
        $this->loader->add_action('pfa_clean_identifiers', $scheduler, 'clean_stale_identifiers');
    }

    /**
     * Run the loader to execute all hooks.
     */
    public function run() {
        $this->loader->run();
    }

    /**
     * Plugin activation hook.
     */
    public function activate_plugin() {
        // Initialize schedules on activation
        $scheduler = PFA_Post_Scheduler::get_instance();
        $scheduler->initialize_schedules();
        
        // Other activation tasks if needed
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook.
     */
    public static function deactivate_plugin() {
        // Clear scheduled hooks
        $scheduler = PFA_Post_Scheduler::get_instance();
        if (method_exists($scheduler, 'clear_all_schedules')) {
            $scheduler->clear_all_schedules();
        }
    }

    /**
     * Add settings page to admin menu.
     */
    public function add_settings_page() {
        add_options_page(
            __('Product Feed Automation', 'product-feed-automation'),
            __('Product Feed Automation', 'product-feed-automation'),
            'manage_options',
            'product_feed_automation',
            array($this, 'display_settings_page')
        );
    }

    /**
     * Display settings page.
     */
    public function display_settings_page() {
        $queue_manager = PFA_Queue_Manager::get_instance();
        $status_data = $queue_manager->get_status();
        
        // Include admin display template
        include_once PFA_PLUGIN_DIR . 'admin/partials/pfa-admin-display.php';
    }

    /**
     * Enqueue admin scripts and styles.
     */
    public function enqueue_admin_scripts($hook) {
        $screen = get_current_screen();
        
        // Only load on our plugin settings page
        if ($screen && strpos($screen->id, 'product_feed_automation') !== false) {
            wp_enqueue_style('pfa_admin_css', PFA_PLUGIN_URL . 'admin/css/pfa-admin.css', array(), PFA_VERSION);
            
            // External dependencies
            wp_enqueue_script('moment', 'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js', array(), null, true);
            wp_enqueue_script('moment-timezone', 'https://cdnjs.cloudflare.com/ajax/libs/moment-timezone/0.5.33/moment-timezone.min.js', array('moment'), null, true);
            
            // Admin script
            wp_enqueue_script('pfa_admin_js', PFA_PLUGIN_URL . 'admin/js/pfa-admin.js', array('jquery', 'moment', 'moment-timezone'), PFA_VERSION, true);
            
            // Localize script with data
            wp_localize_script('pfa_admin_js', 'pfaData', array(
                'nonce' => wp_create_nonce('pfa_ajax_nonce'),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'wp_timezone' => wp_timezone_string()
            ));
        }
    }
}

// Register deactivation hook
register_deactivation_hook(__FILE__, array('Product_Feed_Automation', 'deactivate_plugin'));

/**
 * Returns the main instance of the plugin.
 */
function product_feed_automation() {
    return Product_Feed_Automation::get_instance();
}

// Initialize the plugin
product_feed_automation();

// Legacy compatibility function
function get_queue_status_data() {
    $queue_manager = PFA_Queue_Manager::get_instance();
    return $queue_manager->get_status(true);
}

/**
 * Add this code to the main plugin file (workflow.php) near the end, just before the 
 * function product_feed_automation() or at the end of the file before the closing PHP tag.
 */

// Kick-start the plugin on version update
register_activation_hook(__FILE__, 'pfa_force_restart_all_schedules');
add_action('plugins_loaded', 'pfa_check_version_and_restart');

/**
 * Force restart all schedules on activation.
 */
function pfa_force_restart_all_schedules() {
    // Clear all transients
    delete_transient('pfa_dripfeed_lock');
    delete_transient('pfa_product_queue');
    delete_transient('pfa_queue_status_cache');
    delete_transient('pfa_api_products_cache');
    
    // Reset backup options
    update_option('pfa_product_queue_backup', array());
    
    // Clear all scheduled hooks
    wp_clear_scheduled_hook('pfa_daily_check');
    wp_clear_scheduled_hook('pfa_dripfeed_publisher');
    wp_clear_scheduled_hook('pfa_api_check');
    wp_clear_scheduled_hook('pfa_clean_identifiers');
    
    // Force immediate initialization
    $scheduler = PFA_Post_Scheduler::get_instance();
    $scheduler->initialize_schedules();
    
    // Force immediate run of dripfeed (after 1 minute to ensure everything is set up)
    wp_schedule_single_event(time() + 60, 'pfa_dripfeed_publisher');
}

/**
 * Check for version change and restart if needed.
 */
function pfa_check_version_and_restart() {
    $current_version = PFA_VERSION;
    $stored_version = get_option('pfa_plugin_version', '0.0.0');
    
    if ($current_version !== $stored_version) {
        // Store new version
        update_option('pfa_plugin_version', $current_version);
        
        // Force restart
        pfa_force_restart_all_schedules();
    }
}

add_action('admin_init', 'pfa_force_immediate_run_once');

function pfa_force_immediate_run_once() {
    // Only run on our plugin's admin page
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }
    
    if (!isset($_GET['page']) || $_GET['page'] !== 'product_feed_automation') {
        return;
    }
    
    // Check if we have the force_run parameter
    if (isset($_GET['force_run']) && $_GET['force_run'] === 'true') {
        error_log('[PFA] ===== FORCING IMMEDIATE RUN =====');
        
        // Clear all caches and locks
        delete_transient('pfa_dripfeed_lock');
        delete_transient('pfa_queue_status_cache');
        delete_transient('pfa_product_queue');
        delete_option('pfa_product_queue_backup');
        
        // FIXED: Get scheduler instance properly and force execution
        try {
            error_log('[PFA] Getting scheduler instance...');
            $scheduler = PFA_Post_Scheduler::get_instance();
            
            if (!$scheduler) {
                error_log('[PFA] ERROR: Could not get scheduler instance');
                wp_die('Error: Could not get scheduler instance');
            }
            
            error_log('[PFA] Scheduler instance retrieved successfully');
            
            // Clear existing schedules
            wp_clear_scheduled_hook('pfa_dripfeed_publisher');
            wp_clear_scheduled_hook('pfa_daily_check');
            
            // FIXED: Force the method to run by calling it directly with error handling
            error_log('[PFA] Calling check_and_queue_products...');
            
            // Make sure automation is enabled
            update_option('pfa_automation_enabled', 'yes');
            
            // Call the method with proper error handling
            ob_start();
            $result = $scheduler->check_and_queue_products();
            $output = ob_get_clean();
            
            if ($output) {
                error_log('[PFA] Method output: ' . $output);
            }
            
            error_log('[PFA] check_and_queue_products completed');
            
            // Schedule next dripfeed in 5 minutes as backup
            wp_schedule_single_event(time() + 300, 'pfa_dripfeed_publisher');
            
            error_log('[PFA] Force run completed successfully');
            
        } catch (Exception $e) {
            error_log('[PFA] ERROR during force run: ' . $e->getMessage());
            error_log('[PFA] Stack trace: ' . $e->getTraceAsString());
        }
        
        // Redirect to remove the force_run parameter
        wp_redirect(admin_url('options-general.php?page=product_feed_automation&forced=true'));
        exit;
    }
    
    // Show success message if we just forced a run
    if (isset($_GET['forced']) && $_GET['forced'] === 'true') {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Product Feed Automation:</strong> Forced immediate run completed. Check the logs for details.</p>';
            echo '</div>';
        });
    }
}

// Add a button to the admin interface to force immediate run
add_action('admin_footer', 'pfa_add_force_run_button');

function pfa_add_force_run_button() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'product_feed_automation') {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Add force run button after the refresh button
        var forceRunBtn = '<button type="button" id="force-immediate-run" class="button button-primary" style="margin-left: 10px;">Force Immediate Run</button>';
        $('#refresh-queue-status').after(forceRunBtn);
        
        $('#force-immediate-run').on('click', function() {
            if (confirm('This will force an immediate check and scheduling of products. Continue?')) {
                window.location.href = window.location.href + '&force_run=true';
            }
        });
    });
    </script>
    <?php
}

/**
 * Alternative: Direct execution via WP-CLI or direct URL
 * Visit: /wp-admin/options-general.php?page=product_feed_automation&pfa_force_execute=true
 */
add_action('admin_init', 'pfa_check_force_execute');

function pfa_check_force_execute() {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }
    
    if (isset($_GET['pfa_force_execute']) && $_GET['pfa_force_execute'] === 'true') {
        error_log('[PFA] ===== FORCE EXECUTE TRIGGERED =====');
        
        // Clear all blocks
        delete_transient('pfa_dripfeed_lock');
        delete_transient('pfa_queue_status_cache');
        
        // Get scheduler
        $scheduler = PFA_Post_Scheduler::get_instance();
        
        // Run immediately
        $scheduler->check_and_queue_products();
        
        // Show result
        wp_die('Force execution completed. Check the error logs for details. <a href="' . admin_url('options-general.php?page=product_feed_automation') . '">Return to plugin settings</a>');
    }
}
