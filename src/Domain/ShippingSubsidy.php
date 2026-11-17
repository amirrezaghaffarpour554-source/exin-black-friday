<?php
/**
 * Exact, bounded shipping-subsidy arithmetic independent of WooCommerce.
 *
 * @package Exin\BlackFriday
 */

declare(strict_types=1);

namespace Exin\BlackFriday\Domain;

use InvalidArgumentException;
use OverflowException;

/** Calculates a customer remainder and deterministic shared package budgets. */
final class ShippingSubsidy {
	/**
	 * Round a nonnegative Woo decimal to store precision without a float cast.
	 *
	 * WooCommerce may provide unrounded line/tax decimals. Threshold comparisons
	 * use the same half-up currency quantum while preserving every input digit.
	 *
	 * @param string $amount Plain decimal supplied by WooCommerce.
	 * @param int    $precision Store currency precision.
	 * @return string Canonical rounded amount.
	 * @throws InvalidArgumentException When the amount is malformed or negative.
	 * @throws OverflowException When rounding would exceed the integer range.
	 */
	public static function round_amount( string $amount, int $precision ): string {
		Money::validate_precision( $precision );
		if ( ! preg_match( '/\A(\d+)(?:\.(\d+))?\z/D', $amount, $matches ) ) {
			throw new InvalidArgumentException( 'Shipping amounts must be nonnegative plain decimals.' );
		}
		$fraction = $matches[2] ?? '';
		if ( strlen( $fraction ) <= $precision ) {
			return Money::format( Money::parse( $amount, $precision ), $precision );
		}
		$truncated = $matches[1] . ( $precision ? '.' . substr( $fraction, 0, $precision ) : '' );
		$minor     = Money::parse( $truncated, $precision );
		if ( $fraction[ $precision ] >= '5' ) {
			if ( PHP_INT_MAX === $minor ) {
				throw new OverflowException( 'Rounded shipping amount exceeds the integer range.' );
			}
			++$minor;
		}
		return Money::format( $minor, $precision );
	}
	/**
	 * Deduct a package's subsidy from its ex-tax base without a negative result.
	 *
	 * @param string $base Canonical base shipping cost in the store currency.
	 * @param string $budget Canonical maximum subsidy for this package.
	 * @param int    $precision Currency precision.
	 * @return string Canonical customer-paid ex-tax shipping remainder.
	 * @throws InvalidArgumentException When a cost or budget is negative.
	 */
	public static function remaining( string $base, string $budget, int $precision ): string {
		$base_minor   = Money::parse( $base, $precision );
		$budget_minor = Money::parse( $budget, $precision );
		if ( $base_minor < 0 || $budget_minor < 0 ) {
			throw new InvalidArgumentException( 'Shipping cost and subsidy must be nonnegative.' );
		}
		return Money::format( $base_minor - min( $base_minor, $budget_minor ), $precision );
	}

	/**
	 * Share one cart-wide cap between stable, unique qualifying package keys.
	 *
	 * Shares are equal in integer minor units; any remainder goes to the first
	 * lexicographically sorted keys. Unused shares are not reallocated. Alternative
	 * methods within the same package share its one budget, rather than adding it.
	 *
	 * @param string $cap Canonical cart-wide subsidy cap.
	 * @param int    $precision Currency precision.
	 * @param array  $package_keys Unique string or integer package identifiers.
	 * @return array<string, string> Canonical budgets indexed by package key.
	 * @throws InvalidArgumentException When the cap or package identifiers are invalid.
	 */
	public static function allocate( string $cap, int $precision, array $package_keys ): array {
		$minor = Money::parse( $cap, $precision );
		if ( $minor < 0 ) {
			throw new InvalidArgumentException( 'Shipping subsidy must be nonnegative.' );
		}
		$keys = array();
		foreach ( $package_keys as $key ) {
			if ( ! is_int( $key ) && ! is_string( $key ) ) {
				throw new InvalidArgumentException( 'Shipping package keys must be strings or integers.' );
			}
			$key = (string) $key;
			if ( '' === $key || isset( $keys[ $key ] ) ) {
				throw new InvalidArgumentException( 'Shipping package keys must be unique and nonempty.' );
			}
			$keys[ $key ] = true;
		}
		if ( ! $keys ) {
			return array();
		}
		$keys = array_keys( $keys );
		sort( $keys, SORT_STRING );
		$count     = count( $keys );
		$share     = intdiv( $minor, $count );
		$remainder = $minor % $count;
		$result    = array();
		foreach ( $keys as $index => $key ) {
			$result[ $key ] = Money::format( $share + ( $index < $remainder ? 1 : 0 ), $precision );
		}
		return $result;
	}
}
