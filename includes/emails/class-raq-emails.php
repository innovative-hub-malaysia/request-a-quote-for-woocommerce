<?php
/**
 * Email registrar - rides the WooCommerce email framework.
 *
 * Registers our two emails so they appear under WooCommerce > Settings >
 * Emails, and registers `raq_quote_created` as a transactional trigger so the
 * mailer is loaded (and our classes constructed) when a quote is created.
 *
 * The WC_Email subclasses are required lazily inside the filter, because
 * WC_Email is only defined once the WooCommerce mailer loads.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Emails
 */
class RAQ_Emails {

	/**
	 * Hook registration.
	 *
	 * NOTE: we deliberately do NOT go through the `woocommerce_email_actions`
	 * transactional queue. That chain depends on registration timing and
	 * silently drops the send when it misses - which is exactly the "customer
	 * never got the email" failure. Instead we hook `raq_quote_created`
	 * directly, load the mailer (which constructs our classes), and call each
	 * trigger deterministically.
	 */
	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_classes' ) );
		add_action( 'raq_quote_created', array( __CLASS__, 'send_quote_emails' ), 10, 2 );
	}

	/**
	 * Send both quote emails for a new quote, and write the outcome of each
	 * send into the quote's history so delivery is diagnosable from the quote
	 * screen ("sent OK" but no email in the inbox = SMTP/spam, not the plugin).
	 *
	 * @param int   $quote_id Quote post id.
	 * @param array $customer Customer fields.
	 */
	public static function send_quote_emails( $quote_id, $customer ) {
		if ( ! function_exists( 'WC' ) || ! is_callable( array( WC(), 'mailer' ) ) ) {
			self::log( $quote_id, __( 'Emails NOT sent: WooCommerce mailer unavailable.', 'request-a-quote-for-woocommerce' ) );
			return;
		}

		// Capture the reason when wp_mail fails.
		$mail_error = '';
		$capture    = static function ( $wp_error ) use ( &$mail_error ) {
			$mail_error = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : '';
		};
		add_action( 'wp_mail_failed', $capture );

		$emails = WC()->mailer()->get_emails(); // Instantiates all email classes incl. ours.
		$labels = array(
			'RAQ_Email_Customer' => __( 'Customer email', 'request-a-quote-for-woocommerce' ),
			'RAQ_Email_Admin'    => __( 'Sales email', 'request-a-quote-for-woocommerce' ),
		);

		foreach ( $labels as $class => $label ) {
			if ( ! isset( $emails[ $class ] ) || ! is_callable( array( $emails[ $class ], 'trigger' ) ) ) {
				self::log( $quote_id, sprintf( '%s: %s', $label, __( 'NOT sent - email class not registered.', 'request-a-quote-for-woocommerce' ) ) );
				continue;
			}

			$mail_error = '';
			$email      = $emails[ $class ];
			$sent       = $email->trigger( $quote_id, $customer );
			$recipient  = $email->get_recipient();

			if ( $sent ) {
				self::log( $quote_id, sprintf( '%s (%s): %s', $label, $recipient, __( 'sent OK', 'request-a-quote-for-woocommerce' ) ) );
			} elseif ( ! $email->is_enabled() ) {
				self::log( $quote_id, sprintf( '%s: %s', $label, __( 'skipped - disabled in WooCommerce email settings.', 'request-a-quote-for-woocommerce' ) ) );
			} elseif ( ! $recipient ) {
				self::log( $quote_id, sprintf( '%s: %s', $label, __( 'skipped - no recipient.', 'request-a-quote-for-woocommerce' ) ) );
			} else {
				self::log(
					$quote_id,
					sprintf(
						'%s (%s): %s%s',
						$label,
						$recipient,
						__( 'FAILED', 'request-a-quote-for-woocommerce' ),
						$mail_error ? ' - ' . $mail_error : ''
					)
				);
			}
		}

		remove_action( 'wp_mail_failed', $capture );
	}

	/**
	 * Append a line to the quote's history log (shown in Status & history).
	 *
	 * @param int    $quote_id Quote post id.
	 * @param string $text     Entry text.
	 */
	protected static function log( $quote_id, $text ) {
		$history   = array_filter( (array) get_post_meta( $quote_id, '_raq_history', true ), 'is_array' );
		$history[] = array(
			'time' => current_time( 'mysql' ),
			'user' => __( 'System', 'request-a-quote-for-woocommerce' ),
			'text' => $text,
			'note' => '',
		);
		update_post_meta( $quote_id, '_raq_history', $history );
	}

	/**
	 * Add our email classes to WooCommerce.
	 *
	 * @param array $emails Existing email class instances.
	 * @return array
	 */
	public static function register_classes( $emails ) {
		require_once RAQ_PLUGIN_DIR . 'includes/emails/class-raq-email-helper.php';
		require_once RAQ_PLUGIN_DIR . 'includes/emails/class-raq-email-customer.php';
		require_once RAQ_PLUGIN_DIR . 'includes/emails/class-raq-email-admin.php';

		$emails['RAQ_Email_Customer'] = new RAQ_Email_Customer();
		$emails['RAQ_Email_Admin']    = new RAQ_Email_Admin();
		return $emails;
	}
}
