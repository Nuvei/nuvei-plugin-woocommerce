const nuveiCheckoutBlockFormClass       = 'form.wc-block-components-form';
const nuveiCheckoutClassicFormClass     = 'form.checkout.woocommerce-checkout';
// the DOM id where the Simply Connect SDK renders - either the inline
// checkout container or a modal, depending on the "render_to" plugin setting
const nuveiCheckoutContainerId          = (typeof scTrans !== 'undefined' && scTrans.simplyDomPlace)
    ? scTrans.simplyDomPlace : 'nuvei_checkout_container';
const nuveiCheckoutContainerSel         = '#' + nuveiCheckoutContainerId;
const nuveiCheckoutClassicPayBtn        = '#place_order';
const nuveiCheckoutClassicPMethodName   = 'input[name="payment_method"]';
const NUVEI_GET_CHECKOUT_DATA_DELAY     = 350; // ms — collapses bursts of calls into one fetch
const nuveiWallets                      = ['ppp_ApplePay', 'ppp_GooglePay', 'ppp_Paze', 'apmgw_Venmo', 'apmgw_VenmoPP'];

var nuveiCheckoutSdkParams          = {};
var nuveiSuccessRedirect            = '';
var nuveiIsFormValid                = true;
var nuveiBlocksResolvePayment       = null;
var nuveiWalletInProgress           = false;
// set when a failed transaction triggers a Checkout refresh, so the blocker
// stays visible until the SDK is ready again (nuveiOnSimplyReady)
var nuveiBlocksRefreshInProgress    = false;
// AbortController for the current openOrder fetch request
var nuveiGetCheckoutDataController  = null;
var nuveiCheckoutRequestId          = null; // request flag
var nuveiIsSimplyFormValid          = false;
// Debounce timer and in-flight flag for nuveiGetCheckoutData
var nuveiGetCheckoutDataTimer       = null;
var nuveiGetCheckoutDataInFlight    = false;
var nuveiSelectedPaymentMethod      = '';
// _nuveiOrderId will be set dynamically and will hold the saved WC Order ID
// set to true once the SDK fires onReady - safe to call simplyConnect.submitPayment()
var nuveiSimplyReady                = false;

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
                console.log('[Nuvei]: prepayment resolved.');

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
 * This method is for Classic Checkout only!
 *
 * @param object resp
 * @returns void
 */
function nuveiAfterSdkResponse(resp) {
	console.log('[Nuvei]: nuveiAfterSdkResponse', resp);

    // Success - classic-modal.js always sets nuveiSuccessRedirect before
    // triggering the SDK, so this always redirects.
	if ( (resp?.result === 'APPROVED' || resp?.result === 'PENDING')
		&& resp?.transactionId != 'undefined'
	) {
        nuveiSuccessRedirect += '&status=' + resp.result;

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
                })
            })
            .then(r => r.json())
            .then(data => {
                console.log(data);
                window.location.href = nuveiSuccessRedirect;
            })
            .catch(err => {
                console.error('set-transaction-checker error', err);
                window.location.href = nuveiSuccessRedirect;
            });

            return;
        }

        // continue with the redirect
        window.location.href = nuveiSuccessRedirect;
        return;
	}

    // error - expired session
    if (resp?.session_expired) {
        window.location.reload();
        return;
    }

    // error - a specific currency Error
    if ( resp?.status?.toLowerCase() == 'error'
        && resp?.reason?.toLowerCase().search('the currency is not supported') >= 0
    ) {
        nuveiShowErrorMsg(resp.reason);
        return;
    }
    
    // error - canceled
    if (resp?.status?.toLowerCase() == 'canceled') {
		nuveiShowErrorMsg(scTrans.PaymentCanceled);
		return;
	}

    // error - declined
	if (resp?.result?.toLowerCase() == 'declined') {
        if (resp.hasOwnProperty('errorDescription')
            && 'insufficient funds' == resp.errorDescription.toLowerCase()
        ) {
            nuveiShowErrorMsg(scTrans.insuffFunds);
            return;
        }

		nuveiShowErrorMsg(scTrans.paymentDeclined);
		return;
	}
    
    console.error('Error with Checkout SDK response', resp);
	nuveiShowErrorMsg(scTrans.unexpectedError);
	return;
}

/**
 * @param object _params
 * @returns void
 */
