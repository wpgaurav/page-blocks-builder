const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const canvas = require('../../assets/js/canvas-editor.js');
const layout = require('../../assets/js/canvas-layout.js');
const preview = require('../../assets/js/preview-dom.js');
const builder = require('./helpers/builder.js');

function mount(t, width = 1200, children, config = {}) {
	const page = new JSDOM('<div id="stage"><div class="md-pb-canvas-toolbar"></div></div><div id="panel"></div>',{url:'https://builder.test/',runScripts:'outside-only'});
	t.after(()=>page.window.close());
	const win = page.window, doc = win.document, messages = [], changes = [], viewports = [];
	const block = (name,attrs={},innerBlocks=[])=>({name,attributes:attrs,innerBlocks,clientId:Math.random().toString(36),isValid:true});
	const root = block('core/group',{className:'pb-freeform pb-layout-a'},children || [block('gt-page-block/page-block',{name:'Page Blocks canvas styles',css:'.keep{color:red}'}),block('core/heading',{content:'A <strong>bold</strong> title',className:'pb-node-a'})]);
	const sections = [{uid:'pb-group',kind:'foreign',blockName:'core/group',serialized:JSON.stringify([root])}];
	const api = {getBlockType:name=>({supports:{typography:{fontSize:true},color:{text:true}}}),parse:JSON.parse,serialize:JSON.stringify,createBlock:block,cloneBlock:(b,a,children)=>block(b.name,a,children)};
	win.gtPbCanvasLayout = layout; win.gtPbPreviewDom = preview;
	const frame = {contentWindow:{innerWidth:width,postMessage:m=>messages.push(m)}};
	const editor = canvas.mount({container:doc.querySelector('#panel'),canvas:doc.querySelector('#stage'),config:{icons:{},...config},wp:{blocks:api},getFrame:()=>frame,getSections:()=>sections,isEnabled:()=>true,selectSection:()=>{},onChange:(uid,patch)=>{changes.push(patch);Object.assign(sections[0],patch);},addSection:()=>{},deleteSection:()=>{},canUndo:()=>false,canRedo:()=>false,undo:()=>{},redo:()=>{},save:()=>{},setPreviewViewport:v=>viewports.push(v)});
	editor.sync([{uid:'pb-group',source:sections[0].serialized}]);
	const select = path => editor.handleMessage({type:'pb_canvas_select',sectionUid:'pb-group',nativePath:path});
	const field = name => doc.querySelector('[name="pb-canvas-' + name + '"]');
	const change = (name,value)=>{const input=field(name);input.value=value;input.dispatchEvent(new win.Event('change'));};
	return {win,doc,editor,sections,messages,changes,viewports,select,field,change,tree:()=>JSON.parse(sections[0].serialized)[0]};
}

test('Auto placement removes only its grid CSS and keeps content and authored styling', t => {
	const b=mount(t); var root=b.tree();root.innerBlocks[0].attributes.css='/* before */\n'+layout.css('pb-layout-a',[{key:'pb-node-a',x:20,y:20,w:200,h:80}],1200,300)+'\n/* author rule */ .pb-node-a{color:red}';b.sections[0].serialized=JSON.stringify([root]);b.editor.sync([{uid:'pb-group',source:b.sections[0].serialized}]);b.select('0.1');b.change('placement','auto');
	assert.doesNotMatch(b.tree().attributes.className,/pb-freeform|pb-layout-/);assert.equal(b.tree().innerBlocks[1].attributes.content,'A <strong>bold</strong> title');assert.match(b.tree().innerBlocks[0].attributes.css,/author rule/);assert.doesNotMatch(b.tree().innerBlocks[0].attributes.css,/display:grid/);
});

test('a new visual section starts in Auto placement', () => {
	const api={createBlock:(name,attributes,innerBlocks=[])=>({name,attributes,innerBlocks}),serialize:JSON.stringify};
	const root=JSON.parse(canvas.createSection(api,layout,'hero'))[0];assert.equal(root.name,'core/group');assert.equal(root.innerBlocks.length,3);assert.doesNotMatch(root.attributes.className || '',/pb-freeform/);
});

test('Auto drag changes native reading order without adding geometric placement', t => {
	const b=mount(t),root=b.tree();canvas.autoPlacement(root);root.innerBlocks.push({name:'core/paragraph',attributes:{content:'Second'},innerBlocks:[],clientId:'second',isValid:true});b.sections[0].serialized=JSON.stringify([root]);b.editor.sync([{uid:'pb-group',source:b.sections[0].serialized}]);
	b.editor.handleMessage({type:'pb_canvas_reorder',sectionUid:'pb-group',parentPath:'0',path:'0.1',targetPath:'0.2',after:true});
	assert.equal(b.tree().innerBlocks.at(-1).name,'core/heading');assert.doesNotMatch(b.tree().attributes.className,/pb-freeform/);
});

