/**
 * CSSFlow Tools screen interactions.
 *
 * @package CSSFlow
 */

(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var preview = document.querySelector('[data-cssflow-auto-scroll="1"]');
		var heading;
		var bulkSelect;
		var actionSelects;
		var selectionSummary;

		if (!preview) {
			return;
		}

		preview.scrollIntoView({
			behavior: 'auto',
			block: 'start'
		});

		heading = preview.querySelector('h2[tabindex="-1"]');
		if (heading) {
			heading.focus({ preventScroll: true });
		}

		bulkSelect = preview.querySelector('[data-cssflow-import-set-all]');
		actionSelects = Array.prototype.slice.call(preview.querySelectorAll('[data-cssflow-import-action]'));
		selectionSummary = preview.querySelector('[data-cssflow-import-selection-summary]');

		if (!bulkSelect || !actionSelects.length) {
			return;
		}

		function updateSelectionSummary() {
			var selectedCount = actionSelects.filter(function (select) {
				return 'skip' !== select.value;
			}).length;
			var template;

			if (selectionSummary) {
				template = selectionSummary.getAttribute('data-cssflow-selection-template') || '%1$d of %2$d snippets selected';
				selectionSummary.textContent = template
					.replace('%1$d', selectedCount)
					.replace('%2$d', actionSelects.length);
			}
		}

		function applyBulkAction(value) {
			actionSelects.forEach(function (select) {
				var hasConflict = '1' === select.getAttribute('data-cssflow-has-conflict');

				if ('skip' === value) {
					select.value = 'skip';
					return;
				}

				if ('safe-import' === value) {
					select.value = hasConflict ? 'copy' : 'import';
					return;
				}

				if ('replace-existing' === value) {
					select.value = hasConflict ? 'replace' : 'import';
				}
			});

			updateSelectionSummary();
		}

		bulkSelect.addEventListener('change', function () {
			if ('custom' !== bulkSelect.value) {
				applyBulkAction(bulkSelect.value);
			}
		});

		actionSelects.forEach(function (select) {
			select.addEventListener('change', function () {
				bulkSelect.value = 'custom';
				updateSelectionSummary();
			});
		});

		updateSelectionSummary();
	});
}());
