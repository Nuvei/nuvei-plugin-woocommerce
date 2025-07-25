=== Nuvei Payments for Woocommerce ===

Requires at least: 4.7
Tested up to: 6.8.1
Stable tag: 3.7.0
Requires PHP: 7.3
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The plugin adds Nuvei Gateway for WooCommerce.

== Description ==

Nuvei supports major international credit and debit cards enabling you to accept payments from your global customers. 

A wide selection of region-specific payment methods can help your business grow in new markets. Other popular payment methods from mobile payments to e-wallets can be easily implemented on your checkout page.

The correct payment methods at the checkout page can bring you global reach, help you increase conversions, and create a seamless experience for your customers.

Our plugin enables customers to accept payments by credit and debit cards, as well as other methods. For this purpose, our plugin uses Nuvei’s own Simply Connect SDK. Since we are a top-tier payment provider, we hold the highest data security standard (Level 1) and we take payment data security seriously. Our Simply Connect platform is hosted on our own Nuvei servers, which are audited every year for PCI compliance.

For more information about Simply Connect, please press [here](https://docs.nuvei.com/documentation/accept-payment/simply-connect/).

To work properly, our plugin relays additional services to send order and client data, and receives information about the transactions. All of these services (including the current plugin) belong to Nuvei, formerly known as SafeCharge. The services we use are listed below:

* https://ppp-test.nuvei.com/ppp/api/v1/... - Nuvei' Sandbox REST API path;
* https://secure.safecharge.com/ppp/api/v1/... - Nuvei's Production REST API path;
* https://cdn.safecharge.com/safecharge_resources/v1/checkout/simplyConnect.js - The Production URL to the Nuvei' Simply Connect;
* https://devmobile.sccdev-qa.com/checkoutNext/simplyConnect.js - The QA URL to the Nuvei' Simply Connect;
* https://cdn.safecharge.com/safecharge_resources/v1/websdk/autoclose.html - A help URL who close APM popup, when it is used;
* https://ppp-test.safecharge.com/ppp/purchase.do - Nuvei' Sandbox Redirect payment page;
* https://secure.safecharge.com/ppp/purchase.do - Nuvei's Production Redirect payment page;
* Links to Nuvei documentation:
  * [https://docs.nuvei.com/documentation/accept-payment/simply-connect/payment-customization/#dynamic-currency-conversion](https://docs.nuvei.com/documentation/accept-payment/simply-connect/payment-customization/#dynamic-currency-conversion)
  * [https://docs.nuvei.com/documentation/accept-payment/checkout-2/payment-customization/#card-processing](https://docs.nuvei.com/documentation/accept-payment/checkout-2/payment-customization/#card-processing)
  * [https://docs.nuvei.com/documentation/accept-payment/simply-connect/ui-customization/#text-and-translation](https://docs.nuvei.com/documentation/accept-payment/simply-connect/ui-customization/#text-and-translation)

== System Requirements ==

* Enabled PHP cURL support.
* Public access to the plugin notify URL. Check plugin settings, Help Tools tab, for it.

== Nuvei Requirements ==

In the Merchant configuration:
  * DMNs enabled.
  * On the Site ID level, “DMN timeout” setting should be not less than 20 seconds; 30 seconds is better.

== Notes ==

If you have installed a plugin version earlier than v1.3.0 and you are upgrading for the first time, then you should deactivate and activate hte plugin again after the upgrade, so new logs directory be created!

If you are using a plugin version earlier than v3.2.0, please change the “Status Authorized” plugin setting in the “Advanced Settings” tab to “On-hold”!

If you plan to install this plugin form the WordPress store, but use a version downloaded from Nuvei’s GIT repo, please remove the old plugin first!

== Screenshots ==

1. screenshot-1.png.
2. screenshot-2.png.
3. screenshot-3.png.
4. screenshot-4.png.

== Changelog ==

= 3.7.0 =
* Implemented WC methods to visualize plugin status as "Action needed" when the plugin is not configured and "Test mode" when the plugin works on Sandbox.
* Added validation of the plugin required settings.

= 3.6.0 =
* Added "Go Back" button on the Simply Connect page.

= 3.5.4 =
* Fix for the inconsistent load of Simply Connect with the Blocks Checkout.

= 3.5.3 =
* Lowering the priority when call the hooks "woocommerce_after_checkout_validation" and "init" to handle the errors added from some third party plugins who modify the Checkout page.

= 3.5.2 =
* Replace "window.load" with "window.addEventListener('load'..." in nuvei_public.js. This will prevent some problems with other WP/WC plugins.

= 3.5.1 =
* Decalare support of WordPress 6.8 and WooCommerce 9.8.1.
* Fixed the type of few variables in the methods comments.
* With previouse upload (v3.5.0) to the WordPress store, some of the changes were not uploaded. We appoligies for that! With this version we will upload all of the missing changes.

= 3.5.0 =
* In the plugin_loaded callback we will load only the text domain. The idea is to prevent using of translated texts before the domain is loaded.
* All translated texts from the config file were moved into the main file.
* Added a possibility to modify Simply Connect style from the plugin settings.
* Added a missing private msg variable in notify url class.
* Added custom plugin hook - 'nuvei_pfwc_after_auto_void'. It will be available after approved auto void transaction. Two parameter will be passed in it - the transaction ID of the Sale/Auth and the client email, both parameters a based on the Sale/Auth DMN.

= 3.4.1 =
* Most of the plugin's hooks were moved into init callback method. In the plugin_loaded callback we will load only the text domain. The idea is to prevent using of translated texts before the domain is loaded.
* All translated texts in the config file were moved in the main file.

= 3.4.0 =
* Added option to turn on/off the auto-void logic - plugin settings > General Settings > Allow Auto Void.
* Added a custom system message when the plugin cannot find corresponding Order for some Sale/Auth DMN. At the beginning the message will be visible in the admin. If the user dismiss it, it will be visible only in plugin settings > Help Tools > Read Payment messages. The plugin will load the last 50 messages. From here you can permanently delete the messages.
* The old README.md file was reduced and pointing to the current file.
* For testing purpose, to the Simply Connect was added parameter "webSdkEnv".
* From the readme.txt was removed Contributor parameter.
* Tested with WC 9.4.3.
* Added translation template file.

= 3.3.1 =
* Removed option "Auto-close APM Pop-Up".
* Added webMasterId in Cashier redirect URL.

= 3.3.0 =
* Nuvei Order's action buttons and Nuvei Order's messages were added into WCFM Order details page.
* When Void DMN come, and this is an Auto-Void, directly return response to the Cashier.

= 3.2.4 =
* Fix for the broken DMN log record in the log file.

= 3.2.3 =
* Changed few logs texts.
* Updated the readme files.
* A JS function was marked as deprecated.
* When do Refeund, do not set the order in pending status, until it waits for DMN. Just add a message.
* In the plugin settings activate "Save changes" when click on "Clean" button.

= 3.2.2 =
* Use proper way to get Order private meta data in create_wc_subscr_order();
* Replaced local Simply Connect with a link to its SDN.
* Typecast all parameters passed to rtrim() and trim() methods.
* When the merchant trigger Refund, change Order status before Nuvei request. In any negative case revert the previous status of the Order.
* Few changes because of depracations in PHP 8.2.
* The Default status for "Status Pending DMN" was changed to "On hold".
* relatedTransactionId paramter was added to DMN logged data.
* When try to create the plugin logs directory and its content,s check if FS_CHMOD_DIR and FS_CHMOD_FILE are defined. If they are not, call WP_Filesystem() method, to define them or the code will crash.

= 3.2.1 =
* Removed old index.php file and deactivate the version with index.php.
* Fix for the admin, when the plugin try to get the non-existent file with the payment plans.

= 3.2.0 =
* Fix for Admin generated Orders, when the store use WC Blocks.
* Changed the name of main plugin file.
* Changed the way plugin gets its own data from the header.
* Other changes made by recommendation of WP..
* Changed the names of all constants.
* Changed the names of the classes and its files.
* Changed the names of the functions in the main plugin file.
* A direct call to the DB to get few WC settings was replaced with a WP method.
* Fixed the problem with the null $nuvei_order_details parameter in process_payment().
* Changed the default WC Status for Nuvei Auth transactions to "On-hold", by WC recommendation.
* Do not control Refund button form js script, but only form a PHP hook.

= 3.1.0 =
* Added custom hook 'nuvei_pfwc_after_rebilling_payment' at the end of DMN Rebilling payment logic.
* Fixed the problem when the merchant create an order from the admin, and the client try to pay for it from the Store.

= 3.0.3 =
* Fixed the problem when a Guest user come for the first time on Blocks Checkout and get "Unexpected error" message.
* When validate emails before Nuvei REST API request and there is an error, return the message to the client side.
* Changed a message text.
* On Blocks Checkout get the need data via Ajax before load Simply Connect.
* nuvei-checkout-blocks.js is declared as module via hook.
* Fix for a wrong JS escape.
* Pass sourceApplication to the Simply Connect.
* In hide_payment_gateways() method do not call is_checkout(), because WC Blocks returns false in some cases.
* Fixed the problem with WC Blocks and Zero Total orders.
* Fixed the problem when a Guest user come for the first time on Blocks Checkout and get "The parameter Billing Address Email is not valid." message.
* Removed constants NUVEI_SDK_URL_PROD, NUVEI_SDK_URL_TAG, NUVEI_SESSION_PLUGIN_GIT_V.
* Removed methods/functions nuvei_admin_init(), nuvei_get_file_form_git(), nuvei_rewrite_return_url(), nuvei_wpml_thank_you_page().
* Use Simply Connect v1.140.0.

= 3.0.2 =
* The plugin name and text domain were changed from "nuvei-checkout-for-woocommerce" to "nuvei-payments-for-woocommerce".

= 3.0.1 =
* In plugin description was added WooCommerce dependency.
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
* Do not process Pending DMNs, just save their parameters in the log file.

= 2.1.0 =
* Removed old commented parts of code.
* In the plugin options was added possibility to set custom statuses for the Order.
* Fixes for plugin settings links to Nuvei documentation.
* Fixed the example for Simply Connect translations.
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
* When DMN cannot find the Order, return 400 only for the parent transactions as Auth and Sale.
* Changed the order of few hooks.
* When place Nuvei buttons in the admin, do it only for WC_Order objects.
* Fixed the wrong Failed status of the Order when DCC was used.
* Fixed the check for new plugin version in Git.
* Fixed the problem with the too long deviceName parameter.
* Fix for the checkout page when the plugin get response for "Pending" transaction status.
* Replaced the changelog file.
* Do not create Refund record in WC after error in Nuvei Refund request.
* Save a Note in WC Orders when Pending DMN comes.
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
* Added Auto-Void functionality in case of payment, but did not create Order in WC.

= 1.4.2 =
* Added support for theme picking for Simply Connect.
* Added option to save logs into second log file - nuvei.log.
* Added id attribute to the "Block Payment methods" setting "Clean" button.
* Removed the "SDK Version" option into the plugin.
* Fixed the problem with Chrome and plugin "Block Payment methods" dropdown.
* Replaced safecharge domain into the endpoints with nuvei one, where is possible.
* Split the requests response log.

= 1.4.1 =
* Changed the value of sourceApplication and webMasterId values.
* Fixed the problem when receive DMN from the Control Panel.

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