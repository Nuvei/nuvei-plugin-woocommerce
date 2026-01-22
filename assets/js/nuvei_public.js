const nuveiCheckoutBlockFormClass       = 'form.wc-block-components-form';
const nuveiCheckoutClassicFormClass     = 'form.checkout.woocommerce-checkout';
const nuveiCheckoutClassicPayBtn        = '#place_order';
const nuveiCheckoutCustomPayBtn         = '#nuvei_place_order';
const nuveiCheckoutClassicPMethodName   = 'input[name="payment_method"]';
//const nuveiCheckoutContainer            = `<p id="nuvei_checkout_container">${scTrans.Loading}</p>`;
const nuveiCheckoutGoBackBtn            = `<p id="nuvei_go_back"><a href="#" onclick="nuveiCheckoutGoBack()">${scTrans.goBack}</a></p>`;
const nuveiWallets                      = ['ppp_ApplePay', 'ppp_GooglePay', 'ppp_Paze'];

var nuveiCheckoutSdkParams          = {};
var nuveiIsCheckoutLoaded           = false;
var nuveiIsPayForExistingOrderPage  = false;
var nuveiTriggeredUpdateEvent       = false;
var nuveiSimplyPaymentMethod        = '';

// Debounce function to limit how often a function can fire
function nuveiDebounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

/**
 * Check if the Checkout form is valid.
 * 
 * @params {Boolean} justLoadSimply When is set to true we will check only for country and email.
 */
function nuveiIsCheckoutClassicFormValid(justLoadSimply = false) {
    console.log('nuveiIsCheckoutClassicFormValid()');
    
    if (!nuveiIsPayForExistingOrderPage && !document.querySelector(nuveiCheckoutClassicFormClass)) {
        console.log('The classic checkout form is missing', nuveiCheckoutClassicFormClass);
        return false;
    }
    
    let isFormValid = true;
    let regex       = /^[^\s@]+@[^\s@]+\.[^\s@]+$/; // check the email
    
    // Minimal check, when need only the country and the email.
    if ( justLoadSimply ) {
        jQuery('#billing_country, #billing_email').trigger('validate');
        
        // Check if any fields are now marked as invalid
        if (jQuery('#billing_country').val() == ''
            || jQuery('#billing_email').val() == ''
            || !regex.test(jQuery('#billing_email').val())
        ) {
            console.log('Form is invalid');
            
            nuveiDestroySimplyConnect();
            
            // Scroll to the first error
            setTimeout( () => {
                jQuery('html, body').animate({
                    scrollTop: (jQuery('.woocommerce-invalid').first().offset().top - 50)
                }, 500);
            }, 100 );
            
            isFormValid = false;
        }
        
        return isFormValid;
    }
    
    // try to validate all fields
    jQuery('[id^="billing_"], #terms').trigger('validate');
    
    if (jQuery('.woocommerce-invalid').length > 0) {
        // Scroll to the first error
        setTimeout( () => {
            jQuery('html, body').animate({
                scrollTop: (jQuery('.woocommerce-invalid').first().offset().top - 50)
            }, 500);
        }, 100 );

        return false;
    }
    
    // check the Terms
    if (jQuery('#terms').length > 0 && !jQuery('#terms').is(':checked')) {
        console.log(scTrans.TermsError);
        nuveiShowErrorMsg(scTrans.TermsError);
        return false;
    }
    
    // here is additional check for the billing fields
    jQuery(nuveiCheckoutClassicFormClass).find('input, select, textarea').each( function() {
        let self = jQuery(this);

        // skip this element
        if (!self.attr('name')) {
            return true;
        }

        // skip fields not related with the billing address
        if (self.attr('name').indexOf('billing') < 0) {
            return true;
        }

        // because some themes duplicate the form inputs we will try to find the required fields with id = name
        let theId = `#${self.attr('name')}`;

        // check the field
        if ( ( jQuery(theId).attr('aria-invalid') && 'true' ==  jQuery(theId).attr('aria-invalid') )
            || ( 'true' ==  jQuery(theId).attr('aria-required') && '' == jQuery(theId).val() )
            || jQuery(theId).parent().hasClass('woocommerce-invalid')
        ) {
            console.log({
                'the invalid element': self.attr('name'),
                'check 1': ( jQuery(theId).attr('aria-invalid') && 'true' ==  jQuery(theId).attr('aria-invalid') ),
                'check 2': ( 'true' ==  jQuery(theId).attr('aria-required') && '' == jQuery(theId).val() ),
                'check 3': jQuery(theId).parent().hasClass('woocommerce-invalid')
            });

//            nuveiDestroySimplyConnect();
            return false;
        }
    });
    
//    if (!isFormValid) {
//        jQuery(nuveiCheckoutClassicPayBtn).trigger('click');
//    }
    
    return isFormValid;
}

