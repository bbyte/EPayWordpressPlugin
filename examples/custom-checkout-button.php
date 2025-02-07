<?php
/**
 * Example: Custom Checkout Button for ePay OneTouch
 * 
 * This example shows how to create a custom checkout button that redirects
 * to ePay OneTouch payment directly from any WordPress page.
 */

// Add this to your theme's functions.php or in a custom plugin

function add_epay_onetouch_button($product_id) {
    // Get WooCommerce product
    $product = wc_get_product($product_id);
    if (!$product) {
        return '';
    }

    // Get ePay OneTouch gateway instance
    $gateways = WC()->payment_gateways->payment_gateways();
    $gateway = isset($gateways['epay_onetouch']) ? $gateways['epay_onetouch'] : null;
    
    if (!$gateway || $gateway->enabled !== 'yes') {
        return '';
    }

    // Create button HTML
    $button_text = __('Pay with ePay OneTouch', 'epay-onetouch');
    $button_html = sprintf(
        '<form action="%s" method="post" class="epay-onetouch-button-form">
            <input type="hidden" name="add-to-cart" value="%d">
            <input type="hidden" name="epay_payment_type" value="unregistered">
            <button type="submit" class="button epay-onetouch-button">%s</button>
        </form>',
        esc_url(wc_get_checkout_url()),
        esc_attr($product_id),
        esc_html($button_text)
    );

    return $button_html;
}

// Example usage in a template:
// echo add_epay_onetouch_button(123); // Replace 123 with your product ID
