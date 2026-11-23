<?php
/**
 * PriceProcessor campaign module.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Application;

use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Infrastructure\Cache;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\LockManager;
use Exin\BlackFriday\Infrastructure\Logger;

/** Durable write-ahead intents bridge plugin rows and WooCommerce CRUD without blind replay. */
final class PriceProcessor {
	/**
	 * Reentrant lock handles grouped by product object identity.
	 *
	 * @var array<int,array<string>>
	 */
	private array $external_locks = array();

	/**
	 * Connect the services required by this module.
	 *
	 * @param CampaignRepository $repo Campaign persistence service.
	 * @param Logger             $logger Durable operational audit logger.
	 */
	public function __construct( private CampaignRepository $repo, private Logger $logger ) {}

	/**
	 * Register WordPress hooks for this module.
	 *
	 * @return void Operation result.
	 */
	public function register(): void {
		add_action( 'woocommerce_before_product_object_save', array( $this, 'before_product_save' ), -9999, 1 );
		add_action( 'woocommerce_after_product_object_save', array( $this, 'after_product_save' ), 9999, 1 );
		add_action( 'woocommerce_before_product_variation_object_save', array( $this, 'before_product_save' ), -9999, 1 );
		add_action( 'woocommerce_after_product_variation_object_save', array( $this, 'after_product_save' ), 9999, 1 );
	}

	/**
	 * Serialize financial saves for products selected by a campaign.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 *
	 * @return void Operation result.
	 */
	public function before_product_save( $product ): void {
		if ( ! $product instanceof \WC_Product || ! $product->get_id() ) {
			return;
		}
		$changes = $product->get_changes();
		if ( ! array_intersect( array_keys( $changes ), array( 'regular_price', 'sale_price', 'price', 'date_on_sale_from', 'date_on_sale_to' ) ) ) {
			return;
		}
		if ( ! $this->repo->guards_product( $product->get_id() ) ) {
			return;
		}
		$key                            = spl_object_id( $product );
		$this->external_locks[ $key ][] = ( new LockManager() )->acquire( 'product:' . $product->get_id(), 5 );
	}

	/**
	 * Release the cooperative lock after a WooCommerce financial save.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 *
	 * @return void Operation result.
	 */
	public function after_product_save( $product ): void {
		$key = spl_object_id( $product );
		if ( ! empty( $this->external_locks[ $key ] ) ) {
			( new LockManager() )->release( array_pop( $this->external_locks[ $key ] ) );
		}
	}

