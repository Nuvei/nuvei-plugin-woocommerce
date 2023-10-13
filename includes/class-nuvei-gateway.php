<?php

defined( 'ABSPATH' ) || exit;

/**
 * Main class for the Nuvei Plugin
 */
class Nuvei_Gateway extends WC_Payment_Gateway
{
	private $plugin_data    = [];
	private $subscr_units   = ['year', 'month', 'day'];
    private $rest_params    = []; // Cart data passed from REST API call.
    private $order; // get the Order in process_payment()
	
	public function __construct()
    {
		# settings to get/save options
		$this->id                 = NUVEI_GATEWAY_NAME;
		$this->method_title       = __('Nuvei Checkout', 'nuvei_checkout_woocommerce' );
		$this->method_description = __('Pay with ', 'nuvei_checkout_woocommerce' ) . NUVEI_GATEWAY_TITLE . '.';
		$this->method_name        = NUVEI_GATEWAY_TITLE;
		$this->icon               = plugin_dir_url(NUVEI_PLUGIN_FILE) . 'assets/icons/nuvei.png';
		$this->has_fields         = false;

		$this->init_settings();
		$this->init_form_base_fields();
		$this->init_form_advanced_fields(true);
		$this->init_form_tools_fields(true);
		
		// required for the Store
		$this->title       = $this->get_setting('title', NUVEI_GATEWAY_TITLE);
		$this->description = $this->get_setting('description', $this->method_description);
		$this->plugin_data = get_plugin_data(plugin_dir_path(NUVEI_PLUGIN_FILE) . DIRECTORY_SEPARATOR . 'index.php');
		
		$this->use_wpml_thanks_page = !empty($this->settings['use_wpml_thanks_page']) 
			? $this->settings['use_wpml_thanks_page'] : 'no';
		
        // products are supported by default
		$this->supports[] = 'refunds'; // to enable auto refund support
		$this->supports[] = 'subscriptions'; // to enable WC Subscriptions
		$this->supports[] = 'subscription_cancellation'; // always
		$this->supports[] = 'subscription_suspension'; // for not more than 400 days
		$this->supports[] = 'subscription_reactivation'; // after not more than 400 days
		$this->supports[] = 'subscription_amount_changes'; // always
		$this->supports[] = 'subscription_date_changes'; // always
		$this->supports[] = 'multiple_subscriptions'; // TODO - not sure what is this for
		
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
	 * This function catch the type of the settings element we want
	 * to create. Example - element type button1 -> generate_button1_html
	 *
	 * @param mixed $key
	 * @param mixed $data
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function generate_payment_plans_btn_html( $key, $data)
    {
		$defaults = array(
			'class'             => 'button-secondary',
			'css'               => '',
			'custom_attributes' => array(),
			'desc_tip'          => false,
			'description'       => '',
			'title'             => '',
		);
		
		$nuvei_plans_path = NUVEI_LOGS_DIR . NUVEI_PLANS_FILE;

		if (is_readable($nuvei_plans_path)) { 
			$defaults['description'] = __('Last download: ', 'nuvei_checkout_woocommerce')
				. gmdate('Y-m-d H:i:s', filemtime($nuvei_plans_path));
		}

		ob_start();
		
		$data = wp_parse_args($data, $defaults);
		require_once dirname(NUVEI_PLUGIN_FILE) . '/templates/admin/download_payments_plans_btn.php';
		
		return ob_get_clean();
	}
	
    /**
     * Generate custom multi select for the plugin settings.
     * 
     * @param $key
     * @param array $data
     * 
     * @return string
     */
	public function generate_nuvei_multiselect_html( $key, $data)
    {
		# prepare the list with Payment methods
//		$get_st_obj    = new Nuvei_Session_Token($this->settings);
		$get_st_obj    = new Nuvei_Session_Token();
		$resp          = $get_st_obj->process();
		$session_token = !empty($resp['sessionToken']) ? $resp['sessionToken'] : '';
		
		$nuvei_blocked_pms_visible = array();
		$nuvei_blocked_pms         = explode(',', $this->get_setting('pm_black_list', ''));
		$pms                       = array(
			'' => __('Select payment methods...', 'nuvei_checkout_woocommerce')
		);
		
		$get_apms_obj = new Nuvei_Get_Apms($this->settings);
		$resp         = $get_apms_obj->process(array('sessionToken' => $session_token));
		
		if (!empty($resp['paymentMethods']) && is_array($resp['paymentMethods'])) {
			foreach ($resp['paymentMethods'] as $data) {
				// the array for the select menu
				if (!empty($data['paymentMethodDisplayName'][0]['message'])) {
					$pms[$data['paymentMethod']] = $data['paymentMethodDisplayName'][0]['message'];
				} else {
					$pms[$data['paymentMethod']] = $data['paymentMethod'];
				}
				
				// generate visible list
				if (in_array($data['paymentMethod'], $nuvei_blocked_pms)) {
					$nuvei_blocked_pms_visible[] = $pms[$data['paymentMethod']];
				}
			}
		}
		# prepare the list with Payment methods END
		
		$defaults = array(
			'title'                     => __('Block Payment methods', 'nuvei_checkout_woocommerce'),
			'class'                     => 'nuvei_checkout_setting',
			'css'                       => '',
			'custom_attributes'         => array(),
			'desc_tip'                  => false,
			'merchant_pms'              => $pms,
			'nuvei_blocked_pms'         => $nuvei_blocked_pms,
			'nuvei_blocked_pms_visible' => implode(', ', $nuvei_blocked_pms_visible),
		);
		
		ob_start();
		
		$data = wp_parse_args($data, $defaults);
		require_once dirname(NUVEI_PLUGIN_FILE) . '/templates/admin/block_pms_select.php';
		
		return ob_get_clean();
	}

	// Generate the HTML For the settings form.
	public function admin_options()
    {
		require_once dirname(NUVEI_PLUGIN_FILE) . '/templates/admin/settings.php';
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
	public function process_payment($order_id)
    {
        $nuvei_order_details    = WC()->session->get(NUVEI_SESSION_ORDER_DETAILS);
        $nuvei_oo_details       = WC()->session->get(NUVEI_SESSION_OO_DETAILS);
        
		Nuvei_Logger::write(
            [
                '$order_id'                 => $order_id,
                'request params'            => $_REQUEST,
                NUVEI_SESSION_ORDER_DETAILS => $nuvei_order_details,
            ],
            'Process payment(), Order'
        );
		
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
			Nuvei_Logger::write('Process payment Error - Order payment does not belongs to ' . NUVEI_GATEWAY_NAME);
			
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
		$nuvei_transaction_id   = Nuvei_Http::get_param('nuvei_transaction_id', 'int');
		$nuvei_session_token    = Nuvei_Http::get_param('nuvei_session_token');
        
		# in case we use Cashier
		if (isset($this->settings['integration_type'])
            && 'cashier' == $this->settings['integration_type']
        ) {
			Nuvei_Logger::write('Process Cashier payment.');
			
            $this->order = $order;
            
			$url = $this->generate_cashier_url(
                $return_success_url,
                $return_error_url//,
//                $order_id,
//                $order->get_total()
            );

			if (!empty($url)) {
				return array(
					'result'    => 'success',
					'redirect'  => add_query_arg(array(), $url)
				);
			}
			
			return;
		}
		# /in case we use Cashier
		
         // search for subscr data
        if (!empty($nuvei_order_details)) {
            // save the Nuvei Subscr data to the order
            if (!empty($nuvei_order_details[$nuvei_session_token]['subscr_data'])) {
//                foreach ($nuvei_order_details[$nuvei_session_token]['subscr_data'] as $item_prod => $data) {
                foreach ($nuvei_order_details[$nuvei_session_token]['subscr_data'] as $data) {
                    // set meta key
                    if (isset($data['product_id'])) {
                        $meta_key = NUVEI_ORDER_SUBSCR . '_product_' . $data['product_id'];
                    }
                    if (isset($data['variation_id'])) {
                        $meta_key = NUVEI_ORDER_SUBSCR . '_variation_' . $data['variation_id'];
                    }
                    
                    $order->update_meta_data($meta_key, $data);
                }
            }
            
            // mark order if there is WC Subsc
            if (!empty($nuvei_order_details[$nuvei_session_token]['wc_subscr'])) {
                $order->update_meta_data(NUVEI_WC_SUBSCR, true);
            }
            
            WC()->session->set(NUVEI_SESSION_ORDER_DETAILS, []);
        }
        
        // Success
		if (!empty($nuvei_transaction_id)) {
			Nuvei_Logger::write('Process webSDK Order, transaction ID #' . $nuvei_transaction_id);
			
            $transactions_data[$nuvei_transaction_id] = [];
//			$order->update_meta_data(NUVEI_TRANS_ID, $nuvei_transaction_id);
			$order->update_meta_data(NUVEI_TR_ID, $nuvei_transaction_id);
			$order->update_meta_data(NUVEI_TRANSACTIONS, $transactions_data);
            
            Nuvei_Logger::write($nuvei_oo_details);
            
            // save as meta DCC data if exists
            if (!empty($nuvei_oo_details['dcc']['currency'])
                && !empty($nuvei_oo_details['dcc']['converted_amount'])
            ) {
                $order->update_meta_data(NUVEI_DCC_DATA, [
                    'currency'          => $nuvei_oo_details['dcc']['currency'],
                    'converted_amount'  => $nuvei_oo_details['dcc']['converted_amount'],
                ]);
            }
            
			$order->save();
			
			return array(
				'result'    => 'success',
				'redirect'  => $return_success_url
			);
		}
        
        // Error - missing Transaction ID
        Nuvei_Logger::write($order_id, '$nuvei_transaction_id is empty for Order ID ');
        
        return array(
            'result'    => 'success',
            'redirect'  => array(
                'Status'    => 'error',
            ),
            wc_get_checkout_url() . 'order-received/' . $order_id . '/'
        );
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
	public function process_refund( $order_id, $amount = null, $reason = '')
    {
		if ('true' == Nuvei_Http::get_param('api_refund')) {
			return true;
		}
		
		return false;
	}
	
	public function return_settle_btn( $and_taxes, $order)
    {
        if (!is_a($order, 'WC_Order') || is_a($order, 'WC_Subscription')) {
            return false;
        }
        
		if (!method_exists($order, 'get_payment_method')
			|| empty($order->get_payment_method())
			|| !in_array($order->get_payment_method(), array(NUVEI_GATEWAY_NAME, 'sc'))
		) {
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
	public function restock_on_refunded_status( $order_id)
    {
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
	
	public function reorder()
    {
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
	public function download_subscr_pans( $recursions = 0)
    {
        Nuvei_Logger::write('download_subscr_pans');
        
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
		
		if (file_put_contents(NUVEI_LOGS_DIR . NUVEI_PLANS_FILE, json_encode($resp['plans']))) {
			$this->create_nuvei_global_attribute();
			
			wp_send_json(array(
				'status'    => 1,
				'time'      => gmdate('Y-m-d H:i:s')
			));
			exit;
		}
		
		Nuvei_Logger::write(
			NUVEI_LOGS_DIR . NUVEI_PLANS_FILE,
			'Plans list was not saved.'
		);
		
		wp_send_json(array('status' => 0));
		exit;
	}
	
	public function get_today_log() {
		$log_file = NUVEI_LOGS_DIR . gmdate('Y-m-d') . '.' . NUVEI_LOG_EXT;
		
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
	
	public function can_use_upos()
    {
		if (isset($this->settings['use_upos'])) {
			return $this->settings['use_upos'];
		}
		
		return 0;
	}
	
	public function create_nuvei_global_attribute()
    {
		Nuvei_Logger::write('create_nuvei_global_attribute()');
		
		$nuvei_plans_path          = NUVEI_LOGS_DIR . NUVEI_PLANS_FILE;
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
	public function add_to_cart_validation($true, $product_id, $quantity)
    {
        Nuvei_Logger::write(is_user_logged_in(), 'add_to_cart_validation');
        
		global $woocommerce;
		
		$cart       = $woocommerce->cart;
		$product    = wc_get_product( $product_id );
		$attributes = $product->get_attributes();
		
        // for guests disable adding products with Nuvei Payment plan or WCS to the Cart
        if (!is_user_logged_in()
            && 0 == $this->get_setting('save_guest_upos')
        ) {
            if (!empty($attributes['pa_' . Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME)])) {
                wc_add_notice(
                    __('Please create an account or login to subscribe.', 'nuvei_checkout_woocommerce'),
                    'error'
                );

                return false;
            }
            
            if (false !== strpos($product->get_type(), 'subscription')) {
                wc_add_notice(
                    __('Please create an account or login to subscribe.', 'nuvei_checkout_woocommerce'),
                    'error'
                );

                return false;
            }
        }
        
		// for guests disable adding products with Nuvei Payment plan to the Cart
//		if (!empty($attributes['pa_' . Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME)])
//            && !is_user_logged_in()
//        ) {
//            wc_add_notice(
//                __('You must login to add a product with a Payment Plan.', 'nuvei_checkout_woocommerce'),
//                'error'
//            );
//            
//            return false;
//		}
		
		return true;
	}
	
	/**
	 * Call the Nuvei Checkout SDK form here and pass all parameters.
	 * 
	 * @global $woocommerce
     * @param bool $is_rest
	 */
//	public function call_checkout($is_ajax = false, $is_rest = false)
	public function call_checkout($is_rest = false)
    {
        Nuvei_Logger::write([$is_rest, $this->rest_params], 'call_checkout()');
        
		global $woocommerce;
        
		# OpenOrder
		$oo_obj     = new Nuvei_Open_Order($this->settings, false, $this->rest_params);
		$oo_data    = $oo_obj->process();
		
		if (!$oo_data || empty($oo_data['sessionToken'])) {
            $msg = __('Unexpected error, please try again later!', 'nuvei_checkout_woocommerce');
            
            Nuvei_Logger::write($msg);
            
            if (!empty($oo_data['custom_msg'])) {
                $msg = $oo_data['custom_msg'];
            }
			
            wp_send_json(array(
				'result'	=> 'failure',
				'refresh'	=> false,
				'reload'	=> false,
				'messages'	=> '<ul id="sc_fake_error" class="woocommerce-error" role="alert"><li>' . $msg . '</li></ul>'
			));

			exit;
		}
		# /OpenOrder
        
        $nuvei_helper           = new Nuvei_Helper();
		$ord_details            = $nuvei_helper->get_addresses($this->rest_params);
//		$prod_details           = $nuvei_helper->get_products($this->rest_params);
		$prod_details           = $oo_data['products_data'];
		$pm_black_list          = trim($this->get_setting('pm_black_list', ''));
        $is_there_subscription  = false;
        $total                  = '0.00';
        
        if (isset($woocommerce->cart->total)) {
            $total = (string) number_format((float) $woocommerce->cart->total, 2, '.', '');
        }
        elseif (!empty($this->rest_params)) {
            $total = $nuvei_helper->get_rest_total($this->rest_params);
        }

		if (!empty($pm_black_list)) {
			$pm_black_list = explode(',', $pm_black_list);
		}
		
        // for UPO
        $use_upos   = $save_pm 
                    = (bool) $this->get_setting('use_upos');
        
        Nuvei_Logger::write($prod_details);
        
        if(!is_user_logged_in() 
            || ($is_rest && empty($this->rest_params['isUserLogged']))
        ) {
            $use_upos = $save_pm = false;
        }
        
        if( !empty($prod_details['wc_subscr']) || !empty($prod_details['subscr_data']) ) {
            $save_pm                = 'always';
            $is_there_subscription  = true;
        }
        
		$checkout_data = array( // use it in the template
			'sessionToken'              => $oo_data['sessionToken'],
			'env'                       => 'yes' == $this->get_setting('test') ? 'test' : 'prod',
			'merchantId'                => $this->get_setting('merchantId'),
			'merchantSiteId'            => $this->get_setting('merchantSiteId'),
			'country'                   => $ord_details['billingAddress']['country'],
			'currency'                  => get_woocommerce_currency(),
			'amount'                    => $total,
			'renderTo'                  => '#nuvei_checkout',
			'useDCC'                    =>  $this->get_setting('use_dcc', 'enable'),
			'strict'                    => false,
			'savePM'                    => $save_pm,
			'showUserPaymentOptions'    => $use_upos,
			'pmWhitelist'               => null,
			'pmBlacklist'               => empty($pm_black_list) ? null : $pm_black_list,
			'alwaysCollectCvv'          => true,
			'fullName'                  => $ord_details['billingAddress']['firstName'] . ' ' . $oo_data['billingAddress']['lastName'],
			'email'                     => $ord_details['billingAddress']['email'],
			'payButton'                 => $this->get_setting('pay_button', 'amountButton'),
			'showResponseMessage'       => false, // shows/hide the response popups
			'locale'                    => substr(get_locale(), 0, 2),
			'autoOpenPM'                => (bool) $this->get_setting('auto_open_pm', 1),
			'logLevel'                  => $this->get_setting('log_level'),
			'maskCvv'                   => true,
			'i18n'                      => json_decode($this->get_setting('translation', ''), true),
			'theme'                     => $this->get_setting('sdk_theme', 'accordion'),
		);
        
		// check for product with a plan
		if ($is_there_subscription) {
            $checkout_data['pmWhitelist'] = ['cc_card'];
            
            // only for WCS
            if (1 == $this->get_setting('allow_paypal_rebilling', 0)
                && !empty($prod_details['wc_subscr'])
            ) {
                $checkout_data['pmWhitelist'][]             = 'apmgw_expresscheckout';
                $checkout_data['showUserPaymentOptions']    = false;
            }
            
            unset($checkout_data['pmBlacklist']);
        }
		
		# blocked_cards
		$blocked_cards     = [];
		$blocked_cards_str = $this->get_setting('blocked_cards', '');
		// clean the string from brakets and quotes
		$blocked_cards_str = str_replace('],[', ';', $blocked_cards_str);
		$blocked_cards_str = str_replace('[', '', $blocked_cards_str);
		$blocked_cards_str = str_replace(']', '', $blocked_cards_str);
		$blocked_cards_str = str_replace('"', '', $blocked_cards_str);
		$blocked_cards_str = str_replace("'", '', $blocked_cards_str);
		
		if (empty($blocked_cards_str)) {
			$checkout_data['blockCards'] = [];
		} else {
			$blockCards_sets = explode(';', $blocked_cards_str);

			if (count($blockCards_sets) == 1) {
				$blocked_cards = explode(',', current($blockCards_sets));
			} else {
				foreach ($blockCards_sets as $elements) {
					$blocked_cards[] = explode(',', $elements);
				}
			}

			$checkout_data['blockCards'] = $blocked_cards;
		}
		# blocked_cards END
        
		$resp_data['nuveiPluginUrl'] = plugin_dir_url(NUVEI_PLUGIN_FILE);
		$resp_data['nuveiSiteUrl']   = get_site_url();
			
//        if ($is_ajax) {
//            Nuvei_Logger::write($checkout_data, '$checkout_data');
//            wp_send_json($checkout_data);
//			exit;
//        }
        
        // REST API call
        if (!empty($this->rest_params)) {
            $checkout_data['transactionType']   = $oo_data['transactionType'];
            $checkout_data['orderId']           = $oo_data['orderId'];
            $checkout_data['products_data']     = $prod_details;
            
            Nuvei_Logger::write($checkout_data, 'REST API CALL $checkout_data');
            
            return $checkout_data;
        }

        Nuvei_Logger::write($checkout_data, '$checkout_data');
        
		wp_send_json(array(
			'result'	=> 'failure', // this is just to stop WC send the form, and show APMs
			'refresh'	=> false,
			'reload'	=> false,
			'messages'	=> '<script>showNuveiCheckout(' . json_encode($checkout_data) . ');</script>'
		));

		exit;
	}
    
    public function checkout_prepayment_check()
    {
        Nuvei_Logger::write('checkout_prepayment_check()');
        
		global $woocommerce;
        
        $nuvei_helper           = new Nuvei_Helper();
        $total                  = $woocommerce->cart->get_totals();
        $nuvei_order_details    = $woocommerce->session->get(NUVEI_SESSION_ORDER_DETAILS);
        $open_order_details     = $woocommerce->session->get(NUVEI_SESSION_OO_DETAILS);
        $products_data          = $nuvei_helper->get_products();
        
        Nuvei_Logger::write($woocommerce->cart);
        
        if (!empty($open_order_details['sessionToken'])
            && !empty($products_data_hash = $nuvei_order_details[$open_order_details['sessionToken']]['products_data_hash'])
            && $products_data_hash == md5(serialize($products_data))
        ) {
            
            // save converted order amount to the session and later as meta field
            if (!empty($_POST['dcc']['currency'])
                && 0 < $amount = $_POST['dcc']['converted_amount']
                && get_woocommerce_currency() != $_POST['dcc']['currency']
            ) {
                $open_order_details['dcc'] = [
                    'currency'          => filter_var($_POST['dcc']['currency']),
                    'converted_amount'  => (float) $_POST['dcc']['converted_amount'],
                ];
                
                Nuvei_Logger::write($open_order_details);
                
                WC()->session->set(NUVEI_SESSION_OO_DETAILS, $open_order_details);
            }
            
            wp_send_json(array(
                'success' => 1,
            ));

            exit;
        }
        
        Nuvei_Logger::write([
            '$total'                => $total,
            '$nuvei_order_details'  => $nuvei_order_details,
            '$open_order_details'   => $open_order_details,
            '$products_data'        => $products_data,
        ]);
        
        wp_send_json(array(
			'success' => 0,
		));

		exit;
    }
    
    public function hide_payment_gateways( $available_gateways )
    {
		if ( is_checkout() && ! is_wc_endpoint_url() ) {
            $nuvei_helper   = new Nuvei_Helper();
            $items_info     = $nuvei_helper->get_products();
            
//            if($items_info['item_with_plan']
            if (!empty($items_info['subscr_data'])
                && !empty( $available_gateways[ NUVEI_GATEWAY_NAME ] )
            ) {
                $filtred_gws[ NUVEI_GATEWAY_NAME ] = $available_gateways[ NUVEI_GATEWAY_NAME ];
                return $filtred_gws;
            }
		}

		return $available_gateways;
	}
    
    /**
     * Call this function form a hook to process an WC Subscription Order.
     * 
     * @param float $amount_to_charge
     * @param WC_Order $renewal_order The new Order.
     * @param int $product_id
     */
    public function create_wc_subscr_order($amount_to_charge, $renewal_order)
    {
        $renewal_order_id   = $renewal_order->get_id();
        $subscription       = wc_get_order($renewal_order->get_meta('_subscription_renewal'));
        
        if (!is_object($subscription)) {
            Nuvei_Logger::write(
                [
                    '$amount_to_charge' => $amount_to_charge,
                    '$renewal_order' => (array) $renewal_order,
                    '$_REQUEST' => $_REQUEST,
                    '$subscription' => $subscription, 
                    '$renewal_order_id' => $renewal_order_id, 
                    'get_post_meta' => get_post_meta($renewal_order_id)
                ],
                'Error, the Subscription is not an object.'
            );
            return;
        }
        
        $helper             = new Nuvei_Helper();
        $parent_order_id    = $subscription->get_parent_id();
        $parent_order       = wc_get_order($parent_order_id);
        $parent_tr_id       = $helper->helper_get_tr_id($parent_order_id);
        $parent_tr_upo_id   = $helper->get_tr_upo_id($parent_order_id);
        
        Nuvei_Logger::write(
            [
                '$renewal_order_id' => $renewal_order_id,
                '$parent_order_id'  => $parent_order_id,
            ],
            'create_wc_subscr_order'
        );
        
//        if (empty($parent_order->get_meta(NUVEI_TRANS_ID))
//            || empty($parent_order->get_meta(NUVEI_UPO))
//        ) {
        if (empty($parent_tr_upo_id) || empty($parent_tr_id)) {
            Nuvei_Logger::write(
                $parent_order->get_meta_data(),
                'Error - missing mandatory Parent order data.'
            );
            
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($parent_order);
            return;
        }
        
        // get Session Token
        $st_obj     = new Nuvei_Session_Token($this->settings);
        $st_resp    = $st_obj->process();
        
        if (empty($st_resp['sessionToken'])) {
            Nuvei_Logger::write('Error when try to get Session Token');
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($parent_order);
            return;
        }
        // /get Session Token
        
        $billing_mail   = $renewal_order->get_meta('_billing_email');
        $payment_obj    = new Nuvei_Payment($this->settings);
        $params         = [
            'sessionToken'          => $st_resp['sessionToken'],
            'userTokenId'           => $billing_mail,
            'clientRequestId'       => $renewal_order_id . '_' . $parent_order_id . '_' . uniqid(),
            'currency'              => $renewal_order->get_currency(),
            'amount'                => round($renewal_order->get_total(), 2),
            'billingAddress'        => [
                'country'   => $renewal_order->get_meta('_billing_country'),
                'email'     => $billing_mail,
            ],
//            'paymentOption'         => ['userPaymentOptionId' => $parent_order->get_meta(NUVEI_UPO)],
            'paymentOption'         => ['userPaymentOptionId' => $helper->get_tr_upo_id($parent_order_id)],
        ];
        
//        $parent_payment_method = $parent_order->get_meta(NUVEI_PAYMENT_METHOD);
        $parent_payment_method = $helper->get_payment_method($parent_order_id);
        
        if ('cc_card' == $parent_payment_method) {
            $params['isRebilling']          = 1;
//            $params['relatedTransactionId'] = $parent_order->get_meta(NUVEI_TRANS_ID);
            $params['relatedTransactionId'] = $parent_tr_id;
        }
        
        if ('apmgw_expresscheckout' == $parent_payment_method) {
            Nuvei_Logger::write('PayPal rebilling');
            
            $params['clientUniqueId']               = $renewal_order_id . '_' . uniqid();
            $params['paymentOption']['subMethod']   = ['subMethod' => 'ReferenceTransaction'];
        }
        
        $resp = $payment_obj->process($params);
        
        if (empty($resp['status']) || 'success' != strtolower($resp['status'])) {
            Nuvei_Logger::write('Error when try to get Session Token');
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($parent_order);
        }
    }
    
    /**
     * Get and return SimplyConnect data to REST API caller.
     * 
     * @param array $params Expected Cart data.
     * @return array
     */
    public function rest_get_simply_connect_data($params)
    {
        $this->rest_params = $params;
        
        return $this->call_checkout(false, true);
    }
    
    public function rest_get_cashier_link($params)
    {
        // error
        if (empty($params['id']) || empty($params['successUrl']) || empty(['returnUrl'])) {
            $msg = __('Missing incoming parameters.', 'nuvei_checkout_woocommerce');
            Nuvei_Logger::write($params, $msg);
            
            return [
                'code'      => 'missing_parameters',
                'message'   => $msg,
                'data'      => ['status' => 400],
            ];
        }
        
        $order_id   = (int) $params['id'];
        $order      = wc_get_order($order_id);
		
        // error
		if (!$order) {
            $msg = __('Order is false for order id ', 'nuvei_checkout_woocommerce') . $order_id;
			Nuvei_Logger::write($msg);
			
            return [
                'code'      => 'invalid_order',
                'message'   => $msg,
                'data'      => ['status' => 400],
            ];
		}
		
        // error
		if ($order->get_payment_method() != NUVEI_GATEWAY_NAME) {
            $msg = __('Process payment Error - Order payment does not belongs to ', 'nuvei_checkout_woocommerce') 
                . NUVEI_GATEWAY_NAME;
			Nuvei_Logger::write($msg);
			
            return [
                'code'      => 'not_nuvei_order',
                'message'   => $msg,
                'data'      => ['status' => 400],
            ];
		}
        
        $this->order = $order;
        
        $url = $this->generate_cashier_url(
            $params['successUrl'],
            $params['successUrl'], // error and success URLs are same
            $params['backUrl'],
        );

        // error
        if (empty($url)) {
            $msg = __('Error empty Cashier URL.', 'nuvei_checkout_woocommerce');
            Nuvei_Logger::write($msg);
            
            return [
                'code'      => 'empty_cashier_url',
                'message'   => $msg,
                'data'      => ['status' => 400],
            ];
        }
        
        // success
        return ['url' => $url];
    }
    
	/**
	 * Get a plugin setting by its key.
	 * If key does not exists, return default value.
	 * 
	 * @param string    $key - the key we are search for
	 * @param mixed     $default - the default value if no setting found
	 */
	private function get_setting( $key, $default = 0)
    {
		if (isset($this->settings[$key])) {
			return $this->settings[$key];
		}
		
		return $default;
	}
	
    /**
     * @global type $woocommerce
     * 
     * @param string    $success_url
     * @param string    $error_url
     * @param string    $back_url It is only passed in REST API flow.
     * @param int       $order_id
     * @param float     $total Order total amount.
     * @param array     $order_data Pass some additional data in REST API flow.
     * 
     * @return string
     */
//	private function generate_cashier_url( $success_url, $error_url, $order_id, $total, $order_data = [])
	private function generate_cashier_url($success_url, $error_url, $back_url = '')
    {
//        Nuvei_Logger::write($order_data, 'get_cashier_url() $order_data.');
        
//		global $woocommerce;
		
//		$cart          = $woocommerce->cart;
		$nuvei_helper  = new Nuvei_Helper();
//		$total_amount  = (string) number_format((float) $cart->total, 2, '.', '');
//        $addresses     = $nuvei_helper->get_addresses($order_data);
        $addresses     = $nuvei_helper->get_addresses(['billing_address' => $this->order->get_address()]);
//		$total_amount  = (string) number_format((float) $total, 2, '.', '');
		$total_amount  = (string) number_format((float) $this->order->get_total(), 2, '.', '');
		$shipping      = '0.00';
		$handling      = '0.00'; // put the tax here, because for Cashier the tax is in %
		$discount      = '0.00';
        
        // REST API flow
//        if (isset($order_data['items'])) {
//            $products_data = $order_data['items'];
//        }
//        // standart flow
//        else {
//            $products_data = $nuvei_helper->get_products();
//        }
        
        $items_data['items'] = [];
        
        foreach ($this->order->get_items() as $item) {
            $items_data['items'][] = $item->get_data();
        }
        
        Nuvei_Logger::write($items_data, 'get_cashier_url() $items_data.');
        
        $products_data = $nuvei_helper->get_products($items_data);
        
        // check for the totals, when want Cashier URL totals is 0.
        if (empty($products_data['totals'])) {
            $products_data['totals'] = $total_amount;
        }
		
		Nuvei_Logger::write($products_data, 'get_cashier_url() $products_data.');
		
		$params = array(
			'merchant_id'           => trim($this->settings['merchantId']),
			'merchant_site_id'      => trim($this->settings['merchantSiteId']),
//            'merchant_unique_id'    => $order_id,
            'merchant_unique_id'    => $this->order->get_id(),
			'version'               => '4.0.0',
            'time_stamp'            => gmdate('Y-m-d H:i:s'),
			
			'first_name'        => urldecode($addresses['billingAddress']['firstName']),
			'last_name'         => $addresses['billingAddress']['lastName'],
			'email'             => $addresses['billingAddress']['email'],
			'country'           => $addresses['billingAddress']['country'],
            'state'             => $addresses['billingAddress']['state'],
			'city'              => $addresses['billingAddress']['city'],
			'zip'               => $addresses['billingAddress']['zip'],
			'address1'          => $addresses['billingAddress']['address'],
			'phone1'            => $addresses['billingAddress']['phone'],
			'merchantLocale'    => get_locale(),
			
			'notify_url'        => Nuvei_String::get_notify_url($this->settings),
			'success_url'       => $success_url,
			'error_url'         => $error_url,
			'pending_url'       => $success_url,
			'back_url'          => !empty($back_url) ? $back_url : wc_get_checkout_url(),
			
			'customField1'      => '', // subscription details as json
//			'customField2'      => json_encode($products_data['products_data']), // item details as json
			'customField3'      => time(), // create time time()
			
			'currency'          => get_woocommerce_currency(),
			'total_tax'         => 0,
			'total_amount'      => $total_amount,
            'encoding'          => 'UTF-8'
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
		}
        else { // add all the items
			$cnt                        = 1;
			$contol_amount              = 0;
            $params['numberofitems']    = 0;

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
			trim($this->settings['secret']) . implode('', $params)
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
	private function init_form_base_fields()
    {
		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'nuvei_checkout_woocommerce'),
				'type' => 'checkbox',
				'label' => __('Enable Nuvei Checkout Plugin.', 'nuvei_checkout_woocommerce'),
				'default' => 'no'
			),
		   'title' => array(
				'title'         => __('Default title', 'nuvei_checkout_woocommerce'),
				'type'          => 'text',
				'description'   => __('This is the payment method which the user sees during checkout.', 'nuvei_checkout_woocommerce'),
				'default'       => __('Secure Payments with Nuvei', 'nuvei_checkout_woocommerce')
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
				'title'     => __('Payment action', 'nuvei_checkout_woocommerce') . ' *',
				'type'      => 'select',
				'required'  => true,
				'options'   => array(
					'' => __('Select an option...'),
					'Sale' => 'Authorize and Capture',
					'Auth' => 'Authorize',
				),
				'class'     => 'nuvei_checkout_setting',
				'description'   => __('This option is for Nuvei Checkout SDK.', 'nuvei_checkout_woocommerce'),
			),
			'save_logs' => array(
				'title' => __('Save daily logs', 'nuvei_checkout_woocommerce'),
				'type' => 'checkbox',
				'label' => __('Create and save daily log files. This can help for debugging and catching bugs.', 'nuvei_checkout_woocommerce'),
				'default' => 'yes'
			),
			'save_single_log' => array(
				'title' => __('Save single log file.', 'nuvei_checkout_woocommerce'),
				'type' => 'checkbox',
				'label' => __('Create and save the logs into single file.', 'nuvei_checkout_woocommerce'),
				'default' => 'no'
			),
			'disable_wcs_alert' => array(
				'title' => __('Hide WCS warining', 'nuvei_checkout_woocommerce'),
				'type' => 'checkbox',
				'label' => __('Check it to hide WCS waringn permanent.', 'nuvei_checkout_woocommerce'),
				'default' => 'no'
			),
		);
	}
	
	/**
	 * Instead of override init_form_fields() split the settings in three
	 * groups and put them in different tabs.
	 * 
	 * @param bool $fields_append - use it when load the fields. In this case we want all fields in same array.
	 */
	private function init_form_advanced_fields($fields_append = false)
    {
		$fields = array(
            'integration_type' => array(
				'title'         => __('Integration type', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'options'       => array(
					'sdk'       => __('Checkout SDK', 'nuvei_checkout_woocommerce'),
					'cashier'   => __('Payment page - Cashier', 'nuvei_checkout_woocommerce'),
				),
				'default'       => 0,
			),
			'combine_cashier_products' => array(
				'title'         => __('Combine Cashier products into one', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'description'   => __('Cobine the products into one, to avoid eventual problems with, taxes, discounts, coupons, etc.', 'nuvei_checkout_woocommerce'),
				'default'       => 1,
				'options'       => array(
					1 => __('Yes', 'nuvei_checkout_woocommerce'),
					0 => __('No', 'nuvei_checkout_woocommerce'),
				),
				'class'         => 'nuvei_cashier_setting'
			),
            'use_upos' => array(
				'title'         => __('Allow client to use UPOs', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'options'       => array(
					0 => 'No',
					1 => 'Yes',
				),
				'default'       => 0,
				'class'         => 'nuvei_checkout_setting',
                'description'   => __('Logged users will see their UPOs, and will have option to save UPOs.', 'nuvei_checkout_woocommerce'),
			),
            'save_guest_upos' => [
                'title'         => __('Save UPOs for Guest users', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'options'       => array(
					0 => 'No',
					1 => 'Yes',
				),
				'default'       => 0,
				'class'         => 'nuvei_checkout_setting',
                'description'   => __('The UPO will be save only when the Guest user buy Subscription product.', 'nuvei_checkout_woocommerce'),
            ],
            'allow_paypal_rebilling' => array(
				'title'         => __('Allow rebilling with PayPal', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'options'       => array(
					0 => 'No',
					1 => 'Yes',
				),
				'default'       => 0,
				'class'         => 'nuvei_checkout_setting',
                'description'   => __('PayPal is available only for WCS. Using PayPal for rebilling will disable the UPOs.', 'nuvei_checkout_woocommerce'),
			),
            'sdk_theme' => [
                'title'     => __('SimplyConnect theme', 'nuvei_checkout_woocommerce'),
                'type'      => 'select',
                'options'   => array(
					'accordion'     => __('Accordion', 'nuvei_checkout_woocommerce'),
					'tiles'         => __('Tiles', 'nuvei_checkout_woocommerce'),
					'horizontal'    => __('Horizontal', 'nuvei_checkout_woocommerce'),
				),
                'default'   => 'accordion',
                'class'     => 'nuvei_checkout_setting',
            ],
			'use_dcc' => array(
				'title'         => __('Use currency conversion', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'options'       => array(
					'enable'        => __('Enabled', 'nuvei_checkout_woocommerce'),
					'force'         => __('Enabled and expanded', 'nuvei_checkout_woocommerce'),
					'false'         => __('Disabled', 'nuvei_checkout_woocommerce'),
				),
				'description'   => sprintf(
					'<a href="%s" class="class" target="_blank">%s</a>',
					esc_html('https://docs.nuvei.com/documentation/accept-payment/simply-connect/payment-customization/#dynamic-currency-conversion'),
					__('Check the Documentation.', 'nuvei_checkout_woocommerce')
				),
				'default'       => 'enabled',
				'class'         => 'nuvei_checkout_setting'
			),
			'blocked_cards' => array(
				'title'         => __('Block Cards', 'nuvei_checkout_woocommerce'),
				'type'          => 'text',
				'description'   => sprintf(
                    ' <a href="%s" class="class" target="_blank">%s</a>',
					esc_html('https://docs.nuvei.com/documentation/accept-payment/checkout-2/payment-customization/#card-processing'),
					__('Check the Documentation.', 'nuvei_checkout_woocommerce')
				),
				'class'         => 'nuvei_checkout_setting',
			),
			'pm_black_list' => array(
				'type' => 'nuvei_multiselect',
			),
			'pay_button' => array(
				'title'         => __('Choose the Text on the Pay button', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'options'       => array(
					'amountButton'  => __('Shows the amount', 'nuvei_checkout_woocommerce'),
					'textButton'    => __('Shows the payment method', 'nuvei_checkout_woocommerce'),
				),
				'default'       => 'amountButton',
				'class'         => 'nuvei_checkout_setting'
			),
			'auto_open_pm' => array(
				'title'         => __('Auto expand PMs', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'options'       => array(
					1   => __('Yes', 'nuvei_checkout_woocommerce'),
					0   => __('No', 'nuvei_checkout_woocommerce'),
				),
				'default'       => 1,
				'class'         => 'nuvei_checkout_setting'
			),
            'close_popup' => [
                'title'         => __('Auto close APM popup.', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'options'       => array(
					1   => __('Yes (Recommended)', 'nuvei_checkout_woocommerce'),
					0   => __('No', 'nuvei_checkout_woocommerce'),
				),
				'default'       => 1,
				'class'         => 'nuvei_checkout_setting'
            ],
			'log_level' => array(
				'title'         => __('Checkout Log level', 'nuvei_checkout_woocommerce'),
				'type'          => 'select',
				'options'       => array(
					0 => 0,
					1 => 1,
					2 => 2,
					3 => 3,
					4 => 4,
					5 => 5,
					6 => 6,
				),
				'default'       => 0,
				'description'   => '0 ' . __('for "No logging".', 'nuvei_checkout_woocommerce'),
				'class'         => 'nuvei_checkout_setting'
			),
			'translation' => array(
				'title'         => __('Translations', 'nuvei_checkout_woocommerce'),
				'description'   => sprintf(
					__('This filed is the only way to translate Checkout SDK strings. Put the translations for all desired languages as shown in the placeholder. For examples', 'nuvei_checkout_woocommerce')
						. ' <a href="%s" class="class">%s</a>',
					esc_html('https://docs.nuvei.com/documentation/accept-payment/simply-connect/ui-customization/#text-and-translation'),
					__('check the Documentation.', 'nuvei_checkout_woocommerce')
				),
				'type'          => 'textarea',
				'class'         => 'nuvei_checkout_setting',
				'placeholder'   => '{
    "de": { 
        "doNotHonor": "you dont have enough money",
        "DECLINE": "declined"
    },
    "es": { 
        "doNotHonor": "you dont have enough money",
        "DECLINE": "declined"
    }
}',
			),
		);
		  
		if ($fields_append) {
			$this->form_fields = array_merge($this->form_fields, $fields);
		} else {
			$this->form_fields = $fields;
		}
	}
	
	/**
	 * Instead of override init_form_fields() split the settings in three
	 * groups and put them in different tabs.
	 * 
	 * @param bool $fields_append - use it when load the fields. In this case we want all fields in same array.
	 */
	private function init_form_tools_fields( $fields_append = false)
    {
		$fields = array(
			'get_plans_btn' => array(
				'title' => __('Sync Payment Plans', 'nuvei_checkout_woocommerce'),
				'type'  => 'payment_plans_btn',
			),
			'notify_url' => array(
				'title'         => __('Notify URL', 'nuvei_checkout_woocommerce'),
				'type'          => 'hidden',
				'description'   => Nuvei_String::get_notify_url($this->settings, true),
			),
		);
		
		if ($fields_append) {
			$this->form_fields = array_merge($this->form_fields, $fields);
		}
        else {
			$this->form_fields = $fields;
		}
	}
	
}
