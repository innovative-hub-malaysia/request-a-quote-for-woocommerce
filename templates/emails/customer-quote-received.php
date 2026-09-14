<?php
/**
 * Customer "Quote Received" email (HTML).
 *
 * This template can be overridden by copying it to
 * yourtheme/request-a-quote-for-woocommerce/emails/customer-quote-received.php
 *
 * @package RequestAQuoteForWooCommerce
 *
 * @var string $email_heading
 * @var array  $customer
 * @var array  $items
 * @var string $reference
 * @var object $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php printf( esc_html__( 'Hi %s,', 'request-a-quote-for-woocommerce' ), esc_html( RAQ_Email_Helper::field_value( $customer, 'name' ) ) ); ?></p>

<p><?php esc_html_e( 'Thank you for your quote request. Our team will review it and get back to you shortly.', 'request-a-quote-for-woocommerce' ); ?></p>

<?php if ( ! empty( $reference ) ) : ?>
	<p><?php printf( esc_html__( 'Your reference: %s', 'request-a-quote-for-woocommerce' ), '<strong>' . esc_html( $reference ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<?php endif; ?>

<h2><?php esc_html_e( 'Requested products', 'request-a-quote-for-woocommerce' ); ?></h2>

<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border-color:#e5e5e5;">
	<thead>
		<tr>
			<th scope="col" style="text-align:left;"><?php esc_html_e( 'Product', 'request-a-quote-for-woocommerce' ); ?></th>
			<th scope="col" style="text-align:right;"><?php esc_html_e( 'Qty', 'request-a-quote-for-woocommerce' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( (array) $items as $item ) : ?>
			<tr>
				<td style="text-align:left;vertical-align:top;">
					<?php echo esc_html( $item['name'] ); ?>
					<?php
					$vlabel = RAQ_Email_Helper::variation_label( isset( $item['variation'] ) ? $item['variation'] : array() );
					if ( $vlabel ) {
						echo '<br><small>' . esc_html( $vlabel ) . '</small>';
					}
					if ( ! empty( $item['sku'] ) ) {
						echo '<br><small>' . esc_html__( 'SKU:', 'request-a-quote-for-woocommerce' ) . ' ' . esc_html( $item['sku'] ) . '</small>';
					}
					?>
				</td>
				<td style="text-align:right;vertical-align:top;"><?php echo esc_html( $item['qty'] ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php
do_action( 'woocommerce_email_footer', $email );
