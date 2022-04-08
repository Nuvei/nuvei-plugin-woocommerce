<?php

defined( 'ABSPATH' ) || exit;

/**
 * The class for openOrder request.
 */
class Nuvei_Open_Order extends Nuvei_Request {

	private $is_ajax;
	
	/**
	 * Set is_ajax parameter to the Process metohd.
	 * 
	 * @param array $plugin_settings
	 * @param bool  $is_ajax
	 */
	public function __construct( array $plugin_settings, $is_ajax = false) {
		parent::__construct($plugin_settings);
		
		$this->is_ajax = $is_ajax;
	}

	/**
	 * The main method.
	 * 
	 * @global object $woocommerce
	 * @return array|boolean
	 */
	public function process() {
		global $woocommerce;
		
		$cart        = $woocommerce->cart;
		$uniq_str    = gmdate('YmdHis') . '_' . uniqid();
		$ajax_params = array();
		
		# try to update Order
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
		
		// check for a Product with Payment Plan
		$addresses = $this->get_order_addresses();
		$oo_params = array(
			'clientUniqueId'    => $uniq_str . '_wc_cart',
			'currency'          => get_woocommerce_currency(),
			'amount'            => (string) number_format((float) $cart->total, 2, '.', ''),
			'shippingAddress'	=> $addresses['shippingAddress'],
			'billingAddress'	=> $addresses['billingAddress'],
			'userDetails'       => $addresses['billingAddress'],
			'transactionType'   => $this->plugin_settings['payment_action'],
			'paymentOption'     => array('card' => array('threeD' => array('isDynamic3D' => 1))),
            'urlDetails'        => $url_details,
		);
		
        // add or not userTokenId
        $items_with_plan_data = $this->check_for_product_with_plan();
        
		if (!empty($items_with_plan_data['item_with_plan'])
            || 1 == $this->plugin_settings['use_upos']
        ) {
			$oo_params['userTokenId'] = $addresses['billingAddress']['email'];
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
		//          'merchantDetails'   => $resp['request_base_params']['merchantDetails'],
			'sessionToken'		=> $resp['sessionToken'],
		//          'clientRequestId'   => $resp['request_base_params']['clientRequestId'],
			'orderId'			=> $resp['orderId'],
			'billingAddress'	=> $oo_params['billingAddress'],
		//          'cart_string'       => json_encode(WC()->session->cart), // stringify the Cart
		);
		
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
	protected function get_checksum_params() {
		return array('merchantId', 'merchantSiteId', 'clientRequestId', 'amount', 'currency', 'timeStamp');
	}
}
