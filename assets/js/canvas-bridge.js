/* Preview-only canvas chrome. Authored HTML receives no editor wrappers. */
(function(root) {
	'use strict';
	function bridge(icons, enabled) {
		var selected = null, editing = null, box, toolbar, gesture = null, gx, gy, endEditing = null, positionFrame = null, suppressClickUntil = 0;
		var editableSections = new Set(), nativeEditable = true, scrollFrame = null;
		window.__pbCanvasVisual = enabled;
		window.__pbCanvasWorkspaceVisual = enabled;
		var style = document.createElement('style');
		style.textContent = '.pb-canvas-box{position:fixed;z-index:2147483000;pointer-events:none;border:2px solid #3858e9;box-sizing:border-box}.pb-canvas-tools{position:absolute;left:-2px;bottom:calc(100% + 6px);display:flex;gap:2px;background:#fff;border:1px solid #dcdcde;border-radius:5px;box-shadow:0 4px 16px #0002;padding:3px;font:12px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;white-space:nowrap}.pb-canvas-tools button,.pb-canvas-resize{all:unset;box-sizing:border-box;pointer-events:auto;cursor:pointer;display:flex;align-items:center;gap:5px;color:#1e1e1e;padding:5px 7px;border-radius:3px;font:12px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif}.pb-canvas-tools button:hover{background:#f0f3ff}.pb-canvas-tools svg{width:15px;height:15px;flex-shrink:0}.pb-canvas-tools button:focus-visible{outline:2px solid #3858e9}.pb-canvas-resize{position:absolute;right:-6px;bottom:-6px;width:10px;height:10px;background:#fff;border:2px solid #3858e9;padding:0;cursor:nwse-resize}.pb-canvas-guide{position:absolute;background:#e74b79;pointer-events:none;z-index:2147482000}.pb-canvas-grid .pb-freeform{background-image:linear-gradient(to right,#3858e912 1px,transparent 1px),linear-gradient(to bottom,#3858e912 1px,transparent 1px);background-size:24px 24px}.pb-freeform:empty{min-height:240px}.pb-canvas-box[hidden],.pb-canvas-tools button[hidden],.pb-canvas-resize[hidden]{display:none}';
		document.head.appendChild(style);
		style.textContent += '.pb-canvas-tools{max-width:calc(100vw - 16px);flex-wrap:wrap;box-sizing:border-box;pointer-events:auto}.pb-canvas-tools button{min-height:30px}.pb-canvas-resize{right:-12px;bottom:-12px;width:24px;height:24px;background:transparent;border:0;display:grid;place-items:center}.pb-canvas-resize:after{content:"";width:8px;height:8px;background:#fff;border:2px solid #3858e9}.pb-canvas-resize:focus-visible{outline:2px solid #3858e9}.pb-canvas-tools button:disabled{opacity:.45;pointer-events:none}@media(max-width:480px){.pb-canvas-tools button span{display:none}.pb-canvas-tools button{min-width:36px;min-height:36px;justify-content:center}}@media(pointer:coarse){.pb-canvas-tools button{min-width:44px;min-height:44px}.pb-canvas-resize{width:44px;height:44px;right:-22px;bottom:-22px}}';
		style.textContent += '.pb-canvas-tools [data-pb-move]{cursor:grab;touch-action:none}.pb-canvas-resize{touch-action:none}.pb-canvas-insertion{position:fixed;z-index:2147483001;pointer-events:none;background:#3858e9;border-radius:2px;box-shadow:0 0 0 2px #fff}.pb-canvas-insertion:before{content:"";position:absolute;left:-3px;top:-3px;width:8px;height:8px;border:2px solid #3858e9;border-radius:50%;background:#fff;box-sizing:border-box}.pb-canvas-drag-label{position:fixed;z-index:2147483002;pointer-events:none;padding:6px 9px;border:1px solid #3858e9;border-radius:5px;background:#3858e9;color:#fff;box-shadow:0 3px 12px #0002;font:12px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;white-space:nowrap;max-width:calc(100vw - 24px);overflow:hidden;text-overflow:ellipsis}.pb-canvas-insertion[hidden]{display:none}';
		function clipboardTarget(event) { var node=event.target;return window.__pbCanvasVisual && !editing && !gesture && !(node && (node.isContentEditable || node.closest && node.closest('input,textarea,select,[contenteditable="true"]'))); }
		document.addEventListener('keydown',function(event){if(!clipboardTarget(event) || !(event.metaKey||event.ctrlKey) || event.altKey)return;var key=event.key.toLowerCase();if((key==='c'||key==='x') && selected && !window.getSelection().toString()){event.preventDefault();event.stopPropagation();send(Object.assign({type:'pb_canvas_clipboard',action:key==='x'?'cut':'copy'},ref(selected)));}});
		document.addEventListener('paste',function(event){if(!clipboardTarget(event))return;var text=event.clipboardData && event.clipboardData.getData('text/plain');if(!text || !text.includes('page-blocks-clipboard'))return;event.preventDefault();send(Object.assign({type:'pb_canvas_clipboard',action:'paste',text:text},selected?ref(selected):{}));});
		function send(data) { window.parent.postMessage(data, window.parent.location.origin); }
		function section(node) { return node && node.closest('[data-pb-section]'); }
		function nativeNode(node) { return node && node.closest('[data-pb-canvas-path]'); }
		function ref(node) {
			var sec = section(node), native = nativeNode(node);
			if (!sec) return null;
			if (native) return { sectionUid: sec.dataset.pbSection, nativePath: native.dataset.pbCanvasPath, tagName: native.tagName.toLowerCase() };
			var path = [], cursor = node;
			while (cursor && cursor !== sec) { path.unshift(Array.prototype.indexOf.call(cursor.parentElement.children, cursor)); cursor = cursor.parentElement; }
			path.unshift(Number(sec.dataset.pbRootIndex));
			return { sectionUid: sec.dataset.pbSection, sourcePath: path, tagName: node.tagName.toLowerCase() };
		}
		function draggable(node) { return nativeEditable && node && node.closest('.pb-freeform > [data-pb-canvas-path]'); }
		function movable(node) { if (!nativeEditable) return null; var free=draggable(node); if (free) return free; var item=nativeNode(node); while (item) { if (item.parentElement && ['core/group','core/column','core/columns'].includes(item.parentElement.dataset.pbCanvasType)) return item; item=nativeNode(item.parentElement); } return null; }
		function editable(node) {
			if (!node) return null;
			var native = nativeNode(node), sec = section(node);
			if (!sec || (native ? !nativeEditable || !['core/heading','core/paragraph','core/button'].includes(native.dataset.pbCanvasType) : !editableSections.has(sec.dataset.pbSection))) return null;
			// A nested link or emphasis still edits the complete native block's text.
			var text = native;
			if (native && native.dataset.pbCanvasType === 'core/button' && !native.matches('a,button')) text = native.querySelector('a,button');
			if (!native) text = node.closest('h1,h2,h3,h4,h5,h6,p,li,a,button') || node.querySelector('h1,h2,h3,h4,h5,h6,p,a,button');
			if (text && !sec.contains(text)) return null;
			return text && !text.querySelector('div,section,article,ul,ol,table,form,header,footer,nav,aside') ? text : null;
		}
		function geometry(parent) {
			var rect = parent.getBoundingClientRect(), computed=getComputedStyle(parent);
			function number(property) { return parseFloat(computed[property]) || 0; }
			var px=number('paddingLeft'), py=number('paddingTop'), bx=number('borderLeftWidth'), by=number('borderTopWidth');
			return { rootPath: parent.dataset.pbCanvasPath, sectionUid: section(parent).dataset.pbSection, viewportWidth:innerWidth,
				width:Math.max(1,rect.width - px - number('paddingRight') - bx - number('borderRightWidth')),
				height:Math.max(1,rect.height - py - number('paddingBottom') - by - number('borderBottomWidth')), originX:px,originY:py,
				elements: Array.prototype.filter.call(parent.children, function(n) { return n.dataset.pbCanvasPath && !n.dataset.pbGhost; }).map(function(n) {
					var r = n.getBoundingClientRect(), c=getComputedStyle(n);
					return { path: n.dataset.pbCanvasPath, key: Array.from(n.classList).find(function(c) { return /^pb-node-[a-z0-9]+$/.test(c); }) || '', type: n.dataset.pbCanvasType, x: r.left - rect.left - px - bx + parent.scrollLeft, y: r.top - rect.top - py - by + parent.scrollTop, w: r.width, h: r.height, ml:parseFloat(c.marginLeft) || 0,mr:parseFloat(c.marginRight) || 0,mt:parseFloat(c.marginTop) || 0,mb:parseFloat(c.marginBottom) || 0 };
				}) };
		}
		function position() {
			if (!selected || !selected.isConnected || !window.__pbCanvasVisual) { if (box) box.hidden = true; return; }
			var rect = (gesture && gesture.ghost ? gesture.ghost : selected).getBoundingClientRect();
			if (rect.bottom <= 0 || rect.top >= innerHeight || rect.right <= 0 || rect.left >= innerWidth) { box.hidden = true; return; }
			var canArrange = !!draggable(selected) && window.innerWidth > 768;
			box.querySelector('[data-pb-move]').hidden = !movable(selected) || (!!draggable(selected) && innerWidth <= 768); box.querySelector('.pb-canvas-resize').hidden = !canArrange;
			box.querySelector('[data-pb-move]').title=draggable(selected) ? 'Move freely' : 'Drag to reorder';
			var duplicate=box.querySelector('[data-pb-action="duplicate"]');
			if (duplicate) { var desktopOnly=selected.parentElement.classList.contains('pb-freeform') && innerWidth <= 768; duplicate.disabled=!!editing || desktopOnly; duplicate.title=desktopOnly ? 'Switch to Desktop to duplicate this freeform element.' : 'Duplicate'; }
			box.hidden = false;
			Object.assign(box.style, { left: rect.left + 'px', top: rect.top + 'px', width: rect.width + 'px', height: rect.height + 'px' });
			var width = toolbar.offsetWidth, height = toolbar.offsetHeight, gap = 8;
			var left = Math.max(gap,Math.min(rect.left,innerWidth - width - gap));
			var top = rect.top - height - gap;
			if (top < gap) top = rect.bottom + gap;
			top = Math.max(gap,Math.min(top,innerHeight - height - gap));
			toolbar.style.bottom = 'auto'; toolbar.style.left = (left - rect.left) + 'px'; toolbar.style.top = (top - rect.top) + 'px';
		}
		function queuePosition() { if (positionFrame == null) positionFrame = requestAnimationFrame(function() { positionFrame = null; position(); }); }
		function formatState() { if (!editing || !box) return; Array.from(box.querySelectorAll('[data-pb-format]')).forEach(function(button) { var command=button.dataset.pbFormat; if (command === 'bold' || command === 'italic') button.setAttribute('aria-pressed',String(document.queryCommandState(command))); }); }
		function format(command) {
			if (!editing) return;
			var value = null;
			if (command === 'createLink') { value = window.prompt('Link URL', 'https://'); if (!value) return; try { if (!['http:','https:','mailto:','tel:'].includes(new URL(value,window.parent.location.href).protocol)) return; } catch(error) { return; } }
			document.execCommand('styleWithCSS',false,false); document.execCommand(command,false,value);
			formatState(); queuePosition();
		}
		function select(node, notify) {
			selected = node;
			if (!box) {
				box = document.createElement('div'); box.className = 'pb-canvas-box';
				toolbar = document.createElement('div'); toolbar.className = 'pb-canvas-tools';
				function button(label, icon, callback) {
					var b = document.createElement('button'); b.type = 'button'; b.title = label; b.setAttribute('aria-label', label);
					b.innerHTML = icons[icon] || ''; var text = document.createElement('span'); text.textContent = label; b.appendChild(text);
					b.addEventListener('click', callback); toolbar.appendChild(b); return b;
				}
				[['Bold','bold','bold'],['Italic','italic','italic'],['Link','link','createLink']].forEach(function(f) { var b=button(f[0],f[1],function() { format(f[2]); }); b.dataset.pbFormat=f[2]; b.addEventListener('mousedown',function(event) { event.preventDefault(); }); });
				var move = button('Move', 'arrows-move', function() {}); move.dataset.pbMove = '1'; move.addEventListener('pointerdown', function(e) { start(e, false); });
				button('Edit', 'click', function() { if (!startEditing(selected) && selected) send(Object.assign({ type: 'pb_canvas_inspect' }, ref(selected))); }).dataset.pbEdit = '1';
				var duplicate = button('Duplicate', 'copy', function() { if (selected) send(Object.assign({ type: 'pb_canvas_action', action: 'duplicate' }, ref(selected))); }); duplicate.dataset.pbNativeAction = '1'; duplicate.dataset.pbAction='duplicate';
				button('Delete', 'trash', function() { if (selected) send(Object.assign({ type: 'pb_canvas_action', action: 'delete' }, ref(selected))); }).dataset.pbNativeAction = '1';
				var resize = document.createElement('button'); resize.type = 'button'; resize.className = 'pb-canvas-resize'; resize.title = 'Resize element'; resize.setAttribute('aria-label', 'Resize element'); resize.addEventListener('pointerdown', function(e) { start(e, true); });
				box.append(toolbar, resize); document.body.appendChild(box);
			}
			Array.from(box.querySelectorAll('[data-pb-format]')).forEach(function(b) { b.hidden = !editing; });
			var editButton = box.querySelector('[data-pb-edit]'), editLabel = editable(node) ? 'Edit text' : 'Inspect';
			editButton.setAttribute('aria-label',editLabel); editButton.title = editLabel; editButton.querySelector('span').textContent = editLabel;
			Array.from(box.querySelectorAll('[data-pb-native-action]')).forEach(function(b) { b.disabled = !!editing; });
			var canDrag = !!draggable(node) && window.innerWidth > 768;
			box.querySelector('[data-pb-move]').hidden = !canDrag;
			box.querySelector('.pb-canvas-resize').hidden = !canDrag;
			Array.from(box.querySelectorAll('[data-pb-native-action]')).forEach(function(b) { b.hidden = !nativeEditable || !nativeNode(node); });
			position(); if (document.queryCommandState) formatState();
			if (notify && node) send(Object.assign({ type: 'pb_canvas_select', html: node.innerHTML, text: node.textContent, attributes: { src: node.getAttribute('src') || '', alt: node.getAttribute('alt') || '', href: node.getAttribute('href') || '', className: node.getAttribute('class') || '' }, computed: { color: getComputedStyle(node).color, fontSize: getComputedStyle(node).fontSize } }, ref(node)));
		}
		function pointerMatches(event) { return !event || gesture.pointerId == null || event.pointerId == null || gesture.pointerId === event.pointerId; }
		function start(event, resize) {
			if (event.button !== 0 || event.isPrimary === false || !selected || editing || !window.__pbCanvasVisual || gesture) return;
			var node = movable(selected); if (!node) return;
			var parent = node.parentElement, free = parent.classList.contains('pb-freeform');
			if (free && innerWidth <= 768 || resize && !free) return;
			var model = free ? geometry(parent) : null, index = model ? model.elements.findIndex(function(e) { return e.path === node.dataset.pbCanvasPath; }) : -1;
			if (free && index < 0) return;
			event.preventDefault(); event.stopPropagation();
			// Keep the source untouched until the pointer crosses the drag threshold.
			gesture = { flow: !free, active: false, node: node, parent: parent, model: model, index: index,
				initial: model && Object.assign({}, model.elements[index]), parentRect: parent.getBoundingClientRect(), parentScrollX: parent.scrollLeft, parentScrollY: parent.scrollTop,
				startX: event.clientX, startY: event.clientY, lastX: event.clientX, lastY: event.clientY,
				resize: resize, duplicate: event.altKey, oldVisibility: node.style.visibility, oldOpacity: node.style.opacity,
				pointerId: event.pointerId, capture: event.currentTarget instanceof Element ? event.currentTarget : node };
			var g = gesture;
			window.addEventListener('pointermove', motion); window.addEventListener('pointerup', finish); window.addEventListener('pointercancel', cancel); window.addEventListener('blur', cancel);
			g.capture.addEventListener('lostpointercapture', cancel);
			if (g.capture.setPointerCapture && g.pointerId != null) { try { g.capture.setPointerCapture(g.pointerId); } catch(error) {} }
		}
		function activateGesture(g) {
			g.active = true; toolbar.style.visibility = 'hidden';
			if (g.flow) {
				g.line = document.createElement('div'); g.line.className = 'pb-canvas-insertion'; g.line.hidden = true; g.line.setAttribute('aria-hidden','true');
				g.label = document.createElement('div'); g.label.className = 'pb-canvas-drag-label'; g.label.setAttribute('role','status'); g.label.setAttribute('aria-live','polite');
				document.body.append(g.line,g.label); g.node.style.opacity = '.35';
			} else {
				g.ghost = g.node.cloneNode(true);
				// Duplicate ids and native paths must never become selection or script targets.
				[g.ghost].concat(Array.from(g.ghost.querySelectorAll('*'))).forEach(function(node) {
					node.removeAttribute('id'); node.removeAttribute('contenteditable');
					Array.from(node.attributes).forEach(function(attribute) { if (/^data-pb-/.test(attribute.name)) node.removeAttribute(attribute.name); });
				});
				g.ghost.dataset.pbGhost = '1'; g.ghost.setAttribute('aria-hidden','true'); g.ghost.setAttribute('inert','');
				Object.assign(g.ghost.style, { position: 'absolute', gridArea: 'auto', boxSizing: 'border-box', margin: '0',
					left: (g.initial.x + g.model.originX) + 'px', top: (g.initial.y + g.model.originY) + 'px', width: g.initial.w + 'px', height: g.initial.h + 'px',
					minWidth: '0', minHeight: '0', maxWidth: 'none', maxHeight: 'none', pointerEvents: 'none', zIndex: '2147481000', opacity: '.85', transition: 'none', animation: 'none' });
				g.parent.appendChild(g.ghost); if (!g.duplicate) g.node.style.visibility = 'hidden';
				gx = document.createElement('div'); gy = document.createElement('div'); gx.className = gy.className = 'pb-canvas-guide'; g.parent.append(gx,gy);
			}
			scrollFrame = requestAnimationFrame(autoScroll);
		}
		function flowTarget(g) {
			var items = Array.from(g.parent.children).filter(function(n) { return n.dataset.pbCanvasPath && !n.dataset.pbGhost; });
			var rects = items.map(function(item) { return item.getBoundingClientRect(); });
			var horizontal = false, reverse = false;
			// Columns can have very different heights, or stack at narrow widths. Use
			// actual shared rows instead of comparing the first two top-left corners.
			for (var i = 0; i < rects.length - 1; i++) {
				var a = rects[i], b = rects[i + 1];
				if (Math.min(a.bottom,b.bottom) > Math.max(a.top,b.top) && Math.abs(a.left-b.left) > 2) { horizontal = true; reverse = a.left > b.left; break; }
			}
			if (!horizontal && /column-reverse/.test(getComputedStyle(g.parent).flexDirection)) reverse = true;
			var best = null, distance = Infinity;
			items.forEach(function(item,index) {
				if (item === g.node) return;
				var rect = rects[index]; if (!rect.width || !rect.height) return;
				// Distance to the rectangle also respects wrapped columns and rows.
				var dx = Math.max(rect.left-g.lastX,0,g.lastX-rect.right), dy = Math.max(rect.top-g.lastY,0,g.lastY-rect.bottom), d = dx*dx + dy*dy;
				if (d >= distance) return;
				var after = horizontal ? g.lastX >= rect.left + rect.width/2 : g.lastY >= rect.top + rect.height/2;
				if (reverse) after = !after;
				best = { item: item, rect: rect, after: after, horizontal: horizontal, edge: horizontal ? (after !== reverse ? rect.right : rect.left) : (after !== reverse ? rect.bottom : rect.top), index: index };
				distance = d;
			});
			// Returning to the current slot is a no-op, not a history entry.
			if (best) { var from = items.indexOf(g.node), to = best.index + (best.after ? 1 : 0); if (from < to) to--; if (from === to) best = null; }
			return best;
		}
		function paintGesture(g) {
			if (g.flow) {
				var best = flowTarget(g); g.target = best; g.line.hidden = !best;
				if (best) Object.assign(g.line.style,best.horizontal ? {left:(best.edge-1)+'px',top:best.rect.top+'px',width:'3px',height:best.rect.height+'px'} : {left:best.rect.left+'px',top:(best.edge-1)+'px',width:best.rect.width+'px',height:'3px'});
				var label = best ? 'Drop ' + (best.after ? 'after ' : 'before ') + (best.item.dataset.pbCanvasType || 'block').replace('core/','') : 'Drag to reorder';
				if (g.label.textContent !== label) g.label.textContent = label;
				g.label.style.left = Math.max(8,Math.min(g.lastX+14,innerWidth-(g.label.offsetWidth || 160)-8))+'px';
				g.label.style.top = Math.max(8,Math.min(g.lastY+18,innerHeight-(g.label.offsetHeight || 30)-8))+'px';
				return;
			}
			var parentRect = g.parent.getBoundingClientRect(), event = g.lastEvent;
			var dx = g.lastX - g.startX + g.parentRect.left - parentRect.left + g.parent.scrollLeft - g.parentScrollX, dy = g.lastY - g.startY + g.parentRect.top - parentRect.top + g.parent.scrollTop - g.parentScrollY, next = Object.assign({},g.initial);
			if (event.shiftKey && !g.resize) { if (Math.abs(dx) > Math.abs(dy)) dy = 0; else dx = 0; }
			if (g.resize) { next.w = Math.max(40,Math.min(g.model.width-next.x,g.initial.w+dx)); next.h = Math.max(24,g.initial.h+dy); }
			else {
				next.x = Math.max(0,Math.min(g.model.width-next.w,next.x+dx)); next.y = Math.max(0,next.y+dy);
				var snapped = window.gtPbCanvasLayout.snap(next,g.model.elements.filter(function(_,i) { return i !== g.index; }),g.model.width,g.model.height,event.ctrlKey || event.metaKey);
				next.x = Math.max(0,Math.min(g.model.width-next.w,snapped.x)); next.y = Math.max(0,snapped.y);
				Object.assign(gx.style,{left:((snapped.gx || 0)+g.model.originX)+'px',top:g.model.originY+'px',width:'1px',height:g.model.height+'px',display:snapped.gx == null ? 'none' : 'block'});
				Object.assign(gy.style,{left:g.model.originX+'px',top:((snapped.gy || 0)+g.model.originY)+'px',height:'1px',width:g.model.width+'px',display:snapped.gy == null ? 'none' : 'block'});
			}
			g.precise = event.ctrlKey || event.metaKey; g.model.elements[g.index] = next;
			Object.assign(g.ghost.style,{left:(next.x+g.model.originX)+'px',top:(next.y+g.model.originY)+'px',width:next.w+'px',height:next.h+'px'}); queuePosition();
		}
		function motion(event) {
			if (!gesture || !pointerMatches(event)) return;
			var g = gesture; if (!g.node.isConnected || !g.parent.isConnected) { cleanup(); return; }
			g.lastX = event.clientX; g.lastY = event.clientY; g.lastEvent = event;
			if (!g.active && Math.hypot(g.lastX-g.startX,g.lastY-g.startY) < 5) return;
			if (!g.active) activateGesture(g);
			event.preventDefault(); paintGesture(g);
		}
		function autoScroll(time) {
			scrollFrame = null;
			var g = gesture; if (!g || !g.active) return;
			if (!g.node.isConnected) { cancel(); return; }
			var factor = Math.min(2,(time-(g.scrollTime || time-16))/16); g.scrollTime = time;
			function speed(point,low,high) { var edge = Math.min(56,(high-low)/3); if (edge <= 0) return 0; return point < low+edge ? -18*Math.min(1,(low+edge-point)/edge)*factor : point > high-edge ? 18*Math.min(1,(point-high+edge)/edge)*factor : 0; }
			var cursor = g.parent, scrollers = [];
			while (cursor && cursor !== document.body && cursor !== document.documentElement) { var computed = getComputedStyle(cursor); if (/(auto|scroll)/.test(computed.overflow + computed.overflowY + computed.overflowX)) scrollers.push(cursor); cursor = cursor.parentElement; }
			scrollers.push(document.scrollingElement || document.documentElement);
			for (var i = 0; i < scrollers.length; i++) {
				var node = scrollers[i], viewport = i === scrollers.length-1, rect = viewport ? {left:0,top:0,right:innerWidth,bottom:innerHeight} : node.getBoundingClientRect();
				var left = Math.max(0,rect.left), right = Math.min(innerWidth,rect.right), top = Math.max(0,rect.top), bottom = Math.min(innerHeight,rect.bottom);
				if (g.lastX < left-24 || g.lastX > right+24 || g.lastY < top-24 || g.lastY > bottom+24) continue;
				var dx = speed(g.lastX,left,right), dy = speed(g.lastY,top,bottom), oldX = viewport ? window.scrollX : node.scrollLeft, oldY = viewport ? window.scrollY : node.scrollTop;
				var width = viewport ? innerWidth : node.clientWidth, height = viewport ? innerHeight : node.clientHeight;
				dx = Math.max(-oldX,Math.min(node.scrollWidth-width-oldX,dx)); dy = Math.max(-oldY,Math.min(node.scrollHeight-height-oldY,dy));
				if (Math.abs(dx) < .1 && Math.abs(dy) < .1) continue;
				if (viewport) window.scrollBy(dx,dy); else { node.scrollLeft += dx; node.scrollTop += dy; }
				paintGesture(g); queuePosition(); break;
			}
			scrollFrame = requestAnimationFrame(autoScroll);
		}
		function cleanup() {
			if (!gesture) return;
			var g = gesture; gesture = null;
			if (scrollFrame != null) { cancelAnimationFrame(scrollFrame); scrollFrame = null; }
			if (g.active) {
				suppressClickUntil = Date.now()+250; toolbar.style.visibility = '';
				if (g.flow) { g.node.style.opacity = g.oldOpacity; g.line.remove(); g.label.remove(); }
				else { g.node.style.visibility = g.oldVisibility; g.ghost.remove(); gx.remove(); gy.remove(); gx = gy = null; }
			}
			window.removeEventListener('pointermove',motion); window.removeEventListener('pointerup',finish); window.removeEventListener('pointercancel',cancel); window.removeEventListener('blur',cancel);
			g.capture.removeEventListener('lostpointercapture',cancel);
			if (g.capture.releasePointerCapture && g.pointerId != null) { try { g.capture.releasePointerCapture(g.pointerId); } catch(error) {} }
			position();
		}
		function finish(event) {
			if (!gesture || !pointerMatches(event)) return;
			var g = gesture;
			if (!g.node.isConnected || !g.parent.isConnected) { cleanup(); return; }
			if (g.active && g.flow && g.target) send({type:'pb_canvas_reorder',sectionUid:section(g.node).dataset.pbSection,parentPath:g.parent.dataset.pbCanvasPath,path:g.node.dataset.pbCanvasPath,targetPath:g.target.item.dataset.pbCanvasPath,after:g.target.after});
			if (g.active && !g.flow && JSON.stringify(g.initial) !== JSON.stringify(g.model.elements[g.index])) send(Object.assign({type:'pb_canvas_layout',changedPath:g.initial.path,original:g.initial,duplicate:g.duplicate,precise:g.precise},g.model));
			cleanup();
		}
		function cancel(event) { if (gesture && pointerMatches(event)) cleanup(); }
		function startEditing(node) {
			var text = editable(node); if (!text) return false;
			if (editing === text) return true;
			if (endEditing) endEditing(false);
			var before = text.innerHTML, reference = ref(nativeNode(text) || text), previousEditable = text.getAttribute('contenteditable');
			editing = text; text.contentEditable = 'true'; text.focus(); select(nativeNode(text) || text,false);
			function key(event) {
				if (event.isComposing) return;
				if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); finishEdit(true); }
				else if (event.key === 'Enter' && !event.shiftKey && text.tagName !== 'P') { event.preventDefault(); finishEdit(false); }
			}
			function blur() { finishEdit(false); }
			function finishEdit(cancelled) {
				if (editing !== text) return;
				if (cancelled) text.innerHTML = before;
				text.removeEventListener('keydown',key); text.removeEventListener('blur',blur); text.contentEditable = 'inherit'; if (previousEditable === null) text.removeAttribute('contenteditable'); else text.setAttribute('contenteditable',previousEditable); editing = null; endEditing = null; text.blur(); select(selected,false);
				if (!cancelled && text.innerHTML !== before) send(Object.assign({ type:'pb_canvas_text',oldHtml:before,newHtml:text.innerHTML,text:text.textContent },reference));
			}
			endEditing = finishEdit; text.addEventListener('keydown',key); text.addEventListener('blur',blur); return true;
		}
		document.addEventListener('pointerdown',function(event) {
			if (!window.__pbCanvasVisual || !selected || editing || event.pointerType === 'touch' || event.target.closest('.pb-canvas-box,a,button,input,textarea,select,video,audio,[contenteditable]')) return;
			var node = nativeNode(event.target);
			if (node === selected && !editable(node) && (event.target === node || node.dataset.pbCanvasType === 'core/image')) start(event,false);
		},true);
		document.addEventListener('dragstart',function(event) { if (window.__pbCanvasVisual && !editing && nativeNode(event.target)) event.preventDefault(); },true);
		document.addEventListener('click', function(event) {
			if (Date.now() < suppressClickUntil) { suppressClickUntil=0; event.preventDefault(); event.stopPropagation(); return; }
			if (!window.__pbCanvasVisual || event.target.isContentEditable || event.target.closest('.pb-canvas-box')) return;
			var node = nativeNode(event.target) || event.target.closest('h1,h2,h3,h4,h5,h6,p,li,a,img,button,section,div');
			if (node && section(node)) { if (['core/audio','core/video'].includes(node.dataset.pbCanvasType)) { select(node,true); return; } event.preventDefault(); event.stopPropagation(); select(node, true); }
			else { select(null,false); send({type:'pb_canvas_clear'}); }
		}, true);
		document.addEventListener('dblclick', function(event) {
			if (!window.__pbCanvasVisual) return;
			if (editable(event.target)) { event.preventDefault(); event.stopPropagation(); startEditing(event.target); }
		}, true);
		document.addEventListener('submit', function(event) { if (window.__pbCanvasVisual) event.preventDefault(); }, true);
		function filesDragged(event) { return event.dataTransfer && Array.from(event.dataTransfer.types || []).includes('Files'); }
		document.addEventListener('dragover',function(event) { if (filesDragged(event)) { event.preventDefault(); event.dataTransfer.dropEffect=window.__pbCanvasVisual ? 'copy' : 'none'; } },true);
		document.addEventListener('drop',function(event) { if (!filesDragged(event)) return; event.preventDefault(); event.stopPropagation(); if (!window.__pbCanvasVisual) { send({type:'pb_canvas_files_disabled'}); return; }
			var files=Array.from(event.dataTransfer.files || []),sec=section(event.target),node=nativeNode(event.target),container=node;
			while (container && !['core/group','core/column'].includes(container.dataset.pbCanvasType)) container=nativeNode(container.parentElement);
			var before=null,after=false,point=null; if (container) { var direct=node; while (direct && direct.parentElement !== container) direct=nativeNode(direct.parentElement); if (direct && direct !== container) { before=direct.dataset.pbCanvasPath; var r=direct.getBoundingClientRect(); after=event.clientY >= r.top + r.height / 2; } var g=geometry(container),r=container.getBoundingClientRect(); point={x:Math.max(0,event.clientX-r.left-g.originX),y:Math.max(0,event.clientY-r.top-g.originY)}; }
			send({type:'pb_canvas_files',files:files,sectionUid:sec && sec.dataset.pbSection,containerPath:container && container.dataset.pbCanvasPath,targetPath:before,after:after,point:point});
		},true);
		document.addEventListener('keydown', function(event) {
			if (!window.__pbCanvasVisual) return;
			var meta = event.metaKey || event.ctrlKey;
			if (editing && meta && event.key.toLowerCase() === 'k') { event.preventDefault(); format('createLink'); return; }
			if (meta && event.key.toLowerCase() === 's') { event.preventDefault(); if (event.target.isContentEditable) event.target.blur(); send({ type: 'pb_canvas_save' }); return; }
			if (event.target.isContentEditable || event.target.closest('input,textarea,select')) return;
			if (event.key === 'Escape') { event.preventDefault(); if (gesture) cancel(); else { select(null,false); send({type:'pb_canvas_clear'}); } return; }
			if (meta && event.key.toLowerCase() === 'z') { event.preventDefault(); send({ type: 'pb_canvas_history', redo: event.shiftKey }); return; }
			if (!selected) return;
			if (event.key === 'Enter' && !event.target.closest('.pb-canvas-box')) { event.preventDefault(); startEditing(selected); return; }
			if (event.key === 'Delete' || event.key === 'Backspace') { event.preventDefault(); if (nativeNode(selected)) send(Object.assign({ type: 'pb_canvas_action', action: 'delete' }, ref(selected))); return; }
			if (!/^Arrow/.test(event.key) || !draggable(selected) || window.innerWidth <= 768) return;
			event.preventDefault(); var item = draggable(selected), model = geometry(item.parentElement), e = model.elements.find(function(e) { return e.path === item.dataset.pbCanvasPath; }), step = event.shiftKey ? 10 : 1;
			if (event.key === 'ArrowLeft') e.x = Math.max(0, e.x - step); if (event.key === 'ArrowRight') e.x = Math.min(model.width - e.w, e.x + step);
			if (event.key === 'ArrowUp') e.y = Math.max(0, e.y - step); if (event.key === 'ArrowDown') e.y += step;
			send(Object.assign({ type: 'pb_canvas_layout', precise: true }, model));
		});
		window.addEventListener('message', function(event) {
			if (event.source !== window.parent || event.origin !== window.parent.location.origin || !event.data) return;
			var m = event.data;
			if (m.type === 'pb_canvas_mode') { window.__pbCanvasVisual = !!m.enabled; window.__pbCanvasWorkspaceVisual = !!m.enabled || !!m.paused; nativeEditable=m.nativeEditable !== false; editableSections = new Set(m.editableSections || []); document.body.classList.toggle('pb-canvas-grid', !!m.grid); if (!m.enabled) { if (endEditing) endEditing(false); cancel(); } position(); }
			if (m.type === 'pb_canvas_clear') { cancel(); if (endEditing) endEditing(false); select(null,false); }
			if (m.type === 'pb_canvas_measure') {
				var parent = document.querySelector('[data-pb-canvas-section="' + m.sectionUid + '"][data-pb-canvas-path="' + m.path + '"]');
				if (parent) send(Object.assign({ type: 'pb_canvas_measure_result' }, geometry(parent)));
			}
			if (m.type === 'pb_canvas_select_native') {
				var found = document.querySelector('[data-pb-canvas-section="' + m.sectionUid + '"][data-pb-canvas-path="' + m.path + '"]');
				if (found) select(found, false);
			}
			if (m.type === 'pb_canvas_select_source' && Array.isArray(m.path) && m.path.length) {
				var found=document.querySelector('[data-pb-section="' + m.sectionUid + '"][data-pb-root-index="' + m.path[0] + '"]');
				for (var i=1;found && i<m.path.length;i++) found=found.children[m.path[i]];
				if (found && found.tagName.toLowerCase() === m.tagName) select(found,window.__pbCanvasVisual);
				else { select(null,false); send({type:'pb_canvas_clear'}); }
			}
		});
		window.addEventListener('scroll', function() { if (gesture && gesture.active) paintGesture(gesture); queuePosition(); }, true); window.addEventListener('resize', function() { queuePosition(); send({type:'pb_canvas_viewport',width:innerWidth}); });
		document.addEventListener('input',function() { if (editing) queuePosition(); });
		document.addEventListener('selectionchange',function() { if (document.queryCommandState) formatState(); });
		send({type:'pb_canvas_viewport',width:innerWidth});
	}
	root.gtPbCanvasBridge = { script: function(icons, enabled) { return '(' + bridge.toString() + ')(' + JSON.stringify(icons || {}) + ',' + !!enabled + ');'; } };
})(typeof window !== 'undefined' ? window : this);
