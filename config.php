<?php

defined( 'ABSPATH' ) || exit;

/**
 * Put all Constants here.
 */

const NUVEI_PFW_GATEWAY_TITLE   = 'Nuvei';
const NUVEI_PFW_GATEWAY_NAME    = 'nuvei'; // the name by WC recognize this Gateway

// keys for order metadata, we make them hiden when starts with underscore
const NUVEI_PFW_TR_ID             = '_nuveiTrId'; // we will keep this data for fast search in Orders
const NUVEI_PFW_ORDER_ID          = '_nuveiOrderId';
const NUVEI_PFW_CLIENT_UNIQUE_ID  = '_nuveiClientUniqueId';
const NUVEI_PFW_ORDER_CHANGES     = '_nuveiOrderChanges'; // mark here total ana currency changes
const NUVEI_PFW_WC_SUBSCR         = '_wcSubscription';
const NUVEI_PFW_WC_RENEWAL        = '_wcsRenewal';
const NUVEI_PFW_TRANSACTIONS      = '_nuveiTransactions';
const NUVEI_PFW_ORDER_SUBSCR      = '_nuveiSubscr';
const NUVEI_PFW_PREV_TRANS_STATUS = '_nuveiPrevTransactionStatus';

const NUVEI_PFW_SOURCE_APPLICATION = 'WOOCOMMERCE_PLUGIN';
const NUVEI_PFW_GLOB_ATTR_NAME     = 'Nuvei Payment Plan'; // the name of the Nuvei Global Product Attribute name
const NUVEI_PFW_LOG_EXT            = 'log';
const NUVEI_PFW_PLANS_FILE         = 'sc_plans.json';
const NUVEI_PFW_PMS_REFUND_VOID    = array( 'cc_card', 'apmgw_expresscheckout' );

const NUVEI_PFW_REST_ENDPOINT_INT   = 'https://ppp-test.nuvei.com/ppp/api/v1/';
const NUVEI_PFW_REST_ENDPOINT_PROD  = 'https://secure.safecharge.com/ppp/api/v1/';
const NUVEI_PFW_SDK_URL_PROD        = 'https://cdn.safecharge.com/safecharge_resources/v1/checkout/checkout.js';
const NUVEI_PFW_SDK_URL_TAG         = 'https://devmobile.sccdev-qa.com/checkoutNext/checkout.js';
const NUVEI_PFW_POPUP_AUTOCLOSE_URL = 'https://cdn.safecharge.com/safecharge_resources/v1/websdk/autoclose.html';

const NUVEI_PFW_SESSION_OO_DETAILS   = 'nuvei_last_open_order_details'; // a session key
const NUVEI_PFW_SESSION_PROD_DETAILS = 'nuvei_order_details'; // products details
const NUVEI_PFW_LOG_REQUEST_PARAMS   = 'Request params';

define(
	'NUVEI_PFW_LOGS_DIR',
	dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR
		. 'uploads' . DIRECTORY_SEPARATOR . 'nuvei-logs' . DIRECTORY_SEPARATOR
);

define(
	'NUVEI_PFW_PARAMS_VALIDATION',
	array(
		// deviceDetails
		'deviceType'      => array(
			'length' => 10,
			'flag'   => FILTER_DEFAULT,
		),
		'deviceName'      => array(
			'length' => 255,
			'flag'   => FILTER_DEFAULT,
		),
		'deviceOS'        => array(
			'length' => 255,
			'flag'   => FILTER_DEFAULT,
		),
		'browser'         => array(
			'length' => 255,
			'flag'   => FILTER_DEFAULT,
		),
		// deviceDetails END

		// userDetails, shippingAddress, billingAddress
		'firstName'       => array(
			'length' => 30,
			'flag'   => FILTER_DEFAULT,
		),
		'lastName'        => array(
			'length' => 40,
			'flag'   => FILTER_DEFAULT,
		),
		'address'         => array(
			'length' => 60,
			'flag'   => FILTER_DEFAULT,
		),
		'cell'            => array(
			'length' => 18,
			'flag'   => FILTER_DEFAULT,
		),
		'phone'           => array(
			'length' => 18,
			'flag'   => FILTER_DEFAULT,
		),
		'zip'             => array(
			'length' => 10,
			'flag'   => FILTER_DEFAULT,
		),
		'city'            => array(
			'length' => 30,
			'flag'   => FILTER_DEFAULT,
		),
		'country'         => array(
			'length' => 20,
			'flag'   => FILTER_DEFAULT,
		),
		'state'           => array(
			'length' => 2,
			'flag'   => FILTER_DEFAULT,
		),
		'county'          => array(
			'length' => 255,
			'flag'   => FILTER_DEFAULT,
		),
		// userDetails, shippingAddress, billingAddress END

		// specific for shippingAddress
		'shippingCounty'  => array(
			'length' => 255,
			'flag'   => FILTER_DEFAULT,
		),
		'addressLine2'    => array(
			'length' => 50,
			'flag'   => FILTER_DEFAULT,
		),
		'addressLine3'    => array(
			'length' => 50,
			'flag'   => FILTER_DEFAULT,
		),
		// specific for shippingAddress END

		// urlDetails
		'successUrl'      => array(
			'length' => 1000,
			'flag'   => FILTER_VALIDATE_URL,
		),
		'failureUrl'      => array(
			'length' => 1000,
			'flag'   => FILTER_VALIDATE_URL,
		),
		'pendingUrl'      => array(
			'length' => 1000,
			'flag'   => FILTER_VALIDATE_URL,
		),
		'notificationUrl' => array(
			'length' => 1000,
			'flag'   => FILTER_VALIDATE_URL,
		),
	// urlDetails END
	)
);

define(
	'NUVEI_PFW_PARAMS_VALIDATION_EMAIL',
	array(
		'length' => 79,
		'flag'   => FILTER_VALIDATE_EMAIL,
	)
);

define( 'NUVEI_PFW_BROWSERS_LIST', array( 
    'ucbrowser', 
    'firefox', 
    'chrome', 
    'opera', 
    'msie', 
    'edge', 
    'safari', 
    'blackberry', 
    'trident' 
) );

define( 'NUVEI_PFW_DEVICES_LIST', array( 
    'iphone', 
    'ipad', 
    'android', 
    'silk', 
    'blackberry', 
    'touch', 
    'linux', 
    'windows', 
    'mac' 
) );

define( 'NUVEI_PFW_DEVICES_TYPES_LIST', array( 
    'macintosh', 
    'tablet', 
    'mobile', 
    'tv', 
    'windows', 
    'linux', 
    'tv', 
    'smarttv', 
    'googletv', 
    'appletv', 
    'hbbtv', 
    'pov_tv', 
    'netcast.tv', 
    'bluray' 
) );
