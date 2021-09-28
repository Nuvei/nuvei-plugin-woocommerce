'use strict';

var forceUserTokenId	= 0;
var sfc					= null;
var scFields			= null;
var sfcFirstField		= null;
var scCard				= null;
var cardNumber			= null;
var cardExpiry			= null;
var cardCvc				= null;
var scData				= {};
var lastCvcHolder		= '';
var selectedPM			= '';
var countryCode			= '';
var currencyCode		= '';
var applePayLabel		= '';
var orderAmount			= 0;

// set some classes for the Fields
var elementClasses = {
	focus	: 'focus',
	empty	: 'empty',
	invalid	: 'invalid'
};
// styles for the fields
var fieldsStyle = {
	base: {
		fontSize: '15px',
		fontFamily: 'sans-serif',
		color: '#43454b',
		fontSmoothing: 'antialiased',
		'::placeholder': {
			color: 'gray'
		}
	}
};
//var scOrderAmount, scOrderCurr,
var scMerchantId, scMerchantSiteId, scOpenOrderToken, webMasterId, scUserTokenId, locale;

function nuveiCheckoutCallback() {
	console.log('nuveiCheckoutCallback');
}

/**
 * Function scUpdateCart
 * The first step of the checkout validation
 */
function scUpdateCart() {
	console.log('scUpdateCart()');
	
	jQuery('#sc_loader_background').show();
	
	selectedPM = jQuery('input[name="sc_payment_method"]:checked').val();
	
	if (typeof selectedPM == 'undefined' || selectedPM == '') {
		scFormFalse();
		return;
	}
	
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
				
				scFormFalse(scTrans.paymentError + ' ' + scTrans.TryAgainLater);
				
				jQuery('#sc_second_step_form .input-radio').prop('checked', false);
				jQuery('.apm_fields, #sc_loader_background').hide();
			}
			else if (resp.hasOwnProperty('reload_checkout')) {
				window.location = window.location.hash;
				return;
			}
			
			scValidateAPMFields();
		});
}

 /**
  * Function validateScAPMsModal
  * Second step of checkout validation.
  * When click save on modal, check for mandatory fields and validate them.
  */
