var scSettleBtn = null;
var scVoidBtn   = null;

try {
	var nuveiPlans = JSON.parse(scTrans.nuveiPaymentPlans);
} catch (e) {
	var nuveiPlans = {};
}

// when the admin select to Settle, Void or Cancel Subscription actions
function nuveiAction(question, action, orderId, subscrId) {
	console.log('settleAndCancelOrder')
	
	if (!confirm(question)) {
        return;
    }
		
    jQuery('#custom_loader').show();

    var data = {
        action: 'sc-ajax-action',
        nuveiSecurity: scTrans.nuveiSecurity,
        orderId: orderId
    };

    if (action == 'settle') {
        data.settleOrder = 1;
    }
    else if (action == 'void') {
        data.cancelOrder = 1;
    }
    else if ('cancelSubscr' == action) {
        data.cancelSubs = 1;
        data.subscrId   = subscrId;
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

/**
 * Returns the Settle button.
 */
function nuveiReturnNuveiBtns() {
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
		action: 'sc-ajax-action',
		nuveiSecurity: scTrans.nuveiSecurity,
		refAmount: refAmount,
		postId: jQuery("#post_ID").val()
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

function nuvei_show_hide_rest_settings() {
    if('sdk' == jQuery('#woocommerce_nuvei_integration_type').val()) {
		jQuery('.nuvei_cashier_setting').closest('tr').hide();
        jQuery('.nuvei_checkout_setting').closest('tr').show();
	}
    else {
        jQuery('.nuvei_checkout_setting').closest('tr').hide();
        jQuery('.nuvei_cashier_setting').closest('tr').show();
    }
}   

function switchNuveiTabs() {
	if('' == window.location.hash) {
		jQuery('#nuvei_base_settings_tab').addClass('nav-tab-active');
		jQuery('#nuvei_base_settings_cont').show();
	}
	else {
		jQuery('.nuvei_settings_tabs').removeClass('nav-tab-active');
		jQuery('.nuvei_checkout_settings_cont').hide();
		
		jQuery(window.location.hash + '_tab').addClass('nav-tab-active');
		jQuery(window.location.hash + '_cont').show();
	}
}

function nuveiSyncPaymentPlans() {
	var butonTd = jQuery('#woocommerce_nuvei_get_plans_btn').closest('td');
	
	butonTd.find('.custom_loader').show();
			
	jQuery.ajax({
		type: "POST",
		url: scTrans.ajaxurl,
		data: {
			action: 'sc-ajax-action',
			downloadPlans: 1,
			nuveiSecurity: scTrans.nuveiSecurity,
		},
		dataType: 'json'
	})
	.fail(function(jqXHR, textStatus, errorThrown){
		alert(scTrans.RequestFail);

		console.error(textStatus);
		console.error(errorThrown);

		butonTd.find('.custom_loader').hide();
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
	});
}

/**
 * @deprecated since v3.2.3
 */
function nuveiDisablePm(_value) {
    console.log('nuveiDisablePm', _value);
    
    var selectedOptionId    = '#nuvei_block_pm_' + _value;
	var selectedPMs			= jQuery('#woocommerce_nuvei_pm_black_list').val();
	var selectedPMsVisible	= jQuery('#woocommerce_nuvei_pm_black_list_visible').val();
    
    jQuery(selectedOptionId).hide();
    
	// fill the hidden input
	if('' == selectedPMs) {
		selectedPMs += _value;
	}
	else {
		selectedPMs += ',' + _value;
	}
	
	// fill the visible input
	if('' == selectedPMsVisible) {
		selectedPMsVisible += jQuery(selectedOptionId).text();
	}
	else {
		selectedPMsVisible += ', ' + jQuery(selectedOptionId).text();
	}
	
	document.getElementById('nuvei_block_pms_multiselect').selectedIndex  = 0;
	
	jQuery('#woocommerce_nuvei_pm_black_list').val(selectedPMs);
	jQuery('#woocommerce_nuvei_pm_black_list_visible').val(selectedPMsVisible);
}

function nuveiCleanBlockedPMs() {
	jQuery('#woocommerce_nuvei_pm_black_list, #woocommerce_nuvei_pm_black_list_visible').val('');
	jQuery('#nuvei_block_pms_multiselect option').show();
    
    // activate "Save changes" button when change the selected Block Payment Methods
    jQuery(".woocommerce-save-button").removeAttr("disabled");
}

/**
 * Function for the terms form.
 * 
 * @param int _planId
 * @returns void
 */
function nuveiFillPlanData(_planId) {
    if('' == _planId) {
        return;
    }

    for(var nuveiPlData in nuveiPlans) {
        if(_planId == nuveiPlans[nuveiPlData].planId) {
            // Recurring Amount
            jQuery('#recurringAmount').val(nuveiPlans[nuveiPlData].recurringAmount);

            // Recurring Units and Period
            if(nuveiPlans[nuveiPlData].recurringPeriod.year > 0) {
                jQuery('#recurringPeriodUnit').val('year');
                jQuery('#recurringPeriodPeriod').val(nuveiPlans[nuveiPlData].recurringPeriod.year);
            }
            else if(nuveiPlans[nuveiPlData].recurringPeriod.month > 0) {
                jQuery('#recurringPeriodUnit').val('month');
                jQuery('#recurringPeriodPeriod').val(nuveiPlans[nuveiPlData].recurringPeriod.month);
            }
            else {
                jQuery('#recurringPeriodUnit').val('day');
                jQuery('#recurringPeriodPeriod').val(nuveiPlans[nuveiPlData].recurringPeriod.day);
            }

            // Recurring End-After Units and Period
            if(nuveiPlans[nuveiPlData].endAfter.year > 0) {
                jQuery('#endAfterUnit').val('year');
                jQuery('#endAfterPeriod').val(nuveiPlans[nuveiPlData].endAfter.year);
            }
            else if(nuveiPlans[nuveiPlData].endAfter.month > 0) {
                jQuery('#endAfterUnit').val('month');
                jQuery('#endAfterPeriod').val(nuveiPlans[nuveiPlData].endAfter.month);
            }
            else {
                jQuery('#endAfterUnit').val('day');
                jQuery('#endAfterPeriod').val(nuveiPlans[nuveiPlData].endAfter.day);
            }

            // Recurring Trial Units and Period
            if(nuveiPlans[nuveiPlData].startAfter.year > 0) {
                jQuery('#startAfterUnit').val('year');
                jQuery('#startAfterPeriod').val(nuveiPlans[nuveiPlData].startAfter.year);
            }
            else if(nuveiPlans[nuveiPlData].startAfter.month > 0) {
                jQuery('#startAfterUnit').val('month');
                jQuery('#startAfterPeriod').val(nuveiPlans[nuveiPlData].startAfter.month);
            }
            else {
                jQuery('#startAfterUnit').val('day');
                jQuery('#startAfterPeriod').val(nuveiPlans[nuveiPlData].startAfter.day);
            }

            break;
        }
    }
}

function nuveiPfwDisableRefundBtn() {
    jQuery(".refund-item, .refund-items").prop("disabled", true);
}

jQuery(function() {
	// if the Order does not belongs to Nuvei stop here.
	if(typeof notNuveiOrder != 'undefined' && notNuveiOrder) {
		return;
	}

	if(jQuery('#nuvei_block_pms_multiselect').length > 0) {
		document.getElementById('nuvei_block_pms_multiselect').selectedIndex  = 0;
	}
	
	jQuery('#woocommerce_nuvei_blocked_pms').val('');
	
	switchNuveiTabs();
	
	// set the flags
	if (jQuery('#sc_settle_btn').length == 1) {
		scSettleBtn = jQuery('#sc_settle_btn');
	}
	
	if (jQuery('#sc_void_btn').length == 1) {
		scVoidBtn = jQuery('#sc_void_btn');
	}
	// set the flags END
	
	jQuery('#refund_amount').prop('readonly', false);
	jQuery('.do-manual-refund').remove();
	jQuery('.refund-actions').prepend('<span id="sc_refund_spinner" class="spinner" style="display: none; visibility: visible"></span>');
	
	jQuery('.do-api-refund')
		.attr('id', 'sc_api_refund')
		.attr('onclick', "scCreateRefund('"+ scTrans.refundQuestion +"');")
		.removeClass('do-api-refund');

	// for the Use Cashier... setting
	nuvei_show_hide_rest_settings();
	
	jQuery('#woocommerce_nuvei_integration_type').on('change', function() {
		nuvei_show_hide_rest_settings();
	});
    
});
// document ready function END

window.onhashchange = function() {
	switchNuveiTabs();
}