/**
 * We need to handle blocks and short-code cases.
 *
 * @param object resp
 * @returns void
 */
function nuveiAfterSdkResponse(resp) {
	console.log('nuveiAfterSdkResponse', resp);

    // expired session
    if (resp.hasOwnProperty('session_expired') && resp.session_expired) {
        window.location.reload();
        return;
    }

    // a specific Error
    if(resp.hasOwnProperty('status')
        && resp.status == 'ERROR'
        && resp.hasOwnProperty('reason')
        && resp.reason.toLowerCase().search('the currency is not supported') >= 0
    ) {
        nuveiShowErrorMsg(resp.reason);
        return;
    }

	if (typeof resp.result == 'undefined') {
		console.error('Error with Checkout SDK response', resp);
		nuveiShowErrorMsg(scTrans.unexpectedError);
		return;
	}

	if ( (resp.result == 'APPROVED' || resp.result == 'PENDING')
		&& typeof resp.transactionId != 'undefined'
		&& resp.transactionId != 'undefined'
	) {
        nuveiSetTransactionField(resp.transactionId);

        jQuery('#nuvei_blocker').show();
        jQuery('#nuvei_checkout_container').html('');

        // in case of Classic Checkout or when the client will pay for an Order
        // created from the admin
        if ( jQuery(nuveiCheckoutClassicFormClass).length > 0 || nuveiIsPayForExistingOrderPage) {
            console.log('before click on the payment button');
            jQuery(nuveiCheckoutClassicPayBtn).trigger('click');
            return;
        }

        // in case of Blocks Checkout
        if (jQuery(nuveiCheckoutBlockFormClass).length > 0) {
            jQuery(nuveiCheckoutBlockPayBtn).trigger('click');
            return;
        }
	}

	if (resp.result == 'DECLINED') {
        if (resp.hasOwnProperty('errorDescription')
            && 'insufficient funds' == resp.errorDescription.toLowerCase()
        ) {
            nuveiShowErrorMsg(scTrans.insuffFunds);
            return;
        }

		nuveiShowErrorMsg(scTrans.paymentDeclined);
		return;
	}

	nuveiShowErrorMsg(scTrans.unexpectedError);
	return;
}

/**
 * @param object _params
 * @returns void
 */
function showNuveiCheckout(_params) {
    console.log('call showNuveiCheckout');

	if(typeof _params != 'undefined') {
		nuveiCheckoutSdkParams = _params;
	}

    // on error
    if (!nuveiCheckoutSdkParams
        || !nuveiCheckoutSdkParams.hasOwnProperty('sessionToken')
        || ( nuveiCheckoutSdkParams.hasOwnProperty('status')
            && 'error' == nuveiCheckoutSdkParams.status)
    ) {
        var error = '';

        if (nuveiCheckoutSdkParams
            && nuveiCheckoutSdkParams.hasOwnProperty('messages')
        ) {
            error = nuveiCheckoutSdkParams.messages;
        }

        nuveiShowErrorMsg(error);
        jQuery('#nuvei_blocker').hide();
        return;
    }

    // in this case we have product with Nuvei payment plan.
    if('savePM' === nuveiCheckoutSdkParams.savePM) {
        nuveiCheckoutSdkParams.pmBlacklist  = null;
        nuveiCheckoutSdkParams.pmWhitelist  = ['cc_card'];
    }

    if ( jQuery(nuveiCheckoutBlockFormClass).length > 0
        || jQuery(nuveiCheckoutClassicFormClass).length > 0
    ) {
        nuveiCheckoutSdkParams.prePayment = nuveiPrePayment;
    }

    nuveiCheckoutSdkParams.onResult                 = nuveiAfterSdkResponse;
    nuveiCheckoutSdkParams.onReady                  = nuveiOnSimplyReady;
    nuveiCheckoutSdkParams.onSelectPaymentMethod    = nuveiPmChange;

	simplyConnect(nuveiCheckoutSdkParams);

    jQuery('#nuvei_session_token').val(nuveiCheckoutSdkParams.sessionToken);
}

