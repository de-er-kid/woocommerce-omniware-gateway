<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Omniware_Gateway extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'omniware';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'OmniWare Payment Gateway';
        $this->method_description = 'Accept payments through OmniWare payment gateway';
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->api_key = $this->get_option('api_key');
        $this->salt = $this->get_option('salt');
        $this->test_mode = $this->get_option('test_mode');
        $this->merchant_id = $this->get_option('merchant_id');
        $this->merchant_name = $this->get_option('merchant_name');

        // Add these action hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_api_wc_omniware_gateway', array($this, 'check_response'));
    }
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        // Mark as pending payment
        $order->update_status('pending', __('Awaiting OmniWare payment', 'wc-omniware-gateway'));

        // Return redirect to payment page
        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(true)
        );
    }
    // Remove the first receipt_page method and keep only this one
    public function receipt_page($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $payment_data = array(
            'api_key' => $this->api_key,
            'return_url' => WC()->api_request_url('WC_Omniware_Gateway'),
            'mode' => $this->test_mode === 'yes' ? 'TEST' : 'LIVE',
            'order_id' => $order_id,
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'description' => 'Order #' . $order_id,
            'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'address_line_1' => $order->get_billing_address_1(),
            'address_line_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'zip_code' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
            'udf1' => '',
            'udf2' => '',
            'udf3' => '',
            'udf4' => '',
            'udf5' => ''
        );

        $hash = $this->calculate_hash($payment_data);
        $payment_data['hash'] = $hash;
?>
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Processing Payment...</title>
        </head>

        <body>
            <div style="text-align: center; padding: 40px;">
                <h1>Processing your payment</h1>
                <p>Please do not refresh this page...</p>
            </div>
            <form action="https://pgbiz.omniware.in/v2/paymentrequest" method="post" id="omniware_payment_form">
                <?php foreach ($payment_data as $key => $value): ?>
                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                <?php endforeach; ?>
            </form>
            <script type="text/javascript">
                window.onload = function() {
                    document.getElementById('omniware_payment_form').submit();
                };
            </script>
        </body>

        </html>
    <?php
        exit;
    }
    private function generate_payment_form($data)
    {
        ob_start();
    ?>
        <!DOCTYPE html>
        <html>

        <head>
            <title>Redirecting to Payment Gateway...</title>
        </head>

        <body>
            <p>Please wait while we redirect you to the payment gateway...</p>
            <form action="https://pgbiz.omniware.in/v2/paymentrequest" method="post" id="omniware_payment_form">
                <?php foreach ($data as $key => $value): ?>
                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                <?php endforeach; ?>
            </form>
            <script type="text/javascript">
                document.getElementById("omniware_payment_form").submit();
            </script>
        </body>

        </html>
<?php
        return ob_get_clean();
    }

    private function calculate_hash($data)
    {
        $hash_columns = [
            'address_line_1',
            'address_line_2',
            'amount',
            'api_key',
            'city',
            'country',
            'currency',
            'description',
            'email',
            'mode',
            'name',
            'order_id',
            'phone',
            'return_url',
            'state',
            'udf1',
            'udf2',
            'udf3',
            'udf4',
            'udf5',
            'zip_code'
        ];
        sort($hash_columns);

        $hash_data = $this->salt;
        foreach ($hash_columns as $column) {
            if (isset($data[$column]) && strlen($data[$column]) > 0) {
                $hash_data .= '|' . trim($data[$column]);
            }
        }

        return strtoupper(hash("sha512", $hash_data));
    }

    private function get_payment_url($data)
    {
        $form_html = '<form action="https://pgbiz.omniware.in/v2/paymentrequest" method="post" id="omniware_payment_form">';
        foreach ($data as $key => $value) {
            $form_html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        }
        $form_html .= '</form>';
        $form_html .= '<script>document.getElementById("omniware_payment_form").submit();</script>';

        return $form_html;
    }

    public function check_response()
    {
        $response_data = $_POST;

        if (empty($response_data)) {
            wp_die('OmniWare Response Empty', 'OmniWare', array('response' => 500));
        }

        $order = wc_get_order($response_data['order_id']);

        if (!$order) {
            wp_die('Order not found', 'OmniWare', array('response' => 500));
        }

        // Verify payment status from OmniWare response
        if (isset($response_data['status'])) {
            switch ($response_data['status']) {
                case 'SUCCESS':
                    $order->update_status('processing', 'OmniWare payment completed successfully');
                    break;

                case 'CANCELLED':
                    $order->update_status('failed', 'Payment was cancelled by the customer');
                    wc_add_notice(__('Payment cancelled.', 'wc-omniware-gateway'), 'error');
                    break;

                case 'FAILED':
                    $order->update_status('failed', 'Payment failed at OmniWare gateway');
                    wc_add_notice(__('Payment failed.', 'wc-omniware-gateway'), 'error');
                    break;

                default:
                    $order->update_status('failed', 'Payment status: ' . $response_data['status']);
                    wc_add_notice(__('Payment failed.', 'wc-omniware-gateway'), 'error');
                    break;
            }
        } else {
            $order->update_status('failed', 'Invalid payment response received');
            wc_add_notice(__('Invalid payment response.', 'wc-omniware-gateway'), 'error');
        }

        // Redirect based on payment status
        if ($order->has_status('processing')) {
            wp_redirect($this->get_return_url($order));
        } else {
            wp_redirect(wc_get_checkout_url());
        }
        exit;
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'wc-omniware-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Enable OmniWare Payment', 'wc-omniware-gateway'),
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => __('Title', 'wc-omniware-gateway'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'wc-omniware-gateway'),
                'default'     => __('OmniWare Payment', 'wc-omniware-gateway'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'wc-omniware-gateway'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'wc-omniware-gateway'),
                'default'     => __('Pay securely using OmniWare payment gateway.', 'wc-omniware-gateway'),
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => __('API Key', 'wc-omniware-gateway'),
                'type'        => 'text',
                'description' => __('Enter your OmniWare API Key', 'wc-omniware-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'salt' => array(
                'title'       => __('Salt', 'wc-omniware-gateway'),
                'type'        => 'password',
                'description' => __('Enter your OmniWare Salt key', 'wc-omniware-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'test_mode' => array(
                'title'       => __('Test Mode', 'wc-omniware-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Enable Test Mode', 'wc-omniware-gateway'),
                'default'     => 'yes',
                'description' => __('Place the payment gateway in test mode.', 'wc-omniware-gateway'),
            ),
            'merchant_id' => array(
                'title'       => __('Merchant ID', 'wc-omniware-gateway'),
                'type'        => 'text',
                'description' => __('Enter your OmniWare Merchant ID', 'wc-omniware-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'merchant_name' => array(
                'title'       => __('Merchant Name', 'wc-omniware-gateway'),
                'type'        => 'text',
                'description' => __('Enter your OmniWare Merchant Name', 'wc-omniware-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            )
        );
    }
}
