<?php

defined( 'ABSPATH' ) || exit;

/**
 * The class for openOrder request.
 */
class Nuvei_Open_Order extends Nuvei_Request
{
	private $is_ajax;
	
	/**
	 * Set is_ajax parameter to the Process metohd.
	 * 
	 * @param array $plugin_settings
	 * @param bool  $is_ajax
	 */
	public function __construct( array $plugin_settings, $is_ajax = false)
    {
		parent::__construct($plugin_settings);
		
		$this->is_ajax = $is_ajax;
	}

	/**
	 * The main method.
	 * 
	 * @global object $woocommerce
	 * @return array|boolean
	 */
	public function process()
    {
        Nuvei_Logger::write('OpenOrder class.');
        
		global $woocommerce;
		
		$cart                           = $woocommerce->cart;
		$ajax_params                    = array();
        $nuvei_last_open_order_details  = WC()->session->get('nuvei_last_open_order_details');
        $product_data                   = $this->get_products_data();
        
        // check if product is available when click on Pay button
        if ($this->is_ajax 
            && !empty($product_data['products_data']) 
            && is_array($product_data['products_data'])
        ) {
            foreach ($product_data['products_data'] as $data) {
                if (!$data['in_stock']) {
                    Nuvei_Logger::write($data, 'An item is not available.');
                    
                    wp_send_json(array(
                        'status'    => 0,
                        'msg'       => __('An item is not available.')
                    ));
                    exit;
                }
            }
        }
        
		# try to update Order
        if ( !( empty($nuvei_last_open_order_details['userTokenId'])
            && !empty($product_data['subscr_data'])
        ) ) {
            $uo_obj = new Nuvei_Update_Order($this->plugin_settings);
            $resp   = $uo_obj->process();

            if (!empty($resp['status']) && 'SUCCESS' == $resp['status']) {
                if ($this->is_ajax) {
                    wp_send_json(array(
                        'status'        => 1,
                        'sessionToken'	=> $resp['sessionToken']
                    ));
                    exit;
                }

                return $resp;
            } elseif (!empty($resp['status']) && !empty($resp['reload_checkout'])) {
                wp_send_json(array('reload_checkout' => 1));
                exit;
            }
        }
		# try to update Order END
		
		$form_data = Nuvei_Http::get_param('scFormData');
		
		if (!empty($form_data)) {
			parse_str($form_data, $ajax_params); 
		}
        
        $url_details = [
            'notificationUrl'   => Nuvei_String::get_notify_url($this->plugin_settings),
            'backUrl'           => wc_get_checkout_url(),
        ];
        
        if(1 == $this->plugin_settings['close_popup']) {
            $url_details['successUrl']  = $url_details['failureUrl'] 
                                        = $url_details['pendingUrl'] 
                                        = NUVEI_SDK_AUTOCLOSE_URL;
        }
		
		$addresses = $this->get_order_addresses();
		$oo_params = array(
			'clientUniqueId'    => gmdate('YmdHis') . '_' . uniqid(),
			'currency'          => get_woocommerce_currency(),
			'amount'            => (string) number_format((float) $cart->total, 2, '.', ''),
			'shippingAddress'	=> $addresses['shippingAddress'],
			'billingAddress'	=> $addresses['billingAddress'],
			'userDetails'       => $addresses['billingAddress'],
			'transactionType'   => $this->plugin_settings['payment_action'],
            'urlDetails'        => $url_details,
		);
		
        // add or not userTokenId
		if (!empty($product_data['subscr_data'])
            || 1 == $this->plugin_settings['use_upos']
        ) {
			$oo_params['userTokenId'] = $addresses['billingAddress']['email'];
            // pass the subscription data, to use it later, here we use variation_id as key
			$oo_params['merchantDetails']['customField1']
                = json_encode($product_data['subscr_data']);
		}
		
		$resp = $this->call_rest_api('openOrder', $oo_params);
		
		if (empty($resp['status'])
			|| empty($resp['sessionToken'])
			|| 'SUCCESS' != $resp['status']
		) {
			if ($this->is_ajax) {
				wp_send_json(array(
					'status'	=> 0,
					'msg'		=> $resp
				));
				exit;
			}
			
			return false;
		}
		
		// set them to session for the check before submit the data to the webSDK
		$nuvei_last_open_order_details = array(
			'amount'			=> $oo_params['amount'],
			'sessionToken'		=> $resp['sessionToken'],
			'orderId'			=> $resp['orderId'],
			'billingAddress'	=> $oo_params['billingAddress'],
		);
        
        if (!empty($oo_params['userTokenId'])) {
            $nuvei_last_open_order_details['userTokenId'] = $oo_params['userTokenId'];
        }
		
		WC()->session->set('nuvei_last_open_order_details', $nuvei_last_open_order_details);
		
		Nuvei_Logger::write($cart->nuvei_last_open_order_details, 'nuvei_last_open_order_details');
		
		if ($this->is_ajax) {
			wp_send_json(array(
				'status'        => 1,
				'sessionToken'  => $resp['sessionToken'],
				'amount'        => $oo_params['amount']
			));
			exit;
		}
		
		return array_merge($resp, $oo_params);
	}
	
	/**
	 * Return keys required to calculate checksum. Keys order is relevant.
	 *
	 * @return array
	 */
	protected function get_checksum_params()
    {
		return array('merchantId', 'merchantSiteId', 'clientRequestId', 'amount', 'currency', 'timeStamp');
	}
}
