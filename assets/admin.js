/* global ttrL10n */
( function () {
	'use strict';

	var L10N = window.ttrL10n || {};
	var REDIRECT_CODES = ( L10N.redirectCodes || [ 301, 302, 307, 308 ] ).map( Number );

	/**
	 * Show or hide the inline edit row belonging to a rule.
	 */
	function toggleEditRow( id, show ) {
		var row = document.getElementById( 'ttr-edit-' + id );
		var trigger = document.querySelector( '.ttr-edit-toggle[data-rule="' + id + '"]' );

		if ( ! row ) {
			return;
		}

		row.hidden = ! show;

		if ( trigger ) {
			trigger.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
		}

		if ( show ) {
			var field = row.querySelector( 'input[type="text"]' );
			if ( field ) {
				field.focus();
			}
		}
	}

	/**
	 * Dim the destination field when the chosen status code does not use one.
	 */
	function syncTargetField( select ) {
		var scope = select.closest( '.ttr-edit-grid' ) || select.closest( 'form' );

		if ( ! scope ) {
			return;
		}

		var target = scope.querySelector( 'input[name$="[target]"]' );

		if ( ! target ) {
			return;
		}

		var wrapper = target.closest( '.ttr-field' ) || target.parentNode;
		var needed = REDIRECT_CODES.indexOf( Number( select.value ) ) !== -1;

		wrapper.classList.toggle( 'ttr-field-dim', ! needed );
		target.required = needed;
	}

	document.addEventListener( 'click', function ( event ) {
		var editToggle = event.target.closest( '.ttr-edit-toggle' );

		if ( editToggle ) {
			event.preventDefault();
			var id = editToggle.getAttribute( 'data-rule' );
			var row = document.getElementById( 'ttr-edit-' + id );
			toggleEditRow( id, row ? row.hidden : true );
			return;
		}

		var cancel = event.target.closest( '.ttr-edit-cancel' );

		if ( cancel ) {
			event.preventDefault();
			toggleEditRow( cancel.getAttribute( 'data-rule' ), false );
			return;
		}

		var del = event.target.closest( '.ttr-confirm-delete' );

		if ( del && ! window.confirm( L10N.confirmDelete || 'Delete this rule?' ) ) {
			event.preventDefault();
			return;
		}

		var deleteAll = event.target.closest( '.ttr-confirm-delete-all' );

		if ( deleteAll && ! window.confirm( L10N.confirmReplace || 'This deletes every existing rule. Continue?' ) ) {
			event.preventDefault();
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.classList.contains( 'ttr-status-select' ) ) {
			syncTargetField( event.target );
			return;
		}

		// The visible switch is decorative; the form carries the new state.
		if ( event.target.id === 'ttr-master-switch' ) {
			var form = event.target.closest( 'form' );
			if ( form ) {
				form.submit();
			}
			return;
		}

		if ( event.target.classList.contains( 'ttr-replace-toggle' ) && event.target.checked ) {
			if ( ! window.confirm( L10N.confirmReplace || 'This deletes every existing rule before importing. Continue?' ) ) {
				event.target.checked = false;
			}
		}
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.ttr-status-select' ),
			syncTargetField
		);
	} );
}() );
