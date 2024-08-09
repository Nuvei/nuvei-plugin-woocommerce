<?php
/**
 * Plugin Name: Nuvei Payments for Woocommerce
 * Plugin URI: https://github.com/Nuvei/nuvei-plugin-woocommerce
 * Description: Nuvei Gateway for WooCommerce
 * Version: 3.2.1
 * Author: Nuvei
 * Author: URI: https://nuvei.com
 * License: GPLv2
 * Text Domain: nuvei-payments-for-woocommerce
 * Domain Path: /languages
 * Require at least: 4.7
 * Tested up to: 6.6.1
 * Requires Plugins: woocommerce
 * WC requires at least: 3.0
 * WC tested up to: 9.1.4
*/

defined( 'ABSPATH' ) || die( 'die' );

if ( ! defined( 'NUVEI_PFW_PLUGIN_FILE' ) ) {
	define( 'NUVEI_PFW_PLUGIN_FILE', __FILE__ );
}

require_once 'config.php';
require_once 'includes' . DIRECTORY_SEPARATOR . 'class-nuvei-pfw-autoloader.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php'; // we use it to get the data from the comment at the top
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

$wc_nuvei = null;

add_action( 'plugins_loaded', 'nuvei_pfw_init', 0 );

register_activation_hook( __FILE__, 'nuvei_pfw_plugin_activate' );

add_filter( 'woocommerce_payment_gateways', 'nuvei_pfw_add_gateway' );

// declare compatabilities
add_action(
	'before_woocommerce_init',
	function () {
		// declaration for HPOS compatability
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}

		// Declare compatibility for 'cart_checkout_blocks'
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

// Hook in Blocks integration.
add_action(
	'woocommerce_blocks_loaded',
	function () {
		// Check if the required class exists
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		// Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				// Register an instance of My_Custom_Gateway_Blocks
				$payment_method_registry->register( new Nuvei_Pfw_Gateway_Blocks_Support() );
			}
		);
	}
);

// register the plugin REST endpoint
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'wc',
			'/nuvei',
			array(
				'methods'               => WP_REST_Server::ALLMETHODS,
				'callback'              => 'nuvei_pfw_rest_method',
				'permission_callback'   => function () {
					return ( is_user_logged_in() && current_user_can( 'activate_plugins' ) );
				},
			)
		);
	}
);

/**
 * On activate try to create custom logs directory and few files.
 */
function nuvei_pfw_plugin_activate() {
	$htaccess_file  = NUVEI_PFW_LOGS_DIR . '.htaccess';
	$index_file     = NUVEI_PFW_LOGS_DIR . 'index.html';
	$wp_fs_direct   = new WP_Filesystem_Direct( null );

	if ( ! is_dir( NUVEI_PFW_LOGS_DIR ) ) {
		$wp_fs_direct->mkdir( NUVEI_PFW_LOGS_DIR );
	}

	if ( is_dir( NUVEI_PFW_LOGS_DIR ) ) {
		if ( ! file_exists( $htaccess_file ) ) {
			$wp_fs_direct->put_contents( $htaccess_file, 'deny from all' );
		}

		if ( ! file_exists( $index_file ) ) {
			$wp_fs_direct->put_contents( $htaccess_file, '' );
		}
	}
}

function nuvei_pfw_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	load_plugin_textdomain(
		'nuvei-payments-for-woocommerce',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages/'
	);

	global $wc_nuvei;
	$wc_nuvei = new Nuvei_Pfw_Gateway();

	add_action( 'init', 'nuvei_pfw_enqueue' );

	// load front-end scripts
	add_filter( 'wp_enqueue_scripts', 'nuvei_pfw_load_scripts' );
	// load front-end styles
	add_filter( 'woocommerce_enqueue_styles', 'nuvei_pfw_load_styles' );

	// add admin style
	add_filter( 'admin_enqueue_scripts', 'nuvei_pfw_load_admin_styles_scripts' );

	// add void and/or settle buttons to completed orders
	add_action( 'woocommerce_order_item_add_action_buttons', 'nuvei_pfw_add_buttons', 10, 1 );

	// handle custom Ajax calls
	add_action( 'wp_ajax_sc-ajax-action', 'nuvei_pfw_ajax_action' );
	add_action( 'wp_ajax_nopriv_sc-ajax-action', 'nuvei_pfw_ajax_action' );

	// On checkout form validation success get order details. Works on Classic Checkout only!
	add_action(
		'woocommerce_after_checkout_validation',
		function ( $data, $errors ) {
			global $wc_nuvei;
            
            Nuvei_Pfw_Logger::write([$data, $errors], 'action woocommerce_after_checkout_validation 1' );

			if ( empty( $errors->errors )
                && NUVEI_PFW_GATEWAY_NAME == $data['payment_method']
                && empty( Nuvei_Pfw_Http::get_param( 'nuvei_transaction_id' ) )
			) {
				if ( isset( $wc_nuvei->settings['integration_type'] )
                    && 'cashier' != $wc_nuvei->settings['integration_type']
				) {
					Nuvei_Pfw_Logger::write( 'action woocommerce_after_checkout_validation' );

					$wc_nuvei->call_checkout();
				}
			}
		},
		9999,
		2
	);

	/**
     * TODO - remove this hook.
     * use this to change button text, because of the cache the jQuery not always works
     */
