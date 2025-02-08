<?php
/**
 * EPay OneTouch API Class
 *
 * Handles all communication with the ePay OneTouch payment system API.
 * Implements secure payment processing, token management, and error handling.
 *
 * @package     EPay OneTouch Payment Gateway
 * @author      Nikola Kotarov
 * @version     2.0.0
 * @license     GPL-2.0+
 *
 * Features:
 * - Secure API communication with ePay.bg
 * - Token generation and management
 * - Payment processing and status checking
 * - Device identification
 * - Balance inquiries
 * - Refund processing
 * - Error handling and logging
 *
 * Security measures:
 * - Request signing using HMAC
 * - Response validation
 * - Secure token storage
 * - PCI compliance
 * - Input sanitization
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class EPay_OneTouch_API {
    /**
     * Base URL for the ePay API
     * @var string
     */
    private $api_url;

    /**
     * Application ID provided by ePay
     * @var string
     */
    private $app_id;

    /**
     * Secret key for API authentication
     * @var string
     */
    private $secret_key;

    /**
     * Whether to use test mode
     * @var bool
     */
    private $is_test_mode;

    /**
     * Authentication token
     * @var string
     */
    private $token;

    /**
     * KIN number
     * @var string
     */
    private $kin;

    /**
     * Constructor
     *
     * Initializes the API with credentials from WooCommerce settings
     * or direct parameters if provided.
     *
     * @param string|null $app_id Optional ePay application ID
     * @param string|null $secret_key Optional ePay secret key
     * @param bool|null $is_test_mode Optional test mode flag
     */
    public function __construct($app_id = null, $secret_key = null, $is_test_mode = null) {
        // Get settings from WooCommerce if not provided directly
        $gateway_settings = get_option('woocommerce_epay_onetouch_settings', array());
        
        // Initialize test mode
        $this->is_test_mode = $is_test_mode ?? (isset($gateway_settings['testmode']) && $gateway_settings['testmode'] === 'yes');
        
        // Set API URLs
        $api_url_test = !empty($gateway_settings['api_url_test']) ? 
            $gateway_settings['api_url_test'] : 'https://demo.epay.bg/xdev/api';
        $api_url_prod = !empty($gateway_settings['api_url_prod']) ? 
            $gateway_settings['api_url_prod'] : 'https://epay.bg/api';
        $this->api_url = $this->is_test_mode ? $api_url_test : $api_url_prod;
        
        // Set credentials
        $this->app_id = $app_id ?? ($gateway_settings['app_id'] ?? '');
        $this->secret_key = $secret_key ?? ($gateway_settings['secret'] ?? '');
        
        // Initialize other properties
        $this->token = null;
        $this->kin = null;
        $this->device_id = $this->generate_device_id();
        $this->reply_address = $gateway_settings['reply_address'] ?? home_url('/wc-api/epay_onetouch_callback');
    }

    /**
     * Get authorization code
     *
     * @param string $device_id Device identifier
     * @param string $key Unique key for request
     * @return array Response from API
     */
    public function get_auth_code($device_id, $key) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $device_id,
            'KEY' => $key
        );

        return $this->make_request('api/code/get', $params);
    }

    /**
     * Get token using authorization code
     *
     * @param string $code Authorization code
     * @param string|null $device_id Optional device identifier
     * @return array Response from API
     */
    public function get_token($code, $device_id = null) {
        if ($device_id === null) {
            $device_id = $this->device_id;
        }

        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $device_id,
            'CODE' => $code
        );

        $response = $this->make_request('api/token/get', $params);

        if ($response['status'] === 'OK') {
            $this->token = $response['TOKEN'];
            $this->kin = $response['KIN'];
            return array(
                'token' => $response['TOKEN'],
                'expires' => $response['EXPIRES'],
                'kin' => $response['KIN'],
                'username' => $response['USERNAME'],
                'realname' => $response['REALNAME']
            );
        }

        return $response;
    }

    /**
     * Invalidate token
     *
     * @param string|null $token Optional token to invalidate (uses stored token if not provided)
     * @param string|null $device_id Optional device identifier
     * @return array|bool Response from API
     */
    public function invalidate_token($token = null, $device_id = null) {
        if ($token === null) {
            if (!$this->token) {
                throw new Exception(__('No token available to invalidate', 'epay-onetouch'));
            }
            $token = $this->token;
        }

        if ($device_id === null) {
            $device_id = $this->device_id;
        }

        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $device_id,
            'TOKEN' => $token
        );

        $response = $this->make_request('api/token/invalidate', $params);

        if ($response['status'] === 'OK') {
            if ($token === $this->token) {
                $this->token = null;
                $this->kin = null;
            }
            return true;
        }

        return $response;
    }

    /**
     * Start payment process
     *
     * @param WC_Order $order WooCommerce order
     * @param bool $use_token Whether to use token-based authentication
     * @return array Response from API
     */
    public function start_payment($order, $use_token = false) {
        $device_id = $this->generate_device_id($order);
        
        if ($use_token && !$this->token) {
            throw new Exception(__('No valid token available for payment', 'epay-onetouch'));
        }

        if ($use_token) {
            // Use token-based payment flow
            $response = $this->initialize_payment($device_id);
            
            if ($response['status'] === 'OK') {
                $payment_id = $response['payment']['ID'];
                $order->update_meta_data('_epay_payment_id', $payment_id);
                
                // Check payment details
                $response = $this->check_payment_details($device_id, $payment_id, $order);
                
                if ($response['status'] === 'OK') {
                    // Send payment
                    return $this->send_payment($device_id, $payment_id, $order);
                }
            }
            
            return $response;
        } else {
            // Use no-registration flow
            $key = $this->generate_unique_key();
            
            $params = array(
                'APPID' => $this->app_id,
                'DEVICEID' => $device_id,
                'KEY' => $key,
                'DEVICE_NAME' => 'WooCommerce',
                'OS' => 'Web',
                'PHONE' => $order->get_billing_phone(),
                'AMOUNT' => $this->format_amount($order->get_total()),
                'CURRENCY' => $order->get_currency(),
                'DESCR' => sprintf(__('Order %s from %s', 'epay-onetouch'), $order->get_order_number(), get_bloginfo('name')),
                'EXP_TIME' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                'ENCODING' => 'UTF-8',
                'REPLY_ADDRESS' => WC()->api_request_url('epay_onetouch_callback')
            );

            // Store payment data in order meta
            $order->update_meta_data('_epay_device_id', $device_id);
            $order->update_meta_data('_epay_key', $key);
            $order->save();

            return $this->make_request('api/start', $params);
        }
    }

    /**
     * Initialize payment (token-based flow)
     *
     * @param string $device_id Device identifier
     * @return array Response from API
     */
    private function initialize_payment($device_id) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $device_id,
            'TOKEN' => $this->token,
            'TYPE' => 'send'
        );

        return $this->make_request('payment/init', $params, 'POST');
    }

    /**
     * Check payment details (token-based flow)
     *
     * @param string $device_id Device identifier
     * @param string $payment_id Payment ID
     * @param WC_Order $order WooCommerce order
     * @return array Response from API
     */
    private function check_payment_details($device_id, $payment_id, $order) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $device_id,
            'TOKEN' => $this->token,
            'TYPE' => 'send',
            'ID' => $payment_id,
            'AMOUNT' => $this->format_amount($order->get_total()),
            'RCPT' => get_option('epay_merchant_kin'), // Merchant KIN
            'RCPT_TYPE' => 'KIN',
            'DESCRIPTION' => sprintf(__('Order %s', 'epay-onetouch'), $order->get_order_number()),
            'REASON' => 'WooCommerce Payment',
            'SHOW' => 'KIN,NAME',
            'PINS' => '' // Will be set after user selects payment method
        );

        return $this->make_request('payment/check', $params, 'POST');
    }

    /**
     * Send payment (token-based flow)
     *
     * @param string $device_id Device identifier
     * @param string $payment_id Payment ID
     * @param WC_Order $order WooCommerce order
     * @return array Response from API
     */
    private function send_payment($device_id, $payment_id, $order) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $device_id,
            'TOKEN' => $this->token,
            'TYPE' => 'send',
            'ID' => $payment_id,
            'AMOUNT' => $this->format_amount($order->get_total()),
            'RCPT' => get_option('epay_merchant_kin'), // Merchant KIN
            'RCPT_TYPE' => 'KIN',
            'DESCRIPTION' => sprintf(__('Order %s', 'epay-onetouch'), $order->get_order_number()),
            'REASON' => 'WooCommerce Payment',
            'SHOW' => 'KIN,NAME',
            'PINS' => '' // Will be set after user selects payment method
        );

        return $this->make_request('payment/send/user', $params, 'POST');
    }
    /**
     * Check payment status
     *
     * Checks the status of a payment using either token-based or non-registered flow.
     * 
     * @param string $payment_id Payment ID from ePay
     * @param bool $use_token Whether to use token-based status check
     * @param string|null $device_id Optional device identifier (required for some API endpoints)
     * @return array Response from API
     * @throws Exception if token is required but not available, or if API request fails
     */
    public function check_payment_status($payment_id, $use_token = false, $device_id = null) {
        if ($device_id === null) {
            $device_id = $this->device_id;
        }

        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $device_id,
            'ID' => $payment_id
        );

        if ($use_token) {
            if (!$this->token) {
                throw new Exception(__('No valid token available for status check', 'epay-onetouch'));
            }
            $params['TOKEN'] = $this->token;
            return $this->make_request('payment/send/status', $params, 'POST');
        }

        // Add checksum for non-token requests
        $params['APPCHECK'] = $this->generate_hmac($params);
        return $this->make_request('payment/check', $params, 'GET');
    }

    /**
     * Make API request
     *
     * @param string $endpoint API endpoint
     * @param array $params Request parameters
     * @param string $method HTTP method (GET or POST)
     * @return array Response from API
     */
    private function make_request($endpoint, $params, $method = 'GET') {
        if (empty($this->app_id) || empty($this->secret_key)) {
            throw new Exception(__('API credentials not configured', 'epay-onetouch'));
        }

        // Validate endpoint
        if (!preg_match('/^[a-zA-Z0-9\/\-_]+$/', $endpoint)) {
            throw new Exception(__('Invalid API endpoint', 'epay-onetouch'));
        }

        $url = trailingslashit($this->api_url) . $endpoint;

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception(__('Invalid API URL', 'epay-onetouch'));
        }
        
        // Add request ID and timestamp
        $params['REQUEST_ID'] = wp_generate_uuid4();
        $params['TIMESTAMP'] = time();
        
        // Add HMAC signature
        $params['CHECKSUM'] = $this->generate_hmac($params);

        // Log request (excluding sensitive data)
        $log_params = $params;
        unset($log_params['CHECKSUM']);
        EPay_OneTouch_Logger::log_request($endpoint, $log_params);

        $args = array(
            'method' => $method,
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'EPay-OneTouch-WP/' . EPAY_ONETOUCH_VERSION,
                'X-Request-ID' => $params['REQUEST_ID']
            ),
            'body' => $params,
            'cookies' => array(),
            'sslverify' => true
        );

        // Make the request
        if ($method === 'GET') {
            $response = wp_remote_get(add_query_arg($params, $url), $args);
        } else {
            $response = wp_remote_post($url, $args);
        }

        // Check for WP errors
        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            EPay_OneTouch_Logger::log($error, EPay_OneTouch_Logger::ERROR);
            throw new Exception(sprintf(__('API request failed: %s', 'epay-onetouch'), $error));
        }

        // Check HTTP response code
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code < 200 || $http_code >= 300) {
            $error = sprintf(__('HTTP error %d: %s', 'epay-onetouch'), 
                            $http_code, 
                            wp_remote_retrieve_response_message($response));
            EPay_OneTouch_Logger::log($error, EPay_OneTouch_Logger::ERROR);
            throw new Exception($error);
        }

        $body = wp_remote_retrieve_body($response);
        
        // Verify response is not empty
        if (empty($body)) {
            throw new Exception(__('Empty response from API', 'epay-onetouch'));
        }

        // Decode JSON response
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = sprintf(__('Invalid JSON response: %s', 'epay-onetouch'), json_last_error_msg());
            EPay_OneTouch_Logger::log($error, EPay_OneTouch_Logger::ERROR);
            throw new Exception($error);
        }

        // Verify response structure
        if (!isset($data['status'])) {
            throw new Exception(__('Invalid response format: missing status', 'epay-onetouch'));
        }

        // Log response (excluding sensitive data)
        $log_data = $data;
        if (isset($log_data['TOKEN'])) {
            $log_data['TOKEN'] = '***';
        }
        EPay_OneTouch_Logger::log_response($endpoint, $log_data);

        return $data;
    }

    /**
     * Generate HMAC signature according to ePay specification
     *
     * @param array $params Parameters to sign
     * @return string HMAC signature
     */
    private function generate_hmac($params) {
        if (empty($this->secret_key)) {
            throw new Exception(__('Secret key not configured', 'epay-onetouch'));
        }

        // Validate parameters
        if (!is_array($params)) {
            throw new Exception(__('Invalid parameters for HMAC generation', 'epay-onetouch'));
        }

        // Remove any existing CHECKSUM
        unset($params['CHECKSUM']);

        // Sort parameters alphabetically
        ksort($params);

        // Create string to sign with strict validation
        $string_to_sign = '';
        foreach ($params as $key => $value) {
            // Validate key format
            if (!is_string($key) || !preg_match('/^[A-Z0-9_]+$/', $key)) {
                throw new Exception(sprintf(__('Invalid parameter key format: %s', 'epay-onetouch'), $key));
            }

            // Convert value to string and validate
            if (is_array($value) || is_object($value)) {
                throw new Exception(sprintf(__('Invalid parameter value type for key %s: must be scalar', 'epay-onetouch'), $key));
            }

            $str_value = (string)$value;
            if (preg_match('/[\x00-\x1F\x7F]/', $str_value)) {
                throw new Exception(sprintf(__('Invalid parameter value format for key %s: contains control characters', 'epay-onetouch'), $key));
            }

            $string_to_sign .= $str_value . "\n";
        }

        // Append KIN for token requests if available
        if ($this->kin && isset($params['TOKEN'])) {
            if (!preg_match('/^[A-Z0-9]+$/', $this->kin)) {
                throw new Exception(__('Invalid KIN format', 'epay-onetouch'));
            }
            $string_to_sign .= $this->kin . "\n";
        }

        // Verify string to sign is not empty
        if (empty($string_to_sign)) {
            throw new Exception(__('Empty string for HMAC generation', 'epay-onetouch'));
        }

        // Generate HMAC with constant-time comparison safety
        $raw_hmac = hash_hmac('sha256', $string_to_sign, $this->secret_key, true);
        return bin2hex($raw_hmac);
    }

    /**
     * Generate unique device ID
     *
     * @param WC_Order|null $order Optional WooCommerce order
     * @return string Device ID
     */
    private function generate_device_id($order = null) {
        if ($order instanceof WC_Order) {
            return 'wc_order_' . $order->get_id() . '_' . time();
        }
        return md5(get_site_url());
    }

    /**
     * Generate unique key for request
     *
     * @return string Unique key
     */
    private function generate_unique_key() {
        return wp_generate_password(32, false);
    }

    /**
     * Refund payment
     *
     * @param string $payment_id Payment ID
     * @param float $amount Amount to refund
     * @param string $reason Refund reason
     * @return array Response from API
     */
    public function refund_payment($payment_id, $amount, $reason = '') {
        $params = array(
            'APPID' => $this->app_id,
            'PAYMENTID' => $payment_id,
            'AMOUNT' => $this->format_amount($amount),
            'DESCR' => $reason
        );

        return $this->make_request('api/payment/refund', $params, 'POST');
    }

    /**
     * Format amount according to ePay requirements
     *
     * @param float $amount Amount to format
     * @return string Formatted amount
     */
    private function format_amount($amount) {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Unique device identifier
     * @var string
     */
    private $device_id;

    /**
     * Callback URL for payment notifications
     * @var string
     */
    private $reply_address;

    /**
     * Whether the API is in test mode
     * @var bool
     */
    private $test_mode;
    

    

    
    private function generate_checksum($params, $kin = '') {
        ksort($params);
        $request_data = '';
        foreach ($params as $key => $value) {
            $request_data .= $key . $value . "\n";
        }
        // If request has TOKEN parameter, add KIN to request data
        if (isset($params['TOKEN']) && !empty($kin)) {
            $request_data .= $kin . "\n";
        }
        // For other cases where KIN is needed
        elseif (!empty($kin)) {
            $request_data .= $kin . "\n";
        }
        return hash_hmac('sha1', $request_data, $this->secret);
    }
    
    public function get_auth_url($key) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $this->device_id,
            'KEY' => $key,
            'DEVICE_NAME' => 'WordPress',
            'BRAND' => 'Web',
            'OS' => 'Web',
            'MODEL' => 'Browser',
            'OS_VERSION' => '1.0',
            'PHONE' => '0',
            'UTYPE' => '2' // Allow non-registered users
        );
        
        return $this->api_base . '/api/start?' . http_build_query($params);
    }
    
    /**
     * Получава код за токен
     *
     * @param string $key Ключ за генериране на токен
     * @return array Информация за токена
     * @throws Exception при грешка в API заявката
     */
    public function get_token_code($key) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $this->device_id,
            'KEY' => $key,
            'DEVICE_NAME' => php_uname('n'), // System hostname
            'BRAND' => php_uname('s'), // Operating system name
            'OS' => php_uname('s'),
            'MODEL' => php_uname('m'), // Machine type
            'OS_VERSION' => php_uname('r'), // OS release
            'PHONE' => '' // Empty as this is a server-side application
        );
        
        $response = wp_remote_get($this->api_base . '/api/code/get?' . http_build_query($params));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (!$result || isset($result['status']) && $result['status'] === 'ERR') {
            throw new Exception(isset($result['errm']) ? $result['errm'] : __('Неуспешно получаване на код за токен', 'epay-onetouch'));
        }
        
        return $result['code'];
    }
    

    


    /**
     * Инициализира ново плащане
     *
     * @param WC_Order $order Поръчка за плащане
     * @return array Информация за плащането
     * @throws Exception при грешка в API заявката
     */
    public function init_payment($order) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $this->device_id,
            'TYPE' => 'send',
            'AMOUNT' => $order->get_total(),
            'CURRENCY' => $order->get_currency(),
            'DESCR' => sprintf(__('Order %s on %s', 'epay-onetouch'), $order->get_order_number(), get_bloginfo('name')),
            'EXP' => time() + (3600 * 24), // 24 hours expiry
            'REPLY_ADDRESS' => $this->reply_address
        );
        
        // Add token if available
        $token = get_post_meta($this->get_order_id($order), '_epay_token', true);
        if (!empty($token)) {
            $params['TOKEN'] = $token;
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
        
        $response = wp_remote_post($this->api_base . '/payment/init', array(
            'body' => $params,
            'timeout' => 45,
        ));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (!$result || isset($result['status']) && $result['status'] === 'ERR') {
            throw new Exception(isset($result['errm']) ? $result['errm'] : __('Unknown error occurred', 'epay-onetouch'));
        }
        
        return $result;
    }
    
    public function get_payment_instruments($token) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $this->device_id,
            'TOKEN' => $token
        );
        
        $response = wp_remote_get($this->api_base . '/user/info/pins?' . http_build_query($params));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (!$result || isset($result['status']) && $result['status'] === 'ERR') {
            throw new Exception(isset($result['errm']) ? $result['errm'] : __('Неуспешно получаване на платежни инструменти', 'epay-onetouch'));
        }
        
        return $result['payment_instruments'];
    }
    
    public function get_instrument_balance($token, $pin_id) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $this->device_id,
            'TOKEN' => $token,
            'PINS' => $pin_id
        );
        
        $response = wp_remote_get($this->api_base . '/user/info/pins/balance?' . http_build_query($params));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (!$result || isset($result['status']) && $result['status'] === 'ERR') {
            throw new Exception(isset($result['errm']) ? $result['errm'] : __('Неуспешно получаване на баланс', 'epay-onetouch'));
        }
        
        return $result['payment_instruments'][0];
    }
    
    public function send_money($params) {
        $required_params = array('AMOUNT', 'DESCRIPTION', 'PINS', 'RCPT', 'RCPT_TYPE', 'REASON', 'ID');
        foreach ($required_params as $param) {
            if (!isset($params[$param])) {
                throw new Exception(sprintf(__('Missing required parameter: %s', 'epay-onetouch'), $param));
            }
        }
        
        // Add required system params
        $params['APPID'] = $this->app_id;
        $params['DEVICEID'] = $this->device_id;
        
        // Set default visibility if not provided
        if (!isset($params['SHOW'])) {
            $params['SHOW'] = 'KIN';
        }
        
        // First check payment parameters
        $check_response = wp_remote_post($this->api_base . '/payment/check', array(
            'body' => array_merge($params, array('TYPE' => 'send')),
            'timeout' => 45
        ));
        
        if (is_wp_error($check_response)) {
            throw new Exception($check_response->get_error_message());
        }
        
        $check_body = wp_remote_retrieve_body($check_response);
        $check_result = json_decode($check_body, true);
        
        if (!$check_result || isset($check_result['status']) && $check_result['status'] === 'ERR') {
            throw new Exception(isset($check_result['errm']) ? $check_result['errm'] : __('Неуспешна проверка на плащане', 'epay-onetouch'));
        }
        
        // If check passed, send the payment
        $send_response = wp_remote_post($this->api_base . '/payment/send/user', array(
            'body' => $params,
            'timeout' => 45
        ));
        
        if (is_wp_error($send_response)) {
            throw new Exception($send_response->get_error_message());
        }
        
        $send_body = wp_remote_retrieve_body($send_response);
        $send_result = json_decode($send_body, true);
        
        if (!$send_result || isset($send_result['status']) && $send_result['status'] === 'ERR') {
            throw new Exception(isset($send_result['errm']) ? $send_result['errm'] : __('Неуспешно плащане', 'epay-onetouch'));
        }
        
        return $send_result['payment'];
    }
    
    public function check_send_status($payment_id) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $this->device_id,
            'ID' => $payment_id
        );
        
        $response = wp_remote_post($this->api_base . '/payment/send/status', array(
            'body' => $params,
            'timeout' => 45
        ));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (!$result || isset($result['status']) && $result['status'] === 'ERR') {
            throw new Exception(isset($result['errm']) ? $result['errm'] : __('Failed to check payment status', 'epay-onetouch'));
        }
        
        return $result['payment'];
    }
    
    public function init_unregistered_payment($params) {
        $required_params = array('ID', 'AMOUNT', 'RCPT', 'RCPT_TYPE', 'DESCRIPTION', 'REASON');
        foreach ($required_params as $param) {
            if (!isset($params[$param])) {
                throw new Exception(sprintf(__('Missing required parameter: %s', 'epay-onetouch'), $param));
            }
        }
        
        // Add required system params
        $params['APPID'] = $this->app_id;
        $params['DEVICEID'] = $this->device_id;
        
        // Generate checksum
        $params['APPCHECK'] = $this->generate_checksum($params);
        
        // Build the URL for unregistered payment
        $url = $this->api_base . '/api/payment/noreg/send?' . http_build_query($params);
        
        return $url;
    }
    
    public function check_unregistered_payment_status($payment_id, $rcpt) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $this->device_id,
            'ID' => $payment_id,
            'RCPT' => $rcpt
        );
        
        $response = wp_remote_get($this->api_base . '/api/payment/noreg/send/status?' . http_build_query($params));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (!$result || isset($result['status']) && $result['status'] === 'ERR') {
            throw new Exception(isset($result['errm']) ? $result['errm'] : __('Failed to check payment status', 'epay-onetouch'));
        }
        
        return $result;
    }
    

}
