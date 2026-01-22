const nuveiCheckoutBlockPayBtn      = '.wc-block-components-checkout-place-order-button';
const nuveiCheckoutBlockPMethodName = 'input[name="radio-control-wc-payment-method-options"]';

const nuveiFormNotInvalidTxt = window.wp.i18n.__(
    'Please fill email and country fields to continue with payment.', 
    'nuvei-payments-for-woocommerce'
);

const nuveiCheckoutBlockContText    = (typeof scTrans == 'object'
    && scTrans.hasOwnProperty('checkoutIntegration')
    && 'sdk' === scTrans.checkoutIntegration) ?
        nuveiFormNotInvalidTxt :
            window.wp.i18n.__('You will be redirected to Nuvei secure payment page.', 'nuvei-payments-for-woocommerce');

//const nuveiInvalidField = window.wp.i18n.__('The field is not valid.', 'nuvei-payments-for-woocommerce');

/**
 * Checks if the Checkout form is valid.
 *
 * @params {Boolean} justLoadSimply When is set to true we will check only for country and email.
 * @returns {Boolean}
 */
function nuveiIsCheckoutBlocksFormValid(justLoadSimply = false) {
    console.log('call nuveiIsCheckoutBlocksFormValid');
    
    const { validationStore }   = window.wc.wcBlocksData;
    const noticesStore          = window.wc.wcBlocksData.STORE_NOTICES_STORE_KEY;
    const { dispatch }          = wp.data;
    const validationErrors      = wp.data.select( 'wc/store/validation' ).getValidationErrors();
    
    let isFormValid     = true;
    
    // no errors
    if (Object.keys( validationErrors ).length == 0) {
        return isFormValid;
    }
    
    // Minimal check, when need only the country and the email.
    if ( justLoadSimply ) {
        Object.keys( validationErrors ).forEach( ( id ) => {
            if (id == 'billing_email' || id == 'billing_country') {
                isFormValid = false;
                
                dispatch( 'wc/store/validation' ).setValidationErrors( {
                    [ id ]: {
                        ...validationErrors[ id ],
                        hidden: false // This makes the error visible to the user
                    }
                });
                
                nuveiDestroySimplyConnect();
                
//                wp.data.dispatch( 'core/notices' ).createErrorNotice( 
//                    validationErrors[id].message,
//                    {
//                        id: 'nuvei-form-invalid', // Use a unique ID to prevent duplicates
//                        context: 'wc/checkout',  // Important: This tells Woo to show it in the checkout area
//                        isDismissible: true,
//                    } 
//                );
                
                return true;
            }
        });
        
        if (!isFormValid) {
            return false;
        }
        
        return true;
    }
    
    // show all errors
    let firsError = '';
    
    Object.keys( validationErrors ).forEach( ( id ) => {
        if ('' == firsError) {
            firsError = validationErrors[id].message;
        }
        
        dispatch( 'wc/store/validation' ).setValidationErrors( {
            [ id ]: {
                ...validationErrors[ id ],
                hidden: false // This makes the error visible to the user
            }
        });
    });
    
//    console.log('scroll to the msg');
    
    // and scroll to the message
    setTimeout( (msg) => {
        const noticeElement = document.querySelector( '.has-error' );
//        const noticeElement = document.querySelector( '.wc-block-components-notices' );
        
        if ( noticeElement ) {
//            if ('' != msg) {
//                dispatch( 'core/notices' ).createErrorNotice( 
//                    msg,
//                    {
//                        id: 'nuvei-form-invalid', // Use a unique ID to prevent duplicates
//                        context: 'wc/checkout',  // Important: This tells Woo to show it in the checkout area
//                        isDismissible: true,
//                    } 
//                );
//            }
            
            const elementPosition = noticeElement.getBoundingClientRect().top + window.pageYOffset;

            window.scrollTo( {
                top: elementPosition - 50,
                behavior: 'smooth'
            } );
        }
    }, 100, firsError ); // Short delay ensures the notice has rendered in the DOM
    
    return false;
}

/**
 * In this subscriber we try to handle the payment method check.
 */
function secondSubscriber() {
    const currentpaymentMethod = wp.data.select( 'wc/store/payment' ).getActivePaymentMethod();

//    console.log(currentpaymentMethod);
    
    // Catch changed Payment Method and Show/Hide the default payment button.
    try {
        // in case Nuvei is selected
        if (scTrans && scTrans.paymentGatewayName == currentpaymentMethod) {
            jQuery(nuveiCheckoutBlockPayBtn).not(nuveiCheckoutCustomPayBtn).hide();
            jQuery('#nuvei_checkout_container').show();
            nuveiShowCustomPayBtn();
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
                if (nuveiIsCheckoutBlocksFormValid(true)) {
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

    console.log('nuveiBlocksOptions was registered', scTrans.checkoutIntegration);
})();

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

    let lastTotal           = store.getCartTotals().total_price;
    let lastBillingEmail    = store.getCartData().billingAddress.email;
    let lastBillingCountry  = store.getCartData().billingAddress.country;

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

            const currentTotals         = store.getCartTotals ? store.getCartTotals().total_price : null;
            const currentBillingEmail   = store.getCartData().billingAddress.email;
            const currentBillingCountry = store.getCartData().billingAddress.country;

            // Totals have changed
            if (!nuveiIsPayForExistingOrderPage && currentTotals != lastTotal) {
                lastTotal = currentTotals;

                console.log('Cart totals changed:', currentTotals);

                nuveiDestroySimplyConnect();
                jQuery('#nuvei_checkout_container').html(window.wp.i18n.__('Loading...', 'nuvei-payments-for-woocommerce'));
                nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, 'id');
            }

            // Billing address changed
            if (!nuveiIsPayForExistingOrderPage) {
                if (currentBillingEmail !== lastBillingEmail
                    || currentBillingCountry !== lastBillingCountry
                ) {
                    lastBillingEmail = currentBillingEmail;
                    lastBillingCountry = currentBillingCountry;
                    
                    console.log('Billing address changed');
                    
                    nuveiDestroySimplyConnect();

                    if (nuveiIsCheckoutBlocksFormValid(true)) {
                        nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, 'id');
                        return;
                    }
                }
            }

        }, 1000);

        // Subscribe using the debounced handler
        wp.data.subscribe(debouncedHandler);
    }

    // subscribe from payment method changes
    wp.data.subscribe(secondSubscriber);
});