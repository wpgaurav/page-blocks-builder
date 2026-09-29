/* One-way compatibility transform for the unreleased visualData prototype. */
(function(root, factory) {
	if (typeof module === 'object' && module.exports) module.exports = factory();
	else root.gtPbPrototypeConversion = factory();
})(typeof window !== 'undefined' ? window : this, function() {
	'use strict';
	function escape(text) { return String(text || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
	function convert(attributes, blocks) {
		var data = attributes.visualData;
		if (!data || data.version !== 1 || !data.root || data.root.type !== 'section') throw new Error('This prototype section cannot be converted. Its saved code is still available.');
		var ids = new Set(), count = 0, buttonIds = [];
		function nodeToBlock(node, depth) {
			if (!node || typeof node.id !== 'string' || !/^v[a-z0-9]{6,19}$/.test(node.id) || ids.has(node.id) || depth > 8 || ++count > 120) throw new Error('The prototype has an invalid element. Nothing was converted.');
			ids.add(node.id);
			var props = node.props || {}, style = node.styles && node.styles.desktop || {};
			var attrs = { className: 'gt-pb-v-' + node.id + ' gt-pb-v-type-' + node.type };
			var nativeStyle = {};
			if (style.color || style.backgroundColor) nativeStyle.color = { text: style.color, background: style.backgroundColor };
			if (style.fontSize || style.fontWeight) nativeStyle.typography = { fontSize: style.fontSize, fontWeight: style.fontWeight };
			if (style.padding || style.margin || style.gap) nativeStyle.spacing = { padding: style.padding, margin: style.margin, blockGap: style.gap };
			if (style.borderRadius) nativeStyle.border = { radius: style.borderRadius };
			if (style.minHeight) nativeStyle.dimensions = { minHeight: style.minHeight };
			if (Object.keys(nativeStyle).length) attrs.style = nativeStyle;
			var children = node.children || [];
			if (!Array.isArray(children)) throw new Error('The prototype has invalid child elements. Nothing was converted.');
			if (['section','container','columns'].indexOf(node.type) < 0 && children.length) throw new Error('The prototype has unsupported nested content. Nothing was converted.');
			switch (node.type) {
				case 'section':
					if (depth) throw new Error('Nested prototype sections cannot be converted.');
					attrs.tagName = 'section'; attrs.anchor = 'gt-pb-v-' + node.id;
					attrs.className += ' gt-pb-v-root-' + node.id;
					attrs.metadata = { name: attributes.name || 'Section' };
					attrs.layout = { type: 'default' };
					return blocks.createBlock('core/group', attrs, children.map(function(child) { return nodeToBlock(child, depth + 1); }));
				case 'container':
					attrs.layout = { type: 'default' };
					return blocks.createBlock('core/group', attrs, children.map(function(child) { return nodeToBlock(child, depth + 1); }));
				case 'columns':
					return blocks.createBlock('core/columns', attrs, children.map(function(child) { return blocks.createBlock('core/column', {}, [nodeToBlock(child, depth + 1)]); }));
				case 'heading':
					attrs.content = escape(props.text); attrs.level = Math.min(6, Math.max(1, Number(props.level) || 2));
					if (style.textAlign) attrs.textAlign = style.textAlign;
					return blocks.createBlock('core/heading', attrs);
				case 'text':
					attrs.content = escape(props.text).replace(/\n/g, '<br>');
					if (style.textAlign) attrs.align = style.textAlign;
					return blocks.createBlock('core/paragraph', attrs);
				case 'image':
					attrs.id = Number(props.attachmentId) || undefined; attrs.url = props.url || ''; attrs.alt = props.alt || '';
					return blocks.createBlock('core/image', attrs);
				case 'button':
					buttonIds.push(node.id); attrs.text = escape(props.text); attrs.url = props.url || '#';
					return blocks.createBlock('core/buttons', {}, [blocks.createBlock('core/button', attrs)]);
				default: throw new Error('This prototype contains an unsupported element. Nothing was converted.');
			}
		}
		var result = nodeToBlock(data.root, 0);
		if (attributes.css) {
			var css = attributes.css;
			buttonIds.forEach(function(id) { css = css.replace(new RegExp('\\.gt-pb-v-' + id + '(?=\\s*\\{)', 'g'), '.gt-pb-v-' + id + ' > .wp-block-button__link'); });
			result.innerBlocks.unshift(blocks.createBlock('gt-page-block/page-block', { name: 'Imported section styles', css: css, cssOutput: attributes.cssOutput || 'inline', cssDefer: !!attributes.cssDefer }));
		}
		return [result];
	}
	return { convert: convert };
});
