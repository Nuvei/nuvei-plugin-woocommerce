const { validationStore }           = window.wc.wcBlocksData;
const nuveiCheckoutBlockFormClass   = 'form.wc-block-components-form';
const nuveiCheckoutBlockPayBtn      = '.wc-block-components-checkout-place-order-button';
const nuveiCheckoutBlockPMethodName = 'input[name="radio-control-wc-payment-method-options"]';
const nuveiCheckoutBlockContText    = (typeof scTrans == 'object'
    && scTrans.hasOwnProperty('checkoutIntegration')
    && 'sdk' === scTrans.checkoutIntegration) ?
        window.wp.i18n.__('The Checkout form must be valid to continue!', 'nuvei-payments-for-woocommerce') :
            window.wp.i18n.__('You will be redirected to Nuvei secure payment page.', 'nuvei-payments-for-woocommerce');
    
const nuveiInvalidField = window.wp.i18n.__('The field is not valid.', 'nuvei-payments-for-woocommerce');

/**
 * Checks if the Checkout form is valid.
 *
 * @returns {Boolean}
 */
function nuveiIsCheckoutBlocksFormValid() {
    console.log('call nuveiIsCheckoutBlocksFormValid');

    // error
    if (!document.querySelector(nuveiCheckoutBlockFormClass)
        || !document.querySelector(nuveiCheckoutBlockFormClass).checkValidity()
    ) {
        console.log('The checkout form is not valid.');

        nuveiDestroySimplyConnect();

        jQuery('#nuvei_checkout_container').html('');
        jQuery('#nuvei_blocker').hide();
        return false;
    }
    
    const errors    = {};
    let isFormValid = true;

    jQuery(nuveiCheckoutBlockFormClass).find('input, select, textarea').each( function() {
        let self = jQuery(this);
        let _key = self.prop('id').replace('-', '_');
        
        if (!self.prop('required')) {
            // continue with the next field
            return true;
        }

        // email check
        if ('email' == self.prop('id') || 'email' == self.prop('type')) {
            let regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/; // check the email

            if (!regex.test(self.val())) {
                console.log('the element is not valid', self.attr('id'));

                nuveiDestroySimplyConnect();

                jQuery('#nuvei_checkout_container').html('');
                jQuery('#nuvei_blocker').hide();

                isFormValid = false;

                errors.billing_email = {
                    message: nuveiInvalidField,
                };

                // break the loop
                return false;
            }

            return true;
        }

        // phone check
        if ('shipping-phone' == self.prop('id') || 'tel' == self.prop('type')) {
            console.log('check the phone');
            let regex = /^[+]*[(]{0,1}[0-9]{1,3}[)]{0,1}[-\s\./0-9]*$/g;


            if (!regex.test(self.val())) {
                console.log('the element is not valid', self.attr('id'));

                nuveiDestroySimplyConnect();

                jQuery('#nuvei_checkout_container').html('');
                jQuery('#nuvei_blocker').hide();

                errors.billing_phone = {
                    message: nuveiInvalidField,
                };

                isFormValid = false;
                // break the loop
                return false;
            }

            return true;
        }

        // other errors
        if (self.attr('aria-errormessage')
            || 'true' == self.attr('aria-invalid')
            || self.closest('div').hasClass('has-error')
        ) {
            console.log('the element is not valid', self.attr('id'));

            console.log(
                self.attr('aria-errormessage'),
                self.closest('div').hasClass('has-error'),
                jQuery(nuveiCheckoutBlockFormClass).find('.wc-block-store-notice.is-error').length
            );

            nuveiDestroySimplyConnect();

            jQuery('#nuvei_checkout_container').html('');
            jQuery('#nuvei_blocker').hide();

            isFormValid = false;
            // break the loop
            return false;
        }
        // in case the field is not marked as invalid but it is required and empty.
        else if ('' == self.val()) {
            errors[_key] = {
                message: nuveiInvalidField,
            };
            
            isFormValid = false;
            return false;
        }
    });

    // Set errors in WooCommerce Blocks
    wp.data.dispatch(validationStore).setValidationErrors(errors);
    
    return isFormValid;
}

/**
 * In this subscriber we try to handle the payment method check.
 */
function secondSubscriber() {
    const currentpaymentMethod = wp.data.select( 'wc/store/payment' ).getActivePaymentMethod();

    // Catch changed Payment Method and Show/Hide the default payment button.
    console.log(currentpaymentMethod);

    try {
        // in case Nuvei is selected
        if (scTrans && scTrans.paymentGatewayName == currentpaymentMethod) {
            jQuery(nuveiCheckoutBlockPayBtn).not(nuveiCheckoutCustomPayBtn).hide();
            jQuery(nuveiCheckoutCustomPayBtn).show();
            jQuery('#nuvei_checkout_container').show();
        }
        else {
            nuveiDestroySimplyConnect();
            jQuery(nuveiCheckoutCustomPayBtn).hide();
            jQuery('#nuvei_checkout_container').hide();
            jQuery(nuveiCheckoutBlockPayBtn).not(nuveiCheckoutCustomPayBtn).show();
        }
    }
    catch(e) {}
};

