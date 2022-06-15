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

        const CAMPAIGN_MODE_ORDER = 'order';
        const CAMPAIGN_MODE_PRODUCT = 'product';

        const META_PRODUCT_CAMPAIGN = '_product_campaign_id';
        
        public function __construct()
        {
            $this->environments = include 'environments.php';
            
            $this->id           = 'divit';
            $this->method_title = 'Divit payment gateway';
            $this->method_description = 'pay now, pay later, earn miles';

            $this->init_settings();
            $this->init_form_fields();
            $this->set_default_settings();

            // setting
            $this->enabled = $this->settings['enabled'];
            $this->mode = $this->settings['mode'];
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->api_key = $this->settings['api_key'];

            // merchant setup
            $this->merchant_id = $this->settings['merchant_id'];
            $this->campaigns = $this->settings['campaigns'];
            $this->selected_campaign = $this->find_campaign($this->settings['selected_campaign_id']);
            $this->campaign_mode = $this->settings['campaign_mode'];
            
            // update admin options
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options'), 10, 0);
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'setup_merchant'), 11, 0);
            // add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( 'Divit_Plugin_Cron', 'fire_jobs'), 12, 0);

            // validation
            // TODO: remove for other merchants
            add_action('woocommerce_checkout_process', array( $this, 'custom_validate_billing_phone') );

            // AJAX
            add_action("wp_ajax_divit_apply_promotion_code", array($this, "divit_apply_promotion_code") );
            add_action("wp_ajax_nopriv_divit_apply_promotion_code", array($this, "divit_apply_promotion_code") );

            // register callback for `webhook_events`
            add_action( 'woocommerce_api_callback_divit_payment', array($this, 'callback_handler') );

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

        public function get_currency() {
            // woocommerce default currency
            // return get_option('woocommerce_currency');
            // multiple currency
            return get_woocommerce_currency();
        }

        public function set_default_setting($key, $default_value) {
            if (!array_key_exists($key, $this->settings)) {
                $this->settings[$key] = $default_value;
            } 
        }

        public function set_default_settings() {
            // account
            $this->set_default_setting('merchant_id', '');
            $this->set_default_setting('campaigns', array());
            $this->set_default_setting('selected_campaign_id', '');
            $this->set_default_setting('campaign_mode', '');
            $this->set_default_setting('updated_date', get_utc());

            // osm
            $this->set_default_setting('osm-enabled', 'yes');
            $this->set_default_setting('osm-single-product-page', 'yes');
            $this->set_default_setting('osm-loop-product-page', 'yes');
            $this->set_default_setting('osm-mini-cart-page', 'yes');
            $this->set_default_setting('osm-cart-page', 'yes');
            $this->set_default_setting('osm-checkout-page', 'yes');
            $this->set_default_setting('osm-order-receive-page', 'yes');
            $this->set_default_setting('osm-order-email', 'yes');
        }

        public function process_admin_options() {
			parent::process_admin_options();

            $this->set_default_settings();
			return update_option($this->get_option_key(), $this->settings, 'yes');
		}

        public function reset_settings() {
            $this->settings['api_key'] = '';
            $this->settings['merchant_id'] = '';
            $this->settings['campaigns'] = array();
            $this->settings['selected_campaign_id'] = '';
            $this->settings['campaign_mode'] = '';
            return update_option($this->get_option_key(), $this->settings, 'yes'); 
        }

        public function setup_merchant() {
            $api_key = $this->settings['api_key'];

            $this->log("start setup merchant");

            // get merchant id
            $merchant_resp = $this->get_merchant($api_key);
            if ($merchant_resp['status_code'] != 200) {
                self::log("get merchant fail when setup, " . json_encode($merchant_resp['body']));
                return $this->reset_settings();
            }
            $merchant_id = $merchant_resp['body']['data'][0]['merchantID'];
            $this->settings['merchant_id'] = $merchant_id;

            // set campaigns and earn miles rate
            $campaigns_resp = $this->get_campaigns($merchant_id, $api_key);
            if ($campaigns_resp['status_code'] != 200) {
                self::log("get campaigns fail when setup, " . json_encode($campaigns_resp));
                self::log("get campaigns fail when setup, " . json_encode($campaigns_resp['body']));
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
            $this->settings['updated_date'] = get_utc();

            $this->log("finished setup merchant");

            return update_option($this->get_option_key(), $this->settings, 'yes'); 
		}

        // only allow hong kong phone number
        public function custom_validate_billing_phone() {
            $is_correct = preg_match('/^[0-9]{8}$/', $_POST['billing_phone']);
            if ( $_POST['billing_phone'] && !$is_correct) {
                wc_add_notice( __( 'Please enter valid hong kong phone number' ), 'error' );
            }
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
            return intval(get_miles_by_reward_statement($reward_statement, $price));
        }

        public function get_product_miles($product) {
            if (empty($product)) return 0;
            $campaign = $this->get_product_campaign_by_id($product->get_id());
            if (empty($campaign)) return 0;
            $price = wc_get_price_to_display($product);
            $miles = $this->calc_earn_miles($price, $campaign);
            return $miles;
        }

        public function get_cart_total($cart) {
            $total = 0;
            foreach ( $cart->get_cart() as $cart_item ) {
                $total += $cart_item['line_subtotal'];
            }
            foreach ($cart->applied_coupons as $coupon) {
                $c = new WC_Coupon($coupon);
                $total -= $c->get_amount();
            }
            return $total;
        }

        public function get_cart_miles($cart) {
            if ($this->campaign_mode == self::CAMPAIGN_MODE_ORDER) {
                $total = $this->get_cart_total($cart);
                return $this->calc_earn_miles($total);
            } else if ($this->campaign_mode == self::CAMPAIGN_MODE_PRODUCT) {
                // ignore discount, only calcuate total miles,
                // to support product discount, calcuate the subtotal for each type of product
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
            if ($this->campaign_mode == self::CAMPAIGN_MODE_ORDER) {
                $order_subtotal = $order->get_subtotal();
                $order_discount = $this->get_order_discount($order);
                $total = $order_subtotal - $order_discount;
                // apply promo code
                $promo = WC()->session->get(self::DIVIT_PROMOTION_CAMPAIGN, array());
                if (!empty($promo)) {
                    return intval(get_miles_by_reward_statement($promo['rewardStatement'], $total));
                } else {
                    return $this->calc_earn_miles($total);
                }
            } else if ($this->campaign_mode == self::CAMPAIGN_MODE_PRODUCT) {
                // ignore discount, only calcuate total miles,
                // to support product discount, calcuate the subtotal for each type of product
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

        public function render_logo() {
            $static_url = $this->get_env()['static_url']; 
            echo '<div><img src="' . $static_url . '/brand/divit-logo-white.png" width="108.5px" height="35px" ?></div>';
        }

        public function render_miles_flag($miles) {
            if (empty($miles) || $miles <= 0) return;
            $static_url = $this->get_env()['static_url'];
            echo '<div class="divit_flag" style="display:inline-block;font-size:18px;line-height:28px;font-weight:bold;color: #1c1c1c;">';
            echo '<div class="divit_flag-inner" style="display:flex;flex-wrap:wrap;align-items:center;">';
            echo '<img style="display:inline-block;vertical-align:middle;margin:2px 4px;width:26.35px;height:26.35px;max-height:26.35px;" src="' . $static_url . '/miles/miles-icon.png" />';
            echo '<b> <span class="divit_miles" style="margin:0 5px;">' . $miles . '</span> <span style="margin:0 4px;">=</span></b>';
            echo '<img style="display:inline-block;vertical-align:middle;margin:2px 4px;width:17.67px;height:21.52px;max-height:21.52px;" src="' . $static_url . '/miles/miles-flag-am-colored-icon.png" />';
            echo '<b <span class="divit_miles" style="margin:0 5px;">' . $miles . '</span></b>';
            echo '</div>';
            echo '</div>';
        }

        public function render_miles_message($miles) {
            if (empty($miles) || $miles <= 0) return;
            $static_url = $this->get_env()['static_url'];
            echo '<div class="divit_message" style="display:flex;align-items:center;flex-wrap:wrap;margin:18px 0;font-size:18px;color:#1c1c1c;">';
            echo '<span>' . __('earn miles', 'woo-divit') . '</span>';
            echo '<span style="margin-right:5px;"></span>';
            echo $this->render_miles_flag($miles);
            // echo '<img style="display:inline-block;vertical-align:middle;margin:0 4px;width:24px;height:24px;" src="' . $static_url . '/miles/miles-icon.png" />';
            // echo '<b> <span class="divit_message_miles">' . $miles . '</span> miles.</b>';
            echo '</div>';
        }

        public function render_earned_miles_message($miles) {
            if (empty($miles) || $miles <= 0) return;
            echo '<div class="divit_message" style="margin:18px 0;font-size:16px;font-weight:bold;color:#1c1c1c;">';
            echo '<div>' . _e('You have just earned', 'woo-divit') . '</div>';
            echo '<div style="margin-top:8px;"></div>';
            echo $this->render_miles_flag($miles);
            echo '</div>';
        }

        public function render_claim_miles_button() {
            echo '<div class="divit_signup_btn_container">';
            echo '<a class="button" target="_blank" href="' . $this->get_env()['web_url'] . '/profile/miles">';
            echo __('sign up to claim miles', 'woo-divit');
            echo '</a>';
            echo '</div>';
        }

        public function shortcode_divit_earn_miles_paragraph() {
            if (!$this->is_earn_miles_enabled()) return;
            $product = get_product_from_the_post();
            if (empty($product)) return;
            $miles = $this->get_product_miles($product);
            $this->render_miles_message($miles);
        }

        public function render_shop_loop_product_earn_miles_message() {
            if (!$this->is_earn_miles_enabled()) return;
            if ($this->settings['osm-loop-product-page'] != 'yes') return;
            $product = get_product_from_the_post();
            $miles = $this->get_product_miles($product);
            echo '<div style="margin-top:5px;"></div>';
            $this->render_miles_flag($miles);
        }

        public function render_single_product_earn_miles_message() {
            if (!$this->is_earn_miles_enabled()) return;
            if ($this->settings['osm-single-product-page'] != 'yes') return;
            $product = get_product_from_the_post();
            if (empty($product)) return;
            $miles = $this->get_product_miles($product);
            // TODO: product variants
            if (false && $product->is_type('variable')) {
                $min_price = $product->get_variation_price('min', true);
                $max_price = $product->get_variation_price('max', true);
                ?>
                <script>
                    jQuery(function($){
                        $( ".single_variation_wrap" ).on( "show_variation", function ( event, variation ) {
                            var miles = variation.display_price / <?php echo $rate ?>;
                            $('.divit_message_miles').text(parseInt(miles, 10));
                        })
                        // $( '.reset_variations' ).on('click', function() {
                        //     var minMiles = '<?php echo $min_price / $rate ?>';
                        //     var maxMiles = '<?php echo $max_price / $rate ?>';
                        //     var miles = `${parseInt(minMiles, 10)} - ${parseInt(maxMiles, 10)}`
                        //     $('#divit_message_miles').text(miles);
                        // })
                    })
                </script>
                <?php
            }
            $this->render_miles_message($miles);
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
        public function render_checkout_earn_miles_message() {
            if (!$this->is_earn_miles_enabled()) return;
            if ($this->settings['osm-checkout-page'] != 'yes') return;
            $cart = WC()->cart;
            $miles = $this->get_cart_miles($cart);
            // apply promo code only in order campaign mode
            if ($this->campaign_mode == self::CAMPAIGN_MODE_ORDER) {
                $promo = WC()->session->get(self::DIVIT_PROMOTION_CAMPAIGN, array());
                if (!empty($promo)) {
                    $price = $this->get_cart_total($cart);
                    $miles = intval(get_miles_by_reward_statement($promo['rewardStatement'], $price));
                }
            }
            $this->render_miles_message($miles);
        }

        public function render_place_order_button_text($button_html) {
            $divit_logo_img = '<img style="width:80px;height:28.53px;margin-bottom:2px;" src="' . $this->get_env()['static_url'] . '/brand/divit-logo-colored.png" />';
            $button = '';
            $button .= '<button type="submit" class="button alt" style="background-color:white;border-radius:30px;border:1px solid #1C1C1C;" name="woocommerce_checkout_place_order" id="place_order">';
            $button .= '<span style="color:#1c1c1c;display:flex;justify-content:center;align-items:center;">';
            $button .= sprintf(__(' %3$s %1$scheckout%2$s', 'woo-divit'), '<span style="font-size:16px;margin-top:8px;margin-left:8px;">', '</span>', $divit_logo_img);
            $button .= '</button>';
            ?>
            <script>
                jQuery(function ($) {
                    function checkPaymentMethod() {
                        $('.place-order button[type="submit"]').remove();
                        var paymentMethod = $('form[name="checkout"] input[name="payment_method"]:checked');
                        if(paymentMethod.val() == "<?php echo $this->id; ?>") {
                            $('.place-order').append('<?php echo $button ?>');
                        } else{
                            $('.place-order').append('<?php echo $button_html ?>');
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
                echo $button;
            } else {
                echo $button_html;
            }
        }

        public function get_miles_in_order($divit_order) {
            $txns = $divit_order['milesTransactions'];
            if (empty($txns)) return 0;
            $miles = 0;
            foreach($txns as $txn) {
                $miles_earned = $txn['targetAmount']['amount'];
                $miles += intval($miles_earned / 100);
            }
            return $miles;
        }

        public function render_thank_you_earn_miles_message($thank_you_title, $order) {
            if (!$this->is_earn_miles_enabled()) return;
            if ($this->settings['osm-order-receive-page'] != 'yes') return;

            // get miles from server
            $divit_order_id = get_post_meta($order->get_id(), 'divit_order_id', true);
            $divit_order_resp = $this->get_order($divit_order_id, $this->api_key);
            if ($divit_order_resp['status_code'] != 200) return;

            $divit_order = $divit_order_resp['body']['data'];
            $miles = $this->get_miles_in_order($divit_order);
            if ($miles > 0) {
                echo $thank_you_title;
                $this->render_earned_miles_message($miles);
                $this->render_claim_miles_button();
            } else {
                echo $thank_you_title;
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
            $miles = $this->get_miles_in_order($divit_order);
            if ($miles > 0) {
                // need inline css for email
                echo '<div style="margin-bottom:45px;text-align:center;">';
                // $this->render_logo();
                $this->render_earned_miles_message($miles);
                echo '<div style="text-align:center;">';
                echo '<a style="font-size:16px;font-weight:bold;text-decoration:none;padding:10px 20px;background-color:black;color:white;" target="_blank" href="' . $this->get_env()['web_url'] . '/profile/miles">sign up to claim miles</a>';
                echo '</div>';
                echo '</div>';
            }
        }

        public function campaigns_to_options($campaigns) {
            $options = array_reduce(
                $campaigns, 
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
                'label'		=> __( 'Divit', 'woo-divit' ),
                'target'	=> 'divit_options',
                'class'		=> array( 'show_if_simple', 'show_if_variable'  ),
            );
            return $tabs;
        }

        public function divit_product_data_tabs_content() {
            $options = array_merge(array('' => 'none'), $this->campaigns_to_options($this->campaigns));
            echo '<div id="divit_options" class="panel woocommerce_options_panel hidden">';
            echo '<p>if you want to specify reward for each product, please switch campaign mode to "product mode" in plugin settings.</p>';
            // echo json_encode($options);
            woocommerce_wp_select( array(
                'id'          => self::META_PRODUCT_CAMPAIGN,
                'value'       => get_post_meta( get_the_ID(), self::META_PRODUCT_CAMPAIGN, true ),
                'wrapper_class' => '',
                'label'       => 'Campaign',
                'options'     => $options,
            ) );
            echo '</div>';
        }

        public function divit_product_data_option_fields( $post_id ) {
            if ( isset( $_POST[self::META_PRODUCT_CAMPAIGN] ) ) {
                update_post_meta( $post_id, self::META_PRODUCT_CAMPAIGN, $_POST[self::META_PRODUCT_CAMPAIGN] );
            }
        }

        public function get_env() {
            return $this->environments[$this->settings['mode']];
        }
        
        public function init_form_fields()
        {
            $form_fields = array();

            if (isset($_GET['panel']) && $_GET['panel'] == 'on-site-messaging') {
                $form_fields = array_merge($form_fields, array(
                    'osm-enabled' => array(
                        'title' => __('Enable on-site messging', 'woo-divit'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                    'osm-single-product-page' => array(
                        'title' => __( 'Display miles tag in pages', 'woo-divit'),
                        'label' => __('Display miles in single product page', 'woo-divit'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                    'osm-loop-product-page' => array(
                        'type' => 'checkbox',
                        'label' => __('Display miles in loop product page', 'woo-divit'),
                        'default' => 'yes',
                    ),
                    'osm-mini-cart-page' => array(
                        'label' => __('Display miles in mini cart page', 'woo-divit'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                    'osm-cart-page' => array(
                        'label' => __('Display miles in cart page', 'woo-divit'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                    'osm-checkout-page' => array(
                        'label' => __('Display miles in checkout page', 'woo-divit'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                    'osm-order-receive-page' => array(
                        'label' => __('Display miles in order receive page', 'woo-divit'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                    'osm-order-email' => array(
                        'label' => __('Display miles in order email', 'woo-divit'),
                        'type' => 'checkbox',
                        'default' => 'yes',
                    ),
                ));
            }

            if (!isset($_GET['panel']) || (isset($_GET['panel']) && $_GET['panel'] == 'payment')) {
                $form_fields = array_merge($form_fields, array(
                    'enabled' => array(
                        'title' => __('Enable', 'woo-divit'),
                        'type' => 'checkbox',
                        'label' => __('Enable divit payment', 'woo-divit'),
                        'default' => 'yes',
                    ),
                    'mode' => array(
                        'title' => __('Environment', 'woo-divit'),
                        'type' => 'select',
                        'options' => wp_list_pluck($this->environments, 'name'),
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
                    'api_key' => array(
                        'title' => 'Api key',
                        'type' => 'password',
                        'default' => '',
                        'description' => 'api key for divit merchant',
                    ),
                    'campaign_mode' => array(
                        'title' => 'Campaign mode',
                        'type' => 'select',
                        'options' => array(
                            self::CAMPAIGN_MODE_ORDER => 'order (base on order total)',
                            self::CAMPAIGN_MODE_PRODUCT => 'product (base on product campaign)',
                        ),
                        'default' => self::CAMPAIGN_MODE_ORDER,
                    ),
                    'selected_campaign_id' => array(
                        'title' => 'Order campaign',
                        'type' => 'select',
                        'options' => $this->campaigns_to_options($this->settings['campaigns']),
                        'default' => '',
                        // 'custom_attributes'	=> $this->settings['campaign_mode'] == self::CAMPAIGN_MODE_ORDER ? [] : array('disabled' => 'false'),
                        'description' => 'only available in order campaign mode',
                    )
                ));
            }

            $this->form_fields = $form_fields;
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
            $panel = isset($_GET['panel']) ? $_GET['panel'] : 'payment';
            $environment = $this->mode;
            $merchant_id = $this->merchant_id;
            $campaign_mode = $this->campaign_mode;
            $selected_campaign = $this->selected_campaign;
            $updated_date = $this->settings['updated_date'];
            $loggedin = !empty($this->merchant_id);
    ?>
            <?php echo $loggedin ? 
                '<div class="notice notice-success notice-alt"><h4>Setup account done</h4></div>' : 
                '<div class="notice notice-error notice-alt"><h4>Require setup account</h4></div>'
            ?>

            <?php if ($loggedin) { ?>
                <h3 class="wc-settings-sub-title">Divit Account</h3>
                <div><b>Environment: </b><?php echo $environment ?></div>
                <div><b>Merchant id: </b><?php echo $merchant_id ?></div>
                <div><b>Campaign mode: </b><?php echo $campaign_mode ?></div>
                <?php if ($campaign_mode == self::CAMPAIGN_MODE_ORDER) { ?>
                    <div><b>Order campaign: </b><?php echo !empty($selected_campaign) ? $selected_campaign['title'] : 'none' ?></div>
                <?php } ?>
                <div><b>Last updated: </b><?php echo $updated_date ?></div>
            <?php } ?>

            <h3></h3>

            <h2 class="nav-tab-wrapper">
                <a 
                    href="?page=wc-settings&tab=checkout&section=divit&panel=payment" 
                    class="nav-tab <?php echo $panel == 'payment' ? 'nav-tab-active' : '' ?>"
                >
                    Divit Payment
                </a>
                <a 
                    href="?page=wc-settings&tab=checkout&section=divit&panel=on-site-messaging" 
                    class="nav-tab <?php echo $panel == 'on-site-messaging' ? 'nav-tab-active' : '' ?>"
                >
                    On-site messaging
                </a>
            </h2>

            <!-- <p>post data: <?php echo json_encode($this->get_post_data()) ?></p> -->
            <!-- <p>settings: <?php echo json_encode($this->settings) ?></p> -->
            <!-- <p>form fields: <?php echo json_encode($this->get_form_fields()) ?></p> -->
            <table class="form-table">
                <input hidden name="panel" value="<?php echo $panel ?>" />
                <?php $this->generate_settings_html() ?>
            </table>
    <?php
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
            $order_total_for_reward = ($order_subtotal - $order_discount) * 100;
            $order_total = $order->get_total() * 100;
            $order_currency = $order->get_currency();

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
                // testing
                // "webhookEvents" => 'https://72a0-218-253-146-226.jp.ngrok.io' . '?wc-api=CALLBACK_DIVIT_PAYMENT',
                "webhookEvents" => get_site_url() . '?wc-api=CALLBACK_DIVIT_PAYMENT',
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

            // TODO: cancel previous order
            $order->update_meta_data('divit_order_id', $create_order_id);
            $order->save();

            // promotion code for customer add
            // $promo = WC()->session->get(self::DIVIT_PROMOTION_CAMPAIGN, array());
            // $promotion_code = '';
            // if (!empty($promo)) {
            //     $promotion_code = $promo['promoCode'];
            //     $apply_promotion_code_resp = $this->apply_promotion_code($create_order_id, $promotion_code, $create_order_token);
            //     if ($apply_promotion_code_resp['status_code'] != 200) {
            //         self::log('apply promo code error: ' . $promotion_code . ', ', json_decode($apply_promotion_code_resp['body']));
            //         wc_add_notice('cannot apply promotion code, please contact customer services', 'error');
            //         return;
            //     }
            // }
            // $order->update_meta_data('divit_promotion_code', $promotion_code);
            // $order->save();

            return array(
                'result' => 'success',
                'redirect' => $redirect_uri . '&token=' . $create_order_token,
            );
        }
        
        public function payment_fields()
        {
            if (!$this->is_earn_miles_enabled()) {
                echo $this->description;
                return;
            }
            $promotion_code = WC()->session->get(self::DIVIT_PROMOTION_CODE_INPUT, '');
            $valid_promo = WC()->session->get(self::DIVIT_VALID_PROMO_CODE, '');
            // $promo = WC()->session->get(self::DIVIT_PROMOTION_CAMPAIGN, array());
    ?>
            <p class="payment_method_<?php echo $this->id ?>_desc">
                <?php $this->render_checkout_earn_miles_message() ?>
                <?php
                    if ($this->campaign_mode == self::CAMPAIGN_MODE_ORDER):
                        echo '<div style="margin-top:30px;"></div>';
                        woocommerce_form_field( 'divit_promotion_code', array(
                            'type'          => 'text',
                            'class'         => array('transaction_type form-row-wide'),
                            'label'         => __('Promotion code', 'woo-divit'),
                            'description'   => '',
                        ), $promotion_code);
                ?>
                <div id="divit_promotion_code_submit_success" style="color:green;">
                    <?php echo $valid_promo == 'true' ? __('applied code', 'woo-divit') : '' ?>
                </div>
                <div id="divit_promotion_code_submit_error" style="color:red;">
                    <?php echo $valid_promo == 'false' ? __('invalid code', 'woo-divit') : '' ?>
                </div>
                <div id="divit_promotion_code_submit" class="button" style="margin-top: 10px;">
                    <?php _e('Apply', 'woo-divit') ?>
                </div>
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
                                    // update checkout will call this method again, so server render status
                                },
                                error: function(MLHttpRequest, textStatus, errorThrown){
                                    $(document.body).trigger("update_checkout");
                                }
                            })
                        })
                    })
                </script>
                <?php endif ?>
            </p>
    <?php
        }

        // apply promotion code to session in checkout page
        public function divit_apply_promotion_code() {
            $promotion_code = $_POST['promotion_code'];

            // reset
            WC()->session->set(self::DIVIT_PROMOTION_CODE_INPUT, '');
            WC()->session->set(self::DIVIT_PROMOTION_CAMPAIGN, array());

            if (empty($promotion_code)) {
                WC()->session->set(self::DIVIT_PROMOTION_CODE_INPUT, '');
                WC()->session->set(self::DIVIT_VALID_PROMO_CODE, '');
                wp_send_json_success('clear promotion code');
                die(); 
            }

            $promotion_code = trim($promotion_code);

            $resp = $this->get_promotion_code($this->merchant_id, $promotion_code, $this->api_key);
            if ($resp['status_code'] != 200 || empty($resp['body']['data']['promoCode'])) {
                WC()->session->set(self::DIVIT_PROMOTION_CODE_INPUT, '');
                WC()->session->set(self::DIVIT_VALID_PROMO_CODE, 'false');
                wp_send_json_error('invalid promotion code, '. $resp['body']['data']['promoCode']);
                die();
            }
            WC()->session->set(self::DIVIT_PROMOTION_CODE_INPUT, $promotion_code);
            WC()->session->set(self::DIVIT_PROMOTION_CAMPAIGN, $resp['body']['data']);
            WC()->session->set(self::DIVIT_VALID_PROMO_CODE, 'true');
            wp_send_json_success('applied');
            die();
        }

        // logging for "WooCommerce > System Status > Logs" in the WordPress admin
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
            $headers = array('content-type: application/x-www-form-urlencoded');
            return http_request($url, 'POST', $body, $headers);
        }

        public function get_merchant($api_key) {
            $url = $this->get_env()['api_url'] . '/partners';
            $header = array('content-type: application/json', 'API-Key: ' . $api_key);
            return http_request($url, 'GET', array(), $header);
        }

        public function create_order($create_order, $api_key) {
            $url = $this->get_env()['api_url'] . '/paynow/orders/fast';
            $header = array('content-type: application/json', 'API-Key: ' . $api_key);
            return http_request($url, 'POST', $create_order, $header);
        }

        public function get_order($order_id, $api_key) {
            $url = $this->get_env()['api_url'] . '/paynow/orders/' . $order_id;
            $header = array('content-type: application/json', 'API-Key: ' . $api_key);
            return http_request($url, 'GET', array(), $header);
        }

        public function get_campaigns($merchant_id, $api_key) {
            $url = $this->get_env()['api_url'] . '/campaign/merchants/' . $merchant_id;
            $header = array('content-type: application/json', 'API-Key:' . $api_key);
            return http_request($url, 'GET', array(), $header);
        }

        public function get_promotion_code($merchant_id, $promotion_code, $api_key) {
            $url = $this->get_env()['api_url'] . '/campaign/merchants/' . $merchant_id . '/promo/' . $promotion_code;
            $header = array('content-type: application/json', 'API-Key: ' . $api_key);
            return http_request($url, 'GET',  array(), $header);
        }

        public function apply_promotion_code($order_id, $promotion_code, $api_key) {
            $url = $this->get_env()['api_url'] . '/paynow/fast/orders/' . $order_id . '/promo';
            $header = array('content-type: application/json', 'API-Key: ' . $api_key);
            $body = array('promoCode' => $promotion_code);
            return http_request($url, 'PATCH', $body, $header);
        }

        public function callback_handler() {
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
            $valid = validate_webhook($signature, $this->api_key, $resp);
            if (!$valid) {
                $message = "callback: signature valid error, merchant reference {$merchant_ref}";
                self::log($message);
                wp_send_json($message);
                exit;
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
            // $order_id = get_order_by_order_number($merchant_ref);

            $order_id = $merchant_ref;
            $order = wc_get_order($order_id);

            // cannot find order
            if (!$order) {
                $message = "callback: cannot find order by merchant reference {$merchant_ref}";
                self::log($message);
                wp_send_json($message);
                exit;
            }

            // update order status to processing
            $order->payment_complete();

            // Reduce stock levels
            wc_reduce_stock_levels($order_id);

            // we can't clear customer cart here, cuz this method call by server

            $message = "callback: complete order, merchant reference {$merchant_ref}";
            self::log($message);
            wp_send_json($message);
            exit;
        }

        public function order_received_action($order_id) {
            self::log('order received: ' . $order_id);

            // clear cart
            WC()->cart->empty_cart();

            // clear session
            WC()->session->set(self::DIVIT_PROMOTION_CODE_INPUT, '');
            WC()->session->set(self::DIVIT_PROMOTION_CAMPAIGN, array());
            WC()->session->set(self::DIVIT_VALID_PROMO_CODE, '');
        }
    }
}