const nuveiCheckoutBlockFormClass       = 'form.wc-block-components-form';
const nuveiCheckoutClassicFormClass     = 'form.checkout.woocommerce-checkout';
const nuveiCheckoutClassicPayBtn        = '#place_order';
const nuveiCheckoutCustomPayBtn         = '#nuvei_place_order';
const nuveiCheckoutClassicPMethodName   = 'input[name="payment_method"]';
const nuveiMandatoryCheckoutFields      = '#billing_country, #billing_email';
const NUVEI_GET_CHECKOUT_DATA_DELAY     = 350; // ms — collapses bursts of calls into one fetch
const nuveiWallets                      = ['ppp_ApplePay', 'ppp_GooglePay', 'ppp_Paze', 'apmgw_Venmo', 'apmgw_VenmoPP'];

var nuveiCheckoutSdkParams          = {};
var nuveiIsCheckoutLoaded           = false;
var nuveiIsPayForExistingOrderPage  = false;
var nuveiSuccessRedirect            = '';
var nuveiIsFormValid                = true;
var nuveiBlocksResolvePayment       = null;
var nuveiWalletInProgress           = false;
// AbortController for the current openOrder fetch request
var nuveiGetCheckoutDataController  = null;
var nuveiCheckoutRequestId          = null; // request flag
var nuveiIsSimplyFormValid          = false;
// Debounce timer and in-flight flag for nuveiGetCheckoutData
var nuveiGetCheckoutDataTimer       = null;
var nuveiGetCheckoutDataInFlight    = false;
var nuveiSelectedPaymentMethod      = '';
// _nuveiOrderId will be set dynamically and will hold the saved WC Order ID 

/**
 * Check if the Checkout form is valid.
 *
 * @params {Boolean} justLoadSimply When is set to true we will check only for country and email.
 */
function nuveiIsCheckoutClassicFormValid(justLoadSimply = false) {
    console.log('nuveiIsCheckoutClassicFormValid(), justLoadSimply:', justLoadSimply);
    
    const shipToDifferent = jQuery('#ship-to-different-address-checkbox').is(':checked');
    
    // skip - check for Admin Order
    if (!nuveiIsPayForExistingOrderPage && !document.querySelector(nuveiCheckoutClassicFormClass)) {
        console.log('The classic checkout form is missing', nuveiCheckoutClassicFormClass);
        return false;
    }

    // error - only proceed if Nuvei is the selected payment method
    if (!nuveiIsPayForExistingOrderPage
        && jQuery(nuveiCheckoutClassicPMethodName + ':checked').val() !== scTrans.paymentGatewayName
    ) {
        console.log('Nuvei is not selected as payment provider');
        return false;
    }

    // Dispatch custom JS event - cancelable so listeners can call e.preventDefault() to halt the flow
    const nuveiFormValidEvent = new CustomEvent('nuveiPfw:isCheckoutClassicFormValidEvent', { cancelable: true });

    if (!document.dispatchEvent(nuveiFormValidEvent)) {
        console.log('dispatchEvent failed.');
        return false;
    }

    nuveiIsFormValid    = true;
    let regex           = /^[^\s@]+@[^\s@]+\.[^\s@]+$/; // check the email

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
                jQuery('#nuvei_checkout_container').html(scTrans.MissingEmailCountry);

                if (jQuery('.woocommerce-invalid').length) {
                    jQuery('html, body').animate({
                        scrollTop: (jQuery('.woocommerce-invalid').first().offset().top - 50)
                    }, 500);
                }
            }, 100 );

            nuveiIsFormValid = false;
        }

        return nuveiIsFormValid;
    }

    // check the Terms
    if (jQuery('#terms').length > 0 && !jQuery('#terms').is(':checked')) {
        nuveiShowErrorMsg(scTrans.TermsError);

        nuveiIsFormValid = false;
        return nuveiIsFormValid;
    }
    
    // check for Google Recaptcha
    if (jQuery('.g-recaptcha-response').length && '' == jQuery('.g-recaptcha-response').val()) {
        nuveiShowErrorMsg(scTrans.CaptchaError);
        
        nuveiIsFormValid = false;
        return nuveiIsFormValid;
    }
    
    // here is additional check for the address fields
    jQuery(nuveiCheckoutClassicFormClass).find('input, select, textarea').each( function() {
        let self = jQuery(this);
        
        // skip this element
        if (!self.attr('name')) {
            return true;
        }

        // skip fields not related with the billing and the shipping
        if (self.attr('name').indexOf('billing') < 0 && self.attr('name').indexOf('shipping') < 0) {
            return true;
        }

        // because some themes duplicate the form inputs we will try to find the required fields with id = name
        let theId = `#${self.attr('name')}`;
        
        // skip the shipping fields
        if ( ! shipToDifferent && self.attr('name').includes('shipping') ) {
            return true;
        }

        // check the field
        if ( 
            ( jQuery(theId).attr('aria-invalid') && 'true' == jQuery(theId).attr('aria-invalid') )
            || ( 
                ( 'true' == jQuery(theId).attr('aria-required') || jQuery(theId).closest('.form-row').hasClass('validate-required') )
                && '' == jQuery(theId).val() 
            )
            || jQuery(theId).parent().hasClass('woocommerce-invalid')
        ) {
            console.log({
                'the invalid element': self.attr('name'),
                'check 1': ( jQuery(theId).attr('aria-invalid') && 'true' ==  jQuery(theId).attr('aria-invalid') ),
                'check 2': ( 'true' ==  jQuery(theId).attr('aria-required') && '' == jQuery(theId).val() ),
                'check 3': jQuery(theId).parent().hasClass('woocommerce-invalid')
            });

            nuveiIsFormValid = false;
            return false;
        }
    });
    
    return nuveiIsFormValid;
}

