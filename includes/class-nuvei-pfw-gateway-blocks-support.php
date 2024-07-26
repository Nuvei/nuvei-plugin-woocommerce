<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * A class to supports blocks front-end.
 *
 * @author Nuvei
 * @extends AbstractPaymentMethodType
 */
final class Nuvei_Pfw_Gateway_Blocks_Support extends AbstractPaymentMethodType {

	protected $name = NUVEI_PFW_GATEWAY_NAME;

	private $plugin_dir_url;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles() {
		$this->plugin_dir_url = str_replace( 'includes/', '', plugin_dir_url( __FILE__ ) );

		wp_register_script(
			'nuvei-checkout-blocks',
			$this->plugin_dir_url . 'assets/js/nuvei-checkout-blocks.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			1,
			true
		);
        
		return array( 'nuvei-checkout-blocks' );
	}

	public function get_payment_method_data() {
		$settings = array(
			'title'         => $this->settings['title'],
			'description'   => $this->settings['description'],
			'icon'          => $this->plugin_dir_url . 'assets/icons/nuvei.png',
		);

		return $settings;
	}
}
