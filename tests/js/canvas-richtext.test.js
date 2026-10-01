const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const canvas = require('../../assets/js/canvas-editor.js');
const layout = require('../../assets/js/canvas-layout.js');

function documentFor(t) {
	const page = new JSDOM('');
	t.after(() => page.window.close());
	return page.window.document;
}

test('browser paragraph and div line breaks survive inline normalization', t => {
	const doc = documentFor(t);
	for (const [html, expected] of [
		['First<div>Second</div><div>Third</div>', 'First<br>Second<br>Third'],
		['<div>First</div><div>Second</div>', 'First<br>Second'],
		['First<div><br></div><div>Third</div>', 'First<br><br>Third'],
		['First<br><div>Second</div>', 'First<br>Second'],
		['<div>First<br></div><div>Second</div>', 'First<br>Second'],
		['<p>First</p>\n<p><strong>Second</strong></p>', 'First<br><strong>Second</strong>'],
		['<div>First<div>Second</div>Third</div>', 'First<br>Second<br>Third'],
	]) assert.equal(canvas.cleanInline(html, doc), expected);
});

test('multiline normalization retains allowed formatting but removes unsafe content and links', t => {
	const doc = documentFor(t);
	const html = 'First<div><strong onclick="bad()">Second</strong> <a href="/about/" target="_blank" rel="noopener" style="color:red">link</a></div><div><a href="jav&#x61;script:alert(1)" onfocus="bad()">unsafe</a><script>bad()</script><iframe src="/bad">bad</iframe><svg onload="bad()"><text>bad</text></svg><img src=x onerror="bad()"></div>';
	assert.equal(canvas.cleanInline(html, doc), 'First<br><strong>Second</strong> <a href="/about/" target="_blank" rel="noopener">link</a><br><a>unsafe</a>');
	assert.equal(canvas.cleanInline('<a href="data:text/html,bad">bad</a><a href="mailto:a@example.test">mail</a>', doc), '<a>bad</a><a href="mailto:a@example.test">mail</a>');
});

test('plain text edits across line breaks retain unchanged links and emphasis', t => {
	const doc = documentFor(t);
	const html = 'A <strong>bold</strong> title<br>Visit <a href="/about/">our site</a><br><em>Today</em>';
	assert.equal(canvas.editPlainText(html, 'A bold title\nVisit our site\nToday', doc), html);
	assert.equal(canvas.editPlainText(html, 'A bold heading\nVisit our site\nToday', doc), 'A <strong>bold</strong> heading<br>Visit <a href="/about/">our site</a><br><em>Today</em>');
	assert.equal(canvas.editPlainText(html, 'A bold title\nVisit our site\nTomorrow', doc), 'A <strong>bold</strong> title<br>Visit <a href="/about/">our site</a><br><em>Tomorrow</em>');
});

test('plain text edits can insert and remove line breaks without flattening surrounding formatting', t => {
	const doc = documentFor(t);
	assert.equal(canvas.editPlainText('<strong>First</strong><br><a href="/next/">Second</a>', 'First\n\nSecond', doc), '<strong>First</strong><br><br><a href="/next/">Second</a>');
	assert.equal(canvas.editPlainText('<strong>First</strong><br><a href="/next/">Second</a>', 'First Second', doc), '<strong>First</strong> <a href="/next/">Second</a>');
	assert.equal(canvas.editPlainText('<strong>First</strong><br><em>Second</em>', '<img src=x>\nSecond', doc), '<strong>&lt;img src=x&gt;</strong><br><em>Second</em>');
});

function bridge(t, body, type = 'core/paragraph', tag = 'p') {
	const page = new JSDOM('<section data-pb-section="pb-group" data-pb-root-index="0"><' + tag + ' data-pb-canvas-section="pb-group" data-pb-canvas-path="0.0" data-pb-canvas-type="' + type + '">' + body + '</' + tag + '></section>', { url: 'https://builder.test/', runScripts: 'outside-only', pretendToBeVisual: true });
	t.after(() => page.window.close());
	const win = page.window, doc = win.document, messages = [], block = doc.querySelector('[data-pb-canvas-path]');
	win.postMessage = message => messages.push(message);
	block.getBoundingClientRect = () => ({ left: 20, right: 620, top: 50, bottom: 130, width: 600, height: 80 });
	win.eval(fs.readFileSync(require.resolve('../../assets/js/canvas-bridge.js'), 'utf8'));
	win.eval(win.gtPbCanvasBridge.script({}, true));
	return { win, doc, block, messages, start: selector => doc.querySelector(selector).dispatchEvent(new win.MouseEvent('dblclick', { bubbles: true })) };
}

