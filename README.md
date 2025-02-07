# ePay OneTouch Payment Gateway for WordPress

This plugin integrates the ePay OneTouch payment gateway with WordPress and WooCommerce, allowing you to accept payments through ePay.bg.

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- PHP 7.2 or higher
- Valid ePay OneTouch merchant account with APP ID and Secret Key

## Installation

1. Download the plugin zip file
2. Go to WordPress admin panel > Plugins > Add New
3. Click "Upload Plugin" and select the downloaded zip file
4. Click "Install Now" and then "Activate"

## Configuration

1. Go to WooCommerce > Settings > Payments
2. Click on "ePay OneTouch" to configure the payment gateway
3. Enable the payment gateway
4. Enter your APP ID and Secret Key provided by ePay.bg
5. Customize the title and description that appears to customers during checkout
6. Save changes

## Features

- Seamless integration with WooCommerce checkout
- Secure payment processing through ePay OneTouch
- Automatic order status updates
- Support for both test and live environments
- Detailed payment information in order details

## Examples

The `examples` directory contains sample code demonstrating various ways to customize and extend the plugin:

### 1. Custom Checkout Button (`custom-checkout-button.php`)
Add an ePay OneTouch payment button anywhere on your site:

```php
// Add button to a product page
echo add_epay_onetouch_button($product_id);
```

### 2. Custom Order Processing (`custom-order-processing.php`)
Handle custom order processing and payment status:

```php
// Hook into order status changes
add_action('woocommerce_order_status_changed', 'custom_epay_order_processing', 10, 4);
```

### 3. Custom Payment Fields (`custom-payment-fields.php`)
Customize the payment fields on the checkout page:

```php
// Add custom message above payment options
add_filter('woocommerce_settings_api_form_fields_epay_onetouch', 'add_custom_epay_field');

// Customize payment fields display
add_action('woocommerce_epay_onetouch_payment_fields', 'customize_epay_payment_fields');
```

### 4. Donation Form (`donation-form.php`)
Implement a donation form with recurring payment option:

```php
// Add the donation form to any page or post
[epay_donation_form min_amount="5" default_amount="20" currency="BGN"]

// Process recurring donations automatically
add_action('process_recurring_donation', 'handle_recurring_donation');
```

Features:
- Custom donation amount with minimum limit
- Donor name collection
- Optional monthly recurring donations
- Automatic payment processing
- Clean, responsive design
- WooCommerce order integration

For more details, check the individual example files with full code and comments.

## Support

For support or questions, please contact ePay.bg support or visit their documentation at https://epay.bg

## License

This plugin is licensed under the GPL v2 or later.
