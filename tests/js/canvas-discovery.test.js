const test=require('node:test');
const assert=require('node:assert/strict');
const mount=require('./helpers/canvas');
const builder=require('./helpers/builder');
const canvas=require('../../assets/js/canvas-editor');

test('first heading starts a native section in one insertion and is immediately editable',t=>{
 const b=mount(t);b.sections.length=0;b.editor.clearSelection();b.editor.render();
 assert.equal(b.doc.querySelector('.pb-canvas-welcome').hidden,false);
 b.doc.querySelector('[aria-label="Add"]').click();
 assert.equal(b.doc.activeElement.name,'pb-canvas-search');
 b.doc.querySelector('[data-insert-block="heading"]').click();
 assert.equal(b.sections.length,1);assert.equal(b.tree().innerBlocks.length,1);assert.equal(b.tree().innerBlocks[0].name,'core/heading');assert.equal(b.changes.length,0);
 assert.equal(b.field('text').value,'Your heading');assert.equal(b.doc.querySelector('.pb-canvas-welcome').hidden,true);
});
test('search offers native starter sections and leaves existing code untouched',t=>{
 const b=mount(t),original=b.sections[0].serialized;b.doc.querySelector('[aria-label="Add"]').click();const search=b.doc.querySelector('[name="pb-canvas-search"]');search.value='services';search.dispatchEvent(new b.win.Event('input'));
 assert.equal(b.doc.querySelector('[data-preset="features"]').hidden,false);assert.equal(b.doc.querySelector('[data-insert-block="heading"]').hidden,true);
 b.doc.querySelector('[data-preset="features"]').click();assert.equal(b.sections[0].serialized,original);const root=JSON.parse(b.sections[1].serialized)[0];assert.equal(root.innerBlocks[1].name,'core/columns');assert.equal(b.sections[1].label,'Three columns');
});
test('design opens without the layer tree and Layers returns to the selected block controls',t=>{
 const b=mount(t);b.select('0.1');assert.ok(b.field('font-size'));assert.equal(b.doc.querySelector('.pb-canvas-layer'),null);
 b.doc.querySelector('[role="tab"][aria-label="Layers"]').click();assert.equal(b.field('font-size'),null);b.doc.querySelector('.pb-canvas-layer.is-selected').click();assert.ok(b.field('font-size'));assert.equal(b.doc.querySelector('[role="tab"][aria-label="Design"]').getAttribute('aria-selected'),'true');
 b.doc.querySelector('.pb-canvas-breadcrumbs button').click();assert.equal(b.doc.querySelector('.pb-canvas-selection-title').textContent,'Section');
});
test('Auto insertion goes after the selected sibling while preserving subsequent content',t=>{
 const b=mount(t),root=b.tree();root.attributes.className='';root.innerBlocks.push({name:'core/paragraph',attributes:{content:'Keep last'},innerBlocks:[],clientId:'last'});b.sections[0].serialized=JSON.stringify([root]);b.editor.sync([{uid:b.sections[0].uid,source:b.sections[0].serialized}]);b.select('0.1');b.doc.querySelector('[data-insert-block="heading"]').click();
 assert.equal(b.tree().innerBlocks[2].attributes.content,'Your heading');assert.equal(b.tree().innerBlocks[3].attributes.content,'Keep last');
});
test('color picker and named theme swatches use the same saved style controls',t=>{
 const b=mount(t,1200,undefined,{themePalette:[{slug:'brand',name:'Brand blue',color:'#123456'}]});b.select('0.1');b.doc.querySelector('[aria-label="Text color: Brand blue"]').click();assert.equal(b.tree().innerBlocks[1].attributes.style.color.text,'var(--wp--preset--color-brand)');
 const picker=b.doc.querySelector('[aria-label="Choose text color"]');picker.value='#abcdef';picker.dispatchEvent(new b.win.Event('change'));assert.equal(b.tree().innerBlocks[1].attributes.style.color.text,'#abcdef');assert.equal(b.field('text-color').value,'#abcdef');
});
test('shell duplication gives native sections independent style identities',t=>{
 const block=(name,attributes={},innerBlocks=[])=>({name,attributes,innerBlocks,clientId:Math.random().toString(36),isValid:true});
 const root=block('core/group',{anchor:'original',className:'pb-freeform pb-layout-old'},[block('gt-page-block/page-block',{name:'Page Blocks canvas styles',css:'.pb-layout-old .pb-node-old{color:red}'}),block('core/heading',{content:'Retained',className:'pb-node-old'})]);
 const b=builder(t,[{kind:'foreign',blockName:'core/group',serialized:JSON.stringify([root]),rendered:'<section>Retained</section>'}]);b.window.wp={blocks:{parse:JSON.parse,serialize:JSON.stringify,cloneBlock:(b,a,children)=>block(b.name,a,children)}};b.window.gtPbCanvasEditor=canvas;b.key('d',{ctrlKey:true});b.control('apply').click();
 const sections=b.requests[0].sections;assert.equal(sections.length,2);const duplicate=JSON.parse(sections[1].serialized)[0];assert.equal(duplicate.innerBlocks[1].attributes.content,'Retained');assert.notEqual(duplicate.attributes.className,root.attributes.className);assert.equal(duplicate.attributes.anchor,undefined);assert.doesNotMatch(duplicate.innerBlocks[0].attributes.css,/pb-layout-old|pb-node-old/);
});
test('Add code section opens code editing while retaining the existing visual section',t=>{
 const markup='<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->';const b=builder(t,[{kind:'foreign',blockName:'core/group',serialized:markup,rendered:'<div></div>'}],{config:{builderMode:'visual'}});b.control('add-section').click();assert.equal(b.window.document.querySelector('.md-pb-shell').classList.contains('is-visual-mode'),false);assert.equal(b.window.document.querySelector('.md-pb-shell').classList.contains('is-code-hidden'),false);b.control('apply').click();assert.equal(b.requests[0].sections[0].serialized,markup);assert.equal(b.requests[0].sections.length,2);
});

test('reselecting a section after clearing selection returns to its design controls',t=>{
 const b=mount(t);b.select('0.1');b.editor.clearSelection();b.editor.render();b.editor.selectSectionRoot('pb-group');assert.equal(b.doc.querySelector('.pb-canvas-selection-title').textContent,'Section');assert.ok(b.field('placement'));
});
test('adding a visual section preserves whitespace HTML with authored CSS or JS',t=>{
 for(const property of ['css','js']){
  let callbacks;const b=builder(t,[],{config:{builderMode:'visual'},beforeInit:w=>{w.gtPbCanvasEditor={mount:opts=>{callbacks=opts;return {render(){},sync(){},updateHistory(){},invalidate(){},clearSelection(){}};}};}});
  const html=b.control('textarea-html');html.value='  ';html.dispatchEvent(new b.window.Event('input'));
  const code=b.control('textarea-'+property),value=property==='css'?'.keep{color:red}':'window.keep=true;';code.value=value;code.dispatchEvent(new b.window.Event('input'));
  callbacks.addSection('<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',null,'Blank section');b.control('apply').click();assert.equal(b.requests[0].sections.length,2);assert.equal(b.requests[0].sections[0][property],value);
 }
});
test('whitespace HTML does not hide authored code behind the empty-canvas welcome',t=>{
 const b=mount(t);b.sections.splice(0,1,{uid:'pb-code',kind:'block',content:'  ',css:'.keep{color:red}',js:''});b.editor.clearSelection();b.editor.render();assert.equal(b.doc.querySelector('.pb-canvas-welcome').hidden,true);
});
