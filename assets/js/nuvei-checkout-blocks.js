const nuveiCheckoutBlockFormClass   = 'form.wc-block-components-form';
const nuveiCheckoutBlockPayBtn      = '.wc-block-components-checkout-place-order-button';
const nuveiCheckoutBlockPMethodName = 'input[name="radio-control-wc-payment-method-options"]';
const nuveiCheckoutBlockContText    = 'sdk' === scTrans.checkoutIntegration ? 
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
        
        if (simplyConnect && simplyConnect.hasOwnProperty('destroy')) {
            simplyConnect.destroy();
        }
        
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
            
            // try to validate the form on checkout page load
            if (nuveiIsCheckoutBlocksFormValid()) {
                nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, nuveiCheckoutBlockPayBtn);
            }
        }, []);
    
        return window.wp.element.createElement(
            'div',
            { id: 'nuvei_checkout_container' },
            nuveiCheckoutBlockContText
        );
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
    // when page loaded hide the default payment button if Nuvei is select as payment provider
    if ('nuvei' === jQuery(nuveiCheckoutBlockPMethodName + ':checked').val()) {
        jQuery(nuveiCheckoutBlockPayBtn).hide();
    }
    
    // try to validate the checkout form on each field change
    jQuery(nuveiCheckoutBlockFormClass).on('change', 'input[id^="billing-"], input[id^="shipping-"], select[id^="billing-"], select[id^="shipping-"]', function() {
        console.log('some of the checkout fileds was changed, check the form');
        
        setTimeout(() => {
            if (nuveiIsCheckoutBlocksFormValid()) {
                nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, nuveiCheckoutBlockPayBtn);
            }
        }, 1000);
    });
    
    // Show/Hide the default payment button when change the payment provider
    jQuery(nuveiCheckoutBlockPMethodName).on('change', function() {
        if ('nuvei' !== jQuery(nuveiCheckoutBlockPMethodName + ':checked').val()) {
            jQuery(nuveiCheckoutBlockPayBtn).show();
        }
        else {
            jQuery(nuveiCheckoutBlockPayBtn).hide();
        }
    });
    
    // WP Blocks subscriber
    let lastTotal = wp.data.select('wc/store/cart').getCartTotals().total_price;
    
    wp.data.subscribe(() => {
        if (jQuery('#nuvei_checkout_container').length == 0) {
            return;
        }
        
        // subscribe to price change
        const store         = wp.data.select('wc/store/cart');
        const currentTotals = store.getCartTotals ? store.getCartTotals().total_price : null;
        
        // Totals have changed
        if (currentTotals != lastTotal) {
            lastTotal = currentTotals;
            
            console.log('Cart totals changed:', currentTotals);
            
            simplyConnect.destroy();
            jQuery('#nuvei_checkout_container').html(window.wp.i18n.__('Loading...', 'nuvei-payments-for-woocommerce'));
            nuveiGetCheckoutData(nuveiCheckoutBlockFormClass, nuveiCheckoutBlockPayBtn);
        }
    });
});
