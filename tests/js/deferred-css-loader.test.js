const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(path.join(__dirname, '../../assets/js/deferred-css.js'), 'utf8');

for (const cached of [false, true]) test('deferred stylesheet applies in place after ' + (cached ? 'an early/cached' : 'a later') + ' load', t => {
	const dom = new JSDOM('<head><link id="first" rel="stylesheet" media="all"><link id="deferred" rel="stylesheet" media="print" data-gt-pb-deferred><style id="last">p{color:red}</style></head>', { runScripts: 'outside-only' });
	t.after(() => dom.window.close());
	const { window } = dom;
	const link = window.document.getElementById('deferred');
	if (cached) Object.defineProperty(link, 'sheet', { value: {} });
	window.eval(source);
	if (!cached) {
		assert.equal(link.media, 'print', 'Do not make a pending stylesheet block screen rendering.');
		link.dispatchEvent(new window.Event('load'));
	}
	assert.equal(link.media, 'all');
	assert.equal(link.hasAttribute('data-gt-pb-deferred'), false);
	assert.equal(link.previousElementSibling.id, 'first');
	assert.equal(link.nextElementSibling.id, 'last');
	assert.equal(window.document.querySelectorAll('link').length, 2);
	window.eval(source);
	assert.equal(link.media, 'all', 'A repeated loader must be harmless.');
});

test('normal and third-party print styles are untouched', t => {
	const dom = new JSDOM('<link rel="stylesheet" media="all"><link rel="stylesheet" media="print">', { runScripts: 'outside-only' });
	t.after(() => dom.window.close());
	dom.window.eval(source);
	assert.deepEqual([...dom.window.document.querySelectorAll('link')].map(x => x.media), ['all', 'print']);
});
