<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * A class to supports blocks front-end.
 *
 * @author  Nuvei
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
        
        $helper     = new Nuvei_Pfw_Helper();
        $render_to  = $this->settings['render_to'] ?? '';

		wp_register_script(
			'nuvei-checkout-blocks',
			$this->plugin_dir_url . 'assets/js/blocks/nuvei-checkout-blocks.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
                'wc-blocks-checkout',
			),
			$helper->helper_get_plugin_version(),
            true
		);

		wp_set_script_translations( 'nuvei-checkout-blocks', 'nuvei-payments-for-woocommerce' );

		$handles        = array( 'nuvei-checkout-blocks' );
        // mode-specific script, based on the "render_to" setting
        $script_type    = 'nuvei_checkout_modal' == $render_to ? 'modal' : 'container';
        
        wp_register_script(
            'nuvei-checkout-blocks-' . $script_type,
            $this->plugin_dir_url . "assets/js/blocks/nuvei-{$script_type}.js",
            array( 'nuvei-checkout-blocks' ),
            $helper->helper_get_plugin_version(),
            true
        );

        $handles[] = 'nuvei-checkout-blocks-' . $script_type;

		return $handles;
	}

	public function get_payment_method_data() {
	    return array(
	        'title'       => $this->settings['title'],
	        'description' => $this->settings['description'] ?? '',
	        'icon'        => $this->plugin_dir_url . 'assets/icons/nuvei.png',
	    );
	}
}
