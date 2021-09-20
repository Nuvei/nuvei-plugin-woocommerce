<?php

defined( 'ABSPATH' ) || exit;

/**
 * The class for paymentAPM and payment requests.
 */
class Nuvei_Payment extends Nuvei_Request {

	/**
	 * The main method.
	 * 
	 * @param array $data
	 * @return array|false
	 */
	public function process() {
		$data = current(func_get_args());
		
		if (empty($data['order_id']) 
			|| empty($data['return_success_url'])
			|| empty($data['return_error_url'])
		) {
			Nuvei_Logger::write($data, 'Nuvei_Payment error missing mandatoriy parameters.');
			return false;
		}
		
		
		$order     = wc_get_order($data['order_id']);
		$addresses = $this->get_order_addresses();
		
		// complicated way to filter all $_POST input, but WP will be happy
		$sc_nonce = Nuvei_Http::get_param('sc_nonce');
		
		if (!empty($sc_nonce)
			&& !wp_verify_nonce($sc_nonce, 'sc_checkout')
		) {
			Nuvei_Logger::write('Nuvei_Payment Error - can not verify WP Nonce.');
			
			return array(
				'status'            => 'ERROR',
				'transactionStatus' => 'ERROR',
				'reason'            => __('Nuvei_Payment Error - can not verify WP Nonce.', 'nuvei_checkout_woocommerce'),
			);
		}
		
		$post_array = $_POST;
		array_walk_recursive($post_array, function ( &$value) {
			$value = trim($value);
			$value = filter_var($value);
		});
		// complicated way to filter all $_POST input, but WP will be happy END
		
		if (!empty($data['return_success_url'])) {
			$this->request_base_params['urlDetails']['successUrl'] = $data['return_success_url'];
			$this->request_base_params['urlDetails']['pendingUrl'] = $data['return_success_url'];
		}
		if (!empty($data['return_error_url'])) {
			$this->request_base_params['urlDetails']['failureUrl'] = $data['return_error_url'];
		}
		
		$params = array(
			'clientUniqueId'    => $this->set_cuid($data['order_id']),
			'currency'          => $order->get_currency(),
			'amount'            => (string) $order->get_total(),
			'billingAddress'	=> $addresses['billingAddress'],
			'userDetails'       => $addresses['billingAddress'],
			'shippingAddress'	=> $addresses['shippingAddress'],
			'sessionToken'      => $post_array['lst'],
			
			'items'             => array(array(
				'name'      => $data['order_id'],
				'price'     => (string) $order->get_total(),
				'quantity'  => 1,
			)),

			'amountDetails'     => array(
				'totalShipping'     => '0.00',
				'totalHandling'     => '0.00',
				'totalDiscount'     => '0.00',
				'totalTax'          => '0.00',
			),
		);
		
		$sc_payment_method = $post_array['sc_payment_method'];
		
		// UPO
		if (is_numeric($sc_payment_method)) {
			$endpoint_method                                = 'payment';
			$params['paymentOption']['userPaymentOptionId'] = $sc_payment_method;
			$params['userTokenId']							= $order->get_billing_email();
		} else { // APM
			$endpoint_method         = 'paymentAPM';
			$params['paymentMethod'] = $sc_payment_method;
			
			if (!empty($post_array[$sc_payment_method])) {
				$params['userAccountDetails'] = $post_array[$sc_payment_method]; // this is array
			}
			
			if (Nuvei_Http::get_param('nuvei_save_upo') == 1) {
				$params['userTokenId'] = $order->get_billing_email();
			}
		}
		
		return $this->call_rest_api($endpoint_method, $params);
	}
	
	/**
	 * Return keys required to calculate checksum. Keys order is relevant.
	 *
	 * @return array
	 */
	protected function get_checksum_params() {
		return array('merchantId', 'merchantSiteId', 'clientRequestId', 'amount', 'currency', 'timeStamp');
	}
}
