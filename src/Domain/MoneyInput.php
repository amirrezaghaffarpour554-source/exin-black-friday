<?php
/**
 * Locale-aware monetary input normalization without floating-point arithmetic.
 *
 * @package Exin\BlackFriday
 */

declare(strict_types=1);

namespace Exin\BlackFriday\Domain;

use InvalidArgumentException;
use OverflowException;

/** Converts editable Persian, Arabic and Western amounts to plain decimals. */
final class MoneyInput {
	/**
	 * Normalize and validate one nonnegative amount, preserving exact precision.
	 *
	 * Nine input places are supported only to represent an eight-place rial
	 * currency in toman. Financial store precision remains limited by Money.
	 *
	 * @param string $input User input with optional valid thousands separators.
	 * @param int    $precision Maximum input fractional digits, zero to nine.
	 * @return string Canonical unsigned decimal with fixed fractional digits.
	 * @throws InvalidArgumentException When input or grouping is malformed.
	 * @throws OverflowException When the corresponding smallest units overflow.
	 */
	public static function normalize( string $input, int $precision ): string {
		if ( $precision < 0 || $precision > 9 || strlen( $input ) > 256 ) {
			throw new InvalidArgumentException( 'invalid_money_precision_or_length' );
		}
		$input   = self::digits( $input );
		$input   = preg_replace( '/\A\s+|\s+\z/u', '', $input ) ?? '';
		$input   = str_replace( array( '٬', '،', "\u{00A0}", "\u{202F}", "\u{2009}" ), ',', $input );
		$input   = str_replace( '٫', '.', $input );
		$grouped = str_contains( $input, ',' );
		$pattern = $grouped ? '/\A[0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]+)?\z/D' : '/\A[0-9]+(?:\.[0-9]+)?\z/D';
		if ( ! preg_match( $pattern, $input ) ) {
			throw new InvalidArgumentException( 'invalid_money_input' );
		}
		$parts    = explode( '.', str_replace( ',', '', $input ) );
		$fraction = $parts[1] ?? '';
		if ( strlen( $fraction ) > $precision ) {
			throw new InvalidArgumentException( 'money_precision_exceeded' );
		}
		$whole    = ltrim( $parts[0], '0' );
		$whole    = '' === $whole ? '0' : $whole;
		$fraction = str_pad( $fraction, $precision, '0' );
		$minor    = ltrim( $whole . $fraction, '0' );
		$minor    = '' === $minor ? '0' : $minor;
		$limit    = (string) PHP_INT_MAX;
		if ( strlen( $minor ) > strlen( $limit ) || ( strlen( $minor ) === strlen( $limit ) && strcmp( $minor, $limit ) > 0 ) ) {
			throw new OverflowException( 'money_input_overflow' );
		}
		return $whole . ( $precision ? '.' . $fraction : '' );
	}

	/**
	 * Convert Persian and Arabic decimal digits without changing other input.
	 *
	 * @param string $input Text to normalize.
	 * @return string Text containing Western digits.
	 */
	public static function digits( string $input ): string {
		return strtr(
			$input,
			array(
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);
	}
}
