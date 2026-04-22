<?php

defined( 'ABSPATH' ) || exit;

/**
 * Get Transaction Details request class.
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
