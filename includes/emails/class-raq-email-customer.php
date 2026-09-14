<?php
/**
 * "Quote Received" email - sent to the customer.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

/**
 * Class RAQ_Email_Customer
 */
class RAQ_Email_Customer extends WC_Email {

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
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'raq_quote_received';
		$this->customer_email = true;
		$this->title          = __( 'Quote received (customer)', 'request-a-quote-for-woocommerce' );
		$this->description    = __( 'Confirmation sent to the customer when they submit a quote request.', 'request-a-quote-for-woocommerce' );
		$this->template_html  = 'emails/customer-quote-received.php';
		$this->template_plain = 'emails/plain/customer-quote-received.php';
		$this->template_base  = RAQ_PLUGIN_DIR . 'templates/';
		$this->placeholders   = array(
			'{site_title}' => $this->get_blogname(),
			'{reference}'  => '',
		);

		// Triggered directly by RAQ_Emails::send_quote_emails (no WC queue).
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your quote request at {site_title}', 'request-a-quote-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Thanks for your quote request', 'request-a-quote-for-woocommerce' );
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
			$this->quote_id = $quote_id;
			$this->customer = ! empty( $customer ) ? $customer : (array) get_post_meta( $quote_id, '_raq_customer', true );
			$this->items    = (array) get_post_meta( $quote_id, '_raq_items', true );

			$this->placeholders['{reference}'] = (string) get_post_meta( $quote_id, '_raq_reference', true );
			$this->recipient                   = RAQ_Email_Helper::customer_email( $this->customer );
		}

		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
		return $sent;
	}

	/**
	 * Template args. Falls back to sample data when there is no real quote, so
	 * the WooCommerce email preview renders a representative example.
	 *
	 * @param bool $plain Whether this is the plain-text render.
	 * @return array
	 */
	protected function view_args( $plain ) {
		return array(
			'quote_id'      => $this->quote_id,
			'customer'      => $this->customer ? $this->customer : RAQ_Email_Helper::sample_customer(),
			'items'         => $this->items ? $this->items : RAQ_Email_Helper::sample_items(),
			'reference'     => $this->placeholders['{reference}'] ? $this->placeholders['{reference}'] : 'RAQ-0001',
			'email_heading' => $this->get_heading(),
			'sent_to_admin' => false,
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
