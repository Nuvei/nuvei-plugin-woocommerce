<?php

defined( 'ABSPATH' ) || exit;

/**
 * Main class for the Nuvei Plugin
 */

class Nuvei_Gateway extends WC_Payment_Gateway {

	private $plugin_data  = array();
	private $subscr_units = array('year', 'month', 'day');
	
	public function __construct() {
		# settings to get/save options
		$this->id                 = NUVEI_GATEWAY_NAME;
		$this->method_title       = __('Nuvei Checkout', 'nuvei_checkout_woocommerce' );
		$this->method_description = 'Pay with ' . NUVEI_GATEWAY_TITLE . '.';
		$this->icon               = plugin_dir_url(NUVEI_PLUGIN_FILE) . 'assets/icons/nuvei.png';
		$this->has_fields         = false;

        $this->init_form_fields();
		$this->init_settings();
		
		// required for the Store
		$this->title       = $this->get_setting('title', $this->method_title);
		$this->description = $this->get_setting('description', $this->method_description);
		$this->test        = $this->get_setting('test', '');
		$this->rewrite_dmn = $this->get_setting('rewrite_dmn', 'no');
		$this->plugin_data = get_plugin_data(plugin_dir_path(NUVEI_PLUGIN_FILE) . DIRECTORY_SEPARATOR . 'index.php');
		
		$nuvei_vars = array(
			'save_logs' => $this->get_setting('save_logs'),
			'test_mode' => $this->get_setting('test'),
		);
		
		if (!is_admin() && !empty(WC()->session)) {
			WC()->session->set('nuvei_vars', $nuvei_vars);
		}
		
		$this->use_wpml_thanks_page = !empty($this->settings['use_wpml_thanks_page']) 
			? $this->settings['use_wpml_thanks_page'] : 'no';
		
		$this->supports[] = 'refunds'; // to enable auto refund support
		
		$this->msg['message'] = '';
		$this->msg['class']   = '';
		
		// parent method to save Plugin Settings
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
		
		add_action('woocommerce_order_after_calculate_totals', array($this, 'return_settle_btn'), 10, 2);
		add_action('woocommerce_order_status_refunded', array($this, 'restock_on_refunded_status'), 10, 1);
	}
	
	/**
	 * Generate Button HTML.
	 * Custom function to generate beautiful button in admin settings.
	 * Thanks to https://gist.github.com/BFTrick/31de2d2235b924e853b0
	 *
	 * @param mixed $key
	 * @param mixed $data
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function generate_button_html( $key, $data) {
		Nuvei_Logger::write($key);
		Nuvei_Logger::write($data);
		
		$defaults = array(
			'class'             => 'button-secondary',
			'css'               => '',
			'custom_attributes' => array(),
			'desc_tip'          => false,
			'description'       => '',
			'title'             => '',
		);

		ob_start();
		
		$data = wp_parse_args($data, $defaults);
		require_once dirname(NUVEI_PLUGIN_FILE) . '/templates/admin/download_payments_plans_btn.php';
		
		return ob_get_clean();
	}

	// Generate the HTML For the settings form.
	public function admin_options() {
        ob_start();
        require_once dirname(NUVEI_PLUGIN_FILE) . '/templates/admin/settings.php';
        echo ob_get_clean();
	}

	/**
	 *  Add fields on the payment page. Because we get APMs with Ajax
	 * here we add only AMPs fields modal.
	 */
	public function payment_fields() {
		if ($this->description) {
			echo wp_kses_post(wpautop(wptexturize($this->description)));
		}
		
		// echo here some html if needed
	}

