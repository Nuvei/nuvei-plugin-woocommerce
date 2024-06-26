<?php
/**
 * Plugin Name: Nuvei Gateway for Woocommerce
 * Plugin URI: https://github.com/Nuvei/nuvei-plugin-woocommerce
 * Description: Nuvei Gateway for WooCommerce
 * Version: 3.0.1
 * Author: Nuvei
 * Author URI: https://nuvei.com
 * Text Domain: nuvei-checkout-for-woocommerce
 * Domain Path: /languages
 * Require at least: 4.7
 * Tested up to: 6.5.3
 * Requires Plugins: woocommerce
 * WC requires at least: 3.0
 * WC tested up to: 8.8.3
*/

defined( 'ABSPATH' ) || die( 'die' );

if ( ! defined( 'NUVEI_PLUGIN_FILE' ) ) {
	define( 'NUVEI_PLUGIN_FILE', __FILE__ );
}

require_once 'config.php';
require_once 'includes' . DIRECTORY_SEPARATOR . 'class-nuvei-autoloader.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php'; // we use it to get the data from the comment at the top
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

$wc_nuvei = null;

register_activation_hook( __FILE__, 'nuvei_plugin_activate' );

//add_action( 'admin_init', 'nuvei_admin_init' );
add_filter( 'woocommerce_payment_gateways', 'nuvei_add_gateway' );
add_action( 'plugins_loaded', 'nuvei_init', 0 );

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
				$payment_method_registry->register( new Nuvei_Gateway_Blocks_Support() );
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
				'callback'              => 'nuvei_rest_method',
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
function nuvei_plugin_activate() {
	$htaccess_file  = NUVEI_LOGS_DIR . '.htaccess';
	$index_file     = NUVEI_LOGS_DIR . 'index.html';
	$wp_fs_direct   = new WP_Filesystem_Direct( null );

	if ( ! is_dir( NUVEI_LOGS_DIR ) ) {
		$wp_fs_direct->mkdir( NUVEI_LOGS_DIR );
	}

	if ( is_dir( NUVEI_LOGS_DIR ) ) {
		if ( ! file_exists( $htaccess_file ) ) {
			$wp_fs_direct->put_contents( $htaccess_file, 'deny from all' );
		}

		if ( ! file_exists( $index_file ) ) {
			$wp_fs_direct->put_contents( $htaccess_file, '' );
		}
	}
}

/**
 * Check for SafeCharge version of the plugin.
 * Check for new version in the current Git repo.
 *
 * @deprecated since version 3.0.1
 */
function nuvei_admin_init() {
	try {
		// check if there is the version with "nuvei" in the name of directory,
		// in this case deactivate the current plugin
		$path_to_nuvei_plugin = plugin_dir_path( __FILE__ ) . 'index.php';

		if ( strpos( basename( __DIR__ ), 'safecharge' ) !== false
			&& file_exists( $path_to_nuvei_plugin )
		) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}

		// check in GIT for new version
		if ( ! session_id() ) {
			session_start(
				array(
					'cookie_lifetime'   => 86400, // a day
					'cookie_httponly'   => true,
				)
			);
		}

		$plugin_data  = get_plugin_data( __FILE__ );
		$curr_version = (int) str_replace( '.', '', $plugin_data['Version'] );
		$git_version  = 0;

		if ( ! empty( $_SESSION[ NUVEI_SESSION_PLUGIN_GIT_V ] ) ) {
			$git_version = $_SESSION[ NUVEI_SESSION_PLUGIN_GIT_V ];
		} else {
			$data = nuvei_get_file_form_git();

			if ( ! empty( $data['git_v'] ) ) {
				$_SESSION[ NUVEI_SESSION_PLUGIN_GIT_V ] = $data['git_v'];
				$git_version                            = $data['git_v'];
			}
		}

		// compare versions and show message if need to
		if ( $git_version > $curr_version ) {
			add_action(
				'admin_notices',
				function () {
					$class     = 'notice notice-info is-dismissible';
					$url       = 'https://github.com/Nuvei/nuvei-plugin-woocommerce/blob/main/CHANGELOG.md';
					$message_1 = __( 'There is a new version of Nuvei Plugin available.', 'nuvei-checkout-for-woocommerce' );
					$message_2 = __( 'View version details.', 'nuvei-checkout-for-woocommerce' );

					printf(
						'<div class="%1$s"><p>%2$s <a href="%3$s" target="_blank">%4$s</a></p></div>',
						esc_attr( $class ),
						esc_html( $message_1 ),
						esc_url( $url ),
						esc_html( $message_2 )
					);
				}
			);
		}
		// check in GIT for new version END
	} catch ( Exception $e ) {
		Nuvei_Logger::write( $e->getMessage(), 'Exception in admin init' );
	}
}

