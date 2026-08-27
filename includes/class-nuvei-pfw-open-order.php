<?php

defined( 'ABSPATH' ) || exit;

/**
 * The class for openOrder request.
 */
class Nuvei_Pfw_Open_Order extends Nuvei_Pfw_Request {

	/**
	 * @param array $plugin_settings
	 * @param array $rest_params REST call params if any.
	 */
	public function __construct( array $plugin_settings, $rest_params = array() ) {
		parent::__construct();
        
		$this->plugin_settings = $plugin_settings;
		$this->rest_params     = $rest_params;
	}

	/**
	 * The main method.
	 *
	 * @return array|boolean
	 */
	public function process() {
		Nuvei_Pfw_Logger::write( 'OpenOrder class process.' );

		$try_update_order   = true;
		$method_params      = func_get_args(); // optionaly we will pass here Order ID.
        $open_order_details = WC()->session->get( NUVEI_PFW_SESSION_OO_DETAILS ) ?? [];
        $ord_redirect_url   = '';
        
		// if we pass Order ID get the order.
		if ( ! empty( $method_params[0]['order_id'] ) ) {
			$this->sc_order = wc_get_order( $method_params[0]['order_id'] );
            
            if ($this->sc_order) {
                $ord_redirect_url = $this->sc_order->get_checkout_order_received_url();
            }
		}

        # try to use incoming parameters
        if (!empty($this->rest_params['transactionType'])) {
            $open_order_details['transactionType'] = $this->rest_params['transactionType'];
        }
        // this is the OO Order ID, not WC Order ID!
        if (!empty($this->rest_params['orderId'])) {
            $open_order_details['orderId'] = $this->rest_params['orderId'];
        }
        if (!empty($this->rest_params['email'])) {
            $open_order_details['userTokenId'] = $this->rest_params['email'];
        }
        if (!empty($this->rest_params['sessionToken'])) {
            $open_order_details['sessionToken'] = $this->rest_params['sessionToken'];
        }
        
        $products_data  = $this->get_products_data();
        $addresses      = $this->get_order_addresses();
        $cart_total     = '0';
        
        if (is_numeric($products_data['totals'])) {
            $cart_total = $products_data['totals'];
        }
        elseif (is_numeric($products_data['totals']['total'])) {
            $cart_total = $products_data['totals']['total'];
        }
        
        if ( $this->get_total_from_rest_params($cart_total) == 0 ) {
            $transaction_type = 'Auth';
        }
        else {
            $transaction_type = $this->plugin_settings['payment_action'];
        }
                
		Nuvei_Pfw_Logger::write( $open_order_details, '$open_order_details' );

		// error - do not allow WCS and Nuvei Subscription in same Order
		if ( ! empty( $products_data['subscr_data'] ) && $products_data['wc_subscr'] ) {
			Nuvei_Pfw_Logger::write( 'It is not allowed to put product with WCS and product witn Nuvei Subscription in same Order! Please, contact the site administrator for this problem!' );

			return array(
				'status'     => 0,
				'custom_msg' => __( 'You cannot combine those products in same Order.', 'nuvei-payments-for-woocommerce' ),
			);
		}
        
		// try to update Order or not
		if ( ! is_array( $open_order_details )
			|| empty( $open_order_details['transactionType'] )
			|| empty( $open_order_details['userTokenId'] )
			|| empty( $addresses['billingAddress']['email'] )
			|| $open_order_details['transactionType'] != $transaction_type
			|| $open_order_details['userTokenId'] != $addresses['billingAddress']['email']
			|| ! empty( $this->sc_order )
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

        // try to update Order or not
		if ( $try_update_order ) {
            Nuvei_Pfw_Logger::write( $products_data, 'updateOrder $products_data' );
            
			$uo_obj = new Nuvei_Pfw_Update_Order( $this->rest_params );
			$resp   = $uo_obj->process(
				array(
					'open_order_details' => $open_order_details,
					'products_data'      => $products_data,
					'plugin_settings'    => $this->plugin_settings,
				)
			);

            // updateOrder succeeded
            if ( ! empty( $resp['status'] ) && 'SUCCESS' == $resp['status'] ) {
                // Update_Order already called save the session data
                $saved_oo_details = array(
                    'sessionToken'    => $resp['sessionToken'],
                    'orderId'         => $resp['orderId'],
                    'transactionType' => $transaction_type,
                    'userTokenId'     => $addresses['billingAddress']['email'],
                    'clientUniqueId'  => $resp['clientUniqueId'] ?? '',
                );

                $this->set_nuvei_session_data(
                    $resp['sessionToken'],
                    $saved_oo_details,
                    $products_data
                );
                
                // in case of Admin Order update the metas which hold Subscription data
                $this->set_subscr_meta($products_data);

                Nuvei_Pfw_Logger::write( $saved_oo_details, 'updateOrder success - session open_order_details' );

                $resp['transactionType']    = $transaction_type;
                $resp['ordRedirectUrl']     = $ord_redirect_url;
                
                // success
                return $resp;
            }

            // updateOrder failed — fall through to a fresh openOrder below
            Nuvei_Pfw_Logger::write( 'updateOrder failed, falling through to openOrder.' );
		}

		$url_details = array(
			'notificationUrl' => Nuvei_Pfw_String::get_notify_url( $this->plugin_settings ),
			'backUrl'         => wc_get_checkout_url(),
		);

		// if ( 1 == $this->plugin_settings['close_popup'] ) {
		$url_details['successUrl'] = NUVEI_PFW_POPUP_AUTOCLOSE_URL;
		$url_details['failureUrl'] = NUVEI_PFW_POPUP_AUTOCLOSE_URL;
		$url_details['pendingUrl'] = NUVEI_PFW_POPUP_AUTOCLOSE_URL;
		// }

		$amount   = (string) number_format( $cart_total, 2, '.', '' );
		$currency = get_woocommerce_currency();
		$cl_un_id = $this->get_client_unique_id( $addresses['billingAddress']['email'], $products_data );

		if ( ! empty( $this->sc_order ) ) {
			$cl_un_id = $this->sc_order->get_id();
		}

		$oo_params = array(
			'clientUniqueId'  => $cl_un_id,
			'currency'        => $currency,
			'amount'          => $amount,
			'shippingAddress' => $addresses['shippingAddress'],
			'billingAddress'  => $addresses['billingAddress'],
			'userDetails'     => $addresses['billingAddress'],
			'transactionType' => $transaction_type,
			'urlDetails'      => $url_details,
			'userTokenId'     => $addresses['billingAddress']['email'], // the decision to save UPO is in the SDK
			'merchantDetails' => array(
				'customField1' => $amount,
				'customField2' => $currency,
			),
		);
        
        $dd_name    = trim($this->plugin_settings['dd_name'] ?? '');
        $dd_phone   = trim($this->plugin_settings['dd_phone'] ?? '');
        
        if ( !empty($dd_name) || !empty($dd_phone)) {
            $oo_params['dynamicDescriptor'] = [
                'merchantName'  => $dd_name,
                'merchantPhone' => $dd_phone,
            ];
        }

		// WC Subsc
		if ( $products_data['wc_subscr'] ) {
			$oo_params['isRebilling']                          = 0;
			$oo_params['card']['threeD']['v2AdditionalParams'] = array( // some default params
				'rebillFrequency' => 30, // days
				'rebillExpiry '   => gmdate( 'Ymd', strtotime( '+5 years' ) ),
			);
		}

		$resp = $this->call_rest_api( 'openOrder', $oo_params );

        // error
        if ( empty( $resp['status'] ) || 'SUCCESS' != $resp['status'] || empty( $resp['sessionToken'] ) ) {
            Nuvei_Pfw_Logger::write( $resp, 'openOrder failed.' );
            return $resp ?: false;
        }

        // set them to session for the check before submit the data to the webSDK
        $open_order_details = array(
            'sessionToken'    => $resp['sessionToken'], // use it in updateOrder
            'orderId'         => $resp['orderId'], // use it in updateOrder, this is PPP_TransactionID in the DMN
            'transactionType' => $oo_params['transactionType'], // use it to decide call or not updateOrder
            'userTokenId'     => $oo_params['userTokenId'], // use it to decide call or not updateOrder
            'clientUniqueId'  => $oo_params['clientUniqueId'], // the new parameter to recognize the Order
        );

        $this->set_nuvei_session_data(
            $resp['sessionToken'],
            $open_order_details,
            $products_data
        );
        
        // in case of Admin Order update the metas which hold Subscription data
        $this->set_subscr_meta($products_data);

        Nuvei_Pfw_Logger::write( $open_order_details, 'session open_order_details' );

		$resp['products_data']  = $products_data;
        $resp['ordRedirectUrl'] = $ord_redirect_url;

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
    
    private function set_subscr_meta( $products_data ) {
        if ( $this->sc_order ) {
            // save the Nuvei Subscr data to the order
            if ( ! empty( $products_data['subscr_data'] ) ) {
                foreach ( $products_data['subscr_data'] as $data ) {
                    // set meta key
                    if ( !empty( $data['product_id'] ) ) {
                        $meta_key = NUVEI_PFW_ORDER_SUBSCR . '_product_' . $data['product_id'];
                    }
                    if ( !empty( $data['variation_id'] ) ) {
                        $meta_key = NUVEI_PFW_ORDER_SUBSCR . '_variation_' . $data['variation_id'];
                    }

                    $this->sc_order->update_meta_data( $meta_key, $data );
                }
            }

            // mark order if there is WC Subsc
            if ( ! empty( $products_data['wc_subscr'] ) ) {
                $this->sc_order->update_meta_data( NUVEI_PFW_WC_SUBSCR, true );
            }
            
            $this->sc_order->save();
        }
    }
}
