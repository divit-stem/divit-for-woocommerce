<?php
defined( 'ABSPATH' ) || exit;
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Divit_Gateway_Blocks_Support extends AbstractPaymentMethodType {
	
	private $gateway;
	protected $name = 'divit';

	public function initialize() {
		$gateways = WC()->payment_gateways->payment_gateways();
		$this->gateway  = $gateways[ $this->name ];
    $this->settings = $this->gateway->settings;
	}

	public function is_active() {
		return ! empty( $this->settings[ 'enabled' ] ) && 'yes' === $this->settings[ 'enabled' ];
	}

	public function get_payment_method_script_handles() {

		wp_register_script(
			'wc-divit-blocks-integration',
      plugins_url('assets/js/divit-gateway-blocks.js', __FILE__),
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
			),
			DIVIT_PLUGIN_VERSION,
			true
		);

		return array( 'wc-divit-blocks-integration' );

	}

	public function get_payment_method_data() {
		return array(
      'title' => $this->gateway->title,
      'description' => $this->gateway->description,
      'icon' => plugins_url('assets/images/fps_by_divit.png', __FILE__),
			'supports'  => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ),
			'total' => get_woocommerce_currency_symbol() . number_format((float) WC()->cart->total, wc_get_price_decimals()),
			'pay_now_text' => __( 'pay now', 'wc-divit-payment-gateway' ),
		);
	}
}
