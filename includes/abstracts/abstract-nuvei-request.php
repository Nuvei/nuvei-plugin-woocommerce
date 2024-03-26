<?php

defined( 'ABSPATH' ) || exit;

/**
 * The base class for requests. The different requests classes inherit this one.
 * Some common methods are also here.
 */
abstract class Nuvei_Request
{
    protected $rest_params = [];
	protected $plugin_settings;
	protected $request_base_params;
	protected $sc_order;
    protected $nuvei_gw;

    private $device_types = [];
	
	abstract public function process();
	abstract protected function get_checksum_params();

	/**
	 * Set variables.
	 * Description of merchantDetails:
	 * 
	 * 'merchantDetails'	=> array(
	 *      'customField1'  => string,  // WC Order total
	 *      'customField2'  => string,  // WC Order currency
	 *      'customField3'  => int,     // create time time()
	 *  ),
	 */
	public function __construct()
    {
        $plugin_data    = get_plugin_data(plugin_dir_path(NUVEI_PLUGIN_FILE) . 'index.php');
        $this->nuvei_gw = WC()->payment_gateways->payment_gateways()[NUVEI_GATEWAY_NAME];
		$time           = gmdate('Ymdhis');
		
		$this->request_base_params = array(
			'merchantId'        => trim($this->nuvei_gw->get_option('merchantId')),
			'merchantSiteId'    => trim($this->nuvei_gw->get_option('merchantSiteId')),
//			'clientRequestId'   => $time . '_' . uniqid(),
			'clientRequestId'   => uniqid('', true),
			'timeStamp'         => $time,
			'webMasterId'       => 'WooCommerce ' . WOOCOMMERCE_VERSION . '; Plugin v' . nuvei_get_plugin_version(),
			'sourceApplication' => NUVEI_SOURCE_APPLICATION,
			'encoding'          => 'UTF-8',
			'deviceDetails'     => $this->get_device_details(),
		);
        
        $this->request_base_params['merchantDetails']['customField3'] = time();
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
//	protected function is_order_valid($order_id, $return = false)
	protected function is_order_valid($order_id)
    {
		Nuvei_Logger::write($order_id, 'is_order_valid() check.');
		
		$this->sc_order = wc_get_order( $order_id );
		
        // error
		if ( ! is_a( $this->sc_order, 'WC_Order') ) {
            $msg = 'Error - Provided Order ID is not a WC Order';
			Nuvei_Logger::write($order_id, $msg);
			exit($msg);
		}
		
		Nuvei_Logger::write('The Order is valid.');
		
		// in case of Subscription states DMNs - stop proccess here. We will save only a message to the Order.
		if ('subscription' == Nuvei_Http::get_param('dmnType')) {
			return;
		}
		
		// check for 'sc' also because of the older Orders
		if (!in_array($this->sc_order->get_payment_method(), array(NUVEI_GATEWAY_NAME, 'sc'))) {
            $msg = 'Error - the order does not belongs to Nuvei.';
			Nuvei_Logger::write(
				array(
					'order_id'          => $order_id,
					'payment_method'    => $this->sc_order->get_payment_method()
				), 
				$msg
			);
			
			exit($msg);
		}
		
		// can we override Order status (state)
		$ord_status = strtolower($this->sc_order->get_status());
		
		if ( in_array($ord_status, array('cancelled', 'refunded')) ) {
            $msg = 'Error - can not override status of Voided/Refunded Order.';
            Nuvei_Logger::write($this->sc_order->get_payment_method(), $msg);
			
			exit($msg);
		}
		
        // do not replace "completed" with "auth" status
		if ('completed' == $ord_status
			&& 'auth' == strtolower(Nuvei_Http::get_param('transactionType'))
		) {
            $msg = 'Error - can not override status Completed with Auth.';
			Nuvei_Logger::write($this->sc_order->get_payment_method(), $msg);
			
			exit($msg);
		}
		// can we override Order status (state) END
	}
	
	/**
	 * Help function to generate Billing and Shipping details.
	 * 
	 * @global Woocommerce $woocommerce
     * 
	 * @return array
	 */
	protected function get_order_addresses()
    {
        // REST API flow
        if (!empty($this->rest_params)) {
            $addresses = [];
            
            if (!empty($this->rest_params['shipping_address'])) {
                $shipping_addr = trim( ($this->rest_params['shipping_address']['address_1'] ?? '')
                    . ' ' . ($this->rest_params['shipping_address']['address_2'] ?? '') );
                
                $addresses['shippingAddress'] = [
                    'firstName'	=> $this->rest_params['shipping_address']['first_name'] ?? '',
                    'lastName'  => $this->rest_params['shipping_address']['last_name'] ?? '',
                    'address'   => $shipping_addr,
                    'zip'       => $this->rest_params['shipping_address']['postcode'] ?? '',
                    'city'      => $this->rest_params['shipping_address']['city'] ?? '',
                    'country'   => $this->rest_params['shipping_address']['country'] ?? '',
                ];
            }
            
            if (!empty($this->rest_params['billing_address'])) {
                $billing_addr = trim( ($this->rest_params['billing_address']['address_1'] ?? '')
                    . ' ' . ($this->rest_params['billing_address']['address_2'] ?? '') );
                
                $addresses['billingAddress'] = [
                    "firstName" => $this->rest_params['billing_address']['first_name'] ?? '',
                    "lastName"  => $this->rest_params['billing_address']['last_name'] ?? '',
                    "address"   => $billing_addr,
                    "phone"     => $this->rest_params['billing_address']['phone'] ?? '',
                    "zip"       => $this->rest_params['billing_address']['postcode'] ?? '',
                    "city"      => $this->rest_params['billing_address']['city'] ?? '',
                    "country"   => $this->rest_params['billing_address']['country'] ?? '',
                    "state"     => $this->rest_params['billing_address']['state'] ?? '',
                    "email"     => $this->rest_params['billing_address']['email'] ?? '',
                ];
            }
            
            return $addresses;
        }
        
        #################################

        // default plugin flow
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
        $merchant_hash      = $this->nuvei_gw->get_option('hash_type');
        $merchant_secret    = trim($this->nuvei_gw->get_option('secret'));
        
		if (empty($merchant_hash) || empty($merchant_secret)) {
			return array(
				'status'    => 'ERROR',
				'message'   => 'Missing Plugin hash_type and secret params.'
			);
		}
		
		$concat = '';
		$resp   = false;
		$url    = $this->get_endpoint_base() . $method . '.do';
        
		if (isset($params['status']) && 'ERROR' == $params['status']) {
			return $params;
		}
		
		$all_params = array_merge_recursive($this->request_base_params, $params);
        // validate all params
        $all_params = $this->validate_parameters($all_params);
        
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
			$merchant_hash,
			$concat . $merchant_secret
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
                    LOG_REQUEST_PARAMS  => $all_params,
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
		
		$user_agent = strtolower(filter_var($_SERVER['HTTP_USER_AGENT']));
		
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
        // main variable to fill
        $data  = array(
            'wc_subscr'     => false,
            'subscr_data'	=> [],
            'products_data'	=> [],
            'totals'        => 0,
        );
        
        $nuvei_taxonomy_name    = wc_attribute_taxonomy_name(Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME));
        $nuvei_plan_variation   = 'attribute_' . $nuvei_taxonomy_name;
        
        // default plugin flow
        if (empty($this->rest_params)) {
            global $woocommerce;

            $items          = $woocommerce->cart->get_cart();
            $data['totals'] = $woocommerce->cart->get_totals();

            Nuvei_Logger::write($items);
            
            foreach ($items as $item_id => $item) {
//                Nuvei_Logger::write([ $item['product_id'], $item_id]);

                $cart_product           = wc_get_product( $item['product_id'] );
                $cart_prod_attr         = $cart_product->get_attributes();

                // get short items data, we use it for Cashier url
                $data['products_data'][] = array(
                    'product_id'    => $item['product_id'],
                    'quantity'      => $item['quantity'],
                    'price'         => get_post_meta($item['product_id'] , '_price', true),
                    'name'          => $cart_product->get_title(),
                    'in_stock'      => $cart_product->is_in_stock(),
                    'item_id'       => $item_id,
                );

//                Nuvei_Logger::write([
//                    'nuvei taxonomy name'   => $nuvei_taxonomy_name,
//                    'product attributes'    => $cart_prod_attr
//                ]);

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
        
        #################################
        
        // REST API flow
        $items          = $this->rest_params['items'] ?? [];
        $data['totals'] = $this->get_total_from_rest_params($this->rest_params);
        
        foreach ($items as $item) {
            $product_id     = $item['product_id'] ?? $item['id'];
            $cart_product   = wc_get_product( $product_id );
            $cart_prod_attr = $cart_product->get_attributes();
            
            // get short items data
            $data['products_data'][] = array(
                'product_id'    => $product_id,
                'quantity'      => $item['quantity'],
                'price'         => get_post_meta($product_id , '_price', true),
                'name'          => $cart_product->get_title(),
                'in_stock'      => $cart_product->is_in_stock(),
                'item_id'       => $item['key'] ?? '',
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
            if (!empty($item['variation']) 
                && 0 != $item['id']
                && array_key_exists($nuvei_taxonomy_name, $cart_prod_attr)
            ) {
                $term = get_term_by(
                    'slug',
                    $cart_prod_attr[$nuvei_taxonomy_name],
                    $nuvei_taxonomy_name
                );

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
//                    'variation_id'      => $item['variation_id'],
                    'variation_id'      => $item['id'],
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
                    'item_id'           => $item['key'] ?? '',
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
                $terms = wp_get_post_terms( $item['id'], $name, ['term_id' => $attr_option] );

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
                    'product_id'        => $item['id'],
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
                    'item_id'           => $item['key'] ?? '',
                ];
            }
            # /check if product has only Nuvei Payment Plan Attribute
        }
        
        Nuvei_Logger::write($data, 'get_products_data() data');

        return $data;
	}
    
    /**
     * A help function to extract the total from Cart passed with REST API request.
     * 
     * @param array $rest_params
     * @return string
     */
//    protected function get_total_from_rest_params($rest_params)
    protected function get_total_from_rest_params()
    {
        if (isset($this->rest_params['totals']['total_price'], 
                $this->rest_params['totals']['currency_minor_unit'])
        ) {
            $min_unit   = $this->rest_params['totals']['currency_minor_unit'];
            $delimeter  = 1;
            
            for ($cnt = 0; $cnt < $min_unit; $cnt++) {
                $delimeter *= 10;
            }
            
            $price = round(($this->rest_params['totals']['total_price'] / $delimeter), 2);
            
            return (string) number_format($price, 2, '.', '');
        }
        
        return '0';
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
        WC()->session->set(NUVEI_SESSION_OO_DETAILS, $last_req_details);
        WC()->session->set(
            NUVEI_SESSION_PROD_DETAILS,
            [
                $session_token => [
                    'wc_subscr'             => $product_data['wc_subscr'],
                    'subscr_data'           => $product_data['subscr_data'],
//                    'products_data' => $product_data['products_data'],
                    'products_data_hash'    => md5(serialize($product_data)),
                ]
            ]
        );
    }
    
    /**
     * Just a helper function to extract last of Nuvei transactions.
     * It is possible to set array of desired types. First found will
     * be returned.
     * 
     * @param array $transactions List with all transactions
     * @param array $types Search for specific type/s.
     * 
     * @return array
     */
    protected function get_last_transaction(array $transactions, array $types = [])
    {
        if (empty($transactions) || !is_array($transactions)) {
            Nuvei_Logger::write($transactions, 'Problem with trnsactions array.');
            return [];
        }
        
        if (empty($types)) {
            return end($transactions);
        }
        
        foreach (array_reverse($transactions, true) as $trId => $data) {
            if (!empty($data['transactionType']) 
                && in_array($data['transactionType'], $types)
            ) {
                Nuvei_Logger::write($data);
                
                // fix for the case when work on Order made with plugin before v2.0.0
                if (!isset($data['transactionId'])) {
                    $data['transactionId'] = $trId;
                }
                
                Nuvei_Logger::write($data);
                
                return $data;
            }
        }
        
        return [];
    }
    
    /**
     * Temp help function until stop using old Order meta fields.
     * 
     * @param int|null $order_id WC Order ID
     * @param array $types Search for specific type/s.
     * 
     * @return int
     */
    protected function get_tr_id($order_id = null, $types = [])
    {
        $order = $this->get_order($order_id);
        
        // first check for new meta data
        $ord_tr_id = $order->get_meta(NUVEI_TR_ID);
        
        if (!empty($ord_tr_id)) {
            return $ord_tr_id;
        }
        
        $nuvei_data = $order->get_meta(NUVEI_TRANSACTIONS);
        
        if (!empty($nuvei_data) && is_array($nuvei_data)) {
            // just get from last transaction
            if (empty($types)) {
                $last_tr = end($nuvei_data);
            }
            // get last transaction by type
            else {
                $last_tr = $this->get_last_transaction($nuvei_data, $types);
            }
            
            if (!empty($last_tr['transactionId'])) {
                return $last_tr['transactionId'];
            }
        }
        
        // check for old meta data
        return $order->get_meta('_transactionId'); // NUVEI_TRANS_ID
    }
    
    /**
     * Temp help function until stop using old Order meta fields.
     * 
     * @param int|null $order_id WC Order ID
     * @return int
     */
    protected function get_tr_status($order_id = null)
    {
        $order = $this->get_order($order_id);
        
        // first check for new meta data
        $nuvei_data = $order->get_meta(NUVEI_TRANSACTIONS);
        
        if (!empty($nuvei_data) && is_array($nuvei_data)) {
            $last_tr = end($nuvei_data);
            
            if (!empty($last_tr['status'])) {
                return $last_tr['status'];
            }
        }
        
        // check for old meta data
        return $order->get_meta('_transactionStatus'); // NUVEI_TRANS_STATUS
    }
    
    /**
     * A help function for the above methods.
     */
    protected function get_order($order_id)
    {
        if (empty($this->sc_order)) {
            $order = wc_get_order($order_id);
        }
        elseif ($order_id == $this->sc_order->get_id()) {
            $order = $this->sc_order;
        }
        else {
            $order = wc_get_order($order_id);
        }
        
        return $order;
    }
    
    /**
     * Save main transaction data into a block as private meta field.
     * 
     * @param array $params     Optional list of parameters to search in.
     * @param int $wc_refund_id 
     * @return void
     */
    protected function save_transaction_data($params = [], $wc_refund_id = null)
    {
        Nuvei_Logger::write([$params, $wc_refund_id], 'save_transaction_data()');
        
        $transaction_id = Nuvei_Http::get_param('TransactionID', 'int', '', $params);
        
        if (empty($transaction_id)) {
            $transaction_id = Nuvei_Http::get_param('transactionId', 'int', '', $params);
        }
        if (empty($transaction_id)) {
            Nuvei_Logger::write($transaction_id, 'TransactionID param is empty!', 'CRITICAL');
            return;
        }
        
        // get previous data if exists
        $transactions_data = $this->sc_order->get_meta(NUVEI_TRANSACTIONS);
        // in case it is empty
        if (empty($transactions_data) || !is_array($transactions_data)) {
            $transactions_data = [];
        }
        
        $transactionType    = Nuvei_Http::get_param('transactionType', 'string', '', $params);
        $status             = Nuvei_Http::get_request_status();
        
        // check for already existing data
        if (!empty($transactions_data[$transaction_id])
            && $transactions_data[$transaction_id]['transactionType'] == $transactionType
            && $transactions_data[$transaction_id]['status'] == $status
        ) {
            Nuvei_Logger::write('We have information for this transaction and will not save it again.');
            return;
        }
        
        $transactions_data[$transaction_id]  = [
            'authCode'              => Nuvei_Http::get_param('AuthCode', 'string', '', $params),
            'paymentMethod'         => Nuvei_Http::get_param('payment_method', 'string', '', $params),
            'transactionType'       => $transactionType,
            'transactionId'         => $transaction_id,
            'relatedTransactionId'  => Nuvei_Http::get_param('relatedTransactionId','int', 0, $params),
            'totalAmount'           => Nuvei_Http::get_param('totalAmount', 'float', 0, $params),
            'currency'              => Nuvei_Http::get_param('currency', 'string', '', $params),
            'status'                => $status,
            'userPaymentOptionId'   => Nuvei_Http::get_param('userPaymentOptionId', 'int'),
            'wcsRenewal'            => 'renewal_order' == Nuvei_Http::get_param('customField4', 'string', '', $params) 
                ? true : false,
        ];
        
        if (null !== $wc_refund_id) {
            $transactions_data[$transaction_id]['wcRefundId'] = $wc_refund_id;
        }
        
        $this->sc_order->update_meta_data(NUVEI_TRANSACTIONS, $transactions_data);
        
        // update it only for Auth, Settle and Sale. They are base an we will need this TrID
        if (in_array($transactionType, ['Auth', 'Settle', 'Sale'])) {
            $this->sc_order->update_meta_data(NUVEI_TR_ID, $transaction_id);
        }
        
        if (isset($transactions_data['wcsRenewal'])) {
            $this->sc_order->update_meta_data(NUVEI_WC_RENEWAL, true);
        }
		
//        $this->sc_order->save();
    }
    
    /**
     * Single place to generate the client unique id parameter.
     * 
     * @param string $billing_email
     * @param array $products_data  Optional for the Auto-Void.
     * 
     * @return string $clientUniqueId
     */
    protected function get_client_unique_id($billing_email, $products_data = [])
    {
        $orderString    = $billing_email . '_' . serialize($products_data);
        $clientUniqueId = hash('crc32b', $orderString) . '_' .  uniqid('', true);
        
        return $clientUniqueId;
    }
    
    /**
	 * Get the request endpoint - sandbox or production.
	 * 
	 * @return string
	 */
	private function get_endpoint_base()
    {
		if ('yes' == $this->nuvei_gw->get_option('test')) {
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
        Nuvei_Logger::write('validate_parameters');
        
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
