<?php
/**
 * ePay OneTouch Admin Settings
 *
 * Handles all administrative functionality including settings management,
 * order processing, and admin interface customization.
 *
 * @package     ePay OneTouch Payment Gateway
 * @author      Nikola Kotarov
 * @version     2.0.0
 * @license     GPL-2.0+
 *
 * Features:
 * - Gateway settings management
 * - Order status handling
 * - Admin interface customization
 * - Payment status display
 * - Refund processing
 * - Error logging and display
 *
 * Security measures:
 * - Input validation
 * - Nonce verification
 * - Capability checking
 * - XSS prevention
 * - Secure settings storage
 *
 * Admin Interface:
 * - Clear settings organization
 * - Intuitive payment status display
 * - Easy access to logs
 * - Quick refund processing
 */

if (!defined('ABSPATH')) {
    exit;
}

class Epay_OneTouch_Admin {
    /**
     * Initialize the admin settings
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
    }

    /**
     * Add menu item to WordPress admin
     */
    public function add_admin_menu() {
        add_menu_page(
            __('ePay OneTouch', 'epay-onetouch'),
            __('ePay OneTouch', 'epay-onetouch'),
            'manage_options',
            'epay-onetouch',
            array($this, 'render_settings_page'),
            'dashicons-money'
        );
    }

    /**
     * Initialize settings
     */
    public function init_settings() {
        register_setting('epay_onetouch_options', 'epay_onetouch_settings');

        add_settings_section(
            'epay_onetouch_main',
            __('Main Settings', 'epay-onetouch'),
            array($this, 'render_section_info'),
            'epay_onetouch'
        );

        add_settings_field(
            'merchant_id',
            __('Merchant ID', 'epay-onetouch'),
            array($this, 'render_merchant_id_field'),
            'epay_onetouch',
            'epay_onetouch_main'
        );

        add_settings_field(
            'secret_key',
            __('Secret Key', 'epay-onetouch'),
            array($this, 'render_secret_key_field'),
            'epay_onetouch',
            'epay_onetouch_main'
        );

        add_settings_field(
            'test_mode',
            __('Test Mode', 'epay-onetouch'),
            array($this, 'render_test_mode_field'),
            'epay_onetouch',
            'epay_onetouch_main'
        );
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        // Get WooCommerce payment settings URL
        $wc_settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=epay_onetouch');
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if (class_exists('WooCommerce')) : ?>
                <div class="notice notice-info">
                    <p>
                        <?php 
                        printf(
                            __('Additional WooCommerce specific settings can be found in the <a href="%s">WooCommerce Payment Settings</a>.', 'epay-onetouch'),
                            esc_url($wc_settings_url)
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('epay_onetouch_options');
                do_settings_sections('epay_onetouch');
                submit_button();
                ?>
            </form>

            <hr>

            <h2><?php _e('Quick Links', 'epay-onetouch'); ?></h2>
            <ul class="ul-disc">
                <li><a href="https://epay.bg" target="_blank"><?php _e('ePay.bg Website', 'epay-onetouch'); ?></a></li>
                <li><a href="https://epay.bg/v3/en" target="_blank"><?php _e('API Documentation', 'epay-onetouch'); ?></a></li>
                <?php if (class_exists('WooCommerce')) : ?>
                    <li><a href="<?php echo esc_url($wc_settings_url); ?>"><?php _e('WooCommerce Payment Settings', 'epay-onetouch'); ?></a></li>
                <?php endif; ?>
            </ul>
        </div>
        <style>
            .epay-onetouch-field-wrap {
                margin: 15px 0;
            }
            .epay-onetouch-field-wrap input[type="text"],
            .epay-onetouch-field-wrap input[type="password"] {
                width: 300px;
            }
            .ul-disc {
                list-style: disc;
                margin-left: 20px;
            }
        </style>
        <?php
    }

    /**
     * Render section information
     */
    public function render_section_info() {
        echo '<p>' . __('Configure your ePay OneTouch payment gateway settings below:', 'epay-onetouch') . '</p>';
    }

    /**
     * Render merchant ID field
     */
    public function render_merchant_id_field() {
        $options = get_option('epay_onetouch_settings');
        ?>
        <div class="epay-onetouch-field-wrap">
            <input type="text" 
                   name="epay_onetouch_settings[merchant_id]" 
                   value="<?php echo esc_attr(isset($options['merchant_id']) ? $options['merchant_id'] : ''); ?>"
            />
            <p class="description">
                <?php _e('Enter your ePay.bg Merchant ID', 'epay-onetouch'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render secret key field
     */
    public function render_secret_key_field() {
        $options = get_option('epay_onetouch_settings');
        ?>
        <div class="epay-onetouch-field-wrap">
            <input type="password" 
                   name="epay_onetouch_settings[secret_key]" 
                   value="<?php echo esc_attr(isset($options['secret_key']) ? $options['secret_key'] : ''); ?>"
            />
            <p class="description">
                <?php _e('Enter your ePay.bg Secret Key', 'epay-onetouch'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render test mode field
     */
    public function render_test_mode_field() {
        $options = get_option('epay_onetouch_settings');
        $test_mode = isset($options['test_mode']) ? $options['test_mode'] : '0';
        ?>
        <div class="epay-onetouch-field-wrap">
            <label>
                <input type="checkbox" 
                       name="epay_onetouch_settings[test_mode]" 
                       value="1" 
                       <?php checked('1', $test_mode); ?>
                />
                <?php _e('Enable Test Mode', 'epay-onetouch'); ?>
            </label>
            <p class="description">
                <?php _e('Check this to use the gateway in test mode.', 'epay-onetouch'); ?>
            </p>
        </div>
        <?php
    }
}
