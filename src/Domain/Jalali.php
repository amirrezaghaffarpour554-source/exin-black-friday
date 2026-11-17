<?php
/**
 * Deterministic Jalali/Gregorian calendar conversion at the presentation boundary.
 *
 * @package Exin\BlackFriday
 */

declare(strict_types=1);

namespace Exin\BlackFriday\Domain;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Uses the Borkowski break-year arithmetic; dates remain timezone-free here. */
final class Jalali {
	/** Break years defining the deterministic Jalali leap-year calculation. */
	private const BREAKS = array( -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178 );

	/**
	 * Test whether a supported Jalali year contains 366 days.
	 *
	 * @param int $year Jalali year, 1200 through 1600.
	 * @return bool Whether Esfand has thirty days.
	 */
	public static function is_leap( int $year ): bool {
		self::validate_year( $year );
		return 0 === self::year_info( $year )['leap'];
	}

	/**
	 * Obtain a validated Jalali month's length.
	 *
	 * @param int $year Jalali year.
	 * @param int $month Month number.
	 * @return int Days in the month.
	 * @throws InvalidArgumentException When the month is invalid.
	 */
	public static function month_length( int $year, int $month ): int {
		self::validate_year( $year );
		if ( $month < 1 || $month > 12 ) {
			throw new InvalidArgumentException( 'invalid_jalali_month' );
		}
		return $month <= 6 ? 31 : ( $month <= 11 || self::is_leap( $year ) ? 30 : 29 );
	}

	/**
	 * Parse an actual Jalali date containing Persian, Arabic or Western digits.
	 *
	 * @param string $date Date formatted as YYYY/MM/DD or YYYY-MM-DD.
	 * @return int[] Validated year, month and day.
	 * @throws InvalidArgumentException When the date is malformed or nonexistent.
	 */
	public static function parse( string $date ): array {
		$date = trim( MoneyInput::digits( $date ) );
		if ( ! preg_match( '/\A([0-9]{1,4})[\/-]([0-9]{1,2})[\/-]([0-9]{1,2})\z/D', $date, $parts ) ) {
			throw new InvalidArgumentException( 'invalid_jalali_date' );
		}
		$year  = (int) $parts[1];
		$month = (int) $parts[2];
		$day   = (int) $parts[3];
		if ( $day < 1 || $day > self::month_length( $year, $month ) ) {
			throw new InvalidArgumentException( 'invalid_jalali_day' );
		}
		return array(
			'year'  => $year,
			'month' => $month,
			'day'   => $day,
		);
	}

	/**
	 * Convert a valid Jalali date to Gregorian calendar fields.
	 *
	 * @param int $year Jalali year.
	 * @param int $month Jalali month.
	 * @param int $day Jalali day.
	 * @return int[] Gregorian year, month and day.
	 * @throws InvalidArgumentException When the Jalali date is invalid.
	 */
	public static function to_gregorian( int $year, int $month, int $day ): array {
		if ( $day < 1 || $day > self::month_length( $year, $month ) ) {
			throw new InvalidArgumentException( 'invalid_jalali_day' );
		}
		$info   = self::year_info( $year );
		$start  = self::gregorian_date( $info['gregorian_year'], 3, $info['march'] );
		$offset = ( $month <= 7 ? ( $month - 1 ) * 31 : ( $month - 1 ) * 30 + 6 ) + $day - 1;
		$date   = $start->add( new DateInterval( 'P' . $offset . 'D' ) );
		return array(
			'year'  => (int) $date->format( 'Y' ),
			'month' => (int) $date->format( 'n' ),
			'day'   => (int) $date->format( 'j' ),
		);
	}

	/**
	 * Convert Gregorian calendar fields to a valid Jalali date.
	 *
	 * @param int $year Gregorian year.
	 * @param int $month Gregorian month.
	 * @param int $day Gregorian day.
	 * @return int[] Jalali year, month and day.
	 */
	public static function from_gregorian( int $year, int $month, int $day ): array {
		$date        = self::gregorian_date( $year, $month, $day );
		$jalali_year = $year - 621;
		$info        = self::year_info( $jalali_year );
		$start       = self::gregorian_date( $year, 3, $info['march'] );
		if ( $date < $start ) {
			--$jalali_year;
			$info  = self::year_info( $jalali_year );
			$start = self::gregorian_date( $year - 1, 3, $info['march'] );
		}
		$offset = (int) $start->diff( $date )->days;
		$month  = $offset < 186 ? intdiv( $offset, 31 ) + 1 : intdiv( $offset - 186, 30 ) + 7;
		$day    = $offset < 186 ? $offset % 31 + 1 : ( $offset - 186 ) % 30 + 1;
		self::validate_year( $jalali_year );
		return array(
			'year'  => $jalali_year,
			'month' => $month,
			'day'   => $day,
		);
	}

