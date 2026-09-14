<?php
/**
 * The quote request form + `[raq_quote_form]` shortcode.
 *
 * Renders the quote-list summary followed by the request form, built from the
 * configurable field set. The same form markup is reused by the drawer when
 * the submit mode is "drawer".
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Form
 */
class RAQ_Form {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_shortcode( 'raq_quote_form', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * `[raq_quote_form]` - list summary + request form.
	 *
	 * @return string
	 */
	public static function shortcode() {
		// Auto-remember which page holds the shortcode, so the redirect target
		// and the drawer CTA work without a manual "Quote Page" setting.
		if ( is_singular() ) {
			$page_id = get_the_ID();
			if ( $page_id && (int) get_option( 'raq_quote_page_id' ) !== (int) $page_id ) {
				update_option( 'raq_quote_page_id', (int) $page_id );
			}
		}

		ob_start();

		$flash = self::flash();
		if ( '' !== $flash ) {
			echo $flash; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}

		$title = RAQ_Settings::get( 'page_title', __( 'Request a Quote', 'request-a-quote-for-woocommerce' ) );
		$intro = RAQ_Settings::get( 'page_intro', '' );

		echo '<div class="raq-quote-page">';

		// Page header: bold title + supporting description (both configurable
		// in Quotes > Settings > General).
		echo '<header class="raq-page-head">';
		if ( '' !== trim( (string) $title ) ) {
			echo '<h1 class="raq-page-title">' . esc_html( $title ) . '</h1>';
		}
		if ( '' !== trim( (string) $intro ) ) {
			echo '<p class="raq-page-desc">' . esc_html( $intro ) . '</p>';
		}
		echo '</header>';

		echo '<div class="raq-quote-page__summary">';
		echo self::summary_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
		echo '</div>';
		echo self::form_html( 'page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Success / error flash message from the non-JS redirect.
	 *
	 * @return string
	 */
	protected static function flash() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display flags after a redirect.
		if ( isset( $_GET['raq_success'] ) ) {
			return '<div class="raq-notice raq-notice--success">' . esc_html__( 'Thank you - your quote request has been sent. We will get back to you shortly.', 'request-a-quote-for-woocommerce' ) . '</div>';
		}
		if ( isset( $_GET['raq_error'] ) ) {
			return '<div class="raq-notice raq-notice--error">' . esc_html( sanitize_text_field( wp_unslash( $_GET['raq_error'] ) ) ) . '</div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return '';
	}

	/**
	 * Read-only summary of the current quote list.
	 *
	 * @return string
	 */
	public static function summary_html() {
		/*
		 * CACHE SAFETY - the shell only. Reading the quote list here touches the
		 * WooCommerce session, which both leaks the visitor's items into a cached
		 * page and stops the page being cached at all. On a live site that left
		 * the Quote Page, the conversion page, rendering in 4.2s every time while
		 * every other page served in 0.1s.
		 *
		 * The real rows come from raq_refresh. See summary_items_html().
		 */
		return '<h2 class="raq-section-title">' . esc_html__( 'Your Quote', 'request-a-quote-for-woocommerce' ) . '</h2>'
			. '<div class="raq-quote-page__summary" data-raq-summary="1">'
			. '<p class="raq-quote-page__empty">' . esc_html__( 'Your quote list is empty. Browse the catalogue and add products to request a quote.', 'request-a-quote-for-woocommerce' ) . '</p>'
			. '</div>';
	}

	/**
	 * The rows themselves. Session-dependent, so this is only ever reached
	 * through the raq_refresh AJAX payload, never printed into page HTML.
	 *
	 * @return string
	 */
	public static function summary_items_html() {
		$items = RAQ_Quote_List::get_display_items();
		if ( empty( $items ) ) {
			return '<p class="raq-quote-page__empty">' . esc_html__( 'Your quote list is empty. Browse the catalogue and add products to request a quote.', 'request-a-quote-for-woocommerce' ) . '</p>';
		}

		$show_qty = RAQ_Settings::get( 'qty_selector', true );

		$rows = '';
		foreach ( $items as $item ) {
			$thumb    = '<td class="raq-sum__thumb">' . $item['thumb'] . '</td>'; // wc image = safe HTML.
			$qty_cell = $show_qty ? sprintf( '<td class="raq-sum__qty">%d</td>', (int) $item['qty'] ) : '';
			$rows    .= '<tr>' . $thumb . '<td class="raq-sum__name">' . esc_html( $item['name'] ) . '</td>' . $qty_cell . '</tr>';
		}

		$qty_head = $show_qty ? '<th>' . esc_html__( 'Qty', 'request-a-quote-for-woocommerce' ) . '</th>' : '';

		return '<table class="raq-sum"><thead><tr><th class="raq-sum__thumb"></th><th>' . esc_html__( 'Product', 'request-a-quote-for-woocommerce' ) . '</th>' . $qty_head . '</tr></thead><tbody>'
			. $rows
			. '</tbody></table>';
	}

	/**
	 * The request form.
	 *
	 * @param string $context 'page' (Quote Page, with section heading) or
	 *                        'drawer' (compact, no heading).
	 * @return string
	 */
	public static function form_html( $context = 'page' ) {
		$fields         = RAQ_Settings::get( 'fields', array() );
		$recaptcha_site = RAQ_Settings::get( 'recaptcha_site_key', '' );

		ob_start();
		if ( 'page' === $context ) {
			echo '<h2 class="raq-section-title">' . esc_html__( 'Your details', 'request-a-quote-for-woocommerce' ) . '</h2>';
		}
		?>
		<form class="raq-quote-form-full" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $recaptcha_site ? ' data-recaptcha="' . esc_attr( $recaptcha_site ) . '"' : ''; ?>>
			<input type="hidden" name="action" value="raq_submit_form">
			<?php wp_nonce_field( 'raq_submit_form', 'raq_form_nonce' ); ?>
			<input type="hidden" name="raq_recaptcha_token" value="">

			<?php // Honeypot - visually hidden, must stay empty. ?>
			<div class="raq-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;">
				<label><?php esc_html_e( 'Leave this field empty', 'request-a-quote-for-woocommerce' ); ?>
					<input type="text" name="raq_hp" tabindex="-1" autocomplete="off" value="">
				</label>
			</div>

			<?php foreach ( (array) $fields as $field ) : ?>
				<?php echo self::field_html( $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method. ?>
			<?php endforeach; ?>

			<?php if ( RAQ_Settings::get( 'attachments_enabled', false ) ) : ?>
				<p class="raq-field raq-field--file">
					<label for="raq_attachment"><?php esc_html_e( 'Attachment', 'request-a-quote-for-woocommerce' ); ?></label>
					<input type="file" id="raq_attachment" name="raq_attachment">
					<span class="raq-field__hint">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: allowed types, 2: max size MB. */
								__( 'Allowed: %1$s. Max %2$d MB.', 'request-a-quote-for-woocommerce' ),
								RAQ_Settings::get( 'attachments_types', '' ),
								(int) RAQ_Settings::get( 'attachments_max_mb', 5 )
							)
						);
						?>
					</span>
				</p>
			<?php endif; ?>

			<p class="raq-form__actions">
				<button type="submit" class="button alt raq-submit">
					<?php esc_html_e( 'Submit quote request', 'request-a-quote-for-woocommerce' ); ?>
				</button>
			</p>
			<div class="raq-form__msg" role="alert" aria-live="assertive"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a single field.
	 *
	 * @param array $field Field config.
	 * @return string
	 */
	protected static function field_html( $field ) {
		$key      = isset( $field['key'] ) ? $field['key'] : '';
		$label    = isset( $field['label'] ) ? $field['label'] : $key;
		$type     = isset( $field['type'] ) ? $field['type'] : 'text';
		$required = ! empty( $field['required'] );
		$id       = 'raq_' . $key;
		$req_attr = $required ? ' required' : '';
		$req_mark = $required ? ' <span class="raq-req">*</span>' : '';

		ob_start();
		// DIV, not P: the country-code dropdown contains a <ul>, and the HTML
		// parser force-closes a <p> at any list - that split the phone field
		// and dropped its input into the next grid cell (page AND drawer).
		echo '<div class="raq-field raq-field--' . esc_attr( $type ) . '">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $req_mark . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $req_mark is static safe markup.

		switch ( $type ) {
			case 'textarea':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" rows="4"' . esc_attr( $req_attr ) . '></textarea>';
				break;

			case 'email':
				echo '<input type="email" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '"' . esc_attr( $req_attr ) . '>';
				break;

			case 'tel':
			case 'phone':
				$codes   = self::dial_codes();
				$default = '+60';
				if ( ! isset( $codes[ $default ] ) ) {
					$default = key( $codes );
				}
				$def_flag = isset( $codes[ $default ][0] ) ? $codes[ $default ][0] : '';

				echo '<span class="raq-phone">';
				// Custom country-code dropdown: closed = flag + code; open = names.
				echo '<span class="raq-cc" data-value="' . esc_attr( $default ) . '">';
				echo '<input type="hidden" name="phone_cc" class="raq-cc__value" value="' . esc_attr( $default ) . '">';
				echo '<button type="button" class="raq-cc__toggle" aria-label="' . esc_attr__( 'Country code', 'request-a-quote-for-woocommerce' ) . '" aria-haspopup="listbox" aria-expanded="false">';
				echo '<span class="raq-cc__flag">' . esc_html( $def_flag ) . '</span><span class="raq-cc__code">' . esc_html( $default ) . '</span><span class="raq-cc__caret" aria-hidden="true">&#9662;</span>';
				echo '</button>';
				echo '<ul class="raq-cc__list" role="listbox" hidden>';
				foreach ( $codes as $code => $meta ) {
					$flag = isset( $meta[0] ) ? $meta[0] : '';
					$name = isset( $meta[1] ) ? $meta[1] : '';
					echo '<li class="raq-cc__opt" role="option" data-value="' . esc_attr( $code ) . '" data-flag="' . esc_attr( $flag ) . '">';
					echo '<span class="raq-cc__flag">' . esc_html( $flag ) . '</span> ' . esc_html( $name ) . ' <span class="raq-cc__optcode">(' . esc_html( $code ) . ')</span>';
					echo '</li>';
				}
				echo '</ul>';
				echo '</span>';
				echo '<input type="tel" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" inputmode="numeric" autocomplete="tel" pattern="[0-9]{6,15}" title="' . esc_attr__( 'Digits only (6-15 numbers), no spaces or letters.', 'request-a-quote-for-woocommerce' ) . '"' . esc_attr( $req_attr ) . '>';
				echo '</span>';
				break;

			case 'country':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '"' . esc_attr( $req_attr ) . '>';
				echo '<option value="">' . esc_html__( 'Select a country', 'request-a-quote-for-woocommerce' ) . '</option>';
				if ( function_exists( 'WC' ) && WC()->countries ) {
					foreach ( WC()->countries->get_countries() as $cc => $cname ) {
						echo '<option value="' . esc_attr( $cname ) . '">' . esc_html( $cname ) . '</option>';
					}
				}
				echo '</select>';
				break;

			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '"' . esc_attr( $req_attr ) . '>';
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Phone country-calling-code list. Filterable so any client can extend it.
	 *
	 * @return array code => label
	 */
	public static function dial_codes() {
		// code => [ flag, country name ]. The custom dropdown shows the flag +
		// code when closed and the full name when open. Flags render on
		// macOS/iOS/Android; Windows shows the country letters (still legible).
		$codes = array(
			'+60'  => array( '🇲🇾', 'Malaysia' ),
			'+65'  => array( '🇸🇬', 'Singapore' ),
			'+62'  => array( '🇮🇩', 'Indonesia' ),
			'+66'  => array( '🇹🇭', 'Thailand' ),
			'+84'  => array( '🇻🇳', 'Vietnam' ),
			'+63'  => array( '🇵🇭', 'Philippines' ),
			'+855' => array( '🇰🇭', 'Cambodia' ),
			'+95'  => array( '🇲🇲', 'Myanmar' ),
			'+673' => array( '🇧🇳', 'Brunei' ),
			'+86'  => array( '🇨🇳', 'China' ),
			'+852' => array( '🇭🇰', 'Hong Kong' ),
			'+886' => array( '🇹🇼', 'Taiwan' ),
			'+81'  => array( '🇯🇵', 'Japan' ),
			'+82'  => array( '🇰🇷', 'South Korea' ),
			'+91'  => array( '🇮🇳', 'India' ),
			'+92'  => array( '🇵🇰', 'Pakistan' ),
			'+880' => array( '🇧🇩', 'Bangladesh' ),
			'+94'  => array( '🇱🇰', 'Sri Lanka' ),
			'+971' => array( '🇦🇪', 'UAE' ),
			'+966' => array( '🇸🇦', 'Saudi Arabia' ),
			'+974' => array( '🇶🇦', 'Qatar' ),
			'+973' => array( '🇧🇭', 'Bahrain' ),
			'+965' => array( '🇰🇼', 'Kuwait' ),
			'+968' => array( '🇴🇲', 'Oman' ),
			'+90'  => array( '🇹🇷', 'Turkey' ),
			'+972' => array( '🇮🇱', 'Israel' ),
			'+20'  => array( '🇪🇬', 'Egypt' ),
			'+27'  => array( '🇿🇦', 'South Africa' ),
			'+234' => array( '🇳🇬', 'Nigeria' ),
			'+254' => array( '🇰🇪', 'Kenya' ),
			'+61'  => array( '🇦🇺', 'Australia' ),
			'+64'  => array( '🇳🇿', 'New Zealand' ),
			'+44'  => array( '🇬🇧', 'United Kingdom' ),
			'+353' => array( '🇮🇪', 'Ireland' ),
			'+33'  => array( '🇫🇷', 'France' ),
			'+49'  => array( '🇩🇪', 'Germany' ),
			'+34'  => array( '🇪🇸', 'Spain' ),
			'+39'  => array( '🇮🇹', 'Italy' ),
			'+31'  => array( '🇳🇱', 'Netherlands' ),
			'+32'  => array( '🇧🇪', 'Belgium' ),
			'+41'  => array( '🇨🇭', 'Switzerland' ),
			'+43'  => array( '🇦🇹', 'Austria' ),
			'+46'  => array( '🇸🇪', 'Sweden' ),
			'+47'  => array( '🇳🇴', 'Norway' ),
			'+45'  => array( '🇩🇰', 'Denmark' ),
			'+358' => array( '🇫🇮', 'Finland' ),
			'+351' => array( '🇵🇹', 'Portugal' ),
			'+30'  => array( '🇬🇷', 'Greece' ),
			'+48'  => array( '🇵🇱', 'Poland' ),
			'+7'   => array( '🇷🇺', 'Russia' ),
			'+1'   => array( '🇺🇸', 'USA / Canada' ),
			'+52'  => array( '🇲🇽', 'Mexico' ),
			'+55'  => array( '🇧🇷', 'Brazil' ),
			'+54'  => array( '🇦🇷', 'Argentina' ),
			'+56'  => array( '🇨🇱', 'Chile' ),
			'+57'  => array( '🇨🇴', 'Colombia' ),
		);
		return apply_filters( 'raq_dial_codes', $codes );
	}
}