	/**
	  * Process the payment and return the result. This is the place where site
	  * submit the form and then redirect. Here we will get our custom fields.
	  *
	  * @param int $order_id
	  * @return array
	 */
	public function process_payment( $order_id) {
		Nuvei_Logger::write('Process payment(), Order #' . $order_id);
		
		$sc_nonce = Nuvei_Http::get_param('sc_nonce');
		
		if (!empty($sc_nonce)
			&& !wp_verify_nonce($sc_nonce, 'sc_checkout')
		) {
			Nuvei_Logger::write('process_payment() Error - can not verify WP Nonce.');
			
			return array(
				'result'    => 'success',
				'redirect'  => array(
					'Status'    => 'error',
				),
				wc_get_checkout_url() . 'order-received/' . $order_id . '/'
			);
		}
		
		$order = wc_get_order($order_id);
		$key   = $order->get_order_key();
		
		if (!$order) {
			Nuvei_Logger::write('Order is false for order id ' . $order_id);
			
			return array(
				'result'    => 'success',
				'redirect'  => array(
					'Status'    => 'error',
				),
				wc_get_checkout_url() . 'order-received/' . $order_id . '/'
			);
		}
		
		$return_success_url = add_query_arg(
			array('key' => $key),
			$this->get_return_url($order)
		);
		
		$return_error_url = add_query_arg(
			array(
				'Status'    => 'error',
				'key'        => $key
			),
			$this->get_return_url($order)
		);
		
		if ($order->get_payment_method() != NUVEI_GATEWAY_NAME) {
			Nuvei_Logger::write('Process payment Error - Order payment gateway is not ' . NUVEI_GATEWAY_NAME);
			
			return array(
				'result'    => 'success',
				'redirect'  => array(
					'Status'    => 'error',
					'key'        => $key
				),
				wc_get_checkout_url() . 'order-received/' . $order_id . '/'
			);
		}
		
		// when we have Approved from the SDK we complete the order here
		$sc_transaction_id = Nuvei_Http::get_param('sc_transaction_id', 'int');
		
		# in case we use Cashier
		if (1 == $this->settings['use_cashier']) {
			Nuvei_Logger::write('Process Cashier payment.');
			
			$url = $this->generate_cashier_url($return_success_url, $return_error_url, $order_id);

			if (!empty($url)) {
				return array(
					'result'    => 'success',
					'redirect'    => add_query_arg(array(), $url)
				);
			}
			
			return;
		}
		# in case we use Cashier END
		
		# in case of webSDK payment (cc_card)
		if (!empty($sc_transaction_id)) {
			Nuvei_Logger::write('Process webSDK Order, transaction ID #' . $sc_transaction_id);
			
			$order->update_meta_data(NUVEI_TRANS_ID, $sc_transaction_id);
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_success_url
			);
		}
		# in case of webSDK payment (cc_card) END
		
		Nuvei_Logger::write('Process Rest APM Order.');
		
		$np_obj = new Nuvei_Payment($this->settings);
		$resp   = $np_obj->process(array(
			'order_id'             => $order_id, 
			'return_success_url'   => $return_success_url, 
			'return_error_url'     => $return_error_url
		));
		
