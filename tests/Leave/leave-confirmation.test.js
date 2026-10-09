/* Cancel and return asks first (0.5.0): node --test tests/Leave/leave-confirmation.test.js. A minimal DOM double. */
'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/leave-confirmation.js'), 'utf8');

function page({ dialogs = true, modal = true, open = false } = {}) {
	const listeners = {};
	const dialog = {
		open,
		calls: [],
		close() { this.calls.push('close'); this.open = false; },
	};
	if (modal) { dialog.showModal = function () { if (this.open) { throw new Error('InvalidStateError'); } this.calls.push('showModal'); this.open = true; }; }
	const document = {
		addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); },
		getElementById: (id) => (dialogs && id === 'pow-leave-1' ? dialog : null),
	};
	vm.runInContext(source, vm.createContext({ document }));
	const submit = (attributes) => {
		const form = { getAttribute: (name) => (name in attributes ? attributes[name] : null) };
		const event = { target: form, prevented: false, preventDefault() { this.prevented = true; } };
		(listeners.submit || []).forEach((fn) => fn(event));
		return event;
	};
	return { dialog, submit };
}

test('pressing Cancel and return opens its dialog instead of leaving', () => {
	const { dialog, submit } = page();
	const event = submit({ 'data-pow-leave': 'pow-leave-1' });
	assert.equal(event.prevented, true, 'Nothing is posted');
	assert.deepEqual(dialog.calls, ['showModal']);
});

test('a dialog already drawn open (the server asked) is reopened as a modal', () => {
	const { dialog, submit } = page({ open: true });
	assert.equal(submit({ 'data-pow-leave': 'pow-leave-1' }).prevented, true);
	assert.deepEqual(dialog.calls, ['close', 'showModal']);
});

test('every other form, and the dialog\'s own leave form, posts as it is', () => {
	const { dialog, submit } = page();
	assert.equal(submit({}).prevented, false, 'A form without data-pow-leave');
	assert.equal(submit({ 'data-pow-leave': 'pow-leave-9' }).prevented, false, 'A dialog that is not on the page: the server asks instead');
	assert.deepEqual(dialog.calls, []);
});

test('a browser without dialogs posts, and the server asks instead', () => {
	const { submit } = page({ modal: false });
	assert.equal(submit({ 'data-pow-leave': 'pow-leave-1' }).prevented, false);
});
