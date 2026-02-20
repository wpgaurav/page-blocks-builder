(function() {
	'use strict';

	var config = window.mdPageBlocksBuilderShell || {};
	var app = document.getElementById('md-page-block-builder-app');
	var MESSAGE_NAMESPACE = 'md_pb_builder';
	var ORIGIN = window.location.origin;
	var PARENT_ORIGIN = getParentOrigin();
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
			inlineStyles: [],
			scriptUrls: []
		},
		aiPromptOpen: false,
		aiSelection: '',
		aiSelectionEditor: null,
		aiBusy: false,
		aiModel: (config.aiDefaultModel || 'gpt-5.2'),
		terminalHistory: [],
		terminalHistoryIndex: -1,
		terminalCwd: '',
		terminalBusy: false,
		pageTemplate: config.postTemplate || 'default-template'
	};
	var dom = {};

	if (!app) {
		return;
	}

	function normalizeOrigin(url) {
		if (!url || typeof url !== 'string') {
			return '';
		}

		try {
			return new window.URL(url, ORIGIN).origin;
		} catch (error) {
			return '';
		}
	}

	function getParentOrigin() {
		if (config.parentOrigin) {
			return normalizeOrigin(config.parentOrigin) || ORIGIN;
		}

		try {
			var params = new window.URLSearchParams(window.location.search);
			var fromQuery = params.get('pb_parent_origin');
			if (fromQuery) {
				return normalizeOrigin(fromQuery) || ORIGIN;
			}
		} catch (error) {
			// no-op
		}

		if (document.referrer) {
			return normalizeOrigin(document.referrer) || ORIGIN;
		}

		return ORIGIN;
	}

	function getPreviewTemplateMode(templateSlug) {
		var slug = typeof templateSlug === 'string' ? templateSlug.toLowerCase() : '';

		if (!slug || slug === 'default' || slug === 'default-template' || slug === 'default_template') {
			return 'default';
		}

		if (slug.indexOf('premium-builder') !== -1) {
			return 'premium-builder';
		}

		if (slug.indexOf('builder') !== -1) {
			return 'builder';
		}

		return 'default';
	}

	function applyPreviewTemplateClass(shell) {
		if (!shell) {
			return;
		}

		var mode = getPreviewTemplateMode(config.postTemplate);

		shell.classList.remove('is-template-default', 'is-template-builder', 'is-template-premium-builder');
		shell.classList.add('is-template-' + mode);
	}

	function postToParent(type, payload) {
		if (!window.parent || window.parent === window) {
			return;
		}

		window.parent.postMessage({
			namespace: MESSAGE_NAMESPACE,
			type: type,
			payload: payload || {}
		}, PARENT_ORIGIN);
	}

	function isEmbeddedInParent() {
		return !!(window.parent && window.parent !== window);
	}

	function createDefaultSection() {
		return {
			content: '',
			css: '',
			js: '',
			jsLocation: 'footer',
			format: false,
			phpExec: false,
			collapsed: false
		};
	}

	function normalizeSection(input) {
		var section = createDefaultSection();
		var source = input && typeof input === 'object' ? input : {};

		section.content = typeof source.content === 'string' ? source.content : '';
		section.css = typeof source.css === 'string' ? source.css : '';
		section.js = typeof source.js === 'string' ? source.js : '';
		section.jsLocation = source.jsLocation === 'inline' ? 'inline' : 'footer';
		section.format = !!source.format;
		section.phpExec = !!source.phpExec;
		section.collapsed = !!source.collapsed;

		return section;
	}

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
	}

	function queueAutosave() {
		if (autosaveTimer) {
			window.clearTimeout(autosaveTimer);
		}
		autosaveTimer = window.setTimeout(saveAutosaveDraft, AUTOSAVE_INTERVAL);
	}

	function sanitizeSections(input) {
		if (!Array.isArray(input)) {
			return [createDefaultSection()];
		}

		var sections = input.map(normalizeSection);
		return sections.length ? sections : [createDefaultSection()];
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

	function normalizeAssetUrl(input) {
		if (typeof input !== 'string' || !input) {
			return '';
		}

		try {
			return new window.URL(input, window.location.href).href;
		} catch (error) {
			return '';
		}
	}

	function startsWithAny(value, prefixes) {
		if (!value || !Array.isArray(prefixes) || !prefixes.length) {
			return false;
		}

		for (var i = 0; i < prefixes.length; i += 1) {
			if (prefixes[i] && value.indexOf(prefixes[i]) === 0) {
				return true;
			}
		}

		return false;
	}

	function pushUnique(target, value) {
		if (!value || target.indexOf(value) !== -1) {
			return;
		}
		target.push(value);
	}

	function shouldIncludeThemeScript(url, themeBaseUrls) {
		if (!url) {
			return false;
		}

		if (startsWithAny(url, themeBaseUrls) || url.indexOf('/wp-content/themes/') !== -1) {
			return true;
		}

		if (url.indexOf('/wp-includes/js/jquery/') !== -1) {
			return true;
		}

		return false;
	}

	function collectPreviewAssets() {
		var themeBaseUrls = Array.isArray(config.themeBaseUrls)
			? config.themeBaseUrls.map(normalizeAssetUrl).filter(Boolean)
			: [];
		var explicitThemeStyles = Array.isArray(config.themeStyleUrls)
			? config.themeStyleUrls.map(normalizeAssetUrl).filter(Boolean)
			: [];
		var styleUrls = [];
		var scriptUrls = [];
		var inlineStyles = [];

		explicitThemeStyles.forEach(function(url) {
			pushUnique(styleUrls, url);
		});

		Array.prototype.forEach.call(document.querySelectorAll('link[rel~="stylesheet"][href]'), function(node) {
			var href = normalizeAssetUrl(node.getAttribute('href'));
			if (!href) {
				return;
			}

			if (startsWithAny(href, themeBaseUrls) || href.indexOf('/wp-content/themes/') !== -1) {
				pushUnique(styleUrls, href);
			}
		});

		Array.prototype.forEach.call(document.querySelectorAll('style'), function(node) {
			var id = (node.id || '').toLowerCase();
			var text = node.textContent || '';
			if (!text.trim()) {
				return;
			}

			if (
				id.indexOf('global-styles') !== -1 ||
				id.indexOf('classic-theme-styles') !== -1 ||
				id.indexOf('theme') === 0
			) {
				inlineStyles.push(text);
			}
		});

		Array.prototype.forEach.call(document.querySelectorAll('script[src]'), function(node) {
			var src = normalizeAssetUrl(node.getAttribute('src'));
			if (!src) {
				return;
			}

			if (shouldIncludeThemeScript(src, themeBaseUrls)) {
				pushUnique(scriptUrls, src);
			}
		});

		state.previewAssets = {
			styleUrls: styleUrls,
			inlineStyles: inlineStyles,
			scriptUrls: scriptUrls
		};
	}

	function escapeAttribute(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;');
	}

	function escapeClosingTag(content, tagName) {
		var pattern = new RegExp('</' + tagName, 'gi');
		return String(content).replace(pattern, '<\\/' + tagName);
	}

	function normalizePreviewInjection(input) {
		var source = input && typeof input === 'object' ? input : {};
		return {
			headHtml: typeof source.headHtml === 'string' ? source.headHtml : '',
			bodyStartHtml: typeof source.bodyStartHtml === 'string' ? source.bodyStartHtml : '',
			bodyEndHtml: typeof source.bodyEndHtml === 'string' ? source.bodyEndHtml : '',
			css: typeof source.css === 'string' ? source.css : '',
			jsHead: typeof source.jsHead === 'string' ? source.jsHead : '',
			jsFooter: typeof source.jsFooter === 'string' ? source.jsFooter : ''
		};
	}

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
		form.set('action', config.previewAction || 'md_page_blocks_builder_preview');
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

	function buildPreviewDoc(renderedData) {
		var html = [];
		var css = [];
		var inlineJs = [];
		var footerJs = [];
		var sectionIndex = 0;

		state.sections.forEach(function(section, i) {
			if (section.collapsed) {
				return;
			}

			html.push('<div data-pb-section="' + i + '">' + (section.content || '') + '</div>');
			sectionIndex++;

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

		var themeScripts = state.previewAssets.scriptUrls
			.map(function(url) {
				return '<script src="' + escapeAttribute(url) + '"><\\/script>';
			})
			.join('');

		var rendered = renderedData && typeof renderedData === 'object' ? renderedData : {};
		var htmlOutput = typeof rendered.html === 'string' ? rendered.html : html.join('\n');
		var cssOutput = typeof rendered.css === 'string' ? rendered.css : css.join('\n');
		var inlineJsOutput = typeof rendered.jsInline === 'string' ? rendered.jsInline : inlineJs.join(';\n');
		var footerJsOutput = typeof rendered.jsFooter === 'string' ? rendered.jsFooter : footerJs.join(';\n');

		var inlineJsTag = inlineJsOutput
			? '<script>' + escapeClosingTag(inlineJsOutput, 'script') + '<\\/script>'
			: '';

		var footerJsTag = footerJsOutput
			? '<script>' + escapeClosingTag(footerJsOutput, 'script') + '<\\/script>'
			: '';

		var customCssTag = cssOutput
			? '<style>' + escapeClosingTag(cssOutput, 'style') + '</style>'
			: '';
		var previewInjection = normalizePreviewInjection(config.previewInjection);
		var injectedCssTag = previewInjection.css
			? '<style>' + escapeClosingTag(previewInjection.css, 'style') + '</style>'
			: '';
		var injectedJsHeadTag = previewInjection.jsHead
			? '<script>' + escapeClosingTag(previewInjection.jsHead, 'script') + '<\\/script>'
			: '';
		var injectedJsFooterTag = previewInjection.jsFooter
			? '<script>' + escapeClosingTag(previewInjection.jsFooter, 'script') + '<\\/script>'
			: '';
		var builderHelperCssTag = '<style>.share-sticky{display:none !important;visibility:hidden !important;opacity:0 !important;pointer-events:none !important;}</style>';

		return '<!doctype html>' +
			'<html><head><meta charset="utf-8">' +
			'<meta name="viewport" content="width=device-width, initial-scale=1">' +
			themeStyleLinks +
			themeInlineStyles +
			previewInjection.headHtml +
			builderHelperCssTag +
			injectedCssTag +
			customCssTag +
			injectedJsHeadTag +
			inlineJsTag +
			'</head><body>' +
			previewInjection.bodyStartHtml +
			htmlOutput +
			themeScripts +
			injectedJsFooterTag +
			footerJsTag +
			previewInjection.bodyEndHtml +
			'</body></html>';
	}

	function extractSectionMeta(section, index) {
		var fallbackId = 'section-' + (index + 1);
		var classes = [];

		if (!section || typeof section.content !== 'string' || !section.content.trim()) {
			return {
				id: fallbackId,
				classes: classes
			};
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

			return {
				id: sectionId,
				classes: classes
			};
		} catch (error) {
			return {
				id: fallbackId,
				classes: classes
			};
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

	function setRightPaneMode(mode) {
		var validModes = ['css', 'js'];
		if (config.terminalEnabled) {
			validModes.push('terminal');
		}
		state.rightPaneMode = validModes.indexOf(mode) !== -1 ? mode : 'css';

		if (dom.shell) {
			dom.shell.classList.remove('is-pane-js', 'is-pane-terminal');
			if (state.rightPaneMode === 'js') {
				dom.shell.classList.add('is-pane-js');
			} else if (state.rightPaneMode === 'terminal') {
				dom.shell.classList.add('is-pane-terminal');
			}
		}

		if (dom.rightPaneLabel) {
			var labels = { css: 'CSS', js: 'JS', terminal: 'Term' };
			dom.rightPaneLabel.textContent = labels[state.rightPaneMode] || 'CSS';
		}

		if (dom.swapRightPaneButton) {
			var nextMode = getNextRightPaneMode();
			if (nextMode === 'terminal') {
				dom.swapRightPaneButton.textContent = '';
				var termText = document.createTextNode('Term ');
				var betaBadge = document.createElement('sup');
				betaBadge.textContent = 'B';
				betaBadge.className = 'md-pb-beta-badge';
				dom.swapRightPaneButton.appendChild(termText);
				dom.swapRightPaneButton.appendChild(betaBadge);
			} else {
				var swapLabels = { css: 'CSS', js: 'JS' };
				dom.swapRightPaneButton.textContent = swapLabels[nextMode] || 'JS';
			}
		}

		if (state.rightPaneMode === 'terminal' && dom.terminalInput) {
			dom.terminalInput.focus();
		}

		refreshCodeEditors();
	}

	function getNextRightPaneMode() {
		var modes = ['css', 'js'];
		if (config.terminalEnabled) {
			modes.push('terminal');
		}
		var idx = modes.indexOf(state.rightPaneMode);
		return modes[(idx + 1) % modes.length];
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

	function queuePreviewRender(delay) {
		if (state.previewTimer) {
			window.clearTimeout(state.previewTimer);
		}

		var wait = typeof delay === 'number' ? delay : 0;

		state.previewTimer = window.setTimeout(function() {
			if (!dom.previewFrame) {
				return;
			}

			var requestId = state.previewRequestId + 1;
			state.previewRequestId = requestId;

			if (!needsServerPreview()) {
				dom.previewFrame.srcdoc = buildPreviewDoc();
				return;
			}

			requestServerPreview(getApplyPayloadSections())
				.then(function(renderedData) {
					if (requestId !== state.previewRequestId || !dom.previewFrame) {
						return;
					}
					dom.previewFrame.srcdoc = buildPreviewDoc(renderedData);
				})
				.catch(function() {
					if (requestId !== state.previewRequestId || !dom.previewFrame) {
						return;
					}
					dom.previewFrame.srcdoc = buildPreviewDoc();
				});
		}, wait);
	}

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
		dom.applyButton.textContent = 'Apply to Gutenberg';
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

	function applySectionsStandalone(sections) {
		if (!config.applyEndpoint || !config.postId || !config.applyNonce) {
			window.alert('Builder save endpoint is missing. Open this builder from Gutenberg.');
			return;
		}

		if (state.applyBusy) {
			return;
		}

		setApplyButtonBusy(true, 'Saving...');

		var form = new window.URLSearchParams();
		form.set('action', config.applyAction || 'md_page_blocks_builder_apply');
		form.set('post_id', String(config.postId || 0));
		form.set('pb_nonce', String(config.applyNonce || ''));
		form.set('sections', JSON.stringify(Array.isArray(sections) ? sections : []));
		form.set('page_template', state.pageTemplate || '');

		window.fetch(config.applyEndpoint, {
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
			nameButton.textContent = inferSectionName(section.content, index);
			nameButton.setAttribute('data-action', 'select');
			nameButton.setAttribute('data-index', String(index));

			var grip = document.createElement('span');
			grip.className = 'md-pb-index-grip';
			grip.textContent = '⋮⋮';
			grip.setAttribute('aria-hidden', 'true');

			var actions = document.createElement('div');
			actions.className = 'md-pb-index-item-actions';

			var collapse = document.createElement('button');
			collapse.type = 'button';
			collapse.className = 'md-pb-icon-btn';
			collapse.textContent = section.collapsed ? '◌' : '◉';
			collapse.title = section.collapsed ? 'Show section' : 'Hide section';
			collapse.setAttribute('data-action', 'collapse');
			collapse.setAttribute('data-index', String(index));

			var duplicate = document.createElement('button');
			duplicate.type = 'button';
			duplicate.className = 'md-pb-icon-btn';
			duplicate.textContent = '⧉';
			duplicate.title = 'Duplicate section';
			duplicate.setAttribute('data-action', 'duplicate');
			duplicate.setAttribute('data-index', String(index));

			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'md-pb-icon-btn';
			remove.textContent = '✕';
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

	function renderAll() {
		clampSelectedIndex();
		renderIndexList();
		renderCurrentSectionToEditors();
		refreshCodeEditors();
		queuePreviewRender();
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

	function selectSection(index) {
		if (index < 0 || index >= state.sections.length) {
			return;
		}
		state.selectedIndex = index;
		renderAll();
		ensureSelectedIndexVisible();
		scrollPreviewToSection(index);
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

	function getApplyPayloadSections() {
		return state.sections.map(function(section) {
			var normalized = normalizeSection(section);
			return {
				content: normalized.content,
				css: normalized.css,
				js: normalized.js,
				jsLocation: normalized.jsLocation,
				format: normalized.format,
				phpExec: normalized.phpExec
			};
		});
	}

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
					var el = iframeDoc.querySelector('.' + CSS.escape(firstClass));
					if (el) {
						el.scrollIntoView({ behavior: 'smooth', block: 'center' });
						return;
					}
				}
			}

			var tagMatch = line.match(/<(section|article|header|footer|nav|main|aside|div|h[1-6])\b/i);
			if (tagMatch) {
				var sectionMarker = iframeDoc.querySelector('[data-pb-section="' + state.selectedIndex + '"]');
				if (sectionMarker) {
					var tags = sectionMarker.querySelectorAll(tagMatch[1]);
					if (tags.length) {
						var linesBefore = 0;
						var fullContent = cm.getValue();
						var lines = fullContent.split('\n');
						var tagRegex = new RegExp('<' + tagMatch[1] + '\\b', 'gi');
						var matchCount = 0;

						for (var i = 0; i <= cursor.line; i++) {
							var m = lines[i].match(tagRegex);
							if (m) {
								matchCount += m.length;
							}
						}

						var targetIdx = Math.min(matchCount - 1, tags.length - 1);
						if (targetIdx >= 0) {
							tags[targetIdx].scrollIntoView({ behavior: 'smooth', block: 'center' });
						}
					}
				}
			}
		} catch (error) {
			// cross-origin or iframe not ready
		}
	}

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
		return Object.keys(classMap).sort();
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

	function setupLayout() {
		app.innerHTML = '';

		var shell = document.createElement('div');
		shell.className = 'md-pb-shell';
		shell.innerHTML = '' +
			'<div class="md-pb-topbar">' +
				'<div class="md-pb-topbar-left">' +
					'<div class="md-pb-brand">Blocks</div>' +
					'<span class="md-pb-divider" aria-hidden="true"></span>' +
					'<button type="button" class="md-pb-toggle-btn is-active" data-role="toggle-sections" aria-pressed="true">☰ Sections</button>' +
					'<button type="button" class="md-pb-toggle-btn is-active" data-role="toggle-code" aria-pressed="true">&lt;/&gt; Code</button>' +
					'<button type="button" class="md-pb-toggle-btn is-active" data-role="toggle-preview" aria-pressed="true">&#9655; Preview</button>' +
					'<button type="button" class="md-pb-toggle-btn" data-role="toggle-ai">AI</button>' +
				'</div>' +
				'<div class="md-pb-topbar-actions">' +
					'<button type="button" class="md-pb-button md-pb-button-primary" data-role="apply">Apply to Gutenberg</button>' +
					'<button type="button" class="md-pb-button md-pb-button-preview" data-role="preview-frontend">Preview</button>' +
				'</div>' +
				'<div class="md-pb-topbar-right">' +
					'<button type="button" class="md-pb-button" data-role="cancel">Close</button>' +
				'</div>' +
			'</div>' +
			'<div class="md-pb-main">' +
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
				'<div class="md-pb-ai-bar" data-role="ai-bar" style="display:none">' +
					'<select class="md-pb-ai-model-select" data-role="ai-model-select"></select>' +
					'<div class="md-pb-ai-input-wrap">' +
						'<span class="md-pb-ai-selection-badge" data-role="ai-selection-badge" style="display:none">Selection</span>' +
						'<input type="text" class="md-pb-ai-input" data-role="ai-input" placeholder="Describe what to generate..." autocomplete="off" spellcheck="false">' +
					'</div>' +
					'<span class="md-pb-ai-status" data-role="ai-status" style="display:none">Generating...</span>' +
					'<button type="button" class="md-pb-button md-pb-button-primary md-pb-ai-generate-btn" data-role="ai-generate">Generate</button>' +
					'<button type="button" class="md-pb-icon-btn md-pb-ai-close-btn" data-role="ai-close">&times;</button>' +
				'</div>' +
				'<div class="md-pb-code-wrap">' +
					'<div class="md-pb-code-grid">' +
						'<div class="md-pb-code-column">' +
							'<div class="md-pb-code-title">HTML</div>' +
							'<div class="md-pb-code-pane"><textarea class="md-pb-code-textarea" data-role="textarea-html" spellcheck="false"></textarea></div>' +
						'</div>' +
						'<div class="md-pb-code-column md-pb-code-column-right">' +
							'<div class="md-pb-code-title md-pb-code-title-swap"><span data-role="right-pane-label">CSS</span><button type="button" class="md-pb-icon-btn" data-role="swap-right-pane">JS</button></div>' +
							'<div class="md-pb-code-pane md-pb-code-pane--css"><textarea class="md-pb-code-textarea" data-role="textarea-css" spellcheck="false"></textarea></div>' +
							'<div class="md-pb-code-pane md-pb-code-pane--js"><textarea class="md-pb-code-textarea" data-role="textarea-js" spellcheck="false"></textarea></div>' +
							'<div class="md-pb-code-pane md-pb-code-pane--terminal">' +
								'<div class="md-pb-terminal-output" data-role="terminal-output"></div>' +
								'<div class="md-pb-terminal-input-row">' +
									'<span class="md-pb-terminal-prompt" data-role="terminal-cwd">$</span>' +
									'<input type="text" class="md-pb-terminal-input" data-role="terminal-input" placeholder="Type a command..." autocomplete="off" spellcheck="false">' +
								'</div>' +
							'</div>' +
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
		dom.aiBar = shell.querySelector('[data-role="ai-bar"]');
		dom.aiInput = shell.querySelector('[data-role="ai-input"]');
		dom.aiModelSelect = shell.querySelector('[data-role="ai-model-select"]');
		dom.aiSelectionBadge = shell.querySelector('[data-role="ai-selection-badge"]');
		dom.aiStatus = shell.querySelector('[data-role="ai-status"]');
		dom.aiGenerateButton = shell.querySelector('[data-role="ai-generate"]');
		dom.aiCloseButton = shell.querySelector('[data-role="ai-close"]');
		dom.terminalOutput = shell.querySelector('[data-role="terminal-output"]');
		dom.terminalInput = shell.querySelector('[data-role="terminal-input"]');
		dom.terminalCwd = shell.querySelector('[data-role="terminal-cwd"]');
		dom.templateSelect = shell.querySelector('[data-role="template-select"]');

		populateTemplateSelect();
		populateAiModelSelect();
		applyPreviewTemplateClass(shell);
		applyPreviewViewport();
		setBottomHeight(state.bottomHeight);
		setRightPaneMode(state.rightPaneMode);
		updatePanelVisibility();
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

		if (dom.bottom) {
			dom.bottom.addEventListener('pointerdown', function(event) {
				if (event.button !== 0) {
					return;
				}

				var rect = dom.bottom.getBoundingClientRect();
				var resizeZoneHeight = 8;
				if ((event.clientY - rect.top) > resizeZoneHeight) {
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

	function activateCancel() {
		if (isEmbeddedInParent()) {
			postToParent('md_pb_builder_cancel', {});
			return;
		}

		if (config.editPostUrl) {
			window.location.href = config.editPostUrl;
			return;
		}

		window.history.back();
	}

	function activateApply() {
		var sections = getApplyPayloadSections();
		var template = state.pageTemplate || '';
		var usedDirectApply = false;

		try {
			if (window.parent && typeof window.parent.mdPageBlocksBuilderApply === 'function') {
				usedDirectApply = !!window.parent.mdPageBlocksBuilderApply(sections, template);
			}
		} catch (error) {
			usedDirectApply = false;
		}

		if (usedDirectApply) {
			clearAutosaveDraft();
			return;
		}

		if (!isEmbeddedInParent()) {
			applySectionsStandalone(sections);
			return;
		}

		clearAutosaveDraft();
		postToParent('md_pb_builder_apply', {
			sections: sections,
			pageTemplate: template
		});
	}

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
				toggleAiPrompt(false);
			});
		}

		if (dom.aiInput) {
			dom.aiInput.addEventListener('keydown', function(event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					submitAiPrompt();
				}
				if (event.key === 'Escape') {
					event.preventDefault();
					toggleAiPrompt(false);
				}
			});
		}

		if (dom.aiModelSelect) {
			dom.aiModelSelect.addEventListener('change', function() {
				state.aiModel = dom.aiModelSelect.value;
			});
		}

		if (dom.terminalInput) {
			dom.terminalInput.addEventListener('keydown', function(event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					var cmd = dom.terminalInput.value.trim();
					if (cmd) {
						executeTerminalCommand(cmd);
					}
				}
				if (event.key === 'ArrowUp') {
					event.preventDefault();
					if (state.terminalHistory.length > 0 && state.terminalHistoryIndex > 0) {
						state.terminalHistoryIndex--;
						dom.terminalInput.value = state.terminalHistory[state.terminalHistoryIndex] || '';
					}
				}
				if (event.key === 'ArrowDown') {
					event.preventDefault();
					if (state.terminalHistoryIndex < state.terminalHistory.length - 1) {
						state.terminalHistoryIndex++;
						dom.terminalInput.value = state.terminalHistory[state.terminalHistoryIndex] || '';
					} else {
						state.terminalHistoryIndex = state.terminalHistory.length;
						dom.terminalInput.value = '';
					}
				}
				if (event.ctrlKey && event.key === 'l') {
					event.preventDefault();
					clearTerminal();
				}
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
				if (state.aiPromptOpen) {
					event.preventDefault();
					toggleAiPrompt(false);
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

		dom.indexList.addEventListener('click', function(event) {
			var target = event.target;
			if (!(target instanceof HTMLElement)) {
				return;
			}

			var action = target.getAttribute('data-action');
			var index = parseInt(target.getAttribute('data-index') || '', 10);

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

	function hydrateSections(sections) {
		state.sections = sanitizeSections(sections);
		state.selectedIndex = 0;
		renderAll();
	}

	function handleMessage(event) {
		if (event.source !== window.parent) {
			return;
		}

		if (PARENT_ORIGIN && event.origin !== PARENT_ORIGIN) {
			return;
		}

		var data = event.data || {};
		if (!data || data.namespace !== MESSAGE_NAMESPACE || typeof data.type !== 'string') {
			return;
		}

		if (data.type === 'md_pb_builder_init') {
			hydrateSections(data.payload && data.payload.sections ? data.payload.sections : []);
			return;
		}

		if (data.type === 'md_pb_builder_error' && data.payload && data.payload.message) {
			if (window.console) {
				window.console.error('Page Blocks Builder:', data.payload.message);
			}
		}
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

	function toggleAiPrompt(forceOpen) {
		var shouldOpen = typeof forceOpen === 'boolean' ? forceOpen : !state.aiPromptOpen;
		state.aiPromptOpen = shouldOpen;

		if (dom.aiBar) {
			dom.aiBar.style.display = shouldOpen ? '' : 'none';
		}

		if (shouldOpen && dom.aiInput) {
			dom.aiInput.focus();
		}

		if (!shouldOpen) {
			state.aiSelection = '';
			state.aiSelectionEditor = null;
			if (dom.aiSelectionBadge) {
				dom.aiSelectionBadge.style.display = 'none';
			}
			if (dom.aiInput) {
				dom.aiInput.value = '';
			}
		}
	}

	function captureSelectionAndOpenPrompt() {
		var ed = state.editors.html;
		var cm = ed && ed.codemirror ? ed.codemirror : null;
		state.aiSelection = '';
		state.aiSelectionEditor = null;

		if (cm && typeof cm.getSelection === 'function') {
			var sel = cm.getSelection();
			if (sel && sel.length > 0) {
				state.aiSelection = sel;
				state.aiSelectionEditor = 'html';
			}
		}

		toggleAiPrompt(true);

		if (state.aiSelection && dom.aiSelectionBadge) {
			dom.aiSelectionBadge.style.display = '';
		}
	}

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

	function hasAiGeneratedId(attributes) {
		if (!attributes || typeof attributes !== 'string') {
			return false;
		}

		if (/\bid\s*=\s*(['"])ai-generated\1/i.test(attributes)) {
			return true;
		}

		return /\bid\s*=\s*ai-generated(?:\s|$)/i.test(attributes);
	}

	function extractAiGeneratedAssets(htmlCode) {
		var source = typeof htmlCode === 'string' ? htmlCode : '';
		var result = {
			html: source,
			css: '',
			js: '',
			hasBundle: false,
			hasCssTag: false,
			hasJsTag: false
		};

		if (!source) {
			return result;
		}

		var html = source.replace(/<style\b([^>]*)>([\s\S]*?)<\/style>/gi, function(match, attrs, inner) {
			if (!hasAiGeneratedId(attrs)) {
				return match;
			}

			result.hasBundle = true;
			result.hasCssTag = true;
			var cssChunk = (inner || '').trim();
			if (cssChunk) {
				result.css = result.css ? (result.css + '\n\n' + cssChunk) : cssChunk;
			}
			return '';
		});

		html = html.replace(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi, function(match, attrs, inner) {
			if (!hasAiGeneratedId(attrs)) {
				return match;
			}

			result.hasBundle = true;
			result.hasJsTag = true;
			var jsChunk = (inner || '').trim();
			if (jsChunk) {
				result.js = result.js ? (result.js + '\n\n' + jsChunk) : jsChunk;
			}
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

		if (editorKey === 'html' && dom.textareaHtml) {
			dom.textareaHtml.value = value;
			return;
		}
		if (editorKey === 'css' && dom.textareaCss) {
			dom.textareaCss.value = value;
			return;
		}
		if (editorKey === 'js' && dom.textareaJs) {
			dom.textareaJs.value = value;
		}
	}

	function submitAiPrompt() {
		if (state.aiBusy) {
			return;
		}

		var prompt = dom.aiInput ? dom.aiInput.value.trim() : '';
		if (!prompt) {
			return;
		}

		if (dom.aiModelSelect) {
			state.aiModel = dom.aiModelSelect.value;
		}

		var tab = 'html';
		var fieldMap = { html: 'content', css: 'css', js: 'js' };
		var section = getCurrentSection();
		var existing = section ? (section[fieldMap[tab]] || '') : '';
		var ctxHtml = section ? (section.content || '') : '';
		var ctxCss = section ? (section.css || '') : '';
		var selection = state.aiSelectionEditor === 'html' ? (state.aiSelection || '') : '';

		state.aiBusy = true;
		if (dom.aiStatus) {
			dom.aiStatus.style.display = '';
		}
		if (dom.aiGenerateButton) {
			dom.aiGenerateButton.disabled = true;
		}

		var formData = new FormData();
		formData.append('action', config.aiAction || 'md_page_blocks_ai_generate');
		formData.append('post_id', String(config.postId || 0));
		formData.append('pb_nonce', config.applyNonce || '');
		formData.append('prompt', prompt);
		formData.append('tab', tab);
		formData.append('existing_code', existing);
		formData.append('selection', selection);
		formData.append('model', state.aiModel);
		formData.append('context_html', ctxHtml);
		formData.append('context_css', ctxCss);
		formData.append('page_url', config.viewPostUrl || '');

		var xhr = new XMLHttpRequest();
		xhr.open('POST', config.aiEndpoint || config.applyEndpoint);
		xhr.onload = function() {
			state.aiBusy = false;
			if (dom.aiStatus) {
				dom.aiStatus.style.display = 'none';
			}
			if (dom.aiGenerateButton) {
				dom.aiGenerateButton.disabled = false;
			}

			var result;
			try {
				result = JSON.parse(xhr.responseText);
			} catch (e) {
				window.alert('AI request failed: invalid response');
				return;
			}

			if (!result || !result.success) {
				window.alert('AI error: ' + ((result && result.data && result.data.message) || 'Unknown error'));
				return;
			}

			var code = result.data && result.data.code ? result.data.code : '';
			if (!code) {
				return;
			}

			var extracted = null;
			if (tab === 'html') {
				extracted = extractAiGeneratedAssets(code);
				if (extracted.hasBundle && extracted.html) {
					code = extracted.html;
				} else if (extracted.hasBundle) {
					extracted = null;
				}
			}

			var field = fieldMap[tab];
			var editor = state.editors[tab];
			var cm = editor && editor.codemirror ? editor.codemirror : null;

			if (selection && cm && typeof cm.replaceSelection === 'function') {
				cm.replaceSelection(code);
				state.syncingEditors = true;
				updateCurrentSectionField(field, cm.getValue());
				state.syncingEditors = false;
			} else {
				updateCurrentSectionField(field, code);
				if (cm && typeof cm.setValue === 'function') {
					state.syncingEditors = true;
					cm.setValue(code);
					state.syncingEditors = false;
				}
			}

			if (cm && typeof cm.refresh === 'function') {
				window.setTimeout(function() { cm.refresh(); }, 0);
			}

			if (extracted && extracted.hasBundle) {
				if (extracted.hasCssTag) {
					setSectionFieldFromAi('css', 'css', extracted.css);
				}
				if (extracted.hasJsTag) {
					setSectionFieldFromAi('js', 'js', extracted.js);
				}
			}

			queuePreviewRender();
			toggleAiPrompt(false);
		};
		xhr.onerror = function() {
			state.aiBusy = false;
			if (dom.aiStatus) {
				dom.aiStatus.style.display = 'none';
			}
			if (dom.aiGenerateButton) {
				dom.aiGenerateButton.disabled = false;
			}
			window.alert('AI request failed: network error');
		};
		xhr.send(formData);
	}

	function executeTerminalCommand(command) {
		if (state.terminalBusy || !command) {
			return;
		}

		state.terminalBusy = true;
		appendTerminalOutput('$ ' + command, 'command');

		state.terminalHistory.push(command);
		state.terminalHistoryIndex = state.terminalHistory.length;

		if (dom.terminalInput) {
			dom.terminalInput.value = '';
			dom.terminalInput.placeholder = 'Running...';
		}

		var formData = new FormData();
		formData.append('action', config.terminalAction || 'md_page_blocks_terminal_exec');
		formData.append('post_id', String(config.postId || 0));
		formData.append('pb_nonce', config.applyNonce || '');
		formData.append('command', command);
		formData.append('cwd', state.terminalCwd || '');

		var xhr = new XMLHttpRequest();
		xhr.open('POST', config.aiEndpoint || config.applyEndpoint);
		xhr.onload = function() {
			state.terminalBusy = false;
			if (dom.terminalInput) {
				dom.terminalInput.placeholder = 'Type a command...';
			}

			var result;
			try {
				result = JSON.parse(xhr.responseText);
			} catch (e) {
				appendTerminalOutput('Failed to parse response', 'stderr');
				return;
			}

			if (!result || !result.success) {
				appendTerminalOutput((result && result.data && result.data.message) || 'Command failed', 'stderr');
				return;
			}

			var data = result.data || {};

			if (data.output) {
				appendTerminalOutput(data.output, 'stdout');
			}
			if (data.error) {
				appendTerminalOutput(data.error, 'stderr');
			}
			if (data.cwd) {
				state.terminalCwd = data.cwd;
				if (dom.terminalCwd) {
					dom.terminalCwd.textContent = data.cwd + ' $';
				}
			}
		};
		xhr.onerror = function() {
			state.terminalBusy = false;
			if (dom.terminalInput) {
				dom.terminalInput.placeholder = 'Type a command...';
			}
			appendTerminalOutput('Network error', 'stderr');
		};
		xhr.send(formData);
	}

	function appendTerminalOutput(text, type) {
		if (!dom.terminalOutput) {
			return;
		}

		var line = document.createElement('div');
		line.className = 'md-pb-terminal-line md-pb-terminal-line--' + (type || 'stdout');
		line.textContent = text;
		dom.terminalOutput.appendChild(line);
		dom.terminalOutput.scrollTop = dom.terminalOutput.scrollHeight;
	}

	function clearTerminal() {
		if (dom.terminalOutput) {
			dom.terminalOutput.textContent = '';
		}
	}

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

		window.addEventListener('message', handleMessage);
		postToParent('md_pb_builder_ready', { postId: config.postId || 0 });
	}

	initialize();
})();
