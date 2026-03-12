const nuveiCheckoutBlockPayBtn      = '.wc-block-components-checkout-place-order-button';
const nuveiCheckoutBlockPMethodName = 'input[name="radio-control-wc-payment-method-options"]';

const nuveiFormNotInvalidTxt = window.wp.i18n.__(
    'Loading...',
    'nuvei-payments-for-woocommerce'
);

const nuveiCheckoutBlockContText =
    (typeof scTrans == 'object'
        && scTrans.hasOwnProperty('checkoutIntegration')
        && 'sdk' === scTrans.checkoutIntegration
    ) ? nuveiFormNotInvalidTxt :
            window.wp.i18n.__('You will be redirected to Nuvei secure payment page.', 'nuvei-payments-for-woocommerce');

var nuveiAllowFormSubmit        = false;

/**
 * We use pre-payment for the Blocks only.
 * We call this method from nuvei_public.js
 *
 * @param {object} paymentDetails
 * @returns {Promise}
 */
function nuveiPrePayment(paymentDetails) {
	console.log('nuveiPrePayment');

	return new Promise((resolve, reject) => {
        // check for recaptch
        if (jQuery('#g-recaptcha-response').length && '' == jQuery('#g-recaptcha-response').val()) {
            nuveiShowErrorMsg(scTrans.CaptchaError);
            reject();
            return;
        }

        // Update the Order
        nuveiUpdateOrder(resolve, reject);
        return;
	});
}

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
    let realFormErrors  = 0;

    // no errors
    if (Object.keys( validationErrors ).length == 0) {
        return isFormValid;
    }

    // Minimal check, when need only the country and the email.
    if ( justLoadSimply ) {
        console.log('call nuveiIsCheckoutBlocksFormValid justLoadSimply');

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
            jQuery('#nuvei_checkout_container').text(scTrans.MissingEmailCountry);
            return false;
        }

        return true;
    }

    // show all errors
    Object.keys( validationErrors ).forEach( ( id ) => {
        // skip Nuvei custom orders.
        if ( id.search('nuvei') < 0 ) {
            dispatch( 'wc/store/validation' ).setValidationErrors( {
                [ id ]: {
                    ...validationErrors[ id ],
                    hidden: false // This makes the error visible to the user
                }
            });

            realFormErrors++;
        }
    });

    if (realFormErrors == 0) {
        return true;
    }

    // and scroll to the message
    setTimeout( () => {
        const noticeElement = document.querySelector( '.has-error' );

        if ( noticeElement ) {
            const elementPosition = noticeElement.getBoundingClientRect().top + window.pageYOffset;

            window.scrollTo( {
                top: elementPosition - 50,
                behavior: 'smooth'
            } );

            return false;
        }

        return true;
    }, 100 ); // Short delay ensures the notice has rendered in the DOM
}

/**
 * Just reusing some code.
 */
function nuveiBlocksReloadSimply() {
    let reloadTimer = null;

    jQuery('#nuvei_blocker').show();

    nuveiDestroySimplyConnect();

    jQuery('#nuvei_checkout_container').html(window.wp.i18n.__('Loading...', 'nuvei-payments-for-woocommerce'));

    if (nuveiIsCheckoutBlocksFormValid(true)) {
        // add small delay
        clearTimeout( reloadTimer );

        reloadTimer = setTimeout( function() {
            nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, 'id');
            jQuery('#nuvei_blocker').hide();
            return;
        }, 600 );
    }
}

async function nuveiBlocksRunTransaction() {
    return new Promise( function( resolve ) {
        nuveiBlocksResolvePayment = resolve; // set the resolver
        simplyConnect.submitPayment();
    } );
}

/**
 * Integrate Nuvei payment option and button in the Blocks Chckout.
 */