//	add_filter( 'woocommerce_order_button_text', 'nuvei_pfw_edit_order_buttons' );
    
    // when the client click Pay button on the Order from My Account -> Orders menu.
	add_filter( 'woocommerce_pay_order_after_submit', 'nuvei_pfw_user_orders' );

    // Show some custom meassages if need to.
	if ( ! empty( Nuvei_Pfw_Http::get_param('sc_msg')) ) {
		add_filter( 'woocommerce_before_cart', 'nuvei_pfw_show_message_on_cart', 10, 2 );
	}

	# Payment Plans taxonomies
	// extend Term form to add meta data
	add_action( 'pa_' . Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME ) . '_add_form_fields', 'nuvei_pfw_add_term_fields_form', 10, 2 );
	// update Terms' meta data form
	add_action( 'pa_' . Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME ) . '_edit_form_fields', 'nuvei_pfw_edit_term_meta_form', 10, 2 );
	// hook to catch our meta data and save it
	add_action( 'created_pa_' . Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME ), 'nuvei_pfw_save_term_meta', 10, 2 );
	// edit Term meta data
	add_action( 'edited_pa_' . Nuvei_Pfw_String::get_slug( NUVEI_PFW_GLOB_ATTR_NAME ), 'nuvei_pfw_edit_term_meta', 10, 2 );
	// before add a product to the cart
	add_filter( 'woocommerce_add_to_cart_validation', array( $wc_nuvei, 'add_to_cart_validation' ), 10, 3 );
	// Hide payment gateways in case of product with Nuvei Payment plan in the Cart
	add_filter( 'woocommerce_available_payment_gateways', array( $wc_nuvei, 'hide_payment_gateways' ), 100, 1 );

	// those actions are valid only when the plugin is enabled
	$plugin_enabled = isset( $wc_nuvei->settings['enabled'] ) ? $wc_nuvei->settings['enabled'] : 'no';

	if ( 'no' == $plugin_enabled ) {
		return;
	}

	// for the thank-you page
	add_action( 'woocommerce_thankyou', 'nuvei_pfw_mod_thank_you_page', 100, 1 );

	# For the custom column in the Order list
	// legacy
	add_action( 'manage_shop_order_posts_custom_column', 'nuvei_pfw_edit_order_list_columns', 10, 2 );
	// HPOS
	add_action( 'woocommerce_shop_order_list_table_custom_column', 'nuvei_pfw_hpos_edit_order_list_columns', 10, 2 );

	// for the Store > My Account > Orders list
	add_action( 'woocommerce_my_account_my_orders_column_order-number', 'nuvei_pfw_edit_my_account_orders_col' );
	// show payment methods on checkout when total is 0
	add_filter( 'woocommerce_cart_needs_payment', 'nuvei_pfw_wc_cart_needs_payment', 10, 2 );
	// show custom data into order details, product data
	add_action( 'woocommerce_after_order_itemmeta', 'nuvei_pfw_after_order_itemmeta', 10, 3 );
	// listent for the WC Subscription Payment
	add_action(
		'woocommerce_scheduled_subscription_payment_' . NUVEI_PFW_GATEWAY_NAME,
		array( $wc_nuvei, 'create_wc_subscr_order' ),
		10,
		2
	);
    
    // mark the nuvei-checkout-blocks.js as module
