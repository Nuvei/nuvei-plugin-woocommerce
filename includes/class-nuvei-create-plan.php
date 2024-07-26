<?php

defined( 'ABSPATH' ) || exit;

/**
 * A class for createPlan request.
 */
class Nuvei_Create_Plan extends Nuvei_Request {

	/**
	 * The main method.
     * As hidden parameters we expect the plugin settings.
	 *
	 * @return array|false
	 */
	public function process() {
        $hiden_args = func_get_args();
        
        if (empty($hiden_args[0]['merchantSiteId'])) {
            Nuvei_Logger::write($hiden_args, 'We expect plugin settings here.');
            
            return array(
				'status'    => 'ERROR',
				'message'   => 'Some mandatory plugin settings are missing.',
			);
        }
        
        $this->plugin_settings  = $hiden_args[0];
		$create_params          = array(
			'name'                  => 'Default_plan_for_site_' . trim( $this->plugin_settings['merchantSiteId'] ),
			'initialAmount'         => 0,
			'recurringAmount'       => 1,
			'currency'              => get_woocommerce_currency(),
			'planStatus'            => 'ACTIVE',
			'startAfter'            => array(
				'day'   => 0,
				'month' => 1,
				'year'  => 0,
			),
			'recurringPeriod'       => array(
				'day'   => 0,
				'month' => 1,
				'year'  => 0,
			),
			'endAfter'              => array(
				'day'   => 0,
				'month' => 0,
				'year'  => 1,
			),
		);

		return $this->call_rest_api( 'createPlan', $create_params );
	}

	protected function get_checksum_params() {
		return array( 'merchantId', 'merchantSiteId', 'name', 'initialAmount', 'recurringAmount', 'currency', 'timeStamp' );
	}
}
