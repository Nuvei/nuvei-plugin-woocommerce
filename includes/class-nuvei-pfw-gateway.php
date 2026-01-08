<?php

defined( 'ABSPATH' ) || exit;

/**
 * Main class for the Nuvei Plugin
 */
class Nuvei_Pfw_Gateway extends WC_Payment_Gateway {


	protected $msg = array();
	protected $method_name;

	private $plugin_data  = array();
	private $subscr_units = array( 'year', 'month', 'day' );
	private $rest_params  = array(); // Cart data passed from REST API call.
	private $order; // get the Order in process_payment()

	public function __construct() {
		// settings to get/save options
		$this->id                 = NUVEI_PFW_GATEWAY_NAME;
		$this->icon               = plugin_dir_url( NUVEI_PFW_PLUGIN_FILE ) . 'assets/icons/nuvei.png';
		$this->method_title       = __( 'Nuvei Checkout', 'nuvei-payments-for-woocommerce' );
		$this->method_description = __( 'Pay with ', 'nuvei-payments-for-woocommerce' )
			. NUVEI_PFW_GATEWAY_TITLE . '.';
		$this->method_name        = NUVEI_PFW_GATEWAY_TITLE;
		$this->icon               = plugin_dir_url( NUVEI_PFW_PLUGIN_FILE ) . 'assets/icons/nuvei.png';
		$this->has_fields         = false;

		$this->init_settings();
		// the three settings tabs
		$this->init_form_base_fields();
		$this->init_form_advanced_fields( true );
		$this->init_form_tools_fields( true );

		// required for the Store
		$this->title       = $this->get_option( 'title', NUVEI_PFW_GATEWAY_TITLE );
        
		$this->description = wp_kses_post('<div id="nuvei_checkout_container" data-placeholder="' . 
            ( 'cashier' === $this->get_option( 'integration_type' ) ? 
                __('You will be redirected to Nuvei secure payment page.', 'nuvei-payments-for-woocommerce') :
                    __('The Checkout form must be valid to continue!', 'nuvei-payments-for-woocommerce')
            )
             . '"></div>');
		
        $this->plugin_data = get_plugin_data( NUVEI_PFW_PLUGIN_FILE );

		// $this->use_wpml_thanks_page = !empty($this->settings['use_wpml_thanks_page'])
		// ? $this->settings['use_wpml_thanks_page'] : 'no';

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
// 			array( $this, 'process_admin_options' )
			array( $this, 'validate_settings' )
		);

