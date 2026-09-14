<?php
/**
 * Front-end: assets, the header Quote button, and the Mini-Cart drawer.
 *
 * Loaded only when the Master Switch is ON. The drawer body markup is shared
 * between the initial PHP render and the AJAX refresh so there is one source
 * of truth for how a quote line looks.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Frontend
 */
class RAQ_Frontend {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_shortcode( 'raq_quote_button', array( __CLASS__, 'shortcode_button' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_footer' ) );
	}

	/**
	 * Register + enqueue front-end assets and hand data to the script.
	 */
	public static function enqueue() {
		wp_enqueue_style(
			'raq-frontend',
			RAQ_PLUGIN_URL . 'assets/css/raq-frontend.css',
			array(),
			RAQ_VERSION
		);

		$recaptcha_site = RAQ_Settings::get( 'recaptcha_site_key', '' );
		if ( $recaptcha_site ) {
			wp_enqueue_script(
				'raq-recaptcha',
				'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $recaptcha_site ),
				array(),
				null, // Google versions this URL itself.
				true
			);
		}

		// GA4 direct fallback: only when a Measurement ID is set (implies the
		// site has no GTM container of its own). If a GTM dataLayer exists, the
		// JS prefers it and this is not loaded.
		$ga4_id = RAQ_Settings::get( 'ga4_measurement_id', '' );
		if ( RAQ_Settings::get( 'ga4_enabled', true ) && $ga4_id ) {
			wp_enqueue_script(
				'raq-gtag',
				'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ga4_id ),
				array(),
				null,
				true
			);
			wp_add_inline_script(
				'raq-gtag',
				'window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}gtag("js", new Date());gtag("config", ' . wp_json_encode( $ga4_id ) . ');',
				'after'
			);
		}

		wp_enqueue_script(
			'raq-frontend',
			RAQ_PLUGIN_URL . 'assets/js/raq-frontend.js',
			array( 'jquery' ),
			RAQ_VERSION,
			true
		);

		wp_localize_script(
			'raq-frontend',
			'raqData',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'raq_nonce' ),
				'quoteUrl'      => self::quote_page_url(),
				'qtySelector'   => (bool) RAQ_Settings::get( 'qty_selector', true ),
				'buttonLabel'   => self::button_label_text(),
				'recaptchaSite' => $recaptcha_site,
				'homeUrl'       => home_url( '/' ),
				'redirectSecs'  => 5,
				'ga4'           => array(
					'enabled'       => (bool) RAQ_Settings::get( 'ga4_enabled', true ),
					'measurementId' => RAQ_Settings::get( 'ga4_measurement_id', '' ),
				),
				'i18n'          => array(
					'added'      => __( 'Added to your quote', 'request-a-quote-for-woocommerce' ),
					'error'      => __( 'Something went wrong. Please try again.', 'request-a-quote-for-woocommerce' ),
					'loginFirst' => __( 'Please log in to request a quote.', 'request-a-quote-for-woocommerce' ),
					'chooseOpts' => __( 'Please choose the product options first.', 'request-a-quote-for-woocommerce' ),
					'sending'    => __( 'Sending...', 'request-a-quote-for-woocommerce' ),
					'thankTitle' => __( 'Thank you!', 'request-a-quote-for-woocommerce' ),
					'redirecting' => __( 'Redirecting to the homepage in', 'request-a-quote-for-woocommerce' ),
					'seconds'    => __( 'seconds', 'request-a-quote-for-woocommerce' ),
					'backHome'   => __( 'Back to homepage', 'request-a-quote-for-woocommerce' ),
					'chooseRequired' => __( 'Please complete the required fields.', 'request-a-quote-for-woocommerce' ),
				),
			)
		);

