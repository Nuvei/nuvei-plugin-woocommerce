<?php

defined( 'ABSPATH' ) || exit;

/**
 * The class for openOrder request.
 */
class Nuvei_Pfw_Open_Order extends Nuvei_Pfw_Request {

	/**
	 * Set is_ajax parameter to the Process metohd.
	 *
	 * @param array $plugin_settings
	 * @param bool  $is_ajax
	 */
	public function __construct( array $plugin_settings, $rest_params = array() ) {
		parent::__construct();

		$this->plugin_settings  = $plugin_settings;
		$this->rest_params      = $rest_params;
	}

	/**
	 * The main method.
	 *
	 * @global object $woocommerce
	 * @return array|boolean
	 */
	public function process() {
		Nuvei_Pfw_Logger::write( 'OpenOrder class.' );

		global $woocommerce;

		$try_update_order   = true;
        $method_params      = func_get_args(); // optionaly we will pass here Order ID.
        
        // if we pass Order ID get the order.
        if (!empty($method_params[0]['order_id'])) {
            $this->sc_order = wc_get_order($method_params[0]['order_id']);
        }
        
		// REST call
		if ( ! empty( $this->rest_params ) ) {
			$open_order_details = array(
				'transactionType'   => $this->rest_params['transactionType'] ?? '',
				'orderId'           => $this->rest_params['orderId'] ?? 0,
				'userTokenId'       => $this->rest_params['email'] ?? '',
				'sessionToken'      => $this->rest_params['sessionToken'] ?? '',
			);
			$products_data      = $this->get_products_data();
			$cart_total         = $products_data['totals'];
			$addresses          = $this->get_order_addresses();
			$transaction_type   = $this->get_total_from_rest_params() == 0
				? 'Auth' : $this->plugin_settings['payment_action'];
		} else { // default flow
			$open_order_details = $woocommerce->session->get( NUVEI_PFW_SESSION_OO_DETAILS );
			$products_data      = $this->get_products_data();
			$cart_total         = (float) $products_data['totals']['total'];
			$addresses          = $this->get_order_addresses();
			$transaction_type   = 0 == $cart_total ? 'Auth' : $this->plugin_settings['payment_action'];
		}

		Nuvei_Pfw_Logger::write( $open_order_details, '$open_order_details' );

		// do not allow WCS and Nuvei Subscription in same Order
		if ( ! empty( $products_data['subscr_data'] ) && $products_data['wc_subscr'] ) {
			$msg = 'It is not allowed to put product with WCS and product witn Nuvei Subscription in same Order! Please, contact the site administrator for this problem!';

			Nuvei_Pfw_Logger::write( $msg );

			return array(
				'status'        => 0,
				'custom_msg'    => __( 'You cannot combine those products in same Order.', 'nuvei-payments-for-woocommerce' ),
			);
		}

		// check if product is available when click on Pay button
		//        if ($this->is_ajax
		//            && !empty($products_data['products_data'])
		//            && is_array($products_data['products_data'])
		//        ) {
		//            foreach ($products_data['products_data'] as $data) {
		//                if (!$data['in_stock']) {
		//                    Nuvei_Pfw_Logger::write($data, 'An item is not available.');
		//
		//                    wp_send_json(array(
		//                        'status'    => 0,
		//                        'msg'       => __('An item is not available.', 'nuvei-payments-for-woocommerce')
		//                    ));
		//                    exit;
		//                }
		//            }
		//        }

		# try to update Order or not
		if ( ! is_array( $open_order_details )
			|| empty( $open_order_details['transactionType'] )
			|| empty( $open_order_details['userTokenId'] )
			|| empty( $addresses['billingAddress']['email'] )
			|| $open_order_details['transactionType'] != $transaction_type
			|| $open_order_details['userTokenId'] != $addresses['billingAddress']['email']
            || !empty($this->sc_order)
		) {
			Nuvei_Pfw_Logger::write(
				array(
					'$open_order_details'   => $open_order_details,
					'$transaction_type'     => $transaction_type,
					'$addresses'            => $addresses,
				),
				'$try_update_order = false',
				'DEBUG'
			);

			$try_update_order = false;
		}

		if ( $try_update_order ) {
			$uo_obj = new Nuvei_Pfw_Update_Order( $this->rest_params );
			$resp   = $uo_obj->process(
				array(
					'open_order_details'    => $open_order_details,
					'products_data'         => $products_data,
					'plugin_settings'       => $this->plugin_settings,
				)
			);

			if ( ! empty( $resp['status'] ) && 'SUCCESS' == $resp['status'] ) {
				return $resp;
			} elseif ( ! empty( $resp['status'] ) && ! empty( $resp['reload_checkout'] ) ) {
				wp_send_json( array( 'reload_checkout' => 1 ) );
				exit;
			}
		}
		# /try to update Order or not

		$url_details = array(
			'notificationUrl'   => Nuvei_Pfw_String::get_notify_url( $this->plugin_settings ),
			'backUrl'           => wc_get_checkout_url(),
		);

//		if ( 1 == $this->plugin_settings['close_popup'] ) {
        $url_details['successUrl']  = NUVEI_PFW_POPUP_AUTOCLOSE_URL;
        $url_details['failureUrl']  = NUVEI_PFW_POPUP_AUTOCLOSE_URL;
        $url_details['pendingUrl']  = NUVEI_PFW_POPUP_AUTOCLOSE_URL;
//		}

		$amount     = (string) number_format( $cart_total, 2, '.', '' );
		$currency   = get_woocommerce_currency();
        $cl_un_id   = $this->get_client_unique_id( $addresses['billingAddress']['email'], $products_data );
        
        if (!empty($this->sc_order)) {
            $cl_un_id = $this->sc_order->get_id();
        }

		$oo_params = array(
            'clientUniqueId'    => $cl_un_id,
			'currency'          => $currency,
			'amount'            => $amount,
			'shippingAddress'   => $addresses['shippingAddress'],
			'billingAddress'    => $addresses['billingAddress'],
			'userDetails'       => $addresses['billingAddress'],
			'transactionType'   => $transaction_type,
			'urlDetails'        => $url_details,
			'userTokenId'       => $addresses['billingAddress']['email'], // the decision to save UPO is in the SDK
			'merchantDetails'   => array(
				'customField1'      => $amount,
				'customField2'      => $currency,
			),
		);

		// WC Subsc
		if ( $products_data['wc_subscr'] ) {
			$oo_params['isRebilling'] = 0;
			$oo_params['card']['threeD']['v2AdditionalParams'] = array( // some default params
				'rebillFrequency'   => 30, // days
				'rebillExpiry '     => gmdate( 'Ymd', strtotime( '+5 years' ) ),
			);
		}

		$resp = $this->call_rest_api( 'openOrder', $oo_params );

        if (empty( $resp['status'])) {
            return false;
        }
        
		if ( 'SUCCESS' != $resp['status'] || empty( $resp['sessionToken'] )) {
            return $resp;
		}

		// in default flow
		if ( empty( $this->rest_params ) ) {
			// set them to session for the check before submit the data to the webSDK
			$open_order_details = array(
				'sessionToken'      => $resp['sessionToken'], // use it in updateOrder
				'orderId'           => $resp['orderId'], // use it in updateOrder, this is PPP_TransactionID in the DMN
				'transactionType'   => $oo_params['transactionType'], // use it to decide call or not updateOrder
				'userTokenId'       => $oo_params['userTokenId'], // use it to decide call or not updateOrder
				'clientUniqueId'    => $oo_params['clientUniqueId'], // the new parameter to recognize the Order
			);

			$this->set_nuvei_session_data(
				$resp['sessionToken'],
				$open_order_details,
				$products_data
			);

			Nuvei_Pfw_Logger::write( $open_order_details, 'session open_order_details' );
		}

		$resp['products_data'] = $products_data;

		return array_merge( $resp, $oo_params );
	}
    
	/**
	 * Return keys required to calculate checksum. Keys order is relevant.
	 *
	 * @return array
	 */
	protected function get_checksum_params() {
		return array( 'merchantId', 'merchantSiteId', 'clientRequestId', 'amount', 'currency', 'timeStamp' );
	}
}
