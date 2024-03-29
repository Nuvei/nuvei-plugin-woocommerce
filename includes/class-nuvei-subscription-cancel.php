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
	 * @return array
	 */
	public function process()
    {
		$params = current(func_get_args());
		Nuvei_Logger::write($params, 'Nuvei_Subscription_Cancel');
        
		if (empty($params['subscriptionId'])) {
			Nuvei_Logger::write($params['subscriptionId'], 'There is no Subscription to be canceled.');
			
            return [
                'status' => 'ERROR',
                'reason' => 'subscriptionId is empty.',
            ];
		}

		$resp = $this->call_rest_api('cancelSubscription', $params);
        
        if (!$resp || !is_array($resp) || 'SUCCESS' != $resp['status']) {
            $msg = __('<b>Error</b> when try to cancel Subscription #', 'nuvei_checkout_woocommerce')
                . $params['subscriptionId'] . ' ';

            if (!empty($resp['reason'])) {
                $msg .= '<br/>' . __('<b>Reason</b> ', 'nuvei_checkout_woocommerce') . $resp['reason'];
            }

            $this->sc_order->add_order_note($msg);
            $this->sc_order->save();
        }
        
		return $resp;
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
