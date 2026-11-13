<?php
/** Framework-free, real PHP domain tests. Run: php tests/unit/run.php */

declare(strict_types=1);

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Exin\\BlackFriday\\';
		if ( str_starts_with( $class, $prefix ) ) {
			$path = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $path ) ) {
				require_once $path;
			}
		}
	}
);

use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Domain\PricingEngine;
use Exin\BlackFriday\Domain\Timeline;

$assertions = 0;
$cases      = 0;
$failures   = array();

function same( mixed $expected, mixed $actual, string $message = '' ): void {
	global $assertions;
	++$assertions;
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ' expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function truth( bool $value, string $message = '' ): void {
	same( true, $value, $message );
}

function rejects( callable $callback, string $expected = InvalidArgumentException::class ): void {
	global $assertions;
	++$assertions;
	try {
		$callback();
	} catch ( Throwable $error ) {
		if ( $error instanceof $expected ) {
			return;
		}
		throw new RuntimeException( 'Expected ' . $expected . ', got ' . get_class( $error ) . ': ' . $error->getMessage() );
	}
	throw new RuntimeException( 'Expected exception ' . $expected );
}

function test( string $name, callable $callback ): void {
	global $cases, $failures;
	++$cases;
	try {
		$callback();
		fwrite( STDOUT, 'PASS ' . $name . PHP_EOL );
	} catch ( Throwable $error ) {
		$failures[] = $name . ': ' . $error->getMessage();
		fwrite( STDERR, 'FAIL ' . end( $failures ) . PHP_EOL );
	}
}

$engine = new PricingEngine();

test( 'Money parses and formats exact decimal units', static function (): void {
	same( 12345, Money::parse( '123.45', 2 ) );
	same( 12300, Money::parse( '00123', 2 ) );
	same( 1, Money::parse( '0.00000001', 8 ) );
	same( -120, Money::parse( '-1.20', 2 ) );
	same( '0.00', Money::format( 0, 2 ) );
	same( '0.01', Money::format( 1, 2 ) );
	same( '-1.20', Money::format( -120, 2 ) );
	same( '120', Money::format( 120, 0 ) );
	same( PHP_INT_MAX, Money::parse( (string) PHP_INT_MAX, 0 ) );
	same( (string) PHP_INT_MIN, Money::format( PHP_INT_MIN, 0 ) );
	same( (string) PHP_INT_MAX, Money::format( PHP_INT_MAX, 0 ) );
} );

test( 'Money rejects malformed input, excess precision and overflow', static function (): void {
	foreach ( array( '', '1e3', 'NaN', 'INF', '+1', '1,000', '۱۰۰', '.1', '1.', '1<script>', '--1', '1 2' ) as $value ) {
		rejects( static fn() => Money::parse( $value, 2 ) );
	}
	rejects( static fn() => Money::parse( '1.001', 2 ) );
	rejects( static fn() => Money::parse( '1.0', 0 ) );
	rejects( static fn() => Money::parse( '9223372036854775808', 0 ), OverflowException::class );
	rejects( static fn() => Money::parse( '9999999999999999999999', 2 ), OverflowException::class );
	rejects( static fn() => Money::parse( '1', -1 ) );
	rejects( static fn() => Money::format( 1, 9 ) );
} );

test( 'Linear seven-day known table includes both endpoints', static function () use ( $engine ): void {
	same( array( '100.00', '90.00', '80.00', '70.00', '60.00', '50.00', '40.00' ), $engine->schedule( '100', '40', 7 ) );
	same( array( '100', '90', '80', '70', '60', '50', '40' ), $engine->schedule( '100', '40', 7, 'linear', 0 ) );
} );

test( 'Progressive quadratic has smaller initial reductions', static function () use ( $engine ): void {
	same( array( '100.00', '98.33', '93.33', '85.00', '73.33', '58.33', '40.00' ), $engine->schedule( '100', '40', 7, 'progressive' ) );
	same( array( '100.00', '90.00', '80.00', '70.00', '60.00', '50.00', '40.00' ), $engine->schedule( '100', '40', 7, 'progressive', 2, 1 ) );
} );

test( 'Equal base floor and one-day policy', static function () use ( $engine ): void {
	same( array_fill( 0, 7, '12.34' ), $engine->schedule( '12.34', '12.34', 7 ) );
	same( array( '9.00' ), $engine->schedule( '10', '9', 1 ) );
	same( array( '0.01' ), $engine->schedule( '0.01', '0.01', 1 ) );
} );

