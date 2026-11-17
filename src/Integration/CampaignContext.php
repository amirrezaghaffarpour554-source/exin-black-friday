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

	/**
	 * Enforce the campaign's strict available-and-purchasable listing policy.
	 *
	 * Backorder configuration is never changed; products with exhausted managed
	 * stock stay purchasable elsewhere in WooCommerce but leave campaign lists.
	 *
	 * @param \WC_Product $product Selected simple product or variation.
	 * @return bool Whether the item may appear in a campaign product list.
	 */
	public static function purchasable_product( \WC_Product $product ): bool {
		if ( ! $product->is_type( array( 'simple', 'variation' ) ) || 'publish' !== $product->get_status() || ! $product->is_purchasable() || ! $product->is_in_stock() || 'instock' !== $product->get_stock_status() ) {
			return false;
		}
		$owner = $product;
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent || 'publish' !== $parent->get_status() || ! $parent->is_visible() ) {
				return false;
			}
			$owner = $product->get_stock_managed_by_id() === $parent->get_id() ? $parent : $product;
		} elseif ( ! $product->is_visible() ) {
			return false;
		}
		return ! $owner->managing_stock() || ( null !== $owner->get_stock_quantity() && $owner->get_stock_quantity() > 0 );
	}
}
