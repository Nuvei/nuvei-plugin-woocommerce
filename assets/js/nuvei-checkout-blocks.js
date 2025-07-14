function nuveiBlockIntegration() {
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

    const Content = () => window.wp.htmlEntities.decodeEntities(nuveiSettings.description || '');

    const nuveiBlocksOptions = {
        name: 'nuvei',
        label: label,
        content: Object( window.wp.element.createElement )( Content, null ),
        edit: Object( window.wp.element.createElement )( Content, null ),
        ariaLabel: nuveiLabel,
        placeOrderButtonLabel: window.wp.i18n.__('Continue', 'nuvei-payments-for-woocommerce'),
        canMakePayment: () => true
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod(nuveiBlocksOptions);
    window.nuveiCheckoutSdkParams = nuveiSettings.checkoutParams;
}

nuveiBlockIntegration();
