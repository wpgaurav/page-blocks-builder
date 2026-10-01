const test=require('node:test');
const assert=require('node:assert/strict');
const presets=require('../../assets/js/canvas-presets');
const api={createBlock:(name,attributes={},innerBlocks=[])=>({name,attributes,innerBlocks})};
test('starter sections are native block trees with Auto placement and independent instances',()=>{
 for(const item of presets.items){
  const root=presets.create(api,item.id);
  assert.equal(root.name,'core/group');assert.equal(root.attributes.layout.type,'default');
  const visit=b=>{assert.match(b.name,/^core\//);assert.equal(b.attributes.visualData,undefined);assert.doesNotMatch(b.attributes.className||'',/pb-freeform/);b.innerBlocks.forEach(visit);};visit(root);
  root.attributes.metadata.name='Changed';assert.equal(presets.create(api,item.id).attributes.metadata.name,item.label);
 }
});
test('columns remain native responsive columns and starter search accepts everyday terms',()=>{
 const root=presets.create(api,'features'),columns=root.innerBlocks.find(b=>b.name==='core/columns');
 assert.equal(columns.innerBlocks.length,3);assert.notEqual(columns.attributes.isStackedOnMobile,false);
 assert.equal(presets.matches(presets.items[2],'services'),true);assert.equal(presets.matches(presets.items[2],'video'),false);
 assert.throws(()=>presets.create(api,'unknown'));
});
