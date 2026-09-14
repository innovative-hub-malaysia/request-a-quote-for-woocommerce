<?php
/**
 * Uninstall handler.
 *
 * Runs only when the user deletes the plugin from wp-admin. Respects the
 * "purge data on uninstall" setting: by default we KEEP quote data and options
 * (safe, non-destructive). Only when the admin has explicitly opted in do we
 * remove our CPT records and options.
 *
 * @package RequestAQuoteForWooCommerce
 */

// Exit if not called by WordPress uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$raq_settings   = get_option( 'raq_settings', array() );
$raq_should_purge = ! empty( $raq_settings['purge_on_uninstall'] );

if ( ! $raq_should_purge ) {
	return;
}

// Delete all raq_quote records.
$raq_quotes = get_posts(
	array(
		'post_type'      => 'raq_quote',
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
		'suppress_filters' => true,
	)
);

foreach ( $raq_quotes as $raq_quote_id ) {
	wp_delete_post( $raq_quote_id, true );
}

// Remove our options.
delete_option( 'raq_settings' );
delete_option( 'raq_version' );
