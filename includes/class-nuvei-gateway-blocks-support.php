<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * A class to supports blocks front-end.
 *
 * @author Nuvei
 * @extends AbstractPaymentMethodType
 */
final class Nuvei_Gateway_Blocks_Support extends AbstractPaymentMethodType
{
    protected $name = NUVEI_GATEWAY_NAME;
    
    private $plugin_dir_url;
    
    public function initialize()
    {
        $this->settings = get_option( 'woocommerce_'. $this->name .'_settings', [] );
    }

    public function is_active()
    {
		return ! empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
	}
    
    public function get_payment_method_script_handles()
    {
        $this->plugin_dir_url = str_replace('includes/', '', plugin_dir_url(__FILE__));
        
		wp_register_script(
            'nuvei-checkout-blocks',
            $this->plugin_dir_url . 'assets/js/nuvei-checkout-blocks.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );
        
        return ['nuvei-checkout-blocks'];
	}
    
    public function get_payment_method_data()
    {
        $settings = [
            'title'         => $this->settings['title'],
            'description'   => $this->settings['description'],
            'icon'          => $this->plugin_dir_url . 'assets/icons/nuvei.png',
        ];
        
        // put this check or call_checkout will be called in the Theme Editor in the admin and plugin will
        // crash beacause of the missing WooCommerce Session object :)
        if (!is_admin() && is_checkout()) {
            global $wc_nuvei;
            
            $settings['checkoutParams'] = $wc_nuvei->call_checkout(false, false, true);
        }
        
        return $settings;
    }
}
