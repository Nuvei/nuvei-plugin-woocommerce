<?php

defined( 'ABSPATH' ) || exit;

/**
 * The class for openOrder request.
 */
class Nuvei_Open_Order extends Nuvei_Request
{
	protected $plugin_settings;
    
	private $is_ajax;
	
	/**
	 * Set is_ajax parameter to the Process metohd.
	 * 
	 * @param array $plugin_settings
	 * @param bool  $is_ajax
	 */
	public function __construct( array $plugin_settings, $is_ajax = false, $rest_params = [])
    {
		parent::__construct();
		
		$this->plugin_settings  = $plugin_settings;
		$this->is_ajax          = $is_ajax;
		$this->rest_params      = $rest_params;
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
        
        $try_update_order = true;
        
        if (!empty($this->rest_params)) {
            $open_order_details = [
                'transactionType'   => $this->rest_params['transactionType'] ?? '',
                'orderId'           => $this->rest_params['orderId'] ?? 0,
                'userTokenId'       => $this->rest_params['email'] ?? '',
                'sessionToken'      => $this->rest_params['sessionToken'] ?? '',
            ];
            $products_data      = $this->get_products_data($this->rest_params);
            $cart_total         = $products_data['totals'];
            $addresses          = $this->get_order_addresses();
            $transactionType    = $this->get_total_from_rest_params() == 0 
                ? 'Auth' : $this->plugin_settings['payment_action'];
        }
        else {
            $ajax_params        = [];
//            $open_order_details = WC()->session->get('nuvei_last_open_order_details');
            $open_order_details = $woocommerce->session->get('nuvei_last_open_order_details');
            $products_data      = $this->get_products_data();
            $cart_total         = (float) $products_data['totals'];
            $addresses          = $this->get_order_addresses();
            $transactionType    = $cart_total == 0 ? 'Auth' : $this->plugin_settings['payment_action'];
        }
        
        Nuvei_Logger::write($open_order_details, '$open_order_details');
		
        // do not allow WCS and Nuvei Subscription in same Order
        if (!empty($products_data['subscr_data']) && $products_data['wc_subscr']) {
            $msg = 'It is not allowed to put product with WCS and product witn Nuvei Subscription in same Order! Please, contact the site administrator for this problem!';

            Nuvei_Logger::write($msg);
            
            return array(
				'status'    => 0,
                'custom_msg'       => __($msg, 'nuvei_checkout_woocommerce'),
			);
        }
        
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
        if (!is_array($open_order_details)
            || empty($open_order_details['transactionType'])
            || empty($open_order_details['userTokenId'])
            || empty($addresses['billingAddress']['email'])
            || $open_order_details['transactionType'] != $transactionType
            || $open_order_details['userTokenId'] != $addresses['billingAddress']['email']
        ) {
            Nuvei_Logger::write([
                    '$open_order_details'   => $open_order_details,
                    '$transactionType'      => $transactionType,
                    '$addresses'            => $addresses,
                ],
                '$try_update_order = false',
                'DEBUG'
            );
            
            $try_update_order = false;
        }
        
        if ($try_update_order) {
//            $uo_obj = new Nuvei_Update_Order($this->plugin_settings);
            $uo_obj = new Nuvei_Update_Order($this->rest_params);
            $resp   = $uo_obj->process($open_order_details);

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
        
		$oo_params = array(
			'clientUniqueId'    => gmdate('YmdHis') . '_' . uniqid(),
			'currency'          => get_woocommerce_currency(),
			'amount'            => (string) number_format($cart_total, 2, '.', ''),
			'shippingAddress'	=> $addresses['shippingAddress'],
			'billingAddress'	=> $addresses['billingAddress'],
			'userDetails'       => $addresses['billingAddress'],
			'transactionType'   => $transactionType,
            'urlDetails'        => $url_details,
            'userTokenId'       => $addresses['billingAddress']['email'], // the decision to save UPO is in the SDK
		);
		
        // WC Subsc
        if ($products_data['wc_subscr']) {
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
		
        // in default flow
        if (empty($this->rest_params)) {
            // set them to session for the check before submit the data to the webSDK
            $open_order_details = array(
                'sessionToken'		=> $resp['sessionToken'],
                'orderId'			=> $resp['orderId'],
                'transactionType'	=> $oo_params['transactionType'], // use it to decide call or not updateOrder
                'userTokenId'       => $oo_params['userTokenId'], // use it to decide call or not updateOrder
            );
            
            $this->set_nuvei_session_data(
                $resp['sessionToken'],
                $open_order_details,
                $products_data
            );
		
            Nuvei_Logger::write($open_order_details, 'session open_order_details');
        }
		
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
