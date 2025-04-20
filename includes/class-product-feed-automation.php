<?php
/**
 * main plugin class.
 *
 *  used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @since      1.0.0
 * @package    Product_Feed_Automation
 */

class Product_Feed_Automation {

    /**
     * The single instance of the class.
     *
     * @since    1.0.0
     * @access   protected
     * @var      Product_Feed_Automation    $instance    The single instance of the class.
     */
    protected static $instance = null;

    /**
     * The loader responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      PFA_Loader    $loader    Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * Define the core functionality of the plugin.
     *
     * @since    1.0.0
     */
    protected function __construct() {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_cron_hooks();
    }

    /**
     * Main Product_Feed_Automation Instance.
     *
     * Ensures only one instance of Product_Feed_Automation is loaded or can be loaded.
     *
     * @since    1.0.0
     * @return Product_Feed_Automation - Main instance.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function load_dependencies() {
        // Core plugin loader class
        require_once PFA_PLUGIN_DIR . 'includes/class-pfa-loader.php';
        
        // Core plugin classes
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-api-fetcher.php';
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-post-creator.php';
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-post-scheduler.php';
        require_once PFA_PLUGIN_DIR . 'includes/classes/class-queue-manager.php';
        
        // Admin-specific functionality
        require_once PFA_PLUGIN_DIR . 'admin/class-pfa-admin.php';
        
        // Ajax functions
        require_once PFA_PLUGIN_DIR . 'includes/functions/ajax-functions.php';
        
        $this->loader = new PFA_Loader();
    }

    /**
     * Register all of the hooks related to the admin area functionality.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_admin_hooks() {
        $admin = new PFA_Admin();
        
        // Admin menu
        $this->loader->add_action('admin_menu', $admin, 'add_settings_page');
        
        // Admin scripts and styles
        $this->loader->add_action('admin_enqueue_scripts', $admin, 'enqueue_scripts');
    }

    /**
     * Register all of the hooks related to cron functionality.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_cron_hooks() {
        $scheduler = PFA_Post_Scheduler::get_instance();
        
        // Register custom cron schedules
        $this->loader->add_filter('cron_schedules', $scheduler, 'add_custom_schedules');
        
        // Initialize cron schedules on activation
        register_activation_hook(PFA_PLUGIN_BASENAME, array($scheduler, 'init_schedules'));
        
        // Hook into cron events
        $this->loader->add_action('pfa_daily_check', $scheduler, 'check_and_queue_products');
        $this->loader->add_action('pfa_dripfeed_publisher', $scheduler, 'handle_dripfeed_publish');
        $this->loader->add_action('pfa_api_check', $scheduler, 'handle_api_check');
        $this->loader->add_action('pfa_clean_identifiers', $scheduler, 'clean_stale_identifiers');
    }

    /**
     * Run the loader to execute all the hooks with WordPress.
     *
     * @since    1.0.0
     */
    public function run() {
        $this->loader->run();
    }
}