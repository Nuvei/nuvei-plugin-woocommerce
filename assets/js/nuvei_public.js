const nuveiCheckoutContainer    = '<div id="nuvei_checkout_container">Loading...</div>';
const nuveiCheckoutGoBackBtn    = `<p id="nuvei_go_back"><a href="#" onclick="nuveiCheckoutGoBack()">${scTrans.goBack}</a></p>`;
const nuveiCheckoutErrorCont    = '<div id="nuvei_checkout_errors"></div>';
const nuveiTrIdInput            = '<input id="nuvei_transaction_id" type="hidden" name="nuvei_transaction_id" value="" />';
const nuveiSesTokenInput        = '<input id="nuvei_session_token" type="hidden" name="nuvei_session_token" value="" />';

var nuveiCheckoutSdkParams      = {};
var nuveiCheckoutImplementation = {}; // shortcode, blocks or order-pay
var nuveiCouponsContainers      = {};
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
        try {
            if(jQuery('body #nuvei_go_back').length == 0) {
                jQuery('div.wp-block-woocommerce-checkout')
                    .append(nuveiCheckoutGoBackBtn);
            }
            
            if(jQuery('body #nuvei_checkout_container').length == 0) {
                jQuery('div.wp-block-woocommerce-checkout')
                    .append(nuveiCheckoutContainer);
            }

            // if nuvei inputs does not exists - create them
            if(jQuery('form.wc-block-components-form #nuvei_transaction_id').length == 0) {
                jQuery('form.wc-block-components-form')
                    .append(nuveiTrIdInput);
            }
            
            if(jQuery('form.wc-block-components-form #nuvei_session_token').length == 0) {
                jQuery('form.wc-block-components-form').append(nuveiSesTokenInput);
            }
        }
        catch(_e) {
            console.log('WC blocks logic fail.');
        }
    },
    
    /**
     * A method to modify "Place Order" button for our needs.
     * 
     * @returns boolean
     */
    changePaymentBtn: function() {
        const blockPlaceOrderBtn = jQuery('button.wc-block-components-checkout-place-order-button');
        
//        console.log('#nuvei_transaction_id', jQuery('#nuvei_transaction_id').val());
//        
//        if (!scTrans 
//            || !scTrans.hasOwnProperty('checkoutIntegration')
//            || 'sdk' !== scTrans.checkoutIntegration
//            || blockPlaceOrderBtn.length === 0
//        ) {
//            return;
//        }
//        
//        if (!jQuery('#nuvei_transaction_id')
//            || jQuery('#nuvei_transaction_id').val() === ''
//        ) {
//            return;
//        }
        
        console.log('modify Continue button.');

        blockPlaceOrderBtn.on('click', function(e) {
            nuveiCheckoutBlocks.onCheckoutButtonClick(e);
            
//            e.preventDefault();
//            e.stopImmediatePropagation();
//
//            const nuveiSelectedBlockPm  = jQuery('input[name=radio-control-wc-payment-method-options]:checked').val();
//            let continueDefaultFlow     = false;
//
//            if (scTrans.paymentGatewayName != nuveiSelectedBlockPm) {
//                blockPlaceOrderBtn.trigger('click');
//                return true;
//            }
//
//            // Check the form inputs.
//            jQuery('form.wc-block-components-form').find('input').each(function() {
//                let self = jQuery(this);
//
//                if (typeof self.attr('required') != 'undefined' && '' == self.val()) {
//                    continueDefaultFlow = true;
//                    return false;
//                }
//            });
//
//            // This will trigger default form check and will mark if some fields are missing.
//            if (continueDefaultFlow) {
//                console.log('submit 2');
//
//                blockPlaceOrderBtn.trigger('click');
//                return true;
//            }
//            
//            // Run Nuvei methods.
//            if ( (!jQuery('#nuvei_transaction_id').val()
//                    || '' != jQuery('#nuvei_transaction_id').val())
//                && jQuery('.has-error').length == 0
//            ) {
//                nuveiCheckoutBlocks.getCheckoutData();
//                return false;
//            }
//
//            // continue with default flow
//            blockPlaceOrderBtn.trigger('click');
//            return true;
        });
    },
    
    onCheckoutButtonClick: function(event) {
        // Continue with default behavior.
        if (!scTrans 
            || !scTrans.hasOwnProperty('checkoutIntegration')
            || !scTrans.hasOwnProperty('paymentGatewayName')
            || 'sdk' !== scTrans.checkoutIntegration
            || scTrans.paymentGatewayName != jQuery('input[name=radio-control-wc-payment-method-options]:checked').val()
        ) {
            console.log('Some checks fail. Submit the form.');
            return true;
        }
        
        if (jQuery('#nuvei_transaction_id').length > 0
            && jQuery('#nuvei_transaction_id').val() !== ''
        ) {
            console.log('We have nuvei_transaction_id. Submit the form.');
            return true;
        }
        
        // Check the form inputs.
        let continueDefaultFlow = false;

        jQuery('form.wc-block-components-form').find('input').each(function() {
            let self = jQuery(this);

            // on error
            if (typeof self.attr('required') != 'undefined' && '' == self.val()) {
                continueDefaultFlow = true;
                return false; // just stop the loop here
            }
        });

        if (continueDefaultFlow) {
            return true;
        }
        
        // Run Nuvei methods.
        event.preventDefault();
        event.stopImmediatePropagation();
        
        nuveiCheckoutBlocks.getCheckoutData();
        return false;
    },
    
    getCheckoutData: function() {
        console.log('getCheckoutData');
        
        var scFormData = {};
        
//        console.log(jQuery('.wc-block-components-form input').length);
        
        jQuery('.wc-block-components-form input').each(function(){
            var _self = jQuery(this);
            
            if (_self.attr('id') && '' !=  _self.val()) {
                scFormData[_self.attr('id')] = _self.val();
            }
        }); 
        
//        console.log(scFormData);
        
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
 * @param object resp
 * @returns void
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
        // as I know multi-step checkout works only on shortcode checkout
        nuveiCouponsContainers = nuveiGetTopCouponElements(jQuery(".woocommerce"));
	}
	else { // default checkout
		console.log('default checkout');
        
        // in case of short-code checkout, hide its components
        if ('shortcode' === nuveiCheckoutImplementation.name) {
            jQuery("form.woocommerce-checkout").hide();
            nuveiCouponsContainers = nuveiGetTopCouponElements(jQuery(".woocommerce"));
        }
        // in case of blocks checkout, hide its components
        else if ('blocks' === nuveiCheckoutImplementation.name) {
            jQuery(".wc-block-checkout .wc-block-checkout").hide();
            nuveiCouponsContainers = nuveiGetTopCouponElements(jQuery(".wc-block-checkout .wc-block-checkout"));
        }
	}
    
    // hide all possible coupon containers
    nuveiCouponsContainers.each(function() {
        jQuery(this).hide();
    });
    
    jQuery('#nuvei_session_token').val(nuveiCheckoutSdkParams.sessionToken);
	
	jQuery("#nuvei_checkout_container, #nuvei_go_back").show();
    
    setTimeout(() => {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }, 1000);
}

