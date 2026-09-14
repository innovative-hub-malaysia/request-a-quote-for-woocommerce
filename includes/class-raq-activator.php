<?php
/**
 * Fired during plugin activation.
 *
 * HARD RULE: activation must stay non-destructive. It touches NOTHING in
 * WooCommerce - only registers our own CPT so rewrite rules flush cleanly,
 * and seeds default options if none exist yet.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Activator
 */
class RAQ_Activator {

	/**
	 * Run on activation.
	 */
	public static function activate() {
		require_once RAQ_PLUGIN_DIR . 'includes/class-raq-settings.php';
		require_once RAQ_PLUGIN_DIR . 'includes/class-raq-cpt.php';

		// Seed defaults only when the option is absent - never overwrite a
		// returning site's saved config.
		RAQ_Settings::maybe_seed_defaults();

		// Register the CPT so its rewrite rules exist, then flush once.
		RAQ_CPT::register_post_type();
		flush_rewrite_rules();

		update_option( 'raq_version', RAQ_VERSION );
	}
}
