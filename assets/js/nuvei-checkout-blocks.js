//import React from 'react';
//import { useSelect } from "rooks";

function nuveiBlockIntegration() {
//    const { VALIDATION_STORE_KEY } = window.wc.wcBlocksData;
//    const store = select( VALIDATION_STORE_KEY );
//    const hasValidationErrors = store.hasValidationErrors();
//
//const { validationError, validationErrorId } = useSelect( ( select ) => {
//		const store = select( VALIDATION_STORE_KEY );
//		return {
//			validationError: store.getValidationError( propertyName ),
//			validationErrorId: store.getValidationErrorId( elementId ),
//		};
//	} );
//    
    
    var nuveiSettings   = window.wc.wcSettings.getSetting( 'nuvei_data', {} );
    var nuveiLabel      = window.wp.htmlEntities.decodeEntities( nuveiSettings.title )
        || window.wp.i18n.__('Nuvei', 'nuvei-payments-for-woocommerce');
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
        placeOrderButtonLabel: window.wp.i18n.__('Continue', 'nuvei-payments-for-woocommerce'),
        supports: {
            features: nuveiSettings.supports
        }
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod( nuveiBlocksOptions );
    
    window.nuveiCheckoutSdkParams = nuveiSettings.checkoutParams; // set SDK params
}

nuveiBlockIntegration();