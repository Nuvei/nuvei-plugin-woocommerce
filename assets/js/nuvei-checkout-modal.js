/**
 * Classic Checkout logic used when the "render_to" setting is set to
 * "nuvei_checkout_modal" - Simply Connect is not loaded on page load; we
 * rely on WooCommerce's own validation, let it save the Order, then open
 * a modal and run our transaction against the already-saved Order.
 *
 * Depends on the shared consts/functions defined in nuvei_public.js
 * (loaded before this file).
 */
jQuery(function($) {
    if ('no' === scTrans.isPluginActive) {
        console.log('[Nuvei]: nuvei plugin is not active.');
        return;
    }

    // only for SDK flow
    if ('sdk' != scTrans.checkoutIntegration) {
        return;
    }

    // only on Classic Checkout, shortcode
    if (! jQuery(nuveiCheckoutClassicFormClass).length) {
        return;
    }

    console.log('[Nuvei]: Classic checkout - modal mode.');

    // error missing scTrans or scTrans.paymentGatewayName
    if ( ! scTrans?.paymentGatewayName ) {
        console.error('Missing scTrans.paymentGatewayName.')
        return;
    }

    // the modal lives outside the checkout form/description, so it
    // survives WC/WP re-renders (updated_checkout, fragments, etc.)
    if (jQuery('#nuvei_checkout_modal_overlay').length == 0) {
        jQuery('body').append(
            '<div id="nuvei_checkout_modal_overlay">'
                + '<div class="nuvei-modal-dialog">'
                    + '<button type="button" class="nuvei-modal-close" aria-label="Close">&times;</button>'
                    + '<div id="' + nuveiCheckoutContainerId + '" data-placeholder="Loading..."></div>'
                + '</div>'
            + '</div>'
        );
    }

    // close the modal on demand
    jQuery(document.body).on('click', '#nuvei_checkout_modal_overlay .nuvei-modal-close', function() {
        jQuery('#nuvei_checkout_modal_overlay').hide();
        nuveiDestroySimplyConnect();
    });

    // when the checkout form is placed successfully, the Order is already
    // validated and saved - fetch the Nuvei checkout data for it and open
    // the modal to run the transaction.
    jQuery('form.checkout').on('checkout_place_order_success', function (e, data) {
        console.log('[Nuvei]: Order saved.', data);

//        if (! data?.data?.nuvei_try_payment) {
//            return;
//        }
        if (! data?.data?.order_id) {
            return;
        }

        window._nuveiOrderId   = data?.data?.order_id;
        nuveiSuccessRedirect   = data.data.success_url;

        jQuery('#nuvei_blocker').show();
        jQuery('form.checkout').removeClass('processing');
        jQuery('form.checkout').unblock();

        // The Order already exists - reuse the same endpoint/flow as
        // nuveiPayForExistingOrder() to get the session token tied to it,
        // then render the SDK into the modal and submit.
        fetch(scTrans.apiUrl + '/get-data-for-existing-order/', {
            method: 'POST',
            headers: {
                'X-WP-Nonce': scTrans.nuveiApiSec,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ 
                orderId: window._nuveiOrderId 
            })
        })
            // 1. first check for the status code (200 OK)
            .then(res => {
                if (!res.ok) {
                    // error - 401, 403, 404 or 500
                    throw res;
                }

                return res.json();
            })
            // the success
            .then(data => {
                console.log(data);

                delete(data.ordRedirectUrl);
                
                // let's keep the original data
                let sdkParams = data;
                
                // few modifications for Classic Checkout
//                nuveiCheckoutSdkParams.prePayment   = nuveiPrePaymentClassic; // We no need it anymore
                sdkParams.onResult = nuveiAfterSdkResponse;
                
                // common params for Classic and Blocks
//                nuveiCheckoutSdkParams.onReady                  = nuveiOnSimplyReady; // Not sure if we need it anymore
//                nuveiCheckoutSdkParams.onSelectPaymentMethod    = nuveiPmChange; // Not need it anymore, we do not have to open modal if another PM is selected
//                nuveiCheckoutSdkParams.onFormValidated          = nuveiCheckIsSimplyValid; // No need it. We use it before
                sdkParams.crossBrowserApplePay     = true;
                
                simplyConnect(sdkParams);

                // hand off from the full-page blocker to the modal's own
                // "Loading..." placeholder while the SDK initializes
                jQuery('#nuvei_blocker').hide();
                jQuery('#nuvei_checkout_modal_overlay').show();

//                showNuveiCheckout(sdkParams);
//
//                nuveiSubmitPaymentWhenReady(() => {
//                    jQuery('#nuvei_blocker').hide();
//                    jQuery('#nuvei_checkout_modal_overlay').hide();
//
//                    nuveiShowErrorMsg(scTrans.unexpectedError);
//                });
            })
            // error after the first check
            .catch(async err => {
                console.error(err);

                jQuery('#nuvei_blocker').hide();
                jQuery('#nuvei_checkout_modal_overlay').hide();

                nuveiShowErrorMsg(scTrans.unexpectedError);
            });
    });
});
