<?php

defined( 'ABSPATH' ) || exit;

/**
 * A class for getPlansList request.
 */
class Nuvei_Pfw_Download_Plans extends Nuvei_Pfw_Request {

	/**
	 * The main method.
	 *
	 * @return array|false
	 */
	public function process() {
		$params = array(
			'planStatus'        => 'ACTIVE',
			'currency'          => '',
		);

		return $this->call_rest_api( 'getPlansList', $params );
	}

	protected function get_checksum_params() {
		return array( 'merchantId', 'merchantSiteId', 'currency', 'planStatus', 'timeStamp' );
	}
}
