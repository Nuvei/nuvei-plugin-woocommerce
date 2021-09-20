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

function deleteScUpo(upoId) {
	if(confirm(scTrans.AskDeleteUpo)) {
		jQuery('#sc_remove_upo_' + upoId).hide();
		jQuery('#sc_loader_background').show();

		jQuery.ajax({
			type: "POST",
			dataType: "json",
			url: scTrans.ajaxurl,
			data: {
				action      : 'sc-ajax-action',
				security	: scTrans.security,
				scUpoId		: upoId
			}
		})
		.done(function(res) {
			console.log('delete UPO response', res);

			if(typeof res.status != 'undefined') {
				if('success' == res.status) {
					jQuery('#upo_cont_' + upoId).remove();
				}
				else {
					scFormFalse(res.msg);
				}
			}
			else {
				jQuery('#sc_remove_upo_' + upoId).show();
			}
		})
		.fail(function(e) {
			jQuery('#sc_remove_upo_' + upoId).show();
		});
		
		closeScLoadingModal();
	}
}

function scPrintApms(data) {
	console.log('scPrintApms()');
	
	if(jQuery('.wpmc-step-payment').length > 0) { // multi-step checkout
		console.log('multi-step checkout');
		
		jQuery("form.woocommerce-checkout .wpmc-step-payment *:not(form.woocommerce-checkout, #sc_second_step_form *, #sc_checkout_messages), .woocommerce-form-coupon-toggle").hide();
	}
	else { // default checkout
		console.log('default checkout');
		
		jQuery("form.woocommerce-checkout *:not(form.woocommerce-checkout, #sc_second_step_form *, #sc_checkout_messages), .woocommerce-form-coupon-toggle").hide();
	}
	
	jQuery("form.woocommerce-checkout #sc_second_step_form").show();
	
	jQuery(window).scrollTop(0);
	jQuery('#lst').val(data.sessonToken);
	
	scOpenOrderToken			= data.sessonToken;
	scUserTokenId				= data.userTokenId;
	scData.sessionToken			= data.sessonToken;
	scData.sourceApplication	= scTrans.webMasterId;
	// for Apple pay
	currencyCode				= data.currencyCode;
	countryCode					= data.countryCode;
	orderAmount					= data.orderAmount;
	applePayLabel				= data.applePayLabel;
	
	// Apple Pay
	if(typeof window.ApplePaySession == 'function' 
		&& typeof data.applePay == 'object' 
		&& Object.keys(data.applePay).length > 0
	) {
		var applePayHtml	= '';
		var pmMsg			= '';
			
		if (data.applePay['paymentMethodDisplayName'].hasOwnProperty(0)
			&& data.applePay['paymentMethodDisplayName'][0].hasOwnProperty('message')
		) {
			pmMsg = data.applePay['paymentMethodDisplayName'][0]['message'];
		}
		// fix when there is no display name
		else if ('' != data.applePay['paymentMethod']) {
			pmMsg = data.applePay['paymentMethod'].replace('apmgw_', '');
			pmMsg = pmMsg.replace('_', ' ');
		}

		var newImg = pmMsg;

		if (data.applePay.hasOwnProperty('logoURL')
			&& data.applePay['logoURL'] != ''
		) {
			newImg = '<img src="' + data.applePay['logoURL'].replace('/svg/', '/svg/solid-white/')
				+ '" alt="' + pmMsg + '" />';
		}
		else {
			newImg = '<img src="' + data.pluginUrl + 'assets/icons/applepay.svg" alt="' + pmMsg + '" style="height: 36px;" />';
		}

		applePayHtml +=
				'<li class="apm_container">'
					+ '<label class="apm_title">'
						+ '<input id="sc_payment_method_' + data.applePay['paymentMethod'] + '" type="radio" class="input-radio sc_payment_method_field" name="sc_payment_method" value="' + data.applePay['paymentMethod'] + '" data-nuvei-is-direct="'
							+ ( typeof data.applePay['isDirect'] != 'undefined' ? data.applePay['isDirect'] : 'false' ) + '" />&nbsp;'
						+ newImg
					+ '</label>';

		applePayHtml += 
			'<div class="apm_fields">'
				+ '<button type="button" id="nuvei-apple-pay-button" onclick="scUpdateCart()">'
					+ '<img src="' + data.pluginUrl + 'assets/icons/ApplePay-Button.png" />'
				+ '</button>'
				+ '<span id="nuvei-apple-pay-error">You can not use Apple Pay. Please try another payment method!</span>'
			+ '</div>'
		+ '</li>';
		
		jQuery('#sc_second_step_form #nuvei_apple_pay').html(applePayHtml);
	}
	else {
		jQuery('#upos_list_title, #nuvei_apple_pay').hide();
	}
	
	// UPOs
	if(Object.keys(data.upos).length > 0) {
		var upoHtml = '';
		
		jQuery('#upos_list_title, #sc_upos_list').show();
		
		for(var i in data.upos) {
			if ('cc_card' == data.upos[i]['paymentMethodName']) {
				var img = '<img src="' + data.pluginUrl + 'assets/icons/visa_mc_maestro.svg" alt="'
					+ data.upos[i]['name'] + '" style="height: 36px;" />';
			} else {
				var img = '<img src="' + data.upos[i].logoURL.replace('/svg/', '/svg/solid-white/')
					+ '" alt="' + data.upos[i]['name'] + '" />';
			}
			
			upoHtml +=
				'<li class="upo_container" id="upo_cont_' + data.upos[i]['userPaymentOptionId'] + '">'
					+ '<label class="apm_title">'
						+ '<input id="sc_payment_method_' + data.upos[i]['userPaymentOptionId'] + '" type="radio" class="input-radio sc_payment_method_field" name="sc_payment_method" value="' + data.upos[i]['userPaymentOptionId'] + '" data-upo-name="' + data.upos[i]['paymentMethodName'] + '" />&nbsp;'
						+ img + '&nbsp;&nbsp;'
						+ '<span>';
			
			// add upo identificator
			if ('cc_card' == data.upos[i]['paymentMethodName']) {
				upoHtml += data.upos[i]['upoData']['ccCardNumber'];
			} else if ('' != data.upos[i]['upoName']) {
				upoHtml += data.upos[i]['upoName'];
			}

			upoHtml +=
						'</span>&nbsp;&nbsp;';
				
			// add remove icon
			upoHtml +=
						'<span id="#sc_remove_upo_' + data.upos[i]['userPaymentOptionId'] + '" class="dashicons dashicons-trash" data-upo-id="' + data.upos[i]['userPaymentOptionId'] + '"></span>'
					+ '</label>';
			
			if ('cc_card' === data.upos[i]['paymentMethodName']) {
					upoHtml +=
						'<div class="apm_fields" id="sc_' + data.upos[i]['userPaymentOptionId'] + '">'
							+ '<div id="sc_upo_' + data.upos[i]['userPaymentOptionId'] + '_cvc"></div>'
						+ '</div>';
				}
				
				upoHtml +=
					'</li>';
		}
		
		jQuery('#sc_second_step_form #sc_upos_list').html(upoHtml);
	}
	else {
		jQuery('#upos_list_title, #sc_upos_list').hide();
	}
	
	// APMs
	if(Object.keys(data.apms).length > 0) {
		var apmHmtl = '';
		
		for(var j in data.apms) {
			var pmMsg = '';
			
			if (
				data.apms[j]['paymentMethodDisplayName'].hasOwnProperty(0)
				&& data.apms[j]['paymentMethodDisplayName'][0].hasOwnProperty('message')
			) {
				pmMsg = data.apms[j]['paymentMethodDisplayName'][0]['message'];
			} else if ('' != data.apms[j]['paymentMethod']) {
				// fix when there is no display name
				pmMsg = data.apms[j]['paymentMethod'].replace('apmgw_', '');
				pmMsg = pmMsg.replace('_', ' ');
			}
			
			var newImg = pmMsg;
			
			if ('cc_card' == data.apms[j]['paymentMethod']) {
				newImg = '<img src="' + data.pluginUrl + 'assets/icons/visa_mc_maestro.svg" alt="'
					+ pmMsg + '" style="height: 36px;" />';
			}
			else if (data.apms[j].hasOwnProperty('logoURL')
				&& data.apms[j]['logoURL'] != ''
			) {
				newImg = '<img src="' + data.apms[j]['logoURL'].replace('/svg/', '/svg/solid-white/')
					+ '" alt="' + pmMsg + '" />';
			}
			else {
				newImg = '<img src="#" alt="' + pmMsg + '" />';
			}
			
			apmHmtl +=
					'<li class="apm_container">'
						+ '<label class="apm_title">'
							+ '<input id="sc_payment_method_' + data.apms[j]['paymentMethod'] + '" type="radio" class="input-radio sc_payment_method_field" name="sc_payment_method" value="' + data.apms[j]['paymentMethod'] + '" data-nuvei-is-direct="'
								+ ( typeof data.apms[j]['isDirect'] != 'undefined' ? data.apms[j]['isDirect'] : 'false' ) + '" />&nbsp;'
							+ newImg;
			
			// optional set APM label
			if(1 == scTrans.showApmsNames) {
				apmHmtl += '&nbsp;&nbsp;';
				
				if(typeof data.apms[j]['paymentMethodDisplayName'][0]['message'] != 'undefined' 
					&& '' != data.apms[j]['paymentMethodDisplayName'][0]['message']
				) {
					apmHmtl += data.apms[j]['paymentMethodDisplayName'][0]['message'];
				}
				else {
					var nuveiApmName = data.apms[j]['paymentMethod'];
					
					nuveiApmName = nuveiApmName.replace('apmgw_', '');
					nuveiApmName = nuveiApmName.replace('ppp_', '');
					nuveiApmName = nuveiApmName.replace('_', ' ');
					
					apmHmtl += nuveiApmName;
				}
			}
			
			apmHmtl +=
						'</label>';
			
			// CC
			if ('cc_card' == data.apms[j]['paymentMethod']) {
				apmHmtl +=
						'<div class="apm_fields" id="sc_' + data.apms[j]['paymentMethod'] + '">'
							+ '<input type="text" id="sc_card_holder_name" name="' + data.apms[j]['paymentMethod'] + '[cardHolderName]" placeholder="' + scTrans.CardHolderName + '" />'

							+ '<div id="sc_card_number"></div>'
							+ '<div id="sc_card_expiry"></div>'
							+ '<div id="sc_card_cvc"></div>';
			}
			// APM with fields
			else if (data.apms[j]['fields'].length > 0) {
				apmHmtl +=
						'<div class="apm_fields">';

				for (var f in data.apms[j]['fields']) {
					var pattern = '';
					if ('' != data.apms[j]['fields'][f]['regex']) {
						pattern = data.apms[j]['fields'][f]['regex'];
					}

					var placeholder = '';
					if (
						data.apms[j]['fields'][f]['caption'].hasOwnProperty(0)
						&& data.apms[j]['fields'][f]['caption'][0].hasOwnProperty('message')
						&& '' != data.apms[j]['fields'][f]['caption'][0]['message']
					) {
						placeholder = data.apms[j]['fields'][f]['caption'][0]['message'];
					} else {
						placeholder = data.apms[j]['fields'][f]['name'].replaceAll('_', ' ');
					}
					
					var field_type = data.apms[j]['fields'][f]['type'];
					if('apmgw_Neteller' == data.apms[j]['paymentMethod']) {
						field_type	= 'email';
						placeholder	= placeholder.replace(/netteler/ig, 'neteller');
					}
					
					apmHmtl +=
							'<input id="' + data.apms[j]['paymentMethod'] + '_' + data.apms[j]['fields'][f]['name']
								+ '" name="' + data.apms[j]['paymentMethod'] + '[' + data.apms[j]['fields'][f]['name'] + ']'
								+ '" type="' + field_type
								+ '" pattern="' + pattern
								+ '" placeholder="' + placeholder
								+ '" autocomplete="new-password" />';
				}
				
				apmHmtl +=
						'</div>';
			}
			
			// Apple Pay
			if('ppp_ApplePay' == data.apms[j]['paymentMethod']) {
				apmHmtl += '<div class="apm_fields">'
					+ '<button type="button" id="nuvei-apple-pay-button" onclick="scUpdateCart()">Pay</button>'
					+ '<span id="nuvei-apple-pay-error">You can not use Apple Pay. Please try another payment method!</span>'
				+ '</div>';
			}
			
			apmHmtl +=
					'</li>';
		}
		
		// save UPO checkout
		if(1 == scTrans.useUpos && 1 == scTrans.isUserLogged) {
			apmHmtl +=
					'<li class="apm_container" id="nuvei_save_upo_li">'
						+ '<label class="apm_title">'
							+ '<input type="checkbox" name="nuvei_save_upo" id="nuvei_save_upo" value="0" />&nbsp;&nbsp;'
							+ scTrans.ConfirmSaveUpo
						+ '</label>'
					+ '</li>';
		}
		
		jQuery('#sc_second_step_form #sc_apms_list').html(apmHmtl);
	}
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