function nuvei_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	load_plugin_textdomain(
		'nuvei-checkout-for-woocommerce',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages/'
	);

	global $wc_nuvei;
	$wc_nuvei = new Nuvei_Gateway();

	add_action( 'init', 'nuvei_enqueue' );

	// load front-end scripts
	add_filter( 'wp_enqueue_scripts', 'nuvei_load_scripts' );
	// load front-end styles
	add_filter( 'woocommerce_enqueue_styles', 'nuvei_load_styles' );

	// add admin style
	add_filter( 'admin_enqueue_scripts', 'nuvei_load_admin_styles_scripts' );

	// add void and/or settle buttons to completed orders
	add_action( 'woocommerce_order_item_add_action_buttons', 'nuvei_add_buttons', 10, 1 );

	// handle custom Ajax calls
	add_action( 'wp_ajax_sc-ajax-action', 'nuvei_ajax_action' );
	add_action( 'wp_ajax_nopriv_sc-ajax-action', 'nuvei_ajax_action' );

	// if validation success get order details
	add_action(
		'woocommerce_after_checkout_validation',
		function ( $data, $errors ) {
			global $wc_nuvei;

			//        Nuvei_Logger::write($errors->errors, 'woocommerce_after_checkout_validation errors');
			//        Nuvei_Logger::write($_POST, 'woocommerce_after_checkout_validation post params');

			if ( empty( $errors->errors )
			&& NUVEI_GATEWAY_NAME == $data['payment_method']
			&& empty( Nuvei_Http::get_param( 'nuvei_transaction_id' ) )
			) {
				if ( isset( $wc_nuvei->settings['integration_type'] )
				&& 'cashier' != $wc_nuvei->settings['integration_type']
				) {
					Nuvei_Logger::write( null, 'action woocommerce_after_checkout_validation', 'DEBUG' );

					$wc_nuvei->call_checkout();
				}
			}
		},
		9999,
		2
	);

	// use this to change button text, because of the cache the jQuery not always works
	add_filter( 'woocommerce_order_button_text', 'nuvei_edit_order_buttons' );

	add_filter( 'woocommerce_pay_order_after_submit', 'nuvei_user_orders', 10, 2 );

    //phpcs:ignore
	if ( ! empty( $_GET['sc_msg'] ) ) {
		add_filter( 'woocommerce_before_cart', 'nuvei_show_message_on_cart', 10, 2 );
	}

	# Payment Plans taxonomies
	// extend Term form to add meta data
	add_action( 'pa_' . Nuvei_String::get_slug( NUVEI_GLOB_ATTR_NAME ) . '_add_form_fields', 'nuvei_add_term_fields_form', 10, 2 );
	// update Terms' meta data form
	add_action( 'pa_' . Nuvei_String::get_slug( NUVEI_GLOB_ATTR_NAME ) . '_edit_form_fields', 'nuvei_edit_term_meta_form', 10, 2 );
	// hook to catch our meta data and save it
	add_action( 'created_pa_' . Nuvei_String::get_slug( NUVEI_GLOB_ATTR_NAME ), 'nuvei_save_term_meta', 10, 2 );
	// edit Term meta data
	add_action( 'edited_pa_' . Nuvei_String::get_slug( NUVEI_GLOB_ATTR_NAME ), 'nuvei_edit_term_meta', 10, 2 );
	// before add a product to the cart
	add_filter( 'woocommerce_add_to_cart_validation', array( $wc_nuvei, 'add_to_cart_validation' ), 10, 3 );
	// Show/hide payment gateways in case of product with Nuvei Payment plan in the Cart
	add_filter( 'woocommerce_available_payment_gateways', array( $wc_nuvei, 'hide_payment_gateways' ), 100, 1 );

	// those actions are valid only when the plugin is enabled
	$plugin_enabled = isset( $wc_nuvei->settings['enabled'] ) ? $wc_nuvei->settings['enabled'] : 'no';

	if ( 'no' == $plugin_enabled ) {
		return;
	}

	// for WPML plugin
	//    if (is_plugin_active('sitepress-multilingual-cms' . DIRECTORY_SEPARATOR . 'sitepress.php')
	//        && 'yes' == $wc_nuvei->settings['use_wpml_thanks_page']
	//    ) {
	//        add_filter('woocommerce_get_checkout_order_received_url', 'nuvei_wpml_thank_you_page', 10, 2);
	//    }

	// for the thank-you page
	add_action( 'woocommerce_thankyou', 'nuvei_mod_thank_you_page', 100, 1 );

	# For the custom column in the Order list
	// legacy
	add_action( 'manage_shop_order_posts_custom_column', 'nuvei_edit_order_list_columns', 10, 2 );
	// HPOS
	add_action( 'woocommerce_shop_order_list_table_custom_column', 'nuvei_hpos_edit_order_list_columns', 10, 2 );

	// for the Store > My Account > Orders list
	add_action( 'woocommerce_my_account_my_orders_column_order-number', 'nuvei_edit_my_account_orders_col' );
	// show payment methods on checkout when total is 0
	add_filter( 'woocommerce_cart_needs_payment', 'nuvei_wc_cart_needs_payment', 10, 2 );
	// show custom data into order details, product data
	add_action( 'woocommerce_after_order_itemmeta', 'nuvei_after_order_itemmeta', 10, 3 );
	// listent for the WC Subscription Payment
	add_action(
		'woocommerce_scheduled_subscription_payment_' . NUVEI_GATEWAY_NAME,
		array( $wc_nuvei, 'create_wc_subscr_order' ),
		10,
		2
	);
}

/**
 * Main function for the Ajax requests.
 */
