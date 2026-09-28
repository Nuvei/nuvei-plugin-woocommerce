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

    // build the full-page blocker once, up front, so it's always ready -
    // instead of only being created ad-hoc by nuveiPayForExistingOrder()
    // (Order-Pay page) or the Blocks checkout script.
    if ( jQuery('#nuvei_blocker').length == 0 ) {
        jQuery('body').append('<div id="nuvei_blocker"><img class="nuvei_loader" src="'
            + scTrans.loaderUrl + '" /></div>');
    }

    // the modal lives outside the checkout form/description, so it
    // survives WC/WP re-renders (updated_checkout, fragments, etc.)
    if (jQuery('#nuvei_checkout_modal_overlay').length == 0 && scTrans) {
        jQuery('body').append(
            '<div id="nuvei_checkout_modal_overlay">'
                + '<div class="nuvei-modal-dialog">'
                    + '<div class="nuvei-modal-header">'
                        + '<span class="nuvei-modal-title">' + (scTrans?.paymentGatewayTitle || '') + '</span>'
                        + '<button type="button" class="nuvei-modal-close" aria-label="Close">&times;</button>'
                    + '</div>'
                    + '<div class="nuvei-modal-message sfc-dialog sfc-decline" style="display:none;">'
                        + '<div class="sfc-dialog-body">'
                            + '<div class="sfc-dialog-img"></div>'
                            + '<p class="sfc-dialog-message nuvei-modal-message-text"></p>'
                            + '<div class="sfc-dialog-buttons">'
                                + '<button type="button" class="sfc-dialog-buttons-style sfc-cfa-ok-decline nuvei-modal-message-ok" tabindex="0">' + (scTrans?.Continue || 'OK') + '</button>'
                            + '</div>'
                        + '</div>'
                    + '</div>'
                    + '<div id="' + nuveiCheckoutContainerId + '" data-placeholder="Loading..."></div>'
                + '</div>'
            + '</div>'
        );
    }

    // close the modal on demand
    jQuery(document.body).on( 'click', '#nuvei_checkout_modal_overlay .nuvei-modal-close', function() {
        nuveiCloseCheckoutModal();
    });

    // WC core's own blockUI() should cover the click -> AJAX response
    // window, but on some setups (theme/CSS conflicts) it doesn't render
    // reliably, leaving the page looking unblocked for a few seconds. Show
    // our own blocker immediately on click as a safety net; it stays under
    // WC's own overlay when both are visible, and checkout_place_order_success
    // already re-shows/keeps it before the modal takes over.
    jQuery(document.body).on( 'click', nuveiCheckoutClassicPayBtn, function() {
        if ( jQuery(`${nuveiCheckoutClassicPMethodName}:checked`).val() !== scTrans.paymentGatewayName ) {
            return;
        }

        console.log('[Nuvei]: click on pay button.');
        jQuery('#nuvei_blocker').show();
    });

    // safety net: if WC's own validation fails or the AJAX submit errors
    // out, checkout_place_order_success never fires, so hide the blocker
    // we showed on click above.
    jQuery(document.body).on( 'checkout_error', function() {
        jQuery('#nuvei_blocker').hide();
    });

    // when the checkout form is placed successfully, the Order is already
    // validated and saved - fetch the Nuvei checkout data for it and open
    // the modal to run the transaction.
    jQuery('form.checkout').on('checkout_place_order_success', function (e, data) {
        console.log('[Nuvei]: Order saved.', data);

        if (! data?.data?.order_id) {
            return;
        }

        window._nuveiOrderId   = data?.data?.order_id;
        nuveiSuccessRedirect   = data.data.success_url;

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
                sdkParams.onResult              = nuveiAfterSdkResponse;
                sdkParams.crossBrowserApplePay  = true;

                simplyConnect(sdkParams);

                // hand off from the full-page blocker to the modal's own
                // "Loading..." placeholder while the SDK initializes
                jQuery('#nuvei_blocker').hide();
                jQuery('#nuvei_checkout_modal_overlay').show();
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
