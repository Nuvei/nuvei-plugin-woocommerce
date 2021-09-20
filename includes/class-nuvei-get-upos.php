<?php

defined( 'ABSPATH' ) || exit;

/**
 * A class for getUserUPOs request.
 */
class Nuvei_Get_Upos extends Nuvei_Request {

	/**
	 * The main method.
	 * 
	 * @param array $args - the Open Order data
	 * @return array|bool
	 */
	public function process() {
		$args = current(func_get_args());
		
		if (empty($args['billingAddress']['email'])) {
			Nuvei_Logger::write($args, 'Nuvei_Get_Upos error, missing Billing Address Email.');
			return false;
		}
		
		$upo_params = array(
			'userTokenId' => $args['billingAddress']['email'],
		);
		
		return $this->call_rest_api('getUserUPOs', $upo_params);
	}

	protected function get_checksum_params() {
		return array('merchantId', 'merchantSiteId', 'userTokenId', 'clientRequestId', 'timeStamp');
	}
}