	/**
	 * Apply the current stage once without replacing another campaign ownership.
	 *
	 * @param int      $campaign_id Campaign identifier.
	 * @param int      $product_id Sellable product or variation identifier.
	 * @param int      $version Expected campaign settings version.
	 * @param int|null $stage Optional expected stage to stop an old batch at a boundary.
	 *
	 * @return string Changed, unchanged, skipped, stage_changed or error.
	 */
	public function apply( int $campaign_id, int $product_id, int $version, ?int $stage = null ): string {
		return $this->locked(
			$campaign_id,
			$product_id,
			function () use ( $campaign_id, $product_id, $version, $stage ): string {
				$campaign = $this->repo->get( $campaign_id );
				if ( ! $campaign || 'active' !== $campaign['status'] || $campaign['version'] !== $version ) {
					return 'skipped';
				}
				$day = DiscountPlan::index( $campaign, time() );
				if ( null !== $stage && $stage !== $day ) {
					return 'stage_changed';
				}
				if ( $day < 0 || $day >= DiscountPlan::stage_count( $campaign ) ) {
					return 'skipped';
				}
				$item = $this->repo->item( $campaign_id, $product_id );
				if ( ! $item || in_array( $item['status'], array( 'removed', 'completed', 'paused' ), true ) ) {
					return 'skipped';
				}
				if ( 'conflict' === $item['status'] ) {
					return 'error';
				}
				// Another campaign's durable ownership cannot be preempted by priority.
				if ( $this->repo->owned_elsewhere( $campaign_id, $product_id ) ) {
					return 'skipped';
				}
				$winner = $this->repo->winner_for_product( $product_id, time() );
				if ( ! $item['expected'] && ! $item['intent'] && $winner && (int) $winner['id'] !== $campaign_id ) {
					return 'skipped';
				}
				$product = wc_get_product( $product_id );
				if ( ! $this->validate_product( $campaign_id, $product_id, $product, $item ) ) {
					return 'error';
				}
				$recovered = $item['intent'] && PriceState::same( PriceState::capture( $product ), $item['intent']['target'] )
					&& $item['intent']['before']['price'] !== $item['intent']['target']['price'];
				$item      = $this->settle_intent( $campaign_id, $product_id, $product, $item );
				if ( 'conflict' === $item['status'] ) {
					return 'error';
				}
				$current  = PriceState::capture( $product );
				$expected = $item['expected'] ? $item['expected'] : $item['snapshot'];
				if ( ! PriceState::same( $current, $expected ) ) {
					$this->conflict( $campaign_id, $product_id, 'external_price_changed' );
					return 'error';
				}
				if ( PriceState::dynamic( $product ) ) {
					$this->conflict( $campaign_id, $product_id, 'dynamic_price_conflict' );
					return 'error';
				}
				$automatic   = DiscountPlan::automatic( $campaign );
				$selection   = null;
				$selected    = false;
				$in_stock    = $product->is_in_stock();
				$valid_floor = FloorGuard::positive( $item['floor_price'] ?? null, wc_get_price_decimals(), $item['base_price'] );
				if ( ! $valid_floor ) {
					$price = FloorGuard::ordinary( $item );
				} elseif ( $automatic ) {
					$selection   = new AutomaticSelection( $this->repo );
					$selected    = $selection->selected( $campaign, $product_id, $day );
					$stock_owner = wc_get_product( $product->get_stock_managed_by_id() );
					$in_stock    = 'instock' === $product->get_stock_status() && $stock_owner && ( ! $stock_owner->managing_stock() || ( null !== $stock_owner->get_stock_quantity() && $stock_owner->get_stock_quantity() > 0 ) );
					$price       = DiscountPlan::automatic_price( $item['base_price'], $item['floor_price'], $campaign, $day, $product_id, $selected, 'remaining_floor' === $campaign['settings']['discount_plan']['last_day'] ? $selection->remaining( $campaign, $product_id, $day ) : true, $in_stock );
				} else {
					$price = FloorGuard::scheduled( $item, $campaign, $day ); }

				if ( ! is_string( $price ) ) {
					$this->conflict( $campaign_id, $product_id, 'invalid_price_schedule' );
					return 'error';
				}
				$precision = wc_get_price_decimals();
				$minor     = Money::parse( $price, $precision );
				$basis     = $item['snapshot']['regular'];
				if ( $minor < ( $valid_floor ? Money::parse( (string) $item['floor_price'], $precision ) : 0 ) || $minor > Money::parse( $item['base_price'], $precision ) || $item['base_price'] !== $basis ) {
					$this->conflict( $campaign_id, $product_id, 'schedule_financial_bounds_failed' );
					return 'error';
				}
				if ( $automatic && DiscountPlan::index( $campaign, time() ) !== $day ) {
					return 'stage_changed'; }
				$target          = $current;
				$target['price'] = $price;
				$target['sale']  = $price === $current['regular'] ? '' : $price;
				$target['from']  = null;
				$target['to']    = null;
				$status          = ( $automatic ? $in_stock : $product->is_in_stock() ) ? 'active' : 'sold_out';
				if ( $item['expected'] && PriceState::same( $current, $target ) && $status === $item['status'] ) {
					if ( $automatic && $selected && $minor < Money::parse( $basis, $precision ) ) {
						$selection->applied( $campaign, $product_id, $day ); }
					return $recovered ? 'changed' : 'unchanged';
				}
				$result = $this->write( $campaign_id, $product_id, $product, $current, $target, 'apply', $status, $version );
				if ( $automatic && $selected && $minor < Money::parse( $basis, $precision ) && in_array( $result, array( 'changed', 'unchanged' ), true ) ) {
					$selection->applied( $campaign, $product_id, $day ); }
				return $recovered && 'unchanged' === $result ? 'changed' : $result;
			}
		);
	}

