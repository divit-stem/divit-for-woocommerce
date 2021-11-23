<?php

class WC_Divit_Payment_Gateway extends WC_Payment_Gateway
{
    
    public function __construct()
    {
        $this->environments = include 'environments.php';
        $this->logger = new WC_Logger;
        
        $this->id           = 'divit_payment';
        $this->method_title = 'Divit payment gateway';
        $this->method_description = 'buy now, pay later, earn miles';
        $this->title        = 'Divit payment gateway';
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled           = $this->get_option('enabled');
        $this->mode              = $this->get_option('mode');
        $this->title             = $this->get_option('title');
        $this->description       = $this->get_option('description');
        $this->client_id = $this->get_option('client_id');
        $this->client_secret = $this->get_option('client_secret');
        
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
            $this,
            'process_admin_options'
        ));

        // register callback for `webhook_events`
        add_action( 'woocommerce_api_callback_divit_payment', array($this, 'callback_handler') );
    }

    public function get_env() {
        return $this->environments[$this->mode];
    }
    
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce-other-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable divit payment', 'woocommerce-other-payment-gateway'),
                'default' => 'yes'
            ),
            'mode' => array(
                'title' => __('Environment', 'woocommerce-other-payment-gateway'),
                'type' => 'select',
                'options' => wp_list_pluck($this->environments, 'name'),
                'default' => 'test'
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => '',
                'default' => 'Divit'
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'text',
                'default' => 'Buy now, pay later, earn miles',
                'description' => '',
            ),
            'client_id' => array(
                'title' => 'Client id',
                'type' => 'text',
                'default' => '',
                'description' => 'client id for login divit',
            ),
            'client_secret' => array(
                'title' => 'Client secret',
                'type' => 'password',
                'default' => '',
                'description' => 'client secret for login divit',
            ),
        );
    }
    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     *
     * @since 1.0.0
     * @return void
     */
    public function admin_options()
    {
?>
       <h3>Divit Payment Setting</h3>
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        <table class="form-table">
                            <?php $this->generate_settings_html(); ?>
                       </table>
                    </div>
                </div>
                <div class="clear"></div>
                <?php
    }
    
    // validate customer fields
    public function validate_fields()
    {
        // if ($this->text_box_required === 'no') {
        //     return true;
        // }
        
        // $textbox_value = (isset($_POST['other_payment-admin-note'])) ? trim($_POST['other_payment-admin-note']) : '';
        // if ($textbox_value === '') {
        //     wc_add_notice(__('Please, complete the payment information.', 'woocommerce-custom-payment-gateway'), 'error');
        //     return false;
        // }
        return true;
    }
    
    public function process_payment($order_id)
    {
        global $woocommerce;
        $order = new WC_Order($order_id);

        // login partner
        $login_resp = $this->login($this->client_id, $this->client_secret);
        if ($login_resp['status_code'] != 200) {
            $this->log("divit login error 1000");
            $this->log($login_resp['status_code']);
            $this->log(json_encode($login_resp['body']));
            wc_add_notice('divit error: 1000', 'error');
            return;
        }
        $token = $login_resp['body']['token'];

        $order_total = $order->get_total() * 100;
        $order_currency = $order->get_currency();
        $merchant_ref = $order->get_order_key();
        $line_items = array();
        foreach ( $order->get_items() as $item_key => $item_values ) {
            $item = $item_values->get_data();
            $line_items[] = array(
                "itemData" => array(
                    "pics" => array(
                        "big" => "",
                        "small" => "",
                    ),
                    "productID" => strval($item['id']),
                    "productTitle" => $item['name'],
                    "qty" => $item['quantity'],
                )
            );
        }

        $create_order = array(
            "totalAmount" => array(
                "amount" => $order_total,
                "currency" => $order_currency,
            ),
            "nonrefundableAmount" => array(
                "amount" => $order_total,
                "currency" => $order_currency,
            ),
            "refundableAmount" => array(
                "amount" => 0,
                "currency" => $order_currency,
            ),
            "merchantRef" => $merchant_ref,
            "orderType" => "retail",
            "orderItems" => $line_items,
            "webhookSuccess" => $this->get_return_url($order),
            "webhookFailure" => $order->get_checkout_payment_url(),
            // testing
            // "webhookEvents" => 'https://devwoo.free.beeceptor.com', // 'https://be20-218-253-146-226.jp.ngrok.io' . '?wc-api=CALLBACK_DIVIT_PAYMENT',
            "webhookEvents" => get_home_url() . '?wc-api=CALLBACK_DIVIT_PAYMENT',
        );

        $create_order_resp = $this->create_order($create_order, $token);
        if ($create_order_resp['status_code'] != 200) {
            $this->log("divit create order error 1001");
            $this->log($create_order_resp['status_code']);
            $this->log(json_encode($create_order_resp['body']));
            wc_add_notice('divit error: 1001', 'error');
            return;
        }
        $create_order_id = $create_order_resp['body']['data']['orderID'];
        $redirect_uri = $create_order_json['body']['data']['redirectURI'];
        $this->log('created order ' . json_encode($create_order) . ', ' . json_encode($create_order_resp['body']));

        // TODO: cancel previous order
        $order->update_meta_data('_divit_previous_order_id', $create_order_id);
        $order->save();
        // $order->get_meta_data('_divit_previous_order_id');

        return array(
            'result' => 'success',
            'redirect' => $this->get_env()['web_url'] . '/paynow/fps/' . $create_order_id, 
        );
    }
    
    public function payment_fields()
    {
?>
       <fieldset>
            <p class="form-row form-row-wide">
                <label for="<?php echo $this->id; ?>-admin-note"><?php echo ($this->description); ?></label>
           </p>
            <div class="clear"></div>
        </fieldset>
<?php
    }

    // logging for "WooCommerce > System Status > Logs" in the WordPress admin
    public function log($message) {
        if (is_array($message)) {
            $message = print_r($message, true);
        } elseif (is_object($message)) {
            $ob_get_length = ob_get_length();
            if (!$ob_get_length) {
                if ($ob_get_length === false) {
                    ob_start();
                }
                var_dump($message);
                $message = ob_get_contents();
                if ($ob_get_length === false) {
                    ob_end_clean();
                } else {
                    ob_clean();
                }
            } else {
                $message = '(' . get_class($message) . ' Object)';
            }
        }
        $this->logger->add( 'divit', $message );
    }

    public function login($client_id, $client_secret) {
        $post_fields =  "client_id=" . $client_id . "&client_secret=" . $client_secret;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->get_env()['api_url'] . '/login');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('content-type: application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 70);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $body = json_decode($result, true);
        curl_close($ch);
        return array(
            "status_code" => $status_code,
            "body" => $body
        );
    }

    public function create_order($create_order, $token) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->get_env()['api_url'] . '/paynow/orders');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('content-type: application/json', 'authorization: Bearer ' . $token));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($create_order));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 70);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $body = json_decode($result, true);
        curl_close($ch);
        return array(
            "status_code" => $status_code,
            "body" => $body
        );
    }

    public function get_order($order_id, $token) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->get_env()['api_url'] . '/paynow/profiles/orders/' . $order_id);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('content-type: application/json', 'authorization: Bearer ' . $token));
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 70);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $body = json_decode($result, true);
        curl_close($ch);
        return array(
            "status_code" => $status_code,
            "body" => $body
        );
    }

    public function log_me($data, $headers) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://devwoo.free.beeceptor.com');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_exec($ch);
        curl_close($ch);
    }

    public function validate_webhook($signature, $secret, $body) {
        $strs = explode(',', $signature);
        $timestamp = preg_replace('/^t=/', '', $strs[0]);
        $hmac = preg_replace('/^s1=/', '', $strs[1]);
        if (!$timestamp || !$hmac) {
            return false;
        }
        $payload = $timestamp . '.' . $body;
        $calc_hmac = base64_encode(hex2bin(hash_hmac('sha256', $payload, $secret, false)));
        return $hmac == $calc_hmac;
    }

    public function callback_handler() {
        global $woocommerce;

        $resp = file_get_contents('php://input');
        $json = json_decode($resp, true);
        $headers = getallheaders();
        
        $message_str = json_encode($json);
        $header_str = json_encode($headers);
        $this->log("callback: received {$message_str} {$header_str}");

        // $this->log_me($json, $headers);

        // validate signature
        $valid = $this->validate_webhook($headers['X-Divit-Signature'], $this->client_secret, $resp);
        if (!$valid) {
            $message = 'callback: signature valid error';
            $this->log($message);
            wp_send_json($message);
            exit;
        }

        // only handle completed order
        if ($json['event']['eventId'] != 2001) {
            $message = 'callback: order is not completed';
            $this->log($message);
            wp_send_json($message);
            exit;
        }

        $merchant_ref = $json['eventData']['partnerRef'];

        // find order in wc
        $order_id = wc_get_order_id_by_order_key($merchant_ref);
        $order = wc_get_order($order_id);

        // cannot find order
        if (!$order) {
            $messgae = 'callback: cannot find order by key ' . $merchant_ref;
            $this->log($message);
            wp_send_json($message);
            exit;
        }

        // update order status to processing
        $order->payment_complete();

        // Reduce stock levels
        wc_reduce_stock_levels($order_id);

        // clear cart
        $woocommerce->cart->empty_cart();

        $message = 'callback: complete order';
        $this->log($message);
        wp_send_json($message);
        exit;
    }
}