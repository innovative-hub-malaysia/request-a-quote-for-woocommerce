<?php
/**
 * "New Quote Request" email - sent to sales / admin.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

/**
 * Class RAQ_Email_Admin
 */
class RAQ_Email_Admin extends WC_Email {

	/**
	 * The quote post id.
	 *
	 * @var int
	 */
	public $quote_id = 0;

	/**
	 * Customer fields.
	 *
	 * @var array
	 */
	public $customer = array();

	/**
	 * Line items snapshot.
	 *
	 * @var array
	 */
	public $items = array();

	/**
	 * Attachment meta (if any).
	 *
	 * @var array
	 */
	public $attachment = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'raq_new_quote';
		$this->title          = __( 'New quote request (admin)', 'request-a-quote-for-woocommerce' );
		$this->description    = __( 'Notification sent to sales/admin when a customer submits a quote request.', 'request-a-quote-for-woocommerce' );
		$this->template_html  = 'emails/admin-new-quote.php';
		$this->template_plain = 'emails/plain/admin-new-quote.php';
		$this->template_base  = RAQ_PLUGIN_DIR . 'templates/';
		$this->placeholders   = array(
			'{site_title}' => $this->get_blogname(),
			'{reference}'  => '',
		);

		// Triggered directly by RAQ_Emails::send_quote_emails (no WC queue).
		parent::__construct();

		// Default recipient: our setting, else the site admin email.
		$this->recipient = $this->get_option( 'recipient', RAQ_Email_Helper::admin_recipients() );
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( '[{site_title}] New quote request {reference}', 'request-a-quote-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'New quote request', 'request-a-quote-for-woocommerce' );
	}

	/**
	 * Add the recipient field to the standard email settings.
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		$new = array();
		foreach ( $this->form_fields as $key => $field ) {
			$new[ $key ] = $field;
			if ( 'enabled' === $key ) {
				$new['recipient'] = array(
					'title'       => __( 'Recipient(s)', 'request-a-quote-for-woocommerce' ),
					'type'        => 'text',
					'description' => sprintf(
						/* translators: %s: admin email default. */
						__( 'Comma-separated. Defaults to %s.', 'request-a-quote-for-woocommerce' ),
						'<code>' . esc_html( RAQ_Email_Helper::admin_recipients() ) . '</code>'
					),
					'placeholder' => '',
					'default'     => RAQ_Email_Helper::admin_recipients(),
					'desc_tip'    => true,
				);
			}
		}
		$this->form_fields = $new;
	}

	/**
	 * Send when a quote is created.
	 *
	 * @param int   $quote_id Quote post id.
	 * @param array $customer Customer fields.
	 * @return bool Whether the email was sent.
	 */
	public function trigger( $quote_id, $customer = array() ) {
		$this->setup_locale();

		$quote_id = absint( $quote_id );
		if ( $quote_id ) {
			$this->quote_id   = $quote_id;
			$this->customer   = ! empty( $customer ) ? $customer : (array) get_post_meta( $quote_id, '_raq_customer', true );
			$this->items      = (array) get_post_meta( $quote_id, '_raq_items', true );
			$this->attachment = (array) get_post_meta( $quote_id, '_raq_attachment', true );

			$this->placeholders['{reference}'] = (string) get_post_meta( $quote_id, '_raq_reference', true );
		}

		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
		return $sent;
	}

	/**
	 * Template args, with sample-data fallback for the preview.
	 *
	 * @param bool $plain Whether this is the plain-text render.
	 * @return array
	 */
	protected function view_args( $plain ) {
		return array(
			'quote_id'      => $this->quote_id,
			'customer'      => $this->customer ? $this->customer : RAQ_Email_Helper::sample_customer(),
			'items'         => $this->items ? $this->items : RAQ_Email_Helper::sample_items(),
			'attachment'    => $this->attachment,
			'reference'     => $this->placeholders['{reference}'] ? $this->placeholders['{reference}'] : 'RAQ-0001',
			'email_heading' => $this->get_heading(),
			'sent_to_admin' => true,
			'plain_text'    => $plain,
			'email'         => $this,
		);
	}

	/**
	 * HTML content.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->view_args( false ), '', $this->template_base );
	}

	/**
	 * Plain-text content.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->view_args( true ), '', $this->template_base );
	}
}