function showNuveiCheckout(_params) {
    console.log('[Nuvei]: call showNuveiCheckout');

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
        
        nuveiBlocksRefreshInProgress = false;
        
        jQuery('#nuvei_blocker').hide();
        jQuery(nuveiCheckoutContainerSel).html(scTrans.MissingRequiredFields);
        
        return;
    }

    // in this case we have product with Nuvei payment plan.
    if('savePM' === nuveiCheckoutSdkParams.savePM) {
        nuveiCheckoutSdkParams.pmBlacklist  = null;
        nuveiCheckoutSdkParams.pmWhitelist  = ['cc_card'];
    }
    
    nuveiCheckoutSdkParams.pmWhitelist

    // for the Blocks only
    nuveiCheckoutSdkParams.prePayment = nuveiPrePaymentBlocks;

    // dynamically attach the logic of nuveiAfterSdkResponse() in this empty method.
    nuveiCheckoutSdkParams.onResult = function( resp ) {
        if ( nuveiBlocksResolvePayment ) {
            console.log('[Nuvei]: afterSdkResponse for Blocks', resp);

            if ( (resp?.result?.toLowerCase() == 'approved' || resp?.result?.toLowerCase() == 'pending')
                && resp?.transactionId
            ) {
                jQuery('#nuvei_blocker').show();
                jQuery(nuveiCheckoutContainerSel).html('');
                jQuery('#nuvei_checkout_modal_overlay').hide();

                nuveiBlocksResolvePayment( { success: true, transaction_id: resp.transactionId } );
                nuveiBlocksResolvePayment = null;
                return;
            }

            // error - expired session
            if (resp?.session_expired) {
                nuveiBlocksResolvePayment( { success: false } );
                window.location.reload();
                return;
            }

            var nuveiErrMsg = scTrans.unexpectedError;

            // a specific Error
            if (resp?.status?.toLowerCase() == 'error'
                && resp?.reason?.toLowerCase().search('the currency is not supported') >= 0
            ) {
                nuveiErrMsg = resp.reason;
            }
            // error - canceled
            else if (resp?.status?.toLowerCase() == 'canceled') {
                nuveiErrMsg = scTrans.PaymentCanceled;
            }
            // error - declined
            else if (resp?.result?.toLowerCase() == 'declined') {
                nuveiErrMsg = ( 'insufficient funds' == resp?.errorDescription?.toLowerCase() )
                    ? scTrans.insuffFunds : scTrans.paymentDeclined;
            }
            else {
                console.error('Error with Checkout SDK response', resp);
            }

            // onPaymentSetup's Promise only resolves once per "Place Order"
            // click, so the customer can't retry through the SDK's own
            // button - a second approved result would have no Promise left
            // to fulfil, capturing money with the checkout flow stuck.
            // Close the modal instead; the error is shown on the checkout
            // page and the next "Place Order" click opens a fresh modal
            // (nuveiBlocksRunModalTransaction) with new data.
            jQuery('#nuvei_checkout_modal_overlay').hide();
            nuveiDestroySimplyConnect();

            nuveiBlocksResolvePayment( { success: false, error: nuveiErrMsg } );
            nuveiBlocksResolvePayment = null;

            return;
        }
    };

    nuveiCheckoutSdkParams.onReady                  = nuveiOnSimplyReady;
    nuveiCheckoutSdkParams.onSelectPaymentMethod    = nuveiPmChange;
    nuveiCheckoutSdkParams.onFormValidated          = nuveiCheckIsSimplyValid;
    nuveiCheckoutSdkParams.crossBrowserApplePay     = true;

	simplyConnect(nuveiCheckoutSdkParams);
}

function nuveiCheckIsSimplyValid(params) {
    if (params.hasOwnProperty('isFormValid')) {
        nuveiIsSimplyFormValid = params.isFormValid;
    }
}

function nuveiOnSimplyReady() {
    nuveiBlocksRefreshInProgress = false;
    nuveiSimplyReady             = true;

    jQuery('#nuvei_blocker').hide();
}

/**
 * The SDK is usable only when its script is loaded and it has fired onReady.
 *
 * @returns {Boolean}
 */
function nuveiIsSimplyReady() {
    return nuveiSimplyReady
        && typeof simplyConnect != 'undefined'
        && typeof simplyConnect.submitPayment == 'function';
}

/**
 * Wait until the Simply Connect SDK signals it's ready (nuveiOnSimplyReady)
 * before calling submitPayment(), instead of a blind fixed timeout.
 * Falls back to an error after maxWaitMs so the customer is never stuck.
 *
 * @param {Function}    onTimeout Called if the SDK never becomes ready in time.
 * @param {Number}      maxWaitMs
 * @param {Number}      intervalMs
 */