function nuveiOnSimplyReady() {
    jQuery('#nuvei_blocker').hide();
}

function nuveiPmChange(params) {
    console.log(params.paymentMethodName);
    
    nuveiSimplyPaymentMethod = params.paymentMethodName;
    
    if (params.paymentMethodName && nuveiWallets.indexOf(params.paymentMethodName) >= 0) {
        jQuery(nuveiCheckoutCustomPayBtn).hide();
    }
    else {
//        jQuery(nuveiCheckoutCustomPayBtn).show();
        nuveiShowCustomPayBtn();
    }
}

/**
 * Just show the nuveiSimplyPaymentMethod in some conditions.
 */
function nuveiShowCustomPayBtn() {
    if (nuveiWallets.indexOf(nuveiSimplyPaymentMethod) >= 0) {
        return;
    }
    
    console.log('show nuveiCheckoutCustomPayBtn button');
    jQuery(nuveiCheckoutCustomPayBtn).show();
}

function nuveiShowErrorMsg(text) {
	if (typeof text == 'undefined' || '' == text) {
		text = scTrans.unexpectedError;
	}

	// short-code checkout
    if (jQuery(nuveiCheckoutClassicFormClass).length || nuveiIsPayForExistingOrderPage) {
        jQuery('.woocommerce-notices-wrapper').first().html(
            '<div class="woocommerce-error nuvei_error" role="alert">'
               +'<strong>'+ text +'</strong>'
           +'</div>'
        );

        jQuery('html, body').animate({
            scrollTop: jQuery('.woocommerce-notices-wrapper').offset().top - 50
        }, 500);

        return;
    }

    // blocks checkout
    if (jQuery('.wc-block-components-notices').length > 0) {
        wp.data.dispatch( 'core/notices' ).createErrorNotice( 
            text,
            {
                id: 'nuvei-form-invalid', // Use a unique ID to prevent duplicates
                context: 'wc/checkout',  // Important: This tells Woo to show it in the checkout area
                isDismissible: true,
            } 
        );
    }
}

function nuveiPrePayment(paymentDetails) {
	console.log('nuveiPrePayment');

	return new Promise((resolve, reject) => {
        // For the Classic Checkout
        if ( jQuery(nuveiCheckoutClassicFormClass).length > 0) {
            // Validate the form
            let data    = jQuery('form.checkout').serialize();
            data        += '&nuveiFormValidation=1';

            jQuery.ajax({
                type: "POST",
                url: wc_checkout_params.wc_ajax_url.toString().replace("%%endpoint%%", "checkout" ),
                data: data,
            })
                .fail(function(){
                    console.log('Checking form failed.');
                    jQuery('#nuvei_blocker').hide();
                    reject();
                })
                .done(function(resp) {
                    console.log(resp);

                    if (resp.isFormValid === true) {
                        console.log("Checkout is valid.");

                        // update the Order
                        nuveiUpdateOrder(resolve, reject);
                        return;
                    }
                    else {
                        console.log("Checkout has errors");

                        if (resp.messages) {
                            jQuery('.woocommerce-notices-wrapper').first().html(resp.messages);

                            jQuery('html, body').animate({
                                scrollTop: jQuery('.woocommerce-notices-wrapper').offset().top - 50
                            }, 500);
                        }

                        reject();
                        return;
                    }
                });

            return;
        }

        // Update the Order
        nuveiUpdateOrder(resolve, reject);
        return;
	});
}

/**
 * We update Nuvei Order here.
 *
 * @returns {bool}
 */
