/**
 * WC Blocks Checkout logic used when the "render_to" setting is set to
 * "nuvei_checkout_modal" - Simply Connect isn't rendered inline; it opens
 * in a modal overlay only when the customer clicks "Place Order".
 *
 * Depends on the shared consts/functions defined in nuvei_public.js and
 * blocks/nuvei-checkout-blocks.js (both loaded before this file).
 */

/**
 * Modal mode: the SDK isn't rendered before Pay is clicked, so there is
 * nothing to submit yet - open the modal, fetch fresh checkout data (which
 * renders the SDK into the modal via showNuveiCheckout()), then just wait.
 * The customer submits via the SDK's own in-modal button, which triggers
 * prePayment -> onResult -> nuveiBlocksResolvePayment(), same as container
 * mode's outcome handling.
 */
async function nuveiBlocksRunModalTransaction() {
    return new Promise( function( resolve ) {
        // set the resolver - only when actually submitting
        nuveiBlocksResolvePayment = resolve;

        jQuery('#nuvei_blocker').hide();
        jQuery('#nuvei_checkout_modal_overlay').show();

        nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, 'id');
    } );
}

jQuery(function() {
    // Prevent running in WP admin area
    if (typeof window.wp !== 'undefined'
        && window.wp.data
        && window.location
        && window.location.pathname.indexOf('/wp-admin/') !== -1
    ) {
        // In admin, do not run checkout JS
        return;
    }

    // bail out entirely if scTrans was never localized on this page (e.g.
    // WC Blocks preloaded this payment-method script on a page/context
    // where Nuvei isn't actually rendered) or when not in SDK mode.
    if ( !window.scTrans || 'sdk' !== scTrans?.checkoutIntegration ) {
        return;
    }

    // build the overlay once, outside the React-managed #payment-method
    // subtree, so Blocks re-renders never touch it.
    if ( jQuery('#nuvei_checkout_modal_overlay').length == 0 ) {
        const nuveiModalTitle = window.wc?.wcSettings?.getSetting( 'nuvei_data', {} )?.title || '';

        jQuery('body').append(
            '<div id="nuvei_checkout_modal_overlay">'
                + '<div class="nuvei-modal-dialog">'
                    + '<div class="nuvei-modal-header">'
                        + '<span class="nuvei-modal-title">' + window.wp.htmlEntities.decodeEntities( nuveiModalTitle ) + '</span>'
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

        // WC Blocks only calls onPaymentSetup after its own async validation/
        // store-dispatch cycle runs, which can take a few seconds on a slow
        // device - during that gap #nuvei_blocker isn't shown yet and the
        // form is still interactive. Show it immediately on click instead,
        // only when Nuvei is the selected payment method; onPaymentSetup's
        // own .show() call becomes a harmless no-op, and the existing
        // failure paths (nuveiIsCheckoutBlocksFormValid, onCheckoutFail,
        // transaction failure) already hide #nuvei_blocker again.
        jQuery(document.body).on('click', nuveiCheckoutBlockPayBtn, function() {
            if ( jQuery('#radio-control-wc-payment-method-options-nuvei').is(':checked') ) {
                jQuery('#nuvei_blocker').show();
            }
        });

        jQuery(document.body).on('click', '#nuvei_checkout_modal_overlay .nuvei-modal-close', function() {
            nuveiCloseCheckoutModal();

            // cancel a pending payment promise so onPaymentSetup doesn't hang
            if ( nuveiBlocksResolvePayment ) {
                nuveiBlocksResolvePayment( { success: false, error: scTrans.PaymentCanceled } );
                nuveiBlocksResolvePayment = null;
            }
        });
    }
});