test('Auto sections can opt into Freeform using measured placement', t => {
	const b=mount(t),root=b.tree();canvas.autoPlacement(root);b.sections[0].serialized=JSON.stringify([root]);b.editor.sync([{uid:'pb-group',source:b.sections[0].serialized}]);b.select('0.1');b.change('placement','freeform');
	assert.equal(b.messages.at(-1).type,'pb_canvas_measure');b.editor.handleMessage({type:'pb_canvas_measure_result',sectionUid:'pb-group',rootPath:'0',viewportWidth:1200,width:1200,height:300,elements:[{path:'0.1',x:20,y:20,w:200,h:40}]});
	assert.match(b.tree().attributes.className,/pb-freeform/);
});

test('dropped media uses WordPress upload and inserts a native image in Auto flow', async t => {
	const b=mount(t,1200,undefined,{mediaEndpoint:'/wp-json/wp/v2/media',restNonce:'test',postId:42}),root=b.tree();canvas.autoPlacement(root);b.sections[0].serialized=JSON.stringify([root]);b.editor.sync([{uid:'pb-group',source:b.sections[0].serialized}]);
	var requests=[];b.win.fetch=async(url,options)=>{requests.push({url,options});return {ok:true,json:async()=>({id:44,source_url:'https://builder.test/upload.png',mime_type:'image/png',alt_text:'Uploaded'})};};
	b.editor.handleMessage({type:'pb_canvas_files',sectionUid:'pb-group',containerPath:'0',targetPath:'0.1',after:true,files:[new b.win.File(['image'],'upload.png',{type:'image/png'})]});await new Promise(r=>setImmediate(r));
	assert.equal(requests.length,1);assert.equal(requests[0].options.headers['X-WP-Nonce'],'test');assert.equal(requests[0].options.body.get('file').name,'upload.png');assert.equal(b.tree().innerBlocks.at(-1).name,'core/image');assert.equal(b.tree().innerBlocks.at(-1).attributes.alt,'Uploaded');
});

test('failed media uploads retain the document and surface the WordPress error', async t => {
	const b=mount(t,1200,undefined,{mediaEndpoint:'/media',restNonce:'test',postId:42}),before=b.sections[0].serialized;
	b.win.fetch=async()=>({ok:false,json:async()=>({message:'This file type is not allowed.'})});b.editor.handleMessage({type:'pb_canvas_files',sectionUid:'pb-group',files:[new b.win.File(['svg'],'image.svg',{type:'image/svg+xml'})]});await new Promise(r=>setImmediate(r));
	assert.equal(b.sections[0].serialized,before);assert.match(b.doc.querySelector('.pb-canvas-notice').textContent,/not allowed/);
});

test('media finishing after an intervening section edit does not overwrite that edit', async t => {
	const b=mount(t,1200,undefined,{mediaEndpoint:'/media',restNonce:'test',postId:42});var release;
	b.win.fetch=()=>new Promise(resolve=>release=resolve);b.editor.handleMessage({type:'pb_canvas_files',sectionUid:'pb-group',containerPath:'0',files:[new b.win.File(['image'],'image.png',{type:'image/png'})]});b.sections[0].serialized='changed by the author';release({ok:true,json:async()=>({id:44,source_url:'https://builder.test/new.png',mime_type:'image/png'})});await new Promise(r=>setImmediate(r));
	assert.equal(b.sections[0].serialized,'changed by the author');assert.match(b.doc.querySelector('.pb-canvas-notice').textContent,/section changed during upload/);
});

test('plain inspector text changes retain unchanged emphasis and links', () => {
	const doc = new JSDOM('').window.document;
	const html = 'A <strong>bold</strong> title and <a href="/about/">more</a>';
	assert.equal(canvas.editPlainText(html,'A bold heading and more',doc),'A <strong>bold</strong> heading and <a href="/about/">more</a>');
	assert.equal(canvas.editPlainText(html,'A bold title and more',doc),html);
	assert.equal(canvas.editPlainText('<strong>Replace</strong>','',doc),'<strong></strong>');
});