function nuveiSubmitPaymentWhenReady(onTimeout, maxWaitMs = 5000, intervalMs = 100) {
    if (nuveiIsSimplyReady()) {
        simplyConnect.submitPayment();
        return;
    }

    let waited = 0;

    const poll = setInterval(() => {
        waited += intervalMs;

        if (nuveiIsSimplyReady()) {
            clearInterval(poll);
            simplyConnect.submitPayment();
            return;
        }

        if (waited >= maxWaitMs) {
            clearInterval(poll);
            console.error('Simply Connect SDK was not ready after ' + maxWaitMs + 'ms.');

            if (typeof onTimeout === 'function') {
                onTimeout();
            }
        }
    }, intervalMs);
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

    // The checkout page is covered by our full-screen SDK modal overlay, so
    // a plain WC notice injected behind it would be invisible to the
    // customer. Show the message inside the modal itself instead.
    nuveiShowModalMessage(text);
}

/**
 * Get the required parameters for the SDK, on Classic Checkout.
 *
 * @param {string} formId   The class/id of the checkout form.
 * @param {string} attrName The used attribute - id or name. It is 'name' by default.
 */
function nuveiGetCheckoutData(formId, attrName = 'name') {
    console.log('[Nuvei]: call nuveiGetCheckoutData', formId, attrName);

    if ('sdk' !== scTrans.checkoutIntegration) {
        return;
    }

    // ── Debounce ────────────────────────────────────────────────────────────
    clearTimeout(nuveiGetCheckoutDataTimer);

    nuveiGetCheckoutDataTimer = setTimeout(() => {

        // ── In-flight guard ─────────────────────────────────────────────────
        if (nuveiGetCheckoutDataInFlight) {
            console.log('[Nuvei]: nuveiGetCheckoutData: skipped – previous fetch still in-flight');
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
            console.log('[Nuvei]: nuveiGetCheckoutData: aborting stale controller');
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
                    console.log('[Nuvei]: nuveiGetCheckoutData: stale response ignored');
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
                    console.log('[Nuvei]: nuveiGetCheckoutData: request was aborted');
                    return;
                }

                console.error('Nuvei request failed.', err);
                nuveiShowErrorMsg();
                nuveiBlocksRefreshInProgress = false;
                jQuery('#nuvei_blocker').hide();
            })
            .finally(() => {
                nuveiGetCheckoutDataInFlight = false;
            });

    }, NUVEI_GET_CHECKOUT_DATA_DELAY);

    return;
}

function nuveiDestroySimplyConnect() {
    console.log('[Nuvei]: nuveiDestroySimplyConnect');
    
    // The SDK instance is gone - a new onReady must arrive before we can submit again.
    // This is reset unconditionally: we may destroy an SDK that never fired onReady.
    nuveiSimplyReady = false;

    // Deliberately NOT nuveiIsSimplyReady() here - that guard means "safe to submit"
    // and requires onReady. A half-initialized SDK must still be destroyed.
    if (typeof simplyConnect != 'undefined' && typeof simplyConnect.destroy == 'function') {
        try {
            simplyConnect.destroy();
        }
        catch(e) {
            console.log('exception', e);
        }
    }
}

/**
 * Closes the Simply Connect modal (render_to = nuvei_checkout_modal, Classic
 * or Blocks), tears down the SDK so the modal is empty on the next open, and
 * resets the inline message shown via nuveiShowModalMessage(), if any.
 */
function nuveiCloseCheckoutModal() {
    jQuery('#nuvei_checkout_modal_overlay').hide();
    jQuery('#nuvei_checkout_modal_overlay .nuvei-modal-message').hide();
    jQuery(nuveiCheckoutContainerSel).show();

    nuveiDestroySimplyConnect();
}

/**
 * Shows an error/info message inside the Simply Connect modal, in place of
 * the SDK form, with its own OK button. Used by nuveiShowErrorMsg() instead
 * of the page-level WC notice, which would be hidden behind the modal's
 * overlay. Clicking OK closes the modal (nuveiCloseCheckoutModal()).
 */
function nuveiShowModalMessage(text) {
    jQuery('#nuvei_checkout_modal_overlay .nuvei-modal-message-text').text(text);
    jQuery(nuveiCheckoutContainerSel).hide();
    jQuery('#nuvei_checkout_modal_overlay .nuvei-modal-message').show();
    jQuery('#nuvei_checkout_modal_overlay').show();
}

jQuery(function($) {
    console.log('[Nuvei]: document ready');

    // delegated - the modal markup itself is built later, per mode, in
    // classic-modal.js or blocks-modal.js
    jQuery(document.body).on('click', '#nuvei_checkout_modal_overlay .nuvei-modal-message-ok', function() {
        nuveiCloseCheckoutModal();
    });

	if('no' === scTrans.isPluginActive) {
        console.log('[Nuvei]: nuvei plugin is not active.');
		return;
	}

    // thankyou page modifications
    if (typeof scTrans.thankYouPageNewTitle != 'undefined') {
        jQuery(".entry-title, h1").html(scTrans.thankYouPageNewTitle);
    }
});
