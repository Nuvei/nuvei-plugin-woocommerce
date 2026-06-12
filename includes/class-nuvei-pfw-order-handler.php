<?php

defined( 'ABSPATH' ) || exit;

/**
 * A helper class to mutate WC Orders from classes that do not extend Nuvei_Pfw_Request,
 * such as Nuvei_Payments_For_Woocommerce (main plugin class).
 *
 * @author Nuvei
 */
class Nuvei_Pfw_Order_Handler extends Nuvei_Pfw_Request {

    public function process() {
    }

    protected function get_checksum_params() {
    }

    public function is_nuvei_order_public( $order_id, $return_respons = false ) {
        return $this->is_nuvei_order( $order_id, $return_respons );
    }

    public function can_override_order_status_public( $return_respons = false ) {
        return $this->can_override_order_status( $return_respons );
    }

    public function check_for_repeating_dmn_public( $trId, $status, $return_respons = false ) {
        return $this->check_for_repeating_dmn( $trId, $status, $return_respons );
    }

    public function change_order_status_public(
        $order_id,
        $req_status,
        $transaction_type,
        $refund_id = null,
        $total = null,
        $tr_id = null,
        $pm = null,
        $curr = null
    ) {
        return $this->change_order_status( $order_id, $req_status, $transaction_type, $refund_id, $total, $tr_id, $pm, $curr );
    }

    public function update_order_meta( $key, $data ) {
        if ( ! $this->sc_order instanceof WC_Order ) {
            return false;
        }

        $this->sc_order->update_meta_data( $key, $data );
    }

    public function get_order_meta( $key ) {
        if ( ! $this->sc_order instanceof WC_Order ) {
            return false;
        }

        return $this->sc_order->get_meta( $key );
    }

    public function get_order_status() {
        if ( ! $this->sc_order instanceof WC_Order ) {
            return false;
        }

        return $this->sc_order->get_status();
    }

    public function save_order() {
        if ( ! $this->sc_order instanceof WC_Order ) {
            return false;
        }

        $this->sc_order->save();
    }
    
}
