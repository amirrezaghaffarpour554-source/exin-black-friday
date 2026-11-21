<?php
/** Focused pure regressions for the v1.1.0 scheduled-discount contract. */
declare(strict_types=1);

define( 'EXIN_BF_TESTING', true );
require dirname( __DIR__, 2 ) . '/autoload.php';

use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Domain\Timeline;

$cases = 0;
$checks = 0;
$failed = array();
$equal = static function ( mixed $expected, mixed $actual ) use ( &$checks ): void {
	++$checks;
	if ( $expected !== $actual ) {
		throw new RuntimeException( 'Expected ' . json_encode( $expected ) . ', got ' . json_encode( $actual ) );
	}
};
$rejects = static function ( callable $operation, string $class = InvalidArgumentException::class ) use ( $equal ): void {
	try {
		$operation();
	} catch ( Throwable $error ) {
		$equal( true, $error instanceof $class );
		return;
	}
	$equal( true, false );
};
$test = static function ( string $name, callable $operation ) use ( &$cases, &$failed ): void {
	++$cases;
	try {
		$operation();
		echo 'PASS ' . $name . PHP_EOL;
	} catch ( Throwable $error ) {
		$failed[] = $name . ': ' . $error->getMessage();
		fwrite( STDERR, 'FAIL ' . end( $failed ) . PHP_EOL );
	}
};
$campaign = static function ( ?array $plan = null, int $precision = 2, string $rounding = 'half_up' ): array {
	$record = array(
		'start_utc' => '2026-11-26 20:30:00',
		'timezone' => 'Asia/Tehran',
		'days' => 7,
		'strategy' => 'linear',
		'power' => 2,
		'rounding' => $rounding,
		'settings' => array( 'precision' => $precision ),
	);
	if ( null !== $plan ) {
		$record['settings']['discount_plan'] = $plan;
	}
	$record['end_utc'] = DiscountPlan::end( $record );
	return $record;
};

$test( 'Legacy day-zero behavior and seven-day prices remain unchanged', static function () use ( $equal, $campaign ): void {
	$old = $campaign();
	$equal( array( '100.00', '90.00', '80.00', '70.00', '60.00', '50.00', '40.00' ), DiscountPlan::schedule( '100.00', '40.00', $old ) );
	$equal( Timeline::boundaries( $old['start_utc'], 7, 'Asia/Tehran' ), DiscountPlan::boundaries( $old ) );
	$equal( '2026-12-03 20:30:00', $old['end_utc'] );
	$equal( 0, DiscountPlan::index( $old, strtotime( $old['start_utc'] . ' UTC' ) ) );
} );

$test( 'A fixed percentage applies its initial discount immediately without requiring an independent floor', static function () use ( $equal, $campaign ): void {
	$current = $campaign( array( 'mode' => 'fixed_percent', 'initial_discount' => '5', 'steps' => 3 ) );
	$equal( array( '95.00', '95.00', '95.00' ), DiscountPlan::schedule( '100.00', '0', $current ) );
	$equal( array( '80.00', '80.00', '80.00' ), DiscountPlan::schedule( '100.00', '80', $campaign( array( 'initial_discount' => '50', 'steps' => 3 ) ) ) );
	$equal( array( '95.00', '95.00', '40.00' ), DiscountPlan::schedule( '100.00', '40', $campaign( array( 'initial_discount' => '5', 'steps' => 3, 'reach_floor' => true ) ) ) );
} );

$test( 'Fixed monetary discounts remain exact in IRR and decimal currencies and never breach the floor', static function () use ( $equal, $campaign ): void {
	$equal( array( '900001' ), DiscountPlan::schedule( '1000001', '0', $campaign( array( 'mode' => 'fixed_amount', 'initial_discount' => '100000', 'steps' => 1 ), 0 ) ) );
	$equal( array( '98.77', '98.77' ), DiscountPlan::schedule( '100.00', '0', $campaign( array( 'mode' => 'fixed_amount', 'initial_discount' => '1.23', 'steps' => 2 ) ) ) );
	$equal( array( '25.00' ), DiscountPlan::schedule( '100.00', '25', $campaign( array( 'mode' => 'fixed_amount', 'initial_discount' => '1000', 'steps' => 1 ) ) ) );
	$equal( array( '0.00' ), DiscountPlan::schedule( '100.00', '0', $campaign( array( 'initial_discount' => '100', 'steps' => 1 ) ) ) );
} );