test('canvas tools occupy the preview header and the Add disclosure closes with Escape', t => {
	const b=mount(t); b.select('0.1');
	assert.ok(b.doc.querySelector('.md-pb-canvas-toolbar .pb-canvas-rail'));
	const add=b.doc.querySelector('[aria-controls]'); add.click(); assert.equal(add.getAttribute('aria-expanded'),'true');
	b.doc.dispatchEvent(new b.win.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
	assert.equal(add.getAttribute('aria-expanded'),'false'); assert.equal(b.doc.activeElement,add);
});

test('a failed preview remains paused when toggling Grid', t => {
	const b=mount(t); b.select('0.1'); b.editor.invalidate(); b.doc.querySelector('[aria-label="Grid"]').click();
	const mode=b.messages.filter(m=>m.type==='pb_canvas_mode').at(-1);
	assert.equal(mode.enabled,false); assert.equal(mode.paused,true);
});

test('native controls reflect existing save permissions without accepting unsavable edits', t => {
	const b=mount(t,1200,undefined,{canEditNativeBlocks:false,editPostUrl:'/wp-admin/post.php'});b.select('0.1');
	assert.equal(b.field('text'),null);assert.match(b.doc.querySelector('#panel').textContent,/current permissions/);
	assert.equal(b.doc.querySelector('.pb-canvas-palette [aria-label="New visual section"]').disabled,true);
	assert.equal(b.messages.filter(m=>m.type==='pb_canvas_mode').at(-1).nativeEditable,false);
});

test('responsive scope changes its preview and selected layers expose their state', t => {
	const b=mount(t); b.select('0.1'); b.change('apply-to','mobile');
	assert.deepEqual(b.viewports,['480']);
	assert.equal(b.doc.querySelector('.pb-canvas-layer.is-selected').getAttribute('aria-pressed'),'true');
});

test('first visible layer cannot move above the hidden CSS helper', t => {
	const b=mount(t); b.select('0.1');
	assert.equal(b.doc.querySelector('[aria-label="Move up in reading order"]').disabled,true);
	assert.equal(b.doc.querySelector('[aria-label="Move down in reading order"]').disabled,true);
});

test('custom font size clears its preset and unchanged fields create no history entry', t => {
	const b=mount(t); b.select('0.1');
	b.change('text','A bold title'); assert.equal(b.changes.length,0);
	const tree=b.tree();tree.innerBlocks[1].attributes.fontSize='large';b.sections[0].serialized=JSON.stringify([tree]);b.select('0.1');
	b.change('font-size','22px');
	assert.equal(b.tree().innerBlocks[1].attributes.fontSize,undefined);
	assert.equal(b.tree().innerBlocks[1].attributes.style.typography.fontSize,'22px');
});

test('empty freeform section accepts its first element with finite geometry', t => {
	const b=mount(t,1200,[]); b.select('0');
	b.doc.querySelector('[aria-controls]').click(); b.doc.querySelector('.pb-canvas-palette [aria-label="Heading"]').click();
	b.editor.handleMessage({type:'pb_canvas_measure_result',sectionUid:'pb-group',rootPath:'0',width:1200,height:240,elements:[]});
	assert.equal(b.tree().innerBlocks.at(-1).name,'core/heading');
	assert.doesNotMatch(b.tree().innerBlocks[0].attributes.css,/NaN|Infinity/);
});

test('mobile freeform actions explain their desktop requirement before editing', t => {
	const b=mount(t,480); b.select('0.1');
	assert.equal(b.doc.querySelector('.pb-canvas-actions [aria-label="Duplicate"]').disabled,true);
	b.doc.querySelector('[aria-controls]').click();
	assert.equal(b.doc.querySelector('.pb-canvas-palette [aria-label="Heading"]').disabled,true);
	assert.match(b.doc.querySelector('.pb-canvas-palette p').textContent,/Switch to Desktop/);
});

test('source image attribute replacement can discard old responsive sources without changing sibling bytes', () => {
	const doc=new JSDOM('').window.document, html='<section><img src="old.png" srcset="old-large.png 2x" sizes="100vw" alt="Keep"><p>Keep exact bytes</p></section>';
	let result=preview.replaceAttribute(html,{path:[0,0],tagName:'img',oldValue:'old.png'},'src','new.png',doc);
	for (const name of ['srcset','sizes']) result=preview.replaceAttribute(result,{path:[0,0],tagName:'img'},name,'',doc);
	assert.equal(result,'<section><img src="new.png" alt="Keep"><p>Keep exact bytes</p></section>');
});

test('image selection updates its URL and media alt text together, retaining literal dollars', () => {
	const doc=new JSDOM('').window.document, html='<img src="old.png" srcset="old-large.png 2x" alt="Old"><p>Keep</p>';
	const changed=preview.replaceImageSource(html,{path:[0],tagName:'img',oldValue:'old.png'},{url:'new.png?tag=$&',alt:'Price $&'},doc);
	assert.equal(changed,'<img src="new.png?tag=$&amp;" alt="Price $&amp;"><p>Keep</p>');
});

function bridge(t) {
	const page=new JSDOM('<section data-pb-section="pb-group" data-pb-root-index="0"><h2 data-pb-canvas-section="pb-group" data-pb-canvas-path="0.1" data-pb-canvas-type="core/heading">Original</h2></section>',{url:'https://builder.test/',runScripts:'outside-only',pretendToBeVisual:true});
	t.after(()=>page.window.close());const win=page.window,doc=win.document,messages=[];
	win.postMessage=m=>messages.push(m);
	win.eval(fs.readFileSync(require.resolve('../../assets/js/canvas-bridge.js'),'utf8'));
	win.eval(win.gtPbCanvasBridge.script({},true));
	const h=doc.querySelector('h2'); h.getBoundingClientRect=()=>({left:990,right:1020,top:10,bottom:40,width:30,height:30});
	Object.defineProperty(win.HTMLElement.prototype,'offsetWidth',{get(){return this.classList.contains('pb-canvas-tools')?300:30;}});
	Object.defineProperty(win.HTMLElement.prototype,'offsetHeight',{get(){return 36;}});
	h.click();return {win,doc,h,messages};
}

test('element toolbar stays within the viewport at its right and upper edges', t => {
	const b=bridge(t), tools=b.doc.querySelector('.pb-canvas-tools'), box=b.doc.querySelector('.pb-canvas-box');
	const x=parseFloat(box.style.left)+parseFloat(tools.style.left),y=parseFloat(box.style.top)+parseFloat(tools.style.top);
	assert.ok(x>=8 && x+300<=b.win.innerWidth-8); assert.ok(y>=8 && y+36<=b.win.innerHeight-8);
});

test('Edit text starts typing, Escape cancels once, and Enter commits once', t => {
	const b=bridge(t); b.doc.querySelector('[data-pb-edit]').click(); assert.equal(b.h.contentEditable,'true');
	b.h.innerHTML='Cancelled'; b.h.dispatchEvent(new b.win.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
	assert.equal(b.h.innerHTML,'Original'); assert.equal(b.messages.filter(m=>m.type==='pb_canvas_text').length,0);
	b.doc.querySelector('[data-pb-edit]').click();b.h.innerHTML='Kept';b.h.dispatchEvent(new b.win.KeyboardEvent('keydown',{key:'Enter',bubbles:true}));
	assert.equal(b.messages.filter(m=>m.type==='pb_canvas_text').length,1);
});

test('protected source text can be inspected without accepting unsavable inline edits', t => {
	const b=bridge(t); for (const name of ['data-pb-canvas-section','data-pb-canvas-path','data-pb-canvas-type']) b.h.removeAttribute(name);
	b.h.click(); assert.equal(b.doc.querySelector('[data-pb-edit]').getAttribute('aria-label'),'Inspect');
	b.h.dispatchEvent(new b.win.MouseEvent('dblclick',{bubbles:true}));assert.notEqual(b.h.contentEditable,'true');
});

test('keyboard nudges use the content box of a padded and bordered group', t => {
	const b=bridge(t),root=b.h.parentElement;root.className='pb-freeform';root.dataset.pbCanvasPath='0';root.style.padding='20px';root.style.border='2px solid black';
	root.getBoundingClientRect=()=>({left:100,right:700,top:100,bottom:400,width:600,height:300});
	b.h.getBoundingClientRect=()=>({left:142,right:242,top:142,bottom:172,width:100,height:30});
	b.h.dispatchEvent(new b.win.KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true}));
	const move=b.messages.find(m=>m.type==='pb_canvas_layout');assert.equal(move.width,556);assert.equal(move.elements[0].x,21);assert.equal(move.elements[0].y,20);
	assert.equal(move.viewportWidth,b.win.innerWidth);
});

