<?php
/** 
 * Plugin Name:       Divit Rewards and Payment Gateway
 * Plugin URI:        https://developer.divit.com.hk/docs/platforms/woocommerce/overview/
 * Description:       Divit Rewards and Payment Gateway
 * Version:           1.4.2
 * WC requires at least: 3.0
 * WC tested up to: 5.5
 * Author:            Divit team
 * Author URI:        https://divit.com.hk
 * Text Domain:       wc-divit-payment-gateway
 * Domain Path:       /languages
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

require_once('utils.php');

define('DIVIT_PLUGIN_VERSION', '1.4.2');

if (!class_exists('Divit_Plugin')) {
	class Divit_Plugin {

        protected static $instance;

        public static function init()
		{
            require_once dirname(__FILE__) . '/divit-plugin-cron.php';
            if (class_exists('WC_Payment_Gateway')) {
				require_once dirname(__FILE__) . '/wc-divit-payment-gateway.php';
			}

			if (is_null(self::$instance)) {
				self::$instance = new self;
			}
			return self::$instance;
		}

        public static function activate_plugin() {
            if (!current_user_can( 'activate_plugins' )) {
				return;
			}

            self::init();
            // Divit_Plugin_Cron::create_jobs();
        }

        public static function deactivate_plugin()
		{
			if (!current_user_can( 'activate_plugins' )) {
				return;
			}

			self::init();
			// Divit_Plugin_Cron::delete_jobs();
		}

        public static function uninstall_plugin()
		{
			if (!current_user_can( 'activate_plugins' )) {
				return;
			}
		}

        public function __construct() {

            $gateway = WC_Divit_Payment_Gateway::getInstance();

            if ($this->custom_payment_is_woocommerce_active()) {
                // register payment gateway
                add_filter('woocommerce_payment_gateways', array($this, 'add_payment_gateway'));
                
                // add scripts
                add_action('wp_enqueue_scripts', array( $this, 'divit_enqueue_script'));
                add_filter( 'woocommerce_gateway_icon', array( $this, 'filter_woocommerce_gateway_icon' ), 10, 2 );

                // woocommerce hooks
                add_action( 'woocommerce_after_shop_loop_item_title', array($gateway, 'render_shop_loop_product_earn_miles_message'), 40);
                add_action( 'woocommerce_before_add_to_cart_button', array($gateway, 'render_single_product_earn_miles_message'), 10, 0 );
                add_action( 'woocommerce_after_add_to_cart_form', array($gateway, 'render_single_product_express_checkout_form'), 10, 0 );
                add_action( 'woocommerce_widget_shopping_cart_total', array($gateway, 'render_mini_cart_earn_miles_message'), 20, 0);
                add_action( 'woocommerce_proceed_to_checkout', array($gateway, 'render_cart_earn_miles_message'), 10, 0 );
                add_filter( 'woocommerce_order_button_html', array($gateway, 'render_place_order_button_text') );
                // add_action( 'woocommerce_review_order_after_order_total', array($gateway, 'render_checkout_earn_miles_message'), 10, 0);
                add_filter( 'woocommerce_thankyou_order_received_text', array($gateway, 'render_thank_you_earn_miles_message'), 20, 2 );
                add_action( 'woocommerce_email_after_order_table', array($gateway, 'render_order_received_email_earn_miles_message'), 10, 1 );
                add_action( 'woocommerce_thankyou', array($gateway, 'order_received_action'), 10, 1 );

                // shortcode
                add_shortcode( 'divit_earn_miles_tag', array( $gateway, 'shortcode_divit_earn_miles_tag' ) );
                add_shortcode( 'divit_earn_miles_tag_product_in_cart', array( $gateway, 'shortcode_divit_earn_miles_tag_product_in_cart' ) );
                add_shortcode( 'divit_earn_miles_tag_in_cart', array( $gateway, 'shortcode_divit_earn_miles_tag_in_cart' ) );
                add_shortcode( 'divit_login_button', array( $gateway, 'shortcode_divit_login_button' ) );

                // cron
                // add_action( 'divit_cron_jobs', array('Divit_Plugin_Cron', 'fire_jobs'), 10, 0 );
                // add_filter( 'cron_schedules', array('Divit_Plugin_Cron', 'edit_cron_schedules'), 10, 1 );

                // admin page
                add_filter( 'woocommerce_product_data_tabs', array($gateway, 'divit_product_data_tabs') );
                add_filter( 'woocommerce_product_data_panels', array($gateway, 'divit_product_data_tabs_content') );
                add_action( 'woocommerce_process_product_meta', array($gateway, 'divit_product_data_option_fields'), 10, 2 );
                add_action( 'woocommerce_process_product_meta', array($gateway, 'divit_product_data_option_fields'), 10, 2  );
                add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array($this, 'filter_action_links'), 10, 1 );

                // nav items
                add_filter( 'wp_nav_menu_items', array($gateway, 'divit_render_nav_login_item'), 10, 2);
                
                // translation
                load_plugin_textdomain('wc-divit-payment-gateway', FALSE, basename(dirname(__FILE__)) . '/languages/');
            }
        }

        public function add_payment_gateway($gateways)
        {
            $gateways[] = 'WC_Divit_Payment_Gateway';
            return $gateways;
        }

        public function divit_enqueue_script() {
            global $wp;
            $gateway = WC_Divit_Payment_Gateway::getInstance();

            wp_enqueue_style('rangeslider-style', plugins_url('assets/css/divit-gateway.css', __FILE__), array(), DIVIT_PLUGIN_VERSION);
            wp_enqueue_style('divit-gateway-style', plugins_url('assets/css/rangeslider.css', __FILE__), array(), DIVIT_PLUGIN_VERSION);

            wp_enqueue_script('rangeslider', plugins_url('assets/js/rangeslider.min.js', __FILE__) , array( 'jquery') , DIVIT_PLUGIN_VERSION, true);
            wp_enqueue_script('divit-gateway', plugins_url('assets/js/divit-gateway.js', __FILE__) , array( 'jquery', 'rangeslider') , DIVIT_PLUGIN_VERSION, true);

            wp_localize_script('divit-gateway', 'wcdivit', array(
                'ajaxUrl' => admin_url("admin-ajax.php"),
                'autoFillAddressEnabled' => $gateway->settings['sso-auto-fill-address'] ?? false,
                'isCheckoutPage' => is_checkout() && empty( $wp->query_vars['order-pay'] ) && ! isset( $wp->query_vars['order-received'] )
            ));
        }

        public function custom_payment_is_woocommerce_active()
        {
            $active_plugins = (array) get_option('active_plugins', array());
            
            if (is_multisite()) {
                $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
            }
            
            return in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
        }

        public function filter_woocommerce_gateway_icon($icon_html, $gateway_id) {
            $gateway = WC_Divit_Payment_Gateway::getInstance();

            if ($gateway_id != $gateway->id) {
                return $icon_html;
            }
            
            ob_start();

            ?><img src="<?php echo esc_attr(WC_Divit_Payment_Gateway::getInstance()->get_env()['static_url']); ?>/brand/fps-by-divit.png" width="97.2px" height="25px" alt="Divit" /><?php

            return ob_get_clean();
        }

        public function filter_action_links($links) {
            $new = array(
                sprintf(
                    '<a href="%s">%s</a>',
                    esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=divit' ) ),
                    esc_html__( 'Settings', 'wc-divit-payment-gateway' )
                )
            );

            return array_merge( $new, $links );
        }
    }

    register_activation_hook( __FILE__, array('Divit_Plugin', 'activate_plugin') );
	register_deactivation_hook( __FILE__, array('Divit_Plugin', 'deactivate_plugin') );
	register_uninstall_hook( __FILE__, array('Divit_Plugin', 'uninstall_plugin') );

    add_action( 'plugins_loaded', array('Divit_Plugin', 'init'), 10, 0 );
}