$test( 'Linear plans start discounted and reach the exact safety floor on their last stage', static function () use ( $equal, $campaign ): void {
	$equal( array( '95.00', '67.50', '40.00' ), DiscountPlan::schedule( '100.00', '40', $campaign( array( 'mode' => 'linear', 'initial_discount' => '5', 'steps' => 3 ) ) ) );
	$equal( array( '40.00' ), DiscountPlan::schedule( '100.00', '40', $campaign( array( 'mode' => 'linear', 'initial_discount' => '5', 'steps' => 1 ) ) ) );
	$equal( array( '80.00', '80.00' ), DiscountPlan::schedule( '100.00', '80', $campaign( array( 'mode' => 'linear', 'initial_discount' => '50', 'steps' => 2 ) ) ) );
} );

$test( 'Stepped discounts increase or decrease from the immutable base without compounding', static function () use ( $equal, $campaign ): void {
	$equal( array( '95.00', '90.00', '85.00' ), DiscountPlan::schedule( '100.00', '0', $campaign( array( 'mode' => 'stepped', 'initial_discount' => '5', 'final_discount' => '15', 'steps' => 3 ) ) ) );
	$equal( array( '80.00', '85.00', '90.00' ), DiscountPlan::schedule( '100.00', '0', $campaign( array( 'mode' => 'stepped', 'initial_discount' => '20', 'final_discount' => '10', 'steps' => 3 ) ) ) );
	$equal( array( '95.00', '91.67', '88.33', '85.00' ), DiscountPlan::schedule( '100.00', '0', $campaign( array( 'mode' => 'stepped', 'initial_discount' => '5', 'final_discount' => '15', 'steps' => 4 ) ) ) );
} );

$test( 'Custom percentage and amount points can reverse direction while respecting each product floor', static function () use ( $equal, $campaign ): void {
	$plan = array( 'mode' => 'custom', 'points' => array(
		array( 'offset_minutes' => 0, 'kind' => 'percent', 'value' => '5' ),
		array( 'offset_minutes' => 30, 'kind' => 'amount', 'value' => '40.25' ),
		array( 'offset_minutes' => 150, 'kind' => 'percent', 'value' => '10' ),
	), 'duration_minutes' => 180 );
	$equal( array( '95.00', '59.75', '90.00' ), DiscountPlan::schedule( '100.00', '0', $campaign( $plan ) ) );
	$equal( array( '95.00', '70.00', '90.00' ), DiscountPlan::schedule( '100.00', '70', $campaign( $plan ) ) );
	$equal( array( '95.00', '70.00', '70.00' ), DiscountPlan::schedule( '100.00', '70', $campaign( $plan + array( 'reach_floor' => true ) ) ) );
	$normalized = DiscountPlan::normalize( $plan, 2 );
	$equal( $normalized, DiscountPlan::normalize( $normalized, 2 ) );
} );

$test( 'Percentage rounding applies to the final price and large intermediates never overflow', static function () use ( $equal, $campaign ): void {
	$plan = array( 'initial_discount' => '50', 'steps' => 1 );
	$equal( array( '1' ), DiscountPlan::schedule( '1', '0', $campaign( $plan, 0, 'half_up' ) ) );
	$equal( array( '0' ), DiscountPlan::schedule( '1', '0', $campaign( $plan, 0, 'floor' ) ) );
	$equal( array( '1' ), DiscountPlan::schedule( '1', '0', $campaign( $plan, 0, 'ceil' ) ) );
	$equal( array( '8301034833169298226' ), DiscountPlan::schedule( '9223372036854775807', '0', $campaign( array( 'initial_discount' => '10', 'steps' => 1 ), 0 ) ) );
} );