test( 'Financial validation rejects invalid ranges and unknown policies', static function () use ( $engine ): void {
	foreach ( array( array( '0', '1' ), array( '-1', '1' ), array( '10', '0' ), array( '10', '-1' ), array( '10', '11' ) ) as $prices ) {
		rejects( static fn() => $engine->schedule( $prices[0], $prices[1], 7 ) );
	}
	rejects( static fn() => $engine->schedule( '10', '1', 0 ) );
	rejects( static fn() => $engine->schedule( '10', '1', 367 ) );
	rejects( static fn() => $engine->schedule( '10', '1', 7, 'random' ) );
	rejects( static fn() => $engine->schedule( '10', '1', 7, 'progressive', 2, 0 ) );
	rejects( static fn() => $engine->schedule( '10', '1', 7, 'progressive', 2, 9 ) );
	rejects( static fn() => $engine->schedule( '10', '1', 7, 'linear', 2, 2, 'truncate' ) );
	rejects( static fn() => $engine->schedule( '10.001', '1', 7 ) );
} );

test( 'Rounding applies to resulting price with exact half-up ties', static function () use ( $engine ): void {
	same( array( '0.04', '0.03', '0.01' ), $engine->schedule( '0.04', '0.01', 3 ) );
	same( array( '0.04', '0.02', '0.01' ), $engine->schedule( '0.04', '0.01', 3, 'linear', 2, 2, 'floor' ) );
	same( array( '0.04', '0.03', '0.01' ), $engine->schedule( '0.04', '0.01', 3, 'linear', 2, 2, 'ceil' ) );
	same( array( '0.05', '0.04', '0.02', '0.01' ), $engine->schedule( '0.05', '0.01', 4, 'linear', 2, 2, 'half_up' ) );
} );

test( 'Very large amounts do not overflow intermediate multiplications', static function () use ( $engine ): void {
	$schedule = $engine->schedule( '92233720368547758.07', '0.01', 366, 'progressive', 2, 8 );
	same( 366, count( $schedule ) );
	same( '92233720368547758.07', $schedule[0] );
	same( '0.01', $schedule[365] );
	$previous = PHP_INT_MAX;
	foreach ( $schedule as $price ) {
		$minor = Money::parse( $price, 2 );
		truth( $minor > 0 && $minor <= $previous, 'Prices must never rise or cross floor.' );
		$previous = $minor;
	}
} );

test( 'Changing days recomputes table without off-by-one', static function () use ( $engine ): void {
	same( array( '10.00', '1.00' ), $engine->schedule( '10', '1', 2 ) );
	same( array( '10.00', '5.50', '1.00' ), $engine->schedule( '10', '1', 3 ) );
	foreach ( array( 1, 2, 7, 30, 90, 366 ) as $days ) {
		$table = $engine->schedule( '500', '250', $days );
		same( $days, count( $table ) );
		same( '250.00', $table[ $days - 1 ] );
	}
} );

test( 'Small-amount schedules agree with independent integer rational oracle', static function () use ( $engine ): void {
	mt_srand( 1016 );
	for ( $case = 0; $case < 120; ++$case ) {
		$base  = mt_rand( 2, 1000000 );
		$floor = mt_rand( 1, $base );
		$days  = mt_rand( 2, 12 );
		$power = mt_rand( 1, 3 );
		$denom = ( $days - 1 ) ** $power;
		foreach ( array( 'half_up', 'floor', 'ceil' ) as $rounding ) {
			$table = $engine->schedule( (string) $base, (string) $floor, $days, 'progressive', 0, $power, $rounding );
			for ( $day = 0; $day < $days; ++$day ) {
				$numerator = $base * $denom - ( $base - $floor ) * ( $day ** $power );
				$expected = intdiv( $numerator, $denom );
				$remainder = $numerator % $denom;
				if ( ( 'ceil' === $rounding && $remainder > 0 ) || ( 'half_up' === $rounding && $remainder * 2 >= $denom ) ) {
					++$expected;
				}
				same( (string) $expected, $table[ $day ], 'Rational oracle mismatch.' );
			}
		}
	}
} );

test( 'Current discount percent is exact even for large amounts', static function () use ( $engine ): void {
	same( '0.00', $engine->discount_percent( '100', '100' ) );
	same( '33.33', $engine->discount_percent( '3', '2' ) );
	same( '100.00', $engine->discount_percent( '92233720368547758.07', '0' ) );
	same( '50', $engine->discount_percent( '100', '50', 2, 0 ) );
	rejects( static fn() => $engine->discount_percent( '0', '0' ) );
	rejects( static fn() => $engine->discount_percent( '100', '101' ) );
} );

