<?php
/* @wordpress-plugin
 * Plugin Name:       WooCommerce divit payment gateway
 * Plugin URI:        https://divit.com.hk
 * Description:       Divit payment gateway for woocommerce
 * Version:           0.0.3
 * WC requires at least: 3.0
 * WC tested up to: 5.5
 * Author:            Divit team
 * Author URI:        https://divit.com.hk
 * Text Domain:       woocommerce-divit-payment-gateway
 * Domain Path: /languages
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

$active_plugins = apply_filters('active_plugins', get_option('active_plugins'));
if (custom_payment_is_woocommerce_active()) {
    add_filter('woocommerce_payment_gateways', 'add_other_payment_gateway');
    function add_other_payment_gateway($gateways)
    {
        $gateways[] = 'WC_Divit_Payment_Gateway';
        return $gateways;
    }
    
    add_action('plugins_loaded', 'init_other_payment_gateway');
    function init_other_payment_gateway()
    {
        require 'wc-divit-payment-gateway.php';
    }
    
    // add_action('plugins_loaded', 'other_payment_load_plugin_textdomain');
    // function other_payment_load_plugin_textdomain()
    // {
    //     load_plugin_textdomain('woocommerce-other-payment-gateway', FALSE, basename(dirname(__FILE__)) . '/languages/');
    // }
}

/**
 * @return bool
 */
function custom_payment_is_woocommerce_active()
{
    $active_plugins = (array) get_option('active_plugins', array());
    
    if (is_multisite()) {
        $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
    }
    
    return in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
}