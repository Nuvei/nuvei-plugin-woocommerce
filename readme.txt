=== Nuvei Checkout for Woocommerce ===

Contributors: miroslavnuvei
Requires at least: 4.7
Tested up to: 6.5.5
Stable tag: 3.0.2
Requires PHP: 7.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The plugin adds Nuvei Gateway for WooCommerce.

== Description ==

Nuvei offers major international credit and debit cards enabling you to accept payments from your global customers. 

A wide selection of region-specific payment methods can help your business grow in new markets. Other popular payment methods from mobile payments to eWallets, can be easily implemented at your checkout page.

Right payment methods at the checkout page can bring you global reach, help you increase conversions and create a seamless experience for your customers.

== Screenshots ==

1. screenshot-1.png.
2. screenshot-2.png.
3. screenshot-3.png.
4. screenshot-4.png.

== Changelog ==

= 3.0.3 =
* Fixed the problem when a Guest user come for the first time on Blocks Checkout and get "Unexpected error" message.
* When validate emails before Nuvei REST API request and there is an error, return the message to the client side.
* Changed a message text.
* On Blocks Checkout get the need data via Ajax before load Simply Connect.
* nuvei-checkout-blocks.js is declared as module via hook.

= 3.0.2 =
* The plugin name and text domain were changed from "nuvei-checkout-for-woocommerce" to "nuvei-payments-for-woocommerce".

= 3.0.1 =
* In plugin description was added WooCommerce dependancy.
* Marked NUVEI_SDK_URL_PROD and NUVEI_SDK_URL_TAG constants as deprecated.
* Use Simply Connect v1.138.0.
* Add Simply Connect in nuvei_public.js before the WC checkout script.

= 3.0.0-p1 =
* Fixed sourceApplication paramter.
* Deprecated method nuvei_wpml_thank_you_page.
* Remove checks for plugin setting use_wpml_thanks_page, because it was removed few versions ago.
* Removed few icons - ApplePay-Button.png, applepay.svg, safecharge.png, visa_mc_maestro.svg.

= 3.0.0 =
* Fix for Settle, Void or Refund on Orders made with plugin before v2.0.0.
* Auto-Void fix. Allow Auto-Void for approved transactions only.
* Fix for the missing notify URL in create_auto_void() method.
* Added ApplePay locale.
* Move different DMN logic in separate methods.
* Declare plugin compatibility with 'cart_checkout_blocks';
* Declare plugin compatibility with HPOS.
* Removed deprecated methods - save_refund_meta_data(), get_cuid(), create_refund_record().
* In the log user details are masked by default. This can be changed from the plugin options.
* Clean Items names, when Cashier is used.
* Fix for the broken Cashier implementation.
* Fixed typos in plugin settings.

= 2.1.0-p1 =
* For the QA site use Tag endpoints for the SDKs.
* Do not proccess Pending DMNs, just save their parameters in the log file.

= 2.1.0 =
* Removed old commented parts of code.
* In the plugin options was added possibility to set custom statuses for the Order.
* Fixes for plugin settings links to Nuvei documentation.
* Fixed the example for SimplyConnect translations.
* Added Gpay locale.

= 2.0.1 =
* Fix for the logic who check for Fraud Orders.

= 2.0.0 =
* Trim the merchant credentials when get them.
* Stop using save_update_order_numbers() method and all connected with it meta fields.
* Removed few GET parameters from the notification URL. Please provide the new URL to the Integration team!
* Added transactionId into Nuvei Transactions meta field.
* Added an option into the plugin settings to enable Nuvei GW for Zero-Total checkout.
* Added additional security for the log files.
* Hide "SimplyConnect theme" setting, when Cashier was set as payment option.
* When DMN can not find the Order, return 400 only for the parent transactions as Auth and Sale.
* Changed the order of few hooks.
* When place Nuvei buttons in the admin, do it only for WC_Order objects.
* Fixed the wrong Failed status of the Order when DCC was used.
* Fixed the check for new plugin version in Git.
* Fixed the problem with the too long deviceName parameter.
* Fix for the checkout page when the plugin get response for "Pending" transaction status.
* Replaced the changelog file.
* Do not create Refund record in WC after error in Nuvei Refund request.
* Save a Note in WC Orders when Pendin DMN come.
* Disable DCC when the Total is Zero.
* Added high priority when loading plugin scripts and libraries.

= 1.4.7 =
* Fix for the logic who decide will we create update or open order request.
* Fix for the loadings styles method.
* Do not use the authCode as Int.

= 1.4.6 =
* Added few more fields into _nuveiTransactions meta field.
* Added and removed few comments.
* Changed the place where call the new save_transaction_data method.

= 1.4.5 =
* Moved Auto-Void logic after the last try to find the Order in WC.
* Lowered the maximum tries count when search for existing WC Order.
* Added _nuveiTransactions private Order meta field to save main data for each transaction.

