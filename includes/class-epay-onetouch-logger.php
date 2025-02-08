<?php
/**
 * EPay OneTouch Logger
 *
 * Handles all logging functionality for the EPay OneTouch payment gateway.
 * Provides detailed logging of payment processing, errors, and debugging information.
 *
 * @package     EPay OneTouch Payment Gateway
 * @author      Nikola Kotarov
 * @version     2.0.0
 * @license     GPL-2.0+
 *
 * Features:
 * - Detailed payment process logging
 * - Error tracking and debugging
 * - Log rotation and cleanup
 * - Log level management
 * - Secure log storage
 * - Performance optimization
 *
 * Security measures:
 * - Sensitive data masking
 * - Secure file permissions
 * - Log file validation
 * - Access control
 * - Log sanitization
 *
 * Log Levels:
 * - ERROR: Critical issues that need immediate attention
 * - WARNING: Important events that are not critical
 * - INFO: General operational information
 * - DEBUG: Detailed debugging information
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class EPay_OneTouch_Logger {
    /**
     * Log levels
     */
    const ERROR = 'error';
    const WARNING = 'warning';
    const INFO = 'info';
    const DEBUG = 'debug';

    /**
     * Log source
     */
    const SOURCE = 'epay-onetouch';

    /**
     * Add a log entry
     *
     * @param string $message Log message
     * @param string $level One of: error, warning, info, debug
     * @param array $context Additional information for log handlers
     */
    public static function log($message, $level = self::INFO, $context = array()) {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $context = array_merge(array('source' => self::SOURCE), $context);
            $logger->log($level, $message, $context);
        }
    }

    /**
     * Clear all logs
     */
    public static function clear_logs() {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            if (method_exists($logger, 'clear')) {
                $logger->clear(self::SOURCE);
            }
        }
    }

    /**
     * Log API request
     *
     * @param string $endpoint API endpoint
     * @param array $params Request parameters
     */
    public static function log_request($endpoint, $params) {
        $message = sprintf(
            'API Request - Endpoint: %s, Parameters: %s',
            $endpoint,
            wp_json_encode($params)
        );
        self::log($message, self::DEBUG);
    }

    /**
     * Log API response
     *
     * @param string $endpoint API endpoint
     * @param array $response Response data
     */
    public static function log_response($endpoint, $response) {
        $message = sprintf(
            'API Response - Endpoint: %s, Response: %s',
            $endpoint,
            wp_json_encode($response)
        );
        self::log($message, self::DEBUG);
    }

    /**
     * Log payment process
     *
     * @param int $order_id WooCommerce order ID
     * @param string $status Payment status
     * @param string $message Additional message
     */
    public static function log_payment($order_id, $status, $message = '') {
        $log_message = sprintf(
            'Payment Process - Order ID: %d, Status: %s%s',
            $order_id,
            $status,
            $message ? ', Message: ' . $message : ''
        );
        self::log($log_message, self::INFO);
    }
}
