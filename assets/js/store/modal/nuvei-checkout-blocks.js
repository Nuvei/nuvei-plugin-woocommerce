const nuveiCheckoutBlockPayBtn      = '.wc-block-components-checkout-place-order-button';

/**
 * We use pre-payment for the Blocks only.
 * We call this method from nuvei_public.js
 *
 * @param {object} paymentDetails
 * @returns {Promise}
 */
function nuveiPrePaymentBlocks(paymentDetails) {
	console.log('[Nuvei]: nuveiPrePaymentBlocks');

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
function nuveiIsCheckoutBlocksFormValid() {
    console.log('[Nuvei]: call nuveiIsCheckoutBlocksFormValid');

    const { validationStore }   = window.wc.wcBlocksData;
    const noticesStore          = window.wc.wcBlocksData.STORE_NOTICES_STORE_KEY;
    const { dispatch }          = wp.data;
    const validationErrors      = wp.data.select( 'wc/store/validation' ).getValidationErrors();

    let isFormValid     = true;
    let realFormErrors  = 0;

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
 * Integrate Nuvei payment option and button in the Blocks Chckout.
 */
(function() {
    console.log('[Nuvei]: auto func');

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
        const { onPaymentSetup, onCheckoutFail } = eventRegistration;

        // if the overall checkout ultimately fails on WC's side after we
        // already returned SUCCESS (transaction was already approved by
        // Nuvei by then), make sure the full-page blocker doesn't stay
        // stuck forever.
        useEffect(() => {
            const unsubscribe = onCheckoutFail( function() {
                console.log('[Nuvei]: onCheckoutFail - hide the blocker.');

                jQuery('#nuvei_blocker').hide();

                return {
                    type: emitResponse.responseTypes.SUCCESS
                };
            } );

            return unsubscribe;
        }, [onCheckoutFail]);

        // subscribe for place order event
        useEffect(() => {
            const unsubscribe = onPaymentSetup( async function() {
                console.log('[Nuvei]: onPaymentSetup logic');

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
                                _nuveiTrId: payment.transaction_id,
                                _nuveiPm: nuveiSelectedPaymentMethod
                            }
                        }
                    };
                }

                // Step 1: WC Blocks already blocks onPaymentSetup from firing
                // when its own fields are invalid, and nuveiIsSimplyFormValid
                // is meaningless here since the SDK form doesn't exist yet -
                // it only renders once the modal opens, below.

                // Step 2: run transaction against Order ID
                const payment = await nuveiBlocksRunModalTransaction();

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
        console.log('[Nuvei]: nuveiBlocksOptions was registered', scTrans.checkoutIntegration);
    } catch(e) {};

})();

jQuery(function() {
    console.log('[Nuvei]: jquery func');

    // Prevent running in WP admin area
    if (typeof window.wp !== 'undefined'
        && window.wp.data
        && window.location
        && window.location.pathname.indexOf('/wp-admin/') !== -1
    ) {
        // In admin, do not run checkout JS
        return;
    }

    console.log('[Nuvei]: document ready blocks checkout');

    // append a blocker
    if ( typeof scTrans != 'undefined' && jQuery('#payment-method').length ) {
        jQuery('#payment-method')
            .parent('form')
            .append('<div id="nuvei_blocker"><img class="nuvei_loader" src="'
                + scTrans.loaderUrl + '" /></div>');
    }

    // mode-specific logic (modal overlay build/close, or field/cart
    // watchers) lives in blocks/nuvei-modal.js / blocks/nuvei-container.js,
    // loaded right after this file based on the "render_to" setting.
});
// document ready function end