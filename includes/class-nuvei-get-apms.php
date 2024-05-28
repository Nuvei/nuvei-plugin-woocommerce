<?php

defined( 'ABSPATH' ) || exit;

/**
 * A class for getMerchantPaymentMethods request.
 */
class Nuvei_Get_Apms extends Nuvei_Request {

	/**
	 * The main method.
	 *
	 * @param array $args
	 * @return array|false
	 */
	public function process() {
		$args = current( func_get_args() );

		$apms_params = array(
			'sessionToken'      => $args['sessionToken'],
			//          'currencyCode'      => get_woocommerce_currency(),
							'languageCode'      => Nuvei_String::format_location( get_locale() ),
		);

		return $this->call_rest_api( 'getMerchantPaymentMethods', $apms_params );
	}

	protected function get_checksum_params() {
		return array( 'merchantId', 'merchantSiteId', 'clientRequestId', 'timeStamp' );
	}
}
