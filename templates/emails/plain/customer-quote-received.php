<?php
/**
 * Customer "Quote Received" email (plain text).
 *
 * @package RequestAQuoteForWooCommerce
 *
 * @var string $email_heading
 * @var array  $customer
 * @var array  $items
 * @var string $reference
 */

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

printf( esc_html__( 'Hi %s,', 'request-a-quote-for-woocommerce' ), esc_html( RAQ_Email_Helper::field_value( $customer, 'name' ) ) );
echo "\n\n";
echo esc_html__( 'Thank you for your quote request. Our team will review it and get back to you shortly.', 'request-a-quote-for-woocommerce' ) . "\n";

if ( ! empty( $reference ) ) {
	echo "\n" . esc_html__( 'Your reference:', 'request-a-quote-for-woocommerce' ) . ' ' . esc_html( $reference ) . "\n";
}

echo "\n" . esc_html__( 'Requested products', 'request-a-quote-for-woocommerce' ) . "\n";
echo "----------------------------------------\n";

foreach ( (array) $items as $item ) {
	$line = '- ' . $item['name'];
	$vlabel = RAQ_Email_Helper::variation_label( isset( $item['variation'] ) ? $item['variation'] : array() );
	if ( $vlabel ) {
		$line .= ' (' . $vlabel . ')';
	}
	$line .= ' x ' . $item['qty'];
	echo esc_html( $line ) . "\n";
}

echo "\n";
echo esc_html( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
