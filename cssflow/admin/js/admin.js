/**
 * Handles CSSFlow targeting controls and authenticated targeting searches.
 *
 * @package CSSFlow
 */

( function( $ ) {
	'use strict';

	var config = window.CSSFlowAdmin || {};
	var strings = config.strings || {};
	var scopeSelect = document.getElementById( 'cssflow-scope-type' );
	var panels = document.querySelectorAll( '[data-cssflow-scope-panel]' );
	var specificPostType = document.getElementById( 'cssflow-specific-post-type' );
	var specificContentDetails = document.getElementById( 'cssflow-specific-content-details' );
	var searchInput = document.getElementById( 'cssflow-content-search' );
	var searchButton = document.getElementById( 'cssflow-content-search-button' );
	var searchStatus = document.getElementById( 'cssflow-content-search-status' );
	var searchResults = document.getElementById( 'cssflow-content-search-results' );
	var loadMoreButton = document.getElementById( 'cssflow-content-load-more' );
	var selectedList = document.getElementById( 'cssflow-selected-targets-list' );
	var emptySelection = document.getElementById( 'cssflow-empty-selection' );
	var woocommerceContext = document.getElementById( 'cssflow-woocommerce-context' );
	var wooContextPanels = document.querySelectorAll( '[data-cssflow-woo-context-panel]' );
	var searchTimer = null;
	var request = null;
	var currentPage = 1;
	var currentSearch = '';
	var currentPostType = '';

	/**
	 * Normalize a value to text.
	 *
	 * @param {*} value Value.
	 * @return {string} Text value.
	 */
	function text( value ) {
		return null === value || undefined === value ? '' : String( value );
	}

	/**
	 * Format a translated string containing numbered placeholders.
	 *
	 * @param {string} template Template.
	 * @param {Array}  values   Replacement values.
	 * @return {string} Formatted text.
	 */
	function formatString( template, values ) {
		var output = text( template );

		values.forEach( function( value, index ) {
			var position = index + 1;
			var pattern = new RegExp( '%' + position + '\\$[ds]', 'g' );

			output = output.replace( pattern, text( value ) );
		} );

		return output;
	}

	/**
	 * Enable only controls belonging to the active top-level scope panel.
	 */
	function updateScopePanels() {
		if ( ! scopeSelect ) {
			return;
		}

		Array.prototype.forEach.call( panels, function( panel ) {
			var active = panel.getAttribute( 'data-cssflow-scope-panel' ) === scopeSelect.value;
			var controls = panel.querySelectorAll( '[data-cssflow-target-control]' );

			panel.hidden = ! active;

			Array.prototype.forEach.call( controls, function( control ) {
				control.disabled = ! active;
			} );
		} );

		updateSpecificContentPickerAvailability();
		updateWooContextPanels();
	}

	/**
	 * Show and enable only the picker belonging to the selected Woo context.
	 */
	/**
	 * Show the Specific Content picker only after a content type is selected.
	 */
	function updateSpecificContentPickerAvailability() {
		var scopeActive = scopeSelect && 'specific_content' === scopeSelect.value;
		var hasPostType = specificPostType && '' !== specificPostType.value;
		var controls;

		if ( ! specificContentDetails ) {
			return;
		}

		specificContentDetails.hidden = ! scopeActive || ! hasPostType;
		controls = specificContentDetails.querySelectorAll( '[data-cssflow-target-control]' );

		Array.prototype.forEach.call( controls, function( control ) {
			control.disabled = ! scopeActive || ! hasPostType;
		} );
	}

	function updateWooContextPanels() {
		var wooScopeActive = scopeSelect && 'woocommerce' === scopeSelect.value;
		var selectedContext = woocommerceContext ? woocommerceContext.value : '';

		Array.prototype.forEach.call( wooContextPanels, function( panel ) {
			var active = wooScopeActive && panel.getAttribute( 'data-cssflow-woo-context-panel' ) === selectedContext;
			var controls = panel.querySelectorAll( '[data-cssflow-target-control]' );

			panel.hidden = ! active;

			Array.prototype.forEach.call( controls, function( control ) {
				control.disabled = ! active;
			} );
		} );
	}

	/**
	 * Announce generic specific-content picker status.
	 *
	 * @param {string} message Message.
	 */
	function setStatus( message ) {
		if ( searchStatus ) {
			searchStatus.textContent = text( message );
		}
	}

	/**
	 * Update the generic empty-selection message.
	 */
	function updateEmptySelection() {
		if ( ! selectedList || ! emptySelection ) {
			return;
		}

		emptySelection.hidden = selectedList.children.length > 0;
	}

	/**
	 * Clear generic AJAX search results.
	 */
	function clearSearchResults() {
		if ( searchResults ) {
			searchResults.innerHTML = '';
		}

		if ( loadMoreButton ) {
			loadMoreButton.hidden = true;
		}
	}

	/**
	 * Clear selected generic content targets.
	 *
	 * @param {boolean} announce Whether to announce the change.
	 */
	function clearSelectedTargets( announce ) {
		if ( ! selectedList ) {
			return;
		}

		selectedList.innerHTML = '';
		updateEmptySelection();

		if ( announce ) {
			setStatus(
				strings.selectionCleared ||
					'Selected content was cleared because the content type changed.'
			);
		}
	}

	/**
	 * Determine whether a generic object is already selected.
	 *
	 * @param {number|string} id Content ID.
	 * @return {boolean} Whether selected.
	 */
	function isSelected( id ) {
		if ( ! selectedList ) {
			return false;
		}

		return !! selectedList.querySelector(
			'[data-cssflow-target-id="' + String( id ) + '"]'
		);
	}

	/**
	 * Add one generic selected content target.
	 *
	 * @param {number|string} id    Content ID.
	 * @param {string}        label Display label.
	 */
	function addSelectedTarget( id, label ) {
		var item;
		var title;
		var hidden;
		var remove;

		if ( ! selectedList ) {
			return;
		}

		if ( isSelected( id ) ) {
			setStatus( strings.alreadySelected || 'That target is already selected.' );
			return;
		}

		item = document.createElement( 'li' );
		item.setAttribute( 'data-cssflow-target-id', String( id ) );

		title = document.createElement( 'span' );
		title.className = 'cssflow-selected-target-title';
		title.textContent = text( label );

		hidden = document.createElement( 'input' );
		hidden.type = 'hidden';
		hidden.name = 'target_ids[]';
		hidden.value = String( id );
		hidden.setAttribute( 'data-cssflow-target-control', '' );

		remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button-link-delete cssflow-remove-target';
		remove.textContent = strings.remove || 'Remove';
		remove.setAttribute( 'data-cssflow-target-control', '' );

		item.appendChild( title );
		item.appendChild( hidden );
		item.appendChild( remove );
		selectedList.appendChild( item );

		updateScopePanels();
		updateEmptySelection();
		setStatus( strings.addedTarget || 'Target added.' );
	}

	/**
	 * Render generic content search results.
	 *
	 * @param {Array}   results Search results.
	 * @param {boolean} append  Whether to append to existing results.
	 */
	function renderSearchResults( results, append ) {
		if ( ! searchResults ) {
			return;
		}

		if ( ! append ) {
			searchResults.innerHTML = '';
		}

		results.forEach( function( result ) {
			var item = document.createElement( 'li' );
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'button-link cssflow-search-result-button';
			button.textContent = text( result.text );
			button.setAttribute( 'data-cssflow-result-id', String( result.id ) );
			button.setAttribute( 'data-cssflow-result-text', text( result.text ) );

			if ( isSelected( result.id ) ) {
				button.disabled = true;
				button.setAttribute( 'aria-disabled', 'true' );
			}

			item.appendChild( button );
			searchResults.appendChild( item );
		} );
	}

	/**
	 * Extract a safe error message from an AJAX response.
	 *
	 * @param {*} response Response body.
	 * @return {string} Message.
	 */
	function getResponseMessage( response ) {
		if ( response && response.data && response.data.message ) {
			return text( response.data.message );
		}

		return strings.searchFailed ||
			'CSSFlow could not complete the search. Please try again.';
	}

	/**
	 * Perform the generic specific-content AJAX search.
	 *
	 * @param {number}  page   Page number.
	 * @param {boolean} append Whether to append results.
	 */
	function performSearch( page, append ) {
		var postType = specificPostType ? specificPostType.value : '';
		var query = searchInput ? searchInput.value.trim() : '';
		var minimum = Number( config.minSearchLength || 2 );
		var searchConfig = config.contentSearch || {};

		if ( ! postType ) {
			clearSearchResults();
			setStatus( strings.selectPostType || 'Select a content type before searching.' );
			return;
		}

		if ( query.length < minimum ) {
			clearSearchResults();
			setStatus( strings.enterSearch || 'Enter at least 2 characters to search.' );
			return;
		}

		if ( request && request.readyState !== 4 ) {
			request.abort();
		}

		if ( searchButton ) {
			searchButton.disabled = true;
		}

		if ( loadMoreButton ) {
			loadMoreButton.disabled = true;
		}

		setStatus( strings.searching || 'Searching…' );

		request = $.ajax( {
			url: config.ajaxUrl,
			method: 'GET',
			dataType: 'json',
			data: {
				action: searchConfig.action,
				nonce: searchConfig.nonce,
				post_type: postType,
				search: query,
				paged: page
			}
		} )
			.done( function( response ) {
				var data;
				var results;

				if ( ! response || ! response.success ) {
					clearSearchResults();
					setStatus( getResponseMessage( response ) );
					return;
				}

				data = response.data || {};
				results = Array.isArray( data.results ) ? data.results : [];
				currentPage = Number( data.page || page );
				currentSearch = query;
				currentPostType = postType;

				renderSearchResults( results, append );

				if ( loadMoreButton ) {
					loadMoreButton.hidden = ! data.more;
				}

				if ( 0 === results.length && ! append ) {
					setStatus( strings.noResults || 'No matching items were found.' );
				} else if ( 1 === results.length ) {
					setStatus( strings.oneResult || '1 result found.' );
				} else {
					setStatus( formatString( strings.manyResults || '%1$d results found.', [ results.length ] ) );
				}
			} )
			.fail( function( xhr, status ) {
				var response;

				if ( 'abort' === status ) {
					return;
				}

				response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
				clearSearchResults();
				setStatus( getResponseMessage( response ) );
			} )
			.always( function() {
				if ( searchButton ) {
					searchButton.disabled = false;
				}

				if ( loadMoreButton ) {
					loadMoreButton.disabled = false;
				}

				updateScopePanels();
			} );
	}

	/**
	 * Initialize one WooCommerce AJAX picker.
	 *
	 * @param {Element} root Picker root.
	 * @param {string}  target AJAX target type.
	 * @param {string}  inputName Hidden-input name.
	 */
	function setupWooPicker( root, target, inputName ) {
		var input = root.querySelector( '[data-cssflow-picker-search]' );
		var button = root.querySelector( '[data-cssflow-picker-search-button]' );
		var status = root.querySelector( '[data-cssflow-picker-status]' );
		var resultsList = root.querySelector( '[data-cssflow-picker-results]' );
		var loadMore = root.querySelector( '[data-cssflow-picker-load-more]' );
		var selected = root.querySelector( '[data-cssflow-picker-selected]' );
		var empty = root.querySelector( '[data-cssflow-picker-empty]' );
		var timer = null;
		var ajaxRequest = null;
		var page = 1;
		var lastSearch = '';
		var searchConfig = config.wooSearch || {};

		function announce( message ) {
			if ( status ) {
				status.textContent = text( message );
			}
		}

		function updateEmpty() {
			if ( selected && empty ) {
				empty.hidden = selected.children.length > 0;
			}
		}

		function clearResults() {
			if ( resultsList ) {
				resultsList.innerHTML = '';
			}

			if ( loadMore ) {
				loadMore.hidden = true;
			}
		}

		function alreadySelected( id ) {
			return !! selected && !! selected.querySelector( '[data-cssflow-target-id="' + String( id ) + '"]' );
		}

		function addTarget( id, label ) {
			var item;
			var title;
			var hidden;
			var remove;

			if ( ! selected ) {
				return;
			}

			if ( alreadySelected( id ) ) {
				announce( strings.alreadySelected || 'That target is already selected.' );
				return;
			}

			item = document.createElement( 'li' );
			item.setAttribute( 'data-cssflow-target-id', String( id ) );

			title = document.createElement( 'span' );
			title.className = 'cssflow-selected-target-title';
			title.textContent = text( label );

			hidden = document.createElement( 'input' );
			hidden.type = 'hidden';
			hidden.name = inputName;
			hidden.value = String( id );
			hidden.setAttribute( 'data-cssflow-target-control', '' );

			remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'button-link-delete cssflow-remove-target';
			remove.textContent = strings.remove || 'Remove';
			remove.setAttribute( 'data-cssflow-target-control', '' );

			item.appendChild( title );
			item.appendChild( hidden );
			item.appendChild( remove );
			selected.appendChild( item );

			updateScopePanels();
			updateEmpty();
			announce( strings.addedTarget || 'Target added.' );
		}

		function renderResults( items, append ) {
			if ( ! resultsList ) {
				return;
			}

			if ( ! append ) {
				resultsList.innerHTML = '';
			}

			items.forEach( function( itemData ) {
				var item = document.createElement( 'li' );
				var resultButton = document.createElement( 'button' );

				resultButton.type = 'button';
				resultButton.className = 'button-link cssflow-search-result-button';
				resultButton.textContent = text( itemData.text );
				resultButton.setAttribute( 'data-cssflow-result-id', String( itemData.id ) );
				resultButton.setAttribute( 'data-cssflow-result-text', text( itemData.text ) );

				if ( alreadySelected( itemData.id ) ) {
					resultButton.disabled = true;
					resultButton.setAttribute( 'aria-disabled', 'true' );
				}

				item.appendChild( resultButton );
				resultsList.appendChild( item );
			} );
		}

		function performWooSearch( requestedPage, append ) {
			var query = input ? input.value.trim() : '';
			var minimum = Number( config.minSearchLength || 2 );

			if ( query.length < minimum ) {
				clearResults();
				announce( strings.enterSearch || 'Enter at least 2 characters to search.' );
				return;
			}

			if ( ajaxRequest && ajaxRequest.readyState !== 4 ) {
				ajaxRequest.abort();
			}

			if ( button ) {
				button.disabled = true;
			}

			if ( loadMore ) {
				loadMore.disabled = true;
			}

			announce( strings.searching || 'Searching…' );

			ajaxRequest = $.ajax( {
				url: config.ajaxUrl,
				method: 'GET',
				dataType: 'json',
				data: {
					action: searchConfig.action,
					nonce: searchConfig.nonce,
					target: target,
					search: query,
					paged: requestedPage
				}
			} )
				.done( function( response ) {
					var data;
					var items;

					if ( ! response || ! response.success ) {
						clearResults();
						announce( getResponseMessage( response ) );
						return;
					}

					data = response.data || {};
					items = Array.isArray( data.results ) ? data.results : [];
					page = Number( data.page || requestedPage );
					lastSearch = query;
					renderResults( items, append );

					if ( loadMore ) {
						loadMore.hidden = ! data.more;
					}

					if ( 0 === items.length && ! append ) {
						announce( strings.noResults || 'No matching items were found.' );
					} else if ( 1 === items.length ) {
						announce( strings.oneResult || '1 result found.' );
					} else {
						announce( formatString( strings.manyResults || '%1$d results found.', [ items.length ] ) );
					}
				} )
				.fail( function( xhr, requestStatus ) {
					var response;

					if ( 'abort' === requestStatus ) {
						return;
					}

					response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
					clearResults();
					announce( getResponseMessage( response ) );
				} )
				.always( function() {
					if ( button ) {
						button.disabled = false;
					}

					if ( loadMore ) {
						loadMore.disabled = false;
					}

					updateScopePanels();
				} );
		}

		updateEmpty();

		if ( button ) {
			button.addEventListener( 'click', function() {
				performWooSearch( 1, false );
			} );
		}

		if ( input ) {
			input.addEventListener( 'input', function() {
				window.clearTimeout( timer );
				timer = window.setTimeout( function() {
					if ( input.value.trim().length >= Number( config.minSearchLength || 2 ) ) {
						performWooSearch( 1, false );
					} else {
						clearResults();
						announce( strings.enterSearch || 'Enter at least 2 characters to search.' );
					}
				}, 350 );
			} );

			input.addEventListener( 'keydown', function( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					performWooSearch( 1, false );
				}
			} );
		}

		if ( loadMore ) {
			loadMore.addEventListener( 'click', function() {
				if ( input && input.value.trim() === lastSearch ) {
					performWooSearch( page + 1, true );
				} else {
					performWooSearch( 1, false );
				}
			} );
		}

		if ( resultsList ) {
			resultsList.addEventListener( 'click', function( event ) {
				var resultButton = event.target.closest( '.cssflow-search-result-button' );

				if ( ! resultButton || resultButton.disabled ) {
					return;
				}

				addTarget(
					resultButton.getAttribute( 'data-cssflow-result-id' ),
					resultButton.getAttribute( 'data-cssflow-result-text' )
				);

				resultButton.disabled = true;
				resultButton.setAttribute( 'aria-disabled', 'true' );
			} );
		}

		if ( selected ) {
			selected.addEventListener( 'click', function( event ) {
				var removeButton = event.target.closest( '.cssflow-remove-target' );
				var item;
				var id;
				var resultButton;

				if ( ! removeButton ) {
					return;
				}

				item = removeButton.closest( '[data-cssflow-target-id]' );

				if ( ! item ) {
					return;
				}

				id = item.getAttribute( 'data-cssflow-target-id' );
				item.parentNode.removeChild( item );
				updateEmpty();
				announce( strings.removedTarget || 'Target removed.' );

				if ( resultsList ) {
					resultButton = resultsList.querySelector( '[data-cssflow-result-id="' + String( id ) + '"]' );

					if ( resultButton ) {
						resultButton.disabled = false;
						resultButton.removeAttribute( 'aria-disabled' );
					}
				}
			} );
		}

		return {
			clearResults: clearResults,
			announce: announce
		};
	}

	if ( ! scopeSelect ) {
		return;
	}

	updateScopePanels();
	updateEmptySelection();

	scopeSelect.addEventListener( 'change', function() {
		updateScopePanels();
		clearSearchResults();
		setStatus( '' );
	} );

	if ( specificPostType ) {
		specificPostType.addEventListener( 'change', function() {
			clearSearchResults();
			clearSelectedTargets( true );
			currentPage = 1;
			currentSearch = '';
			currentPostType = specificPostType.value;
			updateSpecificContentPickerAvailability();
		} );
	}

	if ( searchButton ) {
		searchButton.addEventListener( 'click', function() {
			performSearch( 1, false );
		} );
	}

	if ( searchInput ) {
		searchInput.addEventListener( 'input', function() {
			window.clearTimeout( searchTimer );

			searchTimer = window.setTimeout( function() {
				if ( searchInput.value.trim().length >= Number( config.minSearchLength || 2 ) ) {
					performSearch( 1, false );
				} else {
					clearSearchResults();
					setStatus( strings.enterSearch || 'Enter at least 2 characters to search.' );
				}
			}, 350 );
		} );

		searchInput.addEventListener( 'keydown', function( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				performSearch( 1, false );
			}
		} );
	}

	if ( loadMoreButton ) {
		loadMoreButton.addEventListener( 'click', function() {
			if ( searchInput && specificPostType && searchInput.value.trim() === currentSearch && specificPostType.value === currentPostType ) {
				performSearch( currentPage + 1, true );
			} else {
				performSearch( 1, false );
			}
		} );
	}

	if ( searchResults ) {
		searchResults.addEventListener( 'click', function( event ) {
			var button = event.target.closest( '.cssflow-search-result-button' );

			if ( ! button || button.disabled ) {
				return;
			}

			addSelectedTarget(
				button.getAttribute( 'data-cssflow-result-id' ),
				button.getAttribute( 'data-cssflow-result-text' )
			);

			button.disabled = true;
			button.setAttribute( 'aria-disabled', 'true' );
		} );
	}

	if ( selectedList ) {
		selectedList.addEventListener( 'click', function( event ) {
			var button = event.target.closest( '.cssflow-remove-target' );
			var item;
			var id;
			var resultButton;

			if ( ! button ) {
				return;
			}

			item = button.closest( '[data-cssflow-target-id]' );

			if ( ! item ) {
				return;
			}

			id = item.getAttribute( 'data-cssflow-target-id' );
			item.parentNode.removeChild( item );
			updateEmptySelection();
			setStatus( strings.removedTarget || 'Target removed.' );

			if ( searchResults ) {
				resultButton = searchResults.querySelector( '[data-cssflow-result-id="' + String( id ) + '"]' );

				if ( resultButton ) {
					resultButton.disabled = false;
					resultButton.removeAttribute( 'aria-disabled' );
				}
			}
		} );
	}

	if ( woocommerceContext ) {
		var wooProductRoot = document.querySelector( '[data-cssflow-picker="woocommerce-products"]' );
		var wooCategoryRoot = document.querySelector( '[data-cssflow-picker="woocommerce-categories"]' );
		var wooProductPicker = wooProductRoot ? setupWooPicker( wooProductRoot, 'products', 'woocommerce_product_ids[]' ) : null;
		var wooCategoryPicker = wooCategoryRoot ? setupWooPicker( wooCategoryRoot, 'product_categories', 'woocommerce_term_ids[]' ) : null;

		woocommerceContext.addEventListener( 'change', function() {
			updateWooContextPanels();

			if ( wooProductPicker ) {
				wooProductPicker.clearResults();
				wooProductPicker.announce( '' );
			}

			if ( wooCategoryPicker ) {
				wooCategoryPicker.clearResults();
				wooCategoryPicker.announce( '' );
			}
		} );
	}
} )( jQuery );
