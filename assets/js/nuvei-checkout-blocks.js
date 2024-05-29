function nuveiBlockIntegration() {
    console.log('nuvei checkout block');
    
    var nuveiSettings   = window.wc.wcSettings.getSetting( 'nuvei_data', {} );
    var nuveiLabel      = window.wp.htmlEntities.decodeEntities( nuveiSettings.title )
        || window.wp.i18n.__('Nuvei', 'nuvei-checkout-for-woocommerce');
    var label           = nuveiLabel;
    
    // eventualy add an icon
    if (nuveiSettings.icon) {
        label = wp.element.createElement(() =>
            wp.element.createElement(
                "span",
                {
                    style: {
                        display: 'flex'
                    }
                },
                wp.element.createElement(
                    "img",
                    {
                        src: nuveiSettings.icon,
                        alt: nuveiLabel,
                        style: {
                            marginRight: 5
                        }
                    }
                ),
                "  " + nuveiLabel
            )
        );
    }
    
    var Content = () => {
        return window.wp.htmlEntities.decodeEntities( nuveiSettings.description || '' );
    };

    const nuveiBlocksOptions = {
        name: 'nuvei', // the gateway ID
        label: label,
        content: Object( window.wp.element.createElement )( Content, null ),
        edit: Object( window.wp.element.createElement )( Content, null ),
        canMakePayment: () => true,
        ariaLabel: nuveiLabel,
        placeOrderButtonLabel: window.wp.i18n.__('Continue', 'nuvei-checkout-for-woocommerce'),
        supports: {
            features: nuveiSettings.supports
        }
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod( nuveiBlocksOptions );
    
    window.nuveiCheckoutSdkParams = nuveiSettings.checkoutParams; // set SDK params
}

nuveiBlockIntegration();