const nuveiCheckoutBlockFormClass   = 'form.wc-block-components-form';
const nuveiCheckoutBlockPayBtn      = '.wc-block-components-checkout-place-order-button';
const nuveiCheckoutBlockPMethodName = 'input[name="radio-control-wc-payment-method-options"]';
const nuveiCheckoutBlockContText    = (typeof scTrans == 'object'
    && scTrans.hasOwnProperty('checkoutIntegration')
    && 'sdk' === scTrans.checkoutIntegration) ?
        window.wp.i18n.__('The Checkout form must be valid to continue!', 'nuvei-payments-for-woocommerce') :
            window.wp.i18n.__('You will be redirected to Nuvei secure payment page.', 'nuvei-payments-for-woocommerce');

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

        jQuery('#nuvei_checkout_container').html(nuveiCheckoutBlockContText);
        return false;
    }

   let isFormValid = true;

   jQuery(nuveiCheckoutBlockFormClass).find('input, select, textarea').each( function() {
       let self = jQuery(this);

       if (self.attr('aria-errormessage')
           || self.closest('div').hasClass('has-error')
           || jQuery(nuveiCheckoutBlockFormClass).find('.wc-block-store-notice.is-error').length > 0
       ) {
           console.log('the element is not valid', self.attr('id'));

           isFormValid = false;
           return false;
       }
   });

   return isFormValid;
}

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
                // when the element is loaded, hide the default payment button if Nuvei is selected
                if ('nuvei' == jQuery(nuveiCheckoutBlockPMethodName + ':checked').val()) {
                    jQuery(nuveiCheckoutBlockPayBtn).hide();
                }
                
                // append the origial Simply Connect container
                if (jQuery('#payment-method').find('#nuvei_checkout_container').length == 0) {
                    jQuery('#radio-control-wc-payment-method-options-nuvei')
                        .closest('.wc-block-components-radio-control-accordion-option')
                        .append('<div id="nuvei_checkout_container"></div>');
                }

                // try to validate the form on checkout page load
                if (nuveiIsCheckoutBlocksFormValid()) {
                    nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, nuveiCheckoutBlockPayBtn);
                }
            }

            // Cleanup: do nothing (no destroy here)
//            return () => { };
        }, []);

//        return window.wp.element.createElement(
//            'div',
//            { id: 'nuvei_checkout_container' },
//            nuveiCheckoutBlockContText
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
    
    // Show/Hide the default payment button when change the payment provider.
    jQuery(document).on('change', nuveiCheckoutBlockPMethodName, function(e) {
        if ('nuvei' != jQuery(nuveiCheckoutBlockPMethodName + ':checked').val()) {
            simplyConnect.destroy();
            jQuery(nuveiCheckoutBlockPayBtn).show();
        }
        else {
            jQuery(nuveiCheckoutBlockPayBtn).hide();
        }
    });

    // WP Blocks subscriber
    const store         = wp.data.select('wc/store/cart');

    let lastTotal       = store.getCartTotals().total_price;
    let lastBilling     = JSON.stringify(store.getCartData().billingAddress);
    let lastShipping    = JSON.stringify(store.getCartData().shippingAddress);

    // subscribe to change events
    wp.data.subscribe(() => {
        if (jQuery('#nuvei_checkout_container').length == 0) {
            return;
        }

        const currentTotals     = store.getCartTotals ? store.getCartTotals().total_price : null;
        const currentBilling    = JSON.stringify(store.getCartData().billingAddress);
        const currentShipping   = JSON.stringify(store.getCartData().shippingAddress);

        // Totals have changed
        if (currentTotals != lastTotal) {
            lastTotal = currentTotals;

            console.log('Cart totals changed:', currentTotals);

            simplyConnect.destroy();

            jQuery('#nuvei_checkout_container').html(window.wp.i18n.__('Loading...', 'nuvei-payments-for-woocommerce'));
            nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, nuveiCheckoutBlockPayBtn);
        }

        // Billing address changed
        if (currentBilling !== lastBilling) {
            lastBilling = currentBilling;
            console.log('Billing address changed');

            if (nuveiIsCheckoutBlocksFormValid()) {
                nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, nuveiCheckoutBlockPayBtn);
            }
        }

        // Shipping address changed
        if (currentShipping !== lastShipping) {
            lastShipping = currentShipping;
            console.log('Shipping address changed');

            if (nuveiIsCheckoutBlocksFormValid()) {
                nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, nuveiCheckoutBlockPayBtn);
            }
        }
    });

});
