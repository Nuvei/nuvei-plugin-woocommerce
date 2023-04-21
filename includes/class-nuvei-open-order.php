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
        $products_data                  = $this->get_products_data();
        $cart_total                     = (float) $cart->total;
        $try_update_order               = false;
        
        // check if product is available when click on Pay button
//        if ($this->is_ajax 
//            && !empty($products_data['products_data']) 
//            && is_array($products_data['products_data'])
//        ) {
//            foreach ($products_data['products_data'] as $data) {
//                if (!$data['in_stock']) {
//                    Nuvei_Logger::write($data, 'An item is not available.');
//                    
//                    wp_send_json(array(
//                        'status'    => 0,
//                        'msg'       => __('An item is not available.')
//                    ));
//                    exit;
//                }
//            }
//        }
        
        # try to update Order or not
        if ( !( empty($nuvei_last_open_order_details['userTokenId'])
            && !empty($products_data['subscr_data'])
        ) ) {
            $try_update_order = true;
        }
        
        if (empty($nuvei_last_open_order_details['transactionType'])) {
            $try_update_order = false;
        }
        
        if ($cart_total == 0
            && (empty($nuvei_last_open_order_details['transactionType'])
                || 'Auth' != $nuvei_last_open_order_details['transactionType']
            )
        ) {
            $try_update_order = false;
        }
        
        if ($cart_total > 0
            && !empty($nuvei_last_open_order_details['transactionType'])
            && 'Auth' == $nuvei_last_open_order_details['transactionType']
            && $nuvei_last_open_order_details['transactionType'] != $this->plugin_settings['payment_action']
        ) {
            $try_update_order = false;
        }
        
        Nuvei_Logger::write([
            '$nuvei_last_open_order_details'    => $nuvei_last_open_order_details,
            '$try_update_order'                 => $try_update_order,
        ]);
        
        if ($try_update_order) {
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
            }
            elseif (!empty($resp['status']) && !empty($resp['reload_checkout'])) {
                wp_send_json(array('reload_checkout' => 1));
                exit;
            }
        }
		# /try to update Order or not
        
        Nuvei_Logger::write(
            [
                'userTokenId' => $nuvei_last_open_order_details['userTokenId'],
                'subscr_data' => $products_data['subscr_data']
            ],
            'Skip updateOrder'
        );
		
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
			'amount'            => (string) number_format($cart_total, 2, '.', ''),
			'shippingAddress'	=> $addresses['shippingAddress'],
			'billingAddress'	=> $addresses['billingAddress'],
			'userDetails'       => $addresses['billingAddress'],
			'transactionType'   => (float) $cart->total == 0 ? 'Auth' : $this->plugin_settings['payment_action'],
            'urlDetails'        => $url_details,
		);
		
        // add or not userTokenId
		if (!empty($products_data['subscr_data'])
            || 1 == $this->plugin_settings['use_upos']
        ) {
			$oo_params['userTokenId'] = $addresses['billingAddress']['email'];
		}
        
        // WC Subsc
        if ($products_data['wc_subscr']) {
            $oo_params['userTokenId'] = $addresses['billingAddress']['email'];
            $oo_params['isRebilling'] = 0;
            $oo_params['card']['threeD']['v2AdditionalParams'] = [ // some default params
                'rebillFrequency'   => 30, // days
                'rebillExpiry '     => date('Ymd', strtotime('+5 years')),
            ];
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
			'transactionType'	=> $oo_params['transactionType'],
		);
        
        if (!empty($oo_params['userTokenId'])) {
            $nuvei_last_open_order_details['userTokenId'] = $oo_params['userTokenId'];
        }
        
        $this->set_nuvei_session_data(
            $resp['sessionToken'],
            $nuvei_last_open_order_details,
            $products_data
        );
		
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
