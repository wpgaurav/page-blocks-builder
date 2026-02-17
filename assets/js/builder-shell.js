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
		showSidebar: true,
		previewViewport: 'desktop',
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
		}
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

		state.sections.forEach(function(section) {
			if (section.collapsed) {
				return;
			}

			html.push(section.content || '');

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
		state.rightPaneMode = mode === 'js' ? 'js' : 'css';

		if (dom.shell) {
			dom.shell.classList.toggle('is-pane-js', state.rightPaneMode === 'js');
		}

		if (dom.rightPaneLabel) {
			dom.rightPaneLabel.textContent = state.rightPaneMode === 'js' ? 'JS' : 'CSS';
		}

		if (dom.swapRightPaneButton) {
			dom.swapRightPaneButton.textContent = state.rightPaneMode === 'js' ? 'CSS' : 'JS';
		}

		refreshCodeEditors();
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
		dom.shell.classList.toggle('is-sidebar-hidden', !state.showSidebar);

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

	function queuePreviewRender() {
		if (state.previewTimer) {
			window.clearTimeout(state.previewTimer);
		}

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
		}, 110);
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

		queuePreviewRender();
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

	function selectSection(index) {
		if (index < 0 || index >= state.sections.length) {
			return;
		}
		state.selectedIndex = index;
		renderAll();
		ensureSelectedIndexVisible();
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
			return;
		}

		var map = [
			{ key: 'html', textarea: dom.textareaHtml, field: 'content' },
			{ key: 'css', textarea: dom.textareaCss, field: 'css' },
			{ key: 'js', textarea: dom.textareaJs, field: 'js' }
		];

		map.forEach(function(item) {
			var settings = config.codeEditorSettings && config.codeEditorSettings[item.key] ? config.codeEditorSettings[item.key] : {};
			var editor = wp.codeEditor.initialize(item.textarea, settings);
			if (!editor || !editor.codemirror) {
				return;
			}

			state.editors[item.key] = editor;
			editor.codemirror.on('change', function(instance) {
				if (state.syncingEditors) {
					return;
				}
				updateCurrentSectionField(item.field, instance.getValue());
			});
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
				'</div>' +
				'<div class="md-pb-topbar-actions">' +
					'<button type="button" class="md-pb-button md-pb-button-primary" data-role="apply">Apply to Gutenberg</button>' +
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
					'<div class="md-pb-index-header">' +
						'<span>Sections</span>' +
						'<div class="md-pb-index-header-actions">' +
							'<span data-role="section-count">0</span>' +
							'<button type="button" class="md-pb-icon-btn" data-role="add-section" title="Add section">+</button>' +
						'</div>' +
					'</div>' +
					'<ul class="md-pb-index-list" data-role="index-list"></ul>' +
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
							'<div class="md-pb-code-title">HTML</div>' +
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
		dom.cancelButton = shell.querySelector('[data-role="cancel"]');
		dom.addSectionButton = shell.querySelector('[data-role="add-section"]');
		dom.toggleCodeButton = shell.querySelector('[data-role="toggle-code"]');
		dom.toggleSectionsButton = shell.querySelector('[data-role="toggle-sections"]');
		dom.activeSectionId = shell.querySelector('[data-role="active-section-id"]');
		dom.activeSectionClasses = shell.querySelector('[data-role="active-section-classes"]');
		dom.statusCount = shell.querySelector('[data-role="status-count"]');
		dom.splitter = shell.querySelector('[data-role="splitter"]');
		dom.splitterHandle = shell.querySelector('[data-role="splitter-handle"]');
		dom.bottom = shell.querySelector('.md-pb-bottom');
		dom.viewportButtons = Array.prototype.slice.call(shell.querySelectorAll('[data-role="viewport-button"]'));

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
		var usedDirectApply = false;

		try {
			if (window.parent && typeof window.parent.mdPageBlocksBuilderApply === 'function') {
				usedDirectApply = !!window.parent.mdPageBlocksBuilderApply(sections);
			}
		} catch (error) {
			usedDirectApply = false;
		}

		if (usedDirectApply) {
			return;
		}

		if (!isEmbeddedInParent()) {
			applySectionsStandalone(sections);
			return;
		}

		postToParent('md_pb_builder_apply', {
			sections: sections
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

		if (dom.toggleCodeButton) {
			dom.toggleCodeButton.addEventListener('click', function() {
				state.showCode = !state.showCode;
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
				setRightPaneMode(state.rightPaneMode === 'css' ? 'js' : 'css');
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
				return;
			}

			if (isMeta && key === 's') {
				event.preventDefault();
				activateApply();
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

	function initialize() {
		setupLayout();
		collectPreviewAssets();
		setupEvents();
		setupCodeEditors();
		hydrateSections(Array.isArray(config.initialSections) ? config.initialSections : []);
		window.addEventListener('message', handleMessage);
		postToParent('md_pb_builder_ready', { postId: config.postId || 0 });
	}

	initialize();
})();
