<?php
/**
 * Updates.
 *
 * This plugin is distributed by Innovative Hub from GitHub, NOT hosted on
 * wordpress.org. The bundled plugin-update-checker library reads the GitHub
 * Releases of the repository below, offers a new version through the normal
 * WordPress update flow (Plugins screen notice + one-click update), and keeps
 * a same-named wordpress.org plugin from ever being offered in its place: it
 * owns the update-transient entry and the "View details" popup for our slug
 * and excludes the plugin from the wp.org update request.
 *
 * Auto-updates are ON by default so every site picks up a Release on its own.
 * Define RAQ_DISABLE_AUTO_UPDATE as true in wp-config.php to opt a site out.
 *
 * Releasing: bump the Version header + RAQ_VERSION, tag `vX.Y.Z`, and publish a
 * GitHub Release with the built zip attached (bin/build-release.sh does it).
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Class RAQ_Updates
 */
class RAQ_Updates {

	/**
	 * Public GitHub repository the updates are served from.
	 */
	const REPO_URL = 'https://github.com/innovative-hub-malaysia/request-a-quote-for-woocommerce/';

	/**
	 * Hook registration (runs on every load, independent of WooCommerce).
	 */
	public static function init() {
		require_once RAQ_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

		$checker = PucFactory::buildUpdateChecker( self::REPO_URL, RAQ_PLUGIN_FILE, 'request-a-quote-for-woocommerce' );
		// Install the zip attached to the Release, never GitHub's source archive
		// (its top folder carries the tag name and would install as a new plugin).
		$checker->getVcsApi()->enableReleaseAssets();

		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
	}

	/**
	 * Auto-update this plugin unless the site opted out.
	 *
	 * @param bool|null $update Whether to auto-update.
	 * @param object    $item   The update offer.
	 * @return bool|null
	 */
	public static function auto_update( $update, $item ) {
		if ( ! is_object( $item ) || ! isset( $item->plugin ) || RAQ_PLUGIN_BASENAME !== $item->plugin ) {
			return $update;
		}
		return ! ( defined( 'RAQ_DISABLE_AUTO_UPDATE' ) && RAQ_DISABLE_AUTO_UPDATE );
	}
}