test('a narrow group in a desktop preview is still editable as freeform', t => {
	const b=mount(t,1200); b.select('0');
	b.editor.handleMessage({type:'pb_canvas_layout',sectionUid:'pb-group',rootPath:'0',viewportWidth:1200,width:600,height:300,elements:[{path:'0.1',x:20,y:20,w:200,h:40}]});
	assert.ok(b.changes.length); assert.match(b.tree().innerBlocks[0].attributes.css,/canvas layout/);
});

test('freeform sizing keeps the requested box height and accounts for native outer margins', () => {
	const css=layout.css('pb-layout-a',[{key:'pb-node-a',x:44,y:32,w:240,h:96,ml:12,mr:12,mt:8,mb:8}],600,240,true);
	assert.match(css,/width:calc\(100% - 24px\)/);assert.match(css,/min-height:96px/);assert.match(css,/box-sizing:border-box/);
});

test('the click synthesized after a Move gesture does not clear the selected element', t => {
	const b=bridge(t),root=b.h.parentElement; b.win.gtPbCanvasLayout=layout;root.className='pb-freeform';root.dataset.pbCanvasPath='0';
	root.getBoundingClientRect=()=>({left:0,right:1024,top:0,bottom:400,width:1024,height:400});
	b.h.getBoundingClientRect=()=>({left:24,right:224,top:64,bottom:104,width:200,height:40});b.h.click();
	b.doc.querySelector('[data-pb-move]').dispatchEvent(new b.win.MouseEvent('pointerdown',{bubbles:true,button:0,clientX:40,clientY:40}));
	assert.equal(b.doc.querySelector('[data-pb-ghost]').style.gridArea,'auto');
	b.win.dispatchEvent(new b.win.MouseEvent('pointermove',{clientX:80,clientY:80}));b.win.dispatchEvent(new b.win.MouseEvent('pointerup'));
	b.doc.body.click();assert.equal(b.messages.filter(m=>m.type==='pb_canvas_clear').length,0);
	assert.equal(b.messages.filter(m=>m.type==='pb_canvas_layout').length,1);
});

