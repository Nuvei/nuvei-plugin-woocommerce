/**
 * WC Blocks Checkout logic used when the "render_to" setting is set to
 * "nuvei_checkout_container" - Simply Connect renders inline, in the
 * Blocks payment method area, and we watch the form fields/cart totals to
 * keep it in sync.
 *
 * Depends on the shared consts/functions defined in nuvei_public.js and
 * blocks/nuvei-checkout-blocks.js (both loaded before this file).
 */

// must be outside the function so clearTimeout actually debounces
var nuveiBlocksReloadTimer  = null;

/**
 * Just reusing some code.
 */
function nuveiBlocksReloadSimply() {
    // Only proceed if Nuvei is the selected payment method
    if (wp.data.select('wc/store/payment').getActivePaymentMethod() !== scTrans.paymentGatewayName) {
        return;
    }

    jQuery('#nuvei_blocker').show();

    nuveiDestroySimplyConnect();

    jQuery(nuveiCheckoutContainerSel).html(window.wp.i18n.__('Loading...', 'nuvei-payments-for-woocommerce'));

    if (nuveiIsCheckoutBlocksFormValid(true)) {
        // add small delay
        clearTimeout( nuveiBlocksReloadTimer );

        nuveiBlocksReloadTimer = setTimeout( function() {
            nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, 'id');
            jQuery('#nuvei_blocker').hide();
            return;
        }, 600 );
    }
}

async function nuveiBlocksRunTransaction() {
    return new Promise( function( resolve ) {
        // set the resolver - only when actually submitting
        nuveiBlocksResolvePayment = resolve;

        // nuveiSubmitPaymentWhenReady() comes from nuvei_public.js
        nuveiSubmitPaymentWhenReady( function() {
            nuveiBlocksResolvePayment = null;

            resolve( {
                success: false,
                error: scTrans.unexpectedError
            } );
        } );
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

    // keeps the inline SDK form in sync with every field/cart change
    // before Pay is clicked.

    // watch the email field for changes
    let lastEmail = document.getElementById('email')?.value;

    jQuery( document.body ).on( 'blur', `#email:not(${nuveiCheckoutContainerSel} #email)`, function(e) {
        let self = jQuery(this);

        // Check if the value has actually changed
        if (self.val() !== lastEmail) {
            console.log('[Nuvei]: mail was changed', lastEmail, self.val())

            lastEmail = self.val();

            nuveiBlocksReloadSimply();
        }
    });

    // WP Blocks subscriber
    const store = wp.data.select( 'wc/store/cart' );

    // Subscribe for total and billign changes
    let lastTotal           = store.getCartTotals().total_price;
    let lastBillingCountry  = store.getCartData().billingAddress.country;

    wp.data.subscribe(() => {
        // some errors
        if (window.nuveiIsPayForExistingOrderPage || jQuery(nuveiCheckoutContainerSel).length == 0) {
            return;
        }

        // Do not check the totals and billing address if Nuvei is not selected
        const currentpaymentMethod = wp.data.select( 'wc/store/payment' ).getActivePaymentMethod();

        if (scTrans && scTrans.paymentGatewayName !== currentpaymentMethod) {
            console.log('[Nuvei]: The selected payment method is not Nuvei.');
            jQuery(nuveiCheckoutContainerSel).hide();
            return;
        }

        jQuery(nuveiCheckoutContainerSel).show();

        const currentTotals         = store.getCartTotals ? store.getCartTotals().total_price : null;
        const currentBillingCountry = store.getCartData().billingAddress.country;

        // check for changes
        if (currentTotals != lastTotal
            || currentBillingCountry !== lastBillingCountry
        ) {
            console.log('[Nuvei]: Checkout changed:', {
                'is total changed': currentTotals != lastTotal,
                'is country changed': lastBillingCountry  != currentBillingCountry,
            });

            lastTotal           = currentTotals;
            lastBillingCountry  = currentBillingCountry;

            nuveiBlocksReloadSimply();
        }

    });
});
