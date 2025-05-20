var nuveiCheckoutSdkParams      = {};
var nuveiCheckoutImplementation = {}; // shortcode, blocks or order-pay
var nuveiIsCheckoutLoaded       = false;

/**
 * An object to holds in it checkout-blocks methods.
 * 
 * @type object
 */
var nuveiCheckoutBlocks = {
    /**
     * A method to insert into the checkout custom html components.
     * 
     * @returns void
     */
    prepareNuveiComponents: function() {
        console.log('checkout blocks');
    
        try {
            console.log(jQuery('body #nuvei_checkout_container').length);
            
            // if #nuvei_checkout_container does not exists - create it.
            if(jQuery('body #nuvei_checkout_container').length == 0) {
                jQuery('div.wp-block-woocommerce-checkout')
                    .append(
                        '<div id="nuvei_checkout_container" style="display: none;">'
                            + '<img class="nuvei_loader" src="' + scTrans.loaderUrl + '" />'
                        + '</div>'
                    );
            }

            // if nuvei inputs does not exists - creat them
            if(jQuery('form.wc-block-components-form #nuvei_transaction_id').length == 0) {
                jQuery('form.wc-block-components-form')
                    .append('<input id="nuvei_transaction_id" type="hidden" name="nuvei_transaction_id" value="" />');
            }
            
            if(jQuery('form.wc-block-components-form #nuvei_session_token').length == 0) {
                jQuery('form.wc-block-components-form')
                    .append('<input id="nuvei_session_token" type="hidden" name="nuvei_session_token" value="" />');
            }
        }
        catch(_e) {
            console.log('WC blocks logic fail.');
        }
    },
    
    /**
     * A method to modify "Place Order" button for our needs.
     * 
     * @returns void
     */
    changePaymentBtn: function() {
        var blockPlaceOrderBtn = jQuery('button.wc-block-components-checkout-place-order-button');
    
        if (scTrans
            && scTrans.hasOwnProperty('checkoutIntegration')
            && 'sdk' == scTrans.checkoutIntegration
            && blockPlaceOrderBtn.length > 0
            && blockPlaceOrderBtn.attr('data-nuvei') != 'marked'
        ) {
            blockPlaceOrderBtn.attr('data-nuvei', 'marked');
            blockPlaceOrderBtn.on('click', function(e) {
                var nuveiSelectedBlockPm    = jQuery('input[name=radio-control-wc-payment-method-options]:checked').val();
                var continueDefaultFlow     = false;
                
                if (scTrans.paymentGatewayName != nuveiSelectedBlockPm) {
                    return;
                }
                
                // check form inputs
                jQuery('form.wc-block-components-form').find('input').each(function() {
                    var self = jQuery(this);
                    
                    if (typeof self.attr('required') != 'undefined'
                        && '' == self.val()
                    ) {
                        continueDefaultFlow = true;
                        return false;
                    }
                });
                // this will trigger default form check and will mark if some fields are missing
                if (continueDefaultFlow) {
                    return;
                }
                
                // here we stop default click event and run Nuvei methods.
                if ('' == jQuery('#nuvei_transaction_id').val()
                    && jQuery('.has-error').length == 0
                ) {
                    e.stopImmediatePropagation();
                    nuveiCheckoutBlocks.getCheckoutData();
                    return;
                }
                
                // continue with default flow
            });
        }
    },
    
    getCheckoutData: function() {
        console.log('getCheckoutData');
        
        var scFormData = {};
        
        console.log(jQuery('.wc-block-components-form input').length);
        
        jQuery('.wc-block-components-form input').each(function(){
            var _self = jQuery(this);
            
            if (_self.attr('id') && '' !=  _self.val()) {
                scFormData[_self.attr('id')] = _self.val();
            }
        }); 
        
        console.log(scFormData);
        
        jQuery.ajax({
            type: "POST",
            url: scTrans.ajaxurl,
            data: {
                action: 'sc-ajax-action',
                nuveiSecurity: scTrans.nuveiSecurity,
                getBlocksCheckoutData: 1,
                scFormData: scFormData
            },
            dataType: 'json'
        })
            .fail(function() {
                console.log('Nuvei request failed.');
                nuveiShowErrorMsg();
                return;
            })
            .done(function(resp) {
                console.log(resp);

                showNuveiCheckout(resp);
                return;
            });
    }
}

