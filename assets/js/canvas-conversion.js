/* Convert through native WordPress block markup, retaining custom code as code. */
(function(root, factory) {
	if (typeof module === 'object' && module.exports) module.exports = factory();
	else root.gtPbCanvasConversion = factory();
})(typeof window !== 'undefined' ? window : this, function() {
	'use strict';
	var PAGE_BLOCK = 'gt-page-block/page-block';
	var SUPPORTED = ['core/group','core/columns','core/column','core/heading','core/paragraph','core/buttons','core/button','core/image','core/video','core/audio','core/file',PAGE_BLOCK];
	var VOIDS = /^(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$/;
	function fail(message) { throw new Error(message + ' Nothing was converted.'); }
	function requireApi(api, doc) {
		if (!api || !api.createBlock || !api.parse || !api.serialize || !doc || !doc.createElement) fail('WordPress block tools are not ready.');
	}
	function codeGuard(section) {
		if (section.blockId || section.blockSlug || section.kind === 'linked') fail('Detach this linked library section before converting it.');
		if (section.phpExec || /<\?/.test(String(section.content || ''))) fail('PHP sections must stay in Code mode.');
		if (section.visualData) fail('Convert this older prototype with its WordPress block conversion first.');
		if (section.output === 'file') fail('Switch this section to inline output before converting it.');
	}

	// Keep original offsets. An HTML parser alone would silently close containers
	// that intentionally continue into another section, or repair invalid nesting.
	function sourceTree(html) {
		var root = {children:[],start:0,end:html.length}, stack = [root], cursor = 0;
		var tokens = /<!--[\s\S]*?(?:-->|$)|<\?[\s\S]*?(?:\?>|$)|<![^>]*>|<\/?[a-zA-Z][a-zA-Z0-9:-]*(?:\s+(?:"[^"]*"|'[^']*'|[^'">])*)?\s*\/?>/g;
		function appendText(end) {
			if (end > cursor) stack[stack.length - 1].children.push({start:cursor,end:end,children:[]});
		}
		var token;
		while ((token = tokens.exec(html))) {
			appendText(token.index);
			var tagMatch = /^<(\/)?([a-zA-Z][a-zA-Z0-9:-]*)/.exec(token[0]);
			cursor = tokens.lastIndex;
			if (!tagMatch) {
				if (token[0].startsWith('<!--') && !token[0].endsWith('-->')) fail('Close the unfinished HTML comment before converting.');
				stack[stack.length - 1].children.push({start:token.index,end:cursor,children:[]});
				continue;
			}
			var tag = tagMatch[2].toLowerCase();
			if (/^(html|head|body)$/.test(tag)) fail('Convert a section fragment without document-level html, head, or body tags.');
			if (tagMatch[1]) {
				if (stack.length === 1 || stack[stack.length - 1].tag !== tag) fail('This section has unmatched HTML tags or shares a container with another section. Close its containers before converting.');
				var current = stack.pop(); current.closeStart = token.index; current.end = cursor;
				continue;
			}
			var node = {tag:tag,start:token.index,openEnd:cursor,end:cursor,children:[]};
			stack[stack.length - 1].children.push(node);
			if (VOIDS.test(tag)) continue;
			if (/\/\s*>$/.test(token[0])) fail('Use explicit closing tags for non-void HTML elements before converting.');
			if (/^(script|style|textarea|title)$/.test(tag)) {
				var closing = new RegExp('</' + tag + '\\s*>','ig'); closing.lastIndex = cursor;
				var end = closing.exec(html);
				if (!end) fail('Close the unfinished ' + tag + ' element before converting.');
				node.closeStart = end.index; node.end = closing.lastIndex;
				tokens.lastIndex = cursor = node.end;
			} else {
				stack.push(node);
				if (stack.length > 64) fail('This section is too deeply nested to convert safely.');
			}
		}
		appendText(html.length);
		if (stack.length !== 1) fail('This section has an open container that may continue into another section. Close it before converting.');
		return root;
	}
	function nativeBlocks(api, serialized) {
		if (!String(serialized).trim()) fail('Select a section containing WordPress blocks.');
		if (/<\?/.test(serialized)) fail('PHP sections must stay in Code mode.');
		var stack = [], count = 0, hasBlock = false;
		sourceTree(serialized);
		// The native parser can recover incomplete comments. Conversion cannot.
		String(serialized).replace(/<!--[\s\S]*?-->/g,function(comment) {
			if (!/^<!--\s*\/?wp:/.test(comment)) return comment;
			var match = /^<!--\s*(\/)?wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)(?:\s+([\s\S]*?))?\s*-->$/.exec(comment);
			if (!match) fail('Repair the WordPress block comments before converting.');
			hasBlock = true;
			var name = match[2], tail = String(match[3] || '').trim(), selfClosing = /\/$/.test(tail);
			if (match[1]) {
				if (tail || stack.pop() !== name) fail('Repair the unmatched WordPress block comments before converting.');
			} else {
				if (selfClosing) tail = tail.slice(0,-1).trim();
				if (tail) { try { JSON.parse(tail); } catch (error) { fail('Repair the JSON in the WordPress block comments before converting.'); } }
				if (!selfClosing) stack.push(name);
			}
			return comment;
		});
		if (!hasBlock || stack.length) fail('Repair the incomplete WordPress block markup before converting.');
		var blocks;
		try { blocks = api.parse(serialized); } catch (error) { fail('WordPress could not read this block markup.'); }
		if (!Array.isArray(blocks) || !blocks.length) fail('WordPress could not read this block markup.');
		function check(block, depth) {
			if (++count > 2000 || depth > 64) fail('This section is too large or deeply nested to convert safely.');
			if (!block || block.isValid === false || !SUPPORTED.includes(block.name)) fail('This section contains an unsupported, dynamic, or invalid WordPress block. Keep it in its current mode.');
			var attrs = block.attributes || {};
			if (attrs.metadata && attrs.metadata.bindings && Object.keys(attrs.metadata.bindings).length) fail('Blocks with dynamic content bindings must stay in their current mode.');
			if (['content','text','caption'].some(function(key) {
				var value = String(attrs[key] || '').replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1\s*>/gi,'');
				return /\[\/?[a-zA-Z][\w-]*(?:\s[^\]]*)?\s*\/?\]/.test(value);
			})) fail('Sections containing shortcode-like text must stay in their current mode.');
			if (block.name === PAGE_BLOCK) {
				codeGuard(attrs);
				if (attrs.nativeContent || (block.innerBlocks || []).length) fail('Nested converted code blocks cannot be converted again.');
			}
			(block.innerBlocks || []).forEach(function(child) { check(child,depth + 1); });
		}
		blocks.forEach(function(block) { check(block,0); });
		return blocks;
	}
	function visualToCode(api, serialized, doc) {
		requireApi(api,doc); nativeBlocks(api,serialized);
		// Keep delimiters and attributes: do_blocks() can still supply native
		// layout support and render the existing CSS/JS helper at its position.
		return {content:serialized,css:'',js:'',jsLocation:'footer',format:false,nativeContent:true};
	}
	function safeURL(value, media) {
		try { return (media ? ['http:','https:'] : ['http:','https:','mailto:','tel:']).includes(new URL(value || '', 'https://page-blocks.invalid/').protocol); } catch (error) { return false; }
	}
	function onlyAttrs(element, allowed) { return Array.from(element.attributes).every(function(attr) { return allowed.includes(attr.name); }); }
	function commonAttrs(element) {
		var attrs = {};
		if (element.hasAttribute('id')) attrs.anchor = element.getAttribute('id');
		if (element.hasAttribute('class')) attrs.className = element.getAttribute('class');
		return attrs;
	}
	function richText(element) {
		return Array.from(element.querySelectorAll('*')).every(function(child) {
			return /^(a|strong|em|b|i|u|s|sub|sup|span|br|code|mark)$/.test(child.localName) &&
				Array.from(child.attributes).every(function(attr) { return !/^on/i.test(attr.name) && attr.name !== 'srcdoc'; }) &&
				(!child.hasAttribute('href') || safeURL(child.getAttribute('href'),false));
		});
	}
	function helpers(api, section) {
		var result = {before:[],after:[]};
		if (section.css) result.before.push(api.createBlock(PAGE_BLOCK,{name:'Imported section styles',css:section.css,cssOutput:section.cssOutput || 'inline',cssDefer:!!section.cssDefer}));
		if (section.js) result.after.push(api.createBlock(PAGE_BLOCK,{name:'Imported section script',js:section.js,jsLocation:section.jsLocation === 'inline' ? 'inline' : 'footer'}));
		return result;
	}
	function codeToVisual(api, section, doc) {
		requireApi(api,doc); section = section || {}; codeGuard(section);
		var html = String(section.content || ''), extra = helpers(api,section);
		if (section.nativeContent) {
			if (section.format) fail('Turn off WordPress formatting before restoring the native blocks.');
			var original = nativeBlocks(api,html);
			if (!extra.before.length && !extra.after.length) return html;
			if (original.length !== 1 || original[0].name !== 'core/group') fail('Move this section’s added CSS and JavaScript into its original native Group before restoring Visual mode.');
			original[0].innerBlocks = extra.before.concat(original[0].innerBlocks || [],extra.after);
			return api.serialize(original);
		}
		if (section.format) fail('Turn off WordPress formatting before converting this section.');
		if (/<!--\s*\/?wp:/.test(html)) fail('This code contains WordPress block comments without native block rendering enabled. Keep its current rendering or use a section created with Convert to code.');
		var tree = sourceTree(html), converted = 0;
		function code(node) { return api.createBlock(PAGE_BLOCK,{name:'Custom HTML',content:html.slice(node.start,node.end),format:false}); }
		function probe(node) {
			var template = doc.createElement('template'); template.innerHTML = html.slice(node.start,node.end);
			return template.content.childElementCount === 1 ? template.content.firstElementChild : null;
		}
		function convert(node) {
			if (!node.tag) return /^\s*$/.test(html.slice(node.start,node.end)) ? null : code(node);
			var el = probe(node), attrs, result;
			if (!el || el.localName !== node.tag) return code(node);
			if (/^(h[1-6]|p)$/.test(node.tag) && onlyAttrs(el,['id','class']) && richText(el)) {
				attrs = commonAttrs(el); attrs.content = html.slice(node.openEnd,node.closeStart);
				if (node.tag !== 'p') attrs.level = Number(node.tag.slice(1));
				result = api.createBlock(node.tag === 'p' ? 'core/paragraph' : 'core/heading',attrs);
			} else if (node.tag === 'img' && onlyAttrs(el,['src','alt','title']) && el.getAttribute('src') && safeURL(el.getAttribute('src'),true)) {
				attrs = {url:el.getAttribute('src'),alt:el.getAttribute('alt') || ''};
				if (el.hasAttribute('title')) attrs.title = el.getAttribute('title');
				result = api.createBlock('core/image',attrs);
			} else if (/^(a|button)$/.test(node.tag) && onlyAttrs(el,node.tag === 'a' ? ['href','target','rel','title'] : ['type','title']) && richText(el) && (node.tag !== 'a' || safeURL(el.getAttribute('href'),false))) {
				attrs = {text:html.slice(node.openEnd,node.closeStart)};
				if (node.tag === 'a') {
					attrs.url = el.getAttribute('href') || '';
					if (el.hasAttribute('target')) attrs.linkTarget = el.getAttribute('target');
					if (el.hasAttribute('rel')) attrs.rel = el.getAttribute('rel');
				} else { attrs.tagName = 'button'; attrs.type = el.getAttribute('type') || 'submit'; }
				if (el.hasAttribute('title')) attrs.title = el.getAttribute('title');
				result = api.createBlock('core/buttons',{},[api.createBlock('core/button',attrs)]);
			} else if (/^(div|section|main|article|aside|header|footer)$/.test(node.tag) && onlyAttrs(el,['id','class'])) {
				// Refuse browser repairs (e.g. a block nested inside a paragraph)
				// by comparing lexical direct elements with the parsed fragment.
				var tags = node.children.filter(function(child) { return child.tag; }).map(function(child) { return child.tag; });
				if (tags.join(',') !== Array.from(el.children).map(function(child) { return child.localName; }).join(',')) return code(node);
				attrs = commonAttrs(el); attrs.tagName = node.tag; attrs.layout = {type:'default'};
				result = api.createBlock('core/group',attrs,node.children.map(convert).filter(Boolean));
			}
			if (result) { converted++; return result; }
			return code(node);
		}
		var children = tree.children.map(convert).filter(Boolean);
		if (!converted) fail('This section uses custom markup that cannot become editable native blocks safely. Its code remains editable in Code mode.');
		var group;
		if (children.length === 1 && children[0].name === 'core/group') group = children[0];
		else group = api.createBlock('core/group',{tagName:'section',layout:{type:'default'}},children);
		group.attributes.metadata = Object.assign({},group.attributes.metadata,{name:section.label || section.name || 'Converted section'});
		group.innerBlocks = extra.before.concat(group.innerBlocks || [],extra.after);
		return api.serialize([group]);
	}
	return {visualToCode:visualToCode,codeToVisual:codeToVisual};
});
