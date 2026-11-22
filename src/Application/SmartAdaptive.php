<?php
/** Adaptive metadata only; all price writes remain in PriceProcessor.
 *
 * @package ExinBlackFriday
 */

declare(strict_types=1);

namespace Exin\BlackFriday\Application;

use Exin\BlackFriday\Domain\AdaptivePolicy;
use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\LockManager;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Indexed plugin-owned checkpoints must be read fresh under campaign locks.

/** Metadata extension to the existing price processor and Woo order hooks. */
final class SmartAdaptive {
	/** Receive existing durable storage.
	 *
	 * @param CampaignRepository $repo Campaign repository.
	 */
	public function __construct( private CampaignRepository $repo ) {}
	/** Check explicit strategy selection.
	 *
	 * @param array $campaign Stored campaign.
	 * @return bool Whether smart pricing was explicitly selected.
	 */
	public static function enabled( array $campaign ): bool {
		return ( $campaign['settings']['discount_plan']['mode'] ?? '' ) === 'smart_adaptive';}

	/**
	 * Freeze one decision under PriceProcessor's existing campaign/product locks.
	 *
	 * @param array       $campaign Campaign.
	 * @param array       $item Captured enrollment.
	 * @param \WC_Product $product Current Woo product.
	 * @param int         $stage Current stage.
	 * @param bool        $in_stock Strict stock eligibility.
	 * @return string Price.
	 * @throws \RuntimeException When a checkpoint cannot be safely persisted.
	 */
	public function price( array $campaign, array $item, \WC_Product $product, int $stage, bool $in_stock ): string {
		global $wpdb;
		DiscountPlan::automatic_price( $item['base_price'], $item['floor_price'], $campaign, $stage, $item['product_id'], true, true, false );
		if ( ! $in_stock ) {
			return DiscountPlan::automatic_price( $item['base_price'], $item['floor_price'], $campaign, $stage, $item['product_id'], true, true, false );
		}
		$table = $wpdb->prefix . 'exin_bf_adaptive_decisions';
		$saved = $wpdb->get_var( $wpdb->prepare( 'SELECT decision FROM %i WHERE campaign_id=%d AND version=%d AND product_id=%d AND stage=%d', $table, $campaign['id'], $campaign['version'], $item['product_id'], $stage ) );
		if ( null !== $saved ) {
			$d = json_decode( $saved, true, 512, JSON_THROW_ON_ERROR );
			return DiscountPlan::automatic_price( $item['base_price'], $item['floor_price'], $campaign, $stage, $item['product_id'], true, true, true, $d['intensity_bps'] );}
		$worker   = ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || doing_action( 'exin_bf_batch' ) || doing_action( 'exin_bf_retry' );
		$p        = $campaign['settings']['discount_plan']['adaptive'];
		$now      = time();
		$metrics  = ( new AdaptiveAnalytics( $this->repo ) )->snapshot( $campaign, $item['product_id'] );
		$fresh    = $worker && null !== $metrics;
		$metrics  = $metrics ?? array(
			'units'  => 0,
			'orders' => 0,
			'as_of'  => 0,
		);
		$owner    = wc_get_product( $product->get_stock_managed_by_id() );
		$metrics  = array(
			'units'  => min( 1000000, (int) $metrics['units'] ),
			'orders' => min( 1000000, (int) $metrics['orders'] ),
			'stock'  => $owner && $owner->managing_stock() ? max( 0, min( 1000000, (int) $owner->get_stock_quantity() ) ) : -1,
			'as_of'  => $metrics['as_of'],
		);
		$previous = $wpdb->get_var( $wpdb->prepare( 'SELECT decision FROM %i WHERE campaign_id=%d AND version=%d AND product_id=%d AND stage<%d ORDER BY stage DESC LIMIT 1', $table, $campaign['id'], $campaign['version'], $item['product_id'], $stage ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'adaptive_history_read_failed' );
		}
		$previous = null === $previous ? null : json_decode( $previous, true, 512, JSON_THROW_ON_ERROR );
		if ( ! $fresh ) {
			if ( $previous ) {
				return DiscountPlan::automatic_price( $item['base_price'], $item['floor_price'], $campaign, $stage, $item['product_id'], true, true, true, $previous['intensity_bps'] ); }
			$held      = $item['expected']['price'] ?? $item['snapshot']['price'] ?? $item['base_price'];
			$precision = $campaign['settings']['precision'];
			$minor     = \Exin\BlackFriday\Domain\Money::parse( $held, $precision );
			return $minor >= \Exin\BlackFriday\Domain\Money::parse( $item['floor_price'], $precision ) && $minor <= \Exin\BlackFriday\Domain\Money::parse( $item['base_price'], $precision ) ? $held : $item['base_price'];
		}

		// Existing private per-campaign seed; never returned to the public or preview.
		$span            = $p['exploration_bps'];
		$hash            = hash_hmac( 'sha256', $item['product_id'] . ':' . $stage, $campaign['settings']['discount_plan']['seed'] );
		$noise           = (int) ( hexdec( substr( $hash, 0, 8 ) ) % ( 2 * $span + 1 ) ) - $span;
		$d               = $fresh ? AdaptivePolicy::decide( $p, $metrics, $previous, $now, strtotime( $campaign['start_utc'] . ' UTC' ), strtotime( $campaign['end_utc'] . ' UTC' ), $noise ) : array(
			'intensity_bps' => $previous['intensity_bps'] ?? $p['initial_bps'],
			'changed_at'    => $previous['changed_at'] ?? $now,
			'reason'        => 'metrics_refresh_pending',
			'metrics'       => array( 'as_of' => 0 ),
			'direction'     => $previous['direction'] ?? 0,
		);
		$price           = DiscountPlan::automatic_price( $item['base_price'], $item['floor_price'], $campaign, $stage, $item['product_id'], true, true, true, $d['intensity_bps'] );
		$d['price']      = $price;
		$d['stage']      = $stage;
		$d['decided_at'] = $now;
		( new LockManager() )->assert_held( 'campaign:' . $campaign['id'] );
		( new LockManager() )->assert_held( 'product:' . $item['product_id'] );
		if ( false === $wpdb->insert(
			$table,
			array(
				'campaign_id' => $campaign['id'],
				'version'     => $campaign['version'],
				'product_id'  => $item['product_id'],
				'stage'       => $stage,
				'decision'    => wp_json_encode( $d ),
				'created_at'  => gmdate( 'Y-m-d H:i:s', $now ),
			)
		) ) {
			throw new \RuntimeException( 'adaptive_decision_write_failed' );
		}
		return $price;
	}


