<?php
/**
 * Fatal-error capture for diagnosability.
 *
 * When any request dies with a fatal, the shutdown hook records the message,
 * file, line and URL into an option; administrators then see the exact error
 * in an admin notice on the Quotes screens (with a dismiss link) instead of
 * WordPress's blank "There has been a critical error" wall.
 *
 * Deliberately captures fatals from ANY code on the request (not only ours):
 * on a client site the question is always "what exactly died" - this answers it.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Debug
 */
class RAQ_Debug {

	const OPTION = 'raq_last_fatal';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'shutdown', array( __CLASS__, 'capture' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_clear' ) );
	}

	/**
	 * On shutdown, persist the last fatal (if any).
	 */
	public static function capture() {
		$error = error_get_last();
		if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}
		update_option(
			self::OPTION,
			array(
				'time'    => current_time( 'mysql' ),
				'message' => $error['message'],
				'file'    => $error['file'],
				'line'    => $error['line'],
				'url'     => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			),
			false
		);
	}

	/**
	 * Show the captured fatal to admins on our screens.
	 */
	public static function notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, RAQ_CPT::POST_TYPE ) ) {
			return;
		}
		$fatal = get_option( self::OPTION );
		if ( empty( $fatal ) || ! is_array( $fatal ) ) {
			return;
		}
		$clear = wp_nonce_url( add_query_arg( 'raq_clear_fatal', 1 ), 'raq_clear_fatal' );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Last captured fatal error', 'request-a-quote-for-woocommerce' ) . '</strong> (' . esc_html( $fatal['time'] ) . ')<br>';
		echo '<code>' . esc_html( $fatal['message'] ) . '</code><br>';
		echo esc_html( $fatal['file'] ) . ':' . esc_html( $fatal['line'] );
		if ( ! empty( $fatal['url'] ) ) {
			echo '<br><small>' . esc_html__( 'URL:', 'request-a-quote-for-woocommerce' ) . ' ' . esc_html( $fatal['url'] ) . '</small>';
		}
		echo '</p><p><a href="' . esc_url( $clear ) . '">' . esc_html__( 'Dismiss', 'request-a-quote-for-woocommerce' ) . '</a></p></div>';
	}

	/**
	 * Clear the stored fatal.
	 */
	public static function maybe_clear() {
		if ( isset( $_GET['raq_clear_fatal'] ) && check_admin_referer( 'raq_clear_fatal' ) ) {
			delete_option( self::OPTION );
			wp_safe_redirect( remove_query_arg( array( 'raq_clear_fatal', '_wpnonce' ) ) );
			exit;
		}
	}
}
