<?php

defined( 'ABSPATH' ) || exit;

/**
 * A class to work with the DMNs.
 * Here we expect outside requests. Validating with nonce is not needed, because we validate by another specific parameter.
 */
class Nuvei_Pfw_Notify_Url extends Nuvei_Pfw_Request {

	public function process() {
		Nuvei_Pfw_Logger::write(
			array(
                // not all parameters are available all the time
				'Request params'    => array(
                    'Status' => Nuvei_Pfw_Http::get_param('Status', 'string'),
                    'ErrCode' => Nuvei_Pfw_Http::get_param('ErrCode', 'int'),
                    'Reason' => Nuvei_Pfw_Http::get_param('Reason', 'string'),
                    'dmnType' => Nuvei_Pfw_Http::get_param('dmnType', 'string'),
                    'subscriptionId' => Nuvei_Pfw_Http::get_param('subscriptionId', 'int'),
                    'subscriptionState' => Nuvei_Pfw_Http::get_param('subscriptionState', 'string'),
                    'planId' => Nuvei_Pfw_Http::get_param('planId', 'int'),
                    'templateId' => Nuvei_Pfw_Http::get_param('templateId', 'int'),
                    'productId' => Nuvei_Pfw_Http::get_param('productId', 'int'),
                    'productName' => Nuvei_Pfw_Http::get_param('productName', 'string'),
                    'userPaymentOptionId' => Nuvei_Pfw_Http::get_param('userPaymentOptionId', 'int'),
                    'PPP_TransactionID' => Nuvei_Pfw_Http::get_param('PPP_TransactionID', 'int'),
                    'merchant_unique_id' => Nuvei_Pfw_Http::get_param('merchant_unique_id', 'string'),
                    'email' => Nuvei_Pfw_Http::get_param('email', 'email'),
                    'currency' => Nuvei_Pfw_Http::get_param('currency', 'string'),
                    'clientUniqueId' => Nuvei_Pfw_Http::get_param('clientUniqueId', 'string'),
                    'clientRequestId' => Nuvei_Pfw_Http::get_param('clientRequestId', 'string'),
                    'customField1' => Nuvei_Pfw_Http::get_param('customField1', 'string'),
                    'customField2' => Nuvei_Pfw_Http::get_param('customField2', 'string'),
                    'customField3' => Nuvei_Pfw_Http::get_param('customField3', 'string'),
                    'payment_method' => Nuvei_Pfw_Http::get_param('payment_method', 'string'),
                    'webMasterId' => Nuvei_Pfw_Http::get_param('webMasterId', 'string'),
                    'transactionType' => Nuvei_Pfw_Http::get_param('transactionType', 'string'),
                    'user_token_id' => Nuvei_Pfw_Http::get_param('user_token_id', 'email'),
                    'userPaymentOptionId' => Nuvei_Pfw_Http::get_param('userPaymentOptionId', 'int'),
                    'TransactionID' => Nuvei_Pfw_Http::get_param('TransactionID', 'int'),
                    'totalAmount' => Nuvei_Pfw_Http::get_param('totalAmount', 'float'),
                ),
				'REMOTE_ADDR'       => isset($_SERVER['REMOTE_ADDR']) 
                    ? filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) : '',
				'REMOTE_PORT'       => isset($_SERVER['REMOTE_PORT']) ? (int) $_SERVER['REMOTE_PORT'] : '',
				'REQUEST_METHOD'    => isset($_SERVER['REQUEST_METHOD']) 
                    ? sanitize_text_field($_SERVER['REQUEST_METHOD']) : '',
				'HTTP_USER_AGENT'   => isset($_SERVER['HTTP_USER_AGENT'])
                    ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
			),
			'DMN params'
		);

		# stop DMNs only on test mode
//		        exit(wp_json_encode('DMN was stopped, please run it manually!'));

        // Exit - do not process CARD_TOKENIZATION DMNs
		if ( 'CARD_TOKENIZATION' == Nuvei_Pfw_Http::get_param( 'type' ) ) {
			$msg = 'Tokenization DMN, waiting for the next one.';

			Nuvei_Pfw_Logger::write( $msg );
			exit( esc_html( $msg ) );
		}

		$req_status = Nuvei_Pfw_Http::get_request_status();

        // Exit - do not process PENDING DMNs
		if ( 'pending' == strtolower( $req_status ) ) {
			$msg = 'Pending DMN, waiting for the next.';

			Nuvei_Pfw_Logger::write( $msg );
			exit( wp_json_encode( $msg ) );
		}

		// just give few seconds to WC to finish its Order
		sleep( 3 );

		if ( ! $this->validate_checksum() ) {
			$msg = 'DMN Error - Checksum validation problem!';

			Nuvei_Pfw_Logger::write( $msg );
			exit( esc_html( $msg ) );
		}

		// santitized get variables
		$client_unique_id       = Nuvei_Pfw_Http::get_param( 'clientUniqueId' );
		$merchant_unique_id     = Nuvei_Pfw_Http::get_param( 'merchant_unique_id', 'int', false );
		$transaction_type       = Nuvei_Pfw_Http::get_param( 'transactionType' );
		$order_id               = Nuvei_Pfw_Http::get_param( 'order_id', 'int' );
		$transaction_id         = Nuvei_Pfw_Http::get_param( 'TransactionID', 'int', false );
		$related_tr_id          = Nuvei_Pfw_Http::get_param( 'relatedTransactionId', 'int' );
		$dmn_type               = Nuvei_Pfw_Http::get_param( 'dmnType' );
		$client_request_id      = Nuvei_Pfw_Http::get_param( 'clientRequestId' );
		$total                  = Nuvei_Pfw_Http::get_param( 'totalAmount', 'float' );