//    add_filter('script_loader_tag', function($tag, $handle, $src) {
//        if ( 'nuvei-checkout-blocks' !== $handle ) {
//            return $tag;
//        }
//        
//        // replace the type of the script here
//    } , 10, 3);
    
    // Add this hook to catch Zero Total Orders in WC Blocks. The others still go through process_payment().
    add_action( 'woocommerce_blocks_checkout_order_processed', function($order ) {
        global $wc_nuvei;
        $total = (float) $order->get_total();
        
        if (0 == $total) {
            Nuvei_Pfw_Logger::write('hook woocommerce_blocks_checkout_order_processed - Zero Total Order.');
            
            $wc_nuvei->process_payment($order->get_id());
        }
    }, 10 );
    
    add_action( 'nuvei_pfwc_after_rebilling_payment', function() {
        Nuvei_Pfw_Logger::write('nuvei_pfwc_after_rebilling_payment do some action here');
    } );
    
}

/**
 * Main function for the Ajax requests.
 */
function nuvei_pfw_ajax_action() {
	if ( ! check_ajax_referer( 'sc-security-nonce', 'security', false ) ) {
		wp_send_json_error( __( 'Invalid security token sent.', 'nuvei-payments-for-woocommerce' ) );
		wp_die( 'Invalid security token sent' );
	}

	global $wc_nuvei;

	if ( empty( $wc_nuvei->settings['test'] ) ) {
		wp_send_json_error( __( 'Invalid site mode.', 'nuvei-payments-for-woocommerce' ) );
		wp_die( 'Invalid site mode.' );
	}

	$order_id = Nuvei_Pfw_Http::get_param( 'orderId', 'int' );

	# recognize the action:
    // Get Blocks Checkout data
    if (Nuvei_Pfw_Http::get_param( 'getBlocksCheckoutData', 'int' ) == 1 ) {
        // Simply Connect flow
        if ('sdk' == $wc_nuvei->settings['integration_type']) {
            wp_send_json($wc_nuvei->call_checkout(false, true));
            exit;
        }
        
        // Cashier flow
        if ('cashier' == $wc_nuvei->settings['integration_type']) {
            // TODO
            exit;
        }
            
        exit;
    }
    
	// Void (Cancel)
	if ( Nuvei_Pfw_Http::get_param( 'cancelOrder', 'int' ) == 1 && $order_id > 0 ) {
		$nuvei_settle_void = new Nuvei_Pfw_Settle_Void( $wc_nuvei->settings );
		$nuvei_settle_void->create_settle_void( sanitize_text_field( $order_id ), 'void' );
	}

	// Settle
	if ( Nuvei_Pfw_Http::get_param( 'settleOrder', 'int' ) == 1 && $order_id > 0 ) {
		$nuvei_settle_void = new Nuvei_Pfw_Settle_Void( $wc_nuvei->settings );
		$nuvei_settle_void->create_settle_void( sanitize_text_field( $order_id ), 'settle' );
	}

	// Refund
	if ( Nuvei_Pfw_Http::get_param( 'refAmount', 'float' ) != 0 ) {
		$nuvei_refund = new Nuvei_Pfw_Refund( $wc_nuvei->settings );
		$nuvei_refund->create_refund_request(
			Nuvei_Pfw_Http::get_param( 'postId', 'int' ),
			Nuvei_Pfw_Http::get_param( 'refAmount', 'float' )
		);
	}

	// Cancel Subscription
	if ( Nuvei_Pfw_Http::get_param( 'cancelSubs', 'int' ) == 1
		&& ! empty( Nuvei_Pfw_Http::get_param( 'subscrId', 'int' ) )
	) {
		$subscription_id    = Nuvei_Pfw_Http::get_param( 'subscrId', 'int' );
		$order              = wc_get_order( Nuvei_Pfw_Http::get_param( 'orderId', 'int' ) );

		$nuvei_class    = new Nuvei_Pfw_Subscription_Cancel( $wc_nuvei->settings );
		$resp           = $nuvei_class->process( array( 'subscriptionId' => $subscription_id ) );
		$ord_status     = 0;

		if ( ! empty( $resp['status'] ) && 'SUCCESS' == $resp['status'] ) {
			$ord_status = 1;
		}

		wp_send_json(
			array(
				'status'    => $ord_status,
				'data'      => $resp,
			)
		);
		exit;
	}

	// Check Cart on SDK pre-payment event
	if ( Nuvei_Pfw_Http::get_param( 'prePayment', 'int' ) == 1 ) {
		$wc_nuvei->checkout_prepayment_check();
	}

	// when Reorder
	if ( Nuvei_Pfw_Http::get_param( 'sc_request' ) == 'scReorder' ) {
		$wc_nuvei->reorder();
	}

	// download Subscriptions Plans
	if ( Nuvei_Pfw_Http::get_param( 'downloadPlans', 'int' ) == 1 ) {
		$wc_nuvei->download_subscr_pans();
	}
    
    // when need data to pay Existing Order for Simply Connect flow
    if ( Nuvei_Pfw_Http::get_param('payForExistingOrder', 'int') == 1 
        && Nuvei_Pfw_Http::get_param('orderId', 'int') > 0
        && 'sdk' == $wc_nuvei->settings['integration_type']
    ) {
        $params = $wc_nuvei->call_checkout(false, true, Nuvei_Pfw_Http::get_param('orderId', 'int'));

        wp_send_json($params);
        wp_die();
    }

	wp_send_json_error( __( 'Not recognized Ajax call.', 'nuvei-payments-for-woocommerce' ) );
	wp_die();
}

