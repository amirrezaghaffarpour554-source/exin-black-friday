<?php
/** Framework-free tests for exact v1.0.1 shipping subsidy arithmetic. */
declare(strict_types=1);

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'Exin\\BlackFriday\\';
	if ( str_starts_with( $class, $prefix ) ) {
		require_once dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	}
} );

use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Domain\ShippingSubsidy;

$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$throws = static function ( callable $callback, string $message ) use ( $assert ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException | OverflowException $error ) {
		$assert( true, $message );
		return;
	}
	$assert( false, $message );
};

$assert( '0' === ShippingSubsidy::remaining( '100000', '150000', 0 ), 'Below-cap shipping is free.' );
$assert( '0' === ShippingSubsidy::remaining( '150000', '150000', 0 ), 'Exactly-cap shipping is free.' );
$assert( '50000' === ShippingSubsidy::remaining( '200000', '150000', 0 ), 'Above-cap customer pays only excess.' );
$assert( '200000' === ShippingSubsidy::remaining( '200000', '0', 0 ), 'A zero cap grants no subsidy.' );
$assert( '0.00' === ShippingSubsidy::remaining( '0', '150000', 2 ), 'No negative charge for zero base.' );
$assert( '12.34' === ShippingSubsidy::remaining( '162.34', '150.00', 2 ), 'Decimal remaining amount is exact.' );
$assert( '0.00000001' === ShippingSubsidy::remaining( '150.00000001', '150', 8 ), 'Eight-decimal precision is preserved.' );
$assert( (string) ( PHP_INT_MAX - 1 ) === ShippingSubsidy::remaining( (string) PHP_INT_MAX, '1', 0 ), 'Maximum integer shipping is exact.' );
$throws( static fn() => ShippingSubsidy::remaining( '-1', '1', 0 ), 'Negative cost rejected.' );
$throws( static fn() => ShippingSubsidy::remaining( '1', '-1', 0 ), 'Negative budget rejected.' );
$throws( static fn() => ShippingSubsidy::remaining( '1e3', '1', 0 ), 'Exponent base rejected.' );
$throws( static fn() => ShippingSubsidy::remaining( '1.001', '1', 2 ), 'Unreliable base precision rejected.' );
$throws( static fn() => ShippingSubsidy::remaining( (string) PHP_INT_MAX . '0', '1', 0 ), 'Cost overflow rejected.' );

$assert( array() === ShippingSubsidy::allocate( '150000', 0, array() ), 'Empty cart allocation is empty.' );
$assert( array( 'one' => '150000' ) === ShippingSubsidy::allocate( '150000', 0, array( 'one' ) ), 'One package gets one full cap.' );
$assert( array( 'a' => '3.34', 'b' => '3.34', 'c' => '3.33' ) === ShippingSubsidy::allocate( '10.01', 2, array( 'c', 'a', 'b' ) ), 'Remainder allocation is exact and stable.' );
$assert( array( 0 => '1', 1 => '0', 2 => '0' ) === ShippingSubsidy::allocate( '1', 0, array( 2, 1, 0 ) ), 'Integer package keys handle a tiny cart cap.' );
$assert( array( 'a' => '0.00', 'b' => '0.00' ) === ShippingSubsidy::allocate( '0', 2, array( 'b', 'a' ) ), 'Zero cap shares remain zero.' );
$throws( static fn() => ShippingSubsidy::allocate( '-1', 0, array( 'a' ) ), 'Negative allocation cap rejected.' );
$throws( static fn() => ShippingSubsidy::allocate( '10', 0, array( 'a', 'a' ) ), 'Duplicate package keys rejected.' );
$throws( static fn() => ShippingSubsidy::allocate( '10', 0, array( '' ) ), 'Empty package key rejected.' );
$throws( static fn() => ShippingSubsidy::allocate( '10', 0, array( array() ) ), 'Nonscalar package key rejected.' );

foreach ( array( array( '12', 2, '12.00' ), array( '12.3449', 2, '12.34' ), array( '12.3450', 2, '12.35' ), array( '12.995', 2, '13.00' ), array( '0.5', 0, '1' ), array( '0.49', 0, '0' ), array( '9007199254740993.49', 0, '9007199254740993' ) ) as $vector ) {
	$assert( $vector[2] === ShippingSubsidy::round_amount( $vector[0], $vector[1] ), 'Line subtotal rounding reference ' . $vector[0] );
}
$throws( static fn() => ShippingSubsidy::round_amount( '-0.01', 2 ), 'Negative line total rejected.' );
$throws( static fn() => ShippingSubsidy::round_amount( '1E-2', 2 ), 'Scientific line total rejected.' );
$throws( static fn() => ShippingSubsidy::round_amount( (string) PHP_INT_MAX . '.5', 0 ), 'Rounding overflow rejected.' );

mt_srand( 101 );
for ( $trial = 0; $trial < 100; ++$trial ) {
	$cap = mt_rand( 0, 1000000000 );
	$count = mt_rand( 1, 20 );
	$keys = range( 0, $count - 1 );
	$budgets = ShippingSubsidy::allocate( Money::format( $cap, 2 ), 2, $keys );
	$allocated = 0;
	$subsidized = 0;
	foreach ( $budgets as $budget ) {
		$minor = Money::parse( $budget, 2 );
		$allocated += $minor;
		$base = mt_rand( 0, 1000000000 );
		$remaining = Money::parse( ShippingSubsidy::remaining( Money::format( $base, 2 ), $budget, 2 ), 2 );
		$assert( $remaining >= 0 && $remaining <= $base, 'A randomized remainder stays within its base.' );
		$subsidized += $base - $remaining;
	}
	$assert( $allocated === $cap, 'All package budgets conserve exactly one cart cap.' );
	$assert( $subsidized <= $cap, 'Actual selected-package subsidy cannot exceed one cart cap.' );
	$assert( $budgets === ShippingSubsidy::allocate( Money::format( $cap, 2 ), 2, array_reverse( $keys ) ), 'Allocation is independent of input key order.' );
}

echo 'EXIN_V101_SHIPPING_UNIT_RESULT ' . json_encode( array( 'checks' => $checks, 'passed' => $checks, 'failed' => 0, 'php' => PHP_VERSION ), JSON_THROW_ON_ERROR ) . "\n";
