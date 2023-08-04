<?php

defined( 'ABSPATH' ) || exit;

/**
 * The base class for requests. The different requests classes inherit this one.
 * Some common methods are also here.
 */
abstract class Nuvei_Request
{
	protected $plugin_settings;
	protected $request_base_params;
	protected $sc_order;
	
	private $device_types = [];
	
	abstract public function process();
	abstract protected function get_checksum_params();

	/**
	 * Set variables.
	 * Description of merchantDetails:
	 * 
	 * 'merchantDetails'	=> array(
	 *      'customField1'  => string,  // subscription details as json
	 *      'customField2'  => string,  // item details as json
	 *      'customField3'  => int,     // create time time()
	 *  ),
	 * 
	 * @param array $plugin_settings
	 */
	public function __construct(array $plugin_settings)
    {
		$time                  = gmdate('Ymdhis');
		$this->plugin_settings = $plugin_settings;
		
		$this->request_base_params = array(
			'merchantId'        => $plugin_settings['merchantId'],
			'merchantSiteId'    => $plugin_settings['merchantSiteId'],
			'clientRequestId'   => $time . '_' . uniqid(),
			'timeStamp'         => $time,
			'webMasterId'       => 'WooCommerce ' . WOOCOMMERCE_VERSION . '; Plugin v' . nuvei_get_plugin_version(),
			'sourceApplication' => NUVEI_SOURCE_APPLICATION,
			'encoding'          => 'UTF-8',
			'deviceDetails'     => $this->get_device_details(),
			'merchantDetails'	=> array(
				'customField3'      => time(), // create time
			),
		);
	}
    
	/**
	 * Checks if the Order belongs to WC_Order and if the order was made
	 * with Nuvei payment module.
	 * 
	 * @param int|string $order_id
	 * @param bool $return - return the order
	 * 
	 * @return void
	 */
	protected function is_order_valid($order_id, $return = false)
    {
		Nuvei_Logger::write($order_id, 'is_order_valid() check.');
		
		$this->sc_order = wc_get_order( $order_id );
		
		if ( ! is_a( $this->sc_order, 'WC_Order') ) {
			Nuvei_Logger::write('is_order_valid() Error - Provided Order ID is not a WC Order');
			
			if ($return) {
				return;
			}
			
			exit(wp_json_encode('is_order_valid() Error - Provided Order ID is not a WC Order'));
		}
		
		Nuvei_Logger::write('The Order is valid.');
		
		// in case of Subscription states DMNs - stop proccess here. We will save only a message to the Order.
		if ('subscription' == Nuvei_Http::get_param('dmnType')) {
			return;
		}
		
		// check for 'sc' also because of the older Orders
		if (!in_array($this->sc_order->get_payment_method(), array(NUVEI_GATEWAY_NAME, 'sc'))) {
			Nuvei_Logger::write(
				array(
					'order_id'          => $order_id,
					'payment_method'    => $this->sc_order->get_payment_method()
				), 
				'DMN Error - the order does not belongs to Nuvei.'
			);
			
			if ($return) {
				return;
			}
			
			exit(wp_json_encode('DMN Error - the order does not belongs to Nuvei.'));
		}
		
		// can we override Order status (state)
		$ord_status = strtolower($this->sc_order->get_status());
		
		if ( in_array($ord_status, array('cancelled', 'refunded')) ) {
			Nuvei_Logger::write($this->sc_order->get_payment_method(), 'DMN Error - can not override status of Voided/Refunded Order.');
			
			if ($return) {
				return;
			}
			
			exit(wp_json_encode('DMN Error - can not override status of Voided/Refunded Order.'));
		}
		
		if ('completed' == $ord_status
			&& 'auth' == strtolower(Nuvei_Http::get_param('transactionType'))
		) {
			Nuvei_Logger::write(
                $this->sc_order->get_payment_method(),
                'DMN Error - can not override status Completed with Auth.'
            );
			
			if ($return) {
				return;
			}
			
			exit(wp_json_encode('DMN Error - can not override status Completed with Auth.'));
		}
		// can we override Order status (state) END
	}
	
