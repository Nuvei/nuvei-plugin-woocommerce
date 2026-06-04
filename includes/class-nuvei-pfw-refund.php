<?php

defined( 'ABSPATH' ) || exit;

/**
 * A class for Refund requests.
 */
class Nuvei_Pfw_Refund extends Nuvei_Pfw_Request {
	
    public function process() {
        
    }
    
	/**
	 * Create Refund from WC.
	 *
	 * @param int          $order_id
	 * @param float|string $ref_amount
	 */
	public function create_refund_request( $order_id, $ref_amount ) {
		// error
		if ( $order_id < 1 ) {
			Nuvei_Pfw_Logger::write( $order_id, 'create_refund_request() Error - Post parameter is less than 1.' );

            return array(
                'status' => 0,
                'msg'    => __( 'Post parameter is less than 1.', 'nuvei-payments-for-woocommerce' ),
                'data'   => array( $order_id ),
            );
		}

		$ref_amount = round( $ref_amount, 2 );

		// error
		if ( $ref_amount < 0 ) {
            return array(
                'status' => 0,
                'msg'    => __( 'Invalid Refund amount.', 'nuvei-payments-for-woocommerce' ),
            );
		}

		$this->is_order_valid( $order_id );

		// error
		if ( ! $this->sc_order ) {
            return array(
                'status' => 0,
                'msg'    => __( 'Error when try to get the Order.', 'nuvei-payments-for-woocommerce' ),
            );
		}

		$current_ord_status = $this->sc_order->get_status();
		$pending_status     = $this->nuvei_gw->get_option( 'status_pending' );
		$msg                = '';

		// create the Refund request
		$resp = $this->process_refund(
			array(
				'order_id'   => $order_id,
				'ref_amount' => $ref_amount,
			)
		);

		// error
		if ( false === $resp ) {
			$msg = __( 'The REST API retun false.', 'nuvei-payments-for-woocommerce' );

			// revert the old status
			$this->sc_order->update_status( $current_ord_status );
			$this->sc_order->add_order_note( $msg );
			$this->sc_order->save();

            return array(
                'status' => 0,
                'msg'    => $msg,
            );
		}

		$json_arr = $resp;

		if ( ! is_array( $resp ) ) {
			parse_str( $resp, $json_arr );
		}

		// error
		if ( ! is_array( $json_arr ) ) {
			$msg = __( 'Invalid API response.', 'nuvei-payments-for-woocommerce' );

			// revert the old status
			$this->sc_order->update_status( $current_ord_status );
			$this->sc_order->add_order_note( $msg );
			$this->sc_order->save();

            return array(
                'status' => 0,
                'msg'    => $msg,
            );
		}

		// APPROVED
		if ( ! empty( $json_arr['transactionStatus'] ) && 'APPROVED' == $json_arr['transactionStatus'] ) {
			$this->sc_order->add_order_note(
				__(
					'A Refund request was send. Please, wait for response!',
					'nuvei-payments-for-woocommerce'
				)
			);
			$this->sc_order->save();

            return array( 'status' => 1 );
		}

		// error in case we have message but without status
		if ( ! isset( $json_arr['status'] ) && isset( $json_arr['msg'] ) ) {
			$msg = __( 'Refund request problem: ', 'nuvei-payments-for-woocommerce' ) . $json_arr['msg'];

			// revert the old status
			$this->sc_order->update_status( $current_ord_status );
			$this->sc_order->add_order_note( $msg );
			$this->sc_order->save();

			Nuvei_Pfw_Logger::write( $msg );

            return array(
                'status' => 0,
                'msg'    => $msg,
            );
		}

		// the status of the request is ERROR
		if ( isset( $json_arr['status'] ) && 'ERROR' === $json_arr['status'] ) {
			$msg = __( 'Request ERROR: ', 'nuvei-payments-for-woocommerce' ) . $json_arr['reason'];

			// revert the old status
			$this->sc_order->update_status( $current_ord_status );

			Nuvei_Pfw_Logger::write( $msg );

            return array(
                'status' => 0,
                'msg'    => $msg,
            );
		}

		// the status of the request is SUCCESS, check the transaction status
		if ( isset( $json_arr['transactionStatus'] ) && 'ERROR' === $json_arr['transactionStatus'] ) {
			if ( isset( $json_arr['gwErrorReason'] ) && ! empty( $json_arr['gwErrorReason'] ) ) {
				$msg = $json_arr['gwErrorReason'];
			} elseif ( isset( $json_arr['paymentMethodErrorReason'] )
				&& ! empty( $json_arr['paymentMethodErrorReason'] )
			) {
				$msg = $json_arr['paymentMethodErrorReason'];
			} else {
				$msg = __( 'Transaction error.', 'nuvei-payments-for-woocommerce' );
			}

			// revert the old status
			$this->sc_order->update_status( $current_ord_status );
			$this->sc_order->add_order_note( $msg );
			$this->sc_order->save();

			Nuvei_Pfw_Logger::write( $msg );

            return array(
                'status' => 0,
                'msg'    => $msg,
            );
		}

		if ( isset( $json_arr['transactionStatus'] ) && 'DECLINED' === $json_arr['transactionStatus'] ) {
			$msg = __( 'The refund was declined.', 'nuvei-payments-for-woocommerce' );

			// revert the old status
			$this->sc_order->update_status( $current_ord_status );
			$this->sc_order->add_order_note( $msg );
			$this->sc_order->save();

			Nuvei_Pfw_Logger::write( $msg );

            return array(
                'status' => 0,
                'msg'    => $msg,
            );
		}

		$msg = __( 'The status of Refund request is UNKONOWN.', 'nuvei-payments-for-woocommerce' );

		// revert the old status
		$this->sc_order->update_status( $current_ord_status );
		$this->sc_order->add_order_note( $msg );
		$this->sc_order->save();

		Nuvei_Pfw_Logger::write( $msg );

        return array(
            'status' => 0,
            'msg'    => $msg,
        );
	}

