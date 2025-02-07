<?php
/**
 * Example: Custom Order Processing with ePay OneTouch
 * 
 * This example shows how to handle custom order processing and
 * how to integrate with the ePay OneTouch payment status.
 */

// Add this to your theme's functions.php or in a custom plugin

// Hook into WooCommerce order status changes
add_action('woocommerce_order_status_changed', 'custom_epay_order_processing', 10, 4);

function custom_epay_order_processing($order_id, $old_status, $new_status, $order) {
    // Check if this is an ePay OneTouch order
    $payment_method = $order->get_payment_method();
    if ($payment_method !== 'epay_onetouch') {
        return;
    }

    // Get payment ID from order meta
    $payment_id = $order->get_meta('_epay_payment_id');
    if (empty($payment_id)) {
        return;
    }

    // Example: Send email when payment is completed
    if ($new_status === 'completed') {
        $transaction_no = $order->get_meta('_epay_transaction_no');
        
        // Custom email notification
        $to = $order->get_billing_email();
        $subject = sprintf(__('Payment completed for order %s', 'epay-onetouch'), $order->get_order_number());
        $message = sprintf(
            __('Your payment via ePay OneTouch has been completed.\nTransaction ID: %s', 'epay-onetouch'),
            $transaction_no
        );
        
        wp_mail($to, $subject, $message);
    }

    // Example: Custom logging
    $log_entry = sprintf(
        'Order %s status changed from %s to %s. Payment ID: %s',
        $order->get_order_number(),
        $old_status,
        $new_status,
        $payment_id
    );
    
    error_log($log_entry);
}
