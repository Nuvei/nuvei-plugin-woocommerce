/* 
 * This is an example for the users of Breakdance and multi-step checkout,
 * how to use nuveiPfw:onPageLoadEvent event on the checkout.
 * The problem - Simply Connect have to load on the last step, when its
 * container is visible.
 * The events are for the Classic Checkout only!
 * You can add to following events in a plugin like WP Header and Footer,
 * but we recommend to place them in the footer!
 * Don't foreget to add the script tags - <script>...</script>!
 */

// Event 1 - in nuveiIsCheckoutClassicFormValid() the we have to stop the process
// in case Simply Connect container is not visible.
document.addEventListener('nuveiPfw:isCheckoutClassicFormValidEvent', function(e) {
    console.log('nuveiPfw:isCheckoutClassicFormValidEvent');
    
    if ( jQuery('.breakdance').length && ! jQuery('#nuvei_checkout_container').is(':visible') ) {
        console.log('#nuvei_checkout_container is not visible');
        e.preventDefault();
    }
});

// Event 2 - in the jQuery(function($) and sdk check, after all plugins events
// we add mutation observer to find when the sdk container will be visible.
document.addEventListener('nuveiPfw:onPageLoadEvent', function() {
	console.log('nuveiPfw:onPageLoadEvent');

    // Breakdance multi-step checkout: the #payment / #nuvei_checkout_container
    // elements are not directly hidden — an ancestor is. IntersectionObserver
    // only tracks scroll-based viewport changes and won't fire on CSS class/style
    // toggling. MutationObserver watching ancestor attributes + jQuery :visible
    // (which traverses the full parent chain) is the reliable solution.
	if ( jQuery('.breakdance').length ) {
		let nuveiStepLoaded  = false;
		let nuveiVisTimer    = null;

		const nuveiOnBreakdanceMutation = function() {
			clearTimeout(nuveiVisTimer);

			nuveiVisTimer = setTimeout(function() {
                const $payment = jQuery('#payment');

                if ( !$payment.length ) {
                    return;
                }

                if ( $payment.is(':visible') && !nuveiStepLoaded ) {
                    console.log('Nuvei: payment step became visible (Breakdance)');

                    let currPm = jQuery(nuveiCheckoutClassicPMethodName + ':checked').val();

                    if ( currPm == scTrans.paymentGatewayName && nuveiIsCheckoutClassicFormValid(true) ) {
                        nuveiStepLoaded = true;
                        nuveiGetCheckoutData(nuveiCheckoutClassicFormClass);
                    }
                }
                else if ( !$payment.is(':visible') ) {
                    // User navigated back — allow re-init on next forward step.
                    nuveiStepLoaded = false;
                }
            }, 50 );
        };

        // Watch each ancestor of #payment for class/style attribute changes.
        // This is more targeted than a full subtree scan and covers all the
        // ways Breakdance can show/hide a step (toggling a class, inline style, etc.).
        const nuveiWatchAncestors = function() {
            const paymentEl = document.querySelector('#payment');

            if ( !paymentEl ) {
                return;
            }

            const ancestorObserver = new MutationObserver(nuveiOnBreakdanceMutation);

            let node = paymentEl.parentElement;

            while ( node && node !== document.documentElement ) {
                ancestorObserver.observe(node, { attributes: true, attributeFilter: ['class', 'style'] });
                node = node.parentElement;
            }
        };

		// #payment should already be in the DOM on a WC classic checkout page,
		// but guard with a childList watcher just in case.
		if ( document.querySelector('#payment') ) {
			nuveiWatchAncestors();
		}
		else {
			const nuveiDomWatcher = new MutationObserver(function() {
                if ( document.querySelector('#payment') ) {
                    nuveiDomWatcher.disconnect();
                    nuveiWatchAncestors();
                }
            });

            nuveiDomWatcher.observe(document.body, { childList: true, subtree: true });
		}
	}
});