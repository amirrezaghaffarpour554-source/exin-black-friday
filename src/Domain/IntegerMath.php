<?php
/**
 * Minimal unsigned decimal arithmetic for overflow-free intermediate values.
 *
 * @package Exin\BlackFriday
 */

declare(strict_types=1);

namespace Exin\BlackFriday\Domain;

/**
 * Provides the unsigned arithmetic needed for exact pricing ratios.
 *
 * @internal All input strings are normalized nonnegative integers.
 */
final class IntegerMath {

	/**
	 * Compare two unsigned integers of arbitrary length.
	 *
	 * @param string $left Normalized nonnegative integer.
	 * @param string $right Normalized nonnegative integer.
	 * @return int Negative, zero or positive according to the comparison.
	 */
	public static function compare( string $left, string $right ): int {
		return strlen( $left ) === strlen( $right ) ? strcmp( $left, $right ) : strlen( $left ) <=> strlen( $right );
	}

	/**
	 * Multiply two unsigned integers using single-digit intermediates.
	 *
	 * @param string $left Normalized nonnegative integer.
	 * @param string $right Normalized nonnegative integer.
	 * @return string Exact normalized product.
	 */
	public static function multiply( string $left, string $right ): string {
		if ( '0' === $left || '0' === $right ) {
			return '0';
		}
		$result = array_fill( 0, strlen( $left ) + strlen( $right ), 0 );
		for ( $i = strlen( $left ) - 1; $i >= 0; --$i ) {
			for ( $j = strlen( $right ) - 1; $j >= 0; --$j ) {
				$position                 = $i + $j + 1;
				$product                  = (int) $left[ $i ] * (int) $right[ $j ] + $result[ $position ];
				$result[ $position ]      = $product % 10;
				$result[ $position - 1 ] += intdiv( $product, 10 );
			}
		}
		return ltrim( implode( '', $result ), '0' );
	}

	/**
	 * Raise a nonnegative integer to a nonnegative integer exponent.
	 *
	 * @param int $base Nonnegative integer base.
	 * @param int $power Nonnegative integer exponent.
	 * @return string Exact normalized integer power.
	 */
	public static function power( int $base, int $power ): string {
		$result = '1';
		for ( $i = 0; $i < $power; ++$i ) {
			$result = self::multiply( $result, (string) $base );
		}
		return $result;
	}

	/**
	 * Calculate an exact quotient and remainder using schoolbook long division.
	 *
	 * @param string $numerator Normalized nonnegative numerator.
	 * @param string $denominator Normalized positive denominator.
	 * @return string[] Exact quotient and remainder, in that order.
	 * @throws \DivisionByZeroError When the denominator is zero.
	 */
	public static function divide( string $numerator, string $denominator ): array {
		if ( '0' === $denominator ) {
			throw new \DivisionByZeroError( 'Zero denominator.' );
		}
		$quotient  = '';
		$remainder = '0';
		$length    = strlen( $numerator );
		for ( $i = 0; $i < $length; ++$i ) {
			$remainder = ltrim( $remainder . $numerator[ $i ], '0' );
			$remainder = '' === $remainder ? '0' : $remainder;
			$digit     = 0;
			while ( self::compare( $remainder, $denominator ) >= 0 ) {
				$remainder = self::subtract( $remainder, $denominator );
				++$digit;
			}
			$quotient .= (string) $digit;
		}
		$quotient = ltrim( $quotient, '0' );
		return array( '' === $quotient ? '0' : $quotient, $remainder );
	}

	/**
	 * Subtract unsigned integers whose difference is nonnegative.
	 *
	 * @param string $left Normalized integer greater than or equal to right.
	 * @param string $right Normalized nonnegative integer.
	 * @return string Exact normalized difference.
	 */
	private static function subtract( string $left, string $right ): string {
		$right  = str_pad( $right, strlen( $left ), '0', STR_PAD_LEFT );
		$result = '';
		$borrow = 0;
		for ( $i = strlen( $left ) - 1; $i >= 0; --$i ) {
			$digit  = (int) $left[ $i ] - (int) $right[ $i ] - $borrow;
			$borrow = $digit < 0 ? 1 : 0;
			$result = (string) ( $digit < 0 ? $digit + 10 : $digit ) . $result;
		}
		$result = ltrim( $result, '0' );
		return '' === $result ? '0' : $result;
	}
}