/**
 * We need to handle blocks and short-code cases.
 * 
 * @param {type} resp
 * @returns {undefined}
 */
function nuveiAfterSdkResponse(resp) {
	console.log('nuveiAfterSdkResponse', resp);
	
    // expired session
    if (resp.hasOwnProperty('session_expired') && resp.session_expired) {
        window.location.reload();
        return;
    }
    
    // a specific Error
    if(resp.hasOwnProperty('status')
        && resp.status == 'ERROR'
        && resp.hasOwnProperty('reason')
        && resp.reason.toLowerCase().search('the currency is not supported') >= 0
    ) {
        nuveiShowErrorMsg(resp.reason);
        return;
    }
    
	if (typeof resp.result == 'undefined') {
		console.error('Error with Checkout SDK response', resp);
		nuveiShowErrorMsg(scTrans.unexpectedError);
		return;
	}
	
	if ( (resp.result == 'APPROVED' || resp.result == 'PENDING') 
		&& typeof resp.transactionId != 'undefined' 
		&& resp.transactionId != 'undefined'
	) {
		jQuery('#nuvei_transaction_id').val(resp.transactionId);
        jQuery('#nuvei_checkout_container').html('<img class="nuvei_loader" src="' + scTrans.loaderUrl + '" />');
        
        switch(nuveiCheckoutImplementation.name) {
            case 'shortcode':
                jQuery('form.checkout').trigger('submit');
                return;
                
            case 'blocks':
                jQuery('.wc-block-components-checkout-place-order-button').trigger('click');
                return;
                
            case 'order-pay':
                jQuery('#place_order').trigger('click');
                return;
        }
	}
	
	if (resp.result == 'DECLINED') {
        if (resp.hasOwnProperty('errorDescription')
            && 'insufficient funds' == resp.errorDescription.toLowerCase()
        ) {
            nuveiShowErrorMsg(scTrans.insuffFunds);
            return;
        }
        
		nuveiShowErrorMsg(scTrans.paymentDeclined);
		return;
	}
    
	nuveiShowErrorMsg(scTrans.unexpectedError);
	return;
}

/**
 * @param object _params
 * @returns void
 */
function showNuveiCheckout(_params) {
	console.log('showNuveiCheckout()', _params);
	
	if(typeof _params != 'undefined') {
		nuveiCheckoutSdkParams = _params;
	}
    
    // on error
    if (!nuveiCheckoutSdkParams
        || !nuveiCheckoutSdkParams.hasOwnProperty('sessionToken')
        || ( nuveiCheckoutSdkParams.hasOwnProperty('status') 
            && 'error' == nuveiCheckoutSdkParams.status)
    ) {
        var error = '';
        
        if (nuveiCheckoutSdkParams
            && nuveiCheckoutSdkParams.hasOwnProperty('messages')
        ) {
            error = nuveiCheckoutSdkParams.messages;
        }

        nuveiShowErrorMsg(error);
        return;
    }
    
    // in this case we have product with Nuvei payment plan.
    if('savePM' === nuveiCheckoutSdkParams.savePM) {
        nuveiCheckoutSdkParams.pmBlacklist  = null;
        nuveiCheckoutSdkParams.pmWhitelist  = ['cc_card'];
    }
	
    if (nuveiCheckoutImplementation 
        && nuveiCheckoutImplementation.hasOwnProperty('name')
        && 'order-pay' !== nuveiCheckoutImplementation.name) {
        nuveiCheckoutSdkParams.prePayment = nuveiPrePayment;
    }
	
    nuveiCheckoutSdkParams.onResult = nuveiAfterSdkResponse;
	
	simplyConnect(nuveiCheckoutSdkParams);
	
    // TODO - teste it!
	if(jQuery('.wpmc-step-payment').length > 0) { // multi-step checkout
		console.log('multi-step checkout');
		jQuery("form.woocommerce-checkout .wpmc-step-payment *:not(form.woocommerce-checkout, #nuvei_checkout_container *), .woocommerce-form-coupon-toggle").hide();
	}
	else { // default checkout
		console.log('default checkout');
        
        // short-code checkout
		jQuery("form.woocommerce-checkout *, .woocommerce-form-coupon-toggle").hide();
        
        // blocks checkout
        jQuery(".wc-block-checkout .wc-block-checkout").hide();
	}
	
	jQuery("#nuvei_checkout_container").show();
	jQuery(window).scrollTop(0);
    jQuery('#nuvei_session_token').val(nuveiCheckoutSdkParams.sessionToken);
}

