<?php
/**
 * ePay OneTouch Payment Gateway Class
 *
 * Handles payment processing and integration with WooCommerce.
 *
 * @package ePay_OneTouch
 * @author  Nikola Kotarov
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ePay OneTouch Payment Gateway
 *
 * Integrates ePay OneTouch as a payment method in WooCommerce.
 * Supports saved card tokens and automatic payments.
 *
 * @since 1.0.0
 */
class WC_Gateway_Epay_Onetouch extends WC_Payment_Gateway
{
    
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
                
                // Store the key and order ID in session
                WC()->session->set('epay_auth_key', $auth_key);
                WC()->session->set('epay_order_id', $order_id);
                
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
        $auth_key = WC()->session->get('epay_auth_key');
        $order_id = WC()->session->get('epay_order_id');
        
        if (empty($auth_key) || empty($order_id)) {
            wp_redirect(wc_get_checkout_url());
            exit;
        }
        
        try {
            // Get token code
            $code = $this->api->get_token_code($auth_key);
            
            // Get token
            $token_data = $this->api->get_token($code);
            
            // Get order
            $order = wc_get_order($order_id);
            
            // Store token in order meta
            $this->update_order_meta($order, '_epay_token', $token_data['token']);
            $this->update_order_meta($order, '_epay_token_expires', $token_data['expires']);
            $this->update_order_meta($order, '_epay_kin', $token_data['kin']);
            $order->save();
            
            // Initialize payment
            $payment = $this->api->init_payment($order);
            
            if (!isset($payment['ID'])) {
                throw new Exception(__('Неуспешна инициализация на плащане', 'epay-onetouch'));
            }
            
            // Store payment ID
            $this->update_order_meta($order, '_epay_payment_id', $payment['ID']);
            $order->save();
            
            // Clear session data
            WC()->session->__unset('epay_auth_key');
            WC()->session->__unset('epay_order_id');
            
            // Redirect customer to the payment page
            wp_redirect($payment['url']);
            exit;
            
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
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

