<?php

require_once('utils.php');

if (!class_exists('WC_Divit_Payment_Gateway')) {
    class WC_Divit_Payment_Gateway extends WC_Payment_Gateway
    {
        public static $log = false;
        protected static $instance = null;

        const DIVIT_PROMOTION_CODE_INPUT = 'divit_promo_code_input';
        const DIVIT_PROMOTION_CAMPAIGN = 'divit_promotion_campaign';
        const DIVIT_VALID_PROMO_CODE = 'divit_valid_promo_code';
        const DIVIT_CHECKOUT_METHOD = 'divit_checkout_method';
        const DIVIT_CHECKOUT_META = 'divit_checkout_meta';

        const CAMPAIGN_MODE_ORDER = 'order';
        const CAMPAIGN_MODE_PRODUCT = 'product';

        const META_PRODUCT_CAMPAIGN = '_product_campaign_id';

        const QUERY_DIVIT_PROMO_CODE_KEY = 'divitpromocode';
        
        public function __construct()
        {
            // payment info
            $this->id           = 'divit';
            $this->method_title = 'divit payment gateway';
            $this->method_description = 'earn miles faster spend miles easier';

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
            $this->campaigns = $this->settings['campaigns'] ?? array();
            $this->selected_campaign = $this->find_campaign($this->settings['selected_campaign_id']);
            $this->campaign_mode = $this->settings['campaign_mode'];
            
            // update admin options
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options'), 10, 0);
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'setup_merchant'), 11, 0);
            // add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( 'Divit_Plugin_Cron', 'fire_jobs'), 12, 0);

            // AJAX for frontend use
            add_action("wp_ajax_divit_apply_promotion_code", array($this, "divit_apply_promotion_code") );
            add_action("wp_ajax_nopriv_divit_apply_promotion_code", array($this, "divit_apply_promotion_code") );
            add_action("wp_ajax_divit_get_checkout_data", array($this, "divit_get_checkout_data") );
            add_action("wp_ajax_nopriv_divit_get_checkout_data", array($this, "divit_get_checkout_data") );
            add_action("wp_ajax_divit_set_checkout_method", array($this, "divit_set_checkout_method") );
            add_action("wp_ajax_nopriv_divit_set_checkout_method", array($this, "divit_set_checkout_method") );
            add_action("wp_ajax_divit_get_user_profile", array($this, "divit_get_user_profile") );
            add_action("wp_ajax_nopriv_divit_get_user_profile", array($this, "divit_get_user_profile") );
            add_action("wp_ajax_divit_express_checkout", array($this, "divit_express_checkout") );
            add_action("wp_ajax_nopriv_divit_express_checkout", array($this, "divit_express_checkout") );

            // register callback for `webhook_events`
            add_action( 'woocommerce_api_callback_divit_payment', array($this, 'callback_divit_payment_handler') );
            add_action( 'woocommerce_api_divit_auth_login', array($this, 'divit_auth_login_handler') );
            add_action( 'woocommerce_api_divit_auth_logout', array($this, 'divit_auth_logout_handler') );

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

        public function get_env() {
            return $this->environments[$this->settings['mode']];
        }


        public function get_environments($all) {
            $environments = include 'environments.php';
            if ($all) {
                return $environments;
            }
            // turns on dev, demo if query string has debug=true
            if (isset($_GET['debug']) && sanitize_text_field($_GET['debug'])== 'true') {
                return $environments;
            }
            unset($environments['dev']);
            unset($environments['demo']);
            return $environments;
        }

        // redirect url to divit order in order details, use with set_transaction_id
        public function get_transaction_url( $order ): string {
            $base_url = $this->get_env()['partner_portal_url'];
            $url = $base_url . '/orders/pay-now/' . $order->get_transaction_id() . '/view';
            $this->view_transaction_url = $url;
            return parent::get_transaction_url( $order );
        }

        public function get_api_key() {
            if ($this->settings['mode'] == 'production') {
                return $this->settings['api_key_production'];
            }
            return $this->settings['api_key_sandbox'];
        }

        public function get_currency() {
            return get_woocommerce_currency();
        }

        public function is_valid_for_use() {
            return in_array($this->get_currency(), array('HKD'), true);
        }

        // override payment gateway function
        public function is_available() {
            return $this->enabled == 'yes';
        }

        public function getSettings() {
			return $this->settings;
		}

        public function set_default_setting($key, $default_value) {
            if (!array_key_exists($key, $this->settings)) {
                $this->settings[$key] = $default_value;
            } 
        }

        public function set_default_settings() {
            // account
            $this->set_default_setting('mode', 'sandbox');
            $this->set_default_setting('api_key_sandbox', '');
            $this->set_default_setting('api_key_production', '');
            $this->set_default_setting('updated_date', DIVIT_UTILS::get_utc());
            $this->set_default_setting('merchant_id', '');
            $this->set_default_setting('campaigns', array());
            $this->set_default_setting('selected_campaign_id', '');
            $this->set_default_setting('campaign_mode', '');
            $this->set_default_setting('updated_date', DIVIT_UTILS::get_utc());

            // osm
            $this->set_default_setting('osm-enabled', 'yes');
            $this->set_default_setting('osm-single-product-page', 'yes');
            $this->set_default_setting('osm-loop-product-page', 'yes');
            $this->set_default_setting('osm-mini-cart-page', 'yes');
            $this->set_default_setting('osm-cart-page', 'yes');
            $this->set_default_setting('osm-checkout-page', 'yes');
            $this->set_default_setting('osm-order-receive-page', 'yes');
            $this->set_default_setting('osm-order-email', 'yes');

            // sso
            $this->set_default_setting('sso-enabled', 'no');
            $this->set_default_setting('sso-login-button-menu', 'none');

            // express checkout
            $this->set_default_setting('express-enabled', 'no');

            // others
            $this->set_default_setting('promotion-code-enabled', 'no');
            $this->set_default_setting('checkout-button-replacement', 'yes');
        }

        // trigger after click save changes in admin page
        public function process_admin_options() {
			parent::process_admin_options();

            $this->set_default_settings();
			return update_option($this->get_option_key(), $this->settings, 'yes');
		}

        public function reset_settings() {
            // $this->settings['api_key_sandbox'] = '';
            // $this->settings['api_key_production'] = '';
            $this->settings['merchant_id'] = '';
            $this->settings['campaigns'] = array();
            $this->settings['selected_campaign_id'] = '';
            $this->settings['campaign_mode'] = '';
            return update_option($this->get_option_key(), $this->settings, 'yes'); 
        }

        public function setup_merchant() {
            $api_key = $this->get_api_key();

            $this->log("start setup merchant");

            // get merchant id
            $merchant_resp = $this->get_merchant($api_key);
            if ($merchant_resp['status_code'] != 200) {
                self::log("get merchant fail when setup, " . json_encode($merchant_resp));
                return $this->reset_settings();
            }
            $merchant_id = $merchant_resp['body']['data'][0]['merchantID'];
            $this->settings['merchant_id'] = $merchant_id;

            // set campaigns and earn miles rate
            $campaigns_resp = $this->get_campaigns($merchant_id, $api_key);
            if ($campaigns_resp['status_code'] != 200) {
                self::log("get campaigns fail when setup, " . json_encode($campaigns_resp));
                return $this->reset_settings();
            }
            $campaigns = $campaigns_resp['body']['data'];
            $active_campaigns = array_values(array_filter($campaigns, function ($campaign) {
                return $campaign['status'] == 'active';
            }));
            $this->settings['campaigns'] = $active_campaigns;

            // set selected campaign, find in campaign list
            $campaign = $this->find_campaign($this->settings['selected_campaign_id']);
            if (empty($campaign)) {
                $this->settings['selected_campaign_id'] = '';
            }

            // when this account is updated, may updated by cron job
            $this->settings['updated_date'] = DIVIT_UTILS::get_utc();

            $this->log("finished setup merchant");

            return update_option($this->get_option_key(), $this->settings, 'yes'); 
		}


        public function is_earn_miles_enabled() {
            if ($this->enabled == 'no') {
                return false;
            }
            if ($this->settings['osm-enabled'] == 'no') {
                return false;
            }
            if (empty($this->selected_campaign)) {
                return false;
            }
            return true;
        }

        public function is_reward_miles_enabled() {
            if ($this->enabled == 'no') {
                return false;
            }
            if (empty($this->selected_campaign)) {
                return false;
            }
            return true;
        }

        public function is_promotion_code_enabled() {
            if (!$this->is_earn_miles_enabled()) return false;
            if ($this->settings['promotion-code-enabled'] == 'no') {
                return false;
            }
            return true;
        }

        public function find_campaign($campaign_id) {
            $campaign = null;
            foreach ( $this->settings['campaigns'] as $c ) {
                if ($c['campaignID'] == $campaign_id) {
                    $campaign = $c;
                }
            }
            return $campaign;
        }

        public function get_product_campaign_by_id($product_id) {
            if ($this->campaign_mode == self::CAMPAIGN_MODE_PRODUCT) {
                $campaign_id = get_post_meta($product_id, self::META_PRODUCT_CAMPAIGN, true);
                return $this->find_campaign($campaign_id);
            } else {
                return $this->selected_campaign;
            }
        }

        public function calc_earn_miles($price, $campaign = null) {
            if (empty($campaign)) $campaign = $this->selected_campaign;
            if (empty($campaign)) return 0;
            $reward_statement = $campaign['rewardStatement'];
            return intval(DIVIT_UTILS::get_miles_by_reward_statement($reward_statement, $price));
        }

        public function get_miles_rate($is_calc_promotion_code = false) {
            if ($is_calc_promotion_code) {
                if ($this->campaign_mode == self::CAMPAIGN_MODE_ORDER) {
                    $promo_campaign = WC()->session->get(self::DIVIT_PROMOTION_CAMPAIGN, array());
                    if (!empty($promo_campaign)) {
                        return 100 / $this->calc_earn_miles(100, $promo_campaign);
                    }
                }
            }
            return 100 / $this->calc_earn_miles(100);
        }

        public function get_product_miles($product) {
            if (empty($product)) return 0;
            $campaign = $this->get_product_campaign_by_id($product->get_id());
            if (empty($campaign)) return 0;
            $price = wc_get_price_to_display($product);
            $miles = $this->calc_earn_miles($price, $campaign);
            return $miles;
        }

        // calculate subtotal only, exclude shipping, discount, tax
        public function get_cart_total($cart) {
            $total = 0;
            $qty = 0;
            foreach ($cart->get_cart() as $cart_item ) {
                $total += $cart_item['line_subtotal'];
                $qty += $cart_item['quantity'];
            }
            $original_total = $total;
            foreach ($cart->applied_coupons as $coupon) {
                $c = new WC_Coupon($coupon);
                switch ($c->get_discount_type()) {
                    case 'percent':
                        $total -= $original_total * ($c->get_amount()/100);
                        break;
                    case 'fixed_cart':
                        $total -= $c->get_amount();
                        break;
                    case 'fixed_product':
                        $total -= $qty * $c->get_amount();
                        break;
                }
            }
            return max($total, 0);
        }

        public function get_cart_miles_by_product($cart) {
            $total = 0;
            foreach ( $cart->get_cart() as $cart_item ) {
                $subtotal = $cart_item['line_subtotal'];
                $product = wc_get_product($cart_item['product_id']);
                $campaign = $this->get_product_campaign_by_id($product->get_id());
                if (!empty($campaign)) {
                    $total += $this->calc_earn_miles($subtotal, $campaign);
                }
            }
            return $total;
        }

        public function get_cart_miles($cart) {
            if ($this->campaign_mode == self::CAMPAIGN_MODE_ORDER) {
                $total = $this->get_cart_total($cart);
                return $this->calc_earn_miles($total);
            } else if ($this->campaign_mode == self::CAMPAIGN_MODE_PRODUCT) {
                // ignore discount, only calculate total miles,
                // calculate the subtotal for each type of product
                return $this->get_cart_miles_by_product($cart);
            }
            return 0;
        }

        public function get_order_discount($order) {
            if (method_exists($order, 'get_coupons')) {
                $coupons = $order->get_coupons();
            } else {
                $coupons = $order->get_items('coupon'); // fallback for pre WC 3.7
            }
            $order_discount = 0;
            if ($coupons) {
                foreach ($coupons as $coupon) {
                    $order_discount += (float) $coupon->get_discount();
                }
            } 
            return $order_discount;
        }

        public function get_order_miles($order) {
            if (!$this->is_reward_miles_enabled()) {
                return 0;
            }
            if ($this->campaign_mode == self::CAMPAIGN_MODE_ORDER) {
                $order_subtotal = $order->get_subtotal();
                $order_discount = $this->get_order_discount($order);
                $total = $order_subtotal - $order_discount;
                // apply promo code
                $promo = WC()->session->get(self::DIVIT_PROMOTION_CAMPAIGN, array());
                if (!empty($promo)) {
                    return intval(DIVIT_UTILS::get_miles_by_reward_statement($promo['rewardStatement'], $total));
                } else {
                    return $this->calc_earn_miles($total);
                }
            } else if ($this->campaign_mode == self::CAMPAIGN_MODE_PRODUCT) {
                // ignore discount, only calculate total miles,
                // calculate the subtotal for each type of product
                $total = 0;
                foreach ( $order->get_items() as $item_key => $item_values ) {
                    $subtotal = $item_values['line_subtotal'];
                    $item = $item_values->get_data();
                    $product = wc_get_product($item['product_id']);
                    $campaign = $this->get_product_campaign_by_id($product->get_id());
                    if (!empty($campaign)) {
                        $total += $this->calc_earn_miles($subtotal, $campaign);
                    }
                }
                return $total;
            }
            return 0;
        }

        public function get_miles_in_divit_order($divit_order) {
            $txns = $divit_order['milesTransactions'] ?? [];
            if (empty($txns)) return 0;
            $miles = 0;
            foreach($txns as $txn) {
                $miles_earned = $txn['targetAmount']['amount'];
                $miles += intval($miles_earned / 100);
            }
            return $miles;
        }

        public function render_logo() {
            $static_url = $this->get_env()['static_url']; 
        ?>
            <div>
                <img src="<?php echo esc_html($static_url);?>/brand/divit-logo-white.png" width="108.5px" height="35px" ?>
            </div>
        <?php
        }

        public function render_miles_flag($miles) {
            if (empty($miles) || $miles <= 0) return;
            $static_url = $this->get_env()['static_url'];
        ?>
            <div class="divit_flag" style="display:inline-block;font-size:18px;line-height:28px;font-weight:bold;color: #1c1c1c;">
                <div class="divit_flag-inner" style="display:flex;flex-wrap:wrap;align-items:center;">
                <img style="display:inline-block;vertical-align:middle;margin:2px 4px;width:26.35px;height:26.35px;max-height:26.35px;" src="<?php echo esc_attr($static_url); ?>/miles/miles-icon.png" />
                <b> <span class="divit_miles" style="margin:0 5px;"><?php echo esc_html($miles);?></span> <span style="margin-left:0;margin-right:6px;">=</span></b>
                <img style="display:inline-block;vertical-align:middle;margin:2px 4px;width:17.67px;height:21.52px;max-height:21.52px;" src="<?php echo esc_attr($static_url); ?>/miles/miles-flag-am-bw-icon.png" />
                <b> <span class="divit_miles" style="margin:0 5px;"><?php echo esc_html($miles);?></span></b>
                </div>
            </div>
        <?php
        }

        public function render_miles_message($miles) {
            if (empty($miles) || $miles <= 0) return;
        ?>
            <div class="divit_message" style="display:flex;align-items:center;flex-wrap:wrap;margin:18px 0;font-size:18px;color:#1c1c1c;">
            <span><?php _e('earn miles', 'wc-divit-payment-gateway'); ?></span>
            <span style="width:10px;"></span>
                <?php $this->render_miles_flag($miles); ?>
            </div>
        <?php
        }

        public function render_earned_miles_message($miles) {
            if (empty($miles) || $miles <= 0) return;
        ?>
            <div class="divit_message" style="margin:18px 0;font-size:16px;font-weight:bold;color:#1c1c1c;">
                <div><?php _e('you have just earned', 'wc-divit-payment-gateway'); ?></div>
                <div style="margin-top:8px;"></div>
                <?php $this->render_miles_flag($miles); ?>
            </div>
        <?php
        }

        public function render_claim_miles_button() {
            $is_logged_in = !empty($this->get_divit_user());
            $web_url = $this->get_env()['web_url'];
        ?>
            <div class="divit_signup_btn_container">
                <a class="button" target="_blank" href="<?php echo esc_attr($web_url); ?>/profile/miles">
                    <?php echo esc_html($is_logged_in ? __('sign up to claim miles', 'wc-divit-payment-gateway') : __('view your miles', 'wc-divit-payment-gateway')); ?>
                </a>
            </div>
        <?php
        }

        public function shortcode_divit_earn_miles_tag() {
            if (!$this->is_earn_miles_enabled()) return;
            $product = DIVIT_UTILS::get_product_from_the_post();
            if (empty($product)) return;
            $miles = $this->get_product_miles($product);
            $this->render_miles_message($miles);
        }

        public function shortcode_divit_earn_miles_tag_product_in_cart($attrs) {
            if (!$this->is_earn_miles_enabled()) return;
            if ($this->settings['osm-mini-cart-page'] != 'yes') return;
            $product_id = $attrs['product_id'];
            if (empty($product_id)) return;
            $subtotal = 0;
            $cart = WC()->cart;
            foreach ( $cart->get_cart() as $cart_item ) {
                if ($product_id == $cart_item['product_id']) {
                    $subtotal = $cart_item['line_subtotal'];
                    break;
                }
            }
            $miles = $this->calc_earn_miles($subtotal);
            $this->render_miles_message($miles);
        }

        public function shortcode_divit_earn_miles_tag_in_cart() {
            if (!$this->is_earn_miles_enabled()) return;
            if ($this->settings['osm-mini-cart-page'] != 'yes') return;
            $cart = WC()->cart;
            $miles = $this->get_cart_miles($cart);
            $this->render_miles_message($miles);
        }

        public function render_shop_loop_product_earn_miles_message() {
            if (!$this->is_earn_miles_enabled()) return;
            if ($this->settings['osm-loop-product-page'] != 'yes') return;
            $product = DIVIT_UTILS::get_product_from_the_post();
            $miles = $this->get_product_miles($product);
        ?>
            <div style="margin-top:5px;"></div>
        <?php
            $this->render_miles_flag($miles);
        }

        public function render_single_product_earn_miles_message() {
            if (!$this->is_earn_miles_enabled()) return;
            if ($this->settings['osm-single-product-page'] != 'yes') return;
            $product = DIVIT_UTILS::get_product_from_the_post();
            if (empty($product)) return;
            $miles = $this->get_product_miles($product);
            // product variants
            if ($product->is_type('variable')) {
                $rate = $this->get_miles_rate();
                $min_price = $product->get_variation_price('min', true);
                $max_price = $product->get_variation_price('max', true);
        ?>
                <script>
                    jQuery(function($){
                        var minMiles = '<?php echo esc_js($min_price / $rate); ?>';
                        var maxMiles = '<?php echo esc_js($max_price / $rate); ?>';
                        function setMiles(miles) { 
                            $('.single_variation_wrap .divit_miles').text(parseInt(miles, 10)); 
                        }
                        function resetMiles() { 
                            setMiles(minMiles); 
                        }
                        $(document).on('found_variation', function(event, variation) {
                            setMiles(variation.display_price / <?php echo esc_js($rate); ?>);
                        })
                        $('.variations select').change(function() {
                            $(this).val() === '' && resetMiles(); 
                        })
                        $( '.reset_variations' ).on('click', function() {
                            resetMiles();
                        })
                    })
                </script>
            <?php
            }
        ?>
            <div class="divit_earn_miles_message">
                <?php _e('purchase this product to earn ', 'wc-divit-payment-gateway'); ?>
                <?php $this->render_miles_flag($miles); ?>
            </div>
        <?php
        }

        public function render_single_product_express_checkout_form() {
            if ($this->settings['express-enabled'] !== 'yes') return ''; 
            $product = DIVIT_UTILS::get_product_from_the_post();
            if (empty($product)) return;

        ?>

            <form id="divit_express_checkout_form">
                <?php do_action('divit_add_before_express_checkout_button'); ?>
                <button type="submit" class="button alt divit_place_express_order_button" id="place_express_order">
                    <span style="color:#1c1c1c;display:flex;justify-content:center;align-items:center;">
                        <img style="width:80px;height:28.53px;margin-bottom:2px;" src="<?php echo esc_attr($this->get_env()['static_url']); ?>/brand/divit-logo-colored.png" />
                        <span style="font-size:16px;margin-top:8px;margin-left:8px;">
                            <?php _e('express checkout', 'wc-divit-payment-gateway'); ?>
                        </span>
                    </span>
                </button>
                <div class="divit_express_checkout_tips"><?php do_action('divit_express_checkout_tips'); ?></div>
            </form>

        <?php

            // production variants
            if ($product->is_type('variable')) {
            ?>
                <script>
                    jQuery(function($){
                        function show_express_checkout() { 
                            $('#divit_express_checkout_form').show();
                        }
                        function hide_express_checkout() { 
                            $('#divit_express_checkout_form').hide();
                        }
                        $(document).on('found_variation', function(event, variation) {
                            show_express_checkout();
                        })
                        $('.variations select').change(function() {
                            $(this).val() === '' && hide_express_checkout();
                        })
                        $( '.reset_variations' ).on('click', function() {
                            hide_express_checkout();
                        })
                        // default hide until it selected variation
                        hide_express_checkout();
                    })
                </script>
            <?php
            }
        }

        public function render_mini_cart_earn_miles_message() {
            if (!$this->is_earn_miles_enabled()) return;
            if ($this->settings['osm-mini-cart-page'] != 'yes') return;
            $cart = WC()->cart;
            $miles = $this->get_cart_miles($cart);
            $this->render_miles_message($miles);
        }

        public function render_cart_earn_miles_message() {
            if (!$this->is_earn_miles_enabled()) return;
            if ($this->settings['osm-cart-page'] != 'yes') return;
            $cart = WC()->cart;
            $miles = $this->get_cart_miles($cart);
            $this->render_miles_message($miles);
        }

        // only can access cart in checkout page
        public function render_checkout_earn_miles() {
            if (!$this->is_earn_miles_enabled()) return;
            $cart = WC()->cart;
            $miles = $this->get_cart_miles($cart);
            // apply promo code only in order campaign mode
            if ($this->campaign_mode == self::CAMPAIGN_MODE_ORDER) {
                $promo = WC()->session->get(self::DIVIT_PROMOTION_CAMPAIGN, array());
                if (!empty($promo)) {
                    $price = $this->get_cart_total($cart);
                    $miles = intval(DIVIT_UTILS::get_miles_by_reward_statement($promo['rewardStatement'], $price));
                }
            }
            $this->render_miles_flag($miles);
        }

        // replace "place order" button when divit payment is checked
        public function render_place_order_button_text($button_html) {
            if ($this->settings['checkout-button-replacement'] != 'yes') return $button_html; 

            $divit_logo_img = '<img class="divit_place_order_button_image" src="' . $this->get_env()['static_url'] . '/brand/divit-logo-colored.png" />';
            $button = '';
            $button .= '<button type="submit" class="button alt divit_place_order_button" name="woocommerce_checkout_place_order" id="place_order">';
            $button .= '<span class="divit_place_order_button_container">';
            $button .= sprintf(__(' %3$s %1$scheckout%2$s', 'wc-divit-payment-gateway'), '<span class="divit_place_order_button_spacing">', '</span>', $divit_logo_img);
            $button .= '</button>';
        ?>
            <script>
                jQuery(function ($) {
                    function checkPaymentMethod() {
                        $('.place-order button[type="submit"]').remove();
                        var paymentMethod = $('form[name="checkout"] input[name="payment_method"]:checked');
                        if(paymentMethod.val() == "<?php echo esc_js($this->id); ?>") {
                            $('.place-order').append('<?php echo wp_kses_post($button); ?>');
                        } else{
                            $('.place-order').append('<?php echo wp_kses_post($button_html); ?>');
                        }
                    } 
                    $('input[name="payment_method"]').change(function(){
                        checkPaymentMethod();
                    });
                    $('body').on('updated_checkout', function() {
                        checkPaymentMethod();
                    })
                    checkPaymentMethod();
                })
            </script>
        <?php

            // $order_button_text = apply_filters( 'woocommerce_order_button_text', __( "Place order", "woocommerce" ) );
            if (WC()->session->get( 'chosen_payment_method' ) == $this->id) {
                return $button;
            } else {
                return $button_html;
            }
        }

        // after payment success, display miles earned in thank you page
        public function render_thank_you_earn_miles_message($thank_you_title, $order) {
            if (!$this->is_earn_miles_enabled()) return;
            if ($this->settings['osm-order-receive-page'] != 'yes') return;

            // get miles from server
            $divit_order_id = get_post_meta($order->get_id(), 'divit_order_id', true);
            $divit_order_resp = $this->get_order($divit_order_id, $this->api_key);
            if ($divit_order_resp['status_code'] != 200) return;

            $divit_order = $divit_order_resp['body']['data'];
            $miles = $this->get_miles_in_divit_order($divit_order);
            if ($miles > 0) {
                echo wp_kses_post($thank_you_title);
                $this->render_earned_miles_message($miles);
                $this->render_claim_miles_button();
            } else {
                echo wp_kses_post($thank_you_title);
            }
        }

        public function render_order_received_email_earn_miles_message($order) {
            if (!$this->is_earn_miles_enabled()) return;
            if ($this->settings['osm-order-email'] != 'yes') return;

            // get miles from server
            $divit_order_id = get_post_meta($order->get_id(), 'divit_order_id', true);
            $divit_order_resp = $this->get_order($divit_order_id, $this->api_key);
            if ($divit_order_resp['status_code'] != 200) return;

            $divit_order = $divit_order_resp['body']['data'];
            $miles = $this->get_miles_in_divit_order($divit_order);
            if ($miles <= 0) return;

            // need inline css for email
        ?>
            <div style="margin-bottom:45px;text-align:center;">
                <?php $this->render_earned_miles_message($miles); ?>
                <div style="text-align:center;">
                    <a style="font-size:16px;font-weight:bold;text-decoration:none;padding:10px 20px;background-color:black;color:white;" target="_blank" href="<?php echo esc_attr($this->get_env()['web_url']); ?>/profile/miles">
                        <?php _e('sign up to claim miles', 'wc-divit-payment-gateway'); ?>
                    </a>
                </div>
            </div>
        <?php
        }

        /**
         * Product campaign
         */

        public function campaigns_to_options($campaigns) {
            $options = array_reduce(
                $campaigns ?? array(),
                function ($arr, $v) { 
                    $arr[$v['campaignID']] = $v['title'];
                    return $arr; 
                },
                array()
            );
            return array_merge(array('' => 'none'), $options);
        }

        public function divit_product_data_tabs( $tabs ) {
            $tabs['divit'] = array(
                'label'		=> __( 'Divit', 'wc-divit-payment-gateway' ),
                'target'	=> 'divit_options',
                'class'		=> array( 'show_if_simple', 'show_if_variable'  ),
            );
            return $tabs;
        }

        public function divit_product_data_tabs_content() {
            $options = array_merge(array('' => 'none'), $this->campaigns_to_options($this->campaigns));
        ?>
            <div id="divit_options" class="panel woocommerce_options_panel hidden">
                <p>if you want to specify reward for each product, please switch campaign mode to "product mode" in plugin settings.</p>
                <?php woocommerce_wp_select( array(
                    'id'          => self::META_PRODUCT_CAMPAIGN,
                    'value'       => get_post_meta( get_the_ID(), self::META_PRODUCT_CAMPAIGN, true ),
                    'wrapper_class' => '',
                    'label'       => 'Campaign',
                    'options'     => $options,
                ) ); ?>
            </div>
        <?php
        }

        public function divit_product_data_option_fields( $post_id ) {
            if ( isset( $_POST[self::META_PRODUCT_CAMPAIGN] ) ) {
                $campaign = sanitize_text_field($_POST[self::META_PRODUCT_CAMPAIGN]);
                update_post_meta( $post_id, self::META_PRODUCT_CAMPAIGN, $campaign );
            }
        }
        
        // UI for admin page content
        public function init_form_fields()
        {
            $form_fields = array();

            $has_panel = !isset($_GET['panel']);
            $panel = isset($_GET['panel']) ? sanitize_text_field($_GET['panel']) : '';

            if ($has_panel || $panel == 'payment') {
                $form_fields = array_merge($form_fields, array(
                    'enabled' => array(
                        'title' => __('Enable', 'wc-divit-payment-gateway'),
                        'type' => 'checkbox',
                        'label' => __('Enable divit payment', 'wc-divit-payment-gateway'),
                        'default' => 'yes',
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
                        'default' => 'divit'
                    ),
                    'description' => array(
                        'title' => 'Description',
                        'type' => 'text',
                        'default' => 'pay now, pay later, earn miles',
                        'description' => '',
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
                    'campaign_mode' => array(
                        'title' => 'Campaign mode',
                        'type' => 'select',
                        'options' => array(
                            self::CAMPAIGN_MODE_ORDER => 'order (base on order total)',
                            // self::CAMPAIGN_MODE_PRODUCT => 'product (base on product campaign)',
                        ),
                        'default' => self::CAMPAIGN_MODE_ORDER,
                    ),
                    'selected_campaign_id' => array(
                        'title' => 'Order campaign',
                        'type' => 'select',
                        'options' => $this->campaigns_to_options($this->settings['campaigns']),
                        'default' => '',
                        // 'custom_attributes'	=> $this->settings['campaign_mode'] == self::CAMPAIGN_MODE_ORDER ? [] : array('disabled' => 'false'),
                        // 'description' => 'only available in order campaign mode',
                    ),
                ));
            }

            if ($panel == 'on-site-messaging') {
                $form_fields = array_merge($form_fields, array(
                    'osm-enabled' => array(
                        'title' => __('Enable on-site messging', 'wc-divit-payment-gateway'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                    'osm-single-product-page' => array(
                        'title' => __( 'Display miles tag in pages', 'wc-divit-payment-gateway'),
                        'label' => __('Display miles in single product page', 'wc-divit-payment-gateway'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                    'osm-loop-product-page' => array(
                        'type' => 'checkbox',
                        'label' => __('Display miles in loop product page', 'wc-divit-payment-gateway'),
                        'default' => 'yes',
                    ),
                    'osm-mini-cart-page' => array(
                        'label' => __('Display miles in mini cart page', 'wc-divit-payment-gateway'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                    'osm-cart-page' => array(
                        'label' => __('Display miles in cart page', 'wc-divit-payment-gateway'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                    'osm-checkout-page' => array(
                        'label' => __('Display miles in checkout page', 'wc-divit-payment-gateway'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                    'osm-order-receive-page' => array(
                        'label' => __('Display miles in order receive page', 'wc-divit-payment-gateway'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                    'osm-order-email' => array(
                        'label' => __('Display miles in order email', 'wc-divit-payment-gateway'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                ));
            }

            if ($panel == 'single-sign-on') {
                $menu_location_options = array_reduce(array_keys(get_nav_menu_locations()), function ($arr, $v) { 
                        $arr[$v] = $v;
                        return $arr; 
                    },
                    array('' => 'none'),
                );

                $form_fields = array_merge($form_fields, array(
                    'sso-enabled' => array(
                        'title' => __('Enable', 'wc-divit-payment-gateway'),
                        'type' => 'checkbox',
                        'label' => __('Enable divit single sign on', 'wc-divit-payment-gateway'),
                        'default' => 'no',
                    ),
                    'sso-login-button-menu' => array(
                        'title' => __('Login Button in menu location', 'wc-divit-payment-gateway'),
                        'type' => 'select',
                        'options' => $menu_location_options,
                        'default' => 'none',
                    ),
                ));
            }

            if ($panel == 'express-checkout') {
                $menu_location_options = array_reduce(array_keys(get_nav_menu_locations()), function ($arr, $v) { 
                        $arr[$v] = $v;
                        return $arr; 
                    },
                    array('' => 'none'),
                );

                $form_fields = array_merge($form_fields, array(
                    'express-enabled' => array(
                        'title' => __('Enable', 'wc-divit-payment-gateway'),
                        'type' => 'checkbox',
                        'label' => __('Enable divit express checkout', 'wc-divit-payment-gateway'),
                        'default' => 'no',
                    ),
                ));
            }

            if ($panel == 'others') {
                $form_fields = array_merge($form_fields, array(
                    'promotion-code-enabled' => array(
                        'title' => __('Promotion code', 'wc-divit-payment-gateway'),
                        'type' => 'checkbox',
                        'label' => __('Enable promotion code', 'wc-divit-payment-gateway'),
                        'default' => 'no',
                        'description' => __('controls divit promotion code input in checkout', 'wc-divit-payment-gateway'),
                    ),
                    'checkout-button-replacement' => array(
                        'title' => __('Divit checkout button', 'wc-divit-payment-gateway'),
                        'type' => 'checkbox',
                        'label' => __('Replace divit checkout button', 'wc-divit-payment-gateway'),
                        'default' => 'yes',
                        'description' => __('replace when divit payment is checked', 'wc-divit-payment-gateway'),
                    ),
                ));
            }

            $this->form_fields = $form_fields;
        }

        // UI for admin page tab
        public function admin_options()
        {
            $panel = isset($_GET['panel']) ? sanitize_text_field($_GET['panel']) : 'payment';
            $environment = $this->mode;
            $merchant_id = $this->merchant_id;
            $campaign_mode = $this->campaign_mode;
            $selected_campaign = $this->selected_campaign;
            $updated_date = $this->settings['updated_date'];
            $loggedin = !empty($this->merchant_id);
    ?>
            <?php if (!$loggedin) echo '<div class="notice notice-error notice-alt"><h4>Require setup account</h4></div>' ?>

            <?php if ($loggedin) { ?>
                <h3 class="wc-settings-sub-title">Divit Merchant Account</h3>
                <table class="widefat striped" cellspacing="0">
                    <tr>
                        <th>Enviroment</th>
                        <td><?php echo esc_html($environment); ?></td>
                    </tr>
                    <tr>
                        <th>Merchant id</th>
                        <td><?php echo esc_html($merchant_id); ?></td>
                    </tr>
                    <tr>
                        <th>Campaign mode</th>
                        <td><?php echo esc_html($campaign_mode); ?></td>
                    </tr>
                <?php if ($campaign_mode == self::CAMPAIGN_MODE_ORDER) { ?>
                    <tr>
                        <th>Order campaign</th>
                        <td><?php echo esc_html(!empty($selected_campaign) ? $selected_campaign['title'] : 'none'); ?></td>
                    </tr>
                <?php } ?>
                    <tr>
                        <th>Last updated</th>
                        <td><?php echo esc_html($updated_date); ?></td>
                    </tr>
                </table>
            <?php } ?>

            <h3></h3>

            <h2 class="nav-tab-wrapper">
                <a 
                    href="?page=wc-settings&tab=checkout&section=divit&panel=payment" 
                    class="nav-tab <?php echo esc_html($panel == 'payment' ? 'nav-tab-active' : ''); ?>"
                >
                    Payment
                </a>
                <a 
                    href="?page=wc-settings&tab=checkout&section=divit&panel=on-site-messaging" 
                    class="nav-tab <?php echo esc_html($panel == 'on-site-messaging' ? 'nav-tab-active' : ''); ?>"
                >
                    On-site messaging
                </a>
                <a 
                    href="?page=wc-settings&tab=checkout&section=divit&panel=single-sign-on" 
                    class="nav-tab <?php echo esc_html($panel == 'single-sign-on' ? 'nav-tab-active' : ''); ?>"
                >
                    Single sign on
                </a>
                <a 
                    href="?page=wc-settings&tab=checkout&section=divit&panel=express-checkout" 
                    class="nav-tab <?php echo esc_html($panel == 'express-checkout' ? 'nav-tab-active' : ''); ?>"
                >
                    Express checkout
                </a>
                <a 
                    href="?page=wc-settings&tab=checkout&section=divit&panel=others" 
                    class="nav-tab <?php echo esc_html($panel == 'others' ? 'nav-tab-active' : ''); ?>"
                >
                    <?php echo __('Others', 'wc-divit-payment-gateway')?>
                </a>
            </h2>

            <table class="form-table">
                <input hidden name="panel" value="<?php echo esc_attr($panel); ?>" />
                <?php $this->generate_settings_html() ?>
            </table>
    <?php

            $this->setup_merchant();

        }

        public function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            // debug
            // $allmeta = get_option('woocommerce_currency');
            // $allmeta = get_woocommerce_currency();
            // wc_add_notice(json_encode($allmeta), 'error');
            // return;

            $merchant_ref = $order->get_id();

            $line_items = array();
            foreach ( $order->get_items() as $item_key => $item_values ) {
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
                if ($this->campaign_mode == self::CAMPAIGN_MODE_PRODUCT) {
                    $campaign = $this->get_product_campaign_by_id($product->get_id());
                    if (!empty($campaign)) {
                        $line_item['itemData']['promoCode'] = $campaign['promoCode'];
                        $line_item['itemData']['campaignMode'] = 'addition';
                    }
                }
                $line_items[] = $line_item;
            }

            // order subtotal = cart total
            $order_miles = $this->get_order_miles($order);
            $order_subtotal = $order->get_subtotal();
            $order_discount = $this->get_order_discount($order);
            $order_total_for_reward = max($order_subtotal - $order_discount, 0) * 100;
            $order_total = $order->get_total() * 100;
            $order_currency = $order->get_currency();

            // tel
            $tel = DIVIT_UTILS::to_tel($order->get_billing_phone());

            $create_order_customer_data = array(
                'firstName' => $order->get_billing_first_name(),
                'lastName' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'tel' => $tel['countryCode'] . $tel['tel'],
                'floor' => '',
                'building' => $order->get_billing_address_1(),
                'street' => $order->get_billing_address_2(),
                'district' => $order->get_billing_city(),
                'region' => $order->get_billing_state(),
                'language' => 'en-US'
            );

            // promotion code that campaign used
            $order_promo_code = $this->selected_campaign['promoCode'];
            if ($this->campaign_mode == self::CAMPAIGN_MODE_PRODUCT) {
                $order_promo_code = '';
            }

            // promotion code that customer used in order campaign mode only
            $promotion_code = WC()->session->get(self::DIVIT_PROMOTION_CODE_INPUT, '');
            if ($this->campaign_mode == self::CAMPAIGN_MODE_ORDER && !empty($promotion_code)) {
                $order_promo_code = $promotion_code;
            }

            // save promotion code for reference
            $log_promotion_code = array();
            if ($this->campaign_mode == self::CAMPAIGN_MODE_ORDER) {
                $log_promotion_code['order'] = $order_promo_code;
            } else if ($this->campaign_mode == self::CAMPAIGN_MODE_PRODUCT) {
                foreach ( $order->get_items() as $item_key => $item_values ) {
                    $item = $item_values->get_data();
                    $product = wc_get_product($item['product_id']);
                    $campaign = $this->get_product_campaign_by_id($product->get_id());
                    $log_promotion_code[$item['product_id']] = $campaign['promoCode'] ?? "";
                }
            }
            $order->update_meta_data('divit_promotion_code', json_encode($log_promotion_code));

            // save number of miles reward to customer
            $order->update_meta_data('divit_miles_for_reward', $order_miles);
            $order->save();

            $create_order_data = array(
                "milesForReward" => $order_miles,
                "totalForReward" => array(
                    "amount" => $order_total_for_reward,
                    "currency" => $order_currency,
                ),
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
                "promoCode" => $order_promo_code,
                "webhookSuccess" => $this->get_return_url($order),
                "webhookFailure" => $order->get_checkout_payment_url(),
                "webhookEvents" => get_site_url() . '?wc-api=CALLBACK_DIVIT_PAYMENT',
                // "expiredAt" => 1664442745,
            );

            $create_order = array(
                "customer" => $create_order_customer_data,
                "order" => $create_order_data,
            );

            // wc_add_notice(json_encode($create_order), 'error');
            // return;

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
            $create_order_token = $create_order_resp_data['token'];
            self::log('created order ' . json_encode($create_order) . ', ' . json_encode($create_order_resp['body']));

            // save divit order id for cancel, reference
            $order->update_meta_data('divit_order_id', $create_order_id);
            $order->set_transaction_id($create_order_id);
            $order->save();

            // divit checkout method
            $checkout_method = WC()->session->get(self::DIVIT_CHECKOUT_METHOD, 'paynow');
            $checkout_meta = WC()->session->get(self::DIVIT_CHECKOUT_META, array());
            $checkout_params = http_build_query(array(
                'checkoutMethod' => $checkout_method,
                'checkoutMiles' => $checkout_meta['miles'] ?? 0,
            ));

            // append current language in the redirect_uri
            $current_lang = get_locale(); 
            if ($current_lang != NULL) {
                if (str_contains($redirect_uri, 'lang=')) {
                    $redirect_uri = preg_replace('/lang=[a-zA-Z_\-]*[^&]/', 'lang='.$current_lang, $redirect_uri);
                } else {
                    if (str_contains($redirect_uri, '?')) {
                        $redirect_uri = str_replace('?', "?lang=$current_lang&", $redirect_uri);
                    } else {
                        $redirect_uri = $redirect_uri . "?lang=$current_lang";
                    }
                }
            }

            return array(
                'result' => 'success',
                'redirect' => $redirect_uri . '&token=' . $create_order_token . '&' . $checkout_params,
            );
        }

        public function divit_express_checkout()
        {
            $form_data = $_POST['data'];
            $form_products = isset($form_data['products']) ? $form_data['products'] : array();

            $products = array();

            // sanitize
            foreach ($form_products as $product) {
                $variations = array();
                foreach ( $product['variations'] as $key => $value ) {
                    $variation_name = sanitize_text_field($value['name']);
                    $variation_value = sanitize_text_field($value['value']);
                    $variations[ $variation_name ] = $variation_value;
                }
                $products[] = array(
                    'id' => isset($product['id']) ? sanitize_text_field($product['id']) : '',
                    'quantity' => isset($product['quantity']) ? sanitize_text_field($product['quantity']) : '',
                    'variations' => $variations,
                );
            }

            if (empty($products)) {
                wp_send_json_error('no products');
                die();
            }

            do_action('divit_express_checkout_validation', $form_data);
            if (0 !== wc_notice_count( 'error' )) {
                ob_start();
                wc_print_notices();
                $messages = ob_get_clean();
                wp_send_json_error(array(
                    'messages' => $messages
                ));
                die();
            }

            $cart = WC()->cart;
            $shipping = WC()->shipping();
            $data_store = WC_Data_Store::load( 'product' );

            // init
            $shipping->reset_shipping();
            $cart->empty_cart(false);

            // add products to cart
            foreach ( $products as $product ) {
                $p = wc_get_product((int)$product['id']);
                if (! $p->is_type( 'variable' ) ) {
                    $cart->add_to_cart((int)$product['id'], (int)$product['quantity']);
                } else {
                    $variations = $product['variations'];
                    $variation_id = $data_store->find_matching_product_variation($p, $variations);
                    $cart->add_to_cart((int)$product['id'], (int)$product['quantity'], $variation_id, $variations); 
                }
            }

            $cart->calculate_shipping();
            $cart->calculate_totals();

            $checkout = WC()->checkout();
            $order_id = $checkout->create_order(array());

            $order = wc_get_order($order_id);
            $order->set_payment_method($this->id);
            $order->set_payment_method_title($this->method_title);

            $merchant_ref = $order->get_id();

            $line_items = array();
            foreach ( $order->get_items() as $item_key => $item_values ) {
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
                if ($this->campaign_mode == self::CAMPAIGN_MODE_PRODUCT) {
                    $campaign = $this->get_product_campaign_by_id($product->get_id());
                    if (!empty($campaign)) {
                        $line_item['itemData']['promoCode'] = $campaign['promoCode'];
                        $line_item['itemData']['campaignMode'] = 'addition';
                    }
                }
                $line_items[] = $line_item;
            }

            // order subtotal = cart total
            $order_miles = $this->get_order_miles($order);
            $order_subtotal = $order->get_subtotal();
            $order_discount = $this->get_order_discount($order);
            $order_total_for_reward = max($order_subtotal - $order_discount, 0) * 100;
            $order_total = $order->get_total() * 100;
            $order_currency = $order->get_currency();

            // promotion code that campaign used
            $order_promo_code = $this->selected_campaign['promoCode'];
            if ($this->campaign_mode == self::CAMPAIGN_MODE_PRODUCT) {
                $order_promo_code = '';
            }

            // save number of miles reward to customer
            $order->update_meta_data('divit_miles_for_reward', $order_miles);
            $order->save();

            $create_order_data = array(
                "milesForReward" => $order_miles,
                "totalForReward" => array(
                    "amount" => $order_total_for_reward,
                    "currency" => $order_currency,
                ),
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
                "promoCode" => $order_promo_code,
                "webhookSuccess" => $this->get_return_url($order),
                "webhookFailure" => $order->get_checkout_payment_url(),
                "webhookEvents" => get_site_url() . '?wc-api=CALLBACK_DIVIT_PAYMENT',
            );

            $create_order_resp = $this->create_express_order($create_order_data, $this->api_key);
            if ($create_order_resp['status_code'] != 200) {
                if ($create_order_resp['status_code'] == 400) {
                    $err = $create_order_resp['body'];
                    wp_send_json_error($err);
                    die();
                }
                self::log("divit express create order error 1001");
                self::log($create_order_resp['status_code']);
                self::log(json_encode($create_order_resp['body'])); 
                wp_send_json_error($err);
                die();
            }
            $create_order_resp_data = $create_order_resp['body']['data'];
            $create_order_id = $create_order_resp_data['orderID'];
            $redirect_uri = $create_order_resp_data['redirectURI'];
            self::log('created order ' . json_encode($create_order_data) . ', ' . json_encode($create_order_resp['body']));

            // save divit order id for cancel, reference
            $order->update_meta_data('divit_order_id', $create_order_id);
            $order->update_meta_data('divit_express_checkout', true);
            $order->save();

            // divit checkout method
            $checkout_params = http_build_query(array(
                'checkoutMethod' => 'express',
            ));

            if (str_contains($redirect_uri, '?')) {
                $redirect_uri = str_replace('?', '/fast?', $redirect_uri);
            } else {
                $redirect_uri = $redirect_uri . '/fast?';
            }
            $current_lang = get_locale(); 
            if ($current_lang != NULL) {
                if (str_contains($redirect_uri, 'lang=')) {
                    $redirect_uri = preg_replace('/lang=[a-zA-Z_\-]*[^&]/', 'lang='.$current_lang, $redirect_uri);
                } else {
                    if (str_contains($redirect_uri, '?')) {
                        $redirect_uri = str_replace('?', "?lang=$current_lang&", $redirect_uri);
                    } else {
                        $redirect_uri = $redirect_uri . "?lang=$current_lang";
                    }
                }
            }

            wp_send_json_success(array(
                'orderId' => $create_order_id,
                'redirectURL' => $redirect_uri . '&' . $checkout_params,
            ));

            die();
        }
        
        // UI in checkout
        public function payment_fields()
        {
            $is_logged_in = !empty($this->get_divit_user());
            $is_show_earn_miles_label = $this->is_earn_miles_enabled() && $this->settings['osm-checkout-page'] == 'yes';
            $is_promotion_code_enabled = $this->is_promotion_code_enabled();

            // validate promo code
            $promotion_code = WC()->session->get(self::DIVIT_PROMOTION_CODE_INPUT, '');
            $is_valid_promo = WC()->session->get(self::DIVIT_VALID_PROMO_CODE, '') == 'true';
            $is_empty_promo = $promotion_code == '';
            $is_display_invalid_promo = !$is_valid_promo && !$is_empty_promo;
            if (!$is_empty_promo) {
                $resp = $this->get_promotion_code($this->merchant_id, $promotion_code, $this->api_key);
                if ($resp['status_code'] != 200 || empty($resp['body']['data']['promoCode'])) {
                    $this->reset_promo_code();
                    $promotion_code = '';
                    $is_valid_promo = false;
                    $is_empty_promo = true;
                }
            }

            // get cart data or order-pay order
            $currency_symbol = get_woocommerce_currency_symbol();
            $cart_total = $this->get_cart_total(WC()->cart);
            $shipping_total = WC()->cart->get_shipping_total();
            $total = $cart_total + $shipping_total;
            $order = wc_get_order(get_query_var('order-pay'));
            if (!empty($order)) {
                $total = $order->get_total();
            }
            $formatted_order_total = $currency_symbol . number_format($total);

            // checkout option
            if (!$is_logged_in) {
                $this->do_set_checkout_method('paynow', array());
            }

            ?>
            <div class="payment_method_<?php echo $this->id ?>_desc">
                <?php
                    // read campaign message config file from static
                    $config_env = $this->get_env();
                    $config_url = $config_env['static_url'].'/configs/woocommerce/'.$config_env['name'].'-checkout-message-config.json?t='.(floor(time()/100));
                    $config_content=file_get_contents($config_url);
                    $config_data=json_decode($config_content);

                    if (isset($config_data->marketCampaignEndDate)) {
                        if (strtotime($config_data->marketCampaignEndDate) > strtotime(date('Y-m-d'))) {
                            $current_lang = substr(get_locale(), 0, 2); 

                            // banner
                            $banner = $config_data->banner->$current_lang ?? $config_data->banner->en ?? '';
                            $bannerURL = $config_data->bannerURL ?? '';
                            if ($banner != '') { ?>
                                <div class="divit_promotion_banner_container">
                                    <?php if ($bannerURL != '') { 
                                        ?><a href="<?php echo $bannerURL; ?>" target="_blank"><?php 
                                        $desktopBanner = str_replace('{{form}}', '', $banner);
                                        $mobileBanner = str_replace('{{form}}', 'm', $banner);
                                    }?>
                                    <picture>
                                        <source media="(min-width: 640px)" srcset="<?php echo $desktopBanner ?>" />
                                        <source media="(max-width: 639px)" srcset="<?php echo $mobileBanner ?>" />
                                        <img src="<?php echo esc_attr($desktopBanner);?>" alt="banner" style="margin:0" />
                                    </picture>
                                    <?php if ($bannerURL != '') { ?></a><?php }?>
                                </div><?php
                            }

                            // format the message if any
                            $message = $config_data->message->$current_lang ?? $config_data->message->en ?? '';
                            if ($message != '') {
                                if (str_contains($message, '{{formula}}')) {
                                    if (isset($config_data->formula)) {
                                        $formula_val = 0;
                                        // for security reason, we dont allow using php variable sign directly
                                        $formula = str_replace('$', '', $config_data->formula);
                                        $formula = "\$formula_val = ".str_replace('{{amount}}', $total, $formula).";";
                                        eval($formula);
                                        $message = str_replace('{{formula}}', "<b>".number_format($formula_val)."</b>", $message);
                                    }
                                }
                                if ($message != '') {
                                    if (str_contains($message, '{{icon}}')) {
                                        if (isset($config_data->icon)) {
                                            $icon = "<img src=\"".esc_attr($config_data->icon)."\" alt=\"icon\" width=\"24\" />";
                                            $message = str_replace('{{icon}}', $icon, $message);
                                        }
                                    }
                                    ?>
                                    <div class="divit_earn_miles_message divit_promotion_messasge"><?php echo $message;?></div>
                                    <?php
                                }
                            }
                        }
                    }
                ?>
                <?php if ($this->campaign_mode == self::CAMPAIGN_MODE_ORDER): ?>
                    <?php if ($is_show_earn_miles_label): ?>
                    <div class="divit_earn_miles_message">
                        <b><?php echo esc_html($is_valid_promo ? __('promo code applied: ') : ''); ?></b>
                        <?php echo esc_html(__('you will earn', 'wc-divit-payment-gateway')); ?>
                        <?php $this->render_checkout_earn_miles(); ?>
                        <?php echo esc_html(__('when the order is complete', 'wc-divit-payment-gateway')); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($is_promotion_code_enabled): ?>
                    <div class="divit_promotion_code_container">
                        <form id="divit_promotion_code_form" class="divit_promotion_code_input_container">
                            <div class="divit_promotion_code_input">
                                <?php
                                    if ($is_valid_promo) {
                                        echo wp_kses_post(sprintf(
                                            __('promo code %s applied! you will earn 1 mile every %s spent', 'wc-divit-payment-gateway'), 
                                            '<b>' . $promotion_code . '</b>',
                                            '$' . $this->get_miles_rate(true),
                                        ));
                                    } else {
                                        woocommerce_form_field( 'divit_promotion_code', array(
                                            'type'          => 'text',
                                            'class'         => array('transaction_type form-row-wide'),
                                            'label'         => 'divit promotional code',
                                            'placeholder'    => __('divit promotional code (optional) ', 'wc-divit-payment-gateway'),
                                            'description'   => '',
                                        ), $promotion_code);
                                    }
                                ?>
                            </div>
                            <button id="divit_promotion_code_submit" type="submit" class="button" data-submit-type="<?php echo esc_html($is_valid_promo ? __('remove', 'wc-divit-payment-gateway') : __('submit', 'wc-divit-payment-gateway')); ?>">
                                <?php echo esc_html($is_valid_promo ? __('remove', 'wc-divit-payment-gateway') : __('apply', 'wc-divit-payment-gateway')); ?>
                            </button>
                        </form>
                        <div class="divit_promotion_code_message_failed">
                            <?php echo esc_html($is_display_invalid_promo ? __('invalid code', 'wc-divit-payment-gateway') : ''); ?>
                        </div>
                    </div>
                    <?php endif ?>
                    <script>
                        jQuery(function($) {
                            var $code = $('[name=divit_promotion_code]')
                            function submitPromotionCode() {
                                var type = $(this).data('submit-type');
                                var promotion_code = type === 'remove' ? '' : $('[name="divit_promotion_code"]').val();
                                $.ajax({
                                    type: 'POST',
                                    url: '<?php echo esc_html(admin_url("admin-ajax.php")); ?>',
                                    data: {
                                        action: 'divit_apply_promotion_code',
                                        promotion_code: promotion_code,
                                    },
                                    success: function(textStatus){
                                        $(document.body).trigger("update_checkout");
                                        // update checkout will call this method again, so server render status
                                    },
                                    error: function(MLHttpRequest, textStatus, errorThrown){
                                        $(document.body).trigger("update_checkout");
                                    }
                                })
                                return false;
                            }
                            $('divit_promotion_code_form').submit(submitPromotionCode)
                            $('#divit_promotion_code_submit').click(submitPromotionCode)
                        })
                    </script>
                <?php endif ?>
                <div class="divit_checkout_block divit_checkout_paynow checked">
                    <div class="divit_checkout_block_overlay" data-method-id="paynow"></div>
                    <div class="divit_radio divit_radio_paynow checked" data-radio-id="paynow"></div>
                    <div style="width:22px;"></div>
                    <div class="divit_checkout_block_content">
                        <div class="divit_checkout_block_title">
                            <?php echo esc_html(__('pay now', 'wc-divit-payment-gateway')); ?>
                        </div>
                        <?php if ($is_show_earn_miles_label): ?>
                        <div class="divit_checkout_block_subtitle">
                            <?php echo esc_html(__('earn miles with FPS payments', 'wc-divit-payment-gateway')); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="divit_checkout_block_right">
                        <div class="divit_checkout_paynow_total">
                            <?php echo esc_html($formatted_order_total); ?>
                        </div>
                    </div>
                </div>
                <?php if ($this->settings['sso-enabled'] === 'yes'): ?>
                <div style="height:24px"></div>
                <div class="divit_checkout_block divit_checkout_spend">
                    <div class="divit_checkout_block_overlay" data-method-id="spend"></div>
                    <div class="divit_radio divit_radio_spend"></div>
                    <div style="width:22px;"></div>
                    <div class="divit_checkout_block_content">
                        <div class="divit_checkout_block_title">
                            <?php echo esc_html(__('spend miles', 'wc-divit-payment-gateway')); ?>
                        </div>
                        <div class="divit_checkout_block_subtitle">
                            <?php if($is_logged_in): ?>
                                <?php echo esc_html(__('spend your divit miles to save!', 'wc-divit-payment-gateway')); ?>
                            <?php else: ?>
                                <?php echo esc_html(__('want to spend miles to save?', 'wc-divit-payment-gateway')); ?> <a class="divit_redirect_url" href="<?php echo esc_html($this->get_divit_login_url()); ?>"><?php _e('log in', 'wc-divit-payment-gateway'); ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if($is_logged_in): ?>
                    <div class="divit_checkout_spend_loading divit-items-center">
                        <img  src="<?php echo esc_attr($this->get_env()['static_url']); ?>/icons/spinner.svg" width="40px" height="40px" />
                    </div>
                    <div class="divit_checkout_block_right divit_checkout_spend_container">
                        <form id="divit_checkout_miles_form">
                            <div class="divit_checkout_miles_input_container divit-items-center">
                                <div class="divit_checkout_miles_input_box divit-flex-1">
                                    <div class="divit_checkout_cash_input_symbol">$</div>
                                    <input id="divit_checkout_cash_input" type="number" />
                                </div>
                                <div class="divit_checkout_miles_input_plus">+</div>
                                <div class="divit_checkout_miles_input_box divit-flex-1">
                                    <img src="<?php echo esc_attr($this->get_env()['static_url']); ?>/miles/miles-icon.png" width="16px" height="16px" />
                                    <input id="divit_checkout_miles_input" type="number" />
                                </div>
                            </div>
                            <div style="height:16px"></div>
                            <div class="divit_checkout_miles_slider_container">
                                <input id="divit_checkout_miles_slider" type="range" />
                            </div>
                            <div style="height:16px"></div>
                            <div class="divit_checkout_miles_desc">
                                <img src="<?php echo esc_attr($this->get_env()['static_url']); ?>/miles/miles-icon.png" width="16px" height="16px" style="margin-right:5px;filter: grayscale(1);" />
                                <span id="divit_checkout_miles_base"></span>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php
        }

        public function reset_promo_code() {
            WC()->session->set(self::DIVIT_PROMOTION_CODE_INPUT, '');
            WC()->session->set(self::DIVIT_PROMOTION_CAMPAIGN, array()); 
            WC()->session->set(self::DIVIT_VALID_PROMO_CODE, '');
        }

        public function do_apply_promotion_code($promotion_code) {
            $this->reset_promo_code();

            if (empty($promotion_code)) {
                return array('success' => true, 'data' => 'clear promotion code');
            }

            $promotion_code = trim($promotion_code);

            $resp = $this->get_promotion_code($this->merchant_id, $promotion_code, $this->api_key);
            if ($resp['status_code'] != 200 || empty($resp['body']['data']['promoCode'])) {
                WC()->session->set(self::DIVIT_PROMOTION_CODE_INPUT, $promotion_code);
                WC()->session->set(self::DIVIT_VALID_PROMO_CODE, 'false');
                return array('success' => false, 'data' => 'invalid promotion code, ' . $promotion_code);
            }
            WC()->session->set(self::DIVIT_PROMOTION_CODE_INPUT, $promotion_code);
            WC()->session->set(self::DIVIT_PROMOTION_CAMPAIGN, $resp['body']['data']);
            WC()->session->set(self::DIVIT_VALID_PROMO_CODE, 'true');
            return array('success' => true, 'data' => 'applied promotion code, ' . $promotion_code);
        }

        public function prefill_promo_code_from_url() {
            if (!$this->is_promotion_code_enabled()) {
                return;
            }
            if (isset($_GET[self::QUERY_DIVIT_PROMO_CODE_KEY])) {
                DIVIT_UTILS::create_wc_session_if_empty();
                $promotion_code = $_GET[self::QUERY_DIVIT_PROMO_CODE_KEY];
                $result = $this->do_apply_promotion_code($promotion_code);
                if ($result['success']) {
                    // refresh cart by js
            ?>
                <script>
                    jQuery(function() {
                        setTimeout(() => {
                            jQuery(document.body).trigger('wc_fragment_refresh');
                        }, 0.5 * 1000);
                    });
                </script>
            <?php 
                } else {
                    $this->reset_promo_code();
                }
            }
        }

        // apply promotion code to session in checkout page
        public function divit_apply_promotion_code() {
            if (!$this->is_promotion_code_enabled()) {
                wp_send_json_error('promotion code is not enabled');
                die();
            }

            $promotion_code = sanitize_text_field($_POST['promotion_code']);

            $result = $this->do_apply_promotion_code($promotion_code);

            if ($result['success']) {
                wp_send_json_success($result['data']);
            } else {
                wp_send_json_error($result['data']);
            }

            die();
        }

        // data used for checkout calculations and options
        public function divit_get_checkout_data() {
            $miles_rate_resp = $this->get_HKD_DVM_rate($this->api_key);
            if ($miles_rate_resp['status_code'] != 200) { 
                wp_send_json_error('invalid checkout');
                die();
            }
            $miles_rate = $miles_rate_resp['body']['data'];
            $cart_total = $this->get_cart_total(WC()->cart);
            $shipping_total = WC()->cart->get_shipping_total();
            $order_total = $cart_total + $shipping_total;
            $user = $this->get_divit_user();
            $profile = null;
            if (!empty($user)) {
                $profile = $this->get_divit_user_profile($user);
            }
            wp_send_json_success(array(
                'buyRate' => $miles_rate['buy'],
                'sellRate' => $miles_rate['sell'],
                'orderTotal' => $order_total,
                'isLoggedIn' => !empty($user),
                'profile' => $profile,
                'checkoutOption' => array(
                    'method' => WC()->session->get(self::DIVIT_CHECKOUT_METHOD, 'paynow'),
                    'meta' => WC()->session->get(self::DIVIT_CHECKOUT_META),
                )
            ));
            die();
        }

        public function do_set_checkout_method($checkout_method, $checkout_meta) {
            WC()->session->set(self::DIVIT_CHECKOUT_METHOD, $checkout_method);
            WC()->session->set(self::DIVIT_CHECKOUT_META, $checkout_meta);
        }

        public function divit_set_checkout_method() {
            $this->do_set_checkout_method(
                sanitize_text_field($_POST['checkout_method'] ?? ''), 
                array_map( 'sanitize_text_field', $_POST['checkout_meta'] ?? array() )
            );
            wp_send_json_success();
            die();
        }

        // logging for "WooCommerce > System Status > Logs" in the WordPress admin
        // Select / Search for date of log
        public static function log($message) {
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
            self::$log->add( 'divit', $message );
        }

        public function login($client_id, $client_secret) {
            $url = $this->get_env()['api_url'] . '/login';
            $body =  "client_id=" . $client_id . "&client_secret=" . $client_secret;
            $headers = array('Content-Type' => 'application/x-www-form-urlencoded');
            return DIVIT_UTILS::http_request($url, 'POST', $body, $headers);
        }

        public function get_merchant($api_key) {
            $url = $this->get_env()['api_url'] . '/partners';
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            return DIVIT_UTILS::http_request($url, 'GET', array(), $header);
        }

        public function create_order($create_order, $api_key) {
            $url = $this->get_env()['api_url'] . '/paynow/orders/fast';
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            return DIVIT_UTILS::http_request($url, 'POST', $create_order, $header);
        }

        public function create_express_order($create_order, $api_key) {
            $url = $this->get_env()['api_url'] . '/paynow/orders';
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            return DIVIT_UTILS::http_request($url, 'POST', $create_order, $header);
        }

        public function get_order($order_id, $api_key) {
            $url = $this->get_env()['api_url'] . '/paynow/orders/' . $order_id;
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            return DIVIT_UTILS::http_request($url, 'GET', array(), $header);
        }

        public function get_campaigns($merchant_id, $api_key) {
            $url = $this->get_env()['api_url'] . '/campaign/merchants/' . $merchant_id;
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            return DIVIT_UTILS::http_request($url, 'GET', array(), $header);
        }

        public function get_HKD_DVM_rate($api_key) {
            $url = $this->get_env()['api_url'] . '/miles/rates/merchants/HKD/DVM';
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            return DIVIT_UTILS::http_request($url, 'GET', array(), $header);
        }

        public function get_promotion_code($merchant_id, $promotion_code, $api_key) {
            $url = $this->get_env()['api_url'] . '/campaign/merchants/' . $merchant_id . '/promo/' . $promotion_code;
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            return DIVIT_UTILS::http_request($url, 'GET',  array(), $header);
        }

        public function apply_promotion_code($order_id, $promotion_code, $api_key) {
            $url = $this->get_env()['api_url'] . '/paynow/fast/orders/' . $order_id . '/promo';
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            $body = array('promoCode' => $promotion_code);
            return DIVIT_UTILS::http_request($url, 'PATCH', $body, $header);
        }

        public function get_user_profile($access_token, $scope, $api_key) {
            $url = $this->get_env()['api_url'] . '/auth/customer';
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            $body = array('accessToken' => $access_token, 'scope' => $scope);
            return DIVIT_UTILS::http_request($url, 'GET', $body, $header);
        }

        public function revoke_user($refresh_token, $api_key) {
            $url = $this->get_env()['api_url'] . '/auth/token/revoke';
            $header = array('Content-Type' => 'application/json', 'API-Key' => $api_key);
            $body = array('refreshToken' => $refresh_token);
            return DIVIT_UTILS::http_request($url, 'POST', $body, $header);
        }

        public function callback_divit_payment_handler() {
            $resp = file_get_contents('php://input');
            $json = json_decode($resp, true);
            $headers = getallheaders();
            
            $message_str = json_encode($json);
            $header_str = json_encode($headers);
            self::log("callback: received {$message_str} {$header_str}");

            $merchant_ref = $json['eventData']['partnerRef'];

            // validate signature
            $signature = array_key_exists('X-Divit-Signature', $headers) ? $headers['X-Divit-Signature'] : '';
            $signature = array_key_exists('x-divit-signature', $headers) ? $headers['x-divit-signature'] : $signature;
            $valid = DIVIT_UTILS::validate_webhook($signature, $this->api_key, $resp);
            if (!$valid) {
                $message = "callback: signature valid error, merchant reference {$merchant_ref}";
                self::log($message);
                wp_send_json($message);
                exit;
            }

            // cancelled order
            if ($json['event']['eventId'] == 4000) {
                $order_id = $merchant_ref;
                $order = wc_get_order($merchant_ref);
                $is_same_order = get_post_meta($order_id, 'divit_order_id')[0] == $json['eventData']['orderID'];
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

            // find order in wc by order number
            // BUG: plugin changing order number after process payment
            // $order_id = DIVIT_UTILS::get_order_by_order_number($merchant_ref);

            $order_id = $merchant_ref;
            $order = wc_get_order($order_id);

            // cannot find order
            if (!$order) {
                $message = "callback: cannot find order by merchant reference {$merchant_ref}";
                self::log($message);
                wp_send_json($message);
                exit;
            }

            // update order address if none
            $delivery_address = $json['eventData']['deliveryAddress'];
            $is_express_checkout = get_post_meta($order_id, 'divit_express_checkout') == true;
            if ($is_express_checkout && !is_null($delivery_address) && isset($delivery_address['address1'])) {
                update_post_meta($order_id, '_billing_first_name', $delivery_address['contactFirstname']);
                update_post_meta($order_id, '_billing_last_name', $delivery_address['contactLastname']);
                update_post_meta($order_id, '_billing_phone', $delivery_address['tel']);
                // update_post_meta($order_id, '_billing_email', $delivery_address['email']);
                update_post_meta($order_id, '_billing_address_1', $delivery_address['address1']);
                update_post_meta($order_id, '_billing_address_2', $delivery_address['address2']);
                update_post_meta($order_id, '_billing_city', $delivery_address['district']);
                update_post_meta($order_id, '_billing_state', $delivery_address['region']);
                update_post_meta($order_id, '_shipping_first_name', $delivery_address['contactFirstname']);
                update_post_meta($order_id, '_shipping_last_name', $delivery_address['contactLastname']);
                update_post_meta($order_id, '_shipping_phone', $delivery_address['contactNumber']);
                update_post_meta($order_id, '_shipping_address_1', $delivery_address['address1']);
                update_post_meta($order_id, '_shipping_address_2', $delivery_address['address2']);
                update_post_meta($order_id, '_shipping_city', $delivery_address['district']);
                update_post_meta($order_id, '_shipping_state', $delivery_address['region']);
            }

            // update order status to processing
            $order->payment_complete();

            // Reduce stock levels
            wc_reduce_stock_levels($order_id);

            // we can't clear customer cart here, cuz this method call by server, 
            // cannot get specific user section, see function 'order_received_action'

            $message = "callback: complete order, merchant reference {$merchant_ref}";
            self::log($message);

            // get more divit order data mark it in order
            $divit_order_resp = $this->get_order($json['eventData']['orderID'], $this->api_key);
            if ($divit_order_resp['status_code'] == 200) {
                $divit_order = $divit_order_resp['body']['data'];
                $divit_refundable_amount = $divit_order['refundableAmount']['amount'] ?? 0;
                $order->update_meta_data('divit_refundable_amount', $divit_refundable_amount / 100); 
                $divit_nonrefundable_amount = $divit_order['nonrefundableAmount']['amount'] ?? 0;
                $order->update_meta_data('divit_nonrefundable_amount', $divit_nonrefundable_amount / 100);
                $order->update_meta_data('divit_miles_burned', $divit_order['milesBurned'] ?? 0);
                $order->save();
            }

            wp_send_json($message);
            exit;
        }

        // trigger in woocommerce_thankyou
        public function order_received_action($order_id) {
            self::log('order received: ' . $order_id);

            // clear cart
            WC()->cart->empty_cart();

            // clear session
            WC()->session->set(self::DIVIT_PROMOTION_CODE_INPUT, '');
            WC()->session->set(self::DIVIT_PROMOTION_CAMPAIGN, array());
            WC()->session->set(self::DIVIT_VALID_PROMO_CODE, '');
            WC()->session->set(self::DIVIT_CHECKOUT_METHOD, '');
            WC()->session->set(self::DIVIT_CHECKOUT_META, array());
        }

        /**
         * divit single sign on
         */

        const DIVIT_AUTH_COOKIE_KEY = 'divit-auth';
        const DIVIT_AUTH_KEY_PREFIX = 'divit_auth_';

        public function get_divit_user() {
            if (isset($_COOKIE[self::DIVIT_AUTH_COOKIE_KEY])) {
                $id = $_COOKIE[self::DIVIT_AUTH_COOKIE_KEY];
                if (!empty($id)) {
                    return get_option(self::DIVIT_AUTH_KEY_PREFIX . $id, NULL);
                }
            }
            return null;
        }

        public function get_divit_user_profile($user) {
            $profile_resp = $this->get_user_profile($user['access_token'], $user['scope'], $this->api_key); 
            if ($profile_resp['status_code'] != 200 || empty($profile_resp['body']['data'])) {
                return null;

            }
            return $profile_resp['body']['data'];
        }

        public function get_divit_login_url() {
            $url = $this->get_env()['web_url'];
            $scope = 'full';
            $current_lang = get_locale();
            $redirect_url = get_site_url() . '/?wc-api=DIVIT_AUTH_LOGIN';
            $redirect = get_site_url(); 

            // redirect url should be allowed in divit partner portal

            return $url . '/sso?' . http_build_query(array(
                'scope' => $scope,
                'redirectUrl' => $redirect_url,
                'redirect' => $redirect,
                'lang' => $current_lang,
            )); 
        }

        public function get_divit_logout_url() {
            return get_site_url() . '/?wc-api=DIVIT_AUTH_LOGOUT&redirect=' . get_site_url();
        }

        // redirect back after loggined in divit app
        public function divit_auth_login_handler() {
            // validation
            if (!DIVIT_UTILS::isset_all_keys(array('customerID', 'accessToken', 'refreshToken', 'scope', 'expiresIn'), $_GET)) {
                $this->divit_auth_logout();
                wc_add_notice(_('login to divit fail'), 'error');
                wp_redirect(DIVIT_UTILS::get_redirect_url());
                exit;
            }

            // save data in db
            $customer_id = sanitize_text_field($_GET['customerID']);
            $access_token = sanitize_text_field($_GET['accessToken']);
            $refresh_token = sanitize_text_field($_GET['refreshToken']);
            $scope = sanitize_text_field($_GET['scope']);
            $expire = sanitize_text_field($_GET['expiresIn']);

            // create user session key for divit auth
            $id = wp_generate_uuid4();
            $expire_sec = time() + ($expire - 3600 * 24 * 1);
            setcookie(self::DIVIT_AUTH_COOKIE_KEY, $id, $expire_sec, COOKIEPATH, COOKIE_DOMAIN);
            
            update_option(self::DIVIT_AUTH_KEY_PREFIX . $id, array(
                "customer_id" => $customer_id,
                "access_token" => $access_token, 
                "refresh_token" => $refresh_token,
                "scope" => $scope,
                "expire" => $expire,
            ));

            wc_add_notice(__('login to divit successfully', 'wc-divit-payment-gateway'));
            wp_redirect(DIVIT_UTILS::get_redirect_url());
            exit;
        }

        public function divit_auth_logout_handler() {
            $this->divit_auth_logout();
            wc_add_notice(__('logout divit successfully', 'wc-divit-payment-gateway'));
            wp_redirect(DIVIT_UTILS::get_redirect_url());
            exit;
        }

        public function divit_auth_logout() {
            $user = $this->get_divit_user();
            if ($user) {
                $this->revoke_user($user['refresh_token'], $this->api_key);
            }
            setcookie(self::DIVIT_AUTH_COOKIE_KEY, '', time() - 1000, COOKIEPATH, COOKIE_DOMAIN);
        }

        public function get_login_button_html() {
            $static_url = $this->get_env()['static_url'];

            $html = '';
            $html .= '<div class="divit-login">';
            $html .= '  <div class="divit-login-button">';
            $html .= '      <div class="divit-login-button-content divit-login-text">';
            $html .= '          ' . __('log in', 'wc-divit-payment-gateway');
            $html .= '      </div>';
            $html .= '      <div class="divit-arrow-down"></div>';
            $html .= '  </div>';
            $html .= '  <div class="divit-login-popup">';
            $html .= '      <a class="divit-login-text divit_redirect_url" href="' . $this->get_divit_login_url() . '">';
            $html .= '          <div class="divit-login-button">';
            $html .= '              ' . __('log in', 'wc-divit-payment-gateway');
            $html .= '          </div>';
            $html .= '      </a>';
            $html .= '      <div style="height:19px;"></div>';
            $html .= '      <div class="divit-login-signup-text">' . __('don&rsquo;t have a divit account yet?', 'wc-divit-payment-gateway') . ' <a href="' . $this->get_env()['web_url'] . '">' . __('sign up', 'wc-divit-payment-gateway') . '</a></div>';
            $html .= '      <div style="height:16px;"></div>';
            $html .= '      <div class="divit-separator"></div>';
            $html .= '      <div style="height:16px;"></div>';
            $html .= '      <a class="divit-link" target="_blank" href="https://www.asiamiles.com/en/earn-miles/retail/detail/divit/miles-redemption-divit.html">';
            $html .= '          <div class="divit-space-between">';
            $html .= '              <div class="divit-link-text">' . __('convert my Asia Miles', 'wc-divit-payment-gateway') . '</div>';
            $html .= '              <img src="' . $static_url . '/icons/external-link-icon.svg' . '" width="12px" height="12px" />'; 
            $html .= '          </div>';
            $html .= '      </a>';
            $html .= '      <div style="height:16px;"></div>';
            $html .= '      <a class="divit-link" target="_blank" href="' . $this->get_env()['web_url'] . '">';
            $html .= '          <div class="divit-space-between">';
            $html .= '              <div class="divit-link-text">' . __('view my divit account', 'wc-divit-payment-gateway') . '</div>';
            $html .= '              <img src="' . $static_url . '/icons/external-link-icon.svg' . '" width="12px" height="12px" />'; 
            $html .= '          </div>';
            $html .= '      </a>';
            $html .= '  </div>';
            $html .= '</div>';
            return $html;
        }

        public function get_login_button_in_nav_html() {
            $login_button_html = $this->get_login_button_html();
            return '<li class="menu-item">' . $login_button_html . '</li>';
        }

        public function get_user_profile_html($profile) {
            $static_url = $this->get_env()['static_url'];
            $firstname = $profile['firstName'];
            $miles = number_format(intval($profile['milesBalance']['amount'] / 100));

            $html = '';
            $html .= '<div class="divit-login">';
            $html .= '  <div class="divit-login-button divit-loggedin">';
            $html .= '      <div class="divit-login-button-content divit-user-profile">';
            $html .= '          <div class="divit-user-name">'. __('hi', 'wc-divit-payment-gateway') . ' ' . $firstname . ',</div>';
            $html .= '          <div style="height:2px;"></div>';
            $html .= '          <div class="divit-flex">';
            $html .= '              <img src="' . $static_url . '/miles/miles-flag-divit-icon-black-bordered.png" style="margin-right:4px;" width="12px" height="12px" />';
            $html .= '              <div class="divit-user-miles">'. $miles . '</div>';
            $html .= '          </div>';
            $html .= '      </div>';
            $html .= '      <div class="divit-arrow-down"></div>';
            $html .= '  </div>';
            $html .= '  <div class="divit-login-popup divit-loggedin">';
            $html .= '      <div class="divit-text">' . __('my total miles balance', 'wc-divit-payment-gateway') . '</div>';
            $html .= '      <div style="height:4px;"></div>';
            $html .= '      <div class="divit-items-center divit-user-miles-content">';
            $html .= '          <img src="' . $static_url . '/miles/miles-icon.png" style="margin-right:4px;" width="24px" height="24px" />';
            $html .= '          <div class="divit-miles">' . $miles . '</div>';
            $html .= '      </div>';
            $html .= '      <div style="height:16px;"></div>';
            $html .= '      <a class="divit-logout-text divit_redirect_url" href="' . $this->get_divit_logout_url() . '">';
            $html .= '          <div class="divit-logout-button">'. __('log out', 'wc-divit-payment-gateway') . '</div>';
            $html .= '      </a>';
            $html .= '      <div style="height:16px;"></div>';
            $html .= '      <a class="divit-link" target="_blank" href="' . $this->get_env()['web_url'] . '/history">'; 
            $html .= '          <div class="divit-space-between">';
            $html .= '              <div class="divit-link-text">' . __('view my orders', 'wc-divit-payment-gateway') . '</div>';
            $html .= '              <img src="' . $static_url . '/icons/external-link-icon.svg' . '" width="12px" height="12px" />'; 
            $html .= '          </div>';
            $html .= '      </a>';
            $html .= '      <div style="height:16px;"></div>';
            $html .= '      <div class="divit-separator"></div>';
            $html .= '      <div style="height:16px;"></div>';
            $html .= '      <a class="divit-link" target="_blank" href="https://www.asiamiles.com/en/earn-miles/retail/detail/divit/miles-redemption-divit.html">';
            $html .= '          <div class="divit-space-between">';
            $html .= '              <div class="divit-link-text">' . __('convert my Asia Miles', 'wc-divit-payment-gateway') . '</div>';
            $html .= '              <img src="' . $static_url . '/icons/external-link-icon.svg' . '" width="12px" height="12px" />';
            $html .= '          </div>';
            $html .= '      </a>';
            $html .= '      <div style="height:16px;"></div>';
            $html .= '      <a class="divit-link" target="_blank" href="' . $this->get_env()['web_url'] . '">';
            $html .= '          <div class="divit-space-between">';
            $html .= '              <div class="divit-link-text">' . __('view my divit account', 'wc-divit-payment-gateway') . '</div>';
            $html .= '              <img src="' . $static_url . '/icons/external-link-icon.svg' . '" width="12px" height="12px" />';
            $html .= '          </div>';
            $html .= '      </a>';
            $html .= '  </div>';
            $html .= '</div>';
            return $html;
        }

        public function get_user_profile_in_nav_html($profile) {
            $user_profile_html = $this->get_user_profile_html($profile);
            return '<li class="menu-item">' . $user_profile_html . '</li>';
        }

        public function shortcode_divit_login_button() {
            if ($this->settings['sso-enabled'] !== 'yes') return ''; 
            $login_button_html = $this->get_login_button_html();
            $user = $this->get_divit_user();
            if (empty($user)) {
                return $login_button_html;
            }
            $profile = $this->get_divit_user_profile($user);
            if (empty($profile)) {
                $this->divit_auth_logout();
                return $login_button_html;
            }
            $user_profile_html = $this->get_user_profile_html($profile);
            return $user_profile_html;
        }

        public function divit_render_nav_login_item( $items, $args ) {
            if ($this->settings['sso-enabled'] !== 'yes') return $items;
            if ( $this->settings['sso-login-button-menu'] === $args->theme_location) {
                $login_button_html = $this->get_login_button_in_nav_html();
                $user = $this->get_divit_user();
                if (empty($user)) {
                    $items .= $login_button_html;
                    return $items;
                }
                $profile = $this->get_divit_user_profile($user);
                if (empty($profile)) {
                    $this->divit_auth_logout();
                    $items .= $login_button_html;
                    return $items;
                }
                $user_profile_html = $this->get_user_profile_in_nav_html($profile);
                $items .= $user_profile_html;
                return $items;
            }
            return $items;
        }

        // ajax get user profile
        public function divit_get_user_profile() {
            $user = $this->get_divit_user();
            if ($user) {
                $profile = $this->get_divit_user_profile($user);
                if (empty($profile)) {
                    wp_send_json_error('invalid user');
                    die();
                }
                wp_send_json_success($profile);
                die();
            }
            wp_send_json_error('invalid user');
            die();
        }
    }
}