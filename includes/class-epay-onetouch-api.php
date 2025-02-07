<?php
/**
 * ePay OneTouch API Integration Class
 *
 * @package ePay_OneTouch
 * @author  Nikola Kotarov
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ePay OneTouch API Integration Class
 *
 * Handles all API communication with the ePay OneTouch payment system.
 * Provides methods for payment initialization, token management, and payment status checks.
 *
 * @since 1.0.0
 */
class WC_Epay_Onetouch_API {
    /**
     * Base URL for the ePay API
     * @var string
     */
    private $api_base;

    /**
     * Application ID provided by ePay
     * @var string
     */
    private $app_id;

    /**
     * Secret key for API authentication
     * @var string
     */
    private $secret;

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
    
    public function __construct() {
        $gateway_settings = get_option('woocommerce_epay_onetouch_settings');
        $this->test_mode = isset($gateway_settings['testmode']) && $gateway_settings['testmode'] === 'yes';
        
        // Get API URLs from settings, falling back to defaults if not set
        $api_url_test = !empty($gateway_settings['api_url_test']) ? 
            $gateway_settings['api_url_test'] : 'https://demo.epay.bg/xdev/api';
        $api_url_prod = !empty($gateway_settings['api_url_prod']) ? 
            $gateway_settings['api_url_prod'] : 'https://epay.bg/api';
            
        $this->api_base = $this->test_mode ? $api_url_test : $api_url_prod;
        
        $this->app_id = isset($gateway_settings['app_id']) ? $gateway_settings['app_id'] : '';
        $this->secret = isset($gateway_settings['secret']) ? $gateway_settings['secret'] : '';
        $this->reply_address = isset($gateway_settings['reply_address']) ? $gateway_settings['reply_address'] : home_url('/wc-api/epay_onetouch_callback');
        $this->device_id = $this->generate_device_id();
    }
    
    private function generate_device_id() {
        // Generate a unique device ID for the website
        return md5(get_site_url());
    }
    
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
    
    public function get_token($code) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $this->device_id,
            'CODE' => $code
        );
        
        $response = wp_remote_get($this->api_base . '/api/token/get?' . http_build_query($params));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (!$result || isset($result['status']) && $result['status'] === 'ERR') {
            throw new Exception(isset($result['errm']) ? $result['errm'] : __('Неуспешно получаване на токен', 'epay-onetouch'));
        }
        
        return array(
            'token' => $result['TOKEN'],
            'expires' => $result['EXPIRES'],
            'kin' => $result['KIN'],
            'username' => $result['USERNAME'],
            'realname' => $result['REALNAME']
        );
    }
    
    public function invalidate_token($token) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $this->device_id,
            'TOKEN' => $token
        );
        
        $response = wp_remote_get($this->api_base . '/api/token/invalidate?' . http_build_query($params));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (!$result || isset($result['status']) && $result['status'] === 'ERR') {
            throw new Exception(isset($result['errm']) ? $result['errm'] : __('Неуспешно анулиране на токен', 'epay-onetouch'));
        }
        
        return true;
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
    
    /**
     * Проверява статуса на плащане
     *
     * @param string $payment_id ID на плащането
     * @return array Статус на плащането
     * @throws Exception при грешка в API заявката
     */
    public function check_payment_status($payment_id) {
        $params = array(
            'APPID' => $this->app_id,
            'DEVICEID' => $this->device_id,
            'ID' => $payment_id
        );
        
        $params['APPCHECK'] = $this->generate_checksum($params);
        
        $response = wp_remote_get($this->api_base . '/payment/check?' . http_build_query($params));
        
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
}
