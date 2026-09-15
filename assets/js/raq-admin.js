/**
 * Request a Quote - admin settings (Fields editor).
 * Add / remove / reorder the quote-form fields.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $table = $( '#raq-fields' );
		if ( ! $table.length ) {
			return;
		}

		// Reorder rows by dragging the handle. Order = row order on save.
		$table.find( 'tbody' ).sortable( {
			handle: '.raq-fh',
			axis: 'y',
			helper: function ( e, tr ) {
				// Lock cell widths while dragging so the row keeps its shape.
				var $originals = tr.children();
				var $helper = tr.clone();
				$helper.children().each( function ( i ) {
					$( this ).width( $originals.eq( i ).width() );
				} );
				return $helper;
			},
		} );

		var seq = 0;

		// Add a new field row from the template.
		$( '#raq-add-field' ).on( 'click', function ( e ) {
			e.preventDefault();
			seq++;
			var html = $( '#raq-field-tpl' ).html().replace( /__i__/g, 'new' + seq );
			$table.find( 'tbody' ).append( html );
		} );

		// Remove a row.
		$table.on( 'click', '.raq-remove-field', function ( e ) {
			e.preventDefault();
			$( this ).closest( 'tr' ).remove();
		} );
	} );

	// Show only the settings that apply to the chosen submission mode.
	$( function () {
		function toggleModeRows() {
			var mode = $( 'input[name="submit_mode"]:checked' ).val() || 'page';
			$( '.raq-when-page' ).toggle( mode === 'page' );
			$( '.raq-when-drawer' ).toggle( mode === 'drawer' );
		}
		if ( $( 'input[name="submit_mode"]' ).length ) {
			$( document ).on( 'change', 'input[name="submit_mode"]', toggleModeRows );
			toggleModeRows();
		}

		// Same for the After-submit choice: only the rows for the chosen
		// destination are shown.
		function toggleAfterRows() {
			var after = $( 'input[name="after_submit"]:checked' ).val() || 'countdown';
			$( '.raq-when-after-countdown' ).toggle( after === 'countdown' );
			$( '.raq-when-after-page' ).toggle( after === 'page' );
			$( '.raq-when-after-url' ).toggle( after === 'url' );
		}
		if ( $( 'input[name="after_submit"]' ).length ) {
			$( document ).on( 'change', 'input[name="after_submit"]', toggleAfterRows );
			toggleAfterRows();
		}

		// "Create a Thank-you page for me" leaves the form, so confirm first.
		$( document ).on( 'click', '.raq-create-thankyou', function ( e ) {
			if ( ! window.confirm( $( this ).data( 'confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	} );
} )( jQuery );
