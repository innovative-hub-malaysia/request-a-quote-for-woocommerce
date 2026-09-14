<?php
/**
 * Admin "New Quote Request" email (plain text).
 *
 * @package RequestAQuoteForWooCommerce
 *
 * @var string $email_heading
 * @var int    $quote_id
 * @var array  $customer
 * @var array  $items
 * @var array  $attachment
 * @var string $reference
 */

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

echo esc_html__( 'A new quote request has been submitted.', 'request-a-quote-for-woocommerce' ) . "\n";
if ( ! empty( $reference ) ) {
	echo esc_html__( 'Reference:', 'request-a-quote-for-woocommerce' ) . ' ' . esc_html( $reference ) . "\n";
}

echo "\n" . esc_html__( 'Requested products', 'request-a-quote-for-woocommerce' ) . "\n";
echo "----------------------------------------\n";
foreach ( (array) $items as $item ) {
	$line = '- ' . $item['name'];
	$vlabel = RAQ_Email_Helper::variation_label( isset( $item['variation'] ) ? $item['variation'] : array() );
	if ( $vlabel ) {
		$line .= ' (' . $vlabel . ')';
	}
	if ( ! empty( $item['sku'] ) ) {
		$line .= ' [' . $item['sku'] . ']';
	}
	$line .= ' x ' . $item['qty'];
	echo esc_html( $line ) . "\n";
}

echo "\n" . esc_html__( 'Customer details', 'request-a-quote-for-woocommerce' ) . "\n";
echo "----------------------------------------\n";
foreach ( (array) $customer as $field ) {
	if ( '' === trim( (string) $field['value'] ) ) {
		continue;
	}
	echo esc_html( $field['label'] . ': ' . $field['value'] ) . "\n";
}

if ( ! empty( $attachment['name'] ) ) {
	echo "\n" . esc_html__( 'Attachment:', 'request-a-quote-for-woocommerce' ) . ' ' . esc_html( $attachment['name'] ) . "\n";
}

if ( $quote_id ) {
	echo "\n" . esc_html__( 'View this quote:', 'request-a-quote-for-woocommerce' ) . ' ' . esc_url_raw( admin_url( 'post.php?post=' . $quote_id . '&action=edit' ) ) . "\n";
}