	/**
	 * Restore the original financial snapshot when ownership can be proven.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @param int $product_id Sellable product or variation identifier.
	 *
	 * @return string Changed, unchanged, skipped or error.
	 */
	public function restore( int $campaign_id, int $product_id ): string {
		$result = $this->locked(
			$campaign_id,
			$product_id,
			function () use ( $campaign_id, $product_id ): string {
				$item = $this->repo->item( $campaign_id, $product_id );
				if ( ! $item || 'removed' === $item['status'] ) {
					return 'skipped';
				}
				if ( ! $item['expected'] && ! $item['intent'] ) {
					$this->repo->put_item(
						$campaign_id,
						$product_id,
						array(
							'status'   => 'completed',
							'conflict' => '',
						)
					);
					return 'unchanged';
				}
				$product = wc_get_product( $product_id );
				if ( ! $this->validate_product( $campaign_id, $product_id, $product, $item ) ) {
					return 'error';
				}
				$recovered = $item['intent'] && 'restore' === $item['intent']['kind']
					&& PriceState::same( PriceState::capture( $product ), $item['intent']['target'] )
					&& $item['intent']['before']['price'] !== $item['intent']['target']['price'];
				$item      = $this->settle_intent( $campaign_id, $product_id, $product, $item );
				if ( $item['intent'] ) {
					return 'error'; // An unresolved intent never grants ownership of an arbitrary current price.
				}
				$current = PriceState::capture( $product );
				if ( PriceState::same( $current, $item['snapshot'] ) ) {
					$this->repo->put_item(
						$campaign_id,
						$product_id,
						array(
							'expected' => null,
							'intent'   => null,
							'status'   => 'completed',
							'conflict' => '',
						)
					);
					return $recovered ? 'changed' : 'unchanged';
				}
				if ( ! $item['expected'] || ! PriceState::same( $current, $item['expected'] ) ) {
					$this->conflict( $campaign_id, $product_id, 'restore_external_price_changed' );
					return 'error';
				}
				if ( PriceState::dynamic( $product ) ) {
					$this->conflict( $campaign_id, $product_id, 'dynamic_price_conflict' );
					return 'error';
				}
				return $this->write( $campaign_id, $product_id, $product, $current, $item['snapshot'], 'restore', 'completed', 0 );
			}
		);
		if ( in_array( $result, array( 'changed', 'unchanged' ), true ) ) {
			// Queue the next winner only after the previous ownership is cleared.
			do_action( 'exin_bf_price_released', $product_id );
		}
		return $result;
	}

	/**
	 * Recover an interrupted write by comparing before and target fingerprints.
	 *
	 * @param int         $campaign_id Campaign identifier.
	 * @param int         $product_id Sellable product or variation identifier.
	 * @param \WC_Product $product WooCommerce product object.
	 * @param array       $item Campaign product ownership record.
	 *
	 * @return array Operation result.
	 */
	private function settle_intent( int $campaign_id, int $product_id, \WC_Product $product, array $item ): array {
		if ( ! $item['intent'] ) {
			return $item;
		}
		$intent  = $item['intent'];
		$current = PriceState::capture( $product );
		if ( PriceState::same( $current, $intent['target'] ) ) {
			$restored = 'restore' === $intent['kind'];
			$this->logger->log(
				'info',
				'intent_recovered',
				array(
					'campaign_id'  => $campaign_id,
					'product_id'   => $product_id,
					'job_id'       => $item['operation_id'],
					'result'       => 'already_written',
					'before_price' => $intent['before']['price'],
					'after_price'  => $intent['target']['price'],
				)
			);
			$this->repo->put_item(
				$campaign_id,
				$product_id,
				array(
					'expected' => $restored ? null : $intent['target'],
					'intent'   => null,
					'status'   => $restored ? 'completed' : 'active',
					'conflict' => '',
				)
			);
			Cache::invalidate( $product_id );
		} elseif ( PriceState::same( $current, $intent['before'] ) ) {
			// Not written: discard the stale target and calculate the correct current day.
			$this->logger->log(
				'info',
				'intent_recovered',
				array(
					'campaign_id' => $campaign_id,
					'product_id'  => $product_id,
					'result'      => 'not_written',
				)
			);
			$this->repo->put_item(
				$campaign_id,
				$product_id,
				array(
					'intent'   => null,
					'status'   => $item['expected'] ? 'active' : 'included',
					'conflict' => '',
				)
			);
		} else {
			$this->conflict( $campaign_id, $product_id, 'interrupted_write_external_conflict' );
		}
		return $this->repo->item( $campaign_id, $product_id );
	}