function scValidateAPMFields() {
	console.log('scValidateAPMFields');
		
	var formValid			= true;	
	var nuveiPaymentParams	= {
		sessionToken    : scOpenOrderToken,
		merchantId      : scMerchantId,
		merchantSiteId  : scMerchantSiteId,
		webMasterId		: scTrans.webMasterId,
	};
	
	if ( (jQuery('body').find('#nuvei_save_upo').is(':checked')
			|| typeof jQuery('input[name="sc_payment_method"]:checked').attr('data-upo-name') != 'undefined')
		|| 1 == forceUserTokenId
	) {
		nuveiPaymentParams.userTokenId = scUserTokenId;
	}

	console.log('selectedPM', 'selectedPM');
	
	// use apple pay
	if('ppp_ApplePay' == selectedPM) {
		if(typeof ApplePaySession != 'function') {
			scFormFalse(scTrans.ApplePayError + ' ' + scTrans.TryAnotherPM);
			return;
		}
		
		nuveiPaymentParams.countryCode	= countryCode;
		nuveiPaymentParams.currencyCode	= currencyCode;
		nuveiPaymentParams.amount		= orderAmount;
		nuveiPaymentParams.total		= {
			label: applePayLabel, // must be set from plugin settings
			amount: orderAmount
		};
		
		console.log(nuveiPaymentParams)
		
		sfc.createApplePayPayment(nuveiPaymentParams, function(resp) {
			afterSdkResponse(resp);
		});
		return;
	}

	// use cards
	if (selectedPM == 'cc_card') {
		if (
			typeof scOpenOrderToken == 'undefined'
			|| typeof scMerchantId == 'undefined'
			|| typeof scMerchantSiteId == 'undefined'
		) {
			scFormFalse(scTrans.unexpectedError + ' ' + scTrans.TryAgainLater);
			console.error('Missing SDK parameters.');
			return;
		}

		if(jQuery('#sc_card_holder_name').val() == '') {
			scFormFalse(scTrans.CCNameIsEmpty);
			return;
		}

		if(
			jQuery('#sc_card_number.empty').length > 0
			|| jQuery('#sc_card_number.sfc-complete').length == 0
		) {
			scFormFalse(scTrans.CCNumError);
			return;
		}

		if(
			jQuery('#sc_card_expiry.empty').length > 0
			|| jQuery('#sc_card_expiry.sfc-complete').length == 0
		) {
			scFormFalse(scTrans.CCExpDateError);
			return;
		}

		if(
			jQuery('#sc_card_cvc.empty').length > 0
			|| jQuery('#sc_card_cvc.sfc-complete').length == 0
		) {
			scFormFalse(scTrans.CCCvcError);
			return;
		}

		nuveiPaymentParams.cardHolderName	= document.getElementById('sc_card_holder_name').value;
		nuveiPaymentParams.paymentOption	= sfcFirstField;

		console.log('sfcFirstField', sfcFirstField);
		console.log('nuveiPaymentParams', nuveiPaymentParams);

		// create payment with WebSDK
		sfc.createPayment(nuveiPaymentParams, function(resp) {
			afterSdkResponse(resp);
		});
		return;
	}
	// use CC UPO
	else if(
		typeof jQuery('input[name="sc_payment_method"]:checked').attr('data-upo-name') != 'undefined'
		&& 'cc_card' == jQuery('input[name="sc_payment_method"]:checked').attr('data-upo-name')
	) {
		if(jQuery('#sc_upo_'+ selectedPM +'_cvc.sfc-complete').length == 0) {
			scFormFalse(scTrans.CCCvcError);
			return;
		}
		
		nuveiPaymentParams.paymentOption = {
			userPaymentOptionId: selectedPM,
			card: {
				CVV: cardCvc
			}
		};
		
		// create payment with WebSDK
		sfc.createPayment(nuveiPaymentParams, function(resp){
			afterSdkResponse(resp);
		});
	}
	// use APM data
	else {
		nuveiPaymentParams.paymentOption = {
			alternativePaymentMethod: {
				paymentMethod: selectedPM
			}
		};

		var checkId = 'sc_payment_method_' + selectedPM;

		// iterate over payment fields
		jQuery('#' + checkId).closest('li.apm_container').find('.apm_fields input').each(function(){
			var apmField = jQuery(this);

			if (
				typeof apmField.attr('pattern') != 'undefined'
				&& apmField.attr('pattern') !== false
				&& apmField.attr('pattern') != ''
			) {
				var regex = new RegExp(apmField.attr('pattern'), "i");

				// SHOW error
				if (apmField.val() == '' || regex.test(apmField.val()) == false) {
					formValid = false;
				}
			} else if (apmField.val() == '') {
				formValid = false;
			}

			nuveiPaymentParams.paymentOption.alternativePaymentMethod[apmField.attr('name')] = apmField.val();
		});

		if (!formValid) {
			scFormFalse();
			jQuery('#custom_loader').hide();
			return;
		}

		// direct APMs can use the SDK
		if(jQuery('input[name="sc_payment_method"]:checked').attr('data-nuvei-is-direct') == 'true') {
			sfc.createPayment(nuveiPaymentParams, function(resp){
				afterSdkResponse(resp);
			});

			return;
		}

		// if not using SDK submit form
		jQuery('#place_order').trigger('click');
	}
}

/**
 * 
 * @param {type} resp
 * @returns {undefined}
 */
function afterSdkResponse(resp) {
	console.log('afterSdkResponse', resp);
	console.log(resp);
	
	if (typeof resp.result != 'undefined') {
		console.log('resp.result', resp.result)

		if (resp.result == 'APPROVED' && resp.transactionId != 'undefined') {
			jQuery('#sc_transaction_id').val(resp.transactionId);
			jQuery('#place_order').trigger('click');
			
			closeScLoadingModal();
			return;
		}
		else if (resp.result == 'DECLINED') {
			scFormFalse(scTrans.paymentDeclined + ' ' + scTrans.TryAnotherPM);
		}
		else {
			if (resp.errorDescription != 'undefined' && resp.errorDescription != '') {
				scFormFalse(resp.errorDescription);
			} else {
				scFormFalse(scTrans.paymentError + ' ' + scTrans.TryAgainLater);
			}

			jQuery('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');
			scCard = null;
			getNewSessionToken();
		}
	}
	else {
		scFormFalse(scTrans.unexpectedError + ' ' + scTrans.TryAgainLater);
		console.error('Error with SDK response: ' + resp);

		jQuery('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');
		scCard = null;
		getNewSessionToken();
		return;
	}
}
 
