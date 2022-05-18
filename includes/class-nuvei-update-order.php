<?php

defined( 'ABSPATH' ) || exit;

/**
 * Update Order request class.
 */
class Nuvei_Update_Order extends Nuvei_Request {

	public function __construct( array $plugin_settings) {
		parent::__construct($plugin_settings);
	}
	
	/**
	 * Main method
	 * 
	 * @global Woocommerce $woocommerce
	 * @return array
	 */
	public function process() {
		$nuvei_last_open_order_details = array();
		
		if (!empty(WC()->session)) {
			$nuvei_last_open_order_details = WC()->session->get('nuvei_last_open_order_details');
		}
		
		//      Nuvei_Logger::write(
		//          isset($nuvei_last_open_order_details) ? $nuvei_last_open_order_details : '',
		//          'update_order() - session[nuvei_last_open_order_details]'
		//      );
		
		if (empty($nuvei_last_open_order_details)
			|| empty($nuvei_last_open_order_details['sessionToken'])
			|| empty($nuvei_last_open_order_details['orderId'])
		//          || empty($nuvei_last_open_order_details['clientRequestId'])
		) {
			Nuvei_Logger::write($nuvei_last_open_order_details, 'update_order() - Missing last Order session data.');
			
			return array('status' => 'ERROR');
		}
		
		global $woocommerce;
		
		$cart         = $woocommerce->cart;
		$cart_amount  = (string) number_format((float) $cart->total, 2, '.', '');
		$addresses    = $this->get_order_addresses();
		$product_data = $this->get_products_data();
		
		// prevent update with empty values
		foreach ($addresses['billingAddress'] as $key => $val) {
			if (empty(trim($val))) {
				unset($addresses['billingAddress'][$key]);
			}
		}
		
		// create Order upgrade
		$params = array(
			'sessionToken'		=> $nuvei_last_open_order_details['sessionToken'],
			'orderId'			=> $nuvei_last_open_order_details['orderId'],
			'currency'			=> get_woocommerce_currency(),
			'amount'			=> $cart_amount,
			'billingAddress'	=> $addresses['billingAddress'],
			'userDetails'       => $addresses['billingAddress'],
			'shippingAddress'	=> $addresses['shippingAddress'],
			
			'items'				=> array(
				array(
					'name'		=> 'wc_order',
					'price'		=> $cart_amount,
					'quantity'	=> 1
				)
			),
			
			'merchantDetails'   => array(
				'customField1' => '', // subscription details
				'customField2' => json_encode($product_data['products_data']), // item details
			),
		);
        
        // add or not userTokenId
        $items_with_plan_data = $this->check_for_product_with_plan();
        
        if (!empty($items_with_plan_data['item_with_plan'])
            || 1 == $this->plugin_settings['use_upos']
        ) {
			$params['userTokenId'] = $params['billingAddress']['email'];
		}
		
		// lat changes for the rebilling
		if (!empty($product_data['subscr_data'])) {
			$params['isRebilling']                                        = 0;
			$params['paymentOption']['card']['threeD']['rebillFrequency'] = 1;
			$params['paymentOption']['card']['threeD']['rebillExpiry']    = gmdate('Ymd', strtotime('+10 years'));
			$params['merchantDetails']['customField1']                    = json_encode($product_data['subscr_data']);
		} else { // for normal transaction
			$params['isRebilling']                                        = 1;
			$params['paymentOption']['card']['threeD']['rebillFrequency'] = 0;
			$params['paymentOption']['card']['threeD']['rebillExpiry']    = gmdate('Ymd', time());
		}
		
		$resp = $this->call_rest_api('updateOrder', $params);
		
		# Success
		if (!empty($resp['status']) && 'SUCCESS' == $resp['status']) {
			$nuvei_last_open_order_details['amount'] = $cart_amount;
			//          $nuvei_last_open_order_details['merchantDetails']           = $resp['request_base_params']['merchantDetails'];
			$nuvei_last_open_order_details['billingAddress']['country']	= $params['billingAddress']['country'];
			
			// put the new data in the session
			$nuvei_last_open_order_details = array(
				'amount'			=> $params['amount'],
			//              'merchantDetails'   => $resp['request_base_params']['merchantDetails'],
				'sessionToken'		=> $resp['sessionToken'],
			//              'clientRequestId'   => $resp['request_base_params']['clientRequestId'],
				'orderId'			=> $resp['orderId'],
				'billingAddress'	=> $params['billingAddress'],
			//              'cart_string'       => json_encode(WC()->session->cart), // stringify the Cart
			);

			WC()->session->set('nuvei_last_open_order_details', $nuvei_last_open_order_details);

//			Nuvei_Logger::write($nuvei_last_open_order_details, 'Update Order Class - nuvei_last_open_order_details');
			// put the new data in the session END
			
			return array_merge($params, $resp);
		}
		
		Nuvei_Logger::write('Nuvei_Update_Order - Order update was not successful.');

		return array('status' => 'ERROR');
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
