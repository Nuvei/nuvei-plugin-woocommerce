'use strict';

var sfc					= null;
var scFields			= null;
var scData				= {};
var selectedPM			= '';
var orderAmount			= 0;

//var scOrderAmount, scOrderCurr,
var scOpenOrderToken, locale;

/**
 * Function scUpdateCart
 * The first step of the checkout validation
 */
function scUpdateCart() {
	console.log('scUpdateCart()');
	
	jQuery.ajax({
		type: "POST",
		url: scTrans.ajaxurl,
		data: {
			action			: 'sc-ajax-action',
			security		: scTrans.security,
			sc_request		: 'updateOrder'
		},
		dataType: 'json'
	})
		.fail(function(){
			scValidateAPMFields();
		})
		.done(function(resp) {
			console.log(resp);
			
			if(resp.hasOwnProperty('sessionToken')
				&& '' != resp.sessionToken
				&& resp.sessionToken != scData.sessionToken
			) {
				scData.sessionToken = resp.sessionToken;
				jQuery('#lst').val(resp.sessionToken);
				
				sfc			= SafeCharge(scData);
				scFields	= sfc.fields({ locale: locale });
				
				nuveiShowErrorMsg(scTrans.paymentError + ' ' + scTrans.TryAgainLater);
				
				jQuery('#sc_second_step_form .input-radio').prop('checked', false);
			}
			else if (resp.hasOwnProperty('reload_checkout')) {
				window.location = window.location.hash;
				return;
			}
			
			scValidateAPMFields();
		});
}

/**
 * 
 * @param {type} resp
 * @returns {undefined}
 */
function afterSdkResponse(resp) {
	console.log('afterSdkResponse', resp);
	
	if (typeof resp.result == 'undefined') {
		console.error('Error with Checkout SDK response: ' + resp);
		getNewSessionToken();
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
 
function getNewSessionToken() {
	console.log('getNewSessionToken()');
	
	jQuery.ajax({
		type: "POST",
		url: scTrans.ajaxurl,
		data: {
			action      : 'sc-ajax-action',
			security    : scTrans.security,
			sc_request	: 'OpenOrder',
		},
		dataType: 'json'
	})
		.fail(function(){
			console.log('openOrder request fail.')
			nuveiShowErrorMsg(scTrans.unexpectedError);
			return;
		})
		.done(function(resp) {
			console.log('getNewSessionToken()', resp);
	
			try {
				if(resp.sessionToken) {
					showNuveiCheckout(resp);
					return;
				}
				
				window.location.reload();
				return;
			}
			catch(error) {
				window.location.reload();
				return;
			}
		});
}

function showNuveiCheckout(params) {
	console.log(params, 'showNuveiCheckout()');
	
	params.onResult = afterSdkResponse;
	
	checkout(params);
	
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
