<?php

require_once('utils.php');

if (!class_exists('WC_Divit_Payment_Gateway')) {
    class WC_Divit_Payment_Gateway extends WC_Payment_Gateway
    {
        protected static $instance = null;

        const DIVIT_PROMOTION_CAMPAIGN = 'divit_promotion_campaign';
        
        public function __construct()
        {
            $this->environments = include 'environments.php';
            $this->logger = new WC_Logger;
            
            $this->id           = 'divit';
            $this->method_title = 'Divit payment gateway';
            $this->method_description = 'Pay now, pay later, earn miles';
            $this->title        = 'Divit payment gateway';

            $this->init_form_fields();
            $this->init_settings();

            // setting
            $this->enabled           = $this->settings['enabled'];
            $this->mode              = $this->settings['mode'];
            $this->title             = $this->settings['title'];
            $this->description       = $this->settings['description'];
            $this->client_id = $this->settings['client_id'];
            $this->client_secret = $this->settings['client_secret'];

            // merchant setup
            $this->token = $this->settings['token'];
            $this->merchant_id = $this->settings['merchant_id'];
            $this->campaigns = $this->settings['campaigns'];
            $this->earn_miles_rate = $this->settings['earn_miles_rate'];
            
            // update admin options
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options'), 10, 0);
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'setup_merchant'), 11, 0);

            // AJAX
            add_action("wp_ajax_divit_apply_promotion_code", array($this, "divit_apply_promotion_code") );
            add_action("wp_ajax_nopriv_divit_apply_promotion_code", array($this, "divit_apply_promotion_code") );

            // register callback for `webhook_events`
            add_action( 'woocommerce_api_callback_divit_payment', array($this, 'callback_handler') );
        }

        public static function getInstance()
		{
			if (is_null(self::$instance)) {
				self::$instance = new self;
			}
			return self::$instance;
		}

        public function process_admin_options() {
			parent::process_admin_options();

            // default settings
            if (!array_key_exists('token', $this->settings)) {
                $this->settings['token'] = '';
            }
            if (!array_key_exists('merchant_id', $this->settings)) {
                $this->settings['merchant_id'] = '';
            }
            if (!array_key_exists('earn_miles_rate', $this->settings)) {
                $this->settings['earn_miles_rate'] = 1000;
            }
            if (!array_key_exists('campaigns', $this->settings)) {
                $this->settings['campaigns'] = array();
            }
			return update_option($this->get_option_key(), $this->settings, 'yes');
		}

        public function reset_settings() {
            $this->settings['token'] = '';
            $this->settings['merchant_id'] = '';
            $this->settings['campaigns'] = array();
            $this->settings['earn_miles_rate'] = 0;
            return update_option($this->get_option_key(), $this->settings, 'yes'); 
        }

        public function setup_merchant() {
            // set login profile
            $login_resp = $this->login($this->settings['client_id'], $this->settings['client_secret']);
            if ($login_resp['status_code'] != 200) {
                $this->log("login fail when setup, " . json_encode($this->settings));
                return $this->reset_settings();
            }
            $token = $login_resp['body']['token'];
            $this->settings['token'] = $token;
            $merchant_id = get_jwt_payload($token)->pid;
            $this->settings['merchant_id'] = $merchant_id;

            // set campaigns and earn miles rate
            $campaigns_resp = $this->get_campaigns($merchant_id, $token);
            if ($campaigns_resp['status_code'] != 200) {
                $this->log("get campaigns fail when setup, " . json_encode($campaigns_resp['body']));
                return $this->reset_settings();
            }
            $campaigns = $campaigns_resp['body']['data'];
            $active_campaigns = array_values(array_filter($campaigns, function ($campaign) {
                return $campaign['status'] == 'active' && empty($campaign['promoCode']);
            }));
            $this->settings['campaigns'] = $active_campaigns;
            if (!empty($active_campaigns)) {
                $this->settings['earn_miles_rate'] = intval($active_campaigns[0]['title'][0]);
            } 
            return update_option($this->get_option_key(), $this->settings, 'yes'); 
		}

        public function is_earn_miles_enabled() {
            $rate = $this->earn_miles_rate;
            return is_int($rate) && $rate > 0;
        }

        public function calc_earn_miles($price) {
            $rate = $this->earn_miles_rate;
            if (!empty($rate)) {
                return $price / $rate;
            }
            return null;
        }

        public function shortcode_divit_earn_miles_paragraph() {
            if (!$this->is_earn_miles_enabled()) return;
            $product = get_product_from_the_post();
            if (empty($product)) return;
            $price = wc_get_price_to_display($product);
            $miles = $this->calc_earn_miles($price);
            echo '<div class="divit_message">';
            echo "Pay now and earn {$miles} miles.";
            echo '</div>';
        }

        public function redner_single_product_earn_miles_message() {
            if (!$this->is_earn_miles_enabled()) return;
            $rate = $this->settings['earn_miles_rate'];
            $product = get_product_from_the_post();
            $product = get_product_from_the_post();
            if (empty($product)) return;
            if ($product->is_type('variable')) {
                ?>
                <script>
                    jQuery(function($){
                        $( ".single_variation_wrap" ).on( "show_variation", function ( event, variation ) {
                            $('#divit_message_miles').text(variation.display_price / <?php echo $rate ?>);
                        })
                    })
                </script>
                <?php
            }
            $price = wc_get_price_to_display($product);
            $miles = $this->calc_earn_miles($price);
            echo '<div class="divit_message">';
            echo 'Pay now and earn <span id="divit_message_miles">';
            echo $miles;
            echo "</span> miles.";
            echo '</div>';
        }

        public function render_cart_earn_miles_message() {
            if (!$this->is_earn_miles_enabled()) return;
            $price = WC()->cart->total;
            $miles = $this->calc_earn_miles($price);
            echo '<div class="divit_message">';
            echo "Pay now and earn {$miles} miles.";
            echo '</div>';
        }

        // only can access cart in checkout page
        public function render_checkout_earn_miles_message() {
            if (!$this->is_earn_miles_enabled()) return;
            global $woocommerce;
            $price = WC()->cart->total;
            $miles = $this->calc_earn_miles($price);
            $promo = $woocommerce->session->get(self::DIVIT_PROMOTION_CAMPAIGN, array());
            if (!empty($promo)) {
                $rate = intval($promo['title'][0]);
                $miles = $price / $rate;
            }
            echo '<div class="divit_message">';
            echo "Pay now and earn {$miles} miles.";
            echo '</div>'; 
        }

        public function render_thank_you_earn_miles_message($thank_you_title, $order) {
            if (!$this->is_earn_miles_enabled()) return;
            $price = $order->get_total();
            $miles = $this->calc_earn_miles($price);
            echo $thank_you_title;
            echo '<div class="divit_message">';
            echo "You have earned {$miles} miles.";
            echo '</div>';
        }

        public function render_order_received_email_earn_miles_message($order) {
            if (!$this->is_earn_miles_enabled()) return;
            $price = $order->get_total();
            $miles = $this->calc_earn_miles($price);
            // need inline css for email
            echo '<div style="margin-bottom:25px;font-size:16px;">';
            echo "You have earned {$miles} miles.";
            echo '</div>';
        }

        public function get_env() {
            return $this->environments[$this->mode];
        }
        
        public function init_form_fields()
        {
            $this->form_fields = array(
                'core-configuration-title' => array(
                    'title'				=> __( 'Core Configuration', 'woo_divit' ),
                    'type'				=> 'title'
                ),
                'enabled' => array(
                    'title' => __('Enable', 'woocommerce-other-payment-gateway'),
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
                    'default' => 'Pay now, pay later, earn miles',
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
            $merchant_id = $this->merchant_id;
            $earn_miles_rate = $this->earn_miles_rate;
            $loggedin = !empty($this->merchant_id);
    ?>
            <?php echo $loggedin ? 
                '<div class="notice notice-success notice-alt"><h4>Setup done</h4></div>' : 
                '<div class="notice notice-error notice-alt"><h4>Require setup</h4></div>'
            ?>
            <h3>Divit Payment</h3>
            <?php echo $loggedin ? 
                "
                    <div>Merchant id: {$merchant_id}</div>
                    <div>Earn miles rate: {$earn_miles_rate}:1</div>
                " : '' 
            ?>
            <!-- <p><?php echo json_encode($this->settings) ?></p> -->
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        <table class="form-table">
                            <?php $this->generate_settings_html(); ?>
                        </table>
                    </div>
                </div>
                <div class="clear"></div>
            </div>
    <?php
        }

        public function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);

            // debug
            // $allmeta = $order->get_meta_data();
            // wc_add_notice(json_encode($allmeta), 'error');
            // return;

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
            $merchant_ref = $order->get_id();
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

            $create_order_customer_data = array(
                'firstName' => $order->get_billing_first_name(),
                'lastName' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'tel' => '+852' . $order->get_billing_phone(),
                'floor' => '',
                'building' => $order->get_billing_address_2(),
                'street' => $order->get_billing_address_1(),
                'district' => $order->get_billing_city(),
                'region' => $order->get_billing_state(),
                'language' => 'en-US'
            );

            $create_order_data = array(
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
                // "webhookEvents" => 'http://31ef-218-253-146-226.jp.ngrok.io' . '?wc-api=CALLBACK_DIVIT_PAYMENT',
                "webhookEvents" => get_site_url() . '?wc-api=CALLBACK_DIVIT_PAYMENT',
            );

            $create_order = array(
                "customer" => $create_order_customer_data,
                "order" => $create_order_data,
            );

            $create_order_resp = $this->create_order($create_order, $token);
            if ($create_order_resp['status_code'] != 200) {
                $this->log("divit create order error 1001");
                $this->log($create_order_resp['status_code']);
                $this->log(json_encode($create_order_resp['body']));
                wc_add_notice('divit error: 1001', 'error');
                return;
            }
            $create_order_resp_data = $create_order_resp['body']['data'];
            $create_order_id = $create_order_resp_data['orderID'];
            $redirect_uri = $create_order_resp_data['redirectURI'];
            $create_order_token = $create_order_resp_data['token'];
            $this->log('created order ' . json_encode($create_order) . ', ' . json_encode($create_order_resp['body']));

            // TODO: cancel previous order
            $order->update_meta_data('divit_order_id', $create_order_id);
            $order->save();

            // promotion code for customer add
            $promo = $woocommerce->session->get(self::DIVIT_PROMOTION_CAMPAIGN, array());
            $promotion_code = '';
            if (!empty($promo)) {
                $promotion_code = $promo['promoCode'];
            }

            return array(
                'result' => 'success',
                'redirect' => $redirect_uri . '&token=' . $create_order_token .  '&promoCode=' . $promotion_code,
            );
        }
        
        public function payment_fields()
        {
            if (!$this->is_earn_miles_enabled()) {
                echo $this->description;
                return;
            }
            global $woocommerce;
            $promo = $woocommerce->session->get(self::DIVIT_PROMOTION_CAMPAIGN, array());
            $promotion_code = '';
            if (!empty($promo)) {
                $promotion_code = $promo['promoCode'];
            }
    ?>
            <p class="payment_method_<?php echo $this->id ?>_desc">
                <?php $this->render_checkout_earn_miles_message() ?>
                <?php
                woocommerce_form_field( 'divit_promotion_code', array(
                    'type'          => 'text',
                    'class'         => array('transaction_type form-row-wide'),
                    'label'         => 'Promotion code',
                    'description'   => '',
                ), $promotion_code);
                ?>
                <div id="divit_promotion_code_submit_success"></div>
                <div id="divit_promotion_code_submit_error"></div>
                <div id="divit_promotion_code_submit" class="button" style="margin-top: 10px;">Apply</div>
                <script>
                    jQuery(function($) {
                        var $code = $('[name=divit_promotion_code]')
                        var $success = $('#divit_promotion_code_submit_success')
                        var $error = $('#divit_promotion_code_submit_error')
                        $('#divit_promotion_code_submit').click(function() {
                            var promotion_code = $('[name="divit_promotion_code"]').val();
                            $.ajax({
                                type: 'POST',
                                url: '<?php echo admin_url("admin-ajax.php");?>',
                                data: {
                                    action: 'divit_apply_promotion_code',
                                    promotion_code: promotion_code,
                                },
                                success: function(textStatus){
                                    $(document.body).trigger("update_checkout");
                                    if (promotion_code === '') {
                                        $code.val('');
                                        $success.text(''); 
                                        $error.text('');
                                    } else if (!textStatus.success) {
                                        $code.val('');
                                        $success.text('');
                                        $error.text('invalid promotion code');
                                    } else {
                                        $success.text('applied');
                                        $error.text('');
                                    }
                                },
                                error: function(MLHttpRequest, textStatus, errorThrown){
                                    $(document.body).trigger("update_checkout");
                                    $code.val('');
                                    $success.text('');
                                    $error.text('invalid promotion code');
                                }
                            })
                        })
                    })
                </script>
            </p>
    <?php
        }

        // apply promotion code to session in checkout page
        public function divit_apply_promotion_code() {
            global $woocommerce;
            $promotion_code = $_POST['promotion_code'];

            // reset
            $woocommerce->session->set(self::DIVIT_PROMOTION_CAMPAIGN, array());

            if (empty($promotion_code)) {
                wp_send_json_success('clear promotion code');
                die(); 
            }

            $login_resp = $this->login($this->client_id, $this->client_secret);
            if ($login_resp['status_code'] != 200) {
                wp_send_json_error('merchant error');
                die();
            }
            $token = $login_resp['body']['token'];

            $resp = $this->get_promotion_code($this->merchant_id, $promotion_code, $token);
            if ($resp['status_code'] != 200 || $resp['body']['data']['promoCode'] != $promotion_code) {
                wp_send_json_error('invalid promotion code, '. $resp['body']['data']['promoCode']);
                die();
            }
            $woocommerce->session->set(self::DIVIT_PROMOTION_CAMPAIGN, $resp['body']['data']);
            wp_send_json_success('applied');
            die();
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
            $url = $this->get_env()['api_url'] . '/login';
            $body =  "client_id=" . $client_id . "&client_secret=" . $client_secret;
            $headers = array('content-type: application/x-www-form-urlencoded');
            return http_request($url, 'POST', $body, $headers);
        }

        public function create_order($create_order, $token) {
            $url = $this->get_env()['api_url'] . '/paynow/orders/fast';
            $header = array('content-type: application/json', 'authorization: Bearer ' . $token);
            return http_request($url, 'POST', $create_order, $header);
        }

        public function get_campaigns($merchant_id, $token) {
            $url = $this->get_env()['api_url'] . '/campaign/merchants/' . $merchant_id;
            $header = array('content-type: application/json', 'authorization: Bearer ' . $token);
            return http_request($url, 'GET',  array(), $header);
        }

        public function get_promotion_code($merchant_id, $promotion_code, $token) {
            $url = $this->get_env()['api_url'] . '/campaign/merchants/' . $merchant_id . '/promo/' . $promotion_code;
            $header = array('content-type: application/json', 'authorization: Bearer ' . $token);
            return http_request($url, 'GET',  array(), $header);
        }

        public function callback_handler() {
            global $woocommerce;

            $resp = file_get_contents('php://input');
            $json = json_decode($resp, true);
            $headers = getallheaders();
            
            $message_str = json_encode($json);
            $header_str = json_encode($headers);
            $this->log("callback: received {$message_str} {$header_str}");

            $merchant_ref = $json['eventData']['partnerRef'];

            // validate signature
            $signature = array_key_exists('X-Divit-Signature', $headers) ? $headers['X-Divit-Signature'] : '';
            $signature = array_key_exists('x-divit-signature', $headers) ? $headers['x-divit-signature'] : $signature;
            $valid = validate_webhook($signature, $this->client_secret, $resp);
            if (!$valid) {
                $message = "callback: signature valid error, merchant reference {$merchant_ref}";
                $this->log($message);
                wp_send_json($message);
                exit;
            }

            // only handle completed order
            if ($json['event']['eventId'] != 2001) {
                $message = "callback: order is not completed, merchant reference {$merchant_ref}";
                $this->log($message);
                wp_send_json($message);
                exit;
            }

            // find order in wc by order number
            // BUG: plugin changing order number after process payment
            // $order_id = get_order_by_order_number($merchant_ref);

            $order_id = $merchant_ref;
            $order = wc_get_order($order_id);

            // cannot find order
            if (!$order) {
                $message = "callback: cannot find order by merchant reference {$merchant_ref}";
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

            // clear session
            $woocommerce->session->set(self::DIVIT_PROMOTION_CAMPAIGN, array());

            $message = "callback: complete order, merchant reference {$merchant_ref}";
            $this->log($message);
            wp_send_json($message);
            exit;
        }
    }
}