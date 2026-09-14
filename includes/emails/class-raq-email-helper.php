<?php
/**
 * Shared helpers for the quote emails + their templates.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Email_Helper
 */
class RAQ_Email_Helper {

	/**
	 * Extract the customer email from the stored fields.
	 *
	 * @param array $customer Customer fields.
	 * @return string
	 */
	public static function customer_email( $customer ) {
		if ( isset( $customer['email']['value'] ) ) {
			return sanitize_email( $customer['email']['value'] );
		}
		return '';
	}

	/**
	 * Default admin recipients (settings, else site admin email).
	 *
	 * @return string
	 */
	public static function admin_recipients() {
		$recipients = RAQ_Settings::get( 'admin_recipients', '' );
		return $recipients ? $recipients : get_option( 'admin_email' );
	}

	/**
	 * A single field's stored value.
	 *
	 * @param array  $customer Customer fields.
	 * @param string $key      Field key.
	 * @return string
	 */
	public static function field_value( $customer, $key ) {
		return isset( $customer[ $key ]['value'] ) ? (string) $customer[ $key ]['value'] : '';
	}

	/**
	 * Sample customer fields for the WooCommerce email preview (so the template
	 * is not blank when there is no real quote to render).
	 *
	 * @return array
	 */
	public static function sample_customer() {
		return array(
			'name'    => array(
				'label' => __( 'Name', 'request-a-quote-for-woocommerce' ),
				'value' => 'Jane Doe',
			),
			'company' => array(
				'label' => __( 'Company', 'request-a-quote-for-woocommerce' ),
				'value' => 'Acme Manufacturing',
			),
			'email'   => array(
				'label' => __( 'Email', 'request-a-quote-for-woocommerce' ),
				'value' => 'jane@example.com',
			),
			'phone'   => array(
				'label' => __( 'Phone', 'request-a-quote-for-woocommerce' ),
				'value' => '+60 12-345 6789',
			),
			'country' => array(
				'label' => __( 'Country', 'request-a-quote-for-woocommerce' ),
				'value' => 'Malaysia',
			),
			'message' => array(
				'label' => __( 'Message', 'request-a-quote-for-woocommerce' ),
				'value' => 'Please send your best quote for the products below. Thank you.',
			),
		);
	}

	/**
	 * Sample line items for the email preview.
	 *
	 * @return array
	 */
	public static function sample_items() {
		return array(
			array(
				'product_id'   => 0,
				'variation_id' => 0,
				'name'         => 'Sample Product A',
				'sku'          => 'SKU-A-001',
				'qty'          => 2,
				'variation'    => array(),
			),
			array(
				'product_id'   => 0,
				'variation_id' => 0,
				'name'         => 'Sample Product B',
				'sku'          => 'SKU-B-002',
				'qty'          => 5,
				'variation'    => array(),
			),
		);
	}

	/**
	 * A human label for a stored variation attribute map.
	 *
	 * @param array $variation Attribute map.
	 * @return string
	 */
	public static function variation_label( $variation ) {
		if ( empty( $variation ) || ! is_array( $variation ) ) {
			return '';
		}
		$bits = array();
		foreach ( $variation as $attr => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$label  = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( str_replace( 'attribute_', '', $attr ) ) : $attr;
			$bits[] = $label . ': ' . rawurldecode( $value );
		}
		return implode( ', ', $bits );
	}
}