		/**
		 * TODO - do we still use this hook?
		 */
		add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'return_settle_btn' ), 10, 2 );

		add_action( 'woocommerce_order_status_refunded', array( $this, 'restock_on_refunded_status' ), 10, 1 );
	}

	public function is_available() {
		return parent::is_available();
	}
    
    /**
     * Get the badge "Action needed". It is available only when the plugin is not enabled.
     */
	public function needs_setup() {
	    return empty($this->get_option['test']) 
            || empty($this->get_option['merchantId'])
            || empty($this->get_option['merchantSiteId'])
            || empty($this->get_option['secret'])
            || empty($this->get_option['hash_type'])
            || empty($this->get_option['payment_action'])
        ;
	}
    
	/**
	 * A method to check if the plugin is in test mode.
	 * 
	 * @return boolean
	 */
    public function is_in_test_mode() {
        return $this->get_option('test') === 'yes';
    }
    
    /**
     * Save the settings and validate them.
     * 
     * @return boolean
     */
    public function validate_settings() {
        static $notice_shown = false;
        
        // first save the settings
        parent::process_admin_options();
        // then load them
        $this->init_settings();
        
        $title = '';
        
        foreach ($this->form_fields as $field => $data) {
            if ( !empty($data['required']) && empty($this->get_option($field, '')) ) {
                $title = isset($data['title']) ? trim($data['title'], ' *') : $field;
                break;
            }
        }
        
        if ( ! empty( $title ) ) {
            // Disable gateway
            $settings               = get_option( "woocommerce_{$this->id}_settings", [] );
            $settings['enabled']    = 'no';
            
            update_option( "woocommerce_{$this->id}_settings", $settings );
            
            // Add admin notice only once
            if ( ! $notice_shown ) {
                $notice_shown = true;
                
                add_action( 'admin_notices', function() use ( $title ) {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html( sprintf( 
                        /* translators: %s: the title of the field */
                        __( 'Please fill the "%s" field to enable the payment gateway.', 'nuvei-payments-for-woocommerce' ), $title ) );
                    echo '</p></div>';
                } );
            }
            
            return false;
        }
        
        return true;
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
	public function generate_payment_plans_btn_html( $key, $data ) {
		$defaults = array(
			'class'             => 'button-secondary',
			'css'               => '',
			'custom_attributes' => array(),
			'desc_tip'          => false,
			'description'       => '',
			'title'             => '',
		);

		$nuvei_plans_path = NUVEI_PFW_LOGS_DIR . NUVEI_PFW_PLANS_FILE;

		if ( is_readable( $nuvei_plans_path ) ) {
			$defaults['description'] = __( 'Last download: ', 'nuvei-payments-for-woocommerce' )
			. gmdate( 'Y-m-d H:i:s', filemtime( $nuvei_plans_path ) );
		}

		ob_start();

		$data = wp_parse_args( $data, $defaults );
		include_once dirname( NUVEI_PFW_PLUGIN_FILE ) . '/templates/admin/download-payments-plans-btn.php';

		return ob_get_clean();
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
	public function generate_payment_custom_msg_html( $key, $data ) {
		$defaults = array(
			'class'             => 'button-secondary',
			'css'               => '',
			'custom_attributes' => array(),
			'desc_tip'          => false,
			'description'       => '',
			'title'             => '',
		);

		ob_start();

		$data = wp_parse_args( $data, $defaults );
		include_once dirname( NUVEI_PFW_PLUGIN_FILE ) . '/templates/admin/load-nuvei-custom-msgs-btn.php';

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
	public function generate_nuvei_multiselect_html( $key, $data ) {
		# prepare the list with Payment methods
		$get_st_obj    = new Nuvei_Pfw_Session_Token();
		$resp          = $get_st_obj->process();
		$session_token = ! empty( $resp['sessionToken'] ) ? $resp['sessionToken'] : '';

		$nuvei_blocked_pms_visible = array();
		$nuvei_blocked_pms         = explode( ',', $this->get_option( 'pm_black_list', '' ) );
		$pms                       = array(
			'' => __( 'Select payment methods...', 'nuvei-payments-for-woocommerce' ),
		);

		$get_apms_obj = new Nuvei_Pfw_Get_Apms( $this->settings );
		$resp         = $get_apms_obj->process( array( 'sessionToken' => $session_token ) );

		if ( ! empty( $resp['paymentMethods'] ) && is_array( $resp['paymentMethods'] ) ) {
			foreach ( $resp['paymentMethods'] as $data ) {
				// the array for the select menu
				if ( ! empty( $data['paymentMethodDisplayName'][0]['message'] ) ) {
					$pms[ $data['paymentMethod'] ] = $data['paymentMethodDisplayName'][0]['message'];
				} else {
					$pms[ $data['paymentMethod'] ] = $data['paymentMethod'];
				}

				// generate visible list
				if ( in_array( $data['paymentMethod'], $nuvei_blocked_pms ) ) {
					$nuvei_blocked_pms_visible[] = $pms[ $data['paymentMethod'] ];
				}
			}
		}
		# prepare the list with Payment methods END

		$defaults = array(
			'title'                     => __( 'Block Payment Methods', 'nuvei-payments-for-woocommerce' ),
			'class'                     => 'nuvei_checkout_setting',
			'css'                       => '',
			'custom_attributes'         => array(),
			'desc_tip'                  => false,
			'merchant_pms'              => $pms,
			'nuvei_blocked_pms'         => $nuvei_blocked_pms,
			'nuvei_blocked_pms_visible' => implode( ', ', $nuvei_blocked_pms_visible ),
		);

		ob_start();

		$data = wp_parse_args( $data, $defaults );
		require_once dirname( NUVEI_PFW_PLUGIN_FILE ) . '/templates/admin/block-pms-select.php';

		return ob_get_clean();
	}

	// Generate the HTML For the settings form.
	public function admin_options() {
		include_once dirname( NUVEI_PFW_PLUGIN_FILE ) . '/templates/admin/settings.php';
	}

	/**
	 *  Add fields on the payment page. Because we get APMs with Ajax
	 * here we add only AMPs fields modal.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		// echo here some html if needed
	}

	/**
	 * Process the payment and return the result. This is the place where site
	 * submit the form and then redirect. Here we will get our custom fields.
	 *
	 * @param  int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$nuvei_order_details = WC()->session->get( NUVEI_PFW_SESSION_PROD_DETAILS );
		$nuvei_oo_details    = WC()->session->get( NUVEI_PFW_SESSION_OO_DETAILS );

		Nuvei_Pfw_Logger::write(
			array(
				'$order_id'                    => $order_id,
				NUVEI_PFW_SESSION_PROD_DETAILS => $nuvei_order_details,
				NUVEI_PFW_SESSION_OO_DETAILS   => $nuvei_oo_details,
			),
			'Process payment(), Order'
		);

		$order = wc_get_order( $order_id );
		$key   = $order->get_order_key();

		// error
		if ( ! $order ) {
			Nuvei_Pfw_Logger::write( 'Order is false for order id ' . $order_id );

			return array(
				'result'   => 'success',
				'redirect' => array(
					'Status' => 'error',
				),
				wc_get_checkout_url() . 'order-received/' . $order_id . '/',
			);
		}

		$return_success_url = add_query_arg(
			array( 'key' => $key ),
			$this->get_return_url( $order )
		);

		$return_error_url = add_query_arg(
			array(
				'Status' => 'error',
				'key'    => $key,
			),
			$this->get_return_url( $order )
		);

		// error
		if ( $order->get_payment_method() != NUVEI_PFW_GATEWAY_NAME ) {
			Nuvei_Pfw_Logger::write( 'Process payment Error - Order payment does not belongs to ' . NUVEI_PFW_GATEWAY_NAME );

			return array(
				'result'   => 'success',
				'redirect' => array(
					'Status' => 'error',
					'key'    => $key,
				),
				wc_get_checkout_url() . 'order-received/' . $order_id . '/',
			);
		}

		// in case we use Cashier
		if ( isset( $this->settings['integration_type'] )
			&& 'cashier' == $this->settings['integration_type']
		) {
			Nuvei_Pfw_Logger::write( 'Process Cashier payment.' );

			$this->order = $order;
			$url         = $this->generate_cashier_url( $return_success_url, $return_error_url );

			if ( ! empty( $url ) ) {
				return array(
					'result'   => 'success',
					'redirect' => add_query_arg( array(), $url ),
				);
			}

			return;
		}
		// /in case we use Cashier

		// search for subscr data
		if ( ! empty( $nuvei_order_details ) && is_array( $nuvei_order_details ) ) {
			$nuvei_session_token = key( $nuvei_order_details );

			// save the Nuvei Subscr data to the order
			if ( ! empty( $nuvei_order_details[ $nuvei_session_token ]['subscr_data'] ) ) {
				foreach ( $nuvei_order_details[ $nuvei_session_token ]['subscr_data'] as $data ) {
					// set meta key
					if ( isset( $data['product_id'] ) ) {
						$meta_key = NUVEI_PFW_ORDER_SUBSCR . '_product_' . $data['product_id'];
					}
					if ( isset( $data['variation_id'] ) ) {
						$meta_key = NUVEI_PFW_ORDER_SUBSCR . '_variation_' . $data['variation_id'];
					}

					$order->update_meta_data( $meta_key, $data );

					Nuvei_Pfw_Logger::write( array( $meta_key, $data ), 'subsc data' );
				}
			}

			// mark order if there is WC Subsc
			if ( ! empty( $nuvei_order_details[ $nuvei_session_token ]['wc_subscr'] ) ) {
				$order->update_meta_data( NUVEI_PFW_WC_SUBSCR, true );

				Nuvei_Pfw_Logger::write( true, 'wc_subscr' );
			}

			WC()->session->set( NUVEI_PFW_SESSION_PROD_DETAILS, array() );
		}

		// Success
        if (!empty($nuvei_oo_details['orderId'])) {
            $order->update_meta_data( NUVEI_PFW_ORDER_ID, $nuvei_oo_details['orderId'] );
        }
        if (!empty($nuvei_oo_details['clientUniqueId'])) {
            $order->update_meta_data( NUVEI_PFW_CLIENT_UNIQUE_ID, $nuvei_oo_details['clientUniqueId'] );
        }
        
		$order->update_status( $this->settings['status_auth'] );
		$order->save();

		// Remove cart.
		WC()->cart->empty_cart();

		// Return thankyou redirect.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Function process_refund
	 * A overwrite original function to enable auto refund in WC.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Refund amount.
	 * @param string $reason   Refund reason.
	 *
	 * @return boolean
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		if ( 'true' == Nuvei_Pfw_Http::get_param( 'api_refund' ) ) {
			return true;
		}

		return false;
	}

	public function return_settle_btn( $and_taxes, $order ) {
		// Nuvei_Pfw_Logger::write('', 'return_settle_btn', "TRACE");

		if ( ! is_a( $order, 'WC_Order' ) || is_a( $order, 'WC_Subscription' ) ) {
			return false;
		}

		if ( ! method_exists( $order, 'get_payment_method' )
			|| empty( $order->get_payment_method() )
			|| ! in_array( $order->get_payment_method(), array( NUVEI_PFW_GATEWAY_NAME, 'sc' ) )
		) {
			return false;
		}

		// revert buttons on Recalculate
		if ( Nuvei_Pfw_Http::get_param( 'refund_amount', 'float', 0, array(), true ) == 0
			&& ! empty( Nuvei_Pfw_Http::get_param( 'items' ) )
		) {
			wp_add_inline_script(
				'nuvei_js_admin',
				'nuveiReturnNuveiBtns();'
			);
		}
	}

	/**
	 * Restock on refund.
	 *
	 * @param  int $order_id
	 * @return void
	 */
	public function restock_on_refunded_status( $order_id ) {
		$order            = wc_get_order( $order_id );
		$items            = $order->get_items();
		$is_order_restock = $order->get_meta( '_scIsRestock' );

		// do restock only once
		if ( 1 !== $is_order_restock ) {
			wc_restock_refunded_items( $order, $items );
			$order->update_meta_data( '_scIsRestock', 1 );
			$order->save();

			Nuvei_Pfw_Logger::write( 'Items were restocked.' );
		}

		return;
	}

	/**
	 * @global $woocommerce $woocommerce
	 *
	 * @deprecated since version 3.1.0
	 */
//	public function reorder() {
//		global $woocommerce;
//
//		$products_ids = json_decode( Nuvei_Pfw_Http::get_param( 'product_ids' ), true );
//
//		if ( empty( $products_ids ) || ! is_array( $products_ids ) ) {
//			wp_send_json(
//				array(
//					'status' => 0,
//					'msg'    => __( 'Problem with the Products IDs.', 'nuvei-payments-for-woocommerce' ),
//				)
//			);
//			exit;
//		}
//
//		$prod_factory  = new WC_Product_Factory();
//		$msg           = '';
//		$is_prod_added = false;
//
//		foreach ( $products_ids as $id ) {
//			$product = $prod_factory->get_product( $id );
//
//			if ( 'in-stock' != $product->get_availability()['class'] ) {
//				$msg = __( 'Some of the Products are not availavle, and are not added in the new Order.', 'nuvei-payments-for-woocommerce' );
//				continue;
//			}
//
//			$is_prod_added = true;
//			$woocommerce->cart->add_to_cart( $id );
//		}
//
//		if ( ! $is_prod_added ) {
//			wp_send_json(
//				array(
//					'status' => 0,
//					'msg'    => 'There are no added Products to the Cart.',
//				)
//			);
//			exit;
//		}
//
//		$cart_url = wc_get_cart_url();
//
//		if ( ! empty( $msg ) ) {
//			$cart_url .= strpos( $cart_url, '?' ) !== false ? '&sc_msg=' : '?sc_msg=';
//			$cart_url .= urlencode( $msg );
//		}
//
//		wp_send_json(
//			array(
//				'status'       => 1,
//				'msg'          => $msg,
//				'redirect_url' => wc_get_cart_url(),
//			)
//		);
//		exit;
//	}

	/**
	 * Download the Active Payment pPlans and save them to a json file.
	 * If there are no Active Plans, create default one with name, based
	 * on MerchatSiteId parameter, and get it.
	 *
	 * @param int $recursions
	 */
	public function download_subscr_pans( $recursions = 0 ) {
		Nuvei_Pfw_Logger::write( 'download_subscr_pans' );

		if ( $recursions > 1 ) {
			wp_send_json( array( 'status' => 0 ) );
			exit;
		}

		$ndp_obj = new Nuvei_Pfw_Download_Plans( $this->settings );
		$resp    = $ndp_obj->process();

		if ( empty( $resp ) || ! is_array( $resp ) || 'SUCCESS' != $resp['status'] ) {
			Nuvei_Pfw_Logger::write( 'Unexpected error, when try to download the plans.' );

			wp_send_json( array( 
                'status'    => 0,
                'message'   => __( 'Unexpected error, when try to download the plans.', 'nuvei-payments-for-woocommerce')
            ) );
			exit;
		}

		// in case there are  no active plans - create default one
		if ( isset( $resp['total'] ) && 0 == $resp['total'] ) {
			$ncp_obj     = new Nuvei_Pfw_Create_Plan();
			$create_resp = $ncp_obj->process( $this->settings );

			if ( ! empty( $create_resp['planId'] ) ) {
				++$recursions;
				$this->download_subscr_pans( $recursions );
				return;
			}
		}

		$wp_fs_direct = new WP_Filesystem_Direct( null );

		if ( $wp_fs_direct->put_contents(
			NUVEI_PFW_LOGS_DIR . NUVEI_PFW_PLANS_FILE,
			wp_json_encode( $resp['plans'] ),
			0644
		) ) {
			$this->create_nuvei_global_attribute();

			wp_send_json(
				array(
					'status' => 1,
					'time'   => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			exit;
		}

		Nuvei_Pfw_Logger::write(
			NUVEI_PFW_LOGS_DIR . NUVEI_PFW_PLANS_FILE,
			'Plans list was not saved.'
		);

		wp_send_json( array( 
            'status'    => 0,
            'message'   => __( 'Unexpected error, when try to save the file with the plans.', 'nuvei-payments-for-woocommerce')
        ) );
		exit;
	}

	public function get_today_log() {
		$log_file = NUVEI_PFW_LOGS_DIR . gmdate( 'Y-m-d' ) . '.' . NUVEI_PFW_LOG_EXT;

		if ( ! file_exists( $log_file ) ) {
			wp_send_json(
				array(
					'status' => 0,
					'msg'    => __( 'The Log file not exists.', 'nuvei-payments-for-woocommerce' ),
				)
			);
			exit;
		}

		if ( ! is_readable( $log_file ) ) {
			wp_send_json(
				array(
					'status' => 0,
					'msg'    => __( 'The Log file is not readable.', 'nuvei-payments-for-woocommerce' ),
				)
			);
			exit;
		}

		$wp_fs_direct = new WP_Filesystem_Direct( null );

		wp_send_json(
			array(
				'status' => 1,
				'data'   => $wp_fs_direct->get_contents( $log_file ),
			)
		);
		exit;
	}

	public function get_subscr_fields() {
		return $this->subscr_fields;
	}

	public function get_subscr_units() {
		return $this->subscr_units;
	}

	public function can_use_upos() {
		if ( isset( $this->settings['use_upos'] ) ) {
			return $this->settings['use_upos'];
		}

		return 0;
	}

	public function create_nuvei_global_attribute() {
		Nuvei_Pfw_Logger::write( 'create_nuvei_global_attribute()' );

		$nuvei_plans_path          = NUVEI_PFW_LOGS_DIR . NUVEI_PFW_PLANS_FILE;
		$nuvei_glob_attr_name_slug = Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME );
		$taxonomy_name             = wc_attribute_taxonomy_name( $nuvei_glob_attr_name_slug );

		// a check
		if ( ! is_readable( $nuvei_plans_path ) ) {
			Nuvei_Pfw_Logger::write( 'Plans json is not readable.' );

			wp_send_json(
				array(
					'status' => 0,
					'msg'    => __( 'Plans json is not readable.', 'nuvei-payments-for-woocommerce' ),
				)
			);
			exit;
		}

		$plans = wp_json_file_decode(
			$nuvei_plans_path,
			array( 'associative' => true )
		);

		// a check
		if ( empty( $plans ) || ! is_array( $plans ) ) {
			Nuvei_Pfw_Logger::write( $plans, 'Unexpected problem with the Plans list.', 'nuvei-payments-for-woocommerce' );

			wp_send_json(
				array(
					'status' => 0,
					'msg'    => __( 'Unexpected problem with the Plans list.', 'nuvei-payments-for-woocommerce' ),
				)
			);
			exit;
		}

		// check if Taxonomy exists
		if ( taxonomy_exists( $taxonomy_name ) ) {
			Nuvei_Pfw_Logger::write( '$taxonomy_name exists' );
			return;
		}

		// create the Global Attribute
		$args = array(
			'name'         => NUVEI_PFW_GLOB_ATTR_NAME,
			'slug'         => $nuvei_glob_attr_name_slug,
			'order_by'     => 'menu_order',
			'has_archives' => true,
		);

		// create the attribute and check for errors
		$attribute_id = wc_create_attribute( $args );

		if ( is_wp_error( $attribute_id ) ) {
			Nuvei_Pfw_Logger::write(
				array(
					'$args'   => $args,
					'message' => $attribute_id->get_error_message(),
				),
				'Error when try to add Global Attribute with arguments'
			);

			wp_send_json(
				array(
					'status' => 0,
					'msg'    => $attribute_id->get_error_message(),
				)
			);
			exit;
		}

		// craete WP taxonomy based on the WC attribute
		register_taxonomy(
			$taxonomy_name,
			array( 'product' ),
			array(
				'public' => false,
			)
		);
	}

	/**
	 * Decide to add or not a product to the card.
	 *
	 * @param bool $true
	 * @param int  $product_id
	 * @param int  $quantity
	 *
	 * @return bool
	 */
	public function add_to_cart_validation( $true, $product_id, $quantity ) {
		Nuvei_Pfw_Logger::write( is_user_logged_in(), 'add_to_cart_validation' );

		global $woocommerce;

		$cart       = $woocommerce->cart;
		$product    = wc_get_product( $product_id );
		$attributes = $product->get_attributes();

		// for guests disable adding products with Nuvei Payment plan or WCS to the Cart
		if ( ! is_user_logged_in()
			&& 0 == $this->get_option( 'save_guest_upos' )
		) {
			if ( ! empty( $attributes[ 'pa_' . Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME ) ] ) ) {
				wc_add_notice(
					__( 'Please create an account or login to subscribe.', 'nuvei-payments-for-woocommerce' ),
					'error'
				);

				return false;
			}

			if ( false !== strpos( $product->get_type(), 'subscription' ) ) {
				wc_add_notice(
					__( 'Please create an account or login to subscribe.', 'nuvei-payments-for-woocommerce' ),
					'error'
				);

				return false;
			}
		}

		// for guests disable adding products with Nuvei Payment plan to the Cart
		// if (!empty($attributes['pa_' . Nuvei_Pfw_String::get_slug(NUVEI_PFW_GLOB_ATTR_NAME)])
		// && !is_user_logged_in()
		// ) {
		// wc_add_notice(
		// __('You must login to add a product with a Payment Plan.', 'nuvei-payments-for-woocommerce'),
		// 'error'
		// );
		//
		// return false;
		// }

		return true;
	}

	/**
	 * Call the Nuvei Checkout SDK form here and pass all parameters.
	 *
	 * @global $woocommerce
	 *
	 * @param bool $is_rest     Is the method called from the REST API?
	 * @param bool $return_data Pass true when need the method to return the data. We need it when page use WC Blocks and when the client will pay for an order created from the admin.
	 * @param int  $order_id    We will pass the Order ID when will pay an order created from the admin.
	 *
	 * @return array|void       Return array with SDK params or echo same params as JSON.
	 */
	public function call_checkout( $is_rest = false, $return_data = false, $order_id = null ) {
		Nuvei_Pfw_Logger::write(
			array(
				'$is_rest'     => $is_rest,
				'$return_data' => $return_data,
				'rest_params'  => $this->rest_params,
			),
			'call_checkout()'
		);

		global $woocommerce;

		// OpenOrder::START
		$oo_obj  = new Nuvei_Pfw_Open_Order( $this->settings, $this->rest_params );
		$oo_data = $oo_obj->process( array( 'order_id' => $order_id ) );

		if ( ! $oo_data || empty( $oo_data['sessionToken'] ) ) {
			$msg = __( 'Unexpected error, please try again later!', 'nuvei-payments-for-woocommerce' );

			if ( ! empty( $oo_data['message'] ) ) {
				$msg = $oo_data['message'];
			}

			Nuvei_Pfw_Logger::write( $msg );

			if ( ! empty( $oo_data['custom_msg'] ) ) {
				$msg = $oo_data['custom_msg'];
			}

			if ( $return_data ) {
				return array(
					'messages' => $msg,
					'status'   => 'error',
				);
			}

			wp_send_json(
				array(
					'result'   => 'failure',
					'refresh'  => false,
					'reload'   => false,
					'messages' => '<ul id="sc_fake_error" class="woocommerce-error" role="alert"><li>' . $msg . '</li></ul>',
				)
			);

			exit;
		}
		// OpenOrder::END

		$nuvei_helper          = new Nuvei_Pfw_Helper();
		$ord_details           = $nuvei_helper->get_addresses( $this->rest_params );
		$prod_details          = $oo_data['products_data'];
		$pm_black_list         = trim( (string) $this->get_option( 'pm_black_list', '' ) );
		$is_there_subscription = false;
		$locale                = substr( get_locale(), 0, 2 );
		$total                 = $oo_data['amount'];

		if ( ! empty( $pm_black_list ) ) {
			$pm_black_list = explode( ',', $pm_black_list );
		}

		// for UPO
		$use_upos = (bool) $this->get_option( 'use_upos' );
		$save_pm  = $use_upos;

		Nuvei_Pfw_Logger::write( $prod_details );

		if ( ! is_user_logged_in()
			|| ( $is_rest && empty( $this->rest_params['isUserLogged'] ) )
		) {
			$use_upos = false;
			$save_pm  = false;
		}

		if ( ! empty( $prod_details['wc_subscr'] ) || ! empty( $prod_details['subscr_data'] ) ) {
			$save_pm               = 'always';
			$is_there_subscription = true;
		}

		$use_dcc = $this->get_option( 'use_dcc', 'enable' );

		if ( 0 == $total ) {
			$use_dcc = 'false';
		}
        
        // add GooglePay settings
        $google_pay_settings = array(
            'locale' => $locale,
        );
        
        if (!empty($g_merchat_id = $this->get_option( 'gpay_merchantId' ))) {
            $google_pay_settings['merchantId'] = $g_merchat_id;
        }
        if (!empty($g_button_color = $this->get_option( 'gpay_buttonColor' ))) {
            $google_pay_settings['buttonColor'] = $g_button_color;
        }
        if (!empty($g_button_type = $this->get_option( 'gpay_buttonType' ))) {
            $google_pay_settings['buttonType'] = $g_button_type;
        }
        
		$checkout_data = array( // use it in the template
			'sessionToken'           => $oo_data['sessionToken'],
			'env'                    => 'yes' == $this->get_option( 'test' ) ? 'test' : 'prod',
			'merchantId'             => $this->get_option( 'merchantId' ),
			'merchantSiteId'         => $this->get_option( 'merchantSiteId' ),
			'country'                => $ord_details['billingAddress']['country'],
			'currency'               => get_woocommerce_currency(),
			'amount'                 => $total,
			'renderTo'               => '#nuvei_checkout_container',
			'useDCC'                 => $use_dcc,
			'strict'                 => false,
			'savePM'                 => $save_pm,
			'showUserPaymentOptions' => $use_upos,
			'pmWhitelist'            => null,
			'pmBlacklist'            => empty( $pm_black_list ) ? null : $pm_black_list,
			'alwaysCollectCvv'       => true,
			'fullName'               => $ord_details['billingAddress']['firstName'] . ' '
                . $oo_data['billingAddress']['lastName'],
			'email'                  => $ord_details['billingAddress']['email'],
			'payButton'              => $this->get_option( 'pay_button', 'amountButton' ),
			'showResponseMessage'    => false, // shows/hide the response popups
			'locale'                 => $locale,
			'autoOpenPM'             => (bool) $this->get_option( 'auto_open_pm', 1 ),
			'logLevel'               => $this->get_option( 'log_level' ),
			'maskCvv'                => true,
			'i18n'                   => json_decode( $this->get_option( 'translation', '' ), true ),
			'theme'                  => $this->get_option( 'sdk_theme', 'accordion' ),
			'apmConfig'              => array(
				'googlePay' => $google_pay_settings,
				'applePay'  => array(
					'locale'    => $locale,
				),
			),
			'sourceApplication'		=> NUVEI_PFW_SOURCE_APPLICATION,
			'fieldStyle'			=> json_decode( $this->get_option( 'simply_connect_style', '' ), true ),
		);
        
		// For the QA site only
		if ( $this->is_qa_site() ) {
			$checkout_data['webSdkEnv'] = 'devmobile';
		}

		// check for product with a plan
		if ( $is_there_subscription ) {
			$checkout_data['pmWhitelist'] = array( 'cc_card' );

			// only for WCS
			if ( 1 == $this->get_option( 'allow_paypal_rebilling', 0 )
				&& ! empty( $prod_details['wc_subscr'] )
			) {
				$checkout_data['pmWhitelist'][]          = 'apmgw_expresscheckout';
				$checkout_data['showUserPaymentOptions'] = false;
			}

			unset( $checkout_data['pmBlacklist'] );
		} elseif ( 0 == $total && 1 == $this->get_option( 'allow_zero_checkout' ) ) { // in case of Zero-Total and enabled allow_zero_checkout option
			$checkout_data['pmWhitelist'] = array( 'cc_card' );
			unset( $checkout_data['pmBlacklist'] );
		}

		// blocked_cards
		$blocked_cards     = array();
		$blocked_cards_str = $this->get_option( 'blocked_cards', '' );
		// clean the string from brakets and quotes
		$blocked_cards_str = str_replace( '],[', ';', $blocked_cards_str );
		$blocked_cards_str = str_replace( '[', '', $blocked_cards_str );
		$blocked_cards_str = str_replace( ']', '', $blocked_cards_str );
		$blocked_cards_str = str_replace( '"', '', $blocked_cards_str );
		$blocked_cards_str = str_replace( "'", '', $blocked_cards_str );

		if ( empty( $blocked_cards_str ) ) {
			$checkout_data['blockCards'] = array();
		} else {
			$block_cards_sets = explode( ';', $blocked_cards_str );

			if ( count( $block_cards_sets ) == 1 ) {
				$blocked_cards = explode( ',', current( $block_cards_sets ) );
			} else {
				foreach ( $block_cards_sets as $elements ) {
					$blocked_cards[] = explode( ',', $elements );
				}
			}

			$checkout_data['blockCards'] = $blocked_cards;
		}
		// blocked_cards END

		$resp_data['nuveiPluginUrl'] = plugin_dir_url( NUVEI_PFW_PLUGIN_FILE );
		$resp_data['nuveiSiteUrl']   = get_site_url();

		// REST API call
		if ( ! empty( $this->rest_params ) ) {
			$checkout_data['transactionType'] = $oo_data['transactionType'];
			$checkout_data['orderId']         = $oo_data['orderId'];
			$checkout_data['products_data']   = $prod_details;

			Nuvei_Pfw_Logger::write( $checkout_data, 'REST API CALL $checkout_data' );

			return $checkout_data;
		}

		Nuvei_Pfw_Logger::write( $checkout_data, '$checkout_data' );

		// For blocks checkout, get the data when register Nuvei gateway.
		if ( $return_data ) {
			return $checkout_data;
		}

		wp_send_json(
			array(
				'result'      => 'failure', // this is just to stop WC send the form, and show APMs
				'refresh'     => false,
				'reload'      => false,
				'nuveiParams' => $checkout_data,
			)
		);

		exit;
	}

	public function checkout_prepayment_check() {
		Nuvei_Pfw_Logger::write( 'checkout_prepayment_check()' );

		global $woocommerce;

		$nuvei_helper        = new Nuvei_Pfw_Helper();
		$nuvei_order_details = $woocommerce->session->get( NUVEI_PFW_SESSION_PROD_DETAILS );
		$open_order_details  = $woocommerce->session->get( NUVEI_PFW_SESSION_OO_DETAILS );
		$products_data       = $nuvei_helper->get_products();

		// success
		if ( ! empty( $open_order_details['sessionToken'] )
			&& ! empty( $nuvei_order_details[ $open_order_details['sessionToken'] ]['products_data_hash'] )
			&& md5( serialize( $products_data ) ) == $nuvei_order_details[ $open_order_details['sessionToken'] ]['products_data_hash']
		) {
			wp_send_json(
				array(
					'success' => 1,
				)
			);

			exit;
		}

		Nuvei_Pfw_Logger::write(
			array(
				'$nuvei_order_details' => $nuvei_order_details,
				'$open_order_details'  => $open_order_details,
				'$products_data'       => $products_data,
			)
		);

		wp_send_json(
			array(
				'success' => 0,
			)
		);

		exit;
	}

	/**
	 * Filter available gateways in some cases.
	 * When WC Blocks is used sometimes is_checkout() returns false.
	 *
	 * @param  array $available_gateways
	 * @return array
	 */
	public function hide_payment_gateways( $available_gateways ) {
		global $woocommerce;

		// we expect this method to be used on the Store only
		if ( is_admin()
			|| ! isset( $woocommerce->cart )
			|| empty( $woocommerce->cart->get_cart() )
		) {
			return $available_gateways;
		}

		Nuvei_Pfw_Logger::write(
			array(
				'$available_gateways'  => array_keys( $available_gateways ),
				'is_admin'             => is_admin(),
				'is_checkout'          => is_checkout(),
				'is_checkout_pay_page' => is_checkout_pay_page(),
				'is_wc_endpoint_url'   => is_wc_endpoint_url(),
				// 'is_shop()' => is_shop(),
				// 'isset(WC()->session)'  => isset(WC()->session),
					'isset(WC cart)'   => isset( $woocommerce->cart ),
				'items'                => isset( $woocommerce->cart ) ? $woocommerce->cart->get_cart() : null,
			// 'SCRIPT_FILENAME'       => $_SERVER['SCRIPT_FILENAME'],
			// 'checkout_id ' => WC()->session->get('checkout_id'),
			// 'get checkoutid ' => @$_GET['checkoutid'],
			),
			'hide_payment_gateways'
		);

		// if ( ! is_checkout() || is_wc_endpoint_url() ) {
		// Nuvei_Pfw_Logger::write([is_checkout(), is_wc_endpoint_url()]);
		// return $available_gateways;
		// }

		if ( ! isset( $available_gateways[ NUVEI_PFW_GATEWAY_NAME ] ) ) {
			Nuvei_Pfw_Logger::write( 'missing Nuvei GW' );
			return $available_gateways;
		}

		$nuvei_helper                          = new Nuvei_Pfw_Helper();
		$items_info                            = $nuvei_helper->get_products();
		$filtred_gws[ NUVEI_PFW_GATEWAY_NAME ] = $available_gateways[ NUVEI_PFW_GATEWAY_NAME ];

		if ( ! empty( $items_info['subscr_data'] ) ) {
			return $filtred_gws;
		} elseif ( isset( $items_info['totals']['total'] )
			&& ( 1 == $this->get_option( 'allow_zero_checkout' )
			&& 0 == $items_info['totals']['total'] )
		) {
			return $filtred_gws;
		}

		return $available_gateways;
	}

	/**
	 * Call this function form a hook to process an WC Subscription Order.
	 *
	 * @param float    $amount_to_charge
	 * @param WC_Order $renewal_order    The new Order.
	 */
	public function create_wc_subscr_order( $amount_to_charge, $renewal_order ) {
		$renewal_order_id = $renewal_order->get_id();
		$order_all_meta   = $renewal_order->get_meta_data();
		$subscription_id  = $renewal_order->get_meta( '_subscription_renewal', true );;
        // we need them for the payment.do request
		$billing_mail     = $renewal_order->get_billing_email();
		$billing_country  = $renewal_order->get_billing_country();
        
        Nuvei_Pfw_Logger::write(
			[
                '$subscription_id'  => $subscription_id,
                '$billing_mail'     => $billing_mail,
                '$billing_country'  => $billing_country,
            ],
			'create_wc_subscr_order',
            'TRACE'
		);

		// $subscription   = wc_get_order( $renewal_order->get_meta( '_subscription_renewal' ) );
		$subscription = wc_get_order( $subscription_id );
		$helper       = new Nuvei_Pfw_Helper();

        // error
		if ( ! is_object( $subscription ) ) {
			Nuvei_Pfw_Logger::write(
				array(
					'$amount_to_charge' => $amount_to_charge,
					'$renewal_order'    => (array) $renewal_order,
					// '$request'          => $helper->helper_sanitize_assoc_array(),
					'$subscription'     => $subscription,
					'$renewal_order_id' => $renewal_order_id,
					'get_post_meta'     => $renewal_order->get_meta_data(),
				),
				'Error, the Subscription is not an object.'
			);
			return;
		}

		$parent_order_id = $subscription->get_parent_id();
		$parent_order    = wc_get_order( $parent_order_id );
		// we get those from Nuvei metadata, if exists
		$parent_tr_id     = $helper->helper_get_tr_id( $parent_order_id );
		$parent_tr_upo_id = $helper->get_tr_upo_id( $parent_order_id );

		Nuvei_Pfw_Logger::write(
			array(
				'$renewal_order_id' => $renewal_order_id,
				'$parent_order_id'  => $parent_order_id,
				'$parent_tr_id'     => $parent_tr_id,
				'$parent_tr_upo_id' => $parent_tr_upo_id,
			),
			'create_wc_subscr_order',
            'TRACE'
		);

        // error
		if ( empty( $parent_tr_upo_id ) || empty( $parent_tr_id ) ) {
			Nuvei_Pfw_Logger::write(
				$parent_order->get_meta_data(),
				'Error - missing mandatory Parent order data.'
			);

			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $parent_order );
			return;
		}

		// get Session Token
		$st_obj  = new Nuvei_Pfw_Session_Token( $this->settings );
		$st_resp = $st_obj->process();

        // error
		if ( empty( $st_resp['sessionToken'] ) ) {
			Nuvei_Pfw_Logger::write( 'Error when try to get Session Token' );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $parent_order );
			return;
		}

        Nuvei_Pfw_Logger::write( $this->settings, 'Before call Nuvei_Pfw_Payment.' );
        
		// $billing_mail   = $renewal_order->get_meta( '_billing_email' );
		$payment_obj = new Nuvei_Pfw_Payment( $this->settings );
		$params      = array(
			'sessionToken'    => $st_resp['sessionToken'],
			'userTokenId'     => $billing_mail,
			'clientRequestId' => $renewal_order_id . '_' . $parent_order_id . '_' . uniqid(),
			'currency'        => $renewal_order->get_currency(),
			'amount'          => round( $renewal_order->get_total(), 2 ),
			'billingAddress'  => array(
				// 'country'   => $renewal_order->get_meta( '_billing_country' ),
				'country' => $billing_country,
				'email'   => $billing_mail,
			),
			'paymentOption'   => array( 'userPaymentOptionId' => $helper->get_tr_upo_id( $parent_order_id ) ),
		);

		$parent_payment_method = $helper->get_payment_method( $parent_order_id );

		if ( 'cc_card' == $parent_payment_method ) {
			$params['isRebilling']          = 1;
			$params['relatedTransactionId'] = $parent_tr_id;
		}

		if ( 'apmgw_expresscheckout' == $parent_payment_method ) {
			Nuvei_Pfw_Logger::write( 'PayPal rebilling' );

			$params['clientUniqueId']             = $renewal_order_id . '_' . uniqid();
			$params['paymentOption']['subMethod'] = array( 'subMethod' => 'ReferenceTransaction' );
		}

		$resp = $payment_obj->process( $params );

		if ( empty( $resp['status'] ) || 'success' != strtolower( $resp['status'] ) ) {
			Nuvei_Pfw_Logger::write( 'Error when try to get Session Token' );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $parent_order );
		}
	}

	/**
	 * Get and return SimplyConnect data to REST API caller.
	 *
	 * @param  array $params Expected Cart data.
	 * @return array
	 */
	public function rest_get_simply_connect_data( $params ) {
		Nuvei_Pfw_Logger::write( null, 'rest_get_simply_connect_data', 'DEBUG' );

		$this->rest_params = $params;

		return $this->call_checkout( true );
	}

	public function rest_get_cashier_link( $params ) {
		// error
		if ( empty( $params['id'] ) || empty( $params['successUrl'] ) || empty( array( 'returnUrl' ) ) ) {
			$msg = __( 'Missing incoming parameters.', 'nuvei-payments-for-woocommerce' );
			Nuvei_Pfw_Logger::write( $params, $msg );

			return array(
				'code'    => 'missing_parameters',
				'message' => $msg,
				'data'    => array( 'status' => 400 ),
			);
		}

		$order_id = (int) $params['id'];
		$order    = wc_get_order( $order_id );

		// error
		if ( ! $order ) {
			$msg = __( 'Order is false for order id ', 'nuvei-payments-for-woocommerce' ) . $order_id;
			Nuvei_Pfw_Logger::write( $msg );

			return array(
				'code'    => 'invalid_order',
				'message' => $msg,
				'data'    => array( 'status' => 400 ),
			);
		}

		// error
		if ( $order->get_payment_method() != NUVEI_PFW_GATEWAY_NAME ) {
			$msg = __( 'Process payment Error - Order payment does not belongs to ', 'nuvei-payments-for-woocommerce' )
			. NUVEI_PFW_GATEWAY_NAME;
			Nuvei_Pfw_Logger::write( $msg );

			return array(
				'code'    => 'not_nuvei_order',
				'message' => $msg,
				'data'    => array( 'status' => 400 ),
			);
		}

		$this->order = $order;

		$url = $this->generate_cashier_url(
			$params['successUrl'],
			$params['successUrl'], // error and success URLs are same
			$params['backUrl'],
		);

		// error
		if ( empty( $url ) ) {
			$msg = __( 'Error empty Cashier URL.', 'nuvei-payments-for-woocommerce' );
			Nuvei_Pfw_Logger::write( $msg );

			return array(
				'code'    => 'empty_cashier_url',
				'message' => $msg,
				'data'    => array( 'status' => 400 ),
			);
		}

		// success
		return array( 'url' => $url );
	}

	/**
	 * Common method to check if the plugin is used on the QA site.
	 * The method also check if NUVEI_PFW_SDK_URL_TAG constant is defined.
	 *
	 * @return bool
	 */
	public function is_qa_site() {
		$server_name = '';

		if ( isset( $_SERVER['SERVER_NAME'] ) ) {
			$server_name = filter_var( wp_unslash( $_SERVER['SERVER_NAME'] ), FILTER_SANITIZE_URL );
		}

		if ( ! empty( $server_name )
			&& 'woocommerceautomation.gw-4u.com' == $server_name
			&& defined( 'NUVEI_PFW_SDK_URL_TAG' )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Get a plugin setting by its key.
	 * If key does not exists, return default value.
	 *
	 * @param string $key     - the key we are search for
	 * @param mixed  $default - the default value if no setting found
	 */
	private function get_setting( $key, $default = 0 ) {
		if ( isset( $this->settings[ $key ] ) ) {
			return $this->settings[ $key ];
		}

		return $default;
	}

	/**
	 * @global type $woocommerce
	 *
	 * @param string $success_url
	 * @param string $error_url
	 * @param string $back_url    It is only passed in REST API flow.
	 *
	 * @return string
	 */
	private function generate_cashier_url( $success_url, $error_url, $back_url = '' ) {
		Nuvei_Pfw_Logger::write( 'get_cashier_url()' );

		$nuvei_helper = new Nuvei_Pfw_Helper();
		$addresses    = $nuvei_helper->get_addresses(
			array(
				'billing_address' => $this->order->get_address(),
			)
		);
		$total_amount = (string) number_format( (float) $this->order->get_total(), 2, '.', '' );
		$shipping     = '0.00';
		$handling     = '0.00'; // put the tax here, because for Cashier the tax is in %
		$discount     = '0.00';

		$items_data['items'] = array();

		foreach ( $this->order->get_items() as $item ) {
			$items_data['items'][] = $item->get_data();
		}

		Nuvei_Pfw_Logger::write( $items_data, 'get_cashier_url() $items_data.' );

		$products_data = $nuvei_helper->get_products( $items_data );

		// check for the totals, when want Cashier URL totals is 0.
		if ( empty( $products_data['totals'] ) ) {
			$products_data['totals'] = $total_amount;
		}

		Nuvei_Pfw_Logger::write( $products_data, 'get_cashier_url() $products_data.' );

		$currency = get_woocommerce_currency();

		$params = array(
			'merchant_id'        => trim( (int) $this->settings['merchantId'] ),
			'merchant_site_id'   => trim( (int) $this->settings['merchantSiteId'] ),
			'merchant_unique_id' => $this->order->get_id(),
			'version'            => '4.0.0',
			'time_stamp'         => gmdate( 'Y-m-d H:i:s' ),

			'first_name'         => urldecode( $addresses['billingAddress']['firstName'] ),
			'last_name'          => $addresses['billingAddress']['lastName'],
			'email'              => $addresses['billingAddress']['email'],
			'country'            => $addresses['billingAddress']['country'],
			'state'              => $addresses['billingAddress']['state'],
			'city'               => $addresses['billingAddress']['city'],
			'zip'                => $addresses['billingAddress']['zip'],
			'address1'           => $addresses['billingAddress']['address'],
			'phone1'             => $addresses['billingAddress']['phone'],
			'merchantLocale'     => get_locale(),

			'notify_url'         => Nuvei_Pfw_String::get_notify_url( $this->settings ),
			'success_url'        => $success_url,
			'error_url'          => $error_url,
			'pending_url'        => $success_url,
			'back_url'           => ! empty( $back_url ) ? $back_url : wc_get_checkout_url(),

			'customField1'       => $total_amount,
			'customField2'       => $currency,
			'customField3'       => time(), // create time time()

			'currency'           => $currency,
			'total_tax'          => 0,
			'total_amount'       => $total_amount,
			'encoding'           => 'UTF-8',
			'webMasterId'        => $nuvei_helper->helper_get_web_master_id(),
			'sourceApplication'  => NUVEI_PFW_SOURCE_APPLICATION,
		);

		if ( 1 == $this->settings['use_upos'] ) {
			$params['user_token_id'] = $addresses['billingAddress']['email'];
		}

		// check for subscription data
		if ( ! empty( $products_data['subscr_data'] ) ) {
			$params['user_token_id']       = $addresses['billingAddress']['email'];
			$params['payment_method']      = 'cc_card'; // only cards are allowed for Subscribtions
			$params['payment_method_mode'] = 'filter';
		}

		// create one combined item
		if ( 1 == $this->get_option( 'combine_cashier_products' ) ) {
			$params['item_name_1']     = 'WC_Cashier_Order';
			$params['item_quantity_1'] = 1;
			$params['item_amount_1']   = $total_amount;
			$params['numberofitems']   = 1;
		} else { // add all the items
			$cnt                     = 1;
			$contol_amount           = 0;
			$params['numberofitems'] = 0;

			foreach ( $products_data['products_data'] as $item ) {
				$params[ 'item_name_' . $cnt ]     = str_replace( array( '"', "'" ), array( '', '' ), stripslashes( $item['name'] ) );
				$params[ 'item_amount_' . $cnt ]   = number_format( (float) round( $item['price'], 2 ), 2, '.', '' );
				$params[ 'item_quantity_' . $cnt ] = (int) $item['quantity'];

				$contol_amount += $params[ 'item_quantity_' . $cnt ] * $params[ 'item_amount_' . $cnt ];
				++$params['numberofitems'];
				++$cnt;
			}

			Nuvei_Pfw_Logger::write( $contol_amount, '$contol_amount' );

			if ( ! empty( $products_data['totals']['shipping_total'] ) ) {
				$shipping = round( $products_data['totals']['shipping_total'], 2 );
			}
			if ( ! empty( $products_data['totals']['shipping_tax'] ) ) {
				$shipping += round( $products_data['totals']['shipping_tax'], 2 );
			}

			if ( ! empty( $products_data['totals']['discount_total'] ) ) {
				$discount = round( $products_data['totals']['discount_total'], 2 );
			}

			$contol_amount += ( $shipping - $discount );

			if ( $total_amount > $contol_amount ) {
				$handling = round( ( $total_amount - $contol_amount ), 2 );

				Nuvei_Pfw_Logger::write( $handling, '$handling' );
			} elseif ( $total_amount < $contol_amount ) {
				$discount += ( $contol_amount - $total_amount );

				Nuvei_Pfw_Logger::write( $discount, '$discount' );
			}
		}

		$params['discount'] = number_format( (float) $discount, 2, '.', '' );
		$params['shipping'] = number_format( (float) $shipping, 2, '.', '' );
		$params['handling'] = number_format( (float) $handling, 2, '.', '' );

		$params['checksum'] = hash(
			$this->settings['hash_type'],
			trim( (string) $this->settings['secret'] ) . implode( '', $params )
		);

		Nuvei_Pfw_Logger::write( $params, 'get_cashier_url() $params.' );

		$url  = 'yes' == $this->settings['test'] ? 'https://ppp-test.safecharge.com' : 'https://secure.safecharge.com';
		$url .= '/ppp/purchase.do?' . http_build_query( $params );

		Nuvei_Pfw_Logger::write( $url, 'get_cashier_url() url' );

		return $url;
	}

	/**
	 * Instead of override init_form_fields() split the settings in three
	 * groups and put them in different tabs.
	 */
	private function init_form_base_fields() {
		$this->form_fields = array(
			'enabled'           => array(
				'title'   => __( 'Enable/Disable', 'nuvei-payments-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Nuvei Checkout Plugin.', 'nuvei-payments-for-woocommerce' ),
				'default' => 'no',
			),
			'title'             => array(
				'title'       => __( 'Default Title', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This is the payment method which the user sees during checkout.', 'nuvei-payments-for-woocommerce' ),
				'default'     => __( 'Secure Payments with Nuvei', 'nuvei-payments-for-woocommerce' ),
			),
//			'description'       => array(
//				'title'       => __( 'Description', 'nuvei-payments-for-woocommerce' ),
//				'type'        => 'textarea',
//				'description' => __( 'This controls the description which the user sees during checkout.', 'nuvei-payments-for-woocommerce' ),
//				'default'     => 'Place order to get to our secured payment page to select your payment option',
//			),
			'test'              => array(
				'title'    => __( 'Site Mode', 'nuvei-payments-for-woocommerce' ) . ' *',
				'type'     => 'select',
				'required' => true,
				'options'  => array(
					''    => __( 'Select an option...', 'nuvei-payments-for-woocommerce' ),
					'yes' => 'Sandbox',
					'no'  => 'Production',
				),
			),
			'merchantId'        => array(
				'title'    => __( 'Merchant ID', 'nuvei-payments-for-woocommerce' ) . ' *',
				'type'     => 'text',
				'required' => true,
                // phpcs:ignore
                'description' => __( 'Merchant ID is provided by Nuvei', 'nuvei-payments-for-woocommerce' ),
			),
			'merchantSiteId'    => array(
				'title'    => __( 'Merchant Site ID', 'nuvei-payments-for-woocommerce' ) . ' *',
				'type'     => 'text',
				'required' => true,
					// phpcs:ignore
		   'description' => __( 'Merchant Site ID is provided by Nuvei', 'nuvei-payments-for-woocommerce' ),
			),
			'secret'            => array(
				'title'    => __( 'Secret Key', 'nuvei-payments-for-woocommerce' ) . ' *',
				'type'     => 'text',
				'required' => true,
					// phpcs:ignore
		   'description' => __( 'Secret key is provided by Nuvei', 'nuvei-payments-for-woocommerce' ),
			),
			'hash_type'         => array(
				'title'    => __( 'Hash Type', 'nuvei-payments-for-woocommerce' ) . ' *',
				'type'     => 'select',
				'required' => true,
					// phpcs:ignore
		   'description'   => __( 'Choose Hash type provided by Nuvei', 'nuvei-payments-for-woocommerce' ),
				'options'  => array(
					''       => __( 'Select an option...', 'nuvei-payments-for-woocommerce' ),
					'sha256' => 'sha256',
					'md5'    => 'md5',
				),
			),
			'payment_action'    => array(
				'title'       => __( 'Payment Action', 'nuvei-payments-for-woocommerce' ) . ' *',
				'type'        => 'select',
				'required'    => true,
				'options'     => array(
					''     => __( 'Select an option...', 'nuvei-payments-for-woocommerce' ),
					'Sale' => 'Authorize and Capture',
					'Auth' => 'Authorize',
				),
				'class'       => 'nuvei_checkout_setting',
				'description' => __( 'This option is for Nuvei Checkout SDK.', 'nuvei-payments-for-woocommerce' ),
			),
			'allow_auto_void'   => array(
				'title'   => __( 'Allow Auto Void', 'nuvei-payments-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Allow plugin to initiate auto Void request in case there is Payment (transaction), but there is no Order for this transaction in the Store. This logic is based on incoming DMNs. Event the auto Void is disabled, a message will be saved. The last read messages can be view in the Help Tools.', 'nuvei-payments-for-woocommerce' ),
				'default' => 'no',
			),
			'save_logs'         => array(
				'title'   => __( 'Save Daily Logs', 'nuvei-payments-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Create and save daily log files. This can help for debugging and catching bugs.', 'nuvei-payments-for-woocommerce' ),
				'default' => 'yes',
			),
			'save_single_log'   => array(
				'title'   => __( 'Save Single Log file', 'nuvei-payments-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Create and save the logs into single file.', 'nuvei-payments-for-woocommerce' ),
				'default' => 'no',
			),
			'disable_wcs_alert' => array(
				'title'   => __( 'Hide WCS Warning', 'nuvei-payments-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Check it to hide WCS waringn permanent.', 'nuvei-payments-for-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Instead of override init_form_fields() split the settings in three
	 * groups and put them in different tabs.
	 *
	 * @param bool $fields_append - use it when load the fields. In this case we want all fields in same array.
	 */
	private function init_form_advanced_fields( $fields_append = false ) {
		// remove 'wc-' from WC Statuses keys
		$wc_statuses = wc_get_order_statuses();
		$statuses    = array();

		array_walk(
			$wc_statuses,
			function ( $val, $key ) use ( &$statuses ) {
				$statuses[ str_replace( 'wc-', '', $key ) ] = $val;
			}
		);

		$fields = array(
			'integration_type'         => array(
				'title'   => __( 'Integration Type', 'nuvei-payments-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'sdk'     => __( 'Simply Connect', 'nuvei-payments-for-woocommerce' ),
					'cashier' => __( 'Payment page - Cashier', 'nuvei-payments-for-woocommerce' ),
				),
				'default' => 0,
			),
            
            # Common settings
            'advanced_common_settings_title' => array(
                'title'       => '<i>' . __( 'Common settings', 'nuvei-payments-for-woocommerce' ) . '</i>',
                'type'        => 'title',
//                'description' => __( 'Common settings for the Cashier and the Simply Connect', 'nuvei-payments-for-woocommerce' ),
            ),
			// pending dmn -> on-hold
			'status_pending'           => array(
				'title'       => __( 'Status Pending DMN', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => $statuses,
				'default'     => 'on-hold',
				'description' => __( 'The status for Nuvei transactions who still wait for a DMN. This change is triggered after Settle, Refund and Void.', 'nuvei-payments-for-woocommerce' ),
			),
			// auth -> pending payment
			'status_auth'              => array(
				'title'       => __( 'Status Authorized', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => $statuses,
				'default'     => 'on-hold',
				'description' => __( 'The status for Nuvei Authorized transactions.', 'nuvei-payments-for-woocommerce' ),
			),
			// settle & sale -> completed
			'status_paid'              => array(
				'title'       => __( 'Status Paid', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => $statuses,
				'default'     => 'completed',
				'description' => __( 'The status for Settle and Sale flow is same. It shows the Order is Paid.', 'nuvei-payments-for-woocommerce' ),
			),
			// refund -> refunded
			'status_refund'            => array(
				'title'       => __( 'Status Refunded', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => $statuses,
				'default'     => 'refunded',
				'description' => __( 'The status for Nuvei Refunded transactions.', 'nuvei-payments-for-woocommerce' ),
			),
			// void -> cancelled
			'status_void'              => array(
				'title'       => __( 'Status Voided', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => $statuses,
				'default'     => 'cancelled',
				'description' => __( 'The status for Nuvei Voided transactions.', 'nuvei-payments-for-woocommerce' ),
			),
			// failed -> failed
			'status_fail'              => array(
				'title'       => __( 'Status Failed', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => $statuses,
				'default'     => 'failed',
				'description' => __( 'The status for Nuvei Failed transactions.', 'nuvei-payments-for-woocommerce' ),
			),
            'mask_user_data'           => array(
				'title'       => __( 'Mask User Data into the Log', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					1 => __( 'Yes (Recommended)', 'nuvei-payments-for-woocommerce' ),
					0 => __( 'No', 'nuvei-payments-for-woocommerce' ),
				),
				'default'     => 1,
//				'class'       => 'nuvei_checkout_setting',
				'description' => __( 'If you disable this option, the user data will be completly exposed in the log records.', 'nuvei-payments-for-woocommerce' ),
			),
            
            # Cashier settings
            'advanced_cashier_settings_title' => array(
                'title'       => '<i>' . __( 'Cashier settings', 'nuvei-payments-for-woocommerce' ) . '</i>',
                'type'        => 'title',
//                'description' => __( 'Common settings for the Cashier and the Simply Connect', 'nuvei-payments-for-woocommerce' ),
                'class'       => 'nuvei_cashier_setting',
            ),
			'combine_cashier_products' => array(
				'title'       => __( 'Combine Cashier Products into One', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Cobine the products into one, to avoid eventual problems with, taxes, discounts, coupons, etc.', 'nuvei-payments-for-woocommerce' ),
				'default'     => 1,
				'options'     => array(
					1 => __( 'Yes', 'nuvei-payments-for-woocommerce' ),
					0 => __( 'No', 'nuvei-payments-for-woocommerce' ),
				),
				'class'       => 'nuvei_cashier_setting',
			),
            
            # Simply connect settings
            'advanced_simply_connnect_settings_title' => array(
                'title'       => '<i>' . __( 'Simply Connect settings', 'nuvei-payments-for-woocommerce' ) . '</i>',
                'type'        => 'title',
//                'description' => __( 'Common settings for the Cashier and the Simply Connect', 'nuvei-payments-for-woocommerce' ),
                'class'       => 'nuvei_checkout_setting',
            ),
			'allow_zero_checkout'      => array(
				'title'       => __( 'Enable Nuvei GW for Zero-total products', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					0 => 'No',
					1 => 'Yes',
				),
				'default'     => 0,
				'class'       => 'nuvei_checkout_setting',
				'description' => __( 'If enalbe this option, only Nuvei GW will be listed as payment option. This option can be used for Card authentication.<br/>Zero-total checkout for rebilling products is enable by default.', 'nuvei-payments-for-woocommerce' ),
			),
			'use_upos'                 => array(
				'title'       => __( 'Allow Client to Use UPOs', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					0 => 'No',
					1 => 'Yes',
				),
				'default'     => 0,
				'class'       => 'nuvei_checkout_setting',
				'description' => __( 'Logged users will see their UPOs, and will have option to save UPOs.', 'nuvei-payments-for-woocommerce' ),
			),
			'save_guest_upos'          => array(
				'title'       => __( 'Save UPOs for Guest Users', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					0 => 'No',
					1 => 'Yes',
				),
				'default'     => 0,
				'class'       => 'nuvei_checkout_setting',
				'description' => __( 'The UPO will be save only when the Guest user buy Subscription product.', 'nuvei-payments-for-woocommerce' ),
			),
			'allow_paypal_rebilling'   => array(
				'title'       => __( 'Allow Rebilling with PayPal', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					0 => 'No',
					1 => 'Yes',
				),
				'default'     => 0,
				'class'       => 'nuvei_checkout_setting',
				'description' => __( 'PayPal is available only for WCS. Using PayPal for rebilling will disable the UPOs.', 'nuvei-payments-for-woocommerce' ),
			),
			'sdk_theme'                => array(
				'title'   => __( 'Simply Connect Theme', 'nuvei-payments-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'accordion'  => __( 'Accordion', 'nuvei-payments-for-woocommerce' ),
					'tiles'      => __( 'Tiles', 'nuvei-payments-for-woocommerce' ),
					'horizontal' => __( 'Horizontal', 'nuvei-payments-for-woocommerce' ),
				),
				'default' => 'accordion',
				'class'   => 'nuvei_checkout_setting',
			),
			'use_dcc'                  => array(
				'title'       => __( 'Use Currency Conversion', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'enable' => __( 'Enabled', 'nuvei-payments-for-woocommerce' ),
					'force'  => __( 'Enabled and expanded', 'nuvei-payments-for-woocommerce' ),
					'false'  => __( 'Disabled', 'nuvei-payments-for-woocommerce' ),
				),
				'description' => __( 'To work DCC, it must be enabled on merchant level also.', 'nuvei-payments-for-woocommerce' ) . ' '
				. sprintf(
					'<a href="%s" class="class" target="_blank">%s</a>',
					esc_html( 'https://docs.nuvei.com/documentation/accept-payment/simply-connect/payment-customization/#dynamic-currency-conversion' ),
					__( 'Check the Documentation.', 'nuvei-payments-for-woocommerce' )
				),
				'default'     => 'enabled',
				'class'       => 'nuvei_checkout_setting',
			),
			'blocked_cards'            => array(
				'title'       => __( 'Block Cards', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => sprintf(
					' <a href="%s" class="class" target="_blank">%s</a>',
					esc_html( 'https://docs.nuvei.com/documentation/accept-payment/checkout-2/payment-customization/#card-processing' ),
					__( 'Check the Documentation.', 'nuvei-payments-for-woocommerce' )
				),
				'class'       => 'nuvei_checkout_setting',
			),
			'pm_black_list'            => array(
				'type' => 'nuvei_multiselect',
			),
			'pay_button'               => array(
				'title'   => __( 'Choose the Text on the Pay Button', 'nuvei-payments-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'amountButton' => __( 'Shows the amount', 'nuvei-payments-for-woocommerce' ),
					'textButton'   => __( 'Shows the payment method', 'nuvei-payments-for-woocommerce' ),
				),
				'default' => 'amountButton',
				'class'   => 'nuvei_checkout_setting',
			),
			'auto_open_pm'             => array(
				'title'   => __( 'Auto-expand PMs', 'nuvei-payments-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					1 => __( 'Yes', 'nuvei-payments-for-woocommerce' ),
					0 => __( 'No', 'nuvei-payments-for-woocommerce' ),
				),
				'default' => 1,
				'class'   => 'nuvei_checkout_setting',
			),
			'log_level'                => array(
				'title'       => __( 'Checkout Log level', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					0 => 0,
					1 => 1,
					2 => 2,
					3 => 3,
					4 => 4,
					5 => 5,
					6 => 6,
				),
				'default'     => 0,
				'description' => '0 ' . __( 'for "No logging".', 'nuvei-payments-for-woocommerce' ),
				'class'       => 'nuvei_checkout_setting',
			),
			
			'simply_connect_style'	=> array(
				'title'       => __( 'Simply Connect Styling', 'nuvei-payments-for-woocommerce' ),
				'description' => sprintf(
					__( 'You can manipulate Simply Connect style from this filed. Please use valid JSON syntax as the example in the placeholder! For examples', 'nuvei-payments-for-woocommerce' )
								. ' <a href="%s" class="class" target="_blank">%s</a>',
					esc_html( 'https://docs.nuvei.com/documentation/accept-payment/web-sdk/nuvei-fields/nuvei-fields-styling/#example-javascript' ),
					__( 'check the Documentation.', 'nuvei-payments-for-woocommerce' )
				),
				'type'        => 'textarea',
				'class'       => 'nuvei_checkout_setting',
				'placeholder' => '{
	"base": {
		"color": "#6b778c"
	},
	"invalid": {
		"iconColor": "#FFC7EE"
	}
 }',
			),
			
			'translation'              => array(
				'title'       => __( 'Translations', 'nuvei-payments-for-woocommerce' ),
				'description' => sprintf(
					__( 'This filed is the only way to translate Checkout SDK strings. Put the translations for all desired languages as shown in the placeholder. For examples', 'nuvei-payments-for-woocommerce' )
								. ' <a href="%s" class="class" target="_blank">%s</a>',
					esc_html( 'https://docs.nuvei.com/documentation/accept-payment/simply-connect/ui-customization/#text-and-translation' ),
					__( 'check the Documentation.', 'nuvei-payments-for-woocommerce' )
				),
				'type'        => 'textarea',
				'class'       => 'nuvei_checkout_setting',
				'placeholder' => '{
				"doNotHonor":"you dont have enough money",
				"DECLINE":"declined"
}',
			),
            
            # GooglePay settings
            'advanced_gpay_settings_title' => array(
                'title'       => '<i>' . __( 'Google Pay settings', 'nuvei-payments-for-woocommerce' ) . '</i>',
                'type'        => 'title',
                'class'       => 'nuvei_checkout_setting',
//                'description' => __( 'Common settings for the Cashier and the Simply Connect', 'nuvei-payments-for-woocommerce' ),
            ),
            'gpay_merchantId'        => array(
				'title'         => __( 'Google Merchant ID', 'nuvei-payments-for-woocommerce' ),
				'type'          => 'text',
                'description'   => sprintf(
					__( 'For tests use BCR2DN6TZ6DP7P3X.', 'nuvei-payments-for-woocommerce' )
                    . ' <a href="%s" class="class" target="_blank">%s</a>',
					esc_html( 'https://docs.nuvei.com/documentation/global-guides/google-pay/google-pay-integration/google-pay-guide-checkout-sdk/#2-collect-the-card-details' ),
					__( 'Check the Documentation.', 'nuvei-payments-for-woocommerce' )
				),
                'class'       => 'nuvei_checkout_setting',
			),
            'gpay_buttonColor'        => array(
				'title'     => __( 'Google button color', 'nuvei-payments-for-woocommerce' ),
				'type'      => 'select',
                'options'   => array(
					'black'     => __( 'Black', 'nuvei-payments-for-woocommerce' ),
					'white'     => __( 'White', 'nuvei-payments-for-woocommerce' ),
				),
                'default'   => 'black',
                'class'     => 'nuvei_checkout_setting',
			),
            'gpay_buttonType'        => array(
				'title'     => __( 'Google button type', 'nuvei-payments-for-woocommerce' ),
				'type'      => 'select',
                'options'   => array(
					'buy'       => __( 'Buy', 'nuvei-payments-for-woocommerce' ),
					'book'      => __( 'Book', 'nuvei-payments-for-woocommerce' ),
					'checkout'  => __( 'Checkout', 'nuvei-payments-for-woocommerce' ),
					'order'     => __( 'Order', 'nuvei-payments-for-woocommerce' ),
					'pay'       => __( 'Pay', 'nuvei-payments-for-woocommerce' ),
					'plain'     => __( 'Plain', 'nuvei-payments-for-woocommerce' ),
//					'subscribe' => __( 'Subscribe', 'nuvei-payments-for-woocommerce' ),
				),
                'default'   => 'buy',
                'class'     => 'nuvei_checkout_setting',
			),
		);

		if ( $fields_append ) {
			$this->form_fields = array_merge( $this->form_fields, $fields );
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
	private function init_form_tools_fields( $fields_append = false ) {
		$fields = array(
			'get_plans_btn' => array(
				'title' => __( 'Sync Payment Plans', 'nuvei-payments-for-woocommerce' ),
				'type'  => 'payment_plans_btn',
			),
			'notify_url'    => array(
				'title'       => __( 'Notify URL', 'nuvei-payments-for-woocommerce' ),
				'type'        => 'hidden',
				'description' => Nuvei_Pfw_String::get_notify_url( $this->settings, true ),
			),
			'read_msgs'     => array(
				'title' => __( 'Read Payment messages', 'nuvei-payments-for-woocommerce' ),
				'type'  => 'payment_custom_msg',
			),
		);

		if ( $fields_append ) {
			$this->form_fields = array_merge( $this->form_fields, $fields );
		} else {
			$this->form_fields = $fields;
		}
	}
	
}
