<?php
/**
 * Calendar-day campaign timing with canonical UTC persistence.
 *
 * @package Exin\BlackFriday
 */

declare(strict_types=1);

namespace Exin\BlackFriday\Domain;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Maps local calendar-day campaign boundaries to canonical UTC timestamps. */
final class Timeline {

	/**
	 * Preserve local wall time when crossing calendar days (DST may be 23/25h).
	 * PHP's documented timezone normalization resolves a nonexistent wall time
	 * forward across the timezone gap; an ambiguous time uses its chosen offset.
	 *
	 * @param string $start_utc Canonical UTC start in Y-m-d H:i:s format.
	 * @param int    $days Number of local campaign days, one to 366.
	 * @param string $timezone Supported IANA timezone identifier.
	 * @return string[] UTC day starts, indexed from zero.
	 * @throws InvalidArgumentException When the date, duration or timezone is invalid.
	 */
	public static function boundaries( string $start_utc, int $days, string $timezone ): array {
		if ( $days < 1 || $days > 366 ) {
			throw new InvalidArgumentException( 'Campaign days must be between one and 366.' );
		}
		$start       = self::parse_utc( $start_utc );
		$local_start = $start->setTimezone( self::timezone( $timezone ) );
		$utc         = new DateTimeZone( 'UTC' );
		$result      = array();
		for ( $day = 0; $day < $days; ++$day ) {
			$result[] = $local_start->add( new DateInterval( 'P' . $day . 'D' ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		}
		return $result;
	}

	/**
	 * Determine the current campaign day directly, including delayed execution.
	 *
	 * @param array $campaign Campaign with start_utc, end_utc, days and timezone.
	 * @param int   $now Current Unix timestamp from the trusted server clock.
	 * @return int Minus one before start, zero-based day during, days after end.
	 * @throws InvalidArgumentException When campaign timing is invalid or inconsistent.
	 */
	public static function day_index( array $campaign, int $now ): int {
		$days       = (int) ( $campaign['days'] ?? 0 );
		$boundaries = self::boundaries( (string) ( $campaign['start_utc'] ?? '' ), $days, (string) ( $campaign['timezone'] ?? '' ) );
		$start      = self::parse_utc( $boundaries[0] )->getTimestamp();
		$end        = self::parse_utc( (string) ( $campaign['end_utc'] ?? '' ) )->getTimestamp();
		if ( $end <= self::parse_utc( $boundaries[ $days - 1 ] )->getTimestamp() ) {
			throw new InvalidArgumentException( 'Campaign end must follow its final day boundary.' );
		}
		if ( $now < $start ) {
			return -1;
		}
		if ( $now >= $end ) {
			return $days;
		}
		for ( $day = $days - 1; $day >= 0; --$day ) {
			if ( $now >= self::parse_utc( $boundaries[ $day ] )->getTimestamp() ) {
				return $day;
			}
		}
		return -1;
	}

	/**
	 * Calculate the canonical end after exactly the given local calendar days.
	 *
	 * @param string $start_utc Canonical UTC start in Y-m-d H:i:s format.
	 * @param int    $days Number of local campaign days, one to 366.
	 * @param string $timezone Supported IANA timezone identifier.
	 * @return string Canonical UTC end in Y-m-d H:i:s format.
	 * @throws InvalidArgumentException When the date, duration or timezone is invalid.
	 */
	public static function end( string $start_utc, int $days, string $timezone ): string {
		if ( $days < 1 || $days > 366 ) {
			throw new InvalidArgumentException( 'Campaign days must be between one and 366.' );
		}
		return self::parse_utc( $start_utc )->setTimezone( self::timezone( $timezone ) )->add( new DateInterval( 'P' . $days . 'D' ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Parse a canonical UTC datetime without accepting normalized invalid dates.
	 *
	 * @param string $value UTC datetime in exact Y-m-d H:i:s format.
	 * @return DateTimeImmutable Validated immutable UTC datetime.
	 * @throws InvalidArgumentException When the format or calendar date is invalid.
	 */
	public static function parse_utc( string $value ): DateTimeImmutable {
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			throw new InvalidArgumentException( 'Time must be a valid canonical UTC Y-m-d H:i:s value.' );
		}
		return $date;
	}

	/**
	 * Validate a timezone against PHP's IANA timezone database.
	 *
	 * @param string $name Timezone identifier, including supported legacy aliases.
	 * @return DateTimeZone Validated timezone.
	 * @throws InvalidArgumentException When the timezone identifier is unsupported.
	 */
	private static function timezone( string $name ): DateTimeZone {
		if ( ! in_array( $name, DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC ), true ) ) {
			throw new InvalidArgumentException( 'Timezone must be a supported IANA identifier.' );
		}
		return new DateTimeZone( $name );
	}
}
