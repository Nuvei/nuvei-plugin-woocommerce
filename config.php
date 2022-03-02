<?php

/**
 * Put all Constants here.
 */

define('NUVEI_GATEWAY_TITLE', 'Nuvei');
define('NUVEI_GATEWAY_NAME', 'nuvei'); // the name by WC recognize this Gateway

// some keys for order metadata, we make them hiden when starts with underscore
define('NUVEI_AUTH_CODE_KEY', '_authCode');
define('NUVEI_TRANS_ID', '_transactionId');
define('NUVEI_RESP_TRANS_TYPE', '_transactionType');
define('NUVEI_PAYMENT_METHOD', '_paymentMethod');
define('NUVEI_ORDER_HAS_REFUND', '_scHasRefund');
define('NUVEI_REFUNDS', '_sc_refunds');
define('NUVEI_ORDER_SUBSCR_IDS', '_nuveiSubscrIDs');
define('NUVEI_SOURCE_APPLICATION', 'WOOCOMMERCE_PLUGIN');
define('NUVEI_GLOB_ATTR_NAME', 'Nuvei Payment Plan'); // the name of the Nuvei Global Product Attribute name
define('NUVEI_STOP_DMN', 0); // manually stop DMN process
define('NUVEI_CUID_POSTFIX', '_sandbox_apm'); // postfix for Sandbox APM payments
define('NUVEI_TRANS_CURR', '_transactionCurrency');

define('NUVEI_REST_ENDPOINT_INT',   'https://ppp-test.safecharge.com/ppp/api/v1/');
define('NUVEI_REST_ENDPOINT_PROD',  'https://secure.safecharge.com/ppp/api/v1/');

define('NUVEI_SDK_URL_INT',   'https://srv-bsf-devpppjs.gw-4u.com/checkoutNext/checkout.js');
define('NUVEI_SDK_URL_PROD',  'https://cdn.safecharge.com/safecharge_resources/v1/checkout/checkout.js');

define('NUVEI_JS_LOCALIZATIONS', [
    'ajaxurl'               => admin_url('admin-ajax.php'),
    'sourceApplication'     => NUVEI_SOURCE_APPLICATION,
    'plugin_dir_url'        => plugin_dir_url(__FILE__),
    'paymentGatewayName'    => NUVEI_GATEWAY_NAME,
    
    // translations
    'paymentDeclined'	=> __('Your Payment was DECLINED. Please, try another payment option!', 'nuvei_checkout_woocommerce'),
    'paymentError'      => __('Error with your Payment.', 'nuvei_checkout_woocommerce'),
    'unexpectedError'	=> __('Unexpected error. Please, try another payment option!', 'nuvei_checkout_woocommerce'),
    'fillFields'        => __('Please fill all fields marked with * !', 'nuvei_checkout_woocommerce'),
    'errorWithSToken'	=> __('Error when try to get the Session Token.', 'nuvei_checkout_woocommerce'),
    'goBack'            => __('Go back', 'nuvei_checkout_woocommerce'),
    'RequestFail'       => __('Request fail.', 'nuvei_checkout_woocommerce'),
    'ApplePayError'     => __('Unexpected session error.', 'nuvei_checkout_woocommerce'),
    'TryAgainLater'     => __('Please try again later!', 'nuvei_checkout_woocommerce'),
    'TryAnotherPM'      => __('Please try another payment method!', 'nuvei_checkout_woocommerce'),
    'Pay'               => __('Pay', 'nuvei_checkout_woocommerce'),
    'PlaceOrder'        => __('Place order', 'nuvei_checkout_woocommerce'),
    'refundQuestion'    => __('Are you sure about this Refund?', 'nuvei_checkout_woocommerce'),
    'LastDownload'		=> __('Last Download', 'nuvei_checkout_woocommerce'),
    'ReadLog'           => __('Read Log', 'nuvei_checkout_woocommerce'),
    'RefreshLogError'   => __('Getting log faild, please check the console for more information!', 'nuvei_checkout_woocommerce'),
]);

define('NUVEI_PARAMS_VALIDATION', [
    // deviceDetails
    'deviceType' => array(
        'length' => 10,
        'flag'    => FILTER_SANITIZE_STRING
    ),
    'deviceName' => array(
        'length' => 255,
        'flag'    => FILTER_DEFAULT
    ),
    'deviceOS' => array(
        'length' => 255,
        'flag'    => FILTER_DEFAULT
    ),
    'browser' => array(
        'length' => 255,
        'flag'    => FILTER_DEFAULT
    ),
    // deviceDetails END

    // userDetails, shippingAddress, billingAddress
    'firstName' => array(
        'length' => 30,
        'flag'    => FILTER_DEFAULT
    ),
    'lastName' => array(
        'length' => 40,
        'flag'    => FILTER_DEFAULT
    ),
    'address' => array(
        'length' => 60,
        'flag'    => FILTER_DEFAULT
    ),
    'cell' => array(
        'length' => 18,
        'flag'    => FILTER_DEFAULT
    ),
    'phone' => array(
        'length' => 18,
        'flag'    => FILTER_DEFAULT
    ),
    'zip' => array(
        'length' => 10,
        'flag'    => FILTER_DEFAULT
    ),
    'city' => array(
        'length' => 30,
        'flag'    => FILTER_DEFAULT
    ),
    'country' => array(
        'length' => 20,
        'flag'    => FILTER_SANITIZE_STRING
    ),
    'state' => array(
        'length' => 2,
        'flag'    => FILTER_SANITIZE_STRING
    ),
    'county' => array(
        'length' => 255,
        'flag'    => FILTER_DEFAULT
    ),
    // userDetails, shippingAddress, billingAddress END

    // specific for shippingAddress
    'shippingCounty' => array(
        'length' => 255,
        'flag'    => FILTER_DEFAULT
    ),
    'addressLine2' => array(
        'length' => 50,
        'flag'    => FILTER_DEFAULT
    ),
    'addressLine3' => array(
        'length' => 50,
        'flag'    => FILTER_DEFAULT
    ),
    // specific for shippingAddress END

    // urlDetails
    'successUrl' => array(
        'length' => 1000,
        'flag'    => FILTER_VALIDATE_URL
    ),
    'failureUrl' => array(
        'length' => 1000,
        'flag'    => FILTER_VALIDATE_URL
    ),
    'pendingUrl' => array(
        'length' => 1000,
        'flag'    => FILTER_VALIDATE_URL
    ),
    'notificationUrl' => array(
        'length' => 1000,
        'flag'    => FILTER_VALIDATE_URL
    ),
    // urlDetails END
]);

define('NUVEI_PARAMS_VALIDATION_EMAIL', [
    'length'    => 79,
    'flag'      => FILTER_VALIDATE_EMAIL
]);

define('NUVEI_BROWSERS_LIST', ['ucbrowser', 'firefox', 'chrome', 'opera', 'msie', 'edge', 'safari', 'blackberry', 'trident']);
define('NUVEI_DEVICES_LIST', ['iphone', 'ipad', 'android', 'silk', 'blackberry', 'touch', 'linux', 'windows', 'mac']);
define('NUVEI_DEVICES_TYPES_LIST', ['macintosh', 'tablet', 'mobile', 'tv', 'windows', 'linux', 'tv', 'smarttv', 'googletv', 'appletv', 'hbbtv', 'pov_tv', 'netcast.tv', 'bluray']);
