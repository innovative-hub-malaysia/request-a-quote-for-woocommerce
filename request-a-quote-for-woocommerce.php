<?php
/**
 * Plugin Name:       Request a Quote for WooCommerce
 * Plugin URI:        https://www.innovativehub.com.my/
 * Description:       Turn a WooCommerce store into a B2B request-a-quote catalogue: hide prices, add an Add-to-Quote flow, and manage quote requests from the backend.
 * Version:           1.3.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Innovative Hub
 * Author URI:        https://www.innovativehub.com.my/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       request-a-quote-for-woocommerce
 * Domain Path:       /languages
 *
 * WC requires at least: 6.0
 * WC tested up to:      9.9
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'RAQ_VERSION' ) ) {
	return;
}

define( 'RAQ_VERSION', '1.3.1' );
define( 'RAQ_PLUGIN_FILE', __FILE__ );
define( 'RAQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAQ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RAQ_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'RAQ_MIN_WC_VERSION', '6.0' );

/**
 * Updates - loaded first, independent of WooCommerce, so a new GitHub Release
 * is offered (and auto-installed) even when WooCommerce is missing.
 */
require_once RAQ_PLUGIN_DIR . 'includes/class-raq-updates.php';
RAQ_Updates::init();

/**
 * Hide WooCommerce Analytics + Marketing when the store is in quote mode.
 *
 * Registered here at plugin-load (before `plugins_loaded`), because WooCommerce
 * Admin reads `woocommerce_admin_features` very early - a filter added later
 * (e.g. inside the converter) misses the read and the menus stay. We also
 * remove the menu pages as a fallback. Guarded by the saved Master Switch so it
 * only applies in quote mode.
 */
$raq_boot_settings = get_option( 'raq_settings' );
if ( is_array( $raq_boot_settings ) && ! empty( $raq_boot_settings['master_switch'] ) ) {
	add_filter(
		'woocommerce_admin_features',
		static function ( $features ) {
			return is_array( $features ) ? array_values( array_diff( $features, array( 'analytics', 'marketing' ) ) ) : $features;
		}
	);
	add_action(
		'admin_menu',
		static function () {
			// Top-level menus.
			remove_menu_page( 'wc-admin&path=/analytics/overview' );
			remove_menu_page( 'woocommerce-marketing' );

			// WooCommerce submenu items not relevant to a quote-only store.
			// (Keep Home, Settings, Status, Extensions.)
			$sub = array(
				'wc-orders',                        // Orders (HPOS).
				'edit.php?post_type=shop_order',    // Orders (legacy).
				'edit.php?post_type=shop_coupon',   // Coupons.
				'wc-reports',                       // Reports (legacy).
				'wc-admin&path=/payments/overview', // Payments.
				'wc-admin&path=/payments/connect',
				'wc-admin&path=/wc-pay-welcome-page',
			);
			foreach ( $sub as $slug ) {
				remove_submenu_page( 'woocommerce', $slug );
			}
		},
		99
	);

	// Remove the Payments tab from WooCommerce > Settings (gateways are off).
	add_filter(
		'woocommerce_settings_tabs_array',
		static function ( $tabs ) {
			if ( is_array( $tabs ) && isset( $tabs['checkout'] ) ) {
				unset( $tabs['checkout'] );
			}
			return $tabs;
		},
		100
	);

	// Block direct access to the Payments settings screen (the tab link is gone,
	// but the URL still renders it otherwise).
	add_action(
		'admin_init',
		static function () {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing guard.
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( 'wc-settings' === $page && 'checkout' === $tab ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wc-settings' ) );
				exit;
			}
		}
	);
}

/**
 * Declare compatibility with WooCommerce features.
 *
 * We only declare COMPATIBILITY with HPOS (custom order tables) - we do not
 * store anything in WooCommerce order tables. Quotes live in our own
 * `raq_quote` custom post type, so we are safe either way.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RAQ_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', RAQ_PLUGIN_FILE, true );
		}
	}
);

/**
 * Activation - MUST stay non-destructive.
 *
 * The hard rule: deactivating this plugin returns the store exactly as it was.
 * So activation touches NOTHING in WooCommerce. It only registers our own CPT
 * (so rewrite rules can be flushed) and seeds default options.
 */
register_activation_hook(
	__FILE__,
	static function () {
		require_once RAQ_PLUGIN_DIR . 'includes/class-raq-activator.php';
		RAQ_Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		require_once RAQ_PLUGIN_DIR . 'includes/class-raq-deactivator.php';
		RAQ_Deactivator::deactivate();
	}
);

/**
 * Bootstrap the plugin after all others are loaded, so we can reliably detect
 * WooCommerce. If WooCommerce is missing or too old, we no-op and show an
 * admin notice - never a fatal error.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! raq_woocommerce_ready() ) {
			return;
		}

		require_once RAQ_PLUGIN_DIR . 'includes/class-raq-plugin.php';
		RAQ_Plugin::instance();
	}
);

/**
 * Dependency guard. Returns true only when an active WooCommerce meets our
 * minimum version; otherwise queues an admin notice and returns false.
 *
 * @return bool
 */
function raq_woocommerce_ready() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'raq_notice_missing_woocommerce' );
		return false;
	}

	if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, RAQ_MIN_WC_VERSION, '<' ) ) {
		add_action( 'admin_notices', 'raq_notice_old_woocommerce' );
		return false;
	}

	return true;
}

/**
 * Admin notice: WooCommerce not active.
 */
function raq_notice_missing_woocommerce() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Request a Quote for WooCommerce requires WooCommerce to be installed and active.', 'request-a-quote-for-woocommerce' );
	echo '</p></div>';
}

/**
 * Admin notice: WooCommerce too old.
 */
function raq_notice_old_woocommerce() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	printf(
		/* translators: %s: minimum required WooCommerce version. */
		esc_html__( 'Request a Quote for WooCommerce requires WooCommerce %s or higher.', 'request-a-quote-for-woocommerce' ),
		esc_html( RAQ_MIN_WC_VERSION )
	);
	echo '</p></div>';
}
