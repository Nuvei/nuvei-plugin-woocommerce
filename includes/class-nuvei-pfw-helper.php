<?php

defined( 'ABSPATH' ) || exit;

/**
 * Just a helper class to use some functions form Request class for the Cashier
 * and/or Nuvei_Pfw_Gateway Class.
 */
class Nuvei_Pfw_Helper extends Nuvei_Pfw_Request {


	public function process() {
	}

	public function get_addresses( $rest_params = array() ) {
		if ( ! empty( $rest_params ) ) {
			$this->rest_params = $rest_params;
		}

		return $this->get_order_addresses();
	}

	public function get_products( $rest_params = array(), $order_id = 0 ) {
		if ( ! empty( $rest_params ) ) {
			$this->rest_params = $rest_params;
		}
        
        if (is_numeric($order_id) && $order_id > 0) {
            $this->sc_order = wc_get_order($order_id);
        }

		return $this->get_products_data();
	}

	public function helper_get_tr_id( $order_id = null, $types = array() ) {
		return $this->get_tr_id( $order_id, $types );
	}

	/**
	 * Temp help function until stop using old Order meta fields.
	 *
	 * @param  int|null $order_id WC Order ID
	 * @return int
	 */
	public function get_tr_upo_id( $order_id = null ) {
		$order = $this->get_order( $order_id );

		// first check for new meta data
		$nuvei_data = $order->get_meta( NUVEI_PFW_TRANSACTIONS );

		if ( ! empty( $nuvei_data ) && is_array( $nuvei_data ) ) {
			$last_tr = end( $nuvei_data );

			if ( ! empty( $last_tr['userPaymentOptionId'] ) ) {
				return $last_tr['userPaymentOptionId'];
			}
		}

		// check for old meta data
		return $order->get_meta( '_transactionUpo' ); // NUVEI_UPO
	}

	/**
	 * Get the payment method from the last transaction.
	 *
	 * @param  int|null $order_id WC Order ID
	 * @return int
	 */
	public function helper_get_payment_method( $order_id = null ) {
		return $this->get_payment_method( $order_id );
	}

	/**
	 * Temp help function until stop using old Order meta fields.
	 *
	 * @param  int|null $order_id WC Order ID
	 * @return int
	 */
	public function get_tr_type( $order_id = null ) {
		$order = $this->get_order( $order_id );

		// first check for new meta data
		$nuvei_data = $order->get_meta( NUVEI_PFW_TRANSACTIONS );

		if ( ! empty( $nuvei_data ) && is_array( $nuvei_data ) ) {
			$last_tr = end( $nuvei_data );

			if ( ! empty( $last_tr['transactionType'] ) ) {
				return $last_tr['transactionType'];
			}
		}

		// check for old meta data
		return $order->get_meta( '_transactionType' ); // NUVEI_RESP_TRANS_TYPE
	}

	public function get_rebiling_details( $all_data ) {
		return $this->get_order_rebiling_details( $all_data );
	}

	public function helper_get_web_master_id() {
		return $this->get_web_master_id();
	}
	
	public function helper_get_plugin_version() {
		return $this->get_plugin_version();
	}
    
    /**
     * @param string $transaction_type
     * @param int $order_id
     * @param float $total
     */
    public function helper_start_subscription( $transaction_type, $order_id, $total ) {
        if ( ! $this->sc_order ) {
            $this->get_order( $order_id );
        }
        
        $this->subscription_start( $transaction_type, $order_id, $total );
    }
    
    /**
	 * @param int    $transaction_type
	 * @param int    $order_id
	 * @param string $req_status       The status of the transaction.
	 */
    public function helper_cancel_subscription( $transaction_type, $order_id, $req_status ) {
        if ( ! $this->sc_order ) {
            $this->get_order( $order_id );
        }
        
        $this->subscription_cancel( $transaction_type, $order_id, $req_status );
    }
    
    protected function get_checksum_params() {}
}
