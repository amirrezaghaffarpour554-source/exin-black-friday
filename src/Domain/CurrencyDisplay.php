<?php
/**
 * Exact toman display conversion while WooCommerce stores its own currency.
 *
 * @package Exin\BlackFriday
 */

declare(strict_types=1);

namespace Exin\BlackFriday\Domain;

use InvalidArgumentException;

/** Recognizes explicit Iranian units and deliberately performs no currency FX. */
final class CurrencyDisplay {
	/**
	 * Check whether a store currency has an explicit supported toman conversion.
	 *
	 * @param string $currency WooCommerce currency code.
	 * @return bool Whether toman display is supported.
	 */
	public static function is_toman( string $currency ): bool {
		return in_array( strtoupper( $currency ), array( 'IRR', 'IRT', 'TMN' ), true );
	}

	/**
	 * Choose a safe display unit without inferring an exchange rate.
	 *
	 * @param string $currency WooCommerce currency code.
	 * @return string Toman for explicit Iranian units, otherwise store.
	 */
	public static function unit( string $currency ): string {
		return self::is_toman( $currency ) ? 'toman' : 'store';
	}

	/**
	 * Convert and validate editable input into canonical store-currency units.
	 *
	 * @param string $amount Input decimal, optionally locale formatted.
	 * @param string $unit Declared input unit: store or toman.
	 * @param string $currency WooCommerce currency code.
	 * @param int    $precision Store currency precision.
	 * @return string Exact fixed-precision WooCommerce decimal.
	 * @throws InvalidArgumentException When the unit has no supported conversion.
	 */
	public static function to_store( string $amount, string $unit, string $currency, int $precision ): string {
		Money::validate_precision( $precision );
		if ( ! in_array( $unit, array( 'store', 'toman' ), true ) || ( 'toman' === $unit && ! self::is_toman( $currency ) ) ) {
			throw new InvalidArgumentException( 'unsupported_amount_unit' );
		}
		if ( 'toman' !== $unit || 'IRR' !== strtoupper( $currency ) ) {
			return MoneyInput::normalize( $amount, $precision );
		}
		// One minor toman at p+1 places is exactly one minor rial at p places.
		$normalized = MoneyInput::normalize( $amount, $precision + 1 );
		$digits     = ltrim( str_replace( '.', '', $normalized ), '0' );
		return Money::format( (int) ( '' === $digits ? '0' : $digits ), $precision );
	}

	/**
	 * Convert a canonical store amount to an exact ungrouped display decimal.
	 *
	 * @param string $amount Plain store-currency decimal.
	 * @param string $currency WooCommerce currency code.
	 * @param int    $precision Store currency precision.
	 * @return string Display decimal without unnecessary trailing zeros.
	 */
	public static function from_store( string $amount, string $currency, int $precision ): string {
		$minor             = Money::parse( $amount, $precision );
		$display_precision = $precision + ( 'IRR' === strtoupper( $currency ) ? 1 : 0 );
		$text              = (string) $minor;
		$negative          = str_starts_with( $text, '-' );
		$digits            = $negative ? substr( $text, 1 ) : $text;
		if ( $display_precision ) {
			$digits = str_pad( $digits, $display_precision + 1, '0', STR_PAD_LEFT );
			$text   = substr( $digits, 0, -$display_precision ) . '.' . substr( $digits, -$display_precision );
			$text   = rtrim( rtrim( $text, '0' ), '.' );
		} else {
			$text = $digits;
		}
		return ( $negative && '0' !== $text ? '-' : '' ) . $text;
	}

	/**
	 * Provide the 150,000-toman default only when its store value is known.
	 *
	 * @param string $currency WooCommerce currency code.
	 * @param int    $precision Store currency precision.
	 * @return string|null Canonical subsidy cap, or null for unknown conversions.
	 */
	public static function default_subsidy_cap( string $currency, int $precision ): ?string {
		return self::is_toman( $currency ) ? self::to_store( '150000', 'toman', $currency, $precision ) : null;
	}
}
