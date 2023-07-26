<?php 
/**
 * Plugin Name: Nuvei Plugin for Woocommerce
 * Plugin URI: https://github.com/Nuvei/nuvei-plugin-woocommerce
 * Description: Nuvei Gateway for WooCommerce
 * Version: 1.4.3
 * Author: Nuvei
 * Author URI: https://nuvei.com
 * Text Domain: nuvei_checkout_woocommerce
 * Domain Path: /languages
 * Require at least: 4.7
 * Tested up to: 6.2.2
 * WC requires at least: 3.0
 * WC tested up to: 7.9.0
*/

defined('ABSPATH') || die('die');

if ( ! defined( 'NUVEI_PLUGIN_FILE' ) ) {
	define( 'NUVEI_PLUGIN_FILE', __FILE__ );
}

require_once 'config.php';
require_once 'includes' . DIRECTORY_SEPARATOR . 'class-nuvei-autoloader.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$wc_nuvei = null;

register_activation_hook(__FILE__, 'nuvei_plugin_activate');

add_action('admin_init', 'nuvei_admin_init');
add_filter('woocommerce_payment_gateways', 'nuvei_add_gateway');
add_action('plugins_loaded', 'nuvei_init', 0);

/**
 * On activate try to create custom logs directory.
 */
function nuvei_plugin_activate()
{
    $content_dir        = dirname(dirname(dirname(__FILE__)));
    $custom_logs_dir    = $content_dir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'nuvei-logs';
    $htaccess_file      = $custom_logs_dir . DIRECTORY_SEPARATOR . '.htaccess';
    
    if (is_dir($custom_logs_dir) && file_exists($htaccess_file)) {
        return;
    }
    
    // try to create them if not exists
    if (mkdir($custom_logs_dir)) {
        file_put_contents($htaccess_file, 'deny from all');
    }
}

