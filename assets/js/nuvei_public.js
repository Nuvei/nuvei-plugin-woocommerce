

const nuveiCheckoutClassicFormClass     = 'form.checkout.woocommerce-checkout';
const nuveiCheckoutClassicPayBtn        = '#place_order';
const nuveiCheckoutClassicPMethodName   = 'input[name="payment_method"]';
const nuveiCheckoutContainer            = '<div id="nuvei_checkout_container">Loading...</div>';
const nuveiCheckoutGoBackBtn            = `<p id="nuvei_go_back"><a href="#" onclick="nuveiCheckoutGoBack()">${scTrans.goBack}</a></p>`;
const nuveiCheckoutErrorCont            = '<div id="nuvei_checkout_errors"></div>';

var nuveiCheckoutSdkParams                  = {};
var nuveiIsCheckoutLoaded                   = false;
var nuveiIsPayForExistingOrderPage          = false;

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
 */
function nuveiIsCheckoutClassicFormValid() {
    console.log('nuveiIsCheckoutClassicFormValid()');

    if (!document.querySelector(nuveiCheckoutClassicFormClass)
        || !document.querySelector(nuveiCheckoutClassicFormClass).checkValidity()
        || jQuery(nuveiCheckoutClassicPMethodName + ':checked').val() != 'nuvei'
    ) {
        console.log('The checkout form is not valid or Nuvei is not selected as payment provider.');

        nuveiDestroySimplyConnect();
        return false;
    }

    let isFormValid             = true;
    let shipToDifferentAddress  = jQuery('#ship-to-different-address-checkbox').is(':checked');

    jQuery(nuveiCheckoutClassicFormClass).find('input, select, textarea').each( function() {
        let self = jQuery(this);

        if (!self.attr('id')) {
            return true;
        }

        // skip shipping fields check
        if (!shipToDifferentAddress && self.attr('id').indexOf('shipping') !== -1) {
            return true;
        }

        if ( ( self.attr('aria-invalid') && 'true' ==  self.attr('aria-invalid') )
            || ( 'true' ==  self.attr('aria-required') && '' == self.val() )
            || self.parent().hasClass('woocommerce-invalid')
            || jQuery(nuveiCheckoutClassicFormClass).find('div.is-error').length > 0
        ) {
            console.log('the element is not valid', self.attr('id'), typeof simplyConnect);

            isFormValid = false;

            nuveiDestroySimplyConnect();
            return false;
        }
    });

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

        jQuery('#nuvei_checkout_container').html('<img class="nuvei_loader" src="' + scTrans.loaderUrl + '" />');

        // in case of Classic Checkout or when the client will pay for an Order
        // created from the admin
        if ( jQuery(nuveiCheckoutClassicFormClass).length > 0 || nuveiIsPayForExistingOrderPage) {
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

    if ( ( typeof nuveiCheckoutBlockFormClass != 'undefined'
            && jQuery(nuveiCheckoutBlockFormClass).length > 0 )
        || jQuery(nuveiCheckoutClassicFormClass).length > 0
    ) {
        nuveiCheckoutSdkParams.prePayment = nuveiPrePayment;
    }

    nuveiCheckoutSdkParams.onResult = nuveiAfterSdkResponse;
    nuveiCheckoutSdkParams.onReady  = nuveiOnSimplyReady;

	simplyConnect(nuveiCheckoutSdkParams);

    jQuery('#nuvei_session_token').val(nuveiCheckoutSdkParams.sessionToken);
}

function nuveiOnSimplyReady() {
    jQuery('#nuvei_blocker').hide();
}

function nuveiShowErrorMsg(text) {
	if (typeof text == 'undefined' || '' == text) {
		text = scTrans.unexpectedError;
	}

	// short-code checkout
    if (jQuery('#nuvei_checkout_errors').length == 1) {
        jQuery('#nuvei_checkout_errors').html(
           '<div class="woocommerce-error" role="alert">'
               +'<strong>'+ text +'</strong>'
           +'</div>'
        );
    }

    // blocks checkout
    if (jQuery('.wc-block-components-notices').length > 0) {
        jQuery('.wc-block-components-notices').first()
            .append(
                '<div class="woocommerce-error" role="alert">'
                    +'<strong>'+ text +'</strong>'
                +'</div>'
            );
    }

    if (jQuery('#nuvei_checkout_errors').length) {
        jQuery('html, body').animate({
            scrollTop: jQuery('#nuvei_checkout_errors').offset().top - 50
        }, 500);
    }
    else {
        jQuery(window).scrollTop(0);
    }
}

function nuveiPrePayment(paymentDetails) {
	console.log('nuveiPrePayment');

    var postData    = {
        action: 'sc-ajax-action',
        nuveiSecurity: scTrans.nuveiSecurity,
        prePayment: 1
    };

	return new Promise((resolve, reject) => {
        // check for terms and conditions
        if (jQuery('#terms').length > 0 && !jQuery('#terms').is(':checked')) {
            nuveiShowErrorMsg(scTrans.TermsError);
            reject();
        }

		jQuery.ajax({
            type: "POST",
            url: scTrans.ajaxurl,
            data: postData,
            dataType: 'json'
        })
            .fail(function(){
                reject();
            })
            .done(function(resp) {
                console.log(resp);

                if (!resp.hasOwnProperty('success') || 0 == resp.success) {
                    reject();
                    window.location.reload();
                    return;
                }

                resolve();
                return;
            });
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
    if(jQuery('form#order_review #nuvei_checkout_container').length == 0) {
        jQuery('form#order_review').before(nuveiCheckoutErrorCont);
        jQuery('form#order_review .payment_box.payment_method_nuvei p').hide();
        jQuery('form#order_review .payment_box.payment_method_nuvei').append(nuveiCheckoutContainer);
    }

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
    if (jQuery(nuveiCheckoutBlockFormClass).length > 0
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
 * @param {string} payBtnId The clas/id of the Pay Button.
 */
function nuveiGetCheckoutData(formId, payBtnId) {
    console.log('call nuveiGetCheckoutData');

    if ('sdk' !== scTrans.checkoutIntegration) {
        return;
    }

    var scFormData = {};

    // get only populated fields
    jQuery(formId).find('input, select, textarea').each(function(){
        var _self = jQuery(this);

        if (_self.attr('id') && '' !=  _self.val()) {
            scFormData[_self.attr('id')] = _self.val();
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
            return;
        })
        .done(function(resp) {
            console.log(resp);
            showNuveiCheckout(resp);
            return;
        });
}

function nuveiDestroySimplyConnect() {
    console.log('nuveiDestroySimplyConnect');

    if (typeof simplyConnect != 'undefined' && simplyConnect.hasOwnProperty('destroy')) {
        try {
            simplyConnect.destroy();
        }
        catch(e) {
            console.log('exception', e);
        }

        console.log('simplyConnect was destroyed');
//        console.log('nuveiDestroySimplyConnect', jQuery('script#of432cbea-f121-11ea-adc1-0242ac120002').length);

        // manually remove our custom script if it is not removed after the destroy of Simply Connect
//        if ( jQuery('script#of432cbea-f121-11ea-adc1-0242ac120002').length == 1 ) {
//            jQuery('script#of432cbea-f121-11ea-adc1-0242ac120002').remove();
//        }
    }
}

jQuery(function($) {
    console.log('document ready');

	if('no' === scTrans.isPluginActive) {
        console.log('nuvei plugin is not active.');
		return;
	}

    // When the client is on accout -> orders page and pay an order created from the merchant.
    if (jQuery('#nuveiPayForExistingOrder').length > 0
        && ! isNaN(jQuery('#nuveiPayForExistingOrder').val())
        && jQuery('#nuveiPayForExistingOrder').val() > 0
    ) {
        // hide Place order button if need to
        if (jQuery(nuveiCheckoutClassicPMethodName).val() == scTrans.paymentGatewayName) {
            jQuery(nuveiCheckoutClassicPayBtn).hide();
        }

        // set event on Place order button
        jQuery(nuveiCheckoutClassicPMethodName).on('change', function() {
            if(jQuery(nuveiCheckoutClassicPMethodName + ':checked').val() == scTrans.paymentGatewayName) {
                jQuery(nuveiCheckoutClassicPayBtn).hide();
            }
            else {
                jQuery(nuveiCheckoutClassicPayBtn).show();
            }
        });

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

});

window.addEventListener('load', function() {
    console.log('window loaded');

    if('no' === scTrans.isPluginActive) {
		return;
	}

    if ('sdk' !== scTrans.checkoutIntegration) {
        return;
    }

    // In case of Classic Checkout, shortcode
    if (jQuery(nuveiCheckoutClassicFormClass).length) {
        console.log('Classic checkout.');
        
        // add nuvei_checkout_errors
        if(jQuery('.woocommerce #nuvei_checkout_errors').length == 0) {
            jQuery(nuveiCheckoutClassicFormClass).before(nuveiCheckoutErrorCont);
        }

        // try to validate the form on text inputs on changes
        jQuery(nuveiCheckoutClassicFormClass).on(
            'input',
            'input[id^="billing_"], input[id^="shipping_"]',
            nuveiDebounce( function() {
                console.log('Checkout form field changed - ', jQuery(this).attr('id'));

                nuveiDestroySimplyConnect();

                setTimeout(() => {
                    console.log('call nuveiIsCheckoutClassicFormValid becasue of change in the form fields');

                    if (nuveiIsCheckoutClassicFormValid()) {
                        nuveiGetCheckoutData(nuveiCheckoutClassicFormClass, nuveiCheckoutClassicPayBtn);
                    }
                }, 1000);
            }, 1000 )
        );

        // when change the payment method
        jQuery(document.body).on('change', nuveiCheckoutClassicPMethodName, function() {
            let currMethod = jQuery(nuveiCheckoutClassicPMethodName + ':checked').val();

            console.log('pm change event', currMethod);

            if ('nuvei' == currMethod) {
                if (nuveiIsCheckoutClassicFormValid()) {
                    nuveiGetCheckoutData(nuveiCheckoutClassicFormClass, nuveiCheckoutClassicPayBtn);
                }
                
                console.log('hide the button');
                jQuery(nuveiCheckoutClassicPayBtn).hide();
            }
            else {
                nuveiDestroySimplyConnect();
                jQuery(nuveiCheckoutClassicPayBtn).show();
            }
        });

        let lastPaymentMethod;

        // Listen for updated_checkout event on Classic Checkout
        jQuery(document.body).on('updated_checkout', function(event, data) {
            console.log('updated_checkout event');

            let currPaymentMethod = jQuery(nuveiCheckoutClassicPMethodName + ':checked').val();
            
            if (currPaymentMethod == scTrans.paymentGatewayName) {
                jQuery(nuveiCheckoutClassicPayBtn).hide();
            }
            else {
                jQuery(nuveiCheckoutClassicPayBtn).show();
            }

            if (lastPaymentMethod !== undefined && currPaymentMethod !== lastPaymentMethod) {
                lastPaymentMethod = currPaymentMethod;
                return; // Skip block if payment method was changed
            }

            lastPaymentMethod = currPaymentMethod;

            // when page loaded hide the default payment button if Nuvei is select as payment provider
            if (currPaymentMethod == scTrans.paymentGatewayName) {
                if (nuveiIsCheckoutClassicFormValid()) {
                    nuveiGetCheckoutData(nuveiCheckoutClassicFormClass, nuveiCheckoutClassicPayBtn);
                }
            }
            else {
                nuveiDestroySimplyConnect();
            }
        });

        return;
    }

});