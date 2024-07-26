<?php

defined( 'ABSPATH' ) || exit;

/**
 * Create Subscription request class.
 */
class Nuvei_Pfw_Subscription extends Nuvei_Pfw_Request {

	/**
	 * Main method
	 *
	 * @param array $prod_plan - plan details
	 * @return array|bool
	 */
	public function process() {
		Nuvei_Pfw_Logger::write( 'Subscription class' );

		$prod_plan = current( func_get_args() );

		$params = array_merge(
			array(
				'userPaymentOptionId'   => Nuvei_Pfw_Http::get_param( 'userPaymentOptionId', 'int' ),
				'userTokenId'           => Nuvei_Pfw_Http::get_param( 'user_token_id', 'mail' ),
				'currency'              => Nuvei_Pfw_Http::get_param( 'currency' ),
				'initialAmount'         => 0,
			),
			$prod_plan
		);

		return $this->call_rest_api( 'createSubscription', $params );
	}

	protected function get_checksum_params() {
		return array(
			'merchantId',
			'merchantSiteId',
			'userTokenId',
			'planId',
			'userPaymentOptionId',
			'initialAmount',
			'recurringAmount',
			'currency',
			'timeStamp',
		);
	}
}
