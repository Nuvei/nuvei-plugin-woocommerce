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
        // container mode uses the frozen, pre-modal package; modal mode uses
        // the new package, plus its blocks-modal.js add-on
        $is_modal   = 'nuvei_checkout_modal' == $render_to;
        $js_folder  = $is_modal ? 'store/modal' : 'store/in-page';

		wp_register_script(
			'nuvei-checkout-blocks',
			$this->plugin_dir_url . "assets/js/{$js_folder}/nuvei-checkout-blocks.js",
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

		$handles = array( 'nuvei-checkout-blocks' );

        // modal mode needs an extra add-on script; container mode's logic
        // is fully self-contained in the frozen nuvei-checkout-blocks.js
        if ( $is_modal ) {
            wp_register_script(
                'nuvei-blocks-modal',
                $this->plugin_dir_url . "assets/js/{$js_folder}/blocks-modal.js",
                array( 'nuvei-checkout-blocks' ),
                $helper->helper_get_plugin_version(),
                true
            );

            $handles[] = 'nuvei-blocks-modal';
        }

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