function nuveiUpdateOrder(resolve, reject) {
    jQuery.ajax({
        type: "POST",
        url: scTrans.ajaxurl,
        data: {
            action: 'sc-ajax-action',
            nuveiSecurity: scTrans.nuveiSecurity,
            prePayment: 1
        },
        dataType: 'json'
    })
        .fail(function(){
            reject();
            jQuery('#nuvei_blocker').hide();
            return;
        })
        .done(function(resp) {
            console.log(resp);

            if (!resp.hasOwnProperty('success') || 0 == resp.success) {
                reject();
                window.location.reload();
                return;
            }

            console.log('prepayment resolved.')

            resolve();
            return;
        });
}

/**
 * A method for the case when the merchant create an Order in the admin, then
 * the client pay it from its Store profile.
 */
function nuveiPayForExistingOrder() {
    console.log('nuveiPayForExistingOrder');
    
    nuveiIsPayForExistingOrderPage = true;

    // add nuvei_checkout_container
//    if (jQuery('form#order_review #nuvei_checkout_container').length == 0) {
//        jQuery('form#order_review .payment_box.payment_method_nuvei p').hide();
//        jQuery('form#order_review .payment_box.payment_method_nuvei').append(nuveiCheckoutContainer);
//    }
    
    // clone the Place Order button
    nuveiInsertCustomPayButton(nuveiCheckoutClassicPayBtn);
    
    // hide Place order button if need to
    if (jQuery(nuveiCheckoutClassicPMethodName).val() == scTrans.paymentGatewayName) {
        jQuery(nuveiCheckoutClassicPayBtn).hide();
        nuveiShowCustomPayBtn();
    }

    // set event on Place order button
    jQuery(nuveiCheckoutClassicPMethodName).on('change', function() {
        if(jQuery(nuveiCheckoutClassicPMethodName + ':checked').val() == scTrans.paymentGatewayName) {
            jQuery(nuveiCheckoutClassicPayBtn).hide();
            nuveiShowCustomPayBtn();
        }
        else {
            jQuery(nuveiCheckoutCustomPayBtn).hide();
            jQuery(nuveiCheckoutClassicPayBtn).show();
        }
        
        // hide Place Order button if some of the Nuvei Wallets is choosen
        if (nuveiWallets.indexOf(nuveiSimplyPaymentMethod) >= 0) {
            jQuery(nuveiCheckoutCustomPayBtn).hide();
        }
        else {
            console.log('show button');
            nuveiShowCustomPayBtn();
        }
    });
    
    jQuery.ajax({
        type: "POST",
        url: scTrans.ajaxurl,
        data: {
            action: 'sc-ajax-action',
            nuveiSecurity: scTrans.nuveiSecurity,
            payForExistingOrder: 1,
            orderId: jQuery('#nuveiPayForExistingOrder').val()
        },
        dataType: 'json'
    })
        .fail(function() {
            console.log('Nuvei request failed.');
            nuveiShowErrorMsg();
            jQuery('#nuvei_blocker').hide();
            return;
        })
        .done(function(resp) {
            console.log(resp);

            if (!nuveiIsCheckoutLoaded) {
                nuveiIsCheckoutLoaded = true;
                showNuveiCheckout(resp);
            }

            return;
        });
}

/**
 * Add the transaction id field after we get the transaction result.
 *
 * @param string trId
 * @param string sessTok
 */
function nuveiSetTransactionField(trId, sessTok) {
    let nuveiTrIdInput = `<input id="nuvei_transaction_id" type="hidden" name="nuvei_transaction_id" value="${trId}" />`;

    // in case of Classic Checkout
    if ( jQuery(nuveiCheckoutClassicFormClass).length > 0
        && jQuery('.woocommerce #nuvei_transaction_id').length == 0
    ) {
        jQuery(nuveiCheckoutClassicFormClass).append(nuveiTrIdInput);

        return;
    }

    // in case of Blocks Checkout
    if ( jQuery(nuveiCheckoutBlockFormClass).length > 0
        && jQuery(nuveiCheckoutBlockFormClass + ' #nuvei_transaction_id').length == 0
    ) {
        jQuery(nuveiCheckoutBlockFormClass).append(nuveiTrIdInput);

        return;
    }

    // in case when the client will pay for an Orded created from the admin
    if (nuveiIsPayForExistingOrderPage
        && jQuery('form#order_review #nuvei_transaction_id').length == 0
    ) {
        jQuery('form#order_review').append(nuveiTrIdInput);

        return;
    }
}