// TODO - test it on blocks
//function nuveiCheckoutGoBack() {
//	jQuery("#nuvei_checkout_container").html('');
//	jQuery("#nuvei_checkout_container").hide();
//	jQuery("form.woocommerce-checkout *, .woocommerce-form-coupon-toggle").show();
//}

function nuveiCheckoutGoBack() {
    console.log('go back');
    
    try {
        jQuery("#nuvei_checkout_container, #nuvei_go_back").hide();

        // short-code checkout
        if ('shortcode' === nuveiCheckoutImplementation.name) {
            jQuery(".woocommerce-error").remove();
            jQuery("form.woocommerce-checkout").show();
        }
        // blocks checkout
        else {
            jQuery(".wc-block-checkout .wc-block-checkout").show();
        }

        // show all possible coupon containers, hiden before
        nuveiCouponsContainers.each(function() {
            jQuery(this).show();
        });

        simplyConnect.destroy();
    }
    catch(e) {
        console.log(e);
    }
}

/**
 * Get all possible top container elements for coupons to hide them.
 * 
 * @param {object} container
 * @returns {Array}
 */
function nuveiGetTopCouponElements(container) {
    return container.find('[class*="coupon"]').filter(function() {
        return jQuery(this).parents('[class*="coupon"]').length === 0;
    });
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
    
    let html = '';
    
    // add nuvei_checkout_errors
    if(jQuery('.woocommerce #nuvei_checkout_errors').length == 0) {
        html += nuveiCheckoutErrorCont;
    }
    
    // add nuveiCheckoutGoBackBtn
    if(jQuery('.woocommerce #nuvei_go_back').length == 0) {
        html += nuveiCheckoutGoBackBtn;
    }
    
    // add nuvei_checkout_container
    if(jQuery('.woocommerce #nuvei_checkout_container').length == 0) {
        html += nuveiCheckoutContainer;
    }
    
    jQuery('form.woocommerce-checkout').after(html);

    // if nuvei inputs doesnot exists - create them
    if(jQuery('.woocommerce #nuvei_transaction_id').length == 0) {
        jQuery('form.woocommerce-checkout').append(nuveiTrIdInput);
    }

}

