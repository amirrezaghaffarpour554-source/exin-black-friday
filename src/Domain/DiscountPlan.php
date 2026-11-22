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

	/** Maximum automatic starts, including an exact last-day boundary. */
	public const MAX_AUTO_STAGES = 105409;

	/**
	 * Bounded configuration cache; never product prices.
	 *
	 * @var array
	 */
	private static array $automatic_cache = array();

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
		$mode = $input['mode'] ?? 'fixed_percent';
		if ( self::automatic( array( 'settings' => array( 'discount_plan' => $input ) ) ) ) {
			return self::normalize_automatic( $input ); }
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
		if ( self::automatic( $campaign ) ) {
			$result = array();
			$count  = self::stage_count( $campaign );
			for ( $step = 0; $step < $count; ++$step ) {
				$result[] = self::automatic_price( $base, $floor, $campaign, $step );
			} return $result;
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
		if ( self::automatic( $campaign ) ) {
			$result = array();
			$count  = self::stage_count( $campaign );
			for ( $stage = 0; $stage < $count; ++$stage ) {
				$result[] = self::stage_start( $campaign, $stage );
			}
			return $result;
		}
		$plan = self::plan( $campaign );
		if ( 'daily' === $plan['interval'] ) {
			$result = Timeline::boundaries( $campaign['start_utc'], $plan['steps'], $campaign['timezone'] );
			return $result;
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
	 * Compute the independent automatic end or the existing manual plan end.
	 *
	 * @param array $campaign Campaign record.
	 * @return string Canonical UTC end.
	 */
	public static function end( array $campaign ): string {
		if ( ! isset( $campaign['settings']['discount_plan'] ) ) {
			return Timeline::end( $campaign['start_utc'], (int) $campaign['days'], $campaign['timezone'] );
		}
		$plan = self::plan( $campaign );
		if ( ! self::automatic( $campaign ) && 'daily' === $plan['interval'] ) {
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
		if ( self::automatic( $campaign ) ) {
			$context = self::automatic_context( $campaign );
			if ( $now < $context['start'] ) {
				return -1;
			}
			if ( $now >= $context['end'] ) {
				return $context['count'];
			}
			$index = min( $context['plan']['steps'] - 1, intdiv( $now - $context['start'], $context['seconds'] ) );
			return $index + ( null !== $context['extra'] && $now >= $context['start'] + $context['extra'] ? 1 : 0 );
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
	 * Count stages without materializing automatic boundaries.
	 *
	 * @param array $campaign Campaign.
	 * @return int Stage count, including an enabled last-day start.
	 */
	public static function stage_count( array $campaign ): int {
		return self::automatic( $campaign ) ? self::automatic_context( $campaign )['count'] : count( self::boundaries( $campaign ) );
	}

	/**
	 * Resolve one UTC start with constant memory for automatic plans.
	 *
	 * @param array $campaign Campaign.
	 * @param int   $stage Zero-based stage.
	 * @return string Canonical UTC start.
	 * @throws InvalidArgumentException When the stage is out of range.
	 */
	public static function stage_start( array $campaign, int $stage ): string {
		if ( ! self::automatic( $campaign ) ) {
			$boundaries = self::boundaries( $campaign );
			if ( ! isset( $boundaries[ $stage ] ) ) {
				throw new InvalidArgumentException( 'invalid_discount_stage' );
			}
			return $boundaries[ $stage ];
		}
		$c = self::automatic_context( $campaign );
		if ( $stage < 0 || $stage >= $c['count'] ) {
			throw new InvalidArgumentException( 'invalid_discount_stage' );
		}
		$offset = null !== $c['extra'] && $stage === $c['extra_index'] ? $c['extra'] : ( $stage - ( null !== $c['extra'] && $stage > $c['extra_index'] ? 1 : 0 ) ) * $c['seconds'];
		return gmdate( 'Y-m-d H:i:s', $c['start'] + $offset );
	}

	/**
	 * Identify explicitly opted-in automatic plans; legacy modes are untouched.
	 *
	 * @param array $campaign Campaign record.
	 * @return bool Whether an automatic mode was explicitly saved.
	 */
	public static function automatic( array $campaign ): bool {
		return in_array( $campaign['settings']['discount_plan']['mode'] ?? '', array( 'smart_adaptive', 'auto_progressive', 'auto_descending', 'auto_rotation', 'auto_random', 'auto_wave', 'auto_hybrid' ), true );
	}

	/**
	 * Validate automatic parameters within the existing stage plan.
	 *
	 * @param array $input Submitted plan.
	 * @return array Validated automatic plan.
	 * @throws InvalidArgumentException When a plan is unsafe.
	 */
	private static function normalize_automatic( array $input ): array {
		$mode     = $input['mode'];
		$interval = $input['interval'] ?? 'daily';
		if ( ! in_array( $interval, array( 'daily', 'hourly', 'flash' ), true ) ) {
			throw new InvalidArgumentException( 'invalid_automatic_interval' ); }
		$minutes = 'daily' === $interval ? 1440 : self::integer( $input['interval_minutes'] ?? ( 'hourly' === $interval ? 60 : 5 ), 'flash' === $interval ? 5 : 60, 1440 );
		if ( 'hourly' === $interval && 0 !== $minutes % 60 ) {
			throw new InvalidArgumentException( 'automatic_hour_interval_must_be_whole_hours' ); }
		// Previously saved automatic plans without a duration retain their span.
		$duration = $input['duration_minutes'] ?? ( isset( $input['duration_days'] ) ? self::integer( $input['duration_days'], 1, 366 ) * 1440 : ( isset( $input['steps'] ) ? self::integer( $input['steps'], 1, self::MAX_AUTO_STAGES ) * $minutes : 10080 ) );
		$duration = self::integer( $duration, 1, self::MAX_MINUTES );
		$steps    = intdiv( $duration + $minutes - 1, $minutes );
		$min      = self::amount( $input['intensity_min'] ?? '10', 2 );
		$max      = self::amount( $input['intensity_max'] ?? '100', 2 );
		$base_max = self::amount( $input['base_max'] ?? '40', 2 );
		if ( Money::parse( $min, 2 ) > Money::parse( $max, 2 ) || Money::parse( $max, 2 ) > 10000 || Money::parse( $base_max, 2 ) > 10000 ) {
			throw new InvalidArgumentException( 'invalid_automatic_intensity' ); }
		if ( 'auto_hybrid' === $mode && ( Money::parse( $base_max, 2 ) < Money::parse( $min, 2 ) || Money::parse( $base_max, 2 ) > Money::parse( $max, 2 ) ) ) {
			throw new InvalidArgumentException( 'invalid_automatic_base_intensity' ); }
		$curve     = $input['curve'] ?? 'linear';
		$selector  = $input['selector'] ?? 'priority';
		$last      = $input['last_day'] ?? 'disabled';
		$ending    = $input['descending_end'] ?? 'continue';
		$size_mode = $input['selection_mode'] ?? 'percent';
		if ( ! in_array( $curve, array( 'linear', 'stepped' ), true ) || ! in_array( $selector, array( 'priority', 'random' ), true ) || ! in_array( $last, array( 'disabled', 'continue', 'all_floor', 'remaining_floor' ), true ) || ! in_array( $ending, array( 'continue', 'regular', 'minimum' ), true ) || ! in_array( $size_mode, array( 'count', 'percent' ), true ) ) {
			throw new InvalidArgumentException( 'invalid_automatic_option' ); }
		if ( 'smart_adaptive' === $mode && ! isset( $input['seed'] ) ) {
			throw new InvalidArgumentException( 'adaptive_seed_required' ); }
		$seed = $input['seed'] ?? 'exin-auto-130';
		if ( ! is_string( $seed ) || ! preg_match( '/\A[A-Za-z0-9_-]{8,64}\z/D', $seed ) ) {
			throw new InvalidArgumentException( 'invalid_automatic_seed' ); }
		$result = array(
			'mode'             => $mode,
			'interval'         => $interval,
			'steps'            => $steps,
			'interval_minutes' => $minutes,
			'duration_minutes' => $duration,
			'initial_discount' => '0.00',
			'final_discount'   => '0.00',
			'reach_floor'      => false,
			'points'           => array(),
			'intensity_min'    => $min,
			'intensity_max'    => $max,
			'base_max'         => $base_max,
			'curve'            => $curve,
			'bands'            => self::integer( $input['bands'] ?? 3, 2, self::MAX_AUTO_STAGES ),
			'wave_period'      => self::integer( $input['wave_period'] ?? 4, 2, self::MAX_AUTO_STAGES ),
			'selector'         => $selector,
			'selection_mode'   => $size_mode,
			'selection_value'  => self::integer( $input['selection_value'] ?? 25, 1, 'percent' === $size_mode ? 100 : 50000 ),
			'seed'             => $seed,
			'last_day'         => $last,
			'descending_end'   => $ending,
		);
		if ( 'smart_adaptive' === $mode ) {
			if ( ! in_array( $last, array( 'disabled', 'continue' ), true ) ) {
				throw new InvalidArgumentException( 'adaptive_has_no_final_day_floor' ); }
			$result['last_day'] = 'disabled';
			$result['adaptive'] = AdaptivePolicy::normalize( $input['adaptive'] ?? array() );
		}
		return $result;
	}

	/**
	 * Make one exact decision relative to the regular-to-floor headroom.
	 *
	 * @param string   $base Immutable regular price.
	 * @param string   $floor Positive per-product floor.
	 * @param array    $campaign Explicit automatic campaign.
	 * @param int      $stage Actual stage index.
	 * @param int      $product_id Product seed identity.
	 * @param bool     $selected Whether the immutable stage cohort selected this item.
	 * @param bool     $remaining Whether no previous special turn was recorded.
	 * @param bool     $in_stock Current strict stock eligibility.
	 * @param int|null $adaptive_intensity Committed smart intensity, internal only.
	 * @return string One bounded price; discounts never stack.
	 * @throws InvalidArgumentException When the financial inputs are invalid.
	 */
	public static function automatic_price( string $base, string $floor, array $campaign, int $stage, int $product_id = 0, bool $selected = true, bool $remaining = true, bool $in_stock = true, ?int $adaptive_intensity = null ): string {
		$context   = self::automatic_context( $campaign );
		$p         = $context['plan'];
		$precision = self::precision( $campaign );
		$b         = Money::parse( $base, $precision );
		$f         = Money::parse( $floor, $precision );
		$steps     = $context['count'];
		if ( $b <= 0 || $f <= 0 || $f > $b || $stage < 0 || $stage >= $steps ) {
			throw new InvalidArgumentException( 'invalid_automatic_base_floor_or_stage' ); }
		if ( ! $in_stock ) {
			return Money::format( $b, $precision ); }
		$lo       = Money::parse( $p['intensity_min'], 2 );
		$hi       = Money::parse( $p['intensity_max'], 2 );
		$den      = max( 1, $steps - 1 );
		$position = $stage;
		if ( 'stepped' === $p['curve'] ) {
			$den      = max( 1, min( $p['bands'], $steps ) - 1 );
			$position = intdiv( $stage * $den, max( 1, $steps - 1 ) ); }
		$progress = $lo + intdiv( ( $hi - $lo ) * $position, $den );
		$random   = $lo + (int) ( hexdec( substr( hash( 'sha256', $p['seed'] . ':' . $stage . ':' . $product_id . ':intensity' ), 0, 8 ) ) % ( $hi - $lo + 1 ) );
		switch ( $p['mode'] ) {
			case 'smart_adaptive':
				$intensity = $adaptive_intensity ?? $p['adaptive']['initial_bps'];
				if ( $intensity < 0 || $intensity > $p['adaptive']['maximum_bps'] ) {
					throw new InvalidArgumentException( 'invalid_adaptive_intensity' ); }
				break;
			case 'auto_descending':
				$intensity = $hi - ( $progress - $lo );
				if ( $stage === $steps - 1 && 'continue' !== $p['descending_end'] ) {
					$intensity = 'regular' === $p['descending_end'] ? 0 : $lo;
				} break;
			case 'auto_rotation':
				$intensity = $selected ? $hi : 0;
				break;
			case 'auto_random':
				$intensity = $selected ? $random : 0;
				break;
			case 'auto_wave':
				$phase     = $stage % $p['wave_period'];
				$intensity = $lo + intdiv( ( $hi - $lo ) * min( $phase, $p['wave_period'] - $phase ), max( 1, intdiv( $p['wave_period'], 2 ) ) );
				break;
			case 'auto_hybrid':
				$cap            = max( $lo, Money::parse( $p['base_max'], 2 ) );
				$base_intensity = $lo + intdiv( ( $cap - $lo ) * $position, $den );
				$intensity      = $selected ? max( $base_intensity, 'random' === $p['selector'] ? $random : $hi ) : $base_intensity;
				break;
			default:
				$intensity = $progress;
		}
		$last_start = max( $context['start'], $context['end'] - 86400 );
		if ( Timeline::parse_utc( self::stage_start( $campaign, $stage ) )->getTimestamp() >= $last_start && ( 'all_floor' === $p['last_day'] || ( 'remaining_floor' === $p['last_day'] && $remaining ) ) ) {
			$intensity = 10000; }
		$price = self::subtract_ratio( $b, IntegerMath::multiply( (string) ( $b - $f ), (string) $intensity ), '10000', $campaign['rounding'] ?? 'half_up' );
		return Money::format( max( $f, min( $b, $price ) ), $precision );
	}


	/**
	 * Cache only immutable per-request plan context, never mutable stock or prices.
	 *
	 * @param array $campaign Campaign.
	 * @return array Validated plan and scalar timing context.
	 * @throws InvalidArgumentException When the stored end precedes a stage.
	 */
	private static function automatic_context( array $campaign ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure domain also runs outside WordPress.
		$key = hash( 'sha256', json_encode( array( $campaign['settings']['discount_plan'], $campaign['start_utc'], $campaign['end_utc'] ?? null, $campaign['timezone'], self::precision( $campaign ) ), JSON_THROW_ON_ERROR ) );
		if ( ! isset( self::$automatic_cache[ $key ] ) ) {
			if ( count( self::$automatic_cache ) >= 8 ) {
				self::$automatic_cache = array(); }
			$plan = self::plan( $campaign );
			Timeline::boundaries( $campaign['start_utc'], 1, $campaign['timezone'] );
			$start   = Timeline::parse_utc( $campaign['start_utc'] )->getTimestamp();
			$end     = isset( $campaign['end_utc'] ) ? Timeline::parse_utc( $campaign['end_utc'] )->getTimestamp() : $start + $plan['duration_minutes'] * 60;
			$seconds = $plan['interval_minutes'] * 60;
			if ( $end <= $start + ( $plan['steps'] - 1 ) * $seconds ) {
				throw new InvalidArgumentException( 'discount_end_must_follow_final_stage' );
			}
			$last                          = $end - 86400 - $start;
			$extra                         = in_array( $plan['last_day'], array( 'all_floor', 'remaining_floor' ), true ) && $last > 0 && 0 !== $last % $seconds ? $last : null;
			self::$automatic_cache[ $key ] = array(
				'plan'        => $plan,
				'start'       => $start,
				'end'         => $end,
				'seconds'     => $seconds,
				'extra'       => $extra,
				'extra_index' => null === $extra ? null : intdiv( $extra + $seconds - 1, $seconds ),
				'count'       => $plan['steps'] + ( null === $extra ? 0 : 1 ),
			);
		}
		return self::$automatic_cache[ $key ];
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
