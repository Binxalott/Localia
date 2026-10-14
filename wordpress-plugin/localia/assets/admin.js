/* Localia admin pages. */
(function () {
	'use strict';

	var t = ( window.LocaliaAdmin && LocaliaAdmin.i18n ) || {};

	// Task form: show only the section for the chosen type, and disable the other one's
	// fields so they aren't submitted.
	var form = document.getElementById( 'localia-task-form' );
	if ( form ) {
		var syncType = function () {
			var checked = form.querySelector( 'input[name="task[type]"]:checked' );
			var type = checked ? checked.value : 'create_post';
			form.querySelectorAll( '.localia-type-section' ).forEach( function ( section ) {
				var active = section.getAttribute( 'data-type' ) === type;
				section.hidden = ! active;
				section.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
					el.disabled = ! active;
				} );
			} );
		};
		form.addEventListener( 'change', function ( e ) {
			if ( e.target.name === 'task[type]' ) {
				syncType();
			}
		} );
		syncType();

		// Times of day.
		var times = document.getElementById( 'localia-times' );
		document.getElementById( 'localia-add-time' ).addEventListener( 'click', function () {
			var span = document.createElement( 'span' );
			span.className = 'localia-time';
			span.innerHTML = '<input type="time" name="task[times][]"> <button type="button" class="button-link localia-remove-time"></button>';
			span.querySelector( 'button' ).textContent = t.removeTime || 'Remove';
			times.appendChild( span );
			span.querySelector( 'input' ).focus();
		} );
		times.addEventListener( 'click', function ( e ) {
			if ( e.target.classList.contains( 'localia-remove-time' ) ) {
				e.target.parentNode.remove();
			}
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest ? e.target.closest( '.localia-copy, .localia-confirm-delete, .localia-confirm-regenerate' ) : null;
		if ( ! el ) {
			return;
		}
		if ( el.classList.contains( 'localia-copy' ) ) {
			var target = document.getElementById( el.getAttribute( 'data-target' ) );
			target.select();
			( navigator.clipboard ? navigator.clipboard.writeText( target.value ) : Promise.resolve( document.execCommand( 'copy' ) ) ).then( function () {
				var old = el.textContent;
				el.textContent = t.copied || 'Copied!';
				setTimeout( function () { el.textContent = old; }, 1500 );
			} );
		} else if ( el.classList.contains( 'localia-confirm-delete' ) && ! window.confirm( t.confirmDelete ) ) {
			e.preventDefault();
		} else if ( el.classList.contains( 'localia-confirm-regenerate' ) && ! window.confirm( t.confirmRegenerate ) ) {
			e.preventDefault();
		}
	} );
})();
