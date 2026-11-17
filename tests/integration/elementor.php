<?php
/** Run through wp eval-file against actual Elementor. Never touches the campaign. */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'exin_test' !== DB_NAME ) {
	throw new RuntimeException( 'Elementor tests require the isolated local database.' );
}
$checks = 0;
$failures = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks, &$failures ): void {
	++$checks;
	if ( $condition ) {
		echo "PASS {$message}\n";
	} else {
		++$failures;
		echo "FAIL {$message}\n";
	}
};
if ( ! class_exists( '\Elementor\Plugin' ) ) {
	echo "SKIP Elementor unavailable; install official Elementor in the isolated runtime.\n";
	exit( 2 );
}
$plugin = \Elementor\Plugin::$instance;
$widgets = $plugin->widgets_manager->get_widget_types();
$elements = array();
$repository = new \Exin\BlackFriday\Infrastructure\CampaignRepository();
$campaign = $repository->current();
$badge_id = 0;
if ( $campaign ) {
	foreach ( ( new \Exin\BlackFriday\Integration\PublicProductQuery( $repository ) )->selected_ids( $campaign ) as $id ) {
		if ( wc_get_product( $id ) ) {
			$badge_id = $id;
			break;
		}
	}
}
foreach ( array( 'products', 'featured', 'countdown', 'categories', 'status', 'badge' ) as $index => $kind ) {
	$name = 'exin-bf-' . $kind;
	$assert( isset( $widgets[ $name ] ), 'real Elementor registers ' . $name );
	$data = array( 'id' => 'bf' . str_pad( (string) $index, 5, '0', STR_PAD_LEFT ), 'elType' => 'widget', 'widgetType' => $name, 'settings' => 'badge' === $kind ? array( 'product_id' => $badge_id ) : array(), 'elements' => array() );
	$instance = $plugin->elements_manager->create_element_instance( $data );
	$assert( $instance && $name === $instance->get_name(), 'saved widget re-instantiates its own kind ' . $name );
	$elements[] = $data;
}
$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'EXIN isolated Elementor test', 'post_content' => '' ), true );
if ( is_wp_error( $page_id ) ) {
	throw new \RuntimeException( 'test_page_creation_failed' );
}
try {
	$data = array( array( 'id' => 'bfsection', 'elType' => 'section', 'settings' => array(), 'elements' => array( array( 'id' => 'bfcolumn', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ), 'elements' => $elements ) ) ) );
	update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
	update_post_meta( $page_id, '_elementor_version', ELEMENTOR_VERSION );
	update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	$stored = $plugin->documents->get( $page_id )->get_elements_data();
	$assert( count( $stored[0]['elements'][0]['elements'] ?? array() ) === 6, 'saved Elementor page retains six widgets' );
	$html = $plugin->frontend->get_builder_content_for_display( $page_id, true );
	foreach ( array( 'products', 'featured', 'countdown', 'categories', 'status', 'badge' ) as $kind ) {
		if ( 'badge' === $kind && ! $badge_id ) {
			$assert( ! str_contains( $html, 'exin-bf-badge' ), 'badge with no selected product renders safely empty' );
		} else {
			$assert( str_contains( $html, 'data-widget_type="exin-bf-' . $kind . '.default"' ), 'actual saved page renders ' . $kind . ' widget wrapper' );
		}
	}
	$assert( ! preg_match( '/floor_price|base_price|snapshot|operation_id|last_error|schedule\s*:/', $html ), 'saved Elementor page does not expose private financial fields' );
} finally {
	wp_delete_post( $page_id, true );
}
echo wp_json_encode( array( 'suite' => 'real-elementor', 'version' => ELEMENTOR_VERSION, 'checks' => $checks, 'failures' => $failures ) ) . "\n";
if ( $failures ) {
	exit( 1 );
}
