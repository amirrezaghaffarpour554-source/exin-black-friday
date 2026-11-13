<?php
/**
 * CampaignRepository campaign module.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Infrastructure;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial state must be read fresh; not Woo entities.

/** All SQL is restricted to EXIN-owned tables; WooCommerce entities use CRUD. */
final class CampaignRepository {
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
		$now      = gmdate( 'Y-m-d H:i:s' );
		$defaults = array(
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
				'free_shipping'      => false,
				'shipping_threshold' => '0',
				'shipping_instances' => array(),
				'show_out_of_stock'  => false,
				'inactive_output'    => 'message',
				'order_statuses'     => array( 'processing', 'completed' ),
				'target_orders'      => 500,
				'retention_days'     => 90,
			),
			'created_at' => $now,
			'updated_at' => $now,
			'last_error' => '',
		);
		$row      = $this->campaign_data( array_replace( $defaults, $data ) );
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
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d AND status<>%s ORDER BY priority ASC, product_id ASC LIMIT %d OFFSET %d', $this->table( 'items' ), $campaign_id, 'removed', min( 1000, max( 1, $limit ) ), max( 0, $offset ) ), ARRAY_A );
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