/**
* Add the Gateway to WooCommerce
**/
function nuvei_pfw_add_gateway( $methods ) {
	$methods[] = 'Nuvei_Pfw_Gateway'; // get the name of the Gateway Class
	return $methods;
}

/**
 * Loads public scripts
 *
 * @global Nuvei_Pfw_Gateway $wc_nuvei
 * @global type $wpdb
 *
 * @return void
 */
function nuvei_pfw_load_scripts() {
	if ( ! is_checkout() ) {
		return;
	}

	global $wc_nuvei;
	global $wpdb;

	$plugin_url = plugin_dir_url( __FILE__ );
    
    // load the SDK
    wp_register_script(
		'nuvei_checkout_sdk',
		NUVEI_PFW_SIMPLY_CONNECT_PATH . 'simplyConnect.js',
		array('jquery'),
		'1.140.0',
        false
	);

	// main JS
	wp_register_script(
		'nuvei_js_public',
		$plugin_url . 'assets/js/nuvei_public.js',
		array( 'jquery' ),
		'2024-07-30',
		false
	);
    
	// put translations here into the array
	$localizations = array_merge(
		NUVEI_PFW_JS_LOCALIZATIONS,
		array(
			'security'              => wp_create_nonce( 'sc-security-nonce' ),
			'wcThSep'               => get_option('woocommerce_price_thousand_sep'),
            'wcDecSep'              => get_option('woocommerce_price_decimal_sep'),
			'useUpos'               => $wc_nuvei->can_use_upos(),
			'isUserLogged'          => is_user_logged_in() ? 1 : 0,
			'isPluginActive'        => $wc_nuvei->settings['enabled'],
			'loaderUrl'             => plugin_dir_url( __FILE__ ) . 'assets/icons/loader.gif',
			'checkoutIntegration'   => $wc_nuvei->settings['integration_type'],
			'webMasterId'           => 'WooCommerce ' . WOOCOMMERCE_VERSION
				. '; Plugin v' . nuvei_pfw_get_plugin_version(),
		)
	);

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
 * @param type $styles
 *
 * @return void
 */
function nuvei_pfw_load_styles( $styles ) {
	if ( ! is_checkout() ) {
		return $styles;
	}

	global $wc_nuvei;
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
function nuvei_pfw_load_admin_styles_scripts( $hook ) {
	$plugin_url = plugin_dir_url( __FILE__ );

//	if ( 'post.php' == $hook ) {
		wp_register_style(
			'nuvei_admin_style',
			$plugin_url . 'assets/css/nuvei_admin_style.css',
			'',
			1,
			'all'
		);
		wp_enqueue_style( 'nuvei_admin_style' );
//	}

	// main JS
	wp_register_script(
		'nuvei_js_admin',
		$plugin_url . 'assets/js/nuvei_admin.js',
		array( 'jquery' ),
		'2024-07-30',
		true
	);
    
	// get the list of the plans
	$nuvei_plans_path   = NUVEI_PFW_LOGS_DIR . NUVEI_PFW_PLANS_FILE;
	$plans_list         = json_encode(array());
	$wp_fs_direct       = new WP_Filesystem_Direct( null );

	if ( is_readable( $nuvei_plans_path ) ) {
		$plans_list = stripslashes( $wp_fs_direct->get_contents( $nuvei_plans_path ) );
	}
	// get the list of the plans end

	// put translations here into the array
	$localizations = array_merge(
		NUVEI_PFW_JS_LOCALIZATIONS,
		array(
			'security'          => wp_create_nonce( 'sc-security-nonce' ),
			'nuveiPaymentPlans' => $plans_list,
			'webMasterId'       => 'WooCommerce ' . WOOCOMMERCE_VERSION
				. '; Plugin v' . nuvei_pfw_get_plugin_version(),
		)
	);

	wp_localize_script( 'nuvei_js_admin', 'scTrans', $localizations );
	wp_enqueue_script( 'nuvei_js_admin' );
}
# Load Styles and Scripts END

// first method we come in
function nuvei_pfw_enqueue( $hook ) {
	# DMNs catch
	// sc_listener is legacy value
	if ( in_array( Nuvei_Pfw_Http::get_param( 'wc-api' ), array( 'sc_listener', 'nuvei_listener' ) ) ) {
		add_action(
			'wp_loaded',
			function () {
				$nuvei_notify_dmn = new Nuvei_Pfw_Notify_Url();
				$nuvei_notify_dmn->process();
			}
		);
	}
}

/**
 * Add buttons for the Nuvei Order actions in Order details page.
 *
 * @global Order $order
 * @return type
 */
function nuvei_pfw_add_buttons( $order ) {
	// error
	if ( ! is_a( $order, 'WC_Order' ) || is_a( $order, 'WC_Subscription' ) ) {
		return false;
	}

	Nuvei_Pfw_Logger::write( 'nuvei_pfw_add_buttons' );

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
	$order_id       = $order->get_id();
	$helper         = new Nuvei_Pfw_Helper();
	$ord_tr_id      = $helper->helper_get_tr_id( $order_id );
	$order_total    = $order->get_total();
	$order_data     = $order->get_meta( NUVEI_PFW_TRANSACTIONS );
	$last_tr_data   = array();
	$order_refunds  = array();
	$ref_amount     = 0;
	$order_time     = 0;

	// error
	if ( empty( $ord_tr_id ) ) {
		Nuvei_Pfw_Logger::write( $ord_tr_id, 'Invalid Transaction ID! May be this post is not an Order.' );
		return false;
	}

	// error
	if ( empty( $order_data ) || ! is_array( $order_data ) ) {
		Nuvei_Pfw_Logger::write( $order_data, 'Missing or wrong Nuvei transactions data for the order.' );

		// disable refund button
        wp_add_inline_script(
            'nuvei_js_admin',
            'nuveiPfwDisableRefundBtn()',
            'after'
        );
        
		return false;
	}

	// get Refund transactions
	foreach ( array_reverse( $order_data ) as $tr ) {
		if ( isset( $tr['transactionType'], $tr['status'] )
			&& in_array( $tr['transactionType'], array( 'Credit', 'Refund' ) )
			&& 'approved' == strtolower( $tr['status'] )
		) {
			$order_refunds[]    = $tr;
			$ref_amount         += $tr['totalAmount'];
		}
	}

	$order_payment_method   = $helper->get_payment_method( $order_id );
	$last_tr_data           = end( $order_data );

	if ( ! is_null( $order->get_date_created() ) ) {
		$order_time = $order->get_date_created()->getTimestamp();
	}
	if ( ! is_null( $order->get_date_completed() ) ) {
		$order_time = $order->get_date_completed()->getTimestamp();
	}

	// hide Refund Button, it is visible by default
	if ( ! in_array( $order_payment_method, NUVEI_PFW_PMS_REFUND_VOID )
		|| ! in_array( $last_tr_data['transactionType'], array( 'Sale', 'Settle', 'Credit', 'Refund' ) )
		|| 'approved' != strtolower( $last_tr_data['status'] )
		|| 0 == $order_total
		|| $ref_amount >= $order_total
	) {
        wp_add_inline_script(
            'nuvei_js_admin',
            'nuveiPfwDisableRefundBtn()',
            'after'
        );
	}

	/**
	 * Error. If last transaction is not Approved.
	 *
	 * If the status is missing then DMN is not received or there is some error
	 * with the transaction.
	 * In case the Status is Pending this is an APM payment and the plugin still
	 * wait for approval DMN. Till then no actions are allowed.
	 * In above check we will hide the Refund button if last Transaction is
	 * Refund/Credit, but without Status.
	 */
	if ( empty( $last_tr_data['status'] ) || 'approved' != strtolower( $last_tr_data['status'] ) ) {
		Nuvei_Pfw_Logger::write( $last_tr_data, 'Last Transaction is not yet approved or the DMN didn\'t come yet.' );

		// disable refund button
        wp_add_inline_script(
            'nuvei_js_admin',
            'nuveiPfwDisableRefundBtn()',
            'after'
        );

		return false;
	}

	// Show VOID button
	if ( 'cc_card' == $order_payment_method
		&& empty( $order_refunds )
		&& in_array( $last_tr_data['transactionType'], array( 'Sale', 'Settle', 'Auth' ) )
		&& (float) $order_total > 0
		&& time() < $order_time + 172800 // 48 hours
	) {
		$question = sprintf(
			/* translators: %d is replaced with "decimal" */
			__( 'Are you sure, you want to Cancel Order #%d?', 'nuvei-payments-for-woocommerce' ),
			$order_id
		);

		// check for active subscriptions
		$all_meta       = $order->get_meta_data();
		$subscr_list    = $helper->get_rebiling_details( $all_meta );

		foreach ( $subscr_list as $meta_data ) {
			if ( ! empty( $meta_data['subs_data']['state'] )
				&& 'active' == $meta_data['subs_data']['state']
			) {
				$question = __( 'Are you sure, you want to Cancel this Order? This will also deactivate all Active Subscriptions.', 'nuvei-payments-for-woocommerce' );
				break;
			}
		}
		// /check for active subscriptions

		echo '<button id="sc_void_btn" type="button" onclick="nuveiAction(\''
				. esc_html( $question ) . '\', \'void\', ' . esc_html( $order_id )
				. ')" class="button generate-items">'
				. esc_html__( 'Void', 'nuvei-payments-for-woocommerce' ) . '</button>';
	}

	// show SETTLE button ONLY if transaction type IS Auth and the Total is not 0
	if ( 'Auth' == $last_tr_data['transactionType']
		&& $order_total > 0
	) {
		$question = sprintf(
			/* translators: %d is replaced with "decimal" */
			__( 'Are you sure, you want to Settle Order #%d?', 'nuvei-payments-for-woocommerce' ),
			$order_id
		);

		echo '<button id="sc_settle_btn" type="button" onclick="nuveiAction(\''
				. esc_html( $question )
				. '\', \'settle\', \'' . esc_html( $order_id ) . '\')" class="button generate-items">'
				. esc_html__( 'Settle', 'nuvei-payments-for-woocommerce' ) . '</button>';
	}

	// add loading screen
	echo '<div id="custom_loader" class="blockUI blockOverlay" style="height: 100%; position: absolute; top: 0px; width: 100%; z-index: 10; background-color: rgba(255,255,255,0.5); display: none;"></div>';
}

function nuvei_pfw_mod_thank_you_page( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( $order->get_payment_method() != NUVEI_PFW_GATEWAY_NAME ) {
		return;
	}

	# Modify title and the text on errors
    $output             = '';
    $new_title          = '';
    $remove_wcs_pay_btn = false;
	$request_status     = Nuvei_Pfw_Http::get_request_status();
	$new_msg            = esc_html__(
        'Please check your Order status for more information.',
        'nuvei-payments-for-woocommerce'
    );
	

	if ( 'error' == $request_status
		|| 'fail' == strtolower( Nuvei_Pfw_Http::get_param( 'ppp_status' ) )
	) {
		$new_title  = esc_html__( 'Order error', 'nuvei-payments-for-woocommerce' );
	} elseif ( 'canceled' == $request_status ) {
		$new_title  = esc_html__( 'Order canceled', 'nuvei-payments-for-woocommerce' );
	}

//	if ( ! empty( $new_title ) ) {
//		$output .= 'jQuery(".entry-title").html("' . $new_title . '"); '
//			. 'jQuery(".woocommerce-thankyou-order-received").html("' . $new_msg . '"); ';
//	}
	# /Modify title and the text on errors

	# when WCS is turn on, remove Pay button
	if ( is_plugin_active( 'woocommerce-subscriptions' . DIRECTORY_SEPARATOR . 'woocommerce-subscriptions.php' ) ) {
        $removeWCSPayBtn = true;
//		$output .= 'if (0 == jQuery("a.pay").length) { jQuery("a.pay").hide(); }';
	}

    wp_add_inline_script(
        'nuvei_js_public',
        $output,
        'nuveiPfwChangeThankYouPageMsg("'. $new_title .'", "'. $new_msg .'", '. $remove_wcs_pay_btn .');',
        'after'
    );
}

/**
 * @return string
 * 
 * @deprecated since version 3.1.1
 */
function nuvei_pfw_edit_order_buttons() {
	$default_text          = __( 'Place order', 'nuvei-payments-for-woocommerce' );
	$sc_continue_text      = __( 'Continue', 'nuvei-payments-for-woocommerce' );
	$chosen_payment_method = WC()->session->get( 'chosen_payment_method' );

	// save default text into button attribute
    wp_add_inline_script(
        'nuvei_js_public',
        'jQuery("#place_order").attr("data-default-text", "' .  $default_text .'").attr("data-sc-text", "' . $sc_continue_text . '"); });'
    );

	// check for 'sc' also, because of the older Orders
	if ( in_array( $chosen_payment_method, array( NUVEI_PFW_GATEWAY_NAME, 'sc' ) ) ) {
		return $sc_continue_text;
	}

	return $default_text;
}

/**
 * 
 * @param string $title
 * @param int $id
 * @return string
 * 
 * @deprecated since version 3.2.0 This function is not used.
 */
function nuvei_pfw_change_title_order_received( $title, $id ) {
	if ( function_exists( 'is_order_received_page' )
		&& is_order_received_page()
		&& get_the_ID() === $id
	) {
		$title = esc_html__( 'Order error', 'nuvei-payments-for-woocommerce' );
	}

	return $title;
}

/**
 * When the client click Pay button on the Order from My Account -> Orders menu.
 * This Order was created in the store admin, from some of the admins (merchants).
 *
 * @global type $wp
 * @global Nuvei_Pfw_Gateway $wc_nuvei
 * 
 * @return void
 */
function nuvei_pfw_user_orders() {
    Nuvei_Pfw_Logger::write('nuvei_pfw_user_orders()');
	
    global $wp;
    global $wc_nuvei;
    
    //  for Cashier - just don't touch it! It works by default. :D
    if ( isset($wc_nuvei->settings['integration_type']) 
        && 'cashier' == $wc_nuvei->settings['integration_type']
    ) {
        return;
    }

    $order_id   = $wp->query_vars['order-pay'];
	$order      = wc_get_order( $order_id );
	$order_key  = $order->get_order_key();
    
    // error
	if ( Nuvei_Pfw_Http::get_param( 'key' ) != $order_key ) {
        Nuvei_Pfw_Logger::write(
            [
                'param key' => Nuvei_Pfw_Http::get_param( 'key' ),
                '$order_key' => $order_key
            ],
            'Order key problem.'
        );
        
		return;
	}

    // Pass the orderId in custom element, as approve to use Simply Connect logic later.
    echo '<input type="hidden" id="nuveiPayForExistingOrder" value="'. esc_attr($order_id) .'" />';
}

// on reorder, show warning message to the cart if need to
function nuvei_pfw_show_message_on_cart( $data ) {
    wp_add_inline_script(
        'nuvei_js_public',
        'jQuery("#content .woocommerce:first").append("<div class=\'woocommerce-warning\'>' . wp_kses_post( Nuvei_Pfw_Http::get_param( 'sc_msg' ) ) . '</div>");',
        'after'
    );
}

// Attributes, Terms and Meta functions
function nuvei_pfw_add_term_fields_form( $taxonomy ) {
	$nuvei_plans_path   = NUVEI_PFW_LOGS_DIR . NUVEI_PFW_PLANS_FILE;
	$wp_fs_direct       = new WP_Filesystem_Direct( null );

	ob_start();

	$plans_list = array();
	if ( is_readable( $nuvei_plans_path ) ) {
		$plans_list = wp_json_file_decode(
			$nuvei_plans_path,
			array( 'associative' => true )
		);
	}

	require_once __DIR__ . DIRECTORY_SEPARATOR . 'templates/admin/add-terms-form.php';

	ob_end_flush();
}

function nuvei_pfw_edit_term_meta_form( $term, $taxonomy ) {
	$nuvei_plans_path   = NUVEI_PFW_LOGS_DIR . NUVEI_PFW_PLANS_FILE;
	$wp_fs_direct       = new WP_Filesystem_Direct( null );

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

	require_once __DIR__ . DIRECTORY_SEPARATOR . 'templates/admin/edit-term-form.php';
	ob_end_flush();
}

function nuvei_pfw_save_term_meta( $term_id, $tt_id ) {
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

function nuvei_pfw_edit_term_meta( $term_id, $tt_id ) {
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
// Attributes, Terms and Meta functions END

/**
 * For the custom baloon in Order column in the Order list.
 */
function nuvei_pfw_edit_order_list_columns( $column, $col_id ) {
	// the column we put/edit baloons
	if ( ! in_array( $column, array( 'order_number', 'order_status' ) ) ) {
		return;
	}

	global $post;

	$order = wc_get_order( $post->ID );

	if ( $order->get_payment_method() != NUVEI_PFW_GATEWAY_NAME ) {
		return;
	}

	$all_meta       = $order->get_meta_data();
	$order_changes  = $order->get_meta( NUVEI_PFW_ORDER_CHANGES ); // this is the flag for fraud
	$helper         = new Nuvei_Pfw_Helper();
	$subs_list      = $helper->get_rebiling_details( $all_meta );

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
function nuvei_pfw_hpos_edit_order_list_columns( $column, $order ) {
	// the column we put/edit baloons
	if ( ! in_array( $column, array( 'order_number', 'order_status' ) ) ) {
		return;
	}

	if ( $order->get_payment_method() != NUVEI_PFW_GATEWAY_NAME ) {
		return;
	}

	$all_meta       = $order->get_meta_data();
	$order_changes  = $order->get_meta( NUVEI_PFW_ORDER_CHANGES ); // this is the flag for fraud
	$helper         = new Nuvei_Pfw_Helper();
	$subs_list      = $helper->get_rebiling_details( $all_meta );

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
function nuvei_pfw_edit_my_account_orders_col( $order ) {
	// get all meta fields
	$helper         = new Nuvei_Pfw_Helper();
	$post_meta      = $order->get_meta_data();
	$subscr_list    = $helper->get_rebiling_details( $post_meta );
	$is_subscr      = ! empty( $subscr_list ) ? true : false;

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
 * @param object $cart
 *
 * @return boolean $needs_payment
 */
function nuvei_pfw_wc_cart_needs_payment( $needs_payment, $cart ) {
	global $wc_nuvei;

	if ( 1 == $wc_nuvei->settings['allow_zero_checkout'] ) {
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
 * @param type $item_id
 * @param object $item
 * @param type $_product
 *
 * @return void
 */
function nuvei_pfw_after_order_itemmeta( $item_id, $item, $_product ) {
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

	$helper         = new Nuvei_Pfw_Helper();
	$post_meta      = $order->get_meta_data();
	$subscr_list    = $helper->get_rebiling_details( $post_meta );

	if ( empty( $post_meta ) || empty( $subscr_list ) ) {
		return;
	}

	foreach ( $subscr_list as $data ) {
		if ( 'WC_Order_Item_Product' != get_class( $item ) ) {
			continue;
		}

		// because of some delay this may not work, and have to refresh the page
		try {
			Nuvei_Pfw_Logger::write( 'check nuvei_pfw_after_order_itemmeta' );

			//            $subscr_data    = $order->get_meta($mk);
			//            $key_parts      = explode('_', $mk);
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

/**
 * Get the plugin version.
 *
 * @return string
 */
function nuvei_pfw_get_plugin_version() {
	$plugin_data  = get_plugin_data( __FILE__ );
	return $plugin_data['Version'];
}

function nuvei_pfw_rest_method( $request_data ) {
	Nuvei_Pfw_Logger::write( 'nuvei_pfw_rest_method' );

	global $wc_nuvei;

	$wc_nuvei   = new Nuvei_Pfw_Gateway();
	$params     = $request_data->get_params();

	// error
	if ( empty( $params['action'] ) ) {
		$res = new WP_REST_Response(
			array(
				'code'      => 'unknown_action',
				'message'   => __( 'The action you require is unknown.', 'nuvei-payments-for-woocommerce' ),
				'data'      => array( 'status' => 405 ),
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
			'code'      => 'unknown_action',
			'message'   => __( 'The action you require is unknown.', 'nuvei-payments-for-woocommerce' ),
			'data'      => array( 'status' => 405 ),
		)
	);
	$res->set_status( 405 );

	return $rest_resp;
}
