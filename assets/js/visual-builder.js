/* Structured visual sections and their editor controls. No framework or build step. */
(function(root, factory) {
	if (typeof module === 'object' && module.exports) module.exports = factory();
	else root.gtPbVisualBuilder = factory();
})(typeof window !== 'undefined' ? window : this, function() {
	'use strict';

	var CONTAINERS = ['section', 'container', 'columns'];
	var LABELS = { section: 'Section', container: 'Container', columns: 'Columns', heading: 'Heading', text: 'Text', image: 'Image', button: 'Button' };
	var STYLE_FIELDS = [
		['padding', 'Padding', '64px 24px'], ['margin', 'Margin', '0 auto'],
		['gap', 'Gap', '24px'], ['width', 'Width', '100%'], ['maxWidth', 'Max width', '1200px'],
		['minHeight', 'Min height', '300px'], ['fontSize', 'Font size', '32px'],
		['color', 'Text color', '#1e1e1e or var(--token)'],
		['backgroundColor', 'Background', '#ffffff or var(--token)'],
		['borderRadius', 'Corner radius', '8px']
	];

	function id() {
		var bytes = new Uint8Array(8);
		if (typeof crypto !== 'undefined' && crypto.getRandomValues) crypto.getRandomValues(bytes);
		else for (var i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
		return 'v' + Array.from(bytes).map(function(n) { return n.toString(16).padStart(2, '0'); }).join('');
	}

	function createNode(type) {
		var props = {};
		if (type === 'heading') props = { text: 'Your heading', level: 2 };
		if (type === 'text') props = { text: 'Add your text here.' };
		if (type === 'button') props = { text: 'Button', url: '#' };
		if (type === 'image') props = { attachmentId: 0, url: '', alt: '' };
		return { id: id(), type: type, props: props, styles: { desktop: {}, tablet: {}, mobile: {} }, children: [] };
	}

	function createData() {
		var section = createNode('section');
		var container = createNode('container');
		container.children.push(createNode('heading'), createNode('text'));
		section.children.push(container);
		return { version: 1, root: section };
	}

	function clone(data) { return JSON.parse(JSON.stringify(data)); }

	function normalizeData(data) {
		if (!data || !data.root) return data;
		var next = clone(data);
		function visit(node) {
			if (!node.props || Array.isArray(node.props)) node.props = {};
			if (!node.styles || Array.isArray(node.styles)) node.styles = {};
			['desktop', 'tablet', 'mobile'].forEach(function(viewport) {
				if (!node.styles[viewport] || Array.isArray(node.styles[viewport])) node.styles[viewport] = {};
			});
			if (!Array.isArray(node.children)) node.children = [];
			node.children.forEach(visit);
		}
		visit(next.root);
		return next;
	}

	function locate(data, nodeId) {
		function visit(node, parent, index) {
			if (node.id === nodeId) return { node: node, parent: parent, index: index };
			for (var i = 0; i < (node.children || []).length; i++) {
				var found = visit(node.children[i], node, i);
				if (found) return found;
			}
			return null;
		}
		return data && data.root ? visit(data.root, null, -1) : null;
	}

	function add(data, type, selectedId) {
		if (!LABELS[type] || type === 'section') return null;
		var next = clone(data), target = locate(next, selectedId) || { node: next.root };
		var added = createNode(type);
		if (type === 'container') added.children.push(createNode('text'));
		if (type === 'columns') {
			for (var i = 0; i < 2; i++) {
				var column = createNode('container');
				column.children.push(createNode('text'));
				added.children.push(column);
			}
		}
		if (CONTAINERS.indexOf(target.node.type) !== -1) target.node.children.push(added);
		else if (target.parent) target.parent.children.splice(target.index + 1, 0, added);
		else return null;
		return { data: next, id: added.id };
	}

	function contains(node, targetId) {
		if (node.id === targetId) return true;
		return (node.children || []).some(function(child) { return contains(child, targetId); });
	}

	function move(data, sourceId, targetId, placement) {
		if (sourceId === targetId || ['before', 'after', 'inside'].indexOf(placement) === -1) return null;
		var next = clone(data), source = locate(next, sourceId), target = locate(next, targetId);
		if (!source || !source.parent || !target || contains(source.node, targetId)) return null;
		if (placement === 'inside' && CONTAINERS.indexOf(target.node.type) === -1) return null;
		if (placement !== 'inside' && !target.parent) return null;
		var moved = source.parent.children.splice(source.index, 1)[0];
		target = locate(next, targetId);
		if (placement === 'inside') target.node.children.push(moved);
		else target.parent.children.splice(target.index + (placement === 'after' ? 1 : 0), 0, moved);
		return next;
	}

	function remove(data, nodeId) {
		var next = clone(data), target = locate(next, nodeId);
		if (!target || !target.parent) return null;
		target.parent.children.splice(target.index, 1);
		return next;
	}

	function setValue(data, nodeId, group, key, value) {
		var next = clone(data), target = locate(next, nodeId);
		if (!target) return null;
		if (group === 'props') target.node.props[key] = value;
		else {
			if (!target.node.styles[group] || Array.isArray(target.node.styles[group])) target.node.styles[group] = {};
			if (value === '') delete target.node.styles[group][key];
			else target.node.styles[group][key] = value;
		}
		return next;
	}

	function regenerateIds(node) {
		node.id = id();
		(node.children || []).forEach(regenerateIds);
	}

	function duplicate(data, nodeId) {
		var next = clone(data), target = locate(next, nodeId);
		if (!target || !target.parent) return null;
		var copy = clone(target.node);
		regenerateIds(copy);
		target.parent.children.splice(target.index + 1, 0, copy);
		return { data: next, id: copy.id };
	}

	function control(doc, label, value, onChange, options) {
		var wrap = doc.createElement('label');
		wrap.className = 'md-pb-v-field';
		var caption = doc.createElement('span');
		caption.textContent = label;
		wrap.appendChild(caption);
		var input;
		if (options && options.choices) {
			input = doc.createElement('select');
			options.choices.forEach(function(pair) {
				var option = doc.createElement('option');
				option.value = String(pair[0]);
				option.textContent = pair[1];
				input.appendChild(option);
			});
		} else if (options && options.multiline) input = doc.createElement('textarea');
		else input = doc.createElement('input');
		input.value = value == null ? '' : String(value);
		if (options && options.placeholder) input.placeholder = options.placeholder;
		input.addEventListener('change', function() { onChange(input.value); });
		wrap.appendChild(input);
		return wrap;
	}

	function makeButton(doc, text, action, className) {
		var button = doc.createElement('button');
		button.type = 'button';
		button.className = className || 'md-pb-v-button';
		button.textContent = text;
		button.addEventListener('click', action);
		return button;
	}

	function createEditor(options) {
		var doc = options.container.ownerDocument;
		var panel = doc.createElement('div');
		panel.className = 'md-pb-v-panel';
		options.container.appendChild(panel);
		var selectedId = '';
		var breakpoint = 'desktop';
		var dragId = '';

		function section() { return options.getSection(); }
		function data() { var s = section(); return s && s.visualData && s.visualData.root ? s.visualData : null; }
		function change(next, checkpoint) {
			if (!next) return;
			options.onChange(next, checkpoint !== false);
			render();
		}
		function select(nodeId) {
			selectedId = nodeId;
			render();
			syncSelection();
		}
		function syncSelection() {
			var frame = options.getFrame();
			if (!frame || !frame.contentDocument) return;
			var nodes = frame.contentDocument.querySelectorAll('[data-pb-v-id]');
			Array.prototype.forEach.call(nodes, function(node) {
				node.classList.toggle('gt-pb-v-selected', node.getAttribute('data-pb-v-id') === selectedId);
			});
		}
		function property(node, key, value) { change(setValue(data(), node.id, 'props', key, value)); }
		function style(node, key, value) { change(setValue(data(), node.id, breakpoint, key, value)); }

		function render() {
			panel.replaceChildren();
			var model = data();
			panel.hidden = !model;
			if (!model) return;
			if (!locate(model, selectedId)) selectedId = model.root.id;
			var selected = locate(model, selectedId).node;
			var header = doc.createElement('div');
			header.className = 'md-pb-v-header';
			header.innerHTML = '<strong>Visual editor</strong><small>Select an element on the page or in Layers</small>';
			panel.appendChild(header);

			var addTitle = doc.createElement('h3'); addTitle.textContent = 'Add element'; panel.appendChild(addTitle);
			var palette = doc.createElement('div'); palette.className = 'md-pb-v-palette';
			['container', 'columns', 'heading', 'text', 'image', 'button'].forEach(function(type) {
				palette.appendChild(makeButton(doc, '+ ' + LABELS[type], function() {
					var result = add(data(), type, selectedId);
					if (result) { selectedId = result.id; change(result.data); }
				}));
			});
			panel.appendChild(palette);

			var layerTitle = doc.createElement('h3'); layerTitle.textContent = 'Layers'; panel.appendChild(layerTitle);
			var layers = doc.createElement('div'); layers.className = 'md-pb-v-layers';
			function row(node, depth) {
				var item = makeButton(doc, LABELS[node.type] + (node.props.text ? ' · ' + node.props.text.slice(0, 22) : ''), function() { select(node.id); }, 'md-pb-v-layer' + (node.id === selectedId ? ' is-selected' : ''));
				item.style.paddingLeft = (8 + depth * 14) + 'px';
				item.draggable = !!depth;
				item.addEventListener('dragstart', function(event) { dragId = node.id; event.dataTransfer.setData('text/plain', node.id); });
				item.addEventListener('dragover', function(event) { event.preventDefault(); item.classList.add('is-drop-target'); });
				item.addEventListener('dragleave', function() { item.classList.remove('is-drop-target'); });
				item.addEventListener('drop', function(event) {
					event.preventDefault(); item.classList.remove('is-drop-target');
					var source = dragId || event.dataTransfer.getData('text/plain');
					var placement = CONTAINERS.indexOf(node.type) !== -1 && event.offsetX > 50 ? 'inside' : 'after';
					var next = move(data(), source, node.id, placement);
					if (next) change(next);
					dragId = '';
				});
				layers.appendChild(item);
				(node.children || []).forEach(function(child) { row(child, depth + 1); });
			}
			row(model.root, 0);
			panel.appendChild(layers);

			var activeTitle = doc.createElement('h3'); activeTitle.textContent = LABELS[selected.type]; panel.appendChild(activeTitle);
			var actions = doc.createElement('div'); actions.className = 'md-pb-v-actions';
			if (selected.type !== 'section') {
				var location = locate(model, selectedId);
				if (location.index > 0) actions.appendChild(makeButton(doc, 'Move up', function() {
					change(move(data(), selectedId, location.parent.children[location.index - 1].id, 'before'));
				}));
				if (location.index < location.parent.children.length - 1) actions.appendChild(makeButton(doc, 'Move down', function() {
					change(move(data(), selectedId, location.parent.children[location.index + 1].id, 'after'));
				}));
				actions.appendChild(makeButton(doc, 'Duplicate', function() {
					var result = duplicate(data(), selectedId);
					if (result) { selectedId = result.id; change(result.data); }
				}));
				actions.appendChild(makeButton(doc, 'Delete', function() {
					if (!window.confirm('Delete this element?')) return;
					var next = remove(data(), selectedId);
					if (next) { selectedId = next.root.id; change(next); }
				}));
			}
			panel.appendChild(actions);

			if (selected.type === 'heading' || selected.type === 'text' || selected.type === 'button') {
				panel.appendChild(control(doc, 'Text', selected.props.text, function(value) { property(selected, 'text', value); }, { multiline: selected.type === 'text' }));
			}
			if (selected.type === 'heading') panel.appendChild(control(doc, 'Heading level', selected.props.level, function(value) { property(selected, 'level', Number(value)); }, { choices: [1,2,3,4,5,6].map(function(n) { return [n, 'H' + n]; }) }));
			if (selected.type === 'button') panel.appendChild(control(doc, 'Link URL', selected.props.url, function(value) { property(selected, 'url', value); }, { placeholder: 'https://example.com/' }));
			if (selected.type === 'image') {
				panel.appendChild(makeButton(doc, 'Choose from Media Library', function() {
					if (!window.wp || !window.wp.media) { window.alert('The WordPress media picker is unavailable. Paste an image URL below.'); return; }
					var picker = window.wp.media({ title: 'Choose image', button: { text: 'Use image' }, library: { type: 'image' }, multiple: false });
					picker.on('select', function() {
						var image = picker.state().get('selection').first().toJSON();
						var next = setValue(data(), selected.id, 'props', 'attachmentId', image.id || 0);
						next = setValue(next, selected.id, 'props', 'url', image.url || '');
						next = setValue(next, selected.id, 'props', 'alt', image.alt || '');
						change(next);
					});
					picker.open();
				}));
				panel.appendChild(control(doc, 'Image URL', selected.props.url, function(value) {
					var next = setValue(data(), selected.id, 'props', 'attachmentId', 0);
					change(setValue(next, selected.id, 'props', 'url', value));
				}, { placeholder: 'https://example.com/image.jpg' }));
				panel.appendChild(control(doc, 'Alt text', selected.props.alt, function(value) { property(selected, 'alt', value); }));
			}

			var styleTitle = doc.createElement('h3'); styleTitle.textContent = 'Style'; panel.appendChild(styleTitle);
			panel.appendChild(control(doc, 'Viewport', breakpoint, function(value) { breakpoint = value; render(); }, { choices: [['desktop','Desktop'],['tablet','Tablet ≤768px'],['mobile','Mobile ≤480px']] }));
			var values = (selected.styles || {})[breakpoint] || {};
			STYLE_FIELDS.forEach(function(field) {
				panel.appendChild(control(doc, field[1], values[field[0]] || '', function(value) { style(selected, field[0], value.trim()); }, { placeholder: field[2] }));
			});
			if (selected.type === 'columns') panel.appendChild(control(doc, 'Columns', values.gridColumns || '', function(value) { style(selected, 'gridColumns', value ? Number(value) : ''); }, { choices: [['','Default'],[1,'1'],[2,'2'],[3,'3'],[4,'4']] }));
			panel.appendChild(control(doc, 'Text alignment', values.textAlign || '', function(value) { style(selected, 'textAlign', value); }, { choices: [['','Inherit'],['left','Left'],['center','Center'],['right','Right']] }));
			panel.appendChild(control(doc, 'Font weight', values.fontWeight || '', function(value) { style(selected, 'fontWeight', value); }, { choices: [['','Inherit'],['400','Regular'],['600','Semibold'],['700','Bold']] }));
		}

		function handleMessage(message) {
			var model = data();
			if (!model || !message) return false;
			if (message.type === 'md_pb_visual_select') { select(message.nodeId); return true; }
			if (message.type === 'md_pb_visual_text') {
				var found = locate(model, message.nodeId);
				if (found && ['heading','text','button'].indexOf(found.node.type) !== -1) change(setValue(model, message.nodeId, 'props', 'text', String(message.text || '')));
				return true;
			}
			if (message.type === 'md_pb_visual_move') {
				var next = move(model, message.sourceId, message.targetId, message.placement);
				if (next) change(next);
				return true;
			}
			return false;
		}

		return { render: render, handleMessage: handleMessage, syncSelection: syncSelection, bridgeScript: bridgeScript, getSelectedId: function() { return selectedId; } };
	}

	function canvasBridge() {
		var selected = null, toolbar = null, source = null;
		var style = document.createElement('style');
		style.textContent = '[data-pb-v-id]:hover{outline:1px dashed #3858e9;outline-offset:2px;cursor:pointer}.gt-pb-v-selected{outline:2px solid #3858e9!important;outline-offset:2px}.gt-pb-v-type-image:empty{min-height:120px;border:1px dashed #949494;background:#f6f7f7}.gt-pb-v-type-image:empty:before{content:"Choose an image";display:block;padding:44px;text-align:center;color:#757575}.gt-pb-v-toolbar{position:fixed;z-index:2147483647;display:flex;gap:2px;background:#1e1e1e;padding:2px;border-radius:3px}.gt-pb-v-toolbar button{border:0;background:#1e1e1e;color:white;font:12px sans-serif;padding:4px 7px;cursor:grab}.gt-pb-v-drop{outline:3px solid #007017!important;outline-offset:2px}';
		document.head.appendChild(style);
		function send(data) { window.parent.postMessage(data, window.parent.location.origin); }
		function placeToolbar() {
			if (!selected || !toolbar || !selected.isConnected) return;
			var rect = selected.getBoundingClientRect();
			toolbar.style.left = Math.max(2, rect.left) + 'px';
			toolbar.style.top = Math.max(2, rect.top - 28) + 'px';
		}
		function select(node) {
			if (selected) selected.classList.remove('gt-pb-v-selected');
			selected = node;
			if (!node) { if (toolbar) toolbar.remove(); toolbar = null; return; }
			node.classList.add('gt-pb-v-selected');
			if (!toolbar) {
				toolbar = document.createElement('div'); toolbar.className = 'gt-pb-v-toolbar';
				var move = document.createElement('button'); move.type = 'button'; move.textContent = '↕ Element'; move.draggable = true;
				move.addEventListener('dragstart', function(event) { source = { type: 'node', id: selected.getAttribute('data-pb-v-id') }; event.dataTransfer.setData('text/plain', source.id); });
				var sectionMove = document.createElement('button'); sectionMove.type = 'button'; sectionMove.textContent = '↕ Section'; sectionMove.draggable = true;
				sectionMove.addEventListener('dragstart', function(event) { var sec = selected.closest('[data-pb-section]'); if (!sec) return; source = { type: 'section', id: sec.getAttribute('data-pb-section') }; event.dataTransfer.setData('text/plain', source.id); });
				toolbar.appendChild(move); toolbar.appendChild(sectionMove); document.body.appendChild(toolbar);
			}
			placeToolbar();
		}
		document.addEventListener('click', function(event) {
			var node = event.target.closest && event.target.closest('[data-pb-v-id]');
			if (!node) return;
			select(node);
			send({ type: 'md_pb_visual_select', nodeId: node.getAttribute('data-pb-v-id'), sectionUid: (node.closest('[data-pb-section]') || {}).dataset.pbSection || '' });
			if (node.tagName === 'A') event.preventDefault();
		}, true);
		document.addEventListener('dblclick', function(event) {
			var node = event.target.closest && event.target.closest('[data-pb-v-id]');
			if (!node || !/^(H[1-6]|P|A)$/.test(node.tagName)) return;
			event.preventDefault(); node.contentEditable = 'true'; node.focus();
			var before = node.textContent;
			node.addEventListener('blur', function done() { node.contentEditable = 'false'; node.removeEventListener('blur', done); if (node.textContent !== before) send({ type: 'md_pb_visual_text', nodeId: node.getAttribute('data-pb-v-id'), text: node.textContent }); }, { once: true });
		}, true);
		document.addEventListener('dragover', function(event) {
			if (!source) return;
			var target = event.target.closest && event.target.closest(source.type === 'section' ? '[data-pb-section]' : '[data-pb-v-id]');
			if (!target) return;
			event.preventDefault(); target.classList.add('gt-pb-v-drop');
		});
		document.addEventListener('dragleave', function(event) { if (event.target.classList) event.target.classList.remove('gt-pb-v-drop'); });
		document.addEventListener('drop', function(event) {
			if (!source) return;
			var target = event.target.closest && event.target.closest(source.type === 'section' ? '[data-pb-section]' : '[data-pb-v-id]');
			if (!target) { source = null; return; }
			event.preventDefault(); target.classList.remove('gt-pb-v-drop');
			var rect = target.getBoundingClientRect(), ratio = (event.clientY - rect.top) / Math.max(rect.height, 1);
			var placement = ratio < 0.3 ? 'before' : (ratio > 0.7 ? 'after' : 'inside');
			if (placement === 'inside' && !target.matches('.gt-pb-v-type-section,.gt-pb-v-type-container,.gt-pb-v-type-columns')) placement = ratio < 0.5 ? 'before' : 'after';
			if (source.type === 'section') send({ type: 'md_pb_section_move', sourceUid: source.id, targetUid: target.getAttribute('data-pb-section'), placement: ratio < 0.5 ? 'before' : 'after' });
			else send({ type: 'md_pb_visual_move', sourceId: source.id, targetId: target.getAttribute('data-pb-v-id'), placement: placement });
			source = null;
		});
		document.addEventListener('dragend', function() { source = null; Array.prototype.forEach.call(document.querySelectorAll('.gt-pb-v-drop'), function(node) { node.classList.remove('gt-pb-v-drop'); }); });
		window.addEventListener('scroll', placeToolbar, true);
		window.addEventListener('resize', placeToolbar);
	}

	function bridgeScript() { return '(' + canvasBridge.toString() + ')();'; }
	return { createData: createData, createNode: createNode, normalizeData: normalizeData, locate: locate, add: add, move: move, remove: remove, duplicate: duplicate, setValue: setValue, regenerateIds: regenerateIds, createEditor: createEditor, bridgeScript: bridgeScript };
});
