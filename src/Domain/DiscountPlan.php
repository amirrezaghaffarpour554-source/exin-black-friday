<?php
/**
 * Exact discount stages and their UTC execution boundaries.
 *
 * @package ExinBlackFriday
 */

declare(strict_types=1);

namespace Exin\BlackFriday\Domain;

use DateInterval;
use InvalidArgumentException;

/** Adds scheduled discounts while preserving the original campaign rules. */
final class DiscountPlan {

	/** Maximum supported span in minutes, matching 366 campaign days. */
	private const MAX_MINUTES = 527040;

	/**
	 * Validate and canonicalize a new discount plan without touching products.
	 *
	 * @param array $input Submitted plan in store currency units.
	 * @param int   $precision Store currency precision.
	 * @return array Canonical plan.
	 * @throws InvalidArgumentException When a plan is unsafe or inconsistent.
	 */
	public static function normalize( array $input, int $precision ): array {
		Money::validate_precision( $precision );
		$mode     = $input['mode'] ?? 'fixed_percent';
		$interval = $input['interval'] ?? 'daily';
		if ( ! in_array( $mode, array( 'fixed_percent', 'fixed_amount', 'linear', 'stepped', 'custom' ), true )
			|| ! in_array( $interval, array( 'daily', 'hourly', 'custom' ), true ) ) {
			throw new InvalidArgumentException( 'invalid_discount_plan_mode' );
		}
		$steps   = self::integer( $input['steps'] ?? 7, 1, 366 );
		$minutes = 'hourly' === $interval ? 60 : ( 'daily' === $interval ? 1440 : self::integer( $input['interval_minutes'] ?? 60, 1, self::MAX_MINUTES ) );
		$reach   = $input['reach_floor'] ?? false;
		if ( ! is_bool( $reach ) ) {
			throw new InvalidArgumentException( 'invalid_discount_floor_confirmation' );
		}
		$initial = self::amount( $input['initial_discount'] ?? ( 'fixed_amount' === $mode || 'custom' === $mode ? '0' : '5' ), 'fixed_amount' === $mode ? $precision : 2 );
		$final   = self::amount( $input['final_discount'] ?? '15', 2 );
		if ( ( 'fixed_amount' !== $mode && Money::parse( $initial, 2 ) > 10000 ) || Money::parse( $final, 2 ) > 10000 ) {
			throw new InvalidArgumentException( 'discount_percent_out_of_range' );
		}
		$points   = array();
		$duration = $steps * $minutes;
		if ( 'custom' === $mode ) {
			$rows = $input['points'] ?? array();
			if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) < 1 || count( $rows ) > 366 ) {
				throw new InvalidArgumentException( 'invalid_discount_points' );
			}
			$previous = -1;
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! in_array( $row['kind'] ?? '', array( 'percent', 'amount' ), true ) ) {
					throw new InvalidArgumentException( 'invalid_discount_point' );
				}
				$offset = self::integer( $row['offset_minutes'] ?? -1, 0, self::MAX_MINUTES - 1 );
				if ( $offset <= $previous || ( -1 === $previous && 0 !== $offset ) ) {
					throw new InvalidArgumentException( 'discount_points_must_start_at_zero_and_increase' );
				}
				$value = self::amount( $row['value'] ?? '', 'percent' === $row['kind'] ? 2 : $precision );
				if ( 'percent' === $row['kind'] && Money::parse( $value, 2 ) > 10000 ) {
					throw new InvalidArgumentException( 'discount_percent_out_of_range' );
				}
				$points[] = array(
					'offset_minutes' => $offset,
					'kind'           => $row['kind'],
					'value'          => $value,
				);
				$previous = $offset;
			}
			$steps    = count( $points );
			$interval = 'custom';
			$duration = self::integer( $input['duration_minutes'] ?? $previous + 60, $previous + 1, self::MAX_MINUTES );
		}
		if ( $duration > self::MAX_MINUTES ) {
			throw new InvalidArgumentException( 'discount_plan_duration_exceeds_366_days' );
		}
		return array(
			'mode'             => $mode,
			'interval'         => $interval,
			'steps'            => $steps,
			'interval_minutes' => $minutes,
			'initial_discount' => $initial,
			'final_discount'   => $final,
			'reach_floor'      => $reach,
			'points'           => $points,
			'duration_minutes' => $duration,
		);
	}

	/**
	 * Generate exact prices from one immutable base and its safety floor.
	 *
	 * @param string $base Captured base price in store units.
	 * @param string $floor Per-product safety floor, zero allowed for new plans.
	 * @param array  $campaign Campaign and its optional discount plan.
	 * @return string[] Stage prices, without writing WooCommerce prices.
	 * @throws InvalidArgumentException When prices or plan fields are invalid.
	 */
	public static function schedule( string $base, string $floor, array $campaign ): array {
		$precision = self::precision( $campaign );
		if ( ! isset( $campaign['settings']['discount_plan'] ) ) {
			return ( new PricingEngine() )->schedule( $base, $floor, (int) $campaign['days'], $campaign['strategy'], $precision, (int) $campaign['power'], $campaign['rounding'] );
		}
		$plan        = self::plan( $campaign );
		$base_minor  = Money::parse( $base, $precision );
		$floor_minor = Money::parse( $floor, $precision );
		$rounding    = $campaign['rounding'] ?? 'half_up';
		if ( $base_minor <= 0 || $floor_minor < 0 || $floor_minor > $base_minor
			|| ! in_array( $rounding, array( 'half_up', 'floor', 'ceil' ), true ) ) {
			throw new InvalidArgumentException( 'invalid_discount_base_floor_or_rounding' );
		}
		$initial = Money::parse( $plan['initial_discount'], 'fixed_amount' === $plan['mode'] ? $precision : 2 );
		$final   = Money::parse( $plan['final_discount'], 2 );
		$start   = max( $floor_minor, self::subtract_ratio( $base_minor, IntegerMath::multiply( (string) $base_minor, (string) ( 'fixed_amount' === $plan['mode'] ? 0 : $initial ) ), '10000', $rounding ) );
		$result  = array();
		for ( $step = 0; $step < $plan['steps']; ++$step ) {
			if ( 'fixed_amount' === $plan['mode'] ) {
				$price = max( 0, $base_minor - $initial );
			} elseif ( 'linear' === $plan['mode'] ) {
				$price = 1 === $plan['steps'] ? $floor_minor : self::subtract_ratio( $start, IntegerMath::multiply( (string) ( $start - $floor_minor ), (string) $step ), (string) ( $plan['steps'] - 1 ), $rounding );
			} elseif ( 'stepped' === $plan['mode'] ) {
				$divisor = max( 1, $plan['steps'] - 1 );
				$percent = $initial * $divisor + ( $final - $initial ) * $step;
				$price   = self::subtract_ratio( $base_minor, IntegerMath::multiply( (string) $base_minor, (string) $percent ), (string) ( 10000 * $divisor ), $rounding );
			} elseif ( 'custom' === $plan['mode'] ) {
				$point = $plan['points'][ $step ];
				$value = Money::parse( $point['value'], 'percent' === $point['kind'] ? 2 : $precision );
				$price = 'amount' === $point['kind'] ? max( 0, $base_minor - $value ) : self::subtract_ratio( $base_minor, IntegerMath::multiply( (string) $base_minor, (string) $value ), '10000', $rounding );
			} else {
				$price = $start;
			}
			if ( $plan['reach_floor'] && $step === $plan['steps'] - 1 ) {
				$price = $floor_minor;
			}
			$result[] = Money::format( max( $floor_minor, min( $base_minor, $price ) ), $precision );
		}
		return $result;
	}

	/**
	 * Return UTC stage starts; old campaigns keep their calendar-day behavior.
	 *
	 * @param array $campaign Campaign with canonical UTC start and timezone.
	 * @return string[] Canonical UTC starts.
	 */
	public static function boundaries( array $campaign ): array {
		if ( ! isset( $campaign['settings']['discount_plan'] ) ) {
			return Timeline::boundaries( $campaign['start_utc'], (int) $campaign['days'], $campaign['timezone'] );
		}
		$plan = self::plan( $campaign );
		if ( 'daily' === $plan['interval'] ) {
			return Timeline::boundaries( $campaign['start_utc'], $plan['steps'], $campaign['timezone'] );
		}
		// Validate the same IANA timezone and canonical start as legacy campaigns.
		Timeline::boundaries( $campaign['start_utc'], 1, $campaign['timezone'] );
		$start  = Timeline::parse_utc( $campaign['start_utc'] );
		$result = array();
		for ( $step = 0; $step < $plan['steps']; ++$step ) {
			$minutes  = 'custom' === $plan['mode'] ? $plan['points'][ $step ]['offset_minutes'] : $step * $plan['interval_minutes'];
			$result[] = $start->add( new DateInterval( 'PT' . $minutes . 'M' ) )->format( 'Y-m-d H:i:s' );
		}
		return $result;
	}

	/**
	 * Compute the end after the final stage's configured interval.
	 *
	 * @param array $campaign Campaign record.
	 * @return string Canonical UTC end.
	 */
	public static function end( array $campaign ): string {
		if ( ! isset( $campaign['settings']['discount_plan'] ) ) {
			return Timeline::end( $campaign['start_utc'], (int) $campaign['days'], $campaign['timezone'] );
		}
		$plan = self::plan( $campaign );
		if ( 'daily' === $plan['interval'] ) {
			return Timeline::end( $campaign['start_utc'], $plan['steps'], $campaign['timezone'] );
		}
		Timeline::boundaries( $campaign['start_utc'], 1, $campaign['timezone'] );
		return Timeline::parse_utc( $campaign['start_utc'] )->add( new DateInterval( 'PT' . $plan['duration_minutes'] . 'M' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Select the current stage directly, catching up after delayed execution.
	 *
	 * @param array $campaign Campaign record.
	 * @param int   $now Trusted server Unix timestamp.
	 * @return int Minus one before start; stage count at or after the end.
	 * @throws InvalidArgumentException When a stored end precedes the last stage.
	 */
	public static function index( array $campaign, int $now ): int {
		if ( ! isset( $campaign['settings']['discount_plan'] ) ) {
			return Timeline::day_index( $campaign, $now );
		}
		$boundaries = self::boundaries( $campaign );
		$last       = count( $boundaries ) - 1;
		$end        = Timeline::parse_utc( $campaign['end_utc'] ?? self::end( $campaign ) )->getTimestamp();
		if ( $end <= Timeline::parse_utc( $boundaries[ $last ] )->getTimestamp() ) {
			throw new InvalidArgumentException( 'discount_end_must_follow_final_stage' );
		}
		if ( $now >= $end ) {
			return count( $boundaries );
		}
		for ( $step = $last; $step >= 0; --$step ) {
			if ( $now >= Timeline::parse_utc( $boundaries[ $step ] )->getTimestamp() ) {
				return $step;
			}
		}
		return -1;
	}

	/**
	 * Read a plan using its campaign's pinned precision.
	 *
	 * @param array $campaign Campaign record.
	 * @return array Canonical plan.
	 * @throws InvalidArgumentException When the plan has an invalid shape.
	 */
	private static function plan( array $campaign ): array {
		$input = $campaign['settings']['discount_plan'];
		if ( ! is_array( $input ) ) {
			throw new InvalidArgumentException( 'invalid_discount_plan' );
		}
		return self::normalize( $input, self::precision( $campaign ) );
	}

	/**
	 * Read store precision without coupling the domain to WooCommerce.
	 *
	 * @param array $campaign Campaign record.
	 * @return int Supported precision.
	 */
	private static function precision( array $campaign ): int {
		return self::integer( $campaign['settings']['precision'] ?? $campaign['precision'] ?? 2, 0, 8 );
	}

	/**
	 * Validate integer fields without accepting floats or coercing exponents.
	 *
	 * @param mixed $value Submitted integer or decimal integer string.
	 * @param int   $minimum Inclusive lower bound.
	 * @param int   $maximum Inclusive upper bound.
	 * @return int Validated integer.
	 * @throws InvalidArgumentException When the value is malformed or outside bounds.
	 */
	private static function integer( mixed $value, int $minimum, int $maximum ): int {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/\A[0-9]+\z/D', (string) $value )
			|| strlen( (string) $value ) > 9 || (int) $value < $minimum || (int) $value > $maximum ) {
			throw new InvalidArgumentException( 'invalid_discount_plan_integer' );
		}
		return (int) $value;
	}

	/**
	 * Validate an unsigned decimal string and retain canonical precision.
	 *
	 * @param mixed $value Submitted decimal amount.
	 * @param int   $precision Decimal precision.
	 * @return string Canonical amount.
	 * @throws InvalidArgumentException When input is signed, nonnumeric or negative.
	 */
	private static function amount( mixed $value, int $precision ): string {
		if ( ( ! is_string( $value ) && ! is_int( $value ) ) || ! preg_match( '/\A[0-9]+(?:\.[0-9]+)?\z/D', (string) $value ) ) {
			throw new InvalidArgumentException( 'invalid_discount_amount' );
		}
		return Money::format( Money::parse( (string) $value, $precision ), $precision );
	}

	/**
	 * Round the resulting price, not a prematurely rounded discount amount.
	 *
	 * @param int    $base Starting price in minor currency units.
	 * @param string $numerator Exact reduction numerator.
	 * @param string $denominator Exact positive reduction denominator.
	 * @param string $rounding Validated final-price policy.
	 * @return int Rounded price.
	 */
	private static function subtract_ratio( int $base, string $numerator, string $denominator, string $rounding ): int {
		list( $reduction, $remainder ) = IntegerMath::divide( $numerator, $denominator );
		$price                         = $base - (int) $reduction;
		if ( '0' !== $remainder && ( 'floor' === $rounding || ( 'half_up' === $rounding && IntegerMath::compare( IntegerMath::multiply( $remainder, '2' ), $denominator ) > 0 ) ) ) {
			--$price;
		}
		return $price;
	}
}