function nuvei_admin_init()
{
	try {
		// check if there is the version with "nuvei" in the name of directory, in this case deactivate the current plugin
		$path_to_nuvei_plugin = dirname(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR
			. 'nuvei_checkout_woocommerce' . DIRECTORY_SEPARATOR . 'index.php';

		if (strpos(basename(dirname(__FILE__)), 'safecharge') !== false 
			&& file_exists($path_to_nuvei_plugin)
		) {
			deactivate_plugins(plugin_basename( __FILE__ ));
		}
		// /check if there is the version with "nuvei" in the name of directory, in this case deactivate the current plugin

		// check in GIT for new version
		$file         = NUVEI_LOGS_DIR . 'nuvei-latest-version.json';
		$plugin_data  = get_plugin_data(__FILE__);
		$curr_version = (int) str_replace('.', '', $plugin_data['Version']);
		$git_version  = 0;
		$date_check   = 0;

		if (!file_exists($file)) {
			$data = nuvei_get_file_form_git($file);

			if (!empty($data['git_v'])) {
				$git_version = $data['git_v'];
			}
			if (!empty($data['date'])) {
				$date_check = $data['date'];
			}
		}

		// read file if need to
		if (is_readable($file) 
			&& ( 0 == $date_check || 0 == $git_version )
		) {
			$version_file_data = json_decode(file_get_contents($file), true);

			if (!empty($version_file_data['date'])) {
				$date_check = $version_file_data['date'];
			}
			if (!empty($version_file_data['git_v'])) {
				$git_version = $version_file_data['git_v'];
			}
		}

		// check file date and get new file if current one is more than a week old
		if (strtotime('-1 Week') > strtotime($date_check)) {
			$data = nuvei_get_file_form_git($file);

			if (!empty($data['git_v'])) {
				$git_version = $data['git_v'];
			}
			if (!empty($data['date'])) {
				$date_check = $data['date'];
			}
		}

		// compare versions and show message if need to
		if ($git_version > $curr_version) {
			add_action('admin_notices', function() {
				$class     = 'notice notice-info is-dismissible';
				$url       = NUVEI_GIT_REPO . '/blob/main/changelog.txt';
				$message_1 = __('There is a new version of Nuvei Plugin available.', 'nuvei_checkout_woocommerce' );
				$message_2 = __('View version details.', 'nuvei_checkout_woocommerce' );

				printf(
					'<div class="%1$s"><p>%2$s <a href="%3$s" target="_blank">%4$s</a></p></div>',
					esc_attr($class),
					esc_html($message_1),
					esc_url($url),
					esc_html($message_2)
				);
			});
		}
		// check in GIT for new version END
	} catch (Exception $e) {
		Nuvei_Logger::write($e->getMessage(), 'Exception in admin init');
	}
}

function nuvei_init()
{
	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}
	
	load_plugin_textdomain(
		'nuvei_checkout_woocommerce',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages/'
	);
	
	global $wc_nuvei;
	$wc_nuvei = new Nuvei_Gateway();
	
	add_action('init', 'nuvei_enqueue');
	
	// load WC styles
    add_filter('woocommerce_enqueue_styles', 'nuvei_load_styles_scripts');
	
	// add admin style
    add_filter('admin_enqueue_scripts', 'nuvei_load_admin_styles_scripts');
	
	// add void and/or settle buttons to completed orders
	add_action('woocommerce_order_item_add_action_buttons', 'nuvei_add_buttons', 10, 1);
	
	// handle custom Ajax calls
	add_action('wp_ajax_sc-ajax-action', 'nuvei_ajax_action');
	add_action('wp_ajax_nopriv_sc-ajax-action', 'nuvei_ajax_action');
	
	// if validation success get order details
	add_action('woocommerce_after_checkout_validation', function( $data, $errors) {
		global $wc_nuvei;
		
		//        Nuvei_Logger::write($errors->errors, 'woocommerce_after_checkout_validation errors');
		//        Nuvei_Logger::write($_POST, 'woocommerce_after_checkout_validation post params');
		
		if (empty($errors->errors) 
			&& NUVEI_GATEWAY_NAME == $data['payment_method'] 
			&& empty(Nuvei_Http::get_param('nuvei_transaction_id'))
		) {
			if (isset($wc_nuvei->settings['integration_type'])
				&& 'cashier' != $wc_nuvei->settings['integration_type']
			) {
				$wc_nuvei->call_checkout();
			}
		}
	}, 9999, 2);
	
	// use this to change button text, because of the cache the jQuery not always works
	add_filter('woocommerce_order_button_text', 'nuvei_edit_order_buttons');
	
	// those actions are valid only when the plugin is enabled
	$plugin_enabled = isset($wc_nuvei->settings['enabled']) ? $wc_nuvei->settings['enabled'] : 'no';
	
	if ('yes' == $plugin_enabled) {
		// for WPML plugin
		if (is_plugin_active('sitepress-multilingual-cms' . DIRECTORY_SEPARATOR . 'sitepress.php')
			&& 'yes' == $wc_nuvei->settings['use_wpml_thanks_page']
		) {
			add_filter('woocommerce_get_checkout_order_received_url', 'nuvei_wpml_thank_you_page', 10, 2);
		}
        
        // for the thank-you page
        add_action('woocommerce_thankyou', 'nuvei_mod_thank_you_page', 100, 1);

		// For the custom column in the Order list
		add_action( 'manage_shop_order_posts_custom_column', 'nuvei_fill_custom_column' );
		// for the Store > My Account > Orders list
		add_action( 'woocommerce_my_account_my_orders_column_order-number', 'nuvei_edit_my_account_orders_col' );
        // show payment methods on checkout when total is 0
        add_filter( 'woocommerce_cart_needs_payment', 'nuvei_wc_cart_needs_payment', 10, 2 );
        // show custom data into order details, product data
        add_action( 'woocommerce_after_order_itemmeta', 'nuvei_after_order_itemmeta', 10, 3 );
        // listent for the WC Subscription Payment
        add_action(
            'woocommerce_scheduled_subscription_payment_' . NUVEI_GATEWAY_NAME,
            [$wc_nuvei, 'create_wc_subscr_order'],
            10,
            2
        );
	}
	
	// change Thank-you page title and text on error
//	if (!is_admin()) {
//		if ('error' === strtolower(Nuvei_Http::get_request_status())
//			|| 'fail' === strtolower(Nuvei_Http::get_param('ppp_status'))
//		) {
//			add_filter('the_title', function ( $title, $id) {
//				if (function_exists('is_order_received_page')
//					&& is_order_received_page()
//					&& get_the_ID() === $id
//				) {
//					$title = esc_html__('Order error', 'nuvei_checkout_woocommerce');
//				}
//
//				return $title;
//			}, 10, 2);
//
//			add_filter(
//				'woocommerce_thankyou_order_received_text',
//
//				function ( $str, $order) {
//					return esc_html__(' There is an error with your order. Please check your Order status for more information.', 'nuvei_checkout_woocommerce');
//				}, 10, 2);
//		}
//        elseif ('canceled' === strtolower(Nuvei_Http::get_request_status())) {
//			add_filter('the_title', function ( $title, $id) {
//				if (function_exists('is_order_received_page')
//					&& is_order_received_page()
//					&& get_the_ID() === $id
//				) {
//					$title = esc_html__('Order canceled', 'nuvei_checkout_woocommerce');
//				}
//
//				return $title;
//			}, 10, 2);
//
//			add_filter('woocommerce_thankyou_order_received_text', function ( $str, $order) {
//				return esc_html__('Please, check the order for details!', 'nuvei_checkout_woocommerce');
//			}, 10, 2);
//		}
//	}
	// /change Thank-you page title and text
	
	add_filter('woocommerce_pay_order_after_submit', 'nuvei_user_orders', 10, 2);
	
	if (!empty($_GET['sc_msg'])) {
		add_filter('woocommerce_before_cart', 'nuvei_show_message_on_cart', 10, 2);
	}
	
	# Payment Plans taxonomies
	// extend Term form to add meta data
	add_action('pa_' . Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME) . '_add_form_fields', 'nuvei_add_term_fields_form', 10, 2);
	// update Terms' meta data form
	add_action('pa_' . Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME) . '_edit_form_fields', 'nuvei_edit_term_meta_form', 10, 2);
	// hook to catch our meta data and save it
	add_action( 'created_pa_' . Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME), 'nuvei_save_term_meta', 10, 2 );
	// edit Term meta data
	add_action( 'edited_pa_' . Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME), 'nuvei_edit_term_meta', 10, 2 );
	// before add a product to the cart
	add_filter( 'woocommerce_add_to_cart_validation', array($wc_nuvei, 'add_to_cart_validation'), 10, 3 );
    // Show/hide payment gateways in case of product with Nuvei Payment plan in the Cart
    add_filter( 'woocommerce_available_payment_gateways', array($wc_nuvei, 'hide_payment_gateways'), 100, 1 );
}

