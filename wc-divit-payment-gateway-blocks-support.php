<?php
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
      'icon' => $this->gateway->get_env()['static_url'] . '/brand/fps-by-divit.png',
			'supports'  => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ),
		);
	}
}

// function divit_checkout_blocks_compatibility() 
// {
//     if( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
//         \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
//                 'cart_checkout_blocks',
//                 __FILE__,
//                 true,
//                 // false // true (compatible, default) or false (not compatible)
//             );
//     }
// }

// add_action( 'before_woocommerce_init', 'divit_checkout_blocks_compatibility' );