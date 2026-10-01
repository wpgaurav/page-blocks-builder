const test = require('node:test');
const assert = require('node:assert/strict');
const builder = require('./helpers/builder');

const deferred = { cssOutput: 'file', cssDefer: true };
const save = b => { b.key('s', { ctrlKey: true }); return b.requests.at(-1); };
const success = request => request.resolve({ success: true, data: { sections: request.sections } });

test('save completion preserves the selected section and reload restores its loading setting', async t => {
	const b = builder(t, [{}, deferred]);
	b.select(1);
	const request = save(b);
	success(request);
	await b.settle();
	assert.equal(b.control('css-defer').checked, true);
	assert.equal(b.window.document.querySelector('.is-selected').dataset.index, '1');
	assert.equal(b.window.localStorage.getItem(b.draftKey), null);
	const reopened = builder(t, request.sections);
	reopened.select(1);
	assert.equal(reopened.control('css-file').checked, true);
	assert.equal(reopened.control('css-defer').checked, true);
});

test('an edit during an in-flight save survives the older server response', async t => {
	const b = builder(t, [{ cssOutput: 'file' }]);
	const request = save(b);
	b.control('css-defer').click();
	success(request);
	await b.settle();
	assert.equal(b.control('css-defer').checked, true);
	assert.match(b.control('save-status').textContent, /Newer edits are still unsaved/);
	assert.equal(b.draft().sections[0].cssDefer, true);
	assert.equal(save(b).sections[0].cssDefer, true);
});

test('repeated Save and keyboard shortcuts submit only one in-flight request', t => {
	const b = builder(t, [deferred]);
	for (let i = 0; i < 12; i++) save(b);
	assert.equal(b.requests.length, 1);
	assert.equal(b.control('apply').disabled, true);
});

for (const failure of ['network', 'permission', 'invalid JSON']) test(failure + ' failure retains the draft and allows retry', async t => {
	const b = builder(t, [{ cssOutput: 'file' }]);
	b.control('css-defer').click();
	const draft = b.draft();
	const request = save(b);
	if (failure === 'network') request.reject(new Error('Network offline'));
	if (failure === 'permission') request.resolve({ success: false, data: { message: 'Permission denied' } });
	if (failure === 'invalid JSON') request.invalidJSON();
	await b.settle();
	assert.equal(b.alerts.length, 0);
	assert.match(b.control('save-status').textContent, /Network offline|Permission denied|Invalid response/);
	assert.match(b.control('save-status').textContent, /draft is kept/);
	assert.equal(b.control('save-status').getAttribute('data-error'), 'true');
	assert.equal(b.control('apply').disabled, false);
	assert.deepEqual(JSON.parse(b.window.localStorage.getItem(b.draftKey)), draft);
	assert.equal(save(b).sections[0].cssDefer, true);
	assert.equal(b.requests.length, 2);
});

test('draft recovery restores mixed sections and foreign markup without turning them into editable blocks', t => {
	const raw = '<!-- wp:paragraph --><p>Keep me</p><!-- /wp:paragraph -->';
	const draft = { version: 3, contentHash: 'a'.repeat(64), sections: [
		{ uid: 'pb-deferred', content: '<section id="saved">Recovered</section>', ...deferred },
		{ uid: 'pb-foreign', kind: 'foreign', blockName: 'core/paragraph', serialized: raw, rendered: '<p>Keep me</p>' }
	] };
	const b = builder(t, [{}], { draft });
	assert.equal(b.control('css-defer').checked, true);
	b.select(1);
	assert.equal(b.control('css-file').disabled, true);
	assert.equal(b.control('css-defer').disabled, true);
	const request = save(b);
	assert.equal(request.sections[1].serialized, raw);
	assert.equal(request.sections[1].kind, 'foreign');
	assert.equal('cssDefer' in request.sections[1], false);
});

test('crash drafts keep their original saved-page hash and stale recovery cannot approve newer content', async t => {
	const originalHash = 'a'.repeat(64), newerHash = 'b'.repeat(64);
	const first = builder(t, [{ cssOutput: 'file' }], { config: { contentHash: originalHash } });
	first.control('css-defer').click();
	const draft = first.draft();
	assert.equal(draft.contentHash, originalHash);
	const recovered = builder(t, [{ content: '<section id="newer">Someone else updated this</section>' }], {
		config: { contentHash: newerHash }, draft
	});
	assert.match(recovered.control('save-status').textContent, /newer version of this page has been saved/);
	const request = save(recovered);
	assert.equal(request.form.get('content_hash'), originalHash);
	assert.notEqual(request.form.get('content_hash'), newerHash);
	request.resolve({ success: false, data: { message: 'This page changed in another editor.' } });
	await recovered.settle();
	assert.equal(recovered.draft().contentHash, originalHash);
	assert.equal(recovered.draft().sections[0].cssDefer, true);
});

