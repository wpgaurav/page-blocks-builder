const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const shell = fs.readFileSync(path.join(__dirname, '../../../assets/js/builder-shell.js'), 'utf8');
const preview = fs.readFileSync(path.join(__dirname, '../../../assets/js/preview-dom.js'), 'utf8');

// Exercise the shipped UI via DOM events; never expose its private state/functions.
module.exports = function builder(t, sections = [{}], options = {}) {
	const page = new JSDOM('<div id="md-pb-builder-app"></div>', { url: 'https://builder.test/', runScripts: 'outside-only' });
	t.after(() => page.window.close());
	const { window } = page;
	const timers = new Map();
	const requests = [];
	const alerts = [];
	let id = 0, now = Date.now();
	window.Date.now = () => now;
	window.setTimeout = (fn, delay) => { timers.set(++id, { fn, delay }); return id; };
	window.clearTimeout = id => timers.delete(id);
	window.requestAnimationFrame = () => {};
	window.alert = message => alerts.push(message);
	window.confirm = () => true;
	window.HTMLElement.prototype.scrollIntoView = () => {};
	window.mdPbBuilder = { postId: 42, userId: 7, saveNonce: 'fixture', saveEndpoint: '/save', initialSections: sections.map((s, i) => ({
		uid: 'pb-section' + i, name: 'Section ' + i,
		content: '<section id="fixture' + i + '">Section</section>', css: '#fixture' + i + '{color:red}', ...s
	})), ...options.config };
	const draftKey = 'md_pb_draft_' + window.mdPbBuilder.postId + '_u' + window.mdPbBuilder.userId;
	if (options.draft !== undefined) window.localStorage.setItem(draftKey, typeof options.draft === 'string' ? options.draft : JSON.stringify(options.draft));
	if (options.storageUnavailable) window.Storage.prototype.setItem = () => { throw new Error('Quota exceeded'); };
	window.fetch = (url, request) => new Promise((resolve, reject) => {
		const form = new URLSearchParams(request.body);
		requests.push({ url, form, sections: JSON.parse(form.get('sections') || 'null'), resolve: payload => resolve({ json: () => Promise.resolve(payload) }), reject,
			invalidJSON: () => resolve({ json: () => Promise.reject(new SyntaxError('bad JSON')) }) });
	});
	window.eval(preview);
	window.eval(shell);
	window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
	const flush = delay => {
		now += delay;
		for (const [key, timer] of [...timers]) if (timer.delay === delay) { timers.delete(key); timer.fn(); }
	};
	return {
		window, requests, alerts, timers, draftKey,
		control: role => window.document.querySelector('[data-role="' + role + '"]'),
		select: i => window.document.querySelector('[data-action="select"][data-index="' + i + '"]').click(),
		key: (key, extra = {}) => window.document.dispatchEvent(new window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...extra })),
		flush,
		draft: () => { flush(5000); return JSON.parse(window.localStorage.getItem(draftKey)); },
		settle: () => new Promise(resolve => setImmediate(resolve))
	};
};
