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
	/** Read the saved plan at the current floor, never stale cached stage prices.
	 *
	 * @param array $item Item.
	 * @param array $campaign Campaign.
	 * @param int   $stage Current stage.
	 */
	public static function scheduled( array $item, array $campaign, int $stage ): string {
		$p = (int) $campaign['settings']['precision'];
		if ( ! self::positive( $item['floor_price'] ?? null, $p, $item['base_price'] ) ) {
			return self::ordinary( $item ); }
		static $schedules = array();
                $key = serialize( array( $item['base_price'], (string) $item['floor_price'], $p, $campaign['days'], $campaign['strategy'], $campaign['power'], $campaign['rounding'], $campaign['settings']['discount_plan'] ?? null, $campaign['start_utc'] ?? null, $campaign['end_utc'] ?? null, $campaign['timezone'] ?? null ) );
                if ( ! array_key_exists( $key, $schedules ) ) {
                        if ( count( $schedules ) >= 128 ) {
                                $schedules = array();
                        }
                        $schedules[$key] = DiscountPlan::schedule( $item['base_price'], (string) $item['floor_price'], $campaign );
                }
                $schedule = $schedules[$key];
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
			static $campaigns = array(), $items = array();
                        $pid = $product->get_id();
                        $cacheable = did_action( 'wp' )
                                && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' )
                                && empty( $_GET )
                                && ! is_admin()
                                && ! wp_doing_ajax()
                                && ! wp_doing_cron()
                                && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
                                && ! ( defined( 'WP_CLI' ) && WP_CLI )
                                && ! is_user_logged_in()
                                && ! is_cart()
                                && ! is_checkout()
                                && ! is_account_page()
                                && ( is_front_page() || is_shop() || is_product_category() || is_product_tag() || is_product() )
                                && WC()->cart
                                && WC()->cart->is_empty();
                        $key = $pid . ':' . time();
                        $r = new CampaignRepository();
			if ( $cacheable ) {
                                if ( ! array_key_exists( $key, $campaigns ) ) {
                                        $campaigns[$key] = $r->campaign_for_product( $pid );
                                }
                                $c = $campaigns[$key];
                        } else {
                                $c = $r->campaign_for_product( $pid );
                        }
			if ( ! $c || ! CampaignContext::is_live( $c ) || ! empty( $c['settings']['restoring'] ) ) {
				return $price; }
			if ( $cacheable ) {
                                $item_key = $c['id'] . ':' . $key;
                                if ( ! array_key_exists( $item_key, $items ) ) {
                                        $items[$item_key] = $r->item( $c['id'], $pid );
                                }
                                $i = $items[$item_key];
                        } else {
                                $i = $r->item( $c['id'], $pid );
                        }
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
