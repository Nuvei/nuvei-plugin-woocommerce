/**
 * Classic Checkout logic used when the "render_to" setting is set to
 * "nuvei_checkout_container" - Simply Connect renders inline, in the
 * checkout page, and we watch the form fields to keep it in sync.
 *
 * Depends on the shared consts/functions defined in nuvei_public.js
 * (loaded before this file).
 */
jQuery(function($) {
    if ('no' === scTrans.isPluginActive) {
        console.log('nuvei plugin is not active.');
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

    console.log('Classic checkout - container mode.');

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
            jQuery(nuveiCheckoutContainerSel).html(scTrans.MissingEmailCountry);
            return;
        }

        // WooCommerce may have swapped the .woocommerce-checkout-payment fragment
        // (order total changed), destroying the live Simply Connect container.
        // Re-render only if it's now empty, to avoid needless reloads.
        if ( jQuery(nuveiCheckoutContainerSel).is(':empty') ) {
            nuveiDestroySimplyConnect();
            nuveiGetCheckoutData(nuveiCheckoutClassicFormClass);
        }
    });

    // when the checkout form is placed successfully initiate Nuvei transaction
    jQuery('form.checkout').on('checkout_place_order_success', function (e, data) {
        console.log('Order saved.', data)

        if (data?.data?.nuvei_try_payment) {
            if (! simplyConnect) {
                // The order was saved successfully, but the Simply Connect
                // SDK isn't ready - unblock the form instead of leaving the
                // customer stuck, and show an error.
                console.error('nuvei_try_payment is set but simplyConnect is not initialized.');

                jQuery('#nuvei_blocker').hide();
                jQuery('form.checkout').removeClass('processing');
                jQuery('form.checkout').unblock();

                nuveiShowErrorMsg(scTrans.unexpectedError);

                return;
            }

            nuveiSuccessRedirect = data.data.success_url;

            jQuery('#nuvei_blocker').show();
            jQuery('form.checkout').removeClass('processing');
            jQuery('form.checkout').unblock();

            window._nuveiOrderId = data?.data?.order_id;

            nuveiSubmitPaymentWhenReady(() => {
                jQuery('#nuvei_blocker').hide();
                jQuery('form.checkout').removeClass('processing');
                jQuery('form.checkout').unblock();

                nuveiShowErrorMsg(scTrans.unexpectedError);
            });

            return;
        }
    });

    jQuery(document).on('load', nuveiCheckoutContainerSel, function() {
        console.log('on load ' + nuveiCheckoutContainerSel);
        nuveiIsCheckoutClassicFormValid(true);
    });

    // Dispatch custom JS event
    document.dispatchEvent(new CustomEvent('nuveiPfw:onPageLoadEvent'));
});
