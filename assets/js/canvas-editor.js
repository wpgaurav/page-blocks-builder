/* Native WordPress blocks are the document. Canvas controls edit that document. */
(function(root, factory) {
	if (typeof module === 'object' && module.exports) module.exports = factory();
	else root.gtPbCanvasEditor = factory();
})(typeof window !== 'undefined' ? window : this, function() {
	'use strict';
	var SUPPORTED = ['core/group','core/columns','core/column','core/heading','core/paragraph','core/buttons','core/button','core/image','core/video','core/audio','core/file'];
	var LABELS = { 'core/group':'Section', 'core/columns':'Columns', 'core/column':'Column', 'core/heading':'Heading', 'core/paragraph':'Text', 'core/buttons':'Buttons', 'core/button':'Button', 'core/image':'Image','core/video':'Video','core/audio':'Audio','core/file':'File' };
	function token(prefix) { return prefix + Math.random().toString(36).slice(2, 12); }
	function copy(value) { return JSON.parse(JSON.stringify(value)); }
	function escape(value) { return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
	function pathArray(path) { return typeof path === 'string' && /^\d+(?:\.\d+)*$/.test(path) ? path.split('.').map(Number) : null; }
	function safeURL(value, image) {
		if (!value) return true;
		try { return (image ? ['http:','https:'] : ['http:','https:','mailto:','tel:']).includes(new URL(value,'https://page-blocks.invalid/').protocol); } catch (error) { return false; }
	}
	function locate(blocks, path) {
		var parts = pathArray(path); if (!parts) return null;
		var list = blocks, found = null;
		for (var i = 0; i < parts.length; i++) {
			if (!Array.isArray(list) || !list[parts[i]]) return null;
			found = { block: list[parts[i]], list: list, index: parts[i], parent: found ? found.block : null };
			list = found.block.innerBlocks;
		}
		return found;
	}
	function helper(root, api) {
		var item = root.innerBlocks.find(function(b) { return b.name === 'gt-page-block/page-block' && b.attributes.name === 'Page Blocks canvas styles'; });
		if (!item) { item = api.createBlock('gt-page-block/page-block', { name: 'Page Blocks canvas styles', cssOutput: 'inline' }); root.innerBlocks.unshift(item); }
		return item;
	}
	function nodeKey(block) {
		var classes = String(block.attributes.className || '').split(/\s+/), key = classes.find(function(c) { return /^pb-node-[a-z0-9]+$/.test(c); });
		if (!key) { key = token('pb-node-'); block.attributes.className = classes.filter(Boolean).concat(key).join(' '); }
		return key;
	}
	function layoutKey(block) {
		var classes = String(block.attributes.className || '').split(/\s+/), key = classes.find(function(c) { return /^pb-layout-[a-z0-9]+$/.test(c); });
		if (!key) key = token('pb-layout-');
		block.attributes.className = classes.filter(function(c) { return c && c !== 'pb-freeform' && !/^pb-layout-/.test(c); }).concat('pb-freeform',key).join(' ');
		return key;
	}
	function cloneNative(block, api, replacements) {
		replacements = replacements || {};
		function clone(b) {
			var attrs = copy(b.attributes); delete attrs.anchor;
			attrs.className = String(attrs.className || '').replace(/\bpb-(?:node|layout)-[a-z0-9]+\b/g,function(key) { return replacements[key] || (replacements[key] = token(key.startsWith('pb-node-') ? 'pb-node-' : 'pb-layout-')); });
			return api.cloneBlock(b,attrs,(b.innerBlocks || []).map(clone));
		}
		var result = clone(block);
		function rewrite(b) { if (b.name === 'gt-page-block/page-block' && typeof b.attributes.css === 'string') Object.keys(replacements).forEach(function(key) { b.attributes.css = b.attributes.css.replace(new RegExp('\\b' + key + '\\b','g'),replacements[key]); }); (b.innerBlocks || []).forEach(rewrite); }
		rewrite(result); return result;
	}
	function duplicateWithin(block, blocks, api) {
		var keys = {}, owned = new Set(), duplicate = cloneNative(block,api,keys);
		function collect(b) { owned.add(b.clientId); (b.innerBlocks || []).forEach(collect); }
		collect(block);
		function append(b) {
			if (!owned.has(b.clientId) && b.name === 'gt-page-block/page-block' && typeof b.attributes.css === 'string') {
				var snippets = b.attributes.css.match(/\/\* pb-visual:[a-z0-9-]+:start \*\/[\s\S]*?\/\* pb-visual:[a-z0-9-]+:end \*\//g) || [];
				snippets.forEach(function(snippet) { if (!Object.keys(keys).some(function(key) { return snippet.startsWith('/* pb-visual:' + key + '-'); })) return; Object.keys(keys).forEach(function(key) { snippet = snippet.replace(new RegExp('\\b' + key + '\\b','g'),keys[key]); }); b.attributes.css += '\n' + snippet; });
			}
			(b.innerBlocks || []).forEach(append);
		}
		blocks.forEach(append); return duplicate;
	}
	function ruleValue(css, key, property, viewport) {
		if (!key) return '';
		var start = '/* pb-visual:' + key + '-' + viewport + '-' + property + ':start */', a = css.indexOf(start);
		if (a < 0) return '';
		var b = css.indexOf(':end */',a), match = css.slice(a,b).match(new RegExp('\\{' + property + ':([^}]+)\\}'));
		return match ? match[1] : '';
	}
	function editPlainText(html, value, doc) {
		var holder = doc.createElement('div'); holder.innerHTML = html;
		var before = holder.textContent, prefix = 0, suffix = 0;
		if (before === value) return html;
		while (prefix < before.length && prefix < value.length && before[prefix] === value[prefix]) prefix++;
		while (suffix < before.length - prefix && suffix < value.length - prefix && before[before.length - suffix - 1] === value[value.length - suffix - 1]) suffix++;
		var walker = doc.createTreeWalker(holder,4), nodes = [], node, offset = 0;
		while ((node = walker.nextNode())) { nodes.push({node:node,start:offset,end:offset + node.textContent.length}); offset += node.textContent.length; }
		if (!nodes.length || holder.querySelector('br')) return escape(value).replace(/\n/g,'<br>');
		function point(at) { var entry = nodes.find(function(n) { return n.end >= at; }) || nodes[nodes.length - 1]; return [entry.node,Math.max(0,at - entry.start)]; }
		var range = doc.createRange(), start = point(prefix), end = point(before.length - suffix);
		range.setStart(start[0],start[1]); range.setEnd(end[0],end[1]); range.deleteContents();
		var insert = value.slice(prefix,value.length - suffix).split('\n'), fragment = doc.createDocumentFragment();
		insert.forEach(function(text,i) { if (i) fragment.appendChild(doc.createElement('br')); fragment.appendChild(doc.createTextNode(text)); }); range.insertNode(fragment);
		return holder.innerHTML;
	}
	function replaceLayoutCss(old, compiled) {
		var marker = '/* Page Blocks canvas layout */', endMarker = '/* Page Blocks canvas layout end */';
		var a = old.lastIndexOf(marker), b = a < 0 ? -1 : old.indexOf(endMarker,a);
		return a >= 0 && b >= 0 ? old.slice(0,a) + compiled + old.slice(b + endMarker.length) : old + '\n' + compiled;
	}
	function autoPlacement(block) {
		var scope=(String(block.attributes.className || '').match(/\bpb-layout-[a-z0-9]+\b/) || [])[0];
		block.attributes.className=String(block.attributes.className || '').split(/\s+/).filter(function(c) { return c && c !== 'pb-freeform' && !/^pb-layout-/.test(c); }).join(' ');
		if (scope) (block.innerBlocks || []).forEach(function(b) { if (b.name !== 'gt-page-block/page-block' || typeof b.attributes.css !== 'string') return; b.attributes.css=b.attributes.css.replace(/\/\* Page Blocks canvas layout \*\/[\s\S]*?\/\* Page Blocks canvas layout end \*\//g,function(part) { return part.includes('.pb-freeform.' + scope + '{') ? '' : part; }); });
		block.innerBlocks=block.innerBlocks.filter(function(b) { return !(b.name === 'gt-page-block/page-block' && b.attributes.name === 'Page Blocks canvas styles' && !String(b.attributes.css || '').trim() && !b.attributes.content && !b.attributes.js && !b.attributes.blockId); });
	}
	function styleValue(property, value) {
		if (!value) return true;
		if (value.length > 100 || /[;{}<>\\]/.test(value)) return false;
		if (property === 'color' || property === 'background-color') return /^(#[a-f0-9]{3,8}|var\(--[a-z0-9_-]+\)|rgba?\([0-9.,%\s]+\)|transparent|currentColor)$/i.test(value);
		if (property === 'text-align') return /^(left|center|right|start|end)$/.test(value);
		return /^(auto|0|var\(--[a-z0-9_-]+\)|-?\d+(?:\.\d+)?(?:px|rem|em|%|vw|vh)(?:\s+(?:0|-?\d+(?:\.\d+)?(?:px|rem|em|%|vw|vh))){0,3})$/i.test(value);
	}
	function setRule(css, key, selector, property, value, viewport) {
		if (!/^pb-node-[a-z0-9]+$/.test(key) || !/^(color|background-color|font-size|padding|margin|max-width|border-radius|text-align)$/.test(property) || !styleValue(property, value)) return null;
		var marker = key + '-' + viewport + '-' + property;
		var start = '/* pb-visual:' + marker + ':start */', end = '/* pb-visual:' + marker + ':end */';
		var a = css.indexOf(start), b = a < 0 ? -1 : css.indexOf(end, a);
		if (a >= 0 && b >= 0) css = css.slice(0, a) + css.slice(b + end.length);
		if (!value) return css;
		var rule = selector + '{' + property + ':' + value + '}';
		if (viewport !== 'desktop') rule = '@media(max-width:' + (viewport === 'tablet' ? '768' : '480') + 'px){' + rule + '}';
		return css + '\n' + start + '\n' + rule + '\n' + end;
	}
	function createSection(api, layout, kind, placement) {
		var root = api.createBlock('core/group', { tagName:'section', metadata:{name:kind === 'cta' ? 'Call to action' : 'Visual section'}, layout:{type:'default'},style:{spacing:{padding:'48px',blockGap:'24px'}} });
		var children = [api.createBlock('core/heading', { content:kind === 'cta' ? 'Ready for the next step?' : 'Build something worth sharing', level:2 }), api.createBlock('core/paragraph', { content:'Start with your message. Select anything on the canvas to edit it.' }), api.createBlock('core/buttons', {}, [api.createBlock('core/button', { text:'Get started', url:'#' })])];
		root.innerBlocks.push(...children);
		if (placement === 'freeform') { var scope=layoutKey(root),styleBlock=helper(root,api),positions=[{x:64,y:64,w:800,h:96},{x:64,y:184,w:620,h:80},{x:64,y:304,w:240,h:56}]; styleBlock.attributes.css=layout.css(scope,children.map(function(b,i) { return Object.assign({key:nodeKey(b),type:b.name},positions[i]); }),1200,460); }
		return api.serialize([root]);
	}
	function mount(options) {
		var doc = options.container.ownerDocument, win = doc.defaultView, wp = options.wp || win.wp, api = wp && wp.blocks;
		if (!api) return null;
		if (!api.getBlockType('core/group') && wp.blockLibrary) wp.blockLibrary.registerCoreBlocks();
		if (!api.getBlockType('gt-page-block/page-block')) api.registerBlockType('gt-page-block/page-block', { apiVersion:3, title:'Page Block', category:'design', attributes:options.config.pageBlockAttributes, save:function() { return null; } });
		var caches = new Map(), snapshots = new Map(), selection = null, pendingMeasure = null, viewport = 'desktop', grid = false, noticeTimer = null, activeSectionUid = null, mobilePreview = null, previewReady = false, uploading = false;
		var panel = doc.createElement('div'); panel.className = 'pb-canvas-inspector'; options.container.appendChild(panel);
		var rail = doc.createElement('div'); rail.className = 'pb-canvas-rail'; rail.setAttribute('aria-label','Canvas tools'); (options.canvas.querySelector('.md-pb-canvas-toolbar') || options.canvas).appendChild(rail);
		var widthNote=doc.createElement('span'); widthNote.className='pb-canvas-size'; widthNote.title='Actual canvas width. Freeform arrangement needs more than 768px.'; var viewportControls=options.canvas.querySelector('.md-pb-viewport-controls'); if (viewportControls) viewportControls.prepend(widthNote);
		var status = doc.createElement('div'); status.className = 'pb-canvas-notice'; status.setAttribute('role','status'); status.hidden = true; options.canvas.appendChild(status);
		var palette = doc.createElement('div'); palette.className = 'pb-canvas-palette'; palette.hidden = true; options.canvas.appendChild(palette);
		function notify(text, persistent) { if (noticeTimer) win.clearTimeout(noticeTimer); status.replaceChildren(); var copy = doc.createElement('span'); copy.textContent = text; status.append(copy,button('Dismiss notice','x',function() { status.hidden = true; })); status.hidden = false; if (!persistent) noticeTimer = win.setTimeout(function() { status.hidden = true; },6000); }
		function showPalette(open) { palette.hidden = !open; addButton.setAttribute('aria-expanded',String(open)); if (open) { var header=options.canvas.querySelector('.md-pb-canvas-toolbar'), top=(header ? header.offsetHeight : 44) + 6; palette.style.top=top + 'px'; if (options.canvas.clientHeight) palette.style.maxHeight=Math.max(64,options.canvas.clientHeight - top - 12) + 'px'; updatePalette(); palette.querySelector('button').focus(); } }
		function frameWidth() { try { return options.getFrame().contentWindow.innerWidth; } catch(error) { return 0; } }
		function button(label, icon, action, className) {
			var b = doc.createElement('button'); b.type = 'button'; b.className = className || 'pb-canvas-button'; b.setAttribute('aria-label',label); b.title = label;
			b.innerHTML = options.config.icons && options.config.icons[icon] || ''; var t = doc.createElement('span'); t.textContent = label; b.appendChild(t); b.addEventListener('click',action); return b;
		}
		function frameMessage(message) { var f = options.getFrame(); if (f && f.contentWindow) f.contentWindow.postMessage(message, win.location.origin); }
		function section(uid) { return options.getSections().find(function(s) { return s.uid === uid; }); }
		function tree(s) {
			if (!s || s.kind !== 'foreign') return null;
			var cache = caches.get(s.uid);
			if (cache && cache.source === s.serialized) return cache.blocks;
			try {
				var blocks = api.parse(s.serialized);
				function valid(bs) { return bs.every(function(b) { return b.isValid !== false && valid(b.innerBlocks || []); }); }
				if (!valid(blocks)) return null;
				caches.set(s.uid,{source:s.serialized,blocks:blocks}); return blocks;
			} catch (error) { return null; }
		}
		function commit(s, blocks, checkpoint, rebuild) {
			var serialized = api.serialize(blocks); caches.set(s.uid,{source:serialized,blocks:blocks});
			options.onChange(s.uid,{serialized:serialized},checkpoint !== false);
			if (rebuild !== false) render();
		}
		function fresh(uid) {
			var s = section(uid), before = snapshots.get(uid);
			if (s && before && before === (s.kind === 'foreign' ? s.serialized : s.content)) return true;
			notify('The preview changed while you were editing. Select the element again in the refreshed canvas.'); return false;
		}
		function current() {
			if (!selection) return null;
			var s = section(selection.sectionUid); if (!s) return null;
			if (selection.nativePath) {
				var blocks = tree(s), found = blocks && locate(blocks,selection.nativePath);
				return options.config.canEditNativeBlocks !== false && found && SUPPORTED.includes(found.block.name) ? { section:s,blocks:blocks,found:found,block:found.block } : null;
			}
			if (s.kind === 'foreign' || s.blockId || s.phpExec || s.format || s.visualData) return null;
			var source = win.gtPbPreviewDom.sourceElement(s.content, selection.sourcePath || []);
			return source && source.tag === selection.tagName ? {section:s,source:source} : null;
		}
		function content(s, text, html) {
			var old = s.block ? s.block.attributes[s.block.name === 'core/button' ? 'text' : 'content'] || '' : selection.html || '';
			if (html === undefined) html = editPlainText(old,text,doc);
			if (old === html) return;
			if (s.block) {
				if (!['core/heading','core/paragraph','core/button'].includes(s.block.name)) return;
				s.block.attributes[s.block.name === 'core/button' ? 'text' : 'content'] = html; commit(s.section,s.blocks,true,false);
			} else {
				var result = win.gtPbPreviewDom.replaceInnerHtml(s.section.content,{path:selection.sourcePath,tagName:selection.tagName,oldHtml:selection.html || '',newHtml:html},doc);
				if (result === null) { notify('This rendered text does not match its source. Use the HTML editor for this element.'); return; }
				options.onChange(s.section.uid,{content:result},true); selection.html = html;
			}
		}
		function sourceAttribute(s, name, value) {
			if ((name === 'src' || name === 'href') && !safeURL(value,name === 'src')) { notify('Use a valid web URL, relative path, or page anchor.'); return false; }
			var old = (selection.attributes || {})[name] || '';
			var result = win.gtPbPreviewDom.replaceAttribute(s.section.content,{path:selection.sourcePath,tagName:selection.tagName,oldValue:old},name,value,doc);
			if (result === null) { notify('This element no longer matches its source. Select it again or use the code editor.'); return; }
			if (name === 'src') ['srcset','sizes'].forEach(function(attribute) { result = win.gtPbPreviewDom.replaceAttribute(result,{path:selection.sourcePath,tagName:selection.tagName},attribute,'',doc); });
			selection.attributes[name] = value; options.onChange(s.section.uid,{content:result},true);
		}
		function setStyle(s, property, value) {
			if (!['color','background-color','text-align'].includes(property) && /^-?\d+(?:\.\d+)?$/.test(value) && Number(value) !== 0) value += 'px';
			if (!styleValue(property,value)) { notify('Use a CSS length such as 24px or 1rem, a hex color, or a theme variable.'); return false; }
			var support = s.block && api.getBlockType(s.block.name).supports || {};
			var border = support.border || support.__experimentalBorder || {};
			var nativeSupport = property === 'color' ? support.color && support.color.text !== false : property === 'background-color' ? support.color && support.color.background !== false : property === 'font-size' ? support.typography && support.typography.fontSize : property === 'padding' ? support.spacing && support.spacing.padding : property === 'margin' ? support.spacing && support.spacing.margin : property === 'border-radius' ? border.radius : property === 'text-align';
			if (s.block && viewport === 'desktop' && nativeSupport) {
				if (property === 'font-size' && value) delete s.block.attributes.fontSize;
				var map = {'color':['color','text'],'background-color':['color','background'],'font-size':['typography','fontSize'],'padding':['spacing','padding'],'margin':['spacing','margin'],'border-radius':['border','radius']};
				if (property === 'text-align') { s.block.attributes[s.block.name === 'core/heading' ? 'textAlign' : 'align'] = value || undefined; }
				else if (map[property]) { var spec = map[property]; s.block.attributes.style = copy(s.block.attributes.style || {}); s.block.attributes.style[spec[0]] = s.block.attributes.style[spec[0]] || {}; if (value) s.block.attributes.style[spec[0]][spec[1]] = value; else delete s.block.attributes.style[spec[0]][spec[1]]; }
				commit(s.section,s.blocks,true,false); return true;
			}
			if (s.block) {
				var root = s.blocks[0]; if (root.name !== 'core/group') { notify('Responsive overrides are available inside native Group sections.'); return false; }
				var key = nodeKey(s.block), styleBlock = helper(root,api), rule = setRule(styleBlock.attributes.css || '',key,'.' + key + '.' + key,property,value,viewport);
				styleBlock.attributes.css = rule; selection.nativePath = findPath(s.blocks,s.block.clientId); commit(s.section,s.blocks,true,false);
			} else {
				var probe = doc.createElement('template'); probe.innerHTML = s.source.openTag; var element = probe.content.firstElementChild;
				var classes = element ? element.getAttribute('class') || '' : '', key = classes.split(/\s+/).find(function(c) { return /^pb-node-[a-z0-9]+$/.test(c); }) || token('pb-node-');
				var updated = win.gtPbPreviewDom.replaceAttribute(s.section.content,{path:selection.sourcePath,tagName:selection.tagName,oldValue:classes},'class',classes.split(/\s+/).filter(Boolean).filter(function(c) { return c !== key; }).concat(key).join(' '),doc);
				if (updated === null) return false;
				var parsed = new win.DOMParser().parseFromString(updated,'text/html'), rootId = parsed.body.firstElementChild && parsed.body.firstElementChild.id;
				var selector = '.' + key + '.' + key;
				if (rootId && /^[a-zA-Z][a-zA-Z0-9_-]*$/.test(rootId)) selector = '#' + rootId + (selection.sourcePath.length === 1 ? '.' : ' .') + key;
				var css = setRule(s.section.css || '',key,selector,property,value,viewport);
				options.onChange(s.section.uid,{content:updated,css:css},true);
			}
			return true;
		}
		function findPath(blocks, id, prefix) {
			for (var i = 0; i < blocks.length; i++) { var path = prefix ? prefix + '.' + i : String(i); if (blocks[i].clientId === id) return path; var inner = findPath(blocks[i].innerBlocks || [],id,path); if (inner) return inner; }
			return null;
		}
		function requestLayout(s, rootPath, action) { pendingMeasure = {sectionUid:s.section.uid,action:action || 'activate',selectedPath:selection.nativePath}; frameMessage({type:'pb_canvas_measure',sectionUid:s.section.uid,path:rootPath}); }
		function layoutCommit(message, pending) {
			if (options.config.canEditNativeBlocks === false) return;
			var s = section(message.sectionUid), blocks = s && tree(s), found = blocks && locate(blocks,message.rootPath);
			if (!found || found.block.name !== 'core/group' || !fresh(s.uid) || !Number.isFinite(message.width) || !Number.isFinite(message.height) || message.width <= 0) return;
			if ((message.viewportWidth || frameWidth() || message.width) <= 768) { notify('Use a desktop preview to arrange or add elements in a freeform section. Text and style editing work in every preview.'); return; }
			var group = found.block, selected = selection && locate(blocks,selection.nativePath || ''), selectedId = selected && selected.block.clientId;
			var actual = group.innerBlocks.filter(function(b) { return !(b.name === 'gt-page-block/page-block' && !b.attributes.content && !b.attributes.js && !b.attributes.blockId && b.attributes.css); });
			if (!actual.length && pending && pending.action === 'activate') { var scope=layoutKey(group); helper(group,api).attributes.css='/* Page Blocks canvas layout */\n.pb-freeform.' + scope + '{position:relative;min-height:240px}\n/* Page Blocks canvas layout end */'; commit(s,blocks,true,true); notify('Freeform placement enabled.'); return; }
			if ((!message.elements.length && !(pending && (pending.block || pending.blocks))) || actual.length !== message.elements.length || actual.some(function(b) { return !SUPPORTED.includes(b.name); })) { notify('This section contains blocks the canvas cannot arrange. Its content stays intact; use Code or the WordPress editor for those blocks.'); return; }
			var geometry = [];
			for (var i = 0; i < message.elements.length; i++) {
				var e = copy(message.elements[i]), target = locate(blocks,e.path);
				if (!target || target.parent !== group || ![e.x,e.y,e.w,e.h].every(Number.isFinite)) return;
				e.key = nodeKey(target.block); e.type = target.block.name; e.x = Math.max(0,e.x); e.y = Math.max(0,e.y); e.w = Math.max(40,e.w); e.h = Math.max(24,e.h); geometry.push(e);
			}
			if (pending && pending.block) {
				group.innerBlocks.push(pending.block); selectedId = pending.block.clientId;
				geometry.push({key:nodeKey(pending.block),type:pending.block.name,x:24,y:Math.max(0,...geometry.map(function(e) { return e.y + e.h; })) + 24,w:Math.min(540,message.width - 48),h:pending.block.name === 'core/image' ? 240 : 80});
			}
			if (pending && pending.blocks) { var y=pending.point ? pending.point.y : Math.max(0,...geometry.map(function(e) { return e.y + e.h; })) + 24;
				pending.blocks.forEach(function(block) { group.innerBlocks.push(block); selectedId=block.clientId; var w=Math.min(480,message.width - 48),x=Math.min(Math.max(0,pending.point && pending.point.x || 24),Math.max(0,message.width - w)); geometry.push({key:nodeKey(block),type:block.name,x:x,y:y,w:w,h:block.name === 'core/image' || block.name === 'core/video' ? 300 : 80}); y+=block.name === 'core/image' || block.name === 'core/video' ? 324 : 104; });
			}
			var copyPath = message.duplicate ? message.changedPath : pending && pending.action === 'duplicate' ? pending.selectedPath : null;
			if (copyPath) {
				var source = locate(blocks,copyPath), original = geometry.find(function(e) { return e.path === copyPath; });
				if (!source || !original) return;
				var duplicate = duplicateWithin(source.block,blocks,api), duplicateGeometry = copy(original); if (message.duplicate) group.innerBlocks.splice(source.index + 1,0,duplicate); else group.innerBlocks.push(duplicate); selectedId = duplicate.clientId;
				if (message.duplicate && message.original) { Object.assign(original,message.original,{key:nodeKey(source.block),type:source.block.name}); }
				else { duplicateGeometry.y = Math.max(...geometry.map(function(e) { return e.y + e.h; })) + 24; }
				duplicateGeometry.key = nodeKey(duplicate); geometry.push(duplicateGeometry);
			}
			var scope = layoutKey(group), css = win.gtPbCanvasLayout.css(scope,geometry,message.width,Math.max(message.height,...geometry.map(function(e) { return e.y + e.h + 24; })),message.precise);
			if (!css) return;
			var styleBlock = helper(group,api); styleBlock.attributes.css = replaceLayoutCss(styleBlock.attributes.css || '',css);
			if (selection && selectedId) selection.nativePath = findPath(blocks,selectedId);
			commit(s,blocks,true,true); notify(pending && pending.action === 'activate' ? 'Freeform layout enabled. Drag the Move handle, resize, or use arrow keys. Mobile keeps the block reading order.' : 'Layout updated.');
		}
		function action(name) {
			var s = current(); if (!s || !s.block) return;
			if (name === 'delete') {
				if (!s.found.parent) { options.deleteSection(s.section.uid); selection = null; render(); return; }
				s.found.list.splice(s.found.index,1); selection.nativePath = findPath(s.blocks,s.found.parent.clientId); commit(s.section,s.blocks,true,true); return;
			}
			if (name === 'duplicate') {
				if (s.found.parent && /\bpb-freeform\b/.test(s.found.parent.attributes.className || '')) { requestLayout(s,findPath(s.blocks,s.found.parent.clientId),'duplicate'); return; }
				var duplicate = duplicateWithin(s.block,s.blocks,api);
				s.found.list.splice(s.found.index + 1,0,duplicate); selection.nativePath = findPath(s.blocks,duplicate.clientId); commit(s.section,s.blocks,true,true); return;
			}
			var target = neighbor(s,name === 'up' ? -1 : 1);
			if (target < 0 || target >= s.found.list.length) return;
			s.found.list.splice(s.found.index,1); s.found.list.splice(target,0,s.block); selection.nativePath = findPath(s.blocks,s.block.clientId); commit(s.section,s.blocks,true,true);
		}
		function reorder(message) {
			if (options.config.canEditNativeBlocks === false || !fresh(message.sectionUid)) return;
			var s=section(message.sectionUid),blocks=tree(s),parent=blocks && locate(blocks,message.parentPath),source=blocks && locate(blocks,message.path),target=blocks && locate(blocks,message.targetPath);
			if (!parent || !source || !target || source.parent !== parent.block || target.parent !== parent.block || !['core/group','core/column','core/columns'].includes(parent.block.name) || /\bpb-freeform\b/.test(parent.block.attributes.className || '') || source.block === target.block) return;
			var list=parent.block.innerBlocks, index=target.index + (message.after ? 1 : 0), original=source.index;
			list.splice(original,1); if (original < index) index--; list.splice(index,0,source.block); selection={sectionUid:s.uid,nativePath:findPath(blocks,source.block.clientId)}; commit(s,blocks,true,true); notify('Block order updated.');
		}
		function neighbor(s,direction) { var target = s.found.index + direction; while (s.found.list[target] && s.found.list[target].name === 'gt-page-block/page-block' && s.found.list[target].attributes.name === 'Page Blocks canvas styles') target += direction; return target; }
		function addBlock(type) {
			var s = current(), blocks = s && s.blocks, group = insertionGroup(s);
			if (!s || !s.block) { notify('Add a visual section first, then select a section or element inside it.'); return; }
			if (!group || !['core/group','core/column'].includes(group.name)) { notify('Select a Group or Column to add an element.'); return; }
			function insert(block) {
				nodeKey(block); showPalette(false);
				if (/\bpb-freeform\b/.test(group.attributes.className || '')) { pendingMeasure = {sectionUid:s.section.uid,action:'insert',block:block}; frameMessage({type:'pb_canvas_measure',sectionUid:s.section.uid,path:findPath(blocks,group.clientId)}); return; }
				group.innerBlocks.push(block); selection.nativePath = findPath(blocks,block.clientId); commit(s.section,blocks,true,true);
			}
			if (type === 'image') { chooseImage(function(image) { insert(api.createBlock('core/image',{id:image.id,url:image.url,alt:image.alt || ''})); }); return; }
			if (type === 'heading') insert(api.createBlock('core/heading',{content:'Your heading',level:2}));
			if (type === 'text') insert(api.createBlock('core/paragraph',{content:'Add your text here.'}));
			if (type === 'button') insert(api.createBlock('core/buttons',{},[api.createBlock('core/button',{text:'Learn more',url:'#'})]));
			if (type === 'columns') insert(api.createBlock('core/columns',{},[api.createBlock('core/column',{},[api.createBlock('core/paragraph',{content:'First column'})]),api.createBlock('core/column',{},[api.createBlock('core/paragraph',{content:'Second column'})])]));
		}
		function insertionGroup(s) { if (!s || !s.block) return null; var path=selection.nativePath.split('.'); while (path.length) { var found=locate(s.blocks,path.join('.')); if (found && ['core/group','core/column'].includes(found.block.name)) return found.block; path.pop(); } return null; }
		function chooseImage(callback) {
			if (!wp.media) { notify('The WordPress media picker is unavailable. Use the image URL field.'); return; }
			var media = wp.media({title:'Choose image',library:{type:'image'},multiple:false,button:{text:'Use image'}}); media.on('select',function() { callback(media.state().get('selection').first().toJSON()); }); media.open();
		}
		function mediaBlock(media) { var url=media.source_url,mime=media.mime_type || ''; if (!safeURL(url,true)) throw new Error('WordPress returned an invalid media URL.');
			if (mime.startsWith('image/')) return api.createBlock('core/image',{id:media.id,url:url,alt:media.alt_text || ''});
			if (mime.startsWith('video/')) return api.createBlock('core/video',{id:media.id,src:url});
			if (mime.startsWith('audio/')) return api.createBlock('core/audio',{id:media.id,src:url});
			var title=doc.createElement('template'); title.innerHTML=media.title && media.title.rendered || 'Download file'; return api.createBlock('core/file',{id:media.id,href:url,fileName:title.content.textContent,displayPreview:false});
		}
		async function dropFiles(message) {
			if (options.config.canUploadMedia === false || options.config.canEditNativeBlocks === false) { notify('With your current permissions, add media through the WordPress editor.',true); return; }
			if (uploading) { notify('An upload is in progress. Wait before dropping more files.',true); return; }
			var files=Array.isArray(message.files) ? message.files : []; if (!files.length) return; if (files.length > 20) { notify('Drop up to 20 files at a time.',true); return; }
			if (!options.config.mediaEndpoint || !options.config.restNonce) { notify('The WordPress media upload endpoint is unavailable.',true); return; }
			var target=message.sectionUid && section(message.sectionUid),source=target && (target.kind === 'foreign' ? target.serialized : target.content),containers=target && tree(target),found=containers && message.containerPath && locate(containers,message.containerPath);
			if (found && /\bpb-freeform\b/.test(found.block.attributes.className || '') && frameWidth() <= 768) { notify('Use a desktop preview to drop media into a freeform section.',true); return; }
			uploading=true; var uploaded=[],failures=[];
			try {
				for (var i=0;i<files.length;i++) { var file=files[i]; if (!(file instanceof win.Blob) || !file.name || options.config.maxUploadBytes && file.size > options.config.maxUploadBytes) { failures.push((file.name || 'File') + ': exceeds the upload limit or is not a file.'); continue; }
					notify('Uploading ' + file.name + ' (' + (i+1) + '/' + files.length + ')…',true);
					try { var data=new win.FormData(); data.append('file',file,file.name); data.append('post',String(options.config.postId)); var response=await win.fetch(options.config.mediaEndpoint,{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':options.config.restNonce},body:data}),media=await response.json(); if (!response.ok) throw new Error(media.message || 'WordPress rejected this file.'); uploaded.push(mediaBlock(media)); } catch(error) { failures.push(file.name + ': ' + error.message); }
				}
				if (uploaded.length) {
					var latest=target && section(target.uid); if (target && (!latest || source !== (latest.kind === 'foreign' ? latest.serialized : latest.content))) { notify('Media uploaded to the library. The section changed during upload; add the files from the media picker.',true); return; }
					var blocks=latest && tree(latest),parent=blocks && message.containerPath && locate(blocks,message.containerPath);
					if (parent && ['core/group','core/column'].includes(parent.block.name)) {
						if (/\bpb-freeform\b/.test(parent.block.attributes.className || '')) { pendingMeasure={sectionUid:latest.uid,action:'insert',blocks:uploaded,point:message.point}; frameMessage({type:'pb_canvas_measure',sectionUid:latest.uid,path:message.containerPath}); }
						else { var anchor=message.targetPath && locate(blocks,message.targetPath),index=anchor && anchor.parent === parent.block ? anchor.index + (message.after ? 1 : 0) : parent.block.innerBlocks.length; parent.block.innerBlocks.splice(index,0,...uploaded); selection={sectionUid:latest.uid,nativePath:findPath(blocks,uploaded[0].clientId)}; commit(latest,blocks,true,true); }
					} else { var group=api.createBlock('core/group',{tagName:'section',metadata:{name:'Media section'},layout:{type:'default'},style:{spacing:{padding:'48px',blockGap:'24px'}}},uploaded),uid=options.addSection(api.serialize([group]),target && target.uid); selection={sectionUid:uid,nativePath:'0.0'}; render(); }
				}
				notify((uploaded.length ? uploaded.length + ' file(s) uploaded. Save to keep the page changes.' : 'No files were uploaded.') + (failures.length ? ' ' + failures.join(' ') : ''),!!failures.length);
			} finally { uploading=false; }
		}
		function field(parent,label,value,change,choices) {
			var wrap = doc.createElement('label'); wrap.className = 'pb-canvas-field'; var caption = doc.createElement('span'); caption.textContent = label; wrap.appendChild(caption);
			var input = doc.createElement(choices ? 'select' : label === 'Text' ? 'textarea' : 'input');
			if (!choices && input.tagName === 'INPUT') input.type = 'text'; input.name = 'pb-canvas-' + label.toLowerCase().replace(/\W/g,'-');
			if (choices) choices.forEach(function(c) { var o = doc.createElement('option'); o.value = c[0]; o.textContent = c[1]; input.appendChild(o); });
			input.value = value == null ? '' : String(value); var applied = input.value;
			input.addEventListener('change',function() { if (input.value === applied) return; if (change(input.value) === false) { input.setAttribute('aria-invalid','true'); notify(label + ' was not applied. Check the value and try again.',true); } else { input.removeAttribute('aria-invalid'); applied = input.value; } }); wrap.appendChild(input); parent.appendChild(wrap); return input;
		}
		function group(label) { var block = doc.createElement('section'); block.className = 'pb-canvas-field-group'; var h = doc.createElement('h3'); h.textContent = label; block.appendChild(h); panel.appendChild(block); return block; }
		function presets(raw) {
			var all = Array.isArray(raw) ? raw : raw && typeof raw === 'object' ? (raw.theme && raw.theme.length ? (raw.theme || []).concat(raw.custom || []) : Object.values(raw).flatMap(presets)) : [];
			var unique = new Map(); all.forEach(function(p) { if (p && p.slug) unique.set(p.slug,p); }); return Array.from(unique.values());
		}
		function renderLayers(s) {
			var layers=group('Layers');
			function row(bs,prefix,depth) { var counts={}; bs.forEach(function(b,i) { var path=prefix ? prefix + '.' + i : String(i); if (b.name === 'gt-page-block/page-block' && ['Page Blocks canvas styles','Imported section styles'].includes(b.attributes.name)) return;
				var type=LABELS[b.name] || b.name; counts[type]=(counts[type] || 0) + 1; var probe=doc.createElement('template'); probe.innerHTML=b.attributes.content || b.attributes.text || ''; var excerpt=probe.content.textContent.trim().slice(0,48);
				var label=b.attributes.metadata && b.attributes.metadata.name || type + (excerpt ? ' ' + counts[type] + ' · ' + excerpt : ''), item=button(label,'layout',function() { selection={sectionUid:s.section.uid,nativePath:path}; render(); frameMessage({type:'pb_canvas_select_native',sectionUid:s.section.uid,path:path}); },'pb-canvas-layer' + (path === selection.nativePath ? ' is-selected' : ''));
				item.setAttribute('aria-pressed',String(path === selection.nativePath)); item.style.paddingLeft=(8 + depth * 12) + 'px'; layers.appendChild(item); row(b.innerBlocks || [],path,depth+1);
			}); }
			row(s.blocks,'',0);
		}
		function render() {
			var focusedName = panel.contains(doc.activeElement) && doc.activeElement.getAttribute('name');
			if (options.getSelectedUid) { var uid=options.getSelectedUid(); if (uid !== activeSectionUid) { activeSectionUid=uid; var selectedSection=section(uid); selection = selectedSection && selectedSection.kind === 'foreign' && SUPPORTED.includes(selectedSection.blockName) ? {sectionUid:uid,nativePath:'0'} : null; } }
			var scroll = options.container.scrollTop; panel.replaceChildren(); rail.hidden = !options.isEnabled(); panel.hidden = !options.isEnabled(); widthNote.hidden = !options.isEnabled(); if (!options.isEnabled()) { showPalette(false); status.hidden = true; return; }
			updateHistory();
			var s = current();
			var header = doc.createElement('div'); header.className = 'pb-canvas-inspector-header'; header.innerHTML = '<strong>Design</strong><span>Click to select · double-click to edit text</span>'; panel.appendChild(header);
			if (!s) {
				var empty = doc.createElement('p'); empty.className = 'pb-canvas-empty';
				var old = selection && section(selection.sectionUid);
				if (old && old.kind === 'foreign') { var preserved=tree(old); if (preserved) renderLayers({section:old,blocks:preserved}); }
				empty.textContent = !selection ? 'Select an element on the page to edit its content and styles. Add a visual section for a new layout.' : old && old.kind === 'foreign' ? 'This block is preserved. Use the WordPress editor for content the canvas cannot edit.' : 'This output is managed by a library block or generated code. Use Code mode to edit its source.';
				if (options.config.canEditNativeBlocks === false) empty.textContent='With your current permissions, edit native blocks in the WordPress editor. Code sections can still be edited here.';
				panel.appendChild(empty);
				if (options.config.canEditNativeBlocks !== false && old && old.visualData && !old.js && !old.phpExec && !old.format && !old.blockId && win.gtPbPrototypeConversion) {
					panel.appendChild(button('Convert prototype section','layout',function() {
						try { var converted = win.gtPbPrototypeConversion.convert(old,api); var serialized = api.serialize(converted); options.onChange(old.uid,{kind:'foreign',blockName:'core/group',label:old.name || 'Visual section',serialized:serialized,rendered:'',content:'',css:'',js:'',visualData:null},true); selection={sectionUid:old.uid,nativePath:'0'}; render(); notify('Converted to native blocks. Save when ready, or use Undo to restore the section.'); }
						catch(error) { notify(error.message); }
					}));
				}
				if ((selection || options.config.canEditNativeBlocks === false) && options.config.editPostUrl) { var link=doc.createElement('a'); link.className='pb-canvas-button'; link.href=options.config.editPostUrl; link.target='_blank'; link.rel='noopener'; link.innerHTML=(options.config.icons['external-link'] || '')+'<span>WordPress editor</span>'; panel.appendChild(link); }
				if (options.config.canEditNativeBlocks !== false) panel.appendChild(button('Add visual section','plus',newSection)); return;
			}
			var title = doc.createElement('div'); title.className = 'pb-canvas-selection-title'; title.textContent = s.block ? LABELS[s.block.name] || s.block.name : selection.tagName.toUpperCase(); panel.appendChild(title);
			if (s.block) {
				var actions = doc.createElement('div'); actions.className = 'pb-canvas-actions'; [['up','Move up in reading order','chevron-up'],['down','Move down in reading order','chevron-down'],['duplicate','Duplicate','copy'],['delete','Delete','trash']].forEach(function(a) { var b=button(a[1],a[2],function() { action(a[0]); }); if (a[0] === 'up' || a[0] === 'down') { var next=neighbor(s,a[0] === 'up' ? -1 : 1); b.disabled = next < 0 || next >= s.found.list.length; } if (a[0] === 'duplicate' && s.found.parent && /\bpb-freeform\b/.test(s.found.parent.attributes.className || '') && frameWidth() <= 768) { b.disabled = true; b.title='Switch to a desktop preview to duplicate this freeform element.'; } actions.appendChild(b); }); panel.appendChild(actions);
				renderLayers(s);
			}
			var contentGroup = group('Content'), attrs = s.block && s.block.attributes;
			var texty = s.block ? ['core/heading','core/paragraph','core/button'].includes(s.block.name) : /^(h[1-6]|p|li|a|button)$/.test(selection.tagName);
			if (texty) { var text = s.block ? attrs.content || attrs.text || '' : selection.html || ''; var probe = doc.createElement('div'); probe.innerHTML = text; field(contentGroup,'Text',probe.textContent,function(v) { content(s,v); }); }
			if (s.block && s.block.name === 'core/heading') field(contentGroup,'Heading level',attrs.level || 2,function(v) { attrs.level = Number(v); commit(s.section,s.blocks,true,false); },[1,2,3,4,5,6].map(function(n) { return [n,'H'+n]; }));
			var image = s.block ? s.block.name === 'core/image' : selection.tagName === 'img';
			if (image) {
				contentGroup.appendChild(button('Choose image','photo',function() { chooseImage(function(img) { if (s.block) { Object.assign(attrs,{id:img.id,url:img.url,alt:img.alt || ''}); commit(s.section,s.blocks,true,true); } else {
					if (!safeURL(img.url,true)) return;
					var result=win.gtPbPreviewDom.replaceImageSource(s.section.content,{path:selection.sourcePath,tagName:selection.tagName,oldValue:selection.attributes.src || ''},img,doc);
					if (result === null) { notify('The image changed while the media picker was open. Select it again and retry.',true); return; }
					Object.assign(selection.attributes,{src:img.url,alt:img.alt || ''}); options.onChange(s.section.uid,{content:result},true); render();
				} }); }));
				field(contentGroup,'Image URL',s.block ? attrs.url : selection.attributes.src,function(v) { if (!safeURL(v,true)) { notify('Use a valid image URL.'); return false; } if (s.block) { attrs.url = v; ['id','sizeSlug','width','height','aspectRatio','scale'].forEach(function(key) { delete attrs[key]; }); commit(s.section,s.blocks,true,false); } else return sourceAttribute(s,'src',v); });
				field(contentGroup,'Alt text',s.block ? attrs.alt : selection.attributes.alt,function(v) { if (s.block) { attrs.alt = v; commit(s.section,s.blocks,true,false); } else sourceAttribute(s,'alt',v); });
			}
			if (s.block && s.block.name === 'core/button' || !s.block && selection.tagName === 'a') field(contentGroup,'Link URL',s.block ? attrs.url : selection.attributes.href,function(v) { if (!safeURL(v,false)) { notify('Use a valid link URL.'); return false; } if (s.block) { attrs.url = v; commit(s.section,s.blocks,true,false); } else return sourceAttribute(s,'href',v); });
			if (s.block && ['core/video','core/audio','core/file'].includes(s.block.name)) { contentGroup.hidden=false; field(contentGroup,'Media URL',attrs.src || attrs.href || '',function(value) { if (!safeURL(value,true)) return false; attrs[s.block.name === 'core/file' ? 'href' : 'src']=value; delete attrs.id; commit(s.section,s.blocks,true,false); }); }
			if (!texty && !image && !(s.block && s.block.name === 'core/button')) contentGroup.hidden = true;
			if (s.block && ['core/video','core/audio','core/file'].includes(s.block.name)) contentGroup.hidden=false;
			var styles = group('Style'); field(styles,'Apply to',viewport,function(v) { viewport=v; if (options.setPreviewViewport) options.setPreviewViewport(v === 'desktop' ? 'desktop' : v === 'tablet' ? '768' : '480'); render(); },[['desktop','All screens'],['tablet','Tablet ≤768px'],['mobile','Mobile ≤480px']]);
			var styleHint=doc.createElement('p'); styleHint.textContent='Lengths accept px, rem, or a number for pixels. Clear a value to inherit.'; styles.appendChild(styleHint);
			var map = {'color':['color','text'],'background-color':['color','background'],'font-size':['typography','fontSize'],'padding':['spacing','padding'],'margin':['spacing','margin'],'border-radius':['border','radius']};
			[['color','Text color'],['background-color','Background'],['font-size','Font size'],['padding','Padding'],['margin','Margin'],['max-width','Max width'],['border-radius','Corner radius']].forEach(function(spec) {
				var classes = s.block ? attrs.className || '' : s.source.openTag, key = (classes.match(/\bpb-node-[a-z0-9]+\b/) || [])[0];
				var styleBlock = s.block && s.blocks[0].innerBlocks.find(function(b) { return b.name === 'gt-page-block/page-block' && b.attributes.name === 'Page Blocks canvas styles'; });
				var value = ruleValue(s.block ? styleBlock && styleBlock.attributes.css || '' : s.section.css || '',key,spec[0],viewport);
				if (!value && s.block && viewport === 'desktop' && map[spec[0]]) { var m=map[spec[0]], values=attrs.style || {}; value=values[m[0]] && values[m[0]][m[1]] || ''; if (typeof value === 'object') value=''; }
				var input = field(styles,spec[1],value,function(v) { return setStyle(s,spec[0],v.trim()); }); input.placeholder = spec[0].includes('color') ? 'Inherit from theme' : 'Inherit';
				if (spec[0].includes('color')) {
					var list = doc.createElement('datalist'); list.id = token('pb-palette-');
					presets(options.config.themePalette).filter(function(p) { return /^[a-z0-9-]+$/i.test(p.slug || ''); }).forEach(function(p) { var option=doc.createElement('option'); option.value='var(--wp--preset--color-' + p.slug + ')'; option.label=p.name || p.slug; list.appendChild(option); });
					input.setAttribute('list',list.id); styles.appendChild(list);
				}
			});
			if (texty) {
				var fontPresets = presets(options.config.themeFontSizes).filter(function(p) { return /^[a-z0-9-]+$/i.test(p.slug || ''); });
				if (fontPresets.length) field(styles,'Theme font size',s.block && attrs.fontSize || '',function(value) {
					if (s.block && viewport === 'desktop') { attrs.fontSize=value || undefined; if (attrs.style && attrs.style.typography) delete attrs.style.typography.fontSize; commit(s.section,s.blocks,true,false); }
					else return setStyle(s,'font-size',value ? 'var(--wp--preset--font-size-' + value + ')' : '');
				},[['','Custom / inherit']].concat(fontPresets.map(function(p) { return [p.slug,p.name || p.slug]; })));
			}
			if (texty) field(styles,'Text alignment',s.block ? attrs.textAlign || attrs.align || '' : '',function(v) { return setStyle(s,'text-align',v); },[['','Inherit'],['left','Left'],['center','Center'],['right','Right']]);
			var owner=s.block && insertionGroup(s); if (owner && owner.name === 'core/group') {
				var layoutGroup=group('Layout'),freeform=/\bpb-freeform\b/.test(owner.attributes.className || ''),ownerPath=findPath(s.blocks,owner.clientId);
				field(layoutGroup,'Placement',freeform ? 'freeform' : 'auto',function(value) { if (value === 'auto') { pendingMeasure=null; autoPlacement(owner); selection.nativePath=findPath(s.blocks,s.block.clientId); commit(s.section,s.blocks,true,true); notify('Auto placement enabled. Drag blocks to reorder them.'); } else { if (frameWidth() <= 768) { notify('Freeform arrangement needs a canvas wider than 768px.',true); return false; } requestLayout(s,ownerPath,'activate'); } },[['auto','Fixed / Auto'],['freeform','Freeform']]);
				var hint=doc.createElement('p'); hint.textContent=freeform ? 'Drag to place freely. Mobile follows block reading order.' : 'Blocks stay in normal layout flow. Drag to change their order.'; layoutGroup.appendChild(hint);
			}
			options.container.scrollTop = scroll;
			if (focusedName) { var focused=panel.querySelector('[name="' + focusedName + '"]'); if (focused) focused.focus({preventScroll:true}); }
		}
		function newSection() { if (options.config.canEditNativeBlocks === false) return; var serialized = createSection(api,win.gtPbCanvasLayout,'hero'); var uid = options.addSection(serialized); selection = {sectionUid:uid,nativePath:'0'}; showPalette(false); render(); }
		var addButton = button('Add','plus',function() { showPalette(palette.hidden); }); palette.id = token('pb-canvas-add-'); addButton.setAttribute('aria-expanded','false'); addButton.setAttribute('aria-controls',palette.id); rail.appendChild(addButton);
		var gridButton = button('Grid','grid-dots',function() { grid = !grid; this.setAttribute('aria-pressed',grid ? 'true' : 'false'); sync(); }); gridButton.setAttribute('aria-pressed','false'); rail.appendChild(gridButton);
		var undoButton = button('Undo','arrow-back-up',function() { options.undo(); render(); }), redoButton = button('Redo','arrow-forward-up',function() { options.redo(); render(); }); rail.append(undoButton,redoButton);
		function updateHistory() { if (undoButton) undoButton.disabled = !options.canUndo(); if (redoButton) redoButton.disabled = !options.canRedo(); }
		var paletteTitle = doc.createElement('strong'); paletteTitle.textContent = 'Add to canvas'; palette.appendChild(paletteTitle); var paletteHint = doc.createElement('p'); palette.appendChild(paletteHint); var newButton=button('New visual section','layout',newSection); newButton.disabled=options.config.canEditNativeBlocks === false; palette.appendChild(newButton);
		[['heading','Heading','typography'],['text','Text','text-caption'],['image','Image','photo'],['button','Button','click'],['columns','Columns','layout']].forEach(function(a) { palette.appendChild(button(a[1],a[2],function() { addBlock(a[0]); })); });
		function updatePalette() { var target=insertionGroup(current()), allowed=!!target, mobile=allowed && /\bpb-freeform\b/.test(target.attributes.className || '') && frameWidth() <= 768; paletteHint.textContent = !allowed ? 'Create a visual section, then add elements inside it.' : mobile ? 'Switch to Desktop to add freeform elements. You can edit text and styles here.' : 'Add an element inside the selected Group or Column.'; Array.from(palette.querySelectorAll('button')).slice(1).forEach(function(b) { b.disabled = !allowed || mobile; }); }
		doc.addEventListener('keydown',function(event) { if (event.key === 'Escape' && !palette.hidden) { event.preventDefault(); showPalette(false); addButton.focus(); } });
		doc.addEventListener('pointerdown',function(event) { if (!palette.hidden && !palette.contains(event.target) && !addButton.contains(event.target)) showPalette(false); });
		function handleMessage(message) {
			if (message && message.type === 'pb_canvas_files_disabled') { notify('Switch to Visual mode to drop media onto the page.',true); return true; }
			if (!message || (!options.isEnabled() && message.type !== 'pb_canvas_text')) return false;
			if (message.type === 'pb_canvas_clear') { selection = null; showPalette(false); render(); return true; }
			if (message.type === 'pb_canvas_inspect') { render(); var input=panel.querySelector('input,textarea,select'); if (input) input.focus(); return true; }
			if (message.type === 'pb_canvas_viewport') { widthNote.textContent=Math.round(message.width) + 'px'; widthNote.setAttribute('aria-label','Canvas width ' + Math.round(message.width) + ' pixels'); var mobile=message.width <= 768; if (mobile !== mobilePreview) { mobilePreview=mobile; render(); if (!palette.hidden) updatePalette(); } return true; }
			if (message.type === 'pb_canvas_select') { if (!fresh(message.sectionUid)) return true; options.selectSection(message.sectionUid); selection = message; status.hidden = true; showPalette(false); render(); return true; }
			if (message.type === 'pb_canvas_measure_result') { if (pendingMeasure && pendingMeasure.sectionUid === message.sectionUid) { var p=pendingMeasure; pendingMeasure=null; layoutCommit(message,p); } return true; }
			if (message.type === 'pb_canvas_layout') { layoutCommit(message); return true; }
			if (message.type === 'pb_canvas_reorder') { reorder(message); return true; }
			if (message.type === 'pb_canvas_files') { dropFiles(message).catch(function(error) { uploading=false; notify('Upload failed: ' + error.message,true); }); return true; }
			if (message.type === 'pb_canvas_action') { if (!fresh(message.sectionUid)) return true; selection=message; action(message.action); return true; }
			if (message.type === 'pb_canvas_history') { if (message.redo) options.redo(); else options.undo(); render(); return true; }
			if (message.type === 'pb_canvas_save') { options.save(); return true; }
			if (message.type === 'pb_canvas_text') {
				if (!fresh(message.sectionUid)) return true;
				selection = Object.assign({},message,{html:message.oldHtml}); var s=current();
				if (s) content(s,message.text,cleanInline(message.newHtml,doc)); else notify('Use Code mode to edit this generated output.'); render(); return true;
			}
			return false;
		}
		function sync(snapshot) {
			if (snapshot) { previewReady=true; snapshot.forEach(function(s) { snapshots.set(s.uid,s.source); }); }
			frameMessage({type:'pb_canvas_mode',enabled:options.isEnabled() && previewReady,paused:options.isEnabled() && !previewReady,nativeEditable:options.config.canEditNativeBlocks !== false,grid:grid,editableSections:options.getSections().filter(function(s) { return s.kind !== 'foreign' && !s.blockId && !s.phpExec && !s.format && !s.visualData; }).map(function(s) { return s.uid; })});
			if (selection && selection.nativePath) frameMessage({type:'pb_canvas_select_native',sectionUid:selection.sectionUid,path:selection.nativePath});
			else if (selection && selection.sourcePath) frameMessage({type:'pb_canvas_select_source',sectionUid:selection.sectionUid,path:selection.sourcePath,tagName:selection.tagName});
			else if (!selection) frameMessage({type:'pb_canvas_clear'});
		}
		return { render:render, handleMessage:handleMessage, sync:sync, notify:notify, updateHistory:updateHistory, clearSelection:function() { selection=null; }, invalidate:function() { previewReady=false; snapshots.clear(); selection=null; frameMessage({type:'pb_canvas_clear'}); sync(); render(); } };
	}
	function cleanInline(html,doc) {
		var holder = doc.createElement('div'); holder.innerHTML = html;
		Array.from(holder.querySelectorAll('*')).reverse().forEach(function(node) {
			if (['SCRIPT','STYLE','IFRAME','OBJECT'].includes(node.tagName)) { node.remove(); return; }
			if (!['STRONG','B','EM','I','A','BR','CODE'].includes(node.tagName)) { node.replaceWith(...node.childNodes); return; }
			Array.from(node.attributes).forEach(function(attr) { if (node.tagName !== 'A' || !['href','target','rel'].includes(attr.name)) node.removeAttribute(attr.name); });
			if (node.tagName === 'A' && !safeURL(node.getAttribute('href') || '',false)) node.removeAttribute('href');
		});
		return holder.innerHTML;
	}
	return { mount:mount, locate:locate, setRule:setRule, ruleValue:ruleValue, editPlainText:editPlainText, createSection:createSection, autoPlacement:autoPlacement, cleanInline:cleanInline, cloneNative:cloneNative, duplicateWithin:duplicateWithin, replaceLayoutCss:replaceLayoutCss, safeURL:safeURL };
});
