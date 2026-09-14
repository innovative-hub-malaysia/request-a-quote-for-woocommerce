<?php
/**
 * Fired during plugin deactivation.
 *
 * HARD RULE: deactivating returns the store exactly as it was. Because every
 * e-commerce lockdown is a runtime hook/filter (never a DB write), simply not
 * loading those hooks restores WooCommerce. Here we only flush rewrite rules
 * so our CPT routes are cleaned up. We do NOT delete any data on deactivation.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Deactivator
 */
class RAQ_Deactivator {

	/**
	 * Run on deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