/**
 * We update Nuvei Order here.
 *
 * @returns {bool}
 */
function nuveiUpdateOrder(resolve, reject) {
    fetch(scTrans.apiUrl + '/pre-payment/', {
        method: 'GET',
        headers: {
            'X-WP-Nonce': scTrans.nuveiApiSec,
            'Content-Type': 'application/json'
        }
    })
        // 1. first check for the status code (200 OK)
        .then(res => {
            if (!res.ok) {
                // error - 401, 403, 404 or 500
                throw res;
            }

            // success, continue
            return res.json();
        })
        // the success
        .then(data => {
            console.log(data);

            // success
            if (1 == data?.success) {
                console.log('prepayment resolved.');

                resolve();
                return;
            }

            // error
            reject();
            window.location.reload();
            return;
        })
        // error after the first check
        .catch(async err => {
            reject();
            nuveiShowErrorMsg(scTrans.unexpectedError);
            jQuery('#nuvei_blocker').hide();
            return;
        });
}

/**
 * We need to handle blocks and short-code cases.
 *
 * @param object resp
 * @returns void
 */
function nuveiAfterSdkResponse(resp) {
	console.log('nuveiAfterSdkResponse', resp);

    // error - expired session
    if (resp?.session_expired) {
        window.location.reload();
        return;
    }

    // a specific currency Error
    if ( resp?.status == 'ERROR'
        && resp?.reason?.toLowerCase().search('the currency is not supported') >= 0
    ) {
        nuveiShowErrorMsg(resp.reason);
        return;
    }
    
    if (resp?.status?.toLowerCase() == 'canceled') {
		nuveiShowErrorMsg(scTrans.PaymentCanceled);
		return;
	}

	if (typeof resp.result == 'undefined') {
		console.error('Error with Checkout SDK response', resp);
		nuveiShowErrorMsg(scTrans.unexpectedError);
		return;
	}

	if ( (resp.result === 'APPROVED' || resp.result === 'PENDING')
		&& typeof resp.transactionId != 'undefined'
		&& resp.transactionId != 'undefined'
	) {

        console.log(nuveiSuccessRedirect, window?._nuveiOrderId);
        
        // the new Classic Checkout flow
        if ('' != nuveiSuccessRedirect) {
            // submit the transacion data and the related order id
            if ( ! isNaN(window?._nuveiOrderId) ) {
                fetch(scTrans.apiUrl + '/set-transaction-checker/', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': scTrans.nuveiApiSec
                    },
                    body: JSON.stringify({
                        orderId: window._nuveiOrderId,
                        transactionId: resp.transactionId,
                        paymentMethod: nuveiSelectedPaymentMethod
                    }),
                    keepalive: true
                });
            }
            
            // continue with the redirect
            window.location.href = nuveiSuccessRedirect;
            return;
        }

        // the old flow, now used from the Blocks Checkout
        nuveiSetTransactionField(resp.transactionId);

        jQuery('#nuvei_blocker').show();
        jQuery('#nuvei_checkout_container').html('');
        
        // in case of Classic Checkout or when the client will pay for an Order
        // created from the admin
        if ( jQuery(nuveiCheckoutClassicPayBtn).hasClass('nuvei-processing') ) {
            console.log('nuveiCheckoutClassicPayBtn is already processing, skipping click');
            return;
        }
        
        // TODO - remove this, we will get the redirect link when get the SC data
        // in case of admin order and recaptcha do a manual redirect
        if ( nuveiIsPayForExistingOrderPage && jQuery('.g-recaptcha').length ) {
            fetch(scTrans.apiUrl + '/redirect-paid-existing-order/', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': scTrans.nuveiApiSec,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ order_id: jQuery('#nuveiPayForExistingOrder').val() })
            })
            .then(res => {
                if (!res.ok) {
                    // error - 401, 403, 404 or 500
                    throw res;
                }

                // success, continue
                return res.json();
            })
            .then(data => {
                if (data.redirect_url) {
                    window.location.href = data.redirect_url;
                    return;
                }
            })
            .catch(async err => {
                console.error(err);
                nuveiShowErrorMsg();
                jQuery('#nuvei_blocker').hide();
            });
            
            return;
        }

        if ( jQuery(nuveiCheckoutClassicFormClass).length > 0 || nuveiIsPayForExistingOrderPage) {
            jQuery(nuveiCheckoutClassicPayBtn).addClass('nuvei-processing');
            jQuery(nuveiCheckoutClassicPayBtn).trigger('click');
            return;
        }

        // in case of Blocks Checkout
        if (jQuery(nuveiCheckoutBlockFormClass).length > 0) {
//            console.log('clearValidationErrors and nuveiCheckoutBlockPayBtn click');
            nuveiAllowFormSubmit = true;

            wp.data.dispatch('wc/store/validation').clearValidationErrors();

            setTimeout(() => {
                jQuery(nuveiCheckoutBlockPayBtn).trigger('click');
            }, 200);

            return;
        }
	}

	if (resp?.result == 'DECLINED') {
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
    
    nuveiCheckoutSdkParams.pmWhitelist

    // for the Blocks only
    if ( jQuery(nuveiCheckoutBlockFormClass).length > 0 ) {
        nuveiCheckoutSdkParams.prePayment = nuveiPrePaymentBlocks;

        // dynamically attach the logic of nuveiAfterSdkResponse() in this empty method.
        nuveiCheckoutSdkParams.onResult = function( resp ) {
            if ( nuveiBlocksResolvePayment ) {
                console.log('afterSdkResponse for Blocks', resp);

                // expired session
                if (resp.hasOwnProperty('session_expired') && resp.session_expired) {
                    nuveiBlocksResolvePayment( { success: false } );
                    window.location.reload();
                    return;
                }

                // a specific Error
                if(resp.hasOwnProperty('status')
                    && resp.status == 'ERROR'
                    && resp.hasOwnProperty('reason')
                    && resp.reason.toLowerCase().search('the currency is not supported') >= 0
                ) {
                    nuveiBlocksResolvePayment( { success: false, error: resp.reason } );
                    nuveiShowErrorMsg(resp.reason);
                    return;
                }

                if (typeof resp.result == 'undefined') {
                    console.error('Error with Checkout SDK response', resp);
                    nuveiBlocksResolvePayment( { success: false, error: scTrans.unexpectedError } );
                    nuveiShowErrorMsg(scTrans.unexpectedError);
                    return;
                }

                if ( (resp.result == 'APPROVED' || resp.result == 'PENDING')
                    && typeof resp.transactionId != 'undefined'
                    && resp.transactionId != 'undefined'
                ) {
                    jQuery('#nuvei_blocker').show();
                    jQuery('#nuvei_checkout_container').html('');

                    nuveiBlocksResolvePayment( { success: true, transaction_id: resp.transactionId } );
                    nuveiBlocksResolvePayment = null;
                    return;
                }

                if (resp.result == 'DECLINED') {
                    if (resp.hasOwnProperty('errorDescription')
                        && 'insufficient funds' == resp.errorDescription.toLowerCase()
                    ) {
                        nuveiBlocksResolvePayment( { success: false, error: scTrans.insuffFunds } );
                        nuveiShowErrorMsg(scTrans.insuffFunds);
                        return;
                    }

                    nuveiBlocksResolvePayment( { success: false, error: scTrans.paymentDeclined } );
                    nuveiShowErrorMsg(scTrans.paymentDeclined);
                    return;
                }

                nuveiBlocksResolvePayment( { success: false, error: scTrans.unexpectedError } );
                nuveiShowErrorMsg(scTrans.unexpectedError);
            }
        };
    }
    // Classic Checkout
    else {
        nuveiCheckoutSdkParams.prePayment   = nuveiPrePaymentClassic;
        nuveiCheckoutSdkParams.onResult     = nuveiAfterSdkResponse;
    }

    nuveiCheckoutSdkParams.onReady                  = nuveiOnSimplyReady;
    nuveiCheckoutSdkParams.onSelectPaymentMethod    = nuveiPmChange;
    nuveiCheckoutSdkParams.onFormValidated          = nuveiCheckIsSimplyValid;
    nuveiCheckoutSdkParams.crossBrowserApplePay     = true;

	simplyConnect(nuveiCheckoutSdkParams);

    // add some parameters to the Classic Checkout form
    if ( jQuery(nuveiCheckoutClassicFormClass).length > 0) {
        jQuery(nuveiCheckoutClassicFormClass)
            .append(`<input id="nuvei_session_token" type="hidden" name="nuvei_session_token" value="${nuveiCheckoutSdkParams.sessionToken}" />`);

        jQuery(nuveiCheckoutClassicFormClass)
            .append(`<input id="nuvei_oo_order_id" type="hidden" name="nuvei_oo_order_id" value="${nuveiCheckoutSdkParams.orderId}" />`);
    }
}

