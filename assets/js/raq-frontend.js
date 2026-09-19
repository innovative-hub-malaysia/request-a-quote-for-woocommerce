/**
 * Request a Quote - front-end behaviour.
 *
 * Wires the (Stage 1) Add-to-Quote buttons to the quote list: AJAX add,
 * the mini-cart drawer, qty/remove, the GA4 events, and where a submitted
 * form goes next (countdown home / a page / a URL - Quotes > Settings).
 *
 * Depends on jQuery (WooCommerce ships it) and the localised `raqData`.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.raqData === 'undefined' ) {
		return;
	}

	var cfg = window.raqData;

	/* ---------------------------------------------------------------- *
	 * Helpers
	 * ---------------------------------------------------------------- */

	function post( action, data ) {
		return $.ajax( {
			url: cfg.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $.extend( { action: 'raq_' + action, nonce: cfg.nonce }, data ),
		} );
	}

	function setCount( count ) {
		$( '.raq-count' ).each( function () {
			var $c = $( this );
			$c.text( count );
			$c.toggleClass( 'raq-count--empty', ! ( count > 0 ) );
		} );
		// Grey out the drawer CTA when the quote list is empty.
		$( '.raq-drawer' ).toggleClass( 'is-empty', ! ( count > 0 ) );
	}

	function paintDrawer( html ) {
		$( '.raq-drawer__body' ).html( html );
	}

	function openDrawer() {
		$( '.raq-overlay' ).prop( 'hidden', false );
		// next tick so the transition runs.
		window.requestAnimationFrame( function () {
			$( '.raq-overlay' ).addClass( 'is-open' );
			$( '.raq-drawer' ).addClass( 'is-open' ).attr( 'aria-hidden', 'false' );
		} );
	}

	function closeDrawer() {
		$( '.raq-overlay' ).removeClass( 'is-open' );
		$( '.raq-drawer' ).removeClass( 'is-open' ).attr( 'aria-hidden', 'true' );
		window.setTimeout( function () {
			$( '.raq-overlay' ).prop( 'hidden', true );
		}, 280 );
	}

	var toastTimer = null;
	function toast( message, isError ) {
		var $t = $( '.raq-toast' );
		if ( ! $t.length ) {
			$t = $( '<div class="raq-toast" role="status" aria-live="polite"></div>' ).appendTo( 'body' );
		}
		$t.text( message ).toggleClass( 'raq-toast--error', !! isError ).addClass( 'is-open' );
		window.clearTimeout( toastTimer );
		toastTimer = window.setTimeout( function () {
			$t.removeClass( 'is-open' );
		}, 2600 );
	}

	// `done` (optional) is called once the event has been handed off - gtag's
	// event_callback / GTM's eventCallback - or after `timeout` ms, whichever
	// comes first, and never more than once. A page that navigates away the
	// instant it fires an event can lose the hit; the redirect waits on this.
	function fireGA4( eventName, params, done, timeout ) {
		var finished = false;
		var timer    = null;
		function finish() {
			if ( finished ) {
				return;
			}
			finished = true;
			window.clearTimeout( timer );
			if ( typeof done === 'function' ) {
				done();
			}
		}
		if ( ! cfg.ga4 || ! cfg.ga4.enabled ) {
			finish();
			return;
		}
		params = $.extend( {}, params || {} );
		var waiting = typeof done === 'function';
		if ( waiting ) {
			timeout = timeout || 300;
			timer   = window.setTimeout( finish, timeout );
		}
		// The callback keys are added only on the branch that reads them, so
		// neither ends up as a stray event parameter in GA4.
		var gtagParams = waiting ? $.extend( { event_callback: finish, event_timeout: timeout }, params ) : params;
		var dlParams   = waiting ? $.extend( { eventCallback: finish, eventTimeout: timeout }, params ) : params;
		// When we configured a Measurement ID we loaded gtag ourselves - go
		// through gtag('event', ...) (pushing {event} to gtag's dataLayer would
		// not register). Otherwise ride the site's existing GTM dataLayer.
		if ( cfg.ga4.measurementId && typeof window.gtag === 'function' ) {
			window.gtag( 'event', eventName, gtagParams );
		} else if ( window.dataLayer && typeof window.dataLayer.push === 'function' ) {
			window.dataLayer.push( $.extend( { event: eventName }, dlParams ) );
		} else if ( typeof window.gtag === 'function' ) {
			window.gtag( 'event', eventName, gtagParams );
		} else {
			finish();
		}
	}

	/* ---------------------------------------------------------------- *
	 * Read a product context off a clicked button
	 * ---------------------------------------------------------------- */

	function readContext( $btn ) {
		var ctx = {
			product_id: parseInt( $btn.data( 'product_id' ), 10 ) || parseInt( $btn.data( 'productId' ), 10 ) || 0,
			variation_id: 0,
			quantity: 1,
			variation: {},
		};

		// Native theme buttons carry the id in the button value.
		if ( ! ctx.product_id ) {
			ctx.product_id = parseInt( $btn.val(), 10 ) || 0;
		}

		var $form = $btn.closest( '.variations_form, .raq-quote-form, form.cart' );

		if ( $form.length ) {
			if ( ! ctx.product_id ) {
				ctx.product_id =
					parseInt( $form.find( 'input[name="product_id"]' ).val(), 10 ) ||
					parseInt( $form.find( '[name="add-to-cart"]' ).val(), 10 ) ||
					0;
			}
			var qty = parseInt( $form.find( 'input.qty, input[name="quantity"]' ).first().val(), 10 );
			if ( qty > 0 ) {
				ctx.quantity = qty;
			}
		}

		// Variable product: pull the chosen variation + attributes.
		if ( $form.hasClass( 'variations_form' ) ) {
			ctx.variation_id = parseInt( $form.find( 'input.variation_id, input[name="variation_id"]' ).val(), 10 ) || 0;
			$form.find( '.variations select, .variations input' ).each( function () {
				var name = $( this ).attr( 'name' );
				if ( name && name.indexOf( 'attribute_' ) === 0 ) {
					ctx.variation[ name ] = $( this ).val();
				}
			} );
			if ( ! ctx.variation_id ) {
				return null; // options not chosen yet.
			}
		}

		return ctx;
	}

	// Shared add flow, used by our own buttons and intercepted native buttons.
	function sendAdd( $btn ) {
		if ( $btn.hasClass( 'is-busy' ) ) {
			return;
		}
		var ctx = readContext( $btn );
		if ( ctx === null ) {
			toast( cfg.i18n.chooseOpts, true );
			return;
		}
		if ( ! ctx.product_id ) {
			return;
		}

		$btn.addClass( 'is-busy' );

		post( 'add', ctx )
			.done( function ( res ) {
				if ( res && res.success ) {
					setCount( res.data.count );
					paintDrawer( res.data.drawer );
					fireGA4( 'add_to_quote', {
						items: [ { item_id: ctx.variation_id || ctx.product_id, quantity: ctx.quantity } ],
					} );
					toast( cfg.i18n.added );
					openDrawer();
				} else {
					var msg = res && res.data && res.data.message ? res.data.message : cfg.i18n.error;
					toast( msg, true );
					if ( res && res.data && res.data.require_login ) {
						toast( cfg.i18n.loginFirst, true );
					}
				}
			} )
			.fail( function () {
				toast( cfg.i18n.error, true );
			} )
			.always( function () {
				$btn.removeClass( 'is-busy' );
			} );
	}

	/* ---------------------------------------------------------------- *
	 * Events
	 * ---------------------------------------------------------------- */

	// Our own (loop) buttons.
	$( document ).on( 'click', '.raq-add-to-quote', function ( e ) {
		// Variable product on a list page has no options picker here - let the
		// link carry the shopper to the product page to choose options.
		if ( $( this ).hasClass( 'raq-needs-options' ) && this.getAttribute( 'href' ) ) {
			return;
		}
		e.preventDefault();
		sendAdd( $( this ) );
	} );

	// Native theme add-to-cart buttons (incl. Divi Theme Builder) - intercept
	// in the CAPTURE phase so we run before WooCommerce's own click handler,
	// keeping the button wherever the theme placed it.
	document.addEventListener(
		'click',
		function ( e ) {
			if ( ! e.target || ! e.target.closest ) {
				return;
			}
			var el = e.target.closest( '.single_add_to_cart_button, .add_to_cart_button' );
			if ( ! el || el.classList.contains( 'raq-add-to-quote' ) ) {
				return;
			}
			// Variable product with nothing selected yet - let WooCommerce speak.
			if ( el.classList.contains( 'disabled' ) || el.classList.contains( 'wc-variation-selection-needed' ) ) {
				return;
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			sendAdd( $( el ) );
		},
		true
	);

	// Open / close the drawer.
	$( document ).on( 'click', '.raq-quote-toggle, .raq-fab', function ( e ) {
		e.preventDefault();
		openDrawer();
	} );
	$( document ).on( 'click', '.raq-drawer__close, .raq-overlay', function ( e ) {
		e.preventDefault();
		closeDrawer();
	} );
	$( document ).on( 'keyup', function ( e ) {
		if ( e.key === 'Escape' ) {
			closeDrawer();
		}
	} );

	// Change quantity in the drawer.
	$( document ).on( 'change', '.raq-qty', function () {
		var $line = $( this ).closest( '.raq-line' );
		post( 'update', { key: $line.data( 'key' ), quantity: parseInt( $( this ).val(), 10 ) || 0 } ).done(
			function ( res ) {
				if ( res && res.success ) {
					setCount( res.data.count );
					paintDrawer( res.data.drawer );
				}
			}
		);
	} );

	// Remove a line.
	$( document ).on( 'click', '.raq-remove', function ( e ) {
		e.preventDefault();
		var $line = $( this ).closest( '.raq-line' );
		post( 'remove', { key: $line.data( 'key' ) } ).done( function ( res ) {
			if ( res && res.success ) {
				setCount( res.data.count );
				paintDrawer( res.data.drawer );
			}
		} );
	} );

	/* ---------------------------------------------------------------- *
	 * Quote form submission (page + drawer), with reCAPTCHA v3
	 * ---------------------------------------------------------------- */

	function getRecaptchaToken() {
		var site = cfg.recaptchaSite;
		if ( ! site || typeof window.grecaptcha === 'undefined' ) {
			return $.Deferred().resolve( '' ).promise();
		}
		var dfd = $.Deferred();
		window.grecaptcha.ready( function () {
			window.grecaptcha
				.execute( site, { action: 'quote' } )
				.then( function ( token ) {
					dfd.resolve( token );
				}, function () {
					dfd.resolve( '' );
				} );
		} );
		return dfd.promise();
	}

	var CHECK_SVG =
		'<svg viewBox="0 0 24 24" width="46" height="46" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';

	// Countdown mode (the default): replace the form with a thank-you panel
	// that counts down and returns to the homepage, plus a manual button.
	// 0 seconds = stay on the panel.
	function showThankYouCountdown( $form, message ) {
		var secs = parseInt( cfg.redirectSecs, 10 );
		if ( isNaN( secs ) || secs < 0 ) {
			secs = 5;
		}
		var count = secs > 0
			? '<p class="raq-thankyou__count">' + cfg.i18n.redirecting + ' <span class="raq-countdown">' + secs + '</span> ' + cfg.i18n.seconds + '</p>'
			: '';
		var html =
			'<div class="raq-thankyou" role="status" tabindex="-1">' +
			'<div class="raq-thankyou__icon">' + CHECK_SVG + '</div>' +
			'<h3>' + cfg.i18n.thankTitle + '</h3>' +
			'<p>' + ( message || '' ) + '</p>' +
			count +
			'</div>';
		var $panel = $( html ).append( linkButton( cfg.homeUrl, cfg.i18n.backHome ) );
		swapIn( $form, $panel );

		if ( secs <= 0 ) {
			return;
		}
		var n = secs;
		var timer = window.setInterval( function () {
			n--;
			$( '.raq-countdown' ).text( n < 0 ? 0 : n );
			if ( n <= 0 ) {
				window.clearInterval( timer );
				window.location.href = cfg.homeUrl;
			}
		}, 1000 );
	}

	// Redirect modes (page / url): the form becomes a "sent, taking you there"
	// panel with a spinner the moment the server confirms, so the visitor is
	// never left staring at a disabled button while the analytics hit is
	// handed off and the next page loads. A Continue link covers a stalled
	// navigation (slow network, a blocked script).
	function showThankYouRedirect( $form, target ) {
		var html =
			'<div class="raq-thankyou raq-thankyou--redirect" role="status" tabindex="-1">' +
			'<div class="raq-thankyou__icon">' + CHECK_SVG + '</div>' +
			'<h3>' + cfg.i18n.thankTitle + '</h3>' +
			'<p>' + cfg.i18n.sent + '</p>' +
			'<p class="raq-thankyou__wait"><span class="raq-spinner" aria-hidden="true"></span> ' + cfg.i18n.takingYou + '</p>' +
			'</div>';
		var $panel = $( html ).append( linkButton( target, cfg.i18n.continueBtn ) );
		swapIn( $form, $panel );
	}

	// Replace the form with a panel and move focus onto it: the focused submit
	// button is being removed, and without this a keyboard or screen-reader
	// user is dropped onto <body> with no position and no announcement.
	function swapIn( $form, $panel ) {
		$form.replaceWith( $panel );
		$panel.trigger( 'focus' );
	}

	// A panel button built via attributes, never string-concatenated: the
	// target URL carries the quote reference, which an admin-set prefix shapes.
	function linkButton( href, label ) {
		return $( '<a>', { href: href, 'class': 'raq-request-quote raq-home-btn' } ).text( label );
	}

	// Fill the reference slot on a [raq_thank_you] page from ?raq_ref=. Done
	// client-side so the reference never enters cacheable HTML.
	function fillThankYouRef() {
		var $slot = $( '.raq-thankyou--page .raq-thankyou__ref' );
		if ( ! $slot.length ) {
			return;
		}
		var m = /[?&]raq_ref=([^&#]*)/.exec( window.location.search );
		var ref = '';
		try {
			ref = m ? decodeURIComponent( m[ 1 ].replace( /\+/g, ' ' ) ) : '';
		} catch ( e ) {
			return;
		}
		if ( ref && /^[\w .-]{1,40}$/.test( ref ) ) {
			$slot.find( 'strong' ).text( ref );
			$slot.prop( 'hidden', false );
		}
	}
	$( fillThankYouRef );

	function submitForm( $form ) {
		var $msg = $form.find( '.raq-form__msg' );
		// Required searchable dropdowns first (hidden inputs skip HTML5 checks).
		if ( ! ddValidate( $form ) ) {
			$msg.addClass( 'raq-form__msg--error' ).text( cfg.i18n.chooseRequired || 'Please complete the required fields.' );
			return;
		}
		// Native HTML5 validation next (required fields, valid email, etc.).
		var formEl = $form.get( 0 );
		if ( formEl && typeof formEl.checkValidity === 'function' && ! formEl.checkValidity() ) {
			if ( typeof formEl.reportValidity === 'function' ) {
				formEl.reportValidity();
			}
			return;
		}

		var $btn = $form.find( '.raq-submit' );
		$btn.prop( 'disabled', true ).addClass( 'is-busy' );
		$msg.removeClass( 'raq-form__msg--error' ).text( cfg.i18n.sending );

		getRecaptchaToken().then( function ( token ) {
			$form.find( 'input[name="raq_recaptcha_token"]' ).val( token );

			var fd = new FormData( $form.get( 0 ) );
			fd.set( 'action', 'raq_submit' );
			fd.set( 'nonce', cfg.nonce );

			$.ajax( {
				url: cfg.ajaxUrl,
				method: 'POST',
				data: fd,
				processData: false,
				contentType: false,
				dataType: 'json',
			} )
				.done( function ( res ) {
					if ( res && res.success ) {
						setCount( res.data.count || 0 );
						if ( res.data.drawer ) {
							paintDrawer( res.data.drawer );
						}
						// quote_submitted mapped to the GA4 standard generate_lead
						// (the Key Event / conversion), consistent with our other
						// IH lead sources. Prices are hidden, so no value is sent.
						var lead = { lead_source: 'request_a_quote', method: 'quote_form' };
						// The server resolves mode + destination per submit (it
						// knows the reference, and it is never page-cached); the
						// localized values are the fallback.
						var mode = res.data.after || cfg.afterSubmit || 'countdown';
						var target = ( res.data.redirect && String( res.data.redirect ) ) || cfg.redirectUrl || cfg.homeUrl;
						if ( mode === 'page' || mode === 'url' ) {
							showThankYouRedirect( $form, target );
							fireGA4( 'generate_lead', lead, function () {
								window.location.href = target;
							} );
						} else {
							fireGA4( 'generate_lead', lead );
							showThankYouCountdown( $form, res.data.message );
						}
					} else {
						var m = res && res.data && res.data.message ? res.data.message : cfg.i18n.error;
						$msg.addClass( 'raq-form__msg--error' ).text( m );
						$btn.prop( 'disabled', false ).removeClass( 'is-busy' );
					}
				} )
				.fail( function () {
					$msg.addClass( 'raq-form__msg--error' ).text( cfg.i18n.error );
					$btn.prop( 'disabled', false ).removeClass( 'is-busy' );
				} );
		} );
	}

	$( document ).on( 'submit', '.raq-quote-form-full', function ( e ) {
		e.preventDefault();
		submitForm( $( this ) );
	} );

	/* ---------------------------------------------------------------- *
	 * Searchable dropdown (.raq-dd) - phone country code + Country field.
	 *
	 * Markup comes from RAQ_Form::dropdown_html(). Open = show the panel and
	 * focus its search box; typing filters the options by data-search
	 * (name + code); Enter/click picks; Esc or an outside click closes. The
	 * choice lives in the hidden .raq-dd__value input, so the form posts
	 * exactly what a native <select> would.
	 * ---------------------------------------------------------------- */
	function ddClose( $dd ) {
		$dd.find( '.raq-dd__panel' ).prop( 'hidden', true );
		$dd.find( '.raq-dd__toggle' ).attr( 'aria-expanded', 'false' );
		ddCursor( $dd, null );
	}

	function ddCloseAll() {
		$( '.raq-dd' ).each( function () {
			ddClose( $( this ) );
		} );
	}

	// Matches at the start of any word, so "ma" finds Malaysia but not Oman or
	// Germany, and "60" / "+60" find the code. Brackets and slashes count as
	// word breaks ("uk" finds "United Kingdom (UK)", "ivoire" finds "Côte d'Ivoire").
	function ddWords( str ) {
		return ' ' + $.trim( String( str || '' ).toLowerCase().replace( /[()\/\-'&,]/g, ' ' ).replace( /\s+/g, ' ' ) );
	}

	// The keyboard cursor: one visible row carries .is-active, and the search
	// box (the focused element) points at it via aria-activedescendant.
	function ddCursor( $dd, $opt ) {
		$dd.find( '.raq-dd__opt.is-active' ).removeClass( 'is-active' );
		var $search = $dd.find( '.raq-dd__search' );
		if ( ! $opt || ! $opt.length ) {
			$search.removeAttr( 'aria-activedescendant' );
			return;
		}
		$opt.addClass( 'is-active' );
		$search.attr( 'aria-activedescendant', $opt.attr( 'id' ) );
		// Keep the row in view inside the list only - scrollIntoView would
		// also scroll the page and the drawer.
		var list = $dd.find( '.raq-dd__list' ).get( 0 );
		var row = $opt.get( 0 );
		if ( list && row ) {
			if ( row.offsetTop < list.scrollTop ) {
				list.scrollTop = row.offsetTop;
			} else if ( row.offsetTop + row.offsetHeight > list.scrollTop + list.clientHeight ) {
				list.scrollTop = row.offsetTop + row.offsetHeight - list.clientHeight;
			}
		}
	}

	// Filter the rows by the query; the cursor lands on $cursor when it is
	// still visible, else on the first visible row.
	function ddFilter( $dd, query, $cursor ) {
		var q = ddWords( query );
		var shown = 0;
		$dd.find( '.raq-dd__opt' ).each( function () {
			var hit = q === ' ' || ddWords( $( this ).attr( 'data-search' ) ).indexOf( q ) !== -1;
			$( this ).prop( 'hidden', ! hit );
			if ( hit ) {
				shown++;
			}
		} );
		$dd.find( '.raq-dd__empty' ).prop( 'hidden', shown > 0 );
		if ( ! $cursor || ! $cursor.length || $cursor.prop( 'hidden' ) ) {
			$cursor = $dd.find( '.raq-dd__opt:not([hidden])' ).first();
		}
		ddCursor( $dd, $cursor );
	}

	function ddOpen( $dd ) {
		ddCloseAll();
		var $search = $dd.find( '.raq-dd__search' );
		$search.val( '' );
		$dd.find( '.raq-dd__panel' ).prop( 'hidden', false );
		$dd.find( '.raq-dd__toggle' ).attr( 'aria-expanded', 'true' );
		// The cursor starts on the current choice, not the first row.
		ddFilter( $dd, '', $dd.find( '.raq-dd__opt.is-selected' ) );
		$search.trigger( 'focus' );
	}

	function ddChoose( $dd, $opt ) {
		var val = String( $opt.attr( 'data-value' ) );
		$dd.attr( 'data-value', val ).toggleClass( 'is-empty', val === '' );
		$dd.find( '.raq-dd__value' ).val( val );
		$dd.find( '.raq-dd__toggle .raq-dd__flag' ).text( $opt.attr( 'data-flag' ) || '' );
		$dd.find( '.raq-dd__toggle .raq-dd__text' ).text( $opt.attr( 'data-short' ) || $opt.find( '.raq-dd__label' ).text() );
		$dd.find( '.raq-dd__opt' ).removeClass( 'is-selected' ).attr( 'aria-selected', 'false' );
		$opt.addClass( 'is-selected' ).attr( 'aria-selected', 'true' );
		$dd.removeClass( 'is-invalid' );
		ddClose( $dd );
		$dd.find( '.raq-dd__toggle' ).trigger( 'focus' );
	}

	$( document ).on( 'click', '.raq-dd__toggle', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		var $dd = $( this ).closest( '.raq-dd' );
		if ( $dd.find( '.raq-dd__panel' ).prop( 'hidden' ) ) {
			ddOpen( $dd );
		} else {
			ddClose( $dd );
		}
	} );

	$( document ).on( 'click', '.raq-dd__opt', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		ddChoose( $( this ).closest( '.raq-dd' ), $( this ) );
	} );

	// Clicks inside the panel (the search box, scrollbar, empty row) must not
	// reach the document handler that closes every dropdown.
	$( document ).on( 'click', '.raq-dd__panel', function ( e ) {
		e.stopPropagation();
	} );

	$( document ).on( 'input', '.raq-dd__search', function () {
		ddFilter( $( this ).closest( '.raq-dd' ), this.value );
	} );

	$( document ).on( 'keydown', '.raq-dd', function ( e ) {
		var $dd = $( this );
		var open = ! $dd.find( '.raq-dd__panel' ).prop( 'hidden' );
		var $visible, idx, $active;

		if ( e.key === 'Escape' ) {
			if ( open ) {
				e.preventDefault();
				ddClose( $dd );
				$dd.find( '.raq-dd__toggle' ).trigger( 'focus' );
			}
			return;
		}
		if ( ! open ) {
			// Arrow keys on the closed toggle open it (native <select> habit).
			if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
				e.preventDefault();
				ddOpen( $dd );
			}
			return;
		}
		if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
			e.preventDefault();
			$visible = $dd.find( '.raq-dd__opt:not([hidden])' );
			if ( ! $visible.length ) {
				return;
			}
			idx = $visible.index( $visible.filter( '.is-active' ) );
			idx = e.key === 'ArrowDown' ? Math.min( idx + 1, $visible.length - 1 ) : Math.max( idx - 1, 0 );
			ddCursor( $dd, $visible.eq( idx ) );
		} else if ( e.key === 'Enter' ) {
			// Never let Enter in the search box submit the form.
			e.preventDefault();
			$active = $dd.find( '.raq-dd__opt.is-active:not([hidden])' ).first();
			if ( $active.length ) {
				ddChoose( $dd, $active );
			}
		} else if ( e.key === 'Tab' ) {
			ddClose( $dd );
		}
	} );

	$( document ).on( 'click', function () {
		if ( $( '.raq-dd__panel:not([hidden])' ).length ) {
			ddCloseAll();
		}
	} );

	// Required dropdowns: the value travels in a hidden input, which HTML5
	// validation skips, so the form's submit handler asks here first.
	function ddValidate( $form ) {
		var ok = true;
		$form.find( '.raq-dd[data-required]' ).each( function () {
			var $dd = $( this );
			var empty = ! $dd.find( '.raq-dd__value' ).val();
			$dd.toggleClass( 'is-invalid', empty );
			if ( empty && ok ) {
				ok = false;
				$dd.find( '.raq-dd__toggle' ).trigger( 'focus' );
			}
		} );
		return ok;
	}

	// Phone inputs: digits only - strip letters, spaces and symbols as typed
	// (covers typing, paste and autofill). Server validates again.
	$( document ).on( 'input', '.raq-phone input[type="tel"]', function () {
		var clean = this.value.replace( /[^0-9]/g, '' );
		if ( this.value !== clean ) {
			this.value = clean;
		}
	} );

	/* ---------------------------------------------------------------- *
	 * Display tweaks: relabel native buttons + honour the qty setting
	 * ---------------------------------------------------------------- */

	function applyDisplayTweaks() {
		$( '.single_add_to_cart_button, .add_to_cart_button' )
			.not( '.raq-add-to-quote' )
			.each( function () {
				var $b = $( this );
				// Only relabel plain-text buttons so we never wipe theme markup.
				if ( $b.children().length === 0 && cfg.buttonLabel ) {
					$b.text( cfg.buttonLabel );
				}
			} );

		if ( ! cfg.qtySelector ) {
			$( 'form.cart .quantity, .raq-quote-form .quantity' ).hide();
		}
	}

	/* ---------------------------------------------------------------- *
	 * Hydration
	 *
	 * The footer markup ships EMPTY and identical for every visitor, because
	 * wp_footer output is part of the cached page. Printing the real count
	 * server-side leaked one visitor's quote list to the next on a page-cached
	 * site. So the count and the drawer lines are fetched here, once, on load.
	 *
	 * The raq_refresh endpoint has always existed; nothing called it.
	 * ---------------------------------------------------------------- */

	function hydrate() {
		// Nothing to hydrate if the footer was never rendered.
		if ( ! $( '.raq-count, .raq-drawer__body, [data-raq-summary]' ).length ) {
			return;
		}
		post( 'refresh', {} )
			.done( function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					return;
				}
				setCount( res.data.count );
				if ( typeof res.data.drawer === 'string' ) {
					paintDrawer( res.data.drawer );
				}
				if ( typeof res.data.summary === 'string' ) {
					$( '[data-raq-summary]' ).html(
						res.data.summary ||
							$( '[data-raq-summary]' ).find( '.raq-quote-page__empty' ).prop( 'outerHTML' ) ||
							''
					);
				}
				$( '.raq-drawer__body' ).removeAttr( 'data-raq-hydrate' );
			} );
		// A failed refresh leaves the empty state showing, which is the safe
		// direction to fail in: it never shows another visitor's items.
	}

	$( function () {
		applyDisplayTweaks();
		hydrate();
	} );
	// WooCommerce re-renders the variable-product button via ajax; re-apply.
	$( document ).on( 'found_variation reset_data show_variation', applyDisplayTweaks );
} )( jQuery );
