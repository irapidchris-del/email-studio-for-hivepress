/**
 * Email Studio for HivePress - settings tab.
 *
 * Adds two things HivePress's own settings fields do not have: a colour picker, and a button that
 * opens the media library to pick the logo instead of asking somebody to paste an image address.
 */
( function() {
	'use strict';

	var strings = window.hpEmailStudioSettings || {};

	if ( ! window.jQuery ) {
		return;
	}

	/**
	 * Expands a three digit hex to six.
	 *
	 * The field carries pattern="#[0-9a-fA-F]{6}", which lies dormant while the input is
	 * type="color" and arms itself the moment this script converts it to text. HivePress enforces
	 * the same pattern server-side, where a rejected value returns the normal "saved" redirect and
	 * silently keeps the old colour - indistinguishable from the change not taking. Since
	 * sanitize_hex_color() accepts #fff but the pattern does not, shorthand is expanded here rather
	 * than left to fail.
	 *
	 * @param {string} value Colour value.
	 * @return {string}
	 */
	function expandHex( value ) {
		var match = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i.exec( ( value || '' ).trim() );

		if ( ! match ) {
			return value;
		}

		return '#' + match[1] + match[1] + match[2] + match[2] + match[3] + match[3];
	}

	window.jQuery( function( $ ) {

		/* Colour pickers
		   ---------------------------------------------------------------- */

		if ( $.fn.wpColorPicker ) {

			// Matched by shape rather than by name. The list used to be spelled out, and adding a
			// third colour setting left it with a plain text box while the other two had pickers -
			// a difference nobody would read as "the developer forgot to update a selector".
			$( 'input[name^="hp_email_studio_"][name$="_color"]' ).each( function() {
				var input = this;

				// Whether the admin has actually chosen a colour. A native colour input reports
				// "#000000" when it is empty, so the server-rendered attribute is the only honest
				// source: it is absent or empty until someone picks something.
				var wasEmpty = '' === ( input.getAttribute( 'value' ) || '' );

				// Iris works on text inputs; a native colour input has to be converted first,
				// keeping its current value.
				if ( 'color' === input.type ) {
					try {
						input.type = 'text';

						if ( wasEmpty ) {
							input.value = '';
						}
					} catch ( e ) {
						return;
					}
				}

				// Constraint validation runs before any submit handler, so the expansion cannot
				// wait for submit: it happens as the value settles, and on Enter before the form
				// is sent.
				$( input ).on( 'change blur', function() {
					var expanded = expandHex( input.value );

					if ( expanded !== input.value ) {
						input.value = expanded;
					}
				} ).on( 'keydown', function( event ) {
					if ( 13 === event.which ) {
						input.value = expandHex( input.value );
					}
				} );

				$( input ).wpColorPicker();

				// Iris seeds an empty field with its own starting colour, black. Saving the
				// settings page without touching the field would then store #000000 - so a colour
				// nobody asked for becomes the design's accent simply by pressing Save. Blank it
				// again after the picker has initialised, and keep it blank until the admin
				// actually picks.
				//
				// "Actually picks" is detected on irischange, because that is the only signal Iris
				// gives: it writes every palette, square and strip pick into the input with jQuery
				// .val(), which fires no DOM event at all, so an input/change listener misses the
				// picker's primary interaction entirely.
				if ( wasEmpty ) {
					input.value = '';

					var chosen = false;

					$( input ).on( 'irischange', function() {
						chosen = true;
					} );

					$( input ).closest( '.wp-picker-container' ).on( 'click', '.wp-color-result, .iris-picker, .wp-picker-clear', function() {
						chosen = true;
					} );

					$( input ).closest( 'form' ).on( 'submit', function() {
						if ( ! chosen && '#000000' === input.value.toLowerCase() ) {
							input.value = '';
						}
					} );
				}
			} );
		}

		/* Logo picker
		   ---------------------------------------------------------------- */

		var logo = $( 'input[name="hp_email_studio_logo"]' );

		if ( ! logo.length || ! window.wp || ! window.wp.media ) {
			return;
		}

		var button = $( '<button type="button" class="button" />' ).text( strings.chooseText || 'Choose image' );

		// type="button" matters: the field sits inside the settings form, and a button with no type
		// defaults to submit, so clicking it would save the page instead of opening the library.
		logo.after( ' ', button );

		var media = null;

		button.on( 'click', function() {
			if ( ! media ) {
				media = window.wp.media( {
					title: strings.chooseLogo || 'Choose logo image',
					library: { type: 'image' },
					button: { text: strings.useImage || 'Use this image' },
					multiple: false
				} );

				media.on( 'select', function() {
					var attachment = media.state().get( 'selection' ).first();

					if ( ! attachment ) {
						return;
					}

					var url = attachment.get( 'url' );

					if ( url ) {
						logo.val( url ).trigger( 'change' );
					}
				} );
			}

			media.open();
		} );
	} );
}() );
