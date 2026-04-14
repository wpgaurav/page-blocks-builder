/**
 * Page Blocks Admin Edit — Monaco Editor + tabs + preview.
 *
 * @since 7.0.0
 */
(function($) {
	'use strict';

	var MONACO_CDN = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.52.2/min';
	var editors = {};
	var monacoReady = false;

	// ── Monaco loader ──

	function loadMonaco(callback) {
		if (window.monaco) {
			callback();
			return;
		}

		var loaderScript = document.createElement('script');
		loaderScript.src = MONACO_CDN + '/vs/loader.js';
		loaderScript.onload = function() {
			window.require.config({
				paths: { vs: MONACO_CDN + '/vs' }
			});
			window.require(['vs/editor/editor.main'], function() {
				monacoReady = true;
				callback();
			});
		};
		document.head.appendChild(loaderScript);
	}

	// ── CSS class completion provider ──

	function registerCssClassCompletions() {
		var cssClasses = (typeof gtPbPreview !== 'undefined' && gtPbPreview.cssClasses) || [];
		if (!cssClasses.length) return;

		// HTML: suggest classes inside class="..."
		monaco.languages.registerCompletionItemProvider('html', {
			triggerCharacters: ['"', "'", ' '],
			provideCompletionItems: function(model, position) {
				var lineContent = model.getLineContent(position.lineNumber);
				var textBefore = lineContent.substring(0, position.column - 1);

				// Check if we're inside class="..."
				var classMatch = textBefore.match(/class\s*=\s*["'][^"']*$/i);
				if (!classMatch) return { suggestions: [] };

				// Get the partial word being typed
				var wordInfo = model.getWordUntilPosition(position);
				var range = {
					startLineNumber: position.lineNumber,
					startColumn: wordInfo.startColumn,
					endLineNumber: position.lineNumber,
					endColumn: wordInfo.endColumn
				};

				return {
					suggestions: cssClasses.map(function(cls) {
						return {
							label: cls,
							kind: monaco.languages.CompletionItemKind.Value,
							insertText: cls,
							range: range,
							detail: 'Theme CSS class'
						};
					})
				};
			}
		});

		// CSS: suggest classes after . selector
		monaco.languages.registerCompletionItemProvider('css', {
			triggerCharacters: ['.'],
			provideCompletionItems: function(model, position) {
				var lineContent = model.getLineContent(position.lineNumber);
				var textBefore = lineContent.substring(0, position.column - 1);

				// Check if the last non-whitespace char is a dot (class selector)
				if (!/\.\s*$/.test(textBefore)) return { suggestions: [] };

				var range = {
					startLineNumber: position.lineNumber,
					startColumn: position.column,
					endLineNumber: position.lineNumber,
					endColumn: position.column
				};

				return {
					suggestions: cssClasses.map(function(cls) {
						return {
							label: cls,
							kind: monaco.languages.CompletionItemKind.Class,
							insertText: cls,
							range: range,
							detail: 'Theme CSS class'
						};
					})
				};
			}
		});
	}

	// ── Editor initialization ──

	var editorConfig = {
		html: { language: 'html', textarea: 'block_content' },
		css:  { language: 'css',  textarea: 'block_css' },
		js:   { language: 'javascript', textarea: 'block_js' }
	};

	function initEditors() {
		loadMonaco(function() {
			registerCssClassCompletions();

			Object.keys(editorConfig).forEach(function(key) {
				var cfg = editorConfig[key];
				var textarea = document.getElementById(cfg.textarea);
				if (!textarea) return;

				var panel = textarea.closest('.md-pb-editor-panel');
				var container = document.createElement('div');
				container.className = 'md-pb-monaco-container';
				textarea.style.display = 'none';
				textarea.parentNode.insertBefore(container, textarea);

				var editor = monaco.editor.create(container, {
					value: textarea.value,
					language: cfg.language,
					theme: 'vs-dark',
					fontSize: 13,
					lineHeight: 20,
					fontFamily: "'JetBrains Mono', 'Fira Code', 'SF Mono', 'Cascadia Code', Menlo, Consolas, monospace",
					fontLigatures: true,
					minimap: { enabled: false },
					scrollBeyondLastLine: false,
					wordWrap: 'on',
					tabSize: 4,
					insertSpaces: false,
					detectIndentation: false,
					renderWhitespace: 'selection',
					bracketPairColorization: { enabled: true },
					autoClosingBrackets: 'always',
					autoClosingQuotes: 'always',
					autoIndent: 'full',
					formatOnPaste: true,
					suggestOnTriggerCharacters: true,
					quickSuggestions: {
						other: true,
						comments: false,
						strings: true
					},
					padding: { top: 12, bottom: 12 },
					smoothScrolling: true,
					cursorBlinking: 'smooth',
					cursorSmoothCaretAnimation: 'on',
					roundedSelection: true,
					automaticLayout: true
				});

				// Sync editor content back to textarea for form submission
				editor.onDidChangeModelContent(function() {
					textarea.value = editor.getValue();
				});

				editors[key] = editor;
			});

			// Layout the active editor
			if (editors.html) {
				editors.html.layout();
				editors.html.focus();
			}
		});
	}

	// ── Tab switching ──

	function initTabs() {
		$('.md-pb-tab').on('click', function() {
			var tab = $(this).data('tab');

			$('.md-pb-tab').removeClass('active');
			$(this).addClass('active');

			$('.md-pb-editor-panel').removeClass('active');
			$('.md-pb-editor-panel[data-panel="' + tab + '"]').addClass('active');

			// Layout Monaco for the newly visible panel
			if (editors[tab]) {
				setTimeout(function() {
					editors[tab].layout();
					editors[tab].focus();
				}, 10);
			}
		});
	}

	// ── Conditions toggle ──

	function initConditionsToggle() {
		$('#block_position').on('change', function() {
			$('#md-pb-conditions').toggle($(this).val() !== '');
		});
	}

	// ── Slug auto-generation ──

	function initSlugGeneration() {
		var $title = $('#block_title');
		var $slug = $('#block_slug');
		var slugEdited = $slug.val() !== '';

		$title.on('blur', function() {
			if (slugEdited) return;
			var title = $title.val();
			if (!title) return;
			$slug.val(
				title.toLowerCase()
					.replace(/[^a-z0-9\s-]/g, '')
					.replace(/\s+/g, '-')
					.replace(/-+/g, '-')
					.replace(/^-|-$/g, '')
			);
		});

		$slug.on('input', function() { slugEdited = true; });
	}

	// ── Preview ──

	function initPreview() {
		var $btn = $('#md-pb-preview-btn');
		var $container = $('#md-pb-preview-container');
		var $iframe = $('#md-pb-preview-iframe');
		var $status = $('#md-pb-preview-status');
		var previewOpen = false;
		var themeCssUrl = typeof gtPbPreview !== 'undefined' ? gtPbPreview.previewCssUrl : '';

		$btn.on('click', function() {
			if (previewOpen) {
				$container.hide();
				previewOpen = false;
				$btn.find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
				$status.text('');
				return;
			}
			previewOpen = true;
			$container.show();
			$btn.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
			refreshPreview();
		});

		$('.md-pb-viewport').on('click', function() {
			$('.md-pb-viewport').removeClass('active');
			$(this).addClass('active');
			$iframe.css('width', $(this).data('width'));
		});

		function getEditorValue(key, fallbackId) {
			if (editors[key]) return editors[key].getValue();
			var el = document.getElementById(fallbackId);
			return el ? el.value : '';
		}

		function refreshPreview() {
			if (typeof gtPbPreview === 'undefined') return;
			$status.text('Loading...');

			$.ajax({
				url: gtPbPreview.ajaxUrl,
				method: 'POST',
				data: {
					action: 'gt_pb_admin_preview',
					nonce: gtPbPreview.nonce,
					content: getEditorValue('html', 'block_content'),
					css: getEditorValue('css', 'block_css'),
					js: getEditorValue('js', 'block_js'),
					php_exec: $('input[name="block_php_exec"]').is(':checked') ? 1 : 0,
					format: $('input[name="block_format"]').is(':checked') ? 1 : 0
				},
				success: function(response) {
					if (!response.success) { $status.text('Preview error'); return; }
					var data = response.data;
					var doc = $iframe[0].contentDocument || $iframe[0].contentWindow.document;
					doc.open();
					doc.write(
						'<!DOCTYPE html><html><head>' +
						'<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
						'<link rel="stylesheet" href="' + escAttr(themeCssUrl) + '">' +
						(data.css ? '<style>' + data.css + '</style>' : '') +
						'<style>body{margin:0;padding:24px;}</style>' +
						'</head><body>' + data.html +
						(data.js ? '<script>' + data.js + '</' + 'script>' : '') +
						'</body></html>'
					);
					doc.close();
					$status.text('');
				},
				error: function() { $status.text('Preview failed'); }
			});
		}

		// Debounced auto-refresh
		var refreshTimer = null;
		function scheduleRefresh() {
			if (!previewOpen) return;
			clearTimeout(refreshTimer);
			refreshTimer = setTimeout(refreshPreview, 800);
		}

		// Attach change listeners once editors are ready
		function watchEditors() {
			if (!monacoReady || !Object.keys(editors).length) {
				setTimeout(watchEditors, 300);
				return;
			}
			Object.keys(editors).forEach(function(key) {
				editors[key].onDidChangeModelContent(scheduleRefresh);
			});
		}
		watchEditors();
		$('input[name="block_php_exec"], input[name="block_format"]').on('change', scheduleRefresh);
	}

	function escAttr(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	// ── Init ──

	$(document).ready(function() {
		initEditors();
		initTabs();
		initConditionsToggle();
		initSlugGeneration();
		initPreview();
	});

})(jQuery);
