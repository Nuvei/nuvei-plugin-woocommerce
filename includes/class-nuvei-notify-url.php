<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class to work the DMNs.
 */
class Nuvei_Notify_Url extends Nuvei_Request {

	public function __construct( $plugin_settings) {
		$this->plugin_settings = $plugin_settings;
	}
	
	public function process() {
		Nuvei_Logger::write($_REQUEST, 'DMN params');
		
		// stop DMNs only on test mode
		if (Nuvei_Http::get_param('stop_dmn', 'int') == 1 && 'yes' == $this->plugin_settings['test']) {
			$params             = $_REQUEST;
			$params['stop_dmn'] = 0;
			
			Nuvei_Logger::write(
				get_site_url() . '/?' . http_build_query($params),
				'DMN was stopped, please run it manually from the URL bleow:'
			);
			
			echo wp_json_encode('DMN was stopped, please run it manually!');
			exit;
		}
		
        if (!$this->validate_checksum()) {
			echo wp_json_encode('DMN Error - Checksum validation problem!');
			exit;
		}
        
		// santitized get variables
		$clientUniqueId       = $this->get_cuid();
		$transactionType      = Nuvei_Http::get_param('transactionType');
		$order_id             = Nuvei_Http::get_param('order_id', 'int');
		$TransactionID        = Nuvei_Http::get_param('TransactionID', 'int');
		$relatedTransactionId = Nuvei_Http::get_param('relatedTransactionId', 'int');
		$dmnType              = Nuvei_Http::get_param('dmnType');
		$client_request_id    = Nuvei_Http::get_param('clientRequestId');
		
		$req_status = Nuvei_Http::get_request_status();
		
		if (empty($req_status) && empty($dmnType)) {
			Nuvei_Logger::write('DMN Error - the Status is empty!');
			echo wp_json_encode('DMN Error - the Status is empty!');
			exit;
		}
        
		# Subscription State DMN
		if ('subscription' == $dmnType) {
			$subscriptionState = Nuvei_Http::get_param('subscriptionState');
			$subscriptionId    = Nuvei_Http::get_param('subscriptionId', 'int');
			$planId            = Nuvei_Http::get_param('planId', 'int');
			$cri_parts         = explode('_', $client_request_id);
			
			if (empty($cri_parts) || empty($cri_parts[0]) || !is_numeric($cri_parts[0])) {
				Nuvei_Logger::write($cri_parts, 'DMN Subscription Error with Client Request Id parts:');
				echo wp_json_encode('DMN Subscription Error with Client Request Id parts.');
				exit;
			}
			
			$this->is_order_valid((int) $cri_parts[0]);
			
			if (!empty($subscriptionState)) {
				if ('active' == strtolower($subscriptionState)) {
					$msg = __('<b>Subscription is Active</b>.', 'nuvei_checkout_woocommerce') . '<br/>'
						. __('<b>Subscription ID:</b> ', 'nuvei_checkout_woocommerce') . $subscriptionId . '<br/>'
						. __('<b>Plan ID:</b> ', 'nuvei_checkout_woocommerce') . Nuvei_Http::get_param('planId', 'int');
					
					// save the Subscription ID
					$ord_subscr_ids = json_decode($this->sc_order->get_meta(NUVEI_ORDER_SUBSCR_IDS));
					
					if (empty($ord_subscr_ids)) {
						$ord_subscr_ids = array();
					}
					
					// just add the ID without the details, we need only the ID to cancel the Subscription
					if (!in_array($subscriptionId, $ord_subscr_ids)) {
						$ord_subscr_ids[] = $subscriptionId;
					}
					
					$this->sc_order->update_meta_data(NUVEI_ORDER_SUBSCR_IDS, json_encode($ord_subscr_ids));
				} elseif ('inactive' == strtolower($subscriptionState)) {
					$msg = __('<b>Subscription is Inactive</b>.', 'nuvei_checkout_woocommerce') . '<br/>' 
						. __('<b>Subscription ID:</b> ', 'nuvei_checkout_woocommerce') . $subscriptionId . '<br/>' 
						. __('<b>Plan ID:</b> ', 'nuvei_checkout_woocommerce') . $planId;
				} elseif ('canceled' == strtolower($subscriptionState)) {
					$msg = __('<b>Subscription</b> was canceled.', 'nuvei_checkout_woocommerce') . '<br/>'
						. __('<b>Subscription ID:</b> ', 'nuvei_checkout_woocommerce') . $subscriptionId;
				}
				
				$this->sc_order->add_order_note($msg);
				$this->sc_order->save();
			}

			echo wp_json_encode('DMN received.');
			exit;
		}
		# Subscription State DMN END
		
		if (empty($TransactionID)) {
			Nuvei_Logger::write('DMN error - The TransactionID is empty!');
			echo wp_json_encode('DMN error - The TransactionID is empty!');
			exit;
		}
		
		# Subscription Payment DMN
		if ('subscriptionPayment' == $dmnType && 0 != $TransactionID) {
			$cri_parts = explode('_', $client_request_id);
			
			if (empty($cri_parts) || empty($cri_parts[0]) || !is_numeric($cri_parts[0])) {
				Nuvei_Logger::write($cri_parts, 'DMN Subscription Payment Error with Client Request Id parts:');
				echo wp_json_encode('DMN Subscription Payment Error with Client Request Id parts.');
				exit;
			}
			
			$this->is_order_valid((int) $cri_parts[0]);
			
			$msg = sprintf(
				/* translators: %s: the status of the Payment */
				__('<b>Subscription Payment</b> with Status %s was made.', 'nuvei_checkout_woocommerce'),
				$req_status
			)
				. '<br/>' . __('<b>Plan ID:</b> ', 'nuvei_checkout_woocommerce') 
				. Nuvei_Http::get_param('planId', 'int') . '.'
				. '<br/>' . __('<b>Subscription ID:</b> ', 'nuvei_checkout_woocommerce') 
				. Nuvei_Http::get_param('subscriptionId', 'int') . '.'
				. '<br/>' . __('<b>Amount:</b> ', 'nuvei_checkout_woocommerce') . $this->sc_order->get_currency() . ' '
				. Nuvei_Http::get_param('totalAmount', 'float') . '.'
				. '<br/>' . __('<b>TransactionId:</b> ', 'nuvei_checkout_woocommerce') . $TransactionID;

			Nuvei_Logger::write($msg, 'Subscription DMN Payment');
			
			$this->sc_order->add_order_note($msg);
			
			echo wp_json_encode('DMN received.');
			exit;
		}
		# Subscription Payment DMN END
		
		# Sale and Auth
		if (in_array($transactionType, array('Sale', 'Auth'), true)) {
			// SDK
			if ( !is_numeric($clientUniqueId) && 0 != $TransactionID ) {
				$order_id = $this->get_order_by_trans_id($TransactionID, $transactionType);
				
			} elseif (empty($order_id) && is_numeric($clientUniqueId)) { // REST
				Nuvei_Logger::write($order_id, '$order_id');

				$order_id = $clientUniqueId;
			}
			
			$this->is_order_valid($order_id);
            $this->check_for_repeating_dmn();
			$this->save_update_order_numbers();
			
			$order_status   = strtolower($this->sc_order->get_status());
            $order_total    = round($this->sc_order->get_total(), 2);
			
			if ('completed' !== $order_status) {
				$this->change_order_status(
					$order_id,
					$req_status,
					$transactionType
				);
			}
			
			$this->subscription_start($transactionType, $order_id, $order_total);
			
			echo esc_html('DMN process end for Order #' . $order_id);
			exit;
		}
		
		// try to get the Order ID
		$ord_data = $this->get_order_data($relatedTransactionId);

		if (!empty($ord_data[0]->post_id)) {
			$order_id = $ord_data[0]->post_id;
		}
			
		# Void, Settle
		if ('' != $clientUniqueId
			&& ( in_array($transactionType, array('Void', 'Settle'), true) )
		) {
			$this->is_order_valid($clientUniqueId);
            $this->check_for_repeating_dmn();
			
			if ('Settle' == $transactionType) {
				$this->save_update_order_numbers();
			}

			$this->change_order_status($order_id, $req_status, $transactionType);
			$this->subscription_start($transactionType, $clientUniqueId);
			
			if ('Void' == $transactionType) {
				$this->subscription_cancel($clientUniqueId);
			}
				
			echo wp_json_encode('DMN received.');
			exit;
		}
		
		# Refund
		if (in_array($transactionType, array('Credit', 'Refund'), true)) {
			if (0 == $order_id) {
				$order_id = $this->get_order_by_trans_id($relatedTransactionId, $transactionType);
			}
            
            $this->check_for_repeating_dmn();
			$this->create_refund_record($order_id);
			
			$this->change_order_status(
				$order_id,
				$req_status,
				$transactionType
			);

			echo wp_json_encode(array('DMN process end for Order #' . $order_id));
			exit;
		}
		
		Nuvei_Logger::write(
			array(
				'TransactionID' => $TransactionID,
				'relatedTransactionId' => $relatedTransactionId,
			),
			'DMN was not recognized.'
		);
		
		echo wp_json_encode('DMN was not recognized.');
		exit;
	}

