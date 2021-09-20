var scSettleBtn		= null;
var scVoidBtn		= null;
//var nuveiPlansList	= JSON.parse(scTrans.nuveiPaymentPlans);

// when the admin select to Settle or Void the Order
function settleAndCancelOrder(question, action, orderId) {
	console.log('settleAndCancelOrder')
	
	if (confirm(question)) {
		jQuery('#custom_loader').show();
		
		var data = {
			action      : 'sc-ajax-action',
			security    : scTrans.security,
			orderId     : orderId
		};
		
		if (action == 'settle') {
			data.settleOrder = 1;
		} else if (action == 'void') {
			data.cancelOrder = 1;
		}
		
		jQuery.ajax({
			type: "POST",
			url: scTrans.ajaxurl,
			data: data,
			dataType: 'json'
		})
			.fail(function( jqXHR, textStatus, errorThrown){
				jQuery('#custom_loader').hide();
				alert('Response fail.');
				
				console.error(textStatus)
				console.error(errorThrown)
			})
			.done(function(resp) {
				console.log(resp);
				
				if (resp && typeof resp.status != 'undefined' && resp.data != 'undefined') {
					if (resp.status == 1) {
						var urlParts    = window.location.toString().split('post.php');
						window.location = urlParts[0] + 'edit.php?post_type=shop_order';
					} else if (resp.data.reason != 'undefined' && resp.data.reason != '') {
						jQuery('#custom_loader').hide();
						alert(resp.data.reason);
					} else if (resp.data.gwErrorReason != 'undefined' && resp.data.gwErrorReason != '') {
						jQuery('#custom_loader').hide();
						alert(resp.data.gwErrorReason);
					} else {
						jQuery('#custom_loader').hide();
						alert('Response error.');
					}
				} else {
					jQuery('#custom_loader').hide();
					alert('Response error.');
				}
			});
	}
}
 
/**
 * Function returnSCSettleBtn
 * Returns the SC Settle button
 */
function returnSCBtns() {
	if (scVoidBtn !== null) {
		jQuery('.wc-order-bulk-actions p').append(scVoidBtn);
		scVoidBtn = null;
	}
	if (scSettleBtn !== null) {
		jQuery('.wc-order-bulk-actions p').append(scSettleBtn);
		scSettleBtn = null;
	}
}

function scCreateRefund(question) {
	console.log('scCreateRefund()');
	
	var refAmount	= jQuery('#refund_amount').val().replaceAll(' ', '');
	refAmount		= refAmount.replaceAll(",", ".");
	refAmount		= refAmount.replace(/\.(?=.*\.)/, '');
	refAmount		= parseFloat(refAmount);
	
	if(isNaN(refAmount) || refAmount < 0.001) {
		jQuery('#refund_amount').css('border-color', 'red');
		jQuery('#refund_amount').on('focus', function() {
			jQuery('#refund_amount').css('border-color', 'inherit');
		});
		
		return;
	}
	
	if (!confirm(question)) {
		return;
	}
	
	jQuery('body').find('#sc_api_refund').prop('disabled', true);
	jQuery('body').find('#sc_refund_spinner').show();
	
	var data = {
		action      : 'sc-ajax-action',
		security	: scTrans.security,
		refAmount	: refAmount,
		postId		: jQuery("#post_ID").val()
	};

	jQuery.ajax({
		type: "POST",
		url: scTrans.ajaxurl,
		data: data,
		dataType: 'json'
	})
		.fail(function( jqXHR, textStatus, errorThrown) {
			jQuery('body').find('#sc_api_refund').prop('disabled', false);
			jQuery('body').find('#sc_refund_spinner').hide();
			
			alert('Response fail.');

			console.error(textStatus)
			console.error(errorThrown)
		})
		.done(function(resp) {
			console.log(resp);

			if (resp && typeof resp.status != 'undefined' && resp.data != 'undefined') {
				if (resp.status == 1) {
					var urlParts    = window.location.toString().split('post.php');
					window.location = urlParts[0] + 'edit.php?post_type=shop_order';
				}
				else if(resp.hasOwnProperty('data')) {
					jQuery('body').find('#sc_api_refund').prop('disabled', false);
					jQuery('body').find('#sc_refund_spinner').hide();
					
					if (resp.data.reason != 'undefined' && resp.data.reason != '') {
						alert(resp.data.reason);
					}
					else if (resp.data.gwErrorReason != 'undefined' && resp.data.gwErrorReason != '') {
						alert(resp.data.gwErrorReason);
					}
				}
				else if(resp.hasOwnProperty('msg') && '' != resp.msg) {
					jQuery('body').find('#sc_api_refund').prop('disabled', false);
					jQuery('body').find('#sc_refund_spinner').hide();
					
					alert(resp.msg);
				}
				else {
					jQuery('body').find('#sc_api_refund').prop('disabled', false);
					jQuery('body').find('#sc_refund_spinner').hide();
					
					alert('Response error.');
				}
			} else {
				alert('Response error.');
				
				jQuery('body').find('#sc_api_refund').prop('disabled', false);
				jQuery('body').find('#sc_refund_spinner').hide();
			}
		});
}

