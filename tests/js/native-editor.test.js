const test = require('node:test');
const assert = require('node:assert/strict');
const { saveBeforeLeave } = require('../../assets/js/native-editor.js');
const { convert } = require('../../assets/js/prototype-conversion.js');
const builder = require('./helpers/builder.js');
const { JSDOM } = require('jsdom');
const fs = require('node:fs');
const path = require('node:path');

function editor(overrides = {}) {
	let dirty = true, calls = 0;
	const store = { getCurrentPostId: () => 39, isSavingPost: () => false, isAutosavingPost: () => false,
		isEditedPostDirty: () => dirty, didPostSaveRequestFail: () => false, ...overrides };
	return { wp: { data: { select: () => store, dispatch: () => ({ savePost: async () => { calls++; dirty = false; } }) } }, calls: () => calls };
}

test('native mode saves through WordPress before switching', async () => {
	const e = editor();
	await saveBeforeLeave(e.wp, 39);
	assert.equal(e.calls(), 1);
	await saveBeforeLeave(e.wp, 39);
	assert.equal(e.calls(), 1, 'a clean page needs no extra write');
});

test('native mode stays open for failed saves, changed content, locks and template edits', async () => {
	for (const overrides of [
		{ didPostSaveRequestFail: () => true },
		{ isEditedPostDirty: () => true },
		{ isPostSavingLocked: () => true },
		{ hasNonPostEntityChanges: () => true },
		{ isSavingPost: () => true },
		{ getCurrentPostId: () => 40 }
	]) await assert.rejects(saveBeforeLeave(editor(overrides).wp, 39));
});

test('prototype conversion produces native nested blocks and retains existing CSS', () => {
	const api = { createBlock: (name, attributes, innerBlocks = []) => ({ name, attributes, innerBlocks }) };
	const legacy = { name: 'Hero', css: '.gt-pb-v-vbutton01{color:red}', visualData: { version: 1, root: {
		id: 'vsection01', type: 'section', children: [
			{ id: 'vheading01', type: 'heading', props: { text: 'A < B', level: 1 } },
			{ id: 'vbutton01', type: 'button', props: { text: 'Read more', url: '/about/' } },
			{ id: 'vcolumns01', type: 'columns', children: [{ id: 'vtext0001', type: 'text', props: { text: 'Body' } }] }
		]
	} } };
	const [group] = convert(legacy, api);
	assert.equal(group.name, 'core/group');
	assert.equal(group.attributes.tagName, 'section');
	assert.equal(group.innerBlocks[1].name, 'core/heading');
	assert.equal(group.innerBlocks[1].attributes.content, 'A &lt; B');
	assert.equal(group.innerBlocks[2].innerBlocks[0].name, 'core/button');
	assert.equal(group.innerBlocks[3].innerBlocks[0].name, 'core/column');
	assert.match(group.innerBlocks[0].attributes.css, /wp-block-button__link/);
	assert.equal(JSON.stringify(group).includes('visualData'), false);
	legacy.visualData.root.children[0].type = 'unknown';
	assert.throws(() => convert(legacy, api), /unsupported/);
});

test('Code mode preserves opaque prototype metadata and sends the saved content hash', t => {
	const metadata = { version: 1, root: { id: 'vlegacy01', type: 'section', children: [] } };
	const b = builder(t, [{ visualData: metadata }], { config: { contentHash: 'base' } });
	b.key('s', { ctrlKey: true });
	assert.deepEqual(b.requests.at(-1).sections[0].visualData, metadata);
	assert.equal(b.requests.at(-1).form.get('content_hash'), 'base');
});

test('native sections do not leave an empty code pane in the Code workspace', t => {
	const b = builder(t, [{ kind: 'foreign', blockName: 'core/group', label: 'Group', serialized: '<!-- wp:group /-->' }]);
	assert.equal(b.control('toggle-code').disabled, true);
	assert.equal(b.window.document.querySelector('.md-pb-shell').classList.contains('is-code-hidden'), true);
	assert.match(b.control('active-section-classes').textContent, /Visual mode/);
});

test('the native frame accepts save messages only from its parent and configured origin', async t => {
	const page = new JSDOM('', { url: 'https://builder.test/wp-admin/post.php', runScripts: 'outside-only' });
	t.after(() => page.window.close());
	const w = page.window, sent = [], parent = { postMessage: (data, origin) => sent.push({ data, origin }) };
	Object.defineProperty(w, 'parent', { value: parent });
	w.gtPbNativeBridge = { postId: 39, parentUrl: 'https://builder.test/' };
	w.wp = { domReady: fn => fn(), data: { subscribe: () => {}, select: () => ({ getCurrentPostId: () => 39, getEditedPostAttribute: () => 'Page', isEditedPostDirty: () => false }) } };
	let saves = 0;
	w.gtPbNativeEditorApi = { saveBeforeLeave: async () => { saves++; } };
	w.eval(fs.readFileSync(path.join(__dirname, '../../assets/js/native-editor-bridge.js'), 'utf8'));
	function send(source, origin, requestId) { w.dispatchEvent(new w.MessageEvent('message', { source, origin, data: { type: 'gt_pb_native_save', requestId } })); }
	send(parent, 'https://other.test', 1);
	send({}, 'https://builder.test', 2);
	assert.equal(saves, 0);
	send(parent, 'https://builder.test', 3);
	await new Promise(resolve => setImmediate(resolve));
	assert.equal(saves, 1);
	assert.deepEqual(JSON.parse(JSON.stringify(sent.at(-1))), { data: { type: 'gt_pb_native_saved', requestId: 3, ok: true }, origin: 'https://builder.test' });
});
