function scReorder() {
	console.log('scReorder()');
	
	if(jQuery("input[name='payment_method']:checked").attr("id") == "payment_method_sc") {
		if(jQuery("#terms").length > 0) {
			jQuery("#terms").closest(".checkbox").hide();
		}

		jQuery("#place_order").hide();
		jQuery("body").find("#sc_reorder_btn").show();
	}
	else {
		jQuery("#terms").closest(".checkbox").show();
		jQuery("#place_order").show();
		jQuery("body").find("#sc_reorder_btn").hide();
	}
}

function scAddProductsToCart() {
	if(typeof scProductsIdsToReorder == 'undefined' || scProductsIdsToReorder.length == 0) {
		// todo show error
		console.error('there are no products or there are not products IDs!');
		return
	}
	
	jQuery.ajax({
		type: "POST",
		url: scTrans.ajaxurl,
		data: {
			action      : 'sc-ajax-action',
			security    : scTrans.security,
			sc_request	: 'scReorder',
			product_ids	: JSON.stringify(scProductsIdsToReorder)
		},
		dataType: 'json'
	})
		.fail(function(){
			scFormFalse(scTrans.errorWithPMs);
			return;
		})
		.done(function(resp) {
			if(typeof resp == 'undefined' || !resp.hasOwnProperty('status')) {
				
			}
	
			if(resp.status == 0 || !resp.hasOwnProperty('redirect_url')) {
				
			}
			
			window.location = resp.redirect_url;
		});
}

function scOnPayOrderPage() {
	jQuery("#place_order").after('<button type="button" id="sc_reorder_btn" onclick="scAddProductsToCart()">Reorder</button>');

	if(jQuery("input[name='payment_method']:checked").length == 1) {
		scReorder();
	}

	jQuery("input[name='payment_method']").on("change", function(e) {
		scReorder();
	});
}