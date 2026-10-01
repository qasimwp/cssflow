/**
 * CSSFlow All CSS interactions.
 *
 * @package CSSFlow
 */

(function( $ ) {
	'use strict';

	var config = window.CSSFlowSnippets || {};
	var toggleConfig = config.toggle || {};
	var strings = config.strings || {};

	/**
	 * Return a plain string value.
	 *
	 * @param {*} value Value to normalize.
	 * @return {string} Normalized string.
	 */
	function text( value ) {
		return 'string' === typeof value ? value : '';
	}

	/**
	 * Show one contextual CSSFlow notice on the All CSS screen.
	 *
	 * @param {string} type    Notice type.
	 * @param {string} message Notice message.
	 */
	function showNotice( type, message ) {
		var page = document.querySelector( '.cssflow-snippets-page' );
		var header = document.querySelector( '.cssflow-snippets-header' );
		var oldNotice;
		var notice;
		var paragraph;
		var dismiss;
		var dismissText;
		var allowed = [ 'success', 'warning', 'error', 'info' ];

		if ( ! page || ! header ) {
			return;
		}

		oldNotice = page.querySelector( '.cssflow-ajax-notice' );
		if ( oldNotice ) {
			oldNotice.remove();
		}

		type = -1 !== allowed.indexOf( type ) ? type : 'info';

		notice = document.createElement( 'div' );
		notice.className = 'notice notice-' + type + ' is-dismissible inline cssflow-ajax-notice';
		notice.setAttribute( 'role', 'error' === type ? 'alert' : 'status' );

		paragraph = document.createElement( 'p' );
		paragraph.textContent = text( message );
		notice.appendChild( paragraph );

		dismiss = document.createElement( 'button' );
		dismiss.type = 'button';
		dismiss.className = 'notice-dismiss';

		dismissText = document.createElement( 'span' );
		dismissText.className = 'screen-reader-text';
		dismissText.textContent = text( strings.dismissNotice ) || 'Dismiss this notice.';
		dismiss.appendChild( dismissText );
		dismiss.addEventListener( 'click', function() {
			notice.remove();
		} );
		notice.appendChild( dismiss );

		header.insertAdjacentElement( 'afterend', notice );
	}

	/**
	 * Update the All / Active / Inactive counters.
	 *
	 * @param {Object} counts Updated counts.
	 */
	function updateCounts( counts ) {
		[ 'all', 'active', 'inactive' ].forEach( function( key ) {
			var node = document.querySelector( '[data-cssflow-status-count="' + key + '"]' );

			if ( node && counts && Object.prototype.hasOwnProperty.call( counts, key ) ) {
				node.textContent = String( counts[ key ] );
			}
		} );
	}

	/**
	 * Read the currently displayed status counters.
	 *
	 * @return {Object} Current counts.
	 */
	function getCounts() {
		var counts = {};

		[ 'all', 'active', 'inactive' ].forEach( function( key ) {
			var node = document.querySelector( '[data-cssflow-status-count="' + key + '"]' );
			var value = node ? parseInt( node.textContent, 10 ) : 0;

			counts[ key ] = Number.isNaN( value ) ? 0 : value;
		} );

		return counts;
	}

	/**
	 * Calculate optimistic counters for a requested status change.
	 *
	 * @param {Object} counts     Current counts.
	 * @param {string} oldStatus Current snippet status.
	 * @param {string} newStatus Requested snippet status.
	 * @return {Object} Optimistic counts.
	 */
	function getOptimisticCounts( counts, oldStatus, newStatus ) {
		var optimistic = {
			all: counts.all,
			active: counts.active,
			inactive: counts.inactive
		};

		if ( oldStatus === newStatus ) {
			return optimistic;
		}

		if ( 'active' === newStatus ) {
			optimistic.active += 1;
			optimistic.inactive = Math.max( 0, optimistic.inactive - 1 );
		} else {
			optimistic.active = Math.max( 0, optimistic.active - 1 );
			optimistic.inactive += 1;
		}

		return optimistic;
	}

	/**
	 * Return the currently selected WordPress status view.
	 *
	 * @return {string} all, active, or inactive.
	 */
	function getCurrentView() {
		var current = document.querySelector( '[data-cssflow-status-view].current' );
		var view = current ? current.getAttribute( 'data-cssflow-status-view' ) : 'all';

		return 'active' === view || 'inactive' === view ? view : 'all';
	}

	/**
	 * Update one toggle after a successful server response.
	 *
	 * @param {HTMLElement} toggle Toggle link.
	 * @param {Object}      data   AJAX response data.
	 */
	function applyToggleState( toggle, data ) {
		var isActive = !! data.is_active;
		var status = isActive ? 'active' : 'inactive';
		var nextStatus = isActive ? 'inactive' : 'active';
		var stateLabel = text( data.state_label ) || ( isActive ? text( strings.active ) : text( strings.inactive ) );
		var actionLabel = text( data.action_label ) || ( isActive ? text( strings.deactivate ) : text( strings.activate ) );
		var snippetName = text( toggle.getAttribute( 'data-snippet-name' ) );
		var stateText = toggle.querySelector( '.cssflow-list-status-text' );
		var ariaLabel = ( actionLabel + ' ' + snippetName ).trim();

		toggle.classList.toggle( 'is-active', isActive );
		toggle.setAttribute( 'data-current-status', status );
		toggle.setAttribute( 'data-next-status', nextStatus );

		if ( stateText ) {
			stateText.textContent = stateLabel;
		}

		if ( ariaLabel ) {
			toggle.setAttribute( 'aria-label', ariaLabel );
			toggle.setAttribute( 'title', ariaLabel );
		}
	}

	/**
	 * Remove a row when it no longer belongs to the selected status view.
	 *
	 * @param {HTMLElement} toggle Toggle link.
	 * @param {string}      status New snippet status.
	 */
	function removeRowIfFilteredOut( toggle, status ) {
		var currentView = getCurrentView();
		var row;

		if ( 'all' === currentView || currentView === status ) {
			return;
		}

		row = toggle.closest( 'tr' );
		if ( row ) {
			row.remove();
		}
	}

	/**
	 * Extract an error message from a failed AJAX response.
	 *
	 * @param {*} response AJAX response.
	 * @return {string} Error message.
	 */
	function getErrorMessage( response ) {
		if ( response && response.data && response.data.message ) {
			return text( response.data.message );
		}

		return text( strings.toggleFailed ) || 'CSSFlow could not change the snippet status. Please try again.';
	}

	$( document ).on( 'click', '[data-cssflow-snippet-toggle]', function( event ) {
		var toggle = this;
		var snippetId = parseInt( toggle.getAttribute( 'data-snippet-id' ), 10 );
		var nextStatus = text( toggle.getAttribute( 'data-next-status' ) );
		var currentStatus = text( toggle.getAttribute( 'data-current-status' ) );
		var previousCounts;
		var optimisticCounts;
		var optimisticState;
		var rollbackState;

		if ( ! config.ajaxUrl || ! toggleConfig.action || ! toggleConfig.nonce || ! snippetId ) {
			return;
		}

		event.preventDefault();

		if ( 'true' === toggle.getAttribute( 'data-cssflow-pending' ) ) {
			return;
		}

		if (
			( 'active' !== currentStatus && 'inactive' !== currentStatus ) ||
			( 'active' !== nextStatus && 'inactive' !== nextStatus )
		) {
			showNotice( 'error', text( strings.toggleFailed ) );
			return;
		}

		previousCounts = getCounts();
		optimisticCounts = getOptimisticCounts( previousCounts, currentStatus, nextStatus );

		optimisticState = {
			is_active: 'active' === nextStatus,
			state_label: 'active' === nextStatus ? text( strings.active ) : text( strings.inactive ),
			action_label: 'active' === nextStatus ? text( strings.deactivate ) : text( strings.activate )
		};

		rollbackState = {
			is_active: 'active' === currentStatus,
			state_label: 'active' === currentStatus ? text( strings.active ) : text( strings.inactive ),
			action_label: 'active' === currentStatus ? text( strings.deactivate ) : text( strings.activate )
		};

		/*
		 * Optimistic UI: respond immediately while the secure server request runs.
		 * The previous state is restored if the request fails.
		 */
		applyToggleState( toggle, optimisticState );
		updateCounts( optimisticCounts );

		toggle.setAttribute( 'data-cssflow-pending', 'true' );
		toggle.setAttribute( 'aria-disabled', 'true' );
		toggle.setAttribute( 'aria-busy', 'true' );
		toggle.classList.add( 'is-loading' );

		$.ajax( {
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: toggleConfig.action,
				nonce: toggleConfig.nonce,
				snippet_id: snippetId,
				status: nextStatus
			}
		} )
			.done( function( response ) {
				var data;

				if ( ! response || ! response.success ) {
					applyToggleState( toggle, rollbackState );
					updateCounts( previousCounts );
					showNotice( 'error', getErrorMessage( response ) );
					return;
				}

				data = response.data || {};

				/* Use the authoritative state/counts returned by the server. */
				applyToggleState( toggle, data );
				updateCounts( data.counts || optimisticCounts );
				showNotice( text( data.notice_type ) || 'success', text( data.message ) );
				removeRowIfFilteredOut( toggle, text( data.status ) );
			} )
			.fail( function( xhr ) {
				var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;

				applyToggleState( toggle, rollbackState );
				updateCounts( previousCounts );
				showNotice( 'error', getErrorMessage( response ) );
			} )
			.always( function() {
				toggle.removeAttribute( 'data-cssflow-pending' );
				toggle.removeAttribute( 'aria-disabled' );
				toggle.removeAttribute( 'aria-busy' );
				toggle.classList.remove( 'is-loading' );
			} );
	} );
})( jQuery );