function nuveiPrePaymentClassic(paymentDetails) {
	console.log('nuveiPrePaymentClassic');

	return new Promise((resolve, reject) => {
        // check the form only for nuveiWallets
        if ( nuveiWallets.indexOf(nuveiSelectedPaymentMethod) >= 0
            && ! nuveiIsCheckoutClassicFormValid() 
        ) {
            console.log('nuveiIsCheckoutClassicFormValid - false');
            
            nuveiShowErrorMsg(scTrans.MissingRequiredFields);
            reject();
            return;
        }

        // On order-pay the WC order already exists — no need to verify the session hash.
        if ( nuveiIsPayForExistingOrderPage ) {
            resolve();
            return;
        }

        // Update the Order
        nuveiUpdateOrder(resolve, reject);
        return;
	});
}

function nuveiCheckIsSimplyValid(params) {
    if (params.hasOwnProperty('isFormValid')) {
        nuveiIsSimplyFormValid = params.isFormValid;
    }
}

function nuveiOnSimplyReady() {
    jQuery('#nuvei_blocker').hide();
}

function nuveiPmChange(params) {
    console.log(params.paymentMethodName);
    
    nuveiSelectedPaymentMethod = params.paymentMethodName;

    if (nuveiWallets.indexOf(nuveiSelectedPaymentMethod) >= 0) {
        nuveiIsSimplyFormValid = true;
        
        jQuery(nuveiCheckoutClassicPayBtn).hide();
        jQuery(nuveiCheckoutBlockPayBtn).hide();
    }
    else {
        nuveiIsSimplyFormValid = false;
        
        jQuery(nuveiCheckoutClassicPayBtn).show();
        jQuery(nuveiCheckoutBlockPayBtn).show();
    }
}

