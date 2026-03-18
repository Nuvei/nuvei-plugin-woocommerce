<?php

defined( 'ABSPATH' ) || exit;

/**
 * Update Order request class.
 */
class Nuvei_Pfw_Update_Order extends Nuvei_Pfw_Request {


	public function __construct( $rest_params = array() ) {
		if ( ! empty( $rest_params ) ) {
			$this->rest_params = $rest_params;
		}

		parent::__construct();
	}

	/**
	 * Main method
	 *
	 * @param array $products_data
	 * @param array $open_order_details Pass them only in REST API flow.
	 *
	 * @return array
	 */
	public function process() {
        Nuvei_Pfw_Logger::write( 'update_order()' );

		$func_params        = current( func_get_args() );
		$products_data      = $func_params['products_data'] ?? array();
		$open_order_details = $func_params['open_order_details'] ?? array();
        $order_id           = $func_params['order_id'] ?? null;
        $session_token      = $open_order_details['sessionToken'] ?? $func_params['session_token'] ?? null;
        $oo_order_id        = $open_order_details['orderId'] ?? $func_params['oo_order_id'] ?? null;

        if ( ! empty(WC()->cart->total) ) {
            $cart_amount = (string) number_format( WC()->cart->total, 2, '.', '' );
        }
        else {
			$cart_amount = (string) number_format( $products_data['totals'], 2, '.', '' );
		}

		if ( empty( $session_token ) || empty( $oo_order_id ) ) {
			Nuvei_Pfw_Logger::write(
                [
                    '$session_token'    => $session_token,
                    '$oo_order_id'      => $oo_order_id,
                ],
                'update_order() - Missing mandatory data for UpdateOrder.'
            );

			return array( 'status' => 'ERROR' );
		}

		$addresses = $this->get_order_addresses();

		// prevent update with empty values
		foreach ( $addresses['billingAddress'] as $key => $val ) {
			if ( empty( trim( (string) $val ) ) ) {
				unset( $addresses['billingAddress'][ $key ] );
			}
		}

		$currency = get_woocommerce_currency();

		$url_details = array(
			'notificationUrl' => Nuvei_Pfw_String::get_notify_url( $this->plugin_settings ),
			'backUrl'         => wc_get_checkout_url(),
		);

		$url_details['successUrl'] = NUVEI_PFW_POPUP_AUTOCLOSE_URL;
		$url_details['failureUrl'] = NUVEI_PFW_POPUP_AUTOCLOSE_URL;
		$url_details['pendingUrl'] = NUVEI_PFW_POPUP_AUTOCLOSE_URL;

		// create Order upgrade
		$params = array(
			'sessionToken'    => $session_token,
			'orderId'         => $oo_order_id,
			'currency'        => $currency,
			'amount'          => $cart_amount,
			'billingAddress'  => $addresses['billingAddress'],
			'userDetails'     => $addresses['billingAddress'],
			'shippingAddress' => $addresses['shippingAddress'],
			'urlDetails'      => $url_details,

			'items'           => array(
				array(
					'name'     => 'wc_order',
					'price'    => $cart_amount,
					'quantity' => 1,
				),
			),

			'merchantDetails' => array(
				'customField1' => $cart_amount,
				'customField2' => $currency,
			),
		);

        // if the Order already exists, pass the its ID here, as we cannot update clientUniqueId
        if ( !empty($order_id) ) {
            $params['merchantDetails']['customField5'] = $order_id;
        }
        elseif ( is_a( $this->sc_order, 'WC_Order' ) ) {
            $params['merchantDetails']['customField5'] = $this->sc_order->get_id();
        }

		// WC Subsc
		if ( ! empty( $products_data['wc_subscr'] ) ) {
			$params['isRebilling']                          = 0;
			$params['card']['threeD']['v2AdditionalParams'] = array( // some default params
				'rebillFrequency' => 30, // days
				'rebillExpiry '   => gmdate( 'Ymd', strtotime( '+5 years' ) ),
			);
		} else {
			$params['isRebilling'] = null;
			$params['card']        = null;
		}

		$resp = $this->call_rest_api( 'updateOrder', $params );

        // if there is status return the response
		if ( ! empty( $resp['status'] ) ) {
			$params['products_data'] = $products_data;

			return array_merge( $params, $resp );
		}

        // error
		Nuvei_Pfw_Logger::write( 'Nuvei_Pfw_Update_Order - Missing response status.' );

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
