/**
 * Enhances the CSSFlow CSS textarea with WordPress's bundled code editor and
 * provides conservative, advisory CSS structural checks.
 *
 * @package CSSFlow
 */

( function( $, wp, CSSLint ) {
	'use strict';

	var config = window.CSSFlowEditor || {};
	var strings = config.strings || {};
	var textareaId = 'cssflow-css-code';
	var problemsId = 'cssflow-css-problems';
	var summaryId = 'cssflow-css-problems-summary';
	var listId = 'cssflow-css-problems-list';
	var lintTimer = null;
	var editor = null;
	var textarea = null;

	/**
	 * Normalize a value to a string.
	 *
	 * @param {*} value Value to normalize.
	 * @return {string} Normalized text.
	 */
	function normalizeText( value ) {
		return null === value || undefined === value ? '' : String( value );
	}

	/**
	 * Format a translated string containing numbered placeholders.
	 *
	 * @param {string} template Translation template.
	 * @param {Array}  values   Placeholder values.
	 * @return {string} Formatted string.
	 */
	function formatString( template, values ) {
		var formatted = normalizeText( template );

		values.forEach( function( value, index ) {
			var position = index + 1;
			var pattern = new RegExp( '%' + position + '\\$[ds]', 'g' );

			formatted = formatted.replace(
				pattern,
				normalizeText( value )
			);
		} );

		return formatted;
	}

	/**
	 * Get the current CSS source.
	 *
	 * @return {string} Current CSS.
	 */
	function getSource() {
		if ( editor && editor.codemirror ) {
			return editor.codemirror.getValue();
		}

		if ( textarea ) {
			return textarea.value;
		}

		return '';
	}

	/**
	 * Add a problem only once.
	 *
	 * @param {Array}  problems Problem collection.
	 * @param {number} line     One-based line number.
	 * @param {number} column   One-based column number.
	 * @param {string} message  Human-readable message.
	 */
	function addProblem( problems, line, column, message ) {
		var safeLine = Math.max( 1, Number( line || 1 ) );
		var safeColumn = Math.max( 1, Number( column || 1 ) );
		var safeMessage = normalizeText( message );
		var key = [
			safeLine,
			safeColumn,
			safeMessage
		].join( '|' );

		var exists = problems.some( function( problem ) {
			return problem.key === key;
		} );

		if ( exists ) {
			return;
		}

		problems.push( {
			key: key,
			line: safeLine,
			column: safeColumn,
			message: safeMessage
		} );
	}

	/**
	 * Determine whether a line looks like the beginning of a CSS declaration.
	 *
	 * Examples:
	 *
	 * color: red;
	 * background-color: blue;
	 * --custom-property: 10px;
	 *
	 * @param {string} line Line text.
	 * @return {boolean} Whether it resembles a declaration.
	 */
	function looksLikeDeclaration( line ) {
		return /^\s*(?:--[a-zA-Z0-9_-]+|[-_a-zA-Z][a-zA-Z0-9_-]*)\s*:/.test(
			normalizeText( line )
		);
	}

	/**
	 * Remove comments from a single line for conservative line-level checks.
	 *
	 * @param {string} line Source line.
	 * @return {string} Line without simple block-comment content.
	 */
	function stripSimpleComments( line ) {
		return normalizeText( line ).replace(
			/\/\*.*?\*\//g,
			''
		);
	}

	/**
	 * Get the first non-whitespace column on a line.
	 *
	 * @param {string} line Source line.
	 * @return {number} One-based column.
	 */
	function getFirstContentColumn( line ) {
		var match = normalizeText( line ).match( /\S/ );

		return match ? match.index + 1 : 1;
	}

	/**
	 * Determine whether a CSSLint problem points at a selector header that
	 * contains a pseudo-class or pseudo-element.
	 *
	 * WordPress's bundled CSSLint parser predates some selector syntax and can
	 * incorrectly interpret valid selectors such as body::before or
	 * a:hover::after as malformed declarations. CSSFlow already performs its
	 * own conservative brace and declaration checks, so these legacy parser
	 * reports are ignored when the affected source line is clearly a selector
	 * immediately followed by an opening brace.
	 *
	 * @param {string} source  Current CSS source.
	 * @param {Object} problem CSSLint problem.
	 * @return {boolean} Whether the parser message should be ignored.
	 */
	function isPseudoSelectorLintProblem( source, problem ) {
		var lines = normalizeText( source ).split( '\n' );
		var lineNumber = Math.max( 1, Number( problem.line || 1 ) );
		var line = '';
		var trimmed = '';

		if ( lineNumber > lines.length ) {
			return false;
		}

		line = stripSimpleComments( lines[ lineNumber - 1 ] );
		trimmed = line.trim();

		if ( ! trimmed ) {
			return false;
		}

		/*
		 * Only ignore a CSSLint parser message when the reported line is
		 * clearly a selector header. Requiring both ":" and "{" prevents
		 * declaration lines such as "color: red;" from being suppressed.
		 */
		if (
			-1 === trimmed.indexOf( ':' ) ||
			-1 === trimmed.indexOf( '{' )
		) {
			return false;
		}

		/*
		 * A selector header ends at its opening brace. It must not resemble a
		 * normal property declaration before that brace.
		 */
		return ! looksLikeDeclaration(
			trimmed.substring(
				0,
				trimmed.indexOf( '{' )
			)
		);
	}

	/**
	 * Find unmatched braces and opening braces with no selector/at-rule.
	 *
	 * Strings and comments are ignored so braces inside them do not create
	 * false reports.
	 *
	 * @param {string} source CSS source.
	 * @return {Array} Structural brace problems.
	 */
	function getBraceProblems( source ) {
		var problems = [];
		var stack = [];
		var quote = '';
		var escaped = false;
		var inComment = false;
		var line = 1;
		var column = 0;
		var segmentStart = 0;
		var index;
		var character;
		var nextCharacter;
		var header;

		for ( index = 0; index < source.length; index++ ) {
			character = source.charAt( index );
			nextCharacter = source.charAt( index + 1 );
			column++;

			if ( '\n' === character ) {
				line++;
				column = 0;
				escaped = false;
				continue;
			}

			if ( inComment ) {
				if ( '*' === character && '/' === nextCharacter ) {
					inComment = false;
					index++;
					column++;
				}

				continue;
			}

			if ( quote ) {
				if ( escaped ) {
					escaped = false;
					continue;
				}

				if ( '\\' === character ) {
					escaped = true;
					continue;
				}

				if ( character === quote ) {
					quote = '';
				}

				continue;
			}

			if ( '/' === character && '*' === nextCharacter ) {
				inComment = true;
				index++;
				column++;
				continue;
			}

			if ( '"' === character || '\'' === character ) {
				quote = character;
				continue;
			}

			if ( '{' === character ) {
				header = source.substring(
					segmentStart,
					index
				);

				header = header
					.replace( /\/\*[\s\S]*?\*\//g, '' )
					.trim();

				if ( '' === header ) {
					addProblem(
						problems,
						line,
						column,
						strings.missingSelector ||
							'A CSS selector or at-rule is missing before this opening brace {.'
					);
				}

				stack.push( {
					line: line,
					column: column
				} );

				segmentStart = index + 1;
				continue;
			}

			if ( '}' === character ) {
				if ( stack.length ) {
					stack.pop();
				} else {
					addProblem(
						problems,
						line,
						column,
						strings.unexpectedClosingBrace ||
							'This closing brace } does not have a matching opening brace {.'
					);
				}

				segmentStart = index + 1;
				continue;
			}

			if ( ';' === character ) {
				segmentStart = index + 1;
			}
		}

		stack.forEach( function( openingBrace ) {
			addProblem(
				problems,
				openingBrace.line,
				openingBrace.column,
				strings.missingClosingBrace ||
					'This opening brace { does not have a matching closing brace }.'
			);
		} );

		return problems;
	}

	/**
	 * Identify likely missing semicolons between declarations.
	 *
	 * This intentionally uses a conservative line-based rule. It reports a
	 * problem only when one line looks like a declaration and the next
	 * meaningful line also clearly begins another declaration.
	 *
	 * This avoids falsely rejecting multiline modern CSS values.
	 *
	 * @param {string} source CSS source.
	 * @return {Array} Missing-semicolon problems.
	 */
	function getSemicolonProblems( source ) {
		var problems = [];
		var lines = source.split( '\n' );
		var index;

		for ( index = 0; index < lines.length; index++ ) {
			var current = stripSimpleComments( lines[ index ] ).trim();
			var nextIndex;
			var next = '';

			if ( ! current || ! looksLikeDeclaration( current ) ) {
				continue;
			}

			
			if ( -1 !== current.indexOf( '{' ) ) {
				continue;
			}

			/*
			 * A declaration ending with ; is complete.
			 */
			if ( /;\s*$/.test( current ) ) {
				continue;
			}

			/*
			 * Do not flag a line that obviously continues into a multiline
			 * function/value or another continuation construct.
			 */
			if ( /(?:,|\(|\[|\\)\s*$/.test( current ) ) {
				continue;
			}

			for ( nextIndex = index + 1; nextIndex < lines.length; nextIndex++ ) {
				next = stripSimpleComments( lines[ nextIndex ] ).trim();

				if ( next ) {
					break;
				}
			}

			if ( ! next ) {
				continue;
			}

			/*
			 * A closing brace immediately after the declaration is allowed:
			 *
			 * .test {
			 *     color: red
			 * }
			 *
			 * CSS permits the final semicolon to be omitted.
			 */
			if ( /^}/.test( next ) ) {
				continue;
			}

			/*
			 * If the next meaningful line clearly starts another property,
			 * the current declaration should have ended with a semicolon.
			 */
			if ( looksLikeDeclaration( next ) ) {
				addProblem(
					problems,
					index + 1,
					Math.max( 1, lines[ index ].length ),
					strings.missingSemicolon ||
						'A semicolon ; appears to be missing before the next CSS declaration.'
				);
			}
		}

		return problems;
	}

	/**
	 * Convert selected CSSLint parser messages into understandable advice.
	 *
	 * @param {Object} problem CSSLint problem.
	 * @param {string} source  Current CSS source.
	 * @return {string} Friendly message or empty string when ignored.
	 */
	function getFriendlyLintMessage( problem, source ) {
		var message = normalizeText( problem.message );

		if ( ! message ) {
			return '';
		}

		/*
		 * Ignore unreliable modern-CSS compatibility messages.
		 */
		if (
			/^Unknown property/i.test( message ) ||
			/^Unknown @ rule/i.test( message )
		) {
			return '';
		}

		/*
		 * CSSFlow performs brace checking itself.
		 */
		if (
			/Expected RBRACE/i.test( message ) ||
			/Expected LBRACE/i.test( message )
		) {
			return '';
		}

		/*
		 * CSSFlow performs semicolon checking itself.
		 */
		if (
			/Expected SEMICOLON/i.test( message ) ||
			/Missing semicolon/i.test( message )
		) {
			return '';
		}

		/*
		 * WordPress's bundled CSSLint can misread valid pseudo-class and
		 * pseudo-element selector headers as malformed declarations.
		 */
		if (
			isPseudoSelectorLintProblem( source, problem ) &&
			(
				/Expected COLON/i.test( message ) ||
				/Expected IDENT/i.test( message ) ||
				/Unexpected token/i.test( message )
			)
		) {
			return '';
		}

		if ( /Expected COLON/i.test( message ) ) {
			return strings.missingColon ||
				'A colon : may be missing between a CSS property and its value.';
		}

		/*
		 * Keep the Phase 7 checker deliberately conservative instead of
		 * exposing legacy parser jargon or questionable compatibility advice.
		 */
		return '';
	}

	/**
	 * Run CSSLint and retain only useful low-noise results.
	 *
	 * @param {string} source Current CSS.
	 * @return {Array} Friendly lint problems.
	 */
	function getLintProblems( source ) {
		var problems = [];
		var result;

		if (
			! CSSLint ||
			'function' !== typeof CSSLint.verify
		) {
			return problems;
		}

		try {
			result = CSSLint.verify( source, {} );
		} catch ( error ) {
			return problems;
		}

		if ( ! result || ! Array.isArray( result.messages ) ) {
			return problems;
		}

		result.messages.forEach( function( problem ) {
			var friendlyMessage = getFriendlyLintMessage(
				problem,
				source
			);

			if ( ! friendlyMessage ) {
				return;
			}

			addProblem(
				problems,
				Number( problem.line || 1 ),
				Number( problem.col || 1 ),
				friendlyMessage
			);
		} );

		return problems;
	}

	/**
	 * Get all current advisory problems.
	 *
	 * @return {Array} Problems.
	 */
	function getProblems() {
		var source = getSource();
		var problems = [];

		getBraceProblems( source ).forEach( function( problem ) {
			addProblem(
				problems,
				problem.line,
				problem.column,
				problem.message
			);
		} );

		getSemicolonProblems( source ).forEach( function( problem ) {
			addProblem(
				problems,
				problem.line,
				problem.column,
				problem.message
			);
		} );

		getLintProblems( source ).forEach( function( problem ) {
			addProblem(
				problems,
				problem.line,
				problem.column,
				problem.message
			);
		} );

		problems.sort( function( first, second ) {
			if ( first.line !== second.line ) {
				return first.line - second.line;
			}

			return first.column - second.column;
		} );

		return problems;
	}

	/**
	 * Convert line/column into a textarea character offset.
	 *
	 * @param {string} source CSS source.
	 * @param {number} line   One-based line.
	 * @param {number} column One-based column.
	 * @return {number} Character offset.
	 */
	function getTextareaOffset( source, line, column ) {
		var lines = source.split( '\n' );
		var safeLine = Math.max( 1, Number( line || 1 ) );
		var safeColumn = Math.max( 1, Number( column || 1 ) );
		var offset = 0;
		var index;

		for ( index = 0; index < safeLine - 1 && index < lines.length; index++ ) {
			offset += lines[ index ].length + 1;
		}

		offset += safeColumn - 1;

		return Math.min(
			offset,
			source.length
		);
	}

	/**
	 * Move focus to a reported problem.
	 *
	 * @param {number} line   One-based line.
	 * @param {number} column One-based column.
	 */
	function focusProblem( line, column ) {
		var safeLine;
		var safeColumn;
		var offset;

		if ( editor && editor.codemirror ) {
			safeLine = Math.max( 0, Number( line || 1 ) - 1 );
			safeColumn = Math.max( 0, Number( column || 1 ) - 1 );

			editor.codemirror.focus();

			editor.codemirror.setCursor( {
				line: safeLine,
				ch: safeColumn
			} );

			editor.codemirror.scrollIntoView(
				{
					line: safeLine,
					ch: safeColumn
				},
				80
			);

			return;
		}

		if ( ! textarea ) {
			return;
		}

		offset = getTextareaOffset(
			textarea.value,
			line,
			column
		);

		textarea.focus();

		if ( 'function' === typeof textarea.setSelectionRange ) {
			textarea.setSelectionRange(
				offset,
				offset
			);
		}
	}

	/**
	 * Render advisory problems below the editor.
	 */
	function renderProblems() {
		var $panel = $( '#' + problemsId );
		var $summary = $( '#' + summaryId );
		var $list = $( '#' + listId );
		var problems = getProblems();

		if ( ! $panel.length || ! $summary.length || ! $list.length ) {
			return;
		}

		$list.empty();

		if ( ! problems.length ) {
			$summary.text(
				strings.noProblems ||
					'No CSS problems detected.'
			);

			$panel.removeClass( 'has-problems' );

			return;
		}

		$panel.addClass( 'has-problems' );

		$summary.text(
			1 === problems.length ?
				( strings.oneProblem || '1 CSS problem found.' ) :
				formatString(
					strings.manyProblems || '%1$d CSS problems found.',
					[ problems.length ]
				)
		);

		problems.forEach( function( problem ) {
			var displayMessage = formatString(
				strings.lineMessage || 'Line %1$d: %2$s',
				[
					problem.line,
					problem.message
				]
			);

			var $item = $( '<li>' );

			var $button = $( '<button>', {
				type: 'button',
				'class': 'button-link cssflow-problem-link'
			} ).text( displayMessage );

			$button.on( 'click', function() {
				focusProblem(
					problem.line,
					problem.column
				);
			} );

			$item.append( $button );
			$list.append( $item );
		} );
	}

	/**
	 * Debounce problem checking while typing.
	 */
	function scheduleProblemsUpdate() {
		window.clearTimeout( lintTimer );

		lintTimer = window.setTimeout(
			renderProblems,
			250
		);
	}

	/**
	 * Initialize WordPress CodeMirror when available.
	 *
	 * @return {boolean} Whether CodeMirror initialized.
	 */
	function initializeCodeEditor() {
		var inputField;

		if (
			! config.editorSettings ||
			! wp ||
			! wp.codeEditor ||
			'function' !== typeof wp.codeEditor.initialize
		) {
			return false;
		}

		try {
			editor = wp.codeEditor.initialize(
				textareaId,
				config.editorSettings
			);
		} catch ( error ) {
			editor = null;
			return false;
		}

		if ( ! editor || ! editor.codemirror ) {
			editor = null;
			return false;
		}

		inputField = editor.codemirror.getInputField();

		if ( inputField ) {
			inputField.setAttribute(
				'aria-label',
				strings.editorLabel || 'CSS code editor'
			);

			inputField.setAttribute(
				'aria-describedby',
				'cssflow-css-description ' + summaryId
			);
		}

		editor.codemirror.on(
			'change',
			scheduleProblemsUpdate
		);

		editor.codemirror.on(
			'blur',
			renderProblems
		);

		return true;
	}

	/**
	 * Initialize problem checking for the normal textarea fallback.
	 */
	function initializeTextareaFallback() {
		if ( ! textarea ) {
			return;
		}

		textarea.addEventListener(
			'input',
			scheduleProblemsUpdate
		);

		textarea.addEventListener(
			'blur',
			renderProblems
		);
	}

	/**
	 * Initialize Phase 7 editor behavior.
	 */
	function initializeEditor() {
		var form;

		textarea = document.getElementById( textareaId );

		if ( ! textarea ) {
			return;
		}

		if ( ! initializeCodeEditor() ) {
			initializeTextareaFallback();
		}

		form = textarea.form;

		if ( form ) {
			form.addEventListener(
				'submit',
				function() {
					if ( editor && editor.codemirror ) {
						editor.codemirror.save();
					}
				}
			);
		}

		renderProblems();

		const responsiveTarget = document.getElementById( 'cssflow-responsive-type' );
		const customBreakpoint = document.getElementById( 'cssflow-breakpoint-key' );
		const customBreakpointRow = document.getElementById( 'cssflow-breakpoint-row' );

		function updateCustomBreakpointState() {
			if ( ! responsiveTarget || ! customBreakpoint ) {
				return;
			}

			const isCustom = responsiveTarget.value === 'custom';

			customBreakpoint.disabled = ! isCustom;
			customBreakpoint.setAttribute( 'aria-disabled', isCustom ? 'false' : 'true' );

			if ( customBreakpointRow ) {
				customBreakpointRow.hidden = ! isCustom;
			}
		}

		if ( responsiveTarget && customBreakpoint ) {
			updateCustomBreakpointState();

			responsiveTarget.addEventListener( 'change', updateCustomBreakpointState );
		}

	} //end initialeditor

	$( initializeEditor );
}( jQuery, window.wp, window.CSSLint ) );