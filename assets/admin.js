/**
 * Florank settings page: copy buttons and in-page toggles ("Revoke key",
 * "Change" user). Without JavaScript the toggles fall back to plain links.
 */
( function () {
	'use strict';

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			try {
				document.execCommand( 'copy' );
				resolve();
			} catch ( e ) {
				reject( e );
			}
			document.body.removeChild( area );
		} );
	}

	function onCopy( button ) {
		var source = document.getElementById( button.getAttribute( 'data-florank-copy' ) );
		if ( ! source ) {
			return;
		}
		copyText( source.textContent.trim() ).then( function () {
			var copied = button.getAttribute( 'data-florank-copied' );
			button.classList.add( 'is-copied' );
			if ( ! button.classList.contains( 'florank-icon-btn' ) && copied ) {
				if ( ! button.hasAttribute( 'data-florank-label' ) ) {
					button.setAttribute( 'data-florank-label', button.textContent );
				}
				button.textContent = copied;
			}
			window.setTimeout( function () {
				button.classList.remove( 'is-copied' );
				if ( button.hasAttribute( 'data-florank-label' ) ) {
					button.textContent = button.getAttribute( 'data-florank-label' );
				}
			}, 1800 );
		} );
	}

	function onToggle( link ) {
		var show = document.getElementById( link.getAttribute( 'data-florank-open' ) );
		var hide = document.getElementById( link.getAttribute( 'data-florank-hide' ) );
		if ( ! show || ! hide ) {
			return false;
		}
		hide.hidden = true;
		show.hidden = false;
		var focusable = show.querySelector( 'select, button, a' );
		if ( focusable ) {
			focusable.focus();
		}
		return true;
	}

	document.addEventListener( 'click', function ( event ) {
		var root = event.target.closest( '.florank-admin' );
		if ( ! root ) {
			return;
		}
		var copy = event.target.closest( '[data-florank-copy]' );
		if ( copy ) {
			event.preventDefault();
			onCopy( copy );
			return;
		}
		var toggle = event.target.closest( '[data-florank-open]' );
		if ( toggle && onToggle( toggle ) ) {
			event.preventDefault();
		}
	} );
}() );