	/**
	 * Convert Jalali date and 24-hour time into a Gregorian local timestamp.
	 *
	 * The caller must apply the campaign's IANA timezone and persist UTC. This
	 * method deliberately does not depend on PHP's process-default timezone.
	 *
	 * @param string $date Jalali calendar date.
	 * @param string $time Twenty-four-hour HH:mm time.
	 * @return string Gregorian local Y-m-d H:i:s timestamp.
	 * @throws InvalidArgumentException When the time is invalid.
	 */
	public static function to_local( string $date, string $time ): string {
		$parts = self::parse( $date );
		$time  = MoneyInput::digits( trim( $time ) );
		if ( ! preg_match( '/\A([01][0-9]|2[0-3]):([0-5][0-9])\z/D', $time ) ) {
			throw new InvalidArgumentException( 'invalid_24_hour_time' );
		}
		$gregorian = self::to_gregorian( $parts['year'], $parts['month'], $parts['day'] );
		return sprintf( '%04d-%02d-%02d %s:00', $gregorian['year'], $gregorian['month'], $gregorian['day'], $time );
	}

	/**
	 * Present a Gregorian local timestamp using a Jalali date and 24-hour time.
	 *
	 * @param string $local Canonical local Y-m-d H:i:s or Y-m-d H:i timestamp.
	 * @return string[] Persian date, ASCII date and ASCII twenty-four-hour time.
	 * @throws InvalidArgumentException When the timestamp is invalid.
	 */
	public static function from_local( string $local ): array {
		if ( ! preg_match( '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})[ T]([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?\z/D', $local, $parts ) ) {
			throw new InvalidArgumentException( 'invalid_gregorian_local_time' );
		}
		$date  = self::from_gregorian( (int) $parts[1], (int) $parts[2], (int) $parts[3] );
		$ascii = sprintf( '%04d/%02d/%02d', $date['year'], $date['month'], $date['day'] );
		return array(
			'date'       => strtr( $ascii, array_combine( range( 0, 9 ), array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ) ) ),
			'ascii_date' => $ascii,
			'time'       => $parts[4] . ':' . $parts[5],
		);
	}

	/**
	 * Calculate March's New Year anchor and leap position from break-year arithmetic.
	 *
	 * @param int $year Jalali year in the supported positive range.
	 * @return int[] Gregorian year, March day and leap position (zero is leap).
	 * @throws InvalidArgumentException When the year is outside the calendar range.
	 */
	private static function year_info( int $year ): array {
		if ( $year < 1 || $year >= 3178 ) {
			throw new InvalidArgumentException( 'unsupported_jalali_year' );
		}
		$gregorian_year = $year + 621;
		$leap_jalali    = -14;
		$previous       = self::BREAKS[0];
		$jump           = 0;
		foreach ( array_slice( self::BREAKS, 1 ) as $next ) {
			$jump = $next - $previous;
			if ( $year < $next ) {
				break;
			}
			$leap_jalali += intdiv( $jump, 33 ) * 8 + intdiv( $jump % 33, 4 );
			$previous     = $next;
		}
		$offset       = $year - $previous;
		$leap_jalali += intdiv( $offset, 33 ) * 8 + intdiv( $offset % 33 + 3, 4 );
		if ( 4 === $jump % 33 && 4 === $jump - $offset ) {
			++$leap_jalali;
		}
		$leap_gregorian = intdiv( $gregorian_year, 4 ) - intdiv( ( intdiv( $gregorian_year, 100 ) + 1 ) * 3, 4 ) - 150;
		$march          = 20 + $leap_jalali - $leap_gregorian;
		if ( $jump - $offset < 6 ) {
			$offset = $offset - $jump + intdiv( $jump + 4, 33 ) * 33;
		}
		$leap = ( ( $offset + 1 ) % 33 - 1 ) % 4;
		return array(
			'gregorian_year' => $gregorian_year,
			'march'          => $march,
			'leap'           => -1 === $leap ? 4 : $leap,
		);
	}

	/**
	 * Bound the supported campaign date picker and server calendar equally.
	 *
	 * @param int $year Jalali business-calendar year.
	 * @return void
	 * @throws InvalidArgumentException When a year falls outside 1200 through 1600.
	 */
	private static function validate_year( int $year ): void {
		if ( $year < 1200 || $year > 1600 ) {
			throw new InvalidArgumentException( 'unsupported_jalali_year' );
		}
	}

	/**
	 * Create a strict Gregorian date at UTC midnight for calendar arithmetic.
	 *
	 * @param int $year Gregorian year.
	 * @param int $month Gregorian month.
	 * @param int $day Gregorian day.
	 * @return DateTimeImmutable Valid UTC calendar date.
	 * @throws InvalidArgumentException When the date is invalid.
	 */
	private static function gregorian_date( int $year, int $month, int $day ): DateTimeImmutable {
		if ( ! checkdate( $month, $day, $year ) ) {
			throw new InvalidArgumentException( 'invalid_gregorian_date' );
		}
		return new DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day ), new DateTimeZone( 'UTC' ) );
	}
}
