const nuveiCheckoutBlockPayBtn      = '.wc-block-components-checkout-place-order-button';
const nuveiCheckoutBlockPMethodName = 'input[name="radio-control-wc-payment-method-options"]';

const nuveiFormNotInvalidTxt = window.wp.i18n.__(
    'Loading...',
    'nuvei-payments-for-woocommerce'
);

const nuveiCheckoutBlockContText =
    ( typeof window.scTrans !== 'undefined' && 'sdk' === window.scTrans?.checkoutIntegration ) ? nuveiFormNotInvalidTxt :
        window.wp.i18n.__('You will be redirected to Nuvei secure payment page.', 'nuvei-payments-for-woocommerce');

var nuveiAllowFormSubmit    = false;
// must be outside the function so clearTimeout actually debounces
var nuveiBlocksReloadTimer  = null;

/**
 * We use pre-payment for the Blocks only.
 * We call this method from nuvei_public.js
 *
 * @param {object} paymentDetails
 * @returns {Promise}
 */
function nuveiPrePaymentBlocks(paymentDetails) {
	console.log('nuveiPrePaymentBlocks');

	return new Promise((resolve, reject) => {
        // check for recaptch
        if (jQuery('#g-recaptcha-response').length && '' == jQuery('#g-recaptcha-response').val()) {
            nuveiShowErrorMsg(scTrans.CaptchaError);
            reject();
            return;
        }
        
        // check the form
        if ( ! nuveiIsCheckoutBlocksFormValid() ) {
            reject();
            return;
        }

        // Wallet flow: prePayment fires before onPaymentSetup.
        // After validation, resolve prePayment then trigger the WC Pay button so
        // onPaymentSetup can set up nuveiBlocksResolvePayment and await onResult.
        if ( nuveiWallets.indexOf(nuveiSelectedPaymentMethod) >= 0 ) {
            nuveiUpdateOrder(
                function() {
                    nuveiWalletInProgress = true;
                    resolve();
                    jQuery(nuveiCheckoutBlockPayBtn).trigger('click');
                },
                reject
            );
            return;
        }

        // Default flow: onPaymentSetup already called nuveiBlocksRunTransaction()
        // which set nuveiBlocksResolvePayment before calling submitPayment().
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

                // just break the loop
                return true;
            }
        });

        if (!isFormValid) {
            jQuery('#nuvei_checkout_container').text(scTrans.MissingEmailCountry);
        }

        return isFormValid;
    }

    // count all errors and set messages to be visible
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

    if (realFormErrors > 0) {
        isFormValid = false;
    }

    // now check if the Simply Connect form is valid
    if (typeof nuveiCheckoutSdkParams != 'undefined' && !nuveiIsSimplyFormValid) {
        wp.data.dispatch( 'core/notices' ).createErrorNotice(
            scTrans.MissingRequiredFields,
            {
                id: 'nuvei-form-invalid', // Use a unique ID to prevent duplicates
                context: 'wc/checkout',  // Important: This tells Woo to show it in the checkout area
                isDismissible: true,
            }
        );

        isFormValid = false;
        
        // call this just to scroll to the problem - only if the SDK is really ready,
        // deferring it (nuveiSubmitPaymentWhenReady) makes no sense on an invalid form
        if ( nuveiIsSimplyReady() ) {
            simplyConnect.submitPayment();
        }

        jQuery('#nuvei_blocker').hide();
        
        return isFormValid;
    }

    // and scroll to the message
    setTimeout( () => {
        let noticeElement;

        if ( jQuery( '.has-error' ).length ) {
            noticeElement = jQuery( '.has-error' );
        }
        else if ( jQuery( '.wc-block-components-notice-banner.is-error' ).length ) {
            noticeElement = jQuery( '.wc-block-components-notice-banner.is-error' );
        }

        // show error if any
        if ( noticeElement?.length ) {
            const elementPosition = noticeElement.offset().top;

            window.scrollTo( {
                top: elementPosition - 50,
                behavior: 'smooth'
            } );
        }

    }, 200 ); // Short delay ensures the notice has rendered in the DOM

    if (!isFormValid) {
        jQuery('#nuvei_blocker').hide();
    }

    return isFormValid;
}

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

    jQuery('#nuvei_checkout_container').html(window.wp.i18n.__('Loading...', 'nuvei-payments-for-woocommerce'));

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

/**
 * Integrate Nuvei payment option and button in the Blocks Chckout.
 */
(function() {
    console.log('auto func');

    const { useEffect, createElement }  = window.wp.element;
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

                jQuery('#nuvei_blocker').show();

                // For redirect/cashier mode - just let WooCommerce proceed
                if ( 'sdk' !== scTrans?.checkoutIntegration ) {
                    // check if form is invalid, just to hide the blocker
                    nuveiIsCheckoutBlocksFormValid();

                    return {
                        type: emitResponse.responseTypes.SUCCESS
                    };
                }

                // Wallet flow: prePayment already ran validations and nuveiUpdateOrder.
                // Just set up the resolver and wait for onResult to call it.
                if ( nuveiWalletInProgress ) {
                    nuveiWalletInProgress = false;

                    const payment = await new Promise(res => { nuveiBlocksResolvePayment = res; });

                    if ( !payment.success ) {
                        if ( !nuveiBlocksRefreshInProgress ) {
                            jQuery('#nuvei_blocker').hide();
                        }

                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: payment.error || scTrans.paymentDeclined
                        };
                    }

                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                _nuveiTrId: payment.transaction_id
                            }
                        }
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
                    if ( !nuveiBlocksRefreshInProgress ) {
                        jQuery('#nuvei_blocker').hide();
                    }

                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: payment.error || scTrans.paymentDeclined
                    };
                }

                // Step 3: approved - continue the proccess
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            _nuveiTrId: payment.transaction_id,
                            _nuveiPm: nuveiSelectedPaymentMethod
                        }
                    }
                };
            } );

            // Cleanup on unmount
            return unsubscribe;

        }, [onPaymentSetup]);
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

    try {
        console.log('nuveiBlocksOptions was registered', scTrans.checkoutIntegration);
    } catch(e) {};

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

    // append a blocker
    if ( typeof scTrans != 'undefined' && jQuery('#payment-method').length ) {
        jQuery('#payment-method')
            .parent('form')
            .append('<div id="nuvei_blocker"><img class="nuvei_loader" src="'
                + scTrans.loaderUrl + '" /></div>');
    }

    if ( window.scTrans && 'sdk' !== scTrans?.checkoutIntegration ) {
        return;
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
        if (window.nuveiIsPayForExistingOrderPage || jQuery('#nuvei_checkout_container').length == 0) {
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