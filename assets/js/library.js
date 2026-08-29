/**
 * Page Blocks — Library admin panel.
 *
 * REST-driven card grid: search, status filters, live preview thumbnails,
 * duplicate / trash / restore / delete, copy shortcode.
 *
 * @since 2.5.0
 */
( function() {
	'use strict';

	var config = window.gtPbLibrary || {};
	var root = document.getElementById( 'gt-pb-library' );
	if ( ! root || ! config.restUrl ) {
		return;
	}

	var state = {
		items: [],
		total: 0,
		totalPages: 1,
		counts: { all: 0, publish: 0, draft: 0, trash: 0 },
		page: 1,
		search: '',
		status: '',
		loading: false
	};

	var searchTimer = null;

	/* ── REST helpers ── */

	function request( path, options ) {
		options = options || {};
		options.credentials = 'same-origin';
		options.headers = options.headers || {};
		options.headers['X-WP-Nonce'] = config.restNonce || '';
		if ( options.body && ! options.headers['Content-Type'] ) {
			options.headers['Content-Type'] = 'application/json';
		}
		return window.fetch( config.restUrl + path, options ).then( function( response ) {
			return response.json().then( function( data ) {
				if ( ! response.ok ) {
					var msg = data && data.message ? data.message : 'Request failed.';
					throw new Error( msg );
				}
				return { data: data, headers: response.headers };
			} );
		} );
	}

	function loadBlocks() {
		state.loading = true;
		renderToolbarState();

		var params = new window.URLSearchParams();
		params.set( 'per_page', '24' );
		params.set( 'page', String( state.page ) );
		if ( state.search ) params.set( 'search', state.search );
		if ( state.status ) params.set( 'status', state.status );

		request( '/blocks?' + params.toString(), { method: 'GET' } ).then( function( result ) {
			var items = Array.isArray( result.data ) ? result.data : [];
			state.items = state.page === 1 ? items : state.items.concat( items );
			state.total = parseInt( result.headers.get( 'X-WP-Total' ) || '0', 10 );
			state.totalPages = parseInt( result.headers.get( 'X-WP-TotalPages' ) || '1', 10 );
			state.counts.publish = parseInt( result.headers.get( 'X-PBB-Published' ) || '0', 10 );
			state.counts.draft = parseInt( result.headers.get( 'X-PBB-Drafts' ) || '0', 10 );
			state.counts.trash = parseInt( result.headers.get( 'X-PBB-Trash' ) || '0', 10 );
			state.counts.all = state.counts.publish + state.counts.draft;
			state.loading = false;
			renderAll();
		} ).catch( function( err ) {
			state.loading = false;
			notify( err.message || 'Could not load the library.', true );
			renderAll();
		} );
	}

	/* ── Toast feedback ── */

	function notify( message, isError ) {
		var toast = document.createElement( 'div' );
		toast.className = 'gt-pb-toast' + ( isError ? ' is-error' : '' );
		toast.textContent = message;
		document.body.appendChild( toast );
		window.setTimeout( function() { toast.classList.add( 'is-visible' ); }, 10 );
		window.setTimeout( function() {
			toast.classList.remove( 'is-visible' );
			window.setTimeout( function() { toast.remove(); }, 300 );
		}, 3200 );
	}

	function copyText( text, doneMessage ) {
		function done() { notify( doneMessage ); }
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, done );
		} else {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			try { document.execCommand( 'copy' ); } catch ( e ) {}
			ta.remove();
			done();
		}
	}

	/* ── Preview thumbnails ── */

	// Preview documents are held here, keyed by block id.
	//
	// They used to travel in a data-doc attribute, escaped with a helper that
	// encodes &, < and > but not quotes — so every document was cut off at the
	// first one, which is in <meta charset="utf-8">. Every thumbnail in the
	// library was an empty frame for that reason.
	var previewDocs = {};

	/** What a block can actually show in a thumbnail. */
	function previewKind( item ) {
		var html = String( item.content || '' ).replace( /<\?(?:php|=)?[\s\S]*?(?:\?>|$)/g, '' );

		if ( html.trim() ) {
			return 'html';
		}
		if ( item.php_exec && String( item.content || '' ).trim() ) {
			return 'php';
		}
		if ( String( item.css || '' ).trim() ) {
			return 'css';
		}
		if ( String( item.js || '' ).trim() ) {
			return 'js';
		}

		return 'empty';
	}

	function buildPreviewDoc( item ) {
		var themeLinks = '';
		var styles = config.previewStyles || [];
		for ( var i = 0; i < styles.length; i++ ) {
			themeLinks += '<link rel="stylesheet" href="' + styles[ i ] + '">';
		}
		var html = ( item.content || '' ).replace( /<\?(?:php|=)?[\s\S]*?(?:\?>|$)/g, '' );
		return '<!DOCTYPE html><html><head><meta charset="utf-8">' +
			'<meta name="viewport" content="width=device-width, initial-scale=1">' + themeLinks +
			'<style>html{overflow:hidden}body{margin:0;padding:10px;background:#fff;}*{box-sizing:border-box}</style>' +
			'<style>' + ( item.css || '' ) + '</style>' +
			'</head><body>' + html + '</body></html>';
	}

	// Lazy-load card thumbnails as they scroll into view.
	var observer = ( 'IntersectionObserver' in window ) ? new window.IntersectionObserver( function( entries ) {
		entries.forEach( function( entry ) {
			if ( ! entry.isIntersecting ) return;
			var holder = entry.target;
			observer.unobserve( holder );
			mountPreview( holder );
		} );
	}, { rootMargin: '200px' } ) : null;

	/**
	 * Put a block's rendered thumbnail into its card.
	 *
	 * The frame renders at a desktop width and is scaled down, so the preview
	 * reads as a small page rather than a page squeezed into 300px — a layout
	 * built for 1100px would otherwise show its mobile breakpoint here.
	 */
	function mountPreview( holder ) {
		var id = holder.getAttribute( 'data-preview' );
		var doc = id ? previewDocs[ id ] : '';

		if ( ! doc || holder.querySelector( 'iframe' ) ) {
			return;
		}

		var frame = document.createElement( 'iframe' );
		frame.className = 'gt-pb-card-frame';
		frame.setAttribute( 'tabindex', '-1' );
		frame.setAttribute( 'loading', 'lazy' );
		frame.setAttribute( 'sandbox', 'allow-same-origin' );
		frame.setAttribute( 'aria-hidden', 'true' );
		frame.srcdoc = doc;
		holder.appendChild( frame );
		scalePreview( holder );
	}

	var PREVIEW_WIDTH = 1200;

	function scalePreview( holder ) {
		var width = holder.clientWidth;
		if ( width ) {
			holder.style.setProperty( '--gt-pb-thumb-scale', ( width / PREVIEW_WIDTH ).toFixed( 4 ) );
		}
	}

	function scaleAllPreviews() {
		root.querySelectorAll( '.gt-pb-card-thumb' ).forEach( scalePreview );
	}

	window.addEventListener( 'resize', scaleAllPreviews );

	/* ── Rendering ── */

	function esc( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str == null ? '' : String( str );
		return div.innerHTML;
	}

	function timeAgo( mysqlDate ) {
		if ( ! mysqlDate ) return '';
		var then = new Date( mysqlDate.replace( ' ', 'T' ) );
		if ( isNaN( then.getTime() ) ) return mysqlDate;
		var mins = Math.floor( ( Date.now() - then.getTime() ) / 60000 );
		if ( mins < 1 ) return config.i18n.justNow;
		if ( mins < 60 ) return mins + 'm';
		var hours = Math.floor( mins / 60 );
		if ( hours < 24 ) return hours + 'h';
		var days = Math.floor( hours / 24 );
		if ( days < 30 ) return days + 'd';
		return then.toLocaleDateString();
	}

	function renderToolbarState() {
		var btn = root.querySelector( '.gt-pb-load-more' );
		if ( btn ) {
			btn.disabled = state.loading;
			btn.textContent = state.loading ? config.i18n.loading : config.i18n.loadMore;
		}
	}

	function renderTabs() {
		var tabs = [
			{ key: '', label: config.i18n.all, count: state.counts.all },
			{ key: 'publish', label: config.i18n.published, count: state.counts.publish },
			{ key: 'draft', label: config.i18n.drafts, count: state.counts.draft },
			{ key: 'trash', label: config.i18n.trash, count: state.counts.trash }
		];
		// ul.subsubsub is the status filter every WordPress list screen uses.
		// A segmented pill control here was the one piece of this page that
		// looked like it came from another product.
		return '<ul class="subsubsub">' + tabs.map( function( tab, i ) {
			var current = state.status === tab.key;
			return '<li>' +
				'<button type="button" class="button-link gt-pb-filter' + ( current ? ' current' : '' ) + '"' +
					' data-status="' + tab.key + '"' + ( current ? ' aria-current="page"' : '' ) + '>' +
					esc( tab.label ) + ' <span class="count">(' + tab.count + ')</span>' +
				'</button>' +
				( i < tabs.length - 1 ? ' |' : '' ) +
			'</li>';
		} ).join( ' ' ) + '</ul>';
	}

	function renderCard( item ) {
		var badges = '';
		if ( item.content ) badges += '<span class="gt-pb-chip">HTML</span>';
		if ( item.css ) badges += '<span class="gt-pb-chip">CSS</span>';
		if ( item.js ) badges += '<span class="gt-pb-chip">JS</span>';
		if ( item.php_exec ) badges += '<span class="gt-pb-chip gt-pb-chip--php">PHP</span>';
		if ( item.status === 'draft' ) badges += '<span class="gt-pb-chip gt-pb-chip--draft">' + esc( config.i18n.draft ) + '</span>';
		if ( item.position ) {
			var posLabel = item.position.indexOf( 'region:' ) === 0 ? item.position.replace( 'region:', '' ) : item.position;
			badges += '<span class="gt-pb-chip gt-pb-chip--position" title="' + esc( item.position ) + '">&#x1F4CD; ' + esc( posLabel ) + '</span>';
		}

		// Row actions, the way every WordPress list screen writes them:
		// quiet links separated by pipes, destructive one last and red.
		var actions;
		if ( item.status === 'trash' ) {
			actions =
				'<span><button type="button" class="button-link" data-action="restore">' + esc( config.i18n.restore ) + '</button></span>' +
				'<span class="delete"><button type="button" class="button-link" data-action="delete">' + esc( config.i18n.deleteForever ) + '</button></span>';
		} else {
			actions =
				'<span><a href="' + esc( config.editUrl + item.id ) + '">' + esc( config.i18n.edit ) + '</a></span>' +
				'<span><button type="button" class="button-link" data-action="duplicate">' + esc( config.i18n.duplicate ) + '</button></span>' +
				'<span><button type="button" class="button-link" data-action="shortcode" title="[page_block id=&quot;' + item.id + '&quot;]">' + esc( config.i18n.shortcode ) + '</button></span>' +
				'<span class="trash"><button type="button" class="button-link" data-action="trash">' + esc( config.i18n.toTrash ) + '</button></span>';
		}

		var kind = previewKind( item );
		var thumb;

		if ( 'html' === kind ) {
			previewDocs[ item.id ] = buildPreviewDoc( item );
			thumb = '<div class="gt-pb-card-thumb" data-preview="' + item.id + '"></div>';
		} else {
			// A block with no markup has nothing to render. Say which kind of
			// block it is rather than showing an empty white rectangle and
			// letting it read as a broken preview.
			var fallbacks = {
				css:   { icon: 'admin-appearance', label: config.i18n.previewCssOnly },
				js:    { icon: 'editor-code',      label: config.i18n.previewJsOnly },
				php:   { icon: 'editor-code',      label: config.i18n.previewPhpOnly },
				empty: { icon: 'media-default',    label: config.i18n.previewEmpty }
			};
			var fb = fallbacks[ kind ] || fallbacks.empty;
			thumb =
				'<div class="gt-pb-card-thumb is-fallback">' +
					'<span class="dashicons dashicons-' + fb.icon + '" aria-hidden="true"></span>' +
					'<span class="gt-pb-thumb-note">' + esc( fb.label ) + '</span>' +
				'</div>';
		}

		return '<article class="gt-pb-card" data-id="' + item.id + '">' +
			'<a class="gt-pb-card-preview" href="' + esc( config.editUrl + item.id ) + '" aria-label="' + esc( item.title ) + '">' +
				thumb +
			'</a>' +
			'<div class="gt-pb-card-body">' +
				'<div class="gt-pb-card-head">' +
					'<h3 class="gt-pb-card-title"><a href="' + esc( config.editUrl + item.id ) + '">' + ( esc( item.title ) || '(untitled)' ) + '</a></h3>' +
					'<span class="gt-pb-card-time" title="' + esc( item.updated_at ) + '">' + esc( timeAgo( item.updated_at ) ) + '</span>' +
				'</div>' +
				'<div class="gt-pb-card-meta"><code>' + esc( item.slug ) + '</code>' + badges + '</div>' +
				'<div class="gt-pb-card-actions">' + actions + '</div>' +
			'</div>' +
		'</article>';
	}

	function renderAll() {
		var html =
			'<div class="gt-pb-lib-toolbar">' +
				renderTabs() +
				'<p class="search-box">' +
					'<label class="screen-reader-text" for="gt-pb-search-input">' + esc( config.i18n.searchLabel ) + '</label>' +
					'<input type="search" id="gt-pb-search-input" class="gt-pb-search" ' +
						'placeholder="' + esc( config.i18n.searchPlaceholder ) + '" value="' + esc( state.search ) + '">' +
				'</p>' +
			'</div>';

		if ( state.loading && ! state.items.length ) {
			html += '<div class="gt-pb-lib-empty"><span class="spinner is-active"></span></div>';
		} else if ( ! state.items.length ) {
			html += '<div class="gt-pb-lib-empty">' +
				'<span class="dashicons dashicons-layout"></span>' +
				'<h2>' + esc( state.search || state.status ? config.i18n.emptyFiltered : config.i18n.emptyLibrary ) + '</h2>' +
				( state.search || state.status
					? '<p>' + esc( config.i18n.emptyFilteredHint ) + '</p>'
					: '<p>' + esc( config.i18n.emptyLibraryHint ) + '</p><a class="button button-primary button-hero" href="' + esc( config.newUrl ) + '">' + esc( config.i18n.createFirst ) + '</a>' ) +
			'</div>';
		} else {
			html += '<div class="gt-pb-grid">' + state.items.map( renderCard ).join( '' ) + '</div>';
			if ( state.page < state.totalPages ) {
				html += '<div class="gt-pb-more-wrap"><button type="button" class="button gt-pb-load-more">' + esc( config.i18n.loadMore ) + '</button></div>';
			}
		}

		root.innerHTML = html;

		scaleAllPreviews();

		// Observe thumbnails for lazy preview loading.
		if ( observer ) {
			root.querySelectorAll( '.gt-pb-card-thumb[data-preview]' ).forEach( function( holder ) {
				observer.observe( holder );
			} );
		} else {
			root.querySelectorAll( '.gt-pb-card-thumb[data-preview]' ).forEach( mountPreview );
		}

		bindEvents();
	}

	/* ── Actions ── */

	function itemAction( id, action ) {
		var paths = {
			duplicate: { path: '/blocks/' + id + '/duplicate', options: { method: 'POST' }, message: config.i18n.duplicated },
			trash:     { path: '/blocks/' + id, options: { method: 'DELETE' }, message: config.i18n.trashed },
			restore:   { path: '/blocks/' + id, options: { method: 'PUT', body: JSON.stringify( { status: 'draft' } ) }, message: config.i18n.restored },
			'delete':  { path: '/blocks/' + id + '?force=true', options: { method: 'DELETE' }, message: config.i18n.deleted }
		};
		var spec = paths[ action ];
		if ( ! spec ) return;

		if ( action === 'delete' && ! window.confirm( config.i18n.deleteConfirm ) ) {
			return;
		}

		request( spec.path, spec.options ).then( function() {
			notify( spec.message );
			state.page = 1;
			loadBlocks();
		} ).catch( function( err ) {
			notify( err.message, true );
		} );
	}

	function bindEvents() {
		root.querySelectorAll( '.gt-pb-filter' ).forEach( function( btn ) {
			btn.addEventListener( 'click', function() {
				state.status = btn.getAttribute( 'data-status' ) || '';
				state.page = 1;
				loadBlocks();
			} );
		} );

		var search = root.querySelector( '.gt-pb-search' );
		if ( search ) {
			search.addEventListener( 'input', function() {
				window.clearTimeout( searchTimer );
				searchTimer = window.setTimeout( function() {
					state.search = search.value.trim();
					state.page = 1;
					loadBlocks();
				}, 300 );
			} );
			// Keep focus while re-rendering replaces the input.
			search.addEventListener( 'keydown', function( e ) {
				if ( e.key === 'Enter' ) e.preventDefault();
			} );
		}

		var more = root.querySelector( '.gt-pb-load-more' );
		if ( more ) {
			more.addEventListener( 'click', function() {
				state.page += 1;
				loadBlocks();
			} );
		}

		root.querySelectorAll( '.gt-pb-card [data-action]' ).forEach( function( btn ) {
			btn.addEventListener( 'click', function() {
				var card = btn.closest( '.gt-pb-card' );
				var id = card ? parseInt( card.getAttribute( 'data-id' ), 10 ) : 0;
				var action = btn.getAttribute( 'data-action' );
				if ( ! id ) return;
				if ( action === 'shortcode' ) {
					copyText( '[page_block id="' + id + '"]', config.i18n.shortcodeCopied );
					return;
				}
				itemAction( id, action );
			} );
		} );
	}

	loadBlocks();
} )();
