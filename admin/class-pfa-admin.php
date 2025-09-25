<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @since      1.0.0
 * @package    Product_Feed_Automation
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The admin-specific functionality of the plugin.
 */
class PFA_Admin
{

    /**
     * Initialize the class.
     */
    public function __construct()
    {
        // Initialize hooks
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_menu', array($this, 'add_settings_page'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Add plugin action links
        add_filter('plugin_action_links_' . PFA_PLUGIN_BASENAME, array($this, 'add_action_links'));
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @param string $hook The current admin page.
     */
    public function enqueue_styles($hook)
    {
        if (!$this->is_plugin_page($hook)) {
            return;
        }

        wp_enqueue_style('pfa-admin', PFA_PLUGIN_URL . 'admin/css/pfa-admin.css', array(), PFA_VERSION, 'all');
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @param string $hook The current admin page.
     */
    public function enqueue_scripts($hook)
    {
        // Only load on our specific plugin pages - be more restrictive
        if (!$this->is_plugin_page($hook)) {
            return;
        }

        // Don't load on Elementor editor pages
        if (isset($_GET['action']) && $_GET['action'] === 'elementor') {
            return;
        }

        // Don't load if Elementor is in preview mode
        if (class_exists('\Elementor\Plugin') && \Elementor\Plugin::$instance->preview->is_preview_mode()) {
            return;
        }

        // External libraries - only on our pages
        wp_enqueue_script('moment', 'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js', array(), null, true);
        wp_enqueue_script('moment-timezone', 'https://cdnjs.cloudflare.com/ajax/libs/moment-timezone/0.5.33/moment-timezone.min.js', array('moment'), null, true);

        // Plugin scripts
        wp_enqueue_script('pfa-admin', PFA_PLUGIN_URL . 'admin/js/pfa-admin.js', array('jquery', 'moment', 'moment-timezone'), PFA_VERSION, true);

        // Localize script
        wp_localize_script('pfa-admin', 'pfaData', array(
            'nonce' => wp_create_nonce('pfa_ajax_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'wp_timezone' => wp_timezone_string()
        ));
    }

    /**
     * Check if the current page is a plugin admin page.
     *
     * @param string $hook The current admin page.
     * @return bool Whether this is our plugin's page.
     */
    private function is_plugin_page($hook)
    {
        $plugin_pages = array(
            'settings_page_product_feed_automation'
        );

        return in_array($hook, $plugin_pages) ||
            (isset($_GET['page']) && $_GET['page'] === 'product_feed_automation');
    }

    /**
     * Add settings page to the admin menu.
     */
    public function add_settings_page()
    {
        add_options_page(
            __('Product Feed Automation', 'product-feed-automation'),
            __('Product Feed Automation', 'product-feed-automation'),
            'manage_options',
            'product_feed_automation',
            array($this, 'display_settings_page')
        );
    }

    /**
     * Add action links displayed in the Plugins table.
     *
     * @param array $links The existing plugin action links.
     * @return array The modified plugin action links.
     */
    public function add_action_links($links)
    {
        $settings_link = '<a href="' . admin_url('options-general.php?page=product_feed_automation') . '">' . __('Settings', 'product-feed-automation') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Register plugin settings.
     */
    public function register_settings()
    {
        // API Settings
        register_setting('pfa_api_settings', 'addrevenue_api_key');
        register_setting('pfa_api_settings', 'ai_api_key');
        register_setting('pfa_api_settings', 'channel_id');

        // Automation Settings
        register_setting('pfa_automation_settings', 'pfa_automation_enabled');
        register_setting('pfa_automation_settings', 'min_discount');
        register_setting('pfa_automation_settings', 'max_posts_per_day');
        register_setting('pfa_automation_settings', 'check_interval');
        register_setting('pfa_automation_settings', 'dripfeed_interval');

        // AI Settings
        register_setting('pfa_ai_settings', 'ai_model');
        register_setting('pfa_ai_settings', 'max_tokens');
        register_setting('pfa_ai_settings', 'temperature');
        register_setting('pfa_ai_settings', 'prompt_for_ai');
    }

    /**
     * Display the settings page content.
     */
    public function display_settings_page()
    {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'product-feed-automation'));
        }

        $queue_manager = PFA_Queue_Manager::get_instance();
        $status_data = $queue_manager->get_status();

        // Load the settings page template
        require_once PFA_PLUGIN_DIR . 'admin/partials/pfa-admin-display.php';
    }

    /**
     * Handle manual post creation submission.
     */
    public function handle_manual_post_submission()
    {
        // This functionality is now handled via AJAX in ajax-functions.php
    }
}