$test( 'Daily hourly and custom boundaries catch up directly in Tehran and respect exact end times', static function () use ( $equal, $campaign ): void {
	$hourly = $campaign( array( 'interval' => 'hourly', 'steps' => 3 ) );
	$equal( array( '2026-11-26 20:30:00', '2026-11-26 21:30:00', '2026-11-26 22:30:00' ), DiscountPlan::boundaries( $hourly ) );
	$equal( '2026-11-26 23:30:00', $hourly['end_utc'] );
	$equal( -1, DiscountPlan::index( $hourly, strtotime( '2026-11-26 20:29:59 UTC' ) ) );
	$equal( 2, DiscountPlan::index( $hourly, strtotime( '2026-11-26 23:00:00 UTC' ) ) );
	$equal( 3, DiscountPlan::index( $hourly, strtotime( '2026-11-26 23:30:00 UTC' ) ) );
	$custom_interval = $campaign( array( 'interval' => 'custom', 'interval_minutes' => 90, 'steps' => 2 ) );
	$equal( array( '2026-11-26 20:30:00', '2026-11-26 22:00:00' ), DiscountPlan::boundaries( $custom_interval ) );
	$manual = $campaign( array( 'mode' => 'custom', 'points' => array(
		array( 'offset_minutes' => 0, 'kind' => 'percent', 'value' => '5' ),
		array( 'offset_minutes' => 45, 'kind' => 'percent', 'value' => '10' ),
	), 'duration_minutes' => 120 ) );
	$equal( array( '2026-11-26 20:30:00', '2026-11-26 21:15:00' ), DiscountPlan::boundaries( $manual ) );
	$equal( '2026-11-26 22:30:00', $manual['end_utc'] );
	$daily = $campaign( array( 'steps' => 2 ) );
	$equal( array( '2026-11-26 20:30:00', '2026-11-27 20:30:00' ), DiscountPlan::boundaries( $daily ) );
} );

$test( 'Malformed percentages amounts floors and timing fail before any schedule can be applied', static function () use ( $rejects, $campaign ): void {
	foreach ( array( array( 'initial_discount' => '100.01' ), array( 'initial_discount' => '-5' ), array( 'initial_discount' => '1e2' ), array( 'initial_discount' => 5.1 ), array( 'steps' => 0 ), array( 'steps' => 367 ), array( 'interval' => 'custom', 'interval_minutes' => 0 ), array( 'interval' => 'custom', 'interval_minutes' => 527040, 'steps' => 2 ), array( 'reach_floor' => 'true' ) ) as $input ) {
		$rejects( static fn() => DiscountPlan::normalize( $input, 2 ) );
	}
	$rejects( static fn() => DiscountPlan::schedule( '100.00', '101', $campaign( array() ) ) );
	$rejects( static fn() => DiscountPlan::schedule( '100.00', '-1', $campaign( array() ) ) );
	$rejects( static fn() => DiscountPlan::normalize( array( 'mode' => 'fixed_amount', 'initial_discount' => '9223372036854775808' ), 0 ), OverflowException::class );
	foreach ( array(
		array( array( 'offset_minutes' => 1, 'kind' => 'percent', 'value' => '5' ) ),
		array( array( 'offset_minutes' => 0, 'kind' => 'percent', 'value' => '5' ), array( 'offset_minutes' => 0, 'kind' => 'amount', 'value' => '1' ) ),
		array( array( 'offset_minutes' => 0, 'kind' => 'percent', 'value' => '101' ) ),
	) as $points ) {
		$rejects( static fn() => DiscountPlan::normalize( array( 'mode' => 'custom', 'points' => $points ), 2 ) );
	}
	$rejects( static fn() => DiscountPlan::normalize( array( 'mode' => 'custom', 'points' => array( array( 'offset_minutes' => 0, 'kind' => 'percent', 'value' => '5' ) ), 'duration_minutes' => 0 ), 2 ) );
} );

$result = array( 'suite' => 'v110-discount-plan', 'php' => PHP_VERSION, 'cases' => $cases, 'assertions' => $checks, 'passed' => $cases - count( $failed ), 'failed' => count( $failed ), 'failures' => $failed );
echo 'EXIN_V110_PLAN_RESULT ' . json_encode( $result ) . PHP_EOL;
exit( $failed ? 1 : 0 );
