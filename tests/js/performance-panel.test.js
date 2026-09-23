const test = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../assets/js/performance.js'), 'utf8');
function fixture(t) {
	const dom = new JSDOM('<button id="open">Performance</button>', { url: 'https://test.local/', runScripts: 'outside-only' });
	t.after(() => dom.window.close());
	const { window } = dom;
	window.HTMLDialogElement.prototype.showModal = function() { this.open = true; };
	window.HTMLDialogElement.prototype.close = function() { this.open = false; this.dispatchEvent(new window.Event('close')); };
	const requests = [];
	window.fetch = (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve: data => resolve({ json: () => Promise.resolve(data) }), reject }));
	window.eval(source);
	const opener = window.document.getElementById('open');
	return { window, requests, opener, open: () => window.gtPbPerformance.open({ endpoint: '/ajax', postId: 5, nonce: 'nonce', scope: 'section' }, [{ css: '.a{}' }], opener), settle: () => new Promise(resolve => setImmediate(resolve)) };
}
function result(label = 'Hero') {
	return { success: true, data: { totals: { css_requests: 1, loader_requests: 1 }, notes: ['Not a speed score.'], rows: [{ label, mode: 'Deferred file', css_bytes: 1024, css_minified_bytes: 500, js_bytes: 0, js_minified_bytes: 0, notes: ['Check hero styles.'] }] } };
}

test('panel submits current section code read-only and renders untrusted labels as text', async t => {
	const f = fixture(t); f.open(); f.open();
	assert.equal(f.requests.length, 1);
	const form = new URLSearchParams(f.requests[0].options.body);
	assert.equal(form.get('action'), 'gt_pb_performance');
	assert.equal(form.get('scope'), 'section');
	assert.equal(form.get('post_id'), '5');
	assert.deepEqual(JSON.parse(form.get('sections')), [{ css: '.a{}' }]);
	f.requests[0].resolve(result('<img src=x onerror=alert(1)>'));
	await f.settle();
	assert.equal(f.window.document.querySelectorAll('dialog img').length, 0);
	assert.match(f.window.document.querySelector('dialog').textContent, /<img src=x/);
	assert.match(f.window.document.querySelector('table').textContent, /1.0 KiB \/ 500 B/);
	assert.equal(f.window.document.querySelector('th').scope, 'col');
});

test('closing an in-flight analysis aborts it and restores focus', async t => {
	const f = fixture(t); f.open();
	const request = f.requests[0];
	f.window.document.querySelector('dialog button').click();
	assert.equal(request.options.signal.aborted, true);
	assert.equal(f.window.document.activeElement, f.opener);
	request.resolve(result()); await f.settle();
	assert.equal(f.window.document.querySelector('dialog'), null);
});

for (const kind of ['network', 'invalid payload']) test(kind + ' error is visible and retry works', async t => {
	const f = fixture(t); f.open();
	if (kind === 'network') f.requests[0].reject(new Error('Offline'));
	else f.requests[0].resolve({ success: true });
	await f.settle();
	assert.ok(f.window.document.querySelector('[role="alert"]'));
	[...f.window.document.querySelectorAll('dialog button')].find(x => x.textContent === 'Retry').click();
	assert.equal(f.requests.length, 2);
	f.requests[1].resolve(result()); await f.settle();
	assert.ok(f.window.document.querySelector('table'));
	assert.equal(f.window.document.querySelector('[role="alert"]'), null);
});