function nuveiShowErrorMsg(text) {
	if (typeof text == 'undefined' || '' == text) {
		text = scTrans.unexpectedError;
	}

    // Re-enable the pay button in case it was blocked by a programmatic click
    jQuery(nuveiCheckoutClassicPayBtn).removeClass('nuvei-processing');

	// short-code checkout
    if (jQuery(nuveiCheckoutClassicFormClass).length || nuveiIsPayForExistingOrderPage) {
        jQuery('.woocommerce-notices-wrapper').first().html(
            '<div class="woocommerce-error nuvei_error" role="alert">'
               +'<strong>'+ text +'</strong>'
           +'</div>'
        );

        jQuery('html, body').animate({
            scrollTop: jQuery('.woocommerce-notices-wrapper').offset().top - 100
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

        const noticeElement     = document.querySelector( '.wc-block-components-notices' );
        const elementPosition   = noticeElement.getBoundingClientRect().top + window.pageYOffset;

        window.scrollTo( {
            top: elementPosition - 50,
            behavior: 'smooth'
        } );
    }
}

/**
 * A method for the case when the merchant create an Order in the admin, then
 * the client pay it from its Store profile.
 */
function nuveiPayForExistingOrder() {
    console.log('nuveiPayForExistingOrder');

    fetch(scTrans.apiUrl + '/get-data-for-existing-order/', {
        method: 'POST',
        headers: {
            'X-WP-Nonce': scTrans.nuveiApiSec,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            orderId: jQuery('#nuveiPayForExistingOrder').val()
        })
    })
        // 1. first check for the status code (200 OK)
        .then(res => {
            if (!res.ok) {
                // error - 401, 403, 404 or 500
                throw res;
            }

            // success, continue
            return res.json();
        })
        // the success
        .then(data => {
            console.log(data);

            if (!nuveiIsCheckoutLoaded) {
                window._nuveiOrderId    = jQuery('#nuveiPayForExistingOrder').val();
                nuveiSuccessRedirect    = data.ordRedirectUrl;
                nuveiIsCheckoutLoaded   = true;
                
                delete(data.ordRedirectUrl);
                
                showNuveiCheckout(data);
            }
        })
        // error after the first check
        .catch(async err => {
            console.error(err);
            nuveiShowErrorMsg();
            jQuery('#nuvei_blocker').hide();
        });
        
    // add the blocker
    jQuery('#payment')
        .parent('form')
        .append('<div id="nuvei_blocker"><img class="nuvei_loader" src="'
            + scTrans.loaderUrl + '" /></div>');
}

/**
 * Add the transaction id field after we get the transaction result.
 *
 * @param string trId
 */
function nuveiSetTransactionField(trId) {
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
 * Get the required parameters for the SDK, on Classic Checkout.
 *
 * @param {string} formId   The class/id of the checkout form.
 * @param {string} attrName The used attribute - id or name. It is 'name' by default.
 */
function nuveiGetCheckoutData(formId, attrName = 'name') {
    console.log('call nuveiGetCheckoutData', formId, attrName);

    if ('sdk' !== scTrans.checkoutIntegration) {
        return;
    }

    // ── Debounce ────────────────────────────────────────────────────────────
    clearTimeout(nuveiGetCheckoutDataTimer);

    nuveiGetCheckoutDataTimer = setTimeout(() => {

        // ── In-flight guard ─────────────────────────────────────────────────
        if (nuveiGetCheckoutDataInFlight) {
            console.log('nuveiGetCheckoutData: skipped – previous fetch still in-flight');
            return;
        }

        const requestId         = crypto.randomUUID();
        nuveiCheckoutRequestId  = requestId;

        let scFormData = {};

        // get only populated fields
        jQuery(formId).find('input, select, textarea').each(function(){
            let _self = jQuery(this);

            try {
                let fieldById = jQuery('body').find(`#${_self.attr(attrName)}`);

                if (_self.attr(attrName) && fieldById.length > 0) {
                    scFormData[_self.attr(attrName)] = fieldById.val();
                }
            }
            catch (e) {
                return true;
            }
        });

        // Abort any previous controller so the browser stops waiting for a
        // response that we no longer care about (belt-and-suspenders alongside
        // the in-flight guard above).
        if (nuveiGetCheckoutDataController) {
            console.log('nuveiGetCheckoutData: aborting stale controller');
            nuveiGetCheckoutDataController.abort();
        }

        nuveiGetCheckoutDataController  = new AbortController();
        nuveiGetCheckoutDataInFlight    = true;

        fetch(scTrans.apiUrl + '/get-checkout-data/', {
            method: 'POST',
            headers: {
                'X-WP-Nonce': scTrans.nuveiApiSec,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                scFormData: scFormData
            }),
            signal: nuveiGetCheckoutDataController.signal
        })
            // 1. first check for the status code (200 OK)
            .then(res => {
                if (!res.ok) {
                    // error - 401, 403, 404 or 500
                    throw res;
                }

                // stale request check (handles the unlikely case where a second
                // call sneaked past the in-flight guard due to async timing)
                if (nuveiCheckoutRequestId !== requestId) {
                    console.log('nuveiGetCheckoutData: stale response ignored');
                    return;
                }

                // success, continue
                return res.json();
            })
            // the success
            .then(data => {
                if (typeof data !== 'undefined') {
                    console.log(data);
                    showNuveiCheckout(data);
                }
            })
            // error after the first check
            .catch(async err => {
                // Do not treat an intentional abort as an error
                if (err.name === 'AbortError') {
                    console.log('nuveiGetCheckoutData: request was aborted');
                    return;
                }

                console.error('Nuvei request failed.', err);
                nuveiShowErrorMsg();
                jQuery('#nuvei_blocker').hide();
            })
            .finally(() => {
                nuveiGetCheckoutDataInFlight = false;
            });

    }, NUVEI_GET_CHECKOUT_DATA_DELAY);

    return;
}

function nuveiDestroySimplyConnect() {
    if (typeof simplyConnect != 'undefined' && simplyConnect.hasOwnProperty('destroy')) {
        try {
            simplyConnect.destroy();
        }
        catch(e) {
            console.log('exception', e);
        }
    }
}

jQuery(function($) {
    console.log('document ready');

	if('no' === scTrans.isPluginActive) {
        console.log('nuvei plugin is not active.');
		return;
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

            // error missing scTrans or scTrans.paymentGatewayName
            if ( ! scTrans?.paymentGatewayName ) {
                console.error('Missing scTrans.paymentGatewayName.')
                return;
            }

            // on page load try to load Simply Connect
            if (nuveiIsCheckoutClassicFormValid(true)) {
                nuveiGetCheckoutData(nuveiCheckoutClassicFormClass);
            }

            jQuery(document.body).on('blur change focusout', nuveiMandatoryCheckoutFields, function(e) {
                var self    = jQuery(this);
                var newVal  = self.val();
                // Retrieve the previous value stored on this specific element
                var oldVal  = self.data('last-known-value');

                // Check if the value has actually changed
                if (newVal !== oldVal) {
                    // Update the storage immediately to block repeat events
                    self.data('last-known-value', newVal);

                    console.log('Checkout form field changed: ', self.attr('id'), self.val(), e.type);

                    // My custom checks come here
                    nuveiDestroySimplyConnect();

                    // No outer setTimeout needed — nuveiGetCheckoutData() has
                    // its own internal debounce (NUVEI_GET_CHECKOUT_DATA_DELAY)
                    // that collapses rapid bursts.  The old 1 000 ms delay was
                    // the primary contributor to the page-load + field-change
                    // race condition (Race Window 1).
                    if (nuveiIsCheckoutClassicFormValid(true)) {
                        nuveiGetCheckoutData(nuveiCheckoutClassicFormClass);
                    }
                }
            });

            // on payment provider change
            jQuery(document.body).on('change', nuveiCheckoutClassicPMethodName, function(e) {
                console.log('Payment Provider change.', jQuery(nuveiCheckoutClassicPMethodName + ':checked').val());

                if (nuveiIsCheckoutClassicFormValid(true)) {
                    nuveiGetCheckoutData(nuveiCheckoutClassicFormClass);
                }
                else {
                    nuveiDestroySimplyConnect();
                }
            });

            // Listen for updated_checkout event on Classic Checkout
            jQuery(document.body).on('updated_checkout', function() {
                console.log('updated_checkout event');

                if ( ! nuveiIsCheckoutClassicFormValid(true) ) {
                    jQuery('#nuvei_checkout_container').html(scTrans.MissingEmailCountry);
                }
            });

            // when the checkout form is placed successfully initiate Nuvei transaction
            jQuery('form.checkout').on('checkout_place_order_success', function (e, data) {
                console.log('Order success.', data)

                if (data?.data?.nuvei_try_payment && simplyConnect) {
                    nuveiSuccessRedirect = data.data.success_url;

                    jQuery('#nuvei_blocker').show();
                    jQuery('form.checkout').removeClass('processing');
                    jQuery('form.checkout').unblock();
                    
                    window._nuveiOrderId = data?.order_id;

                    setTimeout(() => {
                        simplyConnect.submitPayment();
                    }, 500);
                    
                    return;
                }
            });

            jQuery(document).on('load', '#nuvei_checkout_container', function() {
                console.log('on load #nuvei_checkout_container');
                nuveiIsCheckoutClassicFormValid(true);
            });
            
            // Dispatch custom JS event
            document.dispatchEvent(new CustomEvent('nuveiPfw:onPageLoadEvent'));
        }
        // the Classic Checkout block end

        // on the checkout/order-pay/ page
        if ( jQuery('body').hasClass('woocommerce-order-pay') ) {
            nuveiIsPayForExistingOrderPage = true;

            console.log(jQuery(nuveiCheckoutClassicPMethodName).val());

            // on-load, load Simply Connect if need
            if ( jQuery(nuveiCheckoutClassicPMethodName).val() == scTrans.paymentGatewayName ) {
                nuveiPayForExistingOrder();
            }

            // on PM change, load Simply Connect if need
            jQuery(document.body).on('change', nuveiCheckoutClassicPMethodName, function() {
                if ( jQuery(this).val() == scTrans.paymentGatewayName ) {
                    nuveiPayForExistingOrder();
                }
            });

            // catch when the form is submitted
            jQuery( 'form#order_review' ).on( 'submit', function( e ) {
                // error - Nuvei GW is not selected
                if ( jQuery(`${nuveiCheckoutClassicPMethodName}:checked`).val() !== scTrans.paymentGatewayName ) {
                    return;
                }

                // there is a transaction, submit the order
                if (jQuery(document).find('#nuvei_transaction_id').length
                    && jQuery(document).find('#nuvei_transaction_id').val() !== ''
                ) {
                    return;
                }

                // prevent form s
                e.preventDefault();
                console.log( "Logic running on Order Pay page..." );

                // our custom logic
                setTimeout(() => {
                    if ( nuveiIsCheckoutClassicFormValid() ) {
                        simplyConnect.submitPayment();
                    }

                    jQuery('form#order_review').unblock();
                }, 500);
            });
        }
        // on the checkout/order-pay/ page
    }

});
