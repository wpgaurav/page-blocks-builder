const test = require('node:test');
const assert = require('node:assert/strict');
const visual = require('../../assets/js/visual-builder.js');
const builder = require('./helpers/builder.js');

test('visual elements move inside containers without losing stable identity', () => {
	const data = visual.createData();
	const container = data.root.children[0];
	const heading = container.children[0];
	const text = container.children[1];
	const moved = visual.move(data, text.id, heading.id, 'before');
	assert.deepEqual(moved.root.children[0].children.map(node => node.id), [text.id, heading.id]);
	assert.deepEqual(container.children.map(node => node.id), [heading.id, text.id]);
	assert.equal(visual.move(data, container.id, text.id, 'inside'), null, 'a container cannot move into its descendant');
	const copy = visual.duplicate(data, heading.id);
	assert.notEqual(copy.id, heading.id);
	assert.equal(copy.data.root.children[0].children.length, 3);
});

test('empty style maps from an older save become editable objects', () => {
	const data = visual.createData();
	const heading = data.root.children[0].children[0];
	heading.styles.mobile = [];
	const normalized = visual.normalizeData(data);
	const changed = visual.setValue(normalized, heading.id, 'mobile', 'fontSize', '22px');
	assert.equal(changed.root.children[0].children[0].styles.mobile.fontSize, '22px');
	assert.match(JSON.stringify(changed), /"mobile":\{"fontSize":"22px"\}/);
});

test('Visual section enters save payload, survives draft, and keeps Code sections separate', t => {
	const b = builder(t, [{ name: 'Existing code' }]);
	b.control('add-visual-section').click();
	assert.equal(b.control('toggle-visual').getAttribute('aria-pressed'), 'true');
	assert.equal(b.window.document.querySelector('.md-pb-v-panel').hidden, false);
	const headingLayer = [...b.window.document.querySelectorAll('.md-pb-v-layer')].find(node => node.textContent.startsWith('Heading'));
	headingLayer.click();
	const textField = [...b.window.document.querySelectorAll('.md-pb-v-field')].find(node => node.querySelector('span').textContent === 'Text');
	textField.querySelector('input').value = 'A real visual heading';
	textField.querySelector('input').dispatchEvent(new b.window.Event('change', { bubbles: true }));
	b.key('s', { ctrlKey: true });
	const request = b.requests.at(-1);
	assert.equal(request.sections[0].visualData, undefined);
	assert.equal(request.sections[1].visualData.root.children[0].children[0].props.text, 'A real visual heading');
	assert.equal(b.draft().sections[1].visualData.root.children[0].children[0].props.text, 'A real visual heading');
});
