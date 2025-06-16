<?php

	defined( 'ABSPATH' ) || exit;

	/**
	 * The base class for requests. The different requests classes inherit this one.
	 * Some common methods are also here.
	 */
abstract class Nuvei_Pfw_Request {


	protected $rest_params = array();
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
		$plugin_data    = get_plugin_data( NUVEI_PFW_PLUGIN_FILE );
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
	 * @param bool       $return   - return the order
	 *
	 * @return void
	 */
	protected function is_order_valid( $order_id ) {
		Nuvei_Pfw_Logger::write( $order_id, 'is_order_valid() check.' );

		$this->sc_order = wc_get_order( $order_id );

		// error
		if ( ! is_a( $this->sc_order, 'WC_Order' ) ) {
			$msg = 'Error - Provided Order ID is not a WC Order';
			Nuvei_Pfw_Logger::write( $order_id, $msg );
			exit( esc_html( $msg ) );
		}

		Nuvei_Pfw_Logger::write( 'The Order is valid.' );

		// in case of Subscription states DMNs - stop proccess here. We will save only a message to the Order.
		if ( 'subscription' == Nuvei_Pfw_Http::get_param( 'dmnType' ) ) {
			return;
		}

		// check for 'sc' also because of the older Orders
		if ( ! in_array( $this->sc_order->get_payment_method(), array( NUVEI_PFW_GATEWAY_NAME, 'sc' ) ) ) {
			$msg = 'Error - the order does not belongs to Nuvei.';
			Nuvei_Pfw_Logger::write(
				array(
					'order_id'       => $order_id,
					'payment_method' => $this->sc_order->get_payment_method(),
				),
				$msg
			);

			exit( esc_html( $msg ) );
		}

		// can we override Order status (state)
		$ord_status = strtolower( $this->sc_order->get_status() );

		if ( in_array( $ord_status, array( 'cancelled', 'refunded' ) ) ) {
			$msg = 'Error - can not override status of Voided/Refunded Order.';
			Nuvei_Pfw_Logger::write( $this->sc_order->get_payment_method(), $msg );

			exit( esc_html( $msg ) );
		}

		// do not replace "completed" with "auth" status
		if ( 'completed' == $ord_status
			&& 'auth' == strtolower( Nuvei_Pfw_Http::get_param( 'transactionType' ) )
		) {
			$msg = 'Error - can not override status Completed with Auth.';
			Nuvei_Pfw_Logger::write( $this->sc_order->get_payment_method(), $msg );

			exit( esc_html( $msg ) );
		}
		// can we override Order status (state) END
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
	 *
	 * @global Woocommerce $woocommerce
	 *
	 * @return array
	 */
	protected function get_order_addresses() {
			Nuvei_Pfw_Logger::write( 'get_order_addresses()' );

		// REST API flow
		if ( ! empty( $this->rest_params ) ) {
			$addresses = array();

			if ( ! empty( $this->rest_params['shipping_address'] ) ) {
				$shipping_addr = trim(
					(string) ( $this->rest_params['shipping_address']['address_1'] ?? '' )
					. ' ' . (string) ( $this->rest_params['shipping_address']['address_2'] ?? '' )
				);

				$addresses['shippingAddress'] = array(
					'firstName' => $this->rest_params['shipping_address']['first_name'] ?? '',
					'lastName'  => $this->rest_params['shipping_address']['last_name'] ?? '',
					'address'   => $shipping_addr,
					'zip'       => $this->rest_params['shipping_address']['postcode'] ?? '',
					'city'      => $this->rest_params['shipping_address']['city'] ?? '',
					'country'   => $this->rest_params['shipping_address']['country'] ?? '',
				);
			}

			if ( ! empty( $this->rest_params['billing_address'] ) ) {
				$billing_addr = trim(
					(string) ( $this->rest_params['billing_address']['address_1'] ?? '' )
					. ' ' . (string) ( $this->rest_params['billing_address']['address_2'] ?? '' )
				);

				$addresses['billingAddress'] = array(
					'firstName' => $this->rest_params['billing_address']['first_name'] ?? '',
					'lastName'  => $this->rest_params['billing_address']['last_name'] ?? '',
					'address'   => $billing_addr,
					'phone'     => $this->rest_params['billing_address']['phone'] ?? '',
					'zip'       => $this->rest_params['billing_address']['postcode'] ?? '',
					'city'      => $this->rest_params['billing_address']['city'] ?? '',
					'country'   => $this->rest_params['billing_address']['country'] ?? '',
					'state'     => $this->rest_params['billing_address']['state'] ?? '',
					'email'     => $this->rest_params['billing_address']['email'] ?? '',
				);
			}

			return $addresses;
		}

		// default plugin flow
		global $woocommerce;

		$billing_address         = array();
		$cart                    = $woocommerce->cart;
			$existing_order_data = array();

		if ( ! empty( $this->sc_order ) ) {
			$existing_order_data = $this->sc_order->get_data();
		}

		// Set billing params.
		// billing_first_name
		$bfn = $this->get_scformdata_address_parts( 'first_name' );

		if ( ! empty( $existing_order_data['billing']['first_name'] ) ) {
			$bfn = trim( (string) $existing_order_data['billing']['first_name'] );
		}
		if ( empty( $bfn ) ) {
			$bfn = trim( (string) $cart->get_customer()->get_billing_first_name() );
		}

		$billing_address['firstName'] = ! empty( $bfn ) ? $bfn : 'Missing parameter';

		// billing_last_name
		$bln = $this->get_scformdata_address_parts( 'last_name' );

		if ( ! empty( $existing_order_data['billing']['last_name'] ) ) {
			$bln = trim( (string) $existing_order_data['billing']['last_name'] );
		}
		if ( empty( $bln ) ) {
			$bln = trim( (string) $cart->get_customer()->get_billing_last_name() );
		}

		$billing_address['lastName'] = ! empty( $bln ) ? $bln : 'Missing parameter';

		// address
		$ba     = '';
		$ba_ln1 = $this->get_scformdata_address_parts( 'address_1' );
		$ba_ln2 = $this->get_scformdata_address_parts( 'address_2' );

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
		if ( empty( $ba ) ) {
			$ba_ln1 = trim( (string) $cart->get_customer()->get_billing_address() );
			$ba_ln2 = trim( (string) $cart->get_customer()->get_billing_address_2() );

			if ( ! empty( $ba_ln1 ) ) {
				$ba = $ba_ln1;

				if ( ! empty( $ba_ln2 ) ) {
						$ba .= ' ' . $ba_ln2;
				}
			}
		}

		$billing_address['address'] = ! empty( $ba ) ? $ba : 'Missing parameter';

		// billing_phone
		$bp = $this->get_scformdata_address_parts( 'phone' );

		if ( ! empty( $existing_order_data['billing']['phone'] ) ) {
			$bp = trim( (string) $existing_order_data['billing']['phone'] );
		}
		if ( empty( $bp ) ) {
			$bp = trim( (string) $cart->get_customer()->get_billing_phone() );
		}

		$billing_address['phone'] = ! empty( $bp ) ? $bp : 'Missing parameter';

		// billing_postcode
		$bz = $this->get_scformdata_address_parts( 'postcode' );

		if ( ! empty( $existing_order_data['billing']['postcode'] ) ) {
			$bz = trim( (string) $existing_order_data['billing']['postcode'] );
		}
		if ( empty( $bz ) ) {
			$bz = trim( (string) $cart->get_customer()->get_billing_postcode() );
		}

		$billing_address['zip'] = ! empty( $bz ) ? $bz : 'Missing parameter';

		// billing_city
		$bc = $this->get_scformdata_address_parts( 'city' );

		if ( ! empty( $existing_order_data['billing']['city'] ) ) {
			$bc = trim( (string) $existing_order_data['billing']['city'] );
		}
		if ( empty( $bc ) ) {
			$bc = trim( (string) $cart->get_customer()->get_billing_city() );
		}

		$billing_address['city'] = ! empty( $bc ) ? $bc : 'Missing parameter';

		// billing_country
		$bcn = $this->get_scformdata_address_parts( 'country' );

		if ( ! empty( $existing_order_data['billing']['country'] ) ) {
			$bcn = trim( (string) $existing_order_data['billing']['country'] );
		}
		if ( empty( $bcn ) ) {
			$bcn = trim( (string) $cart->get_customer()->get_billing_country() );
		}

		$billing_address['country'] = $bcn;

		// billing state
		$bst = $this->get_scformdata_address_parts( 'state' );

		if ( ! empty( $existing_order_data['billing']['state'] ) ) {
			$bst = trim( (string) $existing_order_data['billing']['state'] );
		}
		if ( empty( $bst ) ) {
			$bst = trim( (string) $cart->get_customer()->get_billing_state() );
		}

		$billing_address['state'] = $bst;

		// billing_email
		$be = $this->get_scformdata_address_parts( 'email' );

		if ( ! empty( $existing_order_data['billing']['email'] ) ) {
			$be = trim( (string) $existing_order_data['billing']['email'] );
		}
		if ( empty( $be ) ) {
				$be = trim( (string) $cart->get_customer()->get_billing_email() );
		}

		$billing_address['email'] = $be;
		// set billing params END

		// set shipping params
		// shipping first name
		$sfn = $this->get_scformdata_address_parts( 'first_name', 'shipping' );

		if ( ! empty( $existing_order_data['shipping']['first_name'] ) ) {
			$sfn = trim( (string) $existing_order_data['shipping']['first_name'] );
		}
		if ( empty( $sfn ) ) {
			$sfn = trim( (string) $cart->get_customer()->get_shipping_first_name() );
		}

		// shippinh last name
		$sln = $this->get_scformdata_address_parts( 'last_name', 'shipping' );

		if ( ! empty( $existing_order_data['shipping']['last_name'] ) ) {
			$sln = trim( (string) $existing_order_data['shipping']['last_name'] );
		}
		if ( empty( $sln ) ) {
			$sln = trim( (string) $cart->get_customer()->get_shipping_last_name() );
		}

		// shipping address
		$sa_l1 = $this->get_scformdata_address_parts( 'address_1', 'shipping' );
		$sa_l2 = $this->get_scformdata_address_parts( 'address_2', 'shipping' );
		$sa    = trim( (string) $sa_l1 . ' ' . (string) $sa_l2 );

		if ( ! empty( $existing_order_data['shipping']['address_1'] ) ) {
			$sa = trim( (string) $existing_order_data['shipping']['address_1'] );

			if ( empty( $existing_order_data['shipping']['address_2'] ) ) {
				$sa .= ' ' . trim( (string) $existing_order_data['shipping']['address_2'] );
			}
		}
		if ( empty( $sa ) ) {
			$sa = trim(
				(string) $cart->get_customer()->get_shipping_address() . ' '
				. (string) $cart->get_customer()->get_shipping_address_2()
			);
		}

		// shipping zip
		$sz = $this->get_scformdata_address_parts( 'postcode', 'shipping' );

		if ( ! empty( $existing_order_data['shipping']['postcode'] ) ) {
			$sz = trim( (string) $existing_order_data['shipping']['postcode'] );
		}
		if ( empty( $sz ) ) {
			$sz = trim( (string) $cart->get_customer()->get_shipping_postcode() );
		}

		// shipping city
		$sc = $this->get_scformdata_address_parts( 'city', 'shipping' );

		if ( ! empty( $existing_order_data['shipping']['city'] ) ) {
			$sc = trim( (string) $existing_order_data['shipping']['city'] );
		}
		if ( empty( $sc ) ) {
			$sc = trim( (string) $cart->get_customer()->get_shipping_city() );
		}

		// shipping country
		$scn = $this->get_scformdata_address_parts( 'country', 'shipping' );

		if ( ! empty( $existing_order_data['shipping']['country'] ) ) {
			$scn = trim( (string) $existing_order_data['shipping']['country'] );
		}
		if ( empty( $scn ) ) {
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
	 * Check incoming data for valid nonce.
	 *
	 * @return boolean
	 */
	protected function is_request_safe() {
		$request_safe = false;

		if ( false !== check_ajax_referer( 'nuvei-security-nonce', 'nuveiSecurity', false ) ) {
			return true;
		}
		if ( isset( $_POST['woocommerce-process-checkout-nonce'] )
			&& false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) ), 'woocommerce-process_checkout' )
		) {
			return true;
		}

		return false;
	}