	/**
	 * Persist a financial write intent, save through WooCommerce, and verify it.
	 *
	 * @param int         $campaign_id Campaign identifier.
	 * @param int         $product_id Sellable product or variation identifier.
	 * @param \WC_Product $product WooCommerce product object.
	 * @param array       $before Expected fingerprint immediately before the write.
	 * @param array       $target Desired financial fingerprint.
	 * @param string      $kind Operation type.
	 * @param string      $status Desired recorded product status.
	 * @param int         $version Expected campaign settings version.
	 *
	 * @return string Whether the customer price changed, stayed unchanged or failed.
	 */
	private function write( int $campaign_id, int $product_id, \WC_Product $product, array $before, array $target, string $kind, string $status, int $version ): string {
		$operation = wp_generate_uuid4();
		$intent    = array(
			'kind'       => $kind,
			'before'     => $before,
			'target'     => $target,
			'version'    => $version,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$this->repo->put_item(
			$campaign_id,
			$product_id,
			array(
				'intent'       => $intent,
				'operation_id' => $operation,
				'status'       => 'pending',
			)
		);
		if ( ! PriceState::same( $before, $target ) ) {
			// Re-read immediately before CRUD; all normal Woo financial saves share the same advisory lock.
			$fresh = wc_get_product( $product_id );
			if ( ! $fresh || ! PriceState::same( PriceState::capture( $fresh ), $before ) ) {
				$this->conflict( $campaign_id, $product_id, 'compare_before_write_failed' );
				return 'error';
			}
			( new LockManager() )->assert_held( 'campaign:' . $campaign_id );
			( new LockManager() )->assert_held( 'product:' . $product_id );
			PriceState::write( $fresh, $target );
			$product = wc_get_product( $product_id );
		}
		if ( ! $product || ! PriceState::same( PriceState::capture( $product ), $target ) ) {
			$this->conflict( $campaign_id, $product_id, 'write_verification_failed' );
			return 'error';
		}
		// Audit acknowledgement precedes clearing the durable intent. If the audit
		// table is unavailable, a retry can prove and acknowledge the saved target.
		$this->logger->log(
			'info',
			'price_' . $kind,
			array(
				'campaign_id'  => $campaign_id,
				'product_id'   => $product_id,
				'job_id'       => $operation,
				'result'       => $before['price'] === $target['price'] ? 'unchanged' : 'changed',
				'before_price' => $before['price'],
				'after_price'  => $target['price'],
			)
		);
		$this->repo->put_item(
			$campaign_id,
			$product_id,
			array(
				'expected' => 'restore' === $kind ? null : $target,
				'intent'   => null,
				'status'   => $status,
				'conflict' => '',
			)
		);
		Cache::invalidate( $product_id );
		return $before['price'] === $target['price'] ? 'unchanged' : 'changed';
	}

	/**
	 * Validate product support and the immutable currency context.
	 *
	 * @param int         $campaign_id Campaign identifier.
	 * @param int         $product_id Sellable product or variation identifier.
	 * @param \WC_Product $product WooCommerce product object.
	 * @param array       $item Campaign product ownership record.
	 *
	 * @return bool Operation result.
	 */
	private function validate_product( int $campaign_id, int $product_id, $product, array $item ): bool {
		if ( ! $product || ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) ) {
			$this->conflict( $campaign_id, $product_id, 'product_deleted_or_unsupported' );
			return false;
		}
		if ( ( $item['snapshot']['currency'] ?? '' ) !== get_woocommerce_currency() || (int) ( $item['snapshot']['precision'] ?? -1 ) !== wc_get_price_decimals() ) {
			$this->conflict( $campaign_id, $product_id, 'store_currency_or_precision_changed' );
			return false;
		}
		return true;
	}

	/**
	 * Record a financial conflict without overwriting an external price.
	 *
	 * @param int    $campaign_id Campaign identifier.
	 * @param int    $product_id Sellable product or variation identifier.
	 * @param string $code Operation input value.
	 *
	 * @return void Operation result.
	 */
	private function conflict( int $campaign_id, int $product_id, string $code ): void {
		$this->repo->put_item(
			$campaign_id,
			$product_id,
			array(
				'status'   => 'conflict',
				'conflict' => $code,
			)
		);
		$this->logger->log(
			'critical',
			'price_conflict',
			array(
				'campaign_id' => $campaign_id,
				'product_id'  => $product_id,
				'error_code'  => $code,
				'result'      => 'no_write',
			)
		);
		$this->repo->save( $campaign_id, array( 'last_error' => $code ) );
	}

	/**
	 * Run a financial operation under campaign and product locks.
	 *
	 * @param int      $campaign_id Campaign identifier.
	 * @param int      $product_id Sellable product or variation identifier.
	 * @param callable $callback Callback invoked under exclusive locks.
	 *
	 * @return string Result returned by the financial callback.
	 */
	private function locked( int $campaign_id, int $product_id, callable $callback ): string {
		return ( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $product_id, $callback ): string {
				return ( new LockManager() )->run( 'product:' . $product_id, $callback );
			}
		);
	}
}
