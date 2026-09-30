/* Preview-only canvas chrome. Authored HTML receives no editor wrappers. */
(function(root) {
	'use strict';
	function bridge(icons, enabled) {
		var selected = null, editing = null, box, toolbar, gesture = null, gx, gy;
		window.__pbCanvasVisual = enabled;
		var style = document.createElement('style');
		style.textContent = '.pb-canvas-box{position:fixed;z-index:2147483000;pointer-events:none;border:2px solid #3858e9;box-sizing:border-box}.pb-canvas-tools{position:absolute;left:-2px;bottom:calc(100% + 6px);display:flex;gap:2px;background:#fff;border:1px solid #dcdcde;border-radius:5px;box-shadow:0 4px 16px #0002;padding:3px;font:12px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;white-space:nowrap}.pb-canvas-tools button,.pb-canvas-resize{all:unset;box-sizing:border-box;pointer-events:auto;cursor:pointer;display:flex;align-items:center;gap:5px;color:#1e1e1e;padding:5px 7px;border-radius:3px;font:12px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif}.pb-canvas-tools button:hover{background:#f0f3ff}.pb-canvas-tools svg{width:15px;height:15px;flex-shrink:0}.pb-canvas-tools button:focus-visible{outline:2px solid #3858e9}.pb-canvas-resize{position:absolute;right:-6px;bottom:-6px;width:10px;height:10px;background:#fff;border:2px solid #3858e9;padding:0;cursor:nwse-resize}.pb-canvas-guide{position:absolute;background:#e74b79;pointer-events:none;z-index:2147482000}.pb-canvas-grid .pb-freeform{background-image:linear-gradient(to right,#3858e912 1px,transparent 1px),linear-gradient(to bottom,#3858e912 1px,transparent 1px);background-size:24px 24px}.pb-freeform:empty{min-height:240px}.pb-canvas-box[hidden],.pb-canvas-tools button[hidden],.pb-canvas-resize[hidden]{display:none}';
		document.head.appendChild(style);
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
		function draggable(node) { return node && node.closest('.pb-freeform > [data-pb-canvas-path]'); }
		function geometry(parent) {
			var rect = parent.getBoundingClientRect();
			return { rootPath: parent.dataset.pbCanvasPath, sectionUid: section(parent).dataset.pbSection, width: rect.width, height: rect.height,
				elements: Array.prototype.filter.call(parent.children, function(n) { return n.dataset.pbCanvasPath && !n.dataset.pbGhost; }).map(function(n) {
					var r = n.getBoundingClientRect();
					return { path: n.dataset.pbCanvasPath, key: Array.from(n.classList).find(function(c) { return /^pb-node-[a-z0-9]+$/.test(c); }) || '', type: n.dataset.pbCanvasType, x: r.left - rect.left, y: r.top - rect.top, w: r.width, h: r.height };
				}) };
		}
		function position() {
			if (!selected || !selected.isConnected || !window.__pbCanvasVisual) { if (box) box.hidden = true; return; }
			var rect = (gesture ? gesture.ghost : selected).getBoundingClientRect();
			var canArrange = !!draggable(selected) && window.innerWidth > 768;
			box.querySelector('[data-pb-move]').hidden = !canArrange; box.querySelector('.pb-canvas-resize').hidden = !canArrange;
			box.hidden = false;
			Object.assign(box.style, { left: rect.left + 'px', top: rect.top + 'px', width: rect.width + 'px', height: rect.height + 'px' });
			toolbar.style.bottom = rect.top < 42 ? 'auto' : 'calc(100% + 6px)';
			toolbar.style.top = rect.top < 42 ? 'calc(100% + 6px)' : 'auto';
		}
		function format(command) {
			if (!editing) return;
			var value = null;
			if (command === 'createLink') { value = window.prompt('Link URL', 'https://'); if (!value) return; try { if (!['http:','https:','mailto:','tel:'].includes(new URL(value,window.parent.location.href).protocol)) return; } catch(error) { return; } }
			document.execCommand('styleWithCSS',false,false); document.execCommand(command,false,value);
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
				[['Bold','bold','bold'],['Italic','italic','italic'],['Link','link','createLink']].forEach(function(f) { var b=button(f[0],f[1],function() { format(f[2]); }); b.dataset.pbFormat='1'; b.addEventListener('mousedown',function(event) { event.preventDefault(); }); });
				var move = button('Move', 'arrows-move', function() {}); move.dataset.pbMove = '1'; move.addEventListener('pointerdown', function(e) { start(e, false); });
				button('Edit', 'click', function() { if (selected) send(Object.assign({ type: 'pb_canvas_select' }, ref(selected))); });
				button('Duplicate', 'copy', function() { if (selected) send(Object.assign({ type: 'pb_canvas_action', action: 'duplicate' }, ref(selected))); }).dataset.pbNativeAction = '1';
				button('Delete', 'trash', function() { if (selected) send(Object.assign({ type: 'pb_canvas_action', action: 'delete' }, ref(selected))); }).dataset.pbNativeAction = '1';
				var resize = document.createElement('button'); resize.type = 'button'; resize.className = 'pb-canvas-resize'; resize.title = 'Resize element'; resize.setAttribute('aria-label', 'Resize element'); resize.addEventListener('pointerdown', function(e) { start(e, true); });
				box.append(toolbar, resize); document.body.appendChild(box);
			}
			Array.from(box.querySelectorAll('[data-pb-format]')).forEach(function(b) { b.hidden = !editing; });
			var canDrag = !!draggable(node) && window.innerWidth > 768;
			box.querySelector('[data-pb-move]').hidden = !canDrag;
			box.querySelector('.pb-canvas-resize').hidden = !canDrag;
			Array.from(box.querySelectorAll('[data-pb-native-action]')).forEach(function(b) { b.hidden = !nativeNode(node); });
			position();
			if (notify && node) send(Object.assign({ type: 'pb_canvas_select', html: node.innerHTML, text: node.textContent, attributes: { src: node.getAttribute('src') || '', alt: node.getAttribute('alt') || '', href: node.getAttribute('href') || '', className: node.getAttribute('class') || '' }, computed: { color: getComputedStyle(node).color, fontSize: getComputedStyle(node).fontSize } }, ref(node)));
		}
		function start(event, resize) {
			if (event.button !== 0 || !selected || window.innerWidth <= 768) return;
			var node = draggable(selected); if (!node) return;
			var parent = node.parentElement, model = geometry(parent), index = model.elements.findIndex(function(e) { return e.path === node.dataset.pbCanvasPath; });
			if (index < 0) return;
			event.preventDefault(); event.stopPropagation();
			var ghost = node.cloneNode(true); ghost.dataset.pbGhost = '1'; ghost.removeAttribute('id');
			Object.assign(ghost.style, { position: 'absolute', margin: '0', left: model.elements[index].x + 'px', top: model.elements[index].y + 'px', width: model.elements[index].w + 'px', height: model.elements[index].h + 'px', pointerEvents: 'none', zIndex: '2147481000', opacity: '.85' });
			parent.appendChild(ghost);
			gesture = { node: node, parent: parent, ghost: ghost, model: model, index: index, initial: Object.assign({}, model.elements[index]), startX: event.clientX, startY: event.clientY, resize: resize, duplicate: event.altKey, oldVisibility: node.style.visibility };
			if (!event.altKey) node.style.visibility = 'hidden';
			gx = document.createElement('div'); gy = document.createElement('div'); gx.className = gy.className = 'pb-canvas-guide'; parent.append(gx, gy);
			window.addEventListener('pointermove', motion); window.addEventListener('pointerup', finish); window.addEventListener('pointercancel', cancel);
		}
		function motion(event) {
			if (!gesture) return;
			var g = gesture, dx = event.clientX - g.startX, dy = event.clientY - g.startY, next = Object.assign({}, g.initial);
			if (event.shiftKey && !g.resize) { if (Math.abs(dx) > Math.abs(dy)) dy = 0; else dx = 0; }
			if (g.resize) { next.w = Math.max(40, Math.min(g.model.width - next.x, g.initial.w + dx)); next.h = Math.max(24, g.initial.h + dy); }
			else {
				next.x = Math.max(0, Math.min(g.model.width - next.w, next.x + dx)); next.y = Math.max(0, next.y + dy);
				var snapped = window.gtPbCanvasLayout.snap(next, g.model.elements.filter(function(_, i) { return i !== g.index; }), g.model.width, g.model.height, event.ctrlKey || event.metaKey);
				next.x = Math.max(0, Math.min(g.model.width - next.w, snapped.x)); next.y = Math.max(0, snapped.y);
				Object.assign(gx.style, { left: (snapped.gx || 0) + 'px', top: '0', width: '1px', height: g.model.height + 'px', display: snapped.gx == null ? 'none' : 'block' });
				Object.assign(gy.style, { left: '0', top: (snapped.gy || 0) + 'px', height: '1px', width: g.model.width + 'px', display: snapped.gy == null ? 'none' : 'block' });
			}
			g.precise = event.ctrlKey || event.metaKey;
			g.model.elements[g.index] = next;
			Object.assign(g.ghost.style, { left: next.x + 'px', top: next.y + 'px', width: next.w + 'px', height: next.h + 'px' }); position();
		}
		function cleanup() {
			if (!gesture) return;
			gesture.node.style.visibility = gesture.oldVisibility; gesture.ghost.remove(); gx.remove(); gy.remove(); gesture = null;
			window.removeEventListener('pointermove', motion); window.removeEventListener('pointerup', finish); window.removeEventListener('pointercancel', cancel); position();
		}
		function finish() {
			if (!gesture) return;
			var g = gesture;
			if (JSON.stringify(g.initial) !== JSON.stringify(g.model.elements[g.index])) send(Object.assign({ type: 'pb_canvas_layout', changedPath: g.initial.path, original: g.initial, duplicate: g.duplicate, precise: g.precise }, g.model));
			cleanup();
		}
		function cancel() { cleanup(); }
		document.addEventListener('click', function(event) {
			if (!window.__pbCanvasVisual || event.target.isContentEditable || event.target.closest('.pb-canvas-box')) return;
			var node = nativeNode(event.target) || event.target.closest('h1,h2,h3,h4,h5,h6,p,li,a,img,button,section,div');
			if (node && section(node)) { event.preventDefault(); event.stopPropagation(); select(node, true); }
		}, true);
		document.addEventListener('dblclick', function(event) {
			if (!window.__pbCanvasVisual) return;
			var node = event.target.closest('h1,h2,h3,h4,h5,h6,p,li,a');
			if (!node || !section(node) || node.querySelector('div,section,article,ul,ol,table,form')) return;
			event.preventDefault(); event.stopPropagation();
			var before = node.innerHTML, reference = ref(nativeNode(node) || node);
			editing = node; node.contentEditable = 'true'; node.focus(); select(nativeNode(node) || node,false);
			node.addEventListener('blur', function() {
				node.contentEditable = 'false'; editing = null; select(selected,false);
				if (node.innerHTML !== before) send(Object.assign({ type: 'pb_canvas_text', oldHtml: before, newHtml: node.innerHTML, text: node.textContent }, reference));
			}, { once: true });
			node.addEventListener('keydown', function end(event) {
				if (event.key === 'Escape') { node.innerHTML = before; node.blur(); node.removeEventListener('keydown', end); }
				if (event.key === 'Enter' && !event.shiftKey && node.tagName !== 'P') { event.preventDefault(); node.blur(); node.removeEventListener('keydown', end); }
			});
		}, true);
		document.addEventListener('submit', function(event) { if (window.__pbCanvasVisual) event.preventDefault(); }, true);
		document.addEventListener('keydown', function(event) {
			if (!window.__pbCanvasVisual) return;
			var meta = event.metaKey || event.ctrlKey;
			if (editing && meta && event.key.toLowerCase() === 'k') { event.preventDefault(); format('createLink'); return; }
			if (meta && event.key.toLowerCase() === 's') { event.preventDefault(); if (event.target.isContentEditable) event.target.blur(); send({ type: 'pb_canvas_save' }); return; }
			if (event.target.isContentEditable || event.target.closest('input,textarea,select')) return;
			if (meta && event.key.toLowerCase() === 'z') { event.preventDefault(); send({ type: 'pb_canvas_history', redo: event.shiftKey }); return; }
			if (!selected) return;
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
			if (m.type === 'pb_canvas_mode') { window.__pbCanvasVisual = !!m.enabled; document.body.classList.toggle('pb-canvas-grid', !!m.grid); if (!m.enabled) cancel(); position(); }
			if (m.type === 'pb_canvas_measure') {
				var parent = document.querySelector('[data-pb-canvas-section="' + m.sectionUid + '"][data-pb-canvas-path="' + m.path + '"]');
				if (parent) send(Object.assign({ type: 'pb_canvas_measure_result' }, geometry(parent)));
			}
			if (m.type === 'pb_canvas_select_native') {
				var found = document.querySelector('[data-pb-canvas-section="' + m.sectionUid + '"][data-pb-canvas-path="' + m.path + '"]');
				if (found) select(found, false);
			}
		});
		window.addEventListener('scroll', position, true); window.addEventListener('resize', position);
	}
	root.gtPbCanvasBridge = { script: function(icons, enabled) { return '(' + bridge.toString() + ')(' + JSON.stringify(icons || {}) + ',' + !!enabled + ');'; } };
})(typeof window !== 'undefined' ? window : this);
