<?php

defined( 'ABSPATH' ) || exit;

/**
 * Cancel Subscription request class.
 */
class Nuvei_Subscription_Cancel extends Nuvei_Request
{
	/**
	 * Main method
	 * 
	 * @param array $prod_plan - plan details
	 * @return array|bool
	 */
	public function process()
    {
		$params = current(func_get_args());
		Nuvei_Logger::write($params, 'Nuvei_Subscription_Cancel');
		
		return $this->call_rest_api('cancelSubscription', $params);
	}

	protected function get_checksum_params() {
		return array(
			'merchantId',
			'merchantSiteId',
			'subscriptionId',
			'timeStamp',
		);
	}
}
