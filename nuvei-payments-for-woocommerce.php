<?php
/**
 * Plugin Name: Nuvei Payments for Woocommerce
 * Plugin URI: https://github.com/Nuvei/nuvei-plugin-woocommerce
 * Description: Nuvei Gateway for WooCommerce
 * Version: 3.9.5
 * Author: Nuvei
 * Author: URI: https://nuvei.com
 * License: GPLv2
 * Text Domain: nuvei-payments-for-woocommerce
 * Domain Path: /languages
 * Require at least: 4.7
 * Tested up to: 6.9
 * Requires Plugins: woocommerce
 * WC requires at least: 3.0
 * WC tested up to: 10.3.6
 */

defined( 'ABSPATH' ) || die( 'die' );

if ( ! defined( 'NUVEI_PFW_PLUGIN_FILE' ) ) {
	define( 'NUVEI_PFW_PLUGIN_FILE', __FILE__ );
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/class-nuvei-pfw-autoloader.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php'; // we use it to get the data from the comment at the top
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

add_action( 'plugins_loaded', function() {
    Nuvei_Payments_For_Woocommerce::plugin_loaded();
}, 0 );

add_action( 'init', function() {
    Nuvei_Payments_For_Woocommerce::init();
}, 20 );

register_activation_hook( __FILE__, array ('Nuvei_Payments_For_Woocommerce', 'on_plugin_activate') );

class Nuvei_Payments_For_Woocommerce
{
    private static $wc_nuvei;

    public static function plugin_loaded() {
        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            wp_die('Class WC_Payment_Gateway does not exists!');
        }

//        load_plugin_textdomain(
//            'nuvei-payments-for-woocommerce',
//            false,
//            dirname( plugin_basename( __FILE__ ) ) . '/languages/'
//        );

        add_filter( 'woocommerce_payment_gateways', function ( $methods ) {
            $methods[] = 'Nuvei_Pfw_Gateway'; // get the name of the Gateway Class
            return $methods;
        });

        // declare compatabilities
        add_action(
            'before_woocommerce_init',
            function () {
                // declaration for HPOS compatability
                if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
                    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
						'custom_order_tables',
						__FILE__,
						true
					);
                }

                // Declare compatibility for 'cart_checkout_blocks'
                if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
                    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
						'cart_checkout_blocks',
						__FILE__,
						true
					);
                }
            }
        );

		// Hook in Blocks integration.
		// Check if the required class exists
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			// Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					// Register an instance of My_Custom_Gateway_Blocks
					$payment_method_registry->register( new Nuvei_Pfw_Gateway_Blocks_Support() );
				}
			);
		}
    }

    public static function init() {
        // set global constant with translated texts
        self::set_translated_texts();

        add_action( 'wp_loaded', function () {
            if ( in_array( Nuvei_Pfw_Http::get_param( 'wc-api' ), array( 'sc_listener', 'nuvei_listener' ) ) ) {
                $nuvei_notify_dmn = new Nuvei_Pfw_Notify_Url();
                $nuvei_notify_dmn->process();
            }
        });

        // I need and local variable so I can pass it in the callbacks
        self::$wc_nuvei = $wc_nuvei = new Nuvei_Pfw_Gateway();

        // load front-end scripts
        add_filter( 'wp_enqueue_scripts', array(__CLASS__, 'load_scripts') );

		// load front-end styles
        add_filter( 'woocommerce_enqueue_styles', array(__CLASS__, 'load_styles') );

        // add admin style
        add_filter( 'admin_enqueue_scripts', array(__CLASS__, 'load_admin_styles_scripts') );

        // add void and/or settle buttons to completed orders
        add_action( 'woocommerce_order_item_add_action_buttons', array(__CLASS__, 'add_buttons'), 10, 1 );
        add_action( 'after_wcfm_orders_details_items', array(__CLASS__, 'add_buttons_wcfm'), 10, 3 );

        // for WCFM orders, show Nuvei Order's Notes
        add_action( 'end_wcfm_orders_details', array(__CLASS__, 'wcfm_show_notes'), 10, 1 );

        // handle custom Ajax calls
        add_action( 'wp_ajax_sc-ajax-action', array(__CLASS__, 'ajax_action') );
        add_action( 'wp_ajax_nopriv_sc-ajax-action', array(__CLASS__, 'ajax_action') );

        // On checkout form validation. Works on Classic Checkout only!
        add_action(
            'woocommerce_after_checkout_validation',
            array (__CLASS__, 'after_checkout_validation'),
            PHP_INT_MAX, // set it on max, just to be sure we will catch all additional validation errors
            2
        );

        // when the client click Pay button on the Order from My Account -> Orders menu.
        add_filter( 'woocommerce_pay_order_after_submit', array (__CLASS__, 'user_orders') );

        # Payment Plans taxonomies
        // extend Term form to add meta data
        add_action(
			'pa_' . Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME ) . '_add_form_fields',
			array (__CLASS__, 'terms_add_fields_form'),
			10,
			2
		);

        // update Terms' meta data form
        add_action(
			'pa_' . Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME ) . '_edit_form_fields',
			array (__CLASS__, 'terms_edit_meta_form'),
			10,
			2
		);

        // hook to catch our meta data and save it
        add_action(
			'created_pa_' . Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME ),
			array (__CLASS__, 'terms_save_meta'),
			10,
			2
		);

        // edit Term meta data
        add_action(
			'edited_pa_' . Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME ),
			array (__CLASS__, 'terms_edit_meta'),
			10,
			2
		);
        # /

		// before add a product to the cart
        add_filter( 'woocommerce_add_to_cart_validation', array( $wc_nuvei, 'add_to_cart_validation' ), 10, 3 );

		// Hide payment gateways in case of product with Nuvei Payment plan in the Cart
        add_filter( 'woocommerce_available_payment_gateways', array( $wc_nuvei, 'hide_payment_gateways' ), 100, 1 );

        // next actions are valid only when the plugin is enabled
        if (! isset( $wc_nuvei->settings['enabled'] ) || 'no' == $wc_nuvei->settings['enabled']) {
            return;
        }

        // for the thank-you page
        add_filter( 'woocommerce_thankyou_order_received_text', array (__CLASS__, 'thank_you_page_mod'), 10, 2 );

        # For the custom column in the Order list
        // legacy
        add_action( 'manage_shop_order_posts_custom_column', array (__CLASS__, 'order_list_columns_edit'), 10, 2 );
        // HPOS
        add_action(
			'woocommerce_shop_order_list_table_custom_column',
			array (__CLASS__, 'order_list_columns_edit_hpos'),
			10,
			2
		);

        // for the Store > My Account > Orders list
        add_action(
			'woocommerce_my_account_my_orders_column_order-number',
			array (__CLASS__, 'my_account_orders_col_edit')
		);

        // show payment methods on checkout when total is 0
        add_filter( 'woocommerce_cart_needs_payment', array (__CLASS__, 'wc_cart_needs_payment'), 10, 2 );

		// show custom data into order details, product data
        add_action( 'woocommerce_after_order_itemmeta', array (__CLASS__, 'after_order_itemmeta'), 10, 3 );

		// listent for the WC Subscription Payment
        add_action(
            'woocommerce_scheduled_subscription_payment_' . NUVEI_PFW_GATEWAY_NAME,
            array( $wc_nuvei, 'create_wc_subscr_order' ),
            10,
            2
        );

        // Add this hook to catch Zero Total Orders in WC Blocks. The others still go through process_payment().
        add_action(
//             'woocommerce_blocks_checkout_order_processed',
            'woocommerce_store_api_checkout_order_processed',
            array (__CLASS__, 'checkout_order_processed'),
            10,
			3
        );

        add_action(
            'nuvei_pfwc_after_rebilling_payment',
            function () {
                Nuvei_Pfw_Logger::write( 'nuvei_pfwc_after_rebilling_payment do some action here' );
            }
        );

        // hook to show unreaded Nuvei' system messages
        add_action( 'admin_notices', array (__CLASS__, 'display_messages') );

        

        // register the plugin REST endpoint
        add_action('rest_api_init', array (__CLASS__, 'register_plugin_rest_endpoint') );

    }

    public static function set_translated_texts() {
        define(
            'NUVEI_PFW_JS_LOCALIZATIONS',
            array(
                'ajaxurl'            => admin_url( 'admin-ajax.php' ),
                'sourceApplication'  => NUVEI_PFW_SOURCE_APPLICATION,
                'plugin_dir_url'     => plugin_dir_url( __FILE__ ),
                'paymentGatewayName' => NUVEI_PFW_GATEWAY_NAME,

                // translations
                'insuffFunds'        => __( 'You have Insufficient funds, please go back and remove some of the items in your shopping cart, or use another card.', 'nuvei-payments-for-woocommerce' ),
                'paymentDeclined'    => __( 'Your Payment was DECLINED. Please, try another payment option!', 'nuvei-payments-for-woocommerce' ),
                'paymentError'       => __( 'Error with your Payment.', 'nuvei-payments-for-woocommerce' ),
                'unexpectedError'    => __( 'Unexpected error. Please, try another payment option!', 'nuvei-payments-for-woocommerce' ),
                'fillFields'         => __( 'Please fill all mandatory fileds!', 'nuvei-payments-for-woocommerce' ),
                'errorWithSToken'    => __( 'Error when try to get the Session Token.', 'nuvei-payments-for-woocommerce' ),
                'goBack'             => __( 'Go back', 'nuvei-payments-for-woocommerce' ),
                'RequestFail'        => __( 'Request fail.', 'nuvei-payments-for-woocommerce' ),
                'ApplePayError'      => __( 'Unexpected session error.', 'nuvei-payments-for-woocommerce' ),
                'TryAgainLater'      => __( 'Please try again later!', 'nuvei-payments-for-woocommerce' ),
                'TryAnotherPM'       => __( 'Please try another payment method!', 'nuvei-payments-for-woocommerce' ),
                'Pay'                => __( 'Pay', 'nuvei-payments-for-woocommerce' ),
                'PlaceOrder'         => __( 'Place order', 'nuvei-payments-for-woocommerce' ),
                'Continue'           => __( 'Continue', 'nuvei-payments-for-woocommerce' ),
                'refundQuestion'     => __( 'Are you sure about this Refund?', 'nuvei-payments-for-woocommerce' ),
                'LastDownload'       => __( 'Last Download', 'nuvei-payments-for-woocommerce' ),
                'ReadLog'            => __( 'Read Log', 'nuvei-payments-for-woocommerce' ),
                'RefreshLogError'    => __( 'Getting log faild, please check the console for more information!', 'nuvei-payments-for-woocommerce' ),
                'CheckoutFormError'  => __( 'Checkout form class error, please contact the site administrator!', 'nuvei-payments-for-woocommerce' ),
                'TransactionAppr'    => __( 'The transaction was approved.', 'nuvei-payments-for-woocommerce' ),
                'RefundAmountError'  => __( 'Please, check requested Refund amount!', 'nuvei-payments-for-woocommerce' ),
                'TermsError'        => __( 'To continue, please accept the Terms!', 'nuvei-payments-for-woocommerce' ),
            )
        );
    }

    /**
    * Loads public scripts
    *
    * @global Nuvei_Pfw_Gateway $wc_nuvei
    * @global type $wpdb
    *
    * @return void
    */
    public static function load_scripts() {
        if ( ! is_checkout() ) {
            return;
        }

        global $wpdb;
        global $wp;

        $plugin_url	= plugin_dir_url( __FILE__ );
        $sdkUrl     = NUVEI_PFW_SDK_URL_PROD;
		$helper     = new Nuvei_Pfw_Helper();

        if ( self::$wc_nuvei->is_qa_site() ) {
            $sdkUrl = NUVEI_PFW_SDK_URL_TAG;
        }

        // load the SDK
        wp_register_script(
            'nuvei_checkout_sdk',
            $sdkUrl,
            array( 'jquery' ),
            '2025-02-19',
            false
        );

        // main JS
        wp_register_script(
            'nuvei_js_public',
            $plugin_url . 'assets/js/nuvei_public.js',
            array( 'jquery' ),
            '2025-12-08',
            false
        );

        // put translations here into the array
        $localizations = array_merge(
            NUVEI_PFW_JS_LOCALIZATIONS,
            array(
                'nuveiSecurity'       => wp_create_nonce( 'nuvei-security-nonce' ),
//                'wcStoreApiSec'       => wp_create_nonce( 'wc_store_api' ),
                'wcThSep'             => get_option( 'woocommerce_price_thousand_sep' ),
                'wcDecSep'            => get_option( 'woocommerce_price_decimal_sep' ),
                'useUpos'             => self::$wc_nuvei->can_use_upos(),
                'isUserLogged'        => is_user_logged_in() ? 1 : 0,
                'isPluginActive'      => self::$wc_nuvei->settings['enabled'],
                'loaderUrl'           => plugin_dir_url( __FILE__ ) . 'assets/icons/loader.gif',
                'checkoutIntegration' => self::$wc_nuvei->settings['integration_type'],
                'webMasterId'         => 'WooCommerce ' . WOOCOMMERCE_VERSION
                    . '; Plugin v' . $helper->helper_get_plugin_version(),
            )
        );

        // if we are on the thankyou page set some variables
        if (!empty($wp->query_vars['order-received'])
            && !empty( $order_key = Nuvei_Pfw_Http::get_param( 'key' ) )
        ) {
            $request_status     = Nuvei_Pfw_Http::get_request_status();
            $order_id           = wc_get_order_id_by_order_key( $order_key );
            $order              = wc_get_order( $order_id );
            $removeWCSPayBtn    = false;
            $new_title          = '';

            if ( $order->get_payment_method() == NUVEI_PFW_GATEWAY_NAME ) {
                if ( 'error' == $request_status
                    || 'fail' == strtolower( wc_clean( 'ppp_status' ) )
                ) {
                    $localizations['thankYouPageNewTitle'] = esc_html__( 'Order error', 'nuvei-payments-for-woocommerce' );
                } elseif ( 'canceled' == $request_status ) {
                    $localizations['thankYouPageNewTitle'] = esc_html__( 'Order canceled', 'nuvei-payments-for-woocommerce' );
                }

                // when WCS is turn on, remove Pay button
                if ( is_plugin_active( 'woocommerce-subscriptions' . DIRECTORY_SEPARATOR . 'woocommerce-subscriptions.php' ) ) {
                    $localizations['thankYouPageRemovePayBtn'] = true;
                }
            }
        }

        wp_enqueue_script( 'nuvei_checkout_sdk' );

        wp_localize_script( 'nuvei_js_public', 'scTrans', $localizations );
        wp_enqueue_script( 'nuvei_js_public' );
    }

	/**
	* Loads public styles
	*
	* @global Nuvei_Pfw_Gateway $wc_nuvei
	* @global type $wpdb
	*
	* @param array $styles
	*
	* @return void
	*/
	public static function load_styles( $styles ) {
		if ( ! is_checkout() ) {
			return $styles;
		}

//		global $wc_nuvei;
		global $wpdb;

		$plugin_url = plugin_dir_url( __FILE__ );

		if ( ( isset( $_SERVER['HTTPS'] ) && 'on' == $_SERVER['HTTPS'] )
			&& ( isset( $_SERVER['REQUEST_SCHEME'] ) && 'https' == $_SERVER['REQUEST_SCHEME'] )
		) {
			if ( strpos( $plugin_url, 'https' ) === false ) {
				$plugin_url = str_replace( 'http:', 'https:', $plugin_url );
			}
		}

		// novo style
		wp_register_style(
			'nuvei_style',
			$plugin_url . 'assets/css/nuvei_style.css',
			'',
			'1',
			'all'
		);

		wp_enqueue_style( 'nuvei_style' );

		return $styles;
	}

	/**
	* Loads admin styles and scripts
	*
	* @param string $hook
	*/
	public static function load_admin_styles_scripts( $hook ) {
		$plugin_url = plugin_dir_url( __FILE__ );
		$helper     = new Nuvei_Pfw_Helper();

		if ( ! wp_script_is( 'nuvei_admin_style' ) ) {
			wp_register_style(
				'nuvei_admin_style',
				$plugin_url . 'assets/css/nuvei_admin_style.css',
				'',
				1,
				'all'
			);
			wp_enqueue_style( 'nuvei_admin_style' );
		}

		// main JS
		if ( ! wp_script_is( 'nuvei_js_admin' ) ) {
			wp_register_script(
				'nuvei_js_admin',
				$plugin_url . 'assets/js/nuvei_admin.js',
				array( 'jquery' ),
				'2024-07-30',
				true
			);

			// get the list of the plans
			$nuvei_plans_path = NUVEI_PFW_LOGS_DIR . NUVEI_PFW_PLANS_FILE;
			$plans_list       = wp_json_encode( array() );
			$wp_fs_direct     = new WP_Filesystem_Direct( null );

			if ( is_readable( $nuvei_plans_path ) ) {
				$plans_list = stripslashes( $wp_fs_direct->get_contents( $nuvei_plans_path ) );
			}
			// get the list of the plans end

			// put translations here into the array
			$localizations = array_merge(
				NUVEI_PFW_JS_LOCALIZATIONS,
				array(
					'nuveiSecurity'     => wp_create_nonce( 'nuvei-security-nonce' ),
					'nuveiPaymentPlans' => $plans_list,
					'webMasterId'       => 'WooCommerce ' . WOOCOMMERCE_VERSION
						. '; Plugin v' . $helper->helper_get_plugin_version(),
				)
			);

			wp_localize_script( 'nuvei_js_admin', 'scTrans', $localizations );
			wp_enqueue_script( 'nuvei_js_admin' );
		}
	}

	/**
	 * Add buttons for the Nuvei Order actions in Order details page.
	 *
	 * @param WC_Order $order
	 * @param bool $return_html In WCFM context we need html parts to be returned in array.
	 *
	 * @return bool|array
	 */
	public static function add_buttons( $order, $return_html = false ) {
		// error
		if ( ! is_a( $order, 'WC_Order' ) || is_a( $order, 'WC_Subscription' ) ) {
			return false;
		}

		Nuvei_Pfw_Logger::write( 'add_buttons' );

		// error - in case this is not Nuvei order
		if ( empty( $order->get_payment_method() )
			|| ! in_array( $order->get_payment_method(), array( NUVEI_PFW_GATEWAY_NAME, 'sc' ) )
		) {
			wp_add_inline_script(
				'nuvei_js_admin',
				'var notNuveiOrder = true;',
				'before'
			);

			return false;
		}

		// to show Nuvei buttons we must be sure the order is paid via Nuvei Paygate
		$order_id               = $order->get_id();
		$helper                 = new Nuvei_Pfw_Helper();
		$ord_tr_id              = $helper->helper_get_tr_id( $order_id );
		$order_total            = $order->get_total();
		$order_data             = $order->get_meta( NUVEI_PFW_TRANSACTIONS );
		$last_tr_data           = array();
		$last_approved_tr_data  = array();
		$order_refunds          = array();
		$ref_amount             = 0;
		$order_time             = 0;
		$html_elements          = array(
			'showRefundBtn' => true,
		);

		// error
		if ( empty( $ord_tr_id ) ) {
			Nuvei_Pfw_Logger::write( $ord_tr_id, 'Invalid Transaction ID! We will not add any buttons.', 'TRACE' );
			return false;
		}

		// error
		if ( empty( $order_data ) || ! is_array( $order_data ) ) {
			Nuvei_Pfw_Logger::write(
				$order_data,
				'Missing or wrong Nuvei transactions data for the order. We will not add any buttons.',
                'TRACE'
			);

			// disable refund button
			wp_add_inline_script(
				'nuvei_js_admin',
				'nuveiPfwDisableRefundBtn()',
				'after'
			);

			return false;
		}

        $last_tr_data = end( $order_data );

        /**
		 * If the status is missing then DMN is not received or there is some error
		 * with the transaction.
		 * In case the Status is Pending this is an APM payment and the plugin still
		 * wait for approval DMN. Till then no actions are allowed.
		 * In above check we will hide the Refund button.
		 */
		if ( empty( $last_tr_data['status'] )
//			|| 'approved' != strtolower( $last_tr_data['status'] )
			|| 'pending' == strtolower( $last_tr_data['status'] )
		) {
			Nuvei_Pfw_Logger::write(
				$last_tr_data,
				'Last Transaction is not yet approved or the DMN didn\'t come yet.',
                'TRACE'
			);

			// disable refund button
			wp_add_inline_script(
				'nuvei_js_admin',
				'nuveiPfwDisableRefundBtn()',
				'after'
			);

			return false;
		}

		foreach ( array_reverse( $order_data, false ) as $tr ) {
            // get Refund transactions
			if ( isset( $tr['transactionType'], $tr['status'] )
				&& in_array( $tr['transactionType'], array( 'Credit', 'Refund' ) )
				&& 'approved' == strtolower( $tr['status'] )
			) {
				$order_refunds[] = $tr;
				$ref_amount     += $tr['totalAmount'];
			}

            // get last approved transaction
            if (empty($last_approved_tr_data)
                && !empty($tr['status'])
                && 'approved' == strtolower( $tr['status'] )
            ) {
                $last_approved_tr_data = $tr;
            }
		}

		$order_payment_method = $helper->get_payment_method( $order_id );

		if ( ! is_null( $order->get_date_created() ) ) {
			$order_time = $order->get_date_created()->getTimestamp();
		}
		if ( ! is_null( $order->get_date_completed() ) ) {
			$order_time = $order->get_date_completed()->getTimestamp();
		}

		// hide Refund Button, it is visible by default
		if ( ! in_array( $order_payment_method, NUVEI_PFW_PMS_REFUND_VOID )
//			|| ! in_array( $last_tr_data['transactionType'], array( 'Sale', 'Settle', 'Credit', 'Refund' ) )
			|| ! in_array( $last_approved_tr_data['transactionType'], array( 'Sale', 'Settle', 'Credit', 'Refund' ) )
//			|| 'approved' != strtolower( $last_tr_data['status'] )
			|| 'approved' != strtolower( $last_approved_tr_data['status'] )
			|| 0 == $order_total
			|| $ref_amount >= $order_total
		) {
			wp_add_inline_script(
				'nuvei_js_admin',
				'nuveiPfwDisableRefundBtn()',
				'after'
			);

            $html_elements['showRefundBtn'] = false;
		}

        /**
         * Show Void button. To do it the follow conditions must pass:
         *
         * the payment must be CC;
         * there are no refunds on the Order;
         * the last approved transaction must be Sale, Settle or Auth;
         * the Total must be greater than 0;
         * the Void must be triggered no more than 48 hours after the last approved transaction;
         */
		if ( 'cc_card' == $order_payment_method
			&& empty( $order_refunds )
//			&& in_array( $last_tr_data['transactionType'], array( 'Sale', 'Settle', 'Auth' ) )
			&& in_array( $last_approved_tr_data['transactionType'], array( 'Sale', 'Settle', 'Auth' ) )
			&& (float) $order_total > 0
			&& time() < $order_time + 172800 // 48 hours
		) {
			$question = sprintf(
			/* translators: %d is replaced with "decimal" */
				__( 'Are you sure, you want to Cancel Order #%d?', 'nuvei-payments-for-woocommerce' ),
				$order_id
			);

			// check for active subscriptions
			$all_meta    = $order->get_meta_data();
			$subscr_list = $helper->get_rebiling_details( $all_meta );

			foreach ( $subscr_list as $meta_data ) {
				if ( ! empty( $meta_data['subs_data']['state'] )
					&& 'active' == $meta_data['subs_data']['state']
				) {
					$question = __( 'Are you sure, you want to Cancel this Order? This will also deactivate all Active Subscriptions.', 'nuvei-payments-for-woocommerce' );
					break;
				}
			}
			// /check for active subscriptions

			if ( $return_html ) {
				$html_elements['voidQuestion'] = $question;
			} else {
				echo '<button id="sc_void_btn" type="button" onclick="nuveiAction(\''
					. esc_html( $question ) . '\', \'void\', ' . esc_html( $order_id )
					. ')" class="button generate-items">'
					. esc_html__( 'Void', 'nuvei-payments-for-woocommerce' ) . '</button>';
			}
		}

		// show SETTLE button ONLY if transaction type IS Auth and the Total is not 0
//		if ( 'Auth' == $last_tr_data['transactionType']
		if ( 'Auth' == $last_approved_tr_data['transactionType']
			&& $order_total > 0
		) {
			$question = sprintf(
			/* translators: %d is replaced with "decimal" */
				__( 'Are you sure, you want to Settle Order #%d?', 'nuvei-payments-for-woocommerce' ),
				$order_id
			);

			if ( $return_html ) {
						$html_elements['settleQuestion'] = $question;
			} else {
				echo '<button id="sc_settle_btn" type="button" onclick="nuveiAction(\''
					. esc_html( $question )
					. '\', \'settle\', \'' . esc_html( $order_id ) . '\')" class="button generate-items">'
					. esc_html__( 'Settle', 'nuvei-payments-for-woocommerce' ) . '</button>';
			}
		}

		if ( $return_html ) {
			return $html_elements;
		}

		echo '<div id="custom_loader" class="blockUI blockOverlay" style="height: 100%; position: absolute; top: 0px; width: 100%; z-index: 10; background-color: rgba(255,255,255,0.5); display: none;"></div>';
	}

	/**
	 * Add buttons for the Nuvei Order actions in WCFM Order details page.
	 *
	 * @param int   $order_id
	 * @param WC_Order $order
	 * @param array $line_items
	 *
	 * @return void
	 */
	public static function add_buttons_wcfm( $order_id, $order, $line_items ) {

		if ( $order->get_payment_method() != NUVEI_PFW_GATEWAY_NAME ) {
			return;
		}

		// load nuvei script if need to
		self::load_admin_styles_scripts( '' );

		$html_elements = add_buttons( $order, true );

		if ( ! $html_elements ) {
			return;
		}

		ob_start();

		$data = array(
			'orderId'        => $order_id,
			'settleQuestion' => isset( $html_elements['settleQuestion'] ) ? $html_elements['settleQuestion'] : false,
			'voidQuestion'   => isset( $html_elements['voidQuestion'] ) ? $html_elements['voidQuestion'] : false,
			'showRefundBtn'  => isset( $html_elements['showRefundBtn'] ) ? $html_elements['showRefundBtn'] : false,
		);

		include_once __DIR__ . DIRECTORY_SEPARATOR . 'templates/admin/wcfm-orders-details-buttons.php';

		ob_end_flush();
	}

	public static function wcfm_show_notes( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order->get_payment_method() != NUVEI_PFW_GATEWAY_NAME ) {
			return;
		}

		$args = array(
			'post_id' => $order_id,
			'type'    => 'order_note',
		);

		ob_start();

		$notes = wc_get_order_notes( $args );

		include_once __DIR__ . DIRECTORY_SEPARATOR . 'templates/admin/wcfm-orders-details-msgs.php';

		ob_end_flush();
	}

	/**
	 * Main function for the Ajax requests.
	 */
	public static function ajax_action() {
		if ( ! check_ajax_referer( 'nuvei-security-nonce', 'nuveiSecurity', false ) ) {
			wp_send_json_error( __( 'Invalid security token sent.', 'nuvei-payments-for-woocommerce' ) );
			wp_die( 'Invalid security token sent' );
		}

		if ( empty( self::$wc_nuvei->settings['test'] ) ) {
			wp_send_json_error( __( 'Invalid site mode.', 'nuvei-payments-for-woocommerce' ) );
			wp_die( 'Invalid site mode.' );
		}

		$order_id = Nuvei_Pfw_Http::get_param( 'orderId', 'int' );

		// recognize the action:
		// Get Blocks Checkout data
		if ( Nuvei_Pfw_Http::get_param( 'getBlocksCheckoutData', 'int' ) == 1 ) {
			// Simply Connect flow
			if ( 'sdk' == self::$wc_nuvei->settings['integration_type'] ) {
				wp_send_json( self::$wc_nuvei->call_checkout( false, true ) );
				exit;
			}

			// Cashier flow
			if ( 'cashier' == self::$wc_nuvei->settings['integration_type'] ) {
				// TODO
				exit;
			}

			exit;
		}

		// Void (Cancel)
		if ( Nuvei_Pfw_Http::get_param( 'cancelOrder', 'int' ) == 1 && $order_id > 0 ) {
			$nuvei_settle_void = new Nuvei_Pfw_Settle_Void( self::$wc_nuvei->settings );
			$nuvei_settle_void->create_settle_void( sanitize_text_field( $order_id ), 'void' );
		}

		// Settle
		if ( Nuvei_Pfw_Http::get_param( 'settleOrder', 'int' ) == 1 && $order_id > 0 ) {
			$nuvei_settle_void = new Nuvei_Pfw_Settle_Void( self::$wc_nuvei->settings );
			$nuvei_settle_void->create_settle_void( sanitize_text_field( $order_id ), 'settle' );
		}

		// Refund
		if ( Nuvei_Pfw_Http::get_param( 'refAmount', 'float' ) != 0 ) {
			$nuvei_refund = new Nuvei_Pfw_Refund( self::$wc_nuvei->settings );
			$nuvei_refund->create_refund_request(
				Nuvei_Pfw_Http::get_param( 'postId', 'int' ),
				Nuvei_Pfw_Http::get_param( 'refAmount', 'float' )
			);
		}

		// Cancel Subscription
		if ( Nuvei_Pfw_Http::get_param( 'cancelSubs', 'int' ) == 1
			&& ! empty( Nuvei_Pfw_Http::get_param( 'subscrId', 'int' ) )
		) {
			$subscription_id = Nuvei_Pfw_Http::get_param( 'subscrId', 'int' );
			$order           = wc_get_order( Nuvei_Pfw_Http::get_param( 'orderId', 'int' ) );

			$nuvei_class = new Nuvei_Pfw_Subscription_Cancel( self::$wc_nuvei->settings );
			$resp        = $nuvei_class->process( array( 'subscriptionId' => $subscription_id ) );
			$ord_status  = 0;

			if ( ! empty( $resp['status'] ) && 'SUCCESS' == $resp['status'] ) {
				$ord_status = 1;
			}

			wp_send_json(
				array(
					'status' => $ord_status,
					'data'   => $resp,
				)
			);
			exit;
		}

		// Check Cart on SDK pre-payment event
		if ( Nuvei_Pfw_Http::get_param( 'prePayment', 'int' ) == 1 ) {
			self::$wc_nuvei->checkout_prepayment_check();
		}

		// download Subscriptions Plans
		if ( Nuvei_Pfw_Http::get_param( 'downloadPlans', 'int' ) == 1 ) {
			self::$wc_nuvei->download_subscr_pans();
		}

		// when need data to pay Existing Order for Simply Connect flow
		if ( Nuvei_Pfw_Http::get_param( 'payForExistingOrder', 'int' ) == 1
			&& Nuvei_Pfw_Http::get_param( 'orderId', 'int' ) > 0
			&& 'sdk' == self::$wc_nuvei->settings['integration_type']
		) {
			$params = self::$wc_nuvei->call_checkout( false, true, Nuvei_Pfw_Http::get_param( 'orderId', 'int' ) );

			wp_send_json( $params );
			wp_die();
		}

		// dismiss Nuvei system message
		if ( Nuvei_Pfw_Http::get_param( 'msgId', 'int', -1 ) >= 0 ) {
			$messages = get_option( 'custom_system_messages', array() );

			Nuvei_Pfw_Logger::write($messages);

			$msg_id = Nuvei_Pfw_Http::get_param( 'msgId', 'int' );

			if ( isset( $messages[ $msg_id ]['read'] ) ) {
				// remove the message
				if ( true === $messages[ $msg_id ]['read'] ) {

	//            }
	//			if ( $messages[ $msg_id ]['read'] ) {
					unset( $messages[ $msg_id ] );

					// Re-index the array to maintain sequential keys (optional)
					// $messages = array_values($messages);
				}
				// mark the message as read
				else {
					$messages[ $msg_id ]['read'] = true;
				}

				update_option( 'custom_system_messages', $messages );
				wp_send_json_success();
			}

			wp_send_json_error();
		}

		// get custom Payment messages
		if ( Nuvei_Pfw_Http::get_param( 'getPaymentCustomMsgs', 'int' ) == 1 ) {
			$all_msgs   = get_option( 'custom_system_messages', array() );
			$last_msgs  = array();
			$cnt        = 1;
			$msgs_cnt   = 50;

			Nuvei_Pfw_Logger::write($all_msgs);

			if ( empty( $all_msgs ) ) {
				wp_send_json( $last_msgs );
				wp_die();
			}

			foreach ( array_reverse( $all_msgs, true ) as $index => $msg ) {
				if ( empty( $msg['created_by'] ) || 'nuvei_payments' != $msg['created_by'] ) {
					continue;
				}

				if ( $cnt >= $msgs_cnt ) {
					break;
				}

				$last_msgs[ $index ] = $msg;
				++$cnt;
			}

			wp_send_json( $last_msgs );
			wp_die();
		}

		wp_send_json_error( __( 'Not recognized Ajax call.', 'nuvei-payments-for-woocommerce' ) );
		wp_die();
	}

	/**
	 * // We call this function after the user click the Place Order button.
	 * // Here we know if there are any errors in the checkout form.
     * 
     * We manually validate the form calling WC checout method from our JS file.
     * Here we will check for our custom flag. In case there is flag, and no
     * errors, we will return 'result' => 'failure', to prevent the checkout
     * to submit the form before create a payment with Simply Connect.
	 *
	 * @param array $data
	 * @param array $errors
	 */
	public static function after_checkout_validation ( $data, $errors ) {
	    Nuvei_Pfw_Logger::write( 
            array( 
                $data, 
                $errors,
                Nuvei_Pfw_Http::get_param( 'nuveiFormValidation', 'int', 0, array(), true )
            ),
            'action woocommerce_after_checkout_validation start' 
        );

		if ( $errors->has_errors()
		    || wc_notice_count( 'error' ) > 0
		    || ! empty( $errors->get_error_messages() )
		    || ! empty( $errors->errors )
            || NUVEI_PFW_GATEWAY_NAME !== $data['payment_method']
		) {
		    Nuvei_Pfw_Logger::write('There are errors in the checkout form.');
		    return;
		}

		// Only proceed for your gateway
//		if ( NUVEI_PFW_GATEWAY_NAME !== $data['payment_method'] ) {
//		    return;
//		}

//		if ( empty( Nuvei_Pfw_Http::get_param( 'nuvei_transaction_id', 'int', 0, array(), true ) )
//		    && isset( self::$wc_nuvei->settings['integration_type'] )
//		    && 'cashier' != self::$wc_nuvei->settings['integration_type']
//	    ) {
//			Nuvei_Pfw_Logger::write( 'action woocommerce_after_checkout_validation nuvei logic' );
//			self::$wc_nuvei->call_checkout();
//		}
        
        // search for custom nuvei flag - validation only
        $nuvei_form_validation = Nuvei_Pfw_Http::get_param( 'nuveiFormValidation', 'int', 0, array(), true );
        
        if (1 == $nuvei_form_validation) {
            wp_send_json(
                array(
                    'result'        => 'failure', // this is just to stop WC send the form
                    'refresh'       => false,
                    'reload'        => false,
                    'isFormValid'   => true,
                )
            );
        }
	}

	/**
	 * When the client click Pay button on the Order from My Account -> Orders menu.
	 * This Order was created in the store admin, from some of the admins (merchants).
	 *
	 * @global type $wp
	 *
	 * @return void
	 */
	public static function user_orders() {
		Nuvei_Pfw_Logger::write( 'user_orders()' );

		global $wp;

		// for Cashier - just don't touch it! It works by default. :D
		if ( isset( self::$wc_nuvei->settings['integration_type'] )
			&& 'cashier' == self::$wc_nuvei->settings['integration_type']
		) {
			return;
		}

		$order_id      = $wp->query_vars['order-pay'];
		$order         = wc_get_order( $order_id );
		$order_key     = $order->get_order_key();
		$order_key_url = Nuvei_Pfw_Http::get_param( 'key' );

		// error
		if ( $order_key_url != $order_key ) {
			Nuvei_Pfw_Logger::write(
				array(
					'param key'  => $order_key_url,
					'$order_key' => $order_key,
				),
				'Order key problem.'
			);

			return;
		}

		// Pass the orderId in custom element, as approve to use Simply Connect logic later.
		echo '<input type="hidden" id="nuveiPayForExistingOrder" value="' . esc_attr( $order_id ) . '" />';
	}

	// Attributes, Terms and Meta functions
	public static function terms_add_fields_form( $taxonomy ) {
		$nuvei_plans_path = NUVEI_PFW_LOGS_DIR . NUVEI_PFW_PLANS_FILE;

		ob_start();

		$plans_list = array();
		if ( is_readable( $nuvei_plans_path ) ) {
			$plans_list = wp_json_file_decode(
				$nuvei_plans_path,
				array( 'associative' => true )
			);
		}

		include_once __DIR__ . DIRECTORY_SEPARATOR . 'templates/admin/add-terms-form.php';

		ob_end_flush();
	}

	public static function terms_edit_meta_form( $term, $taxonomy ) {
		$nuvei_plans_path = NUVEI_PFW_LOGS_DIR . NUVEI_PFW_PLANS_FILE;

		ob_start();
		$term_meta  = get_term_meta( $term->term_id );
		$plans_list = array();

		if ( is_readable( $nuvei_plans_path ) ) {
			$plans_list = wp_json_file_decode( $nuvei_plans_path, array( 'associative' => true ) );
		}

		// clean unused elements
		foreach ( $term_meta as $key => $data ) {
			if ( strpos( $key, '_' ) !== false ) {
				unset( $term_meta[ $key ] );
				break;
			}
		}

		include_once __DIR__ . DIRECTORY_SEPARATOR . 'templates/admin/edit-term-form.php';
		ob_end_flush();
	}

	public static function terms_save_meta( $term_id, $tt_id ) {
		if ( ! check_admin_referer( 'nuvei-term-nonce', 'nuveiTermNonce' ) ) {
			Nuvei_Pfw_Logger::write( '', 'Cannot validate admin nonce.', 'WARN' );
			die( 'Cannot validate admin nonce.' );
		}

		$taxonomy      = 'pa_' . Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME );
		$post_taxonomy = Nuvei_Pfw_Http::get_param( 'taxonomy', 'string' );

		if ( $post_taxonomy != $taxonomy ) {
			return;
		}

		add_term_meta( $term_id, 'planId', Nuvei_Pfw_Http::get_param( 'planId', 'int' ) );
		add_term_meta( $term_id, 'recurringAmount', Nuvei_Pfw_Http::get_param( 'recurringAmount', 'float' ) );

		add_term_meta( $term_id, 'startAfterUnit', Nuvei_Pfw_Http::get_param( 'startAfterUnit', 'string' ) );
		add_term_meta( $term_id, 'startAfterPeriod', Nuvei_Pfw_Http::get_param( 'startAfterPeriod', 'int' ) );

		add_term_meta( $term_id, 'recurringPeriodUnit', Nuvei_Pfw_Http::get_param( 'recurringPeriodUnit', 'string' ) );
		add_term_meta( $term_id, 'recurringPeriodPeriod', Nuvei_Pfw_Http::get_param( 'recurringPeriodPeriod', 'int' ) );

		add_term_meta( $term_id, 'endAfterUnit', Nuvei_Pfw_Http::get_param( 'endAfterUnit', 'string' ) );
		add_term_meta( $term_id, 'endAfterPeriod', Nuvei_Pfw_Http::get_param( 'endAfterPeriod', 'int' ) );
	}

	public static function terms_edit_meta( $term_id, $tt_id ) {
		if ( ! check_admin_referer( 'nuvei-term-nonce', 'nuveiTermNonce' ) ) {
			Nuvei_Pfw_Logger::write( '', 'Cannot validate admin nonce.', 'WARN' );
			die( 'Cannot validate admin nonce.' );
		}

		$taxonomy      = 'pa_' . Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME );
		$post_taxonomy = Nuvei_Pfw_Http::get_param( 'taxonomy', 'string' );

		if ( $post_taxonomy != $taxonomy ) {
			return;
		}

		update_term_meta( $term_id, 'planId', Nuvei_Pfw_Http::get_param( 'planId', 'int' ) );
		update_term_meta( $term_id, 'recurringAmount', Nuvei_Pfw_Http::get_param( 'recurringAmount', 'float' ) );

		update_term_meta( $term_id, 'startAfterUnit', Nuvei_Pfw_Http::get_param( 'startAfterUnit', 'string' ) );
		update_term_meta( $term_id, 'startAfterPeriod', Nuvei_Pfw_Http::get_param( 'startAfterPeriod', 'int' ) );

		update_term_meta( $term_id, 'recurringPeriodUnit', Nuvei_Pfw_Http::get_param( 'recurringPeriodUnit', 'string' ) );
		update_term_meta( $term_id, 'recurringPeriodPeriod', Nuvei_Pfw_Http::get_param( 'recurringPeriodPeriod', 'int' ) );

		update_term_meta( $term_id, 'endAfterUnit', Nuvei_Pfw_Http::get_param( 'endAfterUnit', 'string' ) );
		update_term_meta( $term_id, 'endAfterPeriod', Nuvei_Pfw_Http::get_param( 'endAfterPeriod', 'int' ) );
	}

    /**
     * Modify the text on the thankyou page.
     * It is impossible to modify the title also. It will be done
     * in a JS file.
     *
     * @param string    $thank_you_text
     * @param WC_Order  $order
     * @return string
     */
	public static function thank_you_page_mod( $thank_you_text, $order ) {
	    if ( ! ($order instanceof WC_Order) || $order->get_payment_method() != NUVEI_PFW_GATEWAY_NAME ) {
			return;
		}

		$request_status = Nuvei_Pfw_Http::get_request_status();

        if ( 'error' == $request_status
			|| 'fail' == strtolower( Nuvei_Pfw_Http::get_param( 'ppp_status' ) )
            || 'canceled' == $request_status
        ) {
            // return the new message
			return esc_html__(
                'Please check your Order status for more information.',
                'nuvei-payments-for-woocommerce'
            );
		}

        return $thank_you_text;
	}

	/**
	 * For the custom baloon in Order column in the Order list.
	 */
	public static function order_list_columns_edit( $column, $col_id ) {
		// the column we put/edit baloons
		if ( ! in_array( $column, array( 'order_number', 'order_status' ) ) ) {
			return;
		}

		global $post;

		$order = wc_get_order( $post->ID );

		if ( $order->get_payment_method() != NUVEI_PFW_GATEWAY_NAME ) {
			return;
		}

		$all_meta      = $order->get_meta_data();
		$order_changes = $order->get_meta( NUVEI_PFW_ORDER_CHANGES ); // this is the flag for fraud
		$helper        = new Nuvei_Pfw_Helper();
		$subs_list     = $helper->get_rebiling_details( $all_meta );

		// put subscription baloon
		if ( 'order_number' == $column && ! empty( $subs_list ) ) {
			echo '<mark class="order-status status-processing tips" style="float: right;"><span>'
			. esc_html__( 'Nuvei Subscription', 'nuvei-payments-for-woocommerce' ) . '</span></mark>';
		}

		// edit status baloon
		if ( 'order_status' == $column
			&& ( ! empty( $order_changes['total_change'] ) || ! empty( $order_changes['curr_change'] ) )
		) {
			echo '<mark class="order-status status-on-hold tips" style="float: left; margin-right: 2px;" title="'
			. esc_html__( 'Please check transaction Total and Currency!', 'nuvei-payments-for-woocommerce' ) . '"><span>!</span></mark>';
		}
	}

	/**
	 * For the custom baloon in Order column in the Order list.
	 */
	public static function order_list_columns_edit_hpos( $column, $order ) {
		// the column we put/edit baloons
		if ( ! in_array( $column, array( 'order_number', 'order_status' ) ) ) {
			return;
		}

		if ( $order->get_payment_method() != NUVEI_PFW_GATEWAY_NAME ) {
			return;
		}

		$all_meta      = $order->get_meta_data();
		$order_changes = $order->get_meta( NUVEI_PFW_ORDER_CHANGES ); // this is the flag for fraud
		$helper        = new Nuvei_Pfw_Helper();
		$subs_list     = $helper->get_rebiling_details( $all_meta );

		// put subscription baloon
		if ( 'order_number' == $column && ! empty( $subs_list ) ) {
			echo '<mark class="order-status status-processing tips" style="float: right;"><span>'
			. esc_html__( 'Nuvei Subscription', 'nuvei-payments-for-woocommerce' ) . '</span></mark>';
		}

		// edit status baloon
		if ( 'order_status' == $column
			&& ( ! empty( $order_changes['total_change'] ) || ! empty( $order_changes['curr_change'] ) )
		) {
			echo '<mark class="order-status status-on-hold tips" style="float: left; margin-right: 2px;" title="'
			. esc_html__( 'Please check transaction Total and Currency!', 'nuvei-payments-for-woocommerce' ) . '"><span>!</span></mark>';
		}
	}

	/**
	 * In Store > My Account > Orders table, Order column
	 * add Rebilling icon for the Orders with Nuvei Payment Plan.
	 *
	 * @param WC_Order $order
	 */
	public static function my_account_orders_col_edit( $order ) {
		// get all meta fields
		$helper      = new Nuvei_Pfw_Helper();
		$post_meta   = $order->get_meta_data();
		$subscr_list = $helper->get_rebiling_details( $post_meta );
		$is_subscr   = ! empty( $subscr_list ) ? true : false;

		echo '<a href="' . esc_url( $order->get_view_order_url() ) . '"';

		if ( $is_subscr ) {
			echo ' class="nuvei_plan_order" title="' . esc_attr__( 'Nuvei Payment Plan Order', 'nuvei-payments-for-woocommerce' ) . '"';
		}

		echo '>#' . esc_html( $order->get_order_number() ) . '</a>';
	}

	/**
	 * Decide to override or not default WC behavior for Zero Total Order.
	 *
	 * @param boolean $needs_payment
	 * @param object  $cart
	 *
	 * @return boolean $needs_payment
	 */
	public static function wc_cart_needs_payment( $needs_payment, $cart ) {
		if ( 1 == self::$wc_nuvei->settings['allow_zero_checkout'] ) {
			return true;
		}

		$cart_items = $cart->get_cart();

		foreach ( $cart_items as $item ) {
			$cart_product   = wc_get_product( $item['product_id'] );
			$cart_prod_attr = $cart_product->get_attributes();

			// check for product with a payment plan
			if ( ! empty( $cart_prod_attr[ 'pa_' . Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME ) ] ) ) {
				return true;
			}
		}

		return $needs_payment;
	}

	/**
	 * Show custom data into order details, product data.
	 *
	 * @param int          $item_id
	 * @param object       $item
	 * @param WC_Product   $_product
	 *
	 * @return void
	 */
	public static function after_order_itemmeta( $item_id, $item, $_product ) {
		/*
		* Choose one of the get parameters.
		* Here we use GET paramteters, but because after Settle or Void some strings
		* can be added to the URL, type cast the parameters we need to clean them.
		*/
		$post_id = 0;

		//phpcs:ignore
		if ( isset( $_GET['post'] ) ) {
			//phpcs:ignore
			$post_id = (int) $_GET['post'];
			  //phpcs:ignore
		} elseif ( isset( $_GET['id'] ) ) {
			//phpcs:ignore
			$post_id = (int) $_GET['id'];
		}

		$order = wc_get_order( $post_id );

		if ( ! is_object( $order ) ) {
			Nuvei_Pfw_Logger::write( $post_id, 'There is a problem when try to get Order by ID', 'WARN' );
			return;
		}

		$helper      = new Nuvei_Pfw_Helper();
		$post_meta   = $order->get_meta_data();
		$subscr_list = $helper->get_rebiling_details( $post_meta );

		if ( empty( $post_meta ) || empty( $subscr_list ) ) {
			return;
		}

		foreach ( $subscr_list as $data ) {
			if ( 'WC_Order_Item_Product' != get_class( $item ) ) {
				continue;
			}

			// because of some delay this may not work, and have to refresh the page
			try {
				Nuvei_Pfw_Logger::write( 'check after_order_itemmeta' );

				// $subscr_data    = $order->get_meta($mk);
				// $key_parts      = explode('_', $mk);
				$subscr_data    = $data['subs_data'];
				$key_parts      = explode( '_', $data['subs_id'] );
				$item_variation = $item->get_variation_id();
				$product_id     = $item->get_product_id();
			} catch ( \Exception $ex ) {
				Nuvei_Pfw_Logger::write(
					array(
						'exception' => $ex->getMessage(),
						'item'      => $item,
					)
				);
				continue;
			}

			if ( empty( $subscr_data['state'] ) || empty( $subscr_data['subscr_id'] ) ) {
				// wait for meta data to be created
				continue;
			}

			// in case of variations item
			if ( ( 0 != $item_variation && 'variation' == $key_parts[2] && $item_variation == $key_parts[3] )
				// in case of attribute only item
				|| ( 0 == $item_variation && 'product' == $key_parts[2] && $product_id == $key_parts[3] )
			) {
				// show Subscr ID and Cancel Subsc button
				echo '<div class="wc-order-item-variation" style="margin-top: 0px;"><strong>'
				. esc_html__( 'Nuvei Subscription ID:', 'nuvei-payments-for-woocommerce' ) . '</strong> '
				. esc_html( $subscr_data['subscr_id'] ) . '</div>';

				if ( ! empty( $subscr_data['state'] ) && 'active' == $subscr_data['state'] ) {
					echo '<button id="nuvei_cancel_subs_' . esc_html( $subscr_data['subscr_id'] )
					. '" class="nuvei_cancel_subscr button generate-items" type="button" '
					. 'style="margin-top: .5em;" onclick="nuveiAction(\''
					. esc_html__( 'Are you sure, you want to cancel this subscription?', 'nuvei-payments-for-woocommerce' )
					. '\', \'cancelSubscr\', ' . esc_html( 0 ) . ', ' . esc_html( $subscr_data['subscr_id'] )
					. ')">' . esc_html__( 'Cancel Subscription', 'nuvei-payments-for-woocommerce' )
					. '</button>';
				}

				break;
			}
		}
	}

 	public static function checkout_order_processed($order) {
// 		if ( 0 == (float) $order->get_total() ) {
	    if ( $order instanceof WC_Order
	        && 0 == (float) $order->get_total()
	        && $order->get_payment_method() == NUVEI_PFW_GATEWAY_NAME
        ) {
			Nuvei_Pfw_Logger::write( 'hook woocommerce_blocks_checkout_order_processed - Zero Total Order.' );

			self::$wc_nuvei->process_payment( $order->get_id() );
		}
	}

	/**
	 * Display Nuvei message in the admin.
	 * At the moment we will use them only for auto-void warnings.
	 */
	public static function display_messages() {
        Nuvei_Pfw_Logger::write('display_messages');

		$messages           = get_option( 'custom_system_messages', array() );
        $permission_error   = get_option('nuvei_logs_permission_error', 0);

		if ( ! empty( $messages ) ) {
			foreach ( $messages as $index => $msg ) {
				if ( empty( $msg['created_by'] )
					|| 'nuvei_payments' != $msg['created_by']
					|| ! isset( $msg['read'] )
					|| $msg['read']
				) {
					continue;
				}

				echo '<div class="notice notice-info is-dismissible nuvei_payments_msg" data-index="' . esc_attr( $index ) . '">'
					. '<p>' . wp_kses_post( $msg['message'] ) . '</p>'
					. '</div>';
			}
		}

		if ( 1 == $permission_error ) {
	       echo '<div class="notice notice-error is-dismissible"><p>'
	           . esc_html__('Nuvei Payments for WooCommerce: Could not create the log directory or .htaccess or index.html file in wp-content/uploads/nuvei-logs. Please check your permissions. Logging may not work properly.', 'nuvei-payments-for-woocommerce')
		      . '</p></div>';
		}
	}

	/**
	 * On activate.
	 * Deactivate the old version plugin who use index.php file.
	 * Try to create custom logs directory and few files.
     * 
     * We cannot log in this method!
	 */
	public static function on_plugin_activate() {
		// try to deactivate the version with index.php
		try {
			deactivate_plugins( basename( __DIR__ ) . '/index.php' );
		} catch ( Exception $ex ) {
			// if fail then this plugin not exists
		}

		$htaccess_file    = NUVEI_PFW_LOGS_DIR . '.htaccess';
		$index_file       = NUVEI_PFW_LOGS_DIR . 'index.html';
		$wp_fs_direct     = new WP_Filesystem_Direct( null );
		$error            = false;

		if ( ! defined( 'FS_CHMOD_DIR' ) || ! defined( 'FS_CHMOD_FILE' ) ) {
			include_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// if there is no logs directory try to create it.
		if ( ! is_dir( NUVEI_PFW_LOGS_DIR ) ) {
		    if ( ! $wp_fs_direct->mkdir( NUVEI_PFW_LOGS_DIR ) ) {
		        $error = true;
		    }
		}

		// if the directory exists
		if ( ! $error && is_dir( NUVEI_PFW_LOGS_DIR ) ) {
		    // try to create .htaccess file
			if ( ! file_exists( $htaccess_file ) ) {
 			    if ( ! $wp_fs_direct->put_contents( $htaccess_file, 'deny from all' ) ) {
			        $error = true;
			    }
			}

			// try to create index.html file
			if ( ! file_exists( $index_file ) ) {
			    if ( ! $wp_fs_direct->put_contents( $index_file, '' ) ) {
			        $error = true;
			    }
			}
		}

		// set flag, as plugin option, to show error message in the admin
		if ( $error ) {
		    update_option('nuvei_logs_permission_error', 1);
		}
		else {
		    delete_option('nuvei_logs_permission_error');
		}

        return;
	}

	public static function register_plugin_rest_endpoint () {
		register_rest_route(
			'wc',
			'/nuvei',
			array(
				'methods'             => WP_REST_Server::ALLMETHODS,
				'callback'            => array ( __CLASS__, 'rest_method' ),
				'permission_callback' => function () {
					return ( is_user_logged_in() && current_user_can( 'activate_plugins' ) );
				},
			)
		);
	}

	/**
	 * The method who is responsible for REST API requests to the plugin.
	 *
	 * @param object $request_data
	 * @return \WP_REST_Response
	 */
	public static function rest_method( $request_data ) {
		Nuvei_Pfw_Logger::write( 'rest_method' );

		$wc_nuvei = new Nuvei_Pfw_Gateway();
		$params   = $request_data->get_params();

		// error
		if ( empty( $params['action'] ) ) {
			$res = new WP_REST_Response(
				array(
					'code'    => 'unknown_action',
					'message' => __( 'The action you require is unknown.', 'nuvei-payments-for-woocommerce' ),
					'data'    => array( 'status' => 405 ),
				)
			);
			$res->set_status( 405 );

			return $res;
		}

		if ( 'get-simply-connect-data' == $params['action'] ) {
			$resp = $wc_nuvei->rest_get_simply_connect_data( $params );

			$rest_resp = new WP_REST_Response( $resp );
			$rest_resp->set_status( 200 );
			return $rest_resp;
		}

		if ( 'get-cashier-link' == $params['action'] ) {
			$resp = $wc_nuvei->rest_get_cashier_link( $params );

			$rest_resp = new WP_REST_Response( $resp );
			$rest_resp->set_status( 200 );
			return $rest_resp;
		}

		$res = new WP_REST_Response(
			array(
				'code'    => 'unknown_action',
				'message' => __( 'The action you require is unknown.', 'nuvei-payments-for-woocommerce' ),
				'data'    => array( 'status' => 405 ),
			)
		);
		$res->set_status( 405 );

		return $rest_resp;
	}

}