/**
 * A method for the case when the merchant create an Order in the admin, then
 * the client pay it from its Store profile.
 * 
 * @param int _orderId The Order Id.
 */
function nuveiPayForExistingOrder(_orderId) {
    console.log('nuveiPayForExistingOrder');
    
    nuveiCheckoutImplementation.name = "order-pay";
    Object.freeze(nuveiCheckoutImplementation);
    
    // add nuvei_checkout_container
    if(jQuery('form#order_review #nuvei_checkout_container').length == 0) {
        jQuery('form#order_review').before(nuveiCheckoutErrorCont);
        jQuery('form#order_review .payment_box.payment_method_nuvei').append(nuveiCheckoutContainer);
    }

    // if nuvei inputs does not exists - creat them
    if(jQuery('form#order_review #nuvei_transaction_id').length == 0) {
        jQuery('form#order_review').append(nuveiTrIdInput);
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
    console.log('document ready');
    
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
    
    // Wait for nuveiParams in the response.
    jQuery( document ).on( "ajaxComplete", function( event, xhr, settings ) {
//        console.log(xhr.responseJSON)
        
        if (typeof xhr.responseJSON == 'object'
            && xhr.responseJSON.hasOwnProperty('nuveiParams')
        ) {
            event.preventDefault();
            showNuveiCheckout(xhr.responseJSON.nuveiParams);
            return;
        }
    });
    
    // When the client is on accout -> orders page and pay an order created from the merchant.
    if (jQuery('#nuveiPayForExistingOrder').length > 0
        && ! isNaN(jQuery('#nuveiPayForExistingOrder').val())
        && jQuery('#nuveiPayForExistingOrder').val() > 0
    ) {
        nuveiPayForExistingOrder(jQuery('#nuveiPayForExistingOrder').val());
    }
    
    // Wait for button.wc-block-components-checkout-place-order-button to appear in the DOM
    const observer = new MutationObserver((mutations, obs) => {
        if (jQuery('body button.wc-block-components-checkout-place-order-button').length > 0) {
            console.log('button loaded');
            // Your logic here
            nuveiCheckoutBlocks.changePaymentBtn();

            obs.disconnect(); // Stop observing
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
    
});
// document ready function END

window.addEventListener('load', function() {
    console.log('window loaded');
    
    // search for WC Shortcode form
    if (jQuery('form.woocommerce-checkout').length > 0) {
        nuveiCheckoutImplementation.name = 'shortcode';
        
        Object.freeze(nuveiCheckoutImplementation);
        nuveiWcShortcode();
//        nuveiCouponsContainers = nuveiGetTopCouponElements(jQuery("form.woocommerce-checkout"));
    }
    // search for WC Blocks
    else if (jQuery('div.wp-block-woocommerce-checkout').length > 0) {
        console.log('WC Blocks found');
        
        nuveiCheckoutImplementation.name = 'blocks';
        
        Object.freeze(nuveiCheckoutImplementation);
        nuveiCheckoutBlocks.prepareNuveiComponents();
//        nuveiCheckoutBlocks.changePaymentBtn();
//        nuveiCouponsContainers = nuveiGetTopCouponElements(jQuery(".wc-block-checkout .wc-block-checkout"));
    }
    else {
       console.log('No Checkout container found or page still loading.');
    }
    
});