// TODO - test it on blocks
function nuveiCheckoutGoBack() {
	jQuery("#nuvei_checkout_container").html('');
	jQuery("#nuvei_checkout_container").hide();
	jQuery("form.woocommerce-checkout *, .woocommerce-form-coupon-toggle").show();
}

function nuveiShowErrorMsg(text) {
	if (typeof text == 'undefined' || '' == text) {
		text = scTrans.unexpectedError;
	}
	
	// short-code checkout
    if (jQuery('#nuvei_checkout_errors').length == 1) {
        jQuery('#nuvei_checkout_errors').html(
           '<div class="woocommerce-error" role="alert">'
               +'<strong>'+ text +'</strong>'
           +'</div>'
        );
    }
    
    // blocks checkout
    if (jQuery('.wc-block-components-notices').length > 0) {
        jQuery('.wc-block-components-notices').first()
            .append(
                '<div class="woocommerce-error" role="alert">'
                    +'<strong>'+ text +'</strong>'
                +'</div>'
            );
    }
	
	jQuery(window).scrollTop(0);
}

function nuveiPrePayment(paymentDetails) {
	console.log('nuveiPrePayment');
    
    var postData    = {
        action: 'sc-ajax-action',
        nuveiSecurity: scTrans.nuveiSecurity,
        prePayment: 1
    };
    
	return new Promise((resolve, reject) => {
		jQuery.ajax({
            type: "POST",
            url: scTrans.ajaxurl,
            data: postData,
            dataType: 'json'
        })
            .fail(function(){
                reject();
            })
            .done(function(resp) {
                console.log(resp);
        
                if (!resp.hasOwnProperty('success') || 0 == resp.success) {
                    reject();
                    window.location.reload();
                    return;
                }
                
                resolve();
                return;
            });
	});
}

function nuveiWcShortcode() {
    console.log('checkout short-code');
        
    // place Checkout container out of the forms START
    // if #nuvei_checkout_container does not exists - create it.
    if(jQuery('.woocommerce #nuvei_checkout_container').length == 0) {
        jQuery('form.woocommerce-checkout')	
            .after(
                '<div id="nuvei_checkout_errors"></div>'
                + '<div id="nuvei_checkout_container" style="display: none;">'
                    + '<div id="nuvei_checkout">Loading...</div>'
                + '</div>'
            );
    }

    // if nuvei inputs doesnot exists - creat them
    if(jQuery('.woocommerce #nuvei_transaction_id').length == 0) {
        jQuery('form.woocommerce-checkout')
            .append('<input id="nuvei_transaction_id" type="hidden" name="nuvei_transaction_id" value="" />');
    }

    // clone the default Place Order button
    jQuery('#place_order').clone().attr({
        id: 'nuvei_place_order',
        type: 'button',
        onClick: 'someFunction()'
    })
        .insertAfter('#place_order');

    jQuery('#place_order').html('Continue');
}

/**
 * A method for the case when the merchant create an Order in the admin, then
 * the client pay it from its Store profile.
 * 
 * @param object _params The SDK params.
 */
function nuveiPayForExistingOrder(_orderId) {
    console.log('nuveiPayForExistingOrder');
    
    nuveiCheckoutImplementation.name = "order-pay";
    Object.freeze(nuveiCheckoutImplementation);
    
    // place Checkout container
    // if #nuvei_checkout_container does not exists - create it.
    if(jQuery('form#order_review #nuvei_checkout_container').length == 0) {
        jQuery('form#order_review')	
            .before('<div id="nuvei_checkout_errors"></div>');

        jQuery('form#order_review .payment_box.payment_method_nuvei').append(
            '<div id="nuvei_checkout_container" style="display: none;">'
                + '<div id="nuvei_checkout">Loading...</div>'
            + '</div>'
        );
    }

    // if nuvei inputs does not exists - creat them
    if(jQuery('form#order_review #nuvei_transaction_id').length == 0) {
        jQuery('form#order_review')
            .append('<input id="nuvei_transaction_id" type="hidden" name="nuvei_transaction_id" value="" />');
    }

    // TODO - Prepare form - get Simply Connect params
    jQuery.ajax({
        type: "POST",
        url: scTrans.ajaxurl,
        data: {
            action: 'sc-ajax-action',
            nuveiSecurity: scTrans.nuveiSecurity,
            payForExistingOrder: 1,
            orderId: _orderId
        },
        dataType: 'json'
    })
        .fail(function() {
            console.log('Nuvei request failed.');
            nuveiShowErrorMsg();
            return;
        })
        .done(function(resp) {
            console.log(resp);

            // set event on Place order button
            jQuery('input[name=payment_method]').on('change', function() {
                var _self = jQuery(this);

                if(_self.val() == scTrans.paymentGatewayName) {
                    jQuery('#place_order').hide();
                }
                else {
                    jQuery('#place_order').show();
                }

                if (!nuveiIsCheckoutLoaded) {
                    nuveiIsCheckoutLoaded = true;
                    showNuveiCheckout(resp);
                }
            });

            // hide Place order button if need to
            if (jQuery('input[name=payment_method]').val() == scTrans.paymentGatewayName) {
                jQuery('#place_order').hide();

                if (!nuveiIsCheckoutLoaded) {
                    nuveiIsCheckoutLoaded = true;
                    showNuveiCheckout(resp);
                }
            }
            
            return;
        });
}