	protected function get_checksum_params() {
		return array( 'merchantId', 'merchantSiteId', 'clientRequestId', 'clientUniqueId', 'amount', 'currency', 'relatedTransactionId', 'url', 'timeStamp' );
	}

    /**
	 * The main method.
	 *
	 * @param  array $data
	 * @return array|false
	 */
	private function process_refund() {
		$data = current( func_get_args() );

		if ( empty( $data['order_id'] ) || empty( $data['ref_amount'] ) ) {
			Nuvei_Pfw_Logger::write( $data, 'Nuvei_Pfw_Refund error missing mandatoriy parameters.' );
			return array(
			    'status' => 0,
			    'msg'    => __( 'Nuvei_Pfw_Refund error missing mandatoriy parameters.', 'nuvei-payments-for-woocommerce' ),
			);
		}

		// check if we already have the Order
		if ( empty( $this->sc_order ) ) {
			$this->sc_order = wc_get_order( $data['order_id'] );
		}

		$time       = gmdate( 'YmdHis', time() );
		$notify_url = Nuvei_Pfw_String::get_notify_url( $this->plugin_settings );
		$nuvei_data = $this->sc_order->get_meta( NUVEI_PFW_TRANSACTIONS );
		$last_tr    = $this->get_last_transaction( $nuvei_data, array( 'Sale', 'Settle' ) );

		// error
		if ( empty( $last_tr['transactionId'] ) ) {
			Nuvei_Pfw_Logger::write( $nuvei_data, 'Nuvei_Pfw_Refund::process() - The Order is missing Transaction ID.' );

			return array(
				'status' => 0,
				'msg'    => __( 'The Order missing Transaction ID.', 'nuvei-payments-for-woocommerce' ),
			);
		}

		$ref_parameters = array(
			'clientRequestId'      => $time . '_' . uniqid(),
			'clientUniqueId'       => $data['order_id'] . '_' . $time . '_' . uniqid(),
			'amount'               => number_format( $data['ref_amount'], 2, '.', '' ),
			'currency'             => get_woocommerce_currency(),
			'relatedTransactionId' => $last_tr['transactionId'],
			'url'                  => $notify_url,
			'urlDetails'           => array( 'notificationUrl' => $notify_url ),
		);

		return $this->call_rest_api( 'refundTransaction', $ref_parameters );
	}
    
}