test('hashless recovered drafts stay inspectable and exportable without borrowing a new baseline', t => {
	const recovered = builder(t, [{}], { draft: { version: 3, sections: [
		{ uid: 'pb-old', content: '<section id="old">Keep my unsaved work</section>', cssOutput: 'file' }
	] } });
	assert.match(recovered.control('save-status').textContent, /older editor.*Export the draft in Page settings/);
	assert.match(recovered.control('textarea-html').value, /Keep my unsaved work/);
	recovered.key('s', { ctrlKey: true });
	assert.equal(recovered.requests.length, 0);
	recovered.control('css-defer').click();
	assert.equal(recovered.draft().contentHash, null);
	assert.match(recovered.draft().sections[0].content, /Keep my unsaved work/);
	recovered.control('page-settings').click();
	assert.ok(recovered.control('setting-export'));
});

for (const operation of ['add', 'duplicate', 'delete']) test(operation + ' alone marks the document dirty and survives a crash', t => {
	const b = builder(t, [{ name: 'First' }, { name: 'Second' }]);
	if (operation === 'add') b.key('n', { ctrlKey: true });
	if (operation === 'duplicate') b.key('d', { ctrlKey: true });
	if (operation === 'delete') b.key('Backspace', { ctrlKey: true });
	assert.equal(b.control('save-status').textContent, 'Unsaved changes');
	const event = new b.window.Event('beforeunload', { cancelable: true });
	b.window.dispatchEvent(event);
	assert.equal(event.defaultPrevented, true);
	const draft = JSON.parse(b.window.localStorage.getItem(b.draftKey));
	assert.equal(draft.sections.length, operation === 'delete' ? 1 : 3);
	assert.equal(draft.contentHash, 'a'.repeat(64));
	b.key('z', { ctrlKey: true });
	assert.equal(b.control('save-status').textContent, 'All changes saved');
	assert.equal(b.window.localStorage.getItem(b.draftKey), null);
	const cleanUnload = new b.window.Event('beforeunload', { cancelable: true });
	b.window.dispatchEvent(cleanUnload);
	assert.equal(cleanUnload.defaultPrevented, false);
	b.key('z', { ctrlKey: true, shiftKey: true });
	assert.equal(b.control('save-status').textContent, 'Unsaved changes');
	assert.equal(b.draft().sections.length, operation === 'delete' ? 1 : 3);
});

test('recovered native deletions and page settings retain the full save payload', t => {
	const native = { kind: 'foreign', blockName: 'core/paragraph', serialized: '<!-- wp:paragraph --><p>Native</p><!-- /wp:paragraph -->' };
	const settings = { postTitle: 'Saved title', postSlug: 'saved-title', postTemplate: 'default-template',
		availableTemplates: [{ slug: 'default-template', label: 'Default' }, { slug: 'wide', label: 'Wide' }] };
	const b = builder(t, [native, { name: 'Code' }], { config: settings });
	b.key('Backspace', { ctrlKey: true });
	b.control('page-settings').click();
	b.control('setting-title').value = 'Recovered title';
	b.control('setting-slug').value = 'recovered-title';
	b.control('setting-template').value = 'wide';
	b.control('settings-done').click();
	const draft = b.draft();
	assert.equal(draft.removedForeign, 1);
	assert.equal(draft.pageTitle, 'Recovered title');
	assert.equal(draft.pageSlug, 'recovered-title');
	assert.equal(draft.pageTemplate, 'wide');
	const recovered = builder(t, [native, { name: 'Code' }], { config: settings, draft });
	const request = save(recovered);
	assert.equal(request.form.get('removed_foreign'), '1');
	assert.equal(request.form.get('post_title'), 'Recovered title');
	assert.equal(request.form.get('post_slug'), 'recovered-title');
	assert.equal(request.form.get('page_template'), draft.pageTemplate);
	assert.equal(request.sections.length, 1);
});