	/**
	 * A helper function to safty check for, and get address parameters from the store request.
	 *
	 * @param string $field The field we are looking for.
	 * @param string $group The address group - shipping or billing.
	 *
	 * @return string
	 */
	private function get_scformdata_address_parts( $field, $group = 'billing' ) {
		// here we check for Nuvei nonce or WC Checkout nonce
		if ( ! $this->is_request_safe() ) {
			Nuvei_Pfw_Logger::write( $field, 'Securtity parameter is missing or nonce is not valid' );
			return '';
		}

		// shortcode
		if ( ! empty( $_REQUEST['scFormData'][ $group . '_' . $field ] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_REQUEST['scFormData'][ $group . '_' . $field ] ) ) );
		}
		// blocks
		elseif ( ! empty( $_REQUEST['scFormData'][ $group . '-' . $field ] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_REQUEST['scFormData'][ $group . '-' . $field ] ) ) );
		}

		// additional check for the email
		if ( 'email' == $field && ! empty( $_REQUEST['scFormData']['email'] ) ) {
			$be = trim( sanitize_email( wp_unslash( $_REQUEST['scFormData']['email'] ) ) );
		}

		return '';
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
			// we expect this method to be used on the Store only
		// if (is_admin()) {
		// return [];
		// }

		// main variable to fill
		$data = array(
			'wc_subscr'     => false,
			'subscr_data'   => array(),
			'products_data' => array(),
			'totals'        => 0,
		);

		$nuvei_taxonomy_name  = wc_attribute_taxonomy_name( Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME ) );
		$nuvei_plan_variation = 'attribute_' . $nuvei_taxonomy_name;

		// default plugin flow
		if ( empty( $this->rest_params ) ) {
			global $woocommerce;

				// get the data from the Cart
			if ( empty( $this->sc_order ) ) {
				$items = $woocommerce->cart->get_cart();

				if ( ! empty( $items ) ) {
					$data['totals'] = $woocommerce->cart->get_totals();
				}
			} else { // get the data from the existing Order
				$items = $this->sc_order->get_items();

				if ( ! empty( $items ) ) {
					$data['totals'] = array( 'total' => $this->sc_order->get_total() );
				}
			}

				Nuvei_Pfw_Logger::write( $items, 'get_products_data() items' );

			if ( empty( $items ) ) {
				return $data;
			}

			foreach ( $items as $item_id => $item ) {
				$cart_product   = wc_get_product( $item['product_id'] );
				$cart_prod_attr = $cart_product->get_attributes();

				// get short items data, we use it for Cashier url
				$data['products_data'][] = array(
					'product_id' => $item['product_id'],
					'quantity'   => $item['quantity'],
					'price'      => get_post_meta( $item['product_id'], '_price', true ),
					'name'       => $cart_product->get_title(),
					'in_stock'   => $cart_product->is_in_stock(),
					'item_id'    => $item_id,
				);

				// Nuvei_Pfw_Logger::write([
				// 'nuvei taxonomy name'   => $nuvei_taxonomy_name,
				// 'product attributes'    => $cart_prod_attr
				// ]);

				// check for WCS
				if ( false !== strpos( $cart_product->get_type(), 'subscription' ) ) {
					$data['wc_subscr'] = true;
					continue;
				}

				// check for product with Nuvei Payment Plan variation
				// We will not add the products with "empty plan" into subscr_data array!
				if ( ! empty( $item['variation'] )
					&& 0 != $item['variation_id']
					&& array_key_exists( $nuvei_plan_variation, $item['variation'] )
				) {
					$term = get_term_by( 'slug', $item['variation'][ $nuvei_plan_variation ], $nuvei_taxonomy_name );

					Nuvei_Pfw_Logger::write( (array) $term, '$term' );

					if ( is_wp_error( $term ) || empty( $term->term_id ) ) {
						Nuvei_Pfw_Logger::write(
							$item['variation'][ $nuvei_plan_variation ],
							'Error when try to get Term by Slug'
						);

						continue;
					}

					$term_meta = get_term_meta( $term->term_id );

					// Nuvei_Pfw_Logger::write($term_meta, '$term_meta');

					if ( empty( $term_meta['planId'][0] ) ) {
						continue;
					}

					$data['subscr_data'][] = array(
						'variation_id'    => $item['variation_id'],
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
						'item_id'         => $item_id,
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
					// $terms = wp_get_post_terms( $item['product_id'], $name, 'all' );
					$terms = wp_get_post_terms( $item['product_id'], $name, array( 'term_id' => $attr_option ) );

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
						'product_id'      => $item['product_id'],
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
						'item_id'         => $item_id,
					);
				}
				// /check if product has only Nuvei Payment Plan Attribute
			}

			Nuvei_Pfw_Logger::write( $data, 'get_products_data() data' );

			return $data;
		}

		// REST API flow
		$items          = $this->rest_params['items'] ?? array();
		$data['totals'] = $this->get_total_from_rest_params( $this->rest_params );

		foreach ( $items as $item ) {
			$product_id     = $item['product_id'] ?? $item['id'];
			$cart_product   = wc_get_product( $product_id );
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
					'nuvei taxonomy name' => $nuvei_taxonomy_name,
					'product attributes'  => $cart_prod_attr,
				)
			);

			// check for WCS
			if ( false !== strpos( $cart_product->get_type(), 'subscription' ) ) {
				$data['wc_subscr'] = true;
				continue;
			}

			// check for product with Nuvei Payment Plan variation
			if ( ! empty( $item['variation'] )
				&& 0 != $item['id']
				&& array_key_exists( $nuvei_taxonomy_name, $cart_prod_attr )
			) {
				$term = get_term_by(
					'slug',
					$cart_prod_attr[ $nuvei_taxonomy_name ],
					$nuvei_taxonomy_name
				);

				Nuvei_Pfw_Logger::write( (array) $term, '$term' );

				if ( is_wp_error( $term ) || empty( $term->term_id ) ) {
					Nuvei_Pfw_Logger::write(
						$item['variation'][ $nuvei_plan_variation ],
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
					// 'variation_id'      => $item['variation_id'],
					'variation_id'    => $item['id'],
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
				$terms = wp_get_post_terms( $item['id'], $name, array( 'term_id' => $attr_option ) );

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
					'product_id'      => $item['id'],
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
	 *
	 * @param  array $rest_params
	 * @return string
	 */
	protected function get_total_from_rest_params() {
		if ( isset( $this->rest_params['totals']['total_price'], $this->rest_params['totals']['currency_minor_unit'] )
		) {
			$min_unit  = $this->rest_params['totals']['currency_minor_unit'];
			$delimeter = 1;

			for ( $cnt = 0; $cnt < $min_unit; $cnt++ ) {
				$delimeter *= 10;
			}

			$price = round( ( $this->rest_params['totals']['total_price'] / $delimeter ), 2 );

			return (string) number_format( $price, 2, '.', '' );
		}

		return '0';
	}


	/**
	 * A common function to set some data into the session.
	 *
	 * @param string $session_token
	 * @param array  $last_req_details Some details from last open/update order request.
	 * @param array  $product_data     Short product and subscription data.
	 */
	protected function set_nuvei_session_data( $session_token, $last_req_details, $product_data ) {
		Nuvei_Pfw_Logger::write(
			array(
				'$session_token'    => $session_token,
				'$last_req_details' => $last_req_details,
				'$product_data'     => $product_data,
			),
			'set_nuvei_session_data'
		);

		WC()->session->set( NUVEI_PFW_SESSION_OO_DETAILS, $last_req_details );
		WC()->session->set(
			NUVEI_PFW_SESSION_PROD_DETAILS,
			array(
				$session_token => array(
					'wc_subscr'          => $product_data['wc_subscr'],
					'subscr_data'        => $product_data['subscr_data'],
					'products_data_hash' => md5( serialize( $product_data ) ),
				),
			)
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
		Nuvei_Pfw_Logger::write( array( $params, $wc_refund_id ), 'save_transaction_data()' );

		$transaction_id = Nuvei_Pfw_Http::get_param( 'TransactionID', 'int', '', $params );

		if ( empty( $transaction_id ) ) {
			$transaction_id = Nuvei_Pfw_Http::get_param( 'transactionId', 'int', '', $params );
		}
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
		$status           = Nuvei_Pfw_Http::get_request_status();

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
			'totalAmount'          => Nuvei_Pfw_Http::get_param( 'totalAmount', 'float', 0, $params ),
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

		// update it only for Auth, Settle and Sale. They are base an we will need this TrID
		if ( in_array( $transaction_type, array( 'Auth', 'Settle', 'Sale' ) ) ) {
			$this->sc_order->update_meta_data( NUVEI_PFW_TR_ID, $transaction_id );
		}

		if ( isset( $transactions_data['wcsRenewal'] ) ) {
			$this->sc_order->update_meta_data( NUVEI_PFW_WC_RENEWAL, true );
		}

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
	 * Common function to sanitize an associative array.
	 * If no array was passed use $_REQUEST variable.
	 *
	 * @param  array $arr
	 * @return array
	 */
	// protected function sanitize_assoc_array($arr = array()) {
	// if ( !is_array($arr) ) {
	// return array();
	// }
	//
	// if ( empty($arr) ) {
	// $arr = $_REQUEST;
	// }
	//
	// $keys   = array_keys($arr);
	// $values = array_values($arr);
	//
	// $san_keys = array_map(function($val) {
	// return sanitize_text_field($val);
	// }, $keys);
	//
	// $san_values = array_map(function($val) {
	// return sanitize_text_field($val);
	// }, $values);
	//
	// return array_combine($san_keys, $san_values);
	// }

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
