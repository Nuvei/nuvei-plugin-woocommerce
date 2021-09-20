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
		$args                          = current(func_get_args());
		$currency                      = !empty($args['currency']) ? $args['currency'] : get_woocommerce_currency();
		$nuvei_last_open_order_details = WC()->session->get('nuvei_last_open_order_details');
		
		if (!empty($args['billingAddress']['country'])) {
			$countryCode = $args['billingAddress']['country'];
		} elseif (!empty($nuvei_last_open_order_details['billingAddress']['country'])) {
			$countryCode = $nuvei_last_open_order_details['billingAddress']['country'];
		} else {
			$addresses = $this->get_order_addresses();
			
			if (!empty($addresses['billingAddress']['country'])) {
				$countryCode = $addresses['billingAddress']['country'];
			} else {
				$countryCode = '';
			}
		}
		
		$apms_params = array(
			'sessionToken'      => $args['sessionToken'],
			'currencyCode'      => $currency,
			'countryCode'       => $countryCode,
			'languageCode'      => Nuvei_String::format_location(get_locale()),
		);
		
		return $this->call_rest_api('getMerchantPaymentMethods', $apms_params);
	}
	
	protected function get_checksum_params() {
		return array('merchantId', 'merchantSiteId', 'clientRequestId', 'timeStamp');
	}
}
