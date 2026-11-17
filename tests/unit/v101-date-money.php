<?php
/** Framework-free Jalali and locale/currency money regression tests. */
declare(strict_types=1);

define( 'EXIN_BF_TESTING', true );
require dirname( __DIR__, 2 ) . '/autoload.php';

use Exin\BlackFriday\Domain\CurrencyDisplay;
use Exin\BlackFriday\Domain\Jalali;
use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Domain\MoneyInput;

$cases = 0;
$checks = 0;
$failures = array();
$equal = static function ( mixed $expected, mixed $actual, string $message = '' ) use ( &$checks ): void {
	++$checks;
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ' expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
};
$rejects = static function ( callable $callback, string $class = InvalidArgumentException::class ) use ( $equal ): void {
	try { $callback(); } catch ( Throwable $error ) {
		$equal( true, $error instanceof $class, get_class( $error ) );
		return;
	}
	$equal( true, false, 'Expected ' . $class );
};
$test = static function ( string $name, callable $callback ) use ( &$cases, &$failures ): void {
	++$cases;
	try { $callback(); echo 'PASS ' . $name . PHP_EOL; }
	catch ( Throwable $error ) { $failures[] = $name . ': ' . $error->getMessage(); fwrite( STDERR, 'FAIL ' . end( $failures ) . PHP_EOL ); }
};