/**
 * Get the required parameters for the SDK.
 *
 * @param {string} formId   The class/id of the checkout form.
 * @param {string} attrName The used attribute - id or name. It is 'name' by default.
 */
function nuveiGetCheckoutData(formId, attrName = 'name') {
    console.log('call nuveiGetCheckoutData', formId, attrName);

    if ('sdk' !== scTrans.checkoutIntegration) {
        return;
    }

    var scFormData = {};

    // get only populated fields
    jQuery(formId).find('input, select, textarea').each(function(){
        let _self = jQuery(this);
        
        try {
            let fieldById    = jQuery('body').find(`#${_self.attr(attrName)}`);

            if (_self.attr(attrName) && fieldById.length > 0) {
                scFormData[_self.attr(attrName)] = fieldById.val();
            }
        }
        catch (e) {
            return true;
        }
    });

    jQuery.ajax({
        type: "POST",
        url: scTrans.ajaxurl,
        data: {
            action: 'sc-ajax-action',
            nuveiSecurity: scTrans.nuveiSecurity,
            getBlocksCheckoutData: 1,
            scFormData: scFormData
        },
        dataType: 'json'
    })
        .fail(function() {
            console.log('Nuvei request failed.');
            nuveiShowErrorMsg();
            jQuery('#nuvei_blocker').hide();
            return;
        })
        .done(function(resp) {
            console.log(resp);
            showNuveiCheckout(resp);
            return;
        });
}

function nuveiDestroySimplyConnect() {
//    console.log('try nuveiDestroySimplyConnect');

    if (typeof simplyConnect != 'undefined' && simplyConnect.hasOwnProperty('destroy')) {
        try {
            simplyConnect.destroy();
        }
        catch(e) {
            console.log('exception', e);
        }

//        console.log('simplyConnect was destroyed');
    }
}

/**
 * For the classic checkout only. 
 * Insert our custom pay button.
 * 
 * @param string originalButton The original Place Order button id
 */
function nuveiInsertCustomPayButton(originalButton) {
    console.log('try to duplicate the button');
    
    // make a clone of the original Clasic Checkout Pay button.
    if (jQuery(nuveiCheckoutCustomPayBtn).length) {
        return;
    }
    
    let clonePayBtn = jQuery(originalButton).clone();
    clonePayBtn.attr('id', 'nuvei_place_order');
    clonePayBtn.attr('type', 'button');

    jQuery(originalButton).after(clonePayBtn);
}

