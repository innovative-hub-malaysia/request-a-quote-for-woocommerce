<?php
/**
 * Admin "New Quote Request" email (HTML).
 *
 * Override at
 * yourtheme/request-a-quote-for-woocommerce/emails/admin-new-quote.php
 *
 * @package RequestAQuoteForWooCommerce
 *
 * @var string $email_heading
 * @var int    $quote_id
 * @var array  $customer
 * @var array  $items
 * @var array  $attachment
 * @var string $reference
 * @var object $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php esc_html_e( 'A new quote request has been submitted.', 'request-a-quote-for-woocommerce' ); ?>
	<?php if ( ! empty( $reference ) ) : ?>
		<?php printf( esc_html__( 'Reference: %s', 'request-a-quote-for-woocommerce' ), '<strong>' . esc_html( $reference ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php endif; ?>
</p>

<h2><?php esc_html_e( 'Requested products', 'request-a-quote-for-woocommerce' ); ?></h2>

<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border-color:#e5e5e5;">
	<thead>
		<tr>
			<th scope="col" style="text-align:left;"><?php esc_html_e( 'Product', 'request-a-quote-for-woocommerce' ); ?></th>
			<th scope="col" style="text-align:left;"><?php esc_html_e( 'SKU', 'request-a-quote-for-woocommerce' ); ?></th>
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
					?>
				</td>
				<td style="text-align:left;vertical-align:top;"><?php echo esc_html( isset( $item['sku'] ) ? $item['sku'] : '' ); ?></td>
				<td style="text-align:right;vertical-align:top;"><?php echo esc_html( $item['qty'] ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Customer details', 'request-a-quote-for-woocommerce' ); ?></h2>

<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border-color:#e5e5e5;">
	<tbody>
		<?php foreach ( (array) $customer as $field ) : ?>
			<?php if ( '' === trim( (string) $field['value'] ) ) { continue; } ?>
			<tr>
				<th scope="row" style="text-align:left;width:30%;"><?php echo esc_html( $field['label'] ); ?></th>
				<td style="text-align:left;"><?php echo nl2br( esc_html( $field['value'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php if ( ! empty( $attachment['url'] ) ) : ?>
	<p><strong><?php esc_html_e( 'Attachment:', 'request-a-quote-for-woocommerce' ); ?></strong>
		<?php echo esc_html( isset( $attachment['name'] ) ? $attachment['name'] : '' ); ?>
		<br><small><?php esc_html_e( 'Available from the quote record in the admin.', 'request-a-quote-for-woocommerce' ); ?></small>
	</p>
<?php endif; ?>

<?php if ( $quote_id ) : ?>
	<p><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $quote_id . '&action=edit' ) ); ?>"><?php esc_html_e( 'View this quote', 'request-a-quote-for-woocommerce' ); ?></a></p>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_footer', $email );
