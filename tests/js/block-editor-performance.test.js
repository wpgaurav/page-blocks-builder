const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(path.join(__dirname, '../../assets/js/block-editor.js'), 'utf8');

// Exercise the registered edit component's controls and callbacks. This does
// not replace a browser/React canvas test; effects and layout are not simulated.
function editor(t, name) {
	const dom = new JSDOM('', { runScripts: 'outside-only' });
	t.after(() => dom.window.close());
	const { window } = dom;
	const types = {}, updates = [], calls = [];
	window.mdPageBlockEditor = { postId: 7, ajaxUrl: '/ajax', previewNonce: 'preview-nonce' };
	window.gtPbPerformance = { open: (...args) => calls.push(args) };
	window.wp = {
		blocks: { registerBlockType: (name, settings) => { types[name] = settings; } },
		element: { createElement: (type, props, ...children) => ({ type, props: props || {}, children }), useState: value => [typeof value === 'function' ? value() : value, () => {}], useRef: value => ({ current: value }), useEffect() {}, Fragment: 'Fragment' },
		i18n: { __: s => s, sprintf: (s, ...args) => s.replace(/%[\d$]*[sd]/g, () => String(args.shift())) },
		blockEditor: { InspectorControls: 'InspectorControls', BlockControls: 'BlockControls' },
		components: new Proxy({}, { get: (o, key) => key })
	};
	window.eval(source);
	const attributes = { content: '<section>Unsaved text</section>', css: '.unsaved{}', cssOutput: 'file', cssDefer: true };
	const tree = types[name].edit({ attributes, setAttributes: value => updates.push(value) });
	const nodes = [];
	function walk(node) {
		if (Array.isArray(node)) { node.forEach(walk); return; }
		if (!node || typeof node !== 'object') return;
		nodes.push(node); (node.children || []).forEach(walk);
	}
	walk(tree);
	return { nodes, attributes, updates, calls };
}
for (const name of ['gt-page-block/page-block', 'marketers-delight/page-block']) test(name + ' analyzes unsaved attributes with section scope and preserves defer controls', t => {
	const e = editor(t, name);
	const analyze = e.nodes.find(x => x.type === 'button' && x.children.includes('Analyze section'));
	assert.ok(analyze);
	const trigger = {};
	analyze.props.onClick({ currentTarget: trigger });
	assert.equal(e.calls[0][0].scope, 'section');
	assert.equal(e.calls[0][0].nonce, 'preview-nonce');
	assert.equal(e.calls[0][1][0], e.attributes);
	assert.equal(e.calls[0][2], trigger);
	const toggle = e.nodes.find(x => x.type === 'ToggleControl' && x.props.label === 'Defer CSS');
	assert.equal(toggle.props.checked, true);
	toggle.props.onChange(false);
	assert.equal(e.updates[0].cssDefer, false);
	assert.equal(e.updates[0].cssOutput, 'file');
});
