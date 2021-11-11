const onReady = function (result) { 
	console.log('onReady', result) 
};

var nuveiCheckoutSdkParams = {};

/**
 * 
 * @param {type} resp
 * @returns {undefined}
 */
function afterSdkResponse(resp) {
	console.log('afterSdkResponse', resp);
	
	if (typeof resp.result == 'undefined') {
		console.error('Error with Checkout SDK response: ' + resp);
		nuveiShowErrorMsg(scTrans.unexpectedError);
		return;
	}
	
	if (resp.result == 'APPROVED' 
		&& typeof resp.transactionId != 'undefined' 
		&& resp.transactionId != 'undefined'
	) {
		jQuery('#nuvei_transaction_id').val(resp.transactionId);
		jQuery('#place_order').trigger('click');
		return;
	}
	
	if (resp.result == 'DECLINED') {
		nuveiShowErrorMsg(scTrans.paymentDeclined);
		return;
	}
	
	nuveiShowErrorMsg(scTrans.unexpectedError);
	return;
}
 
function showNuveiCheckout(_params) {
	console.log('showNuveiCheckout()', _params);
	
	nuveiCheckoutSdkParams = _params;
	
	nuveiCheckoutSdkParams.prePayment	= prePayment;
	nuveiCheckoutSdkParams.onResult		= afterSdkResponse;
	
	checkout(nuveiCheckoutSdkParams);
	
	if(jQuery('.wpmc-step-payment').length > 0) { // multi-step checkout
		console.log('multi-step checkout');
		
		jQuery("form.woocommerce-checkout .wpmc-step-payment *:not(form.woocommerce-checkout, #nuvei_checkout_container *, #sc_checkout_messages), .woocommerce-form-coupon-toggle").hide();
	}
	else { // default checkout
		console.log('default checkout');
		
		jQuery("form.woocommerce-checkout *, .woocommerce-form-coupon-toggle").hide();
	}
	
	jQuery("#nuvei_checkout_container").show();
	jQuery(window).scrollTop(0);
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

function prePayment(paymentDetails) {
	return new Promise((resolve, reject) => {
		var errorMsg = scTrans.paymentError + ' ' + scTrans.TryAgainLater;
		
		jQuery.ajax({
			type: "POST",
			url: scTrans.ajaxurl,
			data: {
				action			: 'sc-ajax-action',
				security		: scTrans.security,
				updateOrder		: 1
			},
			dataType: 'json'
		})
			.fail(function(){
				reject(errorMsg);
			})
			.done(function(resp) {
				console.log(resp);

				if(resp.hasOwnProperty('sessionToken') && '' != resp.sessionToken) {
					if(resp.sessionToken != nuveiCheckoutSdkParams.sessionToken) {
						nuveiCheckoutSdkParams.sessionToken	= resp.sessionToken;
						nuveiCheckoutSdkParams.amount		= resp.amount;

						jQuery('#lst').val(resp.sessionToken);
					}
				}
				else {
					reject(errorMsg);
				}

				resolve();
			});
	});
}

jQuery(function() {
	// place Checkout container out of the forms
	if(jQuery('form.woocommerce-checkout').length == 1) {
		if(jQuery('.woocommerce #nuvei_checkout_container').length == 0) {
			jQuery('form.woocommerce-checkout')	
				.before(
					'<div id="nuvei_checkout_container" style="display: none;">'
						+ '<div id="nuvei_checkout_errors"></div>'
						+ '<div id="nuvei_checkout">Loading...</div>'
						+ '<div class="link">'
							+ '<a href="'+ window.location.hash +'">'+ scTrans.goBack +'</a>'
						+ '</div>'
					+ '</div>'
				);
		}
		
		if(jQuery('.woocommerce #nuvei_transaction_id').length == 0) {
			jQuery('form.woocommerce-checkout')
				.append('<input id="nuvei_transaction_id" type="hidden" name="nuvei_transaction_id" value="" />');
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
	
	// when on multistep checkout -> APMs view, someone click on previous button
	jQuery('body').on('click', '#wpmc-prev', function() {
		if(jQuery('#sc_second_step_form').css('display') == 'block') {
			jQuery("form.woocommerce-checkout .wpmc-step-payment *:not(.payment_box, form.woocommerce-checkout, #sc_second_step_form *, #sc_checkout_messages), .woocommerce-form-coupon-toggle").show('slow');
			
			jQuery("form.woocommerce-checkout #sc_second_step_form").hide();
			
			jQuery('input[name="payment_method"]').prop('checked', false);
		}
	});
});
// document ready function END
