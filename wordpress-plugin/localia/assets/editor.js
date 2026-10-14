/*
 * Localia "Generate with my AI" box.
 * Block editor: a panel in the Post sidebar. Classic editor: a meta box.
 * Both use the same vanilla-JS UI, mounted into a container element.
 */
(function () {
	'use strict';

	if ( ! window.wp || ! wp.apiFetch ) {
		return;
	}
	var cfg = window.LocaliaEditor || {};
	var t = cfg.i18n || {};

	// Survives the block-editor panel being collapsed and re-opened.
	var state = { prompt: '', useContext: true, status: '', kind: '', html: '', busy: false };
	var timer = null;
	var views = [];

	var isBlockEditor = function () {
		return document.body.classList.contains( 'block-editor-page' ) && wp.data && wp.blocks;
	};

	function postId() {
		if ( isBlockEditor() ) {
			return wp.data.select( 'core/editor' ).getCurrentPostId() || 0;
		}
		var el = document.getElementById( 'post_ID' );
		return el ? parseInt( el.value, 10 ) || 0 : 0;
	}

	function currentPost() {
		if ( isBlockEditor() ) {
			var ed = wp.data.select( 'core/editor' );
			return { title: ed.getEditedPostAttribute( 'title' ) || '', content: ed.getEditedPostContent() || '' };
		}
		var title = document.getElementById( 'title' );
		var mce = window.tinymce && tinymce.get( 'content' );
		var content = mce && ! mce.isHidden() ? mce.getContent() : ( document.getElementById( 'content' ) || {} ).value || '';
		return { title: title ? title.value : '', content: content };
	}

	function insert( html, replace ) {
		if ( isBlockEditor() ) {
			var blocks = wp.blocks.rawHandler( { HTML: html } );
			if ( replace ) {
				wp.data.dispatch( 'core/block-editor' ).resetBlocks( blocks );
			} else {
				wp.data.dispatch( 'core/block-editor' ).insertBlocks( blocks );
			}
			return;
		}
		var mce = window.tinymce && tinymce.get( 'content' );
		if ( mce && ! mce.isHidden() ) {
			mce.setContent( replace ? html : mce.getContent() + html );
			return;
		}
		var ta = document.getElementById( 'content' );
		if ( ta ) {
			ta.value = replace ? html : ta.value + '\n\n' + html;
		}
	}

	function setState( patch ) {
		Object.keys( patch ).forEach( function ( k ) { state[ k ] = patch[ k ]; } );
		views = views.filter( function ( v ) { return document.body.contains( v.root ); } );
		views.forEach( render );
	}

	function render( v ) {
		v.status.textContent = state.status;
		v.status.className = 'localia-status' + ( state.kind ? ' is-' + state.kind : '' );
		v.button.disabled = state.busy;
		v.result.hidden = ! state.html;
		if ( v.preview.getAttribute( 'data-html' ) !== state.html ) {
			v.preview.innerHTML = state.html; // sanitized server-side with wp_kses_post
			v.preview.setAttribute( 'data-html', state.html );
		}
	}

	function fail( err ) {
		setState( { status: ( err && err.message ) || String( err ), kind: 'error', busy: false } );
	}

	function poll( id ) {
		wp.apiFetch( { path: '/localia/v1/editor/job/' + id } ).then( function ( res ) {
			if ( res.status === 'done' ) {
				setState( { html: res.html, status: t.done, kind: 'ok', busy: false } );
			} else if ( res.status === 'error' || res.status === 'cancelled' ) {
				setState( { status: t.failed + ' ' + ( res.error || res.status ), kind: 'error', busy: false } );
			} else {
				if ( res.status === 'leased' ) {
					setState( { status: t.working, kind: 'busy' } );
				} else {
					setState( { status: res.agent_online ? t.queued : t.offline, kind: res.agent_online ? 'busy' : 'warn' } );
				}
				timer = setTimeout( function () { poll( id ); }, 3000 );
			}
		} ).catch( fail );
	}

	function generate() {
		var prompt = state.prompt.trim();
		if ( ! prompt ) {
			setState( { status: t.empty, kind: 'warn' } );
			return;
		}
		clearTimeout( timer );
		var data = { prompt: prompt, post_id: postId() };
		if ( state.useContext ) {
			var p = currentPost();
			data.context_title = p.title;
			data.context_content = p.content;
		}
		setState( { html: '', status: t.queued, kind: 'busy', busy: true } );
		wp.apiFetch( { path: '/localia/v1/editor/generate', method: 'POST', data: data } ).then( function ( res ) {
			if ( ! res.agent_online ) {
				setState( { status: t.offline, kind: 'warn' } );
			}
			timer = setTimeout( function () { poll( res.id ); }, 2000 );
		} ).catch( fail );
	}

	function el( tag, attrs, text ) {
		var e = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) { e.setAttribute( k, attrs[ k ] ); } );
		if ( text ) {
			e.textContent = text;
		}
		return e;
	}

	function mount( root ) {
		if ( root.getAttribute( 'data-mounted' ) ) {
			return;
		}
		root.setAttribute( 'data-mounted', '1' );
		root.classList.add( 'localia-editor' );

		if ( ! cfg.connected ) {
			var note = el( 'p', { 'class': 'localia-note' }, t.notConnected + ' ' );
			if ( cfg.settingsUrl ) {
				note.appendChild( el( 'a', { href: cfg.settingsUrl }, t.setUp ) );
			}
			root.appendChild( note );
		}

		var textarea = el( 'textarea', { rows: '4', 'class': 'widefat localia-prompt', placeholder: t.placeholder, 'aria-label': t.label } );
		textarea.value = state.prompt;
		textarea.addEventListener( 'input', function () { state.prompt = textarea.value; } );
		root.appendChild( textarea );

		var label = el( 'label', { 'class': 'localia-context' } );
		var check = el( 'input', { type: 'checkbox' } );
		check.checked = state.useContext;
		check.addEventListener( 'change', function () { state.useContext = check.checked; } );
		label.appendChild( check );
		label.appendChild( document.createTextNode( ' ' + t.useContext ) );
		root.appendChild( label );

		var button = el( 'button', { type: 'button', 'class': 'button button-primary localia-generate' }, t.generate );
		button.addEventListener( 'click', generate );
		root.appendChild( el( 'p' ) ).appendChild( button );

		var status = el( 'p', { 'class': 'localia-status', 'aria-live': 'polite' } );
		root.appendChild( status );

		var result = el( 'div', { 'class': 'localia-result' } );
		var preview = el( 'div', { 'class': 'localia-preview' } );
		var actions = el( 'p', { 'class': 'localia-result-actions' } );
		var bInsert = el( 'button', { type: 'button', 'class': 'button' }, t.insert );
		var bReplace = el( 'button', { type: 'button', 'class': 'button' }, t.replace );
		var bCopy = el( 'button', { type: 'button', 'class': 'button-link' }, t.copy );
		bInsert.addEventListener( 'click', function () { insert( state.html, false ); } );
		bReplace.addEventListener( 'click', function () {
			if ( window.confirm( t.confirmRepl ) ) {
				insert( state.html, true );
			}
		} );
		bCopy.addEventListener( 'click', function () {
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( preview.innerText ).then( function () { setState( { status: t.copied, kind: 'ok' } ); } );
			}
		} );
		actions.appendChild( bInsert );
		actions.appendChild( bReplace );
		actions.appendChild( bCopy );
		result.appendChild( preview );
		result.appendChild( actions );
		root.appendChild( result );

		var view = { root: root, status: status, button: button, result: result, preview: preview };
		views.push( view );
		render( view );
	}

	// Classic editor meta box.
	document.querySelectorAll( '.localia-editor-root' ).forEach( mount );

	// Block editor: a panel in the Post sidebar.
	if ( wp.plugins && wp.element ) {
		var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
		if ( Panel ) {
			// Open the panel once for new users; after that WordPress remembers their choice.
			var openOnce = function () {
				try {
					if ( window.localStorage.getItem( 'localiaPanelShown' ) ) {
						return;
					}
					window.localStorage.setItem( 'localiaPanelShown', '1' );
					if ( ! wp.data.select( 'core/editor' ).isEditorPanelOpened( 'localia-ai/localia-ai' ) ) {
						wp.data.dispatch( 'core/editor' ).toggleEditorPanelOpened( 'localia-ai/localia-ai' );
					}
				} catch ( e ) {}
			};
			// Mount whenever the panel body appears (it is removed while the panel is collapsed).
			var mountRef = function ( node ) {
				if ( node ) {
					mount( node );
				}
			};
			wp.plugins.registerPlugin( 'localia-ai', {
				icon: 'desktop',
				render: function () {
					wp.element.useEffect( openOnce, [] );
					return wp.element.createElement(
						Panel,
						{ name: 'localia-ai', title: t.panelTitle, className: 'localia-doc-panel' },
						wp.element.createElement( 'div', { ref: mountRef } )
					);
				},
			} );
		}
	}
})();
