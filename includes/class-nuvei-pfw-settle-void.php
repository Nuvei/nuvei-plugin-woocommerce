<?php

defined( 'ABSPATH' ) || exit;

/**
 * A class for Settle and Void requests.
 */
class Nuvei_Pfw_Settle_Void extends Nuvei_Pfw_Request {
	
    /**
	 * Mandatory method.
	 * Expected parameters are:
	 *
	 * @param  array [order_id, action, method]
	 * @return array|false
	 */
	public function process() {
		$data = current( func_get_args() );

		if ( empty( $data['order_id'] )
			|| empty( $data['action'] )
			|| empty( $data['method'] )
		) {
			Nuvei_Pfw_Logger::write(
                $data,
                'Nuvei_Pfw_Settle_Void error missing mandatoriy parameters.',
                'TRACE'
            );
			return false;
		}

		if ( empty( $this->sc_order ) ) {
			$this->sc_order = wc_get_order( $data['order_id'] );
		}

		$curr       = get_woocommerce_currency();
		$amount     = (string) $this->sc_order->get_total();
		$notify_url = Nuvei_Pfw_String::get_notify_url( $this->plugin_settings );

		if ( 'voidTransaction' == $data['method'] ) {
			$last_tr_id = $this->get_tr_id( $data['order_id'], array( 'Settle', 'Sale', 'Auth' ) );
		} else {
			$last_tr_id = $this->get_tr_id( $data['order_id'], array( 'Auth' ) );
		}

		$params = array(
			'clientUniqueId'       => $data['order_id'],
			'amount'               => $amount,
			'currency'             => $curr,
			'relatedTransactionId' => $last_tr_id,
			'url'                  => $notify_url,
			'urlDetails'           => array( 'notificationUrl' => $notify_url ),
		);

		$resp = $this->call_rest_api( $data['method'], $params );
        $resp = array_merge($resp, $params);
        
        return $resp;
	}

	/**
	 * Create Settle and Void after internal API requests.
     * This is the the main method we use for Settle and Void.
	 *
	 * @param int    $order_id
	 * @param string $action    'settle' or 'void'.
     * @return array
	 */
	public function create_settle_void( $order_id, $action ) {
		$this->is_order_valid( $order_id );

		$is_success = 0;
		
        $resp = $this->process(
			array(
				'order_id' => $order_id,
				'action'   => $action,
				'method'   => 'settle' == $action ? 'settleTransaction' : 'voidTransaction',
			)
		);

		if ( ! empty( $resp['status'] ) && 'SUCCESS' == $resp['status'] ) {
			$is_success = 1;

			$this->sc_order->update_meta_data( NUVEI_PFW_PREV_TRANS_STATUS, $this->sc_order->get_status() );
			
            // change order status 
            $new_status = $this->nuvei_gw->get_option( 'settle' == $action ? 'status_paid' : 'status_void' );
			
            $this->sc_order->update_status( $new_status );
            
            // the follow method works by default with DMN, so it expects in $resp['status'] to have the Transaction Status,
            // as Approved, Declined, etc., so we will modify the 'status' parameter to be as expect.
            $resp['status'] = $resp['transactionStatus'];
            // add and payment method based on the previous transaction
            $resp['payment_method'] = $this->get_payment_method($order_id);
			
            $this->save_transaction_data( $resp );
            
            $this->change_order_status( 
                $order_id, 
                $resp['status'], // we need transactionStatus when works with the direct response!
                $resp['transactionType'], 
                null, 
                $resp['amount'], 
                $resp['transactionId'], 
                $resp['payment_method'] ?? '', 
                $resp['currency'] 
            );

			$this->sc_order->save();
		}

        return array(
            'status' => $is_success,
            'data'   => $resp,
        );
	}

	protected function get_checksum_params() {
		return array( 'merchantId', 'merchantSiteId', 'clientRequestId', 'clientUniqueId', 'amount', 'currency', 'relatedTransactionId', 'url', 'timeStamp' );
	}
}
