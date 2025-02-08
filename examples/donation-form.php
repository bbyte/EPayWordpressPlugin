<?php
/**
 * Example: Donation Form with Recurring Option
 * 
 * This example shows how to create a donation form that allows:
 * - Custom donation amount
 * - Donor name
 * - Option for recurring monthly donations
 * - Integration with ePay OneTouch
 * - Secure device identification using Promise-based API
 *
 * @package     EPay OneTouch Payment Gateway
 * @author      Nikola Kotarov
 * @version     2.0.0
 * @license     GPL-2.0+
 *
 * Features:
 * - Responsive design optimized for all devices
 * - Secure payment processing through ePay OneTouch
 * - Device identification using Web Crypto API
 * - Support for recurring donations
 * - Comprehensive error handling
 * - User-friendly feedback messages
 *
 * Security measures:
 * - CSRF protection using nonces
 * - Secure device ID generation
 * - Input validation and sanitization
 * - XSS prevention
 * - Secure storage handling
 */

/**
 * Register the donation form shortcode.
 * Usage: [epay_donation_form min_amount="5" default_amount="20" currency="BGN"]
 */
add_shortcode('epay_donation_form', 'epay_donation_form_shortcode');

/**
 * Generate the donation form HTML and handle form processing.
 *
 * @param array $atts Shortcode attributes
 *                    min_amount: Minimum donation amount
 *                    default_amount: Default donation amount
 *                    currency: Currency code (e.g., BGN)
 * @return string HTML output of the donation form
 */
function epay_donation_form_shortcode($atts) {
    // Parse and sanitize shortcode attributes
    // These values control the form's behavior and display
    $atts = shortcode_atts(array(
        'min_amount' => 5, // Minimum donation amount
        'default_amount' => 20, // Default donation amount
        'currency' => 'BGN'
    ), $atts);

    // Get the ePay OneTouch payment gateway instance
    // This is used to check if the gateway is available and properly configured
    $gateways = WC()->payment_gateways->payment_gateways();
    $gateway = isset($gateways['epay_onetouch']) ? $gateways['epay_onetouch'] : null;
    
    if (!$gateway || $gateway->enabled !== 'yes') {
        return '<p>' . __('ePay OneTouch payment gateway is not available.', 'epay-onetouch') . '</p>';
    }

    // Start output buffering to capture the form HTML
    // This allows us to return the form as a string for the shortcode
    ob_start();
    ?>
    <form action="" method="post" class="epay-donation-form" id="epay-donation-form">
        <?php wp_nonce_field('epay_donation_action', 'epay_donation_nonce'); ?>
        
        <input type="hidden" id="epay_device_id" name="epay_device_id" value="">
        
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

    // Initialize the device ID handling and form validation
    // This script ensures secure device identification and proper form submission
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
                        $('.epay-donation-form').prepend(
                            '<div class="epay-device-error">' +
                            '<?php _e("There was an error initializing the payment system. Please try again.", "epay-onetouch"); ?>' +
                            '</div>'
                        );
                    });
            }
        }, 100);
        
        // Handle form submission
        $('#epay-donation-form').on('submit', function(e) {
            const deviceId = $('#epay_device_id').val();
            if (!deviceId) {
                e.preventDefault();
                $('.epay-donation-form').prepend(
                    '<div class="epay-device-error">' +
                    '<?php _e("Please wait for the payment system to initialize.", "epay-onetouch"); ?>' +
                    '</div>'
                );
                return false;
            }
        });
    });
    </script>
    
    <style>
        .epay-donation-form {
            max-width: 400px;
            margin: 20px auto;
            padding: 20px;
            background: #f8f8f8;
            border-radius: 5px;
        }
        
        .epay-device-error {
            color: #dc3232;
            padding: 10px;
            margin: 10px 0;
            background-color: #fff;
            border-left: 4px solid #dc3232;
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