test('Auto Move gestures emit insertion order rather than freeform coordinates', t => {
	const b=bridge(t),root=b.h.parentElement;root.dataset.pbCanvasType='core/group';root.dataset.pbCanvasPath='0';
	b.h.getBoundingClientRect=()=>({left:24,right:224,top:24,bottom:64,width:200,height:40});
	const p=b.doc.createElement('p');p.dataset.pbCanvasPath='0.2';p.dataset.pbCanvasType='core/paragraph';p.textContent='Second';p.getBoundingClientRect=()=>({left:24,right:224,top:100,bottom:140,width:200,height:40});root.appendChild(p);b.h.click();
	b.doc.querySelector('[data-pb-move]').dispatchEvent(new b.win.MouseEvent('pointerdown',{bubbles:true,button:0,clientX:40,clientY:20}));
	b.win.dispatchEvent(new b.win.MouseEvent('pointermove',{clientX:50,clientY:160}));b.win.dispatchEvent(new b.win.MouseEvent('pointerup'));
	const message=b.messages.find(m=>m.type==='pb_canvas_reorder');assert.equal(message.targetPath,'0.2');assert.equal(message.after,true);assert.equal(b.messages.some(m=>m.type==='pb_canvas_layout'),false);
});

test('preview failure is visible and Retry preview requests a fresh render', async t => {
	const b=builder(t,[{}],{config:{builderMode:'visual',previewEndpoint:'/preview',previewNonce:'test'}}); b.flush(250);
	b.requests[0].reject(new Error('Offline'));await b.settle();
	assert.equal(b.control('preview-status').hidden,false);assert.equal(b.control('preview-label').textContent,'Preview unavailable');
	b.control('retry-preview').click();b.flush(250);assert.equal(b.requests.length,2);
	b.requests[1].resolve({success:true,data:{html:'',css:'',js:''}});await b.settle();
	assert.equal(b.control('preview-status').hidden,true);
});

test('theme body-class objects and strings remain compatible with the preview document', t => {
	for (const classes of [{0:'page',4:'custom-theme'},'page custom-theme']) {
		const b=builder(t,[{}],{config:{previewBodyClasses:classes}});b.flush(0);
		assert.match(b.control('preview-status').hidden.toString(),/true/);
		assert.match(b.window.document.querySelector('.md-pb-preview-frame').srcdoc,/body class="page custom-theme"/);
	}
});

test('closing unchanged page settings leaves the document saved', t => {
	const b=builder(t,[{}],{config:{postTemplate:'default-template',availableTemplates:[{slug:'default-template',label:'Default'}]}});
	b.control('page-settings').click(); b.window.document.querySelector('[data-role="settings-done"]').click();
	assert.equal(b.control('save-status').textContent,'All changes saved');
});

test('an unavailable saved template is identified without silently choosing a replacement', t => {
	const b=builder(t,[{}],{config:{postTemplate:'old-template.php',availableTemplates:[{slug:'default-template',label:'Default'}]}});
	b.control('page-settings').click();
	assert.equal(b.window.document.querySelector('[data-role="setting-template"]').value,'old-template.php');
	assert.match(b.window.document.querySelector('#md-pb-settings-overlay').textContent,/current template is unavailable/);
});
