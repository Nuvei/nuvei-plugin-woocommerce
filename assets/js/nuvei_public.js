var nuveiCheckoutSdkParams = {};

/**
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
		jQuery('#nuvei_transaction_id').val(resp.transactionId);
        
        if (jQuery('form.checkout').length != 1) {
            nuveiShowErrorMsg(scTrans.CheckoutFormError);
            return;
        }
        
//        jQuery('#nuvei_checkout_errors').html('<b>' + scTrans.TransactionAppr + '</b>');
        jQuery('#nuvei_checkout').remove();
        jQuery('form.checkout').trigger('submit');
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
    
    // in this case we have product with Nuvei payment plan.
    if('savePM' === nuveiCheckoutSdkParams.savePM) {
        nuveiCheckoutSdkParams.pmBlacklist  = null;
        nuveiCheckoutSdkParams.pmWhitelist  = ['cc_card'];
    }
	
	nuveiCheckoutSdkParams.prePayment	= nuveiPrePayment;
	nuveiCheckoutSdkParams.onResult		= nuveiAfterSdkResponse;
	
	checkout(nuveiCheckoutSdkParams);
	
	if(jQuery('.wpmc-step-payment').length > 0) { // multi-step checkout
		console.log('multi-step checkout');
		jQuery("form.woocommerce-checkout .wpmc-step-payment *:not(form.woocommerce-checkout, #nuvei_checkout_container *), .woocommerce-form-coupon-toggle").hide();
	}
	else { // default checkout
		console.log('default checkout');
		jQuery("form.woocommerce-checkout *, .woocommerce-form-coupon-toggle").hide();
	}
	
	jQuery("#nuvei_checkout_container").show();
	jQuery(window).scrollTop(0);
    jQuery('#nuvei_session_token').val(nuveiCheckoutSdkParams.sessionToken);
}

function nuveiCheckoutGoBack() {
	jQuery("#nuvei_checkout_container").html('');
	jQuery("#nuvei_checkout_container").hide();
	jQuery("form.woocommerce-checkout *, .woocommerce-form-coupon-toggle").show();
}

function nuveiShowErrorMsg(text) {
	if (typeof text == 'undefined') {
		text = scTrans.unexpectedError;
	}
	
	jQuery('#nuvei_checkout_errors').html(
	   '<div class="woocommerce-error" role="alert">'
		   +'<strong>'+ text +'</strong>'
	   +'</div>'
	);
	
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

jQuery(function() {
	if('no' == scTrans.isPluginActive) {
		return;
	}
	
	// place Checkout container out of the forms
	if(jQuery('form.woocommerce-checkout').length == 1) {
		if(jQuery('.woocommerce #nuvei_checkout_container').length == 0) {
			jQuery('form.woocommerce-checkout')	
				.after(
					'<div id="nuvei_checkout_container" style="display: none;">'
						+ '<div id="nuvei_checkout_errors"></div>'
						+ '<div id="nuvei_checkout">Loading...</div>'
					+ '</div>'
				);
		}
		
		if(jQuery('.woocommerce #nuvei_transaction_id').length == 0) {
			jQuery('form.woocommerce-checkout')
				.append('<input id="nuvei_transaction_id" type="hidden" name="nuvei_transaction_id" value="" />');
		}
		if(jQuery('.woocommerce #nuvei_session_token').length == 0) {
			jQuery('form.woocommerce-checkout')
				.append('<input id="nuvei_session_token" type="hidden" name="nuvei_session_token" value="" />');
		}
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
    
    // detect click on DCC option
//    jQuery('#nuvei_checkout').on('click', 'input[name="useDcc"]', function() {
//        let _self = jQuery(this);
//        
//        console.log('DCC click', _self.is(':checked'));
//        
//        if (!_self.is(':checked')) {
//            return;
//        }
//    });
//    
//    jQuery('#nuvei_checkout').on('change', 'select.currency', function() {
//        let _self = jQuery(this);
//        
//        console.log('currency changed to ', _self.val());
//    });
});
// document ready function END
