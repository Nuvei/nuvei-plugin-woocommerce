
let gPayPayload = {
}
let isGpayLoaded = false;
let googlePaymentClient;

let sendMessage = function (eventType, data) {
    parent.window.postMessage({event: eventType, payload: data, origin: document.location.origin}, document.referrer);
};

let initGpay = (config, environment) => {

    let googlePayButton = window.document.getElementById('nuvei-gpay-frame-button');

    let paymentOptions = {
        environment,
        merchantInfo: {
            merchantName: config.apmConfig.googlePay.merchantName,
            merchantId: config.apmConfig.googlePay.merchantId
        }
    }

    googlePaymentClient = new window.google.payments.api.PaymentsClient(paymentOptions);

    googlePaymentClient.isReadyToPay({
        apiVersion: 2,
        apiVersionMinor: 0,
        merchantInfo: {
            merchantName: config.apmConfig.googlePay.merchantName,
            merchantId: config.apmConfig.googlePay.merchantId
        },

        shippingAddressRequired: config.apmConfig?.googlePay?.collectUserDetails,
        shippingAddressParameters: {
            phoneNumberRequired: false
        },

        allowedPaymentMethods: [{
            type: "CARD",
            parameters: {
                allowedAuthMethods: config.apmConfig.googlePay.allowedCardAuthMethods,
                allowedCardNetworks: config.apmConfig.googlePay.allowedCardNetworks,
                allowCreditCards: config.apmConfig.googlePay.allowCreditCards,
                allowPrepaidCards: config.apmConfig.googlePay.allowPrepaidCards,
                assuranceDetailsRequired: config.apmConfig.googlePay.assuranceDetailsRequired,
                billingAddressRequired: config.apmConfig?.googlePay?.collectUserDetails,
                billingAddressParameters: {
                    format: 'FULL',
                    phoneNumberRequired: false
                }
            },
            tokenizationSpecification: {
                type: config.apmConfig.googlePay.tokenizationType,
                parameters: {
                    gateway: config.apmConfig.googlePay.gateway,
                    gatewayMerchantId: config.apmConfig.googlePay.gatewayMerchantId
                }
            }
        }]
    }).then((response) => {
        if (response.result) {
            let buttonLocale = config.apmConfig?.googlePay?.locale || 'en';
            let button = googlePaymentClient.createButton({
                onClick: buttonHandler,
                buttonColor: config.apmConfig.googlePay.buttonColor,
                buttonType: config.apmConfig.googlePay.buttonType,
                buttonLocale, buttonSizeMode: 'fill' });

            googlePayButton.appendChild(button);
        }
        isGpayLoaded = true

        sendMessage('nuvei-Gpay-iframe-size', {height: window.document.body.scrollHeight + 8})
    });
}

let buttonHandler = function() {

    sendMessage('nuvei-Gpay-start-payment', {startPayment: true})

    let {config, currencyCode, totalPrice, environment} = gPayPayload
    let payloadGpay = {
        apiVersion: 2,
        apiVersionMinor: 0,
        environment: environment,
        merchantInfo: {
            merchantName: config.apmConfig.googlePay.merchantName,
            merchantId: config.apmConfig.googlePay.merchantId
        },
        shippingAddressRequired: config.apmConfig?.googlePay?.collectUserDetails,
        shippingAddressParameters: {
            phoneNumberRequired: false
        },
        allowedPaymentMethods: [{
            type: "CARD",
            parameters: {
                allowedAuthMethods: config.apmConfig.googlePay.allowedCardAuthMethods,
                allowedCardNetworks: config.apmConfig.googlePay.allowedCardNetworks,
                allowCreditCards: config.apmConfig.googlePay.allowCreditCards,
                allowPrepaidCards: config.apmConfig.googlePay.allowPrepaidCards,
                assuranceDetailsRequired: config.apmConfig.googlePay.assuranceDetailsRequired,
                billingAddressRequired: config.apmConfig?.googlePay?.collectUserDetails,
                billingAddressParameters: {
                    format: 'FULL',
                    phoneNumberRequired: false
                }
            },
            tokenizationSpecification: {
                type: config.apmConfig.googlePay.tokenizationType,
                parameters: {
                    gateway: config.apmConfig.googlePay.gateway,
                    gatewayMerchantId: config.apmConfig.googlePay.gatewayMerchantId
                }
            }
        }],
        transactionInfo: {
            countryCode: config.country,
            currencyCode: currencyCode,
            totalPriceStatus: config.apmConfig.googlePay.totalPriceStatus,
            totalPrice: totalPrice,
            checkoutOption: config.apmConfig.googlePay.checkoutOption
        }
    }

     googlePaymentClient.loadPaymentData(payloadGpay).then((paymentData) => {
        let paymentPayload = {
            paymentOption: {
                card: {
                    externalToken: {
                        externalTokenProvider: "GooglePay",
                        mobileToken: JSON.stringify(paymentData.paymentMethodData)
                    }
                }
            },
            paymentData: paymentData
        }

        sendMessage('nuvei-Gpay-send-token', paymentPayload)

    }).catch( (err) => {
        sendMessage('nuvei-Gpay-setDialog', {setDialog: ''})
    });

}

let initGooglePay = (event) => {

    if ( event.data.type !== 'nuvei-google-pay') {
        return;
    }
    if (event.data.event === 'nuvei-Gpay-init') {


        const gPayJs = Object.assign(document.createElement("script"), {
            type: "text/javascript",
            defer: true,
            src: 'https://pay.google.com/gp/p/js/pay.js'
        });


        let config = event.data.payload.config;
        let currencyCode = event.data.payload.currencyCode;
        let totalPrice = event.data.payload.totalPrice;
        let environment = event.data.payload.environment;

        gPayPayload = {
            config,
            currencyCode,
            totalPrice,
            environment
        }

        gPayJs.onload = () => {
            initGpay(config, environment)
        };

        document.head.appendChild(gPayJs);
    }
}

let sendPayment = (event) => {
        if (event.data.event === 'nuvei-Gpay-send-payment') {
            buttonHandler();
        }
}

let setDCCDetails = (event) => {
    if (event.data.event === 'nuvei-Gpay-set-dcc-details') {
        gPayPayload = event.data.payload
    }
}

window.addEventListener('message', setDCCDetails, false);
window.addEventListener('message', sendPayment, false);
window.addEventListener('message', initGooglePay, false);