function nuveiPfwChangeThankYouPageMsg(new_title, new_msg, remove_wcs_pay_btn) {
    // on error change thank you page title and message
    if (new_title && '' != new_title) {
        jQuery(".entry-title").html(new_title);
        jQuery(".woocommerce-thankyou-order-received").html(new_msg);
    }
    
    // if there is pay button on thank you page - hide it!
    if (remove_wcs_pay_btn && jQuery("a.pay").length > 0) {
        jQuery("a.pay").hide();
    }
}

jQuery(function() {
	if('no' === scTrans.isPluginActive) {
		return;
	}
    
    // Multistep checkout does not support WC Blocks
	// when on multistep checkout -> Checkout SDK view, someone click on previous/next button
	jQuery('body').on('click', '#wpmc-prev', function(e) {
		if(jQuery('#nuvei_checkout_container').css('display') == 'block') {
			jQuery("#nuvei_checkout_container").hide();
			jQuery('input[name="payment_method"]').prop('checked', false);
		}
	});
	
	jQuery('body').on('click', '#wpmc-next', function(e) {
		if(jQuery('.wpmc-tab-item.wpmc-payment').hasClass('current')
			&& !jQuery('.wpmc-step-item.wpmc-step-payment #payment').is(':visible')
		) {
			jQuery("form.woocommerce-checkout .wpmc-step-payment *:not(.payment_box, form.woocommerce-checkout, #nuvei_checkout_container, script), .woocommerce-form-coupon-toggle").show();
		}
	});
	// when on multistep checkout -> Checkout SDK view, someone click on previous/next button END
    
    // when WC Shortcode submit the checkout form we wait for nuveiParams in the response.
    jQuery( document ).on( "ajaxComplete", function( event, xhr, settings ) {
        console.log(xhr.responseJSON)
        
        if (typeof xhr.responseJSON == 'object'
            && xhr.responseJSON.hasOwnProperty('nuveiParams')
        ) {
            event.preventDefault();
            showNuveiCheckout(xhr.responseJSON.nuveiParams);
            return;
        }
    });
    
    // When the client is on accout -> orders page and to pay an order
    // created from the merchant.
    if (jQuery('#nuveiPayForExistingOrder').length > 0
        && ! isNaN(jQuery('#nuveiPayForExistingOrder').val())
        && jQuery('#nuveiPayForExistingOrder').val() > 0
    ) {
        nuveiPayForExistingOrder(jQuery('#nuveiPayForExistingOrder').val());
    }
});
// document ready function END

window.addEventListener('load', function() {
    // search for WC Shortcode form
    if (jQuery('form.woocommerce-checkout').length > 0) {
        nuveiCheckoutImplementation.name = 'shortcode';
        
        Object.freeze(nuveiCheckoutImplementation);
        nuveiWcShortcode();
    }
    // search for WC Blocks
    else if (jQuery('div.wp-block-woocommerce-checkout').length > 0) {
        nuveiCheckoutImplementation.name = 'blocks';
        
        Object.freeze(nuveiCheckoutImplementation);
        nuveiCheckoutBlocks.prepareNuveiComponents();
        nuveiCheckoutBlocks.changePaymentBtn();
    }
    else {
       console.log('No Checkout container found or page still loading.');
    }
    
});
