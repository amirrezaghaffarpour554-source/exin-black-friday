<?php
/**
 * Exact conversion between decimal currency strings and smallest units.
 *
 * @package Exin\BlackFriday
 */

declare(strict_types=1);

namespace Exin\BlackFriday\Domain;

use InvalidArgumentException;
use OverflowException;

/** Converts money without floating-point arithmetic or precision loss. */
final class Money {

	/**
	 * Parse a plain decimal amount into exact smallest currency units.
	 *
	 * @param string $amount Decimal string without locale separators or exponents.
	 * @param int    $precision Number of fractional currency digits, zero to eight.
	 * @return int Signed amount with magnitude no greater than PHP_INT_MAX.
	 * @throws InvalidArgumentException When the amount or precision is invalid.
	 * @throws OverflowException When the amount exceeds the integer range.
	 */
	public static function parse( string $amount, int $precision ): int {
		self::validate_precision( $precision );
		$amount = trim( $amount );
		if ( ! preg_match( '/\A(-?)(\d+)(?:\.(\d+))?\z/D', $amount, $matches ) ) {
			throw new InvalidArgumentException( 'Amount must be a plain decimal string.' );
		}
		$fraction = $matches[3] ?? '';
		if ( strlen( $fraction ) > $precision ) {
			throw new InvalidArgumentException( 'Amount exceeds currency precision.' );
		}
		$digits = ltrim( $matches[2] . str_pad( $fraction, $precision, '0' ), '0' );
		$digits = '' === $digits ? '0' : $digits;
		$limit  = (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $limit ) || ( strlen( $digits ) === strlen( $limit ) && strcmp( $digits, $limit ) > 0 ) ) {
			throw new OverflowException( 'Amount exceeds the supported integer range.' );
		}
		$minor = (int) $digits;
		return '-' === $matches[1] ? -$minor : $minor;
	}

	/**
	 * Format smallest units without a float cast, including PHP_INT_MIN.
	 *
	 * @param int $minor Signed amount in smallest currency units.
	 * @param int $precision Number of fractional currency digits, zero to eight.
	 * @return string Plain decimal currency string with fixed fractional digits.
	 * @throws InvalidArgumentException When the precision is unsupported.
	 */
	public static function format( int $minor, int $precision ): string {
		self::validate_precision( $precision );
		$text     = (string) $minor;
		$negative = str_starts_with( $text, '-' );
		$digits   = $negative ? substr( $text, 1 ) : $text;
		if ( 0 === $precision ) {
			return ( $negative ? '-' : '' ) . $digits;
		}
		$digits = str_pad( $digits, $precision + 1, '0', STR_PAD_LEFT );
		return ( $negative ? '-' : '' ) . substr( $digits, 0, -$precision ) . '.' . substr( $digits, -$precision );
	}

	/**
	 * Validate the supported currency precision.
	 *
	 * @param int $precision Number of fractional currency digits.
	 * @return void
	 * @throws InvalidArgumentException When precision is outside zero to eight.
	 */
	public static function validate_precision( int $precision ): void {
		if ( $precision < 0 || $precision > 8 ) {
			throw new InvalidArgumentException( 'Currency precision must be between zero and eight.' );
		}
	}
}