		if (!$resp) {
			$msg = __('There is no response for the Order.', 'nuvei_checkout_woocommerce');
			
			$order->add_order_note($msg);
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_error_url
			);
		}
		
		if (empty(Nuvei_Http::get_request_status($resp))) {
			$msg = __('There is no Status for the Order.', 'nuvei_checkout_woocommerce');
			
			$order->add_order_note($msg);
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_error_url
			);
		}
		
		# Redirect
		if (!empty($resp['redirectURL']) || !empty($resp['paymentOption']['redirectUrl'])) {
			return array(
				'result'    => 'success',
				'redirect'    => add_query_arg(
					array(),
					!empty($resp['redirectURL']) ? $resp['redirectURL'] : $resp['paymentOption']['redirectUrl']
				)
			);
		}
		
		if (empty($resp['transactionStatus'])) {
			$msg = __('There is no Transaction Status for the Order.', 'nuvei_checkout_woocommerce');
			
			$order->add_order_note($msg);
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_error_url
			);
		}
		
		if ('DECLINED' === Nuvei_Http::get_request_status($resp)
			|| 'DECLINED' === $resp['transactionStatus']
		) {
			$order->add_order_note(__('Order Declined.', 'nuvei_checkout_woocommerce'));
			$order->set_status('cancelled');
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_error_url
			);
		}
		
		if ('ERROR' === Nuvei_Http::get_request_status($resp)
			|| 'ERROR' === $resp['transactionStatus']
		) {
			$order->set_status('failed');

			$error_txt = __('Payment error', 'nuvei_checkout_woocommerce');

			if (!empty($resp['reason'])) {
				$error_txt .= ': ' . $resp['errCode'] . ' - ' . $resp['reason'] . '.';
			} elseif (!empty($resp['threeDReason'])) {
				$error_txt .= ': ' . $resp['threeDReason'] . '.';
			} elseif (!empty($resp['message'])) {
				$error_txt .= ': ' . $resp['message'] . '.';
			}
			
			$order->add_order_note($error_txt);
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_error_url
			);
		}
		
		// catch Error code or reason
		if ( ( isset($resp['gwErrorCode']) && -1 === $resp['gwErrorCode'] )
			|| isset($resp['gwErrorReason'])
		) {
			$msg = __('Error with the Payment: ', 'nuvei_checkout_woocommerce') . $resp['gwErrorReason'] . '.';

			$order->add_order_note($msg);
			$order->save();

			return array(
				'result'    => 'success',
				'redirect'  => $return_error_url
			);
		}
		
		# SUCCESS
		// If we get Transaction ID save it as meta-data
		if (isset($resp['transactionId']) && $resp['transactionId']) {
			$order->update_meta_data(NUVEI_TRANS_ID, $resp['transactionId'], 0);
		}
		
		// save the response transactionType value
		if (isset($resp['transactionType']) && '' !== $resp['transactionType']) {
			$order->update_meta_data(NUVEI_RESP_TRANS_TYPE, $resp['transactionType']);
		}

		if (isset($resp['transactionId']) && '' !== $resp['transactionId']) {
			$order->add_order_note(__('Payment succsess for Transaction Id ', 'nuvei_checkout_woocommerce') . $resp['transactionId']);
		} else {
			$order->add_order_note(__('Payment succsess.', 'nuvei_checkout_woocommerce'));
		}

		$order->save();
		
		return array(
			'result'    => 'success',
			'redirect'  => $return_success_url
		);
	}
	
	public function add_apms_step() {
		global $woocommerce;
		
		$items      = $woocommerce->cart->get_cart();
		$force_flag = false;
		
		foreach ($items as $item) {
			$cart_product   = wc_get_product( $item['product_id'] );
			$cart_prod_attr = $cart_product->get_attributes();
			
			// check for variations
			if (!empty($cart_prod_attr['pa_' . Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME)])) {
				$force_flag = true;
				break;
			}
		}
		
		ob_start();
		require_once dirname(NUVEI_PLUGIN_FILE) . '/templates/sc_second_step_form.php';
		ob_end_flush();
	}
	
	/**
	 * Function process_refund
	 * A overwrite original function to enable auto refund in WC.
	 *
	 * @param  int    $order_id Order ID.
	 * @param  float  $amount Refund amount.
	 * @param  string $reason Refund reason.
	 *
	 * @return boolean
	 */
	public function process_refund( $order_id, $amount = null, $reason = '') {
		if ('true' == Nuvei_Http::get_param('api_refund')) {
			return true;
		}
		
		return false;
	}
	
	public function return_settle_btn( $and_taxes, $order) {
		Nuvei_Logger::write('return_settle_btn()');
		
		if (!method_exists($order, 'get_payment_method')
			|| empty($order->get_payment_method())
			|| !in_array($order->get_payment_method(), array(NUVEI_GATEWAY_NAME, 'sc'))
		) {
			Nuvei_Logger::write(
				$order,
				'return_settle_btn() - this is not Nuvei Order or there is no Payment Method.'
			);
			
			return false;
		}
		
		// revert buttons on Recalculate
		if (Nuvei_Http::get_param('refund_amount', 'float', 0) == 0 && !empty(Nuvei_Http::get_param('items'))) {
			echo esc_js('<script type="text/javascript">returnSCBtns();</script>');
		}
	}

	/**
	 * Restock on refund.
	 *
	 * @param int $order_id
	 * @return void
	 */
	public function restock_on_refunded_status( $order_id) {
		$order            = wc_get_order($order_id);
		$items            = $order->get_items();
		$is_order_restock = $order->get_meta('_scIsRestock');
		
		// do restock only once
		if (1 !== $is_order_restock) {
			wc_restock_refunded_items($order, $items);
			$order->update_meta_data('_scIsRestock', 1);
			$order->save();
			
			Nuvei_Logger::write('Items were restocked.');
		}
		
		return;
	}
	
	public function delete_user_upo() {
		$upo_id = Nuvei_Http::get_param('scUpoId', 'int', false);
		
		if (!$upo_id) {
			wp_send_json(
				array(
				'status' => 'error',
				'msg' => __('Invalid UPO ID parameter.', 'nuvei_checkout_woocommerce')
				)
			);

			exit;
		}
		
		if (!is_user_logged_in()) {
			wp_send_json(
				array(
				'status' => 'error',
				'msg' => 'The user in not logged in.'
				)
			);

			exit;
		}
		
		$curr_user = wp_get_current_user();
		
		if (empty($curr_user->user_email)) {
			wp_send_json(array(
				'status' => 'error',
				'msg' => 'The user email is not valid.'
			));

			exit;
		}
		
		$ndu_obj = new Nuvei_Delete_Upo($this->settings);
		$resp    = $ndu_obj->process(array(
			'email'     => $curr_user->user_email,
			'upo_id'    => $upo_id
		));
		
		if (empty($resp['status']) || 'SUCCESS' != $resp['status']) {
			$msg = !empty($resp['reason']) ? $resp['reason'] : '';
			
			wp_send_json(array(
				'status' => 'error',
				'msg' => $msg
			));

			exit;
		}
		
		wp_send_json(array('status' => 'success'));
		exit;
	}
	
	public function reorder() {
		global $woocommerce;
		
		$products_ids = json_decode(Nuvei_Http::get_param('product_ids'), true);
		
		if (empty($products_ids) || !is_array($products_ids)) {
			wp_send_json(array(
				'status' => 0,
				'msg' => __('Problem with the Products IDs.', 'nuvei_checkout_woocommerce')
			));
			exit;
		}
		
		$prod_factory  = new WC_Product_Factory();
		$msg           = '';
		$is_prod_added = false;
		
		foreach ($products_ids as $id) {
			$product = $prod_factory->get_product($id);
		
			if ('in-stock' != $product->get_availability()['class'] ) {
				$msg = __('Some of the Products are not availavle, and are not added in the new Order.', 'nuvei_checkout_woocommerce');
				continue;
			}

			$is_prod_added = true;
			$woocommerce->cart->add_to_cart($id);
		}
		
		if (!$is_prod_added) {
			wp_send_json(array(
				'status' => 0,
				'msg' => 'There are no added Products to the Cart.',
			));
			exit;
		}
		
		$cart_url = wc_get_cart_url();
		
		if (!empty($msg)) {
			$cart_url .= strpos($cart_url, '?') !== false ? '&sc_msg=' : '?sc_msg=';
			$cart_url .= urlencode($msg);
		}
		
		wp_send_json(array(
			'status'		=> 1,
			'msg'			=> $msg,
			'redirect_url'	=> wc_get_cart_url(),
		));
		exit;
	}
	
	/**
	 * Download the Active Payment pPlans and save them to a json file.
	 * If there are no Active Plans, create default one with name, based
	 * on MerchatSiteId parameter, and get it.
	 * 
	 * @param int $recursions
	 */
	public function download_subscr_pans( $recursions = 0) {
		if ($recursions > 1) {
			wp_send_json(array('status' => 0));
			exit;
		}
		
		$ndp_obj = new Nuvei_Download_Plans($this->settings);
		$resp    = $ndp_obj->process();
		
		if (empty($resp) || !is_array($resp) || 'SUCCESS' != $resp['status']) {
			Nuvei_Logger::write('Get Plans response error.');
			
			wp_send_json(array('status' => 0));
			exit;
		}
		
		// in case there are  no active plans - create default one
		if (isset($resp['total']) && 0 == $resp['total']) {
			$ncp_obj     = new Nuvei_Create_Plan($this->settings);
			$create_resp = $ncp_obj->process();
			
			if (!empty($create_resp['planId'])) {
				$recursions++;
				$this->download_subscr_pans($recursions);
				return;
			}
		}
		// in case there are  no active plans - create default one END
		
		if (file_put_contents(plugin_dir_path(NUVEI_PLUGIN_FILE) . '/tmp/sc_plans.json', json_encode($resp['plans']))) {
			$this->create_nuvei_global_attribute();
			
			wp_send_json(array(
				'status'    => 1,
				'time'      => gmdate('Y-m-d H:i:s')
			));
			exit;
		}
		
		Nuvei_Logger::write(
			plugin_dir_path(NUVEI_PLUGIN_FILE) . 'tmp/sc_plans.json',
			'Plans list was not saved.'
		);
		
		wp_send_json(array('status' => 0));
		exit;
	}
	
	public function get_today_log() {
		$log_file = plugin_dir_path(NUVEI_PLUGIN_FILE) . 'logs/' . gmdate('Y-m-d') . '.txt';
		
		if (!file_exists($log_file)) {
			wp_send_json(array(
				'status'    => 0,
				'msg'       => __('The Log file not exists.', 'nuvei_checkout_woocommerce')
			));
			exit;
		}
		
		if (!is_readable($log_file)) {
			wp_send_json(array(
				'status'    => 0,
				'msg'       => __('The Log file is not readable.', 'nuvei_checkout_woocommerce')
			));
			exit;
		}
		
		wp_send_json(array(
			'status'    => 1,
			'data'      => file_get_contents($log_file)
		));
		exit;
	}
	
	public function get_subscr_fields() {
		return $this->subscr_fields;
	}
	
	public function get_subscr_units() {
		return $this->subscr_units;
	}
	
	public function can_use_upos() {
		if (isset($this->settings['use_upos'])) {
			return $this->settings['use_upos'];
		}
		
		return 0;
	}
	
	public function show_apms_names() {
		if (isset($this->settings['show_apms_names'])) {
			return $this->settings['show_apms_names'];
		}
		
		return 0;
	}
	
	public function create_nuvei_global_attribute() {
		Nuvei_Logger::write('create_nuvei_global_attribute()');
		
		$nuvei_plans_path          = plugin_dir_path(NUVEI_PLUGIN_FILE) . '/tmp/sc_plans.json';
		$nuvei_glob_attr_name_slug = Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME);
		$taxonomy_name             = wc_attribute_taxonomy_name($nuvei_glob_attr_name_slug);
		
		// a check
		if (!is_readable($nuvei_plans_path)) {
			Nuvei_Logger::write('Plans json is not readable.');
			
			wp_send_json(array(
				'status'    => 0,
				'msg'       => __('Plans json is not readable.')
			));
			exit;
		}
		
		$plans = json_decode(file_get_contents($nuvei_plans_path), true);

		// a check
		if (empty($plans) || !is_array($plans)) {
			Nuvei_Logger::write($plans, 'Unexpected problem with the Plans list.');

			wp_send_json(array(
				'status'    => 0,
				'msg'       => __('Unexpected problem with the Plans list.')
			));
			exit;
		}
		
		// check if Taxonomy exists
		if (taxonomy_exists($taxonomy_name)) {
			Nuvei_Logger::write('$taxonomy_name exists');
			return;
		}
		
		// create the Global Attribute
		$args = array(
			'name'         => NUVEI_GLOB_ATTR_NAME,
			'slug'         => $nuvei_glob_attr_name_slug,
			'order_by'     => 'menu_order',
			'has_archives' => true,
		);

		// create the attribute and check for errors
		$attribute_id = wc_create_attribute($args);

		if (is_wp_error($attribute_id)) {
			Nuvei_Logger::write(
				array(
					'$args'     => $args,
					'message'   => $attribute_id->get_error_message(), 
				),
				'Error when try to add Global Attribute with arguments'
			);

			wp_send_json(array(
				'status'    => 0,
				'msg'       => $attribute_id->get_error_message()
			));
			exit;
		}

		// craete WP taxonomy based on the WC attribute
		register_taxonomy(
			$taxonomy_name, 
			array('product'), 
			array(
				'public' => false,
			)
		);
	}
	
	/**
	 * Decide to add or not a product to the card.
	 * 
	 * @param bool $true
	 * @param int $product_id
	 * @param int $quantity
	 * 
	 * @return bool
	 */
	public function add_to_cart_validation( $true, $product_id, $quantity) {
		global $woocommerce;
		
		$cart       = $woocommerce->cart;
		$product    = wc_get_product( $product_id );
		$attributes = $product->get_attributes();
		$cart_items = $cart->get_cart();
		
		// 1 - incoming Product with plan
		if (!empty($attributes['pa_' . Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME)])) {
			// 1.1 if there are Products in the cart, stop the process
			if (count($cart_items) > 0) {
				wc_print_notice(__('You can not add a Product with Payment Plan to another Product.', 'nuvei_checkout_woocommerce'), 'error');
				return false;
			}
			
			return true;
		}
		
		// 2 - incoming Product without plan
		// 2.1 - the cart is not empty
		if (count($cart_items) > 0) {
			foreach ($cart_items as $item) {
				$cart_product   = wc_get_product( $item['product_id'] );
				$cart_prod_attr = $cart_product->get_attributes();

				// 2.1.1 in case there is Product with plan in the Cart
				if (!empty($cart_prod_attr['pa_' . Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME)])) {
					wc_print_notice(__('You can not add Product to a Product with Payment Plan.', 'nuvei_checkout_woocommerce'), 'error');
					return false;
				}
			}
		}
		
		return true;
	}
	
	/**
	 * 
	 * @global type $woocommerce
	 */
	public function call_checkout() {
		global $woocommerce;
		
		# OpenOrder
		$oo_obj  = new Nuvei_Open_Order($this->settings);
		$oo_data = $oo_obj->process();
		
		if (!$oo_data || empty($oo_data['sessionToken'])) {
			wp_send_json(array(
				'result'	=> 'failure',
				'refresh'	=> false,
				'reload'	=> false,
				'messages'	=> '<ul id="sc_fake_error" class="woocommerce-error" role="alert"><li>' . __('Unexpected error, please try again later!', 'nuvei_checkout_woocommerce') . '</li></ul>'
			));

			exit;
		}
		# OpenOrder END
        $cart           = $woocommerce->cart;
        $cart_items     = $cart->get_cart();
        $time           = gmdate('Ymdhis');
        $ord_details    = WC()->session->get('nuvei_last_open_order_details');
        
		Nuvei_Logger::write($ord_details);
        $checkout_data  = array( // use it in the template
            'sessionToken'       => $oo_data['sessionToken'],
            'env'               => 'yes' == $this->get_setting('test') ? 'test' : 'prod',
            'merchantId'        => $this->get_setting('merchantId'),
            'merchantSiteId'    => $this->get_setting('merchantSiteId'),
            'country'           => $ord_details['billingAddress']['country'],
            'currency'          => get_woocommerce_currency(),
            'amount'            => (string) number_format((float) $cart->total, 2, '.', ''),
            'renderTo'          => '#nuvei_checkout_container',
            'onResult'          => 'nuveiCheckoutCallback',
            'userTokenId'       => $ord_details['billingAddress']['email'],
            'useDCC'            => 'yes' == $this->get_setting['use_dcc'] ? true : false,
            'strict'            => false,
            'savePM'            => 1 == $this->get_setting('use_upos') ? true : false, // for UPO
//            'subMethod'         => '',
            'pmWhitelist'       => [],
//            'pmBlacklist'       => [],
            'blockCards'        => [],
            
            
            
            
            'logLevel'          => '4', // 0 to 6
            'fullName'          => $ord_details['billingAddress']['firstName'] . ' ' . $oo_data['billingAddress']['lastName'],
            'email'             => $ord_details['billingAddress']['email'],
//            'hideApms'          => '',
            'alwaysCollectCvv'  => true,
            'showResponseMessage'  => true,
            'locale'            => get_locale(),
//            'payButton'            => ,
//            
//            'blockCards'            => ,
            'autoOpenPM'            => true,
//            'i18n'            => '',
//            'fieldStyle'            => [],
            
        );
		
		// check for product with a plan
		foreach ($cart_items as $values) {
			$product    = wc_get_product($values['data']->get_id());
			$attributes = $product->get_attributes();
			
			// if there is a plan, remove all APMs
			if (!empty($attributes['pa_' . Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME)])) {
				$checkout_data['pmWhitelist'][] = ['cc_card'];
                break;
			}
		}
		// check for product with a plan END
		
		$resp_data['nuveiPluginUrl']     = plugin_dir_url(NUVEI_PLUGIN_FILE);
		$resp_data['nuveiSiteUrl']       = get_site_url();
			
        Nuvei_Logger::write($checkout_data);
        
		wp_send_json(array(
			'result'	=> 'failure', // this is just to stop WC send the form, and show APMs
			'refresh'	=> false,
			'reload'	=> false,
			'messages'	=> '<script>showNuveiCheckout(' . json_encode($checkout_data) . ');</script>'
		));

		exit;
	}
	
	/**
	 * Get a plugin setting by its key.
	 * If key does not exists, return default value.
	 * 
	 * @param string    $key - the key we are search for
	 * @param mixed     $default - the default value if no setting found
	 */
	private function get_setting( $key, $default = 0) {
		if (!empty($this->settings[$key])) {
			return $this->settings[$key];
		}
		
		return $default;
	}
	
	private function generate_cashier_url( $success_url, $error_url, $order_id) {
		global $woocommerce;
		
		$cart          = $woocommerce->cart;
		$nuvei_helper  = new Nuvei_Helper($this->settings);
		$addresses     = $nuvei_helper->get_addresses();
		$products_data = $nuvei_helper->get_products();
		$total_amount  = (string) number_format((float) $cart->total, 2, '.', '');
		$shipping      = '0.00';
		$handling      = '0.00'; // put the tax here, because for Cashier the tax is in %
		$discount      = '0.00';
		
		Nuvei_Logger::write($products_data, 'get_cashier_url() $products_data.');
		
		$params = array(
			'merchant_id'       => $this->settings['merchantId'],
			'merchant_site_id'  => $this->settings['merchantSiteId'],
			'version'           => '4.0.0',
		//            'user_token_id'     => @$data['order']['description']['billingAddress']['email'],
			'time_stamp'        => gmdate('Y-m-d H:i:s'),
			
			'first_name'        => $addresses['billingAddress']['firstName'],
			'last_name'         => $addresses['billingAddress']['lastName'],
			'email'             => $addresses['billingAddress']['email'],
			'country'           => $addresses['billingAddress']['country'],
			'city'              => $addresses['billingAddress']['city'],
			'zip'               => $addresses['billingAddress']['zip'],
			'address1'          => $addresses['billingAddress']['address'],
			'phone1'            => $addresses['billingAddress']['phone'],
			'merchantLocale'    => get_locale(),
			
			'notify_url'        => Nuvei_String::get_notify_url($this->settings, $order_id),
			'success_url'       => $success_url,
			'error_url'         => $error_url,
			'pending_url'       => $success_url,
			'back_url'          => wc_get_checkout_url(),
			
			'customField1'      => '', // subscription details as json
			'customField2'      => json_encode($products_data['products_data']), // item details as json
			'customField3'      => time(), // create time time()
			
			'currency'          => get_woocommerce_currency(),
			'total_tax'         => 0,
			'total_amount'      => $total_amount,
		);
		
		if (1 == $this->settings['use_upos']) {
			$params['user_token_id'] = $addresses['billingAddress']['email'];
		}
		
		// check for subscription data
		if (!empty($products_data['subscr_data'])) {
			$params['customField1']        = json_encode($products_data['subscr_data']);
			$params['user_token_id']       = $addresses['billingAddress']['email'];
			$params['payment_method']      = 'cc_card'; // only cards are allowed for Subscribtions
			$params['payment_method_mode'] = 'filter';
		}
		
		// create one combined item
		if (1 == $this->get_setting('combine_cashier_products')) {
			$params['item_name_1']     = 'WC_Cashier_Order';
			$params['item_quantity_1'] = 1;
			$params['item_amount_1']   = $total_amount;
			$params['numberofitems']   = 1;
		} else { // add all the items
			$cnt           = 1;
			$contol_amount = 0;

			foreach ($products_data['products_data'] as $item) {
				$params['item_name_' . $cnt]     = ( $item['name'] );
				$params['item_amount_' . $cnt]   = number_format((float) round($item['price'], 2), 2, '.', '');
				$params['item_quantity_' . $cnt] = (int) $item['quantity'];

				$contol_amount += $params['item_quantity_' . $cnt] * $params['item_amount_' . $cnt];
				$params['numberofitems'] ++;
				$cnt++;
			}
			
			Nuvei_Logger::write($contol_amount, '$contol_amount');
			
			if (!empty($products_data['totals']['shipping_total'])) {
				$shipping = round($products_data['totals']['shipping_total'], 2);
			}
			if (!empty($products_data['totals']['shipping_tax'])) {
				$shipping += round($products_data['totals']['shipping_tax'], 2);
			}
			
			if (!empty($products_data['totals']['discount_total'])) {
				$discount = round($products_data['totals']['discount_total'], 2);
			}
			
			$contol_amount += ( $shipping - $discount );
			
			if ($total_amount > $contol_amount) {
				$handling = round( ( $total_amount - $contol_amount ), 2 );
				
				Nuvei_Logger::write($handling, '$handling');
			} elseif ($total_amount < $contol_amount) {
				$discount += ( $contol_amount - $total_amount );
				
				Nuvei_Logger::write($discount, '$discount');
			}
		}
		
		$params['discount'] = number_format((float) $discount, 2, '.', '');
		$params['shipping'] = number_format((float) $shipping, 2, '.', '');
		$params['handling'] = number_format((float) $handling, 2, '.', '');
		
		$params['checksum'] = hash(
			$this->settings['hash_type'],
			$this->settings['secret'] . implode('', $params)
		);
		
		Nuvei_Logger::write($params, 'get_cashier_url() $params.');
		
		$url  = 'yes' == $this->settings['test'] ? 'https://ppp-test.safecharge.com' : 'https://secure.safecharge.com';
		$url .= '/ppp/purchase.do?' . http_build_query($params);
		
		Nuvei_Logger::write($url, 'get_cashier_url() url');
		
		return $url;
	}
	
    /**
     * Instead of override init_form_fields() split the settings in three
     * groups and put them in different tabs.
     */
    private function init_form_base_fields() {
        $this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'nuvei_checkout_woocommerce'),
				'type' => 'checkbox',
				'label' => __('Enable ' . 'Nuvei Checkout.', 'nuvei_checkout_woocommerce'),
				'default' => 'no'
			),
		   'title' => array(
				'title' => __('Default title', 'nuvei_checkout_woocommerce'),
				'type'=> 'text',
				'description' => __('This is the payment method which the user sees during checkout.', 'nuvei_checkout_woocommerce'),
				'default' => __('Secure Payment with Nuvei', 'nuvei_checkout_woocommerce')
			),
			'description' => array(
				'title' => __('Description', 'nuvei_checkout_woocommerce'),
				'type' => 'textarea',
				'description' => __('This controls the description which the user sees during checkout.', 'nuvei_checkout_woocommerce'),
				'default' => 'Place order to get to our secured payment page to select your payment option'
			),
			'test' => array(
				'title' => __('Site Mode', 'nuvei_checkout_woocommerce') . ' *',
				'type' => 'select',
				'required' => 'required',
				'options' => array(
					'' => __('Select an option...'),
					'yes' => 'Sandbox',
					'no' => 'Production',
				),
			),
			'merchantId' => array(
				'title' => __('Merchant ID', 'nuvei_checkout_woocommerce') . ' *',
				'type' => 'text',
				'required' => true,
				'description' => __('Merchant ID is provided by ' . NUVEI_GATEWAY_TITLE . '.')
			),
			'merchantSiteId' => array(
				'title' => __('Merchant Site ID', 'nuvei_checkout_woocommerce') . ' *',
				'type' => 'text',
				'required' => true,
				'description' => __('Merchant Site ID is provided by ' . NUVEI_GATEWAY_TITLE . '.')
			),
			'secret' => array(
				'title' => __('Secret key', 'nuvei_checkout_woocommerce') . ' *',
				'type' => 'text',
				'required' => true,
				'description' =>  __('Secret key is provided by ' . NUVEI_GATEWAY_TITLE, 'nuvei_checkout_woocommerce'),
			),
			'hash_type' => array(
				'title' => __('Hash type', 'nuvei_checkout_woocommerce') . ' *',
				'type' => 'select',
				'required' => true,
				'description' => __('Choose Hash type provided by ' . NUVEI_GATEWAY_TITLE, 'nuvei_checkout_woocommerce'),
				'options' => array(
					'' => __('Select an option...'),
					'sha256' => 'sha256',
					'md5' => 'md5',
				)
			),
			'payment_action' => array(
				'title' => __('Payment action', 'nuvei_checkout_woocommerce') . ' *',
				'type' => 'select',
				'required' => true,
				'options' => array(
					'' => __('Select an option...'),
					'Sale' => 'Authorize and Capture',
					'Auth' => 'Authorize',
				)
			),
			'use_upos' => array(
				'title'         => __('Allow client to use UPOs', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'options'       => array(
					0 => 'No',
					1 => 'Yes',
				),
                'description'   => sprintf(
                    '%s <a href="%s" class="class">%s</a>',
                    __('This option is for Nuvei Checkout.', 'nuvei_checkout_woocommerce'),
                    esc_html('https://docs.safecharge.com/documentation/accept-payment/checkout-sdk/'),
                    __('Check the Documentation - savePM parameter.', 'nuvei_checkout_woocommerce')
                ),
			),
			'show_apms_names' => array(
				'title' => __('Show APMs names', 'nuvei_checkout_woocommerce'),
				'type' => 'select',
				'options' => array(
					0 => 'No',
					1 => 'Yes',
				)
			),
			'apple_pay_label' => array(
				'title' => __('Apple Pay Label', 'nuvei_checkout_woocommerce'),
				'type' => 'text',
			),
			'save_logs' => array(
				'title' => __('Save logs', 'nuvei_checkout_woocommerce'),
				'type' => 'checkbox',
				'label' => __('Create and save daily log files. This can help for debugging and catching bugs.', 'nuvei_checkout_woocommerce'),
				'default' => 'yes'
			),
		);
    }
    
    /**
     * Instead of override init_form_fields() split the settings in three
     * groups and put them in different tabs.
     */
    private function init_form_advanced_fields_cashier() {
        $this->form_fields = array(
            'use_cashier' => array(
				'title'         => __('Use Cashier instead REST API', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'description'   => __('The REST API is recommended.', 'nuvei_checkout_woocommerce'),
				'default'       => 0,
				'options'       => array(
					0 => __('No', 'nuvei_checkout_woocommerce'),
					1 => __('Yes', 'nuvei_checkout_woocommerce'),
				)
			),
			'combine_cashier_products' => array(
				'title'         => __('Combine Cashier products into one', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'description'   => __('Cobine the products into one, to avoid eventual problems with, taxes, discounts, coupons, etc.', 'nuvei_checkout_woocommerce'),
				'default'       => 1,
				'options'       => array(
					1 => __('Yes', 'nuvei_checkout_woocommerce'),
					0 => __('No', 'nuvei_checkout_woocommerce'),
				)
			),
        );
    }
    
    /**
     * Instead of override init_form_fields() split the settings in three
     * groups and put them in different tabs.
     */
    private function init_form_advanced_fields_checkout() {
        $this->form_fields = array(
            'use_dcc' => [
                'title'         => __('Use currency conversion', 'nuvei_checkout_woocommerce'),
				'type'          => 'checkbox',
				'label'         => __('Enable currency conversion.', 'nuvei_checkout_woocommerce'),
                'description'   => sprintf(
                    '%s <a href="%s" class="class">%s</a>',
                    __('This option is for Nuvei Checkout.', 'nuvei_checkout_woocommerce'),
                    esc_html('https://docs.safecharge.com/documentation/guides/currency-conversion-features/dynamic-currency-conversion-dcc/'),
                    __('Check the Documentation.', 'nuvei_checkout_woocommerce')
                ),
				'default'       => 'no'
            ],
            'blocked_cards' => [
                'title'         => __('Block Cards', 'nuvei_checkout_woocommerce'),
				'type'          => 'text',
                'description'   => sprintf(
                    '%s <a href="%s" class="class">%s</a>',
                    __('This option is for Nuvei Checkout.', 'nuvei_checkout_woocommerce'),
                    esc_html('https://preprod-docs-safecharge.gw-4u.com/documentation/features/blocking-cards/'),
                    __('Check the Documentation.', 'nuvei_checkout_woocommerce')
                ),
            ],
        );
    }
    
    /**
     * Instead of override init_form_fields() split the settings in three
     * groups and put them in different tabs.
     */
    private function init_form_advanced_fields() {
        $this->form_fields = array(
            'get_plans_btn' => array(
				'title' => __('Download Payment Plans', 'nuvei_checkout_woocommerce'),
				'type' => 'button',
			),
        );
    }
    
    /**
     * Instead of override init_form_fields() split the settings in three
     * groups and put them in different tabs.
     */
    private function init_form_tools_fields() {
        $this->form_fields = array(
            'merchant_style' => array(
				'title' => __('Custom style', 'nuvei_checkout_woocommerce'),
				'type' => 'textarea',
				'default' => '',
				'description' => __('Override the build-in style for the Nuvei elements.', 'nuvei_checkout_woocommerce')
			),
            'notify_url' => array(
				'title' => __('Notify URL', 'nuvei_checkout_woocommerce'),
				'type' => 'text',
				'default' => '',
				'description' => Nuvei_String::get_notify_url($this->settings),
				'type' => 'hidden'
			),
            'today_log' => array(
				'title' => __('Today log', 'nuvei_checkout_woocommerce'),
				'type' => 'textarea',
				'description' => __('Please, use with caution! The log files can be enormous.', 'nuvei_checkout_woocommerce'),
			),
        );
    }
    
}