$test( 'Target date and both scripts of digits convert exactly', static function () use ( $equal ): void {
	$equal( array( 'year' => 2026, 'month' => 11, 'day' => 27 ), Jalali::to_gregorian( 1405, 9, 6 ) );
	$equal( array( 'year' => 1405, 'month' => 9, 'day' => 6 ), Jalali::from_gregorian( 2026, 11, 27 ) );
	$equal( '2026-11-27 00:00:00', Jalali::to_local( '۱۴۰۵/۰۹/۰۶', '۰۰:۰۰' ) );
	$equal( '2026-11-27 23:59:00', Jalali::to_local( '١٤٠٥/٠٩/٠٦', '٢٣:٥٩' ) );
	$equal( array( 'date' => '۱۴۰۵/۰۹/۰۶', 'ascii_date' => '1405/09/06', 'time' => '23:59' ), Jalali::from_local( '2026-11-27 23:59:00' ) );
} );
$test( 'Leap Esfand is validated as an actual Jalali date', static function () use ( $equal, $rejects ): void {
	$equal( true, Jalali::is_leap( 1399 ) );
	$equal( true, Jalali::is_leap( 1403 ) );
	$equal( false, Jalali::is_leap( 1404 ) );
	$equal( 30, Jalali::month_length( 1403, 12 ) );
	$equal( 29, Jalali::month_length( 1404, 12 ) );
	$equal( array( 'year' => 2025, 'month' => 3, 'day' => 20 ), Jalali::to_gregorian( 1403, 12, 30 ) );
	$rejects( static fn() => Jalali::parse( '1404/12/30' ) );
	$rejects( static fn() => Jalali::parse( '1405/07/31' ) );
	$rejects( static fn() => Jalali::parse( '1405/01/00' ) );
	$rejects( static fn() => Jalali::parse( '1405/13/01' ) );
} );
$test( 'Independent ICU calendar vectors match in both directions', static function () use ( $equal ): void {
	$fixture = json_decode( file_get_contents( __DIR__ . '/jalali-reference.json' ), true, 512, JSON_THROW_ON_ERROR );
	foreach ( $fixture['vectors'] as [ $gy, $gm, $gd, $jy, $jm, $jd ] ) {
		$equal( array( 'year' => $jy, 'month' => $jm, 'day' => $jd ), Jalali::from_gregorian( $gy, $gm, $gd ), "$gy-$gm-$gd" );
		$equal( array( 'year' => $gy, 'month' => $gm, 'day' => $gd ), Jalali::to_gregorian( $jy, $jm, $jd ), "$jy/$jm/$jd" );
	}
} );
$test( 'Month starts and ends round-trip throughout the supported campaign range', static function () use ( $equal ): void {
	for ( $year = 1200; $year <= 1600; $year += 4 ) {
		for ( $month = 1; $month <= 12; ++$month ) {
			foreach ( array( 1, Jalali::month_length( $year, $month ) ) as $day ) {
				$date = Jalali::to_gregorian( $year, $month, $day );
				$equal( array( 'year' => $year, 'month' => $month, 'day' => $day ), Jalali::from_gregorian( $date['year'], $date['month'], $date['day'] ) );
			}
		}
	}
} );
$test( 'Local date conversion stays independent of default PHP timezone', static function () use ( $equal ): void {
	$old = date_default_timezone_get();
	try {
		foreach ( array( 'UTC', 'America/Los_Angeles', 'Asia/Tehran' ) as $zone ) {
			date_default_timezone_set( $zone );
			$local = Jalali::to_local( '1405/09/06', '00:00' );
			$equal( '2026-11-27 00:00:00', $local );
			$equal( '2026-11-26 20:30:00', ( new DateTimeImmutable( $local, new DateTimeZone( 'Asia/Tehran' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
		}
	} finally { date_default_timezone_set( $old ); }
} );
$test( 'Malformed dates, impossible Gregorian days and twelve-hour times fail', static function () use ( $rejects ): void {
	foreach ( array( '1405.09.06', '1405/9/6<script>', '1199/12/29', '1601/01/01' ) as $input ) $rejects( static fn() => Jalali::parse( $input ) );
	foreach ( array( '24:00', '23:60', '12:00 PM', '9:00', '-1:00' ) as $time ) $rejects( static fn() => Jalali::to_local( '1405/09/06', $time ) );
	$rejects( static fn() => Jalali::from_gregorian( 2025, 2, 29 ) );
	$rejects( static fn() => Jalali::from_local( '2026-11-27 24:00:00' ) );
} );
$test( 'Locale amount input normalizes Persian Arabic and grouped decimals', static function () use ( $equal ): void {
	$equal( '1234567', MoneyInput::normalize( '۱٬۲۳۴٬۵۶۷', 0 ) );
	$equal( '1234.50', MoneyInput::normalize( '١٬٢٣٤٫٥٠', 2 ) );
	$equal( '1234.50', MoneyInput::normalize( '1,234.5', 2 ) );
	$equal( '1000000', MoneyInput::normalize( "1\u{202F}000\u{202F}000", 0 ) );
	$equal( '1.00', MoneyInput::normalize( '00,001', 2 ) );
	$equal( '0.00', MoneyInput::normalize( '۰', 2 ) );
} );
$test( 'Malformed grouping signs exponents and excess precision are rejected', static function () use ( $rejects ): void {
	foreach ( array( '12,34', '1,,000', '1,000,00', '-1', '+1', '1e6', '1.2.3', '.50', '1 000', '<script>1</script>', '' ) as $input ) $rejects( static fn() => MoneyInput::normalize( $input, 2 ) );
	$rejects( static fn() => MoneyInput::normalize( '1.001', 2 ) );
	$rejects( static fn() => MoneyInput::normalize( '1', 10 ) );
	$rejects( static fn() => MoneyInput::normalize( str_repeat( '1', 300 ), 0 ) );
	$rejects( static fn() => MoneyInput::normalize( '9223372036854775808', 0 ), OverflowException::class );
} );
$test( 'Toman conversion is exact and unknown foreign-exchange assumptions fail closed', static function () use ( $equal, $rejects ): void {
	$equal( '1500000', CurrencyDisplay::to_store( '۱۵۰٬۰۰۰', 'toman', 'IRR', 0 ) );
	$equal( '1500000.00', CurrencyDisplay::to_store( '150000', 'toman', 'IRR', 2 ) );
	$equal( '150000', CurrencyDisplay::to_store( '۱۵۰٬۰۰۰', 'toman', 'IRT', 0 ) );
	$equal( '150000', CurrencyDisplay::default_subsidy_cap( 'TMN', 0 ) );
	$equal( '1500000', CurrencyDisplay::default_subsidy_cap( 'IRR', 0 ) );
	$equal( null, CurrencyDisplay::default_subsidy_cap( 'USD', 2 ) );
	$equal( 'store', CurrencyDisplay::unit( 'USD' ) );
	$equal( 'toman', CurrencyDisplay::unit( 'IRR' ) );
	$equal( '1234.50', CurrencyDisplay::to_store( '1,234.50', 'store', 'USD', 2 ) );
	$rejects( static fn() => CurrencyDisplay::to_store( '150000', 'toman', 'USD', 2 ) );
	$rejects( static fn() => CurrencyDisplay::to_store( '10', 'rials', 'IRR', 0 ) );
} );
$test( 'Fractional toman values and amounts beyond JavaScript safe integers round-trip exactly', static function () use ( $equal, $rejects ): void {
	$equal( '150000.1', CurrencyDisplay::from_store( '1500001', 'IRR', 0 ) );
	$equal( '1500001', CurrencyDisplay::to_store( '150000.1', 'toman', 'IRR', 0 ) );
	$equal( '900719925474099.3', CurrencyDisplay::from_store( '9007199254740993', 'IRR', 0 ) );
	$equal( '9007199254740993', CurrencyDisplay::to_store( '900719925474099.3', 'toman', 'IRR', 0 ) );
	$equal( '-0.1', CurrencyDisplay::from_store( '-1', 'IRR', 0 ) );
	$rejects( static fn() => CurrencyDisplay::to_store( '150000.01', 'toman', 'IRR', 0 ) );
	$rejects( static fn() => CurrencyDisplay::to_store( '-1', 'toman', 'IRR', 0 ) );
	$largest = Money::format( PHP_INT_MAX, 8 );
	$display = CurrencyDisplay::from_store( $largest, 'IRR', 8 );
	$equal( $largest, CurrencyDisplay::to_store( $display, 'toman', 'IRR', 8 ) );
} );

$result = array( 'suite' => 'v101-date-money', 'php' => PHP_VERSION, 'cases' => $cases, 'assertions' => $checks, 'passed' => $cases - count( $failures ), 'failed' => count( $failures ) );
echo 'EXIN_DATE_MONEY_RESULT ' . json_encode( $result, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
exit( $failures ? 1 : 0 );
