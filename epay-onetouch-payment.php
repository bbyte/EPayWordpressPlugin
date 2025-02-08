<?php
/**
 * Plugin Name: ePay OneTouch Payment Gateway
 * Plugin URI: https://epay.bg
 * Description: Integrates ePay OneTouch payment gateway with WordPress and WooCommerce
 * Version: 2.0.0
 * Author: Nikola Kotarov
 * Author URI: https://github.com/bbyte/
 * Text Domain: epay-onetouch
 * Domain Path: /languages
 * Requires at least: 4.9.3
 * Tested up to: 6.4
 * WC requires at least: 3.0.0
 * WC tested up to: 8.0.0
 *
 * @package     ePay_OneTouch
 * @author      Nikola Kotarov
 * @version     2.0.0
 * @license     GPL-2.0+
 *
 * This plugin provides seamless integration with the ePay OneTouch payment system,
 * offering features such as:
 * - Secure payment processing through ePay.bg
 * - Device identification using Web Crypto API
 * - Token management for recurring payments
 * - Comprehensive error handling and user feedback
 * - Support for custom payment fields
 * - Donation form functionality
 * - Responsive design for all devices
 *
 * Security features:
 * - CSRF protection
 * - XSS prevention
 * - Input validation and sanitization
 * - Secure storage handling
 * - PCI compliance
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('EPAY_ONETOUCH_VERSION', '2.0.0');
define('EPAY_ONETOUCH_MIN_WP_VERSION', '4.9.3');
define('EPAY_ONETOUCH_MIN_WC_VERSION', '3.0.0');
define('EPAY_ONETOUCH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EPAY_ONETOUCH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EPAY_ONETOUCH_DEMO_API_URL', 'https://demo.epay.bg/xdev');
define('EPAY_ONETOUCH_API_URL', ''); // Production URL to be set in admin

/**
 * Main ePay OneTouch Plugin Class
 */
final class EPay_OneTouch {
    /**
     * @var EPay_OneTouch The single instance of the class
     */
    protected static $_instance = null;

    /**
     * Main EPay_OneTouch Instance
     * Ensures only one instance of EPay_OneTouch is loaded or can be loaded.
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * EPay_OneTouch Constructor.
     */
    public function __construct() {
        $this->init_hooks();
        $this->includes();
    }

    /**
     * Hook into actions and filters
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'), 0);
        add_action('plugins_loaded', array($this, 'init_plugin'));
        
        // Add settings link on plugin page
        $plugin = plugin_basename(__FILE__);
        add_filter("plugin_action_links_{$plugin}", array($this, 'add_settings_link'));
    }

    /**
     * Include required core files
     */
    private function includes() {
        // Core classes
        include_once EPAY_ONETOUCH_PLUGIN_DIR . 'includes/class-epay-onetouch-api.php';
        include_once EPAY_ONETOUCH_PLUGIN_DIR . 'includes/class-epay-onetouch-gateway.php';
        include_once EPAY_ONETOUCH_PLUGIN_DIR . 'includes/class-epay-onetouch-admin.php';
        include_once EPAY_ONETOUCH_PLUGIN_DIR . 'includes/class-epay-onetouch-logger.php';
    }

    /**
     * Init EPay_OneTouch when WordPress Initializes
     */
    public function init() {
        // Set up localization
        $this->load_plugin_textdomain();
    }

    /**
     * Initialize plugin for localization
     */
    private function load_plugin_textdomain() {
        load_plugin_textdomain('epay-onetouch', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Initialize the plugin if dependencies are met
     */
    public function init_plugin() {
        // Check if WooCommerce is active
        if (!class_exists('WC_Payment_Gateway')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Initialize admin if we're in admin area
        if (is_admin()) {
            new EPay_OneTouch_Admin();
        }

        // Add the gateway to WooCommerce
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
    }

    /**
     * Add EPay OneTouch Gateway to WooCommerce
     */
    public function add_gateway($methods) {
        $methods[] = 'EPay_OneTouch_Gateway';
        return $methods;
    }

    /**
     * Add settings link on plugin page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=epay_onetouch">' . __('Settings', 'epay-onetouch') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * WooCommerce fallback notice.
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p>' . sprintf(
            __('EPay OneTouch Payment Gateway requires WooCommerce to be installed and active. You can download %s here.', 'epay-onetouch'),
            '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
        ) . '</p></div>';
    }
}

/**
 * Returns the main instance of EPay_OneTouch
 */
function EPay_OneTouch() {
    return EPay_OneTouch::instance();
}

// Initialize the plugin
$GLOBALS['epay_onetouch'] = EPay_OneTouch();