	protected function get_checksum_params() {
		
	}
	
	/**
	 * Get client unique id.
	 * We change it only for Sandbox (test) mode.
	 * 
	 * @return int|string
	 */
	private function get_cuid() {
		$clientUniqueId = Nuvei_Http::get_param('clientUniqueId');
		
		if ('yes' != $this->plugin_settings['test']) {
			return $clientUniqueId;
		}
		
		if (strpos($clientUniqueId, NUVEI_CUID_POSTFIX) !== false) {
			return current(explode('_', $clientUniqueId));
		}
		
		return $clientUniqueId;
	}

	/**
	 * Validate advanceResponseChecksum and/or responsechecksum parameters.
	 *
	 * @return boolean
	 */
	private function validate_checksum() {
		$advanceResponseChecksum = Nuvei_Http::get_param('advanceResponseChecksum');
		$responsechecksum        = Nuvei_Http::get_param('responsechecksum');
		
		if (empty($advanceResponseChecksum) && empty($responsechecksum)) {
			Nuvei_Logger::write(null, 'advanceResponseChecksum and responsechecksum parameters are empty.', 'CRITICAL');
			return false;
		}
		
		// advanceResponseChecksum case
		if (!empty($advanceResponseChecksum)) {
			$concat = $this->plugin_settings['secret'] 
				. Nuvei_Http::get_param('totalAmount')
				. Nuvei_Http::get_param('currency') 
				. Nuvei_Http::get_param('responseTimeStamp')
				. Nuvei_Http::get_param('PPP_TransactionID') 
				. Nuvei_Http::get_request_status()
				. Nuvei_Http::get_param('productId');
			
			$str = hash($this->plugin_settings['hash_type'], $concat);

			if (strval($str) == $advanceResponseChecksum) {
				return true;
			}

			Nuvei_Logger::write(null, 'advanceResponseChecksum validation fail.', 'WARN');
			return false;
		}
		
		# subscription DMN with responsechecksum case
		$concat        = '';
		$request_arr   = $_REQUEST;
		$custom_params = array(
			'wc-api'            => '',
			'save_logs'         => '',
			'test_mode'         => '',
			'stop_dmn'          => '',
			'responsechecksum'  => '',
		);
		
		// remove parameters not part of the checksum
		$dmn_params = array_diff_key($request_arr, $custom_params);
		$concat     = implode('', $dmn_params);
		
		$concat_final = $concat . $this->plugin_settings['secret'];
		$checksum     = hash($this->plugin_settings['hash_type'], $concat_final);
		
		if ($responsechecksum !== $checksum) {
            $log_data = [];
            
            if('yes' == $this->plugin_settings['test']) {
                $log_data['string concat']  = $concat;
                $log_data['hash']           = $this->plugin_settings['hash_type'];
                $log_data['checksum']       = $checksum;
//                $log_data['dmn_params']     = array_keys($dmn_params)
                ;
            }
            
			Nuvei_Logger::write($log_data, 'responsechecksum validation fail.', 'WARN');
			return false;
		}
		
		return true;
	}
	
