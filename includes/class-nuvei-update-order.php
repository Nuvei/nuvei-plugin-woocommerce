<?php

defined( 'ABSPATH' ) || exit;

/**
 * Update Order request class.
 */
class Nuvei_Update_Order extends Nuvei_Request
{
    public function __construct($rest_params = [])
    {
        if (!empty($rest_params)) {
            $this->rest_params = $rest_params;
        }
        
        parent::__construct();
    }
    
	/**
	 * Main method
	 * 
	 * @global Woocommerce $woocommerce
     * 
     * @param array $products_data
     * @param array $open_order_details Pass them only in REST API flow.
     * 
	 * @return array
	 */
//	public function process($products_data, $open_order_details = [])
	public function process()
    {
        global $woocommerce;
        
        $func_params        = current(func_get_args());
        $products_data      = $func_params['products_data'] ?? [];
        $open_order_details = $func_params['open_order_details'] ?? [];
        
//		$open_order_details = array();
		
//		if (!empty(WC()->session)) {
        // default flow
		if (empty($this->rest_params) && !empty($woocommerce->session)) {
//			$open_order_details = WC()->session->get(NUVEI_SESSION_OO_DETAILS);
			$open_order_details = $woocommerce->session->get(NUVEI_SESSION_OO_DETAILS);
//            $cart         = $woocommerce->cart;
//            $cart_amount  = (string) number_format((float) $cart->total, 2, '.', '');
            $cart_amount  = (string) number_format((float) $woocommerce->cart->total, 2, '.', '');
		}
        // REST API flow
        else {
//            $cart_amount  = (string) number_format((float) $this->get_total_from_rest_params(), 2, '.', '');
            $cart_amount  = (string) number_format((float) $products_data['totals'], 2, '.', '');
        }
		
		if (empty($open_order_details)
			|| empty($open_order_details['sessionToken'])
			|| empty($open_order_details['orderId'])
		) {
			Nuvei_Logger::write($open_order_details, 'update_order() - Missing last Order session data.');
			
			return array('status' => 'ERROR');
		}
		
		$addresses      = $this->get_order_addresses();
//		$products_data  = $this->get_products_data();
		
		// prevent update with empty values
		foreach ($addresses['billingAddress'] as $key => $val) {
			if (empty(trim($val))) {
				unset($addresses['billingAddress'][$key]);
			}
		}
		
		// create Order upgrade
		$params = array(
			'sessionToken'		=> $open_order_details['sessionToken'],
			'orderId'			=> $open_order_details['orderId'],
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
		);
        
        // WC Subsc
        if (!empty($products_data['wc_subscr'])) {
            $oo_params['isRebilling'] = 0;
            $oo_params['card']['threeD']['v2AdditionalParams'] = [ // some default params
                'rebillFrequency'   => 30, // days
                'rebillExpiry '     => date('Ymd', strtotime('+5 years')),
            ];
        }
        else {
            $oo_params['isRebilling']   = null;
            $oo_params['card']          = null;
        }
        
//        Nuvei_Logger::write(strlen($params['merchantDetails']['customField1']), 'customField1 len');
        
		$resp = $this->call_rest_api('updateOrder', $params);
		
		# Success
		if (!empty($resp['status']) && 'SUCCESS' == $resp['status']) {
            // in default flow
            if (empty($this->rest_params)) {
                // put the new data in the session
                $open_order_details['amount']                       = $cart_amount;
                $open_order_details['billingAddress']['country']    = $params['billingAddress']['country'];
                $open_order_details['amount']                       = $params['amount'];
                $open_order_details['sessionToken']                 = $resp['sessionToken'];
                $open_order_details['orderId']                      = $resp['orderId'];
                $open_order_details['billingAddress']               = $params['billingAddress'];
                
                $this->set_nuvei_session_data(
                    $resp['sessionToken'],
                    $open_order_details,
                    $products_data
                );
            }
//            else {
//                $params['transactionType']  = $this->rest_params['transactionType'];
//            }
            
            $params['products_data'] = $products_data;
			
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
	protected function get_checksum_params()
    {
		return array('merchantId', 'merchantSiteId', 'clientRequestId', 'amount', 'currency', 'timeStamp');
	}
}
