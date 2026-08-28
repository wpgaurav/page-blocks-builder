( function( wp ) {
	var registerBlockType = wp.blocks.registerBlockType,
		el                = wp.element.createElement,
		useState          = wp.element.useState,
		useRef            = wp.element.useRef,
		useEffect         = wp.element.useEffect,
		__                = wp.i18n.__,
		InspectorControls = wp.blockEditor.InspectorControls,
		BlockControls     = wp.blockEditor.BlockControls,
		PanelBody         = wp.components.PanelBody,
		SelectControl     = wp.components.SelectControl,
		ToggleControl     = wp.components.ToggleControl,
		ToolbarGroup      = wp.components.ToolbarGroup,
		ToolbarButton     = wp.components.ToolbarButton,
		Modal             = wp.components.Modal,
		Spinner           = wp.components.Spinner,
		apiFetch          = wp.apiFetch,
		Fragment          = wp.element.Fragment;

	var classSuggestions = normalizeClassSuggestions(
		window.mdPageBlockEditor && window.mdPageBlockEditor.classSuggestions
			? window.mdPageBlockEditor.classSuggestions
			: []
	);

	function cloneObject( input ) {
		var result = {};
		if ( ! input ) {
			return result;
		}
		Object.keys( input ).forEach( function( key ) {
			result[ key ] = input[ key ];
		} );
		return result;
	}

	function arrayContains( arr, value ) {
		if ( ! arr || ! arr.length ) {
			return false;
		}
		return arr.indexOf( value ) !== -1;
	}

	function normalizeClassSuggestions( input ) {
		var map = {};
		var classes = [];

		if ( ! input || ! input.length ) {
			return classes;
		}

		input.forEach( function( item ) {
			if ( typeof item !== 'string' ) {
				return;
			}
			var value = item.trim().replace( /^\./, '' );
			if ( ! value || map[ value ] ) {
				return;
			}
			map[ value ] = true;
			classes.push( value );
		} );

		classes.sort( function( a, b ) {
			return a.localeCompare( b );
		} );

		return classes;
	}

	function getClassHintContext( cm ) {
		if ( ! window.CodeMirror || ! window.CodeMirror.Pos ) {
			return null;
		}

		var cursor = cm.getCursor();
		var line = cm.getLine( cursor.line );
		if ( typeof line !== 'string' ) {
			return null;
		}

		var uptoCursor = line.slice( 0, cursor.ch );
		var classMatch = uptoCursor.match( /class\s*=\s*["']([^"']*)$/i );
		if ( ! classMatch ) {
			return null;
		}

		var classValue = classMatch[1];
		var fragmentMatch = classValue.match( /(?:^|\s)([^\s]*)$/ );
		var fragment = fragmentMatch ? fragmentMatch[1] : '';
		var start = cursor.ch - fragment.length;

		if ( start < 0 ) {
			start = 0;
		}

		return {
			fragment: fragment.toLowerCase(),
			from: window.CodeMirror.Pos( cursor.line, start ),
			to: window.CodeMirror.Pos( cursor.line, cursor.ch )
		};
	}

	function getClassHintData( cm ) {
		if ( ! classSuggestions.length ) {
			return null;
		}

		var context = getClassHintContext( cm );
		if ( ! context ) {
			return null;
		}

		var fragment = context.fragment;
		var list = classSuggestions.filter( function( className ) {
			var lower = className.toLowerCase();
			return ! fragment || lower.indexOf( fragment ) === 0;
		} );

		if ( ! list.length && fragment ) {
			list = classSuggestions.filter( function( className ) {
				return className.toLowerCase().indexOf( fragment ) !== -1;
			} );
		}

		if ( ! list.length ) {
			return null;
		}

		return {
			list: list.slice( 0, 200 ),
			from: context.from,
			to: context.to
		};
	}

	function triggerClassHint( cm, force ) {
		if ( ! cm || typeof cm.showHint !== 'function' ) {
			return;
		}
		if ( ! force && cm.state && cm.state.completionActive ) {
			return;
		}
		if ( ! getClassHintData( cm ) ) {
			return;
		}

		cm.showHint( {
			hint: function( instance ) {
				return getClassHintData( instance );
			},
			completeSingle: false
		} );
	}

	function getPreferredHtmlMode( value ) {
		if ( /<\?(?:php|=)?/i.test( value || '' ) ) {
			return 'application/x-httpd-php';
		}
		return 'htmlmixed';
	}

	function getCodeEditorSettings( tabKey ) {
		var localized = window.mdPageBlockEditor &&
			window.mdPageBlockEditor.codeEditorSettings &&
			window.mdPageBlockEditor.codeEditorSettings[ tabKey ]
			? window.mdPageBlockEditor.codeEditorSettings[ tabKey ]
			: {};
		var settings = cloneObject( localized );
		var codemirror = cloneObject( settings.codemirror || {} );
		var extraKeys = cloneObject( codemirror.extraKeys || {} );
		var gutters = ( codemirror.gutters || [] ).slice( 0 );
		var fallbackModes = {
			html: 'application/x-httpd-php',
			css: 'css',
			js: 'javascript'
		};

		extraKeys.Tab = function( cm ) {
			if ( cm.somethingSelected() ) {
				cm.indentSelection( 'add' );
				return;
			}
			cm.replaceSelection( '\t', 'end', '+input' );
		};
		extraKeys['Shift-Tab'] = function( cm ) {
			cm.indentSelection( 'subtract' );
		};
		extraKeys['Cmd-Z'] = 'undo';
		extraKeys['Ctrl-Z'] = 'undo';
		extraKeys['Cmd-Y'] = 'redo';
		extraKeys['Ctrl-Y'] = 'redo';
		extraKeys['Shift-Cmd-Z'] = 'redo';
		extraKeys['Shift-Ctrl-Z'] = 'redo';
		extraKeys['Ctrl-Q'] = function( cm ) {
			cm.foldCode( cm.getCursor() );
		};
		if ( tabKey === 'html' && classSuggestions.length ) {
			extraKeys['Ctrl-Space'] = function( cm ) {
				triggerClassHint( cm, true );
			};
			extraKeys['Cmd-Space'] = function( cm ) {
				triggerClassHint( cm, true );
			};
		}

		if ( ! arrayContains( gutters, 'CodeMirror-linenumbers' ) ) {
			gutters.unshift( 'CodeMirror-linenumbers' );
		}
		if ( ! arrayContains( gutters, 'CodeMirror-foldgutter' ) ) {
			gutters.push( 'CodeMirror-foldgutter' );
		}

		codemirror.mode = codemirror.mode || fallbackModes[ tabKey ] || 'htmlmixed';
		codemirror.lineNumbers = true;
		codemirror.indentUnit = 4;
		codemirror.tabSize = 4;
		codemirror.indentWithTabs = true;
		codemirror.foldGutter = true;
		codemirror.styleActiveLine = false;
		codemirror.matchBrackets = true;
		codemirror.autoCloseBrackets = true;
		if ( tabKey === 'html' ) {
			// HTML lint is noisy with mixed PHP + inline SVG. Keep parser hints via mode, skip lint markers.
			codemirror.lint = false;
		}
		codemirror.extraKeys = extraKeys;
		codemirror.gutters = gutters;
		settings.codemirror = codemirror;

		return settings;
	}

	function shouldIsolateShortcut( event ) {
		var key = ( event.key || '' ).toLowerCase();
		var keyCode = event.keyCode || event.which;
		var hasModifier = event.metaKey || event.ctrlKey;

		if ( keyCode === 9 ) {
			return true;
		}
		if ( hasModifier && keyCode === 32 ) {
			return true;
		}
		if ( hasModifier && ( keyCode === 90 || keyCode === 89 || key === 'z' || key === 'y' ) ) {
			return true;
		}
		return false;
	}

	function shouldUseServerPreview( attributes ) {
		return !! ( attributes && ( attributes.phpExec || attributes.format ) );
	}

	function requestServerPreviewPayload( attributes ) {
		var config = window.mdPageBlockEditor || {};
		if ( ! config.previewEndpoint || ! config.postId || ! config.previewNonce ) {
			return window.Promise.reject( new Error( 'Missing preview config.' ) );
		}

		var section = {
			content: attributes && typeof attributes.content === 'string' ? attributes.content : '',
			css: attributes && typeof attributes.css === 'string' ? attributes.css : '',
			js: attributes && typeof attributes.js === 'string' ? attributes.js : '',
			jsLocation: attributes && attributes.jsLocation === 'inline' ? 'inline' : 'footer',
			format: !! ( attributes && attributes.format ),
			phpExec: !! ( attributes && attributes.phpExec )
		};

		var form = new window.URLSearchParams();
		form.set( 'action', config.previewAction || 'md_page_blocks_builder_preview' );
		form.set( 'post_id', String( config.postId || 0 ) );
		form.set( 'pb_nonce', String( config.previewNonce || '' ) );
		form.set( 'sections', JSON.stringify( [ section ] ) );

		return window.fetch( config.previewEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: form.toString()
		} ).then( function( response ) {
			return response.json().catch( function() {
				throw new Error( 'Invalid preview response.' );
			} );
		} ).then( function( payload ) {
			if ( ! payload || ! payload.success || ! payload.data ) {
				throw new Error( 'Preview response failed.' );
			}
			return payload.data;
		} );
	}

	/** Viewport presets for the responsive preview. */
	var VIEWPORTS = [
		{ name: 'desktop', icon: 'desktop', width: '', label: __( 'Desktop preview' ) },
		{ name: 'tablet', icon: 'tablet', width: '768px', label: __( 'Tablet preview (768px)' ) },
		{ name: 'mobile', icon: 'smartphone', width: '390px', label: __( 'Mobile preview (390px)' ) }
	];

	/**
	 * Auto-sizing preview iframe. Same-origin srcdoc (no sandbox) so the
	 * frame height can track the rendered content.
	 */
	function AutoFrame( props ) {
		var frameRef = useRef( null );
		var heightState = useState( 140 );
		var height = heightState[0];
		var setHeight = heightState[1];

		function measure() {
			try {
				var f = frameRef.current;
				if ( f && f.contentDocument && f.contentDocument.body ) {
					var h = f.contentDocument.body.scrollHeight;
					if ( h ) {
						setHeight( Math.min( Math.max( h + 6, 80 ), 1600 ) );
					}
				}
			} catch ( e ) {}
		}

		function onLoad() {
			measure();
			setTimeout( measure, 400 );
			setTimeout( measure, 1200 );
		}

		return el( 'iframe', {
			ref: frameRef,
			className: 'md-page-block-preview-iframe',
			title: __( 'Page Block Preview' ),
			srcDoc: props.doc || '',
			onLoad: onLoad,
			style: { height: height + 'px' }
		} );
	}

	var PB_BLOCK_NAME = 'gt-page-block/page-block';
	var PB_LEGACY_NAME = 'marketers-delight/page-block';

	var pageBlockSettings = {
		title: __( 'Page Block' ),
		description: __( 'Custom HTML, CSS, and JavaScript code block.' ),
		icon: 'editor-code',
		category: 'gt-page-blocks',

		transforms: {
			from: [
				{
					type: 'block',
					blocks: [ PB_LEGACY_NAME ],
					transform: function( attributes ) {
						return wp.blocks.createBlock( PB_BLOCK_NAME, attributes );
					}
				}
			]
		},

		attributes: {
			content:    { type: 'string', default: '' },
			css:        { type: 'string', default: '' },
			js:         { type: 'string', default: '' },
			jsLocation: { type: 'string', default: 'footer' },
			format:     { type: 'boolean', default: false },
			phpExec:    { type: 'boolean', default: false },
			output:     { type: 'string', default: 'inline' }
		},

		edit: function( props ) {
			var attributes = props.attributes;
			var config = window.mdPageBlockEditor || {};

			var activeTabState = useState( 'html' );
			var activeTab = activeTabState[0];
			var setActiveTab = activeTabState[1];

			var hasAnyContent = !! ( attributes.content || attributes.css || attributes.js );

			var modeState = useState( hasAnyContent ? 'preview' : 'editor' );
			var mode = modeState[0];
			var setMode = modeState[1];
			var previewDocState = useState( '' );
			var previewDoc = previewDocState[0];
			var setPreviewDoc = previewDocState[1];
			var previewReqRef = useRef( 0 );
			var textareasRef = useRef( {} );
			var editorsRef = useRef( {} );
			var attrsRef = useRef( attributes );
			var isSyncingRef = useRef( false );

			var viewportState = useState( 'desktop' );
			var viewport = viewportState[0];
			var setViewport = viewportState[1];

			var darkState = useState( false );
			var previewDark = darkState[0];
			var setPreviewDark = darkState[1];

			var copiedState = useState( false );
			var copied = copiedState[0];
			var setCopied = copiedState[1];

			var savingState = useState( false );
			var saving = savingState[0];
			var setSaving = savingState[1];

			var livePaneState = useState( false );
			var livePane = livePaneState[0];
			var setLivePane = livePaneState[1];

			var libOpenState = useState( false );
			var libOpen = libOpenState[0];
			var setLibOpen = libOpenState[1];

			var libItemsState = useState( null );
			var libItems = libItemsState[0];
			var setLibItems = libItemsState[1];

			var libSearchState = useState( '' );
			var libSearch = libSearchState[0];
			var setLibSearch = libSearchState[1];

			var libLoadingState = useState( false );
			var libLoading = libLoadingState[0];
			var setLibLoading = libLoadingState[1];

			var phpDetected = /<\?(?:php|=)/.test( attributes.content || '' );
			var notices = wp.data && wp.data.dispatch ? wp.data.dispatch( 'core/notices' ) : null;

			var tabs = [
				{ key: 'html', label: __( 'HTML' ), attr: 'content' },
				{ key: 'css',  label: __( 'CSS' ),  attr: 'css' },
				{ key: 'js',   label: __( 'JS' ),   attr: 'js' }
			];

			function hasContent( tab ) {
				return !! attributes[ tab.attr ];
			}

			function onTextareaChange( attr ) {
				return function( e ) {
					var update = {};
					update[ attr ] = e.target.value;
					props.setAttributes( update );
				};
			}

			function onTextareaKeyDown( e ) {
				if ( e.keyCode === 9 ) {
					var start = e.target.selectionStart;
					var end = e.target.selectionEnd;
					var value = e.target.value;
					e.target.value = value.substring( 0, start ) + '\t' + value.substring( end );
					e.target.selectionStart = e.target.selectionEnd = start + 1;
					e.preventDefault();
				}
				if ( shouldIsolateShortcut( e ) ) {
					e.stopPropagation();
				}
			}

			function destroyCodeEditors() {
				Object.keys( editorsRef.current ).forEach( function( key ) {
					var editor = editorsRef.current[ key ];
					if ( editor && typeof editor.toTextArea === 'function' ) {
						editor.toTextArea();
					}
				} );
				editorsRef.current = {};
			}

			function initCodeEditor( tab ) {
				if ( ! wp.codeEditor || typeof wp.codeEditor.initialize !== 'function' ) {
					return;
				}

				var textarea = textareasRef.current[ tab.key ];
				if ( ! textarea || editorsRef.current[ tab.key ] ) {
					return;
				}

				var editorInstance = wp.codeEditor.initialize( textarea, getCodeEditorSettings( tab.key ) );
				if ( ! editorInstance || ! editorInstance.codemirror ) {
					return;
				}

				var cm = editorInstance.codemirror;
				var currentValue = attributes[ tab.attr ] || '';

				if ( tab.key === 'html' ) {
					cm.setOption( 'mode', getPreferredHtmlMode( currentValue ) );
				}
				if ( cm.getValue() !== currentValue ) {
					cm.setValue( currentValue );
				}

				cm.on( 'change', function( instance ) {
					if ( isSyncingRef.current ) {
						return;
					}
					var value = instance.getValue();
					if ( value === ( attrsRef.current[ tab.attr ] || '' ) ) {
						return;
					}
					if ( tab.key === 'html' ) {
						var preferredMode = getPreferredHtmlMode( value );
						if ( instance.getOption( 'mode' ) !== preferredMode ) {
							instance.setOption( 'mode', preferredMode );
						}
					}
					var update = {};
					update[ tab.attr ] = value;
					props.setAttributes( update );
				} );

				cm.on( 'keydown', function( instance, event ) {
					if ( shouldIsolateShortcut( event ) ) {
						event.stopPropagation();
					}
				} );

				editorsRef.current[ tab.key ] = cm;

				setTimeout( function() {
					cm.refresh();
				}, 0 );
			}

			useEffect( function() {
				attrsRef.current = attributes;
			}, [ attributes.content, attributes.css, attributes.js ] );

			useEffect( function() {
				if ( mode === 'preview' ) {
					destroyCodeEditors();
					return;
				}
				tabs.forEach( initCodeEditor );
			}, [ mode ] );

			useEffect( function() {
				if ( mode !== 'editor' ) {
					return;
				}
				tabs.forEach( function( tab ) {
					var cm = editorsRef.current[ tab.key ];
					if ( ! cm ) {
						return;
					}
					var nextValue = attributes[ tab.attr ] || '';
					if ( cm.getValue() === nextValue ) {
						return;
					}

					isSyncingRef.current = true;
					cm.setValue( nextValue );
					isSyncingRef.current = false;
				} );
			}, [ attributes.content, attributes.css, attributes.js, mode ] );

			useEffect( function() {
				if ( mode !== 'editor' ) {
					return;
				}
				var cm = editorsRef.current[ activeTab ];
				if ( ! cm ) {
					return;
				}
				setTimeout( function() {
					cm.refresh();
				}, 40 );
			}, [ activeTab, mode ] );

			useEffect( function() {
				return function() {
					destroyCodeEditors();
				};
			}, [] );

			function buildPreviewDoc( renderedData, dark ) {
				var data = renderedData && typeof renderedData === 'object' ? renderedData : {};
				var css = typeof data.css === 'string' ? data.css : ( attributes.css || '' );
				var html = typeof data.html === 'string' ? data.html : ( attributes.content || '' );
				var inlineJs = typeof data.jsInline === 'string' ? data.jsInline : ( attributes.js || '' );
				var footerJs = typeof data.jsFooter === 'string' ? data.jsFooter : '';

				var themeLinks = '';
				var styles = config.previewStyles;
				if ( Array.isArray( styles ) ) {
					for ( var i = 0; i < styles.length; i++ ) {
						if ( typeof styles[i] === 'string' && styles[i] ) {
							themeLinks += '<link rel="stylesheet" href="' + styles[i] + '">';
						}
					}
				}

				return '<!DOCTYPE html><html' + ( dark ? ' data-theme="dark"' : '' ) + '><head><meta charset="utf-8">' +
					'<meta name="viewport" content="width=device-width, initial-scale=1">' +
					themeLinks +
					'<style>body{margin:0;padding:12px;}*{box-sizing:border-box;}</style>' +
					( css ? '<style>' + css + '</style>' : '' ) +
					'</head><body>' + html +
					( inlineJs ? '<script>' + inlineJs + '<\/script>' : '' ) +
					( footerJs ? '<script>' + footerJs + '<\/script>' : '' ) +
					'</body></html>';
			}

			// Rebuild the preview document (debounced) whenever it is visible:
			// full preview mode, or the live pane under the code editor.
			useEffect( function() {
				if ( mode !== 'preview' && ! ( mode === 'editor' && livePane ) ) {
					return;
				}

				var timer = setTimeout( function() {
					var requestId = previewReqRef.current + 1;
					previewReqRef.current = requestId;

					if ( ! shouldUseServerPreview( attributes ) ) {
						setPreviewDoc( buildPreviewDoc( null, previewDark ) );
						return;
					}

					requestServerPreviewPayload( attributes )
						.then( function( payload ) {
							if ( requestId !== previewReqRef.current ) {
								return;
							}
							setPreviewDoc( buildPreviewDoc( payload, previewDark ) );
						} )
						.catch( function() {
							if ( requestId !== previewReqRef.current ) {
								return;
							}
							setPreviewDoc( buildPreviewDoc( null, previewDark ) );
						} );
				}, mode === 'preview' ? 0 : 400 );

				return function() { clearTimeout( timer ); };
			}, [ mode, livePane, previewDark, attributes.content, attributes.css, attributes.js, attributes.jsLocation, attributes.format, attributes.phpExec ] );

			// Copy the active tab's code to the clipboard.
			function copyActiveCode() {
				function done() {
					setCopied( true );
					setTimeout( function() { setCopied( false ); }, 1500 );
				}
				var tab = tabs.filter( function( t ) { return t.key === activeTab; } )[0];
				var txt = tab ? ( attributes[ tab.attr ] || '' ) : '';
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( txt ).then( done, done );
				} else {
					var ta = document.createElement( 'textarea' );
					ta.value = txt;
					document.body.appendChild( ta );
					ta.select();
					try { document.execCommand( 'copy' ); } catch ( e ) {}
					document.body.removeChild( ta );
					done();
				}
			}

			// Promote this block to a reusable library Page Block.
			function saveAsReusable() {
				var title = window.prompt( __( 'Name this Page Block:' ), '' );
				if ( ! title ) {
					return;
				}
				setSaving( true );

				var form = new window.URLSearchParams();
				form.set( 'action', config.libraryAction || 'gt_pb_save_to_library' );
				form.set( 'nonce', String( config.libraryNonce || '' ) );
				form.set( 'title', title );
				form.set( 'content', attributes.content || '' );
				form.set( 'css', attributes.css || '' );
				form.set( 'js', attributes.js || '' );
				form.set( 'js_location', attributes.jsLocation === 'inline' ? 'inline' : 'footer' );
				form.set( 'output', attributes.output === 'file' ? 'file' : 'inline' );
				form.set( 'php_exec', attributes.phpExec ? '1' : '' );
				form.set( 'format', attributes.format ? '1' : '' );

				window.fetch( config.ajaxUrl || window.ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: form.toString()
				} ).then( function( response ) {
					return response.json();
				} ).then( function( payload ) {
					setSaving( false );
					if ( payload && payload.success && payload.data ) {
						if ( notices ) {
							notices.createSuccessNotice(
								__( 'Saved to the Page Blocks library: ' ) + payload.data.title,
								{
									type: 'snackbar',
									actions: config.libraryEditUrl ? [ { label: __( 'Edit in library' ), url: config.libraryEditUrl + payload.data.id } ] : []
								}
							);
						}
					} else if ( notices ) {
						var msg = payload && payload.data && payload.data.message ? payload.data.message : __( 'Could not save the Page Block.' );
						notices.createErrorNotice( msg, { type: 'snackbar' } );
					}
				} ).catch( function() {
					setSaving( false );
					if ( notices ) {
						notices.createErrorNotice( __( 'Could not save the Page Block.' ), { type: 'snackbar' } );
					}
				} );
			}

			// Fetch library blocks for the browser modal.
			function loadLibrary( search ) {
				if ( ! apiFetch ) {
					return;
				}
				setLibLoading( true );
				apiFetch( {
					path: '/pbb/v1/blocks?per_page=50&status=publish&context=summary' + ( search ? '&search=' + encodeURIComponent( search ) : '' )
				} ).then( function( items ) {
					setLibItems( Array.isArray( items ) ? items : [] );
					setLibLoading( false );
				} ).catch( function() {
					setLibItems( [] );
					setLibLoading( false );
				} );
			}

			function openLibrary() {
				setLibOpen( true );
				if ( libItems === null ) {
					loadLibrary( '' );
				}
			}

			// Copy a library block's code into this inline block. The library
			// list is a summary (no code payloads), so fetch the full block first.
			function insertFromLibrary( item ) {
				function applyBlock( full ) {
					props.setAttributes( {
						content: full.content || '',
						css: full.css || '',
						js: full.js || '',
						jsLocation: full.js_location === 'inline' ? 'inline' : 'footer',
						output: full.output === 'file' ? 'file' : 'inline',
						phpExec: !! full.php_exec,
						format: !! full.format
					} );
					setLibOpen( false );
					setMode( 'preview' );
					if ( notices ) {
						notices.createSuccessNotice(
							__( 'Inserted a copy of: ' ) + ( full.title || item.title || '#' + item.id ),
							{ type: 'snackbar' }
						);
					}
				}
				if ( typeof item.content === 'string' ) {
					applyBlock( item );
					return;
				}
				setLibLoading( true );
				apiFetch( { path: '/pbb/v1/blocks/' + item.id } ).then( function( full ) {
					setLibLoading( false );
					applyBlock( full );
				} ).catch( function() {
					setLibLoading( false );
					if ( notices ) {
						notices.createErrorNotice( __( 'Could not load that library block.' ), { type: 'snackbar' } );
					}
				} );
			}

			function libraryModal() {
				if ( ! libOpen ) {
					return null;
				}
				var searchTimerRef = { id: 0 };
				return el( Modal, {
					title: __( 'Page Blocks library' ),
					onRequestClose: function() { setLibOpen( false ); },
					className: 'md-page-block-library-modal'
				},
					el( 'input', {
						type: 'search',
						className: 'md-page-block-library-search',
						placeholder: __( 'Search the library…' ),
						value: libSearch,
						onChange: function( e ) {
							var value = e.target.value;
							setLibSearch( value );
							window.clearTimeout( searchTimerRef.id );
							searchTimerRef.id = window.setTimeout( function() {
								loadLibrary( value.trim() );
							}, 300 );
						}
					} ),
					libLoading && el( 'div', { className: 'md-page-block-library-loading' }, el( Spinner ) ),
					! libLoading && libItems && ! libItems.length && el( 'p', { className: 'md-page-block-library-empty' },
						libSearch ? __( 'No blocks match your search.' ) : __( 'The library is empty — save a block to it first.' )
					),
					! libLoading && libItems && libItems.length > 0 && el( 'ul', { className: 'md-page-block-library-list' },
						libItems.map( function( item ) {
							return el( 'li', { key: item.id, className: 'md-page-block-library-item' },
								el( 'div', { className: 'md-page-block-library-info' },
									el( 'strong', {}, item.title || '(untitled)' ),
									el( 'span', { className: 'md-page-block-library-meta' },
										el( 'code', {}, item.slug ),
										( item.has_css || item.css ) ? ' · CSS' : '',
										( item.has_js || item.js ) ? ' · JS' : '',
										item.php_exec ? ' · PHP' : ''
									)
								),
								el( 'button', {
									type: 'button',
									className: 'button button-small',
									onClick: function() {
										if ( hasAnyContent && ! window.confirm( __( 'Replace this block\u2019s current code with the library block?' ) ) ) {
											return;
										}
										insertFromLibrary( item );
									}
								}, __( 'Insert copy' ) )
							);
						} )
					)
				);
			}

			function badges() {
				return el( 'span', { className: 'md-page-block-badges' },
					attributes.content && el( 'span', { className: 'md-page-block-badge' }, 'HTML' ),
					attributes.css && el( 'span', { className: 'md-page-block-badge' }, 'CSS' ),
					attributes.js && el( 'span', { className: 'md-page-block-badge' }, 'JS' ),
					( phpDetected || attributes.phpExec ) && el( 'span', {
						className: 'md-page-block-badge md-page-block-badge--php',
						title: attributes.phpExec ? __( 'PHP runs on the front end (and in this server-rendered preview).' ) : __( 'Contains PHP tags.' )
					}, 'PHP' )
				);
			}

			// Viewport + dark-mode control cluster.
			function previewControls() {
				return el( 'span', { className: 'md-page-block-controls' },
					VIEWPORTS.map( function( vp ) {
						return el( 'button', {
							key: vp.name,
							type: 'button',
							className: 'md-page-block-ctrl' + ( viewport === vp.name ? ' is-on' : '' ),
							title: vp.label,
							onClick: function() { setViewport( vp.name ); }
						}, el( 'span', { className: 'dashicons dashicons-' + vp.icon } ) );
					} ),
					el( 'button', {
						type: 'button',
						className: 'md-page-block-ctrl' + ( previewDark ? ' is-on' : '' ),
						title: previewDark ? __( 'Switch preview to light scheme' ) : __( 'Switch preview to dark scheme' ),
						onClick: function() { setPreviewDark( ! previewDark ); }
					}, el( 'span', { className: 'dashicons dashicons-lightbulb' } ) )
				);
			}

			// Width-constrained wrapper around the preview iframe.
			function viewportFrame() {
				var vp = VIEWPORTS.filter( function( v ) { return v.name === viewport; } )[0] || VIEWPORTS[0];
				return el( 'div', {
					className: 'md-page-block-viewport' + ( vp.width ? ' is-narrow' : '' )
				},
					el( 'div', {
						className: 'md-page-block-viewport-inner',
						style: vp.width ? { maxWidth: vp.width } : {}
					},
						el( AutoFrame, { doc: previewDoc || buildPreviewDoc( null, previewDark ) } )
					)
				);
			}

			var toolbar = el( BlockControls, {},
				el( ToolbarGroup, {},
					el( ToolbarButton, {
						icon: 'visibility',
						label: __( 'Preview' ),
						isPressed: mode === 'preview',
						onClick: function() { setMode( 'preview' ); }
					} ),
					el( ToolbarButton, {
						icon: 'editor-code',
						label: __( 'Edit code' ),
						isPressed: mode === 'editor',
						onClick: function() { setMode( 'editor' ); }
					} ),
					el( ToolbarButton, {
						icon: 'portfolio',
						label: __( 'Browse library' ),
						onClick: openLibrary
					} )
				)
			);

			var inspector = el( InspectorControls, null,
				el( PanelBody, { title: __( 'Settings' ) },
					el( SelectControl, {
						label: __( 'JavaScript Location' ),
						value: attributes.jsLocation,
						options: [
							{ label: __( 'Footer' ), value: 'footer' },
							{ label: __( 'Inline' ), value: 'inline' }
						],
						onChange: function( val ) {
							props.setAttributes( { jsLocation: val } );
						}
					}),
					el( ToggleControl, {
						label: __( 'WordPress formatting (wpautop)' ),
						checked: attributes.format,
						onChange: function( val ) {
							props.setAttributes( { format: val } );
						}
					}),
					el( ToggleControl, {
						label: __( 'Execute PHP code' ),
						checked: attributes.phpExec,
						onChange: function( val ) {
							props.setAttributes( { phpExec: val } );
						}
					})
				)
			);

			// Preview mode
			if ( mode === 'preview' ) {
				return el( Fragment, null,
					toolbar,
					inspector,
					libraryModal(),
					el( 'div', { className: 'md-page-block-preview-wrap' },
						el( 'div', { className: 'md-page-block-bar' },
							el( 'span', { className: 'dashicons dashicons-editor-code md-page-block-bar-icon' } ),
							el( 'span', { className: 'md-page-block-bar-title' }, __( 'Page Block' ) ),
							badges(),
							el( 'span', { className: 'md-page-block-bar-spacer' } ),
							previewControls(),
							config.canSave && el( 'button', {
								type: 'button',
								className: 'md-page-block-bar-btn',
								disabled: saving,
								onClick: saveAsReusable,
								title: __( 'Save a copy to the Page Blocks library' )
							}, saving ? __( 'Saving…' ) : __( 'Save to library' ) ),
							el( 'button', {
								type: 'button',
								className: 'md-page-block-bar-btn md-page-block-bar-btn--primary',
								onClick: function() { setMode( 'editor' ); }
							}, __( 'Edit code' ) )
						),
						viewportFrame()
					)
				);
			}

			// Editor mode
			return el( Fragment, null,
				toolbar,
				inspector,
				libraryModal(),

				el( 'div', { className: 'md-page-block-editor' },
					el( 'div', { className: 'md-page-block-toolbar' },
						// Tab navigation
						el( 'div', { className: 'md-page-block-tabs' },
							tabs.map( function( tab ) {
								return el( 'button', {
									key: tab.key,
									type: 'button',
									className: 'md-page-block-tab' +
										( activeTab === tab.key ? ' is-active' : '' ) +
										( hasContent( tab ) ? ' has-content' : '' ),
									onClick: function() { setActiveTab( tab.key ); }
								}, tab.label );
							})
						),
						// Copy active tab code
						el( 'button', {
							type: 'button',
							className: 'md-page-block-preview-btn',
							onClick: copyActiveCode,
							title: __( 'Copy this tab\'s code' )
						},
							copied
								? el( 'span', { className: 'dashicons dashicons-yes' } )
								: el( 'span', { className: 'dashicons dashicons-clipboard' } )
						),
						// Preview button
						hasAnyContent ?
							el( 'button', {
								type: 'button',
								className: 'md-page-block-preview-btn',
								onClick: function() { setMode( 'preview' ); },
								title: __( 'Preview' )
							},
								el( 'span', { className: 'dashicons dashicons-visibility' } )
							)
						: null
					),

					// Tab panels
					tabs.map( function( tab ) {
						return el( 'div', {
							key: tab.key,
							className: 'md-page-block-panel',
							style: { display: activeTab === tab.key ? 'block' : 'none' }
						},
							el( 'textarea', {
								className: 'md-page-block-textarea',
								value: attributes[ tab.attr ] || '',
								ref: function( node ) {
									textareasRef.current[ tab.key ] = node;
								},
								onChange: onTextareaChange( tab.attr ),
								onKeyDown: onTextareaKeyDown,
								placeholder: tab.label + ' ' + __( 'code here...' ),
								rows: 12,
								spellCheck: false
							})
						);
					}),

					// Collapsible live preview under the editor
					el( 'div', { className: 'md-page-block-live' },
						el( 'div', { className: 'md-page-block-live-bar' },
							el( 'button', {
								type: 'button',
								className: 'md-page-block-live-toggle',
								onClick: function() { setLivePane( ! livePane ); },
								'aria-expanded': livePane ? 'true' : 'false'
							},
								el( 'span', { className: 'dashicons ' + ( livePane ? 'dashicons-arrow-down-alt2' : 'dashicons-arrow-right-alt2' ) } ),
								__( 'Live preview' )
							),
							livePane && previewControls()
						),
						livePane && viewportFrame()
					)
				)
			);
		},

		save: function() {
			return null;
		}
	};

	registerBlockType( PB_BLOCK_NAME, pageBlockSettings );

	// Legacy alias: same editing experience for un-migrated content, but
	// hidden from the inserter. Use the migration tool (Settings -> Tools
	// or `wp gt-pb migrate-blocks`) to rewrite stored content.
	var legacySettings = {};
	Object.keys( pageBlockSettings ).forEach( function( key ) {
		legacySettings[ key ] = pageBlockSettings[ key ];
	} );
	delete legacySettings.transforms;
	legacySettings.title = __( 'Page Block (legacy)' );
	legacySettings.supports = { inserter: false };
	registerBlockType( PB_LEGACY_NAME, legacySettings );
})( wp );
