<?php

defined( 'ABSPATH' ) || exit;

require_once('utils.php');
require_once('constants.php');

if (!class_exists('WC_Divit_Payment_Gateway')) {
    class WC_Divit_Payment_Gateway extends WC_Payment_Gateway
    {
        public static $log = false;
        protected static $instance = null;

        const ENABLE_MILES = false;

        protected $environments;
        protected $mode = '';
        protected $api_key = '';
        protected $merchant_id = '';

        public function __construct()
        {
            // payment info
            $this->id           = 'divit';
            $this->method_title = 'divit payment gateway';
            $this->method_description = 'secure & fast FPS payments';
            $this->supports = array( 'products', 'refunds' );
            $this->icon         = plugins_url('assets/images/icon.png', __FILE__);

            // env
            $this->environments = $this->get_environments(true);

            // settings
            $this->init_settings();
            $this->init_form_fields();
            $this->set_default_settings();
            $this->enabled = $this->settings['enabled'];
            $this->mode = $this->settings['mode'];
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->api_key = $this->get_api_key();

            // merchant setup
            $this->merchant_id = $this->settings['merchant_id'];

            // update admin options
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'), 10, 0);
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'setup_merchant'), 11, 0);

            // AJAX for frontend use
            add_action("wp_ajax_divit_get_installation_status", array($this, "divit_get_installation_status"));

            // register callback for `webhook_events`
            add_action('woocommerce_api_callback_divit_payment', array($this, 'callback_divit_payment_handler'));

            if (!$this->is_valid_for_use()) {
                $this->enabled = 'no';
            }
        }

        public static function getInstance()
        {
            if (is_null(self::$instance)) {
                self::$instance = new self;
            }
            return self::$instance;
        }

        public function get_env()
        {
            return $this->environments[$this->settings['mode']];
        }

        public function is_debug() {
            return isset($this->settings['debug']) && $this->settings['debug'] === 'yes';
        }

        public function get_webhook_url()
        {
            $testing_url = $this->settings['webhook_url_testing'] ?? '';
            if ($this->is_debug() && !empty($testing_url)) {
                return rtrim($testing_url, '/') . '/?wc-api=CALLBACK_DIVIT_PAYMENT';
            }
            return get_site_url() . '/?wc-api=CALLBACK_DIVIT_PAYMENT';
        }

        public function get_environments($all)
        {
            $environments = include 'environments.php';
            if ($all) {
                return $environments;
            }
            // turns on dev if is debug
            if ($this->is_debug()) {
                return $environments;
            }
            unset($environments['dev']);
            return $environments;
        }

        // redirect url to divit order in order details, use with set_transaction_id
        public function get_transaction_url($order): string
        {
            $transaction_id = $order->get_transaction_id();
            if (str_starts_with($transaction_id, '00000000')) {
                return parent::get_transaction_url($order);
            }

            $locale = get_locale();
            $locale_suff = strtolower(substr($locale, -2, 2));
            $locale_prefix = strtolower(substr($locale, 0, 2)); // zh-hans
            $current_lang = $locale_prefix == "zh" ? (($locale_suff == "cn" || $locale_suff == "ns")  ? "zh-CN" : "zh-HK") : "en";

            $base_url = $this->get_env()['partner_portal_url'];
            $url = $base_url . '/'.$current_lang.'/orders/pay-now/order-information/' . $transaction_id;
            $this->view_transaction_url = $url;
            return parent::get_transaction_url($order);
        }

        public function get_api_key()
        {
            if ($this->settings['mode'] == 'production') {
                return $this->settings['api_key_production'];
            }
            if ($this->settings['mode'] == 'sandbox') {
                return $this->settings['api_key_sandbox'];
            }
            if ($this->settings['mode'] == 'dev') {
                return $this->settings['api_key_dev'];
            }
            return '';
        }

        public function get_signature_key()
        {
            if ($this->settings['mode'] == 'production') {
                return $this->settings['signature_key_production'];
            }
            if ($this->settings['mode'] == 'sandbox') {
                return $this->settings['signature_key_sandbox'];
            }
            if ($this->settings['mode'] == 'dev') {
                return $this->settings['signature_key_dev'];
            }
            return '';
        }

        public function get_currency()
        {
            return get_woocommerce_currency();
        }

        public function is_valid_for_use()
        {
            return in_array($this->get_currency(), array('HKD'), true);
        }

        // override payment gateway function
        public function is_available()
        {
            if (get_woocommerce_currency() !== 'HKD') {
                return false;
            }
            return $this->enabled == 'yes';
        }

        public function getSettings()
        {
            return $this->settings;
        }

        public function set_default_setting($key, $default_value)
        {
            if (!array_key_exists($key, $this->settings)) {
                $this->settings[$key] = $default_value;
            }
        }

        public function set_default_settings()
        {
            $this->set_default_setting('enabled', 'yes');
            $this->set_default_setting('debug', 'no');
            $this->set_default_setting('title', 'FPS by divit');
            $this->set_default_setting('description', 'secure & fast FPS payments');
            $this->set_default_setting('installationStatus', '');

            // account
            $this->set_default_setting('mode', 'sandbox');
            $this->set_default_setting('api_key_dev', '');
            $this->set_default_setting('api_key_sandbox', '');
            $this->set_default_setting('api_key_production', '');
            $this->set_default_setting('signature_key_dev', '');
            $this->set_default_setting('signature_key_sandbox', '');
            $this->set_default_setting('signature_key_production', '');
            $this->set_default_setting('merchant_id', '');
            $this->set_default_setting('branch_id', '');
            $this->set_default_setting('updated_date', DIVIT_UTILS::get_utc());

            // copy api key to signature key by default
            if ($this->settings['signature_key_dev'] == '') {
                $this->settings['signature_key_dev'] = $this->settings['api_key_dev'];
            }
            if ($this->settings['signature_key_sandbox'] == '') {
                $this->settings['signature_key_sandbox'] = $this->settings['api_key_sandbox'];
            }
            if ($this->settings['signature_key_production'] == '') {
                $this->settings['signature_key_production'] = $this->settings['api_key_production'];
            }

            $this->set_default_setting('webhook_url_testing', '');
        }

        // trigger after click save changes in admin page
        public function process_admin_options()
        {

            $old_settings = DIVIT_UTILS::deepclone($this->settings);

            parent::process_admin_options();

            $new_settings = $this->settings;

            if (
                $old_settings['api_key_dev'] != $new_settings['api_key_dev'] || 
                $old_settings['api_key_sandbox'] != $new_settings['api_key_sandbox'] || 
                $old_settings['api_key_production'] != $new_settings['api_key_production'] ||
                $old_settings['signature_key_dev'] != $new_settings['signature_key_dev'] || 
                $old_settings['signature_key_sandbox'] != $new_settings['signature_key_sandbox'] || 
                $old_settings['signature_key_production'] != $new_settings['signature_key_production']
            ) {
                self::log('changed');
                $this->settings['installationStatus'] = '';
            }

            $this->set_default_settings();
            return update_option($this->get_option_key(), $this->settings, 'yes');
        }

        public function reset_settings()
        {
            $this->settings['merchant_id'] = '';
            $this->settings['branch_id'] = '';
            $this->settings['installationStatus'] = '';
            return update_option($this->get_option_key(), $this->settings, 'yes');
        }

        public function setup_merchant()
        {
            $api_key = $this->get_api_key();

            $this->log("start setup merchant");

            if (empty($api_key)) {
                self::log("get merchant fail when setup, missing api key");
                return $this->reset_settings();
            }

            // get merchant id
            $merchant_resp = $this->get_merchant($api_key);

            if ($merchant_resp['status_code'] != 200) {
                self::log("get merchant fail when setup, " . json_encode($merchant_resp));
                return $this->reset_settings();
            }
            if (!isset($merchant_resp['body']['data'])) {
                self::log("get merchant fail when setup, " . json_encode($merchant_resp));
                return $this->reset_settings();
            }
            $merchant = $merchant_resp['body']['data'];
            $merchant_id = $merchant['merchantID'];
            $this->settings['merchant_id'] = $merchant_id;
            $branch_id = $merchant['branchID'];
            $this->settings['branch_id'] = $branch_id;

            $this->get_divit_config_json();

            // when this account is updated, may updated by cron job
            $this->settings['updated_date'] = DIVIT_UTILS::get_utc();

            $this->log("finished setup merchant");

            return update_option($this->get_option_key(), $this->settings, 'yes');
        }

        // UI for admin page content
        public function init_form_fields()
        {
            $form_fields = array(
                'enabled' => array(
                    'title' => __('Enable', 'wc-divit-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable divit payment', 'wc-divit-payment-gateway'),
                    'default' => 'yes',
                ),
                'debug' => array(
                    'title' => __('Debug', 'wc-divit-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable debug mode', 'wc-divit-payment-gateway'),
                    'default' => 'no',
                    'description' => __('Developer option. Enables additional developer settings (e.g., exposing the local Developer environment and its corresponding developer credentials fields).', 'wc-divit-payment-gateway'),
                ),
                'mode' => array(
                    'title' => __('Environment', 'wc-divit-payment-gateway'),
                    'type' => 'select',
                    'options' => wp_list_pluck($this->get_environments(false), 'name'),
                    'default' => 'test',
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => '',
                    'default' => 'FPS by divit'
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'text',
                    'default' => 'secure & fast FPS payments',
                    'description' => '',
                ),
                'api_key_dev' => array(
                    'title' => 'Dev Api key',
                    'type' => 'password',
                    'default' => '',
                    'description' => 'dev api key for divit merchant',
                ),
                'api_key_sandbox' => array(
                    'title' => 'Sandbox Api key',
                    'type' => 'password',
                    'default' => '',
                    'description' => 'sandbox api key for divit merchant',
                ),
                'api_key_production' => array(
                    'title' => 'Production Api key',
                    'type' => 'password',
                    'default' => '',
                    'description' => 'production api key for divit merchant',
                ),
                'signature_key_dev' => array(
                    'title' => 'Dev Signature key',
                    'type' => 'password',
                    'default' => '',
                    'description' => 'dev signature key for validating payments',
                ),
                'signature_key_sandbox' => array(
                    'title' => 'Sandbox Signature key',
                    'type' => 'password',
                    'default' => '',
                    'description' => 'sandbox signature key for validating payments',
                ),
                'signature_key_production' => array(
                    'title' => 'Production Signature key',
                    'type' => 'password',
                    'default' => '',
                    'description' => 'production signature key for validating payments',
                ),
                'webhook_url_testing' => array(
                    'title' => 'Testing Webhook URL',
                    'type' => 'text',
                    'default' => '',
                    'description' => 'Optional webhook URL for testing (e.g., ngrok URL). If filled and in debug mode, it overrides the default site URL for callback webhooks.',
                ),
            );

            $show_debug_checkbox = isset($_GET['debug']) && sanitize_text_field($_GET['debug']) == 'true';
            $debug_saved = $this->is_debug();

            if (!$show_debug_checkbox) {
                unset($form_fields['debug']);
            }
            if (!$debug_saved) {
                unset($form_fields['api_key_dev']);
                unset($form_fields['signature_key_dev']);
                unset($form_fields['webhook_url_testing']);
            }

            $this->form_fields = $form_fields;
        }

        // UI for admin page content
        public function admin_options()
        {
            $is_debug = $this->is_debug();
            $environment = $this->mode;
            $merchant_id = $this->merchant_id;
            $branch_id = $this->settings['branch_id'];
            $updated_date = $this->settings['updated_date'];
            $loggedin = !empty($this->merchant_id);
            $installationStatus = $this->settings['installationStatus'];
        ?>
            <?php if (!$loggedin) : ?>
                <div class="notice notice-error notice-alt"><h4><?php echo esc_html( __('Require setup account', 'wc-divit-payment-gateway') ); ?></h4></div>
            <?php endif; ?>

            <?php if ($loggedin) { ?>
                <h3 class="wc-settings-sub-title">Divit Merchant Account</h3>
                <table class="widefat striped" cellspacing="0">
                    <?php if ($is_debug) { ?>
                    <tr>
                        <th>Debug</th>
                        <td>on</td>
                    </tr>
                    <?php } ?>
                    <tr>
                        <th>Environment</th>
                        <td><?php echo esc_html($environment); ?></td>
                    </tr>
                    <tr>
                        <th>Merchant id</th>
                        <td><?php echo esc_html($merchant_id); ?></td>
                    </tr>
                    <tr>
                        <th>Branch id</th>
                        <td><?php echo esc_html($branch_id); ?></td>
                    </tr>
                    <tr>
                        <th>Installation status</th>
                        <td id="installationStatusField">
                            <span>
                                <?php echo esc_html( $installationStatus == 'success' ? 'success' : 'webhook test not done' ); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Last updated</th>
                        <td><?php echo esc_html($updated_date); ?></td>
                    </tr>
                </table>
            <?php } ?>

            <h3>Configurations</h3>

            <table class="form-table">
                <?php $this->generate_settings_html() ?>
            </table>
        <?php

            $this->setup_merchant();
        }

        public function add_locale_to_url($redirect_uri)
        {
            // append current language in the redirect_uri
            $current_lang = get_locale();
            if ($current_lang != NULL) {
                if (str_contains($redirect_uri, 'lang=')) {
                    $redirect_uri = preg_replace('/lang=[a-zA-Z_\-]*[^&]/', 'lang=' . $current_lang, $redirect_uri);
                } else {
                    if (str_contains($redirect_uri, '?')) {
                        $redirect_uri = str_replace('?', "?lang=$current_lang&", $redirect_uri);
                    } else {
                        $redirect_uri = $redirect_uri . "?lang=$current_lang";
                    }
                }
            }
            return $redirect_uri;
        }

        public function process_payment($order_id)
        {
            $order = new WC_Order($order_id);
            $merchant_ref = $order->get_id();

            $line_items = array();
            foreach ($order->get_items() as $item_key => $item_values) {
                $item = $item_values->get_data();
                $product = wc_get_product($item['product_id']);
                $line_item = array(
                    "itemType" => 'retail',
                    "itemData" => array(
                        "pics" => array(
                            "big" => "",
                            "small" => "",
                        ),
                        "productID" => strval($product->get_id()),
                        "productTitle" => $product->get_name(),
                        "unitPrice" => floatval($product->get_price()),
                        "qty" => $item['quantity'],
                        "subtotal" => floatval($item['subtotal']),
                    )
                );
                $line_items[] = $line_item;
            }

            // Integer cents.
            $order_total = (int) round((float) $order->get_total() * 100);
            $order_currency = $order->get_currency();

            // tel
            $tel = DIVIT_UTILS::to_tel($order->get_billing_phone());

            $create_order_customer_data = array(
                'firstName' => $order->get_billing_first_name(),
                'lastName' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'tel' => $tel['countryCode'] . $tel['tel'],
                'address1' => $order->get_billing_address_1(),
                'address2' => $order->get_billing_address_2(),
                'district' => $order->get_billing_city(),
                'province' => $order->get_billing_state(),
                'postalCode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
                'language' => get_locale() ?? 'en'
            );

            $create_order_data = array(
                "totalAmount" => array(
                    "amount" => $order_total,
                    "currency" => $order_currency,
                ),
                "merchantRef" => $merchant_ref."",
                "merchantUniqueOrderID" => $merchant_ref."",
                "orderType" => "retail",
                "orderItems" => $line_items,
                "webhookSuccess" => $this->get_return_url($order),
                "webhookFailure" => $order->get_checkout_payment_url(),
                "webhookEvents" => $this->get_webhook_url(),
                "source" => "woocommerce"
            );

            $create_order = array(
                "customer" => $create_order_customer_data,
                "order" => $create_order_data,
            );

            $create_order_resp = $this->create_order($create_order, $this->api_key);
            if ($create_order_resp['status_code'] != 200) {
                if ($create_order_resp['status_code'] == 400) {
                    $err = $create_order_resp['body'];
                    if ($err['code'] == 40005) {
                        wc_add_notice('please enter correct billing phone number, and email.', 'error');
                        return;
                    }
                }
                self::log("divit create order error 1001");
                self::log($create_order_resp['status_code']);
                self::log(json_encode($create_order_resp['body']));
                wc_add_notice('payment error (1001), please try again.', 'error');
                return;
            }
            $create_order_resp_data = $create_order_resp['body']['data'];
            $create_order_id = $create_order_resp_data['orderID'];
            $redirect_uri = $create_order_resp_data['redirectURI'];

            // exit if order is null, eg: maintenance mode
            if (str_starts_with($create_order_id, '00000000')) {
                return array(
                    'result' => 'success',
                    'redirect' => $this->add_locale_to_url($redirect_uri),
                );
            }

            $create_order_token = $create_order_resp_data['token'];
            self::log('created order ' . json_encode($create_order) . ', ' . json_encode($create_order_resp['body']));

            // save divit order id for cancel, reference
            $order->update_meta_data('divit_order_id', $create_order_id);
            $order->set_transaction_id($create_order_id);
            $order->save();

            $redirect = $this->add_locale_to_url($redirect_uri) . '&token=' . $create_order_token;

            return array(
                'result' => 'success',
                'redirect' => $redirect,
            );
        }



        public function process_refund( $order_id, $amount = null, $reason = '' ) {
            // mark it as request refund, then approve refund from callback
            $order = wc_get_order($order_id);
            $divit_order_id = $order->get_meta('divit_order_id', true);
            // Use the latest WooCommerce refund id as the refund reference.
            $wc_refunds = $order->get_refunds();
            $refundRef = ! empty($wc_refunds) ? (string) $wc_refunds[0]->get_id() : DIVIT_UTILS::rand_id();
            $refund_order_resp = $this->refund_order($divit_order_id, floatval($amount), $reason, $refundRef, $this->api_key);
            if ($refund_order_resp['status_code'] != 200) {
                self::log('refund requested failed. order id: ' . $divit_order_id . ' ' . json_encode($refund_order_resp['body']));
                $err = $refund_order_resp['body'];
                return new WP_Error( 'error', 'unavailable to refund. please contact divit customer service.' );
            }
            self::log('refund requested success. order id: ' . $divit_order_id . ' amount: ' . $amount);
            $note = sprintf(__('Refund is requested (divit payment): %s%.2f. ref: %s. Please wait for approval.', 'wc-divit-payment-gateway'), get_woocommerce_currency_symbol(), floatval($amount), $refundRef);
            $order->add_order_note($note);
            return true;
        }

        public function get_divit_config_json()
        {
            return (object)array(
                'fpsGuideURL' => 'https://divit.com.hk/customer/fps-guide/',
            );
        }

        // UI in checkout
        // UI in checkout
        public function payment_fields()
        {
            $currency_symbol = get_woocommerce_currency_symbol();
            $total = WC()->cart->total;
            $order = wc_get_order(get_query_var('order-pay'));
            if (!empty($order)) {
                $total = $order->get_total();
            }
            $formatted_order_total = $currency_symbol . number_format((float) $total, wc_get_price_decimals());
            ?>
            <div class="payment_method_<?php echo esc_attr( $this->id ); ?>_desc">
                <div class="divit_checkout_block divit_checkout_paynow checked">
                    <div class="divit_checkout_block_overlay" data-method-id="paynow"></div>
                    <div class="divit_checkout_block_content">
                        <div class="divit_checkout_block_title">
                            <?php echo esc_html(__('pay now', 'wc-divit-payment-gateway')); ?>
                        </div>
                    </div>
                    <div class="divit_checkout_block_right">
                        <div class="divit_checkout_paynow_total">
                            <?php echo esc_html($formatted_order_total); ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }



        public function divit_get_installation_status()
        {
            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(__('Forbidden', 'wc-divit-payment-gateway'), 403);
            }

            check_ajax_referer('divit_admin_nonce', 'nonce');

            $this->webhook_test($this->get_api_key());

            wp_send_json_success(array(
                'installationStatus' => $this->settings['installationStatus'],
            ));

            die();
        }

        // logging for "WooCommerce > System Status > Logs" in the WordPress admin
        // Select / Search for date of log
        public static function log($message)
        {
            if (empty(self::$log)) {
                self::$log = new WC_Logger;
            }
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
            self::$log->add('divit', $message);
        }


        public function get_merchant($api_key)
        {
            $url = $this->get_env()['api_url'] . '/users/v2/profile';
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            return DIVIT_UTILS::http_request($url, 'GET', array(), $header);
        }

        public function webhook_test($api_key)
        {
            $url = $this->get_env()['api_url'] . '/paynow/orders/webhook/test';
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            $body = array(
                'ref' => $this->merchant_id,
                'event' => 'test',
                'webhookEvent' => $this->get_webhook_url(),
            );
            return DIVIT_UTILS::http_request($url, 'POST', $body, $header);
        }

        public function create_order($create_order, $api_key)
        {
            $url = $this->get_env()['api_url'] . '/directpay/o/order';
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            return DIVIT_UTILS::http_request($url, 'POST', $create_order, $header);
        }


        public function get_order($order_id, $api_key)
        {
            $url = $this->get_env()['api_url'] . '/paynow/orders/' . $order_id;
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            return DIVIT_UTILS::http_request($url, 'GET', array(), $header);
        }

        public function refund_order($order_id, $amount, $reason, $refundRef, $api_key)
        {
            $url = $this->get_env()['api_url'] . '/paynow/orders/refund/request';
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            $body = array(
                'orderID' => $order_id, 
                'refundRef' => $refundRef,
                'refundType' => 'partial', 
                'refundAmount' => $amount, 
                'remarks' => $reason,
                'callbackURI' => $this->get_webhook_url(),
            );
            return DIVIT_UTILS::http_request($url, 'POST', $body, $header);
        }



        public function callback_divit_payment_handler()
        {
            $resp = file_get_contents('php://input');
            $json = json_decode($resp, true);
            $headers = getallheaders();

            // get signature key from header
            $signature = '';
            foreach($headers as $key => $value) {
                if (strtoupper('X-Divit-Signature') == strtoupper($key)) {
                    $signature = $value;
                    break;
                }
            }
            $header_str = json_encode($headers);
            if ($signature == '') {
                $message = "callback: no signature from header, got: {$header_str}";
                self::log($message);
                wp_send_json($message, 400);
                exit;
            }

            $message_str = json_encode($json);
            self::log("callback: received {$message_str} {$header_str}");

            $event_data = isset($json['eventData']) ? $json['eventData'] : array();

            // Extract merchant reference with potential case variations
            $merchant_ref = '';
            if (isset($event_data['partnerRef'])) {
                $merchant_ref = $event_data['partnerRef'];
            } elseif (isset($event_data['MerchantRef'])) {
                $merchant_ref = $event_data['MerchantRef'];
            } elseif (isset($event_data['merchantRef'])) {
                $merchant_ref = $event_data['merchantRef'];
            }

            // Extract divit order ID with potential case variations
            $divit_order_id = '';
            if (isset($event_data['OrderID'])) {
                $divit_order_id = $event_data['OrderID'];
            } elseif (isset($event_data['orderID'])) {
                $divit_order_id = $event_data['orderID'];
            }

            // validate signature
            $signature_key = $this->get_signature_key();
            $valid = DIVIT_UTILS::validate_webhook($signature, $signature_key, $resp);
            if (!$valid) {
                $key_length = strlen($signature_key);
                $mode = isset($this->settings['mode']) ? $this->settings['mode'] : 'unknown';
                $message = "callback: signature valid error. Mode: {$mode}, Key length: {$key_length}, Sig: {$signature}, Body: {$resp}";
                self::log($message);
                wp_send_json($message, 400);
                exit;
            }

            // installation status test
            if ($json['event']['eventId'] == 9000) {
                $this->settings['installationStatus'] = 'success';
                update_option($this->get_option_key(), $this->settings, 'yes');
                $message = "callback: installation status success {$merchant_ref}";
                self::log($message);
                wp_send_json($message);
                exit;
            }

            // Attempt to find the WooCommerce order
            $order = null;

            // 1. Search by divit_order_id metadata (highly accurate)
            if (!empty($divit_order_id)) {
                $orders = wc_get_orders(array(
                    'limit' => 1,
                    'meta_key' => 'divit_order_id',
                    'meta_value' => $divit_order_id,
                ));
                if (!empty($orders)) {
                    $order = $orders[0];
                    self::log("callback: found order by divit_order_id meta: " . $order->get_id());
                }
            }

            // 2. Fallback to direct wc_get_order by merchant_ref
            if (!$order && !empty($merchant_ref)) {
                $order = wc_get_order($merchant_ref);
                if ($order) {
                    self::log("callback: found order by direct merchant_ref: " . $order->get_id());
                }
            }

            // If we still can't find a valid WooCommerce order, fail the callback
            if (!$order) {
                $message = "callback: cannot find order by divit_order_id '{$divit_order_id}' or merchant reference '{$merchant_ref}'";
                self::log($message);
                wp_send_json($message, 400);
                exit;
            }

            $order_id = $order->get_id();

            // refund approved 
            if ($json['event']['eventId'] == 2100) {
                $is_same_order = $order->get_meta('divit_order_id', true) == $divit_order_id;
                $is_divit_payment_method = $order->get_payment_method() == $this->id;
                if ($is_same_order && $is_divit_payment_method) {
                    $refundedAmountData = $json['eventData']['refundAmount'];
                    $refundedAmount = $refundedAmountData['amount'] / 100;
                    $refundedAmountCurrency = $refundedAmountData['currency'];
                    $refundRef = $json['eventData']['refundRef'] ?? '';
                    $note = sprintf(__('Refund is approved (divit payment): %s %.2f. ref: %s.', 'wc-divit-payment-gateway'), $refundedAmountCurrency, $refundedAmount, $refundRef);
                    $order->add_order_note($note);
                    $message = "callback: refund is approved, merchant reference {$merchant_ref}";
                    self::log($message);
                    wp_send_json($message);
                    exit;
                }
            }

            // refund cancelled 
            if ($json['event']['eventId'] == 4100) {
                $is_same_order = $order->get_meta('divit_order_id', true) == $divit_order_id;
                $is_divit_payment_method = $order->get_payment_method() == $this->id;
                if ($is_same_order && $is_divit_payment_method) {
                    $refundedAmountData = $json['eventData']['refundAmount'];
                    $refundedAmount = $refundedAmountData['amount'] / 100;
                    $refundedAmountCurrency = $refundedAmountData['currency'];
                    $refundRef = $json['eventData']['refundRef'] ?? '';
                    $note = sprintf(__('Refund is cancelled (divit payment): %s %.2f ref: %s.', 'wc-divit-payment-gateway'), $refundedAmountCurrency, $refundedAmount, $refundRef);
                    $order->add_order_note($note);
                    $message = "callback: refund is cancelled, merchant reference {$merchant_ref}";
                    self::log($message);
                    wp_send_json($message);
                    exit;
                }
            }

            // cancelled order
            if ($json['event']['eventId'] == 4000) {
                $is_same_order = $order->get_meta('divit_order_id', true) == $divit_order_id;
                $is_divit_payment_method = $order->get_payment_method() == $this->id;
                $is_valid_order_status = $order->get_status() == 'pending';
                if ($is_same_order && $is_divit_payment_method && $is_valid_order_status) {
                    $order->add_order_note(__('divit payment is cancelled', 'wc-divit-payment-gateway'));
                    $order->update_status('failed');
                    $message = "callback: order is cancelled, merchant reference {$merchant_ref}";
                    self::log($message);
                    wp_send_json($message);
                    exit;
                }
            }

            // only handle completed order
            if ($json['event']['eventId'] != 2001) {
                $message = "callback: order is not completed, merchant reference {$merchant_ref}";
                self::log($message);
                wp_send_json($message);
                exit;
            }


            // update order status to processing
            $order->payment_complete();

            // Reduce stock levels
            wc_reduce_stock_levels($order_id);

            // we can't clear customer cart here, cuz this method call by server, 
            // cannot get specific user section, see function 'order_received_action'

            $message = "callback: complete order, merchant reference {$merchant_ref}";
            self::log($message);


            wp_send_json($message);
            exit;
        }

        // trigger in woocommerce_thankyou
        public function order_received_action($order_id)
        {
            self::log('order received: ' . $order_id);

            // clear cart
            WC()->cart->empty_cart();
        }

    }
}