function nuveiGetTodayLog() {
	console.log('nuveiGetTodayLog');
	
	var logTd		= jQuery('#woocommerce_nuvei_doday_log').closest('td');
	var thisLoader	= logTd.find('.custom_loader');
	
	thisLoader.show();
	
	var data = {
		action      : 'sc-ajax-action',
		security	: scTrans.security,
		getLog		: 1
	};
	
	jQuery.ajax({
		type		: "POST",
		url			: scTrans.ajaxurl,
		data		: data,
		dataType	: 'json'
	})
		.fail(function( jqXHR, textStatus, errorThrown) {
			jQuery('#woocommerce_nuvei_doday_log').text(scTrans.RefreshLogError);
			
			thisLoader.hide();

			console.error(textStatus)
			console.error(errorThrown)
		})
		.done(function(resp) {
			console.log(resp);

			if (resp && typeof resp.status != 'undefined' && resp.data != 'undefined') {
				if (resp.status == 0) {
					jQuery('#woocommerce_nuvei_doday_log').text(resp.msg);
				}
				else if(resp.hasOwnProperty('data')) {
					jQuery('#woocommerce_nuvei_doday_log').text(resp.data);
				}
				else {
					jQuery('#woocommerce_nuvei_doday_log').text(scTrans.RefreshLogError);
				}
			}
			else {
				jQuery('#woocommerce_nuvei_doday_log').text(scTrans.RefreshLogError);
			}
			
			thisLoader.hide();
		});
}

function nuvei_show_hide_rest_settings() {
	console.log('nuvei_show_hide_rest_settings()');
	
	var disabled = false;
	
	if(1 == jQuery('#woocommerce_nuvei_use_cashier').val()) {
		disabled = true;
	}
	
	console.log('nuvei_show_hide_rest_settings()', disabled);
	
	jQuery('#woocommerce_nuvei_payment_action, #woocommerce_nuvei_show_apms_names, #woocommerce_nuvei_apple_pay_label, #woocommerce_nuvei_merchant_style')
		.attr('disabled', disabled);

	// hide-show the only Cashier setting
	jQuery('#woocommerce_nuvei_combine_cashier_products')
		.attr('disabled', disabled ? false : true);
}

jQuery(function() {
	// set the flags
	if (jQuery('#sc_settle_btn').length == 1) {
		scSettleBtn = jQuery('#sc_settle_btn');
	}
	
	if (jQuery('#sc_void_btn').length == 1) {
		scVoidBtn = jQuery('#sc_void_btn');
	}
	// set the flags END
	
	// hide Refund button if the status is refunded
	if (
		jQuery('#order_status').val() == 'wc-refunded'
		|| jQuery('#order_status').val() == 'wc-cancelled'
		|| jQuery('#order_status').val() == 'wc-pending'
		|| jQuery('#order_status').val() == 'wc-on-hold'
		|| jQuery('#order_status').val() == 'wc-failed'
	) {
		jQuery('.refund-items').prop('disabled', true);
	}
	
	jQuery('#refund_amount').prop('readonly', false);
	jQuery('.do-manual-refund').remove();
	jQuery('.refund-actions').prepend('<span id="sc_refund_spinner" class="spinner" style="display: none; visibility: visible"></span>');
	
	jQuery('.do-api-refund')
		.attr('id', 'sc_api_refund')
		.attr('onclick', "scCreateRefund('"+ scTrans.refundQuestion +"');")
		.removeClass('do-api-refund');

	// actions about "Download Subscriptions plans" button in Plugin's settings
	if(jQuery('#woocommerce_nuvei_get_plans_btn').length > 0) {
		var butonTd = jQuery('#woocommerce_nuvei_get_plans_btn').closest('td');
		butonTd.find('.custom_loader').hide();
		butonTd.find('fieldset').append('<span class="dashicons dashicons-yes-alt" style="display: none;"></span>');

		if('' != scTrans.scPlansLastModTime) {
			butonTd.find('fieldset').append('<p class="description">'+ scTrans.LastDownload 
				+': '+ scTrans.scPlansLastModTime +'</p>');
		}
		else {
			butonTd.find('fieldset').append('<p class="description"></p>');
		}

		jQuery('#woocommerce_nuvei_get_plans_btn').on('click', function() {
			butonTd.find('.custom_loader').show();
			butonTd.find('fieldset').css('opacity', 0.5);
			
			jQuery.ajax({
				type: "POST",
				url: scTrans.ajaxurl,
				data: {
					action			: 'sc-ajax-action',
					downloadPlans	: 1,
					security		: scTrans.security,
				},
				dataType: 'json'
			})
			.fail(function(jqXHR, textStatus, errorThrown){
				alert(scTrans.RequestFail);
				
				console.error(textStatus);
				console.error(errorThrown);
				
				butonTd.find('.custom_loader').hide();
				butonTd.find('fieldset').css('opacity', 1);
			})
			.done(function(resp) {
				console.log(resp);
				
				if (resp.hasOwnProperty('status') && 1 == resp.status) {
					butonTd.find('fieldset span.dashicons.dashicons-yes-alt').css({
						display :'inline',
						color : 'green'
					});
					
					butonTd.find('fieldset p.description').html(scTrans.LastDownload +': '+ resp.time);
				} else {
					alert('Response error.');
				}
				
				butonTd.find('.custom_loader').hide();
				butonTd.find('fieldset').css('opacity', 1);
			});
		});
	}
	
	// about print daily log
	var logTd = jQuery('#woocommerce_nuvei_doday_log').closest('td');
	
	if(logTd.find('button').length < 1) {
		logTd.css('position', 'relative');
		
		logTd.append(
			'<br/><button id="nuvei_get_log_btn" class="button-secondary" type="button" onclick="nuveiGetTodayLog()">' + scTrans.RefreshLog + '</button>'
			+ '<div class="blockUI blockOverlay custom_loader" style="height: 100%; position: absolute; width: 100%; top: 0px; display: none;"></div>'
		);
	}
	// about print daily log END
	
	// for the Use Cashier... setting
	nuvei_show_hide_rest_settings();
	
	jQuery('#woocommerce_nuvei_use_cashier').on('change', function() {
		nuvei_show_hide_rest_settings();
	});
});
// document ready function END
