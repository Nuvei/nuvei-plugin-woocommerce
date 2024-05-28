<?php

defined( 'ABSPATH' ) || exit;

/**
 * Update Order request class.
 */
class Nuvei_Update_Order extends Nuvei_Request {

	public function __construct( $rest_params = array() ) {
		if ( ! empty( $rest_params ) ) {
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
	//  public function process($products_data, $open_order_details = [])
	public function process() {
		global $woocommerce;

		$func_params        = current( func_get_args() );
		$products_data      = $func_params['products_data'] ?? array();
		$open_order_details = $func_params['open_order_details'] ?? array();
		$plugin_settings    = $func_params['plugin_settings'] ?? array();

		//        Nuvei_Logger::write(json_encode($woocommerce->cart->cart_contents));

		// default flow
		if ( empty( $this->rest_params ) && ! empty( $woocommerce->session ) ) {
			$open_order_details = $woocommerce->session->get( NUVEI_SESSION_OO_DETAILS );
			$cart_amount        = (string) number_format( (float) $woocommerce->cart->total, 2, '.', '' );
		} else { // REST API flow
			$cart_amount = (string) number_format( (float) $products_data['totals'], 2, '.', '' );
		}

		if ( empty( $open_order_details )
			|| empty( $open_order_details['sessionToken'] )
			|| empty( $open_order_details['orderId'] )
		) {
			Nuvei_Logger::write( $open_order_details, 'update_order() - Missing last Order session data.' );

			return array( 'status' => 'ERROR' );
		}

		$addresses = $this->get_order_addresses();

		// prevent update with empty values
		foreach ( $addresses['billingAddress'] as $key => $val ) {
			if ( empty( trim( $val ) ) ) {
				unset( $addresses['billingAddress'][ $key ] );
			}
		}

		$currency = get_woocommerce_currency();

		$url_details = array(
			'notificationUrl'   => Nuvei_String::get_notify_url( $this->plugin_settings ),
			'backUrl'           => wc_get_checkout_url(),
		);

		if ( 1 == $plugin_settings['close_popup'] ) {
			$url_details['successUrl']  = NUVEI_SDK_AUTOCLOSE_URL;
			$url_details['failureUrl']  = NUVEI_SDK_AUTOCLOSE_URL;
			$url_details['pendingUrl']  = NUVEI_SDK_AUTOCLOSE_URL;
		}

		// create Order upgrade
		$params = array(
			'sessionToken'      => $open_order_details['sessionToken'],
			'orderId'           => $open_order_details['orderId'],
			'currency'          => $currency,
			'amount'            => $cart_amount,
			'billingAddress'    => $addresses['billingAddress'],
			'userDetails'       => $addresses['billingAddress'],
			'shippingAddress'   => $addresses['shippingAddress'],
			'urlDetails'        => $url_details,

			'items'             => array(
				array(
					'name'      => 'wc_order',
					'price'     => $cart_amount,
					'quantity'  => 1,
				),
			),

			'merchantDetails'   => array(
				'customField1'      => $cart_amount,
				'customField2'      => $currency,
			),
		);

		// WC Subsc
		if ( ! empty( $products_data['wc_subscr'] ) ) {
			$oo_params['isRebilling'] = 0;
			$oo_params['card']['threeD']['v2AdditionalParams'] = array( // some default params
				'rebillFrequency'   => 30, // days
				'rebillExpiry '     => gmdate( 'Ymd', strtotime( '+5 years' ) ),
			);
		} else {
			$oo_params['isRebilling']   = null;
			$oo_params['card']          = null;
		}

		//        Nuvei_Logger::write(strlen($params['merchantDetails']['customField1']), 'customField1 len');

		$resp = $this->call_rest_api( 'updateOrder', $params );

		# Success
		if ( ! empty( $resp['status'] ) && 'SUCCESS' == $resp['status'] ) {
			// in default flow
			if ( empty( $this->rest_params ) ) {
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

			return array_merge( $params, $resp );
		}

		Nuvei_Logger::write( 'Nuvei_Update_Order - Order update was not successful.' );

		return array( 'status' => 'ERROR' );
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
