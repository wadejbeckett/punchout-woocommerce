/* The review form is sent once (0.4.22): node --test tests/Review/delivery-review.test.js. A minimal DOM double. */
'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/delivery-review.js'), 'utf8');

function listeners() {
	const map = {};
	return {
		addEventListener: (type, fn) => { (map[type] = map[type] || []).push(fn); },
		fire: (type, event) => { (map[type] || []).forEach((fn) => fn(event)); return event; },
	};
}

function button(value, disabled = false) {
	return { name: 'pow_delivery_action', value, disabled, style: {}, parentNode: { insertBefore() {} }, getAttribute: () => 'Updating…' };
}

function load({ submitDisabled = false } = {}) {
	const review = button('review');
	const submit = button('submit', submitDisabled);
	const back = button('back');
	const events = listeners();
	const attributes = {};
	const form = {
		...events,
		setAttribute: (name, value) => { attributes[name] = value; },
		removeAttribute: (name) => { delete attributes[name]; },
		querySelector: (selector) => (selector.includes('"review"') ? review : null),
		querySelectorAll: (selector) => (selector.includes('"submit"') ? [submit, back] : []),
		appendChild() {},
	};
	const timers = [];
	const win = { ...listeners(), setTimeout: (fn) => timers.push(fn) };
	const document = {
		getElementById: () => null,
		querySelector: () => form,
		createElement: () => ({ setAttribute() {}, textContent: '' }),
	};
	vm.runInContext(source, vm.createContext({ window: win, document, Array }));
	const submitEvent = () => { const event = { prevented: false, preventDefault() { this.prevented = true; } }; form.fire('submit', event); return event; };
	const tick = () => { while (timers.length) { timers.shift()(); } };
	return { submit, back, submitEvent, tick, win, attributes };
}

test('the first submission goes through and a second one is ignored', () => {
	const page = load();
	assert.equal(page.submitEvent().prevented, false);
	assert.equal(page.submit.disabled, false, 'Still enabled while the browser collects the clicked button');
	page.tick();
	assert.equal(page.submit.disabled, true);
	assert.equal(page.back.disabled, true);
	assert.equal(page.attributes['aria-busy'], 'true');
	assert.equal(page.submitEvent().prevented, true, 'A double click sends nothing more');
});

test('a page restored from history can be used again, keeping a server-disabled Submit disabled', () => {
	const page = load({ submitDisabled: true });
	page.submitEvent(); page.tick();
	page.win.fire('pageshow', { persisted: false });
	assert.equal(page.back.disabled, true, 'An ordinary load is a new page anyway');
	page.win.fire('pageshow', { persisted: true });
	assert.equal(page.back.disabled, false);
	assert.equal(page.submit.disabled, true, 'Nothing to confirm yet: Submit stays as the server drew it');
	assert.equal(page.attributes['aria-busy'], undefined);
	assert.equal(page.submitEvent().prevented, false);
});
