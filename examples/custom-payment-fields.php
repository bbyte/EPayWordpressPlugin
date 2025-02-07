<?php
/**
 * Example: Customizing Payment Fields
 * 
 * This example shows how to customize the payment fields
 * displayed on the checkout page.
 */

// Add this to your theme's functions.php or in a custom plugin

// Add custom field to payment gateway settings
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
    
    // Add custom CSS
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
    </style>
    <?php
}
