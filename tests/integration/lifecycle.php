<?php
/** Real activation/deactivation/upgrade and safe retention lifecycle. */
use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\Scheduler;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Infrastructure\Migration;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'local' || DB_NAME !== 'exin_test' ) {
	throw new RuntimeException( 'Lifecycle tests require isolated local DB.' );
}
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo 'PASS ' . $message . "\n";
};
$repo = new CampaignRepository();
$campaign = $repo->current();
$count_before = $campaign ? $repo->count_items( $campaign['id'] ) : 0;
$tick_before = as_has_scheduled_action( 'exin_bf_tick', array(), Scheduler::GROUP );
$assert( (bool) $tick_before, 'Watchdog exists before deactivation' );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
deactivate_plugins( 'exin-black-friday/exin-black-friday.php' );
$assert( ! is_plugin_active( 'exin-black-friday/exin-black-friday.php' ), 'WordPress executed deactivation' );
$assert( ! as_has_scheduled_action( 'exin_bf_tick', array(), Scheduler::GROUP ), 'Deactivation unscheduled watchdog' );
$assert( ! $campaign || $repo->count_items( $campaign['id'] ) === $count_before, 'Deactivation preserved campaign items' );
$activated = activate_plugin( 'exin-black-friday/exin-black-friday.php' );
$assert( ! is_wp_error( $activated ) && is_plugin_active( 'exin-black-friday/exin-black-friday.php' ), 'WordPress executed reactivation' );
$scheduler = new Scheduler( $repo, new PriceProcessor( $repo, new Logger() ), new Logger() );
$scheduler->recover();
$assert( (bool) as_has_scheduled_action( 'exin_bf_tick', array(), Scheduler::GROUP ), 'Reactivation recovered watchdog' );
delete_option( 'exin_bf_schema_version' );
Migration::run();
$assert( get_option( 'exin_bf_schema_version' ) === Migration::VERSION, 'Schema upgrade reinstalled version marker' );
$assert( ! $campaign || $repo->count_items( $campaign['id'] ) === $count_before, 'Schema upgrade preserved campaign items' );
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'exin-black-friday/exin-black-friday.php' ); }
delete_option( 'exin_bf_delete_data' );
include EXIN_BF_PATH . 'uninstall.php';
$assert( ! $campaign || $repo->count_items( $campaign['id'] ) === $count_before, 'Default uninstall retained snapshots and campaign data' );
update_option( 'exin_bf_delete_data', true, false );
try {
	include EXIN_BF_PATH . 'uninstall.php';
	$assert( get_option( 'exin_bf_schema_version' ) === Migration::VERSION, 'Opted-in uninstall refused deletion while owned prices remain' );
} finally {
	delete_option( 'exin_bf_delete_data' );
}
echo 'EXIN_LIFECYCLE_RESULT ' . wp_json_encode( array( 'checks' => $checks, 'passed' => $checks, 'failed' => 0 ) ) . "\n";