jQuery(function($) {
    console.log('document ready');

	if('no' === scTrans.isPluginActive) {
        console.log('nuvei plugin is not active.');
		return;
	}
    
    // on click on our custom Place Order button
    jQuery(document.body).on('click', '#nuvei_place_order', function (e) {
        console.log('try nuveiSubmitPayment');
    
        try {
            // classic checkout and admin order payment page
            if (jQuery(nuveiCheckoutClassicFormClass).length || nuveiIsPayForExistingOrderPage) {
                if (nuveiIsCheckoutClassicFormValid()) {
                    simplyConnect.submitPayment();
                }
                
                return;
            }
            
            // blocks checkout
            if (jQuery(nuveiCheckoutBlockFormClass).length) {
                if (jQuery('.wc-block-components-notices').length > 0 && nuveiIsCheckoutBlocksFormValid()) {
                    simplyConnect.submitPayment();
                }
                
                return;
            }
        }
        catch(exception) {}
    });
    
    // When the client is on accout -> orders page and pay an order created from the merchant.
    if (jQuery('#nuveiPayForExistingOrder').length > 0
        && ! isNaN(jQuery('#nuveiPayForExistingOrder').val())
        && jQuery('#nuveiPayForExistingOrder').val() > 0
    ) {
        nuveiPayForExistingOrder();
    }

    // thankyou page modifications
    if (typeof scTrans.thankYouPageNewTitle != 'undefined') {
        jQuery(".entry-title, h1").html(scTrans.thankYouPageNewTitle);
    }
    // if there is pay button on thank you page - hide it!
    if (scTrans.thankYouPageRemovePayBtn && jQuery("a.pay").length > 0) {
        jQuery("a.pay").hide();
    }

    // only for SDK flow
    if ('sdk' == scTrans.checkoutIntegration) {
        
        // In case of Classic Checkout, shortcode
        if (jQuery(nuveiCheckoutClassicFormClass).length) {
            console.log('Classic checkout.');
            
            // Do not check the totals and billing address if Nuvei is not selected
            let currPaymentMethod = jQuery(nuveiCheckoutClassicPMethodName + ':checked').val();
   
            if (scTrans && scTrans.paymentGatewayName === currPaymentMethod) {
                // try to validate the form when input changes
                jQuery(document.body).on(
                    'input',
                    '#billing_country, #billing_email',
                    nuveiDebounce( function() {
                        console.log('Checkout form field changed - ', jQuery(this).attr('id'));

                        nuveiDestroySimplyConnect();

                        setTimeout(() => {
                            console.log('call nuveiIsCheckoutClassicFormValid becasue of change in the form fields');

                            if (nuveiIsCheckoutClassicFormValid(true)) {
                                nuveiGetCheckoutData(nuveiCheckoutClassicFormClass);
                            }
                        }, 1000);
                    }, 1000 )
                );
            }

            // when change the payment method
            jQuery(document.body).on('change', nuveiCheckoutClassicPMethodName, function() {
                let currMethod = jQuery(nuveiCheckoutClassicPMethodName + ':checked').val();

                console.log('pm change event', currMethod);

                if ('nuvei' == currMethod) {
                    if (nuveiIsCheckoutClassicFormValid(true)) {
                        nuveiGetCheckoutData(nuveiCheckoutClassicFormClass);
                    }

                    jQuery(nuveiCheckoutClassicPayBtn).hide();
                    nuveiShowCustomPayBtn();
                }
                else {
                    nuveiDestroySimplyConnect();
                    jQuery(nuveiCheckoutCustomPayBtn).hide();
                    jQuery(nuveiCheckoutClassicPayBtn).show();
                }
            });

            let lastPaymentMethod;

            // Listen for updated_checkout event on Classic Checkout
            jQuery(document.body).on('updated_checkout', function(event, data) {
                console.log('updated_checkout event');
                
                nuveiInsertCustomPayButton(nuveiCheckoutClassicPayBtn);

                nuveiTriggeredUpdateEvent = true;

                currPaymentMethod = jQuery(nuveiCheckoutClassicPMethodName + ':checked').val();

                if (currPaymentMethod == scTrans.paymentGatewayName) {
                    jQuery(nuveiCheckoutClassicPayBtn).hide();
                    nuveiShowCustomPayBtn();
                }
                else {
                    jQuery(nuveiCheckoutCustomPayBtn).hide();
                    jQuery(nuveiCheckoutClassicPayBtn).show();
                }

                if (lastPaymentMethod !== undefined && currPaymentMethod !== lastPaymentMethod) {
                    lastPaymentMethod = currPaymentMethod;
                    return; // Skip block if payment method was changed
                }

                lastPaymentMethod = currPaymentMethod;

                // when page loaded hide the default payment button if Nuvei is select as payment provider
                if (currPaymentMethod == scTrans.paymentGatewayName) {
                    if (nuveiIsCheckoutClassicFormValid(true)) {
                        nuveiGetCheckoutData(nuveiCheckoutClassicFormClass);
                    }
                }
                else {
                    nuveiDestroySimplyConnect();
                }
            });

            setTimeout(() => {
                console.log('nuveiTriggeredUpdateEvent', nuveiTriggeredUpdateEvent);

                if (!nuveiTriggeredUpdateEvent 
                    && scTrans.paymentGatewayName === currPaymentMethod 
                    && nuveiIsCheckoutClassicFormValid(true)
                ) {
                    nuveiTriggeredUpdateEvent = true;

                    nuveiGetCheckoutData(nuveiCheckoutClassicFormClass);
                }
            }, 1000);
        }
    }

});