	protected function save_refund_meta_data( $trans_id, $ref_amount, $status = '', $wc_id = 0)
    {
		Nuvei_Logger::write('save_refund_meta_data()');
		
		$refunds = json_decode($this->sc_order->get_meta(NUVEI_REFUNDS), true);
		
		if (empty($refunds)) {
			$refunds = [];
		}
		
		//      Nuvei_Logger::write($refunds, 'save_refund_meta_data(): Saved Refunds before the current one.');
		
		// add the new refund
		$refunds[$trans_id] = array(
			'refund_amount'	=> round((float) $ref_amount, 2),
			'status'		=> empty($status) ? 'pending' : $status
		);
		
		if (0 < $wc_id) {
			$refunds[$trans_id]['wc_id'] = $wc_id;
		}

		$this->sc_order->update_meta_data(NUVEI_REFUNDS, json_encode($refunds));
		$order_id = $this->sc_order->save();
		
		Nuvei_Logger::write('save_refund_meta_data() Saved Refund with Tr ID ' . $trans_id);
		
		return $order_id;
	}
	
	/**
	 * Help function to generate Billing and Shipping details.
	 * 
	 * @global Woocommerce $woocommerce
	 * @return array
	 */
	protected function get_order_addresses()
    {
		global $woocommerce;
		
		$form_params    = array();
		$billingAddress = array();
		$cart           = $woocommerce->cart;
		
		if (!empty(Nuvei_Http::get_param('scFormData'))) {
			parse_str(Nuvei_Http::get_param('scFormData'), $form_params); 
		}
		
		# set billing params
		// billing_first_name
		$bfn = trim(Nuvei_Http::get_param('billing_first_name', 'string', '', $form_params));
		if (empty($bfn)) {
			$bfn = $cart->get_customer()->get_billing_first_name();
		}
		$billingAddress['firstName'] = !empty($bfn) ? trim($bfn) : 'Missing parameter';
		
		// billing_last_name
		$bln = trim(Nuvei_Http::get_param('billing_last_name', 'string', '', $form_params));
		if (empty($bln)) {
			$bln = $cart->get_customer()->get_billing_last_name();
		}
		$billingAddress['lastName'] = !empty($bln) ? trim($bln) : 'Missing parameter';

		// address
        $ba     = '';
        $ba_ln1 = trim(Nuvei_Http::get_param('billing_address_1', 'string', '', $form_params));
        $ba_ln2 = trim(Nuvei_Http::get_param('billing_address_2', 'string', '', $form_params));
        
        if(!empty($ba_ln1)) {
            $ba = $ba_ln1;
            
            if(!empty($ba_ln2)) {
                $ba .= ' ' . $ba_ln2;
            }
        }
        
		if (empty(trim($ba))) {
            $ba_ln1 = trim($cart->get_customer()->get_billing_address());
            $ba_ln2 = trim($cart->get_customer()->get_billing_address_2());
            
            if(!empty($ba_ln1)) {
                $ba = $ba_ln1;

                if(!empty($ba_ln2)) {
                    $ba .= ' ' . $ba_ln2;
                }
            }
            
		}
		$billingAddress['address'] = !empty($ba) ? trim($ba) : 'Missing parameter';
		
		// billing_phone
		$bp = trim(Nuvei_Http::get_param('billing_phone', 'string', '', $form_params));
		if (empty($bp)) {
			$bp = $cart->get_customer()->get_billing_phone();
		}
		$billingAddress['phone'] = !empty($bp) ? trim($bp) : 'Missing parameter';

		// billing_postcode
		$bz = trim(Nuvei_Http::get_param('billing_postcode', 'int', 0, $form_params));
		if (empty($bz)) {
			$bz = $cart->get_customer()->get_billing_postcode();
		}
		$billingAddress['zip'] = !empty($bz) ? trim($bz) : 'Missing parameter';

		// billing_city
		$bc = trim(Nuvei_Http::get_param('billing_city', 'string', '', $form_params));
		if (empty($bc)) {
			$bc = $cart->get_customer()->get_billing_city();
		}
		$billingAddress['city'] = !empty($bc) ? trim($bc) : 'Missing parameter';

		// billing_country
		$bcn = trim(Nuvei_Http::get_param('billing_country', 'string', '', $form_params));
		if (empty($bcn)) {
			$bcn = $cart->get_customer()->get_billing_country();
		}
		$billingAddress['country'] = trim($bcn);
        
        //billing state
        $bst = trim(Nuvei_Http::get_param('billing_state', 'string', '', $form_params));
		if (empty($bst)) {
			$bst = $cart->get_customer()->get_billing_state();
		}
		$billingAddress['state'] = trim($bst);

		// billing_email
		$be = Nuvei_Http::get_param('billing_email', 'mail', '', $form_params);
		if (empty($be)) {
			$be = $cart->get_customer()->get_billing_email();
		}
		$billingAddress['email'] = trim($be);
		# set billing params END
		
		// shipping
		$sfn = Nuvei_Http::get_param('shipping_first_name', 'string', '', $form_params);
		if (empty($sfn)) {
			$sfn = $cart->get_customer()->get_shipping_first_name();
		}
		
		$sln = Nuvei_Http::get_param('shipping_last_name', 'string', '', $form_params);
		if (empty($sln)) {
			$sln = $cart->get_customer()->get_shipping_last_name();
		}
		
		$sa = Nuvei_Http::get_param('shipping_address_1', 'string', '', $form_params)
			. ' ' . Nuvei_Http::get_param('shipping_address_2', 'string', '', $form_params);
		if (empty($sa)) {
			$sa = $cart->get_customer()->get_shipping_address() . ' '
				. $cart->get_customer()->get_shipping_address_2();
		}
        
		$sz = Nuvei_Http::get_param('shipping_postcode', 'string', '', $form_params);
		if (empty($sz)) {
			$sz = $cart->get_customer()->get_shipping_postcode();
		}
		
		$sc = Nuvei_Http::get_param('shipping_city', 'string', '', $form_params);
		if (empty($sc)) {
			$sc = $cart->get_customer()->get_shipping_city();
		}
		
		$scn = Nuvei_Http::get_param('shipping_country', 'string', '', $form_params);
		if (empty($scn)) {
			$scn = $cart->get_customer()->get_shipping_country();
		}
		
		return array(
			'billingAddress'	=> $billingAddress,
			'shippingAddress'	=> array(
				'firstName'	=> trim($sfn),
				'lastName'  => trim($sln),
				'address'   => trim($sa),
				'zip'       => trim($sz),
				'city'      => trim($sc),
				'country'   => trim($scn),
			),
		);
	}

