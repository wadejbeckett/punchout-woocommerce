/** Cart button presentation through WooCommerce's public filters. @license AGPL-3.0-or-later */
(function () {
	'use strict';

	const config = window.powCartBlocks;
	const checkout = window.wc && window.wc.blocksCheckout;
	if (!config || config.restricted !== true || !checkout || typeof checkout.registerCheckoutFilters !== 'function') {
		return;
	}

	const label = typeof config.label === 'string' && config.label.trim() ? config.label : 'Punchout';
	let link = '#';
	try {
		if (typeof config.confirmUrl === 'string' && config.confirmUrl.trim()) {
			const url = new URL(config.confirmUrl, window.location.href);
			if (url.origin === window.location.origin && /^https?:$/.test(url.protocol) &&
				!url.username && !url.password && !url.search && !url.hash && url.pathname.endsWith('/punchout/confirm')) {
				link = url.href;
			}
		}
	} catch (error) {
		// Invalid restricted configuration cannot fall back to a checkout target.
	}

	// The native button remains a link: GET opens the authenticated review.
	// These filters grant no checkout/return permission; server guards still apply.
	checkout.registerCheckoutFilters('punchout-woocommerce', {
		proceedToCheckoutButtonLabel: function () { return label; },
		proceedToCheckoutButtonLink: function () { return link; }
	});
}());
