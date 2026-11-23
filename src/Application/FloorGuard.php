<?php
/** Shared fail-closed floor policy, without campaign data writes.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Application;

use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Integration\CampaignContext;

/** Current-stage price guard shared by workers and WooCommerce views. */
final class FloorGuard {
	/** Suppress our own price filters during raw ownership checks.
	 *
	 * @var int
	 */
	public static int $reading = 0;
	/** Validate stored floor data without coercing invalid types.
	 *
	 * @param mixed       $floor Stored floor.
	 * @param int         $precision Currency precision.
	 * @param string|null $base Optional immutable base bound.
	 */
	public static function positive( mixed $floor, int $precision, ?string $base = null ): bool {
		if ( ! is_string( $floor ) && ! is_int( $floor ) ) {
			return false; }
		try {
			return Money::parse( (string) $floor, $precision ) > 0 && ( null === $base || Money::parse( (string) $floor, $precision ) <= Money::parse( $base, $precision ) );
		} catch ( \Throwable $error ) {
			return false; }
	}
	/** Ordinary snapshot sale, only while its original date window is valid.
	 *
	 * @param array    $item Item snapshot.
	 * @param int|null $now Timestamp.
	 */
	public static function ordinary( array $item, ?int $now = null ): string {
		$now = $now ?? time();
		$s   = $item['snapshot'];
		return '' !== ( $s['sale'] ?? '' ) && ( null === ( $s['from'] ?? null ) || $s['from'] <= $now ) && ( null === ( $s['to'] ?? null ) || $s['to'] > $now ) ? $s['sale'] : $s['regular'];
	}
	/** Recompute custom stages from the saved plan, never from stale stage prices.
	 *
	 * @param array $item Item.
	 * @param array $campaign Campaign.
	 * @param int   $stage Current stage.
	 */
	public static function scheduled( array $item, array $campaign, int $stage ): string {
		$p = (int) $campaign['settings']['precision'];
		if ( ! self::positive( $item['floor_price'] ?? null, $p, $item['base_price'] ) ) {
			return self::ordinary( $item ); }
		$schedule = ( $campaign['settings']['discount_plan']['mode'] ?? '' ) === 'custom' ? DiscountPlan::schedule( $item['base_price'], (string) $item['floor_price'], $campaign ) : $item['schedule'];
		$price    = Money::parse( $schedule[ $stage ] ?? '', $p );
		return Money::format( max( Money::parse( (string) $item['floor_price'], $p ), min( Money::parse( $item['base_price'], $p ), $price ) ), $p );
	}
	/** Read-only current-stage view, including stale carts and individual variations.
	 *
	 * @param mixed       $price Woo price.
	 * @param \WC_Product $product Product.
	 * @param bool|null   $applied Whether this filter owned the value.
	 */
	public static function filter( $price, $product, ?bool &$applied = null ) {
		if ( self::$reading || ! $product instanceof \WC_Product || ! $product->get_id() ) {
			return $price; }
		$applied = false;
		++self::$reading;
		$owned = false;
		try {
			$r = new CampaignRepository();
			$c = $r->campaign_for_product( $product->get_id() );
			if ( ! $c || ! CampaignContext::is_live( $c ) || ! empty( $c['settings']['restoring'] ) ) {
				return $price; }
			$i = $r->item( $c['id'], $product->get_id() );
			if ( ! CampaignContext::eligible_item( $i ) || ( $i['snapshot']['currency'] ?? '' ) !== get_woocommerce_currency() || (int) ( $i['snapshot']['precision'] ?? -1 ) !== wc_get_price_decimals() || ! ( PriceState::same( PriceState::capture( $product ), $i['expected'] ? $i['expected'] : $i['snapshot'] ) || ( ! empty( $i['intent']['target'] ) && PriceState::same( PriceState::capture( $product ), $i['intent']['target'] ) ) ) || PriceState::dynamic( $product ) ) {
				return $price; }
			$owned   = true;
			$applied = true;
			if ( ! self::positive( $i['floor_price'] ?? null, wc_get_price_decimals(), $i['base_price'] ) ) {
				return self::ordinary( $i ); }
			if ( 'active' === $c['status'] && 'paused' !== $i['status'] && 'custom' === ( $c['settings']['discount_plan']['mode'] ?? '' ) ) {
				return self::scheduled( $i, $c, DiscountPlan::index( $c, time() ) ); }
			return Money::format( max( Money::parse( (string) $i['floor_price'], wc_get_price_decimals() ), Money::parse( (string) $price, wc_get_price_decimals() ) ), wc_get_price_decimals() );
		} catch ( \Throwable $error ) {
			if ( $owned ) {
				return self::positive( $i['floor_price'] ?? null, wc_get_price_decimals(), $i['base_price'] ) ? Money::format( max( Money::parse( self::ordinary( $i ), wc_get_price_decimals() ), Money::parse( (string) $i['floor_price'], wc_get_price_decimals() ) ), wc_get_price_decimals() ) : self::ordinary( $i );
			} return $price;
		} finally {
			--self::$reading; }
	}
	/** Guard cached variable price arrays as well as freshly read variations.
	 *
	 * @param array       $prices Cached prices.
	 * @param \WC_Product $product Parent.
	 * @param bool        $display Tax display values.
	 */
	public static function variations( array $prices, $product, bool $display ): array {
		foreach ( $prices['price'] ?? array() as $id => $price ) {
			$v = wc_get_product( $id );
			if ( ! $v ) {
				continue; }
			$guarded = self::filter( $v->get_price( 'edit' ), $v, $applied );
			if ( ! $applied ) {
				continue; }
			$prices['price'][ $id ]      = $display ? wc_get_price_to_display( $v, array( 'price' => $guarded ) ) : $guarded;
			$prices['sale_price'][ $id ] = $prices['price'][ $id ];
		}
		return $prices;
	}
}
