<?php
/**
 * Scheduler campaign module.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Application;

use Exin\BlackFriday\Domain\Timeline;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\LockManager;
use Exin\BlackFriday\Infrastructure\Logger;

/** Action Scheduler orchestration; every job recalculates the current calendar day. */
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
		add_action( 'exin_bf_batch', array( $this, 'batch' ), 10, 4 );
		add_action( 'exin_bf_retry', array( $this, 'retry' ), 10, 5 );
		$this->processor->register();
	}

	/**
	 * Create the recurring watchdog atomically when it is missing.
	 *
	 * @return void Operation result.
	 */
	public function recover(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		try {
			( new LockManager() )->run(
				'scheduler-recover',
				function () {
					if ( ! as_has_scheduled_action( 'exin_bf_tick', array(), self::GROUP ) ) {
						as_schedule_recurring_action( time() + 5, 60, 'exin_bf_tick', array(), self::GROUP, true );
					}
				}
			);
		} catch ( \Throwable $error ) {
			if ( 'lock_busy' !== $error->getMessage() ) {
				$this->logger->log(
					'error',
					'scheduler_recovery',
					array(
						'error_code' => $this->code( $error ),
						'result'     => 'retry_next_request',
					)
				);
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
		foreach ( array( 'exin_bf_tick', 'exin_bf_batch', 'exin_bf_retry' ) as $hook ) {
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
						if ( ( new Timeline() )->day_index( $campaign, time() ) < 0 ) {
							throw new \DomainException( 'campaign_start_is_in_future' );
						}
						if ( ( new Timeline() )->day_index( $campaign, time() ) >= $campaign['days'] ) {
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
						if ( 'paused' !== $status || ( new Timeline() )->day_index( $campaign, time() ) >= $campaign['days'] ) {
							throw new \DomainException( 'campaign_cannot_resume' );
						}
						$status = ( new Timeline() )->day_index( $campaign, time() ) < 0 ? 'scheduled' : 'active';
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
						$id = $campaign['id'];
						try {
							( new LockManager() )->run(
								'campaign:' . $id,
								function () use ( $id ) {
									$campaign = $this->repo->get( $id );
									$settings = $campaign['settings'];
									if ( ! empty( $settings['restoring'] ) ) {
										$this->enqueue_batch( $id, $campaign['version'], 0, 'restore' );
										return;
									}
									if ( ! in_array( $campaign['status'], array( 'active', 'scheduled', 'paused' ), true ) ) {
										return;
									}
									$day = ( new Timeline() )->day_index( $campaign, time() );
									if ( $day >= $campaign['days'] ) {
										// Natural end restores even a paused campaign's already owned prices.
										$settings['restoring'] = true;
										$version               = $campaign['version'] + 1;
										$this->repo->save(
											$id,
											array(
												'status'   => 'paused',
												'version'  => $version,
												'settings' => $settings,
											)
										);
										$this->enqueue_batch( $id, $version, 0, 'restore' );
									} elseif ( 'scheduled' === $campaign['status'] && $day >= 0 ) {
										$settings['started_at'] = $settings['started_at'] ?? gmdate( 'Y-m-d H:i:s' );
										$this->repo->save(
											$id,
											array(
												'status'   => 'active',
												'settings' => $settings,
											)
										);
										$this->enqueue_batch( $id, $campaign['version'], 0, 'apply' );
									} elseif ( 'active' === $campaign['status'] && $day >= 0 ) {
										$this->enqueue_batch( $id, $campaign['version'], 0, 'apply' );
									}
								}
							);
						} catch ( \Throwable $error ) {
							$this->logger->log(
								'error',
								'watchdog_campaign',
								array(
									'campaign_id' => $id,
									'error_code'  => $this->code( $error ),
									'result'      => 'retry_next_tick',
								)
							);
						}
					}
					$current = $this->repo->current();
					$this->logger->prune( (int) ( $current['settings']['retention_days'] ?? 90 ) );
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
	 * Traverse campaign products with a stable product identifier cursor.
	 *
	 * @param int    $campaign_id Campaign identifier.
	 * @param int    $version Expected campaign settings version.
	 * @param int    $after Exclusive product identifier cursor.
	 * @param string $mode Apply or restore operation mode.
	 *
	 * @return void Operation result.
	 */
	public function batch( int $campaign_id, int $version, int $after = 0, string $mode = 'apply' ): void {
		$campaign = $this->repo->get( $campaign_id );
		if ( ! $this->valid( $campaign, $version, $mode ) ) {
			return;
		}
		// One batch chain per campaign/version/mode; workers cannot race its cursor.
		try {
			( new LockManager() )->run(
				'batch:' . $campaign_id . ':' . $version . ':' . $mode,
				function () use ( $campaign_id, $version, $after, $mode ) {
					$rows = $this->repo->batch( $campaign_id, $after, self::BATCH );
					foreach ( $rows as $item ) {
						$after = $item['product_id'];
						$this->perform( $campaign_id, $after, $version, $mode, 0 );
					}
					$campaign = $this->repo->get( $campaign_id );
					if ( ! $this->valid( $campaign, $version, $mode ) ) {
						return;
					}
					if ( count( $rows ) === self::BATCH ) {
						$this->enqueue_batch( $campaign_id, $version, $after, $mode );
					} else {
						$this->repo->save( $campaign_id, array( 'last_success' => gmdate( 'Y-m-d H:i:s' ) ) );
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
		$this->perform( $campaign_id, $product_id, $version, $mode, $attempt );
		if ( 'restore' === $mode ) {
			$this->finish_restore( $campaign_id, $version );
		}
	}

	/**
	 * Execute a product operation and schedule bounded exponential retries.
	 *
	 * @param int    $campaign_id Campaign identifier.
	 * @param int    $product_id Sellable product or variation identifier.
	 * @param int    $version Expected campaign settings version.
	 * @param string $mode Apply or restore operation mode.
	 * @param int    $attempt Current bounded retry attempt.
	 *
	 * @return void Operation result.
	 */
	private function perform( int $campaign_id, int $product_id, int $version, string $mode, int $attempt ): void {
		if ( $attempt < 0 || $attempt > self::RETRIES ) {
			return;
		}
		( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $campaign_id, $product_id, $version, $mode, $attempt ) {
				( new LockManager() )->run(
					'product:' . $product_id,
					function () use ( $campaign_id, $product_id, $version, $mode, $attempt ) {
						$campaign = $this->repo->get( $campaign_id );
						$item     = $this->repo->item( $campaign_id, $product_id );
						if ( ! $this->valid( $campaign, $version, $mode ) || ! $item ) {
							return;
						}
						if ( in_array( $item['status'], array( 'removed', 'completed' ), true ) ) {
							if ( $item['retry_state'] ) {
								$this->save_retry( $campaign_id, $product_id, array( 'retry_state' => null ) );
							}
							return;
						}
						if ( 'apply' === $mode && in_array( $item['status'], array( 'paused', 'conflict' ), true ) ) {
							return;
						}
						$state   = $item['retry_state'];
						$matches = is_array( $state ) && (int) ( $state['version'] ?? -1 ) === $version && ( $state['mode'] ?? '' ) === $mode;
						if ( $matches ) {
							if ( ! empty( $state['exhausted'] ) || (int) $state['next_at'] > time() ) {
								return;
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
								return;
							}
							if ( 0 !== $attempt && $attempt !== $next_attempt ) {
								return;
							}
							$attempt = $next_attempt;
						} elseif ( 0 !== $attempt ) {
							// Completed operations and old versions cannot be restarted by duplicate callbacks.
							return;
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
								$this->processor->restore( $campaign_id, $product_id );
							} else {
								$this->processor->apply( $campaign_id, $product_id, $version );
							}
							$this->save_retry( $campaign_id, $product_id, array( 'retry_state' => null ) );
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
		$args = array( $campaign_id, $version, $after, $mode );
		if ( ! as_has_scheduled_action( 'exin_bf_batch', $args, self::GROUP ) ) {
			as_enqueue_async_action( 'exin_bf_batch', $args, self::GROUP, true );
		}
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
		return preg_match( '/^[a-z][a-z0-9_]{0,79}$/', $message ) ? $message : 'internal_operation_error';
	}
}
