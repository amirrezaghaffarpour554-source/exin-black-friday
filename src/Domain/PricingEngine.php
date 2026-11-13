<?php
/**
 * Pure pricing rules, independent of WordPress and WooCommerce.
 *
 * @package Exin\BlackFriday
 */

declare(strict_types=1);

namespace Exin\BlackFriday\Domain;

use InvalidArgumentException;

/** Builds deterministic campaign prices with exact currency-unit arithmetic. */
final class PricingEngine {

	/**
	 * Price(i) = base - (base-floor) * (i/(days-1))^power.
	 * Linear uses power=1. The one-day campaign starts at its floor.
	 * Rounding is applied to the resulting price in the smallest currency unit.
	 * Decimal-string intermediate arithmetic avoids integer multiplication overflow.
	 * Money conversion propagates OverflowException for unsupported price magnitudes.
	 *
	 * @param string $base Positive campaign base price as a decimal string.
	 * @param string $floor Positive minimum price, no greater than base.
	 * @param int    $days Number of local campaign days, one to 366.
	 * @param string $strategy Linear or progressive pricing strategy.
	 * @param int    $precision Number of fractional currency digits, zero to eight.
	 * @param int    $power Progressive integer exponent, one to eight.
	 * @param string $rounding Final-price rounding: half_up, floor or ceil.
	 * @return string[] Exactly $days decimal currency strings.
	 * @throws InvalidArgumentException When any pricing input violates the rules.
	 */
	public function schedule( string $base, string $floor, int $days, string $strategy = 'linear', int $precision = 2, int $power = 2, string $rounding = 'half_up' ): array {
		$base_minor  = Money::parse( $base, $precision );
		$floor_minor = Money::parse( $floor, $precision );
		if ( $base_minor <= 0 || $floor_minor <= 0 || $floor_minor > $base_minor ) {
			throw new InvalidArgumentException( 'Base and floor must be positive and floor must not exceed base.' );
		}
		if ( $days < 1 || $days > 366 ) {
			throw new InvalidArgumentException( 'Campaign days must be between one and 366.' );
		}
		if ( ! in_array( $strategy, array( 'linear', 'progressive' ), true ) ) {
			throw new InvalidArgumentException( 'Unknown pricing strategy.' );
		}
		if ( $power < 1 || $power > 8 ) {
			throw new InvalidArgumentException( 'Progressive power must be an integer between one and eight.' );
		}
		if ( ! in_array( $rounding, array( 'half_up', 'floor', 'ceil' ), true ) ) {
			throw new InvalidArgumentException( 'Unknown rounding policy.' );
		}
		if ( 1 === $days ) {
			return array( Money::format( $floor_minor, $precision ) );
		}
		$exponent    = 'linear' === $strategy ? 1 : $power;
		$denominator = IntegerMath::power( $days - 1, $exponent );
		$difference  = $base_minor - $floor_minor;
		$schedule    = array();
		for ( $day = 0; $day < $days; ++$day ) {
			$numerator                     = IntegerMath::multiply( (string) $difference, IntegerMath::power( $day, $exponent ) );
			list( $reduction, $remainder ) = IntegerMath::divide( $numerator, $denominator );
			$price                         = $base_minor - (int) $reduction;
			if ( '0' !== $remainder && ( 'floor' === $rounding || ( 'half_up' === $rounding && IntegerMath::compare( IntegerMath::multiply( $remainder, '2' ), $denominator ) > 0 ) ) ) {
				--$price;
			}
			$schedule[] = Money::format( max( $floor_minor, $price ), $precision );
		}
		return $schedule;
	}

	/**
	 * Calculate the exact current discount percentage for presentation.
	 *
	 * Money conversion propagates OverflowException for unsupported price magnitudes.
	 *
	 * @param string $base Positive comparison price as a decimal string.
	 * @param string $current Current price between zero and base, inclusive.
	 * @param int    $precision Number of fractional currency digits.
	 * @param int    $percent_precision Number of fractional percentage digits.
	 * @return string Exact percentage rounded half-up to the requested digits.
	 * @throws InvalidArgumentException When the amounts or precision are invalid.
	 */
	public function discount_percent( string $base, string $current, int $precision = 2, int $percent_precision = 2 ): string {
		$base_minor    = Money::parse( $base, $precision );
		$current_minor = Money::parse( $current, $precision );
		Money::validate_precision( $percent_precision );
		if ( $base_minor <= 0 || $current_minor < 0 || $current_minor > $base_minor ) {
			throw new InvalidArgumentException( 'Discount requires a positive base and a current price between zero and base.' );
		}
		$scale                        = '100' . str_repeat( '0', $percent_precision );
		list( $quotient, $remainder ) = IntegerMath::divide( IntegerMath::multiply( (string) ( $base_minor - $current_minor ), $scale ), (string) $base_minor );
		$minor                        = (int) $quotient;
		if ( IntegerMath::compare( IntegerMath::multiply( $remainder, '2' ), (string) $base_minor ) >= 0 ) {
			++$minor;
		}
		return Money::format( $minor, $percent_precision );
	}
}
