<?php

defined( 'ABSPATH' ) || exit;

/**
 * A class for Refund requests.
 */
class Nuvei_Pfw_Refund extends Nuvei_Pfw_Request {
	
    private $last_tr_id = '';
    
    public function process() {
        
    }
    
	/**
	 * Create Refund from WC.
	 *
	 * @param int          $order_id
	 * @param float|string $ref_amount
     * 
     * @return bool|WP_Error
	 */
	public function create_refund_request( $order_id, $ref_amount ) {
        // error
        if ( ! $this->is_order_valid( $order_id, true ) ) {
            return new WP_Error( 
                'invalid_order', 
                __( 'The Order is not valid or does not belogn to Nuvei.', 'nuvei-payments-for-woocommerce' ) 
            );
        }

		$current_ord_status = $this->sc_order->get_status();
		$msg                = __( 'The status of Refund request is UNKONOWN.', 'nuvei-payments-for-woocommerce' );
        
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

            return new WP_Error( 'invalid_order', $msg );
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

            return new WP_Error( 'invalid_order', $msg );
		}

		// APPROVED
		if ( ! empty( $json_arr['transactionStatus'] ) && 'APPROVED' == $json_arr['transactionStatus'] ) {
			$this->sc_order->add_order_note(
				__(
					'A Refund request was send. Please, wait for response!',
					'nuvei-payments-for-woocommerce'
				)
			);
            
            $this->sc_order->update_meta_data( NUVEI_PFW_PREV_TRANS_STATUS, $this->sc_order->get_status() );
            
            $pm = $this->get_payment_method($order_id);
			
            // change order status 
            $order_amount                   = number_format($this->sc_order->get_total(), 2, '.', '');
            // the follow method works by default with DMN, so it expects in $resp['status'] to have the Transaction Status,
            // as Approved, Declined, etc., so we will modify the 'status' parameter to be as expect.
            $resp['status']                 = $resp['transactionStatus'];
            // add and payment method based on the previous transaction
            $resp['payment_method']         = $pm;
            // add and the refunded amount based on the response
            $resp['totalAmount']            = $ref_amount;
            // add the default currency
            $resp['currency']               = get_woocommerce_currency();
            $resp['relatedTransactionId']   = $this->last_tr_id;
			
            $this->save_transaction_data( $resp );
            
            // add Order Note when the Refund is created
            add_action( 'woocommerce_refund_created', function( $refund_id, $args ) use ( $order_id, $resp ) {
                if ( (int) $args['order_id'] !== (int) $order_id ) {
                    return; // not our Order
                }

                // add the transaction id to the Refund
                $refund = wc_get_order( $refund_id );
                $refund->update_meta_data( '_nuvei_refund_tr_id', $resp['transactionId'] );
                $refund->save();
                
                // create Order Note
                $note = '<b>' . $resp['transactionType'] . ' </b> ' . __( 'request', 'nuvei-payments-for-woocommerce' ) . '.<br/>'
                    . __( 'Response status: ', 'nuvei-payments-for-woocommerce' ) . '<b>' . $resp['transactionStatus'] . '</b>.<br/>'
                    . __( 'Payment Method: ', 'nuvei-payments-for-woocommerce' ) . $resp['payment_method'] . '.<br/>'
                    . __( 'Transaction ID: ', 'nuvei-payments-for-woocommerce' ) . $resp['transactionId'] . '.<br/>'
                    . __( 'Related Tr. ID: ', 'nuvei-payments-for-woocommerce' ) . $resp['relatedTransactionId'] . '.<br/>'
                    . __( 'Transaction Amount: ', 'nuvei-payments-for-woocommerce' ) . $resp['totalAmount'] . ' ' . $resp['currency'] . '.<br/>'
                    . __( 'Refund # ', 'nuvei-payments-for-woocommerce' ) . $refund_id . '.';

                $this->sc_order->add_order_note( $note );
            }, 10, 2 );
            
			$this->sc_order->save();

            return true;
		}

		// error in case we have message but without status
		if ( ! isset( $json_arr['status'] ) && isset( $json_arr['msg'] ) ) {
			$msg = __( 'Refund request problem: ', 'nuvei-payments-for-woocommerce' ) . $json_arr['msg'];
		}
		// the status of the request is ERROR
		elseif ( isset( $json_arr['status'] ) && 'ERROR' === $json_arr['status'] ) {
			$msg = __( 'Request ERROR: ', 'nuvei-payments-for-woocommerce' ) . $json_arr['reason'];
		}
		// the status of the request is SUCCESS, check the transaction status
		elseif ( isset( $json_arr['transactionStatus'] ) && 'ERROR' === $json_arr['transactionStatus'] ) {
			if ( isset( $json_arr['gwErrorReason'] ) && ! empty( $json_arr['gwErrorReason'] ) ) {
				$msg = $json_arr['gwErrorReason'];
			} 
            elseif ( isset( $json_arr['paymentMethodErrorReason'] )
				&& ! empty( $json_arr['paymentMethodErrorReason'] )
			) {
				$msg = $json_arr['paymentMethodErrorReason'];
			} 
            else {
				$msg = __( 'Transaction error.', 'nuvei-payments-for-woocommerce' );
			}
		}
		elseif ( isset( $json_arr['transactionStatus'] ) && 'DECLINED' === $json_arr['transactionStatus'] ) {
			$msg = __( 'The refund was declined.', 'nuvei-payments-for-woocommerce' );
		}

		// revert the old status
		$this->sc_order->update_status( $current_ord_status );
		$this->sc_order->add_order_note( $msg );
		$this->sc_order->save();

		Nuvei_Pfw_Logger::write( $msg );

//        return array(
//            'status' => 0,
//            'msg'    => $msg,
//        );
        
        return new WP_Error( 'invalid_order', $msg );
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

		$time               = gmdate( 'YmdHis', time() );
		$notify_url         = Nuvei_Pfw_String::get_notify_url( $this->plugin_settings );
		$nuvei_data         = $this->sc_order->get_meta( NUVEI_PFW_TRANSACTIONS );
		$last_tr            = $this->get_last_transaction( $nuvei_data, array( 'Sale', 'Settle' ) );
        $this->last_tr_id   = $last_tr['transactionId'];

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