	/**
	 * Get the Order data by Transaction ID.
	 * 
	 * @param int $trans_id
	 * @param string $transactionType
	 * 
	 * @return int
	 */
	private function get_order_by_trans_id( $trans_id, $transactionType = '') {
		// try to get Order ID by its meta key
		$tries				= 0;
		$max_tries			= 10;
		$order_request_time	= Nuvei_Http::get_param('customField3', 'int'); // time of create/update order
		
		// do not search more than once if the DMN response time is more than 1 houre before now
		if ($order_request_time > 0
			&& in_array($transactionType, array('Auth', 'Sale', 'Credit', 'Refund'), true)
			&& ( time() - $order_request_time > 3600 )
		) {
			$max_tries = 0;
		}

		do {
			$tries++;

			$res = $this->get_order_data($trans_id);

			if (empty($res[0]->post_id)) {
				sleep(3);
			}
		} while ($tries <= $max_tries && empty($res[0]->post_id));

		if (empty($res[0]->post_id)) {
			Nuvei_Logger::write(
				array(
					'trans_id' => $trans_id,
					'order data' => $res
				),
				'The searched Order does not exists.'
			);
			
			http_response_code(400);
			echo wp_json_encode('The searched Order does not exists.');
			exit;
		}

		return $res[0]->post_id;
	}
	
