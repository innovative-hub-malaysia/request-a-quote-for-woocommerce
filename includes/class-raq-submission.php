<?php
/**
 * Quote submission handler.
 *
 * Validates a submitted quote request, handles the (optional) attachment
 * securely, creates the `raq_quote` record, then clears the list. Shared by
 * the AJAX endpoint (raq_submit - the primary path) and an admin-post
 * fallback (which initialises the WC session so it works for guests too;
 * note that ADDING to the list still requires JS).
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Submission
 */
class RAQ_Submission {

	const COUNTER_OPTION = 'raq_quote_counter';

	/**
	 * Hook the non-JS fallback handler.
	 */
	public static function init() {
		add_action( 'admin_post_nopriv_raq_submit_form', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_post_raq_submit_form', array( __CLASS__, 'handle_post' ) );
	}

	/**
	 * Non-JS POST fallback: process, then redirect back with a flag.
	 */
	public static function handle_post() {
		if ( ! isset( $_POST['raq_form_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['raq_form_nonce'] ) ), 'raq_submit_form' ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'request-a-quote-for-woocommerce' ) );
		}

		// admin-post.php does not load the WooCommerce front-end session, so a
		// guest's quote list would read empty here. Initialise it explicitly so
		// the no-JS fallback works for guests too.
		if ( function_exists( 'WC' ) && null === WC()->session && is_callable( array( WC(), 'initialize_session' ) ) ) {
			WC()->initialize_session();
		}

		$result   = self::process( $_POST, $_FILES ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce verified above; process() sanitises each field.
		$redirect = wp_get_referer() ? wp_get_referer() : home_url( '/' );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'raq_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg( 'raq_success', 1, $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Core processing. Returns the new quote ID or a WP_Error.
	 *
	 * @param array $post  Raw $_POST.
	 * @param array $files Raw $_FILES.
	 * @return int|WP_Error
	 */
	public static function process( $post, $files ) {
		// Login gate.
		if ( RAQ_Settings::get( 'require_login', false ) && ! is_user_logged_in() ) {
			return new WP_Error( 'raq_login', __( 'Please log in to request a quote.', 'request-a-quote-for-woocommerce' ) );
		}

		// Honeypot: a filled decoy field means a bot. Reject quietly.
		if ( ! empty( $post['raq_hp'] ) ) {
			return new WP_Error( 'raq_spam', __( 'Your request could not be submitted.', 'request-a-quote-for-woocommerce' ) );
		}

		// Rate limit: max 5 submissions per IP per 10 minutes. The honeypot
		// does not stop direct POSTs and reCAPTCHA is optional - this does.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( $ip ) {
			$rate_key = 'raq_rate_' . md5( $ip );
			$count    = (int) get_transient( $rate_key );
			if ( $count >= 5 ) {
				return new WP_Error( 'raq_rate', __( 'Too many requests. Please try again in a few minutes.', 'request-a-quote-for-woocommerce' ) );
			}
			set_transient( $rate_key, $count + 1, 10 * MINUTE_IN_SECONDS );
		}

		// reCAPTCHA v3 (only when both keys are configured).
		$captcha = self::verify_recaptcha( isset( $post['raq_recaptcha_token'] ) ? $post['raq_recaptcha_token'] : '' );
		if ( is_wp_error( $captcha ) ) {
			return $captcha;
		}

		// The list must not be empty.
		$items = RAQ_Quote_List::get_display_items();
		if ( empty( $items ) ) {
			return new WP_Error( 'raq_empty', __( 'Your quote list is empty.', 'request-a-quote-for-woocommerce' ) );
		}

		// Validate + collect the configured fields.
		$fields    = RAQ_Settings::get( 'fields', array() );
		$customer  = array();
		$errors    = array();
		$cc        = isset( $post['phone_cc'] ) ? sanitize_text_field( wp_unslash( $post['phone_cc'] ) ) : '';

		foreach ( (array) $fields as $field ) {
			$key = $field['key'];
			$raw = isset( $post[ $key ] ) ? wp_unslash( $post[ $key ] ) : '';

			$value = self::sanitize_field( $field['type'], $raw );

			// Phone (by TYPE, so a renamed phone field still works): digits
			// only - no spaces, letters or symbols - then compose with the
			// country code. Client-side enforces this too; this is the backstop.
			if ( in_array( $field['type'], array( 'tel', 'phone' ), true ) && '' !== $value ) {
				if ( ! preg_match( '/^[0-9]{6,15}$/', $value ) ) {
					/* translators: %s: field label. */
					$errors[] = sprintf( __( '%s must contain digits only (6-15 numbers, no spaces or letters).', 'request-a-quote-for-woocommerce' ), $field['label'] );
					continue;
				}
				if ( '' !== $cc ) {
					$value = trim( $cc . ' ' . $value );
				}
			}

			if ( ! empty( $field['required'] ) && '' === trim( (string) $value ) ) {
				/* translators: %s: field label. */
				$errors[] = sprintf( __( '%s is required.', 'request-a-quote-for-woocommerce' ), $field['label'] );
				continue;
			}

			if ( 'email' === $field['type'] && '' !== $value && ! is_email( $value ) ) {
				$errors[] = __( 'Please enter a valid email address.', 'request-a-quote-for-woocommerce' );
				continue;
			}

			$customer[ $key ] = array(
				'label' => $field['label'],
				'value' => $value,
			);
		}

		if ( $errors ) {
			return new WP_Error( 'raq_validation', implode( ' ', $errors ) );
		}

		// Per-recipient throttle: the confirmation email goes to an unverified
		// address, so cap how often any one address can be targeted (stops a
		// rotating-IP attacker using the site to spam a victim's inbox).
		$cust_email = isset( $customer['email']['value'] ) ? strtolower( (string) $customer['email']['value'] ) : '';
		if ( $cust_email ) {
			$em_key   = 'raq_rate_em_' . md5( $cust_email );
			$em_count = (int) get_transient( $em_key );
			if ( $em_count >= 3 ) {
				return new WP_Error( 'raq_rate', __( 'Too many requests for this email address. Please try again later.', 'request-a-quote-for-woocommerce' ) );
			}
			set_transient( $em_key, $em_count + 1, HOUR_IN_SECONDS );
		}

		// Attachment (optional).
		$attachment = null;
		if ( RAQ_Settings::get( 'attachments_enabled', false ) && ! empty( $files['raq_attachment']['name'] ) ) {
			$attachment = self::handle_attachment( $files['raq_attachment'] );
			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}
		}

		// Build the line-item snapshot (frozen at submission time).
		$line_items = array();
		foreach ( $items as $item ) {
			$product = $item['product'];
			if ( $product->is_type( 'variation' ) ) {
				$product_id   = $product->get_parent_id();
				$variation_id = $product->get_id();
			} else {
				$product_id   = $product->get_id();
				$variation_id = 0;
			}
			$line_items[] = array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'name'         => $item['name'],
				'sku'          => $product->get_sku(),
				'qty'          => $item['qty'],
				'variation'    => $item['variation'],
			);
		}

		// Customer-facing reference (our own counter option - not a WC write).
		$reference = '';
		if ( RAQ_Settings::get( 'auto_numbering', true ) ) {
			$reference = self::next_reference();
		}

		$title = self::build_title( $customer, $reference );

		$quote_id = wp_insert_post(
			array(
				'post_type'   => RAQ_CPT::POST_TYPE,
				'post_status' => RAQ_CPT::STATUS_NEW,
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $quote_id ) ) {
			return $quote_id;
		}

		update_post_meta( $quote_id, '_raq_customer', $customer );
		update_post_meta( $quote_id, '_raq_items', $line_items );
		update_post_meta( $quote_id, '_raq_status', RAQ_CPT::STATUS_NEW );
		update_post_meta( $quote_id, '_raq_source_url', esc_url_raw( wp_get_referer() ? wp_get_referer() : '' ) );
		if ( $reference ) {
			update_post_meta( $quote_id, '_raq_reference', $reference );
		}
		if ( $attachment ) {
			update_post_meta( $quote_id, '_raq_attachment', $attachment );
		}

		// Clear the visitor's list now the quote is captured.
		RAQ_Quote_List::clear();

		/**
		 * Fires after a quote is successfully created. Stage 4 hangs the
		 * customer + admin emails off this.
		 *
		 * @param int   $quote_id The new quote post ID.
		 * @param array $customer Customer fields.
		 */
		do_action( 'raq_quote_created', $quote_id, $customer );

		return $quote_id;
	}

	/**
	 * Sanitise a field value by its type.
	 *
	 * @param string $type Field type.
	 * @param mixed  $raw  Raw value.
	 * @return string
	 */
	protected static function sanitize_field( $type, $raw ) {
		switch ( $type ) {
			case 'email':
				return sanitize_email( $raw );
			case 'textarea':
				return sanitize_textarea_field( $raw );
			default:
				return sanitize_text_field( $raw );
		}
	}

	/**
	 * Build a readable post title.
	 *
	 * @param array  $customer  Customer fields.
	 * @param string $reference Reference string.
	 * @return string
	 */
	protected static function build_title( $customer, $reference ) {
		$name = '';
		if ( isset( $customer['company']['value'] ) && '' !== $customer['company']['value'] ) {
			$name = $customer['company']['value'];
		} elseif ( isset( $customer['name']['value'] ) ) {
			$name = $customer['name']['value'];
		}
		$name = $name ? $name : __( 'Quote', 'request-a-quote-for-woocommerce' );
		return $reference ? $reference . ' - ' . $name : $name;
	}

	/**
	 * Atomically increment and return the next reference (e.g. RAQ-0001).
	 *
	 * @return string
	 */
	protected static function next_reference() {
		$next = (int) get_option( self::COUNTER_OPTION, 0 ) + 1;
		update_option( self::COUNTER_OPTION, $next );
		$prefix = RAQ_Settings::get( 'number_prefix', 'RAQ-' );
		return $prefix . str_pad( (string) $next, 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Verify a reCAPTCHA v3 token. No-op (pass) when keys are not configured.
	 *
	 * @param string $token The client token.
	 * @return true|WP_Error
	 */
	protected static function verify_recaptcha( $token ) {
		$site   = RAQ_Settings::get( 'recaptcha_site_key', '' );
		$secret = RAQ_Settings::get( 'recaptcha_secret_key', '' );
		if ( '' === $site || '' === $secret ) {
			return true; // Not configured - honeypot still ran.
		}

		$token = sanitize_text_field( $token );
		if ( '' === $token ) {
			return new WP_Error( 'raq_captcha', __( 'Spam check failed. Please reload and try again.', 'request-a-quote-for-woocommerce' ) );
		}

		$response = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return true; // Network failure - do not block a genuine customer.
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['success'] ) || ( isset( $body['score'] ) && (float) $body['score'] < 0.5 ) ) {
			return new WP_Error( 'raq_captcha', __( 'Spam check failed. Please try again.', 'request-a-quote-for-woocommerce' ) );
		}

		return true;
	}

	/**
	 * Handle the uploaded attachment into a protected sub-directory with a
	 * sanitised, unguessable filename.
	 *
	 * @param array $file A single $_FILES entry.
	 * @return array|WP_Error File data (path + url) or error.
	 */
	protected static function handle_attachment( $file ) {
		$max_mb  = (int) RAQ_Settings::get( 'attachments_max_mb', 5 );
		$allowed = array_filter( array_map( 'trim', explode( ',', (string) RAQ_Settings::get( 'attachments_types', '' ) ) ) );

		if ( ! empty( $file['size'] ) && $file['size'] > $max_mb * 1024 * 1024 ) {
			/* translators: %d: max size in MB. */
			return new WP_Error( 'raq_file_size', sprintf( __( 'The attachment exceeds the %d MB limit.', 'request-a-quote-for-woocommerce' ), $max_mb ) );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( $allowed && ! in_array( $ext, $allowed, true ) ) {
			return new WP_Error( 'raq_file_type', __( 'That file type is not allowed.', 'request-a-quote-for-woocommerce' ) );
		}

		// Hard blocklist - never accepted even if an admin adds them to the
		// allowed list: executable/server-side types (RCE if the upload dir is
		// ever web-served) and markup types that execute script when viewed.
		$blocked = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd', 'js', 'mjs', 'html', 'htm', 'xhtml', 'svg', 'svgz', 'swf', 'htaccess' );
		if ( in_array( $ext, $blocked, true ) ) {
			return new WP_Error( 'raq_file_type', __( 'That file type is not allowed.', 'request-a-quote-for-woocommerce' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		// wp_handle_upload enforces WP's GLOBAL mime whitelist unless we pass a
		// `mimes` override - without this, B2B / CAD types (dwg, step, igs...)
		// the admin explicitly allowed would still be rejected.
		$overrides = array(
			'test_form'                => false,
			'unique_filename_callback' => array( __CLASS__, 'random_filename' ),
		);
		$mimes = self::allowed_mimes( $allowed );
		if ( ! empty( $mimes ) ) {
			$overrides['mimes'] = $mimes;
		}

		$dir_filter = array( __CLASS__, 'attachment_upload_dir' );
		add_filter( 'upload_dir', $dir_filter );
		$moved = wp_handle_upload( $file, $overrides );
		remove_filter( 'upload_dir', $dir_filter );

		if ( isset( $moved['error'] ) ) {
			return new WP_Error( 'raq_upload', $moved['error'] );
		}

		self::protect_dir( dirname( $moved['file'] ) );

		return array(
			'file' => $moved['file'],
			'url'  => $moved['url'],
			'type' => isset( $moved['type'] ) ? $moved['type'] : '',
			'name' => sanitize_file_name( $file['name'] ),
		);
	}

	/**
	 * Map allowed extensions to mime types for the upload override. Unknown
	 * (e.g. CAD) extensions default to application/octet-stream so they pass
	 * WP's ext-vs-content check instead of being blocked.
	 *
	 * @param array $exts Allowed extensions.
	 * @return array ext => mime
	 */
	protected static function allowed_mimes( $exts ) {
		$map = array(
			'pdf'  => 'application/pdf',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'  => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'csv'  => 'text/csv',
			'txt'  => 'text/plain',
			'zip'  => 'application/zip',
			'rar'  => 'application/vnd.rar',
			'ai'   => 'application/postscript',
			'eps'  => 'application/postscript',
		);

		$mimes = array();
		foreach ( (array) $exts as $ext ) {
			$ext = strtolower( trim( $ext ) );
			if ( '' === $ext ) {
				continue;
			}
			$mimes[ $ext ] = isset( $map[ $ext ] ) ? $map[ $ext ] : 'application/octet-stream';
		}
		return $mimes;
	}

	/**
	 * Route quote uploads into uploads/raq-quotes.
	 *
	 * @param array $dirs Upload dir data.
	 * @return array
	 */
	public static function attachment_upload_dir( $dirs ) {
		$sub           = '/raq-quotes';
		$dirs['path']  = $dirs['basedir'] . $sub;
		$dirs['url']   = $dirs['baseurl'] . $sub;
		$dirs['subdir'] = $sub;
		return $dirs;
	}

	/**
	 * Generate an unguessable filename, keeping the extension.
	 *
	 * @param string $dir  Directory.
	 * @param string $name Original name.
	 * @param string $ext  Extension.
	 * @return string
	 */
	public static function random_filename( $dir, $name, $ext ) {
		return wp_generate_password( 20, false, false ) . $ext;
	}

	/**
	 * Drop a deny rule + index into the upload dir (Apache). Nginx needs a
	 * server rule - flagged as a QA/deploy item.
	 *
	 * @param string $path Directory path.
	 */
	protected static function protect_dir( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}
		if ( ! file_exists( $path . '/index.html' ) ) {
			@file_put_contents( $path . '/index.html', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- best-effort hardening.
		}
		if ( ! file_exists( $path . '/.htaccess' ) ) {
			@file_put_contents( $path . '/.htaccess', "Options -Indexes\ndeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- best-effort hardening.
		}
	}
}
