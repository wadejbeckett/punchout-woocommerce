/* The start link's page sends its form once (0.4.23): node --test tests/Start/start-link.test.js. A minimal DOM double. */
'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/start-link.js'), 'utf8');

function listeners() {
	const map = {};
	return {
		addEventListener: (type, fn) => { (map[type] = map[type] || []).push(fn); },
		fire: (type, event) => { (map[type] || []).forEach((fn) => fn(event)); return event; },
	};
}

function load(mode, { form: present = true } = {}) {
	const button = { disabled: false };
	const attributes = { 'data-pow-start': mode };
	const form = {
		...listeners(),
		submits: 0,
		submit() { this.submits += 1; },
		getAttribute: (name) => (name in attributes ? attributes[name] : null),
		setAttribute: (name, value) => { attributes[name] = value; },
		removeAttribute: (name) => { delete attributes[name]; },
		querySelector: (selector) => (selector === 'button' ? button : null),
	};
	const win = { ...listeners(), setTimeout: (fn) => fn() };
	const document = { getElementById: (id) => (present && id === 'pow-start-form' ? form : null) };
	vm.runInContext(source, vm.createContext({ window: win, document }));
	const submitEvent = () => { const event = { prevented: false, preventDefault() { this.prevented = true; } }; form.fire('submit', event); return event; };
	return { form, button, submitEvent, win, attributes };
}

test('auto: the page sends its form once at load and ignores a later press', () => {
	const page = load('auto');
	assert.equal(page.form.submits, 1);
	assert.equal(page.button.disabled, true);
	assert.equal(page.attributes['aria-busy'], 'true');
	assert.equal(page.submitEvent().prevented, true, 'A press while the first POST is on its way sends nothing more');
	assert.equal(page.form.submits, 1);
});

test('click: nothing is sent until the press, and a second press is ignored', () => {
	const page = load('click');
	assert.equal(page.form.submits, 0);
	assert.equal(page.button.disabled, false);
	assert.equal(page.submitEvent().prevented, false, 'The first press goes through');
	assert.equal(page.button.disabled, true);
	assert.equal(page.submitEvent().prevented, true, 'A double click sends one POST');
	assert.equal(page.form.submits, 0, 'The browser sends the pressed form; the script never submits it again');
});

test('a page restored from history can be pressed again', () => {
	const page = load('click');
	page.submitEvent();
	page.win.fire('pageshow', { persisted: false });
	assert.equal(page.button.disabled, true, 'An ordinary load is a new page anyway');
	page.win.fire('pageshow', { persisted: true });
	assert.equal(page.button.disabled, false);
	assert.equal(page.attributes['aria-busy'], undefined);
	assert.equal(page.submitEvent().prevented, false);
});

test('any other mode waits for the press, and a page without the form is left alone', () => {
	assert.equal(load('').form.submits, 0);
	assert.equal(load('AUTO').form.submits, 0);
	assert.doesNotThrow(() => load('auto', { form: false }));
});
