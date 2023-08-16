<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class to work the DMNs.
 */
class Nuvei_Notify_Url extends Nuvei_Request
{
	public function __construct($plugin_settings)
    {
		$this->plugin_settings = $plugin_settings;
        
        parent::__construct($this->plugin_settings);
	}
	
	public function process()
    {
		Nuvei_Logger::write($_REQUEST, 'DMN params');
		
		# stop DMNs only on test mode
//        Nuvei_Logger::write(
//            get_site_url() . '/?' . http_build_query($_REQUEST),
//            'DMN was stopped, please run it manually from the URL bleow:'
//        );
//        exit(wp_json_encode('DMN was stopped, please run it manually!'));
        # /stop DMNs only on test mode
		
        if ('CARD_TOKENIZATION' == Nuvei_Http::get_param('type')) {
			exit(wp_json_encode('Tokenization DMN, waiting for the next one.'));
        }
        
        // just give few seconds to WC to finish the process, who generated the DMN
        sleep(3);
        
        if (!$this->validate_checksum()) {
			echo wp_json_encode('DMN Error - Checksum validation problem!');
			exit;
		}
        
		// santitized get variables
		$clientUniqueId         = $this->get_cuid();
		$merchant_unique_id     = Nuvei_Http::get_param('merchant_unique_id', 'int', false);
		$transactionType        = Nuvei_Http::get_param('transactionType');
		$order_id               = Nuvei_Http::get_param('order_id', 'int');
		$TransactionID          = Nuvei_Http::get_param('TransactionID', 'int', false);
		$relatedTransactionId   = Nuvei_Http::get_param('relatedTransactionId', 'int');
		$dmnType                = Nuvei_Http::get_param('dmnType');
		$client_request_id      = Nuvei_Http::get_param('clientRequestId');
		$req_status             = Nuvei_Http::get_request_status();
		
        if ('pending' == strtolower($req_status)) {
            $msg = 'Pending DMN, waiting for the next.';
            Nuvei_Logger::write($msg);
			exit(wp_json_encode($msg));
        }
        
		# Subscription State DMN
		if ('subscription' == $dmnType) {
			$subscriptionState = strtolower(Nuvei_Http::get_param('subscriptionState'));
			$subscriptionId    = Nuvei_Http::get_param('subscriptionId', 'int');
			$planId            = Nuvei_Http::get_param('planId', 'int');
			$cri_parts         = explode('_', $client_request_id);
			
			if (empty($cri_parts) || empty($cri_parts[0]) || !is_numeric($cri_parts[0])) {
				Nuvei_Logger::write($cri_parts, 'DMN Subscription Error with Client Request Id parts:');
				echo wp_json_encode('DMN Subscription Error with Client Request Id parts.');
				exit;
			}
            
            $subs_data_key = str_replace($cri_parts[0], '', $client_request_id);
            
			// this is just to give WC time to update its metadata, before we update it here
            sleep(5);
            
			$this->is_order_valid((int) $cri_parts[0]);
            
            $subsc_data = $this->sc_order->get_meta($subs_data_key);
            Nuvei_Logger::write([$subs_data_key, $subsc_data], '$subs_data_key $subsc_data');
            
            if (empty($subsc_data)) {
                $subsc_data = [];
            }
			
			if (!empty($subscriptionState)) {
				if ('active' == $subscriptionState) {
					$msg = __('<b>Subscription is Active</b>.', 'nuvei_checkout_woocommerce') . '<br/>'
						. __('<b>Subscription ID:</b> ', 'nuvei_checkout_woocommerce') . $subscriptionId . '<br/>'
						. __('<b>Plan ID:</b> ', 'nuvei_checkout_woocommerce') . Nuvei_Http::get_param('planId', 'int');
				}
                elseif ('inactive' == $subscriptionState) {
					$msg = __('<b>Subscription is Inactive</b>.', 'nuvei_checkout_woocommerce') . '<br/>' 
						. __('<b>Subscription ID:</b> ', 'nuvei_checkout_woocommerce') . $subscriptionId . '<br/>' 
						. __('<b>Plan ID:</b> ', 'nuvei_checkout_woocommerce') . $planId;
				}
                elseif ('canceled' == $subscriptionState) {
					$msg = __('<b>Subscription</b> was canceled.', 'nuvei_checkout_woocommerce') . '<br/>'
						. __('<b>Subscription ID:</b> ', 'nuvei_checkout_woocommerce') . $subscriptionId;
				}
                
                // update subscr meta
                $subsc_data['state']        = $subscriptionState;
                $subsc_data['subscr_id']    = $subscriptionId;
                
                $this->sc_order->update_meta_data($subs_data_key, $subsc_data);
				$this->sc_order->add_order_note($msg);
				$this->sc_order->save();
			}

			echo wp_json_encode('DMN received.');
			exit;
		}
		# Subscription State DMN END
		
		# Subscription Payment DMN
		if ('subscriptionPayment' == $dmnType && 0 != $TransactionID) {
			$cri_parts      = explode('_', $client_request_id);
            $subscriptionId = Nuvei_Http::get_param('subscriptionId', 'int');
			$planId         = Nuvei_Http::get_param('planId', 'int');
            $total          = Nuvei_Http::get_param('totalAmount', 'float');
			
			if (empty($cri_parts) || empty($cri_parts[0]) || !is_numeric($cri_parts[0])) {
				Nuvei_Logger::write($cri_parts, 'DMN Subscription Payment Error with Client Request Id parts:');
				echo wp_json_encode('DMN Subscription Payment Error with Client Request Id parts.');
				exit;
			}
			
			$this->is_order_valid((int) $cri_parts[0]);
            
            $ord_curr       = $this->sc_order->get_currency();
            $subs_data_key  = str_replace($cri_parts[0], '', $client_request_id);
			
			$msg = sprintf(
				/* translators: %s: the status of the Payment */
				__('<b>Subscription Payment</b> with Status %s was made.', 'nuvei_checkout_woocommerce'),
				$req_status
			)
				. '<br/>' . __('<b>Plan ID:</b> ', 'nuvei_checkout_woocommerce') . $planId . '.'
				. '<br/>' . __('<b>Subscription ID:</b> ', 'nuvei_checkout_woocommerce') . $subscriptionId . '.'
				. '<br/>' . __('<b>Amount:</b> ', 'nuvei_checkout_woocommerce') . $ord_curr . ' ' . $total . '.'
				. '<br/>' . __('<b>TransactionId:</b> ', 'nuvei_checkout_woocommerce') . $TransactionID;

			Nuvei_Logger::write($msg, 'Subscription DMN Payment');
			
            $subsc_data = $this->sc_order->get_meta($subs_data_key);
            
            $subsc_data['payments'][] = [
                'amount'            => $total,
                'order_currency'    => $ord_curr,
                'transaction_id'    => $TransactionID,
                'resp_time'         => Nuvei_Http::get_param('responseTimeStamp'),
            ];
            
            $this->sc_order->update_meta_data($subs_data_key, $subsc_data);
            $this->sc_order->add_order_note($msg);
            $this->sc_order->save();
            
			echo wp_json_encode('DMN received.');
			exit;
		}
		# Subscription Payment DMN END
		
		# Sale and Auth
		if (in_array($transactionType, array('Sale', 'Auth'), true)) {
            if($merchant_unique_id) { // Cashier
                Nuvei_Logger::write('Cashier Order');
                $order_id = $merchant_unique_id;
            }
            elseif ('renewal_order' == Nuvei_Http::get_param('customField4')
                && !empty($client_request_id)
            ) { // WCS renewal order
                $order_id = current(explode('_', $client_request_id));
            }
            elseif($TransactionID) { // SDK
                Nuvei_Logger::write('SDK Order');
                $order_id = $this->get_order_by_trans_id($TransactionID, $transactionType);
            }
            
			$this->is_order_valid($order_id);
            $this->check_for_repeating_dmn();
			$this->save_update_order_numbers();
			$this->save_transaction_data();
			
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
			
            $msg = 'DMN process end for Order #' . $order_id;
            Nuvei_Logger::write($msg);
            
            http_response_code(200);
			exit(wp_json_encode($msg));
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
			$this->is_order_valid(0 < $order_id ? $order_id : $clientUniqueId);
            $this->check_for_repeating_dmn();
			
			if ('Settle' == $transactionType) {
				$this->save_update_order_numbers();
			}

			$this->change_order_status($order_id, $req_status, $transactionType);
            $this->save_transaction_data();
			$this->subscription_start($transactionType, $clientUniqueId);
            $this->subscription_cancel($transactionType, $order_id, $req_status);
			
			echo wp_json_encode('DMN received.');
			exit;
		}
		
		# Refund
		if (in_array($transactionType, array('Credit', 'Refund'), true)) {
			if (0 == $order_id) {
				$order_id = $this->get_order_by_trans_id($relatedTransactionId, $transactionType);
			}
            
            $this->is_order_valid($order_id);
            /**
             * TODO
             * check_for_repeating_dmn will start workin after we start to
             * use the new structure for the transactions data.
             * Then check_for_repeating_dmn can be called at the beginning of this method.
             */
            //$this->check_for_repeating_dmn();
			$this->create_refund_record($order_id);
			$this->change_order_status($order_id, $req_status, $transactionType);
            $this->save_transaction_data();

			echo wp_json_encode('DMN process end for Order #' . $order_id);
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

    /**
     * @param string $method Optional parameter used for Auto-Void
     * @return array
     */
	protected function get_checksum_params($method = '')
    {
        if ('voidTransaction' == $method) {
            return array('merchantId', 'merchantSiteId', 'clientRequestId', 'clientUniqueId', 'amount', 'currency', 'relatedTransactionId', 'url', 'timeStamp');
        }
        
        return [];
    }
	
	/**
	 * Get client unique id.
	 * We change it only for Sandbox (test) mode.
	 * 
	 * @return int|string
	 */
	private function get_cuid()
    {
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
	private function validate_checksum()
    {
		$advanceResponseChecksum = Nuvei_Http::get_param('advanceResponseChecksum');
		$responsechecksum        = Nuvei_Http::get_param('responsechecksum');
		
		if (empty($advanceResponseChecksum) && empty($responsechecksum)) {
			Nuvei_Logger::write(null, 'advanceResponseChecksum and responsechecksum parameters are empty.', 'CRITICAL');
			return false;
		}
        
        $merchant_secret = trim($this->plugin_settings['secret']);
		
		// advanceResponseChecksum case
		if (!empty($advanceResponseChecksum)) {
			$concat = $merchant_secret 
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
            /** @deprecated
             * TODO - must be removed in near future.
             * Be new notify URL is provided to Integration/TechSupport Team
             */
            'stop_dmn'          => '',
			'responsechecksum'  => '',
		);
		
		// remove parameters not part of the checksum
		$dmn_params = array_diff_key($request_arr, $custom_params);
		$concat     = implode('', $dmn_params);
		
		$concat_final = $concat . $merchant_secret;
		$checksum     = hash($this->plugin_settings['hash_type'], $concat_final);
		
		if ($responsechecksum !== $checksum) {
            $log_data = [];
            
            if('yes' == $this->plugin_settings['test']) {
                $log_data['string concat']  = $concat;
                $log_data['hash']           = $this->plugin_settings['hash_type'];
                $log_data['checksum']       = $checksum;
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
	private function get_order_by_trans_id($trans_id, $transactionType = '')
    {
        Nuvei_Logger::write([$trans_id, $transactionType], 'get_order_by_trans_id');
        
		// try to get Order ID by its meta key
		$tries		= 0;
		$max_tries  = 4;
        
		do {
			$tries++;

			$res = $this->get_order_data($trans_id);

			if (empty($res[0]->post_id)) {
				sleep(3);
			}
		} while ($tries <= $max_tries && empty($res[0]->post_id));

		if (empty($res[0]->post_id)) {
            // for Auth and Sale implement Auto-Void if more than 30 minutes passed and still no Order
            $this->create_auto_void($transactionType);
            
			Nuvei_Logger::write(
				array(
					'trans_id' => $trans_id,
					'order data' => $res
				),
				'The searched Order does not exists.'
			);
			
			http_response_code(400);
			exit(wp_json_encode('The searched Order does not exists.'));
		}

		return $res[0]->post_id;
	}
    
    /**
     * A help function just to move some of the code.
     * 
     * @param string $transactionType
     * @return void
     */
    private function create_auto_void($transactionType)
    {
        $order_request_time	= Nuvei_Http::get_param('customField3', 'int'); // time of create/update order
        $curr_time          = time();
        
        Nuvei_Logger::write(
            [
                $order_request_time,
                $transactionType,
                $curr_time
            ],
            'create_auto_void'
        );
        
        // not allowed Auto-Void
        if (0 == $order_request_time || !$order_request_time) {
            Nuvei_Logger::write(
                null,
                'There is problem with $order_request_time. End process.',
                'WARINING'
            );
            return;
        }
        
        if (!in_array($transactionType, array('Auth', 'Sale'), true)) {
            Nuvei_Logger::write('The transacion is not in allowed range.');
            return;
        }
        
        if ($curr_time - $order_request_time <= 1800) {
            Nuvei_Logger::write("Let's wait one more DMN try.");
            return;
        }
        // /not allowed Auto-Void
        
        $notify_url     = Nuvei_String::get_notify_url($this->plugin_settings);
        $void_params    = [
            'clientUniqueId'        => gmdate('YmdHis') . '_' . uniqid(),
            'amount'                => (string) Nuvei_Http::get_param('totalAmount', 'float'),
            'currency'              => Nuvei_Http::get_param('currency'),
            'relatedTransactionId'  => Nuvei_Http::get_param('TransactionID', 'int'),
            'url'                   => $notify_url,
            'urlDetails'            => ['notificationUrl' => $notify_url],
            'customData'            => 'This is Auto-Void transaction',
        ];

//        Nuvei_Logger::write(
//            [$this->request_base_params, $void_params],
//            'Try to Void a transaction by not existing WC Order.'
//        );

        $resp = $this->call_rest_api('voidTransaction', $void_params);

        // Void Success
        if (!empty($resp['transactionStatus'])
            && 'APPROVED' == $resp['transactionStatus']
            && !empty($resp['transactionId'])
        ) {
            http_response_code(200);
            exit(wp_json_encode('The searched Order does not exists, a Void request was made for this Transacrion.'));
        }
        
        return;
    }
	
	/**
	 * Just a repeating code.
	 * 
	 * @global type $wpdb
	 * @param int $TransactionID
	 * @return type
	 */
	private function get_order_data( $TransactionID)
    {
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
     * 
     * @deprecated since version 1.4.5
	 */
	private function save_update_order_numbers()
    {
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
        
        $is_wc_subscr   = $this->sc_order->get_meta(NUVEI_WC_SUBSCR);
        $upo_id         = Nuvei_Http::get_param('userPaymentOptionId', 'int');
        if ($is_wc_subscr && !empty($upo_id)) {
            $this->sc_order->update_meta_data(NUVEI_UPO, $upo_id);
        }
        
        if ('renewal_order' == Nuvei_Http::get_param('customField4')) {
            $this->sc_order->update_meta_data(NUVEI_WC_RENEWAL, true);
        }
		
		$this->sc_order->save();
	}
    
    /**
     * Save main transaction data into a block as private meta field.
     * 
     * @return void
     */
    private function save_transaction_data()
    {
        Nuvei_Logger::write('save_transaction_data()');
        
        $transaction_id = Nuvei_Http::get_param('TransactionID', 'int');
        
        if (empty($transaction_id)) {
            Nuvei_Logger::write(null, 'TransactionID param is empty!', 'CRITICAL');
            return;
        }
        
        // get previous data if exists
        $transactions_data = $this->sc_order->get_meta(NUVEI_TRANSACTIONS);
        // in case it is empty
        if (empty($transactions_data) || !is_array($transactions_data)) {
            $transactions_data = [];
        }
        
        $transactionType    = Nuvei_Http::get_param('transactionType');
        $status             = Nuvei_Http::get_request_status();
        
        // check for already existing data
        if (!empty($transactions_data[$transaction_id])
            && $transactions_data[$transaction_id]['transactionType'] == $transactionType
            && $transactions_data[$transaction_id]['status'] == $status
        ) {
            Nuvei_Logger::write('We have information for this transaction and will not save it again.');
            return;
        }
        
        $transactions_data[$transaction_id]  = [
            'authCode'              => Nuvei_Http::get_param('AuthCode', 'int'),
            'paymentMethod'         => Nuvei_Http::get_param('payment_method'),
            'transactionType'       => $transactionType,
            'relatedTransactionId'  => Nuvei_Http::get_param('relatedTransactionId'),
            'totalAmount'           => Nuvei_Http::get_param('totalAmount'),
            'currency'              => Nuvei_Http::get_param('currency'),
            'status'                => $status,
            'userPaymentOptionId'   => Nuvei_Http::get_param('userPaymentOptionId', 'int'),
            'wcsRenewal'            => 'renewal_order' == Nuvei_Http::get_param('customField4') ? true : false,
        ];
        
        $this->sc_order->update_meta_data(NUVEI_TRANSACTIONS, $transactions_data);
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
        Nuvei_Logger::write('Try to start subscription.');
        
        if ($this->sc_order->get_meta(NUVEI_WC_SUBSCR)) {
            Nuvei_Logger::write('WC Subscription.');
			return;
        }
        
        if (!in_array($transactionType, array('Settle', 'Sale', 'Auth'))) {
            Nuvei_Logger::write(
                ['$transactionType'  => $transactionType],
                'Can not start Subscription.'
            );
			return;
		}
        
        if('Auth' == $transactionType && 0 != (float) $order_total) {
            Nuvei_Logger::write($order_total, 'We allow Rebilling for Auth only when the Order total is 0.');
            return;
        }
        
        // The meta key for the Subscription is dynamic.
        $order_all_meta = get_post_meta($order_id);
        
        if (!is_array($order_all_meta) || empty($order_all_meta)) {
            Nuvei_Logger::write('Order meta is not array or is empty.');
            return;
        }
        
        foreach ($order_all_meta as $key => $data) {
            if (false === strpos($key, NUVEI_ORDER_SUBSCR)) {
                continue;
            }
            
            if (empty($data) || !is_array($data)) {
                Nuvei_Logger::write($data, 'There is a problem with the DMN Product Payment Plan data:');
                continue;
            }
            
            $subs_data = $this->sc_order->get_meta($key);
            
            Nuvei_Logger::write([$key, $subs_data]);
            
            $subs_data['clientRequestId']   = $order_id . $key;
            
            $ns_obj = new Nuvei_Subscription($this->plugin_settings);
            $resp   = $ns_obj->process($subs_data);

            // On Error
            if (!$resp || !is_array($resp) || empty($resp['status']) || 'SUCCESS' != $resp['status']) {
                $msg = __('<b>Error</b> when try to start a Subscription by the Order.', 'nuvei_checkout_woocommerce');

                if (!empty($resp['reason'])) {
                    $msg .= '<br/>' . __('Reason: ', 'nuvei_checkout_woocommerce') . $resp['reason'];
                }
            }
            // On Success
            else {
                $msg = __('Subscription was created. ') . '<br/>'
                    . __('Subscription ID: ', 'nuvei_checkout_woocommerce') . $resp['subscriptionId'] . '.<br/>' 
                    . __('Recurring amount: ', 'nuvei_checkout_woocommerce') . $this->sc_order->get_currency() . ' '
                    . $subs_data['recurringAmount'];
            }
            
            $this->sc_order->add_order_note($msg);
            $this->sc_order->save();
        }
        
		return;
	}
    
    /**
     * @param int       $transactionType
     * @param int       $order_id
     * @param string    $req_status The status of the transaction.
     */
    private function subscription_cancel($transactionType, $order_id, $req_status)
    {
        if ('Void' != $transactionType) {
            Nuvei_Logger::write($transactionType, 'Only Void can cancel a subscription.');
			return;
		}
        
        if ('approved' != strtolower($req_status)) {
            Nuvei_Logger::write($transactionType, 'The void was not approved.');
			return;
        }
        
        $order_all_meta = get_post_meta($order_id);
        
        foreach ($order_all_meta as $key => $data) {
            if (false === strpos($key, NUVEI_ORDER_SUBSCR)) {
                continue;
            }
            
            $subs_data = $this->sc_order->get_meta($key);
            Nuvei_Logger::write([$key, $subs_data]);
            
            if (empty($subs_data['state']) || 'active' != $subs_data['state']) {
                Nuvei_Logger::write($subs_data, 'The subscription is not Active.');
                continue;
            }
            
            $ncs_obj = new Nuvei_Subscription_Cancel($this->plugin_settings);
            $ncs_obj->process(['subscriptionId' => $subs_data['subscr_id']]);
        }
    }
	
	/**
	 * Change the status of the order.
	 *
	 * @param int    $order_id The Order Id.
	 * @param string $req_status The Status of the request.
	 * @param string $transaction_type The type of the transaction.
	 */
	private function change_order_status( $order_id, $req_status, $transaction_type )
    {
		Nuvei_Logger::write(
			'Order ' . $order_id . ' was ' . $req_status,
			'Nuvei change_order_status()'
		);
        
        $msg_transaction = '<b>' . __( Nuvei_Http::get_param( 'transactionType' ), 'nuvei_checkout_woocommerce' )
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
                $order_amount = round(floatval($this->sc_order->get_total()), 2);
                
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
					
//					if (round($this->sc_order->get_total(), 2) <= $this->sum_order_refunds()) {
					if ($order_amount <= $this->sum_order_refunds()) {
						$status = 'refunded';
					}
				} elseif ( 'Auth' === $transaction_type ) {
					$message = $gw_data;
					$status  = 'pending';
                    
                    if (0 == $order_amount) {
                        $status  = 'completed';
                    }
				} elseif ( in_array( $transaction_type, array( 'Settle', 'Sale' ), true ) ) {
					$message = $gw_data;
					$status  = 'completed';
					
					$this->sc_order->payment_complete($order_id);
				}
				
				// check for correct amount and currency
				if (in_array($transaction_type, array('Auth', 'Sale'), true)) {
					
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
                $message    = Nuvei_Http::get_param( 'message' );
                $ErrCode    = Nuvei_Http::get_param( 'ErrCode' );
                $Reason     = Nuvei_Http::get_param( 'Reason' );
                
                if (empty($Reason)) {
                    $Reason = Nuvei_Http::get_param( 'reason' );
                }
				
				$message = $gw_data . '<br/>'
                    . (!empty($ErrCode) ? __( 'Error code: ', 'nuvei_checkout_woocommerce' ) . $ErrCode . '<br/>' : '')
					. (!empty($Reason) ? __( 'Reason: ', 'nuvei_checkout_woocommerce' ) . $Reason . '<br/>' : '')
					. (!empty($message) ? __( 'Message: ', 'nuvei_checkout_woocommerce' ) . $message : '');
                        
				if (in_array($transaction_type, array('Auth', 'Settle', 'Sale'))) {
					$status = 'failed';
				}
                if ('Void' == $transaction_type) {
                    $status = $this->sc_order->get_meta(NUVEI_PREV_TRANS_STATUS);
                }
                if ('Refund' == $transaction_type) {
                    $status = 'completed';
                }
				
				$this->msg['class'] = 'woocommerce_message';
				break;

			case 'PENDING':
				if ( 'processing' === $status || 'completed' === $status ) {
					break;
				}

				$message            = $gw_data;
				$this->msg['class'] = 'woocommerce_message woocommerce_message_info';
				$status             = 'on-hold';
				break;
		}
		
		if (!empty($message)) {
			$this->msg['message'] = $message;
			$this->sc_order->add_order_note( $this->msg['message'] );
		}

		$this->sc_order->update_status($status);
		$this->sc_order->save();
		
		Nuvei_Logger::write($status, 'Status of Order #' . $order_id . ' was set to');
	}
	
	/**
	 * Create a Refund in WC.
	 * 
	 * @param int $order_id
	 * @return int the order id
	 */
	private function create_refund_record( $order_id)
    {
		$refunds	= array();
		$ref_amount = 0;
		$tries		= 0;
		$ref_tr_id	= Nuvei_Http::get_param('TransactionID', 'int');
        $isCpRefund = strpos(Nuvei_Http::get_param('clientRequestId'), 'gwp_') !== false ? true : false;
        
        if ($isCpRefund) {
            $tries = 5;
        }
		
		// there is chance of slow saving of meta data (in create_refund_record()), so let's wait
        // in case of CPanel Refund the $refunds meta will be empty
		do {
            Nuvei_Logger::write('Check for Refund meta data.');
            
//            $this->is_order_valid($order_id);
		
            if ( !in_array($this->sc_order->get_status(), array('completed', 'processing')) ) {
                Nuvei_Logger::write(
                    $this->sc_order->get_status(),
                    'DMN Refund Error - the Order status does not allow refunds, the status is:'
                );

                exit(wp_json_encode(array('DMN Refund Error - the Order status does not allow refunds.')));
            }
            
			$refunds = json_decode($this->sc_order->get_meta(NUVEI_REFUNDS), true);
			
			sleep(3);
			$tries++;
		} while (empty($refunds[$ref_tr_id]) && $tries < 5);
		
		Nuvei_Logger::write($refunds, 'Saved refunds for Order #' . $order_id);
		
		// check for DMN trans ID in the refunds
		if (!empty($refunds[$ref_tr_id])
			&& 'pending' == $refunds[$ref_tr_id]['status']
			&& !empty($refunds[$ref_tr_id]['refund_amount'])
		) {
			$ref_amount = $refunds[$ref_tr_id]['refund_amount'];
		}
        elseif (0 == $ref_amount && $isCpRefund) {
			// in case of CPanel refund - add Refund meta data here
			$ref_amount = Nuvei_Http::get_param('totalAmount', 'float');
		}
		
		if (0 == $ref_amount) {
			Nuvei_Logger::write('Refund Amount is 0, do not create Refund in WooCommerce.');
			return;
		}
		
		$refund = wc_create_refund(array(
			'amount'	=> round(floatval($ref_amount), 2),
			'order_id'	=> $order_id,
		));
		
		if (is_a($refund, 'WP_Error')) {
			Nuvei_Logger::write($refund, 'The Refund process in WC returns error: ');
			exit(wp_json_encode(array('The Refund process in WC returns error.')));
		}
		
		$this->save_refund_meta_data(
			Nuvei_Http::get_param('TransactionID'),
			$ref_amount,
			'approved',
			$refund->get_id()
		);

		return;
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
        Nuvei_Logger::write('check_for_repeating_dmn');
        
        if($this->sc_order->get_meta(NUVEI_TRANS_ID) == Nuvei_Http::get_param('TransactionID', 'int')
            && $this->sc_order->get_meta(NUVEI_TRANS_STATUS) == Nuvei_Http::get_request_status()
        ) {
            Nuvei_Logger::write('Repating DMN message detected. Stop the process.');
			exit(wp_json_encode('This DMN is already received.'));
        }
        
        return;
    }
	
}
