<?php
/**
 * The quote list - the "cart" replacement.
 *
 * A persistent, variation-aware list of products a visitor wants quoted.
 * Storage follows the plan: WooCommerce session (cookie-backed) for guests,
 * user meta for logged-in customers so it survives across devices/sessions.
 *
 * This is our OWN store - it never touches the WooCommerce cart.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Quote_List
 */
class RAQ_Quote_List {

	const SESSION_KEY = 'raq_quote_list';
	const META_KEY    = '_raq_quote_list';

	/**
	 * Read the raw list (assoc: line_key => item).
	 *
	 * @return array
	 */
	public static function get_items() {
		if ( is_user_logged_in() ) {
			$items = get_user_meta( get_current_user_id(), self::META_KEY, true );
		} else {
			$items = ( function_exists( 'WC' ) && WC()->session ) ? WC()->session->get( self::SESSION_KEY ) : array();
		}
		return is_array( $items ) ? $items : array();
	}

	/**
	 * Persist the list.
	 *
	 * @param array $items Items to store.
	 */
	protected static function save_items( array $items ) {
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::META_KEY, $items );
		} elseif ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $items );
			// Guests: make sure the session cookie is actually issued, or the
			// list won't survive the next page load.
			if ( method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
				WC()->session->set_customer_session_cookie( true );
			}
		}
	}

	/**
	 * Deterministic line key for a product + variation combination.
	 *
	 * @param int   $product_id   Parent (or simple) product id.
	 * @param int   $variation_id Variation id (0 for none).
	 * @param array $variation    Chosen attribute map.
	 * @return string
	 */
	public static function line_key( $product_id, $variation_id = 0, $variation = array() ) {
		return md5( $product_id . '|' . $variation_id . '|' . wp_json_encode( $variation ) );
	}

	/**
	 * Add a product to the quote list (merging quantity if already present).
	 *
	 * @param int   $product_id   Product id.
	 * @param int   $variation_id Variation id.
	 * @param array $variation    Chosen attributes.
	 * @param int   $qty          Quantity.
	 * @return string|WP_Error Line key on success.
	 */
	public static function add( $product_id, $variation_id = 0, $variation = array(), $qty = 1 ) {
		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		$qty          = max( 1, absint( $qty ) );

		$lookup_id = $variation_id ? $variation_id : $product_id;
		$product   = wc_get_product( $lookup_id );
		if ( ! $product ) {
			return new WP_Error( 'raq_invalid_product', __( 'Product not found.', 'request-a-quote-for-woocommerce' ) );
		}

		$items = self::get_items();
		$key   = self::line_key( $product_id, $variation_id, $variation );

		if ( isset( $items[ $key ] ) ) {
			$items[ $key ]['qty'] += $qty;
		} else {
			$items[ $key ] = array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'variation'    => is_array( $variation ) ? $variation : array(),
				'qty'          => $qty,
			);
		}

		self::save_items( $items );
		return $key;
	}

	/**
	 * Update the quantity of a line (0 or less removes it).
	 *
	 * @param string $key Line key.
	 * @param int    $qty New quantity.
	 */
	public static function update_qty( $key, $qty ) {
		$items = self::get_items();
		if ( ! isset( $items[ $key ] ) ) {
			return;
		}
		$qty = intval( $qty );
		if ( $qty <= 0 ) {
			unset( $items[ $key ] );
		} else {
			$items[ $key ]['qty'] = $qty;
		}
		self::save_items( $items );
	}

	/**
	 * Remove a line.
	 *
	 * @param string $key Line key.
	 */
	public static function remove( $key ) {
		$items = self::get_items();
		if ( isset( $items[ $key ] ) ) {
			unset( $items[ $key ] );
			self::save_items( $items );
		}
	}

	/**
	 * Empty the list.
	 */
	public static function clear() {
		self::save_items( array() );
	}

	/**
	 * Total quantity across all lines (the header badge number).
	 *
	 * @return int
	 */
	public static function get_count() {
		$count = 0;
		foreach ( self::get_items() as $item ) {
			$count += absint( $item['qty'] );
		}
		return $count;
	}

	/**
	 * Hydrated view of the list for rendering: each line with its resolved
	 * product object, display name, thumbnail and permalink. Silently drops
	 * lines whose product no longer exists.
	 *
	 * @return array
	 */
	public static function get_display_items() {
		$display = array();
		foreach ( self::get_items() as $key => $item ) {
			$lookup_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
			$product   = wc_get_product( $lookup_id );
			if ( ! $product ) {
				continue;
			}
			$display[] = array(
				'key'       => $key,
				'product'   => $product,
				'qty'       => absint( $item['qty'] ),
				'name'      => $product->get_name(),
				'permalink' => $product->is_visible() ? $product->get_permalink() : '',
				'thumb'     => $product->get_image( 'woocommerce_thumbnail' ),
				'variation' => isset( $item['variation'] ) ? (array) $item['variation'] : array(),
			);
		}
		return $display;
	}
}