= 1.4.4 =
* Added new checkout icon for the plugin.
* Load front-end scripts and styles in separate methods.
* Always pass userTokenId into the openOrder request. The decision for saving UPO is into the SDK.
* Changed the logic when to call updateOrder.
* Fix for the unexpected refresh of the Checkout SDK when try to pay.

= 1.4.3 =
* Added option to save UPOs for Guest users, when the user try to buy a product with Subscription.
* Added Auto-Void functionality in case of payment, but did not created Order in WC.

= 1.4.2 =
* Added support for theme picking for SimplyConnect.
* Added option to save logs into second log file - nuvei.log.
* Added id attribute to the "Block Payment methods" setting "Clean" button.
* Removed the "SDK Version" option into the plugin.
* Fixed the problem with Chrome and plugin "Block Payment methods" dropdown.
* Replaced safecharge domain into the endpoints with nuvei one, where is possible.
* Split the requests response log.

= 1.4.1 =
* Changed the value of sourceApplication and webMasterId values.
* Fixed the problem when receive DMN from the CPanel.

= 1.4.0 =
* Add support for WC Subscriptions plugin.
* Added a script to remove the WCS Pay button on Thank-you page.
* On Thank-you page hooks check if the order belongs to Nuvei.
* Added PayPal as option for Rebillings with WCS only.
* Added _nuveiPrevTransactionStatus custom field to the Order. It will be used in case of declined Void to return Order to original status.
* Void button will be available only for orders with AuthCode.
* For WCS Renewal order try to generate unique clientUniqueId parameter.

= 1.3.1 =
* Added the possibility to create empty Payment Plan. The merchant must leave the Payment Plan field empty.
* When create Nuvei Payment plan "Select a Plan..." option was renamed to "Without Plan".
* When edit Nuvei Payment plans "Without Plan" option is available.
* Force transaction type to Auth when Order total amount is 0.

= 1.3.0 =
* Allow checkout with any combination of products.
* Enable using Nuvei Payment Plans for product with attribute only, without variations.
* Do not pass Product data by open/updateOrder request.
* Do not pass Subscription data by open/updateOrder request, but keep it in WC session. Just before redirect to the success page save it as Order meta data.
* Logs directory was moved to wp-content/uploads/nuvei-logs.
* Removed old log directory from the plugin.
* Removed the option to change the DMN URL.
* Removed the option to read the Today log file. Any file manager plugin can be use instead.

= 1.2.4 =
* Fix for the case when updateOrder does not upgrade empty userTokenId.
* Do not validate and process the Tokenization DMNs.
* When click on Pay button check if the products are still in stock.

= 1.2.3 =
* Fix the links to Nuvei documentation into the plugin settings.
* Do not process Pending DMNs.
* Check for set integration_type before try to use it.
* Changes into the readme file.
* Added NUVEI_LOGS_DIR constant. Save all plugin generated files in it.
* Removed tmp folder.
* The Notify URL provided to Nuvei Integration/TechSupport Team must be updated!

= 1.2.2 =
* Fixed the missing message for Insufficient funds.
* Tested on WP 6.1.1 and WC 7.3.0.

= 1.2.1 =
* After approved transaction, do not click on #place_order button, but submit form.checkout if exists.
* Tested on WP 6.1.1 and WC 7.1.0.

= 1.2.0 =
* Hide Void button 48 hours after the Order is made.
* Hide Void button for Zero Orders.
* Added "Cancel Subscription" button, when the Subscription was confirmed as Active.

= 1.1.2 =
* Tested on WP 6.1 and WC 7.0.1.

= 1.1.1 =
* Disable Nuvei Rebilling products for Guest users.

= 1.1.0 =
* Unify products with plan in a single subscription, and multiply subscription amount by their quantity.
* Tested on WC 6.9.2.

= 1.0.4 =
* Show better error message if the client get "Insufficient funds" error.
* Upgraded latest tested WC version.

= 1.0.3 =
* Tested on WP 6.0.1 and WC 6.7.0.
* Do not get all information in debug_backtrace().
* Added link to the Documentation for "Block Payment methods" setting.

= 1.0.2 =
* Added default encoding into Cashier URL.
* Removed set_cuid() method.
* For Cashier URL, clientUniqueId was replaced with merchant_unique_id.
* Removed old commented code parts.
* Removed last checks for 'https to http' option.
* If the logged data is string try to url decode it, if it is an URL.
* Do not print pretty merchant payment methods list.
* Added userData.billingAddress into Checkout SDK parameters.
* The option for using Checkout SDK or Cashier was moved at the top of the Advanced settings. When change it, the unselected group of settings will hide.

= 1.0.1 =
* Added billing "state" parameters in the requests.

= 1.0.0 =
* Based on latest version of Nuvei Woocommerce plugin, but use Checkout SDK instead Web SDK.