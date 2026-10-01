const test = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const builder = require('./helpers/builder');

function fixture(t, sections = [{}], config = {}) {
	const b = builder(t, sections, { config: { libraryEnabled: true, restUrl: '/wp-json/pbb/v1', restNonce: 'nonce', canManageLibrary: true, libraryEditUrl: '/wp-admin/admin.php?page=gt_pb_edit&id=', ...config } });
	const requests = [];
	b.window.fetch = (url, options) => new Promise((resolve, reject) => requests.push({ url, options, reject, resolve: (payload, status = 200, headers = {}) => resolve({ ok: status < 400, headers: { get: name => headers[name] ?? null }, json: async () => payload }) }));
	const doc = b.window.document;
	const query = selector => doc.querySelector(selector);
	const open = () => { b.control('library').focus(); b.control('library').click(); };
	return { ...b, doc, query, libraryRequests: requests, open };
}

const summary = (id, title) => ({ id, title, slug: 'section-' + id, status: 'publish', has_content: true, has_css: true, has_js: false });
const full = (id, title, extra = {}) => ({ ...summary(id, title), content: '<section><h2>' + title + '</h2></section>', css: 'h2{color:red}', js: '', ...extra });

async function selectFirst(b, item = full(1, 'Hero')) {
	b.open();
	b.libraryRequests[0].resolve([summary(item.id, item.title)], 200, { 'X-WP-Total': '1', 'X-WP-TotalPages': '1' });
	await b.settle();
	b.libraryRequests[1].resolve(item);
	await b.settle();
}

test('library loads summaries, lazily previews one item, and inserts the exact code after the selected visual section', async t => {
	const b = fixture(t, [{ kind: 'foreign', serialized: '<!-- wp:paragraph --><p>Keep</p><!-- /wp:paragraph -->', blockName: 'core/paragraph', label: 'Keep' }, { name: 'Following section' }], { builderMode: 'visual' });
	const item = full(8, 'CTA', { content: '<section><?php echo "Dynamic"; ?><h2>CTA</h2></section>', css: 'section{padding:12px}', js: 'window.keepExactly = "$&";', js_location: 'inline', output: 'file', php_exec: true, format: true });
	await selectFirst(b, item);
	assert.match(b.libraryRequests[0].url, /context=summary&per_page=12/);
	assert.equal(b.libraryRequests[1].url, '/wp-json/pbb/v1/blocks/8');
	assert.equal(b.libraryRequests[1].options.headers['X-WP-Nonce'], 'nonce');
	b.query('[data-library-copy]').click();
	const draft = b.draft();
	assert.equal(draft.sections.length, 3);
	assert.equal(draft.sections[0].serialized, '<!-- wp:paragraph --><p>Keep</p><!-- /wp:paragraph -->');
	assert.equal(draft.sections[1].name, 'CTA');
	assert.equal(draft.sections[1].content, item.content);
	assert.equal(draft.sections[1].css, item.css);
	assert.equal(draft.sections[1].js, item.js);
	assert.equal(draft.sections[1].jsLocation, 'inline');
	assert.equal(draft.sections[1].phpExec, true);
	assert.equal(draft.sections[1].format, true);
	assert.equal(draft.sections[1].output, 'file');
	assert.equal(draft.sections[2].name, 'Following section');
	assert.equal(b.query('#md-pb-library-overlay'), null);
	assert.ok(b.control('toggle-code').classList.contains('is-active'));
});

test('library preview strips executable HTML and PHP, isolates CSS, and never calls the render endpoint', async t => {
	const b = fixture(t);
	await selectFirst(b, full(1, '<img src=x onerror=alert(1)>', { content: '<section onclick="alert(1)"><h2>Safe heading</h2><?php dangerous(); ?><script>alert(1)</script><iframe src="/danger"></iframe><form action="/save"><input name="secret"></form><a href="javascript:alert(1)">Link</a><img src="/sample.jpg" onerror="alert(1)"></section>', css: '/* </style><script>alert(1)</script> */ h2{color:red}', js: 'alert(1)' }));
	const frame = b.query('.pb-library-preview iframe');
	assert.equal(frame.getAttribute('sandbox'), '');
	assert.equal(frame.getAttribute('tabindex'), '-1');
	const parsed = new JSDOM(frame.srcdoc).window.document;
	assert.equal(parsed.querySelector('script,iframe,form,input'), null);
	assert.equal(parsed.querySelector('[onclick],[onerror],[href]'), null);
	assert.equal(parsed.querySelector('h2').textContent, 'Safe heading');
	assert.doesNotMatch(parsed.body.innerHTML, /dangerous/);
	assert.match(parsed.querySelector('meta[http-equiv]').content, /script-src 'none'/);
	assert.equal(b.query('.pb-library-detail-heading h3 img'), null);
	assert.equal(b.libraryRequests.some(r => /\/render/.test(r.url)), false);
});

test('linked library insertion keeps the reference instead of copying executable content', async t => {
	const b = fixture(t);
	await selectFirst(b, full(17, 'Shared footer', { conditions: { include: ['page'] }, position: 'wp_footer' }));
	assert.match(b.query('.pb-library-rules').textContent, /wp_footer/);
	assert.match(b.query('.pb-library-rules').textContent, /include/);
	assert.equal(b.query('.pb-library-detail-meta a').getAttribute('href'), '/wp-admin/admin.php?page=gt_pb_edit&id=17');
	b.query('[data-library-link]').click();
	const section = b.draft().sections[1];
	assert.equal(section.blockId, 17);
	assert.equal(section.content, '');
	assert.equal(section.css, '');
	assert.equal(section.js, '');
});

