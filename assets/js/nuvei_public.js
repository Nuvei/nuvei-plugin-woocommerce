var nuveiCheckoutSdkParams = {};

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
            
            // place Checkout container out of the forms START
            // if #nuvei_checkout_container does not exists - create it.
            if(jQuery('body #nuvei_checkout_container').length == 0) {
                jQuery('div.wp-block-woocommerce-checkout')
                    .append(
                        '<div id="nuvei_checkout_container" style="display: none;">'
                            + '<img class="nuvei_loader" src="' + scTrans.loaderUrl + '" />'
                        + '</div>'
                    );
            }

            // id nuvei inputs doee not exists - creat them
            if(jQuery('form.wc-block-components-form #nuvei_transaction_id').length == 0) {
                jQuery('form.wc-block-components-form')
                    .append('<input id="nuvei_transaction_id" type="hidden" name="nuvei_transaction_id" value="" />');
            }
            
            if(jQuery('form.wc-block-components-form #nuvei_session_token').length == 0) {
                jQuery('form.wc-block-components-form')
                    .append('<input id="nuvei_session_token" type="hidden" name="nuvei_session_token" value="" />');
            }
            // place Checkout container out of the forms END
            
            jQuery('input[name=radio-control-wc-payment-method-options]').on('change', function() {
                nuveiCheckoutBlocks.changePaymentBtn(jQuery(this).val());
            });
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
    changePaymentBtn: function(nuveiSelectedBlockPm) {
        console.log('changePaymentBtn for blocks', nuveiSelectedBlockPm);
        
        var self                = this;
        var blockPlaceOrderBtn  = jQuery('button.wc-block-components-checkout-place-order-button');
    
        if (scTrans
            && scTrans.hasOwnProperty('checkoutIntegration')
            && 'sdk' == scTrans.checkoutIntegration
            && blockPlaceOrderBtn.length > 0
        ) {
            console.log('change submit button', nuveiSelectedBlockPm);

            blockPlaceOrderBtn.on('click', function(e) {
                if (scTrans.paymentGatewayName == nuveiSelectedBlockPm
                    && '' == jQuery('#nuvei_transaction_id').val()
                    && jQuery('.has-error').length == 0
                ) {
                    e.stopImmediatePropagation();
                    showNuveiCheckout(nuveiCheckoutSdkParams);
                    return;
                }
            });
        }
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
        
        // short-code checkout
        if (typeof wc == 'undefined') {
            jQuery('form.checkout').trigger('submit');
        }
        // blocks checkout
        else {
            jQuery('.wc-block-components-checkout-place-order-button').trigger('click');
        }

		return;
	}
	
	if (resp.result == 'DECLINED') {
        if (resp.hasOwnProperty('errorDescription')
            && 'insufficient funds' == resp.errorDescription.toLowerCase()
        ) {
            nuveiShowErrorMsg(scTrans.insuffFunds);
            return
        }
        
		nuveiShowErrorMsg(scTrans.paymentDeclined);
		return;
	}
    
	nuveiShowErrorMsg(scTrans.unexpectedError);
	return;
}

function showNuveiCheckout(_params) {
	console.log('showNuveiCheckout()', _params);
	
	if(typeof _params != 'undefined') {
		nuveiCheckoutSdkParams = _params;
	}
    
    // on error
    if (!nuveiCheckoutSdkParams.hasOwnProperty('sessionToken')
        || ( nuveiCheckoutSdkParams.hasOwnProperty('status') 
            && 'error' == nuveiCheckoutSdkParams.status)
    ) {
        nuveiShowErrorMsg();
        return;
    }
    
    // in this case we have product with Nuvei payment plan.
    if('savePM' === nuveiCheckoutSdkParams.savePM) {
        nuveiCheckoutSdkParams.pmBlacklist  = null;
        nuveiCheckoutSdkParams.pmWhitelist  = ['cc_card'];
    }
	
	nuveiCheckoutSdkParams.prePayment	= nuveiPrePayment;
	nuveiCheckoutSdkParams.onResult		= nuveiAfterSdkResponse;
	
	checkout(nuveiCheckoutSdkParams);
	
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
	if (typeof text == 'undefined') {
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
        security: scTrans.security,
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
        
    try {
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

        // id nuvei inputs doee not exists - creat them
        if(jQuery('.woocommerce #nuvei_transaction_id').length == 0) {
            jQuery('form.woocommerce-checkout')
                .append('<input id="nuvei_transaction_id" type="hidden" name="nuvei_transaction_id" value="" />');
        }

        // change text on Place order button
        jQuery('form.woocommerce-checkout').on('change', 'input[name=payment_method]', function(){
            if(jQuery('input[name=payment_method]:checked').val() == scTrans.paymentGatewayName) {
                jQuery('#place_order').html(jQuery('#place_order').attr('data-sc-text'));
            }
            else if(jQuery('#place_order').html() == jQuery('#place_order').attr('data-sc-text')) {
                jQuery('#place_order').html(jQuery('#place_order').attr('data-default-text'));
            }
        });
    }
    catch(_e) {
        console.log('WC shorcode logic fail.');
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
});
// document ready function END

window.onload = function() {
    // loocks like this object is available only for checkout blocks
    if (typeof wc == 'object') {
        nuveiCheckoutBlocks.prepareNuveiComponents();
        
        nuveiCheckoutBlocks.changePaymentBtn(
            jQuery('input[name=radio-control-wc-payment-method-options]:checked').val()
        );
    }
    else {
        nuveiWcShortcode();
    }
}
