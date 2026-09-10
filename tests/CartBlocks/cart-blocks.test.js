/* Isolated public-filter behavior: node --test tests/CartBlocks/cart-blocks.test.js. */
'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const file = path.resolve(__dirname, '../../assets/js/cart-blocks.js');

function load(config, api = true) {
	const registrations = [];
	const window = { location: { href: 'https://shop.example.test/store/cart/', origin: 'https://shop.example.test' }, powCartBlocks: config };
	if (api) window.wc = { blocksCheckout: { registerCheckoutFilters: (name, filters) => registrations.push({ name, filters }) } };
	const context = vm.createContext({ window, URL });
	// The pre-fix baseline has no extension asset and therefore leaves native defaults intact.
	vm.runInContext(fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '', context);
	const apply = (key, value, args) => registrations.reduce((result, entry) => entry.filters[key] ? entry.filters[key](result, {}, args) : result, value);
	return { registrations, apply, window };
}

const restricted = { restricted: true, label: 'Send requisition', confirmUrl: 'https://shop.example.test/store/punchout/confirm' };

test('restricted first render and later cart refreshes use one native confirmation button', () => {
	const client = load(restricted);
	for (const args of [undefined, { cart: { items: [{ id: 11 }] } }, { cart: { items: [] } }]) {
		assert.equal(client.apply('proceedToCheckoutButtonLabel', 'Proceed to Checkout', args), 'Send requisition');
		assert.equal(client.apply('proceedToCheckoutButtonLink', '/checkout/', args), restricted.confirmUrl);
	}
	assert.equal(client.registrations.length, 1);
	assert.equal(client.apply('placeOrderButtonLabel', 'Place order'), 'Place order');
});

test('ordinary, dual exit and missing configuration preserve native filters', () => {
	for (const config of [undefined, null, {}, { ...restricted, restricted: false }, { ...restricted, restricted: 'true' }]) {
		const client = load(config);
		assert.equal(client.apply('proceedToCheckoutButtonLabel', 'Proceed to Checkout'), 'Proceed to Checkout');
		assert.equal(client.apply('proceedToCheckoutButtonLink', '/checkout/'), '/checkout/');
		assert.equal(client.registrations.length, 0);
	}
});

test('restricted malformed or foreign URLs cannot preserve a checkout or credential-bearing target', () => {
	for (const confirmUrl of [undefined, '', '/checkout/', 'https://elsewhere.example.test/punchout/confirm', 'javascript:alert(1)', 'https://user:invented@shop.example.test/store/punchout/confirm', 'https://shop.example.test/store/punchout/confirm?token=invented', 'https://shop.example.test/store/punchout/confirm#token', 'http://shop.example.test/store/punchout/confirm']) {
		const client = load({ ...restricted, confirmUrl });
		assert.equal(client.apply('proceedToCheckoutButtonLink', '/checkout/'), '#');
	}
});

test('configured label remains text data and a blank label has a usable fallback', () => {
	const label = '</script><script>example & "label"</script>';
	assert.equal(load({ ...restricted, label }).apply('proceedToCheckoutButtonLabel', 'Checkout'), label);
	for (const invalid of [undefined, null, '', '   ', {}]) assert.equal(load({ ...restricted, label: invalid }).apply('proceedToCheckoutButtonLabel', 'Checkout'), 'Punchout');
});

test('absent or incomplete Blocks API is a bounded no-op without timers or DOM dependencies', () => {
	assert.doesNotThrow(() => load(restricted, false));
	for (const wc of [{}, { blocksCheckout: {} }, { blocksCheckout: { registerCheckoutFilters: null } }]) {
		assert.doesNotThrow(() => vm.runInNewContext(fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '', { window: { wc, powCartBlocks: restricted }, URL }));
	}
});
