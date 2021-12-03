<?php
/* @wordpress-plugin
 * Plugin Name:       Divit payment gateway for WooCommerce
 * Plugin URI:        https://divit.com.hk
 * Description:       Divit payment gateway & on-site messaging
 * Version:           0.0.14
 * WC requires at least: 3.0
 * WC tested up to: 5.5
 * Author:            Divit team
 * Author URI:        https://divit.com.hk
 * Text Domain:       woocommerce-divit-payment-gateway
 * Domain Path: /languages
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

require_once('utils.php');

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
            Divit_Plugin_Cron::create_jobs();
        }

        public static function deactivate_plugin()
		{
			if (!current_user_can( 'activate_plugins' )) {
				return;
			}

			self::init();
			Divit_Plugin_Cron::delete_jobs();
		}

        public function __construct() {

            $gateway = WC_Divit_Payment_Gateway::getInstance();

            $active_plugins = apply_filters('active_plugins', get_option('active_plugins'));
            if ($this->custom_payment_is_woocommerce_active()) {
                // register payment gateway
                add_filter('woocommerce_payment_gateways', array($this, 'add_payment_gateway'));
                
                // add scripts
                add_action( 'wp_enqueue_scripts', array($this, 'init_website_assets'), 10, 0 );
                add_filter( 'woocommerce_gateway_icon', array( $this, 'filter_woocommerce_gateway_icon' ), 10, 2 );

                // check agreement
                add_action( 'woocommerce_review_order_before_submit', array($gateway, 'custom_checkout_field'), 10 );
                add_action( 'woocommerce_checkout_process', array($gateway, 'add_checkout_checkbox_warning' ));

                // woocommerce hooks
                add_action( 'woocommerce_before_add_to_cart_form', array($gateway, 'redner_single_product_earn_miles_message'), 10, 0 );
                add_action( 'woocommerce_proceed_to_checkout', array($gateway, 'render_cart_earn_miles_message'), 10, 0 );
                // add_action( 'woocommerce_review_order_after_order_total', array($gateway, 'render_checkout_earn_miles_message'), 10, 0);
                add_filter( 'woocommerce_thankyou_order_received_text', array($gateway, 'render_thank_you_earn_miles_message'), 20, 2 );
                add_action( 'woocommerce_email_after_order_table', array($gateway, 'render_order_received_email_earn_miles_message'), 10, 1 );
                add_action( 'woocommerce_thankyou', array($gateway, 'order_received_action'), 10, 1 );

                // shortcode
                add_shortcode( 'divit_earn_miles_paragraph', array( $gateway, 'shortcode_divit_earn_miles_paragraph' ) );

                // cron
                add_action( 'divit_do_cron_jobs', array('Divit_Plugin_Cron', 'fire_jobs'), 10, 0 );
                add_filter( 'cron_schedules', array('Divit_Plugin_Cron', 'edit_cron_schedules'), 10, 1 );
                
                // translation
                // load_plugin_textdomain('woocommerce-other-payment-gateway', FALSE, basename(dirname(__FILE__)) . '/languages/');
            }
        }

        public function add_payment_gateway($gateways)
        {
            $gateways[] = 'WC_Divit_Payment_Gateway';
            return $gateways;
        }

        public function init_website_assets() {
            wp_enqueue_style( 'divit_css', plugins_url( 'css/divit.css', __FILE__ ), array(), time() );
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
            if ($gateway_id != 'divit') {
                return $icon_html;
            }
            
            ob_start();

            ?><img src="<?php echo WC_Divit_Payment_Gateway::getInstance()->get_env()['static_url'] ?>/brand/divit-logo-white.png" width="77.5px" height="25px" alt="Divit" /><?php

            return ob_get_clean();
        }

    }

    add_action( 'plugins_loaded', array('Divit_Plugin', 'init'), 10, 0 );
}
