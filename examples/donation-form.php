<?php
/**
 * Example: Donation Form with Recurring Option
 * 
 * This example shows how to create a donation form that allows:
 * - Custom donation amount
 * - Donor name
 * - Option for recurring monthly donations
 * - Integration with ePay OneTouch
 */

// Add shortcode for the donation form
add_shortcode('epay_donation_form', 'epay_donation_form_shortcode');

function epay_donation_form_shortcode($atts) {
    // Parse attributes
    $atts = shortcode_atts(array(
        'min_amount' => 5, // Minimum donation amount
        'default_amount' => 20, // Default donation amount
        'currency' => 'BGN'
    ), $atts);

    // Get gateway instance
    $gateways = WC()->payment_gateways->payment_gateways();
    $gateway = isset($gateways['epay_onetouch']) ? $gateways['epay_onetouch'] : null;
    
    if (!$gateway || $gateway->enabled !== 'yes') {
        return '<p>' . __('ePay OneTouch payment gateway is not available.', 'epay-onetouch') . '</p>';
    }

    // Start output buffering
    ob_start();
    ?>
    <form action="" method="post" class="epay-donation-form" id="epay-donation-form">
        <?php wp_nonce_field('epay_donation_action', 'epay_donation_nonce'); ?>
        
        <div class="form-row">
            <label for="donor_name"><?php _e('Your Name', 'epay-onetouch'); ?></label>
            <input type="text" id="donor_name" name="donor_name" required>
        </div>

        <div class="form-row">
            <label for="donation_amount"><?php _e('Donation Amount', 'epay-onetouch'); ?></label>
            <div class="amount-wrapper">
                <input type="number" 
                       id="donation_amount" 
                       name="donation_amount" 
                       min="<?php echo esc_attr($atts['min_amount']); ?>" 
                       value="<?php echo esc_attr($atts['default_amount']); ?>" 
                       required>
                <span class="currency"><?php echo esc_html($atts['currency']); ?></span>
            </div>
        </div>

        <div class="form-row">
            <label>
                <input type="checkbox" name="is_recurring" id="is_recurring" value="1">
                <?php _e('Make this a monthly donation', 'epay-onetouch'); ?>
            </label>
        </div>

        <button type="submit" class="donation-submit">
            <?php _e('Donate Now', 'epay-onetouch'); ?>
        </button>
    </form>

    <style>
        .epay-donation-form {
            max-width: 400px;
            margin: 20px auto;
            padding: 20px;
            background: #f8f8f8;
            border-radius: 5px;
        }
        .form-row {
            margin-bottom: 15px;
        }
        .form-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .amount-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .amount-wrapper input {
            flex: 1;
            padding-right: 50px;
        }
        .amount-wrapper .currency {
            position: absolute;
            right: 10px;
            color: #666;
        }
        .donation-submit {
            width: 100%;
            padding: 10px;
            background: #0073aa;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        .donation-submit:hover {
            background: #005177;
        }
    </style>
    <?php
    return ob_get_clean();
}

// Handle form submission
add_action('init', 'handle_donation_form_submission');

function handle_donation_form_submission() {
    if (!isset($_POST['epay_donation_nonce']) || 
        !wp_verify_nonce($_POST['epay_donation_nonce'], 'epay_donation_action')) {
        return;
    }

    if (isset($_POST['donation_amount'], $_POST['donor_name'])) {
        $amount = floatval($_POST['donation_amount']);
        $donor_name = sanitize_text_field($_POST['donor_name']);
        $is_recurring = isset($_POST['is_recurring']) ? true : false;

        // Create WooCommerce order
        $order = wc_create_order();
        
        // Add donation as a custom line item
        $item = new WC_Order_Item_Fee();
        $item->set_name(sprintf(
            __('Donation from %s%s', 'epay-onetouch'),
            $donor_name,
            $is_recurring ? ' ' . __('(Monthly)', 'epay-onetouch') : ''
        ));
        $item->set_amount($amount);
        $item->set_total($amount);
        $order->add_item($item);

        // Set order metadata
        $order->set_payment_method('epay_onetouch');
        $order->update_meta_data('_donor_name', $donor_name);
        $order->update_meta_data('_is_recurring_donation', $is_recurring);
        
        // Calculate and set totals
        $order->calculate_totals();
        $order->save();

        // Get gateway instance
        $gateways = WC()->payment_gateways->payment_gateways();
        $gateway = isset($gateways['epay_onetouch']) ? $gateways['epay_onetouch'] : null;

        if ($gateway) {
            // Prepare payment parameters
            $payment_type = 'unregistered'; // Allow unregistered donations
            
            // Store payment type in order
            $order->update_meta_data('_epay_payment_type', $payment_type);
            $order->save();

            // Process payment
            $result = $gateway->process_payment($order->get_id());

            if ($result['result'] === 'success') {
                wp_redirect($result['redirect']);
                exit;
            }
        }
    }
}

// Add custom order note for recurring donations
add_action('woocommerce_payment_complete', 'add_donation_order_note');

function add_donation_order_note($order_id) {
    $order = wc_get_order($order_id);
    
    if (!$order) {
        return;
    }

    $is_recurring = $order->get_meta('_is_recurring_donation');
    $donor_name = $order->get_meta('_donor_name');

    if ($is_recurring) {
        $note = sprintf(
            __('Recurring monthly donation set up for %s. Next payment will be processed in 30 days.', 'epay-onetouch'),
            $donor_name
        );
        $order->add_order_note($note);
        
        // Schedule next payment
        wp_schedule_single_event(
            strtotime('+30 days'),
            'process_recurring_donation',
            array($order_id)
        );
    }
}

// Handle recurring donations
add_action('process_recurring_donation', 'handle_recurring_donation');

function handle_recurring_donation($original_order_id) {
    $original_order = wc_get_order($original_order_id);
    
    if (!$original_order) {
        return;
    }

    // Create new order for recurring donation
    $new_order = wc_create_order();
    
    // Copy items from original order
    foreach ($original_order->get_items() as $item) {
        $new_order->add_item($item);
    }

    // Copy metadata
    $new_order->update_meta_data('_donor_name', $original_order->get_meta('_donor_name'));
    $new_order->update_meta_data('_is_recurring_donation', true);
    $new_order->update_meta_data('_original_donation_order_id', $original_order_id);
    
    // Set payment method
    $new_order->set_payment_method('epay_onetouch');
    
    // Calculate totals
    $new_order->calculate_totals();
    $new_order->save();

    // Process payment
    $gateways = WC()->payment_gateways->payment_gateways();
    $gateway = isset($gateways['epay_onetouch']) ? $gateways['epay_onetouch'] : null;

    if ($gateway) {
        $result = $gateway->process_payment($new_order->get_id());
        
        if ($result['result'] === 'success') {
            // Schedule next payment
            wp_schedule_single_event(
                strtotime('+30 days'),
                'process_recurring_donation',
                array($new_order->get_id())
            );
        }
    }
}