/**
 * Integrate Nuvei payment option and button in the Blocks Chckout.
 */
(function() {
    const { useEffect } = window.wp.element;
    const nuveiSettings = window.wc.wcSettings.getSetting( 'nuvei_data', {} );
    const nuveiLabel    = window.wp.htmlEntities.decodeEntities( nuveiSettings.title )
        || window.wp.i18n.__('Nuvei', 'nuvei-payments-for-woocommerce');
    let label           = nuveiLabel;

    // eventualy add an icon
    if (nuveiSettings.icon) {
        label = wp.element.createElement(
            "span",
            { style: { display: 'flex' } },
            wp.element.createElement(
                "img",
                {
                    src: nuveiSettings.icon,
                    alt: nuveiLabel,
                    style: { marginRight: 5 }
                }
            ),
            "  " + nuveiLabel
        );
    }

    const Content = () => {
        useEffect(() => {
            console.log('Nuvei payment method element loaded. Check if the checkout form is valid.');

            if (typeof scTrans == 'object'
                && scTrans.hasOwnProperty('checkoutIntegration')
                && 'sdk' === scTrans.checkoutIntegration
            ) {
                // clone the original Place Order button
                nuveiInsertCustomPayButton(nuveiCheckoutBlockPayBtn);

                // append the origial Simply Connect container
                if (jQuery('#payment-method').find('#nuvei_checkout_container').length == 0) {
                    let placeholderText =

                    jQuery('#radio-control-wc-payment-method-options-nuvei')
                        .closest('.wc-block-components-radio-control-accordion-option')
                        .append(`<div id="nuvei_checkout_container" data-placeholder="${nuveiCheckoutBlockContText}"></div>`);
                }

                // try to validate the form on checkout page load
                if (nuveiIsCheckoutBlocksFormValid()) {
                    nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, 'id');
                }
            }

            // Cleanup: do nothing (no destroy here)
//            return () => { };
        }, []);

//        return window.wp.element.createElement(
//            'div',
//            { id: 'nuvei_checkout_container' },
//            ''
//        );
    };

    const nuveiBlocksOptions = {
        name: 'nuvei',
        label: label,
        content: Object( window.wp.element.createElement )( Content, null ),
        edit: Object( window.wp.element.createElement )( Content, null ),
        ariaLabel: nuveiLabel,
        canMakePayment: () => true
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod(nuveiBlocksOptions);
    window.nuveiCheckoutSdkParams = nuveiSettings.checkoutParams;

    console.log('nuveiBlocksOptions was registered');
})();

jQuery(function() {
    console.log('document ready blocks checkout');

    if (typeof scTrans == 'object'
        && scTrans.hasOwnProperty('checkoutIntegration')
        && 'sdk' !== scTrans.checkoutIntegration
    ) {
        return;
    }

    // append a blocker
    if ( typeof scTrans != 'undefined' && jQuery('#payment-method').length ) {
        jQuery('#payment-method')
            .append('<div id="nuvei_blocker"><img class="nuvei_loader" src="'
                + scTrans.loaderUrl + '" /></div>');
    }

    // WP Blocks subscriber
    const store = wp.data.select( 'wc/store/cart' );

    let lastTotal       = store.getCartTotals().total_price;
    let lastBilling     = JSON.stringify(store.getCartData().billingAddress);

    // subscribe to change events for the totals and the billing address
    if (typeof nuveiDebounce != 'undefined') {
        const debouncedHandler = nuveiDebounce(() => {
            if (jQuery('#nuvei_checkout_container').length == 0) {
                return;
            }

            // Do not check the totals and billing address if Nuvei is not selected
            const currentpaymentMethod = wp.data.select( 'wc/store/payment' ).getActivePaymentMethod();

            if (scTrans && scTrans.paymentGatewayName !== currentpaymentMethod) {
                console.log('The selected payment method is not Nuvei.')
                return;
            }

            const currentTotals     = store.getCartTotals ? store.getCartTotals().total_price : null;
            const currentBilling    = JSON.stringify(store.getCartData().billingAddress);

            // Totals have changed
            if (currentTotals != lastTotal) {
                lastTotal = currentTotals;

                console.log('Cart totals changed:', currentTotals);

                nuveiDestroySimplyConnect();
                jQuery('#nuvei_checkout_container').html(window.wp.i18n.__('Loading...', 'nuvei-payments-for-woocommerce'));
                nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, 'id');
            }

            // Billing address changed
            if (currentBilling !== lastBilling) {
                lastBilling = currentBilling;
                console.log('Billing address changed');

                if (nuveiIsCheckoutBlocksFormValid()) {
                    nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, 'id');
                    return;
                }
            }

        }, 1000);

        // Subscribe using the debounced handler
        wp.data.subscribe(debouncedHandler);
    }

    // subscribe from payment method changes
    wp.data.subscribe(secondSubscriber);
});