	/** Queue a durable latest-order event.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Queued.
	 */
	public function observe( \WC_Order $order ): bool {
		( new AdaptiveAnalytics( $this->repo ) )->enqueue( $order->get_id(), $order );
		return true; }

	/**
	 * Incremental order checkpoints via Woo CRUD: status/refund changes replace rows.
	 *
	 * @param \WC_Order $order Current order.
	 * @param ?int      $only_campaign Optional locked campaign scope.
	 * @return bool Completion state.
	 * @throws \RuntimeException When a checkpoint read fails.
	 * @throws \Throwable When the campaign lock must be retried.
	 */
	public function sync_order( \WC_Order $order, ?int $only_campaign = null ): bool {
		global $wpdb;
		$ok      = true;
		$groups  = array();
		$old_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT campaign_id FROM %i WHERE order_id=%d', $wpdb->prefix . 'exin_bf_adaptive_sales', $order->get_id() ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'adaptive_event_read_failed' ); }
		foreach ( $old_ids as $old_id ) {
			$old = $this->repo->get( (int) $old_id );
			if ( ( null === $only_campaign || (int) $old_id === $only_campaign ) && $old && self::enabled( $old ) ) {
				$groups[ (int) $old_id ] = array( 'campaign' => $old );
			}
		}
		foreach ( $order->get_items( 'line_item' )as$line_id => $line ) {
			$cid = (int) $line->get_meta( '_exin_bf_campaign_id', true );
			if ( ! $cid || ( null !== $only_campaign && $cid !== $only_campaign ) ) {
				continue;
			}
			$c = $this->repo->get( $cid );
			if ( ! $c || ! self::enabled( $c ) ) {
				continue;
			}
			$groups[ $cid ]['campaign'] = $c;
			$pid                        = (int) $line->get_meta( '_exin_bf_product_id', true );
			if ( ( $line->get_variation_id() ? $line->get_variation_id() : $line->get_product_id() ) !== $pid ) {
				continue;
			}
			$date       = $order->get_date_created();
			$statuses   = array_map( static fn( $status ) => preg_replace( '/^wc-/', '', (string) $status ), (array) ( $c['settings']['order_statuses'] ?? array( 'processing', 'completed' ) ) );
			$registered = array_map( static fn( $status ) => preg_replace( '/^wc-/', '', $status ), array_keys( wc_get_order_statuses() ) );
			$statuses   = array_values( array_diff( array_intersect( $statuses, $registered ), array( 'pending', 'failed', 'cancelled', 'checkout-draft', 'refunded' ) ) );
			if ( ! $statuses ) {
				$statuses = array( 'processing', 'completed' ); }
			if ( ! $date || ! in_array( $order->get_status(), $statuses, true ) || $order->get_currency() !== ( $c['settings']['currency'] ?? '' ) || $date->getTimestamp() < strtotime( $c['start_utc'] . ' UTC' ) ) {
				continue;
			}
			if ( \Exin\BlackFriday\Domain\Money::parse( (string) $order->get_total(), wc_get_price_decimals() ) <= 0 || \Exin\BlackFriday\Domain\Money::parse( (string) $order->get_total_refunded(), wc_get_price_decimals() ) >= \Exin\BlackFriday\Domain\Money::parse( (string) $order->get_total(), wc_get_price_decimals() ) ) {
				continue;
			}
			$units = max( 0, (int) ( $line->get_quantity() + $order->get_qty_refunded_for_item( $line_id ) ) );
			if ( \Exin\BlackFriday\Domain\Money::parse( (string) $line->get_total(), wc_get_price_decimals() ) <= \Exin\BlackFriday\Domain\Money::parse( (string) $order->get_total_refunded_for_item( $line_id ), wc_get_price_decimals() ) ) {
				$units = 0;
			}
			if ( $units > 0 ) {
				$groups[ $cid ]['rows'][ $pid ] = ( $groups[ $cid ]['rows'][ $pid ] ?? 0 ) + $units;
			}
		}
		foreach ( $groups as$cid => $group ) {
			try {
				( new LockManager() )->run(
					'campaign:' . $cid,
					function () use ( $order, $cid, $group ) {
						global $wpdb;
						$this->repo->transaction(
							function () use ( $order, $cid, $group ) {
								global $wpdb;
								$table = $wpdb->prefix . 'exin_bf_adaptive_sales';
								if ( false === $wpdb->delete(
									$table,
									array(
										'order_id'    => $order->get_id(),
										'campaign_id' => $cid,
									)
								) ) {
									throw new \RuntimeException( 'adaptive_sales_delete_failed' );
								}
								foreach ( $group['rows'] ?? array()as$pid => $units ) {
									if ( false === $wpdb->insert(
										$table,
										array(
											'order_id'    => $order->get_id(),
											'campaign_id' => $cid,
											'product_id'  => $pid,
											'created_at'  => gmdate( 'Y-m-d H:i:s', $order->get_date_created()->getTimestamp() ),
											'units'       => min( 1000000, $units ),
										)
									) ) {
										throw new \RuntimeException( 'adaptive_sales_write_failed' );
									}
								}
							}
						);
					}
				);
			} catch ( \Throwable $e ) {
				if ( 'lock_busy' === $e->getMessage() ) {
					throw $e; }
				$ok = false;
				if ( 'lock_busy' !== $e->getMessage() ) {
					update_option( 'exin_bf_adaptive_error_' . $cid, 'order_checkpoint_failed', false ); }
			}
		}
		return $ok;
	}
	/**
	 * Remove deleted orders from checkpoints before Woo deletes their attribution.
	 *
	 * @param mixed $order_id Woo order or ID.
	 * @return void Operation result.
	 */
	public function forget( $order_id ): void {
		$id = $order_id instanceof \WC_Order ? $order_id->get_id() : (int) $order_id;
		( new AdaptiveAnalytics( $this->repo ) )->enqueue( $id, null, true );
	}
	/**
	 * Bounded manager-only decisions; no future prices or seeds.
	 *
	 * @param array $c Campaign.
	 * @param int   $page Member page.
	 * @return array Private preview.
	 */
	public function preview( array $c, int $page ): array {
		global $wpdb;
		$samples = array();
		foreach ( $this->repo->items( $c['id'], 10, max( 0, $page - 1 ) * 10 )as$item ) {
			$d         = $wpdb->get_var( $wpdb->prepare( 'SELECT decision FROM %i WHERE campaign_id=%d AND version=%d AND product_id=%d ORDER BY stage DESC LIMIT 1', $wpdb->prefix . 'exin_bf_adaptive_decisions', $c['id'], $c['version'], $item['product_id'] ) );
			$d         = $d ? json_decode( $d, true, 512, JSON_THROW_ON_ERROR ) : null;
			$p         = wc_get_product( $item['product_id'] );
			$samples[] = array(
				'product_id'  => $item['product_id'],
				'name'        => $p ? $p->get_name() : '',
				'base_price'  => $item['base_price'],
				'floor_price' => $item['floor_price'],
				'schedule'    => array( $d['price'] ?? DiscountPlan::automatic_price( $item['base_price'], $item['floor_price'], $c, 0 ) ),
				'adaptive'    => $d ?? array(
					'reason'  => 'cold_start',
					'metrics' => array(),
				),
				'details'     => array(),
			);
		}
		return array(
			'count'       => $this->repo->count_items( $c['id'] ),
			'page'        => $page,
			'has_more'    => $page * 10 < $this->repo->count_items( $c['id'] ),
			'stage_start' => max( 0, DiscountPlan::index( $c, time() ) ),
			'next_stage'  => null,
			'samples'     => $samples,
			'warnings'    => array( 'تصمیم‌های آینده قابل پیش‌بینی نیستند؛ فقط آخرین تصمیم یا برآورد محافظه‌کارانهٔ شروع نمایش داده می‌شود. قیمت کمپین با مبلغ پس از کوپن خارجی متفاوت است.' ),
			'start_utc'   => $c['start_utc'],
			'end_utc'     => $c['end_utc'],
			'error'       => get_option( 'exin_bf_adaptive_error_global', '' ) ? get_option( 'exin_bf_adaptive_error_global', '' ) : get_option( 'exin_bf_adaptive_error_' . $c['id'], '' ),
		);
	}
}
