<?php

defined( 'ABSPATH' ) || exit;

/**
 * A class for Refund requests.
 */
class Nuvei_Refund extends Nuvei_Request
{
	/**
	 * The main method.
	 * 
	 * @param array $data
	 * @return array|false
	 */
	public function process()
    {
		$data = current(func_get_args());
		
		if (empty($data['order_id']) 
			|| empty($data['ref_amount'])
//			|| empty($data['tr_id'])
		) {
			Nuvei_Logger::write($data, 'Nuvei_Refund error missing mandatoriy parameters.');
			return false;
		}
		
		$time       = gmdate('YmdHis', time());
		$order      = wc_get_order($data['order_id']);
//		$curr       = get_woocommerce_currency();
        $notify_url = Nuvei_String::get_notify_url($this->plugin_settings);
        $nuvei_data = $order->get_meta(NUVEI_TRANSACTIONS);
        $last_tr    = $this->get_last_transaction($nuvei_data, ['Sale', 'Settle']);
        
        if (empty($last_tr['transactionId'])) {
			wp_send_json(array(
				'status' => 0,
				'msg' => __('The Order missing Transaction ID.', 'nuvei_checkout_woocommerce')));
			exit;
		}
		
		$ref_parameters = array(
			'clientRequestId'       => $data['order_id'] . '_' . $time . '_' . uniqid(),
			'clientUniqueId'        => $time . '_' . uniqid(),
			'amount'                => number_format($data['ref_amount'], 2, '.', ''),
//			'relatedTransactionId'  => $data['tr_id'], // GW Transaction ID
			'relatedTransactionId'  => $last_tr['transactionId'],
            'url'                   => $notify_url,
            'urlDetails'            => ['notificationUrl' => $notify_url],
		);
		
		return $this->call_rest_api('refundTransaction', $ref_parameters);
	}
	
	/**
	 * Function create_refund
	 * 
	 * Create Refund in SC by Refund from WC, after the merchant
	 * click refund button or set Status to Refunded
	 */
	public function create_refund_request( $order_id, $ref_amount)
    {
		if ($order_id < 1) {
			Nuvei_Logger::write($order_id, 'create_refund_request() Error - Post parameter is less than 1.');
			
			wp_send_json(array(
				'status' => 0,
				'msg' => __('Post parameter is less than 1.', 'nuvei_checkout_woocommerce'),
				'data' => array($order_id)
			));
			exit;
		}
		
		$ref_amount = round($ref_amount, 2);
		
		if ($ref_amount < 0) {
			wp_send_json(array(
				'status' => 0,
				'msg' => __('Invalid Refund amount.', 'nuvei_checkout_woocommerce')));
			exit;
		}
		
		$this->is_order_valid($order_id);
		
		if (!$this->sc_order) {
			wp_send_json(array(
				'status' => 0,
				'msg' => __('Error when try to get the Order.', 'nuvei_checkout_woocommerce'),
			));
			exit;
		}
		
//		$tr_id = $this->sc_order->get_meta(NUVEI_TRANS_ID);
		
//		if (empty($tr_id)) {
//			wp_send_json(array(
//				'status' => 0,
//				'msg' => __('The Order missing Transaction ID.', 'nuvei_checkout_woocommerce')));
//			exit;
//		}
		
		//      $nr_obj = new Nuvei_Refund($this->plugin_settings);
		$nr_obj = new Nuvei_Refund($this->plugin_settings);
		$resp   = $nr_obj->process(array(
			'order_id'     => $order_id, 
			'ref_amount'   => $ref_amount, 
//			'tr_id'        => $tr_id
		));
		$msg    = '';

		if (false === $resp) {
			$msg = __('The REST API retun false.', 'nuvei_checkout_woocommerce');

			$this->sc_order->add_order_note($msg);
			$this->sc_order->save();
			
			wp_send_json(array(
				'status' => 0,
				'msg' => $msg
			));
			exit;
		}

		$json_arr = $resp;
		if (!is_array($resp)) {
			parse_str($resp, $json_arr);
		}

		if (!is_array($json_arr)) {
			$msg = __('Invalid API response.', 'nuvei_checkout_woocommerce');

			$this->sc_order->add_order_note($msg);
			$this->sc_order->save();
			
			wp_send_json(array(
				'status' => 0,
				'msg' => $msg
			));
			exit;
		}

		// APPROVED
		if (!empty($json_arr['transactionStatus']) && 'APPROVED' == $json_arr['transactionStatus']) {
			$this->sc_order->update_status('processing');
//			$this->save_refund_meta_data($json_arr['transactionId'], $ref_amount);
			
			wp_send_json(array('status' => 1));
			exit;
		}
		
		// in case we have message but without status
		if (!isset($json_arr['status']) && isset($json_arr['msg'])) {
			$msg = __('Refund request problem: ', 'nuvei_checkout_woocommerce') . $json_arr['msg'];

			$this->sc_order->add_order_note($msg);
			$this->sc_order->save();
			
			Nuvei_Logger::write($msg);

			wp_send_json(array(
				'status' => 0,
				'msg' => $msg
			));
			exit;
		}
		
		// the status of the request is ERROR
		if (isset($json_arr['status']) && 'ERROR' === $json_arr['status']) {
			$msg = __('Request ERROR: ', 'nuvei_checkout_woocommerce') . $json_arr['reason'];

			$this->sc_order->add_order_note($msg);
			$this->sc_order->save();
			
			Nuvei_Logger::write($msg);
			
			wp_send_json(array(
				'status' => 0,
				'msg' => $msg
			));
			exit;
		}

		// the status of the request is SUCCESS, check the transaction status
		if (isset($json_arr['transactionStatus']) && 'ERROR' === $json_arr['transactionStatus']) {
			if (isset($json_arr['gwErrorReason']) && !empty($json_arr['gwErrorReason'])) {
				$msg = $json_arr['gwErrorReason'];
			} elseif (isset($json_arr['paymentMethodErrorReason']) && !empty($json_arr['paymentMethodErrorReason'])) {
				$msg = $json_arr['paymentMethodErrorReason'];
			} else {
				$msg = __('Transaction error.', 'nuvei_checkout_woocommerce');
			}

			$this->sc_order->add_order_note($msg);
			$this->sc_order->save();
			
			Nuvei_Logger::write($msg);
			
			wp_send_json(array(
				'status' => 0,
				'msg' => $msg
			));
			exit;
		}

		if (isset($json_arr['transactionStatus']) && 'DECLINED' === $json_arr['transactionStatus']) {
			$msg = __('The refund was declined.', 'nuvei_checkout_woocommerce');

			$this->sc_order->add_order_note($msg);
			$this->sc_order->save();
			
			Nuvei_Logger::write($msg);
			
			wp_send_json(array(
				'status' => 0,
				'msg' => $msg
			));
			exit;
		}

		$msg = __('The status of Refund request is UNKONOWN.', 'nuvei_checkout_woocommerce');

		$this->sc_order->add_order_note($msg);
		$this->sc_order->save();
		
		Nuvei_Logger::write($msg);
		
		wp_send_json(array(
			'status' => 0,
			'msg' => $msg
		));
		exit;
	}

	protected function get_checksum_params()
    {
		return  array('merchantId', 'merchantSiteId', 'clientRequestId', 'clientUniqueId', 'amount', 'relatedTransactionId', 'url', 'timeStamp');
	}
}
