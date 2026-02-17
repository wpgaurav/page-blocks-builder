( function( wp ) {
	var registerBlockType = wp.blocks.registerBlockType,
		el                = wp.element.createElement,
		useState          = wp.element.useState,
		useRef            = wp.element.useRef,
		useEffect         = wp.element.useEffect,
		__                = wp.i18n.__,
		InspectorControls = wp.blockEditor.InspectorControls,
		PanelBody         = wp.components.PanelBody,
		SelectControl     = wp.components.SelectControl,
		ToggleControl     = wp.components.ToggleControl,
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

	registerBlockType( 'marketers-delight/page-block', {
		title: __( 'Page Block' ),
		description: __( 'Custom HTML, CSS, and JavaScript code block.' ),
		icon: 'editor-code',
		category: 'marketers-delight',

		attributes: {
			content:    { type: 'string', default: '' },
			css:        { type: 'string', default: '' },
			js:         { type: 'string', default: '' },
			jsLocation: { type: 'string', default: 'footer' },
			format:     { type: 'boolean', default: false },
			phpExec:    { type: 'boolean', default: false }
		},

		edit: function( props ) {
			var attributes = props.attributes;
			var activeTabState = useState( 'html' );
			var activeTab = activeTabState[0];
			var setActiveTab = activeTabState[1];

			var modeState = useState( 'editor' );
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

			var tabs = [
				{ key: 'html', label: __( 'HTML' ), attr: 'content' },
				{ key: 'css',  label: __( 'CSS' ),  attr: 'css' },
				{ key: 'js',   label: __( 'JS' ),   attr: 'js' }
			];

			var hasAnyContent = !! ( attributes.content || attributes.css || attributes.js );

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

			function buildPreviewDoc( renderedData ) {
				var data = renderedData && typeof renderedData === 'object' ? renderedData : {};
				var css = typeof data.css === 'string' ? data.css : ( attributes.css || '' );
				var html = typeof data.html === 'string' ? data.html : ( attributes.content || '' );
				var inlineJs = typeof data.jsInline === 'string' ? data.jsInline : ( attributes.js || '' );
				var footerJs = typeof data.jsFooter === 'string' ? data.jsFooter : '';

				return '<!DOCTYPE html><html><head><meta charset="utf-8">' +
					'<style>body{margin:0;padding:12px;font-family:"System Sans",system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;font-size:14px;line-height:1.6;color:#1e1e1e;}*{box-sizing:border-box;}</style>' +
					( css ? '<style>' + css + '</style>' : '' ) +
					'</head><body>' + html +
					( inlineJs ? '<script>' + inlineJs + '<\/script>' : '' ) +
					( footerJs ? '<script>' + footerJs + '<\/script>' : '' ) +
					'</body></html>';
			}

			useEffect( function() {
				if ( mode !== 'preview' ) {
					return;
				}

				var requestId = previewReqRef.current + 1;
				previewReqRef.current = requestId;

				if ( ! shouldUseServerPreview( attributes ) ) {
					setPreviewDoc( buildPreviewDoc() );
					return;
				}

				requestServerPreviewPayload( attributes )
					.then( function( payload ) {
						if ( requestId !== previewReqRef.current ) {
							return;
						}
						setPreviewDoc( buildPreviewDoc( payload ) );
					} )
					.catch( function() {
						if ( requestId !== previewReqRef.current ) {
							return;
						}
						setPreviewDoc( buildPreviewDoc() );
					} );
			}, [ mode, attributes.content, attributes.css, attributes.js, attributes.jsLocation, attributes.format, attributes.phpExec ] );

			// Preview mode
			if ( mode === 'preview' ) {
				return el( Fragment, null,
					el( InspectorControls, null,
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
					),
					el( 'div', { className: 'md-page-block-preview-wrap' },
						el( 'iframe', {
							className: 'md-page-block-preview-iframe',
							title: __( 'Page Block Preview' ),
							srcDoc: previewDoc || buildPreviewDoc(),
							sandbox: 'allow-scripts'
						}),
						el( 'button', {
							type: 'button',
							className: 'md-page-block-edit-btn',
							onClick: function() { setMode( 'editor' ); },
							title: __( 'Edit code' )
						},
							el( 'span', { className: 'dashicons dashicons-edit' } )
						)
					)
				);
			}

			// Editor mode
			return el( Fragment, null,
				el( InspectorControls, null,
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
				),

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
					})
				)
			);
		},

		save: function() {
			return null;
		}
	});
})( wp );
