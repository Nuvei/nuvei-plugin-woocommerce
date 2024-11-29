<?php

defined( 'ABSPATH' ) || exit;

/**
 * Get a Session token for the getMerchantPaymentMethods request.
 */
class Nuvei_Pfw_Session_Token extends Nuvei_Pfw_Request {


	public function process() {
		return $this->call_rest_api( 'getSessionToken', array() );
	}

	protected function get_checksum_params() {
		return array( 'merchantId', 'merchantSiteId', 'clientRequestId', 'timeStamp', 'merchantSecretKey' );
	}
}
