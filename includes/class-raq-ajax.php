<?php
/**
 * AJAX endpoints for the quote list.
 *
 * All endpoints are nonce-guarded (`raq_nonce`), sanitise their input, and
 * respond with a JSON payload carrying the current count + freshly rendered
 * drawer body so the UI can update in one round-trip.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Ajax
 */
class RAQ_Ajax {

	/**
	 * Register the endpoints (both logged-in and guest).
	 */
	public static function init() {
		$actions = array( 'add', 'update', 'remove', 'refresh', 'submit' );
		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_raq_' . $action, array( __CLASS__, $action ) );
			add_action( 'wp_ajax_nopriv_raq_' . $action, array( __CLASS__, $action ) );
		}
	}

	/**
	 * Verify the nonce or die with a JSON error.
	 */
	protected static function verify() {
		if ( ! check_ajax_referer( 'raq_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Please refresh the page.', 'request-a-quote-for-woocommerce' ) ), 403 );
		}
	}

	/**
	 * Standard success payload.
	 *
	 * @param array $extra Extra keys to merge in.
	 * @return array
	 */
	protected static function payload( $extra = array() ) {
		return array_merge(
			array(
				'count'   => RAQ_Quote_List::get_count(),
				'drawer'  => RAQ_Frontend::drawer_body_html(),
				// The Quote Page summary ships empty for cache safety and is
				// hydrated from here. See RAQ_Form::summary_html().
				'summary' => class_exists( 'RAQ_Form' ) ? RAQ_Form::summary_items_html() : '',
			),
			$extra
		);
	}

	/**
	 * Add a product to the quote list.
	 */
	public static function add() {
		self::verify();

		if ( RAQ_Settings::get( 'require_login', false ) && ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'message'       => __( 'Please log in to request a quote.', 'request-a-quote-for-woocommerce' ),
					'require_login' => true,
				)
			);
		}

		$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$qty          = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;

		// When the quantity selector is disabled, quantity is always 1 - the
		// server enforces it, not just the hidden UI.
		if ( ! RAQ_Settings::get( 'qty_selector', true ) ) {
			$qty = 1;
		}

		$variation = array();
		if ( isset( $_POST['variation'] ) && is_array( $_POST['variation'] ) ) {
			foreach ( wp_unslash( $_POST['variation'] ) as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per element below.
				$variation[ sanitize_text_field( $key ) ] = sanitize_text_field( $value );
			}
		}

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'request-a-quote-for-woocommerce' ) ) );
		}

		$result = RAQ_Quote_List::add( $product_id, $variation_id, $variation, $qty );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( self::payload( array( 'added' => true ) ) );
	}

	/**
	 * Update a line's quantity.
	 */
	public static function update() {
		self::verify();
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$qty = isset( $_POST['quantity'] ) ? intval( $_POST['quantity'] ) : 0;

		// Selector disabled => quantities are fixed at 1 (removal still allowed).
		if ( $qty > 1 && ! RAQ_Settings::get( 'qty_selector', true ) ) {
			$qty = 1;
		}
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Invalid line.', 'request-a-quote-for-woocommerce' ) ) );
		}
		RAQ_Quote_List::update_qty( $key, $qty );
		wp_send_json_success( self::payload() );
	}

	/**
	 * Remove a line.
	 */
	public static function remove() {
		self::verify();
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Invalid line.', 'request-a-quote-for-woocommerce' ) ) );
		}
		RAQ_Quote_List::remove( $key );
		wp_send_json_success( self::payload() );
	}

	/**
	 * Return the current state (used to sync the badge/drawer on load).
	 */
	public static function refresh() {
		self::verify();
		wp_send_json_success( self::payload() );
	}

	/**
	 * Submit the quote request (create the raq_quote record).
	 */
	public static function submit() {
		self::verify();
		// RAQ_Submission sanitises every field; $_FILES is validated there.
		$result = RAQ_Submission::process( $_POST, $_FILES ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- AJAX nonce verified in verify(); process() sanitises.
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$reference = (string) get_post_meta( (int) $result, '_raq_reference', true );
		wp_send_json_success(
			array(
				'message'   => __( 'Thank you - your quote request has been sent. We will get back to you shortly.', 'request-a-quote-for-woocommerce' ),
				'count'     => RAQ_Quote_List::get_count(),
				'drawer'    => RAQ_Frontend::drawer_body_html(),
				'reference' => $reference,
				'after'     => RAQ_Frontend::after_submit_mode(),
				'redirect'  => RAQ_Frontend::redirect_target( $reference ),
			)
		);
	}
}
