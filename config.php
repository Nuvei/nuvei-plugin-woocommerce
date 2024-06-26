<?php

/**
 * Put all Constants here.
 */

const NUVEI_GATEWAY_TITLE   = 'Nuvei';
const NUVEI_GATEWAY_NAME    = 'nuvei'; // the name by WC recognize this Gateway

// keys for order metadata, we make them hiden when starts with underscore
const NUVEI_TR_ID               = '_nuveiTrId'; // we will keep this data for fast search in Orders
const NUVEI_ORDER_ID            = '_nuveiOrderId';
const NUVEI_CLIENT_UNIQUE_ID    = '_nuveiClientUniqueId';
const NUVEI_ORDER_CHANGES       = '_nuveiOrderChanges'; // mark here total ana currency changes
const NUVEI_WC_SUBSCR           = '_wcSubscription';
const NUVEI_WC_RENEWAL          = '_wcsRenewal';
const NUVEI_TRANSACTIONS        = '_nuveiTransactions';
const NUVEI_ORDER_SUBSCR        = '_nuveiSubscr';
const NUVEI_DCC_DATA            = '_nuveiDccData';

//const NUVEI_CUID_POSTFIX        = '_sandbox_apm'; // postfix for Sandbox APM payments
const NUVEI_PREV_TRANS_STATUS   = '_nuveiPrevTransactionStatus';

const NUVEI_SOURCE_APPLICATION  = 'WOOCOMMERCE_PLUGIN';
const NUVEI_GLOB_ATTR_NAME      = 'Nuvei Payment Plan'; // the name of the Nuvei Global Product Attribute name
const NUVEI_LOG_EXT             = 'log';
const NUVEI_PLANS_FILE          = 'sc_plans.json';
const NUVEI_APMS_REFUND_VOID    = array( 'cc_card', 'apmgw_expresscheckout' );

const NUVEI_REST_ENDPOINT_INT   = 'https://ppp-test.nuvei.com/ppp/api/v1/';
const NUVEI_REST_ENDPOINT_PROD  = 'https://secure.safecharge.com/ppp/api/v1/';

/**
 * @var string The SDK Prod URL.
 * @deprecated since version 3.0.0
 */
const NUVEI_SDK_URL_PROD = 'https://cdn.safecharge.com/safecharge_resources/v1/checkout/simplyConnect.js';
/**
 * @var string The SDK Tag URL.
 * @deprecated since version 3.0.0
 */
const NUVEI_SDK_URL_TAG = 'https://srv-bsf-devpppjs.gw-4u.com/checkoutNext_tag/simplyConnect.js';

const NUVEI_SDK_AUTOCLOSE_URL       = 'https://cdn.safecharge.com/safecharge_resources/v1/websdk/autoclose.html';

const NUVEI_SESSION_OO_DETAILS      = 'nuvei_last_open_order_details'; // a session key
const NUVEI_SESSION_PROD_DETAILS    = 'nuvei_order_details'; // products details
const NUVEI_SESSION_PLUGIN_GIT_V    = 'nuvei_plugin_git_version';

const LOG_REQUEST_PARAMS            = 'Request params';

define(
	'NUVEI_LOGS_DIR',
	dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR
	. 'uploads' . DIRECTORY_SEPARATOR . 'nuvei-logs' . DIRECTORY_SEPARATOR
);

define(
	'NUVEI_JS_LOCALIZATIONS',
	array(
		'ajaxurl'               => admin_url( 'admin-ajax.php' ),
		'sourceApplication'     => NUVEI_SOURCE_APPLICATION,
		'plugin_dir_url'        => plugin_dir_url( __FILE__ ),
		'paymentGatewayName'    => NUVEI_GATEWAY_NAME,
		'simplyConnectUrl'      => plugin_dir_url( __FILE__ ) . 'assets/js/nuveiSimplyConnect/simplyConnect.js',

		// translations
		'insuffFunds'       => __( 'You have Insufficient funds, please go back and remove some of the items in your shopping cart, or use another card.', 'nuvei-checkout-for-woocommerce' ),
		'paymentDeclined'   => __( 'Your Payment was DECLINED. Please, try another payment option!', 'nuvei-checkout-for-woocommerce' ),
		'paymentError'      => __( 'Error with your Payment.', 'nuvei-checkout-for-woocommerce' ),
		'unexpectedError'   => __( 'Unexpected error. Please, try another payment option!', 'nuvei-checkout-for-woocommerce' ),
		'fillFields'        => __( 'Please fill all fields marked with * !', 'nuvei-checkout-for-woocommerce' ),
		'errorWithSToken'   => __( 'Error when try to get the Session Token.', 'nuvei-checkout-for-woocommerce' ),
		'goBack'            => __( 'Go back', 'nuvei-checkout-for-woocommerce' ),
		'RequestFail'       => __( 'Request fail.', 'nuvei-checkout-for-woocommerce' ),
		'ApplePayError'     => __( 'Unexpected session error.', 'nuvei-checkout-for-woocommerce' ),
		'TryAgainLater'     => __( 'Please try again later!', 'nuvei-checkout-for-woocommerce' ),
		'TryAnotherPM'      => __( 'Please try another payment method!', 'nuvei-checkout-for-woocommerce' ),
		'Pay'               => __( 'Pay', 'nuvei-checkout-for-woocommerce' ),
		'PlaceOrder'        => __( 'Place order', 'nuvei-checkout-for-woocommerce' ),
		'Continue'          => __( 'Continue', 'nuvei-checkout-for-woocommerce' ),
		'refundQuestion'    => __( 'Are you sure about this Refund?', 'nuvei-checkout-for-woocommerce' ),
		'LastDownload'      => __( 'Last Download', 'nuvei-checkout-for-woocommerce' ),
		'ReadLog'           => __( 'Read Log', 'nuvei-checkout-for-woocommerce' ),
		'RefreshLogError'   => __( 'Getting log faild, please check the console for more information!', 'nuvei-checkout-for-woocommerce' ),
		'CheckoutFormError' => __( 'Checkout form class error, please contact the site administrator!', 'nuvei-checkout-for-woocommerce' ),
		'TransactionAppr'   => __( 'The transaction was approved.', 'nuvei-checkout-for-woocommerce' ),
	)
);