		// Subscription State DMN. We save Order here and exit.
		if ( 'subscription' == $dmn_type ) {
			$this->process_subscription_dmn( $client_request_id );
		}

		// Subscription Payment DMN. We save Order here and exit.
		if ( 'subscriptionPayment' == $dmn_type && 0 != $transaction_id ) {
			$this->process_subscription_payment_dmn( $client_request_id, $req_status, $transaction_id );
		}

		# Sale and Auth
		if ( in_array( $transaction_type, array( 'Sale', 'Auth' ), true ) ) {
			$this->process_auth_sale_dmn( $transaction_type, $client_request_id, $transaction_id, $req_status );
		}

		// try to get the Order ID
		$ord_data = $this->get_order_data( $related_tr_id );

		if ( ! empty( $ord_data[0]->post_id ) ) {
			$order_id = $ord_data[0]->post_id;
		}

		# Void, Settle
		if ( '' != $client_unique_id
			&& ( in_array( $transaction_type, array( 'Void', 'Settle' ), true ) )
		) {
			$this->process_settle_void_dmn( $order_id, $req_status, $transaction_type, $client_unique_id );
		}

		# Refund
		if ( in_array( $transaction_type, array( 'Credit', 'Refund' ), true ) ) {
			$this->process_refund_dmn( $related_tr_id, $transaction_type, $req_status );
		}

		Nuvei_Pfw_Logger::write(
			array(
				'TransactionID'         => $transaction_id,
				'relatedTransactionId'  => $related_tr_id,
			),
			'DMN was not recognized.'
		);

