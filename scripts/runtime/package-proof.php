<?php
/** Exact release ZIP installation/lifecycle proof on a separate synthetic site. */
global $wpdb;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'exin_test' !== DB_NAME || 'exin_package_' !== $wpdb->prefix ) {
	throw new RuntimeException( 'Package proof requires the isolated exin_package_ site.' );
}
$repository = new \Exin\BlackFriday\Infrastructure\CampaignRepository();
$mode = $args[0] ?? '';
if ( 'prepare' === $mode ) {
	if ( '1.0.1' !== EXIN_BF_VERSION || '3' !== get_option( 'exin_bf_schema_version' ) ) {
		throw new RuntimeException( 'The installed ZIP did not activate the expected version/schema.' );
	}
	$id = $repository->create( array( 'title' => 'EXIN installed ZIP lifecycle proof' ) );
	update_option( 'exin_package_lifecycle', $repository->get( $id ), false );
	echo "ZIP activation created schema 3 and a durable draft.\n";
} elseif ( 'verify' === $mode ) {
	$before = get_option( 'exin_package_lifecycle' );
	if ( ! is_array( $before ) || $before !== $repository->get( $before['id'] ) || 'draft' !== $before['status'] ) {
		throw new RuntimeException( 'Deactivate/reactivate did not preserve the installed ZIP draft.' );
	}
	$file = ( new ReflectionClass( \Exin\BlackFriday\Plugin::class ) )->getFileName();
	if ( ! str_contains( $file, '/package/exin-black-friday/src/Plugin.php' ) || ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		throw new RuntimeException( 'Expected the extracted ZIP code and real HPOS datastore.' );
	}
	echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => $wpdb->db_version(), 'plugin_version' => EXIN_BF_VERSION, 'schema' => get_option( 'exin_bf_schema_version' ), 'hpos' => true, 'class_file' => $file, 'bootstrap_file' => realpath( EXIN_BF_FILE ), 'bootstrap_sha256' => hash_file( 'sha256', EXIN_BF_FILE ), 'activation' => 'passed', 'deactivate_reactivate_preserves_draft' => true, 'test_data' => 'synthetic-only', 'production_access' => false ) ) . "\n";
} else {
	throw new RuntimeException( 'Expected prepare or verify lifecycle phase.' );
}
