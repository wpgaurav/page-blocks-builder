const { test } = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const preview = require('../../assets/js/preview-dom');

function page(sections) {
	const html = sections.map((s, i) => preview.sectionHtml(s.content, { uid: 'pb-' + i, ...s })).join('\n');
	const doc = new JSDOM('<!doctype html><html><body>' + html + '</body></html>').window.document;
	preview.markSections(doc);
	return doc;
}

function edit(doc, node, html) {
	const section = node.closest('[data-pb-section]');
	const path = [];
	for (let current = node; current !== section; current = current.parentElement) {
		path.unshift(Array.from(current.parentElement.children).indexOf(current));
	}
	path.unshift(Number(section.getAttribute('data-pb-root-index')));
	return { path, tagName: node.tagName.toLowerCase(), oldHtml: node.innerHTML, newHtml: html };
}

test('shared container spans all sections without changing child and sibling selectors', () => {
	const doc = page([
		{ content: '<main id="home" class="gth"><section id="first"><h1>First</h1></section>' },
		{ content: '<section id="second"><p>Second</p></section>' },
		{ content: '<section id="third"><p>Third</p></section></main>' }
	]);
	assert.equal(doc.querySelectorAll('.gth > section').length, 3);
	assert.equal(doc.querySelector('.gth > section + section').id, 'second');
	assert.equal(doc.querySelector('.gth > section:nth-child(3)').id, 'third');
	for (const [i, id] of ['first', 'second', 'third'].entries()) {
		assert.equal(doc.querySelector('#' + id + ' :first-child').closest('[data-pb-section]').dataset.pbSection, 'pb-' + i);
	}
	assert.equal(doc.querySelectorAll('div').length, 0);
});

test('multiple roots keep their source indexes and foreign/linked roots stay identified', () => {
	const doc = page([
		{ content: '<p>One</p><p>Two</p>' },
		{ content: '<section><p>Foreign</p></section>', kind: 'foreign' },
		{ content: '<article>Linked</article>', blockId: 42 }
	]);
	assert.equal(doc.querySelectorAll('p')[1].dataset.pbRootIndex, '1');
	assert.equal(doc.querySelector('section').dataset.pbForeign, '1');
	assert.equal(doc.querySelector('article').dataset.pbLinked, '1');
});

test('inline edit leaves an open shared ancestor and every other source byte intact', () => {
	const source = '<!-- keep -->\n<main class=\'gth\'>\n<section><h1 title="a > b">Hello &amp; world</h1></section>';
	const doc = page([{ content: source }, { content: '<section>Last</section></main>' }]);
	const result = preview.replaceInnerHtml(source, edit(doc, doc.querySelector('h1'), 'New <em>title</em>'), doc);
	assert.equal(result, source.replace('Hello &amp; world', 'New <em>title</em>'));
	assert.equal(result.includes('</main>'), false);
});

test('duplicate text edits the selected element, including the second root', () => {
	const source = '<p>Same</p><section><p>Same</p><p>Same</p></section>';
	const doc = page([{ content: source }]);
	const target = doc.querySelectorAll('p')[2];
	assert.equal(preview.replaceInnerHtml(source, edit(doc, target, 'Changed'), doc), '<p>Same</p><section><p>Same</p><p>Changed</p></section>');
});

test('script, style, PHP and comments do not create fake source elements', () => {
	const source = '<script>var markup="<p>Wrong</p>";</script><style>p::after{content:"<p>"}</style><!--<p>Wrong</p>--><?php echo "<p>PHP</p>"; ?><p data-x=\'a > b\'>Right</p>';
	const doc = page([{ content: source }]);
	const change = { path: [2], tagName: 'p', oldHtml: 'Right', newHtml: 'Changed' };
	assert.equal(preview.replaceInnerHtml(source, change, doc), source.replace('Right', 'Changed'));
});

test('generated or stale markup refuses an ambiguous edit', () => {
	const doc = new JSDOM('').window.document;
	const change = { path: [0], tagName: 'p', oldHtml: 'Rendered', newHtml: 'Changed' };
	assert.equal(preview.replaceInnerHtml('<p>[shortcode]</p>', change, doc), null);
	assert.equal(preview.replaceInnerHtml('<p><?php echo "Rendered"; ?></p>', change, doc), null);
	assert.equal(preview.replaceInnerHtml('<p>Other</p>', change, doc), null);
	assert.equal(preview.replaceInnerHtml('<p>Rendered', change, doc), null);
});

test('server whitespace minification still permits a precise inline edit', () => {
	const source = '<main>\n<h1>Hello\n  <em>world</em></h1>\n';
	const doc = page([{ content: '<main> <h1>Hello <em>world</em></h1> ' }, { content: '</main>' }]);
	assert.equal(preview.replaceInnerHtml(source, edit(doc, doc.querySelector('h1'), 'Updated'), doc), '<main>\n<h1>Updated</h1>\n');
});
