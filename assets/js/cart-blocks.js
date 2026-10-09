/** Cart button presentation through WooCommerce's public filters. @license AGPL-3.0-or-later */
(function () {
	'use strict';

	const config = window.powCartBlocks;
	const checkout = window.wc && window.wc.blocksCheckout;
	if (!config || config.restricted !== true || !checkout || typeof checkout.registerCheckoutFilters !== 'function') {
		return;
	}

	const label = typeof config.label === 'string' && config.label.trim() ? config.label : null;

	// In a visit (0.5.0) the native button keeps its own link to checkout, where PunchOut is the only payment method,
	// and carries the Checkout button label. The filter grants nothing; the server's guards still decide.
	checkout.registerCheckoutFilters('punchout-woocommerce', {
		proceedToCheckoutButtonLabel: function (value) { return label === null ? value : label; }
	});
}());