(function() {
    console.log('auto func');

    const { useEffect, createElement } = window.wp.element;
    const { useSelect }                 = window.wp.data;

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

    const Content = (props) => {
        const { eventRegistration, emitResponse } = props;
        const { onPaymentSetup } = eventRegistration;

        // only on the first load
        useEffect(() => {
            console.log('Nuvei payment method element loaded. Check if the checkout form is valid.');

            // Append the origial Simply Connect container, in all cases, just for the message.
            if (jQuery('#payment-method').find('#nuvei_checkout_container').length == 0) {
                jQuery('#radio-control-wc-payment-method-options-nuvei')
                    .closest('.wc-block-components-radio-control-accordion-option')
                    .append(`<div id="nuvei_checkout_container" data-placeholder="${nuveiCheckoutBlockContText}"></div>`);
            }

            if ('sdk' === scTrans?.checkoutIntegration
                && nuveiIsCheckoutBlocksFormValid(true)
            ) {
                nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, 'id');
            }
        }, []);

        // subscribe for place order event
        useEffect(() => {
            const unsubscribe = onPaymentSetup( async function() {
                console.log('onPaymentSetup logic');
                
                // For redirect/cashier mode - just let WooCommerce proceed
                if ( 'sdk' !== scTrans?.checkoutIntegration ) {
                    return {
                        type: emitResponse.responseTypes.SUCCESS
                    };
                }
                
                // Step 1: validate your SDK fields
                if ( !nuveiIsCheckoutBlocksFormValid() ) {
                    return {
                        type: emitResponse.responseTypes.ERROR
                    };
                }

                // Step 2: run transaction against Order ID
                const payment = await nuveiBlocksRunTransaction();

                if ( !payment.success ) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: payment.error || 'Payment declined, please try again.'
                    };
                }

                // Step 3: approved - continue the proccess
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            _nuveiTrId: payment.transaction_id
                        }
                    }
                };
            } );

            // Cleanup on unmount
            return unsubscribe;

            // Cleanup: do nothing (no destroy here)
//            return () => { };
        }, [onPaymentSetup]);

//        return window.wp.element.createElement(
//            'div',
//            { id: 'nuvei_checkout_container' },
//            ''
//        );
    };

    const nuveiBlocksOptions = {
        name: 'nuvei',
        label: label,
        content: createElement( Content, null ),
        edit: createElement( Content, null ),
        ariaLabel: nuveiLabel,
        canMakePayment: () => true
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod(nuveiBlocksOptions);
    window.nuveiCheckoutSdkParams = nuveiSettings.checkoutParams;

    console.log('nuveiBlocksOptions was registered', scTrans.checkoutIntegration);

})();

jQuery(function() {
    console.log('jquery func');

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

    // watch the email field for changes
    let lastEmail = document.getElementById('email')?.value;

    jQuery( document.body ).on( 'blur', '#email:not(#nuvei_checkout_container #email)', function(e) {
        let self = jQuery(this);

        // Check if the value has actually changed
        if (self.val() !== lastEmail) {
            console.log('mail was changed', lastEmail, self.val())

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
        if (nuveiIsPayForExistingOrderPage || jQuery('#nuvei_checkout_container').length == 0) {
            return;
        }

        // Do not check the totals and billing address if Nuvei is not selected
        const currentpaymentMethod = wp.data.select( 'wc/store/payment' ).getActivePaymentMethod();

        if (scTrans && scTrans.paymentGatewayName !== currentpaymentMethod) {
            console.log('The selected payment method is not Nuvei.');
            jQuery('#nuvei_checkout_container').hide();
            return;
        }

        jQuery('#nuvei_checkout_container').show();

        const currentTotals         = store.getCartTotals ? store.getCartTotals().total_price : null;
        const currentBillingCountry = store.getCartData().billingAddress.country;

        // check for changes
        if (currentTotals != lastTotal
            || currentBillingCountry !== lastBillingCountry
        ) {
            console.log('Checkout changed:', {
                'is total changed': currentTotals != lastTotal,
                'is country changed': lastBillingCountry  != currentBillingCountry,
            });

            lastTotal           = currentTotals;
            lastBillingCountry  = currentBillingCountry;

            nuveiBlocksReloadSimply();
        }

    });

});
// document ready function end