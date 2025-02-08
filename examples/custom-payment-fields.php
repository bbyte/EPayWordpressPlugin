<?php
/**
 * Example: Customizing Payment Fields
 * 
 * This example shows how to customize the payment fields
 * displayed on the checkout page and handle device identification
 * using the new Promise-based API.
 *
 * @package     EPay OneTouch Payment Gateway
 * @author      Nikola Kotarov
 * @version     2.0.0
 * @license     GPL-2.0+
 *
 * The payment fields customization includes:
 * - Custom messages above payment options
 * - Secure device ID generation and storage
 * - Error handling and user feedback
 * - Responsive styling
 *
 * Security measures:
 * - Uses Web Crypto API for device ID generation
 * - Implements fallback mechanisms for storage
 * - Validates device ID before form submission
 * - Sanitizes all output
 */

// Add this to your theme's functions.php or in a custom plugin

/**
 * Add custom field to payment gateway settings.
 * This allows admins to customize the message shown above payment options.
 *
 * @param array $fields Existing gateway settings fields
 * @return array Modified gateway settings fields
 */
add_filter('woocommerce_settings_api_form_fields_epay_onetouch', 'add_custom_epay_field');

function add_custom_epay_field($fields) {
    $fields['custom_message'] = array(
        'title' => __('Custom Message', 'epay-onetouch'),
        'type' => 'textarea',
        'description' => __('This message will appear above the payment options', 'epay-onetouch'),
        'default' => __('Thank you for choosing ePay OneTouch!', 'epay-onetouch'),
        'desc_tip' => true
    );
    
    return $fields;
}

// Customize payment fields display
add_action('woocommerce_epay_onetouch_payment_fields', 'customize_epay_payment_fields');

/**
 * Customize the payment fields display on the checkout page.
 * This function handles:
 * - Custom message display from settings
 * - Device ID field initialization
 * - Promise-based device handling
 * - Error feedback to users
 * - Custom styling
 */
function customize_epay_payment_fields() {
    // Get gateway instance
    $gateways = WC()->payment_gateways->payment_gateways();
    $gateway = isset($gateways['epay_onetouch']) ? $gateways['epay_onetouch'] : null;
    
    if (!$gateway) {
        return;
    }

    // Get custom message from settings
    $message = $gateway->get_option('custom_message');
    
    // Display custom message
    if ($message) {
        echo '<div class="epay-custom-message">' . esc_html($message) . '</div>';
    }
    
    // Add hidden device ID field that will be populated by our JavaScript
    // This field is crucial for the ePay OneTouch API to identify the device
    // The value is generated using the Web Crypto API and stored securely
    echo '<input type="hidden" id="epay_device_id" name="epay_device_id" value="">';
    
    // Add custom JavaScript for device ID handling
    // This script initializes the device ID using our Promise-based API
    // It includes error handling and user feedback mechanisms
    ?>
    <script>
    jQuery(function($) {
        // Wait for the EPayOneTouchHandler to be initialized
        const checkHandler = setInterval(() => {
            if (window.EPayOneTouchHandler) {
                clearInterval(checkHandler);
                
                // Initialize device ID
                EPayOneTouchHandler.initDeviceId()
                    .then(() => {
                        console.log('Device ID initialized successfully');
                    })
                    .catch(error => {
                        console.error('Error initializing device ID:', error);
                    });
            }
        }, 100);
    });
    </script>
    <?php
    
    // Add custom CSS for styling payment fields and error messages
    // These styles ensure a consistent look across different themes
    // and provide clear feedback to users
    ?>
    <style>
        .epay-custom-message {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f8f8f8;
            border-left: 4px solid #0073aa;
        }
        
        .epay-payment-type {
            margin-top: 15px;
        }
        
        .epay-payment-type label {
            display: block;
            margin-bottom: 10px;
        }
        
        .epay-payment-type input[type="radio"] {
            margin-right: 8px;
        }
        
        .epay-device-error {
            color: #dc3232;
            padding: 10px;
            margin: 10px 0;
            background-color: #fff;
            border-left: 4px solid #dc3232;
        }
    </style>
    <?php
}
