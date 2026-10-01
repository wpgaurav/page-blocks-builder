const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(path.join(__dirname, '../../assets/js/block-editor.js'), 'utf8');

function setup(t) {
	const dom = new JSDOM('', { runScripts: 'outside-only' });
	t.after(() => dom.window.close());
	const { window } = dom;
	const types = {}, states = [], notices = [];
	let cursor = 0;
	const attrs = { nativeContent: true, content: '<!-- wp:paragraph --><p>Native</p><!-- /wp:paragraph -->' };
	const row = { id: 12, slug: 'shared-card', title: 'Shared card', content: '<p>Library source</p>' };
	window.mdPageBlockEditor = { canSave: true };
	window.confirm = () => true;
	window.prompt = () => { throw new Error('Native content must never be sent to library storage'); };
	window.wp = {
		blocks: { registerBlockType: (name, settings) => { types[name] = settings; } },
		element: {
			createElement: (type, props, ...children) => ({ type, props: props || {}, children }),
			useState: initial => {
				const i = cursor++;
				if (!(i in states)) states[i] = typeof initial === 'function' ? initial() : initial;
				return [states[i], value => { states[i] = typeof value === 'function' ? value(states[i]) : value; }];
			},
			useRef: value => ({ current: value }), useEffect() {}, Fragment: 'Fragment'
		},
		i18n: { __: s => s, sprintf: (s, ...args) => s.replace(/%[\d$]*[sd]/g, () => String(args.shift())) },
		blockEditor: { InspectorControls: 'InspectorControls', BlockControls: 'BlockControls' },
		components: new Proxy({}, { get: (o, key) => key }),
		data: { dispatch: () => ({ createErrorNotice: message => notices.push(message), createSuccessNotice() {} }) },
		apiFetch: () => Promise.resolve([row])
	};
	window.eval(source);
	function render() {
		cursor = 0;
		const nodes = [];
		function walk(node) {
			if (Array.isArray(node)) return node.forEach(walk);
			if (!node || typeof node !== 'object') return;
			nodes.push(node); (node.children || []).forEach(walk);
		}
		walk(types['gt-page-block/page-block'].edit({ attributes: attrs, setAttributes: value => Object.assign(attrs, value) }));
		return nodes;
	}
	return { render, attrs, notices, types };
}

test('converted native sections remain registered and cannot lose their flag through code library saves', t => {
	const e = setup(t);
	assert.equal(e.types['gt-page-block/page-block'].attributes.nativeContent.type, 'boolean');
	assert.equal(e.types['marketers-delight/page-block'].attributes.nativeContent.type, 'boolean');
	const save = e.render().find(n => n.type === 'button' && n.children.includes('Save to library'));
	assert.equal(save.props.disabled, true);
	save.props.onClick();
	assert.match(e.notices[0], /cannot be saved to the code library/);
	assert.equal(e.attrs.nativeContent, true);
});

for (const action of ['Copy code', 'Link']) test('replacing converted native code with library ' + action + ' resets native rendering', async t => {
	const e = setup(t);
	e.render().find(n => n.props.label === 'Browse library').props.onClick();
	await Promise.resolve();
	e.render().find(n => n.type === 'button' && n.children.includes(action)).props.onClick();
	assert.equal(e.attrs.nativeContent, false);
	assert.equal(e.attrs.blockId, action === 'Link' ? 12 : 0);
	assert.equal(e.attrs.content, action === 'Link' ? '' : '<p>Library source</p>');
});
