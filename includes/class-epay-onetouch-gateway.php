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
        add_action('woocommerce_api_epay_onetouch_auth', array($this, 'handle_auth_callback'));

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
                'default' => __('Pay securely using ePay OneTouch payment gateway.', 'epay-onetouch'),
                'desc_tip' => true,
            ),
            'app_id' => array(
                'title' => __('App ID', 'epay-onetouch'),
                'type' => 'text',
                'description' => __('Enter your ePay OneTouch App ID.', 'epay-onetouch'),
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
            'merchant_kin' => array(
                'title' => __('Merchant KIN', 'epay-onetouch'),
                'type' => 'text',
                'description' => __('Enter your ePay Merchant KIN (Client Identification Number).', 'epay-onetouch'),
                'default' => '',
                'desc_tip' => true,
            ),
            'enable_token_payments' => array(
                'title' => __('Token Payments', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Enable Token Payments', 'epay-onetouch'),
                'default' => 'no',
                'description' => __('Allow customers to save their payment methods for future use.', 'epay-onetouch'),
            ),
            'test_mode' => array(
                'title' => __('Test mode', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Enable Test Mode', 'epay-onetouch'),
                'default' => 'yes',
                'description' => __('Place the payment gateway in test mode using test API credentials.', 'epay-onetouch'),
            )
        );
    }

    /**
     * Process the payment and return the result
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $use_token = false;
        $token = null;

        try {
            // Check if using saved payment method
            if ($this->enable_token_payments && isset($_POST['wc-epay_onetouch-payment-token']) && 'new' !== $_POST['wc-epay_onetouch-payment-token']) {
                $token_id = wc_clean($_POST['wc-epay_onetouch-payment-token']);
                $token = WC_Payment_Tokens::get($token_id);
                
                if (!$token || !$token->get_user_id() || $token->get_user_id() !== get_current_user_id()) {
                    throw new Exception(__('Invalid payment method', 'epay-onetouch'));
                }
                
                $use_token = true;
                $device_id = $token->get_meta('device_id', true);
                
                if (empty($device_id)) {
                    // Token is invalid, delete it
                    $token->delete();
                    throw new Exception(__('Invalid payment method - missing device ID', 'epay-onetouch'));
                }
            } else {
                // Get device ID for new payment method
                $device_id = isset($_POST['epay_device_id']) ? wc_clean($_POST['epay_device_id']) : '';
                
                if (empty($device_id)) {
                    throw new Exception(__('Device ID is required', 'epay-onetouch'));
                }
            }

            // Store device ID and token usage
            $order->update_meta_data('_epay_device_id', $device_id);
            $order->update_meta_data('_epay_token', $use_token);
            $order->save();

            // Initialize payment
            $response = $this->api->init_payment($order);

            if ($response['status'] === 'OK') {
                // Store payment ID
                $order->update_meta_data('_epay_payment_id', $response['payment']['ID']);
                $order->save();

                if ($use_token) {
                    try {
                        // For token payments, process directly
                        $payment_result = $this->api->process_token_payment($order, $token->get_token());
                        
                        if ($payment_result['status'] === 'OK') {
                            $order->payment_complete($response['payment']['ID']);
                            return array(
                                'result' => 'success',
                                'redirect' => $this->get_return_url($order)
                            );
                        }
                        
                        throw new Exception($payment_result['errm']);
                    } catch (Exception $e) {
                        // If token payment fails, invalidate the token
                        try {
                            $this->api->invalidate_token($device_id);
                            $token->delete();
                        } catch (Exception $invalidate_error) {
                            EPay_OneTouch_Logger::log('Failed to invalidate token: ' . $invalidate_error->getMessage(), EPay_OneTouch_Logger::ERROR);
                        }
                        throw $e;
                    }
                } else {
                    // For regular payments, redirect to ePay with callback URL
                    $callback_url = add_query_arg(
                        array(
                            'payment_id' => $response['payment']['ID'],
                            'key' => wp_create_nonce('epay_onetouch_callback')
                        ),
                        WC()->api_request_url('epay_onetouch_callback')
                    );

                    // Add callback URL to API response URL
                    $redirect_url = add_query_arg('callback_url', urlencode($callback_url), $response['payment']['URL']);

                    return array(
                        'result' => 'success',
                        'redirect' => $redirect_url
                    );
                }
            } else {
                throw new Exception($response['errm']);
            }
        } catch (Exception $e) {
            EPay_OneTouch_Logger::log($e->getMessage(), EPay_OneTouch_Logger::ERROR);
            wc_add_notice(__('Payment error:', 'epay-onetouch') . ' ' . $e->getMessage(), 'error');
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
        if (!isset($_GET['key']) || !wp_verify_nonce($_GET['key'], 'epay_onetouch_callback')) {
            wp_die('Invalid callback request', 'ePay OneTouch Error', array('response' => 403));
        }

        $payment_id = isset($_GET['payment_id']) ? sanitize_text_field($_GET['payment_id']) : '';
        $hmac = isset($_GET['hmac']) ? sanitize_text_field($_GET['hmac']) : '';

        if (empty($payment_id)) {
            wp_die('Invalid payment ID', 'ePay OneTouch Error', array('response' => 400));
        }

        // Get order by payment ID
        $orders = wc_get_orders(array(
            'meta_key' => '_epay_payment_id',
            'meta_value' => $payment_id,
            'limit' => 1
        ));

        if (empty($orders)) {
            wp_die('Order not found', 'ePay OneTouch Error', array('response' => 404));
        }

        $order = $orders[0];
        $use_token = (bool) $order->get_meta('_epay_token');

        try {
            // Check payment status
            $response = $this->api->check_payment_status($payment_id, $use_token);

            // Verify HMAC signature
            if (!empty($hmac)) {
                $params = array(
                    'APPID' => $this->app_id,
                    'PAYMENTID' => $payment_id,
                    'STATE' => $response['payment']['STATE']
                );
                $calculated_hmac = $this->api->generate_hmac($params);
                
                if ($hmac !== $calculated_hmac) {
                    throw new Exception(__('Invalid HMAC signature', 'epay-onetouch'));
                }
            }

            if ($response['status'] === 'OK') {
                // Check payment state
                if (isset($response['payment']['STATE']) && $response['payment']['STATE'] === 4) {
                    // Payment successful
                    $order->payment_complete($payment_id);
                    $order->add_order_note(__('ePay payment completed. Payment ID: ', 'epay-onetouch') . $payment_id);

                    // Store payment instrument ID if available
                    if (isset($response['payment']['PINS'])) {
                        $pins = $response['payment']['PINS'];
                        $order->update_meta_data('_epay_pins', $pins);
                        $order->save();

                        // Save payment method if user is logged in and requested
                        if (is_user_logged_in() && 
                            isset($_POST['wc-epay_onetouch-new-payment-method']) && 
                            $_POST['wc-epay_onetouch-new-payment-method']) {
                            
                            try {
                                $token = new WC_Payment_Token_CC();
                                $token->set_token($pins);
                                $token->set_gateway_id($this->id);
                                $token->set_user_id(get_current_user_id());
                                $token->set_last4(substr($pins, -4));
                                $token->set_expiry_month('12');
                                $token->set_expiry_year('2099');
                                $token->add_meta_data('device_id', $order->get_meta('_epay_device_id'), true);
                                $token->save();
                            } catch (Exception $token_error) {
                                EPay_OneTouch_Logger::log('Failed to save payment token: ' . $token_error->getMessage(), EPay_OneTouch_Logger::ERROR);
                            }
                        }
                    }

                    wp_redirect($this->get_return_url($order));
                    exit;
                } else {
                    // Payment still processing
                    $order->update_status('on-hold', __('ePay payment processing. Payment ID: ', 'epay-onetouch') . $payment_id);
                    wp_redirect($this->get_return_url($order));
                    exit;
                }
            } else {
                // Payment failed
                $order->update_status('failed', __('ePay payment failed: ', 'epay-onetouch') . $response['errm']);
                wp_redirect(wc_get_checkout_url());
                exit;
            }
        } catch (Exception $e) {
            EPay_OneTouch_Logger::log($e->getMessage(), EPay_OneTouch_Logger::ERROR);
            $order->update_status('failed', __('ePay payment error: ', 'epay-onetouch') . $e->getMessage());
            wp_redirect(wc_get_checkout_url());
            exit;
        }
    }

    /**
     * Handle the authorization callback
     */
    public function handle_auth_callback() {
        if (!isset($_GET['order_id']) || !isset($_GET['key']) || !wp_verify_nonce($_GET['nonce'], 'epay_onetouch_auth')) {
            wp_die('Invalid request', 'ePay OneTouch Error', array('response' => 403));
        }

        $order_id = absint($_GET['order_id']);
        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $_GET['key']) {
            wp_die('Invalid order', 'ePay OneTouch Error', array('response' => 404));
        }

        try {
            // Get authorization code
            $device_id = $order->get_meta('_epay_device_id');
            $key = $this->generate_unique_key();
            
            $response = $this->api->get_auth_code($device_id, $key);

            if ($response['status'] === 'OK') {
                // Get token
                $token_response = $this->api->get_token($device_id, $response['code']);

                if ($token_response['status'] === 'OK') {
                    // Store token
                    $order->update_meta_data('_epay_token', $token_response['TOKEN']);
                    $order->save();

                    // Redirect to confirmation page
                    wp_redirect($this->get_return_url($order));
                    exit;
                }
            }

            throw new Exception($response['errm'] ?? __('Authorization failed', 'epay-onetouch'));
        } catch (Exception $e) {
            EPay_OneTouch_Logger::log($e->getMessage(), EPay_OneTouch_Logger::ERROR);
            $order->update_status('failed', __('ePay authorization error: ', 'epay-onetouch') . $e->getMessage());
            wp_redirect(wc_get_checkout_url());
            exit;
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
    }
    /**
     * Payment form on checkout page
     */
    public function payment_fields() {
        $description = $this->get_description();
        if ($description) {
            echo wpautop(wptexturize($description));
        }

        if ($this->supports('tokenization') && is_checkout() && $this->enable_token_payments) {
            $this->tokenization_script();
            $this->saved_payment_methods();

            if ($this->supports('tokenization')) {
                $this->save_payment_method_checkbox();
            }
        }

        // Add device ID field
        echo '<div class="epay-device-fields">';
        echo '<input type="hidden" id="epay_device_id" name="epay_device_id" />';
        echo '</div>';
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
    private $api;
    
    public function __construct() {
        $this->id = 'epay_onetouch';
        $this->icon = EPAY_ONETOUCH_PLUGIN_URL . 'assets/images/epay-logo.png';
        $this->has_fields = false;
        $this->method_title = __('ePay OneTouch', 'epay-onetouch');
        $this->method_description = __('Accept payments through ePay OneTouch payment gateway', 'epay-onetouch');
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Define properties
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        
        // Initialize API
        $this->api = new WC_Epay_Onetouch_API();
        
        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_epay_onetouch_callback', array($this, 'handle_callback'));
        add_action('woocommerce_api_epay_onetouch_auth', array($this, 'handle_auth'));
        add_action('woocommerce_api_epay_onetouch_auth_callback', array($this, 'handle_auth_callback'));
    }
    
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Активиране/Деактивиране', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Активиране на ePay OneTouch плащане', 'epay-onetouch'),
                'default' => 'no'
            ),
            'testmode' => array(
                'title' => __('Тестов режим', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Активиране на тестов режим', 'epay-onetouch'),
                'description' => __('Поставя платежния шлюз в тестов режим, използвайки демо API на ePay.bg (няма да се обработват реални плащания).', 'epay-onetouch'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'api_url_test' => array(
                'title' => __('Test API URL', 'epay-onetouch'),
                'type' => 'text',
                'description' => __('The API URL for test mode. Default: https://demo.epay.bg/xdev/api', 'epay-onetouch'),
                'default' => 'https://demo.epay.bg/xdev/api',
                'desc_tip' => true,
            ),
            'api_url_prod' => array(
                'title' => __('Production API URL', 'epay-onetouch'),
                'type' => 'text',
                'description' => __('The API URL for production mode. Default: https://epay.bg/api', 'epay-onetouch'),
                'default' => 'https://epay.bg/api',
                'desc_tip' => true,
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
                'default' => __('Pay securely via ePay OneTouch', 'epay-onetouch'),
                'desc_tip' => true,
            ),
            'app_id' => array(
                'title' => __('APP ID', 'epay-onetouch'),
                'type' => 'text',
                'description' => __('Enter your ePay OneTouch APP ID', 'epay-onetouch'),
                'default' => '',
                'desc_tip' => true,
            ),
            'secret' => array(
                'title' => __('Secret Key', 'epay-onetouch'),
                'type' => 'password',
                'description' => __('Enter your ePay OneTouch Secret Key', 'epay-onetouch'),
                'default' => '',
                'desc_tip' => true,
            ),
            'merchant_identifier' => array(
                'title' => __('Merchant Identifier', 'epay-onetouch'),
                'type' => 'text',
                'description' => __('Enter your ePay.bg identifier (KIN, GSM, or Email)', 'epay-onetouch'),
                'default' => '',
                'desc_tip' => true,
            ),
            'merchant_identifier_type' => array(
                'title' => __('Identifier Type', 'epay-onetouch'),
                'type' => 'select',
                'description' => __('Select the type of identifier you entered above', 'epay-onetouch'),
                'default' => 'KIN',
                'options' => array(
                    'KIN' => __('Client ID Number (KIN)', 'epay-onetouch'),
                    'GSM' => __('Mobile Number', 'epay-onetouch'),
                    'EMAIL' => __('Email Address', 'epay-onetouch')
                ),
                'desc_tip' => true,
            ),
            'reply_address' => array(
                'title' => __('Reply Address', 'epay-onetouch'),
                'type' => 'text',
                'description' => __('The URL where ePay will send payment notifications. Leave empty to use the default callback URL.', 'epay-onetouch'),
                'default' => '',
                'desc_tip' => true,
            ),
            'show_kin' => array(
                'title' => __('Show KIN', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Show Client ID Number to recipient', 'epay-onetouch'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'show_name' => array(
                'title' => __('Show Name', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Show customer name to recipient', 'epay-onetouch'),
                'default' => 'no',
                'desc_tip' => true,
            ),
            'allow_unregistered' => array(
                'title' => __('Allow Unregistered Users', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Allow payments from users without ePay.bg registration', 'epay-onetouch'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'show_email' => array(
                'title' => __('Show Email', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Show customer email to recipient', 'epay-onetouch'),
                'default' => 'no',
                'desc_tip' => true,
            ),
            'show_gsm' => array(
                'title' => __('Show Phone', 'epay-onetouch'),
                'type' => 'checkbox',
                'label' => __('Show customer phone number to recipient', 'epay-onetouch'),
                'default' => 'no',
                'desc_tip' => true,
            ),
        );
    }
    
    /**
     * Process a new payment
     *
     * @param int $order_id Order ID
     * @return array Processing result
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        try {
            // Check if user wants to use registered or unregistered payment
            $payment_type = isset($_POST['epay_payment_type']) ? sanitize_text_field($_POST['epay_payment_type']) : 'registered';
            
            if ($payment_type === 'unregistered') {
                // Generate unique payment ID
                $payment_id = wp_generate_password(32, false);
                
                // Store payment ID in order meta
                $this->update_order_meta($order, '_epay_payment_id', $payment_id);
                $order->save();
                
                // Prepare payment parameters
                // Build visibility settings
                $show_fields = array();
                if ($this->get_option('show_kin') === 'yes') $show_fields[] = 'KIN';
                if ($this->get_option('show_name') === 'yes') $show_fields[] = 'NAME';
                if ($this->get_option('show_email') === 'yes') $show_fields[] = 'EMAIL';
                if ($this->get_option('show_gsm') === 'yes') $show_fields[] = 'GSM';
                
                $params = array(
                    'ID' => $payment_id,
                    'AMOUNT' => $order->get_total() * 100, // Convert to stotinki
                    'RCPT' => $this->get_option('merchant_identifier'),
                    'RCPT_TYPE' => $this->get_option('merchant_identifier_type'),
                    'DESCRIPTION' => sprintf(__('Order %s on %s', 'epay-onetouch'), $order->get_order_number(), get_bloginfo('name')),
                    'REASON' => __('Online purchase', 'epay-onetouch'),
                    'SAVECARD' => '1', // Allow saving card for future use
                    'SHOW' => implode(',', $show_fields), // Add visibility settings
                    'EXP' => time() + (3600 * 24), // Set payment ID expiration to 24 hours
                    'UTYPE' => $this->get_option('allow_unregistered') === 'yes' ? '2' : '1' // Allow unregistered users if enabled
                );
                
                // Get payment URL
                $payment_url = $this->api->init_unregistered_payment($params);
                
                return array(
                    'result' => 'success',
                    'redirect' => $payment_url
                );
                
            } else {
                // Generate unique key for authorization
                $auth_key = wp_generate_password(32, false);
                
                // Store the key, order ID, and timestamp in session
                WC()->session->set('epay_auth_key', $auth_key);
                WC()->session->set('epay_order_id', $order_id);
                WC()->session->set('epay_auth_timestamp', time());
                
                // Get authorization URL
                $auth_url = $this->api->get_auth_url($auth_key);
                
                // Return success and redirect to authorization page
                return array(
                    'result' => 'success',
                    'redirect' => $auth_url
                );
            }
            
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }
    
    public function handle_auth() {
        if (!is_ssl()) {
            wp_die(__('Authorization must be performed over HTTPS', 'epay-onetouch'));
        }

        // Verify session is valid
        if (!WC()->session || !WC()->session->has_session()) {
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        // Get and validate session data
        $auth_key = WC()->session->get('epay_auth_key');
        $order_id = WC()->session->get('epay_order_id');
        $auth_timestamp = WC()->session->get('epay_auth_timestamp');
        
        if (empty($auth_key) || empty($order_id) || empty($auth_timestamp)) {
            wc_add_notice(__('Invalid authorization session', 'epay-onetouch'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        // Check if auth session has expired (15 minutes)
        if (time() - $auth_timestamp > 900) {
            // Clear expired session data
            WC()->session->__unset('epay_auth_key');
            WC()->session->__unset('epay_order_id');
            WC()->session->__unset('epay_auth_timestamp');
            
            wc_add_notice(__('Authorization session has expired', 'epay-onetouch'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }
        
        try {
            // Get and validate order
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Invalid order', 'epay-onetouch'));
            }

            // Verify order belongs to current user if logged in
            if (is_user_logged_in() && $order->get_user_id() !== get_current_user_id()) {
                throw new Exception(__('Order does not belong to current user', 'epay-onetouch'));
            }

            // Get token code with rate limiting
            $code = $this->api->get_token_code($auth_key);
            if (empty($code)) {
                throw new Exception(__('Failed to get authorization code', 'epay-onetouch'));
            }
            
            // Get token with validation
            $token_data = $this->api->get_token($code);
            if (empty($token_data['token']) || empty($token_data['expires']) || empty($token_data['kin'])) {
                throw new Exception(__('Invalid token data received', 'epay-onetouch'));
            }

            // Validate token expiration
            if ($token_data['expires'] <= time()) {
                throw new Exception(__('Received expired token', 'epay-onetouch'));
            }
            
            // Store token data securely
            $this->update_order_meta($order, '_epay_token', wp_hash($token_data['token']));
            $this->update_order_meta($order, '_epay_token_expires', (int)$token_data['expires']);
            $this->update_order_meta($order, '_epay_kin', sanitize_text_field($token_data['kin']));
            $order->save();
            
            // Initialize payment with validation
            $payment = $this->api->init_payment($order);
            if (empty($payment['ID']) || empty($payment['url'])) {
                throw new Exception(__('Invalid payment initialization response', 'epay-onetouch'));
            }
            
            // Store payment ID securely
            $this->update_order_meta($order, '_epay_payment_id', sanitize_text_field($payment['ID']));
            $order->save();
            
            // Clear all session data
            WC()->session->__unset('epay_auth_key');
            WC()->session->__unset('epay_order_id');
            WC()->session->__unset('epay_auth_timestamp');
            
            // Add security headers before redirect
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Frame-Options: DENY');
            
            // Redirect to payment page with validation
            if (filter_var($payment['url'], FILTER_VALIDATE_URL)) {
                wp_redirect($payment['url']);
                exit;
            } else {
                throw new Exception(__('Invalid payment URL received', 'epay-onetouch'));
            }
            
        } catch (Exception $e) {
            // Log error securely
            EPay_OneTouch_Logger::log($e->getMessage(), EPay_OneTouch_Logger::ERROR);
            
            // Clear session data on error
            WC()->session->__unset('epay_auth_key');
            WC()->session->__unset('epay_order_id');
            WC()->session->__unset('epay_auth_timestamp');
            
            // Show generic error to user
            wc_add_notice(__('Payment authorization failed. Please try again.', 'epay-onetouch'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }
    }
    
    public function handle_auth_callback()
    {
        $ret = isset($_GET['ret']) ? sanitize_text_field($_GET['ret']) : '';
        
        if ($ret === 'authok') {
            wp_redirect(home_url('/wc-api/epay_onetouch_auth'));
        } else {
            wc_add_notice(__('Authorization failed or was cancelled', 'epay-onetouch'), 'error');
            wp_redirect(wc_get_checkout_url());
        }
        exit;
    }
    
    /**
     * Output payment fields for the gateway
     */
    public function payment_fields()
    {
        parent::payment_fields();
        
        // Display payment type selection for user
        echo '<div class="epay-payment-type">';
        echo '<p>' . __('Select payment type:', 'epay-onetouch') . '</p>';
        echo '<label><input type="radio" name="epay_payment_type" value="registered" checked> ' . __('Pay with ePay.bg account', 'epay-onetouch') . '</label><br>';
        echo '<label><input type="radio" name="epay_payment_type" value="unregistered"> ' . __('Pay without registration', 'epay-onetouch') . '</label>';
        echo '</div>';
    }
    
    public function handle_callback()
    {
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
                throw new Exception(__('Поръчката не е намерена', 'epay-onetouch'));
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
                                    sprintf(__('ePay OneTouch Транзакционен номер: %s', 'epay-onetouch'),
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
}