define(
	'NUVEI_PARAMS_VALIDATION',
	array(
		// deviceDetails
		'deviceType' => array(
			'length' => 10,
			'flag'    => FILTER_DEFAULT,
		),
		'deviceName' => array(
			'length' => 255,
			'flag'    => FILTER_DEFAULT,
		),
		'deviceOS' => array(
			'length' => 255,
			'flag'    => FILTER_DEFAULT,
		),
		'browser' => array(
			'length' => 255,
			'flag'    => FILTER_DEFAULT,
		),
		// deviceDetails END

		// userDetails, shippingAddress, billingAddress
		'firstName' => array(
			'length' => 30,
			'flag'    => FILTER_DEFAULT,
		),
		'lastName' => array(
			'length' => 40,
			'flag'    => FILTER_DEFAULT,
		),
		'address' => array(
			'length' => 60,
			'flag'    => FILTER_DEFAULT,
		),
		'cell' => array(
			'length' => 18,
			'flag'    => FILTER_DEFAULT,
		),
		'phone' => array(
			'length' => 18,
			'flag'    => FILTER_DEFAULT,
		),
		'zip' => array(
			'length' => 10,
			'flag'    => FILTER_DEFAULT,
		),
		'city' => array(
			'length' => 30,
			'flag'    => FILTER_DEFAULT,
		),
		'country' => array(
			'length' => 20,
			'flag'    => FILTER_DEFAULT,
		),
		'state' => array(
			'length' => 2,
			'flag'    => FILTER_DEFAULT,
		),
		'county' => array(
			'length' => 255,
			'flag'    => FILTER_DEFAULT,
		),
		// userDetails, shippingAddress, billingAddress END

		// specific for shippingAddress
		'shippingCounty' => array(
			'length' => 255,
			'flag'    => FILTER_DEFAULT,
		),
		'addressLine2' => array(
			'length' => 50,
			'flag'    => FILTER_DEFAULT,
		),
		'addressLine3' => array(
			'length' => 50,
			'flag'    => FILTER_DEFAULT,
		),
		// specific for shippingAddress END

		// urlDetails
		'successUrl' => array(
			'length' => 1000,
			'flag'    => FILTER_VALIDATE_URL,
		),
		'failureUrl' => array(
			'length' => 1000,
			'flag'    => FILTER_VALIDATE_URL,
		),
		'pendingUrl' => array(
			'length' => 1000,
			'flag'    => FILTER_VALIDATE_URL,
		),
		'notificationUrl' => array(
			'length' => 1000,
			'flag'    => FILTER_VALIDATE_URL,
		),
	// urlDetails END
	)
);

define(
	'NUVEI_PARAMS_VALIDATION_EMAIL',
	array(
		'length'    => 79,
		'flag'      => FILTER_VALIDATE_EMAIL,
	)
);

define( 'NUVEI_BROWSERS_LIST', array( 'ucbrowser', 'firefox', 'chrome', 'opera', 'msie', 'edge', 'safari', 'blackberry', 'trident' ) );
define( 'NUVEI_DEVICES_LIST', array( 'iphone', 'ipad', 'android', 'silk', 'blackberry', 'touch', 'linux', 'windows', 'mac' ) );
define( 'NUVEI_DEVICES_TYPES_LIST', array( 'macintosh', 'tablet', 'mobile', 'tv', 'windows', 'linux', 'tv', 'smarttv', 'googletv', 'appletv', 'hbbtv', 'pov_tv', 'netcast.tv', 'bluray' ) );
