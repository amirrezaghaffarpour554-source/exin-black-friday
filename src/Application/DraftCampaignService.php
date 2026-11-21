<?php
/**
 * Atomic draft editing without changing WooCommerce product prices.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Application;

use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\LockManager;
use Exin\BlackFriday\Infrastructure\Logger;

/** Replans only unpublished campaign data while snapshots remain immutable. */
final class DraftCampaignService {
	/**
	 * Connect durable campaign storage and audit logging.
	 *
	 * @param CampaignRepository $repository Campaign and selection repository.
	 * @param Logger             $logger Durable audit logger.
	 */
	public function __construct( private CampaignRepository $repository, private Logger $logger ) {}

	/**
	 * Save validated draft fields and regenerate affected daily price plans.
	 *
	 * No WooCommerce product save or campaign activation occurs in this service.
	 * Rejected amounts and failed database/audit writes preserve the old plan.
	 *
	 * @param int   $campaign_id Draft campaign identifier.
	 * @param array $data Server-validated editable campaign fields.
	 * @return array Saved campaign document.
	 * @throws \DomainException When the campaign or currency cannot be safely edited.
	 */
	public function save( int $campaign_id, array $data ): array {
		return ( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $campaign_id, $data ): array {
				$old = $this->repository->get( $campaign_id );
				if ( ! $old || 'draft' !== $old['status'] || ! empty( $old['settings']['started_at'] ) || ! empty( $old['settings']['restoring'] ) ) {
					throw new \DomainException( 'campaign_not_draft' );
				}
				$allowed         = array( 'title', 'start_utc', 'end_utc', 'timezone', 'days', 'strategy', 'power', 'rounding', 'settings' );
				$updates         = array_intersect_key( $data, array_flip( $allowed ) );
				$new             = array_replace( $old, $updates );
				$new['settings'] = array_replace( $old['settings'], (array) ( $updates['settings'] ?? array() ) );
				$new['end_utc']  = DiscountPlan::end( $new );
				$new['version']  = (int) $old['version'] + 1;
				$new['status']   = 'draft';
				$replan          = ( $new['settings']['discount_plan'] ?? null ) !== ( $old['settings']['discount_plan'] ?? null );
				foreach ( array( 'days', 'strategy', 'power', 'rounding' ) as $field ) {
					$replan = $replan || $new[ $field ] !== $old[ $field ];
				}
				$plans = array();
				$after = 0;
				do {
					$items = $this->repository->batch( $campaign_id, $after, 100 );
					foreach ( $items as $item ) {
						$after = $item['product_id'];
						if ( 'removed' === $item['status'] ) {
							continue;
						}
						if ( $item['expected'] || $item['intent'] ) {
							throw new \DomainException( 'draft_contains_owned_prices' );
						}
						if ( ( $item['snapshot']['currency'] ?? '' ) !== get_woocommerce_currency()
							|| (int) ( $item['snapshot']['precision'] ?? -1 ) !== wc_get_price_decimals() ) {
							throw new \DomainException( 'store_currency_or_precision_changed' );
						}
						if ( $replan && DiscountPlan::automatic( $new ) ) {
							// Automatic decisions are evaluated at execution; do not build N-by-stages arrays.
							DiscountPlan::automatic_price( $item['base_price'], $item['floor_price'], $new, 0 );
						} elseif ( $replan ) {
							$plans[ $item['product_id'] ] = DiscountPlan::schedule( $item['base_price'], $item['floor_price'], $new );
						}
					}
					$count = count( $items );
				} while ( 100 === $count );
				return $this->repository->transaction(
					function () use ( $campaign_id, $new, $plans ): array {
						foreach ( $plans as $product_id => $schedule ) {
							( new LockManager() )->assert_held( 'campaign:' . $campaign_id );
							$this->repository->put_item( $campaign_id, $product_id, array( 'schedule' => $schedule ) );
						}
						( new LockManager() )->assert_held( 'campaign:' . $campaign_id );
						$this->repository->save( $campaign_id, $new );
						$this->logger->log(
							'info',
							'draft_saved',
							array(
								'campaign_id' => $campaign_id,
								'result'      => 'success',
							)
						);
						( new LockManager() )->assert_held( 'campaign:' . $campaign_id );
						return $this->repository->get( $campaign_id );
					}
				);
			}
		);
	}
}
