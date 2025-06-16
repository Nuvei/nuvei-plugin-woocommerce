<?php

defined( 'ABSPATH' ) || exit;

/**
 * A class for Refund requests.
 */
class Nuvei_Pfw_Refund extends Nuvei_Pfw_Request {


	/**
	 * The main method.
	 *
	 * @param  array $data
	 * @return array|false
	 */
	public function process() {
		$data = current( func_get_args() );

		if ( empty( $data['order_id'] ) || empty( $data['ref_amount'] ) ) {
			Nuvei_Pfw_Logger::write( $data, 'Nuvei_Pfw_Refund error missing mandatoriy parameters.' );
			return false;
		}

		// check if we already have the Order
		if ( empty( $this->sc_order ) ) {
			$this->sc_order = wc_get_order( $data['order_id'] );
		}

		$time       = gmdate( 'YmdHis', time() );
		$notify_url = Nuvei_Pfw_String::get_notify_url( $this->plugin_settings );
		$nuvei_data = $this->sc_order->get_meta( NUVEI_PFW_TRANSACTIONS );
		$last_tr    = $this->get_last_transaction( $nuvei_data, array( 'Sale', 'Settle' ) );

		if ( empty( $last_tr['transactionId'] ) ) {
			wp_send_json(
				array(
					'status' => 0,
					'msg'    => __( 'The Order missing Transaction ID.', 'nuvei-payments-for-woocommerce' ),
				)
			);
			exit;
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

	/**
	 * Create Refund from WC, after the merchant click refund button or set Status to Refunded.
	 *
	 * @param int          $order_id
	 * @param float|string $ref_amount
	 */
	public function create_refund_request( $order_id, $ref_amount ) {
		// error
		if ( $order_id < 1 ) {
			Nuvei_Pfw_Logger::write( $order_id, 'create_refund_request() Error - Post parameter is less than 1.' );

			wp_send_json(
				array(
					'status' => 0,
					'msg'    => __( 'Post parameter is less than 1.', 'nuvei-payments-for-woocommerce' ),
					'data'   => array( $order_id ),
				)
			);
			exit;
		}

		$ref_amount = round( $ref_amount, 2 );

		// error
		if ( $ref_amount < 0 ) {
			wp_send_json(
				array(
					'status' => 0,
					'msg'    => __( 'Invalid Refund amount.', 'nuvei-payments-for-woocommerce' ),
				)
			);
			exit;
		}

		$this->is_order_valid( $order_id );

		// error
		if ( ! $this->sc_order ) {
			wp_send_json(
				array(
					'status' => 0,
					'msg'    => __( 'Error when try to get the Order.', 'nuvei-payments-for-woocommerce' ),
				)
			);
			exit;
		}

		$current_ord_status = $this->sc_order->get_status();
		$pending_status     = $this->nuvei_gw->get_option( 'status_pending' );
		$msg                = '';

		// first set Pending status
		// $this->sc_order->update_status( $this->nuvei_gw->get_option( 'status_pending' ) );

		// then create the Refund request
		$resp = $this->process(
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

			wp_send_json(
				array(
					'status' => 0,
					'msg'    => $msg,
				)
			);
			wp_die();
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

			wp_send_json(
				array(
					'status' => 0,
					'msg'    => $msg,
				)
			);
			wp_die();
		}

		// APPROVED
		if ( ! empty( $json_arr['transactionStatus'] ) && 'APPROVED' == $json_arr['transactionStatus'] ) {
			// change order status
			// $this->sc_order->update_status( $this->nuvei_gw->get_option( 'status_pending' ) );

			// save the Refund into transactions, but without status, unitl DMN come
			// unset( $json_arr['status'] );
			// $json_arr['transactionType'] = 'Credit';
			//
			// $this->save_transaction_data( $json_arr );

			$this->sc_order->add_order_note(
				__(
					'A Refund request was send. Please, wait for response!',
					'nuvei-payments-for-woocommerce'
				)
			);
			$this->sc_order->save();

			wp_send_json( array( 'status' => 1 ) );
			wp_die();
		}

		// in case we have message but without status
		if ( ! isset( $json_arr['status'] ) && isset( $json_arr['msg'] ) ) {
			$msg = __( 'Refund request problem: ', 'nuvei-payments-for-woocommerce' ) . $json_arr['msg'];

			// revert the old status
			$this->sc_order->update_status( $current_ord_status );
			$this->sc_order->add_order_note( $msg );
			$this->sc_order->save();

			Nuvei_Pfw_Logger::write( $msg );

			wp_send_json(
				array(
					'status' => 0,
					'msg'    => $msg,
				)
			);
			wp_die();
		}

		// the status of the request is ERROR
		if ( isset( $json_arr['status'] ) && 'ERROR' === $json_arr['status'] ) {
			$msg = __( 'Request ERROR: ', 'nuvei-payments-for-woocommerce' ) . $json_arr['reason'];

			// revert the old status
			$this->sc_order->update_status( $current_ord_status );

			Nuvei_Pfw_Logger::write( $msg );

			wp_send_json(
				array(
					'status' => 0,
					'msg'    => $msg,
				)
			);
			wp_die();
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

			wp_send_json(
				array(
					'status' => 0,
					'msg'    => $msg,
				)
			);
			wp_die();
		}

		if ( isset( $json_arr['transactionStatus'] ) && 'DECLINED' === $json_arr['transactionStatus'] ) {
			$msg = __( 'The refund was declined.', 'nuvei-payments-for-woocommerce' );

			// revert the old status
			$this->sc_order->update_status( $current_ord_status );
			$this->sc_order->add_order_note( $msg );
			$this->sc_order->save();

			Nuvei_Pfw_Logger::write( $msg );

			wp_send_json(
				array(
					'status' => 0,
					'msg'    => $msg,
				)
			);
			wp_die();
		}

		$msg = __( 'The status of Refund request is UNKONOWN.', 'nuvei-payments-for-woocommerce' );

		// revert the old status
		$this->sc_order->update_status( $current_ord_status );
		$this->sc_order->add_order_note( $msg );
		$this->sc_order->save();

		Nuvei_Pfw_Logger::write( $msg );

		wp_send_json(
			array(
				'status' => 0,
				'msg'    => $msg,
			)
		);
		wp_die();
	}

	protected function get_checksum_params() {
		return array( 'merchantId', 'merchantSiteId', 'clientRequestId', 'clientUniqueId', 'amount', 'currency', 'relatedTransactionId', 'url', 'timeStamp' );
	}
}
