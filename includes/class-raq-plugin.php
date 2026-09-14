<?php
/**
 * Main plugin loader (singleton).
 *
 * Wires the always-on modules (settings store, CPT, admin settings page) and -
 * crucially - loads the e-commerce CONVERTER module only when the Master Switch
 * is ON. This "load converter iff master_switch" spine is the architectural
 * decision that makes the hard rule enforceable: switch OFF (or plugin
 * deactivated) => none of the lockdown hooks are ever attached => the store is
 * exactly as WooCommerce left it.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Plugin
 */
final class RAQ_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var RAQ_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Get / create the instance.
	 *
	 * @return RAQ_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Load dependencies and attach modules.
	 */
	protected function boot() {
		$this->includes();

		// WordPress 6.7 warns when a text domain is loaded before `init`, and
		// boot() runs on plugins_loaded. Defer it.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Always-on modules.
		RAQ_CPT::init();

		if ( is_admin() ) {
			RAQ_Settings_Page::init();
			RAQ_Admin_Quotes::init();
			RAQ_Admin_Analytics::init();
			RAQ_Debug::init();
		}

		// Everything below is the converted-store surface. It loads ONLY when
		// the Master Switch is on - the hard-rule spine. (This runs in the
		// admin-ajax context too, so the AJAX endpoints stay available.)
		if ( RAQ_Settings::is_active() ) {
			RAQ_Converter::init();
			RAQ_Ajax::init();
			RAQ_Frontend::init();
			RAQ_Form::init();
			RAQ_Submission::init();
			RAQ_Emails::init();
		}
	}

	/**
	 * Require class files.
	 */
	protected function includes() {
		require_once RAQ_PLUGIN_DIR . 'includes/class-raq-settings.php';
		require_once RAQ_PLUGIN_DIR . 'includes/class-raq-cpt.php';
		require_once RAQ_PLUGIN_DIR . 'includes/class-raq-converter.php';
		require_once RAQ_PLUGIN_DIR . 'includes/class-raq-quote-list.php';
		require_once RAQ_PLUGIN_DIR . 'includes/class-raq-submission.php';
		require_once RAQ_PLUGIN_DIR . 'includes/class-raq-ajax.php';
		require_once RAQ_PLUGIN_DIR . 'includes/frontend/class-raq-frontend.php';
		require_once RAQ_PLUGIN_DIR . 'includes/frontend/class-raq-form.php';
		require_once RAQ_PLUGIN_DIR . 'includes/emails/class-raq-emails.php';
		// The email helper is used by admin screens outside the mailer context,
		// so it must load unconditionally (it has no WC_Email dependency).
		require_once RAQ_PLUGIN_DIR . 'includes/emails/class-raq-email-helper.php';

		if ( is_admin() ) {
			require_once RAQ_PLUGIN_DIR . 'includes/admin/class-raq-settings-page.php';
			require_once RAQ_PLUGIN_DIR . 'includes/admin/class-raq-admin-quotes.php';
			require_once RAQ_PLUGIN_DIR . 'includes/admin/class-raq-admin-analytics.php';
			require_once RAQ_PLUGIN_DIR . 'includes/class-raq-debug.php';
		}
	}

	/**
	 * Load translations. Text domain === plugin slug (locked).
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'request-a-quote-for-woocommerce',
			false,
			dirname( RAQ_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
