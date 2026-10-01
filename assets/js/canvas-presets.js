/* Small starting points built exclusively with native WordPress blocks. */
(function(root, factory) {
	if (typeof module === 'object' && module.exports) module.exports = factory();
	else root.gtPbCanvasPresets = factory();
})(typeof window !== 'undefined' ? window : this, function() {
	'use strict';
	var items = [
		{ id:'blank', label:'Blank section', description:'An empty space for your own layout', icon:'layout', keywords:'empty group container' },
		{ id:'hero', label:'Introduction', description:'Heading, short message, and a button', icon:'typography', keywords:'hero welcome title' },
		{ id:'features', label:'Three columns', description:'Three features that stack on mobile', icon:'layout', keywords:'features services benefits columns' },
		{ id:'cta', label:'Call to action', description:'A focused message and next step', icon:'click', keywords:'cta contact button' },
		{ id:'faq', label:'Questions and answers', description:'Three questions with editable answers', icon:'info-circle', keywords:'faq help questions answers' }
	];
	function create(api, id) {
		var item=items.find(function(p) { return p.id === id; });
		if (!item) throw new Error('Unknown starter section.');
		var b=api.createBlock, heading=function(text,level) { return b('core/heading',{content:text,level:level || 2}); },text=function(copy) { return b('core/paragraph',{content:copy}); };
		var buttons=function(label) { return b('core/buttons',{},[b('core/button',{text:label,url:'#'})]); };
		var children=[];
		if(id==='hero') children=[heading('A clear idea. A great place to start.'),text('Tell your visitors what you do and how you can help. Make the next step easy.'),buttons('Get started')];
		if(id==='cta') children=[heading('Let’s take the next step'),text('Add a short invitation and give your visitors one clear action.'),buttons('Get in touch')];
		if(id==='features') children=[heading('What you can do here'),b('core/columns',{},['One useful feature','Another great reason','One more possibility'].map(function(title) { return b('core/column',{},[heading(title,3),text('Describe the benefit in a few clear sentences.')]); }))];
		if(id==='faq') ['What do I need to get started?','How does it work?','Where can I get help?'].forEach(function(question) { children.push(heading(question,3),text('Add a helpful answer to this question.')); });
		return b('core/group',{tagName:'section',metadata:{name:item.label},layout:{type:'default'},style:{spacing:{padding:'32px',blockGap:'24px'}}},children);
	}
	function matches(item, query) { return !query.trim() || (item.label+' '+item.description+' '+item.keywords).toLowerCase().includes(query.trim().toLowerCase()); }
	return {items:items,create:create,matches:matches};
});