		exit( 'DMN was not recognized.' );
	}

	/**
	 * @param string $method Optional parameter used for Auto-Void
	 * @return array
	 */
	protected function get_checksum_params( $method = '' ) {
		if ( 'voidTransaction' == $method ) {
			return array( 'merchantId', 'merchantSiteId', 'clientRequestId', 'clientUniqueId', 'amount', 'currency', 'relatedTransactionId', 'url', 'timeStamp' );
		}

		return array();
	}

	/**
	 * Validate advanceResponseChecksum and/or responsechecksum parameters.
	 *
	 * @return boolean
	 */
	private function validate_checksum() {
		$adv_resp_checksum  = Nuvei_Pfw_Http::get_param( 'advanceResponseChecksum' );
		$responsechecksum   = Nuvei_Pfw_Http::get_param( 'responsechecksum' );

		if ( empty( $adv_resp_checksum ) && empty( $responsechecksum ) ) {
			Nuvei_Pfw_Logger::write(
                null, 
                'advanceResponseChecksum and responsechecksum parameters are empty.', 
                'CRITICAL'
            );
			return false;
		}

		$merchant_secret = trim( $this->nuvei_gw->get_option( 'secret' ) );

		// advanceResponseChecksum case
		if ( ! empty( $adv_resp_checksum ) ) {
			$concat = $merchant_secret
				. Nuvei_Pfw_Http::get_param( 'totalAmount' )
				. Nuvei_Pfw_Http::get_param( 'currency' )
				. Nuvei_Pfw_Http::get_param( 'responseTimeStamp' )
				. Nuvei_Pfw_Http::get_param( 'PPP_TransactionID' )
				. Nuvei_Pfw_Http::get_request_status()
				. Nuvei_Pfw_Http::get_param( 'productId' );

			$str = hash( $this->nuvei_gw->get_option( 'hash_type' ), $concat );

			if ( strval( $str ) == $adv_resp_checksum ) {
				return true;
			}

			Nuvei_Pfw_Logger::write( null, 'advanceResponseChecksum validation fail.', 'WARN' );
			return false;
		}

		# subscription DMN with responsechecksum case
		$custom_params = array(
			'wc-api'            => '',
			'responsechecksum'  => '',

			/** @deprecated
			 * TODO - must be removed in near future.
			 * Be new notify URL is provided to Integration/TechSupport Team
			 */
			'save_logs'         => '',
			'test_mode'         => '',
			'stop_dmn'          => '',
		);

		// remove parameters not part of the checksum
        // This is the only way to validate this request. And we need all parameters.
        //phpcs:ignore
		$dmn_params = array_diff_key( $_REQUEST, $custom_params );
		$concat     = implode( '', $dmn_params );

		$concat_final = $concat . $merchant_secret;
		$checksum     = hash( $this->nuvei_gw->get_option( 'hash_type' ), $concat_final );

		if ( $responsechecksum !== $checksum ) {
			$log_data = array();

			if ( 'yes' == $this->nuvei_gw->get_option( 'test' ) ) {
				$log_data['string concat']  = $concat;
				$log_data['hash']           = $this->nuvei_gw->get_option( 'hash_type' );
				$log_data['checksum']       = $checksum;
			}

			Nuvei_Pfw_Logger::write( $log_data, 'responsechecksum validation fail.', 'WARN' );
			return false;
		}

		return true;
	}

	/**
	 * Get the Order data by DMN data.
	 *
	 * @param mixed $trans_id           Can be the transactionId or null.
	 * @param string $transaction_type
	 *
	 * @return int
	 */
	private function search_order_by_dmn_data( $trans_id, $transaction_type = '' ) {
		Nuvei_Pfw_Logger::write( array( $trans_id, $transaction_type ), 'search_order_by_dmn_data' );

		// try to get Order ID by its meta key
		$tries      = 0;
		$max_tries  = 'yes' == $this->nuvei_gw->get_option( 'test' ) ? 10 : 4;
		$wait_time  = 3;

		do {
			$tries++;

			$res = $this->get_order_data( $trans_id );

			if ( empty( $res[0]->post_id ) ) {
				sleep( $wait_time );
			}
		} while ( $tries <= $max_tries && empty( $res[0]->post_id ) );

		if ( empty( $res[0]->post_id ) ) {
			// for Auth and Sale implement Auto-Void if more than 30 minutes passed and still no Order
			$resp_code = $this->create_auto_void( $transaction_type );

			Nuvei_Pfw_Logger::write(
				array(
					'trans_id'      => $trans_id,
					'order data'    => $res,
					'$resp_code'    => $resp_code,
				),
				'The searched Order does not exists.'
			);

			http_response_code( $resp_code );
			exit( 'The searched Order does not exists.' );
		}

		return $res[0]->post_id;
	}

	/**
	 * A help function just to move some of the code.
	 *
	 * @param string $transaction_type
	 * @return int Return response code.
	 */
	private function create_auto_void( $transaction_type ) {
		$order_request_time = Nuvei_Pfw_Http::get_param( 'customField3', 'int' ); // time of create/update order
		$curr_time          = time();

		Nuvei_Pfw_Logger::write(
			array(
				'order_request_time'    => $order_request_time,
				'transactionType'       => $transaction_type,
				'curr_time'             => $curr_time,
			),
			'create_auto_void'
		);

		# break Auto-Void process
		// order time error
		if ( 0 == $order_request_time || ! $order_request_time ) {
			Nuvei_Pfw_Logger::write(
				null,
				'There is problem with $order_request_time. End process.',
				'WARINING'
			);
			return 200; // is $order_request_time is missing we can't do anything
		}

		// not allowed transaction type error
		$req_status = Nuvei_Pfw_Http::get_request_status();

		if ( ! in_array( $transaction_type, array( 'Auth', 'Sale' ), true )
			|| 'approved' != strtolower( $req_status )
		) {
			Nuvei_Pfw_Logger::write(
				array(
					'$transaction_type'  => $transaction_type,
					'Status'            => $req_status,
				),
				'The transacion is not Auth/Sale or Status is not approved.'
			);
			return 200; // not allowed type or wrong status
		}

		// it is too early for Auto-Void
		if ( $curr_time - $order_request_time <= 1800 ) {
			Nuvei_Pfw_Logger::write( "Let's wait one more DMN try." );
			return 400; // lets wait more
		}
		# /break Auto-Void process

		$nuvei_gw   = WC()->payment_gateways->payment_gateways()[ NUVEI_PFW_GATEWAY_NAME ];
		$notify_url = Nuvei_Pfw_String::get_notify_url(
			array(
				'notify_url' => $nuvei_gw->get_option( 'notify_url' ),
			)
		);

		$void_params    = array(
			'clientUniqueId'        => $this->get_client_unique_id( Nuvei_Pfw_Http::get_param( 'email' ) ),
			'amount'                => (string) Nuvei_Pfw_Http::get_param( 'totalAmount', 'float' ),
			'currency'              => Nuvei_Pfw_Http::get_param( 'currency' ),
			'relatedTransactionId'  => Nuvei_Pfw_Http::get_param( 'TransactionID', 'int' ),
			'url'                   => $notify_url,
			'urlDetails'            => array( 'notificationUrl' => $notify_url ),
			'customData'            => 'This is Auto-Void transaction',
		);

		//        Nuvei_Pfw_Logger::write(
		//            [$this->request_base_params, $void_params],
		//            'Try to Void a transaction by not existing WC Order.'
		//        );

		$resp = $this->call_rest_api( 'voidTransaction', $void_params );

		// Void Success
		if ( ! empty( $resp['transactionStatus'] )
			&& 'APPROVED' == $resp['transactionStatus']
			&& ! empty( $resp['transactionId'] )
		) {
			Nuvei_Pfw_Logger::write( 'Auto-Void request approved.' );

			http_response_code( 200 );
			exit( 'The searched Order does not exists, a Void request was made for this Transacrion.' );
		}

		Nuvei_Pfw_Logger::write( $resp, 'Problem with Auto-Void request.' );

		return 200; // the next time the request will be same, we do not expect for approve
	}

	/**
	 * Just a repeating code.
	 *
	 * @global $wpdb
	 * @param int $transaction_id
	 * @return array
	 */
	private function get_order_data( $transaction_id ) {
		global $wpdb;
        
		if ( is_null( $transaction_id ) ) {
			// old WC records
			$query = $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}postmeta "
					. 'WHERE meta_key = %s '
						. 'AND meta_value = %s ;',
				NUVEI_PFW_CLIENT_UNIQUE_ID,
				Nuvei_Pfw_Http::get_param( 'clientUniqueId' )
			);

            // phpcs:ignore
			$res = $wpdb->get_results( $query );
            
			if ( ! empty( $res ) ) {
				return $res;
			}

			// search for HPOS record
			$query = $wpdb->prepare(
				'SELECT order_id AS post_id '
				. "FROM {$wpdb->prefix}wc_orders_meta  "
					. 'WHERE meta_key = %s '
						. 'AND meta_value = %s ;',
				NUVEI_PFW_CLIENT_UNIQUE_ID,
				Nuvei_Pfw_Http::get_param( 'clientUniqueId' )
			);

            // phpcs:ignore
			$res = $wpdb->get_results( $query );
            
			if ( ! empty( $res ) ) {
				return $res;
			}

			return array();
		}

		// plugin legacy search, TODO - after few versions stop search by "_transactionId" and search only by NUVEI_PFW_TR_ID
		// search in WC legacy table
		$query = $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->prefix}postmeta "
				. "WHERE (meta_key = '_transactionId' OR meta_key = %s )"
					. 'AND meta_value = %s ;',
			NUVEI_PFW_TR_ID,
			$transaction_id
		);

        // phpcs:ignore
		$res = $wpdb->get_results( $query );

		if ( ! empty( $res ) ) {
			return $res;
		}

		// search for HPOS record
		$query = $wpdb->prepare(
			'SELECT order_id AS post_id'
			. " FROM {$wpdb->prefix}wc_orders_meta "
				. "WHERE (meta_key = '_transactionId' OR meta_key = %s )"
					. 'AND meta_value = %s ;',
			NUVEI_PFW_TR_ID,
			$transaction_id
		);

        // phpcs:ignore
		$res = $wpdb->get_results( $query );

		if ( ! empty( $res ) ) {
			return $res;
		}

		return array();
	}

	/**
	 * The start of create subscriptions logic.
	 * We call this method when we've got Settle or Sale DMNs.
	 *
	 * @param string    $transaction_type
	 * @param int       $order_id
	 * @param float     $order_total Pass the Order Total only for Auth.
	 */
	private function subscription_start( $transaction_type, $order_id, $order_total = null ) {
		Nuvei_Pfw_Logger::write( 'Try to start subscription.' );

		if ( $this->sc_order->get_meta( NUVEI_PFW_WC_SUBSCR ) ) {
			Nuvei_Pfw_Logger::write( 'WC Subscription.' );
			return;
		}

		if ( ! in_array( $transaction_type, array( 'Settle', 'Sale', 'Auth' ) ) ) {
			Nuvei_Pfw_Logger::write(
				array( '$transaction_type'  => $transaction_type ),
				'Can not start Subscription.'
			);
			return;
		}

		if ( 'Auth' == $transaction_type && 0 != (float) $order_total ) {
			Nuvei_Pfw_Logger::write( $order_total, 'We allow Rebilling for Auth only when the Order total is 0.' );
			return;
		}

		// The meta key for the Subscription is dynamic.
		$order_all_meta = $this->sc_order->get_meta_data();

		if ( ! is_array( $order_all_meta ) || empty( $order_all_meta ) ) {
			Nuvei_Pfw_Logger::write( 'Order meta is not array or is empty.' );
			return;
		}

		$all_subscr = $this->get_order_rebiling_details( $order_all_meta );

		Nuvei_Pfw_Logger::write( $all_subscr, '$order_all_meta' );

		// create subscription request for each subscription record
		foreach ( $all_subscr as $data ) {
			// this key is not for subscription
			if ( empty( $data['subs_id'] ) ) {
				Nuvei_Pfw_Logger::write( $data, 'This is not a subscription key' );
				continue;
			}

			if ( empty( $data['subs_data'] ) || ! is_array( $data['subs_data'] ) ) {
				Nuvei_Pfw_Logger::write( $data, 'There is a problem with the DMN Product Payment Plan data:' );
				continue;
			}

			$data['subs_data']['clientRequestId']   = $order_id . $data['subs_id'];

			$ns_obj = new Nuvei_Pfw_Subscription();
			$resp   = $ns_obj->process( $data['subs_data'] );

			// On Error
			if ( ! $resp || ! is_array( $resp ) || empty( $resp['status'] ) || 'SUCCESS' != $resp['status'] ) {
				$msg = '<b>'
					. sprintf(
						/* translators: %s: close bold html tag */
						__( 'Error%s when try to start a Subscription by the Order.', 'nuvei-payments-for-woocommerce' ),
						'</b>'
					);

				if ( ! empty( $resp['reason'] ) ) {
					$msg .= '<br/>' . __( 'Reason: ', 'nuvei-payments-for-woocommerce' ) . $resp['reason'];
				}
			} else { // On Success
				$msg = __( 'Subscription was created. ', 'nuvei-payments-for-woocommerce' ) . '<br/>'
					. __( 'Subscription ID: ', 'nuvei-payments-for-woocommerce' ) . $resp['subscriptionId'] . '.<br/>'
					. __( 'Recurring amount: ', 'nuvei-payments-for-woocommerce' ) . $this->sc_order->get_currency() . ' '
					. $data['subs_data']['recurringAmount'];
			}

			$this->sc_order->add_order_note( $msg );
			//            break;
		}

		return;
	}

	/**
	 * @param int       $transaction_type
	 * @param int       $order_id
	 * @param string    $req_status The status of the transaction.
	 */
	private function subscription_cancel( $transaction_type, $order_id, $req_status ) {
		if ( 'Void' != $transaction_type ) {
			Nuvei_Pfw_Logger::write( $transaction_type, 'Only Void can cancel a subscription.' );
			return;
		}

		if ( 'approved' != strtolower( $req_status ) ) {
			Nuvei_Pfw_Logger::write( $transaction_type, 'The void was not approved.' );
			return;
		}

		$order_all_meta = $this->sc_order->get_meta_data();
		$subscr_list    = $this->get_order_rebiling_details( $order_all_meta );

		foreach ( $subscr_list as $data ) {
			Nuvei_Pfw_Logger::write( $data );

			if ( empty( $data['subs_data']['state'] ) || 'active' != $data['subs_data']['state'] ) {
				Nuvei_Pfw_Logger::write( 'The subscription is not Active.' );
				continue;
			}

			$ncs_obj = new Nuvei_Pfw_Subscription_Cancel();
			$ncs_obj->process( array( 'subscriptionId' => $data['subs_data']['subscr_id'] ) );
		}
	}

	/**
	 * Change the status of the order.
	 *
	 * @param int    $order_id The Order Id.
	 * @param string $req_status The Status of the request.
	 * @param string $transaction_type The type of the transaction.
	 * @param int $refund_id The ID of the Refund into WC
	 */
	private function change_order_status( $order_id, $req_status, $transaction_type, $refund_id = null ) {
		Nuvei_Pfw_Logger::write(
			'Order ' . $order_id . ' was ' . $req_status,
			'Nuvei change_order_status()'
		);

		$dmn_amount = Nuvei_Pfw_Http::get_param( 'totalAmount', 'float' );

        // phpcs:ignore
		$msg_transaction = '<b>' . $transaction_type . ' </b> ' 
            . __( 'request', 'nuvei-payments-for-woocommerce' ) . '.<br/>';

		$gw_data = $msg_transaction
			. __( 'Response status: ', 'nuvei-payments-for-woocommerce' ) . '<b>' . $req_status . '</b>.<br/>'
			. __( 'Payment Method: ', 'nuvei-payments-for-woocommerce' ) . Nuvei_Pfw_Http::get_param( 'payment_method' ) . '.<br/>'
			. __( 'Transaction ID: ', 'nuvei-payments-for-woocommerce' ) . Nuvei_Pfw_Http::get_param( 'TransactionID', 'int' ) . '.<br/>'
			. __( 'Related Transaction ID: ', 'nuvei-payments-for-woocommerce' )
				. Nuvei_Pfw_Http::get_param( 'relatedTransactionId', 'int' ) . '.<br/>'
			. __( 'Transaction Amount: ', 'nuvei-payments-for-woocommerce' )
				. number_format( $dmn_amount, 2, '.', '' )
				. ' ' . Nuvei_Pfw_Http::get_param( 'currency' ) . '.';

		$message = '';
		$status  = $this->sc_order->get_status();

		Nuvei_Pfw_Logger::write( array( $status, $req_status, $transaction_type ), 'order status' );

		switch ( $req_status ) {
			case 'CANCELED':
				$message            = $gw_data;
				$this->msg['class'] = 'woocommerce_message';

				if ( in_array( $transaction_type, array( 'Auth', 'Settle', 'Sale' ) ) ) {
					$status = $this->nuvei_gw->get_option( 'status_fail' );
				}
				break;

			case 'APPROVED':
				$order_amount       = round( floatval( $this->sc_order->get_total() ), 2 );
				$this->msg['class'] = 'woocommerce_message';

				// Void
				if ( 'Void' === $transaction_type ) {
					$message    = $gw_data;
					$status     = $this->nuvei_gw->get_option( 'status_void' );
					break;
				}

				// Refund
				if ( in_array( $transaction_type, array( 'Credit', 'Refund' ), true ) ) {
					$message    = $gw_data;
					$status     = $this->nuvei_gw->get_option( 'status_paid' );

					// get current refund amount
					$currency_code   = $this->sc_order->get_currency();
					$currency_symbol = get_woocommerce_currency_symbol( $currency_code );
					$message        .= '<br/><b>' . __( 'Refund: ', 'nuvei-payments-for-woocommerce' ) 
                        . '<b> #' . $refund_id;

					if ( $order_amount == $this->sum_order_refunds() + $dmn_amount ) {
						$status = $this->nuvei_gw->get_option( 'status_refund' );
					}

					break;
				}

				// Auth
				if ( 'Auth' === $transaction_type ) {
					$message    = $gw_data;
					$status     = $this->nuvei_gw->get_option( 'status_auth' );

					if ( 0 == $order_amount ) {
						$status  = $this->nuvei_gw->get_option( 'status_paid' );
					}
				}

				if ( in_array( $transaction_type, array( 'Settle', 'Sale' ), true ) ) {
					$message    = $gw_data;
					$status     = $this->nuvei_gw->get_option( 'status_paid' );

					$this->sc_order->payment_complete( $order_id );

					Nuvei_Pfw_Logger::write( $status, 'Settle/Sale status' );
				}

				// check for correct amount
				if ( in_array( $transaction_type, array( 'Auth', 'Sale' ), true ) ) {
					$set_amount_warning = false;
					$set_curr_warning   = false;

					Nuvei_Pfw_Logger::write(
						array(
							'$order_amount'     => $order_amount,
							'$dmn_amount'       => $dmn_amount,
							'customField1'      => Nuvei_Pfw_Http::get_param( 'customField1' ),
							'order currency'    => $this->sc_order->get_currency(),
							'param currency'    => Nuvei_Pfw_Http::get_param( 'currency' ),
							'customField2'      => Nuvei_Pfw_Http::get_param( 'customField2' ),
						),
						'Check for fraud order.'
					);

					// check for correct amount
					if ( $order_amount != $dmn_amount
						&& Nuvei_Pfw_Http::get_param( 'customField1' ) != $order_amount
					) {
						$set_amount_warning = true;
						Nuvei_Pfw_Logger::write( 'Amount warning!' );
					}

					// check for correct currency
					if ( $this->sc_order->get_currency() !== Nuvei_Pfw_Http::get_param( 'currency' )
						&& $this->sc_order->get_currency() !== Nuvei_Pfw_Http::get_param( 'customField2' )
					) {
						$set_curr_warning = true;
						Nuvei_Pfw_Logger::write( 'Currency warning!' );
					}

					// when currency is same, check the amount again, in case of some kind partial transaction
					if ( $this->sc_order->get_currency() === Nuvei_Pfw_Http::get_param( 'currency' )
						&& $order_amount != $dmn_amount
					) {
						$set_amount_warning = true;
						Nuvei_Pfw_Logger::write( 'Amount warning when currency is same!' );
					}

					$this->sc_order->update_meta_data(
						NUVEI_PFW_ORDER_CHANGES,
						array(
							'curr_change'   => $set_curr_warning,
							'total_change'  => $set_amount_warning,
						)
					);
				}

				break;

			case 'ERROR':
			case 'DECLINED':
			case 'FAIL':
				$message    = Nuvei_Pfw_Http::get_param( 'message' );
				$err_code   = Nuvei_Pfw_Http::get_param( 'ErrCode' );
				$reason     = Nuvei_Pfw_Http::get_param( 'Reason' );

				if ( empty( $reason ) ) {
					$reason = Nuvei_Pfw_Http::get_param( 'reason' );
				}

				$message = $gw_data . '<br/>'
					. ( ! empty( $err_code ) ? __( 'Error code: ', 'nuvei-payments-for-woocommerce' ) . $err_code . '<br/>' : '' )
					. ( ! empty( $reason ) ? __( 'Reason: ', 'nuvei-payments-for-woocommerce' ) . $reason . '<br/>' : '' )
					. ( ! empty( $message ) ? __( 'Message: ', 'nuvei-payments-for-woocommerce' ) . $message : '' );

				if ( in_array( $transaction_type, array( 'Auth', 'Settle', 'Sale' ) ) ) {
					$status = $this->nuvei_gw->get_option( 'status_fail' );
				}
				if ( 'Void' == $transaction_type ) {
					$status = $this->sc_order->get_meta( NUVEI_PFW_PREV_TRANS_STATUS );
				}
				if ( 'Refund' == $transaction_type ) {
					$status = $this->nuvei_gw->get_option( 'status_paid' );
				}

				$this->msg['class'] = 'woocommerce_message';
				break;

			case 'PENDING':
				$message            = $gw_data;
				$this->msg['class'] = 'woocommerce_message woocommerce_message_info';
				break;
		}

		if ( ! empty( $message ) ) {
			$this->msg['message'] = $message;
			$this->sc_order->add_order_note( $this->msg['message'] );
		}

		$this->sc_order->update_status( $status );

		Nuvei_Pfw_Logger::write(
			array(
				'$order_id' => $order_id,
				'$status'   => $status,
			//                '$message'  => $message,
			),
			'Order Status saved.'
		);
	}

	private function sum_order_refunds() {
		$sum        = 0;
		$nuvei_data = $this->sc_order->get_meta( NUVEI_PFW_TRANSACTIONS );

		if ( empty( $nuvei_data ) || ! is_array( $nuvei_data ) ) {
			return '0.00';
		}

		foreach ( $nuvei_data as $data ) {
			if ( ! empty( $data['transactionType'] )
				&& in_array( $data['transactionType'], array( 'Credit', 'Refund' ) )
				&& ! empty( $data['status'] )
				&& strtolower( $data['status'] ) == 'approved'
				&& isset( $data['totalAmount'] )
			) {
				$sum += $data['totalAmount'];
			}
		}

		return number_format( $sum, 2, '.', '' );
	}

	private function check_for_repeating_dmn() {
		Nuvei_Pfw_Logger::write( 'check_for_repeating_dmn' );

		$order_data = $this->sc_order->get_meta( NUVEI_PFW_TRANSACTIONS );
		$dmn_tr_id  = Nuvei_Pfw_Http::get_param( 'TransactionID', 'int' );
		$dmn_status = Nuvei_Pfw_Http::get_request_status();

		if ( ! empty( $order_data[ $dmn_tr_id ] )
			&& ! empty( $order_data[ $dmn_tr_id ]['status'] )
			&& $dmn_status == $order_data[ $dmn_tr_id ]['status']
		) {
			Nuvei_Pfw_Logger::write( 'Repating DMN message detected. Stop the process.' );
			exit( 'This DMN is already received.' );
		}

		return;
	}

	/**
	 * Method to handle Subscription DMN logic.
	 *
	 * @param mixed $client_request_id
	 * @return void
	 */
	private function process_subscription_dmn( $client_request_id ) {
		$subscription_state = strtolower( Nuvei_Pfw_Http::get_param( 'subscriptionState' ) );
		$subscription_id    = Nuvei_Pfw_Http::get_param( 'subscriptionId', 'int' );
		$plan_id            = Nuvei_Pfw_Http::get_param( 'planId', 'int' );
		$cri_parts          = explode( '_', $client_request_id );

		if ( empty( $cri_parts ) || empty( $cri_parts[0] ) || ! is_numeric( $cri_parts[0] ) ) {
			Nuvei_Pfw_Logger::write( $cri_parts, 'DMN Subscription Error with Client Request Id parts:' );
			exit( 'DMN Subscription Error with Client Request Id parts.' );
		}

		$subs_data_key = str_replace( $cri_parts[0], '', $client_request_id );

		// this is just to give WC time to update its metadata, before we update it here
		sleep( 5 );

		$this->is_order_valid( (int) $cri_parts[0] );

		$subsc_data = $this->sc_order->get_meta( $subs_data_key );
		Nuvei_Pfw_Logger::write( array( $subs_data_key, $subsc_data ), '$subs_data_key $subsc_data' );

		if ( empty( $subsc_data ) ) {
			$subsc_data = array();
		}

		if ( ! empty( $subscription_state ) ) {
			if ( 'active' == $subscription_state ) {
				$msg = '<b>' . __( 'Subscription is Active', 'nuvei-payments-for-woocommerce' )
					. '</b>.<br/><b>' . __( 'Subscription ID:', 'nuvei-payments-for-woocommerce' )
					. '</b> ' . $subscription_id . '<br/><b>'
					. __( 'Plan ID:', 'nuvei-payments-for-woocommerce' ) . '</b> '
					. Nuvei_Pfw_Http::get_param( 'planId', 'int' );
			} elseif ( 'inactive' == $subscription_state ) {
				$msg = '<b>' . __( 'Subscription is Inactive', 'nuvei-payments-for-woocommerce' )
					. '</b>.<br/><b>' . __( 'Subscription ID:', 'nuvei-payments-for-woocommerce' ) . '</b> '
					. $subscription_id . '<br/><b>'
					. __( 'Plan ID:', 'nuvei-payments-for-woocommerce' ) . '</b> ' . $plan_id;
			} elseif ( 'canceled' == $subscription_state ) {
				$msg = '<b>' . sprintf(
						/* translators: %s: close bold html tag */
					__( 'Subscription%s was canceled.', 'nuvei-payments-for-woocommerce' ),
					'</b>'
				)
					. '<br/><b>' . __( 'Subscription ID:', 'nuvei-payments-for-woocommerce' ) . '</b> '
					. $subscription_id;
			}

			// update subscr meta
			$subsc_data['state']        = $subscription_state;
			$subsc_data['subscr_id']    = $subscription_id;

			$this->sc_order->update_meta_data( $subs_data_key, $subsc_data );
			$this->sc_order->add_order_note( $msg );
			$this->sc_order->save();
		}

		exit( 'DMN received.' );
	}

	/**
	 * Method to handle Subscription Payment DMN logic.
	 *
	 * @param mixed $client_request_id
	 * @param string $req_status        The DMN status parameter.
	 * @param string $transaction_id     The Transaction ID.
	 *
	 * @return void
	 */
	private function process_subscription_payment_dmn( $client_request_id, $req_status, $transaction_id ) {
		$total          = Nuvei_Pfw_Http::get_param( 'totalAmount', 'float' );
		$cri_parts      = explode( '_', $client_request_id );
		$subscription_id = Nuvei_Pfw_Http::get_param( 'subscriptionId', 'int' );
		$plan_id         = Nuvei_Pfw_Http::get_param( 'planId', 'int' );

		if ( empty( $cri_parts ) || empty( $cri_parts[0] ) || ! is_numeric( $cri_parts[0] ) ) {
			Nuvei_Pfw_Logger::write( $cri_parts, 'DMN Subscription Payment Error with Client Request Id parts:' );
			exit( 'DMN Subscription Payment Error with Client Request Id parts.' );
		}

		$this->is_order_valid( (int) $cri_parts[0] );

		$ord_curr       = $this->sc_order->get_currency();
		$subs_data_key  = str_replace( $cri_parts[0], '', $client_request_id );

		$msg = sprintf(
			/* translators: %1$s: open bold html tag */
			/* translators: %2$s: close bold html tag */
			/* translators: %3$s: the status of the Payment */
			__( '%1$sSubscription Payment%2$s with Status %3$s was made.', 'nuvei-payments-for-woocommerce' ),
			'<b>',
			'</b>',
			$req_status
		)
			. '<br/><b>' . __( 'Plan ID:', 'nuvei-payments-for-woocommerce' ) . '</b> ' . $plan_id . '.'
			. '<br/><b>' . __( 'Subscription ID:', 'nuvei-payments-for-woocommerce' ) . '</b> ' . $subscription_id . '.'
			. '<br/><b>' . __( 'Amount:', 'nuvei-payments-for-woocommerce' ) . '</b> ' . $ord_curr . ' ' . $total . '.'
			. '<br/><b>' . __( 'TransactionId:', 'nuvei-payments-for-woocommerce' ) . '</b> ' . $transaction_id;

		Nuvei_Pfw_Logger::write( $msg, 'Subscription DMN Payment' );

		$subsc_data = $this->sc_order->get_meta( $subs_data_key );

		$subsc_data['payments'][] = array(
			'amount'            => $total,
			'order_currency'    => $ord_curr,
			'transaction_id'    => $transaction_id,
			'resp_time'         => Nuvei_Pfw_Http::get_param( 'responseTimeStamp' ),
		);

		$this->sc_order->update_meta_data( $subs_data_key, $subsc_data );
		$this->sc_order->add_order_note( $msg );
		$this->sc_order->save();
        
        /* 
         * Add custom hook after Rebilling payment so other developers can use it.
         * The whole name is nuvei_payments_for_woocommerce_after_rebilling_payment.
         */
        do_action('nuvei_pfwc_after_rebilling_payment');

		exit( 'DMN received.' );
	}

	/**
	 * Method to handle Auth and Sale DMN logic.
	 *
	 * @param string $transaction_type
	 * @param mixed $client_request_id
	 * @param string $transaction_id     The Transaction ID.
	 * @param string $req_status        The DMN status parameter.
	 *
	 * @return void
	 */
	private function process_auth_sale_dmn( $transaction_type, $client_request_id, $transaction_id, $req_status ) {
		$is_sdk_order       = false;
		$merchant_unique_id = Nuvei_Pfw_Http::get_param( 'merchant_unique_id', 'int', false );

		// Cashier
		if ( $merchant_unique_id ) {
			Nuvei_Pfw_Logger::write( 'Cashier Order' );
			$order_id = $merchant_unique_id;
		} elseif ( 'renewal_order' == Nuvei_Pfw_Http::get_param( 'customField4' )
			&& ! empty( $client_request_id )
		) { // WCS renewal order
			Nuvei_Pfw_Logger::write( 'Renewal Order' );
			$order_id = current( explode( '_', $client_request_id ) );
		} elseif ( $transaction_id ) { // SDK
			Nuvei_Pfw_Logger::write( 'SDK Order' );
			$is_sdk_order   = true;
			$order_id       = $this->search_order_by_dmn_data( null, $transaction_type );
		}

		$this->is_order_valid( $order_id );

		// error check for SDK orders only
		if ( $is_sdk_order
			&& $this->sc_order->get_meta( NUVEI_PFW_ORDER_ID ) != Nuvei_Pfw_Http::get_param( 'PPP_TransactionID' )
		) {
			$msg = 'Saved Nuvei Order ID is different than the ID in the DMN.';
			Nuvei_Pfw_Logger::write( $msg );
			exit( esc_html( $msg ) );
		}

		$this->check_for_repeating_dmn();
		$this->save_transaction_data();

		$order_status   = strtolower( $this->sc_order->get_status() );
		$order_total    = round( $this->sc_order->get_total(), 2 );

		if ( 'completed' !== $order_status ) {
			$this->change_order_status(
				$order_id,
				$req_status,
				$transaction_type
			);
		}

		$this->subscription_start( $transaction_type, $order_id, $order_total );

		$this->sc_order->save();

		$msg = 'DMN process end for Order #' . $order_id;

		Nuvei_Pfw_Logger::write( $msg );
		http_response_code( 200 );
		exit( esc_html( $msg ) );
	}

	/**
	 * Method to handle Settle and Void DMN logic.
	 *
	 * @param int $order_id
	 * @param string $req_status    The DMN status parameter.
	 * @param string $transaction_id The Transaction ID.
	 * @param mixed $client_unique_id The Transaction ID.
	 *
	 * @return void
	 */
	private function process_settle_void_dmn( $order_id, $req_status, $transaction_type, $client_unique_id ) {
		$order_id = 0 < $order_id ? $order_id : $client_unique_id;

		$this->is_order_valid( $order_id );
		$this->check_for_repeating_dmn();
		$this->change_order_status( $order_id, $req_status, $transaction_type );
		$this->save_transaction_data();
		$this->subscription_start( $transaction_type, $client_unique_id );
		$this->subscription_cancel( $transaction_type, $order_id, $req_status );

		$this->sc_order->save();

		$msg = 'DMN received.';

		Nuvei_Pfw_Logger::write( $msg );
		exit( esc_html( $msg ) );
	}

	/**
	 * Method to handle Refund DMN logic.
	 *
	 * @param int $related_tr_id
	 * @param string $transaction_type
	 * @param string $req_status    The DMN status parameter.
	 *
	 * @return void
	 */
	private function process_refund_dmn( $related_tr_id, $transaction_type, $req_status ) {
		$order_id   = $this->search_order_by_dmn_data( $related_tr_id, $transaction_type );
		$total      = Nuvei_Pfw_Http::get_param( 'totalAmount', 'float' );

		Nuvei_Pfw_Logger::write( $order_id );

		$this->is_order_valid( $order_id );

		if ( 'APPROVED' == $req_status ) {
			$this->check_for_repeating_dmn();

			# create Refund in WC
			$refund = wc_create_refund(
				array(
					'amount'    => $total,
					'order_id'  => $order_id,
				)
			);

			if ( is_a( $refund, 'WP_Error' ) ) {
				http_response_code( 400 );
				Nuvei_Pfw_Logger::write( (array) $refund, 'The Refund process in WC returns error: ' );
				exit( 'The Refund process in WC returns error.' );
			}
			# /create Refund in WC

			$refund_id = $refund->get_id();

			$this->change_order_status( $order_id, $req_status, $transaction_type, $refund_id );
			$this->save_transaction_data( array(), $refund_id );
		}

		$this->sc_order->save();

		$msg = 'DMN process end for Order #' . $order_id;

		Nuvei_Pfw_Logger::write( $msg );
		exit( esc_html( $msg ) );
	}
}
