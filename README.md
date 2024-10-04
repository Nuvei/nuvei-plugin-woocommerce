# Nuvei Payments for Woocommerce

## Description
Nuvei supports major international credit and debit cards enabling you to accept payments from your global customers. 

A wide selection of region-specific payment methods can help your business grow in new markets. Other popular payment methods, from mobile payments to e-wallets, can be easily implemented on your checkout page.

The correct payment methods at the checkout page can bring you global reach, help you increase conversions, and create a seamless experience for your customers.

## Important Note
If you have installed a plugin version earlier than v1.3.0 and you are upgrading for the first time, then you should deactivate and activate plugin again after the upgrade, so new logs directory be created!

If you are used our plugin before v3.2.0, please change plugin setting "Status Authorized" in "Advanced Settings" tab to "On-hold"!

## System Requirements
Enabled PHP cURL support.

Public access to the plugin notify URL. Check plugin settings, Help Tools tab, for it.

Wordpress: 
  - Minimum v4.7.
  - Tested up to v6.6.2.

WooCommerce: 
  - Minimum v3.0.
  - Tested up to v9.3.3.

## Nuvei Requirements
Merchant configuration: 
  - Enabled DMNs.
  - On SiteID level "DMN  timeout" setting is recommendet to be not less than 20 seconds, 30 seconds is better.

## Manual Installation
1. Back up your site completely before proceeding.
2. Download the last release of the plugin ("nuvei-plugin-woocommerce.zip") or form main branch.
3. Select one of the following methods:
  - If you downloaded the plugin from some of the branches:
    1. Extract the plugin and rename the folder to "nuvei-checkout-woocommerce".
	2. Add it to a ZIP archive.
  - If you downloaded the plugin from the Releases page continue.
4. Install it from WordPress > Plugins > Add New.
5. At the end be sure there is only one Nuvei plugin installed!
6. Configure the plugin from WooCommerce > Settings > Payments > Nuvei Checkout.

## Support
Please contact our Technical Support (tech-support@nuvei.com) for any questions and difficulties.
