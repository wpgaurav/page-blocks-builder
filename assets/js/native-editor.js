/* Visual mode hosts the real WordPress editor. Its core/editor store owns saves. */
(function(root, factory) {
	var api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	else { root.gtPbNativeEditorApi = api; if (root.gtPbNativeEditor) api.mount(root.document, root.gtPbNativeEditor); }
})(typeof window !== 'undefined' ? window : this, function() {
	'use strict';
	async function saveBeforeLeave(wp, postId) {
		var editor = wp && wp.data && wp.data.select('core/editor');
		if (!editor || Number(editor.getCurrentPostId()) !== Number(postId)) throw new Error('The editor is still loading. Try again when your page appears.');
		if (editor.isSavingPost() || editor.isAutosavingPost()) throw new Error('WordPress is saving your page. Try again when the save finishes.');
		if (editor.hasNonPostEntityChanges && editor.hasNonPostEntityChanges()) throw new Error('Save your template or pattern changes in WordPress before switching modes.');
		if (editor.isEditedPostDirty()) {
			if (editor.isPostSavingLocked && editor.isPostSavingLocked()) throw new Error('WordPress has locked saving. Resolve the notice in the editor before switching modes.');
			await wp.data.dispatch('core/editor').savePost();
			if (editor.didPostSaveRequestFail() || editor.isEditedPostDirty()) throw new Error('Your changes could not be saved. Resolve the notice in WordPress and try again.');
		}
	}

	function mount(doc, config) {
		var app = doc.getElementById('md-pb-builder-app');
		if (!app) return;
		var win = doc.defaultView;
		app.className = 'gt-pb-native-workspace';
		app.replaceChildren();
		var header = doc.createElement('header');
		header.className = 'gt-pb-native-header';
		var brand = doc.createElement('div');
		brand.className = 'gt-pb-native-brand';
		brand.textContent = 'Page Blocks';
		var modes = doc.createElement('nav');
		modes.className = 'gt-pb-native-modes';
		modes.setAttribute('aria-label', 'Editing mode');
		function button(label, icon) {
			var node = doc.createElement('button'); node.type = 'button';
			node.innerHTML = config.icons[icon] || '';
			var text = doc.createElement('span'); text.textContent = label; node.appendChild(text);
			return node;
		}
		var visual = button('Visual', 'layout'); visual.setAttribute('aria-current', 'page');
		var code = button('Code', 'code'); code.title = 'Save changes and switch to Code';
		modes.append(visual, code);
		var title = doc.createElement('span'); title.className = 'gt-pb-native-title'; title.textContent = config.title || 'Untitled page';
		var view = doc.createElement('a'); view.href = config.viewUrl; view.target = '_blank'; view.rel = 'noopener';
		view.innerHTML = (config.icons['external-link'] || '') + '<span>View page</span>';
		header.append(brand, modes, title, view);
		var notice = doc.createElement('div'); notice.className = 'gt-pb-native-notice'; notice.setAttribute('role', 'status'); notice.hidden = true;
		var loading = doc.createElement('div'); loading.className = 'gt-pb-native-loading'; loading.textContent = 'Opening WordPress editor…'; loading.setAttribute('role', 'status');
		var frame = doc.createElement('iframe'); frame.className = 'gt-pb-native-frame'; frame.title = 'WordPress visual editor'; frame.src = config.editorUrl;
		app.append(header, notice, loading, frame);
		var navigating = false, dirty = false, ready = false, requestId = 0, pending = null;
		var origin = new URL(config.editorUrl).origin;
		function showError(message) { notice.textContent = message; notice.hidden = false; }
		function loadingError() {
			if (ready) return;
			loading.hidden = true;
			showError('The WordPress editor is taking longer to load. Sign in below if prompted.');
			var direct = doc.createElement('a'); direct.href = config.editUrl; direct.textContent = ' Open editor directly'; notice.appendChild(direct);
		}
		var loadTimer = win.setTimeout(loadingError, 15000);
		frame.addEventListener('load', function() {
			ready = false;
			win.clearTimeout(loadTimer);
			loadTimer = win.setTimeout(loadingError, 15000);
			frame.contentWindow.postMessage({ type: 'gt_pb_native_ping' }, origin);
		});
		win.addEventListener('message', function(event) {
			if (event.source !== frame.contentWindow || event.origin !== origin || !event.data) return;
			var message = event.data;
			if (message.type === 'gt_pb_native_state' && Number(message.postId) === Number(config.postId)) {
				if (!ready) notice.hidden = true;
				ready = true; dirty = !!message.dirty; loading.hidden = true; win.clearTimeout(loadTimer);
				title.textContent = message.title || 'Untitled page';
				return;
			}
			if (message.type !== 'gt_pb_native_saved' || !pending || message.requestId !== pending.id) return;
			win.clearTimeout(pending.timer); pending = null; code.disabled = false;
			if (message.ok === true) { navigating = true; win.location.href = config.codeUrl; }
			else showError(message.message || 'Your changes could not be saved. Check the WordPress editor.');
		});
		code.addEventListener('click', function() {
			if (!ready) { showError('Wait for the WordPress editor to finish loading before switching modes.'); return; }
			if (pending) return;
			code.disabled = true; notice.hidden = true;
			pending = { id: ++requestId, timer: win.setTimeout(function() {
				pending = null; code.disabled = false; showError('The save has not finished. Check WordPress before switching modes.');
			}, 30000) };
			frame.contentWindow.postMessage({ type: 'gt_pb_native_save', requestId: pending.id }, origin);
		});
		visual.addEventListener('click', function() { frame.focus(); });
		win.addEventListener('beforeunload', function(event) {
			if (!navigating && dirty) { event.preventDefault(); event.returnValue = ''; }
		});
	}
	return { saveBeforeLeave: saveBeforeLeave, mount: mount };
});
