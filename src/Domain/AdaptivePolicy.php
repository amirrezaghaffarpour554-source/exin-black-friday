<?php
/** Explainable adaptive intensity rules; monetary conversion stays in DiscountPlan.
 *
 * @package ExinBlackFriday
 */

declare(strict_types=1);
namespace Exin\BlackFriday\Domain;

/** Pure bounded scoring with no storage, scheduler or product writes. */
final class AdaptivePolicy {
	/** Normalize bounded manager settings without modifying existing profiles.
	 *
	 * @param array $input Manager parameters.
	 * @return array Validated parameters.
	 * @throws \InvalidArgumentException When parameters exceed safe bounds.
	 */
	public static function normalize( array $input ): array {
		$preset  = $input['preset'] ?? 'balanced';
		$presets = array(
			'conservative' => array( 500, 6000, 720 ),
			'balanced'     => array( 1000, 8000, 360 ),
			'aggressive'   => array( 1500, 10000, 180 ),
		);
		if ( ! is_string( $preset ) || ! isset( $presets[ $preset ] ) ) {
			throw new \InvalidArgumentException( 'invalid_adaptive_preset' );
		}
		[$change, $maximum, $cooldown] = $presets[ $preset ];
		$bounds                        = array(
			'expected_units_day' => array( 5, 1, 10000 ),
			'window_hours'       => array( 24, 1, 168 ),
			'cooldown_minutes'   => array( $cooldown, 30, 1440 ),
			'max_change_bps'     => array( $change, 100, 2500 ),
			'hysteresis_bps'     => array( 1500, 500, 5000 ),
			'min_orders'         => array( 3, 1, 50 ),
			'cold_start_minutes' => array( 180, 30, 1440 ),
			'initial_bps'        => array( 1000, 0, 3000 ),
			'maximum_bps'        => array( $maximum, 1000, 10000 ),
			'exploration_bps'    => array( 100, 0, 500 ),
		);
		$result                        = array( 'preset' => $preset );
		foreach ( $bounds as$key => [$default, $min, $max] ) {
			$v = $input[ $key ] ?? $default;
			if ( ( ! is_string( $v ) && ! is_int( $v ) ) || ! preg_match( '/\A[0-9]{1,8}\z/D', (string) $v ) || (int) $v < $min || (int) $v > $max ) {
				throw new \InvalidArgumentException( 'invalid_adaptive_parameter' );
			}
			$result[ $key ] = (int) $v;
		}
		if ( $result['initial_bps'] > $result['maximum_bps'] ) {
			throw new \InvalidArgumentException( 'invalid_adaptive_parameter' );
		}
		return $result;
	}
	/** Decide increases, decreases or holds.
	 *
	 * @param array      $p Normalized parameters.
	 * @param array      $metrics Trusted bounded observations.
	 * @param array|null $previous Last committed decision.
	 * @param int        $now Server timestamp.
	 * @param int        $start Campaign start.
	 * @param int        $end Campaign end.
	 * @param int        $jitter Bounded seeded exploration.
	 * @return array Explained intensity decision.
	 * @throws \DomainException When the campaign has expired.
	 */
	public static function decide( array $p, array $metrics, ?array $previous, int $now, int $start, int $end, int $jitter ): array {
		foreach ( array( 'units', 'orders', 'stock' ) as $key ) {
			if ( ! isset( $metrics[ $key ] ) || ! is_int( $metrics[ $key ] ) || $metrics[ $key ] < ( 'stock' === $key ? -1 : 0 ) || $metrics[ $key ] > 1000000 ) {
				throw new \DomainException( 'invalid_adaptive_observation' ); }
		}
		if ( $previous && ( ! isset( $previous['intensity_bps'], $previous['changed_at'] ) || ! is_int( $previous['intensity_bps'] ) || $previous['intensity_bps'] < 0 || $previous['intensity_bps'] > $p['maximum_bps'] || $previous['changed_at'] > $now ) ) {
			throw new \DomainException( 'invalid_adaptive_history' ); }
		$old     = $previous['intensity_bps'] ?? $p['initial_bps'];
		$changed = $previous['changed_at'] ?? $now;
		$result  = array(
			'intensity_bps' => $old,
			'changed_at'    => $changed,
			'reason'        => 'hold',
			'direction'     => $previous['direction'] ?? 0,
			'metrics'       => $metrics,
		);
		if ( $now < $start || $now >= $end ) {
			throw new \DomainException( 'adaptive_expired' );
		}
		if ( $previous && $now - $changed < $p['cooldown_minutes'] * 60 ) {
			$result['reason'] = 'cooldown';
			return $result;}
		if ( $now - $start < $p['cold_start_minutes'] * 60 || $metrics['orders'] < $p['min_orders'] ) {
			$result['reason'] = 'cold_start';
			return $result;}
		$seconds                                 = max( 60, min( $p['window_hours'] * 3600, $now - $start ) );
		$expected                                = max( 1, intdiv( $p['expected_units_day'] * $seconds * 1000, 86400 ) );
		$performance                             = min( 100000, intdiv( $metrics['units'] * 10000000, $expected ) );
		$stock                                   = $metrics['stock'];
		$sellthrough                             = $stock < 0 ? 0 : intdiv( $metrics['units'] * 10000, max( 1, $metrics['units'] + $stock ) );
		$result['metrics']['performance_bps']    = $performance;
		$result['metrics']['sellthrough_bps']    = $sellthrough;
		$result['metrics']['expected_units_day'] = $p['expected_units_day'];
		$delta                                   = 0;
		$reason                                  = 'hold';
		if ( $stock >= 0 && ( $performance > 10000 + $p['hysteresis_bps'] || $sellthrough >= 7500 || $stock <= $p['expected_units_day'] ) ) {
			$delta  = -$p['max_change_bps'];
			$reason = 'strong_demand_or_limited_stock';
		} elseif ( $stock > $p['expected_units_day'] && $performance < 10000 - $p['hysteresis_bps'] ) {
			$pressure = $stock > $p['expected_units_day'] * max( 1, (int) ceil( ( $end - $now ) / 86400 ) ) * 2;
			$delta    = $pressure ? $p['max_change_bps'] : intdiv( $p['max_change_bps'], 2 );
			$reason   = 'weak_sales_excess_stock';}
			// Observe a prior increase: if measured velocity did not improve, do not escalate again.
		if ( $delta > 0 && $previous && ( $previous['direction'] ?? 0 ) > 0 && ( $previous['metrics']['units'] ?? 0 ) > 0 ) {
			$prior_seconds = max( 60, (int) ( $previous['metrics']['seconds'] ?? $seconds ) );
			if ( $metrics['units'] * $prior_seconds <= ( $previous['metrics']['units'] ?? 0 ) * $seconds ) {
				$delta  = 0;
				$reason = 'no_observed_gain';}
		}
			// Urgency is a capped stock-pressure input, never a final-day floor rule.
			$days_left = max( 1, (int) ceil( ( $end - $now ) / 86400 ) );
		if ( $delta > 0 && $stock > $p['expected_units_day'] * $days_left * 2 ) {
			$reason = 'weak_sales_stock_time_pressure';
		}
			$noise = max( -$p['exploration_bps'], min( $p['exploration_bps'], $jitter ) );
		if ( 0 === $delta && 'hold' === $reason && $stock > $p['expected_units_day'] ) {
			$delta  = $noise;
			$reason = 0 === $delta ? 'hold' : 'bounded_exploration';
		} elseif ( 0 !== $delta ) {
			$delta += max( -intdiv( $p['max_change_bps'], 4 ), min( intdiv( $p['max_change_bps'], 4 ), $noise ) );
		}
			$next                         = max( 0, min( $p['maximum_bps'], $old + max( -$p['max_change_bps'], min( $p['max_change_bps'], $delta ) ) ) );
			$result['intensity_bps']      = $next;
			$result['direction']          = $next === $old ? ( $previous['direction'] ?? 0 ) : ( $next <=> $old );
			$result['reason']             = $reason;
			$result['metrics']['seconds'] = $seconds;
		if ( $next !== $old ) {
			$result['changed_at'] = $now;
		}
			return $result;
	}
}
