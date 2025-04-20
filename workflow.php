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
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-api-fetcher.php';
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-post-creator.php';
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-post-scheduler.php';
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-queue-manager.php';
        
        // Admin functionality
        require_once PFA_PLUGIN_DIR . 'admin/class-pfa-admin.php';
        
        // Functions
        require_once PFA_PLUGIN_DIR . 'includes/functions/ajax-functions.php';
        
        $this->loader = new PFA_Loader();
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