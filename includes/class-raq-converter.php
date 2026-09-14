<?php
/**
 * The e-commerce converter (Master Switch lockdown).
 *
 * Instantiated ONLY when the Master Switch is ON (see RAQ_Plugin::boot). Every
 * conversion below is a RUNTIME hook/filter and never writes to WooCommerce's
 * database - that is what guarantees the store returns untouched the moment the
 * switch flips off or the plugin is deactivated.
 *
 * STAGE 1 (this file): hide prices, swap Add-to-Cart for Add-to-Quote, disable
 * payment/shipping/tax/coupons, neutralise cart/checkout/pay pages, remove
 * WooCommerce Analytics + Marketing + usage tracking.
 *
 * The Add-to-Quote BUTTON is rendered here; its click BEHAVIOUR (add to the
 * quote list + drawer, AJAX, GA4 add_to_quote) is wired in Stage 2. Until then
 * the buttons are inert placeholders (type="button", no handler).
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Converter
 */
class RAQ_Converter {

	/**
	 * Attach all conversion hooks. Called only when the switch is ON.
	 */
	public static function init() {
		// 1.1 Hide prices everywhere.
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'hide_price' ), 100 );
		add_filter( 'woocommerce_variable_price_html', array( __CLASS__, 'hide_price' ), 100 );
		add_filter( 'woocommerce_variable_sale_price_html', array( __CLASS__, 'hide_price' ), 100 );
		add_filter( 'woocommerce_grouped_price_html', array( __CLASS__, 'hide_price' ), 100 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'hide_variation_price' ), 100 );

		// 1.2 Relabel Add to Cart -> Add to Quote. The BUTTONS stay wherever the
		// theme (incl. Divi Theme Builder) places them; we only relabel here and
		// intercept the click in JS (assets/js/raq-frontend.js) to add to the
		// quote list. This is theme-agnostic - we never reposition the button.
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_add_to_quote_link' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'button_label' ), 10 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'button_label' ), 10 );

		// Render our own Add-to-Quote button on the single product page so it is
		// always present (some themes / Divi Theme Builder layouts render no
		// native add-to-cart button for us to relabel). Can be turned off when
		// the button is placed manually via the [raq_add_to_quote] shortcode.
		if ( RAQ_Settings::get( 'auto_button', true ) ) {
			add_action( 'woocommerce_before_single_product', array( __CLASS__, 'swap_single_add_to_cart' ) );
			remove_action( 'woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20 );
			add_action( 'woocommerce_single_variation', array( __CLASS__, 'render_variation_quote_button' ), 20 );
		}

		// Shortcode for placing the button manually (e.g. a Divi Code module).
		add_shortcode( 'raq_add_to_quote', array( __CLASS__, 'add_button_shortcode' ) );

		// 1.2b Close the add-to-cart hole.
		//
		// Relabelling the button and intercepting the click is presentation only:
		// POST or GET ?add-to-cart=<id> still added the product to a real cart on
		// a converted store (verified with curl, 2026-09-09), and the cart page is
		// neutralised, so the customer reached a dead end.
		//
		// woocommerce_add_to_cart_validation is used rather than
		// woocommerce_is_purchasable on purpose: is_purchasable=false also strips
		// the variation form that our own Add-to-Quote button needs in order to
		// capture the chosen variation, and it changes what several templates
		// render. Failing validation blocks the action itself and leaves every
		// template alone.
		add_filter( 'woocommerce_add_to_cart_validation', '__return_false', 100 );

		// 1.2c Archives: some themes (Divi among them, in its own functions.php)
		// remove woocommerce_template_loop_add_to_cart entirely, so the
		// woocommerce_loop_add_to_cart_link filter above never fires and the
		// archive gets no button at all. Only add ours when the native one is
		// genuinely gone, and check late enough for the theme to have run.
		add_action( 'wp', array( __CLASS__, 'maybe_add_loop_button' ), 20 );

		// 1.3 Disable payment / shipping / tax / coupons (runtime, no DB writes).
		add_filter( 'woocommerce_available_payment_gateways', '__return_empty_array', 100 );
		add_filter( 'woocommerce_shipping_enabled', '__return_false', 100 );
		add_filter( 'wc_tax_enabled', '__return_false', 100 );
		add_filter( 'woocommerce_coupons_enabled', '__return_false', 100 );

		// 1.4 Neutralise cart / checkout / pay pages.
		add_action( 'template_redirect', array( __CLASS__, 'neutralise_pages' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 100 );

		// 1.5 Usage tracking off. (Analytics + Marketing menus are stripped at
		// plugin-load in the main file, which is early enough for WC Admin.)
		add_filter( 'woocommerce_apply_tracking', '__return_false', 100 );

		do_action( 'raq_converter_init' );
	}

	/**
	 * Add our loop button only when the theme has removed WooCommerce's own.
	 */
	public static function maybe_add_loop_button() {
		if ( has_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart' ) ) {
			return; // The native button is present; the link filter handles it.
		}
		add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'render_loop_quote_button' ), 10 );
	}

	/**
	 * The Add-to-Quote button on an archive listing.
	 */
	public static function render_loop_quote_button() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		printf(
			'<a href="%1$s" class="button raq-add-to-quote %2$s" data-product-id="%3$d" data-product-type="%4$s" rel="nofollow">%5$s</a>',
			esc_url( $product->get_permalink() ),
			esc_attr( $product->is_type( 'variable' ) ? 'raq-needs-options' : '' ),
			(int) $product->get_id(),
			esc_attr( $product->get_type() ),
			esc_html( self::button_label() )
		);
	}

	/* ------------------------------------------------------------------ *
	 * 1.1 Prices
	 * ------------------------------------------------------------------ */

	/**
	 * Blank any price HTML.
	 *
	 * @return string
	 */
	public static function hide_price() {
		return '';
	}

	/**
	 * Blank the price shown when a variation is selected.
	 *
	 * @param array $data Variation data passed to the front-end.
	 * @return array
	 */
	public static function hide_variation_price( $data ) {
		if ( is_array( $data ) ) {
			$data['price_html'] = '';
		}
		return $data;
	}

	/* ------------------------------------------------------------------ *
	 * 1.2 Add to Quote button
	 * ------------------------------------------------------------------ */

	/**
	 * The configured button label.
	 *
	 * @return string
	 */
	public static function label() {
		$label = RAQ_Settings::get( 'add_to_quote_label', __( 'Add to Quote', 'request-a-quote-for-woocommerce' ) );
		return $label ? $label : __( 'Add to Quote', 'request-a-quote-for-woocommerce' );
	}

	/**
	 * Filter callback for the various add-to-cart text hooks.
	 *
	 * @return string
	 */
	public static function button_label() {
		return self::label();
	}

	/**
	 * Replace the loop (shop / archive / widget) add-to-cart link.
	 *
	 * Simple products get an inert Add-to-Quote button (behaviour in Stage 2).
	 * Products that need option selection link to their product page instead.
	 *
	 * @param string     $html    Original link HTML.
	 * @param WC_Product $product The product.
	 * @return string
	 */
	public static function loop_add_to_quote_link( $html, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $html;
		}

		if ( $product->is_type( 'simple' ) ) {
			return sprintf(
				'<button type="button" class="button raq-add-to-quote" data-product_id="%d">%s</button>',
				esc_attr( $product->get_id() ),
				esc_html( self::label() )
			);
		}

		return sprintf(
			'<a href="%s" class="button raq-select-options">%s</a>',
			esc_url( $product->get_permalink() ),
			esc_html__( 'Select options', 'request-a-quote-for-woocommerce' )
		);
	}

	/**
	 * On a single simple product, replace the native add-to-cart region with an
	 * Add-to-Quote button. Variable products keep their dropdowns (handled by
	 * the variation-button swap).
	 */
	public static function swap_single_add_to_cart() {
		global $product;
		if ( $product instanceof WC_Product && $product->is_type( 'simple' ) ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
			add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_simple_quote_button' ), 30 );
		}
	}

	/**
	 * Render the simple-product Add-to-Quote block.
	 */
	public static function render_simple_quote_button() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$show_qty = RAQ_Settings::get( 'qty_selector', true );
		?>
		<form class="cart raq-quote-form" method="post" enctype="multipart/form-data">
			<?php if ( $show_qty ) { woocommerce_quantity_input( array(), $product, true ); } ?>
			<button type="button" class="single_add_to_quote_button button alt raq-add-to-quote" data-product_id="<?php echo esc_attr( $product->get_id() ); ?>">
				<?php echo esc_html( self::label() ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Render the variable-product Add-to-Quote button (keeps the WooCommerce
	 * variation-script classes so attribute selection still gates the button).
	 */
	public static function render_variation_quote_button() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$show_qty = RAQ_Settings::get( 'qty_selector', true );
		?>
		<div class="woocommerce-variation-add-to-cart variations_button">
			<?php if ( $show_qty ) { woocommerce_quantity_input( array(), $product, true ); } ?>
			<button type="button" class="single_add_to_quote_button button alt raq-add-to-quote" data-product_id="<?php echo esc_attr( $product->get_id() ); ?>">
				<?php echo esc_html( self::label() ); ?>
			</button>
			<input type="hidden" name="variation_id" class="variation_id" value="0" />
		</div>
		<?php
	}

	/**
	 * `[raq_add_to_quote]` - place the Add-to-Quote button anywhere (e.g. in a
	 * Divi module) for precise positioning. Defaults to the current product.
	 *
	 * @param array $atts Shortcode attributes (id).
	 * @return string
	 */
	public static function add_button_shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'raq_add_to_quote' );
		$id      = absint( $atts['id'] );
		$product = $id ? wc_get_product( $id ) : ( function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null );
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		return sprintf(
			'<button type="button" class="button raq-add-to-quote" data-product_id="%d">%s</button>',
			esc_attr( $product->get_id() ),
			esc_html( self::label() )
		);
	}

	/* ------------------------------------------------------------------ *
	 * 1.4 Cart / checkout / account
	 * ------------------------------------------------------------------ */

	/**
	 * Redirect the native cart and checkout (incl. order-pay / order-received)
	 * away - a quote store has no cart or checkout.
	 */
	public static function neutralise_pages() {
		if ( is_admin() ) {
			return;
		}
		$is_cart     = function_exists( 'is_cart' ) && is_cart();
		$is_checkout = function_exists( 'is_checkout' ) && is_checkout();
		if ( $is_cart || $is_checkout ) {
			wp_safe_redirect( self::destination() );
			exit;
		}
	}

	/**
	 * Where to send neutralised traffic: the Quote Page if set, else the shop,
	 * else the home page.
	 *
	 * @return string
	 */
	public static function destination() {
		$quote_page_id = (int) get_option( 'raq_quote_page_id', 0 );
		if ( $quote_page_id > 0 ) {
			$url = get_permalink( $quote_page_id );
			if ( $url ) {
				return $url;
			}
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop_url = get_permalink( wc_get_page_id( 'shop' ) );
			if ( $shop_url ) {
				return $shop_url;
			}
		}
		return home_url( '/' );
	}

	/**
	 * Remove the payment-methods tab from the My Account menu.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function account_menu_items( $items ) {
		if ( isset( $items['payment-methods'] ) ) {
			unset( $items['payment-methods'] );
		}
		return $items;
	}
}