function closeScLoadingModal() {
	jQuery('#sc_loader_background').hide();
}

function scFormFalse(text) {
	console.log('scFormFalse()');
	
	// uncheck radios and hide fileds containers
	jQuery('.sc_payment_method_field').attr('checked', false);
	jQuery('.apm_fields').hide();
	
	if (typeof text == 'undefined') {
		text = scTrans.choosePM;
	}
	
	jQuery('#sc_checkout_messages').html(
	   '<div class="woocommerce-error" role="alert">'
		   +'<strong>'+ text +'</strong>'
	   +'</div>'
	);
	
	jQuery(window).scrollTop(0);
	closeScLoadingModal();
}
 
 /**
  * Function showErrorLikeInfo
  * Show error message as information about the field.
  * 
  * @param {int} elemId
  */
function showErrorLikeInfo(elemId) {
	jQuery('#error_'+elemId).addClass('error_info');

	if (jQuery('#error_'+elemId).css('display') == 'block') {
		jQuery('#error_'+elemId).hide();
	} else {
		jQuery('#error_'+elemId).show();
	}
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
			scFormData	: jQuery('form.woocommerce-checkout').serialize()
		},
		dataType: 'json'
	})
		.fail(function(){
			scFormFalse(scTrans.errorWithPMs);
			return;
		})
		.done(function(resp) {
			console.log(resp);
	
			if (
				resp === null
				|| typeof resp.status == 'undefined'
				|| typeof resp.sessionToken == 'undefined'
			) {
				scFormFalse(scTrans.errorWithSToken + ' ' + scTrans.TryAgainLater);
				return;
			}
			
			if (resp.status == 0) {
				window.location.reload();
				return;
			}
			
			scOpenOrderToken = scData.sessionToken = resp.sessionToken;
			
			sfc			= SafeCharge(scData);
			scFields	= sfc.fields({ locale: locale });
			
			jQuery('#sc_second_step_form .input-radio').prop('checked', false);
			jQuery('.apm_fields, #sc_loader_background').hide();
		});
}

function showNuveiCheckout(params) {
	console.log(params, 'showNuveiCheckout()');
	
	checkout(params);
	
	if(jQuery('.wpmc-step-payment').length > 0) { // multi-step checkout
		console.log('multi-step checkout');
		
		jQuery("form.woocommerce-checkout .wpmc-step-payment *:not(form.woocommerce-checkout, #nuvei_checkout_container *, #sc_checkout_messages), .woocommerce-form-coupon-toggle").hide();
	}
	else { // default checkout
		console.log('default checkout');
		
		jQuery("form.woocommerce-checkout *:not(form.woocommerce-checkout, #nuvei_checkout_container *, #sc_checkout_messages), .woocommerce-form-coupon-toggle").hide();
	}
	
	jQuery("form.woocommerce-checkout #nuvei_checkout_container").show();
	
	jQuery(window).scrollTop(0);
}

