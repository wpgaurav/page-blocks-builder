/**
 * Page Blocks Visual Builder Shell
 *
 * Frontend page builder for the GT Page Blocks Builder plugin.
 * Each section = one marketers-delight/page-block in post_content.
 *
 * @since 2.0.0
 */
(function() {
	'use strict';

	var config = window.mdPbBuilder || {};
	var app = document.getElementById('md-pb-builder-app');
	var state = {
		sections: [],
		selectedIndex: 0,
		dragIndex: null,
		editors: {},
		syncingEditors: false,
		previewTimer: null,
		hasCodeMirror: false,
		showCode: true,
		showPreview: true,
		showSidebar: true,
		previewViewport: 'desktop',
		activeEditorKey: 'html',
		rightPaneMode: 'css',
		bottomHeight: 360,
		resizeActive: false,
		resizePointerId: null,
		resizeStartY: 0,
		resizeStartHeight: 360,
		applyBusy: false,
		previewRequestId: 0,
		previewAssets: {
			styleUrls: [],
			inlineStyles: []
		},
		pageTemplate: config.postTemplate || 'default',
		aiOpen: false,
		aiMessages: [],
		aiSelection: '',
		aiSelectionEditor: null,
		aiBusy: false,
		aiXhr: null,
		aiModel: (config.aiDefaultModel || '')
	};
	var dom = {};

	if (!app) {
		return;
	}


	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function createDefaultSection() {
		return {
			name: '',
			content: '',
			css: '',
			js: '',
			jsLocation: 'footer',
			output: 'inline',
			format: false,
			phpExec: false,
			collapsed: false
		};
	}

	function normalizeSection(input) {
		var section = createDefaultSection();
		var source = input && typeof input === 'object' ? input : {};

		section.name = typeof source.name === 'string' ? source.name : '';
		section.content = typeof source.content === 'string' ? source.content : '';
		section.css = typeof source.css === 'string' ? source.css : '';
		section.js = typeof source.js === 'string' ? source.js : '';
		section.jsLocation = source.jsLocation === 'inline' ? 'inline' : 'footer';
		section.output = source.output === 'file' ? 'file' : 'inline';
		section.format = !!source.format;
		section.phpExec = !!source.phpExec;
		section.collapsed = !!source.collapsed;

		return section;
	}

	function sanitizeSections(input) {
		if (!Array.isArray(input)) {
			return [createDefaultSection()];
		}

		var sections = input.map(normalizeSection);
		return sections.length ? sections : [createDefaultSection()];
	}

	function getSectionDisplayName(section, index) {
		if (section && section.name && section.name.trim()) {
			return section.name.trim();
		}
		return inferSectionName(section ? section.content : '', index);
	}

	function inferSectionName(content, index) {
		if (typeof content !== 'string' || !content.trim()) {
			return 'Section ' + (index + 1);
		}

		try {
			var parser = new window.DOMParser();
			var doc = parser.parseFromString(content, 'text/html');
			var sectionWithId = doc.querySelector('section[id]');
			if (sectionWithId && sectionWithId.id) {
				return sectionWithId.id;
			}

			var heading = doc.querySelector('h1, h2, h3, h4, h5, h6');
			if (heading) {
				var text = (heading.textContent || '').trim().replace(/\s+/g, ' ');
				if (text) {
					return text;
				}
			}
		} catch (error) {
			return 'Section ' + (index + 1);
		}

		return 'Section ' + (index + 1);
	}

	function getCurrentSection() {
		return state.sections[state.selectedIndex] || null;
	}

	function clampSelectedIndex() {
		if (!state.sections.length) {
			state.selectedIndex = 0;
			return;
		}

		if (state.selectedIndex < 0) {
			state.selectedIndex = 0;
		}

		if (state.selectedIndex >= state.sections.length) {
			state.selectedIndex = state.sections.length - 1;
		}
	}

	function escapeAttribute(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;');
	}

	function escapeClosingTag(content, tagName) {
		// Not used for srcdoc anymore — scripts injected post-load
		var pattern = new RegExp('</' + tagName, 'gi');
		return String(content).replace(pattern, '<' + String.fromCharCode(92) + '/' + tagName);
	}


	// -------------------------------------------------------------------------
	// Autosave
	// -------------------------------------------------------------------------

	var AUTOSAVE_KEY = 'md_pb_draft_' + (config.postId || 0);
	var AUTOSAVE_INTERVAL = 5000;
	var autosaveTimer = null;

	function getAutosaveDraft() {
		try {
			var raw = window.localStorage.getItem(AUTOSAVE_KEY);
			if (!raw) {
				return null;
			}
			var data = JSON.parse(raw);
			if (data && Array.isArray(data.sections) && data.sections.length) {
				return data;
			}
		} catch (error) {
			// corrupted draft
		}
		return null;
	}

	function saveAutosaveDraft() {
		try {
			window.localStorage.setItem(AUTOSAVE_KEY, JSON.stringify({
				sections: getApplyPayloadSections(),
				timestamp: Date.now()
			}));
		} catch (error) {
			// storage full or unavailable
		}
	}

	function clearAutosaveDraft() {
		try {
			window.localStorage.removeItem(AUTOSAVE_KEY);
		} catch (error) {
			// no-op
		}

		// Clear pending autosave timer so beforeunload won't warn
		if (autosaveTimer) {
			window.clearTimeout(autosaveTimer);
			autosaveTimer = null;
		}
	}

	function queueAutosave() {
		if (autosaveTimer) {
			window.clearTimeout(autosaveTimer);
		}
		autosaveTimer = window.setTimeout(saveAutosaveDraft, AUTOSAVE_INTERVAL);
	}


	// -------------------------------------------------------------------------
	// Preview
	// -------------------------------------------------------------------------

	function needsServerPreview() {
		if (!Array.isArray(state.sections) || !state.sections.length) {
			return false;
		}

		return state.sections.some(function(section) {
			return !!(section && (section.phpExec || section.format));
		});
	}

	function requestServerPreview(sections) {
		if (!config.previewEndpoint || !config.postId || !config.previewNonce) {
			return window.Promise.reject(new Error('Missing preview endpoint configuration.'));
		}

		var form = new window.URLSearchParams();
		form.set('action', config.previewAction || 'md_pb_builder_preview');
		form.set('post_id', String(config.postId || 0));
		form.set('pb_nonce', String(config.previewNonce || ''));
		form.set('sections', JSON.stringify(Array.isArray(sections) ? sections : []));

		return window.fetch(config.previewEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: form.toString()
		}).then(function(response) {
			return response.json().catch(function() {
				throw new Error('Invalid preview response.');
			});
		}).then(function(payload) {
			if (!payload || !payload.success || !payload.data || typeof payload.data !== 'object') {
				throw new Error('Preview response failed.');
			}
			return payload.data;
		});
	}

	function collectPreviewAssets() {
		var styleUrls = [];
		var inlineStyles = [];

		// Use previewCssUrl from dropin's compiled CSS endpoint
		if (config.previewCssUrl) {
			styleUrls.push(config.previewCssUrl);
		}

		// Also include explicitly passed theme style URLs
		if (Array.isArray(config.themeStyleUrls)) {
			config.themeStyleUrls.forEach(function(url) {
				if (url && styleUrls.indexOf(url) === -1) {
					styleUrls.push(url);
				}
			});
		}

		state.previewAssets = {
			styleUrls: styleUrls,
			inlineStyles: inlineStyles
		};
	}

	function buildPreviewDoc(renderedData) {
		var html = [];
		var css = [];
		var inlineJs = [];
		var footerJs = [];

		state.sections.forEach(function(section, i) {
			if (section.collapsed) {
				return;
			}

			html.push('<div data-pb-section="' + i + '">' + (section.content || '') + '</div>');

			if (section.css) {
				css.push(section.css);
			}

			if (section.js) {
				if (section.jsLocation === 'inline') {
					inlineJs.push(section.js);
				} else {
					footerJs.push(section.js);
				}
			}
		});

		var themeStyleLinks = state.previewAssets.styleUrls
			.map(function(url) {
				return '<link rel="stylesheet" href="' + escapeAttribute(url) + '">';
			})
			.join('');

		var themeInlineStyles = state.previewAssets.inlineStyles
			.map(function(style) {
				return '<style>' + escapeClosingTag(style, 'style') + '</style>';
			})
			.join('');

		var rendered = renderedData && typeof renderedData === 'object' ? renderedData : {};
		var htmlOutput = typeof rendered.html === 'string' ? rendered.html : html.join('\n');
		var cssOutput = typeof rendered.css === 'string' ? rendered.css : css.join('\n');
		var inlineJsOutput = typeof rendered.jsInline === 'string' ? rendered.jsInline : inlineJs.join(';\n');
		var footerJsOutput = typeof rendered.jsFooter === 'string' ? rendered.jsFooter : footerJs.join(';\n');

		var customCssTag = cssOutput
			? '<style>' + cssOutput + '</style>'
			: '';

		// Preview injection from theme/plugins
		var injection = config.previewInjection && typeof config.previewInjection === 'object'
			? config.previewInjection : {};
		var injectedCssTag = injection.css
			? '<style>' + injection.css + '</style>'
			: '';

		var inlineEditCss = '<style>[contenteditable="true"]{outline:2px solid rgba(99,179,237,0.6);outline-offset:2px;border-radius:2px;min-height:1em;}[data-pb-editable-hover]{outline:1px dashed rgba(99,179,237,0.3);outline-offset:1px;border-radius:2px;cursor:text;}</style>';

		// Build HTML (no <script> tags — scripts injected post-load to avoid srcdoc parsing issues)
		var docHtml = '<!doctype html>' +
			'<html><head><meta charset="utf-8">' +
			'<meta name="viewport" content="width=device-width, initial-scale=1">' +
			themeStyleLinks +
			themeInlineStyles +
			(injection.headHtml || '') +
			injectedCssTag +
			customCssTag +
			inlineEditCss +
			'</head><body>' +
			(injection.bodyStartHtml || '') +
			htmlOutput +
			(injection.bodyEndHtml || '') +
			'</body></html>';

		// Collect all JS to inject after iframe loads
		var scripts = [];
		if (injection.jsHead) scripts.push(injection.jsHead);
		if (inlineJsOutput) scripts.push(inlineJsOutput);
		if (footerJsOutput) scripts.push(footerJsOutput);
		if (injection.jsFooter) scripts.push(injection.jsFooter);

		// Inline text editing script
		scripts.push(
			'(function(){' +
			'var SEL="h1,h2,h3,h4,h5,h6,p,li,td,th,figcaption,blockquote,label,cite,dt,dd,summary,a";' +
			'var editing=null,origText="";' +
			'document.addEventListener("mouseover",function(e){var el=e.target.closest(SEL);if(el&&el!==editing)el.setAttribute("data-pb-editable-hover","");});' +
			'document.addEventListener("mouseout",function(e){var el=e.target.closest(SEL);if(el)el.removeAttribute("data-pb-editable-hover");});' +
			'document.addEventListener("click",function(e){' +
			'var el=e.target.closest(SEL);' +
			'if(!el||el.contentEditable==="true")return;' +
			'if(el.querySelector("div,section,article,ul,ol,table,form,header,footer,nav,aside"))return;' +
			'e.preventDefault();e.stopPropagation();' +
			'el.removeAttribute("data-pb-editable-hover");' +
			'el.contentEditable="true";editing=el;origText=el.textContent;' +
			'el.focus();' +
			'try{var r=document.createRange();r.selectNodeContents(el);var s=window.getSelection();s.removeAllRanges();s.addRange(r);}catch(x){}' +
			'},true);' +
			'document.addEventListener("focusout",function(e){' +
			'var el=e.target;if(el.contentEditable!=="true")return;' +
			'el.contentEditable="false";' +
			'var t=el.textContent;' +
			'if(t!==origText){' +
			'var sec=el.closest("[data-pb-section]");' +
			'var idx=sec?parseInt(sec.getAttribute("data-pb-section"),10):-1;' +
			'if(idx>=0)window.parent.postMessage({type:"md_pb_inline_edit",sectionIndex:idx,oldText:origText,newText:t},"*");' +
			'}' +
			'editing=null;origText="";' +
			'});' +
			'document.addEventListener("keydown",function(e){' +
			'if(!editing)return;' +
			'if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();editing.blur();}' +
			'if(e.key==="Escape"){editing.textContent=origText;editing.blur();}' +
			'});' +
			'})();'
		);

		return { html: docHtml, scripts: scripts };
	}

	/**
	 * Try live-patching the iframe DOM instead of full srcdoc reload.
	 * Returns true if patched successfully, false if full reload needed.
	 */
	function tryLivePatch() {
		if (!dom.previewFrame) return false;

		try {
			var doc = dom.previewFrame.contentDocument || dom.previewFrame.contentWindow.document;
			if (!doc || !doc.body) return false;

			// Update each section's HTML in place
			var patched = true;
			state.sections.forEach(function(section, i) {
				var el = doc.querySelector('[data-pb-section="' + i + '"]');
				if (!el) {
					patched = false;
					return;
				}
				if (!section.collapsed) {
					el.innerHTML = section.content || '';
					el.style.display = '';
				} else {
					el.style.display = 'none';
				}
			});

			// Update combined CSS in the preview
			var cssEl = doc.getElementById('md-pb-live-css');
			if (!cssEl) {
				cssEl = doc.createElement('style');
				cssEl.id = 'md-pb-live-css';
				doc.head.appendChild(cssEl);
			}
			var allCss = [];
			state.sections.forEach(function(section) {
				if (section.css && !section.collapsed) {
					allCss.push(section.css);
				}
			});
			cssEl.textContent = allCss.join('\n');

			return patched;
		} catch (e) {
			return false;
		}
	}

	function fullPreviewRender() {
		if (!dom.previewFrame) return;

		var requestId = state.previewRequestId + 1;
		state.previewRequestId = requestId;

		function applyPreview(preview) {
			if (!dom.previewFrame) return;
			dom.previewFrame.srcdoc = preview.html;
			if (preview.scripts && preview.scripts.length) {
				dom.previewFrame.addEventListener('load', function onLoad() {
					dom.previewFrame.removeEventListener('load', onLoad);
					try {
						var doc = dom.previewFrame.contentDocument || dom.previewFrame.contentWindow.document;
						preview.scripts.forEach(function(code) {
							var s = doc.createElement('script');
							s.textContent = code;
							doc.body.appendChild(s);
						});
					} catch (e) {
						// cross-origin or iframe not ready
					}
				});
			}
		}

		if (!needsServerPreview()) {
			applyPreview(buildPreviewDoc());
			return;
		}

		requestServerPreview(getApplyPayloadSections())
			.then(function(renderedData) {
				if (requestId !== state.previewRequestId || !dom.previewFrame) return;
				applyPreview(buildPreviewDoc(renderedData));
			})
			.catch(function() {
				if (requestId !== state.previewRequestId || !dom.previewFrame) return;
				applyPreview(buildPreviewDoc());
			});
	}

	// Track whether iframe has been initialized with a full render
	var previewInitialized = false;

	function queuePreviewRender(delay, forceFullRender) {
		if (state.previewTimer) {
			window.clearTimeout(state.previewTimer);
		}

		var wait = typeof delay === 'number' ? delay : 0;

		state.previewTimer = window.setTimeout(function() {
			// Try live-patching first (no flicker) for CSS/content edits
			if (previewInitialized && !forceFullRender && !needsServerPreview()) {
				if (tryLivePatch()) {
					return;
				}
			}

			// Full render (needed for structural changes, first load, or server preview)
			fullPreviewRender();
			previewInitialized = true;
		}, wait);
	}

	function getPreviewViewportWidth(mode) {
		switch (mode) {
			case '992':
				return 992;
			case '768':
				return 768;
			case '480':
				return 480;
			case '360':
				return 360;
			default:
				return 0;
		}
	}

	function applyPreviewViewport() {
		if (!dom.previewFrame) {
			return;
		}

		var width = getPreviewViewportWidth(state.previewViewport);
		if (width > 0) {
			dom.previewFrame.style.width = width + 'px';
			dom.previewFrame.style.maxWidth = 'calc(100% - 24px)';
		} else {
			dom.previewFrame.style.width = '';
			dom.previewFrame.style.maxWidth = '';
		}

		if (Array.isArray(dom.viewportButtons)) {
			dom.viewportButtons.forEach(function(button) {
				var viewport = button.getAttribute('data-viewport') || 'desktop';
				var isActive = viewport === state.previewViewport;
				button.classList.toggle('is-active', isActive);
				button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
			});
		}
	}


	// -------------------------------------------------------------------------
	// Section Meta
	// -------------------------------------------------------------------------

	function extractSectionMeta(section, index) {
		var fallbackId = 'section-' + (index + 1);
		var classes = [];

		if (!section || typeof section.content !== 'string' || !section.content.trim()) {
			return { id: fallbackId, classes: classes };
		}

		try {
			var parser = new window.DOMParser();
			var doc = parser.parseFromString(section.content, 'text/html');
			var idNode = doc.querySelector('[id]');
			var classNode = doc.querySelector('[class]');
			var sectionId = idNode && idNode.id ? idNode.id : fallbackId;

			if (classNode && classNode.classList && classNode.classList.length) {
				classes = Array.prototype.slice.call(classNode.classList).slice(0, 8);
			}

			return { id: sectionId, classes: classes };
		} catch (error) {
			return { id: fallbackId, classes: classes };
		}
	}

	function updateStatusBar() {
		if (!dom.statusCount) {
			return;
		}

		var total = state.sections.length;
		var visible = state.sections.filter(function(section) {
			return !section.collapsed;
		}).length;
		dom.statusCount.textContent = visible + '/' + total + ' sections';
	}

	function renderActiveSectionMeta() {
		if (!dom.activeSectionId || !dom.activeSectionClasses) {
			return;
		}

		var section = getCurrentSection();
		var meta = extractSectionMeta(section, state.selectedIndex);
		dom.activeSectionId.value = meta.id;
		dom.activeSectionClasses.innerHTML = '';

		if (!meta.classes.length) {
			var emptyTag = document.createElement('span');
			emptyTag.className = 'md-pb-meta-class is-empty';
			emptyTag.textContent = 'No classes';
			dom.activeSectionClasses.appendChild(emptyTag);
			return;
		}

		meta.classes.forEach(function(className) {
			var tag = document.createElement('span');
			tag.className = 'md-pb-meta-class';
			tag.textContent = className;
			dom.activeSectionClasses.appendChild(tag);
		});
	}


	// -------------------------------------------------------------------------
	// Panel Controls
	// -------------------------------------------------------------------------

	function setRightPaneMode(mode) {
		var validModes = ['css', 'js'];
		state.rightPaneMode = validModes.indexOf(mode) !== -1 ? mode : 'css';

		if (dom.shell) {
			dom.shell.classList.remove('is-pane-js');
			if (state.rightPaneMode === 'js') {
				dom.shell.classList.add('is-pane-js');
			}
		}

		if (dom.rightPaneLabel) {
			var labels = { css: 'CSS', js: 'JS' };
			dom.rightPaneLabel.textContent = labels[state.rightPaneMode] || 'CSS';
		}

		if (dom.swapRightPaneButton) {
			var nextMode = getNextRightPaneMode();
			var swapLabels = { css: 'CSS', js: 'JS' };
			dom.swapRightPaneButton.textContent = swapLabels[nextMode] || 'JS';
		}

		refreshCodeEditors();
	}

	function getNextRightPaneMode() {
		var modes = ['css', 'js'];
		var idx = modes.indexOf(state.rightPaneMode);
		return modes[(idx + 1) % modes.length];
	}

	function updatePanelVisibility() {
		if (!dom.shell) {
			return;
		}

		dom.shell.classList.toggle('is-code-hidden', !state.showCode);
		dom.shell.classList.toggle('is-preview-hidden', !state.showPreview);
		dom.shell.classList.toggle('is-sidebar-hidden', !state.showSidebar);

		if (dom.togglePreviewButton) {
			dom.togglePreviewButton.classList.toggle('is-active', state.showPreview);
			dom.togglePreviewButton.setAttribute('aria-pressed', state.showPreview ? 'true' : 'false');
		}

		if (dom.toggleCodeButton) {
			dom.toggleCodeButton.classList.toggle('is-active', state.showCode);
			dom.toggleCodeButton.setAttribute('aria-pressed', state.showCode ? 'true' : 'false');
		}

		if (dom.toggleSectionsButton) {
			dom.toggleSectionsButton.classList.toggle('is-active', state.showSidebar);
			dom.toggleSectionsButton.setAttribute('aria-pressed', state.showSidebar ? 'true' : 'false');
		}

		refreshCodeEditors();
	}


	// -------------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------------

	function setApplyButtonBusy(isBusy, label) {
		state.applyBusy = !!isBusy;
		if (!dom.applyButton) {
			return;
		}

		dom.applyButton.disabled = !!isBusy;
		if (typeof label === 'string' && label) {
			dom.applyButton.textContent = label;
		}
	}

	function resetApplyButtonLabel() {
		if (!dom.applyButton) {
			return;
		}
		dom.applyButton.textContent = 'Save';
	}

	function extractAjaxErrorMessage(payload, fallback) {
		if (payload && typeof payload === 'object') {
			if (payload.data && typeof payload.data.message === 'string' && payload.data.message) {
				return payload.data.message;
			}
			if (typeof payload.message === 'string' && payload.message) {
				return payload.message;
			}
		}
		return fallback;
	}

	function saveSections(sections) {
		if (!config.saveEndpoint || !config.postId || !config.saveNonce) {
			window.alert('Save endpoint is missing.');
			return;
		}

		if (state.applyBusy) {
			return;
		}

		setApplyButtonBusy(true, 'Saving...');

		var form = new window.URLSearchParams();
		form.set('action', config.saveAction || 'md_pb_builder_save');
		form.set('post_id', String(config.postId || 0));
		form.set('pb_nonce', String(config.saveNonce || ''));
		form.set('sections', JSON.stringify(Array.isArray(sections) ? sections : []));
		form.set('page_template', state.pageTemplate || '');

		window.fetch(config.saveEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: form.toString()
		})
			.then(function(response) {
				return response.json().catch(function() {
					throw new Error('Invalid response from save endpoint.');
				});
			})
			.then(function(payload) {
				if (!payload || !payload.success) {
					throw new Error(extractAjaxErrorMessage(payload, 'Could not save Page Blocks.'));
				}

				if (payload.data && Array.isArray(payload.data.sections)) {
					hydrateSections(payload.data.sections);
				}

				if (payload.data && payload.data.editPostUrl) {
					config.editPostUrl = payload.data.editPostUrl;
				}

				clearAutosaveDraft();
				setApplyButtonBusy(false, 'Saved');
				window.setTimeout(function() {
					resetApplyButtonLabel();
				}, 1200);
			})
			.catch(function(error) {
				setApplyButtonBusy(false);
				resetApplyButtonLabel();
				window.alert(error && error.message ? error.message : 'Could not save Page Blocks.');
			});
	}

	function getApplyPayloadSections() {
		return state.sections.map(function(section) {
			var normalized = normalizeSection(section);
			return {
				content: normalized.content,
				css: normalized.css,
				js: normalized.js,
				jsLocation: normalized.jsLocation,
				output: normalized.output,
				format: normalized.format,
				phpExec: normalized.phpExec
			};
		});
	}

	function activateApply() {
		saveSections(getApplyPayloadSections());
	}

	function activateCancel() {
		if (config.viewPostUrl) {
			window.location.href = config.viewPostUrl;
			return;
		}

		if (config.editPostUrl) {
			window.location.href = config.editPostUrl;
			return;
		}

		window.history.back();
	}


	// -------------------------------------------------------------------------
	// Section CRUD
	// -------------------------------------------------------------------------

	function updateCurrentSectionField(field, value) {
		var section = getCurrentSection();
		if (!section) {
			return;
		}

		section[field] = value;

		if (field === 'content') {
			renderIndexList();
		}

		queuePreviewRender(field === 'css' ? 1000 : 0);
		queueAutosave();
	}

	function addSection(afterIndex) {
		var insertAt = typeof afterIndex === 'number' ? afterIndex + 1 : state.sections.length;
		state.sections.splice(insertAt, 0, createDefaultSection());
		state.selectedIndex = insertAt;
		renderAll();
	}

	function duplicateSection(index) {
		if (index < 0 || index >= state.sections.length) {
			return;
		}
		var copy = normalizeSection(state.sections[index]);
		if (copy.content) {
			copy.content = copy.content.replace(/id=(["'])([^"']+)\1/i, function(match, quote, idValue) {
				if (/-copy$/.test(idValue)) {
					return 'id=' + quote + idValue + quote;
				}
				return 'id=' + quote + idValue + '-copy' + quote;
			});
		}
		state.sections.splice(index + 1, 0, copy);
		state.selectedIndex = index + 1;
		renderAll();
	}

	function deleteSection(index) {
		if (index < 0 || index >= state.sections.length) {
			return;
		}

		state.sections.splice(index, 1);

		if (!state.sections.length) {
			state.sections.push(createDefaultSection());
		}

		if (state.selectedIndex >= state.sections.length) {
			state.selectedIndex = state.sections.length - 1;
		}

		renderAll();
	}

	function toggleCollapse(index) {
		if (index < 0 || index >= state.sections.length) {
			return;
		}

		state.sections[index].collapsed = !state.sections[index].collapsed;
		renderAll();
	}

	function reorderSections(fromIndex, toIndex) {
		if (fromIndex === toIndex || fromIndex < 0 || toIndex < 0) {
			return;
		}

		if (fromIndex >= state.sections.length || toIndex >= state.sections.length) {
			return;
		}

		var moved = state.sections.splice(fromIndex, 1)[0];
		state.sections.splice(toIndex, 0, moved);

		if (state.selectedIndex === fromIndex) {
			state.selectedIndex = toIndex;
		} else if (fromIndex < state.selectedIndex && toIndex >= state.selectedIndex) {
			state.selectedIndex -= 1;
		} else if (fromIndex > state.selectedIndex && toIndex <= state.selectedIndex) {
			state.selectedIndex += 1;
		}

		renderAll();
	}

	function selectSection(index) {
		if (index < 0 || index >= state.sections.length) {
			return;
		}
		state.selectedIndex = index;
		renderAll();
		ensureSelectedIndexVisible();
		scrollPreviewToSection(index);
	}

	function scrollPreviewToSection(index) {
		if (!dom.previewFrame || !dom.previewFrame.contentWindow) {
			return;
		}

		try {
			var iframeDoc = dom.previewFrame.contentDocument || dom.previewFrame.contentWindow.document;
			if (!iframeDoc || !iframeDoc.body) {
				return;
			}

			var marker = iframeDoc.querySelector('[data-pb-section="' + index + '"]');
			if (marker) {
				marker.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		} catch (error) {
			// cross-origin or iframe not ready
		}
	}


	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	function renderIndexList() {
		if (!dom.indexList) {
			return;
		}

		var previousScrollTop = dom.indexList.scrollTop;
		dom.indexList.innerHTML = '';

		state.sections.forEach(function(section, index) {
			var item = document.createElement('li');
			item.className = 'md-pb-index-item';
			item.setAttribute('data-index', String(index));
			item.setAttribute('tabindex', '0');
			item.setAttribute('draggable', 'true');

			if (index === state.selectedIndex) {
				item.classList.add('is-selected');
			}

			if (section.collapsed) {
				item.classList.add('is-collapsed');
			}

			var nameButton = document.createElement('button');
			nameButton.type = 'button';
			nameButton.className = 'md-pb-index-item-name';
			nameButton.textContent = getSectionDisplayName(section, index);
			nameButton.setAttribute('data-action', 'select');
			nameButton.setAttribute('data-index', String(index));
			nameButton.title = 'Click to select, double-click to rename';

			// Double-click to rename
			nameButton.addEventListener('dblclick', function(e) {
				e.stopPropagation();
				var idx = parseInt(nameButton.getAttribute('data-index') || '', 10);
				if (Number.isNaN(idx) || idx < 0 || idx >= state.sections.length) return;

				var current = state.sections[idx].name || getSectionDisplayName(state.sections[idx], idx);
				var input = document.createElement('input');
				input.type = 'text';
				input.className = 'md-pb-index-rename-input';
				input.value = current;

				function commitRename() {
					var newName = input.value.trim();
					state.sections[idx].name = newName;
					renderIndexList();
					queueAutosave();
				}

				input.addEventListener('blur', commitRename);
				input.addEventListener('keydown', function(ke) {
					if (ke.key === 'Enter') { ke.preventDefault(); input.blur(); }
					if (ke.key === 'Escape') { ke.preventDefault(); input.value = current; input.blur(); }
				});

				nameButton.replaceWith(input);
				input.focus();
				input.select();
			});

			var grip = document.createElement('span');
			grip.className = 'md-pb-index-grip';
			grip.innerHTML = '<svg width="8" height="14" viewBox="0 0 8 14" fill="currentColor"><circle cx="2" cy="2" r="1.2"/><circle cx="6" cy="2" r="1.2"/><circle cx="2" cy="7" r="1.2"/><circle cx="6" cy="7" r="1.2"/><circle cx="2" cy="12" r="1.2"/><circle cx="6" cy="12" r="1.2"/></svg>';
			grip.setAttribute('aria-hidden', 'true');

			var actions = document.createElement('div');
			actions.className = 'md-pb-index-item-actions';

			var collapse = document.createElement('button');
			collapse.type = 'button';
			collapse.className = 'md-pb-icon-btn';
			collapse.innerHTML = section.collapsed
				? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/><line x1="2" y1="2" x2="22" y2="22"/></svg>'
				: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
			collapse.title = section.collapsed ? 'Show section' : 'Hide section';
			collapse.setAttribute('data-action', 'collapse');
			collapse.setAttribute('data-index', String(index));

			var duplicate = document.createElement('button');
			duplicate.type = 'button';
			duplicate.className = 'md-pb-icon-btn';
			duplicate.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';
			duplicate.title = 'Duplicate section';
			duplicate.setAttribute('data-action', 'duplicate');
			duplicate.setAttribute('data-index', String(index));

			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'md-pb-icon-btn';
			remove.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>';
			remove.title = 'Delete section';
			remove.setAttribute('data-action', 'delete');
			remove.setAttribute('data-index', String(index));

			actions.appendChild(collapse);
			actions.appendChild(duplicate);
			actions.appendChild(remove);
			item.appendChild(grip);
			item.appendChild(nameButton);
			item.appendChild(actions);
			dom.indexList.appendChild(item);
		});

		if (dom.sectionCount) {
			dom.sectionCount.textContent = String(state.sections.length);
		}

		dom.indexList.scrollTop = previousScrollTop;
		updateStatusBar();
		renderActiveSectionMeta();
	}

	function renderCurrentSectionToEditors() {
		var section = getCurrentSection();
		if (!section) {
			return;
		}

		state.syncingEditors = true;
		if (state.hasCodeMirror) {
			if (state.editors.html) {
				state.editors.html.codemirror.setValue(section.content || '');
			}
			if (state.editors.css) {
				state.editors.css.codemirror.setValue(section.css || '');
			}
			if (state.editors.js) {
				state.editors.js.codemirror.setValue(section.js || '');
			}
		} else {
			dom.textareaHtml.value = section.content || '';
			dom.textareaCss.value = section.css || '';
			dom.textareaJs.value = section.js || '';
		}
		state.syncingEditors = false;

		dom.jsLocation.value = section.jsLocation;
		dom.format.checked = !!section.format;
		dom.phpExec.checked = !!section.phpExec;
		renderActiveSectionMeta();
		updateStatusBar();
	}

	function refreshCodeEditors() {
		if (!state.hasCodeMirror) {
			return;
		}

		window.requestAnimationFrame(function() {
			Object.keys(state.editors).forEach(function(key) {
				if (state.editors[key] && state.editors[key].codemirror) {
					state.editors[key].codemirror.refresh();
				}
			});
		});
	}

	function renderAll() {
		clampSelectedIndex();
		renderIndexList();
		renderCurrentSectionToEditors();
		refreshCodeEditors();
		queuePreviewRender(0, true); // force full render on structural changes
	}

	function ensureSelectedIndexVisible() {
		if (!dom.indexList) {
			return;
		}

		window.requestAnimationFrame(function() {
			var item = dom.indexList.querySelector('.md-pb-index-item[data-index="' + String(state.selectedIndex) + '"]');
			if (item && typeof item.scrollIntoView === 'function') {
				item.scrollIntoView({ block: 'nearest' });
			}
		});
	}

	function hydrateSections(sections) {
		state.sections = sanitizeSections(sections);
		state.selectedIndex = 0;
		renderAll();
	}


	// -------------------------------------------------------------------------
	// Bottom Panel Resize
	// -------------------------------------------------------------------------

	function setBottomHeight(value) {
		if (!dom.shell) {
			return;
		}

		var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 900;
		var minBottom = 240;
		var maxBottom = Math.max(minBottom, viewportHeight - 210);
		var clamped = Math.min(maxBottom, Math.max(minBottom, Math.round(value)));

		state.bottomHeight = clamped;
		dom.shell.style.setProperty('--pb-bottom-height', clamped + 'px');
		refreshCodeEditors();
	}


	// -------------------------------------------------------------------------
	// Cursor Scroll Sync
	// -------------------------------------------------------------------------

	var cursorScrollTimer = null;

	function scrollPreviewToHtmlCursor(cm) {
		if (!dom.previewFrame || !dom.previewFrame.contentWindow) {
			return;
		}

		try {
			var iframeDoc = dom.previewFrame.contentDocument || dom.previewFrame.contentWindow.document;
			if (!iframeDoc || !iframeDoc.body) {
				return;
			}

			var cursor = cm.getCursor();
			var line = cm.getLine(cursor.line);
			if (typeof line !== 'string') {
				return;
			}

			var idMatch = line.match(/id\s*=\s*["']([^"']+)["']/i);
			if (idMatch) {
				var el = iframeDoc.getElementById(idMatch[1]);
				if (el) {
					el.scrollIntoView({ behavior: 'smooth', block: 'center' });
					return;
				}
			}

			var classMatch = line.match(/class\s*=\s*["']([^"']+)["']/i);
			if (classMatch) {
				var firstClass = classMatch[1].trim().split(/\s+/)[0];
				if (firstClass) {
					var elByClass = iframeDoc.querySelector('.' + CSS.escape(firstClass));
					if (elByClass) {
						elByClass.scrollIntoView({ behavior: 'smooth', block: 'center' });
						return;
					}
				}
			}
		} catch (error) {
			// cross-origin or iframe not ready
		}
	}


	// -------------------------------------------------------------------------
	// CSS Class Autocomplete
	// -------------------------------------------------------------------------

	function extractClassesFromHtml() {
		var classMap = {};
		state.sections.forEach(function(section) {
			if (!section.content) {
				return;
			}
			var matches = section.content.match(/class\s*=\s*["']([^"']+)["']/gi);
			if (!matches) {
				return;
			}
			matches.forEach(function(match) {
				var inner = match.match(/class\s*=\s*["']([^"']+)["']/i);
				if (inner && inner[1]) {
					inner[1].trim().split(/\s+/).forEach(function(cls) {
						if (cls.length > 1) {
							classMap[cls] = true;
						}
					});
				}
			});
		});

		// Also include theme CSS classes from config
		if (Array.isArray(config.cssClasses)) {
			config.cssClasses.forEach(function(cls) {
				if (cls && cls.length > 1) {
					classMap[cls] = true;
				}
			});
		}

		return Object.keys(classMap).sort();
	}

	// -------------------------------------------------------------------------
	// Emmet expansion (minimal parser for 90% cases)
	// Supports: tag, .class, #id, [attr="val"], {text}, >, +, *N, $ numbering
	// -------------------------------------------------------------------------

	var EMMET_VOID_TAGS = {
		area:1,base:1,br:1,col:1,embed:1,hr:1,img:1,input:1,
		link:1,meta:1,param:1,source:1,track:1,wbr:1
	};

	function emmetParse(input) {
		var pos = 0;
		var len = input.length;

		function peek() { return pos < len ? input.charAt(pos) : ''; }
		function consume() { return input.charAt(pos++); }
		function match(re) { return re.test(peek()); }

		function parseIdentifier() {
			var s = '';
			while (pos < len && /[\w-]/.test(input.charAt(pos))) s += consume();
			return s;
		}

		function parseElement() {
			var tag = '';
			if (match(/[a-zA-Z]/)) tag = parseIdentifier();
			if (!tag) tag = 'div';

			var node = { tag: tag, classes: [], id: '', attrs: [], text: '', children: [], multiply: 1 };

			while (pos < len) {
				var c = peek();
				if (c === '.') {
					consume();
					var cls = parseIdentifier();
					if (cls) node.classes.push(cls);
				} else if (c === '#') {
					consume();
					node.id = parseIdentifier();
				} else if (c === '[') {
					consume();
					var attrStr = '';
					while (pos < len && peek() !== ']') attrStr += consume();
					if (peek() === ']') consume();
					node.attrs.push(attrStr.trim());
				} else if (c === '{') {
					consume();
					var text = '';
					while (pos < len && peek() !== '}') text += consume();
					if (peek() === '}') consume();
					node.text = text;
				} else if (c === '*') {
					consume();
					var num = '';
					while (pos < len && /\d/.test(peek())) num += consume();
					node.multiply = parseInt(num, 10) || 1;
				} else {
					break;
				}
			}

			return node;
		}

		function parseSequence() {
			var first = parseElement();
			if (peek() === '>') {
				consume();
				first.children.push(parseSequence());
			}
			if (peek() === '+') {
				consume();
				var sibling = parseSequence();
				return { siblings: [first, sibling] };
			}
			return first;
		}

		return parseSequence();
	}

	function emmetRender(node, index) {
		// Flatten sibling wrappers
		if (node.siblings) {
			var parts = [];
			node.siblings.forEach(function(n) {
				parts.push(emmetRender(n, index));
			});
			return parts.join('\n');
		}

		var out = '';
		var count = node.multiply || 1;
		for (var i = 1; i <= count; i++) {
			var currentIdx = i;
			var classes = node.classes.map(function(c) { return c.replace(/\$+/g, currentIdx); });
			var id = node.id.replace(/\$+/g, currentIdx);
			var text = node.text.replace(/\$+/g, currentIdx);

			var attrs = '';
			if (id) attrs += ' id="' + id + '"';
			if (classes.length) attrs += ' class="' + classes.join(' ') + '"';
			node.attrs.forEach(function(a) { attrs += ' ' + a; });

			if (EMMET_VOID_TAGS[node.tag]) {
				out += '<' + node.tag + attrs + '>';
			} else {
				var childrenHtml = '';
				node.children.forEach(function(c) { childrenHtml += emmetRender(c, currentIdx); });

				if (text && !childrenHtml) {
					out += '<' + node.tag + attrs + '>' + text + '</' + node.tag + '>';
				} else if (childrenHtml) {
					out += '<' + node.tag + attrs + '>\n\t' + childrenHtml.split('\n').join('\n\t') + '\n</' + node.tag + '>';
				} else {
					out += '<' + node.tag + attrs + '></' + node.tag + '>';
				}
			}
			if (i < count) out += '\n';
		}
		return out;
	}

	function tryEmmetExpand(cm) {
		var cursor = cm.getCursor();
		var line = cm.getLine(cursor.line);
		if (typeof line !== 'string') return false;

		var uptoCursor = line.slice(0, cursor.ch);

		// Don't expand if cursor is inside a tag or attribute value
		var lastLt = uptoCursor.lastIndexOf('<');
		var lastGt = uptoCursor.lastIndexOf('>');
		if (lastLt > lastGt) return false;

		// Match abbreviation at end: tag chars followed by emmet operators
		// Characters allowed: a-zA-Z0-9 . # > + * [ ] { } = " ' - _ $
		var match = uptoCursor.match(/([a-zA-Z][a-zA-Z0-9]*[\w.#>+*\[\]\{\}="'\-\$]*)$/);
		if (!match) return false;

		var abbr = match[1];

		// Require some emmet operator to trigger (avoid expanding plain words)
		if (!/[.#>+*\[\{]/.test(abbr)) return false;

		var expanded;
		try {
			var tree = emmetParse(abbr);
			expanded = emmetRender(tree, 1);
		} catch (e) {
			return false;
		}

		if (!expanded || expanded.indexOf('<') === -1) return false;

		// Replace abbreviation with expansion
		cm.replaceRange(
			expanded,
			{ line: cursor.line, ch: cursor.ch - abbr.length },
			cursor
		);

		// Place cursor at first empty text slot
		var newPos = findFirstCursorSlot(cm, cursor.line, cursor.ch - abbr.length, expanded);
		if (newPos) cm.setCursor(newPos);

		return true;
	}

	function findFirstCursorSlot(cm, startLine, startCh, expanded) {
		// Find first empty >< pair or empty "" attr
		var lines = expanded.split('\n');
		for (var i = 0; i < lines.length; i++) {
			var ln = lines[i];
			// Empty attribute value: class="" / href=""
			var attrMatch = ln.match(/\w+=""/);
			if (attrMatch) {
				return { line: startLine + i, ch: (i === 0 ? startCh : 0) + attrMatch.index + attrMatch[0].indexOf('"') + 1 };
			}
			// Empty element body: ><
			var bodyMatch = ln.match(/><\//);
			if (bodyMatch) {
				return { line: startLine + i, ch: (i === 0 ? startCh : 0) + bodyMatch.index + 1 };
			}
		}
		return null;
	}

	function getHtmlClassHintData(cm) {
		if (!window.CodeMirror || !window.CodeMirror.Pos) {
			return null;
		}

		var cursor = cm.getCursor();
		var line = cm.getLine(cursor.line);
		if (typeof line !== 'string') {
			return null;
		}

		var uptoCursor = line.slice(0, cursor.ch);

		// Match when cursor is inside class="..." value
		var classMatch = uptoCursor.match(/class\s*=\s*["']([^"']*)$/i);
		if (!classMatch) {
			return null;
		}

		// Extract the partial class name being typed (last word after space)
		var classValue = classMatch[1];
		var fragmentMatch = classValue.match(/(?:^|\s)([^\s]*)$/);
		var fragment = fragmentMatch ? fragmentMatch[1].toLowerCase() : '';
		var start = cursor.ch - (fragmentMatch ? fragmentMatch[1].length : 0);

		var allClasses = extractClassesFromHtml();
		if (!allClasses.length) {
			return null;
		}

		// Prefix match first, then contains match
		var list = allClasses.filter(function(cls) {
			return !fragment || cls.toLowerCase().indexOf(fragment) === 0;
		});

		if (!list.length && fragment) {
			list = allClasses.filter(function(cls) {
				return cls.toLowerCase().indexOf(fragment) !== -1;
			});
		}

		if (!list.length) {
			return null;
		}

		return {
			list: list.slice(0, 200),
			from: window.CodeMirror.Pos(cursor.line, start),
			to: window.CodeMirror.Pos(cursor.line, cursor.ch)
		};
	}

	function triggerHtmlClassHint(cm) {
		if (!cm || typeof cm.showHint !== 'function') {
			return;
		}
		if (cm.state && cm.state.completionActive) {
			return;
		}
		if (!getHtmlClassHintData(cm)) {
			return;
		}

		cm.showHint({
			hint: function(instance) {
				return getHtmlClassHintData(instance);
			},
			completeSingle: false
		});
	}

	function getCssClassHintData(cm) {
		if (!window.CodeMirror || !window.CodeMirror.Pos) {
			return null;
		}

		var cursor = cm.getCursor();
		var line = cm.getLine(cursor.line);
		if (typeof line !== 'string') {
			return null;
		}

		var uptoCursor = line.slice(0, cursor.ch);
		var dotMatch = uptoCursor.match(/\.([a-zA-Z_-][\w-]*)$/);
		if (!dotMatch) {
			return null;
		}

		var fragment = dotMatch[1].toLowerCase();
		var start = cursor.ch - dotMatch[1].length;
		var htmlClasses = extractClassesFromHtml();
		if (!htmlClasses.length) {
			return null;
		}

		var list = htmlClasses.filter(function(cls) {
			return cls.toLowerCase().indexOf(fragment) === 0;
		});

		if (!list.length) {
			list = htmlClasses.filter(function(cls) {
				return cls.toLowerCase().indexOf(fragment) !== -1;
			});
		}

		if (!list.length) {
			return null;
		}

		return {
			list: list.slice(0, 200),
			from: window.CodeMirror.Pos(cursor.line, start),
			to: window.CodeMirror.Pos(cursor.line, cursor.ch)
		};
	}


	// -------------------------------------------------------------------------
	// Code Editors
	// -------------------------------------------------------------------------

	function setupCodeEditors() {
		var hasCodeEditor = !!(window.wp && wp.codeEditor && typeof wp.codeEditor.initialize === 'function');
		state.hasCodeMirror = hasCodeEditor;

		if (!hasCodeEditor) {
			dom.textareaHtml.addEventListener('input', function(event) {
				updateCurrentSectionField('content', event.target.value);
			});
			dom.textareaCss.addEventListener('input', function(event) {
				updateCurrentSectionField('css', event.target.value);
			});
			dom.textareaJs.addEventListener('input', function(event) {
				updateCurrentSectionField('js', event.target.value);
			});
			dom.textareaHtml.addEventListener('focus', function() {
				state.activeEditorKey = 'html';
			});
			dom.textareaCss.addEventListener('focus', function() {
				state.activeEditorKey = 'css';
			});
			dom.textareaJs.addEventListener('focus', function() {
				state.activeEditorKey = 'js';
			});
			return;
		}

		var map = [
			{ key: 'html', textarea: dom.textareaHtml, field: 'content' },
			{ key: 'css', textarea: dom.textareaCss, field: 'css' },
			{ key: 'js', textarea: dom.textareaJs, field: 'js' }
		];

		map.forEach(function(item) {
			var settings = config.codeEditorSettings && config.codeEditorSettings[item.key] ? config.codeEditorSettings[item.key] : {};

			// Tab in HTML editor = Emmet expansion
			if (item.key === 'html') {
				var htmlCmSettings = settings.codemirror || {};
				var htmlExtraKeys = htmlCmSettings.extraKeys || {};
				htmlExtraKeys['Tab'] = function(cm) {
					if (cm.somethingSelected()) {
						// If text is selected, indent it (default Tab behavior)
						return window.CodeMirror ? window.CodeMirror.Pass : undefined;
					}
					var expanded = tryEmmetExpand(cm);
					if (expanded) return;
					// Otherwise, default Tab (insert tab/indent)
					return window.CodeMirror ? window.CodeMirror.Pass : undefined;
				};
				htmlCmSettings.extraKeys = htmlExtraKeys;
				settings.codemirror = htmlCmSettings;
			}

			if (item.key === 'css') {
				var cmSettings = settings.codemirror || {};
				var extraKeys = cmSettings.extraKeys || {};
				extraKeys['Ctrl-Space'] = function(cm) {
					var hintData = getCssClassHintData(cm);
					if (hintData && cm.showHint) {
						cm.showHint({
							hint: function() { return getCssClassHintData(cm); },
							completeSingle: false
						});
					}
				};
				extraKeys['Cmd-Space'] = extraKeys['Ctrl-Space'];
				cmSettings.extraKeys = extraKeys;
				settings.codemirror = cmSettings;
			}

			var editor = wp.codeEditor.initialize(item.textarea, settings);
			if (!editor || !editor.codemirror) {
				return;
			}

			state.editors[item.key] = editor;
			editor.codemirror.on('focus', function() {
				state.activeEditorKey = item.key;
			});
			editor.codemirror.on('change', function(instance) {
				if (state.syncingEditors) {
					return;
				}
				updateCurrentSectionField(item.field, instance.getValue());
			});

			if (item.key === 'html') {
				editor.codemirror.on('cursorActivity', function(instance) {
					if (cursorScrollTimer) {
						window.clearTimeout(cursorScrollTimer);
					}
					cursorScrollTimer = window.setTimeout(function() {
						scrollPreviewToHtmlCursor(instance);
					}, 400);
				});

			}
		});
	}

	function focusEditor(editorKey) {
		state.activeEditorKey = editorKey;

		if (editorKey === 'css') {
			setRightPaneMode('css');
		}

		if (editorKey === 'js') {
			setRightPaneMode('js');
		}

		if (state.hasCodeMirror && state.editors[editorKey] && state.editors[editorKey].codemirror) {
			state.editors[editorKey].codemirror.focus();
			return;
		}

		if (editorKey === 'html' && dom.textareaHtml) {
			dom.textareaHtml.focus();
			return;
		}

		if (editorKey === 'css' && dom.textareaCss) {
			dom.textareaCss.focus();
			return;
		}

		if (editorKey === 'js' && dom.textareaJs) {
			dom.textareaJs.focus();
		}
	}


	// -------------------------------------------------------------------------
	// AI Chat Sidebar
	// -------------------------------------------------------------------------

	function getActiveTab() {
		var editors = state.editors;
		var keys = ['html', 'css', 'js'];
		for (var i = 0; i < keys.length; i++) {
			var ed = editors[keys[i]];
			if (ed && ed.codemirror && ed.codemirror.hasFocus()) {
				return keys[i];
			}
		}
		if (keys.indexOf(state.activeEditorKey) !== -1) {
			return state.activeEditorKey;
		}
		if (state.rightPaneMode === 'js') {
			return 'js';
		}
		if (state.rightPaneMode === 'css') {
			return 'css';
		}
		return 'html';
	}

	function toggleAiPanel(forceOpen) {
		var shouldOpen = typeof forceOpen === 'boolean' ? forceOpen : !state.aiOpen;
		state.aiOpen = shouldOpen;

		if (dom.shell) {
			dom.shell.classList.toggle('is-ai-open', shouldOpen);
		}

		if (shouldOpen && dom.aiInput) {
			window.setTimeout(function() { dom.aiInput.focus(); }, 100);
		}

		if (!shouldOpen && state.aiBusy && state.aiXhr) {
			state.aiXhr.abort();
			state.aiBusy = false;
			state.aiXhr = null;
		}
	}

	function captureSelectionAndOpenPrompt() {
		state.aiSelection = '';
		state.aiSelectionEditor = null;

		var activeTab = getActiveTab();
		var editorKeys = [activeTab].concat(['html', 'css', 'js'].filter(function(k) { return k !== activeTab; }));

		for (var i = 0; i < editorKeys.length; i++) {
			var key = editorKeys[i];
			var ed = state.editors[key];
			var cm = ed && ed.codemirror ? ed.codemirror : null;
			if (cm && typeof cm.getSelection === 'function') {
				var sel = cm.getSelection();
				if (sel && sel.length > 0) {
					state.aiSelection = sel;
					state.aiSelectionEditor = key;
					break;
				}
			}
		}

		toggleAiPanel(true);

		if (state.aiSelection && dom.aiSelectionBadge) {
			dom.aiSelectionBadge.style.display = '';
			dom.aiSelectionBadge.textContent = state.aiSelectionEditor.toUpperCase() + ' sel';
		} else if (dom.aiSelectionBadge) {
			dom.aiSelectionBadge.style.display = 'none';
		}
	}

	function startNewAiChat() {
		state.aiMessages = [];
		state.aiSelection = '';
		state.aiSelectionEditor = null;
		if (dom.aiSelectionBadge) {
			dom.aiSelectionBadge.style.display = 'none';
		}
		if (dom.aiInput) {
			dom.aiInput.value = '';
		}
		renderAiMessages();
	}

	function addAiMessage(role, content) {
		state.aiMessages.push({ role: role, content: content });
		renderAiMessages();
	}

	function renderAiMessages() {
		if (!dom.aiMessages) return;
		dom.aiMessages.innerHTML = '';

		if (!state.aiMessages.length) {
			var empty = document.createElement('div');
			empty.className = 'md-pb-ai-empty';
			empty.innerHTML = '<div class="md-pb-ai-empty-icon">AI</div><p>Ask AI to generate or edit code for the current section.</p><p class="md-pb-ai-hint">Tip: Select code first, then open AI to edit just that selection.</p>';
			dom.aiMessages.appendChild(empty);
			return;
		}

		state.aiMessages.forEach(function(msg) {
			var bubble = document.createElement('div');
			bubble.className = 'md-pb-ai-msg md-pb-ai-msg--' + msg.role;

			if (msg.role === 'user') {
				bubble.textContent = msg.content;
			} else {
				// Assistant: show code with "Apply" button
				var pre = document.createElement('pre');
				pre.className = 'md-pb-ai-code';
				pre.textContent = msg.content;

				var applyBtn = document.createElement('button');
				applyBtn.type = 'button';
				applyBtn.className = 'md-pb-button md-pb-button-primary md-pb-ai-apply-btn';
				applyBtn.textContent = 'Apply to section';
				applyBtn.addEventListener('click', function() {
					applyAiCode(msg.content);
					applyBtn.textContent = 'Applied';
					applyBtn.disabled = true;
				});

				bubble.appendChild(pre);
				bubble.appendChild(applyBtn);
			}

			dom.aiMessages.appendChild(bubble);
		});

		// Scroll to bottom
		dom.aiMessages.scrollTop = dom.aiMessages.scrollHeight;
	}

	function applyAiCode(code) {
		var tab = getActiveTab();
		var fieldMap = { html: 'content', css: 'css', js: 'js' };

		// Extract bundled CSS/JS from HTML
		var extracted = null;
		if (tab === 'html') {
			extracted = extractAiGeneratedAssets(code);
			if (extracted.hasBundle && extracted.html) {
				code = extracted.html;
			} else {
				extracted = null;
			}
		}

		var field = fieldMap[tab];
		if (tab === 'css' || tab === 'js') {
			setRightPaneMode(tab);
		}

		setSectionFieldFromAi(tab, field, code);

		if (extracted && extracted.hasBundle) {
			if (extracted.hasCssTag) {
				setSectionFieldFromAi('css', 'css', extracted.css);
			}
			if (extracted.hasJsTag) {
				setSectionFieldFromAi('js', 'js', extracted.js);
			}
		}

		queuePreviewRender();
	}

	function populateTemplateSelect() {
		if (!dom.templateSelect || !Array.isArray(config.availableTemplates)) {
			return;
		}

		config.availableTemplates.forEach(function(tpl) {
			var opt = document.createElement('option');
			opt.value = tpl.slug;
			opt.textContent = tpl.label;
			dom.templateSelect.appendChild(opt);
		});

		dom.templateSelect.value = state.pageTemplate;
	}

	function populateAiModelSelect() {
		if (!dom.aiModelSelect || !Array.isArray(config.aiModels)) {
			return;
		}

		var providerFlags = {
			openai: !!config.aiHasOpenAI,
			anthropic: !!config.aiHasAnthropic,
			gemini: !!config.aiHasGemini
		};

		var groups = {};
		config.aiModels.forEach(function(m) {
			if (!providerFlags[m.provider]) {
				return;
			}
			if (!groups[m.provider]) {
				groups[m.provider] = [];
			}
			groups[m.provider].push(m);
		});

		var providerLabels = { openai: 'OpenAI', anthropic: 'Anthropic', gemini: 'Google' };
		dom.aiModelSelect.textContent = '';

		Object.keys(groups).forEach(function(provider) {
			var optgroup = document.createElement('optgroup');
			optgroup.label = providerLabels[provider] || provider;
			groups[provider].forEach(function(m) {
				var opt = document.createElement('option');
				opt.value = m.id;
				opt.textContent = m.label;
				if (m.id === state.aiModel) {
					opt.selected = true;
				}
				optgroup.appendChild(opt);
			});
			dom.aiModelSelect.appendChild(optgroup);
		});

		if (dom.aiModelSelect.options.length === 0) {
			var noOpt = document.createElement('option');
			noOpt.textContent = 'No API keys configured';
			noOpt.disabled = true;
			dom.aiModelSelect.appendChild(noOpt);
		}
	}

	function hasAiGeneratedId(attributes) {
		if (!attributes || typeof attributes !== 'string') {
			return false;
		}
		return /\bid\s*=\s*(['"])ai-generated\1/i.test(attributes) ||
			/\bid\s*=\s*ai-generated(?:\s|$)/i.test(attributes);
	}

	function extractAiGeneratedAssets(htmlCode) {
		var source = typeof htmlCode === 'string' ? htmlCode : '';
		var result = { html: source, css: '', js: '', hasBundle: false, hasCssTag: false, hasJsTag: false };

		if (!source) return result;

		var html = source.replace(/<style\b([^>]*)>([\s\S]*?)<\/style>/gi, function(match, attrs, inner) {
			if (!hasAiGeneratedId(attrs)) return match;
			result.hasBundle = true;
			result.hasCssTag = true;
			var c = (inner || '').trim();
			if (c) result.css = result.css ? (result.css + '\n\n' + c) : c;
			return '';
		});

		html = html.replace(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi, function(match, attrs, inner) {
			if (!hasAiGeneratedId(attrs)) return match;
			result.hasBundle = true;
			result.hasJsTag = true;
			var c = (inner || '').trim();
			if (c) result.js = result.js ? (result.js + '\n\n' + c) : c;
			return '';
		});

		result.html = html.replace(/\n{3,}/g, '\n\n').trim();
		return result;
	}

	function setSectionFieldFromAi(editorKey, field, value) {
		updateCurrentSectionField(field, value);

		var editor = state.editors[editorKey];
		var cm = editor && editor.codemirror ? editor.codemirror : null;
		if (cm && typeof cm.setValue === 'function') {
			state.syncingEditors = true;
			cm.setValue(value);
			state.syncingEditors = false;
			if (typeof cm.refresh === 'function') {
				window.setTimeout(function() { cm.refresh(); }, 0);
			}
			return;
		}

		if (editorKey === 'html' && dom.textareaHtml) dom.textareaHtml.value = value;
		else if (editorKey === 'css' && dom.textareaCss) dom.textareaCss.value = value;
		else if (editorKey === 'js' && dom.textareaJs) dom.textareaJs.value = value;
	}

	function submitAiPrompt() {
		if (!config.aiEndpoint || !config.aiAction) {
			window.alert('AI is not configured. Add API keys in MD Settings > Page Blocks.');
			return;
		}

		if (state.aiBusy) return;

		var prompt = dom.aiInput ? dom.aiInput.value.trim() : '';
		if (!prompt) return;

		// Clear input immediately
		if (dom.aiInput) dom.aiInput.value = '';

		if (dom.aiModelSelect) {
			state.aiModel = dom.aiModelSelect.value;
		}

		// Add user message to chat
		addAiMessage('user', prompt);

		var tab = state.aiSelection ? 'html' : getActiveTab();
		var fieldMap = { html: 'content', css: 'css', js: 'js' };
		var section = getCurrentSection();
		var existing = section ? (section[fieldMap[tab]] || '') : '';
		var ctxHtml = section ? (section.content || '') : '';
		var ctxCss = section ? (section.css || '') : '';
		var selection = state.aiSelectionEditor === tab ? (state.aiSelection || '') : '';

		// Build conversation history for multi-turn (exclude current message)
		var history = [];
		for (var i = 0; i < state.aiMessages.length - 1; i++) {
			history.push({
				role: state.aiMessages[i].role,
				content: state.aiMessages[i].content
			});
		}

		state.aiBusy = true;
		if (dom.aiGenerateButton) dom.aiGenerateButton.disabled = true;

		// Show thinking indicator
		var thinkingEl = document.createElement('div');
		thinkingEl.className = 'md-pb-ai-msg md-pb-ai-msg--thinking';
		thinkingEl.textContent = 'Thinking...';
		if (dom.aiMessages) {
			dom.aiMessages.appendChild(thinkingEl);
			dom.aiMessages.scrollTop = dom.aiMessages.scrollHeight;
		}

		var formData = new FormData();
		formData.append('action', config.aiAction);
		formData.append('post_id', String(config.postId || 0));
		formData.append('pb_nonce', config.saveNonce || '');
		formData.append('prompt', prompt);
		formData.append('tab', tab);
		formData.append('existing_code', existing);
		formData.append('selection', selection);
		formData.append('model', state.aiModel);
		formData.append('context_html', ctxHtml);
		formData.append('context_css', ctxCss);
		formData.append('page_url', config.viewPostUrl || '');
		formData.append('css_context', config.aiCssContext || '');
		formData.append('history', JSON.stringify(history));

		var xhr = new XMLHttpRequest();
		state.aiXhr = xhr;
		xhr.open('POST', config.aiEndpoint);
		xhr.timeout = 120000;

		function resetAiUi() {
			state.aiBusy = false;
			state.aiXhr = null;
			if (dom.aiGenerateButton) dom.aiGenerateButton.disabled = false;
			if (thinkingEl && thinkingEl.parentNode) thinkingEl.remove();
			// Clear selection after first use
			state.aiSelection = '';
			state.aiSelectionEditor = null;
			if (dom.aiSelectionBadge) dom.aiSelectionBadge.style.display = 'none';
		}

		xhr.onload = function() {
			resetAiUi();

			var result;
			try {
				result = JSON.parse(xhr.responseText);
			} catch (e) {
				addAiMessage('assistant', 'Error: Invalid response from server.');
				return;
			}

			if (!result || !result.success) {
				var errMsg = (result && result.data && result.data.message) || 'Unknown error';
				addAiMessage('assistant', 'Error: ' + errMsg);
				return;
			}

			var code = result.data && result.data.code ? result.data.code : '';
			if (code) {
				addAiMessage('assistant', code);
			}
		};
		xhr.onerror = function() {
			resetAiUi();
			addAiMessage('assistant', 'Error: Network request failed.');
		};
		xhr.ontimeout = function() {
			resetAiUi();
			addAiMessage('assistant', 'Error: Request timed out. Try a shorter prompt or faster model.');
		};
		xhr.send(formData);
	}


	// -------------------------------------------------------------------------
	// Layout
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// HTML Snippet Insert / Wrap
	// -------------------------------------------------------------------------

	function insertHtmlSnippet(tag, selfClosing) {
		var editor = state.editors.html;
		var cm = editor && editor.codemirror ? editor.codemirror : null;

		if (!cm) {
			// Fallback for plain textarea
			if (dom.textareaHtml) {
				var ta = dom.textareaHtml;
				var start = ta.selectionStart;
				var end = ta.selectionEnd;
				var sel = ta.value.substring(start, end);
				var snippet = buildSnippet(tag, sel, selfClosing);
				ta.value = ta.value.substring(0, start) + snippet.text + ta.value.substring(end);
				ta.selectionStart = ta.selectionEnd = start + snippet.cursor;
				ta.focus();
				updateCurrentSectionField('content', ta.value);
			}
			return;
		}

		var sel = cm.getSelection();
		var snippet = buildSnippet(tag, sel, selfClosing);

		cm.replaceSelection(snippet.text, 'around');

		// Place cursor inside the tag
		var from = cm.getCursor('from');
		var lines = snippet.text.substring(0, snippet.cursor).split('\n');
		var cursorLine = from.line + lines.length - 1;
		var cursorCh = lines.length > 1 ? lines[lines.length - 1].length : from.ch + lines[0].length;
		cm.setCursor({ line: cursorLine, ch: cursorCh });
		cm.focus();
	}

	function buildSnippet(tag, selection, selfClosing) {
		var sel = selection || '';
		var text, cursor;

		if (selfClosing) {
			// e.g. <img src="" alt="">
			if (tag === 'img') {
				text = '<img src="" alt="' + sel + '">';
				cursor = 10; // inside src=""
			} else {
				text = '<' + tag + '>';
				cursor = text.length;
			}
			return { text: text, cursor: cursor };
		}

		switch (tag) {
			case 'a':
				if (sel) {
					text = '<a href="">' + sel + '</a>';
					cursor = 9; // inside href=""
				} else {
					text = '<a href="">\n\t\n</a>';
					cursor = 9;
				}
				break;

			case 'ul':
				if (sel) {
					// Wrap each line as <li>
					var ulItems = sel.split('\n').map(function(line) {
						var trimmed = line.trim();
						return trimmed ? '\t<li>' + trimmed + '</li>' : '';
					}).filter(Boolean).join('\n');
					text = '<ul>\n' + ulItems + '\n</ul>';
				} else {
					text = '<ul>\n\t<li></li>\n</ul>';
				}
				cursor = text.indexOf('</li>');
				break;

			case 'ol':
				if (sel) {
					var olItems = sel.split('\n').map(function(line) {
						var trimmed = line.trim();
						return trimmed ? '\t<li>' + trimmed + '</li>' : '';
					}).filter(Boolean).join('\n');
					text = '<ol>\n' + olItems + '\n</ol>';
				} else {
					text = '<ol>\n\t<li></li>\n</ol>';
				}
				cursor = text.indexOf('</li>');
				break;

			case 'section':
				if (sel) {
					text = '<section class="">\n' + sel + '\n</section>';
					cursor = 16; // inside class=""
				} else {
					text = '<section class="">\n\t\n</section>';
					cursor = 16;
				}
				break;

			case 'div':
				if (sel) {
					text = '<div class="">\n' + sel + '\n</div>';
					cursor = 12; // inside class=""
				} else {
					text = '<div class="">\n\t\n</div>';
					cursor = 12;
				}
				break;

			default:
				// h1, h2, h3, p, span, strong, em, etc.
				if (sel) {
					text = '<' + tag + '>' + sel + '</' + tag + '>';
				} else {
					text = '<' + tag + '></' + tag + '>';
				}
				cursor = tag.length + 2; // right after opening tag
				break;
		}

		return { text: text, cursor: cursor };
	}


	// -------------------------------------------------------------------------
	// Layout
	// -------------------------------------------------------------------------

	function setupLayout() {
		app.innerHTML = '';

		var shell = document.createElement('div');
		shell.className = 'md-pb-shell';
		shell.innerHTML = '' +
			'<div class="md-pb-topbar">' +
				'<div class="md-pb-topbar-left">' +
					'<div class="md-pb-brand">Page Blocks</div>' +
					'<span class="md-pb-divider" aria-hidden="true"></span>' +
					'<button type="button" class="md-pb-toggle-btn is-active" data-role="toggle-sections" aria-pressed="true">\u2630 Sections</button>' +
					'<button type="button" class="md-pb-toggle-btn is-active" data-role="toggle-code" aria-pressed="true">&lt;/&gt; Code</button>' +
					'<button type="button" class="md-pb-toggle-btn is-active" data-role="toggle-preview" aria-pressed="true">&#9655; Preview</button>' +
					'<button type="button" class="md-pb-toggle-btn" data-role="toggle-ai">AI</button>' +
				'</div>' +
				'<div class="md-pb-topbar-actions">' +
					'<button type="button" class="md-pb-button md-pb-button-primary" data-role="apply">Save</button>' +
					'<button type="button" class="md-pb-button md-pb-button-preview" data-role="preview-frontend">Preview</button>' +
				'</div>' +
				'<div class="md-pb-topbar-right">' +
					(config.libraryEndpoint ? '<button type="button" class="md-pb-button" data-role="library" title="Section Library">\u2261 Library</button>' : '') +
					'<button type="button" class="md-pb-button" data-role="export" title="Export sections">Export</button>' +
					'<button type="button" class="md-pb-button" data-role="import" title="Import sections">Import</button>' +
					'<button type="button" class="md-pb-button" data-role="shortcuts" title="Keyboard shortcuts">?</button>' +
					'<button type="button" class="md-pb-button" data-role="cancel">Close</button>' +
				'</div>' +
			'</div>' +
			'<div class="md-pb-main">' +
				'<aside class="md-pb-ai-panel" data-role="ai-panel">' +
					'<div class="md-pb-ai-header">' +
						'<span class="md-pb-ai-title">AI Assistant</span>' +
						'<div class="md-pb-ai-header-actions">' +
							'<button type="button" class="md-pb-icon-btn" data-role="ai-new-chat" title="New chat">+</button>' +
							'<button type="button" class="md-pb-icon-btn" data-role="ai-close" title="Close">&times;</button>' +
						'</div>' +
					'</div>' +
					'<div class="md-pb-ai-model-row">' +
						'<select class="md-pb-ai-model-select" data-role="ai-model-select"></select>' +
						'<span class="md-pb-ai-selection-badge" data-role="ai-selection-badge" style="display:none">Selection</span>' +
					'</div>' +
					'<div class="md-pb-ai-messages" data-role="ai-messages"></div>' +
					'<div class="md-pb-ai-input-area">' +
						'<textarea class="md-pb-ai-input" data-role="ai-input" placeholder="Describe what to build or change..." rows="2" spellcheck="false"></textarea>' +
						'<button type="button" class="md-pb-button md-pb-button-primary md-pb-ai-send-btn" data-role="ai-generate">Send</button>' +
					'</div>' +
				'</aside>' +
				'<div class="md-pb-canvas-wrap">' +
					'<div class="md-pb-canvas-toolbar">' +
						'<span>Preview</span>' +
						'<div class="md-pb-viewport-controls">' +
							'<button type="button" class="md-pb-viewport-btn is-active" data-role="viewport-button" data-viewport="desktop" aria-pressed="true">Desktop</button>' +
							'<button type="button" class="md-pb-viewport-btn" data-role="viewport-button" data-viewport="992" aria-pressed="false">992</button>' +
							'<button type="button" class="md-pb-viewport-btn" data-role="viewport-button" data-viewport="768" aria-pressed="false">768</button>' +
							'<button type="button" class="md-pb-viewport-btn" data-role="viewport-button" data-viewport="480" aria-pressed="false">480</button>' +
							'<button type="button" class="md-pb-viewport-btn" data-role="viewport-button" data-viewport="360" aria-pressed="false">360</button>' +
						'</div>' +
					'</div>' +
					'<iframe class="md-pb-preview-frame" sandbox="allow-scripts allow-same-origin" title="Page Blocks Preview"></iframe>' +
				'</div>' +
				'<aside class="md-pb-index">' +
					'<div class="md-pb-template-bar">' +
						'<label class="md-pb-template-label">Template</label>' +
						'<select class="md-pb-template-select" data-role="template-select"></select>' +
					'</div>' +
					'<div class="md-pb-index-header">' +
						'<span>Sections</span>' +
						'<div class="md-pb-index-header-actions">' +
							'<span data-role="section-count">0</span>' +
						'</div>' +
					'</div>' +
					'<ul class="md-pb-index-list" data-role="index-list"></ul>' +
					'<button type="button" class="md-pb-add-section-btn" data-role="add-section">+ Add Section</button>' +
					'<div class="md-pb-meta">' +
						'<div class="md-pb-meta-title">Active Section</div>' +
						'<input type="text" class="md-pb-meta-id" data-role="active-section-id" readonly>' +
						'<div class="md-pb-meta-classes" data-role="active-section-classes"></div>' +
					'</div>' +
				'</aside>' +
			'</div>' +
			'<div class="md-pb-splitter" data-role="splitter">' +
				'<button type="button" class="md-pb-splitter-handle" data-role="splitter-handle" title="Drag to resize preview and code" aria-label="Resize preview and code panels"></button>' +
			'</div>' +
			'<div class="md-pb-bottom">' +
				'<div class="md-pb-bottom-toolbar">' +
					'<div class="md-pb-bottom-title">Code</div>' +
					'<div class="md-pb-options">' +
						'<label class="md-pb-chip"><input type="checkbox" data-role="format"><span>wpauto</span></label>' +
						'<label class="md-pb-chip"><input type="checkbox" data-role="php-exec"><span>PHP</span></label>' +
						'<label class="md-pb-select-wrap">JS: <select data-role="js-location"><option value="footer">Footer</option><option value="inline">Inline</option></select></label>' +
					'</div>' +
				'</div>' +
					'<div class="md-pb-code-wrap">' +
					'<div class="md-pb-code-grid">' +
						'<div class="md-pb-code-column">' +
							'<div class="md-pb-code-title md-pb-code-title-html">' +
								'<span>HTML</span>' +
								'<div class="md-pb-snippets" data-role="html-snippets">' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="section" title="&lt;section&gt;">sec</button>' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="div" title="&lt;div&gt;">div</button>' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="h1" title="&lt;h1&gt;">h1</button>' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="h2" title="&lt;h2&gt;">h2</button>' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="h3" title="&lt;h3&gt;">h3</button>' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="p" title="&lt;p&gt;">p</button>' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="a" title="&lt;a&gt;">a</button>' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="img" data-self-closing="1" title="&lt;img&gt;">img</button>' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="ul" title="&lt;ul&gt;&lt;li&gt;">ul</button>' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="ol" title="&lt;ol&gt;&lt;li&gt;">ol</button>' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="span" title="&lt;span&gt;">span</button>' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="strong" title="&lt;strong&gt;">b</button>' +
									'<button type="button" class="md-pb-snippet-btn" data-tag="em" title="&lt;em&gt;">i</button>' +
								'</div>' +
							'</div>' +
							'<div class="md-pb-code-pane"><textarea class="md-pb-code-textarea" data-role="textarea-html" spellcheck="false"></textarea></div>' +
						'</div>' +
						'<div class="md-pb-code-column md-pb-code-column-right">' +
							'<div class="md-pb-code-title md-pb-code-title-swap"><span data-role="right-pane-label">CSS</span><button type="button" class="md-pb-icon-btn" data-role="swap-right-pane">JS</button></div>' +
							'<div class="md-pb-code-pane md-pb-code-pane--css"><textarea class="md-pb-code-textarea" data-role="textarea-css" spellcheck="false"></textarea></div>' +
							'<div class="md-pb-code-pane md-pb-code-pane--js"><textarea class="md-pb-code-textarea" data-role="textarea-js" spellcheck="false"></textarea></div>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>' +
			'<div class="md-pb-statusbar">' +
				'<div><span class="md-pb-live-dot"></span>Live preview</div>' +
				'<div data-role="status-count">0/0 sections</div>' +
			'</div>';

		app.appendChild(shell);

		dom.shell = shell;
		dom.previewFrame = shell.querySelector('.md-pb-preview-frame');
		dom.indexList = shell.querySelector('[data-role="index-list"]');
		dom.sectionCount = shell.querySelector('[data-role="section-count"]');
		dom.textareaHtml = shell.querySelector('[data-role="textarea-html"]');
		dom.textareaCss = shell.querySelector('[data-role="textarea-css"]');
		dom.textareaJs = shell.querySelector('[data-role="textarea-js"]');
		dom.rightPaneLabel = shell.querySelector('[data-role="right-pane-label"]');
		dom.swapRightPaneButton = shell.querySelector('[data-role="swap-right-pane"]');
		dom.jsLocation = shell.querySelector('[data-role="js-location"]');
		dom.format = shell.querySelector('[data-role="format"]');
		dom.phpExec = shell.querySelector('[data-role="php-exec"]');
		dom.applyButton = shell.querySelector('[data-role="apply"]');
		dom.previewFrontendButton = shell.querySelector('[data-role="preview-frontend"]');
		dom.cancelButton = shell.querySelector('[data-role="cancel"]');
		dom.addSectionButton = shell.querySelector('[data-role="add-section"]');
		dom.toggleCodeButton = shell.querySelector('[data-role="toggle-code"]');
		dom.togglePreviewButton = shell.querySelector('[data-role="toggle-preview"]');
		dom.toggleSectionsButton = shell.querySelector('[data-role="toggle-sections"]');
		dom.activeSectionId = shell.querySelector('[data-role="active-section-id"]');
		dom.activeSectionClasses = shell.querySelector('[data-role="active-section-classes"]');
		dom.statusCount = shell.querySelector('[data-role="status-count"]');
		dom.splitter = shell.querySelector('[data-role="splitter"]');
		dom.splitterHandle = shell.querySelector('[data-role="splitter-handle"]');
		dom.bottom = shell.querySelector('.md-pb-bottom');
		dom.viewportButtons = Array.prototype.slice.call(shell.querySelectorAll('[data-role="viewport-button"]'));
		dom.toggleAiButton = shell.querySelector('[data-role="toggle-ai"]');
		// dom.aiBar removed — replaced by dom.aiPanel
		dom.aiInput = shell.querySelector('[data-role="ai-input"]');
		dom.aiModelSelect = shell.querySelector('[data-role="ai-model-select"]');
		dom.aiSelectionBadge = shell.querySelector('[data-role="ai-selection-badge"]');
		dom.aiStatus = shell.querySelector('[data-role="ai-status"]');
		dom.aiGenerateButton = shell.querySelector('[data-role="ai-generate"]');
		dom.aiCloseButton = shell.querySelector('[data-role="ai-close"]');
		dom.aiNewChatButton = shell.querySelector('[data-role="ai-new-chat"]');
		dom.aiPanel = shell.querySelector('[data-role="ai-panel"]');
		dom.aiMessages = shell.querySelector('[data-role="ai-messages"]');
		dom.libraryButton = shell.querySelector('[data-role="library"]');
		dom.exportButton = shell.querySelector('[data-role="export"]');
		dom.importButton = shell.querySelector('[data-role="import"]');
		dom.shortcutsButton = shell.querySelector('[data-role="shortcuts"]');
		dom.htmlSnippets = shell.querySelector('[data-role="html-snippets"]');
		dom.templateSelect = shell.querySelector('[data-role="template-select"]');

		populateAiModelSelect();
		populateTemplateSelect();
		applyPreviewViewport();
		setBottomHeight(state.bottomHeight);
		setRightPaneMode(state.rightPaneMode);
		updatePanelVisibility();
	}


	// -------------------------------------------------------------------------
	// Events
	// -------------------------------------------------------------------------

	function bindButtonActivation(button, handler) {
		if (!button) {
			return;
		}
		var lastActivationAt = 0;

		function runHandler() {
			var now = Date.now();
			if (now - lastActivationAt < 220) {
				return;
			}
			lastActivationAt = now;
			handler();
		}

		button.addEventListener('click', function(event) {
			event.preventDefault();
			runHandler();
		});

		button.addEventListener('pointerup', function(event) {
			if (event.button !== 0) {
				return;
			}
			event.preventDefault();
			runHandler();
		});
	}

	function setupResizeEvents() {
		if (!dom.bottom && !dom.splitterHandle) {
			return;
		}

		function onPointerMove(event) {
			if (!state.resizeActive || event.pointerId !== state.resizePointerId) {
				return;
			}

			var delta = state.resizeStartY - event.clientY;
			setBottomHeight(state.resizeStartHeight + delta);
		}

		function onPointerEnd(event) {
			if (!state.resizeActive || event.pointerId !== state.resizePointerId) {
				return;
			}

			state.resizeActive = false;
			state.resizePointerId = null;
			dom.shell.classList.remove('is-resizing');

			window.removeEventListener('pointermove', onPointerMove);
			window.removeEventListener('pointerup', onPointerEnd);
			window.removeEventListener('pointercancel', onPointerEnd);
		}

		if (dom.splitterHandle) {
			dom.splitterHandle.addEventListener('pointerdown', function(event) {
				if (event.button !== 0) {
					return;
				}

				event.preventDefault();
				state.resizeActive = true;
				state.resizePointerId = event.pointerId;
				state.resizeStartY = event.clientY;
				state.resizeStartHeight = state.bottomHeight;
				dom.shell.classList.add('is-resizing');

				window.addEventListener('pointermove', onPointerMove);
				window.addEventListener('pointerup', onPointerEnd);
				window.addEventListener('pointercancel', onPointerEnd);
			});
		}

		if (dom.splitter) {
			dom.splitter.addEventListener('pointerdown', function(event) {
				if (event.button !== 0) {
					return;
				}

				event.preventDefault();
				state.resizeActive = true;
				state.resizePointerId = event.pointerId;
				state.resizeStartY = event.clientY;
				state.resizeStartHeight = state.bottomHeight;
				dom.shell.classList.add('is-resizing');

				window.addEventListener('pointermove', onPointerMove);
				window.addEventListener('pointerup', onPointerEnd);
				window.addEventListener('pointercancel', onPointerEnd);
			});
		}

		window.addEventListener('resize', function() {
			setBottomHeight(state.bottomHeight);
			applyPreviewViewport();
		});
	}

	function setupEvents() {
		dom.addSectionButton.addEventListener('click', function() {
			addSection(state.selectedIndex);
		});

		bindButtonActivation(dom.cancelButton, activateCancel);
		bindButtonActivation(dom.applyButton, activateApply);

		if (dom.previewFrontendButton) {
			dom.previewFrontendButton.addEventListener('click', function() {
				if (config.viewPostUrl) {
					window.open(config.viewPostUrl, '_blank');
				}
			});
		}

		if (dom.toggleCodeButton) {
			dom.toggleCodeButton.addEventListener('click', function() {
				state.showCode = !state.showCode;
				updatePanelVisibility();
			});
		}

		if (dom.togglePreviewButton) {
			dom.togglePreviewButton.addEventListener('click', function() {
				state.showPreview = !state.showPreview;
				updatePanelVisibility();
			});
		}

		if (dom.toggleSectionsButton) {
			dom.toggleSectionsButton.addEventListener('click', function() {
				state.showSidebar = !state.showSidebar;
				updatePanelVisibility();
			});
		}

		if (dom.swapRightPaneButton) {
			dom.swapRightPaneButton.addEventListener('click', function() {
				setRightPaneMode(getNextRightPaneMode());
			});
		}

		if (dom.toggleAiButton) {
			dom.toggleAiButton.addEventListener('click', function() {
				captureSelectionAndOpenPrompt();
			});
		}

		if (dom.aiGenerateButton) {
			dom.aiGenerateButton.addEventListener('click', function() {
				submitAiPrompt();
			});
		}

		if (dom.aiCloseButton) {
			dom.aiCloseButton.addEventListener('click', function() {
				toggleAiPanel(false);
			});
		}

		if (dom.aiNewChatButton) {
			dom.aiNewChatButton.addEventListener('click', function() {
				startNewAiChat();
			});
		}

		if (dom.aiInput) {
			dom.aiInput.addEventListener('keydown', function(event) {
				// Enter sends, Shift+Enter inserts newline
				if (event.key === 'Enter' && !event.shiftKey) {
					event.preventDefault();
					submitAiPrompt();
				}
				if (event.key === 'Escape') {
					event.preventDefault();
					toggleAiPanel(false);
				}
			});
		}

		if (dom.aiModelSelect) {
			dom.aiModelSelect.addEventListener('change', function() {
				state.aiModel = dom.aiModelSelect.value;
			});
		}

		// Library
		if (dom.libraryButton) {
			dom.libraryButton.addEventListener('click', function() {
				showLibraryDialog();
			});
		}

		// Export
		if (dom.exportButton) {
			dom.exportButton.addEventListener('click', function() {
				exportSections();
			});
		}

		// Import
		if (dom.importButton) {
			dom.importButton.addEventListener('click', function() {
				importSections();
			});
		}

		// Shortcuts overlay
		if (dom.shortcutsButton) {
			dom.shortcutsButton.addEventListener('click', function() {
				showShortcutsOverlay();
			});
		}

		// HTML snippet buttons
		if (dom.htmlSnippets) {
			dom.htmlSnippets.addEventListener('click', function(e) {
				var btn = e.target.closest('.md-pb-snippet-btn');
				if (!btn) return;
				var tag = btn.getAttribute('data-tag');
				if (!tag) return;
				insertHtmlSnippet(tag, !!btn.getAttribute('data-self-closing'));
			});
		}

		if (Array.isArray(dom.viewportButtons)) {
			dom.viewportButtons.forEach(function(button) {
				button.addEventListener('click', function() {
					var viewport = button.getAttribute('data-viewport') || 'desktop';
					state.previewViewport = viewport;
					applyPreviewViewport();
				});
			});
		}

		dom.jsLocation.addEventListener('change', function(event) {
			updateCurrentSectionField('jsLocation', event.target.value === 'inline' ? 'inline' : 'footer');
		});

		dom.format.addEventListener('change', function(event) {
			updateCurrentSectionField('format', !!event.target.checked);
		});

		dom.phpExec.addEventListener('change', function(event) {
			updateCurrentSectionField('phpExec', !!event.target.checked);
		});

		if (dom.templateSelect) {
			dom.templateSelect.addEventListener('change', function(event) {
				state.pageTemplate = event.target.value;
			});
		}

		// Keyboard shortcuts
		document.addEventListener('keydown', function(event) {
			var target = event.target;
			var tagName = target && target.tagName ? String(target.tagName).toLowerCase() : '';
			var isInputTarget = !!(
				target &&
				(
					tagName === 'textarea' ||
					tagName === 'select' ||
					(tagName === 'input' && target.type !== 'checkbox')
				)
			);
			var isMeta = !!(event.metaKey || event.ctrlKey);
			var key = String(event.key || '').toLowerCase();

			if (event.key === 'Escape') {
				if (state.aiOpen) {
					event.preventDefault();
					toggleAiPanel(false);
				}
				return;
			}

			if (isMeta && key === 'k') {
				event.preventDefault();
				captureSelectionAndOpenPrompt();
				return;
			}

			if (isMeta && key === 's') {
				event.preventDefault();
				activateApply();
				return;
			}

			if (isMeta && key === 'b') {
				event.preventDefault();
				var viewports = ['desktop', '992', '768', '480', '360'];
				var currentIdx = viewports.indexOf(state.previewViewport);
				var nextIdx = (currentIdx + 1) % viewports.length;
				state.previewViewport = viewports[nextIdx];
				applyPreviewViewport();
				return;
			}

			if (isMeta && key === 'n') {
				event.preventDefault();
				addSection(state.selectedIndex);
				return;
			}

			if (isMeta && key === 'd') {
				event.preventDefault();
				duplicateSection(state.selectedIndex);
				return;
			}

			if (isMeta && key === 'backspace') {
				event.preventDefault();
				deleteSection(state.selectedIndex);
				return;
			}

			if (event.altKey && !isMeta && key === 'arrowup') {
				event.preventDefault();
				reorderSections(state.selectedIndex, Math.max(0, state.selectedIndex - 1));
				return;
			}

			if (event.altKey && !isMeta && key === 'arrowdown') {
				event.preventDefault();
				reorderSections(state.selectedIndex, Math.min(state.sections.length - 1, state.selectedIndex + 1));
				return;
			}

			if (event.altKey && !isMeta && key === '1') {
				event.preventDefault();
				focusEditor('html');
				return;
			}

			if (event.altKey && !isMeta && key === '2') {
				event.preventDefault();
				focusEditor('css');
				return;
			}

			if (event.altKey && !isMeta && key === '3') {
				event.preventDefault();
				focusEditor('js');
				return;
			}

			if (isInputTarget) {
				return;
			}
		});

		// Section list click delegation
		dom.indexList.addEventListener('click', function(event) {
			var target = event.target;
			if (!(target instanceof HTMLElement) && !(target instanceof SVGElement)) {
				return;
			}

			// Find the closest element with data-action (handles SVG icon clicks)
			var actionEl = target.closest('[data-action]');
			if (!actionEl) {
				return;
			}

			var action = actionEl.getAttribute('data-action');
			var index = parseInt(actionEl.getAttribute('data-index') || '', 10);

			if (!action || Number.isNaN(index)) {
				return;
			}

			if (action === 'select') {
				selectSection(index);
				return;
			}

			if (action === 'duplicate') {
				duplicateSection(index);
				return;
			}

			if (action === 'delete') {
				deleteSection(index);
				return;
			}

			if (action === 'collapse') {
				toggleCollapse(index);
			}
		});

		// Arrow key navigation in section list
		dom.indexList.addEventListener('keydown', function(event) {
			var item = event.target;
			if (!(item instanceof HTMLElement)) {
				return;
			}

			var index = parseInt(item.getAttribute('data-index') || '', 10);
			if (Number.isNaN(index)) {
				return;
			}

			if (event.key === 'ArrowUp') {
				event.preventDefault();
				selectSection(index - 1);
				return;
			}

			if (event.key === 'ArrowDown') {
				event.preventDefault();
				selectSection(index + 1);
				return;
			}

			if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'd') {
				event.preventDefault();
				duplicateSection(index);
			}
		});

		// Drag-and-drop reordering
		dom.indexList.addEventListener('dragstart', function(event) {
			var target = event.target;
			if (!(target instanceof HTMLElement)) {
				return;
			}

			var item = target.closest('.md-pb-index-item');
			if (!item) {
				return;
			}

			state.dragIndex = parseInt(item.getAttribute('data-index') || '', 10);
			if (event.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData('text/plain', String(state.dragIndex));
			}
		});

		dom.indexList.addEventListener('dragover', function(event) {
			event.preventDefault();
			if (event.dataTransfer) {
				event.dataTransfer.dropEffect = 'move';
			}
		});

		dom.indexList.addEventListener('drop', function(event) {
			event.preventDefault();
			var target = event.target;
			if (!(target instanceof HTMLElement)) {
				return;
			}

			var item = target.closest('.md-pb-index-item');
			if (!item) {
				return;
			}

			var toIndex = parseInt(item.getAttribute('data-index') || '', 10);
			if (Number.isNaN(toIndex) || Number.isNaN(state.dragIndex)) {
				return;
			}

			reorderSections(state.dragIndex, toIndex);
			state.dragIndex = null;
		});

		setupResizeEvents();
	}


	// -------------------------------------------------------------------------
	// Initialize
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Keyboard Shortcuts Overlay
	// -------------------------------------------------------------------------

	function showShortcutsOverlay() {
		var existing = document.getElementById('md-pb-shortcuts-overlay');
		if (existing) {
			existing.remove();
			return;
		}

		var overlay = document.createElement('div');
		overlay.id = 'md-pb-shortcuts-overlay';
		overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);';

		var isMac = navigator.platform.indexOf('Mac') !== -1;
		var mod = isMac ? '\u2318' : 'Ctrl';

		var shortcuts = [
			[mod + '+S', 'Save sections'],
			[mod + '+K', 'Toggle AI prompt'],
			[mod + '+N', 'Add section after current'],
			[mod + '+D', 'Duplicate section'],
			[mod + '+Backspace', 'Delete section'],
			[mod + '+B', 'Cycle viewport size'],
			['Alt+\u2191', 'Move section up'],
			['Alt+\u2193', 'Move section down'],
			['Alt+1', 'Focus HTML editor'],
			['Alt+2', 'Focus CSS editor'],
			['Alt+3', 'Focus JS editor'],
			['Escape', 'Close AI / overlays'],
		];

		var rows = shortcuts.map(function(s) {
			return '<tr><td style="padding:4px 16px 4px 0;text-align:right;"><kbd style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);border-radius:4px;padding:2px 8px;font:12px var(--pb-font-code);color:var(--pb-accent);">' + s[0] + '</kbd></td><td style="padding:4px 0;color:var(--pb-text);font-size:13px;">' + s[1] + '</td></tr>';
		}).join('');

		overlay.innerHTML = '<div style="background:var(--pb-surface);border:1px solid var(--pb-border);border-radius:12px;padding:24px 32px;max-width:420px;box-shadow:var(--pb-shadow);">' +
			'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;"><h3 style="margin:0;font-size:16px;font-weight:700;color:var(--pb-text);">Keyboard Shortcuts</h3><button type="button" id="md-pb-shortcuts-close" style="background:none;border:none;color:var(--pb-muted);font-size:18px;cursor:pointer;padding:4px;">&times;</button></div>' +
			'<table style="border-collapse:collapse;">' + rows + '</table>' +
			'</div>';

		document.body.appendChild(overlay);

		function closeOverlay() {
			overlay.remove();
			document.removeEventListener('keydown', escHandler);
		}

		function escHandler(e) {
			if (e.key === 'Escape') {
				closeOverlay();
			}
		}

		overlay.addEventListener('click', function(e) {
			if (e.target === overlay || e.target.id === 'md-pb-shortcuts-close') {
				closeOverlay();
			}
		});

		document.addEventListener('keydown', escHandler);
	}


	// -------------------------------------------------------------------------
	// Section Library
	// -------------------------------------------------------------------------

	function showLibraryDialog() {
		var existing = document.getElementById('md-pb-library-overlay');
		if (existing) {
			existing.remove();
			return;
		}

		var overlay = document.createElement('div');
		overlay.id = 'md-pb-library-overlay';
		overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);';

		overlay.innerHTML = '<div style="background:var(--pb-surface);border:1px solid var(--pb-border);border-radius:12px;padding:24px;max-width:560px;width:90%;max-height:80vh;display:flex;flex-direction:column;box-shadow:var(--pb-shadow);">' +
			'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;"><h3 style="margin:0;font-size:16px;font-weight:700;color:var(--pb-text);">Section Library</h3><button type="button" id="md-pb-library-close" style="background:none;border:none;color:var(--pb-muted);font-size:18px;cursor:pointer;padding:4px;">&times;</button></div>' +
			'<div style="display:flex;gap:8px;margin-bottom:12px;">' +
				'<button type="button" class="md-pb-button md-pb-button-primary" id="md-pb-library-save-current">Save Current Section to Library</button>' +
			'</div>' +
			'<div id="md-pb-library-list" style="flex:1;overflow-y:auto;min-height:120px;"><div style="color:var(--pb-muted);font-size:13px;padding:20px;text-align:center;">Loading...</div></div>' +
			'</div>';

		document.body.appendChild(overlay);

		function closeLibrary() {
			overlay.remove();
			document.removeEventListener('keydown', libEscHandler);
		}

		function libEscHandler(e) {
			if (e.key === 'Escape') {
				closeLibrary();
			}
		}

		document.addEventListener('keydown', libEscHandler);

		overlay.addEventListener('click', function(e) {
			if (e.target === overlay || e.target.id === 'md-pb-library-close') {
				closeLibrary();
			}
		});

		// Save current section
		document.getElementById('md-pb-library-save-current').addEventListener('click', function() {
			var section = getCurrentSection();
			if (!section || (!section.content && !section.css && !section.js)) {
				window.alert('Current section is empty.');
				return;
			}

			var title = window.prompt('Section name for the library:');
			if (!title) return;

			var form = new window.URLSearchParams();
			form.set('action', config.librarySaveAction || 'md_pb_builder_library_save');
			form.set('post_id', String(config.postId || 0));
			form.set('pb_nonce', String(config.saveNonce || ''));
			form.set('title', title);
			form.set('content', section.content || '');
			form.set('css', section.css || '');
			form.set('js', section.js || '');

			window.fetch(config.libraryEndpoint || config.saveEndpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: form.toString()
			}).then(function(r) { return r.json(); }).then(function(payload) {
				if (payload && payload.success) {
					window.alert('Saved to library: ' + title);
					loadLibraryList();
				} else {
					window.alert('Error: ' + ((payload && payload.data && payload.data.message) || 'Unknown'));
				}
			}).catch(function() {
				window.alert('Failed to save to library.');
			});
		});

		loadLibraryList();
	}

	function loadLibraryList() {
		var listEl = document.getElementById('md-pb-library-list');
		if (!listEl) return;

		var url = (config.libraryEndpoint || config.saveEndpoint) +
			'?action=' + (config.libraryListAction || 'md_pb_builder_library_list') +
			'&pb_nonce=' + encodeURIComponent(config.saveNonce || '') +
			'&post_id=' + encodeURIComponent(config.postId || 0);

		window.fetch(url, { credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(payload) {
				if (!payload || !payload.success || !Array.isArray(payload.data)) {
					listEl.innerHTML = '<div style="color:var(--pb-muted);font-size:13px;padding:20px;text-align:center;">No library sections found.</div>';
					return;
				}

				listEl.innerHTML = '';
				payload.data.forEach(function(item) {
					var row = document.createElement('div');
					row.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:8px;border:1px solid var(--pb-border);border-radius:6px;margin-bottom:6px;';

					var nameSpan = document.createElement('span');
					nameSpan.style.cssText = 'font-size:13px;color:var(--pb-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
					nameSpan.textContent = item.title + (item.slug ? ' (' + item.slug + ')' : '');

					var insertBtn = document.createElement('button');
					insertBtn.type = 'button';
					insertBtn.className = 'md-pb-icon-btn';
					insertBtn.textContent = 'Insert';
					insertBtn.title = 'Insert as new section';
					insertBtn.addEventListener('click', function() {
						var newSection = {
							content: item.content || '',
							css: item.css || '',
							js: item.js || '',
							jsLocation: 'footer',
							output: 'inline',
							format: false,
							phpExec: false,
							collapsed: false
						};
						state.sections.splice(state.selectedIndex + 1, 0, newSection);
						state.selectedIndex = state.selectedIndex + 1;
						renderAll();

						var overlay = document.getElementById('md-pb-library-overlay');
						if (overlay) overlay.remove();
					});

					row.appendChild(nameSpan);
					row.appendChild(insertBtn);
					listEl.appendChild(row);
				});

				if (!payload.data.length) {
					listEl.innerHTML = '<div style="color:var(--pb-muted);font-size:13px;padding:20px;text-align:center;">No library sections found. Save a section first.</div>';
				}
			})
			.catch(function() {
				listEl.innerHTML = '<div style="color:var(--pb-muted);font-size:13px;padding:20px;text-align:center;">Failed to load library.</div>';
			});
	}


	// -------------------------------------------------------------------------
	// Export / Import
	// -------------------------------------------------------------------------

	function exportSections() {
		var sections = getApplyPayloadSections();
		var data = JSON.stringify({
			version: '1.0',
			exported: new Date().toISOString(),
			sections: sections
		}, null, 2);

		var blob = new Blob([data], { type: 'application/json' });
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url;
		a.download = 'page-blocks-' + (config.postId || 'export') + '.json';
		a.click();
		URL.revokeObjectURL(url);
	}

	function importSections() {
		var input = document.createElement('input');
		input.type = 'file';
		input.accept = '.json';

		input.addEventListener('change', function() {
			var file = input.files && input.files[0];
			if (!file) return;

			var reader = new FileReader();
			reader.onload = function(e) {
				var data;
				try {
					data = JSON.parse(e.target.result);
				} catch (err) {
					window.alert('Invalid JSON file.');
					return;
				}

				if (!data || !Array.isArray(data.sections) || !data.sections.length) {
					window.alert('No sections found in import file.');
					return;
				}

				var mode = window.confirm(
					'Import ' + data.sections.length + ' section(s).\n\n' +
					'OK = Append to existing sections\n' +
					'Cancel = Replace all sections'
				) ? 'append' : 'replace';

				if (mode === 'replace') {
					if (!window.confirm('This will replace ALL existing sections. Are you sure?')) {
						return;
					}
				}

				var imported = data.sections.map(normalizeSection);

				if (mode === 'replace') {
					state.sections = imported.length ? imported : [createDefaultSection()];
					state.selectedIndex = 0;
				} else {
					imported.forEach(function(section) {
						state.sections.push(section);
					});
				}

				renderAll();
				queueAutosave();
			};

			reader.readAsText(file);
		});

		input.click();
	}


	// -------------------------------------------------------------------------
	// Initialize
	// -------------------------------------------------------------------------

	function initialize() {
		setupLayout();
		collectPreviewAssets();
		setupEvents();
		setupCodeEditors();

		var initial = Array.isArray(config.initialSections) ? config.initialSections : [];
		var draft = getAutosaveDraft();

		if (draft && window.confirm('An unsaved draft was recovered. Restore it?')) {
			hydrateSections(draft.sections);
		} else {
			if (draft) {
				clearAutosaveDraft();
			}
			hydrateSections(initial);
		}

		// Listen for inline text edits from the preview iframe
		window.addEventListener('message', function(e) {
			var data = e.data;
			if (!data || data.type !== 'md_pb_inline_edit') {
				return;
			}

			var idx = data.sectionIndex;
			var oldText = data.oldText;
			var newText = data.newText;

			if (typeof idx !== 'number' || idx < 0 || idx >= state.sections.length) {
				return;
			}

			var section = state.sections[idx];
			if (!section || !section.content) {
				return;
			}

			// Replace the first occurrence of oldText in the section HTML
			var html = section.content;
			var pos = html.indexOf(oldText);

			if (pos !== -1) {
				section.content = html.substring(0, pos) + newText + html.substring(pos + oldText.length);

				// Sync to CodeMirror if this is the selected section
				if (idx === state.selectedIndex) {
					if (state.hasCodeMirror && state.editors.html) {
						state.syncingEditors = true;
						state.editors.html.codemirror.setValue(section.content);
						state.syncingEditors = false;
					} else if (dom.textareaHtml) {
						dom.textareaHtml.value = section.content;
					}
				}

				renderIndexList();
				queueAutosave();
				// Don't re-render preview — we edited it in-place
			}
		});

		// Warn before leaving with unsaved changes
		window.addEventListener('beforeunload', function(e) {
			if (autosaveTimer) {
				// There are pending autosave changes, which means edits exist
				e.preventDefault();
				e.returnValue = '';
			}
		});
	}

	initialize();
})();