/**
 * Main function for the Ajax requests.
 */
function nuvei_ajax_action()
{
	if (!check_ajax_referer('sc-security-nonce', 'security', false)) {
		wp_send_json_error(__('Invalid security token sent.'));
		wp_die('Invalid security token sent');
	}
	
	global $wc_nuvei;
	
	if (empty($wc_nuvei->settings['test'])) {
		wp_send_json_error(__('Invalid site mode.'));
		wp_die('Invalid site mode.');
	}
	
	$order_id = Nuvei_Http::get_param('orderId', 'int');
	
	# recognize the action:
	// Void (Cancel)
	if (Nuvei_Http::get_param('cancelOrder', 'int') == 1 && $order_id > 0) {
		$nuvei_settle_void = new Nuvei_Settle_Void($wc_nuvei->settings);
		$nuvei_settle_void->create_settle_void(sanitize_text_field($order_id), 'void');
	}

	// Settle
	if (Nuvei_Http::get_param('settleOrder', 'int') == 1 && $order_id > 0) {
		$nuvei_settle_void = new Nuvei_Settle_Void($wc_nuvei->settings);
		$nuvei_settle_void->create_settle_void(sanitize_text_field($order_id), 'settle');
	}
	
	// Refund
	if (Nuvei_Http::get_param('refAmount', 'float') != 0) {
		$nuvei_refund = new Nuvei_Refund($wc_nuvei->settings);
		$nuvei_refund->create_refund_request(
            Nuvei_Http::get_param('postId', 'int'), 
            Nuvei_Http::get_param('refAmount', 'float')
        );
	}
    
	// Cancel Subscription
	if (Nuvei_Http::get_param('cancelSubs', 'int') == 1
        && !empty($subscriptionId = Nuvei_Http::get_param('subscrId', 'int'))
    ) {
        $order = wc_get_order(Nuvei_Http::get_param('orderId', 'int'));
        
		$nuvei_class    = new Nuvei_Subscription_Cancel($wc_nuvei->settings);
		$resp           = $nuvei_class->process(['subscriptionId' => $subscriptionId]);
        $ord_status     = 0;
        
        if (!empty($resp['status']) && 'SUCCESS' == $resp['status']) {
			$ord_status = 1;
		}
        
		wp_send_json([
            'status'    => $ord_status, 
            'data'      => $resp
        ]);
		exit;
	}
	
	// Update Order before submit
	if (Nuvei_Http::get_param('updateOrder', 'int') == 1) {
//		$oo_obj = new Nuvei_Open_Order($wc_nuvei->settings, true);
//		$oo_obj->process();
        $wc_nuvei->call_checkout($is_ajax = true);
	}
	
	// when Reorder
	if (Nuvei_Http::get_param('sc_request') == 'scReorder') {
		$wc_nuvei->reorder();
	}
	
	// download Subscriptions Plans
	if (Nuvei_Http::get_param('downloadPlans', 'int') == 1) {
		$wc_nuvei->download_subscr_pans();
	}
	
	wp_send_json_error(__('Not recognized Ajax call.', 'nuvei_checkout_woocommerce'));
	wp_die();
}

/**
* Add the Gateway to WooCommerce
**/
function nuvei_add_gateway( $methods)
{
	$methods[] = 'Nuvei_Gateway'; // get the name of the Gateway Class
	return $methods;
}

# Load Styles and Scripts
/**
 * Loads public styles and scripts
 * 
 * @global Nuvei_Gateway $wc_nuvei
 * @global type $wpdb
 * 
 * @param type $styles
 */