	/**
	 * Just a repeating code.
	 * 
	 * @global type $wpdb
	 * @param int $TransactionID
	 * @return type
	 */
	private function get_order_data( $TransactionID) {
		global $wpdb;
		
		$res = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %s ;",
				NUVEI_TRANS_ID,
				$TransactionID
			)
		);
				
		return $res;
	}
	
	/**
	 * Function save_update_order_numbers
	 * Save or update order AuthCode and TransactionID on status change.
	 */
	private function save_update_order_numbers() {
		// save or update AuthCode and Transaction ID
		$auth_code = Nuvei_Http::get_param('AuthCode', 'int');
		if (!empty($auth_code)) {
			$this->sc_order->update_meta_data(NUVEI_AUTH_CODE_KEY, $auth_code);
		}

		$transaction_id = Nuvei_Http::get_param('TransactionID', 'int');
		if (!empty($transaction_id)) {
			$this->sc_order->update_meta_data(NUVEI_TRANS_ID, $transaction_id);
		}
		
		$pm = Nuvei_Http::get_param('payment_method');
		if (!empty($pm)) {
			$this->sc_order->update_meta_data(NUVEI_PAYMENT_METHOD, $pm);
		}

		$tr_type = Nuvei_Http::get_param('transactionType');
		if (!empty($tr_type)) {
			$this->sc_order->update_meta_data(NUVEI_RESP_TRANS_TYPE, $tr_type);
		}
		
		$tr_curr = Nuvei_Http::get_param('currency');
		if (!empty($tr_curr)) {
			$this->sc_order->update_meta_data(NUVEI_TRANS_CURR, $tr_curr);
		}
        
        $tr_status = Nuvei_Http::get_request_status();
        if (!empty($tr_status)) {
			$this->sc_order->update_meta_data(NUVEI_TRANS_STATUS, $tr_status);
		}
		
		$this->sc_order->save();
	}
	
	/**
	 * The start of create subscriptions logic.
	 * We call this method when we've got Settle or Sale DMNs.
	 * 
	 * @param string    $transactionType
	 * @param int       $order_id
	 * @param float     $order_total Pass the Order Total only for Auth.
	 */
	private function subscription_start($transactionType, $order_id, $order_total = null)
    {
        $subscr_data = json_decode(Nuvei_Http::get_param('customField1', 'json'), true);
        
		if (!in_array($transactionType, array('Settle', 'Sale', 'Auth'))
            || empty($subscr_data)
            || !is_array($subscr_data)
        ) {
			return;
		}
		
		$prod_plan = current($subscr_data);
		
		if (empty($prod_plan) || !is_array($prod_plan)) {
			Nuvei_Logger::write($prod_plan, 'There is a problem with the DMN Product Payment Plan data:');
			return;
		}
        
        if('Auth' == $transactionType && null !== $order_total && 0 < $order_total) {
            Nuvei_Logger::write($order_total, 'We allow Rebilling for Auth only when the Order total is 0.');
            return;
        }
		
		// this is the only place to pass the Order ID, we will need it later, to identify the Order
		$prod_plan['clientRequestId'] = $order_id . '_' . uniqid();
		
		$ns_obj = new Nuvei_Subscription($this->plugin_settings);
		
		// check for more than one products of same type
		$qty        = 1;
		$items_data = json_decode(Nuvei_Http::get_param('customField2', 'json'), true);
		
		if (!empty($items_data) && is_array($items_data)) {
			$items_data_curr = current($items_data);
			
			if (!empty($items_data_curr['quantity']) && is_numeric($items_data_curr['quantity'])) {
				$qty = $items_data_curr['quantity'];
				
				Nuvei_Logger::write('We will create ' . $qty . ' subscriptions.');
			}
		}
		
		for ($qty; $qty > 0; $qty--) {
			$resp = $ns_obj->process($prod_plan);
		
			// On Error
			if (!$resp || !is_array($resp) || empty($resp['status']) || 'SUCCESS' != $resp['status']) {
				$msg = __('<b>Error</b> when try to start a Subscription by the Order.', 'nuvei_checkout_woocommerce');

				if (!empty($resp['reason'])) {
					$msg .= '<br/>' . __('<b>Reason:</b> ', 'nuvei_checkout_woocommerce') . $resp['reason'];
				}
				
				$this->sc_order->add_order_note($msg);
				$this->sc_order->save();
				
				break;
			}
			
			// On Success
			$msg = __('<b>Subscription</b> was created. ') . '<br/>'
				. __('<b>Subscription ID:</b> ', 'nuvei_checkout_woocommerce') . $resp['subscriptionId'] . '.<br/>' 
				. __('<b>Recurring amount:</b> ', 'nuvei_checkout_woocommerce') . $this->sc_order->get_currency() . ' '
				. $prod_plan['recurringAmount'];

			$this->sc_order->add_order_note($msg);
			$this->sc_order->save();
		}
			
		return;
	}
	
	/**
	 * Change the status of the order.
	 *
	 * @param int    $order_id The Order Id.
	 * @param string $req_status The Status of the request.
	 * @param string $transaction_type The type of the transaction.
	 */
	private function change_order_status( $order_id, $req_status, $transaction_type ) {
		Nuvei_Logger::write(
			'Order ' . $order_id . ' was ' . $req_status,
			'Nuvei change_order_status()'
		);
        
        $msg_transaction = '<b>';
                
//        if($this->is_partial_settle === true) {
//            $msg_transaction .= __("Partial ");
//        }

        $msg_transaction .= __( Nuvei_Http::get_param( 'transactionType' ), 'nuvei_checkout_woocommerce' ) 
                . ' </b> ' . __( 'request', 'nuvei_checkout_woocommerce' ) . '.<br/>';

		$gw_data = $msg_transaction
			. __( 'Response status: ', 'nuvei_checkout_woocommerce' ) . '<b>' . $req_status . '</b>.<br/>'
			. __( 'Payment Method: ', 'nuvei_checkout_woocommerce' ) . Nuvei_Http::get_param( 'payment_method' ) . '.<br/>'
            . __( 'Transaction ID: ', 'nuvei_checkout_woocommerce' ) . Nuvei_Http::get_param( 'TransactionID', 'int' ) . '.<br/>'
            . __( 'Related Transaction ID: ', 'nuvei_checkout_woocommerce' ) 
                . Nuvei_Http::get_param( 'relatedTransactionId', 'int' ) . '.<br/>'
            . __( 'Transaction Amount: ', 'nuvei_checkout_woocommerce' ) 
                . number_format(Nuvei_Http::get_param( 'totalAmount', 'float' ), 2, '.', '') 
                . ' ' . Nuvei_Http::get_param( 'currency') . '.';

		$message = '';
		$status  = $this->sc_order->get_status();
        
		switch ($req_status) {
			case 'CANCELED':
				$message            = $gw_data;
				$this->msg['class'] = 'woocommerce_message';
				
				if (in_array($transaction_type, array('Auth', 'Settle', 'Sale'))) {
					$status = 'failed';
				}
				break;

			case 'APPROVED':
				if ( 'Void' === $transaction_type ) {
					$message = $gw_data;

					$status = 'cancelled';
				} elseif ( in_array( $transaction_type, array( 'Credit', 'Refund' ), true ) ) {
					$message = $gw_data;
					$status  = 'completed';
					
					// get current refund amount
					$refunds         = json_decode($this->sc_order->get_meta(NUVEI_REFUNDS), true);
					$currency_code   = $this->sc_order->get_currency();
					$currency_symbol = get_woocommerce_currency_symbol( $currency_code );
					
					if (isset($refunds[Nuvei_Http::get_param('TransactionID', 'int')]['refund_amount'])) {
						$message .= '<br/><b>' . __('<b>Refund Amount: ') . '</b>' 
							. number_format($refunds[Nuvei_Http::get_param('TransactionID', 'int')]['refund_amount'], 2, '.', '') . $currency_symbol
							. '<br/><b>' . __('<b>Refund: ') . ' #</b>' 
							. $refunds[Nuvei_Http::get_param('TransactionID', 'int')]['wc_id'];
					}
					
					if (round($this->sc_order->get_total(), 2) <= $this->sum_order_refunds()) {
						$status = 'refunded';
					}
				} elseif ( 'Auth' === $transaction_type ) {
					$message = $gw_data;
					$status  = 'pending';
				} elseif ( in_array( $transaction_type, array( 'Settle', 'Sale' ), true ) ) {
					$message = $gw_data;
					$status  = 'completed';
					
					$this->sc_order->payment_complete($order_id);
				}
				
				// check for correct amount and currency
				if (in_array($transaction_type, array('Auth', 'Sale'), true)) {
					$order_amount = round(floatval($this->sc_order->get_total()), 2);
					$dmn_amount   = round(Nuvei_Http::get_param('totalAmount', 'float'), 2);
					
					if ($order_amount !== $dmn_amount) {
						$message .= '<br/><b>' . __('Payment ERROR!', 'nuvei_checkout_woocommerce') . '</b> ' 
							. $dmn_amount . ' ' . Nuvei_Http::get_param('currency')
							. ' ' . __('paid instead of', 'nuvei_checkout_woocommerce') . ' ' . $order_amount
							. ' ' . $this->sc_order->get_currency() . '!';
						
						$status = 'failed';
						
						Nuvei_Logger::write(
							array(
								'order_amount' => $order_amount,
								'dmn_amount'   => $dmn_amount,
							),
							'DMN amount and Order amount do not much.'
						);
					}
				}
				
				if ($this->sc_order->get_currency() !== Nuvei_Http::get_param('currency')) {
					$message .= '<br/><b>' . __('Payment ERROR!', 'nuvei_checkout_woocommerce') . '</b> '
							. __('The Order currency is ', 'nuvei_checkout_woocommerce') 
							. $this->sc_order->get_currency()
							. __( ', but the DMN currency is ', 'nuvei_checkout_woocommerce' )
							. Nuvei_Http::get_param( 'currency' ) . '!';

						$status = 'failed';
						
						Nuvei_Logger::write(
							array(
								'order currency' => $this->sc_order->get_currency(),
								'dmn currency'   => Nuvei_Http::get_param( 'currency' ),
							),
							'DMN currency and Order currency do not much.'
						);
				}
				
				$this->msg['class'] = 'woocommerce_message';
				break;

			case 'ERROR':
			case 'DECLINED':
			case 'FAIL':
				$reason = ',<br/>' . __( '<b>Reason:</b> ', 'nuvei_checkout_woocommerce' );
				if ( '' != Nuvei_Http::get_param( 'reason' ) ) {
					$reason .= Nuvei_Http::get_param( 'reason' );
				} elseif ( '' != Nuvei_Http::get_param( 'Reason' ) ) {
					$reason .= Nuvei_Http::get_param( 'Reason' );
				}
				
				$message = $gw_data . '<br/>'
					. __( 'Error code: ', 'nuvei_checkout_woocommerce' ) . Nuvei_Http::get_param( 'ErrCode' ) . '<br/>'
					. __( 'Message: ', 'nuvei_checkout_woocommerce' ) . Nuvei_Http::get_param( 'message' ) . $reason;
				
				// do not change status
//				if ('Void' === $transaction_type) {
//					$message = 'Your Void request <b>fail</b>.';
//				}
				if (in_array($transaction_type, array('Auth', 'Settle', 'Sale'))) {
					$status = 'failed';
				}
				
				$this->msg['class'] = 'woocommerce_message';
				break;

			case 'PENDING':
				if ( 'processing' === $status || 'completed' === $status ) {
					break;
				}

//				$message            = __( 'Payment is still pending.', 'nuvei_checkout_woocommerce' ) . $gw_data;
				$message            = $gw_data;
				$this->msg['class'] = 'woocommerce_message woocommerce_message_info';
				$status             = 'on-hold';
				break;
		}
		
		if (!empty($message)) {
			$this->msg['message'] = $message;
			$this->sc_order->add_order_note( $this->msg['message'] );
		}

		$this->sc_order->update_status( $status );
		$this->sc_order->save();
		
		Nuvei_Logger::write($status, 'Status of Order #' . $order_id . ' was set to');
	}
	
	/**
	 * Try to Cancel any Subscription if there are.
	 * 
	 * @param int $order_id
	 * @return void
	 */
	private function subscription_cancel( $order_id) {
		Nuvei_Logger::write($order_id, 'subscription_cancel()');
		
		$subscr_ids = json_decode($this->sc_order->get_meta(NUVEI_ORDER_SUBSCR_IDS));

		if (empty($subscr_ids) || !is_array($subscr_ids)) {
			Nuvei_Logger::write($subscr_ids, 'DMN Message - there is no Subscription to be canceled.');
			return;
		}

		$ncs_obj = new Nuvei_Subscription_Cancel($this->plugin_settings);

		foreach ($subscr_ids as $id) {
			$resp = $ncs_obj->process(array('subscriptionId' => $id));

			// On Error
			if (!$resp || !is_array($resp) || 'SUCCESS' != $resp['status']) {
				$msg = __('<b>Error</b> when try to cancel Subscription #', 'nuvei_checkout_woocommerce') . $id . ' ';

				if (!empty($resp['reason'])) {
					$msg .= '<br/>' . __('<b>Reason:</b> ', 'nuvei_checkout_woocommerce') . $resp['reason'];
				}
				
				$this->sc_order->add_order_note($msg);
				$this->sc_order->save();
			}
		}
		
		return;
	}
	
	/**
	 * Create a Refund in WC.
	 * 
	 * @param int $order_id
	 * @return int the order id
	 */
	private function create_refund_record( $order_id) {
		$refunds	= array();
		$ref_amount = 0;
		$tries		= 0;
		$ref_tr_id	= Nuvei_Http::get_param('TransactionID', 'int');
		
		$this->is_order_valid($order_id);
		
		if ( !in_array($this->sc_order->get_status(), array('completed', 'processing')) ) {
			Nuvei_Logger::write(
				$this->sc_order->get_status(),
				'DMN Refund Error - the Order status does not allow refunds, the status is:'
			);

			echo wp_json_encode(array('DMN Refund Error - the Order status does not allow refunds.'));
			exit;
		}
		
		// there is chance of slow saving of meta data (in create_refund_record()), so let's wait
		do {
			$refunds = json_decode($this->sc_order->get_meta(NUVEI_REFUNDS), true);
			Nuvei_Logger::write('create_refund_record() Wait for Refund meta data.');
			
			sleep(3);
			$tries++;
		} while (empty($refunds[$ref_tr_id]) && $tries < 5);
		
		Nuvei_Logger::write($refunds, 'create_refund_record() Saved refunds for Order #' . $order_id);
		
		// check for DMN trans ID in the refunds
		if (!empty($refunds[$ref_tr_id])
			&& 'pending' == $refunds[$ref_tr_id]['status']
			&& !empty($refunds[$ref_tr_id]['refund_amount'])
		) {
			$ref_amount = $refunds[$ref_tr_id]['refund_amount'];
		} elseif (0 == $ref_amount && strpos(Nuvei_Http::get_param('clientRequestId'), 'gwp_') !== false) {
			// in case of CPanel refund - add Refund meta data here
			$ref_amount = Nuvei_Http::get_param('totalAmount', 'float');
		}
		
		if (0 == $ref_amount) {
			Nuvei_Logger::write('create_refund_record() Refund Amount is 0, do not create Refund in WooCommerce.');
			
			return;
		}
		
		$refund = wc_create_refund(array(
			'amount'	=> round(floatval($ref_amount), 2),
			'order_id'	=> $order_id,
		));
		
		if (is_a($refund, 'WP_Error')) {
			Nuvei_Logger::write($refund, 'create_refund_record() - the Refund process in WC returns error: ');
			
			echo wp_json_encode(array('create_refund_record() - the Refund process in WC returns error.'));
			exit;
		}
		
		$this->save_refund_meta_data(
			Nuvei_Http::get_param('TransactionID'),
			$ref_amount,
			'approved',
			$refund->get_id()
		);

		return true;
	}
	
	private function sum_order_refunds() {
		$refunds = json_decode($this->sc_order->get_meta(NUVEI_REFUNDS), true);
		$sum     = 0;
		
		if (!empty($refunds[Nuvei_Http::get_param('TransactionID', 'int')])) {
			Nuvei_Logger::write($refunds, 'Order Refunds');
			
			foreach ($refunds as $data) {
				if ('approved' == $data['status']) {
					$sum += $data['refund_amount'];
				}
			}
		}
		
		Nuvei_Logger::write($sum, 'Sum of refunds for an Order.');
		return round($sum, 2);
	}
    
    private function check_for_repeating_dmn()
    {
        if($this->sc_order->get_meta(NUVEI_TRANS_ID) == Nuvei_Http::get_param('TransactionID', 'int')
            && $this->sc_order->get_meta(NUVEI_TRANS_STATUS) == Nuvei_Http::get_request_status()
        ) {
            Nuvei_Logger::write('Repating DMN message detected. Stop the process.');
			exit(wp_json_encode('This DMN is already received.'));
        }
        
        return;
    }
	
}
