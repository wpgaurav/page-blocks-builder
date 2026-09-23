const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const shell = fs.readFileSync(path.join(__dirname, '../../assets/js/builder-shell.js'), 'utf8');
const preview = fs.readFileSync(path.join(__dirname, '../../assets/js/preview-dom.js'), 'utf8');

function builder(t, section) {
	const page = new JSDOM('<div id="md-pb-builder-app"></div>', { url: 'https://builder.test/', runScripts: 'outside-only' });
	t.after(() => page.window.close());
	const { window } = page;
	const timers = new Map();
	let id = 0;
	window.setTimeout = (fn, delay) => { timers.set(++id, { fn, delay }); return id; };
	window.clearTimeout = id => timers.delete(id);
	window.requestAnimationFrame = () => {};
	window.mdPbBuilder = { postId: 42, saveNonce: 'fixture', saveEndpoint: '/save', initialSections: [
		{ content: '<section id="fixture">Section</section>', css: '#fixture{color:red}', ...section }
	] };
	let saved;
	window.fetch = (url, request) => {
		saved = JSON.parse(new URLSearchParams(request.body).get('sections'));
		return new Promise(() => {});
	};
	window.eval(preview);
	window.eval(shell);
	window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
	return {
		window,
		control: role => window.document.querySelector('[data-role="' + role + '"]'),
		draft: () => {
			for (const timer of timers.values()) if (timer.delay === 5000) timer.fn();
			return JSON.parse(window.localStorage.getItem('md_pb_draft_42_u0'));
		},
		saved: () => saved
	};
}

test('defer is opt-in, available only in file mode, and survives draft and save payloads', t => {
	const b = builder(t, {});
	assert.equal(b.control('css-defer').closest('label').hidden, true);
	assert.equal(b.control('css-defer').checked, false);
	b.control('css-file').click();
	assert.equal(b.control('css-defer').closest('label').hidden, false);
	b.control('css-defer').click();
	assert.equal(b.draft().sections[0].cssDefer, true);
	b.control('apply').click();
	assert.equal(b.saved()[0].cssDefer, true);
	assert.equal(b.saved()[0].cssOutput, 'file');
});

test('saved defer setting loads and switching to inline hides it without losing the preference', t => {
	const b = builder(t, { cssOutput: 'file', cssDefer: true });
	assert.equal(b.control('css-defer').checked, true);
	b.control('css-file').click();
	assert.equal(b.control('css-defer').closest('label').hidden, true);
	b.control('apply').click();
	assert.equal(b.saved()[0].cssOutput, 'inline');
	assert.equal(b.saved()[0].cssDefer, true);
});

test('linked library sections cannot change the defer setting', t => {
	const b = builder(t, { blockId: 10, cssOutput: 'file' });
	assert.equal(b.control('css-defer').disabled, true);
});
