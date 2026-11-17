<?php
/**
 * Plugin composition and lifecycle.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday;

use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Infrastructure\Migration;
use Exin\BlackFriday\Application\PriceProcessor;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\Scheduler;

/** Composition root. Integrations contain no financial domain logic. */
final class Plugin {
	/**
	 * Prevent duplicate hooks within one request.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Install versioned plugin tables without altering WooCommerce entities.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Migration::run();
		update_option( 'exin_bf_needs_recovery', 1, false );
	}

	/**
	 * Cancel future work while preserving price ownership and recovery data.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Scheduler::deactivate();
		wp_clear_scheduled_hook( 'exin_bf_watchdog' );
		update_option( 'exin_bf_needs_recovery', 1, false );
	}

	/**
	 * Compose services only after required dependencies are available.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'exin-black-friday', false, dirname( plugin_basename( EXIN_BF_FILE ) ) . '/languages' );
			},
			0
		);
		$errors = Compatibility::errors();
		if ( $errors ) {
			Compatibility::notice( $errors );
			return;
		}
		try {
			if ( Migration::VERSION !== get_option( 'exin_bf_schema_version' ) ) {
				Migration::run();
			}
		} catch ( \Throwable $error ) {
			Compatibility::notice( array( 'schema_unavailable' ) );
			return;
		}
		$repository = new CampaignRepository();
		$logger     = new Logger();
		$processor  = new PriceProcessor( $repository, $logger );
		$selection  = new ProductSelectionService( $repository );
		$scheduler  = new Scheduler( $repository, $processor, $logger );
		$scheduler->register();
		( new Admin\Dashboard( $repository, $selection, $scheduler, $logger ) )->register();
		( new Rest\AdminController( $repository, $selection, $scheduler, $logger ) )->register();
		( new Integration\Storefront( $repository ) )->register();
		( new Integration\Shipping( $repository ) )->register();
		( new Integration\Orders( $repository ) )->register();
		// Action Scheduler is initialized on init priority 1 by WooCommerce.
		add_action(
			'init',
			static function () use ( $scheduler, $logger ) {
				try {
					$scheduler->recover();
					delete_option( 'exin_bf_boot_error' );
				} catch ( \Throwable $error ) {
					try {
						$logger->log( 'error', 'recovery', array( 'error_code' => 'recovery_failed' ) );
					} catch ( \Throwable $audit_error ) {
						// Storage can be unavailable; dependency notice remains usable.
						update_option( 'exin_bf_boot_error', 'recovery_and_audit_failed', false );
					}
					Compatibility::notice( array( __( 'بازیابی صف کمپین ناموفق بود؛ صفحه عیب‌یابی را بررسی کنید.', 'exin-black-friday' ) ) );
				}
			},
			20
		);
	}
}
