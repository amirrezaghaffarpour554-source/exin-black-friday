<?php
/**
 * CampaignRepository campaign module.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Infrastructure;

use Exin\BlackFriday\Domain\CurrencyDisplay;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial state must be read fresh; not Woo entities.

/** All SQL is restricted to EXIN-owned tables; WooCommerce entities use CRUD. */
final class CampaignRepository {
	/**
	 * Whether this repository currently owns a database transaction.
	 *
	 * @var bool
	 */
	private bool $in_transaction = false;

	/**
	 * Campaigns whose public selection caches must be invalidated after commit.
	 *
	 * @var array<int,bool>
	 */
	private array $pending_item_changes = array();

	/**
	 * Atomically replace a draft plan using only transactional plugin tables.
	 *
	 * @param callable $callback Operation performed inside the owned transaction.
	 * @return mixed Callback result.
	 * @throws \RuntimeException When transactional storage or commit is unavailable.
	 * Callback failures propagate after all owned writes are rolled back.
	 */
	public function transaction( callable $callback ): mixed {
		global $wpdb;
		if ( $this->in_transaction ) {
			return $callback();
		}
		// A replacement driver may implement its own reconnect/replay policy.
		if ( \wpdb::class !== get_class( $wpdb ) ) {
			throw new \RuntimeException( 'draft_transaction_driver_unsupported' );
		}
		foreach ( array( 'campaigns', 'items', 'audit' ) as $suffix ) {
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $this->table( $suffix ) ) ), ARRAY_A );
			if ( ! $status || 'INNODB' !== strtoupper( (string) $status['Engine'] ) ) {
				throw new \RuntimeException( 'transactional_storage_required' );
			}
		}
		$retries = $wpdb->reconnect_retries;
		if ( ! is_int( $retries ) || $retries < 0 ) {
			throw new \RuntimeException( 'draft_transaction_driver_unsupported' );
		}
		$reporting = error_reporting(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Read the existing mask; do not enable additional diagnostics.
		$started   = false;
		try {
			// wpdb::query() otherwise replays a failed statement on a new,
			// autocommitting connection. The documented compatibility accessors
			// expose this core setting without reflection or replacing wpdb.
			$wpdb->reconnect_retries = 0;
			if ( 0 !== $wpdb->reconnect_retries ) {
				throw new \RuntimeException( 'draft_transaction_driver_unsupported' );
			}
			$connection = (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
			if ( '' === $connection ) {
				throw new \RuntimeException( 'draft_transaction_connection_lost' );
			}
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'draft_transaction_failed' );
			}
			$started                    = true;
			$this->in_transaction       = true;
			$this->pending_item_changes = array();
			$result                     = $callback();
			if ( $connection !== (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) {
				throw new \RuntimeException( 'draft_transaction_connection_lost' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'draft_commit_failed' );
			}
			if ( $connection !== (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) {
				throw new \RuntimeException( 'draft_transaction_connection_lost' );
			}
		} catch ( \Throwable $error ) {
			try {
				// A disconnected server already rolled back its old transaction.
				// Never reconnect merely to issue ROLLBACK on a different session.
				if ( $started && $wpdb->check_connection( false ) ) {
					$wpdb->query( 'ROLLBACK' );
				}
			} catch ( \Throwable $rollback_error ) {
				$this->pending_item_changes = array();
			}
			$this->pending_item_changes = array();
			throw $error;
		} finally {
			$this->in_transaction    = false;
			$wpdb->reconnect_retries = $retries;
			// Core's unsuccessful zero-retry connection probe masks E_WARNING.
			error_reporting( $reporting ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Restore the exact previous mask after core's failed connection probe.
		}
		foreach ( array_keys( $this->pending_item_changes ) as $campaign_id ) {
			do_action( 'exin_bf_item_changed', $campaign_id, 0 );
		}
		$this->pending_item_changes = array();
		return $result;
	}

	/**
	 * Defer listing-cache invalidation until an owned transaction is committed.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @param int $product_id Changed product identifier, or zero for a batch.
	 * @return void
	 */
	private function item_changed( int $campaign_id, int $product_id ): void {
		if ( $this->in_transaction ) {
			$this->pending_item_changes[ $campaign_id ] = true;
			return;
		}
		do_action( 'exin_bf_item_changed', $campaign_id, $product_id );
	}
	/**
	 * Build the name of a plugin-owned database table.
	 *
	 * @param string $name Plugin table suffix or advisory lock name.
	 *
	 * @return string Operation result.
	 */
	private function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'exin_bf_' . $name;
	}

	/**
	 * Create a draft campaign and return its identifier.
	 *
	 * @param array $data Allowlisted record fields.
	 *
	 * @return int Operation result.
	 * @throws \RuntimeException When the plugin-owned database write fails.
	 */
	public function create( array $data ): int {
		global $wpdb;
		$now                = gmdate( 'Y-m-d H:i:s' );
		$defaults           = array(
			'title'      => __( 'بلک فرایدی اکسین', 'exin-black-friday' ),
			'start_utc'  => '2026-11-26 20:30:00',
			'end_utc'    => '2026-12-03 20:30:00',
			'timezone'   => 'Asia/Tehran',
			'days'       => 7,
			'strategy'   => 'linear',
			'power'      => 2,
			'rounding'   => 'half_up',
			'status'     => 'draft',
			'version'    => 1,
			'settings'   => array(
				'free_shipping'        => false,
				'shipping_threshold'   => '0',
				'shipping_subsidy_cap' => CurrencyDisplay::default_subsidy_cap( get_woocommerce_currency(), wc_get_price_decimals() ),
				'shipping_instances'   => array(),
				'inactive_output'      => 'message',
				'order_statuses'       => array( 'processing', 'completed' ),
				'retention_days'       => 90,
				'currency'             => get_woocommerce_currency(),
				'precision'            => wc_get_price_decimals(),
			),
			'created_at' => $now,
			'updated_at' => $now,
			'last_error' => '',
		);
		$record             = array_replace( $defaults, $data );
		$record['settings'] = array_replace( $defaults['settings'], (array) ( $data['settings'] ?? array() ) );
		$row                = $this->campaign_data( $record );
		if ( false === $wpdb->insert( $this->table( 'campaigns' ), $row ) ) {
			throw new \RuntimeException( 'campaign_create_failed' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Read the latest durable state for one campaign.
	 *
	 * @param int $id Record identifier.
	 *
	 * @return array|null Operation result.
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $this->table( 'campaigns' ), $id ), ARRAY_A );
		return $row ? $this->decode_campaign( $row ) : null;
	}

	/**
	 * Read the most recently created campaign.
	 *
	 * @return ?array Operation result.
	 */
	public function current(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', $this->table( 'campaigns' ) ), ARRAY_A );
		return $row ? $this->decode_campaign( $row ) : null;
	}

	/**
	 * List campaigns in reverse creation order.
	 *
	 * @param int $limit Maximum number of records.
	 *
	 * @return array Operation result.
	 */
	public function all( int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $this->table( 'campaigns' ), min( 100, max( 1, $limit ) ) ), ARRAY_A );
		return array_map( array( $this, 'decode_campaign' ), $rows );
	}

	/**
	 * Read campaigns that require scheduler supervision.
	 *
	 * @return array Operation result.
	 */
	public function runnable(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status IN ('scheduled','active','paused') ORDER BY id ASC", $this->table( 'campaigns' ) ), ARRAY_A );
		return array_map( array( $this, 'decode_campaign' ), $rows );
	}

	/**
	 * Persist allowlisted campaign fields.
	 *
	 * @param int   $id Record identifier.
	 * @param array $data Allowlisted record fields.
	 *
	 * @return void Operation result.
	 * @throws \RuntimeException When the plugin-owned database write fails.
	 */
	public function save( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		if ( false === $wpdb->update( $this->table( 'campaigns' ), $this->campaign_data( $data ), array( 'id' => $id ) ) ) {
			throw new \RuntimeException( 'campaign_save_failed' );
		}
	}

	/**
	 * Read a bounded page of campaign product records.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @param int $limit Maximum number of records.
	 * @param int $offset Number of records to skip.
	 *
	 * @return array Operation result.
	 */
	public function items( int $campaign_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d AND status<>%s ORDER BY featured DESC, priority ASC, product_id ASC LIMIT %d OFFSET %d', $this->table( 'items' ), $campaign_id, 'removed', min( 1000, max( 1, $limit ) ), max( 0, $offset ) ), ARRAY_A );
		return array_map( array( $this, 'decode_item' ), $rows );
	}

	/**
	 * Traverse campaign products with a stable product identifier cursor.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @param int $after Exclusive product identifier cursor.
	 * @param int $limit Maximum number of records.
	 *
	 * @return array Operation result.
	 */
	public function batch( int $campaign_id, int $after = 0, int $limit = 25 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d AND product_id>%d ORDER BY product_id ASC LIMIT %d', $this->table( 'items' ), $campaign_id, $after, min( 100, max( 1, $limit ) ) ), ARRAY_A );
		return array_map( array( $this, 'decode_item' ), $rows );
	}

	/**
	 * Read one campaign product and its ownership state.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @param int $product_id Sellable product or variation identifier.
	 *
	 * @return array|null Operation result.
	 */
	public function item( int $campaign_id, int $product_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d AND product_id=%d', $this->table( 'items' ), $campaign_id, $product_id ), ARRAY_A );
		return $row ? $this->decode_item( $row ) : null;
	}

	/**
	 * Check whether another campaign already claims the product.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @param int $product_id Sellable product or variation identifier.
	 *
	 * @return bool Operation result.
	 */
	public function owned_elsewhere( int $campaign_id, int $product_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT product_id FROM %i WHERE product_id=%d AND campaign_id<>%d AND status NOT IN ('completed','removed') LIMIT 1", $this->table( 'items' ), $product_id, $campaign_id ) );
	}

	/**
	 * Check whether financial WooCommerce saves need serialization.
	 *
	 * @param int $product_id Sellable product or variation identifier.
	 *
	 * @return bool Operation result.
	 */
	public function guards_product( int $product_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT product_id FROM %i WHERE product_id=%d AND status NOT IN ('completed','removed') LIMIT 1", $this->table( 'items' ), $product_id ) );
	}

	/**
	 * Persist allowlisted product state and durable ownership metadata.
	 *
	 * @param int   $campaign_id Campaign identifier.
	 * @param int   $product_id Sellable product or variation identifier.
	 * @param array $data Allowlisted record fields.
	 *
	 * @return void Operation result.
	 * @throws \RuntimeException When the plugin-owned database write fails.
	 */
	public function put_item( int $campaign_id, int $product_id, array $data ): void {
		global $wpdb;
		$old                 = $this->item( $campaign_id, $product_id );
		$allowed             = array( 'base_price', 'floor_price', 'schedule', 'snapshot', 'expected', 'intent', 'retry_state', 'status', 'operation_id', 'conflict', 'last_changed', 'priority', 'featured' );
		$row                 = array_intersect_key( $data, array_flip( $allowed ) );
		$row['last_changed'] = gmdate( 'Y-m-d H:i:s' );
		foreach ( array( 'schedule', 'snapshot', 'expected', 'intent', 'retry_state' ) as $field ) {
			if ( array_key_exists( $field, $row ) && null !== $row[ $field ] ) {
				$row[ $field ] = wp_json_encode( $row[ $field ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
			}
		}
		if ( $old ) {
			$ok = $wpdb->update(
				$this->table( 'items' ),
				$row,
				array(
					'campaign_id' => $campaign_id,
					'product_id'  => $product_id,
				)
			);
		} else {
			$row += array(
				'campaign_id'  => $campaign_id,
				'product_id'   => $product_id,
				'base_price'   => '',
				'floor_price'  => '',
				'schedule'     => '[]',
				'snapshot'     => '{}',
				'expected'     => null,
				'intent'       => null,
				'retry_state'  => null,
				'status'       => 'included',
				'operation_id' => '',
				'conflict'     => '',
				'priority'     => 0,
				'featured'     => 0,
			);
			$ok   = $wpdb->insert( $this->table( 'items' ), $row );
		}
		if ( false === $ok ) {
			throw new \RuntimeException( 'item_save_failed' );
		}
		$changed = ! $old;
		foreach ( array( 'featured', 'priority', 'status', 'base_price', 'floor_price', 'schedule' ) as $field ) {
			if ( array_key_exists( $field, $data ) && ( ! $old || $old[ $field ] !== $data[ $field ] ) ) {
				$changed = true;
			}
		}
		if ( $changed ) {
			/** Selected catalog data changed; financial retry-only writes do not invalidate listing caches. */
			$this->item_changed( $campaign_id, $product_id );
		}
	}

	/**
	 * Delete one plugin-owned selection record.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @param int $product_id Sellable product or variation identifier.
	 *
	 * @return void Operation result.
	 * @throws \RuntimeException When the plugin-owned database write fails.
	 */
	public function delete_item( int $campaign_id, int $product_id ): void {
		global $wpdb;
		if ( false === $wpdb->delete(
			$this->table( 'items' ),
			array(
				'campaign_id' => $campaign_id,
				'product_id'  => $product_id,
			)
		) ) {
			throw new \RuntimeException( 'item_delete_failed' );
		}
		$this->item_changed( $campaign_id, $product_id );
	}

	/**
	 * Count campaign products excluding removed records.
	 *
	 * @param int $campaign_id Campaign identifier.
	 *
	 * @return int Operation result.
	 */
	public function count_items( int $campaign_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE campaign_id=%d AND status<>'removed'", $this->table( 'items' ), $campaign_id ) );
	}

	/**
	 * Annotate a bounded picker page without loading every campaign item.
	 *
	 * @param int   $campaign_id Campaign whose existing selections are requested.
	 * @param int[] $candidate_ids At most one bounded set of catalog identifiers.
	 * @return int[] Candidates already enrolled and not removed.
	 */
	public function member_product_ids( int $campaign_id, array $candidate_ids ): array {
		global $wpdb;
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $candidate_ids ) ) ) ), 0, 1000 );
		if ( ! $ids || $campaign_id < 1 ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = 'SELECT product_id FROM %i WHERE campaign_id=%d AND status<>%s AND product_id IN (' . $placeholders . ')';
		$values       = array_merge( array( $this->table( 'items' ), $campaign_id, 'removed' ), $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only generated integer placeholders are appended; table and every value are prepared.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $values ) ) );
	}

	/**
	 * Allowlist and encode campaign fields for persistence.
	 *
	 * @param array $data Allowlisted record fields.
	 *
	 * @return array Operation result.
	 */
	private function campaign_data( array $data ): array {
		$allowed = array( 'title', 'start_utc', 'end_utc', 'timezone', 'days', 'strategy', 'power', 'rounding', 'status', 'version', 'settings', 'created_at', 'updated_at', 'last_success', 'last_error' );
		$data    = array_intersect_key( $data, array_flip( $allowed ) );
		if ( isset( $data['settings'] ) ) {
			unset( $data['settings']['target_orders'], $data['settings']['show_out_of_stock'] );
			$data['settings'] = wp_json_encode( $data['settings'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		}
		return $data;
	}

	/**
	 * Decode persisted campaign settings and numeric fields.
	 *
	 * @param array $row Persisted database record.
	 *
	 * @return array Operation result.
	 */
	public function decode_campaign( array $row ): array {
		$settings        = json_decode( $row['settings'] ?? '{}', true );
		$row['settings'] = is_array( $settings ) ? $settings : array();
		foreach ( array( 'id', 'days', 'power', 'version' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		return $row;
	}

	/**
	 * Decode persisted product snapshots and numeric fields.
	 *
	 * @param array $row Persisted database record.
	 *
	 * @return array Operation result.
	 */
	public function decode_item( array $row ): array {
		foreach ( array( 'schedule', 'snapshot', 'expected', 'intent', 'retry_state' ) as $field ) {
			$row[ $field ] = null === ( $row[ $field ] ?? null ) ? null : json_decode( $row[ $field ], true, 512, JSON_THROW_ON_ERROR );
		}
		foreach ( array( 'campaign_id', 'product_id', 'priority', 'featured' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		return $row;
	}
}
