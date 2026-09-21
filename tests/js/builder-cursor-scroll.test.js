const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const shell = fs.readFileSync(path.join(__dirname, '../../assets/js/builder-shell.js'), 'utf8');
const preview = fs.readFileSync(path.join(__dirname, '../../assets/js/preview-dom.js'), 'utf8');

function builder(t) {
	const page = new JSDOM('<div id="md-pb-builder-app"></div>', {
		url: 'https://builder.test/', runScripts: 'outside-only'
	});
	t.after(() => page.window.close());
	const { window } = page;
	const timers = new Map();
	let timerId = 0;
	window.setTimeout = (fn, delay) => { timers.set(++timerId, { fn, delay }); return timerId; };
	window.clearTimeout = id => timers.delete(id);
	window.requestAnimationFrame = () => {};
	window.mdPbBuilder = { initialSections: [{
		uid: 'pb-hero',
		content: '<main id="content"><p>Hero text</p></main>'
	}] };
	const editors = [];
	window.wp = { codeEditor: { initialize() {
		const events = {};
		const cm = {
			focused: false, value: '',
			on(name, fn) { events[name] = fn; },
			emit(name) { if (events[name]) events[name](cm); },
			setValue(value) { cm.value = value; cm.emit('change'); cm.emit('cursorActivity'); },
			getValue() { return cm.value; },
			getCursor() { return { line: 0, ch: 0 }; },
			getLine() { return cm.value.split('\n')[0]; },
			hasFocus() { return cm.focused; },
			clearHistory() {}, setOption() {}, refresh() {}
		};
		editors.push(cm);
		return { codemirror: cm };
	} } };
	window.eval(preview);
	window.eval(shell);
	window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
	const frame = window.document.querySelector('iframe');
	frame.contentDocument.body.innerHTML = '<main id="content"><p>Hero text</p></main>';
	let scrolls = 0;
	frame.contentDocument.querySelector('main').scrollIntoView = () => { scrolls++; };
	const flush = () => {
		for (const [id, timer] of [...timers]) {
			if (timer.delay === 400) { timers.delete(id); timer.fn(); }
		}
	};
	return { window, frame, cm: editors[0], flush, scrolls: () => scrolls };
}

for (const focused of [false, true]) test('inline hero edits update HTML without scrolling (editor focus: ' + focused + ')', t => {
	const b = builder(t);
	b.cm.focused = focused;
	b.window.dispatchEvent(new b.window.MessageEvent('message', {
		source: b.frame.contentWindow,
		data: { type: 'md_pb_inline_edit', sectionUid: 'pb-hero', tagName: 'p',
			path: [0, 0], oldHtml: 'Hero text', newHtml: 'Hero textx' }
	}));
	assert.match(b.cm.getValue(), /Hero textx/);
	b.flush();
	assert.equal(b.scrolls(), 0);
});

test('cursor movement in the focused HTML editor still scrolls the preview', t => {
	const b = builder(t);
	b.cm.focused = true;
	b.cm.emit('cursorActivity');
	b.flush();
	assert.equal(b.scrolls(), 1);
});

test('moving focus to the preview during the delay prevents a stale scroll', t => {
	const b = builder(t);
	b.cm.focused = true;
	b.cm.emit('cursorActivity');
	b.cm.focused = false;
	b.flush();
	assert.equal(b.scrolls(), 0);
});

test('blurring and refocusing HTML does not revive the previous scroll', t => {
	const b = builder(t);
	b.cm.focused = true;
	b.cm.emit('cursorActivity');
	b.cm.focused = false;
	b.cm.emit('blur');
	b.cm.focused = true;
	b.flush();
	assert.equal(b.scrolls(), 0);
});