jQuery(function() {
	jQuery('body').on('change', 'input[name="sc_payment_method"]', function() {
		console.log('click on APM/UPO');
		
		// hide all containers with fields
		jQuery('.apm_fields').hide();
		
		var currInput		= jQuery(this);
		var filedsToShowId	= currInput.closest('li').find('.apm_fields');
		
		if(undefined !== currInput.attr('data-upo-name')
			|| currInput.val() == 'ppp_ApplePay'
		) {
			jQuery('body').find('#nuvei_save_upo_li').hide();
		}
		else {
			jQuery('body').find('#nuvei_save_upo_li').show();
		}
		
		// reset sc fields holders
		cardNumber = sfcFirstField = cardExpiry = cardCvc = null;
		if(lastCvcHolder !== '') {
			jQuery(lastCvcHolder).html('');
		}
		
		if('undefined' != filedsToShowId) {
			filedsToShowId.slideToggle('fast');
		}
		
		jQuery('button[name="woocommerce_checkout_place_order"]').show();

		// CC - load webSDK fields
		if(currInput.val() == 'cc_card') {
			console.log('CC');
			
			jQuery('#sc_card_number').html('');
			cardNumber = sfcFirstField = scFields.create('ccNumber', {
				classes: elementClasses
				,style: fieldsStyle
			});
			cardNumber.attach('#sc_card_number');

			jQuery('#sc_card_expiry').html('');
			cardExpiry = scFields.create('ccExpiration', {
				classes: elementClasses
				,style: fieldsStyle
			});
			cardExpiry.attach('#sc_card_expiry');

			lastCvcHolder = '#sc_card_cvc';

			jQuery(lastCvcHolder).html('');
			cardCvc = scFields.create('ccCvc', {
				classes: elementClasses
				,style: fieldsStyle
			});
			cardCvc.attach(lastCvcHolder);
		}
		// Apple Pay
		else if(currInput.val() == 'ppp_ApplePay') {
			console.log('Apple Pay');
			
			if(!window.ApplePaySession) {
				jQuery('#nuvei-apple-pay-button').hide();
				jQuery('#nuvei-apple-pay-error, button[name="woocommerce_checkout_place_order"]').show();
				return;
			}
			
//			var merchantIdentifier = 'example.com.store';
//			var promise = ApplePaySession.canMakePaymentsWithActiveCard(merchantIdentifier);
//			
//			promise.then(function(canMakePayments) {
//				// Display Apple Pay Button
//				if(canMakePayments) {
//					jQuery('#nuvei-apple-pay-error').hide();
//					jQuery('#nuvei-apple-pay-button').show();
//				}
//				else {
//					jQuery('#nuvei-apple-pay-button').hide();
//					jQuery('#nuvei-apple-pay-error').show();
//				}
//			});

			jQuery('#nuvei-apple-pay-error, button[name="woocommerce_checkout_place_order"]').hide();
			jQuery('#nuvei-apple-pay-button').show();
		}
		// CC UPO - load webSDK fields
		else if(!isNaN(currInput.val())
			&& typeof currInput.attr('data-upo-name') != 'undefined'
			&& currInput.attr('data-upo-name') === 'cc_card'
		) {
			console.log('CC UPO');
			
			lastCvcHolder = '#sc_upo_' + currInput.val() + '_cvc';

			cardCvc = scFields.create('ccCvc', {
				classes: elementClasses
				,style: fieldsStyle
			});
			cardCvc.attach(lastCvcHolder);
		}

		jQuery('.SfcField').addClass('input-text');
		// load webSDK fields END
	});
	
	// change text on Place order button
	jQuery('form.woocommerce-checkout').on('change', 'input[name=payment_method]', function(){
		if(jQuery('input[name=payment_method]:checked').val() == scTrans.paymentGatewayName) {
			jQuery('#place_order').html(jQuery('#place_order').attr('data-sc-text'));
		}
		else if(jQuery('#place_order').html() == jQuery('#place_order').attr('data-sc-text')) {
			jQuery('#place_order').html(jQuery('#place_order').attr('data-default-text'));
		}
	});
	
	jQuery('body').on('click', '#sc_second_step_form span.dashicons-trash', function(e) {
		e.preventDefault();
		deleteScUpo(jQuery(this).attr('data-upo-id'));
	});
	
	// when on multistep checkout -> APMs view, someone click on previous button
	jQuery('body').on('click', '#wpmc-prev', function() {
		if(jQuery('#sc_second_step_form').css('display') == 'block') {
			jQuery("form.woocommerce-checkout .wpmc-step-payment *:not(.payment_box, form.woocommerce-checkout, #sc_second_step_form *, #sc_checkout_messages), .woocommerce-form-coupon-toggle").show('slow');
			
			jQuery("form.woocommerce-checkout #sc_second_step_form").hide();
			
			jQuery('input[name="payment_method"]').prop('checked', false);
		}
	});
	
	jQuery('body').on('change', '#nuvei_save_upo', function() {
		var _self = jQuery(this);
		
		_self.val(_self.is(':checked') ? 1 : 0);
	});
});
// document ready function END