test('undo restores native deletion accounting and returns to a clean saved document', t => {
	const native = { kind: 'foreign', blockName: 'core/paragraph', serialized: '<!-- wp:paragraph --><p>Native</p><!-- /wp:paragraph -->' };
	const b = builder(t, [native, {}]);
	b.key('Backspace', { ctrlKey: true });
	assert.equal(b.draft().removedForeign, 1);
	b.key('z', { ctrlKey: true });
	assert.equal(b.control('save-status').textContent, 'All changes saved');
	const request = save(b);
	assert.equal(request.form.get('removed_foreign'), '0');
	assert.equal(request.sections[0].serialized, native.serialized);
});

test('new edits made during save persist against the hash returned by that save', async t => {
	const b = builder(t, [{ cssOutput: 'file' }]);
	b.key('d', { ctrlKey: true });
	const request = save(b);
	b.control('css-defer').click();
	request.resolve({ success: true, data: { sections: request.sections, contentHash: 'b'.repeat(64) } });
	await b.settle();
	const draft = b.draft();
	assert.equal(draft.contentHash, 'b'.repeat(64));
	assert.equal(draft.sections[1].cssDefer, true);
});

test('undo after a completed save compares with the newly saved document', async t => {
	const b = builder(t, [{}]);
	b.key('d', { ctrlKey: true });
	success(save(b));
	await b.settle();
	b.key('n', { ctrlKey: true });
	assert.equal(b.control('save-status').textContent, 'Unsaved changes');
	b.key('z', { ctrlKey: true });
	assert.equal(b.control('save-status').textContent, 'All changes saved');
	assert.equal(b.window.localStorage.getItem(b.draftKey), null);
});

for (const draft of ['{broken', { version: 2, sections: [deferred] }]) test('corrupt or obsolete draft leaves saved content usable: ' + JSON.stringify(draft), t => {
	const b = builder(t, [{ name: 'Saved section' }], { draft });
	assert.equal(save(b).sections[0].name, 'Saved section');
	assert.equal(b.alerts.length, 0);
});

test('unavailable local storage does not block saving', async t => {
	const b = builder(t, [{ cssOutput: 'file' }], { storageUnavailable: true });
	b.control('css-defer').click();
	assert.doesNotThrow(() => b.draft());
	const request = save(b);
	success(request);
	await b.settle();
	assert.equal(b.control('apply').disabled, false);
	assert.equal(request.sections[0].cssDefer, true);
});

test('duplicate, reorder, undo and redo carry loading settings with section identity', t => {
	const b = builder(t, [deferred, { name: 'Inline' }]);
	b.key('d', { ctrlKey: true });
	b.key('ArrowDown', { altKey: true });
	b.key('z', { ctrlKey: true });
	b.key('z', { ctrlKey: true, shiftKey: true });
	const sections = save(b).sections;
	assert.equal(sections.length, 3);
	assert.equal(sections[0].cssDefer, true);
	assert.equal(sections[1].name, 'Inline');
	assert.equal(sections[2].cssDefer, true);
	assert.notEqual(sections[0].uid, sections[2].uid);
	assert.notEqual(sections[0].content, sections[2].content);
});

test('section switches never copy one section loading settings into another', t => {
	const b = builder(t, [deferred, { cssOutput: 'inline' }, { cssOutput: 'file' }]);
	b.select(1);
	assert.equal(b.control('css-defer').closest('label').hidden, true);
	b.select(2);
	assert.equal(b.control('css-defer').checked, false);
	b.select(0);
	assert.equal(b.control('css-defer').checked, true);
	assert.deepEqual(save(b).sections.map(s => s.cssDefer), [true, false, false]);
});

test('rapid CSS edits coalesce into one pending preview and one draft write', t => {
	const b = builder(t, [deferred]);
	b.flush(0);
	const input = b.control('textarea-css');
	for (let i = 0; i < 100; i++) {
		input.value = '.last{opacity:' + i / 100 + '}';
		input.dispatchEvent(new b.window.Event('input', { bubbles: true }));
	}
	assert.equal([...b.timers.values()].filter(x => x.delay === 1000).length, 1);
	assert.equal([...b.timers.values()].filter(x => x.delay === 5000).length, 1);
	assert.equal(b.draft().sections[0].css, '.last{opacity:0.99}');
	assert.equal(b.requests.length, 0);
});
