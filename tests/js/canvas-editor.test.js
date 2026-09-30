const test = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const canvas = require('../../assets/js/canvas-editor.js');
const layout = require('../../assets/js/canvas-layout.js');
const preview = require('../../assets/js/preview-dom.js');
const builder = require('./helpers/builder.js');

test('freeform grid tracks preserve exact placement when snapping is bypassed', () => {
	const elements = [{ key:'pb-node-a',x:16,y:24,w:160,h:48 },{ key:'pb-node-b',x:17,y:100,w:160,h:48 }];
	const solved = layout.solve(elements,400,220,true);
	assert.notEqual(solved.areas[0].c1,solved.areas[1].c1);
	assert.ok(solved.areas.every(a=>a.c2>a.c1&&a.r2>a.r1));
	const css = layout.css('pb-layout-a',elements,400,220,true);
	assert.match(css, /display:grid/);
	assert.match(css, /@media\(max-width:768px\)/);
	assert.match(css, /canvas layout end/);
	assert.equal(layout.css('unsafe}{',elements,400,220),null);
});

test('alignment magnets snap to neighbors while free drag keeps the requested position', () => {
	const rect = {x:101,y:35,w:80,h:20}, others=[{x:100,y:80,w:50,h:20}];
	assert.equal(layout.snap(rect,others,400,200,false).x,100);
	assert.equal(layout.snap(rect,others,400,200,true).x,101);
});

test('visual style updates change only their managed rule and retain authored CSS', () => {
	const authored = '/* Keep exact author bytes */\n#hero { color: red; }';
	const first = canvas.setRule(authored,'pb-node-a','#hero .pb-node-a','color','#315b56','mobile');
	const second = canvas.setRule(first,'pb-node-a','#hero .pb-node-a','color','#000000','mobile');
	assert.ok(second.startsWith(authored));
	assert.equal((second.match(/:start/g)||[]).length,1);
	assert.match(second, /max-width:480px/);
	assert.equal(canvas.ruleValue(second,'pb-node-a','color','mobile'),'#000000');
	assert.equal(canvas.ruleValue(second,'pb-node-a','color','desktop'),'');
	assert.equal(canvas.setRule(authored,'pb-node-a','.pb-node-a','color','red;}</style>','desktop'),null);
	const old = 'author-before\n/* Page Blocks canvas layout */old/* Page Blocks canvas layout end */\nauthor-after';
	assert.equal(canvas.replaceLayoutCss(old,'new'),'author-before\nnew\nauthor-after');
});

test('source attribute edits retain cross-section ancestors and duplicate text', () => {
	const doc = new JSDOM('').window.document;
	const html = '<main id="shared"><section><a href="/first/">Same</a><a href="/second/">Same</a></section>';
	const result = preview.replaceAttribute(html,{path:[0,0,1],tagName:'a',oldValue:'/second/'},'href','/updated/?a=1&b=2',doc);
	assert.equal(result,'<main id="shared"><section><a href="/first/">Same</a><a href="/updated/?a=1&amp;b=2">Same</a></section>');
	assert.equal(preview.replaceAttribute(html,{path:[0,0,1],tagName:'a',oldValue:'/stale/'},'href','/new/',doc),null);
});

test('Visual and Code switch in the established workspace without saving or navigating', t => {
	const b = builder(t,[{ content:'<section id="hero"><h1>Keep me</h1></section>',css:'#hero{color:red}',js:'window.keep=true;' }]);
	b.control('open-visual').click();
	assert.ok(b.window.document.querySelector('.md-pb-shell').classList.contains('is-visual-mode'));
	assert.ok(b.control('toggle-ai'));
	assert.ok(b.control('page-settings'));
	assert.equal(b.requests.length,0);
	b.control('toggle-code').click();
	assert.equal(b.window.document.querySelector('.md-pb-shell').classList.contains('is-visual-mode'),false);
	b.key('s',{ctrlKey:true});
	assert.equal(b.requests.at(-1).sections[0].js,'window.keep=true;');
	assert.equal(b.requests.at(-1).sections[0].css,'#hero{color:red}');
});

test('canvas text keeps supported inline links and removes executable markup', () => {
	const doc = new JSDOM('').window.document;
	assert.equal(canvas.cleanInline('<strong>Bold</strong> <a href="/about/" onclick="bad()">link</a><script>bad()</script>',doc),'<strong>Bold</strong> <a href="/about/">link</a>');
});

test('duplicated native sections have independent layout keys and retained content', () => {
	const api = { cloneBlock: (b,a,children) => ({name:b.name,attributes:a,innerBlocks:children,clientId:Math.random().toString()}) };
	const source = {name:'core/group',attributes:{className:'pb-freeform pb-layout-a',anchor:'hero'},innerBlocks:[
		{name:'gt-page-block/page-block',attributes:{name:'Page Blocks canvas styles',css:'.pb-layout-a>.pb-node-a{color:red}'},innerBlocks:[]},
		{name:'core/heading',attributes:{className:'pb-node-a',content:'Keep this heading'},innerBlocks:[]}
	]};
	const duplicate = canvas.cloneNative(source,api);
	assert.notEqual(duplicate.attributes.className,source.attributes.className);
	assert.equal(duplicate.attributes.anchor,undefined);
	assert.equal(duplicate.innerBlocks[1].attributes.content,'Keep this heading');
	assert.equal(duplicate.innerBlocks[0].attributes.css.includes('pb-node-a'),false);
	assert.ok(duplicate.innerBlocks[0].attributes.css.includes(duplicate.innerBlocks[1].attributes.className));
	assert.equal(source.innerBlocks[1].attributes.className,'pb-node-a');
});

test('canvas link controls accept page anchors and reject executable URL protocols', () => {
	assert.equal(canvas.safeURL('/about/',false),true);
	assert.equal(canvas.safeURL('#contact',false),true);
	assert.equal(canvas.safeURL('mailto:hello@example.com',false),true);
	assert.equal(canvas.safeURL('java\nscript:alert(1)',false),false);
	assert.equal(canvas.safeURL('data:text/html,bad',true),false);
});

test('duplicating an element retains scoped responsive styles without sharing its class', () => {
	const api = { cloneBlock:(b,a,children)=>({name:b.name,attributes:a,innerBlocks:children,clientId:'copy'}) };
	const heading = {name:'core/heading',attributes:{className:'pb-node-a',content:'Styled'},innerBlocks:[],clientId:'heading'};
	const styleBlock = {name:'gt-page-block/page-block',attributes:{css:canvas.setRule('author-css','pb-node-a','.pb-node-a.pb-node-a','color','#315b56','mobile')},innerBlocks:[],clientId:'styles'};
	const root = {name:'core/group',attributes:{},innerBlocks:[styleBlock,heading],clientId:'root'};
	const duplicate = canvas.duplicateWithin(heading,[root],api);
	assert.equal(canvas.ruleValue(styleBlock.attributes.css,duplicate.attributes.className,'color','mobile'),'#315b56');
	assert.equal(canvas.ruleValue(styleBlock.attributes.css,'pb-node-a','color','mobile'),'#315b56');
	assert.ok(styleBlock.attributes.css.startsWith('author-css'));
});
