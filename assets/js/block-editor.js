( function( wp ) {
	var registerBlockType = wp.blocks.registerBlockType,
		el                = wp.element.createElement,
		useState          = wp.element.useState,
		useRef            = wp.element.useRef,
		useEffect         = wp.element.useEffect,
		__                = wp.i18n.__,
		sprintf           = wp.i18n.sprintf,
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

	/**
	 * CSS custom properties the active theme defines, harvested server-side
	 * from its stylesheets and theme.json. Suggesting these rather than a
	 * fixed list is what keeps the editor useful on any theme.
	 */
	var cssVariables = ( function () {
		var raw = window.mdPageBlockEditor && window.mdPageBlockEditor.cssVariables;
		if ( ! Array.isArray( raw ) ) {
			return [];
		}
		return raw.filter( function ( v ) {
			return typeof v === 'string' && v.indexOf( '--' ) === 0;
		} );
	} )();

	/**
	 * The `--foo` token immediately before the caret, if the caret is inside
	 * one. Returns null when there is nothing to complete.
	 */
	function variableTokenAt( value, caret ) {
		var upto = value.slice( 0, caret );
		var m = upto.match( /(--[A-Za-z0-9_-]*)$/ );
		if ( ! m ) {
			return null;
		}
		return { token: m[1], from: caret - m[1].length, to: caret };
	}

	function matchVariables( token ) {
		var needle = token.toLowerCase();
		var out = [];
		for ( var i = 0; i < cssVariables.length && out.length < 40; i++ ) {
			if ( cssVariables[ i ].toLowerCase().indexOf( needle ) === 0 ) {
				out.push( cssVariables[ i ] );
			}
		}
		return out;
	}

	/**
	 * Caret position in pixels inside a textarea. The code panes are
	 * monospace, so column * character-width is exact rather than estimated.
	 */
	function caretOffset( textarea, caret ) {
		var doc = textarea.ownerDocument;
		var cs = doc.defaultView.getComputedStyle( textarea );
		var probe = doc.createElement( 'span' );
		probe.textContent = '0';
		probe.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;font:' + cs.font;
		doc.body.appendChild( probe );
		var chW = probe.getBoundingClientRect().width || 7;
		doc.body.removeChild( probe );

		var lineH = parseFloat( cs.lineHeight );
		if ( ! lineH ) {
			lineH = parseFloat( cs.fontSize ) * 1.6;
		}

		var upto  = textarea.value.slice( 0, caret );
		var lines = upto.split( '\n' );
		var row   = lines.length - 1;
		var col   = lines[ lines.length - 1 ].length;

		return {
			left: parseFloat( cs.paddingLeft ) + col * chW - textarea.scrollLeft,
			top:  parseFloat( cs.paddingTop ) + ( row + 1 ) * lineH - textarea.scrollTop
		};
	}

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

	/**
	 * Shape a library REST row like block attributes, so the preview, badges,
	 * and the detach path can treat a linked block and an inline one the same.
	 *
	 * @param {Object|null|false} row Row from GET /pbb/v1/blocks/<id>.
	 */
	function libraryRowAsSource( row ) {
		if ( ! row || typeof row !== 'object' ) {
			return {
				content: '', css: '', js: '',
				jsLocation: 'footer', format: false, phpExec: false, output: 'inline'
			};
		}

		return {
			content:    typeof row.content === 'string' ? row.content : '',
			css:        typeof row.css === 'string' ? row.css : '',
			js:         typeof row.js === 'string' ? row.js : '',
			jsLocation: row.js_location === 'inline' ? 'inline' : 'footer',
			format:     !! row.format,
			phpExec:    !! row.php_exec,
			output:     row.output === 'file' ? 'file' : 'inline'
		};
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
		var frameRef  = useRef( null );
		var holderRef = useRef( null );

		var heightState = useState( 140 );
		var height = heightState[0];
		var setHeight = heightState[1];

		// Each mounted preview is a whole document carrying the theme's CSS —
		// on a real page that measured ~25 stylesheets and ~2,200 rules to
		// style ~30 elements, once per block. Eight blocks meant ~200
		// stylesheets and ~17,600 rules live at once, and scrolling paid for
		// all of it. Frames are mounted only while near the viewport.
		var mountedState = useState( false );
		var mounted = mountedState[0];
		var setMounted = mountedState[1];

		useEffect( function() {
			var node = holderRef.current;
			if ( ! node ) {
				return;
			}

			// The observer must come from the window the node lives in — the
			// block canvas is its own iframe, so an observer built in the
			// admin window would measure against the wrong viewport.
			var view = node.ownerDocument && node.ownerDocument.defaultView;
			if ( ! view || ! view.IntersectionObserver ) {
				setMounted( true );
				return;
			}

			var io = new view.IntersectionObserver(
				function( entries ) {
					for ( var i = 0; i < entries.length; i++ ) {
						setMounted( entries[ i ].isIntersecting );
					}
				},
				// Generous margin so a frame is ready before it is seen, and
				// so ordinary scrolling never oscillates across the boundary.
				{ root: null, rootMargin: '800px 0px' }
			);

			io.observe( node );
			return function() { io.disconnect(); };
		}, [] );

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

		// The holder keeps the last measured height whether or not the frame
		// is mounted, so unmounting never collapses the block and yanks the
		// scroll position out from under the cursor.
		return el( 'div', {
			ref: holderRef,
			className: 'md-page-block-preview-holder',
			style: { height: height + 'px' }
		},
			mounted
				? el( 'iframe', {
					ref: frameRef,
					className: 'md-page-block-preview-iframe',
					title: __( 'Page Block Preview' ),
					srcDoc: props.doc || '',
					onLoad: onLoad
				} )
				: null
		);
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
			// A non-zero blockId makes this block a *reference* to a library
			// row: the code lives in one place and every placement follows it.
			// This must mirror the PHP registration — a client registration
			// that omits an attribute drops it from stored content on re-save,
			// which would silently unlink every migrated block.
			blockId:    { type: 'number', default: 0 },
			content:    { type: 'string', default: '' },
			css:        { type: 'string', default: '' },
			js:         { type: 'string', default: '' },
			jsLocation: { type: 'string', default: 'footer' },
			format:     { type: 'boolean', default: false },
			phpExec:    { type: 'boolean', default: false },
			output:     { type: 'string', default: 'inline' },
			// Added in 3.0.0. These must mirror the PHP registration for the
			// same reason the comment above gives.
			name:              { type: 'string', default: '' },
			blockSlug:         { type: 'string', default: '' },
			respectConditions: { type: 'boolean', default: false }
		},

		edit: function( props ) {
			var attributes = props.attributes;
			var config = window.mdPageBlockEditor || {};

			// Reference mode: render_block() resolves this to a library row and
			// ignores the inline content/css/js attributes entirely.
			var linkedId   = Math.max( 0, parseInt( attributes.blockId, 10 ) || 0 );
			var linkedSlug = String( attributes.blockSlug || '' );
			// A block copied from another site carries a slug whose id means
			// nothing here, so an id-only test showed it as unlinked and
			// offered to edit code that render_block() would ignore.
			var isLinked   = linkedId > 0 || '' !== linkedSlug;

			var activeTabState = useState( 'html' );
			var activeTab = activeTabState[0];
			var setActiveTab = activeTabState[1];

			var hasAnyContent = !! ( attributes.content || attributes.css || attributes.js );

			var modeState = useState( hasAnyContent || isLinked ? 'preview' : 'editor' );
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

			// { items, index, top, left, from, to, attr } while a variable
			// completion is open; null otherwise.
			var hintState = useState( null );
			var hint = hintState[0];
			var setHint = hintState[1];

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

			// The library row behind a linked block: null while it is being
			// fetched, false once the fetch failed (deleted row, or no access).
			var linkedRowState = useState( null );
			var linkedRow = linkedRowState[0];
			var setLinkedRow = linkedRowState[1];

			var linkedReqRef = useRef( 0 );

			// Everything the preview, badges, and detach path describe: the
			// library row for a linked block, the block's own attributes
			// otherwise.
			var source        = isLinked ? libraryRowAsSource( linkedRow ) : attributes;
			var linkedPending = isLinked && linkedRow === null;
			var linkedMissing = isLinked && linkedRow === false;
			var linkedDraft   = isLinked && !! linkedRow && 'publish' !== linkedRow.status;

			// Linked blocks have no editable code here, so they never leave
			// preview — the editor tabs would write attributes render_block()
			// throws away.
			var viewMode = isLinked ? 'preview' : mode;

			var phpDetected = /<\?(?:php|=)/.test( source.content || '' );
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
					var node = e.target;
					var update = {};
					update[ attr ] = node.value;
					props.setAttributes( update );
					refreshHint( node, attr );
				};
			}

			/**
			 * Open, update, or close the variable suggestion list for the
			 * caret's current position.
			 */
			function refreshHint( node, attr ) {
				if ( ! cssVariables.length ) {
					return;
				}

				var tok = variableTokenAt( node.value, node.selectionStart );
				if ( ! tok ) {
					setHint( null );
					return;
				}

				var items = matchVariables( tok.token );
				if ( ! items.length ) {
					setHint( null );
					return;
				}

				var pos = caretOffset( node, tok.from );
				setHint( {
					items: items,
					index: 0,
					top: pos.top,
					left: pos.left,
					from: tok.from,
					to: tok.to,
					attr: attr
				} );
			}

			/**
			 * Replace the partial token with the chosen variable and put the
			 * caret after it.
			 */
			function applyHint( choice ) {
				if ( ! hint ) {
					return;
				}

				var node = textareasRef.current[ activeTab ];
				if ( ! node ) {
					return;
				}

				var value = node.value;
				var next  = value.slice( 0, hint.from ) + choice + value.slice( hint.to );
				var caret = hint.from + choice.length;

				var update = {};
				update[ hint.attr ] = next;
				props.setAttributes( update );
				setHint( null );

				window.requestAnimationFrame( function() {
					if ( node.isConnected ) {
						node.focus();
						node.selectionStart = node.selectionEnd = caret;
					}
				} );
			}

			function onTextareaKeyDown( attr ) {
				return function( e ) {
					// The suggestion list owns these keys while it is open.
					if ( hint && hint.items.length ) {
						if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
							e.preventDefault();
							e.stopPropagation();
							var delta = e.key === 'ArrowDown' ? 1 : -1;
							var next = ( hint.index + delta + hint.items.length ) % hint.items.length;
							setHint( Object.assign( {}, hint, { index: next } ) );
							return;
						}
						if ( e.key === 'Enter' || e.keyCode === 9 ) {
							e.preventDefault();
							e.stopPropagation();
							applyHint( hint.items[ hint.index ] );
							return;
						}
						if ( e.key === 'Escape' ) {
							e.preventDefault();
							e.stopPropagation();
							setHint( null );
							return;
						}
					}

					if ( e.keyCode === 9 ) {
						e.preventDefault();

						var node  = e.target;
						var start = node.selectionStart;
						var end   = node.selectionEnd;
						var next  = node.value.substring( 0, start ) + '\t' + node.value.substring( end );

						// Commit through setAttributes. Writing node.value
						// directly moves the caret but never reaches the block,
						// so the tab vanished on the next render.
						var update = {};
						update[ attr ] = next;
						props.setAttributes( update );

						// Restore the caret after React re-renders the value.
						window.requestAnimationFrame( function() {
							if ( node.isConnected ) {
								node.selectionStart = node.selectionEnd = start + 1;
							}
						} );
					}
					if ( shouldIsolateShortcut( e ) ) {
						e.stopPropagation();
					}
				};
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

				// wp.codeEditor and CodeMirror are loaded into the admin
				// document. Since WP 6.3 the block canvas is an iframe, and a
				// CodeMirror mounted onto an element in that iframe keeps
				// resolving focus, selection and key events against the outer
				// document: it renders, but clicking and typing do nothing and
				// even cm.focus() lands elsewhere. Leave the plain textarea in
				// place instead — the same choice core's Custom HTML block
				// makes, and it is fully wired to setAttributes already.
				if ( textarea.ownerDocument !== window.document ) {
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
				if ( viewMode === 'preview' ) {
					destroyCodeEditors();
					return;
				}
				tabs.forEach( initCodeEditor );
			}, [ viewMode ] );

			useEffect( function() {
				if ( viewMode !== 'editor' ) {
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
			}, [ attributes.content, attributes.css, attributes.js, viewMode ] );

			useEffect( function() {
				if ( viewMode !== 'editor' ) {
					return;
				}
				var cm = editorsRef.current[ activeTab ];
				if ( ! cm ) {
					return;
				}
				setTimeout( function() {
					cm.refresh();
				}, 40 );
			}, [ activeTab, viewMode ] );

			useEffect( function() {
				return function() {
					destroyCodeEditors();
				};
			}, [] );

			// Resolve the linked library row. Keyed on linkedId so re-pointing
			// a block never leaves the previous target's title or code on screen.
			useEffect( function() {
				if ( ! isLinked ) {
					setLinkedRow( null );
					return;
				}

				var requestId = linkedReqRef.current + 1;
				linkedReqRef.current = requestId;
				setLinkedRow( null );

				if ( ! apiFetch ) {
					setLinkedRow( false );
					return;
				}

				apiFetch( { path: '/pbb/v1/blocks/' + linkedId } ).then( function( row ) {
					if ( requestId === linkedReqRef.current ) {
						setLinkedRow( row && typeof row === 'object' ? row : false );
					}
				} ).catch( function() {
					if ( requestId === linkedReqRef.current ) {
						setLinkedRow( false );
					}
				} );
			}, [ linkedId ] );

			function buildPreviewDoc( renderedData, dark ) {
				var data = renderedData && typeof renderedData === 'object' ? renderedData : {};
				var css = typeof data.css === 'string' ? data.css : ( source.css || '' );
				var html = typeof data.html === 'string' ? data.html : ( source.content || '' );
				var inlineJs = typeof data.jsInline === 'string' ? data.jsInline : ( source.js || '' );
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

				// theme.json global styles carry the preset custom properties and
				// base element styles that the theme's own stylesheets reference.
				// Without them a preview can load every theme sheet and still
				// render nothing like the front end, because every var() misses.
				// Printed after the theme links, which is the order WordPress
				// itself uses.
				var globalCss = typeof config.previewGlobalCss === 'string' ? config.previewGlobalCss : '';
				var revealJs  = typeof config.previewRevealJs === 'string' ? config.previewRevealJs : '';

				return '<!DOCTYPE html><html' + ( dark ? ' data-theme="dark"' : '' ) + '><head><meta charset="utf-8">' +
					'<meta name="viewport" content="width=device-width, initial-scale=1">' +
					themeLinks +
					( globalCss ? '<style id="pb-global-styles">' + globalCss + '</style>' : '' ) +
					'<style>body{margin:0;padding:12px;}*{box-sizing:border-box;}</style>' +
					( css ? '<style>' + css + '</style>' : '' ) +
					'</head><body>' + html +
					( inlineJs ? '<script>' + inlineJs + '<\/script>' : '' ) +
					( footerJs ? '<script>' + footerJs + '<\/script>' : '' ) +
					// Last, so it runs after the block's own scripts. Content
					// that waits for a theme's scroll observer to reveal it
					// would otherwise render as an empty box here, because a
					// preview loads the theme's styles but not its scripts.
					( revealJs ? '<script>' + revealJs + '<\/script>' : '' ) +
					'</body></html>';
			}

			// Rebuild the preview document (debounced) whenever it is visible:
			// full preview mode, or the live pane under the code editor.
			useEffect( function() {
				if ( viewMode !== 'preview' && ! ( viewMode === 'editor' && livePane ) ) {
					return;
				}

				var timer = setTimeout( function() {
					var requestId = previewReqRef.current + 1;
					previewReqRef.current = requestId;

					// A linked block previews through the library's own render
					// route, so PHP, shortcodes, and wpautop run exactly as
					// render_library_block() runs them on the front end.
					if ( isLinked ) {
						if ( linkedPending || linkedMissing || ! apiFetch ) {
							return;
						}

						apiFetch( { path: '/pbb/v1/blocks/' + linkedId + '/render' } )
							.then( function( payload ) {
								if ( requestId !== previewReqRef.current ) {
									return;
								}
								// That route already embeds the CSS (and inline
								// JS) inside `html`; footer JS is only ever
								// returned separately, so re-add just that.
								setPreviewDoc( buildPreviewDoc( {
									html: payload && typeof payload.html === 'string' ? payload.html : '',
									css: '',
									jsInline: 'inline' === source.jsLocation
										? ''
										: ( payload && typeof payload.js === 'string' ? payload.js : '' )
								}, previewDark ) );
							} )
							.catch( function() {
								if ( requestId !== previewReqRef.current ) {
									return;
								}
								setPreviewDoc( buildPreviewDoc( null, previewDark ) );
							} );
						return;
					}

					if ( ! shouldUseServerPreview( source ) ) {
						setPreviewDoc( buildPreviewDoc( null, previewDark ) );
						return;
					}

					requestServerPreviewPayload( source )
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
				}, viewMode === 'preview' ? 0 : 400 );

				return function() { clearTimeout( timer ); };
			}, [ viewMode, livePane, previewDark, linkedId, linkedRow, source.content, source.css, source.js, source.jsLocation, source.format, source.phpExec ] );

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
						// Copy semantics: this placement owns the code from
						// here on, so any existing library link is dropped.
						blockId: 0,
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

			// Point this block at a library row. The inline code is cleared so
			// the block is unambiguously a reference: render_block() ignores
			// those attributes, and unlinking refills them from the library.
			function linkToLibrary( item ) {
				props.setAttributes( {
					blockId: item.id,
					// The slug travels; blockId is a site-local auto-increment.
					// The builder already writes both, and render_block()
					// prefers the slug, so the editor has to agree or the two
					// surfaces produce different references for the same act.
					blockSlug: item.slug || '',
					content: '',
					css: '',
					js: ''
				} );
				setLibOpen( false );
				setMode( 'preview' );
				if ( notices ) {
					notices.createSuccessNotice(
						sprintf(
							/* translators: %s: library block title. */
							__( 'Linked to library block: %s' ),
							item.title || '#' + item.id
						),
						{ type: 'snackbar' }
					);
				}
			}

			// Detach: copy the library row's code into this block's own
			// attributes and drop the reference, so later library edits stop
			// reaching this placement.
			function unlinkBlock() {
				function detach( row ) {
					var copy = libraryRowAsSource( row );
					props.setAttributes( {
						blockId:    0,
						// Must be cleared. Leaving it set means render_block()
						// resolves the library row over the copy the user just
						// took ownership of, and nothing reports it.
						blockSlug:  '',
						content:    copy.content,
						css:        copy.css,
						js:         copy.js,
						jsLocation: copy.jsLocation,
						output:     copy.output,
						format:     copy.format,
						phpExec:    copy.phpExec
					} );
					setMode( 'editor' );
					if ( notices ) {
						notices.createSuccessNotice(
							__( 'Detached a copy. This block no longer follows the library.' ),
							{ type: 'snackbar' }
						);
					}
				}

				if ( ! window.confirm( __( 'Copy the library code into this block? It will stop updating when the library block changes.' ) ) ) {
					return;
				}

				if ( linkedRow && typeof linkedRow === 'object' ) {
					detach( linkedRow );
					return;
				}

				if ( ! apiFetch ) {
					return;
				}

				// The row has not resolved yet (or the first fetch failed):
				// try once more rather than detaching an empty block.
				apiFetch( { path: '/pbb/v1/blocks/' + linkedId } ).then( detach ).catch( function() {
					if ( notices ) {
						notices.createErrorNotice( __( 'Could not load the linked library block.' ), { type: 'snackbar' } );
					}
				} );
			}

			// Drop a link with no code to copy (the target is gone).
			function clearLink() {
				props.setAttributes( { blockId: 0, blockSlug: '' } );
				setMode( 'editor' );
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
							var isCurrent = isLinked && item.id === linkedId;

							// Both actions overwrite what the block renders now,
							// so confirm whenever there is something to lose.
							function confirmReplace() {
								if ( ! hasAnyContent && ! isLinked ) {
									return true;
								}
								return window.confirm( __( 'Replace what this block renders with the library block?' ) );
							}

							return el( 'li', {
								key: item.id,
								className: 'md-page-block-library-item' + ( isCurrent ? ' is-linked' : '' )
							},
								el( 'div', { className: 'md-page-block-library-info' },
									el( 'strong', {}, item.title || '(untitled)' ),
									el( 'span', { className: 'md-page-block-library-meta' },
										el( 'code', {}, item.slug ),
										( item.has_css || item.css ) ? ' · CSS' : '',
										( item.has_js || item.js ) ? ' · JS' : '',
										item.php_exec ? ' · PHP' : '',
										isCurrent ? ' · ' + __( 'linked' ) : ''
									)
								),
								el( 'div', { className: 'md-page-block-library-actions' },
									el( 'button', {
										type: 'button',
										className: 'button button-small',
										title: __( 'Copy this block\u2019s code in. The copy stops following the library.' ),
										onClick: function() {
											if ( ! confirmReplace() ) {
												return;
											}
											insertFromLibrary( item );
										}
									}, __( 'Copy code' ) ),
									el( 'button', {
										type: 'button',
										className: 'button button-small button-primary',
										disabled: isCurrent,
										title: isCurrent
											? __( 'Already linked to this block.' )
											: __( 'Render this library block here. Library edits reach every linked placement.' ),
										onClick: function() {
											if ( ! confirmReplace() ) {
												return;
											}
											linkToLibrary( item );
										}
									}, __( 'Link' ) )
								)
							);
						} )
					)
				);
			}

			function badges() {
				return el( 'span', { className: 'md-page-block-badges' },
					source.content && el( 'span', { className: 'md-page-block-badge' }, 'HTML' ),
					source.css && el( 'span', { className: 'md-page-block-badge' }, 'CSS' ),
					source.js && el( 'span', { className: 'md-page-block-badge' }, 'JS' ),
					( phpDetected || source.phpExec ) && el( 'span', {
						className: 'md-page-block-badge md-page-block-badge--php',
						title: source.phpExec ? __( 'PHP runs on the front end (and in this server-rendered preview).' ) : __( 'Contains PHP tags.' )
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
						isPressed: viewMode === 'preview',
						onClick: function() { setMode( 'preview' ); }
					} ),
					el( ToolbarButton, {
						icon: 'editor-code',
						label: isLinked
							? __( 'Code lives in the library — unlink to edit it here' )
							: __( 'Edit code' ),
						isPressed: viewMode === 'editor',
						disabled: isLinked,
						onClick: function() { setMode( 'editor' ); }
					} ),
					el( ToolbarButton, {
						icon: 'portfolio',
						label: __( 'Browse library' ),
						onClick: openLibrary
					} )
				)
			);

			// While linked, these mirror the library row and are read-only:
			// render_block() takes them from the row, not from the block.
			var settingsHelp = isLinked
				? __( 'Set on the library block. Unlink to control it here.' )
				: undefined;

			var inspector = el( InspectorControls, null,
				isLinked && el( PanelBody, { title: __( 'Library link' ) },
					el( 'p', { className: 'md-page-block-linked-help' },
						linkedMissing
							? sprintf(
								/* translators: %d: library block ID. */
								__( 'Library block #%d is missing, so this block renders nothing.' ),
								linkedId
							)
							: sprintf(
								/* translators: 1: library block title, 2: library block ID. */
								__( 'Rendering \u201c%1$s\u201d (#%2$d) from the Page Blocks library. Every placement of it updates together.' ),
								linkedPending ? __( 'library block' ) : ( linkedRow.title || __( '(untitled)' ) ),
								linkedId
							)
					),
					config.libraryEditUrl && ! linkedMissing && el( 'p', {},
						el( 'a', {
							href: config.libraryEditUrl + linkedId,
							target: '_blank',
							rel: 'noreferrer'
						}, __( 'Edit in library' ) )
					),
					el( 'p', {},
						el( 'button', {
							type: 'button',
							className: 'button button-secondary',
							onClick: linkedMissing ? clearLink : unlinkBlock
						}, linkedMissing ? __( 'Remove link' ) : __( 'Unlink (detach copy)' ) )
					)
				),
				el( PanelBody, { title: __( 'Settings' ) },
					el( SelectControl, {
						label: __( 'JavaScript Location' ),
						value: source.jsLocation,
						disabled: isLinked,
						help: settingsHelp,
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
						checked: !! source.format,
						disabled: isLinked,
						help: settingsHelp,
						onChange: function( val ) {
							props.setAttributes( { format: val } );
						}
					}),
					el( ToggleControl, {
						label: __( 'Execute PHP code' ),
						checked: !! source.phpExec,
						disabled: isLinked,
						help: settingsHelp,
						onChange: function( val ) {
							props.setAttributes( { phpExec: val } );
						}
					}),
					// Only meaningful on a linked block: display conditions live
					// on the library row. Off by default, and deliberately so -
					// turning it on for existing placements would make pages
					// that render today go blank with nothing in any log.
					isLinked && el( ToggleControl, {
						label: __( 'Respect the library block\'s display conditions' ),
						checked: !! attributes.respectConditions,
						help: __( 'Off by default. When on, this placement is hidden wherever the library block\'s conditions do not match.' ),
						onChange: function( val ) {
							props.setAttributes( { respectConditions: val } );
						}
					})
				)
			);

			// Linked mode: the library row is the subject, and there is no
			// inline code to edit here.
			if ( isLinked ) {
				return el( Fragment, null,
					toolbar,
					inspector,
					libraryModal(),
					el( 'div', { className: 'md-page-block-preview-wrap is-linked' },
						el( 'div', { className: 'md-page-block-bar' },
							el( 'span', { className: 'dashicons dashicons-admin-links md-page-block-bar-icon' } ),
							el( 'span', { className: 'md-page-block-bar-title' },
								linkedPending || linkedMissing
									? __( 'Linked Page Block' )
									: ( linkedRow.title || __( '(untitled)' ) )
							),
							el( 'span', { className: 'md-page-block-linked-id' }, '#' + linkedId ),
							! linkedPending && ! linkedMissing && badges(),
							el( 'span', { className: 'md-page-block-bar-spacer' } ),
							! linkedMissing && previewControls(),
							config.libraryEditUrl && ! linkedMissing && el( 'a', {
								className: 'md-page-block-bar-btn',
								href: config.libraryEditUrl + linkedId,
								target: '_blank',
								rel: 'noreferrer',
								title: __( 'Open this block in the Page Blocks library' )
							}, __( 'Edit in library' ) ),
							el( 'button', {
								type: 'button',
								className: 'md-page-block-bar-btn',
								onClick: openLibrary,
								title: __( 'Link this block to a different library block' )
							}, __( 'Change' ) ),
							el( 'button', {
								type: 'button',
								className: 'md-page-block-bar-btn md-page-block-bar-btn--primary',
								onClick: linkedMissing ? clearLink : unlinkBlock,
								title: linkedMissing
									? __( 'Drop the broken link so this block can hold its own code' )
									: __( 'Copy the library code into this block and stop following the library' )
							}, linkedMissing ? __( 'Remove link' ) : __( 'Unlink' ) )
						),
						el( 'p', {
							className: 'md-page-block-linked-note' +
								( linkedMissing || linkedDraft ? ' is-warning' : '' )
						},
							linkedMissing
								? sprintf(
									/* translators: %d: library block ID. */
									__( 'Library block #%d no longer exists, so this renders nothing on the front end. Remove the link to write code here instead.' ),
									linkedId
								)
								: ( linkedDraft
									? __( 'This library block is not published, so it renders nothing on the front end.' )
									: __( 'The code lives in the Page Blocks library, so edits here would be ignored. Edit the library block, or unlink to take a copy.' ) )
						),
						linkedPending
							? el( 'div', { className: 'md-page-block-library-loading' }, el( Spinner ) )
							: ( ! linkedMissing && viewportFrame() )
					)
				);
			}

			// Preview mode
			if ( viewMode === 'preview' ) {
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
								onKeyDown: onTextareaKeyDown( tab.attr ),
								onKeyUp: function( e ) {
									// Caret moves that are not edits (arrows,
									// clicks) still change what should be offered.
									if ( e.key && e.key.indexOf( 'Arrow' ) === 0 ) {
										refreshHint( e.target, tab.attr );
									}
								},
								// mousedown, not blur: blur fires before click
								// and would close the list under the pointer.
								onBlur: function() {
									window.setTimeout( function() { setHint( null ); }, 120 );
								},
								placeholder: tab.label + ' ' + __( 'code here...' ),
								rows: 12,
								spellCheck: false,
								autoComplete: 'off',
								autoCorrect: 'off',
								autoCapitalize: 'off'
							}),

							activeTab === tab.key && hint && hint.items.length
								? el( 'ul', {
									className: 'md-page-block-hints',
									style: { top: hint.top + 'px', left: hint.left + 'px' }
								},
									hint.items.map( function( name, i ) {
										return el( 'li', {
											key: name,
											className: 'md-page-block-hint' + ( i === hint.index ? ' is-active' : '' ),
											onMouseDown: function( ev ) {
												// Keep focus in the textarea.
												ev.preventDefault();
												applyHint( name );
											}
										}, name );
									})
								)
								: null
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

	/**
	 * "Build" — save the page, then open it in the visual builder.
	 *
	 * The builder was only reachable from the front-end admin bar, which
	 * meant leaving the editor, finding the page, and coming back. Saving
	 * first matters: the builder reads post_content from the database, so
	 * anything unsaved in the editor would be silently absent from it.
	 */
	( function registerBuildButton() {
		var settings = window.mdPageBlockEditor || {};
		var plugins  = window.wp && window.wp.plugins;
		var editPost = window.wp && ( window.wp.editor || window.wp.editPost );

		if ( ! settings.builderUrl || ! plugins || ! editPost || ! editPost.PluginPostStatusInfo ) {
			return;
		}

		var Button   = wp.components.Button;
		var useSelect  = wp.data.useSelect;
		var useDispatch = wp.data.useDispatch;

		function BuildButton() {
			var state = useSelect( function( select ) {
				var editor = select( 'core/editor' );
				return {
					saving: editor.isSavingPost() || editor.isAutosavingPost(),
					dirty: editor.isEditedPostDirty(),
					isNew: editor.isEditedPostNew()
				};
			}, [] );
			var savePost = useDispatch( 'core/editor' ).savePost;
			var pending = useRef( false );

			// The save is asynchronous and there is no promise to await from
			// every entry point, so the handoff waits for saving to finish.
			useEffect( function() {
				if ( pending.current && ! state.saving ) {
					pending.current = false;
					window.location.href = settings.builderUrl;
				}
			}, [ state.saving ] );

			return el(
				editPost.PluginPostStatusInfo,
				{ className: 'md-page-block-build-row' },
				el(
					Button,
					{
						variant: 'secondary',
						className: 'md-page-block-build-btn',
						disabled: state.saving || state.isNew,
						icon: 'layout',
						onClick: function() {
							if ( state.dirty ) {
								pending.current = true;
								savePost();
								return;
							}
							window.location.href = settings.builderUrl;
						}
					},
					state.saving && pending.current ? __( 'Saving…' ) : __( 'Build' )
				)
			);
		}

		plugins.registerPlugin( 'gt-page-blocks-build', { render: BuildButton } );
	} )();
})( wp );
