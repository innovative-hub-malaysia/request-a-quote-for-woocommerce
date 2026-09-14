/**
 * Request a Quote - front-end behaviour.
 *
 * Wires the (Stage 1) Add-to-Quote buttons to the quote list: AJAX add,
 * the mini-cart drawer, qty/remove, and the GA4 add_to_quote event.
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

	function fireGA4( eventName, params ) {
		if ( ! cfg.ga4 || ! cfg.ga4.enabled ) {
			return;
		}
		params = params || {};
		// When we configured a Measurement ID we loaded gtag ourselves - go
		// through gtag('event', ...) (pushing {event} to gtag's dataLayer would
		// not register). Otherwise ride the site's existing GTM dataLayer.
		if ( cfg.ga4.measurementId && typeof window.gtag === 'function' ) {
			window.gtag( 'event', eventName, params );
		} else if ( window.dataLayer && typeof window.dataLayer.push === 'function' ) {
			window.dataLayer.push( $.extend( { event: eventName }, params ) );
		} else if ( typeof window.gtag === 'function' ) {
			window.gtag( 'event', eventName, params );
		}
	}

	/* ---------------------------------------------------------------- *
	 * Read a product context off a clicked button
	 * ---------------------------------------------------------------- */

	function readContext( $btn ) {
		var ctx = {
			product_id: parseInt( $btn.data( 'product_id' ), 10 ) || 0,
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

	// Replace the form with a thank-you panel that counts down and redirects
	// to the homepage (plus a manual "Back to homepage" button).
	function showThankYou( $form, message ) {
		var secs = parseInt( cfg.redirectSecs, 10 ) || 5;
		var check =
			'<svg viewBox="0 0 24 24" width="46" height="46" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
		var html =
			'<div class="raq-thankyou">' +
			'<div class="raq-thankyou__icon">' + check + '</div>' +
			'<h3>' + cfg.i18n.thankTitle + '</h3>' +
			'<p>' + ( message || '' ) + '</p>' +
			'<p class="raq-thankyou__count">' + cfg.i18n.redirecting + ' <span class="raq-countdown">' + secs + '</span> ' + cfg.i18n.seconds + '</p>' +
			'<a href="' + cfg.homeUrl + '" class="raq-request-quote raq-home-btn">' + cfg.i18n.backHome + '</a>' +
			'</div>';
		$form.replaceWith( html );

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

	function submitForm( $form ) {
		// Native HTML5 validation first (required fields, valid email, etc.).
		var formEl = $form.get( 0 );
		if ( formEl && typeof formEl.checkValidity === 'function' && ! formEl.checkValidity() ) {
			if ( typeof formEl.reportValidity === 'function' ) {
				formEl.reportValidity();
			}
			return;
		}

		var $btn = $form.find( '.raq-submit' );
		var $msg = $form.find( '.raq-form__msg' );
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
						// quote_submitted mapped to the GA4 standard generate_lead
						// (the Key Event / conversion), consistent with our other
						// IH lead sources. Prices are hidden, so no value is sent.
						fireGA4( 'generate_lead', {
							lead_source: 'request_a_quote',
							method: 'quote_form',
						} );
						setCount( res.data.count || 0 );
						if ( res.data.drawer ) {
							paintDrawer( res.data.drawer );
						}
						showThankYou( $form, res.data.message );
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
	 * Country-code dropdown (closed = flag + code, open = country names)
	 * ---------------------------------------------------------------- */
	$( document ).on( 'click', '.raq-cc__toggle', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		var $list = $( this ).siblings( '.raq-cc__list' );
		var isOpen = ! $list.prop( 'hidden' );
		$( '.raq-cc__list' ).prop( 'hidden', true );
		$( '.raq-cc__toggle' ).attr( 'aria-expanded', 'false' );
		if ( ! isOpen ) {
			$list.prop( 'hidden', false );
			$( this ).attr( 'aria-expanded', 'true' );
		}
	} );

	$( document ).on( 'click', '.raq-cc__opt', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		var $opt = $( this );
		var $cc = $opt.closest( '.raq-cc' );
		var val = String( $opt.attr( 'data-value' ) );
		$cc.attr( 'data-value', val );
		$cc.find( '.raq-cc__value' ).val( val );
		$cc.find( '.raq-cc__toggle .raq-cc__flag' ).text( $opt.attr( 'data-flag' ) );
		$cc.find( '.raq-cc__toggle .raq-cc__code' ).text( val );
		$cc.find( '.raq-cc__list' ).prop( 'hidden', true );
		$cc.find( '.raq-cc__toggle' ).attr( 'aria-expanded', 'false' );
	} );

	$( document ).on( 'click', function () {
		$( '.raq-cc__list' ).prop( 'hidden', true );
		$( '.raq-cc__toggle' ).attr( 'aria-expanded', 'false' );
	} );

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
