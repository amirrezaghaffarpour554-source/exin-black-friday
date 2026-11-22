<?php
/** Durable adaptive order outbox and validated metrics, separate from pricing.
 *
 * @package ExinBlackFriday
 */

declare(strict_types=1);

namespace Exin\BlackFriday\Application;

use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\LockManager;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Financial metadata must be read fresh.
/** Durable event processing and bounded, validated sales snapshots. */
final class AdaptiveAnalytics {
	/** Receive existing campaign persistence.
	 *
	 * @param CampaignRepository $repo Repository.
	 */
	public function __construct( private CampaignRepository $repo ) {}
	/** Match the snapshot to its reporting configuration.
	 *
	 * @param array $c Campaign.
	 * @return string Configuration signature.
	 */
	public static function signature( array $c ): string {
		return hash( 'sha256', wp_json_encode( array( $c['settings']['discount_plan']['adaptive']['window_hours'], $c['settings']['order_statuses'] ?? array( 'processing', 'completed' ), $c['settings']['currency'] ?? '' ) ) );}
	/** Read-only downgrade guard: legacy versions cannot run active smart profiles.
	 *
	 * @return void Validation result.
	 * @throws \DomainException When active smart profiles or claims remain.
	 */
	public function assert_rollback_safe(): void {
		global $wpdb;
		$cursor = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM %i WHERE id>%d ORDER BY id LIMIT 100', $wpdb->prefix . 'exin_bf_campaigns', $cursor ), ARRAY_A );
			foreach ( $rows as$row ) {
				$cursor = (int) $row['id'];
				$c      = $this->repo->get( $cursor );
				if ( $c && SmartAdaptive::enabled( $c ) && ( $this->repo->has_ownership( $cursor ) || ! in_array( $c['status'], array( 'completed', 'archived', 'draft' ), true ) ) ) {
					throw new \DomainException( 'adaptive_rollback_has_claims' );
				}
			}
			$count = count( $rows );
		} while ( 100 === $count );
	}
	/** Register one shared analytics worker.
	 *
	 * @return void Registration result.
	 */
	public function register(): void {
		add_action( 'exin_bf_adaptive_analytics', array( $this, 'run' ) );
		add_action( 'exin_bf_tick', array( $this, 'wake' ) );
	}
	/** Coalesce analytics wake-ups on the existing watchdog.
	 *
	 * @return void Queue result.
	 */
	public function wake(): void {
		if ( ! as_has_scheduled_action( 'exin_bf_adaptive_analytics', array(), Scheduler::GROUP ) ) {
			as_enqueue_async_action( 'exin_bf_adaptive_analytics', array(), Scheduler::GROUP, true );
		}
	}
	/** Events enqueue identifiers only; the worker rereads the latest Woo object.
	 *
	 * @param int            $id Order ID.
	 * @param \WC_Order|null $order Optional producer object, never trusted by the worker.
	 * @param bool           $deleted Committed deletion marker.
	 * @return void Queue result.
	 * @throws \RuntimeException When an event cannot be durably queued.
	 */
	public function enqueue( int $id, ?\WC_Order $order = null, bool $deleted = false ): void {
		global $wpdb;
		$groups = array();
		$old    = $wpdb->get_results( $wpdb->prepare( 'SELECT campaign_id,product_id FROM %i WHERE order_id=%d', $wpdb->prefix . 'exin_bf_adaptive_sales', $id ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $old ) ) {
			throw new \RuntimeException( 'adaptive_event_read_failed' );
		}
		foreach ( $old as$row ) {
			$groups[ (int) $row['campaign_id'] ][ (int) $row['product_id'] ] = true;
		}
		$pending = $wpdb->get_col( $wpdb->prepare( 'SELECT campaign_id FROM %i WHERE order_id=%d', $wpdb->prefix . 'exin_bf_adaptive_events', $id ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'adaptive_event_read_failed' );
		}
		foreach ( $pending as$cid ) {
			$groups[ (int) $cid ] = $groups[ (int) $cid ] ?? array();
		}
		if ( $order ) {
			foreach ( $order->get_items( 'line_item' )as$line ) {
				$cid = (int) $line->get_meta( '_exin_bf_campaign_id', true );
				$pid = (int) $line->get_meta( '_exin_bf_product_id', true );
				if ( $cid && $pid ) {
					$groups[ $cid ][ $pid ] = true;
				}
			}
		}
		foreach ( $groups as$cid => $pids ) {
			$c = $this->repo->get( $cid );
			if ( ! $c || ! SmartAdaptive::enabled( $c ) ) {
				continue;
			}
			$this->repo->transaction(
				function () use ( $id, $cid, $deleted, $pids ) {
					global $wpdb;
					$this->state( $cid ); // Also locks the durable campaign state until commit.
					if ( false === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (campaign_id,order_id,revision,deleted) VALUES (%d,%d,1,%d) ON DUPLICATE KEY UPDATE revision=revision+1,deleted=VALUES(deleted)', $wpdb->prefix . 'exin_bf_adaptive_events', $cid, $id, $deleted ? 1 : 0 ) ) ) {
						throw new \RuntimeException( 'adaptive_outbox_write_failed' );
					}
					foreach ( array_keys( $pids )as$pid ) {
						if ( false === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (campaign_id,product_id,revision) VALUES (%d,%d,1) ON DUPLICATE KEY UPDATE revision=revision+1', $wpdb->prefix . 'exin_bf_adaptive_metrics', $cid, $pid ) ) ) {
							throw new \RuntimeException( 'adaptive_invalidation_failed' );
						}
					}
				}
			);
		}
	}
	/** Insert/lock metadata state only; never reset campaign or checkpoint data.
	 *
	 * @param int $cid Campaign ID.
	 * @return void Lock result.
	 * @throws \RuntimeException When state cannot be written.
	 */
	private function state( int $cid ): void {
		global $wpdb;
		if ( false === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (campaign_id) VALUES (%d) ON DUPLICATE KEY UPDATE campaign_id=VALUES(campaign_id)', $wpdb->prefix . 'exin_bf_adaptive_state', $cid ) ) ) {
			throw new \RuntimeException( 'adaptive_state_failed' );
		}
	}
	/** One bounded, independently scheduled analytics pass.
	 *
	 * @param int $only Optional campaign ID for isolated execution.
	 * @return void Processing result.
	 * @throws \RuntimeException When metadata processing fails.
	 */
	public function run( int $only = 0 ): void {
		try {
			( new LockManager() )->run(
				'adaptive:analytics',
				function () use ( $only ) {
					global $wpdb;
					$deadline = microtime( true ) + 15;
					// Long-lived workers must not reuse pre-refund in-process Woo query caches.
					if ( function_exists( 'wp_cache_flush_runtime' ) ) {
						wp_cache_flush_runtime();
					}
					if ( get_option( 'exin_bf_adaptive_error_global' ) ) {
						if ( false === $wpdb->query( 'UPDATE ' . $wpdb->prefix . "exin_bf_adaptive_state SET recovering=1,recovery_cursor=0,signature=''" ) ) {
							throw new \RuntimeException( 'adaptive_recovery_mark_failed' );
						}delete_option( 'exin_bf_adaptive_error_global' );}
					$cursor = (int) get_option( 'exin_bf_adaptive_campaign_cursor', 0 );
					$ids    = $only ? array( $only ) : $this->campaign_ids( $cursor );
					if ( ! $only && ! $ids && $cursor > 0 ) {
						// Wrap in this pass; do not waste every alternate watchdog tick.
						update_option( 'exin_bf_adaptive_campaign_cursor', 0, false );
						$ids = $this->campaign_ids( 0 );
					}
					foreach ( $ids as$cid ) {
						$c = $this->repo->get( $cid );
						if ( $c && SmartAdaptive::enabled( $c ) ) {
							$this->campaign( $c );
						}if ( ! $only ) {
							update_option( 'exin_bf_adaptive_campaign_cursor', $cid, false );
						}if ( microtime( true ) > $deadline ) {
							break;
						}
					}
				}
			);
		} catch ( \RuntimeException $error ) {
			if ( $error->getMessage() !== 'lock_busy' ) {
				throw $error;
			}
		}
	}
	/** Read a bounded campaign page without hiding database failures.
	 *
	 * @param int $after Exclusive campaign cursor.
	 * @return array Campaign IDs.
	 * @throws \RuntimeException When the campaign page cannot be read.
	 */
	private function campaign_ids( int $after ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status IN ('active','scheduled') AND id>%d ORDER BY id LIMIT 10", $wpdb->prefix . 'exin_bf_campaigns', $after ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'adaptive_campaign_read_failed' );
		}
		return array_map( 'intval', $ids );
	}
	/** Advance durable recovery, events and snapshot publication for one campaign.
	 *
	 * @param array $c Campaign.
	 * @return void Processing result.
	 * @throws \RuntimeException When metadata persistence fails.
	 */
	private function campaign( array $c ): void {
		global $wpdb;
		$cid    = (int) $c['id'];
		$state  = $wpdb->prefix . 'exin_bf_adaptive_state';
		$events = $wpdb->prefix . 'exin_bf_adaptive_events';
		$this->state( $cid );
		$s         = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d', $state, $cid ), ARRAY_A );
		$signature = self::signature( $c );
		if ( ! $s ) {
			throw new \RuntimeException( 'adaptive_state_missing' );
		}
		if ( $s['signature'] !== $signature || get_option( 'exin_bf_adaptive_error_' . $cid ) ) {
			if ( false === $wpdb->update(
				$state,
				array(
					'signature'       => $signature,
					'recovering'      => 1,
					'recovery_cursor' => 0,
				),
				array( 'campaign_id' => $cid )
			) ) {
				throw new \RuntimeException( 'adaptive_recovery_state_failed' );
			}
			delete_option( 'exin_bf_adaptive_error_' . $cid );
			$s['recovering']      = 1;
			$s['recovery_cursor'] = 0;
		}
		// Only missing/inconsistent checkpoints trigger this durable keyset recovery.
		if ( (int) $s['recovering'] ) {
			$hpos   = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			$meta   = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
			$column = $hpos ? 'order_id' : 'post_id';
			$sql    = $wpdb->prepare( 'SELECT order_id FROM (SELECT %i order_id FROM %i WHERE meta_key=%s AND meta_value=%s UNION SELECT order_id FROM %i WHERE campaign_id=%d) recovery WHERE order_id>%d ORDER BY order_id LIMIT 100', $column, $meta, '_exin_bf_campaign_' . $cid, '1', $wpdb->prefix . 'exin_bf_adaptive_sales', $cid, (int) $s['recovery_cursor'] );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fully prepared identifier/value keyset query above.
			$ids = $wpdb->get_col( $sql );
			if ( $wpdb->last_error ) {
				throw new \RuntimeException( 'adaptive_recovery_read_failed' );
			}
			foreach ( $ids as$id ) {
				$this->sync( $c, (int) $id, false );
				if ( false === $wpdb->update( $state, array( 'recovery_cursor' => (int) $id ), array( 'campaign_id' => $cid ) ) ) {
					throw new \RuntimeException( 'adaptive_recovery_cursor_failed' );
				}
			}
			if ( count( $ids ) === 100 ) {
				return;
			}
			if ( false === $wpdb->update( $state, array( 'recovering' => 0 ), array( 'campaign_id' => $cid ) ) ) {
				throw new \RuntimeException( 'adaptive_recovery_commit_failed' );
			}
		}
		$queued = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d ORDER BY order_id LIMIT 100', $events, $cid ), ARRAY_A );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'adaptive_outbox_read_failed' );
		}
		foreach ( $queued as$event ) {
			$this->sync( $c, (int) $event['order_id'], (bool) $event['deleted'] );
			if ( false === $wpdb->delete(
				$events,
				array(
					'campaign_id' => $cid,
					'order_id'    => $event['order_id'],
					'revision'    => $event['revision'],
				)
			) ) {
				throw new \RuntimeException( 'adaptive_outbox_ack_failed' );
			}
		}
		$this->repo->transaction(
			function () use ( $c, $cid, $state, $events ) {
				global $wpdb;
				$this->state( $cid );
				$pending = $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE campaign_id=%d LIMIT 1', $events, $cid ) );
				if ( $wpdb->last_error ) {
					throw new \RuntimeException( 'adaptive_outbox_read_failed' );
				}
				$pending = null !== $pending;
				$s       = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d', $state, $cid ), ARRAY_A );
				if ( $wpdb->last_error || ! $s ) {
					throw new \RuntimeException( 'adaptive_state_missing' );
				}
				$rows = $this->repo->batch( $cid, (int) $s['product_cursor'], 100 );
				if ( $wpdb->last_error ) {
					throw new \RuntimeException( 'adaptive_product_read_failed' );
				}
				$ids = array_column( $rows, 'product_id' );
				if ( ! $rows ) {
					$wpdb->update( $state, array( 'product_cursor' => 0 ), array( 'campaign_id' => $cid ) );}
				// Dirty or expired products take priority; rotation discovers missing rows.
				// Backlog must not stall unrelated metrics or validate unprocessed changes.
				$dirty = $wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM %i WHERE campaign_id=%d AND (revision<>validated_revision OR valid_until<=%s OR version<>%d) AND (%d=0 OR revision=validated_revision) ORDER BY product_id LIMIT 500', $wpdb->prefix . 'exin_bf_adaptive_metrics', $cid, gmdate( 'Y-m-d H:i:s' ), $c['version'], (int) $pending ) );
				if ( $wpdb->last_error ) {
					throw new \RuntimeException( 'adaptive_metrics_read_failed' );
				}
				$ids = array_unique( array_merge( array_map( 'intval', $ids ), array_map( 'intval', $dirty ) ) );
				if ( $ids ) {
					$this->publish( $c, $ids, $pending );
				}
				if ( $rows ) {
					$wpdb->update( $state, array( 'product_cursor' => (int) end( $rows )['product_id'] ), array( 'campaign_id' => $cid ) );
				}
			}
		);
	}
	/** Read a fresh Woo order and idempotently replace its checkpoint.
	 *
	 * @param array $c Campaign.
	 * @param int   $id Order ID.
	 * @param bool  $deleted Committed deletion.
	 * @return void Processing result.
	 * @throws \RuntimeException When metadata persistence fails.
	 */
	private function sync( array $c, int $id, bool $deleted ): void {
		global $wpdb;
		// An event can arrive after recovery read the same order earlier in this pass.
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
		$order = $deleted ? false : wc_get_order( $id );
		if ( $order instanceof \WC_Order ) {
			$order->get_data_store()->read( $order );
			if ( ! ( new SmartAdaptive( $this->repo ) )->sync_order( $order, (int) $c['id'] ) ) {
				throw new \RuntimeException( 'adaptive_event_sync_failed' );
			}
		} else {
			( new LockManager() )->run(
				'campaign:' . $c['id'],
				function () use ( $id, $c ) {
					global $wpdb;
					if ( false === $wpdb->delete(
						$wpdb->prefix . 'exin_bf_adaptive_sales',
						array(
							'campaign_id' => $c['id'],
							'order_id'    => $id,
						)
					) ) {
						throw new \RuntimeException( 'adaptive_deleted_order_failed' );
					}}
			);}
	}
	/** Publish a bounded group of validated metrics while the state row is locked.
	 *
	 * @param array $c Campaign.
	 * @param array $ids Validated integer product IDs.
	 * @param bool  $pending Whether unprocessed events still exist.
	 * @return void Processing result.
	 * @throws \RuntimeException When metadata persistence fails.
	 */
	private function publish( array $c, array $ids, bool $pending ): void {
		global $wpdb;
		$now     = time();
		$seconds = $c['settings']['discount_plan']['adaptive']['window_hours'] * 3600;
		$from    = max( strtotime( $c['start_utc'] . ' UTC' ), $now - $seconds );
		$list    = implode( ',', array_map( 'intval', $ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IN list contains database product IDs cast to integers only.
		$sql = $wpdb->prepare( "INSERT INTO %i (campaign_id,product_id,version,units,orders,validated_at,valid_until,validated_revision) SELECT i.campaign_id,i.product_id,%d,LEAST(COALESCE(SUM(s.units),0),1000000),LEAST(COUNT(s.order_id),1000000),%s,LEAST(%s,COALESCE(DATE_ADD(MIN(s.created_at),INTERVAL %d SECOND),%s)),COALESCE(m.revision,1) FROM %i i LEFT JOIN %i s ON s.campaign_id=i.campaign_id AND s.product_id=i.product_id AND s.created_at>=%s AND s.created_at<=%s LEFT JOIN %i m ON m.campaign_id=i.campaign_id AND m.product_id=i.product_id WHERE i.campaign_id=%d AND i.status<>'removed' AND i.product_id IN ($list) AND (%d=0 OR m.product_id IS NULL OR m.revision=m.validated_revision) GROUP BY i.campaign_id,i.product_id,m.revision ON DUPLICATE KEY UPDATE version=VALUES(version),units=VALUES(units),orders=VALUES(orders),validated_at=VALUES(validated_at),valid_until=VALUES(valid_until),validated_revision=VALUES(validated_revision)", $wpdb->prefix . 'exin_bf_adaptive_metrics', $c['version'], gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now + 300 ), $seconds + 1, gmdate( 'Y-m-d H:i:s', $now + 300 ), $wpdb->prefix . 'exin_bf_items', $wpdb->prefix . 'exin_bf_adaptive_sales', gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $now ), $wpdb->prefix . 'exin_bf_adaptive_metrics', $c['id'], (int) $pending );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Complete prepared snapshot statement above.
		if ( false === $wpdb->query( $sql ) ) {
			throw new \RuntimeException( 'adaptive_snapshot_publish_failed' );
		}
	}
	/** A pricing read never scans orders, aggregates sales, enqueues or recovers.
	 *
	 * @param array $c Campaign.
	 * @param int   $pid Product ID.
	 * @return array|null Validated snapshot or unavailable.
	 */
	public function snapshot( array $c, int $pid ): ?array {
		global $wpdb;
		if ( get_option( 'exin_bf_adaptive_error_global' ) || get_option( 'exin_bf_adaptive_error_' . $c['id'] ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT m.units,m.orders,m.validated_at FROM %i m JOIN %i s ON s.campaign_id=m.campaign_id WHERE m.campaign_id=%d AND m.product_id=%d AND m.version=%d AND m.revision=m.validated_revision AND m.valid_until>%s AND s.recovering=0 AND s.signature=%s', $wpdb->prefix . 'exin_bf_adaptive_metrics', $wpdb->prefix . 'exin_bf_adaptive_state', $c['id'], $pid, $c['version'], gmdate( 'Y-m-d H:i:s' ), self::signature( $c ) ), ARRAY_A );
		if ( ! $row || $wpdb->last_error ) {
			return null;
		}
		return array(
			'units'  => (int) $row['units'],
			'orders' => (int) $row['orders'],
			'as_of'  => strtotime( $row['validated_at'] . ' UTC' ),
		);
	}
}
