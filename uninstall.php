<?php
/**
 * Preserve snapshots unless cleanup was enabled and all owned prices restored.
 *
 * @package ExinBlackFriday
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'exin_bf_delete_data', false ) ) {
	return;
}

global $wpdb;
$items = $wpdb->prefix . 'exin_bf_items';
// Only our own tables; no product/order data is ever deleted on uninstall.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Uninstall must inspect and remove only plugin-owned storage without caches.
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $items ) ) ) === $items ) {
	$owned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE (expected IS NOT NULL AND expected NOT IN ('', 'null', '[]')) OR (intent IS NOT NULL AND intent NOT IN ('', 'null', '[]'))", $items ) );
	if ( $owned > 0 ) {
		return;
	}
}
foreach ( array( 'campaigns', 'items', 'locks', 'audit' ) as $suffix ) {
	$table = $wpdb->prefix . 'exin_bf_' . $suffix;
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
}
$options = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( 'exin_bf_' ) . '%',
		$wpdb->esc_like( '_transient_exin_bf_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_exin_bf_' ) . '%'
	)
);
foreach ( $options as $option ) {
	delete_option( $option );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
