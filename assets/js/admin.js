/**
 * Email Studio for HivePress - the Studio screen and the email edit screen.
 *
 * No jQuery dependency: everything here is plain DOM work, and the screen loads faster without it.
 *
 * Nothing here writes text through innerHTML except the token list, which is HTML this plugin built
 * and escaped on the server. Every value that came from a person - an email label, a message from
 * the server - goes through textContent.
 */
( function() {
	'use strict';

	var data = window.hpEmailStudio;

	if ( ! data ) {
		return;
	}

	var panel = document.getElementById( 'hpes-panel' );

	if ( ! panel ) {
		return;
	}

	// The table is absent on the email edit screen, where this file runs only for the preview panel.
	var table = document.querySelector( '.hpes__table' );

	var frame       = panel.querySelector( '.hpes-panel__frame' );
	var title       = panel.querySelector( '.hpes-panel__title' );
	var subjectLine = panel.querySelector( '.hpes-panel__subject' );
	var versions    = panel.querySelector( '.hpes-panel__versions' );
	var result      = panel.querySelector( '.hpes-panel__result' );
	var address     = panel.querySelector( '#hpes-test-address' );
	var tokensBox   = panel.querySelector( '.hpes-panel__tokens' );
	var tokensBody  = panel.querySelector( '.hpes-panel__tokens-body' );

	var current = { name: '', useDefault: false, compose: false };

	var lastFocus = null;

	/**
	 * Posts to admin-ajax and hands back the parsed response.
	 *
	 * @param {string} action Action name.
	 * @param {Object} fields Fields to send.
	 * @return {Promise}
	 */
	function post( action, fields ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'nonce', data.nonce );

		Object.keys( fields || {} ).forEach( function( key ) {
			var value = fields[ key ];

			if ( Array.isArray( value ) ) {
				value.forEach( function( item ) {
					body.append( key + '[]', item );
				} );
			} else {
				body.append( key, value );
			}
		} );

		return fetch( data.ajaxUrl, {
			method: 'POST',
			body: body,

			// admin-ajax identifies the administrator by cookie, so the request has to carry it.
			credentials: 'same-origin'
		} ).then( function( response ) {
			return response.json();
		} );
	}

	/**
	 * Reads a message out of a response without trusting its shape.
	 *
	 * @param {Object} response Parsed response.
	 * @return {string}
	 */
	function messageFrom( response ) {
		if ( response && response.data && response.data.message ) {
			return response.data.message;
		}

		return data.strings.genericError;
	}

	/**
	 * Shows a result message in the panel.
	 *
	 * @param {string} text Message.
	 * @param {boolean} isError Is this a failure?
	 */
	function say( text, isError ) {
		result.textContent = text;
		result.classList.toggle( 'is-error', !! isError );
	}

	/**
	 * Builds the preview address for whatever the panel currently shows.
	 *
	 * @return {string}
	 */
	function previewUrl() {
		if ( current.compose ) {
			return data.ajaxUrl +
				'?action=hpes_compose_preview' +
				'&subject=' + encodeURIComponent( composeSubject() ) +
				'&body=' + encodeURIComponent( composeBody() ) +
				'&_wpnonce=' + encodeURIComponent( data.nonce ) +
				'&t=' + Date.now();
		}

		return data.ajaxUrl +
			'?action=hpes_preview' +
			'&name=' + encodeURIComponent( current.name ) +
			'&default=' + ( current.useDefault ? '1' : '0' ) +
			'&layout=' + encodeURIComponent( current.layout || '' ) +
			'&_wpnonce=' + encodeURIComponent( data.nonce ) +

			// Cache buster, so switching between versions always refetches rather than showing the
			// copy the browser kept from the last time this exact address was loaded.
			'&t=' + Date.now();
	}

	/**
	 * Loads the preview into the iframe.
	 */
	function loadPreview() {
		frame.setAttribute( 'src', previewUrl() );
	}

	/**
	 * Loads the token list for the email currently open.
	 */
	function loadTokens() {
		tokensBody.textContent = '';

		post( 'hpes_tokens', { name: current.name } ).then( function( response ) {
			if ( response && response.success && response.data && response.data.html ) {

				// Server-built markup, escaped there; see the note at the top of this file.
				tokensBody.innerHTML = response.data.html;
			}
		} ).catch( function() {
			tokensBody.textContent = data.strings.genericError;
		} );
	}

	/**
	 * Opens the panel.
	 *
	 * @param {Object} options Panel options.
	 */
	function openPanel( options ) {
		lastFocus = document.activeElement;

		current.name = options.name || '';
		current.useDefault = false;
		current.compose = !! options.compose;
		current.layout = '';

		title.textContent = options.label || '';
		subjectLine.textContent = options.subject || '';

		// The original-versus-yours switch only means anything once there is a version of your own.
		versions.hidden = ! options.customised;

		panel.querySelectorAll( '.hpes-version' ).forEach( function( button ) {
			button.classList.toggle( 'is-active', '0' === button.getAttribute( 'data-default' ) );
		} );

		// The layout switch only applies to WooCommerce emails, and starts on the saved layout.
		var layouts = panel.querySelector( '.hpes-panel__layouts' );

		if ( layouts ) {
			layouts.hidden = ! options.woocommerce;

			panel.querySelectorAll( '.hpes-layout' ).forEach( function( button ) {
				button.classList.toggle( 'is-active', button.getAttribute( 'data-layout' ) === ( data.wooLayout || 'woocommerce' ) );
			} );
		}

		setDevice( 'desktop' );
		say( '', false );

		if ( tokensBox ) {
			tokensBox.open = false;
			tokensBox.hidden = current.compose;
		}

		panel.hidden = false;
		document.body.classList.add( 'hpes-panel-open' );

		loadPreview();

		if ( ! current.compose ) {
			loadTokens();
		}

		panel.querySelector( '.hpes-panel__close' ).focus();
	}

	/**
	 * Opens the panel for a row in the list.
	 *
	 * @param {Element} row Table row.
	 */
	function openPanelForRow( row ) {
		var subject = row.querySelector( '.hpes__subject' );

		openPanel( {
			name: row.getAttribute( 'data-name' ),
			label: row.getAttribute( 'data-label' ),
			subject: subject ? subject.textContent : '',
			customised: '1' === row.getAttribute( 'data-customised' ),
			woocommerce: '1' === row.getAttribute( 'data-woocommerce' )
		} );
	}

	/**
	 * Closes the preview panel.
	 */
	function closePanel() {
		panel.hidden = true;
		document.body.classList.remove( 'hpes-panel-open' );

		// Stop the preview rendering behind a closed panel.
		frame.setAttribute( 'src', 'about:blank' );

		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}

	/**
	 * Switches the preview between widths.
	 *
	 * @param {string} device Device name.
	 */
	function setDevice( device ) {
		panel.querySelectorAll( '.hpes-device' ).forEach( function( button ) {
			button.classList.toggle( 'is-active', button.getAttribute( 'data-device' ) === device );
		} );

		panel.querySelector( '.hpes-panel__stage' ).setAttribute( 'data-device', device );
	}

	/* ==================================================================
	 * The email list
	 * ================================================================== */

	/**
	 * Applies the search and filter boxes to the table.
	 */
	function applyFilters() {
		var search = ( document.getElementById( 'hpes-search' ).value || '' ).toLowerCase().trim();
		var source = document.getElementById( 'hpes-source' ).value;
		var status = document.getElementById( 'hpes-status' ).value;

		var shown = 0;

		table.querySelectorAll( '.hpes__row' ).forEach( function( row ) {
			var matches = true;

			if ( search && row.getAttribute( 'data-search' ).indexOf( search ) === -1 ) {
				matches = false;
			}

			if ( source && row.getAttribute( 'data-source' ) !== source ) {
				matches = false;
			}

			if ( status && row.getAttribute( 'data-status' ) !== status ) {
				matches = false;
			}

			row.hidden = ! matches;

			if ( matches ) {
				shown++;
			}
		} );

		document.getElementById( 'hpes-empty' ).hidden = shown > 0;
	}

	/**
	 * Runs an action on a row and reloads when it succeeds.
	 *
	 * @param {Element} button Button pressed.
	 * @param {string} action Action name.
	 * @param {Object} fields Fields to send.
	 */
	function rowAction( button, action, fields ) {
		button.disabled = true;

		post( action, fields ).then( function( response ) {
			button.disabled = false;

			if ( ! response || ! response.success ) {
				window.alert( messageFrom( response ) );

				return;
			}

			window.location.reload();
		} ).catch( function() {
			button.disabled = false;
			window.alert( data.strings.genericError );
		} );
	}

	if ( table ) {

		// One listener on the table rather than one per button, so rows stay cheap.
		table.addEventListener( 'click', function( event ) {
			var button = event.target.closest( 'button' );

			if ( ! button ) {
				return;
			}

			var row = button.closest( '.hpes__row' );

			if ( ! row ) {
				return;
			}

			var name = row.getAttribute( 'data-name' );

			if ( button.classList.contains( 'hpes-preview' ) ) {
				openPanelForRow( row );

				return;
			}

			if ( button.classList.contains( 'hpes-toggle' ) ) {
				var disabling = '0' === button.getAttribute( 'data-disabled' );

				// Disabling an email people depend on to finish signing in deserves a second look,
				// so the confirmation names the email rather than asking a generic "are you sure".
				if ( disabling && '1' === row.getAttribute( 'data-critical' ) ) {
					if ( ! window.confirm( data.strings.confirmCritical.replace( '%s', row.getAttribute( 'data-label' ) ) ) ) {
						return;
					}
				}

				rowAction( button, 'hpes_toggle', { name: name, disabled: disabling ? '1' : '0' } );

				return;
			}

			if ( button.classList.contains( 'hpes-reset' ) ) {
				if ( ! window.confirm( data.strings.confirmReset ) ) {
					return;
				}

				rowAction( button, 'hpes_reset', { name: name } );

				return;
			}

			if ( button.classList.contains( 'hpes-customise' ) ) {
				button.disabled = true;

				post( 'hpes_customise', { name: name } ).then( function( response ) {
					if ( response && response.success && response.data && response.data.url ) {
						window.location.href = response.data.url;

						return;
					}

					button.disabled = false;
					window.alert( messageFrom( response ) );
				} ).catch( function() {
					button.disabled = false;
					window.alert( data.strings.genericError );
				} );
			}
		} );

		[ 'hpes-search', 'hpes-source', 'hpes-status' ].forEach( function( id ) {
			var control = document.getElementById( id );

			if ( control ) {
				control.addEventListener( 'input', applyFilters );
				control.addEventListener( 'change', applyFilters );
			}
		} );

		/* ==============================================================
		 * Sorting
		 * ============================================================== */

		var body = table.querySelector( 'tbody' );

		/**
		 * Reorders the rows by one column.
		 *
		 * Sorting moves the existing rows rather than rebuilding them, so whatever the filters have
		 * hidden stays hidden and every button keeps the listener the table already has.
		 *
		 * @param {Element} header Header cell clicked.
		 */
		function sortBy( header ) {
			var key = header.getAttribute( 'data-sort' );
			var wasAscending = 'ascending' === header.getAttribute( 'aria-sort' );
			var direction = wasAscending ? -1 : 1;

			table.querySelectorAll( '.hpes__sortable' ).forEach( function( th ) {
				th.setAttribute( 'aria-sort', 'none' );
				th.classList.remove( 'is-ascending', 'is-descending' );
			} );

			header.setAttribute( 'aria-sort', wasAscending ? 'descending' : 'ascending' );
			header.classList.add( wasAscending ? 'is-descending' : 'is-ascending' );

			var rows = Array.prototype.slice.call( body.querySelectorAll( '.hpes__row' ) );

			/**
			 * Compares two values the way a reader expects.
			 *
			 * Locale-aware and case-insensitive, so a translated column sorts by its own language's
			 * rules rather than by character code.
			 *
			 * @param {string} a First value.
			 * @param {string} b Second value.
			 * @return {number}
			 */
			function compare( a, b ) {
				return ( a || '' ).localeCompare( b || '', undefined, { sensitivity: 'base', numeric: true } );
			}

			rows.sort( function( a, b ) {
				var result = compare( a.getAttribute( 'data-sort-' + key ), b.getAttribute( 'data-sort-' + key ) );

				/*
				 * Ties break on the email's name, as a SECOND comparison rather than by gluing the
				 * two keys into one string. Concatenating them looked equivalent and was not:
				 * "HivePress Bookings" plus a label sorted in among the plain "HivePress" rows,
				 * because the comparison never reached the boundary between the two parts. Sorting
				 * by Plugin therefore split HivePress's own emails in half.
				 */
				if ( 0 === result ) {
					return compare( a.getAttribute( 'data-sort-label' ), b.getAttribute( 'data-sort-label' ) );
				}

				return direction * result;
			} );

			rows.forEach( function( row ) {
				body.appendChild( row );
			} );
		}

		table.querySelectorAll( '.hpes__sortable' ).forEach( function( header ) {
			var button = header.querySelector( '.hpes__sort-button' );

			if ( button ) {
				button.addEventListener( 'click', function() {
					sortBy( header );
				} );
			}
		} );
	}

	/* ==================================================================
	 * The preview button on the email edit screen
	 * ================================================================== */

	var editPreview = document.querySelector( '.hpes-edit-preview .hpes-preview' );

	if ( editPreview ) {
		editPreview.addEventListener( 'click', function() {
			openPanel( {
				name: editPreview.getAttribute( 'data-name' ),
				label: editPreview.getAttribute( 'data-label' ),
				customised: true
			} );
		} );
	}

	/* ==================================================================
	 * The panel's own controls
	 * ================================================================== */

	panel.addEventListener( 'click', function( event ) {
		if ( event.target.closest( '[data-hpes-close]' ) ) {
			closePanel();

			return;
		}

		var device = event.target.closest( '.hpes-device' );

		if ( device ) {
			setDevice( device.getAttribute( 'data-device' ) );

			return;
		}

		var layout = event.target.closest( '.hpes-layout' );

		if ( layout ) {
			current.layout = layout.getAttribute( 'data-layout' );

			panel.querySelectorAll( '.hpes-layout' ).forEach( function( button ) {
				button.classList.toggle( 'is-active', button === layout );
			} );

			loadPreview();

			return;
		}

		var version = event.target.closest( '.hpes-version' );

		if ( version ) {
			current.useDefault = '1' === version.getAttribute( 'data-default' );

			panel.querySelectorAll( '.hpes-version' ).forEach( function( button ) {
				button.classList.toggle( 'is-active', button === version );
			} );

			loadPreview();

			return;
		}

		if ( event.target.closest( '.hpes-send-test' ) ) {
			var to = ( address.value || '' ).trim();

			if ( ! to ) {
				address.focus();

				return;
			}

			var send = panel.querySelector( '.hpes-send-test' );

			send.disabled = true;
			say( data.strings.testSending, false );

			var action = current.compose ? 'hpes_compose_test' : 'hpes_test';
			var fields = current.compose ?
				{ subject: composeSubject(), body: composeBody() } :
				{ name: current.name, address: to };

			post( action, fields ).then( function( response ) {
				send.disabled = false;
				say( messageFrom( response ), ! ( response && response.success ) );
			} ).catch( function() {
				send.disabled = false;
				say( data.strings.genericError, true );
			} );
		}
	} );

	document.addEventListener( 'keydown', function( event ) {
		if ( 'Escape' === event.key && ! panel.hidden ) {
			closePanel();
		}
	} );

	/* ==================================================================
	 * The delivery log
	 * ================================================================== */

	var clearLog = document.querySelector( '.hpes-clear-log' );

	if ( clearLog ) {
		clearLog.addEventListener( 'click', function() {
			if ( ! window.confirm( data.strings.confirmClearLog ) ) {
				return;
			}

			rowAction( clearLog, 'hpes_clear_log', {} );
		} );
	}

	/* ==================================================================
	 * The composer
	 * ================================================================== */

	var compose = document.querySelector( '.hpes-compose' );

	/**
	 * Reads the composed subject.
	 *
	 * @return {string}
	 */
	function composeSubject() {
		var field = document.getElementById( 'hpes-compose-subject' );

		return field ? field.value : '';
	}

	/**
	 * Reads the composed body out of whichever editor is showing.
	 *
	 * TinyMCE keeps its content in an iframe until it syncs back to the textarea, so the visual tab
	 * has to be asked directly or a message typed there reads as empty.
	 *
	 * @return {string}
	 */
	function composeBody() {
		var editor = window.tinymce && window.tinymce.get( 'hpescomposebody' );

		if ( editor && ! editor.isHidden() ) {
			return editor.getContent();
		}

		var textarea = document.getElementById( 'hpescomposebody' );

		return textarea ? textarea.value : '';
	}

	/**
	 * Reads the chosen recipients for the "specific people" audience.
	 *
	 * @return {Array}
	 */
	function composeUsers() {
		var select = document.getElementById( 'hpes-compose-users' );

		if ( ! select ) {
			return [];
		}

		return Array.prototype.slice.call( select.selectedOptions || [] ).map( function( option ) {
			return option.value;
		} );
	}

	if ( compose ) {
		var audience = document.getElementById( 'hpes-compose-audience' );
		var usersRow = document.getElementById( 'hpes-compose-users-row' );
		var countBox = document.getElementById( 'hpes-compose-count' );
		var composeResult = compose.querySelector( '.hpes-compose__result' );

		/*
		 * Which count request is the current one.
		 *
		 * Changing the audience and changing the chosen people fire in quick succession, and two
		 * counts can then be in flight at once. Whichever answered LAST used to win, which is not
		 * the same as whichever was ASKED last: switching from two named people back to everyone
		 * left "0 people" on screen, because the earlier reply landed after the later one. Stamping
		 * each request and ignoring anything but the newest is what makes the number on screen the
		 * answer to the question currently being asked.
		 */
		var countRequest = 0;

		/**
		 * Says how many people the current audience covers.
		 */
		function refreshCount() {
			usersRow.hidden = 'users' !== audience.value;

			countBox.textContent = data.strings.counting;

			countRequest++;

			var mine = countRequest;

			post( 'hpes_compose_count', { audience: audience.value, user_ids: composeUsers() } ).then( function( response ) {
				if ( mine !== countRequest ) {
					return;
				}

				if ( response && response.success && response.data ) {
					var n = response.data.count;

					countBox.textContent = 1 === n ? data.strings.audienceCountOne : data.strings.audienceCount.replace( '%s', n );

					countBox.setAttribute( 'data-count', n );
				} else {
					countBox.textContent = '';
				}
			} ).catch( function() {
				if ( mine === countRequest ) {
					countBox.textContent = '';
				}
			} );
		}

		audience.addEventListener( 'change', refreshCount );

		var usersSelect = document.getElementById( 'hpes-compose-users' );

		if ( usersSelect ) {
			usersSelect.addEventListener( 'change', refreshCount );

			/*
			 * Select2 announces a choice by calling jQuery's .trigger('change') on the original
			 * select, and a jQuery-triggered event does NOT reach a listener added with
			 * addEventListener. So the native listener above never fired for the people picker: the
			 * count stayed on whatever it said before anything was chosen, and the confirmation
			 * offered to send to "0 people" while the server correctly resolved one. Binding through
			 * jQuery as well is what hears it.
			 */
			if ( window.jQuery ) {
				window.jQuery( usersSelect ).on( 'change', refreshCount );
			}
		}

		refreshCount();

		compose.addEventListener( 'click', function( event ) {
			var button = event.target.closest( 'button' );

			if ( ! button ) {
				return;
			}

			if ( button.classList.contains( 'hpes-compose-preview' ) ) {
				openPanel( {
					compose: true,
					label: composeSubject(),
					customised: false
				} );

				return;
			}

			if ( button.classList.contains( 'hpes-compose-test' ) ) {
				if ( ! composeSubject() || ! composeBody().trim() ) {
					composeResult.textContent = data.strings.composeMissing;

					return;
				}

				button.disabled = true;
				composeResult.textContent = data.strings.testSending;

				post( 'hpes_compose_test', { subject: composeSubject(), body: composeBody() } ).then( function( response ) {
					button.disabled = false;
					composeResult.textContent = messageFrom( response );
				} ).catch( function() {
					button.disabled = false;
					composeResult.textContent = data.strings.genericError;
				} );

				return;
			}

			if ( button.classList.contains( 'hpes-compose-send' ) ) {
				if ( ! composeSubject() || ! composeBody().trim() ) {
					composeResult.textContent = data.strings.composeMissing;

					return;
				}

				button.disabled = true;
				composeResult.textContent = data.strings.counting;

				/*
				 * The count is fetched again here rather than read off the screen.
				 *
				 * Whatever the live counter says, the number in a confirmation that cannot be undone
				 * has to be the number the server is about to act on - and the two disagreed once
				 * already, when the picker's change event never reached the counter. Asking the same
				 * endpoint the send will use, with the same fields, is the only way the dialog and
				 * the outcome cannot drift apart.
				 */
				post( 'hpes_compose_count', {
					audience: audience.value,
					user_ids: composeUsers()
				} ).then( function( response ) {
					var total = ( response && response.success && response.data ) ? response.data.count : 0;

					button.disabled = false;
					composeResult.textContent = '';

					if ( ! total ) {
						composeResult.textContent = data.strings.audienceEmpty;

						return;
					}

					var question = 1 === total ? data.strings.confirmSendOne : data.strings.confirmSend.replace( '%s', total );

					if ( ! window.confirm( question ) ) {
						return;
					}

					button.disabled = true;
					composeResult.textContent = data.strings.testSending;

					post( 'hpes_compose_send', {
						audience: audience.value,
						user_ids: composeUsers(),
						subject: composeSubject(),
						body: composeBody()
					} ).then( function( sendResponse ) {
						button.disabled = false;
						composeResult.textContent = messageFrom( sendResponse );
					} ).catch( function() {
						button.disabled = false;
						composeResult.textContent = data.strings.genericError;
					} );
				} ).catch( function() {
					button.disabled = false;
					composeResult.textContent = data.strings.genericError;
				} );
			}
		} );
	}

	/* ==================================================================
	 * Shared settings chrome
	 * ------------------------------------------------------------------
	 * The quick-links anchor nav, a floating Save control and a back-to-top
	 * button, copied from the reference implementation in Account Menu
	 * Enhancer (resources/hivepress-settings.md, "The settings anchor nav:
	 * one shared marker class").
	 *
	 * Each piece carries TWO classes: a shared marker that is never styled and
	 * exists only so siblings can find it (`hp-settings-nav`,
	 * `hp-settings-save`, `hp-settings-top`), plus this plugin's own prefixed
	 * class carrying the CSS. Before rendering a piece, test for its marker
	 * with an EXACT class selector and stand down if a sibling got there
	 * first. The exact test is the point: the old substring convention was
	 * blind to three of the plugins it was meant to see, and failed silently.
	 *
	 * THE PERMITTED ADAPTATION. Every other carrier decorates a HivePress
	 * settings tab, so its `chromeForm()` finds `.hp-page form.hp-form--table`.
	 * This plugin has no settings tab at all - the design controls live on its
	 * own admin page - so the nav is built from the page's own sections and
	 * the Save control targets the design form inside it. Social Proof carries
	 * the same kind of adaptation for the same reason, and it is the one
	 * difference a parity audit should expect to see here.
	 * ================================================================== */

	var CHROME = { prefix: 'hpes' };

	/**
	 * The strings the chrome shows, from the same localised object as everything else.
	 *
	 * @return {Object}
	 */
	function chromeLabels() {
		return ( window.hpEmailStudio && window.hpEmailStudio.strings ) || {};
	}

	/**
	 * The Studio page container, or null when this is not it.
	 *
	 * @return {Element|null}
	 */
	function chromePage() {
		return document.querySelector( '.wrap.hpes' );
	}

	/**
	 * The quick-links anchor nav.
	 *
	 * @param {Element} page Page container.
	 */
	function addSectionNav( page ) {
		if ( document.querySelector( 'nav.hp-settings-nav' ) ) {
			return;
		}

		var headings = page.querySelectorAll( '.hpes__section > h2' );

		if ( headings.length < 2 ) {
			return;
		}

		var nav = document.createElement( 'nav' ),
			navLabel = chromeLabels().jumpTo || 'Jump to a section:';

		nav.className = 'hp-settings-nav ' + CHROME.prefix + '-settings-nav';

		/*
		 * The bar opens with its own wording, not just an aria-label. A row of pills with nothing in
		 * front of it reads as decoration, and the one audience that was told what it is - a screen
		 * reader, through the aria-label - is the one audience that could not see the pills anyway.
		 */
		var label = document.createElement( 'span' );

		label.className = CHROME.prefix + '-settings-nav__label';
		label.textContent = navLabel;

		nav.appendChild( label );

		headings.forEach( function( heading, index ) {
			var section = heading.parentNode;

			// Reuse the id the section already carries and mint one only where there is none.
			// Overwriting it breaks every link and bookmark pointing at the real id.
			if ( ! section.id ) {
				section.id = CHROME.prefix + '-section-' + index;
			}

			var link = document.createElement( 'a' );

			link.href = '#' + section.id;

			// textContent on both ends, so heading markup can never become link markup.
			link.textContent = heading.textContent;

			nav.appendChild( link );
		} );

		page.insertBefore( nav, page.firstElementChild.nextSibling );
	}

	/**
	 * The floating Save control.
	 *
	 * It submits the real form rather than carrying any save logic of its own: requestSubmit() runs
	 * the same validation and the same submit handlers as pressing the button at the bottom of the
	 * page, so there is only ever one way to save.
	 *
	 * @param {Element} page Page container.
	 */
	function addFloatingSave( page ) {
		if ( document.querySelector( '.hp-settings-save' ) ) {
			return;
		}

		var form = page.querySelector( 'form.hpes-design-form' );

		if ( ! form ) {
			return;
		}

		var submit = form.querySelector( 'input[type="submit"], button[type="submit"]' );

		if ( ! submit ) {
			return;
		}

		var button = document.createElement( 'button' ),
			icon = document.createElement( 'span' ),
			text = document.createElement( 'span' ),
			label = chromeLabels().save || 'Save Changes';

		button.type = 'button';

		/*
		 * Core's own button classes, so WordPress paints it. This control IS the form's Save button
		 * moved somewhere reachable, so it has to look like it - and "looks like it" is not one
		 * colour: every user can pick an Admin Colour Scheme and each repaints .button-primary. The
		 * prefixed class is kept for layout only.
		 */
		button.className = 'hp-settings-save ' + CHROME.prefix + '-settings-save button button-primary';
		button.setAttribute( 'aria-label', label );

		icon.className = 'dashicons dashicons-saved';
		icon.setAttribute( 'aria-hidden', 'true' );

		text.className = CHROME.prefix + '-settings-save__text';
		text.textContent = label;

		button.appendChild( icon );
		button.appendChild( text );

		button.addEventListener( 'click', function() {

			// requestSubmit() fires the submit event and the browser's own validation;
			// form.submit() would skip both.
			if ( form.requestSubmit ) {
				form.requestSubmit( submit );
			} else {
				submit.click();
			}
		} );

		document.body.appendChild( button );
	}

	/**
	 * The back-to-top button, hidden until the page has actually scrolled.
	 */
	function addBackToTop() {
		if ( document.querySelector( '.hp-settings-top' ) ) {
			return;
		}

		var button = document.createElement( 'button' ),
			icon = document.createElement( 'span' ),
			label = chromeLabels().backToTop || 'Back to top';

		button.type = 'button';

		// Core's secondary button, for the same reason as the Save tab above.
		button.className = 'hp-settings-top ' + CHROME.prefix + '-settings-top button';
		button.setAttribute( 'aria-label', label );
		button.title = label;
		button.hidden = true;

		icon.className = 'dashicons dashicons-arrow-up-alt2';
		icon.setAttribute( 'aria-hidden', 'true' );

		button.appendChild( icon );

		button.addEventListener( 'click', function() {

			// A reader who has asked for reduced motion is asking not to be moved through a long
			// page; "auto" jumps instead of animating.
			var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			window.scrollTo( { top: 0, behavior: reduced ? 'auto' : 'smooth' } );

			// Focus follows the scroll, so a keyboard user carries on from the top of the page.
			var heading = document.querySelector( '.hpes__title' );

			if ( heading ) {
				heading.setAttribute( 'tabindex', '-1' );
				heading.focus( { preventScroll: true } );
			}
		} );

		document.body.appendChild( button );

		/*
		 * The show/hide runs straight off the scroll event rather than inside
		 * requestAnimationFrame: a browser pauses rAF on a hidden page, so the callback would never
		 * run and the button would never appear. The work is two property reads and a boolean write.
		 */
		function update() {
			button.hidden = ( window.pageYOffset || document.documentElement.scrollTop ) < 300;
		}

		window.addEventListener( 'scroll', update, { passive: true } );

		update();
	}

	/**
	 * Adds every piece of chrome, one tick after ready.
	 *
	 * The delay is deliberate: load order between plugins is not something any of them controls, so
	 * a sibling whose hook registered first may still be placing its own nav when this runs. One
	 * tick lets it finish, and the stand-down guards then see it.
	 */
	window.setTimeout( function() {
		var page = chromePage();

		if ( ! page ) {
			return;
		}

		addSectionNav( page );
		addFloatingSave( page );
		addBackToTop();
	}, 0 );
}() );
