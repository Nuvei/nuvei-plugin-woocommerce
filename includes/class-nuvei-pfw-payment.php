<?php

defined( 'ABSPATH' ) || exit;

/**
 * A class to call payment.do endpoint.
 *
 * @author Nuvei
 */
class Nuvei_Pfw_Payment extends Nuvei_Pfw_Request {

	public function process() {
		/**
		 * expected keys: sessionToken, userTokenId, clientRequestId, currency,
		 *      amount, transactionType, relatedTransactionId, upoId, bCountry, bEmail
		 */
		$data = current( func_get_args() );

		Nuvei_Pfw_Logger::write( $data, 'Nuvei_Pfw_Payment process' );

		$params = array_merge(
			array(
				'sessionToken'    => @$data['sessionToken'],
				'userTokenId'     => @$data['userTokenId'],
				'clientRequestId' => @$data['clientRequestId'],
				'currency'        => @$data['currency'],
				'amount'          => @$data['amount'],
				'transactionType' => 'Sale',
				'urlDetails'      => array( 'notificationUrl' => Nuvei_Pfw_String::get_notify_url( $this->plugin_settings ) ),
				'merchantDetails' => array(
					'customField4' => 'renewal_order',
				),
			),
			$data
		);

		return $this->call_rest_api( 'payment', $params );
	}

	protected function get_checksum_params() {
		return array( 'merchantId', 'merchantSiteId', 'clientRequestId', 'amount', 'currency', 'timeStamp', 'merchantSecretKey' );
	}
}
