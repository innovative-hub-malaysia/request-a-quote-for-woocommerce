<?php
/**
 * Central settings store.
 *
 * The whole plugin reads its config from ONE option (`raq_settings`, an array).
 * Sane defaults are baked in so a brand-new client works out of the box and
 * nothing is client-specific in code - each client is just configuration.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Settings
 */
class RAQ_Settings {

	const OPTION_KEY = 'raq_settings';

	/**
	 * Cached settings for the request.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Default settings. The single source of truth for shipped defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// --- General / Master Switch ---------------------------------.
			'master_switch'        => false, // Off until the admin flips it - never auto-lock a live store.
			'add_to_quote_label'   => __( 'Add to Quote', 'request-a-quote-for-woocommerce' ),
			'submit_mode'          => 'page', // 'page' (route to Quote Page) | 'drawer' (short form in drawer). Quote Page is auto-detected from the shortcode.
			'require_login'        => false,
			'page_title'           => __( 'Request a Quote', 'request-a-quote-for-woocommerce' ),
			'page_intro'           => __( 'Fill in your details below and our team will get back to you shortly.', 'request-a-quote-for-woocommerce' ),

			// --- Appearance ----------------------------------------------.
			'auto_button'          => true,       // Auto-render the button on the product page (off = place via shortcode).
			'qty_selector'         => true,       // Show the quantity selector on products.
			'btn_bg'               => '#1c1a17',  // Add-to-Quote button background.
			'btn_text'             => '#ffffff',  // Add-to-Quote button text.
			'btn_size'             => 'medium',   // small | medium | large.
			'fab_position'         => 'bottom-right', // bottom-right|bottom-left|top-right|top-left|hidden.
			'custom_css'           => '',

			// --- Fields --------------------------------------------------.
			// Default B2B field set. Admin can add / remove / reorder later.
			'fields'               => self::default_fields(),

			// --- After submit --------------------------------------------.
			// 'countdown' = thank-you panel in place, counts down, then home
			// (the pre-1.2.0 behaviour - the default so an auto-update never
			// changes an installed site). 'page' = a chosen page, 'url' = any
			// same-site or external URL. Delay applies to 'countdown' only.
			'after_submit'         => 'countdown',
			'redirect_page_id'     => 0,
			'redirect_url'         => '',
			'redirect_delay'       => 5,

			// --- Attachments ---------------------------------------------.
			'attachments_enabled'  => false,
			'attachments_types'    => 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
			'attachments_max_mb'   => 5,

			// --- Emails --------------------------------------------------.
			'admin_recipients'     => '', // Empty = fall back to site admin email at send time.

			// --- Anti-spam (honeypot is always on, not a setting) --------.
			'recaptcha_site_key'   => '',
			'recaptcha_secret_key' => '',

			// --- Analytics / GA4 -----------------------------------------.
			'ga4_enabled'          => true,
			'ga4_measurement_id'   => '', // Used only when no GTM dataLayer is present.

			// --- Advanced ------------------------------------------------.
			'auto_numbering'       => true,  // RAQ-0001 style customer-facing reference (built in Stage 5).
			'number_prefix'        => 'RAQ-',
			'purge_on_uninstall'   => false, // Non-destructive default: keep data on delete.
		);
	}

	/**
	 * Default B2B field set.
	 *
	 * @return array
	 */
	public static function default_fields() {
		return array(
			array(
				'key'      => 'name',
				'label'    => __( 'Name', 'request-a-quote-for-woocommerce' ),
				'type'     => 'text',
				'required' => true,
			),
			array(
				'key'      => 'company',
				'label'    => __( 'Company', 'request-a-quote-for-woocommerce' ),
				'type'     => 'text',
				'required' => false,
			),
			array(
				'key'      => 'email',
				'label'    => __( 'Email', 'request-a-quote-for-woocommerce' ),
				'type'     => 'email',
				'required' => true,
			),
			array(
				'key'      => 'phone',
				'label'    => __( 'Phone', 'request-a-quote-for-woocommerce' ),
				'type'     => 'tel', // Includes the country-code selector, so a separate Country field is not needed by default.
				'required' => true,
			),
			array(
				'key'      => 'message',
				'label'    => __( 'Message', 'request-a-quote-for-woocommerce' ),
				'type'     => 'textarea',
				'required' => false,
			),
		);
	}

	/**
	 * Get all settings (defaults merged with saved values).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION_KEY, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Persist a full settings array (already sanitised by the caller).
	 *
	 * @param array $settings Settings to save.
	 */
	public static function save( array $settings ) {
		update_option( self::OPTION_KEY, $settings );
		self::$cache = null;
	}

	/**
	 * Is the Master Switch on?
	 *
	 * @return bool
	 */
	public static function is_active() {
		return (bool) self::get( 'master_switch', false );
	}

	/**
	 * Seed defaults only if the option does not exist yet.
	 */
	public static function maybe_seed_defaults() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::defaults() );
		}
	}
}
