/* Small origin-checked bridge; WordPress retains all editor state and writes. */
(function(wp, config) {
	'use strict';
	if (window.parent === window || !wp || !config) return;
	var origin = new URL(config.parentUrl).origin;
	var lastState = '', saving = false;
	function send(data) { window.parent.postMessage(data, origin); }
	wp.domReady(function() {
		function publishState() {
			var editor = wp.data.select('core/editor');
			if (!editor || Number(editor.getCurrentPostId()) !== Number(config.postId)) return;
			var state = { type: 'gt_pb_native_state', postId: config.postId,
				title: editor.getEditedPostAttribute('title') || '',
				dirty: editor.isEditedPostDirty() || !!(editor.hasNonPostEntityChanges && editor.hasNonPostEntityChanges()) };
			var serialized = JSON.stringify(state);
			if (serialized !== lastState) { lastState = serialized; send(state); }
		}
		wp.data.subscribe(publishState); publishState();
		window.addEventListener('message', async function(event) {
			if (event.source !== window.parent || event.origin !== origin || !event.data) return;
			if (event.data.type === 'gt_pb_native_ping') { lastState = ''; publishState(); return; }
			if (event.data.type !== 'gt_pb_native_save' || saving) return;
			saving = true;
			try {
				await window.gtPbNativeEditorApi.saveBeforeLeave(wp, config.postId);
				send({ type: 'gt_pb_native_saved', requestId: event.data.requestId, ok: true });
			} catch (error) {
				send({ type: 'gt_pb_native_saved', requestId: event.data.requestId, ok: false, message: error.message });
			} finally { saving = false; }
		});
	});
})(window.wp, window.gtPbNativeBridge);