function nuvei_ajax_action() {
	if ( ! check_ajax_referer( 'sc-security-nonce', 'security', false ) ) {
		wp_send_json_error( __( 'Invalid security token sent.' ) );
		wp_die( 'Invalid security token sent' );
	}

	global $wc_nuvei;

	if ( empty( $wc_nuvei->settings['test'] ) ) {
		wp_send_json_error( __( 'Invalid site mode.' ) );
		wp_die( 'Invalid site mode.' );
	}

	$order_id = Nuvei_Http::get_param( 'orderId', 'int' );

	# recognize the action:
	// Void (Cancel)
	if ( Nuvei_Http::get_param( 'cancelOrder', 'int' ) == 1 && $order_id > 0 ) {
		$nuvei_settle_void = new Nuvei_Settle_Void( $wc_nuvei->settings );
		$nuvei_settle_void->create_settle_void( sanitize_text_field( $order_id ), 'void' );
	}

	// Settle
	if ( Nuvei_Http::get_param( 'settleOrder', 'int' ) == 1 && $order_id > 0 ) {
		$nuvei_settle_void = new Nuvei_Settle_Void( $wc_nuvei->settings );
		$nuvei_settle_void->create_settle_void( sanitize_text_field( $order_id ), 'settle' );
	}

	// Refund
	if ( Nuvei_Http::get_param( 'refAmount', 'float' ) != 0 ) {
		$nuvei_refund = new Nuvei_Refund( $wc_nuvei->settings );
		$nuvei_refund->create_refund_request(
			Nuvei_Http::get_param( 'postId', 'int' ),
			Nuvei_Http::get_param( 'refAmount', 'float' )
		);
	}

	// Cancel Subscription
	if ( Nuvei_Http::get_param( 'cancelSubs', 'int' ) == 1
		&& ! empty( Nuvei_Http::get_param( 'subscrId', 'int' ) )
	) {
		$subscription_id    = Nuvei_Http::get_param( 'subscrId', 'int' );
		$order              = wc_get_order( Nuvei_Http::get_param( 'orderId', 'int' ) );

		$nuvei_class    = new Nuvei_Subscription_Cancel( $wc_nuvei->settings );
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
	if ( Nuvei_Http::get_param( 'prePayment', 'int' ) == 1 ) {
		$wc_nuvei->checkout_prepayment_check();
	}

	// when Reorder
	if ( Nuvei_Http::get_param( 'sc_request' ) == 'scReorder' ) {
		$wc_nuvei->reorder();
	}

	// download Subscriptions Plans
	if ( Nuvei_Http::get_param( 'downloadPlans', 'int' ) == 1 ) {
		$wc_nuvei->download_subscr_pans();
	}

	wp_send_json_error( __( 'Not recognized Ajax call.', 'nuvei-checkout-for-woocommerce' ) );
	wp_die();
}

/**
* Add the Gateway to WooCommerce
**/
function nuvei_add_gateway( $methods ) {
	$methods[] = 'Nuvei_Gateway'; // get the name of the Gateway Class
	return $methods;
}

/**
 * Loads public scripts
 *
 * @global Nuvei_Gateway $wc_nuvei
 * @global type $wpdb
 *
 * @return void
 */
function nuvei_load_scripts() {
	if ( ! is_checkout() ) {
		return;
	}

	global $wc_nuvei;
	global $wpdb;

	$plugin_url = plugin_dir_url( __FILE__ );
	//
	//  if ( (isset($_SERVER['HTTPS']) && 'on' == $_SERVER['HTTPS'])
	//      && (isset($_SERVER['REQUEST_SCHEME']) && 'https' == $_SERVER['REQUEST_SCHEME'])
	//  ) {
	//      if (strpos($plugin_url, 'https') === false) {
	//          $plugin_url = str_replace('http:', 'https:', $plugin_url);
	//      }
	//  }
	//
	////    $sdkUrl = NUVEI_SDK_URL_PROD;
	//    $sdkUrl = $plugin_url . 'assets/js/nuveiSimplyConnect/simplyConnect.js';
	//
	//    if (!empty($_SERVER['SERVER_NAME'])
	//        && 'woocommerceautomation.gw-4u.com' == $_SERVER['SERVER_NAME']
	//        && defined('NUVEI_SDK_URL_TAG')
	//    ) {
	//        $sdkUrl = NUVEI_SDK_URL_TAG;
	//    }

	// load the SDK
	//    wp_register_script(
	//      'nuvei_checkout_sdk',
	//      $sdkUrl,
	//      array('jquery')
	//  );

	// reorder.js
	wp_register_script(
		'nuvei_js_reorder',
		$plugin_url . 'assets/js/nuvei_reorder.js',
		array( 'jquery' ),
		'1',
		true
	);

	// main JS
	wp_register_script(
		'nuvei_js_public',
		$plugin_url . 'assets/js/nuvei_public.js',
		array( 'jquery' ),
		'1',
		false
	);

	// get selected WC price separators
	$wc_th_sep  = '';
	$wc_dec_sep = '';

	$res = $wpdb->get_results(
		'SELECT option_name, option_value '
			. "FROM {$wpdb->prefix}options "
			. "WHERE option_name LIKE 'woocommerce%_sep' ;",
		ARRAY_N
	);

	if ( ! empty( $res ) ) {
		foreach ( $res as $row ) {
			if ( false != strpos( $row[0], 'thousand_sep' ) && ! empty( $row[1] ) ) {
				$wc_th_sep = $row[1];
			}

			if ( false != strpos( $row[0], 'decimal_sep' ) && ! empty( $row[1] ) ) {
				$wc_dec_sep = $row[1];
			}
		}
	}

	// put translations here into the array
	$localizations = array_merge(
		NUVEI_JS_LOCALIZATIONS,
		array(
			'security'              => wp_create_nonce( 'sc-security-nonce' ),
			'wcThSep'               => $wc_th_sep,
			'wcDecSep'              => $wc_dec_sep,
			'useUpos'               => $wc_nuvei->can_use_upos(),
			'isUserLogged'          => is_user_logged_in() ? 1 : 0,
			'isPluginActive'        => $wc_nuvei->settings['enabled'],
			'loaderUrl'             => plugin_dir_url( __FILE__ ) . 'assets/icons/loader.gif',
			'checkoutIntegration'   => $wc_nuvei->settings['integration_type'],
			'webMasterId'           => 'WooCommerce ' . WOOCOMMERCE_VERSION
				. '; Plugin v' . nuvei_get_plugin_version(),
		)
	);

	wp_enqueue_script( 'nuvei_checkout_sdk' );
	wp_enqueue_script( 'nuvei_js_reorder' );

	wp_localize_script( 'nuvei_js_public', 'scTrans', $localizations );
	wp_enqueue_script( 'nuvei_js_public' );
}

/**
 * Loads public styles
 *
 * @global Nuvei_Gateway $wc_nuvei
 * @global type $wpdb
 *
 * @param type $styles
 *
 * @return void
 */
function nuvei_load_styles( $styles ) {
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
function nuvei_load_admin_styles_scripts( $hook ) {
	$plugin_url = plugin_dir_url( __FILE__ );

	if ( 'post.php' == $hook ) {
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
	wp_register_script(
		'nuvei_js_admin',
		$plugin_url . 'assets/js/nuvei_admin.js',
		array( 'jquery' ),
		'1',
		true
	);

	// get the list of the plans
	$nuvei_plans_path   = NUVEI_LOGS_DIR . NUVEI_PLANS_FILE;
	$plans_list         = array();
	$wp_fs_direct       = new WP_Filesystem_Direct( null );

	if ( is_readable( $nuvei_plans_path ) ) {
		$plans_list = stripslashes( $wp_fs_direct->get_contents( $nuvei_plans_path ) );
	}
	// get the list of the plans end

	// put translations here into the array
	$localizations = array_merge(
		NUVEI_JS_LOCALIZATIONS,
		array(
			'security'          => wp_create_nonce( 'sc-security-nonce' ),
			'nuveiPaymentPlans' => $plans_list,
			'webMasterId'       => 'WooCommerce ' . WOOCOMMERCE_VERSION
				. '; Plugin v' . nuvei_get_plugin_version(),
		)
	);

	wp_localize_script( 'nuvei_js_admin', 'scTrans', $localizations );
	wp_enqueue_script( 'nuvei_js_admin' );
}
# Load Styles and Scripts END

// first method we come in
function nuvei_enqueue( $hook ) {
	# DMNs catch
	// sc_listener is legacy value
	if ( in_array( Nuvei_Http::get_param( 'wc-api' ), array( 'sc_listener', 'nuvei_listener' ) ) ) {
		add_action(
			'wp_loaded',
			function () {
				$nuvei_notify_dmn = new Nuvei_Notify_Url();
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
function nuvei_add_buttons( $order ) {
	// error
	if ( ! is_a( $order, 'WC_Order' ) || is_a( $order, 'WC_Subscription' ) ) {
		return false;
	}

	Nuvei_Logger::write( 'nuvei_add_buttons' );

	// error - in case this is not Nuvei order
	if ( empty( $order->get_payment_method() )
		|| ! in_array( $order->get_payment_method(), array( NUVEI_GATEWAY_NAME, 'sc' ) )
	) {
		echo '<script type="text/javascript">var notNuveiOrder = true;</script>';
		return false;
	}

	// to show Nuvei buttons we must be sure the order is paid via Nuvei Paygate
	$order_id       = $order->get_id();
	$helper         = new Nuvei_Helper();
	$ord_tr_id      = $helper->helper_get_tr_id( $order_id );
	$order_total    = $order->get_total();
	$order_data     = $order->get_meta( NUVEI_TRANSACTIONS );
	$last_tr_data   = array();
	$order_refunds  = array();
	$ref_amount       = 0;
	$order_time     = 0;

	// error
	if ( empty( $ord_tr_id ) ) {
		Nuvei_Logger::write( $ord_tr_id, 'Invalid Transaction ID! May be this post is not an Order.' );
		return false;
	}

	// error
	if ( empty( $order_data ) || ! is_array( $order_data ) ) {
		Nuvei_Logger::write( $order_data, 'Missing or wrong Nuvei transactions data for the order.' );

		// disable refund button
		echo '<script type="text/javascript">jQuery(\'.refund-items\').prop("disabled", true);</script>';

		return false;
	}

	// get Refund transactions
	foreach ( array_reverse( $order_data ) as $tr ) {
		if ( isset( $tr['transactionType'], $tr['status'] )
			&& in_array( $tr['transactionType'], array( 'Credit', 'Refund' ) )
			&& 'approved' == strtolower( $tr['status'] )
		) {
			$order_refunds[]    = $tr;
			$ref_amount           += $tr['totalAmount'];
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
	if ( ! in_array( $order_payment_method, NUVEI_APMS_REFUND_VOID )
		|| ! in_array( $last_tr_data['transactionType'], array( 'Sale', 'Settle', 'Credit', 'Refund' ) )
		|| 'approved' != strtolower( $last_tr_data['status'] )
		|| 0 == $order_total
		|| $ref_amount >= $order_total
	) {
		echo '<script type="text/javascript">jQuery(\'.refund-items\').prop("disabled", true);</script>';
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
		Nuvei_Logger::write( $last_tr_data, 'Last Transaction is not yet approved or the DMN didn\'t come yet.' );

		// disable refund button
		echo '<script type="text/javascript">jQuery(\'.refund-items\').prop("disabled", true);</script>';

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
			__( 'Are you sure, you want to Cancel Order #%d?', 'nuvei-checkout-for-woocommerce' ),
			$order_id
		);

		// check for active subscriptions
		$all_meta       = $order->get_meta_data();
		$subscr_list    = $helper->get_rebiling_details( $all_meta );

		//        foreach ($all_meta as $meta_key => $meta_data) {
		//            if (false !== strpos($meta_key, NUVEI_ORDER_SUBSCR)) {
		//                $subscr_data = $order->get_meta($meta_key);
		//
		//                if (!empty($subscr_data['state'])
		//                    && 'active' == $subscr_data['state']
		//                ) {
		//                    $question = __('Are you sure, you want to Cancel this Order? This will also deactivate all Active Subscriptions.', 'nuvei-checkout-for-woocommerce');
		//                    break;
		//                }
		//            }
		//        }
		foreach ( $subscr_list as $meta_data ) {
			if ( ! empty( $meta_data['subs_data']['state'] )
				&& 'active' == $meta_data['subs_data']['state']
			) {
				$question = __( 'Are you sure, you want to Cancel this Order? This will also deactivate all Active Subscriptions.', 'nuvei-checkout-for-woocommerce' );
				break;
			}
		}
		// /check for active subscriptions

		echo '<button id="sc_void_btn" type="button" onclick="nuveiAction(\''
				. esc_html( $question ) . '\', \'void\', ' . esc_html( $order_id )
				. ')" class="button generate-items">'
				. esc_html__( 'Void', 'nuvei-checkout-for-woocommerce' ) . '</button>';
	}

	// show SETTLE button ONLY if transaction type IS Auth and the Total is not 0
	if ( 'Auth' == $last_tr_data['transactionType']
		&& $order_total > 0
	) {
		$question = sprintf(
			/* translators: %d is replaced with "decimal" */
			__( 'Are you sure, you want to Settle Order #%d?', 'nuvei-checkout-for-woocommerce' ),
			$order_id
		);

		echo '<button id="sc_settle_btn" type="button" onclick="nuveiAction(\''
				. esc_html( $question )
				. '\', \'settle\', \'' . esc_html( $order_id ) . '\')" class="button generate-items">'
				. esc_html__( 'Settle', 'nuvei-checkout-for-woocommerce' ) . '</button>';
	}

	// add loading screen
	echo '<div id="custom_loader" class="blockUI blockOverlay" style="height: 100%; position: absolute; top: 0px; width: 100%; z-index: 10; background-color: rgba(255,255,255,0.5); display: none;"></div>';
}

/**
 * When user have problem with white spaces in the URL, it have option to
 * rewrite the return URL and redirect to new one.
 *
 * @global WC_SC $wc_nuvei
 * @deprecated since version 3.0.1
 */
function nuvei_rewrite_return_url() {
    //phpcs:ignore
	if ( isset( $_REQUEST['ppp_status'] ) && '' != $_REQUEST['ppp_status']
        //phpcs:ignore
		&& ( ! isset( $_REQUEST['wc_sc_redirected'] ) || 0 == $_REQUEST['wc_sc_redirected'] )
	) {
		$query_string = '';
		if ( isset( $_SERVER['QUERY_STRING'] ) ) {
			$query_string = sanitize_text_field( $_SERVER['QUERY_STRING'] );
		}

		$server_protocol = '';
		if ( isset( $_SERVER['SERVER_PROTOCOL'] ) ) {
			$server_protocol = sanitize_text_field( $_SERVER['SERVER_PROTOCOL'] );
		}

		$http_host = '';
		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$http_host = sanitize_text_field( $_SERVER['HTTP_HOST'] );
		}

		$request_uri = '';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$request_uri = sanitize_text_field( $_SERVER['REQUEST_URI'] );
		}

		$new_url = '';
		$host    = ( strpos( $server_protocol, 'HTTP/' ) !== false ? 'http' : 'https' )
			. '://' . $http_host . current( explode( '?', $request_uri ) );

		if ( '' != $query_string ) {
			$new_url = preg_replace( '/\+|\s|\%20/', '_', $query_string );
			// put flag the URL was rewrited
			$new_url .= '&wc_sc_redirected=1';

			wp_redirect( $host . '?' . $new_url );
			exit;
		}
	}
}

/**
 * Fix for WPML plugin "Thank you" page
 *
 * @param string $order_received_url
 * @param WC_Order $order
 *
 * @return string $order_received_url
 *
 * @deprecated since version 3.0.0-p1
 */
function nuvei_wpml_thank_you_page( $order_received_url, $order ) {
	if ( $order->get_payment_method() != NUVEI_GATEWAY_NAME ) {
		return;
	}

	$lang_code          = $order->get_meta( 'wpml_language', true );
	$order_received_url = apply_filters( 'wpml_permalink', $order_received_url, $lang_code );

	Nuvei_Logger::write( $order_received_url, 'nuvei_wpml_thank_you_page: ' );

	return $order_received_url;
}

function nuvei_mod_thank_you_page( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( $order->get_payment_method() != NUVEI_GATEWAY_NAME ) {
		return;
	}

	$output = '<script>';

	# Modify title and the text on errors
	$request_status = Nuvei_Http::get_request_status();
	$new_msg        = esc_html__( 'Please check your Order status for more information.', 'nuvei-checkout-for-woocommerce' );
	$new_title      = '';

	if ( 'error' == $request_status
		|| 'fail' == strtolower( Nuvei_Http::get_param( 'ppp_status' ) )
	) {
		$new_title  = esc_html__( 'Order error', 'nuvei-checkout-for-woocommerce' );
	} elseif ( 'canceled' == $request_status ) {
		$new_title  = esc_html__( 'Order canceled', 'nuvei-checkout-for-woocommerce' );
	}

	if ( ! empty( $new_title ) ) {
		$output .= 'jQuery(".entry-title").html("' . $new_title . '");'
			. 'jQuery(".woocommerce-thankyou-order-received").html("' . $new_msg . '");';
	}
	# /Modify title and the text on errors

	# when WCS is turn on, remove Pay button
	if ( is_plugin_active( 'woocommerce-subscriptions' . DIRECTORY_SEPARATOR . 'woocommerce-subscriptions.php' ) ) {
		$output .= 'jQuery("a.pay").hide();';
	}

	$output .= '</script>';

	echo esc_js( $output );
}

function nuvei_edit_order_buttons() {
	$default_text          = __( 'Place order', 'woocommerce' );
	$sc_continue_text      = __( 'Continue', 'woocommerce' );
	$chosen_payment_method = WC()->session->get( 'chosen_payment_method' );

	// save default text into button attribute ?><script>
		(function($){
			$('#place_order')
				.attr('data-default-text', '<?php echo esc_attr( $default_text ); ?>')
				.attr('data-sc-text', '<?php echo esc_attr( $sc_continue_text ); ?>');
		})(jQuery);
	</script>
	<?php

	// check for 'sc' also, because of the older Orders
	if ( in_array( $chosen_payment_method, array( NUVEI_GATEWAY_NAME, 'sc' ) ) ) {
		return $sc_continue_text;
	}

	return $default_text;
}

function nuvei_change_title_order_received( $title, $id ) {
	if ( function_exists( 'is_order_received_page' )
		&& is_order_received_page()
		&& get_the_ID() === $id
	) {
		$title = esc_html__( 'Order error', 'nuvei-checkout-for-woocommerce' );
	}

	return $title;
}

/**
 * Call this on Store when the logged user is in My Account section
 *
 * @global type $wp
 */
function nuvei_user_orders() {
	global $wp;

	$order     = wc_get_order( $wp->query_vars['order-pay'] );
	$order_key = $order->get_order_key();

	// check for 'sc' also, because of the older Orders
	if ( ! in_array( $order->get_payment_method(), array( NUVEI_GATEWAY_NAME, 'sc' ) ) ) {
		return;
	}

	if ( Nuvei_Http::get_param( 'key' ) != $order_key ) {
		return;
	}

	$prods_ids = array();

	foreach ( $order->get_items() as $data ) {
		$prods_ids[] = $data->get_product_id();
	}

	echo '<script>'
		. 'var scProductsIdsToReorder = ' . wp_kses_post( wp_json_encode( $prods_ids ) ) . ';'
		. 'scOnPayOrderPage();'
	. '</script>';
}

// on reorder, show warning message to the cart if need to
function nuvei_show_message_on_cart( $data ) {
	echo '<script>jQuery("#content .woocommerce:first").append("<div class=\'woocommerce-warning\'>'
		. wp_kses_post( Nuvei_Http::get_param( 'sc_msg' ) ) . '</div>");</script>';
}

// Attributes, Terms and Meta functions
function nuvei_add_term_fields_form( $taxonomy ) {
	$nuvei_plans_path   = NUVEI_LOGS_DIR . NUVEI_PLANS_FILE;
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

function nuvei_edit_term_meta_form( $term, $taxonomy ) {
	$nuvei_plans_path   = NUVEI_LOGS_DIR . NUVEI_PLANS_FILE;
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

function nuvei_save_term_meta( $term_id, $tt_id ) {
	$taxonomy      = 'pa_' . Nuvei_String::get_slug( NUVEI_GLOB_ATTR_NAME );
	$post_taxonomy = Nuvei_Http::get_param( 'taxonomy', 'string' );

	if ( $post_taxonomy != $taxonomy ) {
		return;
	}

	add_term_meta( $term_id, 'planId', Nuvei_Http::get_param( 'planId', 'int' ) );
	add_term_meta( $term_id, 'recurringAmount', Nuvei_Http::get_param( 'recurringAmount', 'float' ) );

	add_term_meta( $term_id, 'startAfterUnit', Nuvei_Http::get_param( 'startAfterUnit', 'string' ) );
	add_term_meta( $term_id, 'startAfterPeriod', Nuvei_Http::get_param( 'startAfterPeriod', 'int' ) );

	add_term_meta( $term_id, 'recurringPeriodUnit', Nuvei_Http::get_param( 'recurringPeriodUnit', 'string' ) );
	add_term_meta( $term_id, 'recurringPeriodPeriod', Nuvei_Http::get_param( 'recurringPeriodPeriod', 'int' ) );

	add_term_meta( $term_id, 'endAfterUnit', Nuvei_Http::get_param( 'endAfterUnit', 'string' ) );
	add_term_meta( $term_id, 'endAfterPeriod', Nuvei_Http::get_param( 'endAfterPeriod', 'int' ) );
}

function nuvei_edit_term_meta( $term_id, $tt_id ) {
	$taxonomy      = 'pa_' . Nuvei_String::get_slug( NUVEI_GLOB_ATTR_NAME );
	$post_taxonomy = Nuvei_Http::get_param( 'taxonomy', 'string' );

	if ( $post_taxonomy != $taxonomy ) {
		return;
	}

	update_term_meta( $term_id, 'planId', Nuvei_Http::get_param( 'planId', 'int' ) );
	update_term_meta( $term_id, 'recurringAmount', Nuvei_Http::get_param( 'recurringAmount', 'float' ) );

	update_term_meta( $term_id, 'startAfterUnit', Nuvei_Http::get_param( 'startAfterUnit', 'string' ) );
	update_term_meta( $term_id, 'startAfterPeriod', Nuvei_Http::get_param( 'startAfterPeriod', 'int' ) );

	update_term_meta( $term_id, 'recurringPeriodUnit', Nuvei_Http::get_param( 'recurringPeriodUnit', 'string' ) );
	update_term_meta( $term_id, 'recurringPeriodPeriod', Nuvei_Http::get_param( 'recurringPeriodPeriod', 'int' ) );

	update_term_meta( $term_id, 'endAfterUnit', Nuvei_Http::get_param( 'endAfterUnit', 'string' ) );
	update_term_meta( $term_id, 'endAfterPeriod', Nuvei_Http::get_param( 'endAfterPeriod', 'int' ) );
}
// Attributes, Terms and Meta functions END

/**
 * For the custom baloon in Order column in the Order list.
 */
function nuvei_edit_order_list_columns( $column, $col_id ) {
	// the column we put/edit baloons
	if ( ! in_array( $column, array( 'order_number', 'order_status' ) ) ) {
		return;
	}

	global $post;

	$order = wc_get_order( $post->ID );

	if ( $order->get_payment_method() != NUVEI_GATEWAY_NAME ) {
		return;
	}

	$all_meta       = $order->get_meta_data();
	//    $nuvei_subscr   = [];
	$order_changes  = $order->get_meta( NUVEI_ORDER_CHANGES ); // this is the flag for fraud
	//
	//    foreach ($all_meta as $key => $data) {
	//        if (false !== strpos($key, NUVEI_ORDER_SUBSCR)) {
	//            $nuvei_subscr = $order->get_meta($key);
	//            break;
	//        }
	//    }

	$helper = new Nuvei_Helper();
	$subs_list  = $helper->get_rebiling_details( $all_meta );

	// put subscription baloon
	if ( 'order_number' == $column && ! empty( $subs_list ) ) {
		echo '<mark class="order-status status-processing tips" style="float: right;"><span>'
			. esc_html__( 'Nuvei Subscription', 'nuvei-checkout-for-woocommerce' ) . '</span></mark>';
	}

	// edit status baloon
	if ( 'order_status' == $column
		&& ( ! empty( $order_changes['total_change'] ) || ! empty( $order_changes['curr_change'] ) )
	) {
		echo '<mark class="order-status status-on-hold tips" style="float: left; margin-right: 2px;" title="'
			. esc_html__( 'Please check transaction Total and Currency!', 'nuvei-checkout-for-woocommerce' ) . '"><span>!</span></mark>';
	}
}

/**
 * For the custom baloon in Order column in the Order list.
 */
function nuvei_hpos_edit_order_list_columns( $column, $order ) {
	// the column we put/edit baloons
	if ( ! in_array( $column, array( 'order_number', 'order_status' ) ) ) {
		return;
	}

	if ( $order->get_payment_method() != NUVEI_GATEWAY_NAME ) {
		return;
	}

	$all_meta       = $order->get_meta_data();
	$order_changes  = $order->get_meta( NUVEI_ORDER_CHANGES ); // this is the flag for fraud
	$helper         = new Nuvei_Helper();
	$subs_list      = $helper->get_rebiling_details( $all_meta );

	// put subscription baloon
	if ( 'order_number' == $column && ! empty( $subs_list ) ) {
		echo '<mark class="order-status status-processing tips" style="float: right;"><span>'
			. esc_html__( 'Nuvei Subscription', 'nuvei-checkout-for-woocommerce' ) . '</span></mark>';
	}

	// edit status baloon
	if ( 'order_status' == $column
		&& ( ! empty( $order_changes['total_change'] ) || ! empty( $order_changes['curr_change'] ) )
	) {
		echo '<mark class="order-status status-on-hold tips" style="float: left; margin-right: 2px;" title="'
			. esc_html__( 'Please check transaction Total and Currency!', 'nuvei-checkout-for-woocommerce' ) . '"><span>!</span></mark>';
	}
}

/**
 * In Store > My Account > Orders table, Order column
 * add Rebilling icon for the Orders with Nuvei Payment Plan.
 *
 * @param WC_Order $order
 */
function nuvei_edit_my_account_orders_col( $order ) {
	// get all meta fields
	$helper         = new Nuvei_Helper();
	$post_meta      = $order->get_meta_data();
	$subscr_list    = $helper->get_rebiling_details( $post_meta );
	$is_subscr      = ! empty( $subscr_list ) ? true : false;

	//    if (!empty($post_meta) && is_array($post_meta)) {
	//        foreach ($post_meta as $key => $data) {
	//            if (false !== strpos($key, NUVEI_ORDER_SUBSCR)) {
	//                $is_subscr = true;
	//                break;
	//            }
	//        }
	//    }

	echo '<a href="' . esc_url( $order->get_view_order_url() ) . '"';

	if ( $is_subscr ) {
		echo ' class="nuvei_plan_order" title="' . esc_attr__( 'Nuvei Payment Plan Order', 'nuvei-checkout-for-woocommerce' ) . '"';
	}

	echo '>#' . esc_html( $order->get_order_number() ) . '</a>';
}

/**
 * Repeating code from Version Checker logic.
 *
 * @return array
 * @deprecated since version 3.0.1
 */
function nuvei_get_file_form_git() {
	$matches = array();
	//  $ch      = curl_init();
	//
	//  curl_setopt(
	//      $ch,
	//      CURLOPT_URL,
	//      'https://raw.githubusercontent.com/Nuvei/nuvei-plugin-woocommerce/main/index.php'
	//  );
	//
	//  curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	//  curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	//
	//  $file_text = curl_exec( $ch );
	//  curl_close( $ch );

	$file_text = wp_remote_get(
		'https://raw.githubusercontent.com/Nuvei/nuvei-plugin-woocommerce/main/index.php',
		array(
			'sslverify' => false,
		)
	);

	preg_match( '/(\s?\*\s?Version\s?:\s?)(.*\s?)(\n)/', $file_text, $matches );

	if ( ! isset( $matches[2] ) ) {
		return array();
	}

	$array = array(
		'date'  => gmdate( 'Y-m-d H:i:s', time() ),
		'git_v' => (int) str_replace( '.', '', trim( $matches[2] ) ),
	);

	return $array;
}

/**
 * Decide to override or not default WC behavior for Zero Total Order.
 *
 * @param boolean $needs_payment
 * @param object $cart
 *
 * @return boolean $needs_payment
 */
function nuvei_wc_cart_needs_payment( $needs_payment, $cart ) {
	global $wc_nuvei;

	if ( 1 == $wc_nuvei->settings['allow_zero_checkout'] ) {
		return true;
	}

	$cart_items = $cart->get_cart();

	foreach ( $cart_items as $item ) {
		$cart_product   = wc_get_product( $item['product_id'] );
		$cart_prod_attr = $cart_product->get_attributes();

		// check for product with a payment plan
		if ( ! empty( $cart_prod_attr[ 'pa_' . Nuvei_String::get_slug( NUVEI_GLOB_ATTR_NAME ) ] ) ) {
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
function nuvei_after_order_itemmeta( $item_id, $item, $_product ) {
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
		Nuvei_Logger::write( $post_id, 'There is a problem when try to get Order by ID', 'WARN' );
		return;
	}

	$helper         = new Nuvei_Helper();
	$post_meta      = $order->get_meta_data();
	$subscr_list    = $helper->get_rebiling_details( $post_meta );

	//    if (empty($post_meta) || !is_array($post_meta)) {
	//        return;
	//    }
	if ( empty( $post_meta ) || empty( $subscr_list ) ) {
		return;
	}

	//    foreach ($post_meta as $mk => $md) {
	foreach ( $subscr_list as $data ) {
		//        if (false === strpos($mk, NUVEI_ORDER_SUBSCR)) {
		//            continue;
		//        }

		if ( 'WC_Order_Item_Product' != get_class( $item ) ) {
			continue;
		}

		// because of some delay this may not work, and have to refresh the page
		try {
			Nuvei_Logger::write( 'check nuvei_after_order_itemmeta' );

			//            $subscr_data    = $order->get_meta($mk);
			//            $key_parts      = explode('_', $mk);
			$subscr_data    = $data['subs_data'];
			$key_parts      = explode( '_', $data['subs_id'] );
			$item_variation = $item->get_variation_id();
			$product_id     = $item->get_product_id();
		} catch ( \Exception $ex ) {
			Nuvei_Logger::write(
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
				. esc_html__( 'Nuvei Subscription ID:', 'nuvei-checkout-for-woocommerce' ) . '</strong> '
				. esc_html( $subscr_data['subscr_id'] ) . '</div>';

			if ( ! empty( $subscr_data['state'] ) && 'active' == $subscr_data['state'] ) {
				echo '<button id="nuvei_cancel_subs_' . esc_html( $subscr_data['subscr_id'] )
						. '" class="nuvei_cancel_subscr button generate-items" type="button" '
						. 'style="margin-top: .5em;" onclick="nuveiAction(\''
						. esc_html__( 'Are you sure, you want to cancel this subscription?', 'nuvei-checkout-for-woocommerce' )
						. '\', \'cancelSubscr\', ' . esc_html( 0 ) . ', ' . esc_html( $subscr_data['subscr_id'] )
						. ')">' . esc_html__( 'Cancel Subscription', 'nuvei-checkout-for-woocommerce' )
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
function nuvei_get_plugin_version() {
	$plugin_data  = get_plugin_data( __FILE__ );
	return $plugin_data['Version'];
}

function nuvei_rest_method( $request_data ) {
	Nuvei_Logger::write( 'nuvei_rest_method' );

	global $wc_nuvei;

	$wc_nuvei   = new Nuvei_Gateway();
	$params     = $request_data->get_params();

	// error
	if ( empty( $params['action'] ) ) {
		$res = new WP_REST_Response(
			array(
				'code'      => 'unknown_action',
				'message'   => __( 'The action you require is unknown.', 'nuvei-checkout-for-woocommerce' ),
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
			'message'   => __( 'The action you require is unknown.', 'nuvei-checkout-for-woocommerce' ),
			'data'      => array( 'status' => 405 ),
		)
	);
	$res->set_status( 405 );

	return $rest_resp;
}
