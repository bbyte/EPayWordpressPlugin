<?php
/**
 * Plugin Name: ePay OneTouch Payment Gateway
 * Plugin URI: https://epay.bg
 * Description: Integrates ePay OneTouch payment gateway with WordPress
 * Version: 1.0.0
 * Author: Nikola Kotarov
 * Author URI: https://github.com/bbyte/
 * Text Domain: epay-onetouch
 * Requires at least: 4.9.3
 * Tested up to: 6.4
 * WC requires at least: 3.0.0
 * WC tested up to: 8.0.0
 *
 * @author Nikola Kotarov
 * @package ePay_OneTouch
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('EPAY_ONETOUCH_VERSION', '1.0.0');
define('EPAY_ONETOUCH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EPAY_ONETOUCH_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once EPAY_ONETOUCH_PLUGIN_DIR . 'includes/class-epay-onetouch-gateway.php';
require_once EPAY_ONETOUCH_PLUGIN_DIR . 'includes/class-epay-onetouch-api.php';

// Initialize the plugin
function epay_onetouch_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    
    // Add the gateway to WooCommerce
    function add_epay_onetouch_gateway($methods) {
        $methods[] = 'WC_Gateway_Epay_Onetouch';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_epay_onetouch_gateway');
}
add_action('plugins_loaded', 'epay_onetouch_init');

// Add settings link on plugin page
function epay_onetouch_settings_link($links) {
    $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=epay_onetouch">' . __('Settings', 'epay-onetouch') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'epay_onetouch_settings_link');