test('editing a nested paragraph link commits the whole paragraph instead of replacing it with the link', t => {
	const b = bridge(t, 'Read <a href="/guide/">the guide</a> today.');
	b.start('a');
	assert.equal(b.block.contentEditable, 'true');
	assert.notEqual(b.doc.querySelector('a').contentEditable, 'true');
	b.doc.querySelector('a').textContent = 'our guide';
	b.block.dispatchEvent(new b.win.Event('blur'));
	const changes = b.messages.filter(m => m.type === 'pb_canvas_text');
	assert.equal(changes.length, 1);
	assert.equal(changes[0].nativePath, '0.0');
	assert.equal(changes[0].oldHtml, 'Read <a href="/guide/">the guide</a> today.');
	assert.equal(changes[0].newHtml, 'Read <a href="/guide/">our guide</a> today.');
	assert.equal(b.block.hasAttribute('contenteditable'), false);
});

test('nested heading emphasis enters editing and composition Enter does not commit early', t => {
	const b = bridge(t, 'A <strong>bold</strong> heading', 'core/heading', 'h2');
	b.start('strong');
	assert.equal(b.block.contentEditable, 'true');
	b.doc.querySelector('strong').textContent = 'new';
	b.block.dispatchEvent(new b.win.KeyboardEvent('keydown', { key: 'Enter', isComposing: true, bubbles: true }));
	assert.equal(b.block.contentEditable, 'true');
	assert.equal(b.messages.filter(m => m.type === 'pb_canvas_text').length, 0);
	b.block.dispatchEvent(new b.win.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
	assert.equal(b.messages.filter(m => m.type === 'pb_canvas_text').at(-1).newHtml, 'A <strong>new</strong> heading');
});

test('a native button edits its inner link content and Escape restores it without a content change', t => {
	const b = bridge(t, '<a class="wp-block-button__link" href="/buy/"><strong>Buy</strong> now</a>', 'core/button', 'div');
	b.start('strong');
	const link = b.doc.querySelector('a');
	assert.equal(link.contentEditable, 'true');
	assert.notEqual(b.block.contentEditable, 'true');
	link.innerHTML = 'Cancel this change';
	link.dispatchEvent(new b.win.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
	assert.equal(link.innerHTML, '<strong>Buy</strong> now');
	assert.equal(link.getAttribute('href'), '/buy/');
	assert.equal(b.messages.filter(m => m.type === 'pb_canvas_text').length, 0);
});

test('the inspector displays editable newlines and keeps existing markup when one line changes', t => {
	const page = new JSDOM('<div id="stage"><div class="md-pb-canvas-toolbar"></div></div><div id="panel"></div>', { url: 'https://builder.test/' });
	t.after(() => page.window.close());
	const win = page.window, doc = win.document;
	const block = (name, attributes = {}, innerBlocks = []) => ({ name, attributes, innerBlocks, clientId: Math.random().toString(36), isValid: true });
	const html = '<strong>First</strong><br>Read <a href="/guide/">the guide</a>';
	const sections = [{ uid: 'pb-group', kind: 'foreign', blockName: 'core/group', serialized: JSON.stringify([block('core/group', {}, [block('core/paragraph', { content: html })])]) }];
	const api = { getBlockType: () => ({}), parse: JSON.parse, serialize: JSON.stringify, createBlock: block };
	win.gtPbCanvasLayout = layout;
	const editor = canvas.mount({ container: doc.querySelector('#panel'), canvas: doc.querySelector('#stage'), config: { icons: {} }, wp: { blocks: api }, getFrame: () => ({ contentWindow: { innerWidth: 1200, postMessage() {} } }), getSections: () => sections, isEnabled: () => true, selectSection() {}, onChange: (uid, patch) => Object.assign(sections[0], patch), canUndo: () => false, canRedo: () => false });
	editor.sync([{ uid: 'pb-group', source: sections[0].serialized }]);
	editor.handleMessage({ type: 'pb_canvas_select', sectionUid: 'pb-group', nativePath: '0.0' });
	const field = doc.querySelector('[name="pb-canvas-text"]');
	assert.equal(field.value, 'First\nRead the guide');
	field.value = 'First\nEnjoy the guide';
	field.dispatchEvent(new win.Event('change'));
	assert.equal(JSON.parse(sections[0].serialized)[0].innerBlocks[0].attributes.content, '<strong>First</strong><br>Enjoy <a href="/guide/">the guide</a>');
});
