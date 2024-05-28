<?php

defined( 'ABSPATH' ) || exit;

/**
 * Just a helper class to use some functions form Request class for the Cashier
 * and/or Nuvei_Gateway Class.
 */
class Nuvei_Helper extends Nuvei_Request {

	public function process() {
	}

	protected function get_checksum_params() {
	}

	public function get_addresses( $rest_params = array() ) {
		if ( ! empty( $rest_params ) ) {
			$this->rest_params = $rest_params;
		}

		return $this->get_order_addresses();
	}

	public function get_products( $rest_params = array() ) {
		if ( ! empty( $rest_params ) ) {
			$this->rest_params = $rest_params;
		}

		return $this->get_products_data();
	}

	public function helper_get_tr_id( $order_id = null, $types = array() ) {
		return $this->get_tr_id( $order_id, $types );
	}

	/**
	 * Temp help function until stop using old Order meta fields.
	 *
	 * @param int|null $order_id WC Order ID
	 * @return int
	 */
	public function get_tr_upo_id( $order_id = null ) {
		$order = $this->get_order( $order_id );

		// first check for new meta data
		$nuvei_data = $order->get_meta( NUVEI_TRANSACTIONS );

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
	 * Temp help function until stop using old Order meta fields.
	 *
	 * @param int|null $order_id WC Order ID
	 * @return int
	 */
	public function get_payment_method( $order_id = null ) {
		$order = $this->get_order( $order_id );

		// first check for new meta data
		$nuvei_data = $order->get_meta( NUVEI_TRANSACTIONS );

		if ( ! empty( $nuvei_data ) && is_array( $nuvei_data ) ) {
			$last_tr = $this->get_last_transaction( $nuvei_data, array( 'Sale', 'Settle', 'Auth' ) );

			if ( ! empty( $last_tr['paymentMethod'] ) ) {
				return $last_tr['paymentMethod'];
			}
		}

		// check for old meta data
		return $order->get_meta( '_paymentMethod' ); // NUVEI_PAYMENT_METHOD
	}

	/**
	 * Temp help function until stop using old Order meta fields.
	 *
	 * @param int|null $order_id WC Order ID
	 * @return int
	 */
	public function get_tr_type( $order_id = null ) {
		$order = $this->get_order( $order_id );

		// first check for new meta data
		$nuvei_data = $order->get_meta( NUVEI_TRANSACTIONS );

		if ( ! empty( $nuvei_data ) && is_array( $nuvei_data ) ) {
			$last_tr = end( $nuvei_data );

			if ( ! empty( $last_tr['transactionType'] ) ) {
				return $last_tr['transactionType'];
			}
		}

		// check for old meta data
		return $order->get_meta( '_transactionType' ); // NUVEI_RESP_TRANS_TYPE
	}

	public function get_rest_total( $rest_params ) {
		$this->rest_params = $rest_params;

		return $this->get_total_from_rest_params();
	}

	public function get_rebiling_details( $all_data ) {
		return $this->get_order_rebiling_details( $all_data );
	}
}