function nuvei_load_styles_scripts( $styles)
{
	global $wc_nuvei;
	global $wpdb;
	
	$plugin_url = plugin_dir_url(__FILE__);
	
	if ( (isset($_SERVER['HTTPS']) && 'on' == $_SERVER['HTTPS'])
		&& (isset($_SERVER['REQUEST_SCHEME']) && 'https' == $_SERVER['REQUEST_SCHEME'])
	) {
		if (strpos($plugin_url, 'https') === false) {
			$plugin_url = str_replace('http:', 'https:', $plugin_url);
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
	
	// Checkout SDK URL for integration and production
//    $sdk_version = NUVEI_SDK_URL_PROD;
    
//    if(!empty($wc_nuvei->settings['sdk_version'])) {
//        $sdk_version = $wc_nuvei->settings['sdk_version'];
//    }
    
	wp_register_script(
		'nuvei_checkout_sdk',
//		($sdk_version == 'prod' ? NUVEI_SDK_URL_PROD : NUVEI_SDK_URL_INT),
		NUVEI_SDK_URL_PROD,
		array('jquery'),
		'1'
	);

	// reorder js
	wp_register_script(
		'nuvei_js_reorder',
		$plugin_url . 'assets/js/nuvei_reorder.js',
		array('jquery'),
		'1'
	);
	
	// get selected WC price separators
	$wcThSep  = '';
	$wcDecSep = '';
	
	$res = $wpdb->get_results(
		'SELECT option_name, option_value '
			. "FROM {$wpdb->prefix}options "
			. "WHERE option_name LIKE 'woocommerce%_sep' ;",
		ARRAY_N
	);
			
	if (!empty($res)) {
		foreach ($res as $row) {
			if (false != strpos($row[0], 'thousand_sep') && !empty($row[1])) {
				$wcThSep = $row[1];
			}

			if (false != strpos($row[0], 'decimal_sep') && !empty($row[1])) {
				$wcDecSep = $row[1];
			}
		}
	}
	
	// put translations here into the array
    $localizations = array_merge(
        NUVEI_JS_LOCALIZATIONS,
        [
            'security'          => wp_create_nonce('sc-security-nonce'),
            'wcThSep'           => $wcThSep,
            'wcDecSep'          => $wcDecSep,
            'useUpos'           => $wc_nuvei->can_use_upos(),
            'isUserLogged'      => is_user_logged_in() ? 1 : 0,
            'isPluginActive'    => $wc_nuvei->settings['enabled'],
            'webMasterId'       => 'WooCommerce ' . WOOCOMMERCE_VERSION
                . '; Plugin v' . nuvei_get_plugin_version(),
        ]
    );
    
    // main JS
	wp_register_script(
		'nuvei_js_public',
		$plugin_url . 'assets/js/nuvei_public.js',
		array('jquery'),
		'1'
	);
	
    if(is_checkout()) {
        $nuvei_helper = new Nuvei_Helper($wc_nuvei->settings);
        
        wp_enqueue_style('nuvei_style');
        wp_enqueue_script('nuvei_checkout_sdk');
        wp_enqueue_script('nuvei_js_reorder');
        
        wp_localize_script('nuvei_js_public', 'scTrans', $localizations);
        wp_enqueue_script('nuvei_js_public');
    }
    
	//return $styles;
}

/**
 * Loads admin styles and scripts
 * 
 * @param string $hook
 */
function nuvei_load_admin_styles_scripts( $hook) {
	$plugin_url = plugin_dir_url(__FILE__);
	
	if ( 'post.php' == $hook ) {
		wp_register_style(
			'nuvei_admin_style',
			$plugin_url . 'assets/css/nuvei_admin_style.css',
			'',
			1,
			'all'
		);
		wp_enqueue_style('nuvei_admin_style');
	}

	// main JS
	wp_register_script(
		'nuvei_js_admin',
		$plugin_url . 'assets/js/nuvei_admin.js',
		array('jquery'),
		'1'
	);
	
	// get the list of the plans
	$nuvei_plans_path = NUVEI_LOGS_DIR . NUVEI_PLANS_FILE;
	$plans_list       = [];

	if (is_readable($nuvei_plans_path)) { 
		$plans_list = stripslashes(file_get_contents($nuvei_plans_path));
	}
	// get the list of the plans end

	// put translations here into the array
    $localizations = array_merge(
        NUVEI_JS_LOCALIZATIONS,
        [
            'security'          => wp_create_nonce('sc-security-nonce'),
            'nuveiPaymentPlans' => $plans_list,
            'webMasterId'       => 'WooCommerce ' . WOOCOMMERCE_VERSION
                . '; Plugin v' . nuvei_get_plugin_version(),
        ]
    );
    
	wp_localize_script('nuvei_js_admin', 'scTrans', $localizations);
	wp_enqueue_script('nuvei_js_admin');
}

// first method we come in
function nuvei_enqueue( $hook)
{
	global $wc_nuvei;
		
	# DMNs catch
    // sc_listener is legacy value
	if (in_array(Nuvei_Http::get_param('wc-api'), array('sc_listener', 'nuvei_listener'))) {
		//      add_action('wp_loaded', array($wc_nuvei, 'process_dmns'));
		add_action('wp_loaded', function() {
			global $wc_nuvei;
			
			//            $wc_nuvei->process_dmns();
			
			$nuvei_notify_dmn = new Nuvei_Notify_Url($wc_nuvei->settings);
			$nuvei_notify_dmn->process();
		});
	}
	
	// nuvei checkout step process order, after the internal submit from the checkout
	if ('process-order' == Nuvei_Http::get_param('wc-api')
		&& !empty(Nuvei_Http::get_param('order_id'))
	) {
		$wc_nuvei->process_payment(Nuvei_Http::get_param('order_id', 'int', 0));
	}
}
# Load Styles and Scripts END

/**
 * Add buttons for the Nuvei Order actions in Order details page.
 * 
 * @global Order $order
 * @return type
 */
function nuvei_add_buttons($order)
{
    Nuvei_Logger::write('nuvei_add_buttons');
    //echo '<pre style="text-align: left;">'.print_r(get_post_meta($order->get_id()), true) . '</pre>';
    
    // in case this is not Nuvei order
	if (empty($order->get_payment_method())
		|| !in_array($order->get_payment_method(), array(NUVEI_GATEWAY_NAME, 'sc'))
	) {
		echo '<script type="text/javascript">var notNuveiOrder = true;</script>';
		return false;
	}
    
    // to show Nuvei buttons we must be sure the order is paid via Nuvei Paygate
    // AMP's transactions does not have Auth code
    if (!$order->get_meta(NUVEI_AUTH_CODE_KEY) && !$order->get_meta(NUVEI_TRANS_ID)) {
        Nuvei_Logger::write('', 'Missing Transaction ID and Auth Code!', 'WARN');
        return false;
    }
    
	try {
		$order_id               = $order->get_id();
		$order_status           = strtolower($order->get_status());
		$order_payment_method   = $order->get_meta('_paymentMethod');
		$order_refunds          = json_decode($order->get_meta(NUVEI_REFUNDS), true);
		$refunds_exists         = false;
        $order_time             = 0;
        
        if (!is_null($order->get_date_created())) {
            $order_time = $order->get_date_created()->getTimestamp();
        }
        if (!is_null($order->get_date_completed())) {
            $order_time = $order->get_date_completed()->getTimestamp();
        }
        
		if (!empty($order_refunds) && is_array($order_refunds)) {
			foreach ($order_refunds as $data) {
				if ('approved' == $data['status']) {
					$refunds_exists = true;
					break;
				}
			}
		}
	}
    catch (Exception $ex) {
		echo '<script type="text/javascript">console.error("'
			. esc_js($ex->getMessage()) . '")</script>';
		exit;
	}
    
	// hide Refund Button
	if (!in_array($order_payment_method, NUVEI_APMS_REFUND_VOID)
		|| 'processing' == $order_status
        || 0 == $order->get_total()
	) {
		echo '<script type="text/javascript">jQuery(\'.refund-items\').prop("disabled", true);</script>';
	}
    
    # Show Cancel Subscription buttons - Legacy
    if ('active' == $order->get_meta(NUVEI_ORDER_SUBSCR_STATE)) {
        echo
            '<button id="sc_cancel_subs_btn" type="button" onclick="nuveiAction(\''
                . esc_html__('Are you sure, you want to cancel the subscription for this order?', 'nuvei_checkout_woocommerce')
                . '\', \'cancelSubscr\', \'' . esc_html($order_id) . '\')" class="button generate-items">'
                . esc_html__('Cancel Subscription', 'nuvei_checkout_woocommerce') . '</button>';
    }
	
	if (in_array($order_status, array('completed', 'pending', 'failed'))) {
		// Show VOID button
		if (in_array($order_payment_method, NUVEI_APMS_REFUND_VOID)
            && !empty($order->get_meta(NUVEI_AUTH_CODE_KEY))
            && !$refunds_exists
            && 0 < (float) $order->get_total()
            && time() < $order_time + 172800 // 48 hours
        ) {
            $question = sprintf(
				/* translators: %d is replaced with "decimal" */
				__('Are you sure, you want to Cancel Order #%d?', 'nuvei_checkout_woocommerce'),
				$order_id
			);
            
            // check for active subscriptions
            $all_meta = get_post_meta($order->get_id());
            
            foreach ($all_meta as $meta_key => $meta_data) {
                if (false !== strpos($meta_key, NUVEI_ORDER_SUBSCR)) {
                    $subscr_data = $order->get_meta($meta_key);
                    
                    if (!empty($subscr_data['state'])
                        && 'active' == $subscr_data['state']
                    ) {
                        $question = __('Are you sure, you want to Cancel this Order? This will also deactivate all Active Subscriptions.', 'nuvei_checkout_woocommerce');
                        break;
                    }
                }
            }
            // /check for active subscriptions
			
			echo
				'<button id="sc_void_btn" type="button" onclick="nuveiAction(\''
					. esc_html($question) . '\', \'void\', ' . esc_html($order_id)
					. ')" class="button generate-items">'
					. esc_html__('Void', 'nuvei_checkout_woocommerce') . '</button>';
		}
		
		// show SETTLE button ONLY if transaction type IS Auth and the Total is not 0
		if ('pending' == $order_status 
            && 'Auth' == $order->get_meta(NUVEI_RESP_TRANS_TYPE)
            && 0 < $order->get_total()
        ) {
			$question = sprintf(
				/* translators: %d is replaced with "decimal" */
				__('Are you sure, you want to Settle Order #%d?', 'nuvei_checkout_woocommerce'),
				$order_id
			);
			
			echo
				'<button id="sc_settle_btn" type="button" onclick="nuveiAction(\''
					. esc_html($question)
					. '\', \'settle\', \'' . esc_html($order_id) . '\')" class="button generate-items">'
					. esc_html__('Settle', 'nuvei_checkout_woocommerce') . '</button>';
		}
    }
    
    // add loading screen
	echo '<div id="custom_loader" class="blockUI blockOverlay" style="height: 100%; position: absolute; top: 0px; width: 100%; z-index: 10; background-color: rgba(255,255,255,0.5); display: none;"></div>';
}

/**
 * Function nuvei_rewrite_return_url
 * When user have problem with white spaces in the URL, it have option to
 * rewrite the return URL and redirect to new one.
 *
 * @global WC_SC $wc_nuvei
 */
function nuvei_rewrite_return_url() {
	if (isset($_REQUEST['ppp_status']) && '' != $_REQUEST['ppp_status']
		&& ( !isset($_REQUEST['wc_sc_redirected']) || 0 ==  $_REQUEST['wc_sc_redirected'] )
	) {
		$query_string = '';
		if (isset($_SERVER['QUERY_STRING'])) {
			$query_string = sanitize_text_field($_SERVER['QUERY_STRING']);
		}
		
		$server_protocol = '';
		if (isset($_SERVER['SERVER_PROTOCOL'])) {
			$server_protocol = sanitize_text_field($_SERVER['SERVER_PROTOCOL']);
		}
		
		$http_host = '';
		if (isset($_SERVER['HTTP_HOST'])) {
			$http_host = sanitize_text_field($_SERVER['HTTP_HOST']);
		}
		
		$request_uri = '';
		if (isset($_SERVER['REQUEST_URI'])) {
			$request_uri = sanitize_text_field($_SERVER['REQUEST_URI']);
		}
		
		$new_url = '';
		$host    = ( strpos($server_protocol, 'HTTP/') !== false ? 'http' : 'https' )
			. '://' . $http_host . current(explode('?', $request_uri));
		
		if ('' != $query_string) {
			$new_url = preg_replace('/\+|\s|\%20/', '_', $query_string);
			// put flag the URL was rewrited
			$new_url .= '&wc_sc_redirected=1';
			
			wp_redirect($host . '?' . $new_url);
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
 */
function nuvei_wpml_thank_you_page( $order_received_url, $order)
{
    if ($order->get_payment_method() != NUVEI_GATEWAY_NAME) {
        return;
    }
    
	$lang_code          = get_post_meta($order->id, 'wpml_language', true);
	$order_received_url = apply_filters('wpml_permalink', $order_received_url, $lang_code);
	
	Nuvei_Logger::write($order_received_url, 'nuvei_wpml_thank_you_page: ');
 
	return $order_received_url;
}

function nuvei_mod_thank_you_page($order_id)
{
    $order = wc_get_order($order_id);
    
    if ($order->get_payment_method() != NUVEI_GATEWAY_NAME) {
        return;
    }
    
    $output = '<script>';
    
    # Modify title and the text on errors
    $request_status = Nuvei_Http::get_request_status();
    $new_msg        = esc_html__('Please check your Order status for more information.', 'nuvei_checkout_woocommerce');
    $new_title      = '';
    
    if ('error' == $request_status
        || 'fail' == strtolower(Nuvei_Http::get_param('ppp_status'))
    ) {
        $new_title  = esc_html__('Order error', 'nuvei_checkout_woocommerce');
    }
    elseif ('canceled' == $request_status) {
        $new_title  = esc_html__('Order canceled', 'nuvei_checkout_woocommerce');
    }
    
    if (!empty($new_title)) {
        $output .= 'jQuery(".entry-title").html("'. $new_title .'");'
            . 'jQuery(".woocommerce-thankyou-order-received").html("'. $new_msg .'");';
    }
    # /Modify title and the text on errors
    
    # when WCS is turn on, remove Pay button
    if (is_plugin_active('woocommerce-subscriptions' . DIRECTORY_SEPARATOR . 'woocommerce-subscriptions.php')) {
        $output .= 'jQuery("a.pay").hide();';
    }
    
    $output .= '</script>';
    
    echo $output;
}

function nuvei_edit_order_buttons()
{
	$default_text          = __('Place order', 'woocommerce');
	$sc_continue_text      = __('Continue', 'woocommerce');
	$chosen_payment_method = WC()->session->get('chosen_payment_method');
	
	// save default text into button attribute ?><script>
		(function($){
			$('#place_order')
				.attr('data-default-text', '<?php echo esc_attr($default_text); ?>')
				.attr('data-sc-text', '<?php echo esc_attr($sc_continue_text); ?>');
		})(jQuery);
	</script>
	<?php

	// check for 'sc' also, because of the older Orders
	if (in_array($chosen_payment_method, array(NUVEI_GATEWAY_NAME, 'sc'))) {
		return $sc_continue_text;
	}

	return $default_text;
}

function nuvei_change_title_order_received( $title, $id) {
	if (function_exists('is_order_received_page')
		&& is_order_received_page()
		&& get_the_ID() === $id
	) {
		$title = esc_html__('Order error', 'nuvei_checkout_woocommerce');
	}
	
	return $title;
}

/**
 * Call this on Store when the logged user is in My Account section
 * 
 * @global type $wp
 */
function nuvei_user_orders()
{
	global $wp;
	
	$order     = wc_get_order($wp->query_vars['order-pay']);
	$order_key = $order->get_order_key();
	
	// check for 'sc' also, because of the older Orders
	if (!in_array($order->get_payment_method(), array(NUVEI_GATEWAY_NAME, 'sc'))) {
		return;
	}
	
	if (Nuvei_Http::get_param('key') != $order_key) {
		return;
	}
	
	$prods_ids = array();
	
	foreach ($order->get_items() as $data) {
		$prods_ids[] = $data->get_product_id();
	}
	
	echo '<script>'
		. 'var scProductsIdsToReorder = ' . wp_kses_post(json_encode($prods_ids)) . ';'
		. 'scOnPayOrderPage();'
	. '</script>';
}

// on reorder, show warning message to the cart if need to
function nuvei_show_message_on_cart( $data)
{
	echo '<script>jQuery("#content .woocommerce:first").append("<div class=\'woocommerce-warning\'>'
		. wp_kses_post(Nuvei_Http::get_param('sc_msg')) . '</div>");</script>';
}

// Attributes, Terms and Meta functions
function nuvei_add_term_fields_form( $taxonomy)
{
	$nuvei_plans_path = NUVEI_LOGS_DIR . NUVEI_PLANS_FILE;
	
	ob_start();
	
	$plans_list = array();
	if (is_readable($nuvei_plans_path)) {
		$plans_list = json_decode(file_get_contents($nuvei_plans_path), true);
	}
	
	require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'templates/admin/add_terms_form.php';
	
	ob_end_flush();
}

function nuvei_edit_term_meta_form( $term, $taxonomy)
{
	$nuvei_plans_path = NUVEI_LOGS_DIR . NUVEI_PLANS_FILE;

	ob_start();
	$term_meta  = get_term_meta($term->term_id);
	$plans_list = array();
	$plans_json = '';
	
	if (is_readable($nuvei_plans_path)) {
		$plans_json = file_get_contents($nuvei_plans_path);
		$plans_list = json_decode($plans_json, true);
	}
	
	// clean unused elements
	foreach ($term_meta as $key => $data) {
		if (strpos($key, '_') !== false) {
			unset($term_meta[$key]);
			break;
		}
	}
	
	require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'templates/admin/edit_term_form.php';
	ob_end_flush();
}

function nuvei_save_term_meta( $term_id, $tt_id)
{
	$taxonomy      = 'pa_' . Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME);
	$post_taxonomy = Nuvei_Http::get_param('taxonomy', 'string');
	
	if ($post_taxonomy != $taxonomy) {
		return;
	}
	
	add_term_meta( $term_id, 'planId', Nuvei_Http::get_param('planId', 'int') );
	add_term_meta( $term_id, 'recurringAmount', Nuvei_Http::get_param('recurringAmount', 'float') );

	add_term_meta( $term_id, 'startAfterUnit', Nuvei_Http::get_param('startAfterUnit', 'string') );
	add_term_meta( $term_id, 'startAfterPeriod', Nuvei_Http::get_param('startAfterPeriod', 'int') );

	add_term_meta( $term_id, 'recurringPeriodUnit', Nuvei_Http::get_param('recurringPeriodUnit', 'string') );
	add_term_meta( $term_id, 'recurringPeriodPeriod', Nuvei_Http::get_param('recurringPeriodPeriod', 'int') );

	add_term_meta( $term_id, 'endAfterUnit', Nuvei_Http::get_param('endAfterUnit', 'string') );
	add_term_meta( $term_id, 'endAfterPeriod', Nuvei_Http::get_param('endAfterPeriod', 'int') );
}

function nuvei_edit_term_meta( $term_id, $tt_id)
{
	$taxonomy      = 'pa_' . Nuvei_String::get_slug(NUVEI_GLOB_ATTR_NAME);
	$post_taxonomy = Nuvei_Http::get_param('taxonomy', 'string');
	
	if ($post_taxonomy != $taxonomy) {
		return;
	}
	
	update_term_meta( $term_id, 'planId', Nuvei_Http::get_param('planId', 'int') );
	update_term_meta( $term_id, 'recurringAmount', Nuvei_Http::get_param('recurringAmount', 'float') );

	update_term_meta( $term_id, 'startAfterUnit', Nuvei_Http::get_param('startAfterUnit', 'string') );
	update_term_meta( $term_id, 'startAfterPeriod', Nuvei_Http::get_param('startAfterPeriod', 'int') );

	update_term_meta( $term_id, 'recurringPeriodUnit', Nuvei_Http::get_param('recurringPeriodUnit', 'string') );
	update_term_meta( $term_id, 'recurringPeriodPeriod', Nuvei_Http::get_param('recurringPeriodPeriod', 'int') );

	update_term_meta( $term_id, 'endAfterUnit', Nuvei_Http::get_param('endAfterUnit', 'string') );
	update_term_meta( $term_id, 'endAfterPeriod', Nuvei_Http::get_param('endAfterPeriod', 'int') );
}
// Attributes, Terms and Meta functions END

# For the custom column in the Order list
function nuvei_fill_custom_column( $column)
{
	global $post;
	
	$order              = wc_get_order($post->ID);
    $old_subscr         = $order->get_meta(NUVEI_ORDER_SUBSCR_ID); // int
    $new_subscr_data    = $order->get_meta(NUVEI_ORDER_SUBSCR); // array
    
    
    $html_baloon = '<mark class="order-status status-processing tips" style="float: right;"><span>'
        . esc_html__('Nuvei Subscription', 'nuvei_checkout_woocommerce') . '</span></mark>';
    
    // check for old data
    if (!empty($old_subscr) && 'order_number' === $column) {
        echo $html_baloon;
        return;
    }
    
    // check for new data
    $post_meta = get_post_meta($post->ID);
    
    if (empty($post_meta) || !is_array($post_meta)) {
        return;
    }
    
    foreach ($post_meta as $key => $data) {
        if (false === strpos($key, NUVEI_ORDER_SUBSCR)) {
            continue;
        }
        
        if ('order_number' === $column) {
            echo $html_baloon;
            return;
        }
    }
}
# For the custom column in the Order list END

/**
 * In Store > My Account > Orders table, Order column
 * add Rebilling icon for the Orders with Nuvei Payment Plan.
 * 
 * @param WC_Order $order
 */
function nuvei_edit_my_account_orders_col( $order) {
	echo '<a href="' . esc_url( $order->get_view_order_url() ) . '"';
	
	if (!empty($order->get_meta(NUVEI_ORDER_SUBSCR_ID))) {
		echo ' class="nuvei_plan_order" title="' . esc_attr__('Nuvei Payment Plan Order', 'nuvei_checkout_woocommerce') . '"';
	}
	
	echo '>#' . esc_html($order->get_order_number()) . '</a>';
}

/**
 * Repeating code from Version Checker logic.
 * 
 * @param string $file the path to the file we will save
 * @return array
 */
function nuvei_get_file_form_git( $file) {
	$matches = array();
	$ch      = curl_init();

	curl_setopt(
		$ch,
		CURLOPT_URL,
		NUVEI_GIT_REPO . '/main/index.php'
	);

	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

	$file_text = curl_exec($ch);
	curl_close($ch);

	preg_match('/(\s?\*\s?Version\s?:\s?)(.*\s?)(\n)/', $file_text, $matches);

	if (!isset($matches[2])) {
		return array();
	}
	
	$array = array(
		'date'  => gmdate('Y-m-d H:i:s', time()),
		'git_v' => (int) str_replace('.', '', trim($matches[2])),
	);

	file_put_contents($file, json_encode($array));
	
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
function nuvei_wc_cart_needs_payment($needs_payment, $cart)
{
    $cart_items = $cart->get_cart();

    foreach ( $cart_items as $item ) {
        $cart_product   = wc_get_product( $item['product_id'] );
        $cart_prod_attr = $cart_product->get_attributes();

        // check for product with a payment plan
        if ( ! empty( $cart_prod_attr[ 'pa_' . Nuvei_String::get_slug( NUVEI_GLOB_ATTR_NAME ) ] )) {
            $needs_payment = true;
        }
    }
    
    return $needs_payment;
}

function nuvei_after_order_itemmeta($item_id, $item, $_product)
{
    $post_id        = Nuvei_Http::get_param('post', 'int');
    $order          = wc_get_order($post_id);
    $subs_id        = $order->get_meta(NUVEI_ORDER_SUBSCR_ID);
    $post_meta      = get_post_meta($post_id);
    
    if (empty($post_meta) || !is_array($post_meta)) {
        return;
    }
    
    foreach ($post_meta as $mk => $md) {
        if (false === strpos($mk, NUVEI_ORDER_SUBSCR)) {
            continue;
        }
        
        if ('WC_Order_Item_Product' != get_class($item)) {
            continue;
        }
        
        // because of some delay this may not work, and have to refresh the page
        try {
            Nuvei_Logger::write('check nuvei_after_order_itemmeta');
            
            $subscr_data    = $order->get_meta($mk);
            $key_parts      = explode('_', $mk);
            $item_variation = $item->get_variation_id();
            $product_id     = $item->get_product_id();
        }
        catch (\Exception $ex) {
            Nuvei_Logger::write([
                'exception' => $ex->getMessage(),
                'item'      => $item,
            ]);
            continue;
        }
        
//        echo '<pre>'.print_r([$mk, $subscr_data],true).'</pre>';
////        echo '<pre>'.print_r($subscr_data,true).'</pre>';
//        echo '<pre>'.print_r([$item_id, $product_id],true).'</pre>';
//        echo '<pre>'.print_r([$item_id, $item->get_variation_id()],true).'</pre>';
        
        if (empty($subscr_data['state']) || empty ($subscr_data['subscr_id'])) {
            // wait for meta data to be created
            continue;
        }
        
        // in case of variations item
        if ( (0 != $item_variation && 'variation' == $key_parts[2] && $item_variation == $key_parts[3])
            // in case of attribute only item
            || (0 == $item_variation && 'product' == $key_parts[2] && $product_id == $key_parts[3])
        ) {
            // show Subscr ID and Cancel Subsc button
            echo '<div class="wc-order-item-variation" style="margin-top: 0px;"><strong>'
                . __('Nuvei Subscription ID:', 'nuvei_checkout_woocommerce') .'</strong> '
                . esc_html($subscr_data['subscr_id']) .'</div>';
            
            if (!empty($subscr_data['state']) && 'active' == $subscr_data['state']) {
                echo
                    '<button id="nuvei_cancel_subs_'. esc_html($subscr_data['subscr_id']) 
                        .'" class="nuvei_cancel_subscr button generate-items" type="button" '
                        . 'style="margin-top: .5em;" onclick="nuveiAction(\''
                        . esc_html__('Are you sure, you want to cancel this subscription?', 'nuvei_checkout_woocommerce')
                        . '\', \'cancelSubscr\', ' . esc_html(0) . ', ' . esc_html($subscr_data['subscr_id']) 
                        . ')">' . esc_html__('Cancel Subscription', 'nuvei_checkout_woocommerce') 
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
function nuvei_get_plugin_version()
{
    $plugin_data  = get_plugin_data(__FILE__);
    return $plugin_data['Version'];
}