		wp_add_inline_style( 'raq-frontend', self::inline_css() );
	}

	/**
	 * The configured Add-to-Quote label (used for the JS relabel of native
	 * theme buttons, e.g. Divi's hardcoded "Add to cart").
	 *
	 * @return string
	 */
	public static function button_label_text() {
		$label = RAQ_Settings::get( 'add_to_quote_label', '' );
		return $label ? $label : __( 'Add to Quote', 'request-a-quote-for-woocommerce' );
	}

	/**
	 * Build the inline CSS from the appearance settings (button colours + size,
	 * floating-button position, custom CSS).
	 *
	 * @return string
	 */
	public static function inline_css() {
		$bg   = RAQ_Settings::get( 'btn_bg', '#1c1a17' );
		$fg   = RAQ_Settings::get( 'btn_text', '#ffffff' );
		$size = RAQ_Settings::get( 'btn_size', 'medium' );

		$pad = array(
			'small'  => '6px 14px',
			'medium' => '10px 20px',
			'large'  => '15px 30px',
		);
		$fs  = array(
			'small'  => '13px',
			'medium' => '15px',
			'large'  => '17px',
		);
		$p = isset( $pad[ $size ] ) ? $pad[ $size ] : $pad['medium'];
		$f = isset( $fs[ $size ] ) ? $fs[ $size ] : $fs['medium'];

		// Feed the brand colour into the design-system tokens.
		$css = ':root{--raq-accent:' . $bg . ';--raq-accent-text:' . $fg . ';}';

		// Force the brand colour on every quote button (ours + native theme
		// buttons), beating the theme's .button styles.
		$btns = '.raq-add-to-quote,.raq-submit,.raq-request-quote,.raq-home-btn,.single_add_to_cart_button,.add_to_cart_button';
		$css .= $btns . '{background:' . $bg . ' !important;color:' . $fg . ' !important;border-color:' . $bg . ' !important;}';

		// Button size.
		$css .= $btns . '{padding:' . $p . ' !important;font-size:' . $f . ' !important;line-height:1.3 !important;}';

		// Floating button position.
		$fab_pos = array(
			'bottom-right' => 'right:20px;bottom:20px;top:auto;left:auto;',
			'bottom-left'  => 'left:20px;bottom:20px;top:auto;right:auto;',
			'top-right'    => 'right:20px;top:100px;bottom:auto;left:auto;',
			'top-left'     => 'left:20px;top:100px;bottom:auto;right:auto;',
			'hidden'       => 'display:none !important;',
		);
		$fab  = RAQ_Settings::get( 'fab_position', 'bottom-right' );
		$css .= '.raq-fab{' . ( isset( $fab_pos[ $fab ] ) ? $fab_pos[ $fab ] : $fab_pos['bottom-right'] ) . '}';

		// Custom CSS (already sanitised on save).
		$custom = RAQ_Settings::get( 'custom_css', '' );
		if ( $custom ) {
			$css .= "\n" . $custom;
		}

		return $css;
	}

	/**
	 * The Quote Page URL (falls back to home if unset).
	 *
	 * @return string
	 */
	public static function quote_page_url() {
		// Auto-detected from wherever the [raq_quote_form] shortcode renders.
		$page_id = (int) get_option( 'raq_quote_page_id', 0 );
		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}

	/**
	 * `[raq_quote_button]` - the header Quote icon + count badge.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode_button( $atts = array() ) {
		$atts  = shortcode_atts( array( 'label' => __( 'Quote', 'request-a-quote-for-woocommerce' ) ), $atts, 'raq_quote_button' );
		$count = RAQ_Quote_List::get_count();
		return sprintf(
			'<a href="#" class="raq-quote-toggle" role="button" aria-label="%1$s">
				<span class="raq-quote-toggle__icon" aria-hidden="true">%2$s</span>
				<span class="raq-quote-toggle__label">%3$s</span>
				<span class="raq-count%4$s">%5$d</span>
			</a>',
			esc_attr__( 'Open quote list', 'request-a-quote-for-woocommerce' ),
			self::icon_svg(),
			esc_html( $atts['label'] ),
			$count > 0 ? '' : ' raq-count--empty',
			(int) $count
		);
	}

	/**
	 * Inline SVG quote icon (no external assets).
	 *
	 * @return string
	 */
	public static function icon_svg() {
		return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="8" y1="13" x2="16" y2="13"></line><line x1="8" y1="17" x2="13" y2="17"></line></svg>';
	}

	/**
	 * Footer: the floating quote button (unless disabled) + the drawer + overlay.
	 * The floating button is the primary entry point; the `[raq_quote_button]`
	 * shortcode is an optional header alternative.
	 */
	public static function render_footer() {
		/*
		 * CACHE SAFETY - do not print the visitor's own count or lines here.
		 *
		 * wp_footer output is part of the page HTML, so a page cache (WP Rocket,
		 * LiteSpeed, Cloudflare APO, any of them) stores whatever the FIRST
		 * visitor saw and serves it to everyone else. Rendering the real count
		 * server-side therefore leaks one visitor's quote list to the next, and
		 * in the other direction leaves a returning visitor looking at a stale
		 * zero. Reproduced on a live WP Rocket site, 2026-09-09.
		 *
		 * So the markup ships EMPTY and identical for everybody, and the real
		 * state is fetched by raq_refresh on load. See hydrate() in
		 * assets/js/raq-frontend.js.
		 */
		$count       = 0;
		$drawer_mode = 'drawer' === RAQ_Settings::get( 'submit_mode', 'page' ) && class_exists( 'RAQ_Form' );
		$show_fab    = 'hidden' !== RAQ_Settings::get( 'fab_position', 'bottom-right' );
		?>
		<?php if ( $show_fab ) : ?>
			<button type="button" class="raq-fab" aria-label="<?php esc_attr_e( 'Open quote list', 'request-a-quote-for-woocommerce' ); ?>">
				<?php echo self::icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
				<span class="raq-count<?php echo $count > 0 ? '' : ' raq-count--empty'; ?>"><?php echo (int) $count; ?></span>
			</button>
		<?php endif; ?>

		<div class="raq-overlay" hidden></div>

		<aside class="raq-drawer<?php echo $count > 0 ? '' : ' is-empty'; ?>" aria-hidden="true" aria-label="<?php esc_attr_e( 'Your quote list', 'request-a-quote-for-woocommerce' ); ?>">
			<header class="raq-drawer__head">
				<h2 class="raq-drawer__title"><?php esc_html_e( 'Your Quote', 'request-a-quote-for-woocommerce' ); ?></h2>
				<button type="button" class="raq-drawer__close" aria-label="<?php esc_attr_e( 'Close', 'request-a-quote-for-woocommerce' ); ?>">&times;</button>
			</header>

			<div class="raq-drawer__scroll">
				<div class="raq-drawer__body" data-raq-hydrate="1">
					<?php // Deliberately the empty state: hydrated by raq_refresh on load. See the note in render_footer(). ?>
					<p class="raq-drawer__empty"><?php esc_html_e( 'Your quote list is empty.', 'request-a-quote-for-woocommerce' ); ?></p>
				</div>
				<?php if ( $drawer_mode ) : ?>
					<div class="raq-drawer__formwrap">
						<?php echo RAQ_Form::form_html( 'drawer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method. ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! $drawer_mode ) : ?>
				<footer class="raq-drawer__foot">
					<a href="<?php echo esc_url( self::quote_page_url() ); ?>" class="button raq-request-quote"><?php esc_html_e( 'Request a Quote', 'request-a-quote-for-woocommerce' ); ?></a>
				</footer>
			<?php endif; ?>
		</aside>
		<?php
	}

	/**
	 * The drawer body: the list of quote lines. Shared by the PHP render and
	 * the AJAX refresh. Every dynamic value is escaped here.
	 *
	 * @return string
	 */
	public static function drawer_body_html() {
		$items = RAQ_Quote_List::get_display_items();

		if ( empty( $items ) ) {
			return '<p class="raq-drawer__empty">' . esc_html__( 'Your quote list is empty.', 'request-a-quote-for-woocommerce' ) . '</p>';
		}

		$rows = '';
		foreach ( $items as $item ) {
			$name = $item['permalink']
				? sprintf( '<a href="%s">%s</a>', esc_url( $item['permalink'] ), esc_html( $item['name'] ) )
				: esc_html( $item['name'] );

			$meta = '';
			if ( ! empty( $item['variation'] ) ) {
				$bits = array();
				foreach ( $item['variation'] as $attr => $value ) {
					if ( '' === (string) $value ) {
						continue;
					}
					$label = wc_attribute_label( str_replace( 'attribute_', '', $attr ) );
					$bits[] = esc_html( $label . ': ' . rawurldecode( $value ) );
				}
				if ( $bits ) {
					$meta = '<span class="raq-line__meta">' . implode( ', ', $bits ) . '</span>';
				}
			}

			// Qty input only when the quantity selector is enabled (unified with
			// the product page + summary). When off, quantity is always 1.
			if ( RAQ_Settings::get( 'qty_selector', true ) ) {
				$qty_ctrl = sprintf(
					'<label class="screen-reader-text">%1$s</label><input type="number" class="raq-qty" min="1" step="1" value="%2$d" inputmode="numeric">',
					esc_html__( 'Quantity', 'request-a-quote-for-woocommerce' ),
					(int) $item['qty']
				);
			} else {
				$qty_ctrl = '';
			}

			$rows .= sprintf(
				'<div class="raq-line" data-key="%1$s">
					<div class="raq-line__thumb">%2$s</div>
					<div class="raq-line__info">
						<span class="raq-line__name">%3$s</span>
						%4$s
						<div class="raq-line__qty">
							%5$s
							<button type="button" class="raq-remove" aria-label="%6$s">%7$s</button>
						</div>
					</div>
				</div>',
				esc_attr( $item['key'] ),
				$item['thumb'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_product get_image is safe HTML.
				$name,
				$meta,
				$qty_ctrl, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				esc_attr__( 'Remove', 'request-a-quote-for-woocommerce' ),
				esc_html__( 'Remove', 'request-a-quote-for-woocommerce' )
			);
		}

		return '<div class="raq-lines">' . $rows . '</div>';
	}
}