test( 'Extreme financial results agree with independent Python big-integer reference fixtures', static function () use ( $engine ): void {
	$vectors = json_decode( file_get_contents( __DIR__ . '/reference-vectors.json' ), true, 512, JSON_THROW_ON_ERROR );
	foreach ( $vectors as $vector ) {
		$table = $engine->schedule( $vector['base'], $vector['floor'], $vector['days'], 'progressive', 2, $vector['power'], $vector['rounding'] );
		foreach ( $vector['expected'] as $index => $expected ) {
			same( $expected, $table[ (int) $index ], 'Independent big-integer fixture mismatch.' );
		}
	}
} );

test( 'Tehran target date is stored in UTC with seven exact local days', static function (): void {
	$boundaries = Timeline::boundaries( '2026-11-26 20:30:00', 7, 'Asia/Tehran' );
	same( 7, count( $boundaries ) );
	same( '2026-11-26 20:30:00', $boundaries[0] );
	same( '2026-12-02 20:30:00', $boundaries[6] );
	same( '2026-12-03 20:30:00', Timeline::end( $boundaries[0], 7, 'Asia/Tehran' ) );
	$campaign = array( 'start_utc' => $boundaries[0], 'end_utc' => '2026-12-03 20:30:00', 'days' => 7, 'timezone' => 'Asia/Tehran' );
	$start = Timeline::parse_utc( $boundaries[0] )->getTimestamp();
	same( -1, Timeline::day_index( $campaign, $start - 1 ) );
	same( 0, Timeline::day_index( $campaign, $start ) );
	same( 0, Timeline::day_index( $campaign, $start + 86399 ) );
	same( 1, Timeline::day_index( $campaign, $start + 86400 ) );
	same( 6, Timeline::day_index( $campaign, $start + 6 * 86400 ) );
	same( 6, Timeline::day_index( $campaign, $start + 7 * 86400 - 1 ) );
	same( 7, Timeline::day_index( $campaign, $start + 7 * 86400 ) );
	same( 7, Timeline::day_index( $campaign, $start + 100 * 86400 ) );
} );

test( 'Delayed scheduler derives current day directly', static function (): void {
	$campaign = array( 'start_utc' => '2026-11-26 20:30:00', 'end_utc' => '2026-12-03 20:30:00', 'days' => 7, 'timezone' => 'Asia/Tehran' );
	same( 4, Timeline::day_index( $campaign, Timeline::parse_utc( '2026-12-01 08:00:00' )->getTimestamp() ) );
} );

test( 'Local calendar boundaries handle spring DST without fixed 86400 assumptions', static function (): void {
	$days = Timeline::boundaries( '2026-03-07 05:00:00', 4, 'America/New_York' );
	same( array( '2026-03-07 05:00:00', '2026-03-08 05:00:00', '2026-03-09 04:00:00', '2026-03-10 04:00:00' ), $days );
	same( 23 * 3600, Timeline::parse_utc( $days[2] )->getTimestamp() - Timeline::parse_utc( $days[1] )->getTimestamp() );
	same( '2026-03-11 04:00:00', Timeline::end( $days[0], 4, 'America/New_York' ) );
	$campaign = array( 'start_utc' => $days[0], 'end_utc' => '2026-03-11 04:00:00', 'days' => 4, 'timezone' => 'America/New_York' );
	same( 2, Timeline::day_index( $campaign, Timeline::parse_utc( '2026-03-09 04:00:00' )->getTimestamp() ) );
} );

test( 'Local calendar boundaries handle autumn DST', static function (): void {
	$days = Timeline::boundaries( '2026-10-31 04:00:00', 4, 'America/New_York' );
	same( array( '2026-10-31 04:00:00', '2026-11-01 04:00:00', '2026-11-02 05:00:00', '2026-11-03 05:00:00' ), $days );
	same( 25 * 3600, Timeline::parse_utc( $days[2] )->getTimestamp() - Timeline::parse_utc( $days[1] )->getTimestamp() );
} );

test( 'Timeline rejects invalid dates, zones and end boundaries', static function (): void {
	rejects( static fn() => Timeline::boundaries( '2026-02-30 00:00:00', 7, 'UTC' ) );
	rejects( static fn() => Timeline::boundaries( '2026-11-27T00:00:00Z', 7, 'UTC' ) );
	rejects( static fn() => Timeline::boundaries( '2026-11-27 00:00:00', 7, 'Moon/Sea' ) );
	rejects( static fn() => Timeline::boundaries( '2026-11-27 00:00:00', 0, 'UTC' ) );
	rejects( static fn() => Timeline::day_index( array( 'start_utc' => '2026-11-27 00:00:00', 'end_utc' => '2026-11-28 00:00:00', 'days' => 7, 'timezone' => 'UTC' ), 1 ) );
} );

fwrite( STDOUT, sprintf( 'Domain tests: %d cases, %d assertions, %d failures%s', $cases, $assertions, count( $failures ), PHP_EOL ) );
exit( count( $failures ) > 0 ? 1 : 0 );
