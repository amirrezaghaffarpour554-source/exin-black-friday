<?php
/**
 * Shared, server-authoritative campaign visibility policy.
 *
 * @package Exin\BlackFriday
 */

namespace Exin\BlackFriday\Integration;

/**
 * Shared, server-authoritative campaign visibility policy.
 */
final class CampaignContext {
	/**
	 * Check the server-authoritative campaign date window and lifecycle.
	 *
	 * @param array|null $campaign Campaign configuration loaded from the repository.
	 * @param int|null   $now Optional Unix timestamp for deterministic date checks.
	 * @return bool Whether the condition holds.
	 */
	public static function is_live( ?array $campaign, ?int $now = null ): bool {
		if ( ! $campaign || ! in_array( $campaign['status'] ?? '', array( 'active', 'paused' ), true ) ) {
			return false;
		}
		$now   = $now ?? time();
		$start = strtotime( ( $campaign['start_utc'] ?? '' ) . ' UTC' );
		$end   = strtotime( ( $campaign['end_utc'] ?? '' ) . ' UTC' );
		return false !== $start && false !== $end && $now >= $start && $now < $end;
	}

	/**
	 * Check whether an item remains eligible for campaign presentation.
	 *
	 * @param array|null $item Campaign item record or order line item.
	 * @return bool Whether the condition holds.
	 */
	public static function eligible_item( ?array $item ): bool {
		return $item && in_array( $item['status'] ?? '', array( 'included', 'pending', 'active', 'sold_out', 'paused' ), true );
	}
}
