(function() {
	'use strict';
	var __ = window.wp && window.wp.i18n ? function(s) { return window.wp.i18n.__(s, 'page-blocks-builder'); } : function(s) { return s; };
	function element(doc, tag, text, className) {
		var node = doc.createElement(tag);
		if (text !== undefined) node.textContent = text;
		if (className) node.className = className;
		return node;
	}
	function size(bytes) { return bytes < 1024 ? bytes + ' B' : (bytes / 1024).toFixed(1) + ' KiB'; }
	window.gtPbPerformance = {
		open: function(config, sections, trigger) {
			var doc = trigger && trigger.ownerDocument || document;
			var existing = doc.querySelector('.gt-pb-performance');
			if (existing) return;
			var dialog = element(doc, 'dialog', undefined, 'gt-pb-performance');
			dialog.setAttribute('aria-label', __('Page Blocks performance'));
			var heading = element(doc, 'div', undefined, 'gt-pb-performance__header');
			heading.appendChild(element(doc, 'h2', __('Performance')));
			var close = element(doc, 'button', __('Close'));
			close.type = 'button';
			heading.appendChild(close);
			dialog.appendChild(heading);
			var body = element(doc, 'div');
			dialog.appendChild(body);
			var controller = null;
			function cleanup() {
				if (controller) controller.abort();
				dialog.remove();
				if (trigger && trigger.isConnected) trigger.focus();
			}
			dialog.addEventListener('close', cleanup);
			close.addEventListener('click', function() { dialog.close(); });
			doc.body.appendChild(dialog);
			dialog.showModal();
			close.focus();
			function load() {
				body.replaceChildren();
				var status = element(doc, 'p', __('Analyzing section code…'));
				status.setAttribute('role', 'status');
				body.appendChild(status);
				var form = new URLSearchParams();
				form.set('action', 'gt_pb_performance');
				form.set('post_id', String(config.postId || 0));
				form.set('pb_nonce', config.nonce || '');
				form.set('sections', JSON.stringify(sections));
				form.set('scope', config.scope || 'page');
				controller = new AbortController();
				window.fetch(config.endpoint, { method: 'POST', credentials: 'same-origin', signal: controller.signal,
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: form.toString()
				}).then(function(res) { return res.json(); }).then(function(payload) {
					if (!dialog.isConnected) return;
					if (!payload || !payload.success || !payload.data || !Array.isArray(payload.data.rows) || !payload.data.totals || !Array.isArray(payload.data.notes)) {
						throw new Error(payload && payload.data && payload.data.message || __('Could not analyze this page.'));
					}
					var data = payload.data;
					body.replaceChildren();
					body.appendChild(element(doc, 'p', __('Expected CSS file requests:') + ' ' + data.totals.css_requests + ' · ' + __('Deferred loader requests:') + ' ' + data.totals.loader_requests));
					var scroll = element(doc, 'div', undefined, 'gt-pb-performance__scroll');
					scroll.tabIndex = 0;
					scroll.setAttribute('role', 'region');
					scroll.setAttribute('aria-label', __('Section code sizes'));
					var table = element(doc, 'table');
					table.appendChild(element(doc, 'caption', __('Source / minified size before compression')));
					var head = element(doc, 'thead'), headRow = element(doc, 'tr');
					[__('Section'), __('CSS'), __('JavaScript'), __('CSS loading')].forEach(function(label) {
						var th = element(doc, 'th', label); th.scope = 'col'; headRow.appendChild(th);
					});
					head.appendChild(headRow); table.appendChild(head);
					var rows = element(doc, 'tbody');
					data.rows.forEach(function(row) {
						var tr = element(doc, 'tr'), title = element(doc, 'th', row.label); title.scope = 'row'; tr.appendChild(title);
						tr.appendChild(element(doc, 'td', size(row.css_bytes) + ' / ' + size(row.css_minified_bytes)));
						tr.appendChild(element(doc, 'td', size(row.js_bytes) + ' / ' + size(row.js_minified_bytes)));
						tr.appendChild(element(doc, 'td', row.mode)); rows.appendChild(tr);
						if (row.notes.length) {
							var noteRow = element(doc, 'tr'), cell = element(doc, 'td'); cell.colSpan = 4;
							var list = element(doc, 'ul'); row.notes.forEach(function(note) { list.appendChild(element(doc, 'li', note)); });
							cell.appendChild(list); noteRow.appendChild(cell); rows.appendChild(noteRow);
						}
					});
					table.appendChild(rows); scroll.appendChild(table); body.appendChild(scroll);
					data.notes.forEach(function(note) { body.appendChild(element(doc, 'p', note, 'gt-pb-performance__note')); });
				}).catch(function(error) {
					if (!dialog.isConnected || error.name === 'AbortError') return;
					body.replaceChildren();
					var message = element(doc, 'p', error.message || __('Could not analyze this page.')); message.setAttribute('role', 'alert');
					body.appendChild(message);
					var retry = element(doc, 'button', __('Retry')); retry.type = 'button'; retry.addEventListener('click', load); body.appendChild(retry);
				});
			}
			load();
		}
	};
}());
