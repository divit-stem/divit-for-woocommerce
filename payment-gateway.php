<?php
/** 
 * Plugin Name:       Divit Payment Gateway
 * Plugin URI:        https://developer.divit.com.hk/docs/platforms/woocommerce/overview/
 * Description:       Divit Payment Gateway for WooCommerce
 * Version:           1.8.0
 * WC requires at least: 5.0
 * WC tested up to:   9.7.1
 * Author:            Divit team
 * Author URI:        https://divit.com.hk
 * Text Domain:       wc-divit-payment-gateway
 * Domain Path:       /languages
 */
defined( 'ABSPATH' ) || exit;

require_once('utils.php');

define('DIVIT_PLUGIN_VERSION', '1.8.0');

if (!class_exists('Divit_Plugin')) {
	class Divit_Plugin {

        public static $log = false;
        protected static $instance;

        public static function init()
		{
            if (class_exists('WC_Payment_Gateway')) {
				require_once dirname(__FILE__) . '/wc-divit-payment-gateway.php';
			} else {
                self::log('failed to find WC_Payment_Gateway class');
            }

			if (is_null(self::$instance)) {
				self::$instance = new self;
			}
			return self::$instance;
		}

        public static function activate_plugin() {
            if (!current_user_can( 'activate_plugins' ) || !class_exists('WC_Payment_Gateway')) {
                if (!class_exists('WC_Payment_Gateway')) {
                    self::log('failed to find WC_Payment_Gateway class');
                }
				return;
			}

            self::init();

            $gateway = WC_Divit_Payment_Gateway::getInstance();
            $gateway->setup_merchant();
        }

        public static function deactivate_plugin()
		{
			if (!current_user_can( 'activate_plugins' )) {
				return;
			}

			self::init();
		}

        public static function uninstall_plugin()
		{
			if (!current_user_can( 'activate_plugins' )) {
				return;
			}
		}

        public function __construct() 
        {
            if ($this->custom_payment_is_woocommerce_active()) {
                add_action( 'before_woocommerce_init', function() {
                    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
                        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
                        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
                    }
                } );
                
                // register payment gateway
                add_filter('woocommerce_payment_gateways', array($this, 'add_payment_gateway'));
                add_action( 'woocommerce_blocks_loaded', array($this, 'divit_gateway_block_support') );
                
                // add scripts
                add_action( 'wp_enqueue_scripts', array( $this, 'divit_enqueue_script'));
                add_action( 'admin_enqueue_scripts', array( $this, 'divit_admin_enqueue_script'));
                add_filter( 'woocommerce_gateway_icon', array( $this, 'filter_woocommerce_gateway_icon' ), 10, 2 );

                add_action( 'woocommerce_thankyou', function($order_id) {
                    $gateway = WC_Divit_Payment_Gateway::getInstance();
                    $gateway->order_received_action($order_id);
                }, 10, 1 );

                // admin page
                add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array($this, 'filter_action_links'), 10, 1 );
                
            }

            // translation
            add_action( 'init', array( $this, 'load_textdomain' ) );
        }

        public function load_textdomain()
        {
            load_plugin_textdomain( 'wc-divit-payment-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' ); 
        }

        public function add_payment_gateway($gateways)
        {
            $gateways[] = 'WC_Divit_Payment_Gateway';
            return $gateways;
        }
        
        public function divit_gateway_block_support()
        {
            require_once dirname(__FILE__) . '/wc-divit-payment-gateway-blocks-support.php';

            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                    $payment_method_registry->register( new WC_Divit_Gateway_Blocks_Support );
                }
            );
        }

        public function divit_enqueue_script() 
        {
            global $wp;
            $gateway = WC_Divit_Payment_Gateway::getInstance();

            wp_enqueue_style('divit-gateway-style', plugins_url('assets/css/divit-gateway.css', __FILE__), array(), DIVIT_PLUGIN_VERSION);

            wp_enqueue_script('divit-gateway', plugins_url('assets/js/divit-gateway.js', __FILE__) , array( 'jquery') , DIVIT_PLUGIN_VERSION, true);

            $config_data = $gateway->get_divit_config_json();

            // custom js for frontend usage
            wp_localize_script('divit-gateway', 'wcdivit', array(
                'ajaxUrl' => admin_url("admin-ajax.php"),
                'fpsGuideURL' => $config_data->fpsGuideURL ?? '',
            ));
        }

        public function divit_admin_enqueue_script() 
        {
            global $wp;
            $gateway = WC_Divit_Payment_Gateway::getInstance();

            wp_enqueue_script('divit-gateway-admin', plugins_url('assets/js/divit-gateway-admin.js', __FILE__) , array( 'jquery' ) , DIVIT_PLUGIN_VERSION, true);

            // custom js for frontend usage
            wp_localize_script('divit-gateway-admin', 'wcdivit', array(
                'ajaxUrl' => admin_url("admin-ajax.php"),
                'installationStatus' => $gateway->settings['installationStatus'] ?? 'no',
                'nonce' => wp_create_nonce('divit_admin_nonce'),
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

        // show divit icon in payment gateway list
        public function filter_woocommerce_gateway_icon($icon_html, $gateway_id) {
            $gateway = WC_Divit_Payment_Gateway::getInstance();

            if ($gateway_id != $gateway->id) {
                return $icon_html;
            }
            
            $icon_url = plugins_url('assets/images/fps_by_divit.png', __FILE__);

            return sprintf(
                '<img id="divit_checkout_icon" src="%s" width="97.2px" height="25px" alt="Divit" />',
                esc_url($icon_url)
            );
        }

        // show setting in plugin listing page
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
    }

    register_activation_hook( __FILE__, array('Divit_Plugin', 'activate_plugin') );
	register_deactivation_hook( __FILE__, array('Divit_Plugin', 'deactivate_plugin') );
	register_uninstall_hook( __FILE__, array('Divit_Plugin', 'uninstall_plugin') );

    add_action( 'plugins_loaded', array('Divit_Plugin', 'init'), 10, 0 );
}