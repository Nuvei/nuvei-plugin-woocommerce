<?php

defined( 'ABSPATH' ) || exit;

/**
 * Get Transaction Details request class.
 * We use this class only when we update Order based on the Transacion ID,
 * provided as aprroved result from Simply Connect transacion.
 */
class Nuvei_Pfw_Get_Trans_Details extends Nuvei_Pfw_Request {

	/**
	 * The main method.
	 *
     * @param array $request_params  Must contain 'orderId' and 'transactionId', optionally 'paymentMethod'.
	 * @return array
	 */
	public function process() {
        $request_params = current( func_get_args() );
        
        Nuvei_Pfw_Logger::write( $request_params, 'Nuvei_Pfw_Get_Trans_Details' );
        
        $tr_id = sanitize_text_field( $request_params['transactionId'] ?? '' );
        
        $resp = $this->call_rest_api( 
            'getTransactionDetails', 
            [ 'transactionId' => $tr_id ]
		);
        
        return $resp;
        
        
        
        
        
        
        
        
        
        
        // error - the Order doesn't belog to Nuvei
        if ( ! $this->is_nuvei_order($order_id, true) ) {
            Nuvei_Pfw_Logger::write( $order_id, 'Nuvei_Pfw_Get_Trans_Details error 1' );
            return [];
        }
        
        if ( empty($this->sc_order) ) {
            $this->sc_order = wc_get_order( $order_id );
        }
        
        // check for saved transacion data
        $transactions_data = $this->sc_order->get_meta( NUVEI_PFW_TRANSACTIONS );
        
        if ( ! empty( $transactions_data[ $tr_id ] ) ) {
            Nuvei_Pfw_Logger::write( 'We have information for this transaction and will not save it again.' );
            return $transactions_data[ $tr_id ];
        }
        
        
        
        
        
        
        
        $pm       = sanitize_text_field( $request_params['paymentMethod'] ?? '' );
        
        
        
        
        
        

        // just to be sure $transactions_data is array so we can fill it.
        if ( empty( $transactions_data ) || ! is_array( $transactions_data ) ) {
            $transactions_data = array();
        }

		
        
        $status             = $resp['transactionDetails']['transactionStatus'] ?? '';
        $transaction_type   = $resp['transactionDetails']['transactionType'] ?? '';
        $currency           = $resp['partialApproval']['requestedCurrency'] ?? '';

        // error - end the action, we expect this transaction to be approved.
		if ( empty( $status ) || 'approved' !== strtolower($status) ) {
            Nuvei_Pfw_Logger::write( $status, 'Nuvei_Pfw_Get_Trans_Details error 2' );
			return;
		}

        // few checks
        if ( ! $this->can_override_order_status(true) ) {
            Nuvei_Pfw_Logger::write( 'Nuvei_Pfw_Get_Trans_Details error 3' );
            return;
        }
        if ( ! $this->check_for_repeating_dmn($tr_id, $status, true) ) {
            Nuvei_Pfw_Logger::write( 'Nuvei_Pfw_Get_Trans_Details error 4' );
            return;
        }
        
        $total = 0;

        if (isset($resp['partialApproval']['requestedAmount'])) {
            $total = number_format($resp['partialApproval']['requestedAmount'], 2, '.');
        }

        // add the new transaction
        $transactions_data[ $tr_id ] = array(
//          'authCode'             => Nuvei_Pfw_Http::get_param( 'AuthCode', 'string', '', $params ), // not available
            'paymentMethod'        => $pm,
            'transactionType'      => $transaction_type,
            'transactionId'        => $tr_id,
//          'relatedTransactionId' => Nuvei_Pfw_Http::get_param( 'relatedTransactionId', 'int', 0, $params ), // not available
            'totalAmount'          => $total,
            'currency'             => $currency,
            'status'               => $status,
            'userPaymentOptionId'  => $resp['paymentOption']['userPaymentOptionId'] ?? '',
            'wcsRenewal'           => false, // this order can be made only from the admin
        );

        $this->sc_order->update_meta_data( NUVEI_PFW_TRANSACTIONS, $transactions_data );

        // Update it only for Auth and Sale. They are base an we will need this TrID
        if ( in_array( $transaction_type, array( 'Auth', 'Sale' ) ) ) {
            Nuvei_Pfw_Logger::write( 'save_transaction_data(), Auth or Sale');
            $this->sc_order->update_meta_data( NUVEI_PFW_TR_ID, $tr_id );
        }
        
        $order_status = strtolower( $this->sc_order->get_status() );
		$order_total  = number_format($this->sc_order->get_total(), 2, '.');

		if ( 'completed' !== $order_status ) {
			$this->change_order_status(
				$order_id,
				$status,
				$transaction_type,
                null,
                $total,
                $tr_id,
                $pm,
                $currency
			);
		}
        
        $this->sc_order->save();
        
		Nuvei_Pfw_Logger::write( 'Order #' . $order_id . ' was updated.' );

		return;
	}

	/**
	 * Return keys required to calculate checksum. Keys order is relevant.
	 *
	 * @return array
	 */
	protected function get_checksum_params() {
		return array( 'merchantId', 'merchantSiteId', 'transactionId', 'timeStamp' );
	}
}
