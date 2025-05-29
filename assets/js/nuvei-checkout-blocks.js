function nuveiBlockIntegration() {
    const nuveiSettings = window.wc.wcSettings.getSetting('nuvei_data', {});
    const nuveiLabel = window.wp.htmlEntities.decodeEntities(nuveiSettings.title)
        || window.wp.i18n.__('Nuvei', 'nuvei-payments-for-woocommerce');

    // Label component with optional icon
    const NuveiLabel = () => (
        nuveiSettings.icon
            ? window.wp.element.createElement(
                "span",
                { style: { display: 'flex', alignItems: 'center' } },
                window.wp.element.createElement(
                    "img",
                    {
                        src: nuveiSettings.icon,
                        alt: nuveiLabel,
                        style: { marginRight: 5 }
                    }
                ),
                nuveiLabel
            )
            : nuveiLabel
    );

    const Content = () => window.wp.htmlEntities.decodeEntities(nuveiSettings.description || '');

    const nuveiBlocksOptions = {
        name: 'nuvei',
        label: window.wp.element.createElement(NuveiLabel),
        content: window.wp.element.createElement(Content),
        edit: window.wp.element.createElement(Content),
        ariaLabel: nuveiLabel,
        placeOrderButtonLabel: window.wp.i18n.__('Continue', 'nuvei-payments-for-woocommerce'),
        canMakePayment: () => true,
        supports: {
            features: nuveiSettings.supports
        },
        submitPayment: ({billingData, shippingData, paymentData}) => {
            console.log('Submitting payment with data:');
            
            return {
                status: 'failure',
                message: 'Payment failed. Please check your details and try again.'
            };
            
            return Promise.reject( new Error( 'Sorry, your payment method failed – please try again.' ) );
        }
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod(nuveiBlocksOptions);
    window.nuveiCheckoutSdkParams = nuveiSettings.checkoutParams;
    
    console.log('is there a button', jQuery('button.wc-block-components-checkout-place-order-button').length);
}

nuveiBlockIntegration();
