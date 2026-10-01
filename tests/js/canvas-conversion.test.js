const test = require('node:test');
const assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');
const conversion = require('../../assets/js/canvas-conversion');

function context(t) {
	const page = new JSDOM(''); t.after(() => page.window.close());
	const documents = new Map();
	const copy = value => JSON.parse(JSON.stringify(value));
	const api = {
		createBlock:(name,attributes={},innerBlocks=[]) => ({name,attributes,innerBlocks,isValid:true}),
		serialize(blocks) {
			function markup(block) {
				const name = block.name.replace(/^core\//,'');
				const attrs = JSON.stringify(block.attributes).replace(/</g,'\\u003c').replace(/>/g,'\\u003e');
				if (block.name === 'gt-page-block/page-block') return '<!-- wp:' + name + ' ' + attrs + ' /-->';
				const tag = block.name === 'core/group' ? block.attributes.tagName || 'div' : block.name === 'core/heading' ? 'h' + (block.attributes.level || 2) : 'p';
				return '<!-- wp:' + name + ' ' + attrs + ' --><' + tag + '>' + (block.attributes.content || '') + block.innerBlocks.map(markup).join('') + '</' + tag + '><!-- /wp:' + name + ' -->';
			}
			const serialized = blocks.map(markup).join('\n'); documents.set(serialized,copy(blocks)); return serialized;
		},
		parse(markup) { return documents.has(markup) ? copy(documents.get(markup)) : []; }
	};
	return {api,doc:page.window.document,documents};
}

test('native Visual to Code and back keeps exact WordPress source, responsive CSS, and scripts', t => {
	const {api,doc} = context(t);
	const root = api.createBlock('core/group',{tagName:'section',className:'pb-freeform pb-layout-a',layout:{type:'default'}},[
		api.createBlock('gt-page-block/page-block',{name:'Page Blocks canvas styles',css:'@media(max-width:480px){.pb-node-a{color:red}}'}),
		api.createBlock('core/heading',{content:'Keep <strong>all</strong> text',className:'pb-node-a'}),
		api.createBlock('gt-page-block/page-block',{js:'window.answer = [1,2,3];',jsLocation:'inline'})
	]);
	const source = api.serialize([root]);
	const code = conversion.visualToCode(api,source,doc);
	assert.equal(code.nativeContent,true); assert.equal(code.format,false);
	assert.equal(code.content,source); assert.equal(code.css,''); assert.equal(code.js,'');
	assert.equal(conversion.codeToVisual(api,code,doc),source);
});

test('native conversion retains multiple roots and refuses to add wrappers for new stylesheet fields', t => {
	const {api,doc} = context(t);
	const source = api.serialize([api.createBlock('core/heading',{content:'Heading'}),api.createBlock('core/paragraph',{content:'Paragraph'})]);
	const section = conversion.visualToCode(api,source,doc);
	assert.equal(conversion.codeToVisual(api,section,doc),source);
	assert.throws(() => conversion.codeToVisual(api,{...section,css:'p{color:red}'},doc),/original native Group/);
});

test('restoring a native Group retains newly authored stylesheet and inline script in execution order', t => {
	const {api,doc} = context(t);
	const source = api.serialize([api.createBlock('core/group',{},[api.createBlock('core/heading',{content:'Hello'})])]);
	const section = {...conversion.visualToCode(api,source,doc),css:'h2{color:red}',js:'document.querySelector("h2").dataset.ready = "yes";',jsLocation:'inline'};
	const root = api.parse(conversion.codeToVisual(api,section,doc))[0];
	assert.equal(root.innerBlocks[0].attributes.css,section.css);
	assert.equal(root.innerBlocks[1].name,'core/heading');
	assert.equal(root.innerBlocks[2].attributes.js,section.js);
	assert.equal(root.innerBlocks[2].attributes.jsLocation,'inline');
});

test('semantic code becomes native editable content while unsupported HTML remains byte-for-byte code', t => {
	const {api,doc} = context(t);
	const custom = '<custom-widget data-label="a &amp; b"><span>Exact &copy; bytes</span></custom-widget>';
	const section = {name:'My hero',content:'<section id="hero" class="hero"><h1 class="title">A <em>clear</em> title</h1><p>Keep <a href="/about/?a=1&amp;b=2">this link</a><br>and line break.</p>' + custom + '</section>',css:'.hero > .title { color: red; }',js:'window.custom = true;',jsLocation:'inline',cssOutput:'file',cssDefer:true};
	const root = api.parse(conversion.codeToVisual(api,section,doc))[0];
	assert.equal(root.name,'core/group'); assert.equal(root.attributes.tagName,'section');
	assert.equal(root.attributes.anchor,'hero'); assert.equal(root.attributes.className,'hero');
	assert.equal(root.attributes.metadata.name,'My hero');
	assert.deepEqual(root.innerBlocks.map(block=>block.name),['gt-page-block/page-block','core/heading','core/paragraph','gt-page-block/page-block','gt-page-block/page-block']);
	assert.equal(root.innerBlocks[1].attributes.content,'A <em>clear</em> title');
	assert.equal(root.innerBlocks[1].attributes.level,1);
	assert.equal(root.innerBlocks[1].attributes.className,'title');
	assert.match(root.innerBlocks[2].attributes.content,/<br>/);
	assert.equal(root.innerBlocks[3].attributes.content,custom);
	assert.deepEqual(root.innerBlocks[0].attributes,{name:'Imported section styles',css:section.css,cssOutput:'file',cssDefer:true});
	assert.equal(root.innerBlocks[4].attributes.js,section.js);
	assert.equal(root.innerBlocks[4].attributes.jsLocation,'inline');
});

test('simple images and buttons preserve URLs, alt text, titles, link target, and relationship', t => {
	const {api,doc} = context(t);
	const root = api.parse(conversion.codeToVisual(api,{content:'<img src="/media/a.jpg" alt="A &amp; B" title="Picture"><a href="/go/?a=1&amp;b=2" target="_blank" rel="noopener" title="Open">Get <strong>started</strong></a><button type="submit">Submit</button>'},doc))[0];
	assert.equal(root.innerBlocks[0].name,'core/image');
	assert.deepEqual(root.innerBlocks[0].attributes,{url:'/media/a.jpg',alt:'A & B',title:'Picture'});
	const button = root.innerBlocks[1].innerBlocks[0];
	assert.equal(button.name,'core/button');
	assert.deepEqual(button.attributes,{text:'Get <strong>started</strong>',url:'/go/?a=1&b=2',linkTarget:'_blank',rel:'noopener',title:'Open'});
	assert.equal(root.innerBlocks[2].innerBlocks[0].attributes.tagName,'button');
	assert.equal(root.innerBlocks[2].innerBlocks[0].attributes.type,'submit');
});

test('unmapped attributes and inline styles stay in code leaves without being discarded', t => {
	const {api,doc} = context(t);
	const pieces = ['<p data-value="42" style="padding:3px">Keep attributes</p>','<img id="image-id" src="/photo.jpg" srcset="/photo-2x.jpg 2x" width="20">','<a class="custom-button" href="#go" aria-label="Start">Go</a>'];
	const root = api.parse(conversion.codeToVisual(api,{content:'<section><h2>Editable</h2>' + pieces.join('') + '</section>'},doc))[0];
	assert.deepEqual(root.innerBlocks.slice(1).map(block=>block.attributes.content),pieces);
	assert.equal(root.innerBlocks[0].name,'core/heading');
});

test('converting custom code retains comments and script/style tags exactly', t => {
	const {api,doc} = context(t);
	const script = '<script>if (1 < 2) { window.value = "<p>keep</p>"; }</script>';
	const style = '<style>.x::before { content: "<h1>keep</h1>"; }</style>';
	const root = api.parse(conversion.codeToVisual(api,{content:'<section><h1>Editable</h1><!-- custom comment -->' + script + style + '</section>'},doc))[0];
	assert.deepEqual(root.innerBlocks.slice(1).map(block=>block.attributes.content),['<!-- custom comment -->',script,style]);
});

test('code conversion refuses shared open containers, unmatched endings, and unfinished comments', t => {
	const {api,doc} = context(t);
	for (const html of ['<main><section><h1>First</h1></section>','<section><h1>Last</h1></section></main>','<section><h1>Title</h1></div>','<section><h1>Title</h1><!-- unfinished']) {
		assert.throws(()=>conversion.codeToVisual(api,{content:html},doc),/Nothing was converted/);
	}
	assert.throws(()=>conversion.codeToVisual(api,{content:'<section><script>unfinished'},doc),/unfinished script/);
});

test('code conversion refuses linked, PHP, file-output, and formatting-dependent sections', t => {
	const {api,doc} = context(t), base = {content:'<h1>Keep me</h1>'};
	for (const patch of [{blockId:9},{blockSlug:'shared-hero'},{phpExec:true},{output:'file'},{format:true},{content:'<?php echo "hello"; ?><h1>Keep me</h1>'}]) {
		assert.throws(()=>conversion.codeToVisual(api,{...base,...patch},doc),/Nothing was converted/);
	}
	assert.throws(()=>conversion.codeToVisual(api,{content:'<svg><path d="M0 0"></path></svg>'},doc),/custom markup/);
});

test('native conversion refuses dynamic blocks, bindings, PHP, linked helpers, nested native content, and shortcodes', t => {
	const {api,doc} = context(t);
	for (const child of [
		api.createBlock('core/query',{}),
		api.createBlock('core/paragraph',{content:'[year]'}),
		api.createBlock('core/paragraph',{content:'Bound',metadata:{bindings:{content:{source:'custom/source'}}}}),
		api.createBlock('gt-page-block/page-block',{blockId:7}),
		api.createBlock('gt-page-block/page-block',{phpExec:true}),
		api.createBlock('gt-page-block/page-block',{nativeContent:true}),
		{...api.createBlock('core/heading',{content:'Invalid'}),isValid:false}
	]) {
		const source = api.serialize([api.createBlock('core/group',{},[child])]);
		assert.throws(()=>conversion.visualToCode(api,source,doc),/Nothing was converted/);
	}
});

test('invalid native block comment structure is refused before a recovering parser can alter it', t => {
	const {api,doc} = context(t);
	for (const source of ['<!-- wp:group --><div><p>Hello</p></div>','<!-- wp:group --><div></div><!-- /wp:paragraph -->','<!-- wp:group {bad} --><div></div><!-- /wp:group -->']) {
		assert.throws(()=>conversion.visualToCode(api,source,doc),/WordPress block/);
	}
});
