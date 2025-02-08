<?php
/**
 * EPay OneTouch Payment Gateway
 *
 * Core payment gateway class that handles payment processing and integration
 * with WooCommerce and the EPay OneTouch API.
 *
 * @package     EPay OneTouch Payment Gateway
 * @author      Nikola Kotarov
 * @version     2.0.0
 * @license     GPL-2.0+
 *
 * Features:
 * - Seamless WooCommerce integration
 * - Secure payment processing
 * - Token management for recurring payments
 * - Order status handling and updates
 * - Refund processing
 * - Custom payment fields
 * - Comprehensive error handling
 *
 * Security measures:
 * - PCI compliance
 * - Secure token storage
 * - Input validation
 * - XSS prevention
 * - CSRF protection
 *
 * Payment Processing:
 * - Direct card payments
 * - Tokenized payments
 * - Recurring payments
 * - Refunds
 * - Payment status tracking
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

if (!class_exists('WC_Payment_Gateway')) {
    return;
}

class EPay_OneTouch_Gateway extends WC_Payment_Gateway {
    /**
     * @var EPay_OneTouch_API
     */
    private $api;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id = 'epay_onetouch';
        $this->icon = apply_filters('woocommerce_epay_onetouch_icon', EPAY_ONETOUCH_PLUGIN_URL . 'assets/images/epay-logo.png');
        $this->has_fields = true;
        $this->method_title = __('ePay OneTouch', 'epay-onetouch');
        $this->method_description = __('Accept payments through ePay OneTouch payment gateway.', 'epay-onetouch');
        $this->supports = array(
            'products',
            'refunds',
            'tokenization',
            'add_payment_method',
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->app_id = $this->get_option('app_id');
        $this->secret_key = $this->get_option('secret_key');
        $this->test_mode = 'yes' === $this->get_option('test_mode');
        $this->enable_token_payments = 'yes' === $this->get_option('enable_token_payments');
        $this->merchant_kin = $this->get_option('merchant_kin');

        // Initialize API
        $this->api = new EPay_OneTouch_API($this->app_id, $this->secret_key, $this->test_mode);

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_epay_onetouch_callback', array($this, 'handle_callback'));
        add_action('woocommerce_api_epay_onetouch_auth', array($this, 'handle_auth'));
        add_action('woocommerce_api_epay_onetouch_auth_callback', array($this, 'handle_auth_callback'));

        // AJAX actions
        add_action('wp_ajax_epay_onetouch_get_token', array($this, 'ajax_get_token'));
        add_action('wp_ajax_nopriv_epay_onetouch_get_token', array($this, 'ajax_get_token'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Enable ePay OneTouch Payment', 'epay-onetouch'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'epay-onetouch'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'epay-onetouch'),
                'default' => __('ePay OneTouch', 'epay-onetouch'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'epay-onetouch'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'epay-onetouch'),
                'default' => __('Pay securely via ePay OneTouch.', 'epay-onetouch'),
                'desc_tip' => true,
            ),
            'app_id' => array(
                'title' => __('APP ID', 'epay-onetouch'),
                'type' => 'text',
                'description' => __('Enter your ePay OneTouch APP ID.', 'epay-onetouch'),
                'default' => '',
                'desc_tip' => true,
            ),
            'secret_key' => array(
                'title' => __('Secret Key', 'epay-onetouch'),
                'type' => 'password',
                'description' => __('Enter your ePay OneTouch Secret Key.', 'epay-onetouch'),
                'default' => '',
                'desc_tip' => true,
            ),
            'test_mode' => array(
                'title' => __('Test Mode', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Enable Test Mode', 'epay-onetouch'),
                'default' => 'yes',
                'description' => __('Place the payment gateway in test mode.', 'epay-onetouch'),
            ),
            'enable_token_payments' => array(
                'title' => __('Enable Token Payments', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Enable Token Payments', 'epay-onetouch'),
                'default' => 'yes',
                'description' => __('Allow customers to save their payment information for future purchases.', 'epay-onetouch'),
            ),
            'merchant_kin' => array(
                'title' => __('Merchant KIN', 'epay-onetouch'),
                'type' => 'text',
                'description' => __('Enter your ePay OneTouch Merchant KIN.', 'epay-onetouch'),
                'default' => '',
                'desc_tip' => true,
            ),
            'logging' => array(
                'title' => __('Logging', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Enable logging', 'epay-onetouch'),
                'default' => 'no',
                'description' => __('Log ePay OneTouch events inside <code>WooCommerce > Status > Logs</code>', 'epay-onetouch'),
            ),
        );
    }

    /**
     * Output payment fields on the checkout page
     *
     * Displays the payment form with options for:
     * - Device ID field
     * - Saved payment methods (if enabled)
     * - Payment method save checkbox
     * - Gateway description
     */
    public function payment_fields() {
        // Output gateway description if set
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }

        echo '<div id="epay-onetouch-payment-form">';
        
        // Handle tokenization if supported and enabled
        if ($this->supports('tokenization') && is_checkout() && $this->enable_token_payments) {
            $this->tokenization_script();
            $this->saved_payment_methods();

            if ($this->supports('tokenization')) {
                $this->save_payment_method_checkbox();
            }
        }

        echo '<p>' . __('Select payment type:', 'epay-onetouch') . '</p>';
        echo '<label><input type="radio" name="epay_payment_type" value="registered" checked> ' . __('Pay with ePay.bg account', 'epay-onetouch') . '</label><br>';
        echo '<label><input type="radio" name="epay_payment_type" value="unregistered"> ' . __('Pay without registration', 'epay-onetouch') . '</label>';

        // Add device ID field
        echo '<div class="epay-device-fields">';
        echo '<input type="hidden" id="epay_device_id" name="epay_device_id" />';
        echo '</div>';

        // Add nonce for security
        wp_nonce_field('epay_onetouch_payment', 'epay_onetouch_nonce');

        echo '</div>';
    }

    /**
     * Process the payment and return the result
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            throw new Exception(__('Invalid order', 'epay-onetouch'));
        }
        
        try {
            $payment_id = $this->generate_unique_key();
            $this->update_order_meta($order, '_epay_payment_id', $payment_id);
            
            // Get payment type
            $payment_type = isset($_POST['epay_payment_type']) ? sanitize_text_field($_POST['epay_payment_type']) : 'registered';
            $this->update_order_meta($order, '_epay_payment_type', $payment_type);
            
            if ($payment_type === 'unregistered') {
                // Create unregistered payment
                $response = $this->api->create_unregistered_payment($payment_id, $order);
                
                if ($response['status'] === 'OK') {
                    return array(
                        'result' => 'success',
                        'redirect' => $response['payment']['URL']
                    );
                }
                
                throw new Exception($response['errm']);
            } else {
                // Create registered payment
                $response = $this->api->create_payment($payment_id, $order);
                
                if ($response['status'] === 'OK') {
                    return array(
                        'result' => 'success',
                        'redirect' => $response['payment']['URL']
                    );
                }
                
                throw new Exception($response['errm']);
            }
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }

    /**
     * Handle the payment callback from ePay
     */
    public function handle_callback() {
        $payment_id = isset($_GET['ID']) ? sanitize_text_field($_GET['ID']) : '';
        
        if (empty($payment_id)) {
            wp_die('Invalid request', 'ePay OneTouch', array('response' => 400));
        }
        
        try {
            // Find order by stored payment ID
            $orders = wc_get_orders(array(
                'meta_key' => '_epay_payment_id',
                'meta_value' => $payment_id,
                'limit' => 1
            ));
            
            if (empty($orders)) {
                throw new Exception(__('Order not found', 'epay-onetouch'));
            }
            
            $order = $orders[0];
            
            // Check payment type (registered or unregistered user)
            $payment_type = $order->get_meta('_epay_payment_type');
            
            if ($payment_type === 'unregistered') {
                // Get merchant KIN to check unregistered payment status
                $merchant_kin = $this->get_option('merchant_kin');
                $status = $this->api->check_unregistered_payment_status($payment_id, $merchant_kin);
                
                if ($status['status'] === 'OK' && isset($status['payment']['STATE'])) {
                    switch ($status['payment']['STATE']) {
                        case 3: // Payment successful
                            $order->payment_complete();
                            // Store transaction number
                            if (isset($status['payment']['NO'])) {
                                $this->update_order_meta($order, '_epay_transaction_no', $status['payment']['NO']);
                                $order->add_order_note(
                                    sprintf(__('ePay OneTouch Transaction Number: %s', 'epay-onetouch'),
                                    $status['payment']['NO'])
                                );
                            }
                            // Store token if card was saved
                            if (isset($status['payment']['TOKEN'])) {
                                $this->update_order_meta($order, '_epay_token', $status['payment']['TOKEN']);
                            }
                            break;
                        case 2: // Payment processing
                            $order->update_status('on-hold', __('Payment processing via ePay OneTouch', 'epay-onetouch'));
                            break;
                        case 4: // Payment failed
                            $order->update_status('failed', sprintf(__('Payment failed: %s', 'epay-onetouch'), $status['payment']['STATE.TEXT']));
                            break;
                    }
                }
            } else {
                // Check status for registered user payment
                $status = $this->api->check_payment_status($payment_id);
                
                if ($status['status'] === 'OK' && isset($status['payment']['status'])) {
                    switch ($status['payment']['status']) {
                        case 'PAID':
                            $order->payment_complete();
                            break;
                        case 'PENDING':
                            $order->update_status('on-hold', __('Payment pending via ePay OneTouch', 'epay-onetouch'));
                            break;
                        case 'FAILED':
                            $order->update_status('failed', __('Payment failed via ePay OneTouch', 'epay-onetouch'));
                            break;
                    }
                }
            }
            
            $order->save();
            wp_redirect($this->get_return_url($order));
            exit;
            
        } catch (Exception $e) {
            wp_die($e->getMessage(), 'ePay OneTouch', array('response' => 500));
        }
    }

    /**
     * Handle the authorization callback
     */
    public function handle_auth_callback() {
        $payment_id = isset($_GET['ID']) ? sanitize_text_field($_GET['ID']) : '';
        
        if (empty($payment_id)) {
            wp_die('Invalid request', 'ePay OneTouch', array('response' => 400));
        }
        
        try {
            $response = $this->api->check_payment_status($payment_id);
            
            if ($response['status'] === 'OK') {
                echo 'OK';
                exit;
            }
            
            throw new Exception($response['errm']);
        } catch (Exception $e) {
            wp_die($e->getMessage(), 'ePay OneTouch', array('response' => 500));
        }
    }

    /**
     * Handle AJAX token request
     */
    public function ajax_get_token() {
        check_ajax_referer('epay_onetouch_token', 'nonce');

        $device_id = isset($_POST['device_id']) ? sanitize_text_field($_POST['device_id']) : '';
        $code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';

        try {
            $response = $this->api->get_token($device_id, $code);
            wp_send_json_success($response);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Generate unique key for request
     *
     * @return string
     */
    private function generate_unique_key() {
        return wp_generate_password(32, false);
    }

    /**
     * Update order meta data in a way that's compatible with both old and new versions of WooCommerce
     *
     * @param WC_Order $order Order object
     * @param string $key Meta key
     * @param mixed $value Meta value
     */
    private function update_order_meta($order, $key, $value) {
        if (version_compare(WC_VERSION, '3.0.0', '>=')) {
            $order->update_meta_data($key, $value);
            $order->save();
        } else {
            update_post_meta($this->get_order_id($order), $key, $value);
        }
    }

    /**
     * Get order ID in a way that's compatible with both old and new versions of WooCommerce
     *
     * @param WC_Order $order Order object
     * @return int Order ID
     */
    private function get_order_id($order) {
        return version_compare(WC_VERSION, '3.0.0', '>=') ? $order->get_id() : $order->id;
    }

    /**
     * Process refund
     *
     * @param int $order_id Order ID
     * @param float $amount Refund amount
     * @param string $reason Refund reason
     * @return bool|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('invalid_order', __('Invalid order ID', 'epay-onetouch'));
        }

        $payment_id = $order->get_meta('_epay_payment_id');
        if (!$payment_id) {
            return new WP_Error('invalid_payment', __('No ePay payment ID found', 'epay-onetouch'));
        }

        try {
            $response = $this->api->refund_payment($payment_id, $amount, $reason);

            if ($response['status'] === 'OK') {
                $order->add_order_note(
                    sprintf(__('Refunded %s via ePay OneTouch - Refund ID: %s', 'epay-onetouch'),
                    wc_price($amount),
                    $response['refund']['ID'])
                );
                return true;
            }

            return new WP_Error('refund_failed', $response['errm']);
        } catch (Exception $e) {
            EPay_OneTouch_Logger::log($e->getMessage(), EPay_OneTouch_Logger::ERROR);
            return new WP_Error('refund_error', $e->getMessage());
        }
    }

    /**
     * Enqueue payment scripts
     */
    public function payment_scripts() {
        if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order']) && !is_add_payment_method_page()) {
            return;
        }

        wp_enqueue_script(
            'epay-onetouch',
            EPAY_ONETOUCH_PLUGIN_URL . 'assets/js/epay-onetouch.js',
            array('jquery'),
            EPAY_ONETOUCH_VERSION,
            true
        );

        wp_localize_script(
            'epay-onetouch',
            'epay_onetouch_params',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('epay_onetouch_token'),
                'merchant_kin' => $this->merchant_kin,
                'test_mode' => $this->test_mode,
            )
        );
    }
}