	/**
	 * Call REST API with cURL post and get response.
	 * The URL depends from the case.
	 *
	 * @param type $method - API method
	 * @param array $params - parameters
	 *
	 * @return mixed
	 */
	protected function call_rest_api($method, $params)
    {
		if (empty($this->plugin_settings['hash_type'])
			|| empty($this->plugin_settings['secret'])
		) {
			return array(
				'status'    => 'ERROR',
				'message'   => 'Missing Plugin hash_type and secret params.'
			);
		}
		
		$concat = '';
		$resp   = false;
		$url    = $this->get_endpoint_base() . $method . '.do';
		$params = $this->validate_parameters($params); // validate parameters
		
		if (isset($params['status']) && 'ERROR' == $params['status']) {
			return $params;
		}
		
		$all_params = array_merge_recursive($this->request_base_params, $params);
        
        // use incoming clientRequestId instead of auto generated one
        if (!empty($params['clientRequestId'])) {
            $all_params['clientRequestId'] = $params['clientRequestId'];
        }
		
		// add the checksum
		$checksum_keys = $this->get_checksum_params($method);
        
		if (is_array($checksum_keys)) {
			foreach ($checksum_keys as $key) {
				if (isset($all_params[$key])) {
					$concat .= $all_params[$key];
				}
			}
		}
		
		$all_params['checksum'] = hash(
			$this->plugin_settings['hash_type'],
			$concat . $this->plugin_settings['secret']
		);
		// add the checksum END
		
		$json_post = json_encode($all_params);
		
		try {
			$header =  array(
				'Content-Type: application/json',
//				'Content-Length: ' . strlen($json_post),
			);
			
			if (!function_exists('curl_init')) {
				return array(
					'status' => 'ERROR',
					'message' => 'To use Nuvei Payment gateway you must install CURL module!'
				);
			}
            
            Nuvei_Logger::write(
                array(
                    'Request URL'       => $url,
                    'Request header'    => $header,
                    'Request params'    => $all_params,
                ),
                'Nuvei Request data'
            );
			
			// create cURL post
			$ch = curl_init();

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json_post);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

			$resp       = curl_exec($ch);
            $resp_array = json_decode($resp, true);
            $resp_info  = curl_getinfo($ch);
            
            Nuvei_Logger::write(is_array($resp_array) ? $resp_array : $resp, 'Response');
            
			curl_close($ch);
			
			if (false === $resp) {
                Nuvei_Logger::write($resp_info, 'Response info');
                
				return array(
					'status' => 'ERROR',
					'message' => 'REST API ERROR: response is false'
				);
			}
			
			return $resp_array;
		}
        catch (Exception $e) {
			return array(
				'status' => 'ERROR',
				'message' => 'Exception ERROR when call REST API: ' . $e->getMessage()
			);
		}
	}
	
	/**
	 * Function get_device_details
	 *
	 * Get browser and device based on HTTP_USER_AGENT.
	 * The method is based on D3D payment needs.
	 *
	 * @return array $device_details
	 */
	protected function get_device_details()
    {
		$device_details = array(
			'deviceType'    => 'UNKNOWN', // DESKTOP, SMARTPHONE, TABLET, TV, and UNKNOWN
			'deviceName'    => 'UNKNOWN',
			'deviceOS'      => 'UNKNOWN',
			'browser'       => 'UNKNOWN',
			'ipAddress'     => '0.0.0.0',
		);
		
		if (empty($_SERVER['HTTP_USER_AGENT'])) {
			$device_details['Warning'] = 'User Agent is empty.';
			
			return $device_details;
		}
		
		$user_agent = strtolower(filter_var($_SERVER['HTTP_USER_AGENT'], FILTER_SANITIZE_STRING));
		
		if (empty($user_agent)) {
			$device_details['Warning'] = 'Probably the merchant Server has problems with PHP filter_var function!';
			
			return $device_details;
		}
		
		$device_details['deviceName'] = $user_agent;

		foreach (NUVEI_DEVICES_TYPES_LIST as $d) {
			if (strstr($user_agent, $d) !== false) {
				if (in_array($d, array('linux', 'windows', 'macintosh'), true)) {
					$device_details['deviceType'] = 'DESKTOP';
				} elseif ('mobile' === $d) {
					$device_details['deviceType'] = 'SMARTPHONE';
				} elseif ('tablet' === $d) {
					$device_details['deviceType'] = 'TABLET';
				} else {
					$device_details['deviceType'] = 'TV';
				}

				break;
			}
		}

		foreach (NUVEI_DEVICES_LIST as $d) {
			if (strstr($user_agent, $d) !== false) {
				$device_details['deviceOS'] = $d;
				break;
			}
		}

		foreach (NUVEI_BROWSERS_LIST as $b) {
			if (strstr($user_agent, $b) !== false) {
				$device_details['browser'] = $b;
				break;
			}
		}

		// get ip
		if (!empty($_SERVER['REMOTE_ADDR'])) {
			$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP);
		}
		if (empty($ip_address) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip_address = filter_var($_SERVER['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP);
		}
		if (empty($ip_address) && !empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip_address = filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP);
		}
		if (!empty($ip_address)) {
			$device_details['ipAddress'] = (string) $ip_address;
		} else {
			$device_details['Warning'] = array(
				'REMOTE_ADDR'			=> empty($_SERVER['REMOTE_ADDR'])
					? '' : filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP),
				'HTTP_X_FORWARDED_FOR'	=> empty($_SERVER['HTTP_X_FORWARDED_FOR'])
					? '' : filter_var($_SERVER['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP),
				'HTTP_CLIENT_IP'		=> empty($_SERVER['HTTP_CLIENT_IP'])
					? '' : filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP),
			);
		}
		
		return $device_details;
	}
	
	/**
	 * A help function to get Products data from the Cart and pass it to the OpenOrder or UpdateOrder.
	 * 
	 * @return array $data
	 */
	protected function get_products_data()
    {
		global $woocommerce;
		
		$items = $woocommerce->cart->get_cart();
		$data  = array(
			'wc_subscr'     => false,
			'subscr_data'	=> [],
			'products_data'	=> [],
			'totals'        => $woocommerce->cart->get_totals(),
		);
        
        Nuvei_Logger::write($items);
        
		foreach ($items as $item_id => $item) {
			$cart_product           = wc_get_product( $item['product_id'] );
			$cart_prod_attr         = $cart_product->get_attributes();
            $nuvei_taxonomy_name    = wc_attribute_taxonomy_name(Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME));
            $nuvei_plan_variation   = 'attribute_' . $nuvei_taxonomy_name;
            
            // get short items data, we use it for Cashier url
			$data['products_data'][] = array(
                'product_id'    => $item['product_id'],
				'quantity'      => $item['quantity'],
				'price'         => get_post_meta($item['product_id'] , '_price', true),
				'name'          => $cart_product->get_title(),
				'in_stock'      => $cart_product->is_in_stock(),
                'item_id'       => $item_id,
			);
            
            Nuvei_Logger::write([
                'nuvei taxonomy name'   => $nuvei_taxonomy_name,
                'product attributes'    => $cart_prod_attr
            ]);
            
            // check for WCS
            if (false !== strpos($cart_product->get_type(), 'subscription')) {
                $data['wc_subscr'] = true;
                continue;
            }
            
            # check for product with Nuvei Payment Plan variation
            // We will not add the products with "empty plan" into subscr_data array!
            if (!empty($item['variation']) 
                && 0 != $item['variation_id']
                && array_key_exists($nuvei_plan_variation, $item['variation'])
            ) {
                $term = get_term_by('slug', $item['variation'][$nuvei_plan_variation], $nuvei_taxonomy_name);
                
                Nuvei_Logger::write((array) $term, '$term');

                if (is_wp_error($term) || empty($term->term_id)) {
                    Nuvei_Logger::write(
                        $item['variation'][$nuvei_plan_variation],
                        'Error when try to get Term by Slug'
                    );
                    
                    continue;
                }
                
                $term_meta = get_term_meta($term->term_id);
                
                Nuvei_Logger::write((array) $term_meta, '$term_meta');
                
                if (empty($term_meta['planId'][0])) {
                    continue;
                }

                $data['subscr_data'][] = [
                    'variation_id'      => $item['variation_id'],
                    'planId'			=> $term_meta['planId'][0],
                    'recurringAmount'	=> number_format($term_meta['recurringAmount'][0] * $item['quantity'], 2, '.', ''),
                    'recurringPeriod'   => [
                        $term_meta['recurringPeriodUnit'][0] => $term_meta['recurringPeriodPeriod'][0],
                    ],
                    'startAfter'        => [
                        $term_meta['startAfterUnit'][0] => $term_meta['startAfterPeriod'][0],
                    ],
                    'endAfter'          => [
                        $term_meta['endAfterUnit'][0] => $term_meta['endAfterPeriod'][0],
                    ],
                    'item_id'           => $item_id,
                ];
                
                continue;
            }
            # /check for product with Nuvei Payment Plan variation
            
            # check if product has only Nuvei Payment Plan Attribute
            foreach ($cart_prod_attr as $attr) {
                Nuvei_Logger::write((array) $attr, '$attr');
                
                $name = $attr->get_name();
                
                // if the attribute name is not nuvei taxonomy name go to next attribute
                if ($name != $nuvei_taxonomy_name) {
                    Nuvei_Logger::write($name, 'Not Nuvei attribute, check the next one.');
                    continue;
                }
                
                $attr_option = current($attr->get_options());
                
                // get all terms for this product ID
//                $terms = wp_get_post_terms( $item['product_id'], $name, 'all' );
                $terms = wp_get_post_terms( $item['product_id'], $name, ['term_id' => $attr_option] );
                
                if (is_wp_error($terms)) {
                    continue;
                }
                
                $nuvei_plan_term    = current($terms);
                $term_meta          = get_term_meta($nuvei_plan_term->term_id);
                
                // in case of missing Nuvei Plan ID
                if (empty($term_meta['planId'][0])) {
                    Nuvei_Logger::write($term_meta, 'Iteam with attribute $term_meta');
                    continue;
                }
                
                // in this case we do not have variation_id, only product_id
                $data['subscr_data'][] = [
                    'product_id'        => $item['product_id'],
                    'planId'			=> $term_meta['planId'][0],
                    'recurringAmount'	=> number_format($term_meta['recurringAmount'][0] * $item['quantity'], 2, '.', ''),
                    'recurringPeriod'   => [
                        $term_meta['recurringPeriodUnit'][0] => $term_meta['recurringPeriodPeriod'][0],
                    ],
                    'startAfter'        => [
                        $term_meta['startAfterUnit'][0] => $term_meta['startAfterPeriod'][0],
                    ],
                    'endAfter'          => [
                        $term_meta['endAfterUnit'][0] => $term_meta['endAfterPeriod'][0],
                    ],
                    'item_id'           => $item_id,
                ];
            }
            # /check if product has only Nuvei Payment Plan Attribute
		}

        Nuvei_Logger::write($data, 'get_products_data() data');
        
		return $data;
	}
	
    /**
     * A common function to set some data into the session.
     * 
     * @param string $session_token
     * @param array $last_req_details Some details from last opne/update order request.
     * @param array $product_data Short product and subscription data.
     */
    protected function set_nuvei_session_data($session_token, $last_req_details, $product_data)
    {
        WC()->session->set('nuvei_last_open_order_details', $last_req_details);
        WC()->session->set(
            'nuvei_order_details',
            [
                $session_token => [
                    'wc_subscr'     => $product_data['wc_subscr'],
                    'subscr_data'   => $product_data['subscr_data'],
//                    'products_data' => $product_data['products_data'],
                ]
            ]
        );
    }
    /**
	 * Get the request endpoint - sandbox or production.
	 * 
	 * @return string
	 */
	private function get_endpoint_base()
    {
		if ('yes' == $this->plugin_settings['test']) {
			return NUVEI_REST_ENDPOINT_INT;
		}
		
		return NUVEI_REST_ENDPOINT_PROD;
	}
	
	/**
	 * Validate some of the parameters in the request by predefined criteria.
	 * 
	 * @param array $params
	 * @return array
	 */
	private function validate_parameters( $params)
    {
		// directly check the mails
		if (isset($params['billingAddress']['email'])) {
			if (!filter_var($params['billingAddress']['email'], NUVEI_PARAMS_VALIDATION_EMAIL['flag'])) {
				
				return array(
					'status' => 'ERROR',
					'message' => 'REST API ERROR: The parameter Billing Address Email is not valid.'
				);
			}
			
			if (strlen($params['billingAddress']['email']) > NUVEI_PARAMS_VALIDATION_EMAIL['length']) {
				return array(
					'status' => 'ERROR',
					'message' => 'REST API ERROR: The parameter Billing Address Email must be maximum '
						. NUVEI_PARAMS_VALIDATION_EMAIL['length'] . ' symbols.'
				);
			}
		}
		
		if (isset($params['shippingAddress']['email'])) {
			if (!filter_var($params['shippingAddress']['email'], NUVEI_PARAMS_VALIDATION_EMAIL['flag'])) {
				return array(
					'status' => 'ERROR',
					'message' => 'REST API ERROR: The parameter Shipping Address Email is not valid.'
				);
			}
			
			if (strlen($params['shippingAddress']['email']) > NUVEI_PARAMS_VALIDATION_EMAIL['length']) {
				return array(
					'status' => 'ERROR',
					'message' => 'REST API ERROR: The parameter Shipping Address Email must be maximum '
						. NUVEI_PARAMS_VALIDATION_EMAIL['length'] . ' symbols.'
				);
			}
		}
		// directly check the mails END
		
		foreach ($params as $key1 => $val1) {
			if (!is_array($val1) && !empty($val1) && array_key_exists($key1, NUVEI_PARAMS_VALIDATION)) {
				$new_val = $val1;
				
				if (mb_strlen($val1) > NUVEI_PARAMS_VALIDATION[$key1]['length']) {
					$new_val = mb_substr($val1, 0, NUVEI_PARAMS_VALIDATION[$key1]['length']);
				}
				
				$params[$key1] = filter_var($new_val, NUVEI_PARAMS_VALIDATION[$key1]['flag']);
				
				if (!$params[$key1]) {
					$params[$key1] = 'The value is not valid.';
				}
			} elseif (is_array($val1) && !empty($val1)) {
				foreach ($val1 as $key2 => $val2) {
					if (!is_array($val2) && !empty($val2) && array_key_exists($key2, NUVEI_PARAMS_VALIDATION)) {
						$new_val = $val2;

						if (mb_strlen($val2) > NUVEI_PARAMS_VALIDATION[$key2]['length']) {
							$new_val = mb_substr($val2, 0, NUVEI_PARAMS_VALIDATION[$key2]['length']);
						}

						$params[$key1][$key2] = filter_var($new_val, NUVEI_PARAMS_VALIDATION[$key2]['flag']);
						
						if (!$params[$key1][$key2]) {
							$params[$key1][$key2] = 'The value is not valid.';
						}
					}
				}
			}
		}
		
		return $params;
	}
	
}
