<?php

	defined( 'ABSPATH' ) || exit;

	/**
	 * The base class for requests. The different requests classes inherit this one.
	 * Some common methods are also here.
	 */
abstract class Nuvei_Pfw_Request {

	protected $rest_params = array();
    protected $message;
	protected $plugin_settings;
	protected $request_base_params;
	protected $sc_order;
	protected $order_id;
	protected $nuvei_gw;
    
	private $device_types = array();

	abstract public function process();
	abstract protected function get_checksum_params();

	/**
	 * Set variables.
	 * Description of merchantDetails:
	 *
	 * 'merchantDetails'    => array(
	 *      'customField1'  => string,  // WC Order total
	 *      'customField2'  => string,  // WC Order currency
	 *      'customField3'  => int,     // create time time()
	 *  ),
	 */
	public function __construct() {
		$this->nuvei_gw = WC()->payment_gateways->payment_gateways()[ NUVEI_PFW_GATEWAY_NAME ];
		$time           = gmdate( 'Ymdhis' );

		$this->request_base_params = array(
			'merchantId'        => trim( (int) $this->nuvei_gw->get_option( 'merchantId' ) ),
			'merchantSiteId'    => trim( (int) $this->nuvei_gw->get_option( 'merchantSiteId' ) ),
			'clientRequestId'   => uniqid( '', true ),
			'timeStamp'         => $time,
			'webMasterId'       => $this->get_web_master_id(),
			'sourceApplication' => NUVEI_PFW_SOURCE_APPLICATION,
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
	 * @param bool       $return   - return response
	 *
	 * @return void
	 */
	protected function is_order_valid( $order_id, $return = false ) {
		Nuvei_Pfw_Logger::write( $order_id, 'is_order_valid() check.' );

		$this->sc_order = wc_get_order( $order_id );

		// error
		if ( ! is_a( $this->sc_order, 'WC_Order' ) ) {
			$this->message  = $msg 
                            = 'Error - Provided Order ID is not a WC Order';
			Nuvei_Pfw_Logger::write( $order_id, $msg );
            
            if ($return) {
                return false;
            }
            
			exit( esc_html( $msg ) );
		}

		Nuvei_Pfw_Logger::write( 'The Order is valid.' );

		// in case of Subscription states DMNs - stop proccess here. We will save only a message to the Order.
		if ( 'subscription' == Nuvei_Pfw_Http::get_param( 'dmnType' ) ) {
			return;
		}

		// check for 'sc' also because of the older Orders
        if ($return) {
            return $this->is_nuvei_order($order_id, $return);
        }
        
        $this->is_nuvei_order($order_id);
        
		// can we override Order status (state)
        if ($return) {
            return $this->can_override_order_status($return);
        }
        
        $this->can_override_order_status();
	}

	/**
	 * A help function to get webMasterId parameter.
	 *
	 * @return string
	 */
	protected function get_web_master_id() {
		return 'WooCommerce ' . WOOCOMMERCE_VERSION . '; Plugin v' . $this->get_plugin_version();
	}

	/**
	 * A helper function to get the plugin version.
	 *
	 * @return string
	 */
	protected function get_plugin_version() {
		$plugin_data = get_plugin_data( NUVEI_PFW_PLUGIN_FILE );

		return $plugin_data['Version'];
	}

	/**
	 * Help function to generate Billing and Shipping details.
     * We use this method for Carts and Orders.
     * In the method we will try to get the details from different sources.
	 *
	 * @return array
	 */
	protected function get_order_addresses() {
        Nuvei_Pfw_Logger::write( 'get_order_addresses()' );

		$billing_address        = array();
		$cart                   = WC()->cart;
        $existing_order_data    = array();

        if ( ! empty($this->rest_params['order_id'])
            && empty($this->sc_order)
        ) {
            $this->sc_order = wc_get_order($this->rest_params['order_id']);
        }
        
		if ( ! empty( $this->sc_order ) ) {
			$existing_order_data = $this->sc_order->get_data();
		}
        
		# Set billing params.
		// billing_first_name, for all check for Blocks and Classic formats
        $bfn = $this->rest_params['billing-first_name'] ?? $this->rest_params['billing_first_name'] ?? '';
        
        if (empty($bfn)) {
            $bfn = $this->rest_params['billing_address']['first_name'] ?? ''; // headless
        }
		if ( ! empty( $existing_order_data['billing']['first_name'] ) ) {
			$bfn = trim( (string) $existing_order_data['billing']['first_name'] );
		}
		if ( $cart && empty( $bfn ) ) {
			$bfn = trim( (string) $cart->get_customer()->get_billing_first_name() );
		}

		$billing_address['firstName'] = $bfn;

		// billing_last_name
        $bln = $this->rest_params['billing-last_name'] ?? $this->rest_params['billing_last_name'] ?? '';
        
        if ( empty($bln) ) {
            $bln = $this->rest_params['billing_address']['last_name'] ?? ''; // headless
        }
		if ( ! empty( $existing_order_data['billing']['last_name'] ) ) {
			$bln = trim( (string) $existing_order_data['billing']['last_name'] );
		}
		if ( $cart && empty( $bln ) ) {
			$bln = trim( (string) $cart->get_customer()->get_billing_last_name() );
		}

		$billing_address['lastName'] = $bln;

		// address
		$ba     = '';
        $ba_ln1 = $this->rest_params['billing-address_1'] ?? $this->rest_params['billing_address_1'] ?? '';
        $ba_ln2 = $this->rest_params['billing-address_2'] ?? $this->rest_params['billing_address_2'] ?? '';
        
        if (empty($ba_ln1)) {
            $ba_ln1 = $this->rest_params['billing_address']['address_1'] ?? '';
        }
        if (!empty ($ba_ln2)) {
            $ba_ln2 = $this->rest_params['billing_address']['address_2'] ?? '';
        }
        
        if ( ! empty( $ba_ln1 ) ) {
			$ba = $ba_ln1;

			if ( ! empty( $ba_ln2 ) ) {
				$ba .= ' ' . $ba_ln2;
			}
		}

		if ( ! empty( $existing_order_data['billing']['address_1'] ) ) {
			$ba_ln1 = trim( (string) $existing_order_data['billing']['address_1'] );

			if ( ! empty( $ba_ln1 ) ) {
				$ba = $ba_ln1;

				if ( ! empty( $existing_order_data['billing']['address_2'] ) ) {
					$ba .= ' ' . $existing_order_data['billing']['address_2'];
				}
			}
		}
		if ( $cart && empty( $ba ) ) {
			$ba_ln1 = trim( (string) $cart->get_customer()->get_billing_address() );
			$ba_ln2 = trim( (string) $cart->get_customer()->get_billing_address_2() );

			if ( ! empty( $ba_ln1 ) ) {
				$ba = $ba_ln1;

				if ( ! empty( $ba_ln2 ) ) {
						$ba .= ' ' . $ba_ln2;
				}
			}
		}

		$billing_address['address'] = $ba;

		// billing_phone
        $bp = $this->rest_params['billing-phone'] ?? $this->rest_params['billing_phone'] ?? '';
        
        if (empty($bp)) {
            $bp = $this->rest_params['billing_address']['phone'] ?? '';
        }
		if ( ! empty( $existing_order_data['billing']['phone'] ) ) {
			$bp = trim( (string) $existing_order_data['billing']['phone'] );
		}
		if ( $cart && empty( $bp ) ) {
			$bp = trim( (string) $cart->get_customer()->get_billing_phone() );
		}

		$billing_address['phone'] = $bp;

		// billing_postcode
        $bz = $this->rest_params['billing-postcode'] ?? $this->rest_params['billing_postcode'] ?? '';
        
        if (empty($bz)) {
            $bz = $this->rest_params['billing_address']['postcode'] ?? '';
        }
		if ( ! empty( $existing_order_data['billing']['postcode'] ) ) {
			$bz = trim( (string) $existing_order_data['billing']['postcode'] );
		}
		if ( $cart && empty( $bz ) ) {
			$bz = trim( (string) $cart->get_customer()->get_billing_postcode() );
		}

		$billing_address['zip'] = $bz;

		// billing_city
        $bc = $this->rest_params['billing-city'] ?? $this->rest_params['billing_city'] ?? '';
        
        if (empty($bc)) {
            $bc = $this->rest_params['billing_address']['city'] ?? '';
        }
		if ( ! empty( $existing_order_data['billing']['city'] ) ) {
			$bc = trim( (string) $existing_order_data['billing']['city'] );
		}
		if ( $cart && empty( $bc ) ) {
			$bc = trim( (string) $cart->get_customer()->get_billing_city() );
		}

		$billing_address['city'] = $bc ?? 'Missing parameter';

		// billing_country
		$bcn = $this->rest_params['billing-country'] ?? $this->rest_params['billing_country'] ?? '';
        
        if (empty($bcn)) {
            $bcn = $this->rest_params['billing_address']['country'] ?? '';
        }
		if ( ! empty( $existing_order_data['billing']['country'] ) ) {
			$bcn = trim( (string) $existing_order_data['billing']['country'] );
		}
		if ( $cart && empty( $bcn ) ) {
			$bcn = trim( (string) $cart->get_customer()->get_billing_country() );
		}

		$billing_address['country'] = $bcn;

		// billing state
		$bst = $this->rest_params['billing-state'] ?? $this->rest_params['billing_state'] ?? '';

        if (empty($bst)) {
            $bst = $this->rest_params['billing_address']['state'] ?? '';
        }
		if ( ! empty( $existing_order_data['billing']['state'] ) ) {
			$bst = trim( (string) $existing_order_data['billing']['state'] );
		}
		if ( $cart && empty( $bst ) ) {
			$bst = trim( (string) $cart->get_customer()->get_billing_state() );
		}

		$billing_address['state'] = $bst;

		// billing_email
		$be = $this->rest_params['email'] ?? $this->rest_params['billing_email'] ?? '';

        if (empty($be)) {
            $be = $this->rest_params['billing_address']['email'] ?? '';
        }
		if ( ! empty( $existing_order_data['billing']['email'] ) ) {
			$be = trim( (string) $existing_order_data['billing']['email'] );
		}
		if ( $cart && empty( $be ) ) {
            $be = trim( (string) $cart->get_customer()->get_billing_email() );
		}

		$billing_address['email'] = $be;
		// set billing params END

		# Set shipping params. When we do openOrder we do not pass shipping details.
		// shipping first name
		$sfn = $this->rest_params['shipping_address']['first_name'] ?? '';

		if ( ! empty( $existing_order_data['shipping']['first_name'] ) ) {
			$sfn = trim( (string) $existing_order_data['shipping']['first_name'] );
		}
		if ( $cart && empty( $sfn ) ) {
			$sfn = trim( (string) $cart->get_customer()->get_shipping_first_name() );
		}

		// shippinh last name
		$sln = $this->rest_params['shipping_address']['last_name'] ?? '';

		if ( ! empty( $existing_order_data['shipping']['last_name'] ) ) {
			$sln = trim( (string) $existing_order_data['shipping']['last_name'] );
		}
		if ( $cart && empty( $sln ) ) {
			$sln = trim( (string) $cart->get_customer()->get_shipping_last_name() );
		}

		// shipping address
		$sa = trim(
                (string) ( $this->rest_params['shipping_address']['address_1'] ?? '' )
                . ' ' . (string) ( $this->rest_params['shipping_address']['address_2'] ?? '' )
            );

		if ( ! empty( $existing_order_data['shipping']['address_1'] ) ) {
			$sa = trim( (string) $existing_order_data['shipping']['address_1'] );

			if ( ! empty( $existing_order_data['shipping']['address_2'] ) ) {
				$sa .= ' ' . trim( (string) $existing_order_data['shipping']['address_2'] );
			}
		}
		if ( $cart && empty( $sa ) ) {
			$sa = trim(
				(string) $cart->get_customer()->get_shipping_address() . ' '
				. (string) $cart->get_customer()->get_shipping_address_2()
			);
		}

		// shipping zip
		$sz = $this->rest_params['shipping_address']['postcode'] ?? '';

		if ( ! empty( $existing_order_data['shipping']['postcode'] ) ) {
			$sz = trim( (string) $existing_order_data['shipping']['postcode'] );
		}
		if ( $cart && empty( $sz ) ) {
			$sz = trim( (string) $cart->get_customer()->get_shipping_postcode() );
		}

		// shipping city
		$sc = $this->rest_params['shipping_address']['city'] ?? '';

		if ( ! empty( $existing_order_data['shipping']['city'] ) ) {
			$sc = trim( (string) $existing_order_data['shipping']['city'] );
		}
		if ( $cart && empty( $sc ) ) {
			$sc = trim( (string) $cart->get_customer()->get_shipping_city() );
		}

		// shipping country
		$scn = $this->rest_params['shipping_address']['country'] ?? '';

		if ( ! empty( $existing_order_data['shipping']['country'] ) ) {
			$scn = trim( (string) $existing_order_data['shipping']['country'] );
		}
		if ( $cart && empty( $scn ) ) {
			$scn = trim( (string) $cart->get_customer()->get_shipping_country() );
		}

		return array(
			'billingAddress'  => $billing_address,
			'shippingAddress' => array(
				'firstName' => $sfn,
				'lastName'  => $sln,
				'address'   => $sa,
				'zip'       => $sz,
				'city'      => $sc,
				'country'   => $scn,
			),
		);
	}

	/**
	 * Call REST API with cURL post and get response.
	 * The URL depends from the case.
	 *
	 * @param string $method   API method.
	 * @param array $params    Parameters.
	 *
	 * @return mixed
	 */
	protected function call_rest_api( $method, $params ) {
		$merchant_hash   = $this->nuvei_gw->get_option( 'hash_type' );
		$merchant_secret = trim( (string) $this->nuvei_gw->get_option( 'secret' ) );

		if ( empty( $merchant_hash ) || empty( $merchant_secret ) ) {
			return array(
				'status'  => 'ERROR',
				'message' => 'Missing Plugin hash_type and secret params.',
			);
		}

		$concat = '';
		$resp   = false;
		$url    = $this->get_endpoint_base() . $method . '.do';

		if ( isset( $params['status'] ) && 'ERROR' == $params['status'] ) {
			return $params;
		}

		$all_params = array_merge_recursive( $this->request_base_params, $params );
		// validate all params
		$all_params = $this->validate_parameters( $all_params );

			// Error. if there is validation error and Satus was set to Error return the response.
		if ( isset( $all_params['status'] ) && 'error' == strtolower( $all_params['status'] ) ) {
			Nuvei_Pfw_Logger::write( $all_params, 'Error before call the REST API during the validation' );
			return $all_params;
		}

		// use incoming clientRequestId instead of auto generated one
		if ( ! empty( $params['clientRequestId'] ) ) {
			$all_params['clientRequestId'] = $params['clientRequestId'];
		}

		// add the checksum
		$checksum_keys = $this->get_checksum_params( $method );

		if ( is_array( $checksum_keys ) ) {
			foreach ( $checksum_keys as $key ) {
				if ( isset( $all_params[ $key ] ) ) {
					$concat .= $all_params[ $key ];
				}
			}
		}

		$all_params['checksum'] = hash(
			$merchant_hash,
			$concat . $merchant_secret
		);
		// add the checksum END

		try {
			Nuvei_Pfw_Logger::write(
				array(
					'Request URL'                => $url,
					NUVEI_PFW_LOG_REQUEST_PARAMS => $all_params,
				),
				'Nuvei Request data'
			);

			$resp = wp_remote_post(
				$url,
				array(
					'headers'   => array(
						'Content-Type' => 'application/json',
					),
					'sslverify' => false,
					'timeout'   => 45,
					'body'      => wp_json_encode( $all_params ),
				)
			);

				Nuvei_Pfw_Logger::write( $resp, 'Response info' );

			if ( false === $resp || ! is_array( $resp ) || empty( $resp['body'] ) ) {
				return array(
					'status'  => 'ERROR',
					'message' => 'REST API ERROR: response is false',
				);
			}

			return json_decode( $resp['body'], true );
		} catch ( Exception $e ) {
			return array(
				'status'  => 'ERROR',
				'message' => 'Exception ERROR when call REST API: ' . $e->getMessage(),
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
	protected function get_device_details() {
		$device_details = array(
			'deviceType' => 'UNKNOWN', // DESKTOP, SMARTPHONE, TABLET, TV, and UNKNOWN
			'deviceName' => 'UNKNOWN',
			'deviceOS'   => 'UNKNOWN',
			'browser'    => 'UNKNOWN',
			'ipAddress'  => '0.0.0.0',
		);

        // error
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$device_details['Warning'] = 'User Agent is empty.';

			return $device_details;
		}

		$user_agent = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) );

        // error
		if ( empty( $user_agent ) ) {
			$device_details['Warning'] = 'Probably the merchant Server has problems with PHP filter_var function!';

			return $device_details;
		}

		$device_details['deviceName'] = $user_agent;

		foreach ( NUVEI_PFW_DEVICES_TYPES_LIST as $d ) {
			if ( strstr( $user_agent, $d ) !== false ) {
				if ( in_array( $d, array( 'linux', 'windows', 'macintosh' ), true ) ) {
						$device_details['deviceType'] = 'DESKTOP';
				} elseif ( 'mobile' === $d ) {
					$device_details['deviceType'] = 'SMARTPHONE';
				} elseif ( 'tablet' === $d ) {
					$device_details['deviceType'] = 'TABLET';
				} else {
					$device_details['deviceType'] = 'TV';
				}

				break;
			}
		}

		foreach ( NUVEI_PFW_DEVICES_LIST as $d ) {
			if ( strstr( $user_agent, $d ) !== false ) {
				$device_details['deviceOS'] = $d;
				break;
			}
		}

		foreach ( NUVEI_PFW_BROWSERS_LIST as $b ) {
			if ( strstr( $user_agent, $b ) !== false ) {
				$device_details['browser'] = $b;
				break;
			}
		}

		// get ip
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip_address = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP );
		}
		if ( empty( $ip_address ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip_address = filter_var( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ), FILTER_VALIDATE_IP );
		}
		if ( empty( $ip_address ) && ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip_address = filter_var( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ), FILTER_VALIDATE_IP );
		}
		if ( ! empty( $ip_address ) ) {
			$device_details['ipAddress'] = (string) $ip_address;
		} else {
			$device_details['Warning'] = array(
				'REMOTE_ADDR'          => empty( $_SERVER['REMOTE_ADDR'] )
				? '' : filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ),
				'HTTP_X_FORWARDED_FOR' => empty( $_SERVER['HTTP_X_FORWARDED_FOR'] )
				? '' : filter_var( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ), FILTER_VALIDATE_IP ),
				'HTTP_CLIENT_IP'       => empty( $_SERVER['HTTP_CLIENT_IP'] )
				? '' : filter_var( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ), FILTER_VALIDATE_IP ),
			);
		}

		return $device_details;
	}

	/**
	 * A help function to get Products data from the Cart and pass it to the OpenOrder or UpdateOrder.
	 *
	 * @return array $data
	 */
	protected function get_products_data() {
        Nuvei_Pfw_Logger::write( 'get_products_data()' );

		// main variable to fill
		$data = array(
			'wc_subscr'     => false,
			'subscr_data'   => array(),
			'products_data' => array(),
			'totals'        => 0,
		);

		$nuvei_taxonomy_name    = wc_attribute_taxonomy_name( Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME ) );
		$nuvei_plan_variation   = 'attribute_' . $nuvei_taxonomy_name;
        $items                  = [];

        if (!empty($this->rest_params['items'])) {
            $items          = $this->rest_params['items'];
            $data['totals'] = $this->get_total_from_rest_params();
        }
        elseif (!empty($this->sc_order)) {
            $items = $this->sc_order->get_items();
            
            if ( ! empty( $items ) ) {
                $data['totals'] = array( 'total' => $this->sc_order->get_total() );
            }
        }
        elseif (!empty(WC()->cart)) {
            $items = WC()->cart->get_cart();
            
            if ( ! empty( $items ) ) {
                $data['totals'] = WC()->cart->get_totals();
            }
        }
        
        // error
        if ( empty( $items ) ) {
            Nuvei_Pfw_Logger::write( $items, 'There are no items.' );
            return $data;
        }
        
        // check the items
		foreach ( $items as $item ) {
			// Normalize: REST items use 'id', Cart and Order items use 'product_id'
			$product_id   = $item['product_id'] ?? $item['id'] ?? 0;
			$variation_id = $item['variation_id'] ?? $item['id'] ?? 0;
			$cart_product = wc_get_product( $product_id );
            
            if ( empty($cart_product) ) {
                Nuvei_Pfw_Logger::write( $item, 'Could not load product, skip item.' );
                continue;
            }
            
			$cart_prod_attr = $cart_product->get_attributes();
            
			// get short items data
			$data['products_data'][] = array(
				'product_id' => $product_id,
				'quantity'   => $item['quantity'],
				'price'      => get_post_meta( $product_id, '_price', true ),
				'name'       => $cart_product->get_title(),
				'in_stock'   => $cart_product->is_in_stock(),
				'item_id'    => $item['key'] ?? '',
			);

			Nuvei_Pfw_Logger::write(
				array(
					'nuvei taxonomy name'   => $nuvei_taxonomy_name,
					'product attributes'    => $cart_prod_attr,
					'variation'             => $item['variation'] ?? [],
					'$variation_id'         => $variation_id,
				)
			);

			// check for WCS
			if ( false !== strpos( $cart_product->get_type(), 'subscription' ) ) {
				$data['wc_subscr'] = true;
				continue;
			}

			// Normalize variation attributes. Cart items carry $item['variation'] as an array,
			// but WC_Order_Item_Product objects (admin-created or order-pay orders) do not —
			// their variation data must be read from the WC_Product_Variation object directly.
			$variation_attr = array();
            
			if ( ! empty( $item['variation'] ) ) {
				$variation_attr = $item['variation'];
			}
            elseif ( $variation_id && $variation_id != $product_id ) {
				$variation_product = wc_get_product( $variation_id );
				
                if ( $variation_product ) {
					foreach ( $variation_product->get_attributes() as $attr_key => $attr_val ) {
						$variation_attr[ 'attribute_' . $attr_key ] = $attr_val;
					}
				}
			}

			// check for product with Nuvei Payment Plan variation
			if ( ! empty( $variation_attr )
				&& 0 != $variation_id
				&& array_key_exists( $nuvei_taxonomy_name, $cart_prod_attr )
			) {
				// The slug comes from the selected variation, not from the attribute object
				$variation_slug = $variation_attr[ $nuvei_plan_variation ] ?? '';

				if ( empty( $variation_slug ) ) {
					Nuvei_Pfw_Logger::write( $variation_attr, 'Missing variation slug for ' . $nuvei_plan_variation );
					continue;
				}

				$term = get_term_by( 'slug', $variation_slug, $nuvei_taxonomy_name );

				Nuvei_Pfw_Logger::write( (array) $term, '$term' );

				if ( is_wp_error( $term ) || empty( $term->term_id ) ) {
					Nuvei_Pfw_Logger::write(
						$variation_attr[ $nuvei_plan_variation ] ?? '',
						'Error when try to get Term by Slug'
					);

							continue;
				}

				$term_meta = get_term_meta( $term->term_id );

				Nuvei_Pfw_Logger::write( (array) $term_meta, '$term_meta' );

				if ( empty( $term_meta['planId'][0] ) ) {
					continue;
				}

				$data['subscr_data'][] = array(
					'variation_id'    => $variation_id,
					'planId'          => $term_meta['planId'][0],
					'recurringAmount' => number_format( $term_meta['recurringAmount'][0] * $item['quantity'], 2, '.', '' ),
					'recurringPeriod' => array(
						$term_meta['recurringPeriodUnit'][0] => $term_meta['recurringPeriodPeriod'][0],
					),
					'startAfter'      => array(
						$term_meta['startAfterUnit'][0] => $term_meta['startAfterPeriod'][0],
					),
					'endAfter'        => array(
						$term_meta['endAfterUnit'][0] => $term_meta['endAfterPeriod'][0],
					),
					'item_id'         => $item['key'] ?? '',
				);

				continue;
			}
			// /check for product with Nuvei Payment Plan variation

			// check if product has only Nuvei Payment Plan Attribute
			foreach ( $cart_prod_attr as $attr ) {
				Nuvei_Pfw_Logger::write( (array) $attr, '$attr' );

				$name = $attr->get_name();

				// if the attribute name is not nuvei taxonomy name go to next attribute
				if ( $name != $nuvei_taxonomy_name ) {
                    Nuvei_Pfw_Logger::write( $name, 'Not Nuvei attribute, check the next one.' );
                    continue;
				}

				$attr_option = current( $attr->get_options() );

				// get all terms for this product ID
				$terms = wp_get_post_terms( $product_id, $name, array( 'term_id' => $attr_option ) );

				if ( is_wp_error( $terms ) ) {
					continue;
				}

				$nuvei_plan_term = current( $terms );
				$term_meta       = get_term_meta( $nuvei_plan_term->term_id );

				// in case of missing Nuvei Plan ID
				if ( empty( $term_meta['planId'][0] ) ) {
					Nuvei_Pfw_Logger::write( $term_meta, 'Iteam with attribute $term_meta' );
					continue;
				}

				// in this case we do not have variation_id, only product_id
				$data['subscr_data'][] = array(
					'product_id'      => $product_id,
					'planId'          => $term_meta['planId'][0],
					'recurringAmount' => number_format( $term_meta['recurringAmount'][0] * $item['quantity'], 2, '.', '' ),
					'recurringPeriod' => array(
						$term_meta['recurringPeriodUnit'][0] => $term_meta['recurringPeriodPeriod'][0],
					),
					'startAfter'      => array(
						$term_meta['startAfterUnit'][0] => $term_meta['startAfterPeriod'][0],
					),
					'endAfter'        => array(
						$term_meta['endAfterUnit'][0] => $term_meta['endAfterPeriod'][0],
					),
					'item_id'         => $item['key'] ?? '',
				);
			}
			// /check if product has only Nuvei Payment Plan Attribute
		}

		Nuvei_Pfw_Logger::write( $data, 'get_products_data() final data' );

		return $data;
	}

	/**
	 * A help function to extract the total from Cart passed with REST API request.
     * In case the total is not passed via the API, then this is not a headless
     * request and we will return false.
	 *
     * @param int $total    A default total.
	 * @return string|bool
	 */
	protected function get_total_from_rest_params($total = 0) {
		if ( isset( $this->rest_params['totals']['total_price'], $this->rest_params['totals']['currency_minor_unit'] ) ) {
			$min_unit  = $this->rest_params['totals']['currency_minor_unit'];
			$delimeter = 1;

			for ( $cnt = 0; $cnt < $min_unit; $cnt++ ) {
				$delimeter *= 10;
			}

			$price = round( ( $this->rest_params['totals']['total_price'] / $delimeter ), 2 );

			return (string) number_format( $price, 2, '.', '' );
		}

		return $total;
	}


	/**
	 * A common function to set some data into the session.
	 *
	 * @param string $session_token
	 * @param array  $last_req_details Some details from last open/update order request.
	 * @param array  $product_data     Short product and subscription data.
	 */
	protected function set_nuvei_session_data( $session_token, $last_req_details, $product_data ) {
		WC()->session->set( NUVEI_PFW_SESSION_OO_DETAILS, $last_req_details );
        
        $prod_details = array(
            $session_token => array(
                'wc_subscr'          => $product_data['wc_subscr'],
                'subscr_data'        => $product_data['subscr_data'],
                'products_data_hash' => md5( serialize( $product_data ) ),
            ),
        );
        
		WC()->session->set( NUVEI_PFW_SESSION_PROD_DETAILS, $prod_details );
        
        Nuvei_Pfw_Logger::write(
			array(
                NUVEI_PFW_SESSION_OO_DETAILS => $last_req_details,
                NUVEI_PFW_SESSION_PROD_DETAILS => $prod_details
			),
			'set_nuvei_session_data'
		);
	}

	/**
	 * Just a helper function to extract last of Nuvei transactions.
	 * It is possible to set array of desired types. First found will
	 * be returned.
	 *
	 * @param array $transactions List with all transactions
	 * @param array $types        Search for specific type/s.
	 *
	 * @return array
	 */
	protected function get_last_transaction( array $transactions, array $types = array() ) {
		if ( empty( $transactions ) || ! is_array( $transactions ) ) {
			Nuvei_Pfw_Logger::write( $transactions, 'Problem with trnsactions array.' );
			return array();
		}

		if ( empty( $types ) ) {
			return end( $transactions );
		}

		foreach ( array_reverse( $transactions, true ) as $tr_id => $data ) {
			if ( ! empty( $data['transactionType'] )
				&& in_array( $data['transactionType'], $types )
			) {
				// fix for the case when work on Order made with plugin before v2.0.0
				if ( ! isset( $data['transactionId'] ) ) {
					Nuvei_Pfw_Logger::write( $data, 'modify Order made with plugin version before v2.0.0.' );

					$data['transactionId'] = $tr_id;
				}

				Nuvei_Pfw_Logger::write( $data, 'get_last_transaction()' );

				return $data;
			}
		}

		return array();
	}

	/**
	 * Temp help function until stop using old Order meta fields.
	 *
	 * @param int|null $order_id WC Order ID
	 * @param array    $types    Search for specific type/s.
	 *
	 * @return int
	 */
	protected function get_tr_id( $order_id = null, $types = array() ) {
		$order = $this->get_order( $order_id );

		// first check for new meta data
		$ord_tr_id = $order->get_meta( NUVEI_PFW_TR_ID );

		if ( ! empty( $ord_tr_id ) ) {
			return $ord_tr_id;
		}

		$nuvei_data = $order->get_meta( NUVEI_PFW_TRANSACTIONS );

		if ( ! empty( $nuvei_data ) && is_array( $nuvei_data ) ) {
			// just get from last transaction
			if ( empty( $types ) ) {
				$last_tr = end( $nuvei_data );
			} else { // get last transaction by type
				$last_tr = $this->get_last_transaction( $nuvei_data, $types );
			}

			if ( ! empty( $last_tr['transactionId'] ) ) {
				return $last_tr['transactionId'];
			}
		}

		// check for old meta data
		return $order->get_meta( '_transactionId' ); // NUVEI_TRANS_ID
	}

	/**
	 * Temp help function until stop using old Order meta fields.
	 *
	 * @param  int|null $order_id WC Order ID
	 * @return int
	 */
	protected function get_tr_status( $order_id = null ) {
		$order = $this->get_order( $order_id );

		// first check for new meta data
		$nuvei_data = $order->get_meta( NUVEI_PFW_TRANSACTIONS );

		if ( ! empty( $nuvei_data ) && is_array( $nuvei_data ) ) {
			$last_tr = end( $nuvei_data );

			if ( ! empty( $last_tr['status'] ) ) {
				return $last_tr['status'];
			}
		}

		// check for old meta data
		return $order->get_meta( '_transactionStatus' ); // NUVEI_TRANS_STATUS
	}

	/**
	 * A help function for the above methods.
	 */
	protected function get_order( $order_id ) {
		if ( empty( $this->sc_order ) ) {
			return wc_get_order( $order_id );
		}

			return $this->sc_order;
	}

	/**
	 * Save main transaction data into a block as private meta field.
	 *
	 * @param  array $params       Optional list of parameters to search in.
	 * @param  int   $wc_refund_id
	 * @return void
	 */
	protected function save_transaction_data( $params = array(), $wc_refund_id = null ) {
		Nuvei_Pfw_Logger::write(
            array(
                '$params'       => $params,
                '$wc_refund_id' => $wc_refund_id,
            ),
            'save_transaction_data() incoming method parameters'
        );

		$transaction_id = Nuvei_Pfw_Http::get_param( 'TransactionID', 'string', '', $params );

		if ( empty( $transaction_id ) ) {
			Nuvei_Pfw_Logger::write( $transaction_id, 'TransactionID param is empty!', 'CRITICAL' );
			return;
		}

		// get previous data if exists
		$transactions_data = $this->sc_order->get_meta( NUVEI_PFW_TRANSACTIONS );
		// in case it is empty
		if ( empty( $transactions_data ) || ! is_array( $transactions_data ) ) {
			$transactions_data = array();
		}

		$transaction_type = Nuvei_Pfw_Http::get_param( 'transactionType', 'string', '', $params );
		$status           = Nuvei_Pfw_Http::get_param( 'status', 'string', '' );
        
        if (!empty($params['status'])) {
            $status = $params['status'];
        }

        Nuvei_Pfw_Logger::write(
            [
                '$transaction_type' => $transaction_type,
                '$status'           => $status,
            ],
            'save_transaction_data() paramters from DMN or REST response'
        );

		// check for already existing data
		if ( ! empty( $transactions_data[ $transaction_id ] )
			&& $transactions_data[ $transaction_id ]['transactionType'] == $transaction_type
			&& $transactions_data[ $transaction_id ]['status'] == $status
		) {
			Nuvei_Pfw_Logger::write( 'We have information for this transaction and will not save it again.' );
			return;
		}
        
		$transactions_data[ $transaction_id ] = array(
			'authCode'             => Nuvei_Pfw_Http::get_param( 'AuthCode', 'string', '', $params ),
			'paymentMethod'        => Nuvei_Pfw_Http::get_param( 'payment_method', 'string', '', $params ),
			'transactionType'      => $transaction_type,
			'transactionId'        => $transaction_id,
			'relatedTransactionId' => Nuvei_Pfw_Http::get_param( 'relatedTransactionId', 'int', 0, $params ),
			'totalAmount'          => Nuvei_Pfw_Http::get_param( ['totalAmount', 'amount'], 'float', 0, $params ),
			'currency'             => Nuvei_Pfw_Http::get_param( 'currency', 'string', '', $params ),
			'status'               => $status,
			'userPaymentOptionId'  => Nuvei_Pfw_Http::get_param( 'userPaymentOptionId', 'int' ),
			'wcsRenewal'           => 'renewal_order' == Nuvei_Pfw_Http::get_param( 'customField4', 'string', '', $params )
				? true : false,
		);

		if ( null !== $wc_refund_id ) {
			$transactions_data[ $transaction_id ]['wcRefundId'] = $wc_refund_id;
		}
        
		$this->sc_order->update_meta_data( NUVEI_PFW_TRANSACTIONS, $transactions_data );

		// Update it only for Auth and Sale. They are base an we will need this TrID
		if ( in_array( $transaction_type, array( 'Auth', 'Sale' ) ) ) {
            Nuvei_Pfw_Logger::write( 'save_transaction_data(), Auth or Sale');
			$this->sc_order->update_meta_data( NUVEI_PFW_TR_ID, $transaction_id );
		}

		// Update for Settle only if it was Approved. If it is not, the merchant can try again.
		if ( 'Settle' == $transaction_type && 'approved' == strtolower($status) ) {
            Nuvei_Pfw_Logger::write( 'save_transaction_data(), Approved Settle');
			$this->sc_order->update_meta_data( NUVEI_PFW_TR_ID, $transaction_id );
		}

		if ( isset( $transactions_data['wcsRenewal'] ) ) {
			$this->sc_order->update_meta_data( NUVEI_PFW_WC_RENEWAL, true );
		}

        Nuvei_Pfw_Logger::write( $transactions_data, 'The transaction was added to the Order meta data.' );
		// $this->sc_order->save();
	}

	/**
	 * Single place to generate the client unique id parameter.
	 *
	 * @param string $billing_email
	 * @param array  $products_data Optional for the Auto-Void.
	 *
	 * @return string $client_unique_id
	 */
	protected function get_client_unique_id( $billing_email, $products_data = array() ) {
		$order_string     = $billing_email . '_' . serialize( $products_data );
		$client_unique_id = hash( 'crc32b', $order_string ) . '_' . uniqid( '', true );

		return $client_unique_id;
	}

	/**
	 * A common method to get get rebilling details from the Order meta.
	 *
	 * @param  array $all_data All meta data for some Order.
	 * @return array $subscr_list
	 */
	protected function get_order_rebiling_details( $all_data ) {
		$subscr_list = array();

		if ( empty( $all_data ) || ! is_array( $all_data ) ) {
			Nuvei_Pfw_Logger::write( $all_data, 'There is no meta data or it is in wrong format!', 'WARN' );
			return $subscr_list;
		}

		foreach ( $all_data as $key => $data ) {
			// legacy
			if ( ! is_numeric( $key ) ) {
				// Nuvei_Pfw_Logger::write($data);

				if ( false === strpos( $key, NUVEI_PFW_ORDER_SUBSCR ) ) {
                    continue;
				}

				$subscr_list[] = array(
					'subs_id'   => $key,
					'subs_data' => $data,
				);
			} else { // for HPOS
				$meta_data = $data->get_data();

				// Nuvei_Pfw_Logger::write($meta_data);

				if ( empty( $meta_data['key'] )
					|| false === strpos( $meta_data['key'], NUVEI_PFW_ORDER_SUBSCR )
				) {
						continue;
				}

				$subscr_list[] = array(
					'subs_id'   => $meta_data['key'],
					'subs_data' => $meta_data['value'],
				);
			}
		}

		return $subscr_list;
	}

    /**
     * Here we check if the order belongs to Nuvei.
     * 
     * @param int $order_id
     * @param bool $return_respons
     * 
     * @return bool
     */
    protected function is_nuvei_order( $order_id, $return_respons = false) {
        if (! $this->sc_order instanceof WC_Order) {
            $this->sc_order = wc_get_order($order_id);
        }
        
        if ( ! $this->sc_order instanceof WC_Order
            || ! in_array( $this->sc_order->get_payment_method(), array( NUVEI_PFW_GATEWAY_NAME, 'sc' ) ) 
        ) {
			$this->message  = $msg 
                            = 'Error - the order does not belongs to Nuvei.';
			
            Nuvei_Pfw_Logger::write(
				array(
					'order_id'       => $order_id,
					'payment_method' => ( $this->sc_order instanceof WC_Order ) ? $this->sc_order->get_payment_method() : 'N/A',
				),
				$msg
			);
            
            if ($return_respons) {
                return false;
            }

			exit( esc_html( $msg ) );
		}
        
        return true;
    }
    
    /**
     * Check if we can override the Order state.
     * 
     * @param bool $return_respons
     * @return void
     */
    protected function can_override_order_status( $return_respons = false ) {
        $ord_status = strtolower( $this->sc_order->get_status() );

		if ( in_array( $ord_status, array( 'cancelled', 'refunded' ) ) ) {
			$this->message = 'Error - can not override status of Voided/Refunded Order.';
			
            Nuvei_Pfw_Logger::write( $this->sc_order->get_payment_method(), $this->message );

            if ($return_respons) {
                return false;
            }
            
			exit( esc_html( $this->message ) );
		}

		// do not replace "completed" with "auth" status
		if ( 'completed' == $ord_status
			&& 'auth' == strtolower( Nuvei_Pfw_Http::get_param( 'transactionType' ) )
		) {
			$this->message = 'Error - can not override status Completed with Auth.';
			Nuvei_Pfw_Logger::write( $this->sc_order->get_payment_method(), $this->message );

            if ($return_respons) {
                return false;
            }
            
			exit( esc_html( $this->message ) );
		}
        
        return true;
    }
    
    protected function check_for_repeating_dmn($trId, $status, $return_respons = false) {
		Nuvei_Pfw_Logger::write( 'check_for_repeating_dmn' );

		$order_data = $this->sc_order->get_meta( NUVEI_PFW_TRANSACTIONS );

		if ( ! empty( $order_data[ $trId ] )
			&& ! empty( $order_data[ $trId ]['status'] )
			&& strtolower($status) == strtolower($order_data[ $trId ]['status'])
		) {
			Nuvei_Pfw_Logger::write( 'Repating DMN message detected. Stop the process.' );
            
            if ($return_respons) {
                return false;
            }
            
			exit( 'This DMN is already received.' );
		}

		return true;
	}
    
    /**
	 * Change the status of the order.
     * The null parameters are optional in some flows.
	 *
	 * @param int           $order_id           The Order Id.
	 * @param string        $req_status         The Status of the request.
	 * @param string        $transaction_type   The type of the transaction.
	 * @param int|null      $refund_id          The ID of the Refund into WC.
	 * @param float|null    $total              The Total according the plugin.
	 * @param mixed|null    $tr_id              The Transaction ID according the plugin.
	 * @param string|null   $pm                 The used payment method according the plugin.
	 * @param string|null   $curr               The used currency according the plugin.
	 * @param string|null   $rel_tr_id          The Related Transaction ID according the plugin.
	 */
	protected function change_order_status( 
        $order_id, 
        $req_status, 
        $transaction_type, 
        $refund_id = null,
        $total = null,
        $tr_id = null,
        $pm = null,
        $curr = null,
        $rel_tr_id = null
    ) {
		Nuvei_Pfw_Logger::write('Nuvei change_order_status()');

		$dmn_amount     = $total ?? number_format(Nuvei_Pfw_Http::get_param( 'totalAmount', 'float' ), 2, '.', '');
        $trans_id       = $tr_id ?? Nuvei_Pfw_Http::get_param( 'TransactionID' );
        $rel_trans_id   = $rel_tr_id ?? Nuvei_Pfw_Http::get_param( 'relatedTransactionId' );
        $payment_method = $pm ?? Nuvei_Pfw_Http::get_param( 'payment_method' );
        $currency       = $curr ?? Nuvei_Pfw_Http::get_param( 'currency' );
        $msg            = [];

        // phpcs:ignore
        $msg_transaction = '<b>' . $transaction_type . ' </b> '
			. __( 'request', 'nuvei-payments-for-woocommerce' ) . '.<br/>';

		$gw_data = $msg_transaction
            . __( 'Response status: ', 'nuvei-payments-for-woocommerce' ) . '<b>' . $req_status . '</b>.<br/>'
            . __( 'Payment Method: ', 'nuvei-payments-for-woocommerce' ) . $payment_method . '.<br/>'
            . __( 'Transaction ID: ', 'nuvei-payments-for-woocommerce' ) . $trans_id . '.<br/>'
            . __( 'Related Transaction ID: ', 'nuvei-payments-for-woocommerce' ) . $rel_trans_id . '.<br/>'
            . __( 'Transaction Amount: ', 'nuvei-payments-for-woocommerce' ) . $dmn_amount . ' ' . $currency . '.';

		$message = '';
		$status  = $this->sc_order->get_status();

		Nuvei_Pfw_Logger::write(
            array(
                'Order status, order->get_status()' => $status,
                'order PREV_TRANS_STATUS'           => $this->sc_order->get_meta( NUVEI_PFW_PREV_TRANS_STATUS ),
                'DMN status'                        => $req_status,
                'transaction type'                  => $transaction_type
            ),
            'order status'
        );

		switch ( strtolower($req_status) ) {
			case 'canceled':
				$message            = $gw_data;
				$msg['class'] = 'woocommerce_message';

				if ( in_array( $transaction_type, array( 'Auth', 'Settle', 'Sale' ) ) ) {
					$status = $this->nuvei_gw->get_option( 'status_fail' );
				}
				break;

			case 'approved':
				$order_amount       = number_format($this->sc_order->get_total(), 2, '.', '');
				$msg['class'] = 'woocommerce_message';

				// Void
				if ( 'Void' === $transaction_type ) {
					$message = $gw_data;
					$status  = $this->nuvei_gw->get_option( 'status_void' );
					break;
				}

				// Refund
				if ( in_array( $transaction_type, array( 'Credit', 'Refund' ), true ) ) {
					$message = $gw_data;
					$status  = $this->nuvei_gw->get_option( 'status_paid' );

					// get current refund amount
					$currency_code   = $this->sc_order->get_currency();
					$currency_symbol = get_woocommerce_currency_symbol( $currency_code );
					$message        .= '<br/><b>' . __( 'Refund: ', 'nuvei-payments-for-woocommerce' )
						. '</b> #' . $refund_id;

					if ( $order_amount == $this->sum_order_refunds() + $dmn_amount ) {
						$status = $this->nuvei_gw->get_option( 'status_refund' );
					}

					break;
				}

				// Auth
				if ( 'Auth' === $transaction_type ) {
					$message = $gw_data;
					$status  = $this->nuvei_gw->get_option( 'status_auth' );

					if ( 0 == $order_amount ) {
						$status = $this->nuvei_gw->get_option( 'status_paid' );
					}
				}

				if ( in_array( $transaction_type, array( 'Settle', 'Sale' ), true ) ) {
					$message = $gw_data;
					$status  = $this->nuvei_gw->get_option( 'status_paid' );

					$this->sc_order->payment_complete( $order_id );

					Nuvei_Pfw_Logger::write( $status, 'Settle/Sale status' );
				}

				// check for correct amount
				if ( in_array( $transaction_type, array( 'Auth', 'Sale' ), true ) ) {
					$set_amount_warning = false;
					$set_curr_warning   = false;

					Nuvei_Pfw_Logger::write(
						array(
							'$order_amount'  => $order_amount,
							'$dmn_amount'    => $dmn_amount,
							'customField1'   => Nuvei_Pfw_Http::get_param( 'customField1' ),
							'order currency' => $this->sc_order->get_currency(),
							'param currency' => $currency,
							'customField2'   => Nuvei_Pfw_Http::get_param( 'customField2' ),
						),
						'Check for fraud order.'
					);

					// check for correct amount
					if ( $order_amount != $dmn_amount
						&& Nuvei_Pfw_Http::get_param( 'customField1' ) != $order_amount
					) {
						$set_amount_warning = true;
						Nuvei_Pfw_Logger::write( 'Amount warning!' );
					}

					// check for correct currency
					if ( $this->sc_order->get_currency() !== $currency
                        && $this->sc_order->get_currency() !== Nuvei_Pfw_Http::get_param( 'customField2' )
					) {
						$set_curr_warning = true;
						Nuvei_Pfw_Logger::write( 'Currency warning!' );
					}

					// when currency is same, check the amount again, in case of some kind partial transaction
					if ( $this->sc_order->get_currency() === $currency
                        && $order_amount != $dmn_amount
					) {
						$set_amount_warning = true;
						Nuvei_Pfw_Logger::write( 'Amount warning when currency is same!' );
					}

					$this->sc_order->update_meta_data(
						NUVEI_PFW_ORDER_CHANGES,
						array(
							'curr_change'  => $set_curr_warning,
							'total_change' => $set_amount_warning,
						)
					);
				}

				break;

			case 'error':
			case 'declined':
			case 'fail':
				$message  = Nuvei_Pfw_Http::get_param( 'message' );
				$err_code = Nuvei_Pfw_Http::get_param( 'ErrCode' );
				$reason   = Nuvei_Pfw_Http::get_param( 'Reason' );

				if ( empty( $reason ) ) {
					$reason = Nuvei_Pfw_Http::get_param( 'reason' );
				}

				$message = $gw_data . '<br/>'
                	. ( ! empty( $err_code ) ? __( 'Error code: ', 'nuvei-payments-for-woocommerce' ) . $err_code . '<br/>' : '' )
                    . ( ! empty( $reason ) ? __( 'Reason: ', 'nuvei-payments-for-woocommerce' ) . $reason . '<br/>' : '' )
                    . ( ! empty( $message ) ? __( 'Message: ', 'nuvei-payments-for-woocommerce' ) . $message : '' );

                $msg['class'] = 'woocommerce_message';

				if ( in_array( $transaction_type, array( 'Auth', 'Sale' ) ) ) {
					$status = $this->nuvei_gw->get_option( 'status_fail' );
                    break;
				}
				if ( in_array( $transaction_type, array( 'Void', 'Settle' ) ) ) {
					$status = $this->sc_order->get_meta( NUVEI_PFW_PREV_TRANS_STATUS );
                    break;
				}
				if ( 'Refund' == $transaction_type ) {
					$status = $this->nuvei_gw->get_option( 'status_paid' );
                    break;
				}

				break;

			case 'pending':
				$message            = $gw_data;
				$msg['class'] = 'woocommerce_message woocommerce_message_info';
				break;
		}

		if ( ! empty( $message ) ) {
			$msg['message'] = $message;
			$this->sc_order->add_order_note( $msg['message'] );
		}

        Nuvei_Pfw_Logger::write(
			array(
				'$order_id' => $order_id,
				'$status'   => $status,
			),
			'Order Status before save.'
		);

		$this->sc_order->update_status( $status );
	}
    
    /**
	 * Get the payment method from the last transaction.
	 *
	 * @param  int|null $order_id WC Order ID
	 * @return int
	 */
	protected function get_payment_method( $order_id = null ) {
		$order = $this->get_order( $order_id );
        
        if ( ! $order ) {
            return 0;
        }

		// first check for new meta data
		$nuvei_data = $order->get_meta( NUVEI_PFW_TRANSACTIONS );

		if ( ! empty( $nuvei_data ) && is_array( $nuvei_data ) ) {
			$last_tr = $this->get_last_transaction( $nuvei_data, array( 'Sale', 'Settle', 'Auth' ) );

			if ( ! empty( $last_tr['paymentMethod'] ) ) {
				return $last_tr['paymentMethod'];
			}
		}

		return 0;
	}
    
    protected function sum_order_refunds() {
		$sum        = 0;
		$nuvei_data = $this->sc_order->get_meta( NUVEI_PFW_TRANSACTIONS );

		if ( empty( $nuvei_data ) || ! is_array( $nuvei_data ) ) {
			return '0.00';
		}

		foreach ( $nuvei_data as $data ) {
			if ( ! empty( $data['transactionType'] )
				&& in_array( $data['transactionType'], array( 'Credit', 'Refund' ) )
				&& ! empty( $data['status'] )
				&& strtolower( $data['status'] ) == 'approved'
				&& isset( $data['totalAmount'] )
			) {
				$sum += $data['totalAmount'];
			}
		}

		return number_format( $sum, 2, '.', '' );
	}
    
	/**
	 * Get the request endpoint - sandbox or production.
	 *
	 * @return string
	 */
	private function get_endpoint_base() {
		if ( 'yes' == $this->nuvei_gw->get_option( 'test' ) ) {
			return NUVEI_PFW_REST_ENDPOINT_INT;
		}

		return NUVEI_PFW_REST_ENDPOINT_PROD;
	}

	/**
	 * Validate some of the parameters in the request by predefined criteria.
	 *
	 * @param  array $params
	 * @return array
	 */
	private function validate_parameters( $params ) {
		Nuvei_Pfw_Logger::write( 'validate_parameters' );

		// directly check the mails
		if ( isset( $params['billingAddress']['email'] ) ) {
			if ( ! filter_var( $params['billingAddress']['email'], NUVEI_PFW_PARAMS_VALIDATION_EMAIL['flag'] ) ) {
				return array(
					'status'  => 'ERROR',
					'message' => 'The parameter Billing Address Email is not valid.',
					'email'   => $params['billingAddress']['email'],
				);
			}

			if ( strlen( $params['billingAddress']['email'] ) > NUVEI_PFW_PARAMS_VALIDATION_EMAIL['length'] ) {
				return array(
					'status'  => 'ERROR',
					'message' => 'The parameter Billing Address Email is too long.',
					'email'   => $params['billingAddress']['email'],
				);
			}
		}

		if ( isset( $params['shippingAddress']['email'] ) ) {
			if ( ! filter_var( $params['shippingAddress']['email'], NUVEI_PFW_PARAMS_VALIDATION_EMAIL['flag'] ) ) {
				return array(
					'status'  => 'ERROR',
					'message' => 'The parameter Shipping Address Email is not valid.',
					'email'   => $params['shippingAddress']['email'],
				);
			}

			if ( strlen( $params['shippingAddress']['email'] ) > NUVEI_PFW_PARAMS_VALIDATION_EMAIL['length'] ) {
				return array(
					'status'  => 'ERROR',
					'message' => 'The parameter Shipping Address Email is too long.',
					'email'   => $params['shippingAddress']['email'],
				);
			}
		}
		// directly check the mails END

		foreach ( $params as $key1 => $val1 ) {
			if ( ! is_array( $val1 ) && ! empty( $val1 ) && array_key_exists( $key1, NUVEI_PFW_PARAMS_VALIDATION ) ) {
				$new_val = $val1;

				if ( mb_strlen( $val1 ) > NUVEI_PFW_PARAMS_VALIDATION[ $key1 ]['length'] ) {
						$new_val = mb_substr( $val1, 0, NUVEI_PFW_PARAMS_VALIDATION[ $key1 ]['length'] );
				}

				$params[ $key1 ] = filter_var( $new_val, NUVEI_PFW_PARAMS_VALIDATION[ $key1 ]['flag'] );

				if ( ! $params[ $key1 ] ) {
					$params[ $key1 ] = 'The value is not valid.';
				}
			} elseif ( is_array( $val1 ) && ! empty( $val1 ) ) {
				foreach ( $val1 as $key2 => $val2 ) {
					if ( ! is_array( $val2 ) && ! empty( $val2 ) && array_key_exists( $key2, NUVEI_PFW_PARAMS_VALIDATION ) ) {
						$new_val = $val2;

						if ( mb_strlen( $val2 ) > NUVEI_PFW_PARAMS_VALIDATION[ $key2 ]['length'] ) {
								$new_val = mb_substr( $val2, 0, NUVEI_PFW_PARAMS_VALIDATION[ $key2 ]['length'] );
						}

						$params[ $key1 ][ $key2 ] = filter_var( $new_val, NUVEI_PFW_PARAMS_VALIDATION[ $key2 ]['flag'] );

						if ( ! $params[ $key1 ][ $key2 ] ) {
							$params[ $key1 ][ $key2 ] = 'The value is not valid.';
						}
					}
				}
			}
		}

		return $params;
	}

}