test('search invalidates old results before its debounce and pagination respects server totals', async t => {
	const b = fixture(t); b.open();
	const search = b.query('[data-library-search]');
	search.value = 'pricing'; search.dispatchEvent(new b.window.Event('input'));
	b.libraryRequests[0].resolve([summary(1, 'Stale hero')]); await b.settle();
	assert.equal(b.query('[data-library-id]'), null);
	b.flush(250);
	assert.match(b.libraryRequests[1].url, /page=1&search=pricing/);
	b.libraryRequests[1].resolve([summary(2, 'Pricing')], 200, { 'X-WP-Total': '13', 'X-WP-TotalPages': '2' }); await b.settle();
	assert.equal(b.query('[data-library-prev]').disabled, true);
	assert.equal(b.query('[data-library-next]').disabled, false);
	assert.equal(b.query('[data-library-page]').textContent, 'Page 1 of 2');
	b.query('[data-library-next]').click();
	assert.match(b.libraryRequests[3].url, /page=2&search=pricing/);
	b.libraryRequests[2].resolve(full(2, 'Old preview')); await b.settle();
	assert.equal(b.query('[data-library-copy]'), null);
	b.libraryRequests[3].resolve([summary(3, 'Another pricing')], 200, { 'X-WP-Total': '13', 'X-WP-TotalPages': '2' }); await b.settle();
	assert.equal(b.query('[data-library-next]').disabled, true);
	assert.equal(b.query('[data-library-prev]').disabled, false);
});

test('late detail responses cannot replace a newly selected section', async t => {
	const b = fixture(t); b.open();
	b.libraryRequests[0].resolve([summary(1, 'First'), summary(2, 'Second')]); await b.settle();
	b.query('[data-library-id="2"]').click();
	b.libraryRequests[2].resolve(full(2, 'Second')); await b.settle();
	b.libraryRequests[1].resolve(full(1, 'First')); await b.settle();
	assert.equal(b.query('.pb-library-detail h3').textContent, 'Second');
	b.query('[data-library-copy]').click();
	assert.equal(b.draft().sections[1].name, 'Second');
});

test('failed or removed library items offer retry without inserting an empty section', async t => {
	const b = fixture(t); b.open();
	b.libraryRequests[0].resolve([summary(1, 'Hero')]); await b.settle();
	b.libraryRequests[1].resolve({ code: 'not_found', message: 'Section was removed.' }, 404); await b.settle();
	assert.match(b.query('[data-library-detail]').textContent, /Section was removed/);
	assert.equal(b.query('[data-library-copy]'), null);
	b.query('[data-library-detail] button').click();
	b.libraryRequests[2].resolve(full(1, 'Hero', { status: 'trash' })); await b.settle();
	assert.match(b.query('[data-library-detail]').textContent, /no longer available/);
	assert.equal(b.query('[data-library-copy]'), null);
	assert.equal(b.draft(), null);
});

test('library has recoverable list errors and a useful empty search state', async t => {
	const b = fixture(t); b.open();
	b.libraryRequests[0].reject(new Error('Connection unavailable')); await b.settle();
	assert.match(b.query('[data-library-list]').textContent, /Connection unavailable/);
	b.query('[data-library-list] button').click();
	b.libraryRequests[1].resolve([]); await b.settle();
	assert.match(b.query('[data-library-status]').textContent, /library is empty/);
	const search = b.query('[data-library-search]'); search.value = 'nothing'; search.dispatchEvent(new b.window.Event('input')); b.flush(250);
	b.libraryRequests[2].resolve([]); await b.settle();
	assert.match(b.query('[data-library-status]').textContent, /No sections match/);
});

test('Escape restores opener focus and late requests cannot resurrect a closed library', async t => {
	const b = fixture(t); b.open();
	const search = b.query('[data-library-search]');
	assert.equal(b.doc.activeElement, search);
	search.dispatchEvent(new b.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
	assert.equal(b.doc.activeElement, b.control('library'));
	b.libraryRequests[0].resolve([summary(1, 'Late')]); await b.settle();
	assert.equal(b.query('#md-pb-library-overlay'), null);
	assert.equal(b.libraryRequests.length, 1);
});

test('save to library preserves code settings, submits once, and respects library permissions', async t => {
	const b = fixture(t, [{ name: 'Reusable', content: '<h2 id="keep">Keep</h2>', css: 'h2{color:red}', js: 'init()', jsLocation: 'inline', phpExec: true, format: true, output: 'file' }]);
	b.open(); b.query('[data-library-save-toggle]').click();
	const form = b.query('[data-library-save-form]');
	assert.equal(form.elements.title.value, 'Reusable');
	form.dispatchEvent(new b.window.Event('submit', { bubbles: true, cancelable: true }));
	form.dispatchEvent(new b.window.Event('submit', { bubbles: true, cancelable: true }));
	assert.equal(b.libraryRequests.length, 2);
	const request = b.libraryRequests[1];
	assert.equal(request.options.method, 'POST');
	assert.deepEqual(JSON.parse(request.options.body), { title: 'Reusable', status: 'publish', content: '<h2 id="keep">Keep</h2>', css: 'h2{color:red}', js: 'init()', js_location: 'inline', output: 'file', format: true, php_exec: true });
	request.resolve(full(10, 'Reusable')); await b.settle();
	assert.equal(form.hidden, true);
	assert.match(b.query('[data-library-save-status]').textContent, /Saved/);
	assert.equal(b.draft(), null);
	const restricted = fixture(t, [{}], { canManageLibrary: false }); restricted.open();
	assert.equal(restricted.query('[data-library-save-toggle]').hidden, true);
	assert.equal(restricted.query('[data-library-save-toggle]').disabled, true);
});
