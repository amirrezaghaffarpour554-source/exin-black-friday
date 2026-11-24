<?php
/**
 * Scheduler campaign module.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Application;

use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\LockManager;
use Exin\BlackFriday\Infrastructure\Logger;

/** Action Scheduler stage jobs with a watchdog for delayed-operation recovery. */
final class Scheduler {
	public const GROUP    = 'exin-black-friday';
	private const BATCH   = 25;
	private const RETRIES = 3;

	/**
	 * Connect the services required by this module.
	 *
	 * @param CampaignRepository $repo Campaign persistence service.
	 * @param PriceProcessor     $processor Safe financial operation processor.
	 * @param Logger             $logger Durable operational audit logger.
	 */
	public function __construct( private CampaignRepository $repo, private PriceProcessor $processor, private Logger $logger ) {}

	/**
	 * Register WordPress hooks for this module.
	 *
	 * @return void Operation result.
	 */
	public function register(): void {
		add_action( 'exin_bf_tick', array( $this, 'tick' ) );
		add_action( 'exin_bf_stage', array( $this, 'stage' ), 10, 3 );
		add_action( 'exin_bf_price_released', array( $this, 'released' ) );
		add_action( 'exin_bf_batch', array( $this, 'batch' ), 10, 5 );
		add_action( 'exin_bf_retry', array( $this, 'retry' ), 10, 5 );
		foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' ) as $hook ) {
			add_action( $hook, array( $this, 'stock_changed' ) ); }
		$this->processor->register();
	}


	/**
	 * Coalesce all stock changes into the existing watchdog and campaign batch.
	 *
	 * @param mixed $product Woo product or product identifier.
	 * @return void
	 */
	public function stock_changed( $product ): void {
		global $wpdb;
		$id              = is_object( $product ) ? $product->get_id() : absint( $product );
		$current_product = is_object( $product ) ? $product : wc_get_product( $id );
		if ( ! $current_product ) {
			return; }
		$owner       = wc_get_product( $current_product->get_stock_managed_by_id() );
		$unavailable = 'instock' !== $current_product->get_stock_status() || ! $owner || ( $owner->managing_stock() && ( null === $owner->get_stock_quantity() || $owner->get_stock_quantity() <= 0 ) );
		foreach ( $this->repo->runnable() as $campaign ) {
			if ( 'active' !== $campaign['status'] || ! DiscountPlan::automatic( $campaign ) ) {
				continue; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Indexed, bounded membership existence check.
			$member = $wpdb->get_var( $wpdb->prepare( "SELECT i.product_id FROM %i i INNER JOIN %i p ON p.ID=i.product_id WHERE i.campaign_id=%d AND (p.ID=%d OR p.post_parent=%d) AND (%d=1 OR i.status='sold_out') LIMIT 1", $wpdb->prefix . 'exin_bf_items', $wpdb->posts, $campaign['id'], $id, $id, $unavailable ? 1 : 0 ) );
			if ( $member ) {
				update_option( 'exin_bf_auto_stock_' . $campaign['id'], 1, false ); }
		}
	}

	/**
	 * Create the recurring watchdog atomically when it is missing.
	 *
	 * @return void Operation result.
	 */
	public function maybe_recover(): void {
		$now = time();
		$health = get_option( 'exin_bf_recovery_health', array() );
		$last_tick = get_option( 'exin_bf_last_tick', '' );
		$heartbeat = $last_tick ? strtotime( $last_tick . ' UTC' ) : false;
		$urgent = get_option( 'exin_bf_needs_recovery' ) || get_option( 'exin_bf_boot_error' );
		if ( ! $urgent && ( $health['release'] ?? '' ) === EXIN_BF_VERSION && $heartbeat && $heartbeat > $now - 180 && $heartbeat <= $now + 60 && ( $health['verified'] ?? 0 ) > $now - 60 ) {
			return;
		}
		if ( (int) get_option( 'exin_bf_recovery_attempt', 0 ) > $now - 30 ) {
			return;
		}
		update_option( 'exin_bf_recovery_attempt', $now, false );
		$this->recover();
	}

	/** Explicit recovery also serves activation, manual calls and delayed AS init. */
	public function recover(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			update_option( 'exin_bf_needs_recovery', 1, false );
			return;
		}
		if ( class_exists( \ActionScheduler::class ) && ! \ActionScheduler::is_initialized() ) {
			update_option( 'exin_bf_needs_recovery', 1, false );
			add_action( 'action_scheduler_init', array( $this, 'recover' ), 20 );
			return;
		}
		try {
			( new LockManager() )->run( 'scheduler-recover', function (): void {
				$flag = get_option( 'exin_bf_needs_recovery' );
				$store = \ActionScheduler::store();
				// Only recurring watchdogs with the exact group/empty arguments qualify.
				// Claimed and running actions are left alone; the next verification
				// reconciles any successor they create after completing.
				$actions = as_get_scheduled_actions( array( 'hook' => 'exin_bf_tick', 'group' => self::GROUP, 'args' => array(), 'status' => 'pending', 'per_page' => 100, 'orderby' => 'date', 'order' => 'ASC' ), 'ids' );
				$keeper = false;
				foreach ( $actions as $id ) {
					$action = $store->fetch_action( $id );
					if ( $action->get_args() || ! $action->get_schedule()->is_recurring() ) { continue; }
					if ( ! $keeper ) { $keeper = true; continue; }
					if ( ! $store->get_claim_id( $id ) && 'pending' === $store->get_status( $id ) ) {
						$store->cancel_action( $id );
					}
				}
				if ( ! $keeper && ! as_schedule_recurring_action( time() + 5, 60, 'exin_bf_tick', array(), self::GROUP, false ) ) {
					throw new \RuntimeException( 'action_scheduler_watchdog_schedule_failed' );
				}
				if ( ! as_has_scheduled_action( 'exin_bf_tick', array(), self::GROUP ) ) {
					throw new \RuntimeException( 'action_scheduler_watchdog_verify_failed' );
				}
				update_option( 'exin_bf_recovery_health', array( 'verified' => time(), 'release' => EXIN_BF_VERSION ), false );
				if ( count( $actions ) >= 100 ) { update_option( 'exin_bf_needs_recovery', 1, false ); }
				elseif ( get_option( 'exin_bf_needs_recovery' ) === $flag ) { delete_option( 'exin_bf_needs_recovery' ); }
				delete_option( 'exin_bf_boot_error' );
			} );
		} catch ( \Throwable $error ) {
			update_option( 'exin_bf_needs_recovery', 1, false );
			if ( 'lock_busy' !== $error->getMessage() ) {
				$this->logger->log( 'error', 'scheduler_recovery', array( 'error_code' => $this->code( $error ), 'result' => 'retry_next_request' ) );
			}
		}
	}

	/**
	 * Unschedule plugin jobs without changing product prices.
	 *
	 * @return void Operation result.
	 */
	public static function deactivate(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		foreach ( array( 'exin_bf_tick', 'exin_bf_stage', 'exin_bf_batch', 'exin_bf_retry' ) as $hook ) {
			as_unschedule_all_actions( $hook, null, self::GROUP );
		}
	}

	/**
	 * Perform a validated versioned campaign lifecycle transition.
	 *
	 * @param int    $campaign_id Campaign identifier.
	 * @param string $action Stable operation identifier.
	 *
	 * @return void Operation result.
	 * @throws \RuntimeException When the queue dependency is unavailable; invalid transitions fail closed.
	 */
	public function transition( int $campaign_id, string $action ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			throw new \RuntimeException( 'action_scheduler_unavailable' );
		}
		( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $campaign_id, $action ) {
				$campaign = $this->repo->get( $campaign_id );
				if ( ! $campaign ) {
					throw new \DomainException( 'campaign_not_found' );
				}
				$settings = $campaign['settings'];
				if ( ! empty( $settings['restoring'] ) && 'restore' !== $action ) {
					throw new \DomainException( 'restore_in_progress' );
				}
				$version = $campaign['version'] + 1;
				$status  = $campaign['status'];
				switch ( $action ) {
					case 'schedule':
						if ( 'draft' !== $status || $this->repo->count_items( $campaign_id ) < 1 || strtotime( $campaign['start_utc'] . ' UTC' ) <= time() ) {
							throw new \DomainException( 'campaign_cannot_schedule' );
						}
						$status = 'scheduled';
						break;
					case 'start':
						if ( ! in_array( $status, array( 'draft', 'scheduled' ), true ) || $this->repo->count_items( $campaign_id ) < 1 ) {
							throw new \DomainException( 'campaign_cannot_start' );
						}
						if ( DiscountPlan::index( $campaign, time() ) < 0 ) {
							throw new \DomainException( 'campaign_start_is_in_future' );
						}
						if ( DiscountPlan::index( $campaign, time() ) >= DiscountPlan::stage_count( $campaign ) ) {
							throw new \DomainException( 'campaign_already_ended' );
						}
						$status                 = 'active';
						$settings['started_at'] = $settings['started_at'] ?? gmdate( 'Y-m-d H:i:s' );
						break;
					case 'pause':
						if ( ! in_array( $status, array( 'active', 'scheduled' ), true ) ) {
							throw new \DomainException( 'campaign_cannot_pause' );
						}
						$status = 'paused';
						break;
					case 'resume':
						if ( 'paused' !== $status || DiscountPlan::index( $campaign, time() ) >= DiscountPlan::stage_count( $campaign ) ) {
							throw new \DomainException( 'campaign_cannot_resume' );
						}
						$status = DiscountPlan::index( $campaign, time() ) < 0 ? 'scheduled' : 'active';
						break;
					case 'restore':
						$status                = 'paused';
						$settings['restoring'] = true;
						break;
					default:
						throw new \DomainException( 'invalid_campaign_transition' );
				}
				$this->repo->save(
					$campaign_id,
					array(
						'status'   => $status,
						'version'  => $version,
						'settings' => $settings,
					)
				);
				$this->logger->log(
					'warning',
					'campaign_' . $action,
					array(
						'campaign_id' => $campaign_id,
						'result'      => 'success',
					)
				);
				$this->recover();
				$this->plan_next( $this->repo->get( $campaign_id ) );
				if ( 'active' === $status || ! empty( $settings['restoring'] ) ) {
					$this->enqueue_batch( $campaign_id, $version, 0, ! empty( $settings['restoring'] ) ? 'restore' : 'apply' );
				}
			}
		);
	}

	/**
	 * Supervise campaigns and queue current-day or restoration batches.
	 *
	 * @return void Operation result.
	 */
	public function tick(): void {
		try {
			( new LockManager() )->run(
				'watchdog',
				function () {
					foreach ( $this->repo->runnable() as $campaign ) {
						if ( DiscountPlan::automatic( $campaign ) && get_option( 'exin_bf_auto_stock_' . $campaign['id'] ) ) {
							( new LockManager() )->run(
								'batch:' . $campaign['id'] . ':' . $campaign['version'] . ':apply',
								function () use ( $campaign ): void {
									( new LockManager() )->run(
										'campaign:' . $campaign['id'],
										function () use ( $campaign ): void {
											$current = $this->repo->get( $campaign['id'] );
											$run     = $current['settings']['last_run'] ?? array();
											if ( ! $run || ! empty( $run['complete'] ) ) {
												$settings = $current['settings'];
												unset( $settings['last_run'] );
												$this->repo->save( $current['id'], array( 'settings' => $settings ) );
												delete_option( 'exin_bf_auto_stock_' . $current['id'] ); }
										}
									);
								}
							);
						}
						$this->stage( $campaign['id'], $campaign['version'] );
					}
					$current = $this->repo->current();
					if ( (int) get_option( 'exin_bf_retention_checked', 0 ) < time() - DAY_IN_SECONDS ) {
						$this->logger->prune( (int) ( $current['settings']['retention_days'] ?? 90 ) );
						update_option( 'exin_bf_retention_checked', time(), false );
					}
					update_option( 'exin_bf_last_tick', gmdate( 'Y-m-d H:i:s' ), false );
				}
			);
		} catch ( \Throwable $error ) {
			$this->logger->log(
				'warning',
				'watchdog',
				array(
					'error_code' => $this->code( $error ),
					'result'     => 'skipped',
				)
			);
		}
	}

	/**
	 * Supervise one campaign at an exact stage boundary or during catch-up.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @param int $version Expected settings version.
	 * @param int $boundary Scheduled timestamp, included in action uniqueness.
	 * @return void
	 */
	public function stage( int $campaign_id, int $version, int $boundary = 0 ): void {
		if ( $boundary > time() ) {
			return;
		}
		try {
			( new LockManager() )->run(
				'campaign:' . $campaign_id,
				function () use ( $campaign_id, $version ): void {
					$campaign = $this->repo->get( $campaign_id );
					if ( ! $campaign || $campaign['version'] !== $version ) {
						return;
					}
					$settings = $campaign['settings'];
					if ( ! empty( $settings['restoring'] ) ) {
						$this->recover_pending( $campaign );
						$this->enqueue_batch( $campaign_id, $version, 0, 'restore' );
						return;
					}
					if ( ! in_array( $campaign['status'], array( 'active', 'scheduled', 'paused' ), true ) ) {
						return;
					}
					$index = DiscountPlan::index( $campaign, time() );
					if ( $index >= DiscountPlan::stage_count( $campaign ) ) {
						$settings['restoring'] = true;
						$this->repo->save(
							$campaign_id,
							array(
								'status'   => 'paused',
								'version'  => $version + 1,
								'settings' => $settings,
							)
						);
						$this->enqueue_batch( $campaign_id, $version + 1, 0, 'restore' );
						return;
					}
					if ( 'scheduled' === $campaign['status'] && $index >= 0 ) {
						$settings['started_at'] = $settings['started_at'] ?? gmdate( 'Y-m-d H:i:s' );
						$this->repo->save(
							$campaign_id,
							array(
								'status'   => 'active',
								'settings' => $settings,
							)
						);
						$campaign = $this->repo->get( $campaign_id );
					}
					$this->plan_next( $campaign );
					$this->recover_pending( $campaign );
					if ( 'active' === $campaign['status'] && $index >= 0 ) {
						try {
							do_action( 'exin_bf_stage_started', $campaign_id, $version, $index );
						} catch ( \Throwable $ignored ) {
							update_option( 'exin_bf_notification_error', 'provider_hook_failed', false ); }
						$due = strtotime( DiscountPlan::stage_start( $campaign, $index ) . ' UTC' );
						if ( time() - $due > 120 && add_option( 'exin_bf_late_' . $campaign_id . '_' . $version . '_' . $index, time(), '', false ) ) {
							$this->logger->log(
								'warning',
								'job_late',
								array(
									'campaign_id' => $campaign_id,
									'error_code'  => 'stage_late',
									'result'      => 'catchup',
								)
							); }
						$this->enqueue_batch( $campaign_id, $version, 0, 'apply' );
					}
				}
			);
		} catch ( \Throwable $error ) {
			$this->logger->log(
				'error',
				'watchdog_campaign',
				array(
					'campaign_id' => $campaign_id,
					'error_code'  => $this->code( $error ),
					'result'      => 'retry_next_tick',
				)
			);
		}
	}

	/**
	 * Wake only the winning product after its previous owner releases it.
	 *
	 * @param int $product_id Released sellable product identifier.
	 * @return void
	 */
	public function released( int $product_id ): void {
		try {
			$winner = $this->repo->winner_for_product( $product_id, time() );
			if ( ! $winner || 'active' !== $winner['status'] || ! function_exists( 'as_enqueue_async_action' ) ) {
				return;
			}
			$args = array( (int) $winner['id'], $product_id, (int) $winner['version'], 'apply', 0 );
			if ( ! as_has_scheduled_action( 'exin_bf_retry', $args, self::GROUP ) ) {
				as_enqueue_async_action( 'exin_bf_retry', $args, self::GROUP, true );
			}
		} catch ( \Throwable $error ) {
			// A wake-up failure must not turn a completed financial restore into a retry.
			try {
				$this->logger->log(
					'warning',
					'release_wakeup',
					array(
						'product_id' => $product_id,
						'error_code' => $this->code( $error ),
						'result'     => 'watchdog_recovery',
					)
				);
			} catch ( \Throwable $audit_error ) {
				return;
			}
		}
	}

	/**
	 * Recreate a missing retry after a worker died with a reserved attempt.
	 *
	 * @param array $campaign Current durable campaign state.
	 * @return void
	 */
	private function recover_pending( array $campaign ): void {
		$run = $campaign['settings']['last_run'] ?? array();
		if ( ( $run['version'] ?? null ) !== $campaign['version'] ) {
			return;
		}
		$pending = (array) ( $run['pending_results'] ?? array() );
		$ids     = array_map( 'intval', array_keys( $pending ) );
		sort( $ids, SORT_NUMERIC );
		$after = (int) ( $run['wake_after'] ?? 0 );
		$next  = array_values( array_filter( $ids, static fn( int $id ): bool => $id > $after ) );
		$ids   = array_merge( $next, array_values( array_diff( $ids, $next ) ) );
		foreach ( array_slice( $ids, 0, self::BATCH ) as $product_id ) {
			$run['wake_after'] = $product_id;
			$item              = $this->repo->item( $campaign['id'], $product_id );
			if ( ! $item || in_array( $item['status'], array( 'conflict', 'paused', 'completed', 'removed' ), true ) ) {
				continue;
			}
			if ( 'skipped' === $pending[ $product_id ] ) {
				$winner = $this->repo->winner_for_product( $product_id, time() );
				if ( $winner && (int) $winner['id'] === $campaign['id'] && 'active' === $winner['status'] && ! $this->repo->owned_elsewhere( $campaign['id'], $product_id ) ) {
					$this->released( $product_id );
				}
				continue;
			}
			$retry = $item['retry_state'] ?? null;
			if ( ! is_array( $retry ) || ! empty( $retry['exhausted'] ) || ( $retry['version'] ?? null ) !== $campaign['version'] ) {
				continue;
			}
			$attempt = (int) $retry['attempt'] < self::RETRIES ? (int) $retry['attempt'] + 1 : 0;
			$args    = array( (int) $campaign['id'], $product_id, (int) $campaign['version'], $retry['mode'], $attempt );
			if ( ! as_has_scheduled_action( 'exin_bf_retry', $args, self::GROUP ) ) {
				as_schedule_single_action( max( time(), (int) $retry['next_at'] ), 'exin_bf_retry', $args, self::GROUP, true );
			}
		}
		if ( $ids ) {
			$settings             = $campaign['settings'];
			$settings['last_run'] = $run;
			$this->repo->save( $campaign['id'], array( 'settings' => $settings ) );
		}
	}

	/**
	 * Queue the next real UTC boundary; a paused campaign needs only its end.
	 *
	 * @param array $campaign Current durable campaign record.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler rejects a boundary.
	 */
	private function plan_next( array $campaign ): void {
		if ( ! in_array( $campaign['status'], array( 'active', 'scheduled', 'paused' ), true ) || ! empty( $campaign['settings']['restoring'] ) ) {
			return;
		}
		$now  = time();
		$next = strtotime( $campaign['end_utc'] . ' UTC' );
		if ( 'paused' !== $campaign['status'] && DiscountPlan::automatic( $campaign ) ) {
			$index = DiscountPlan::index( $campaign, $now ) + 1;
			if ( $index < DiscountPlan::stage_count( $campaign ) ) {
				$next = strtotime( DiscountPlan::stage_start( $campaign, $index ) . ' UTC' );
			}
		} elseif ( 'paused' !== $campaign['status'] ) {
			foreach ( DiscountPlan::boundaries( $campaign ) as $start ) {
				$at = strtotime( $start . ' UTC' );
				if ( $at > $now ) {
					$next = $at;
					break;
				}
			}
		}
		if ( $next <= $now ) {
			return;
		}
		$args = array( (int) $campaign['id'], (int) $campaign['version'], $next );
		if ( ! as_has_scheduled_action( 'exin_bf_stage', $args, self::GROUP ) && ! as_schedule_single_action( $next, 'exin_bf_stage', $args, self::GROUP, true ) ) {
			throw new \RuntimeException( 'action_scheduler_stage_schedule_failed' );
		}
	}

	/**
	 * Traverse campaign products with a stable product identifier cursor.
	 *
	 * @param int      $campaign_id Campaign identifier.
	 * @param int      $version Expected campaign settings version.
	 * @param int      $after Exclusive product identifier cursor.
	 * @param string   $mode Apply or restore operation mode.
	 * @param int|null $queued_stage Stage included in new action uniqueness; old jobs remain valid.
	 *
	 * @return void Operation result.
	 */
	public function batch( int $campaign_id, int $version, int $after = 0, string $mode = 'apply', ?int $queued_stage = null ): void {
		$campaign = $this->repo->get( $campaign_id );
		if ( ! $this->valid( $campaign, $version, $mode ) ) {
			return;
		}
		try {
			( new LockManager() )->run(
				'batch:' . $campaign_id . ':' . $version . ':' . $mode,
				function () use ( $campaign_id, $version, $mode, $queued_stage ): void {
					$campaign = $this->repo->get( $campaign_id );
					if ( ! $this->valid( $campaign, $version, $mode ) ) {
						return;
					}
					$stage = 'restore' === $mode ? -1 : DiscountPlan::index( $campaign, time() );
					if ( null !== $queued_stage && $queued_stage > $stage ) {
						return;
					}
					$run     = $campaign['settings']['last_run'] ?? array();
					$matches = $this->same_run( $run, $version, $stage, $mode );
					if ( $matches && ! empty( $run['complete'] ) ) {
						return;
					}
					// The durable cursor, not an old callback payload, resumes a batch.
					$after = $matches ? (int) $run['after'] : 0;
					$rows  = $this->repo->batch( $campaign_id, $after, self::BATCH );
					foreach ( $rows as $item ) {
						$result = $this->perform( $campaign_id, $item['product_id'], $version, $mode, 0, $stage, true );
						if ( 'stage_changed' === $result ) {
							$this->enqueue_batch( $campaign_id, $version, 0, $mode );
							return;
						}
						$after = $item['product_id'];
					}
					$campaign = $this->repo->get( $campaign_id );
					if ( ! $this->valid( $campaign, $version, $mode ) ) {
						return;
					}
					if ( 'apply' === $mode && DiscountPlan::index( $campaign, time() ) !== $stage ) {
						$this->enqueue_batch( $campaign_id, $version, 0, $mode );
						return;
					}
					if ( count( $rows ) === self::BATCH ) {
						$this->enqueue_batch( $campaign_id, $version, $after, $mode );
					} else {
						( new LockManager() )->run(
							'campaign:' . $campaign_id,
							function () use ( $campaign_id, $version, $stage, $mode ): void {
								$campaign = $this->repo->get( $campaign_id );
								if ( ! $this->valid( $campaign, $version, $mode ) ) {
									return;
								}
								$settings = $campaign['settings'];
								$run      = $settings['last_run'] ?? array();
								if ( $this->same_run( $run, $version, $stage, $mode ) ) {
									$run['complete']      = true;
									$settings['last_run'] = $run;
									$this->repo->save(
										$campaign_id,
										array(
											'settings'     => $settings,
											'last_success' => gmdate( 'Y-m-d H:i:s' ),
										)
									);
								}
							}
						);
						if ( 'restore' === $mode ) {
							$this->finish_restore( $campaign_id, $version );
						}
					}
				}
			);
		} catch ( \Throwable $error ) {
			$this->logger->log(
				'error',
				'batch',
				array(
					'campaign_id' => $campaign_id,
					'error_code'  => $this->code( $error ),
					'result'      => 'watchdog_recovery',
				)
			);
		}
	}

	/**
	 * Run a bounded retry for one product operation.
	 *
	 * @param int    $campaign_id Campaign identifier.
	 * @param int    $product_id Sellable product or variation identifier.
	 * @param int    $version Expected campaign settings version.
	 * @param string $mode Apply or restore operation mode.
	 * @param int    $attempt Current bounded retry attempt.
	 *
	 * @return void Operation result.
	 */
	public function retry( int $campaign_id, int $product_id, int $version, string $mode, int $attempt ): void {
		$this->perform( $campaign_id, $product_id, $version, $mode, $attempt, null, false );
		if ( 'restore' === $mode ) {
			$this->finish_restore( $campaign_id, $version );
		}
	}

	/**
	 * Execute a product operation and schedule bounded exponential retries.
	 *
	 * @param int      $campaign_id Campaign identifier.
	 * @param int      $product_id Sellable product or variation identifier.
	 * @param int      $version Expected campaign settings version.
	 * @param string   $mode Apply or restore operation mode.
	 * @param int      $attempt Current bounded retry attempt.
	 * @param int|null $expected_stage Optional stage of the active batch.
	 * @param bool     $cursor Whether this operation advances its batch cursor.
	 *
	 * @return string Financial result or skipped operation.
	 */
	private function perform( int $campaign_id, int $product_id, int $version, string $mode, int $attempt, ?int $expected_stage = null, bool $cursor = true ): string {
		if ( $attempt < 0 || $attempt > self::RETRIES ) {
			return 'skipped';
		}
		return ( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $campaign_id, $product_id, $version, $mode, $attempt, $expected_stage, $cursor ): string {
				return ( new LockManager() )->run(
					'product:' . $product_id,
					function () use ( $campaign_id, $product_id, $version, $mode, $attempt, $expected_stage, $cursor ): string {
						$campaign = $this->repo->get( $campaign_id );
						$item     = $this->repo->item( $campaign_id, $product_id );
						if ( ! $this->valid( $campaign, $version, $mode ) || ! $item ) {
							return 'skipped';
						}
						$stage = 'restore' === $mode ? -1 : DiscountPlan::index( $campaign, time() );
						if ( null !== $expected_stage && $stage !== $expected_stage ) {
							return 'stage_changed';
						}
						$run = $campaign['settings']['last_run'] ?? array();
						if ( ! $cursor && 0 === $attempt && $this->same_run( $run, $version, $stage, $mode )
							&& $product_id <= (int) $run['after'] && ! isset( $run['pending_results'][ $product_id ] ) ) {
							return 'skipped';
						}
						if ( in_array( $item['status'], array( 'removed', 'completed' ), true ) ) {
							if ( $item['retry_state'] ) {
								$this->save_retry( $campaign_id, $product_id, array( 'retry_state' => null ) );
							}
							$this->record_result( $campaign_id, $version, $stage, $mode, $product_id, 'skipped', $cursor );
							return 'skipped';
						}
						if ( 'apply' === $mode && in_array( $item['status'], array( 'paused', 'conflict' ), true ) ) {
							$result = 'conflict' === $item['status'] ? 'error' : 'skipped';
							$this->record_result( $campaign_id, $version, $stage, $mode, $product_id, $result, $cursor );
							return $result;
						}
						$state   = $item['retry_state'];
						$matches = is_array( $state ) && (int) ( $state['version'] ?? -1 ) === $version && ( $state['mode'] ?? '' ) === $mode;
						if ( $matches ) {
							if ( ! empty( $state['exhausted'] ) || (int) $state['next_at'] > time() ) {
								$this->record_result( $campaign_id, $version, $stage, $mode, $product_id, 'error', $cursor );
								return 'skipped';
							}
							$next_attempt = (int) $state['attempt'] + 1;
							if ( $next_attempt > self::RETRIES ) {
								// A worker died after reserving the last attempt; never reset its budget.
								$state['exhausted'] = true;
								$this->save_retry(
									$campaign_id,
									$product_id,
									array(
										'retry_state' => $state,
										'status'      => 'conflict',
										'conflict'    => 'retry_exhausted_interrupted_operation',
									)
								);
								return 'skipped';
							}
							if ( 0 !== $attempt && $attempt !== $next_attempt ) {
								return 'skipped';
							}
							$attempt = $next_attempt;
						} elseif ( 0 !== $attempt ) {
							// Completed operations and old versions cannot be restarted by duplicate callbacks.
							return 'skipped';
						}
						$state = array(
							'version'   => $version,
							'mode'      => $mode,
							'attempt'   => $attempt,
							'next_at'   => time() + 30 * ( 2 ** $attempt ),
							'exhausted' => false,
							'running'   => true,
						);
						// Reserve the budget before Woo CRUD; a hard crash cannot reset a retry chain.
						$this->save_retry( $campaign_id, $product_id, array( 'retry_state' => $state ) );
						try {
							if ( 'restore' === $mode ) {
								$result = $this->processor->restore( $campaign_id, $product_id );
							} else {
								$result = $this->processor->apply( $campaign_id, $product_id, $version, $stage );
							}
							$this->save_retry( $campaign_id, $product_id, array( 'retry_state' => null ) );
							if ( 'stage_changed' !== $result ) {
								$this->record_result( $campaign_id, $version, $stage, $mode, $product_id, $result, $cursor );
							}
							return $result;
						} catch ( \Throwable $error ) {
							$code               = $this->code( $error );
							$state['running']   = false;
							$state['next_at']   = time() + 30 * ( 2 ** $attempt );
							$state['exhausted'] = $attempt >= self::RETRIES;
							$changes            = array( 'retry_state' => $state );
							if ( $state['exhausted'] ) {
								$changes['status']   = 'conflict';
								$changes['conflict'] = 'retry_exhausted_' . $code;
							}
							// Durable state and queue acknowledgement precede nonfinancial diagnostic writes.
							$this->save_retry( $campaign_id, $product_id, $changes );
							if ( ! $state['exhausted'] ) {
								as_schedule_single_action( $state['next_at'], 'exin_bf_retry', array( $campaign_id, $product_id, $version, $mode, $attempt + 1 ), self::GROUP, true );
							}
							$this->repo->save( $campaign_id, array( 'last_error' => $code ) );
							$this->record_result( $campaign_id, $version, $stage, $mode, $product_id, 'error', $cursor );
							$this->logger->log(
								'error',
								'job_' . $mode,
								array(
									'campaign_id' => $campaign_id,
									'product_id'  => $product_id,
									'error_code'  => $code,
									'result'      => $state['exhausted'] ? 'retry_exhausted' : 'retry_scheduled',
								)
							);
							return 'error';
						}
					}
				);
			}
		);
	}

	/**
	 * Persist retry accounting only while both connection-owned locks remain valid.
	 *
	 * @param int   $campaign_id Campaign identifier.
	 * @param int   $product_id Sellable product identifier.
	 * @param array $data Allowlisted retry state or conflict fields.
	 * @return void
	 */
	private function save_retry( int $campaign_id, int $product_id, array $data ): void {
		$locks = new LockManager();
		$locks->assert_held( 'campaign:' . $campaign_id );
		$locks->assert_held( 'product:' . $product_id );
		$this->repo->put_item( $campaign_id, $product_id, $data );
	}

	/**
	 * Finalize restoration after all products have a recorded result.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @param int $version Expected campaign settings version.
	 *
	 * @return void Operation result.
	 */
	private function finish_restore( int $campaign_id, int $version ): void {
		( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $campaign_id, $version ) {
				$campaign = $this->repo->get( $campaign_id );
				if ( ! $this->valid( $campaign, $version, 'restore' ) ) {
					return;
				}
				$conflicts = false;
				$pending   = false;
				$after     = 0;
				do {
					$rows = $this->repo->batch( $campaign_id, $after, 100 );
					foreach ( $rows as $item ) {
						$after = $item['product_id'];
						if ( 'conflict' === $item['status'] ) {
							$conflicts = true;
						} elseif ( $item['expected'] || $item['intent'] || ! in_array( $item['status'], array( 'removed', 'completed' ), true ) ) {
							$pending = true;
						}
					}
					$row_count = count( $rows );
				} while ( 100 === $row_count );
				if ( $pending ) {
					return;
				}
				$settings              = $campaign['settings'];
				$settings['restoring'] = false;
				$this->repo->save(
					$campaign_id,
					array(
						'status'       => $conflicts ? 'failed' : 'completed',
						'settings'     => $settings,
						'last_error'   => $conflicts ? 'restore_conflicts_require_review' : '',
						'last_success' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->logger->log(
					$conflicts ? 'critical' : 'info',
					'restore_finished',
					array(
						'campaign_id' => $campaign_id,
						'result'      => $conflicts ? 'conflicts_require_review' : 'success',
					)
				);
			}
		);
	}

	/**
	 * Check whether a queued job still matches the durable campaign version.
	 *
	 * @param array|null $campaign Campaign record.
	 * @param int        $version Expected campaign settings version.
	 * @param string     $mode Apply or restore operation mode.
	 *
	 * @return bool Operation result.
	 */
	private function valid( ?array $campaign, int $version, string $mode ): bool {
		if ( ! $campaign || $campaign['version'] !== $version ) {
			return false;
		}
		return 'restore' === $mode ? ! empty( $campaign['settings']['restoring'] ) : ( 'apply' === $mode && 'active' === $campaign['status'] && empty( $campaign['settings']['restoring'] ) );
	}

	/**
	 * Queue a batch only when an equivalent action is not already scheduled.
	 *
	 * @param int    $campaign_id Campaign identifier.
	 * @param int    $version Expected campaign settings version.
	 * @param int    $after Exclusive product identifier cursor.
	 * @param string $mode Apply or restore operation mode.
	 *
	 * @return void Operation result.
	 */
	private function enqueue_batch( int $campaign_id, int $version, int $after, string $mode ): void {
		$campaign = $this->repo->get( $campaign_id );
		if ( ! $this->valid( $campaign, $version, $mode ) ) {
			return;
		}
		$stage = 'restore' === $mode ? -1 : DiscountPlan::index( $campaign, time() );
		$run   = $campaign['settings']['last_run'] ?? array();
		if ( $this->same_run( $run, $version, $stage, $mode ) && ! empty( $run['complete'] ) ) {
			return;
		}
		$args = array( $campaign_id, $version, $after, $mode, $stage );
		if ( ! as_has_scheduled_action( 'exin_bf_batch', $args, self::GROUP ) ) {
			as_enqueue_async_action( 'exin_bf_batch', $args, self::GROUP, true );
		}
	}

	/**
	 * Compare the identity of one campaign's stage report.
	 *
	 * @param array  $run Saved operation summary.
	 * @param int    $version Settings version.
	 * @param int    $stage Current stage or minus one for restore.
	 * @param string $mode Operation mode.
	 * @return bool Whether this report describes the same operation.
	 */
	private function same_run( array $run, int $version, int $stage, string $mode ): bool {
		return ( $run['version'] ?? null ) === $version && ( $run['stage'] ?? null ) === $stage && ( $run['mode'] ?? null ) === $mode;
	}

	/**
	 * Count each product once, replacing a failed result when its retry succeeds.
	 *
	 * Normal products use the keyset cursor. Only failures, skipped ownership and
	 * retries ahead of that cursor need individual entries, keeping reports small.
	 * Caller already holds this campaign's lock.
	 *
	 * @param int    $campaign_id Campaign identifier.
	 * @param int    $version Settings version.
	 * @param int    $stage Current stage or minus one for restore.
	 * @param string $mode Operation mode.
	 * @param int    $product_id Product whose operation completed.
	 * @param string $result Changed, unchanged, skipped or error.
	 * @param bool   $cursor Whether this result advances the main batch cursor.
	 * @return void
	 */
	private function record_result( int $campaign_id, int $version, int $stage, string $mode, int $product_id, string $result, bool $cursor ): void {
		$campaign = $this->repo->get( $campaign_id );
		if ( ! $campaign || $campaign['version'] !== $version ) {
			return;
		}
		( new LockManager() )->assert_held( 'campaign:' . $campaign_id );
		$settings = $campaign['settings'];
		$run      = $settings['last_run'] ?? array();
		if ( ! $this->same_run( $run, $version, $stage, $mode ) ) {
			$run = array(
				'version'         => $version,
				'stage'           => $stage,
				'mode'            => $mode,
				'changed'         => 0,
				'unchanged'       => 0,
				'skipped'         => 0,
				'errors'          => 0,
				'after'           => 0,
				'complete'        => false,
				'pending_results' => array(),
			);
		}
		$pending  = $run['pending_results'] ?? array();
		$previous = $pending[ $product_id ] ?? null;
		$key      = 'error' === $result ? 'errors' : $result;
		if ( ! in_array( $key, array( 'changed', 'unchanged', 'skipped', 'errors' ), true ) ) {
			return;
		}
		if ( null !== $previous ) {
			if ( in_array( $previous, array( 'errors', 'skipped' ), true ) && $previous !== $key ) {
				$run[ $previous ] = max( 0, (int) $run[ $previous ] - 1 );
				++$run[ $key ];
			} else {
				$key = $previous;
			}
		} elseif ( $product_id > (int) $run['after'] ) {
			++$run[ $key ];
		}
		if ( ! $cursor || in_array( $key, array( 'errors', 'skipped' ), true ) ) {
			$pending[ $product_id ] = $key;
		} else {
			unset( $pending[ $product_id ] );
		}
		if ( $cursor ) {
			$run['after'] = max( (int) $run['after'], $product_id );
		}
		$run['pending_results'] = $pending;
		$run['at']              = gmdate( 'Y-m-d H:i:s' );
		$settings['last_run']   = $run;
		$this->repo->save( $campaign_id, array( 'settings' => $settings ) );
	}

	/**
	 * Reduce exceptions to safe stable internal diagnostic codes.
	 *
	 * @param \Throwable $error Exception to reduce to a diagnostic code.
	 *
	 * @return string Operation result.
	 */
	private function code( \Throwable $error ): string {
		$message = $error->getMessage();
		if ( preg_match( '/^[a-z][a-z0-9_]{0,79}$/', $message ) ) {
			return $message;
		}
		// A stable class/fingerprint identifies the failure without logging SQL or secrets.
		$parts = explode( '\\', get_class( $error ) );
		return 'internal_' . substr( strtolower( (string) end( $parts ) ), 0, 32 ) . '_' . substr( hash( 'sha256', $message ), 0, 12 );
	}
}
