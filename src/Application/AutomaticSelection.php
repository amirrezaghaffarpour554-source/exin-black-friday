<?php
/**
 * Immutable stage cohorts for the existing discount plan and scheduler.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Application;

use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\LockManager;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Indexed plugin-owned stage ledger, always read fresh.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal SQL fragments contain prepared identifiers/values and validated integer limits only.

/** Cohort metadata only: no product writes, snapshots, scheduler or pricing engine. */
final class AutomaticSelection {
	/**
	 * Query fragments for the current preview page.
	 *
	 * @var array
	 */
	private array $queries = array();
	/**
	 * Receive existing campaign storage.
	 *
	 * @param CampaignRepository $repo Campaign repository.
	 */
	public function __construct( private CampaignRepository $repo ) {}

	/**
	 * Resolve a deterministic selection, freezing the current stage only on execution.
	 *
	 * @param array $campaign Server campaign.
	 * @param int   $product_id Member identifier.
	 * @param int   $stage Actual stage.
	 * @param bool  $freeze Whether execution may persist its cohort.
	 * @return bool Whether this product has the special turn.
	 */
	public function selected( array $campaign, int $product_id, int $stage, bool $freeze = true ): bool {
		global $wpdb;
		$mode = $campaign['settings']['discount_plan']['mode'];
		if ( ! in_array( $mode, array( 'auto_rotation', 'auto_random', 'auto_hybrid' ), true ) ) {
			return true; }
		$table   = $wpdb->prefix . 'exin_bf_turns';
		$id      = (int) $campaign['id'];
		$version = (int) $campaign['version'];
		$marker  = $wpdb->prepare( 'SELECT product_id FROM %i WHERE campaign_id=%d AND version=%d AND stage=%d AND product_id=0', $table, $id, $version, $stage );
		if ( $freeze && null === $wpdb->get_var( $marker ) ) {
			( new LockManager() )->run(
				'campaign:' . $id,
				function () use ( $campaign, $stage, $marker, $table, $id, $version ): void {
					global $wpdb;
					if ( null !== $wpdb->get_var( $marker ) ) {
						return; }
					$current = $this->repo->get( $id );
					if ( ! $current || 'active' !== $current['status'] || $version !== $current['version'] || DiscountPlan::index( $current, time() ) !== $stage || ! empty( $current['settings']['restoring'] ) ) {
						throw new \DomainException( 'automatic_stage_changed' ); }
					$selects = $this->cohort_queries( $current, $stage );
					$this->repo->transaction(
						function () use ( $selects, $table, $id, $version, $stage ): void {
							global $wpdb;
							foreach ( $selects as $sql ) {
									$insert = $wpdb->prepare( 'INSERT INTO %i (campaign_id,version,stage,product_id) SELECT %d,%d,%d,turn_product.product_id FROM (', $table, $id, $version, $stage ) . $sql . ') turn_product';
								if ( false === $wpdb->query( $insert ) ) {
									throw new \RuntimeException( 'automatic_cohort_write_failed' ); }
							}
							if ( false === $wpdb->insert(
								$table,
								array(
									'campaign_id' => $id,
									'version'     => $version,
									'stage'       => $stage,
									'product_id'  => 0,
								)
							) ) {
								throw new \RuntimeException( 'automatic_cohort_marker_failed' ); }
						}
					);
				}
			);
		}
		if ( null !== $wpdb->get_var( $marker ) ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT product_id FROM %i WHERE campaign_id=%d AND version=%d AND stage=%d AND product_id=%d', $table, $id, $version, $stage, $product_id ) ); }
		foreach ( $this->cohort_queries( $campaign, $stage ) as $sql ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT product_id FROM (' . $sql . ') chosen WHERE product_id=%d', $product_id ) ) ) {
				return true; }
		}
		return false;
	}

	/**
	 * Read actual earlier special participation for the last-day remaining policy.
	 *
	 * @param array $campaign Campaign.
	 * @param int   $product_id Product.
	 * @param int   $stage Current stage.
	 * @return bool Whether no earlier special price was acknowledged.
	 */
	public function remaining( array $campaign, int $product_id, int $stage ): bool {
		global $wpdb;
		// The remaining set is fixed at the beginning of the last day, not depleted
		// differently depending on whether its floor price came from a special slot.
		$start = max( strtotime( $campaign['start_utc'] . ' UTC' ), strtotime( $campaign['end_utc'] . ' UTC' ) - DAY_IN_SECONDS );
		$stage = min( $stage, DiscountPlan::index( $campaign, $start ) );
		return ! $wpdb->get_var( $wpdb->prepare( 'SELECT product_id FROM %i WHERE campaign_id=%d AND version=%d AND product_id=%d AND stage<%d AND applied=1 LIMIT 1', $wpdb->prefix . 'exin_bf_turns', $campaign['id'], $campaign['version'], $product_id, $stage ) );
	}

	/**
	 * Acknowledge a successful special price; no customer or financial snapshot data.
	 *
	 * @throws \RuntimeException When a turn acknowledgment cannot be stored.
	 *
	 * @param array $campaign Campaign.
	 * @param int   $product_id Product.
	 * @param int   $stage Applied stage.
	 * @return void
	 */
	public function applied( array $campaign, int $product_id, int $stage ): void {
		global $wpdb;
		if ( false === $wpdb->update(
			$wpdb->prefix . 'exin_bf_turns',
			array( 'applied' => 1 ),
			array(
				'campaign_id' => $campaign['id'],
				'version'     => $campaign['version'],
				'stage'       => $stage,
				'product_id'  => $product_id,
			)
		) ) {
			throw new \RuntimeException( 'automatic_turn_ack_failed' ); }
	}

	/**
	 * Bound flash work and total cohort storage before activation, without benchmarks.
	 *
	 * @throws \DomainException When interval or storage budgets are exceeded.
	 *
	 * @param array $campaign Campaign.
	 * @param int   $count Valid member count.
	 * @return void
	 */
	public function capacity( array $campaign, int $count ): void {
		$p       = $campaign['settings']['discount_plan'];
		$minimum = max( 5, (int) apply_filters( 'exin_bf_flash_min_minutes', 5 ), (int) ceil( ceil( $count / 25 ) * max( 1, (int) apply_filters( 'exin_bf_auto_batch_seconds', 20 ) ) * 2 / 60 ) );
		if ( 'flash' === $p['interval'] && $p['interval_minutes'] < $minimum ) {
			throw new \DomainException( 'automatic_flash_capacity_exceeded' ); }
		$size = 'count' === $p['selection_mode'] ? min( $count, $p['selection_value'] ) : (int) ceil( $count * $p['selection_value'] / 100 );
		if ( in_array( $p['mode'], array( 'auto_rotation', 'auto_random', 'auto_hybrid' ), true ) && ( $size + 1 ) * DiscountPlan::stage_count( $campaign ) > 250000 ) {
			throw new \DomainException( 'automatic_cohort_capacity_exceeded' ); }
	}

	/**
	 * Preview ten members and twelve future stages without saving prices or cohorts.
	 *
	 * @param array $campaign Campaign.
	 * @param int   $page One-based product page.
	 * @param int   $stage_start First stage on this page.
	 * @return array Manager-only preview data.
	 */
	public function preview( array $campaign, int $page, int $stage_start ): array {
		global $wpdb;
		$count       = DiscountPlan::stage_count( $campaign );
		$boundaries  = array();
		$page        = max( 1, $page );
		$stage_start = max( 0, min( $count - 1, $stage_start ) );
		$rows        = $this->repo->items( $campaign['id'], 10, ( $page - 1 ) * 10 );
		$ids         = array_column( $rows, 'product_id' );
		$samples     = array();
		$selections  = array();
		$stage_end   = min( $count, $stage_start + 12 );
		for ( $stage = $stage_start; $stage < $stage_end; ++$stage ) {
			$boundaries[]         = DiscountPlan::stage_start( $campaign, $stage );
			$selections[ $stage ] = array();
			if ( ! $ids || ! in_array( $campaign['settings']['discount_plan']['mode'], array( 'auto_rotation', 'auto_random', 'auto_hybrid' ), true ) ) {
				continue; }
			$list   = implode( ',', array_map( 'intval', $ids ) );
			$marker = $wpdb->get_var( $wpdb->prepare( 'SELECT product_id FROM %i WHERE campaign_id=%d AND version=%d AND stage=%d AND product_id=0', $wpdb->prefix . 'exin_bf_turns', $campaign['id'], $campaign['version'], $stage ) );
			if ( null !== $marker ) {
				$selections[ $stage ] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM %i WHERE campaign_id=%d AND version=%d AND stage=%d AND product_id IN ($list)", $wpdb->prefix . 'exin_bf_turns', $campaign['id'], $campaign['version'], $stage ) ) ); } else {
				foreach ( $this->cohort_queries( $campaign, $stage ) as $sql ) {
					$selections[ $stage ] = array_merge( $selections[ $stage ], array_map( 'intval', $wpdb->get_col( "SELECT product_id FROM ($sql) chosen WHERE product_id IN ($list)" ) ) ); }
				}
		}
		$rejected = 0;
		foreach ( $rows as $item ) {
			$product = wc_get_product( $item['product_id'] );
			if ( ! $product || in_array( $item['status'], array( 'removed', 'conflict', 'paused', 'completed' ), true ) ) {
				++$rejected;
				continue; }
			$stock_owner = wc_get_product( $product->get_stock_managed_by_id() );
			$in_stock    = 'instock' === $product->get_stock_status() && $stock_owner && ( ! $stock_owner->managing_stock() || ( null !== $stock_owner->get_stock_quantity() && $stock_owner->get_stock_quantity() > 0 ) );
			$schedule    = array();
			$details     = array();
			try {
				foreach ( $selections as $stage => $selected_ids ) {
					$selected   = ! in_array( $campaign['settings']['discount_plan']['mode'], array( 'auto_rotation', 'auto_random', 'auto_hybrid' ), true ) || in_array( $item['product_id'], $selected_ids, true );
					$remaining  = 'remaining_floor' === $campaign['settings']['discount_plan']['last_day'] ? $this->remaining( $campaign, $item['product_id'], $stage ) : true;
					$price      = DiscountPlan::automatic_price( $item['base_price'], $item['floor_price'], $campaign, $stage, $item['product_id'], $selected, $remaining, $in_stock );
					$schedule[] = $price;
					$details[]  = array(
						'stage'            => $stage,
						'starts_at'        => DiscountPlan::stage_start( $campaign, $stage ),
						'price'            => $price,
						'discount_percent' => ( new \Exin\BlackFriday\Domain\PricingEngine() )->discount_percent( $item['base_price'], $price, (int) $campaign['settings']['precision'], 2 ),
						'special'          => $selected,
					);
				}
				$samples[] = array(
					'product_id'               => $item['product_id'],
					'name'                     => $product->get_name(),
					'base_price'               => $item['base_price'],
					'floor_price'              => $item['floor_price'],
					'maximum_discount_percent' => ( new \Exin\BlackFriday\Domain\PricingEngine() )->discount_percent( $item['base_price'], $item['floor_price'], (int) $campaign['settings']['precision'], 2 ),
					'schedule'                 => $schedule,
					'details'                  => $details,
				);
			} catch ( \InvalidArgumentException $error ) {
				++$rejected; }
		}
		$total = $this->repo->count_items( $campaign['id'] );
		return array(
			'count'            => $total,
			'page'             => $page,
			'has_more'         => $page * 10 < $total,
			'stage_start'      => $stage_start,
			'total_stages'     => $count,
			'next_stage'       => $stage_start + 12 < $count ? $stage_start + 12 : null,
			'samples'          => $samples,
			'rejected_on_page' => $rejected,
			'warnings'         => array( __( 'پیش‌نمایش بر پایهٔ موجودی و اعضای فعلی است؛ Seed ثابت است. تغییر موجودی یا اعضا می‌تواند نوبت‌های آینده را تغییر دهد؛ نوبت اجراشده دوباره قرعه‌کشی نمی‌شود.', 'exin-black-friday' ) ),
			'start_utc'        => $campaign['start_utc'],
			'end_utc'          => $campaign['end_utc'],
			'stages'           => $boundaries,
		);
	}


	/**
	 * Build at most two bounded SQL slices; never load all product identifiers in PHP.
	 *
	 * @param array $campaign Campaign.
	 * @param int   $stage Stage.
	 * @return array Prepared SELECT statements.
	 */
	private function cohort_queries( array $campaign, int $stage ): array {
		global $wpdb;
		$key = $campaign['id'] . ':' . $campaign['version'] . ':' . $stage;
		if ( isset( $this->queries[ $key ] ) ) {
			return $this->queries[ $key ]; }
		$p      = $campaign['settings']['discount_plan'];
		$posts  = $wpdb->posts;
		$meta   = $wpdb->postmeta;
		$items  = $wpdb->prefix . 'exin_bf_items';
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		$owner  = "CASE WHEN q.post_type='product_variation' AND NOT EXISTS (SELECT 1 FROM $meta own_m WHERE own_m.post_id=q.ID AND own_m.meta_key='_manage_stock' AND own_m.meta_value='yes') AND EXISTS (SELECT 1 FROM $meta parent_m WHERE parent_m.post_id=q.post_parent AND parent_m.meta_key='_manage_stock' AND parent_m.meta_value='yes') THEN q.post_parent ELSE q.ID END";
		$source = $wpdb->prepare( " FROM %i i INNER JOIN %i q ON q.ID=i.product_id INNER JOIN %i l ON l.product_id=i.product_id WHERE i.campaign_id=%d AND i.status IN ('included','pending','active','sold_out') AND q.post_status='publish' AND l.stock_status='instock' AND CAST(i.floor_price AS DECIMAL(30,8))>0 AND CAST(i.floor_price AS DECIMAL(30,8))<CAST(i.base_price AS DECIMAL(30,8)) AND (q.post_type='product' OR EXISTS (SELECT 1 FROM $posts parent WHERE parent.ID=q.post_parent AND parent.post_status='publish')) AND (NOT EXISTS (SELECT 1 FROM $meta m WHERE m.post_id=($owner) AND m.meta_key='_manage_stock' AND m.meta_value='yes') OR EXISTS (SELECT 1 FROM $meta s WHERE s.post_id=($owner) AND s.meta_key='_stock' AND CAST(s.meta_value AS DECIMAL(30,8))>0)) AND NOT EXISTS (SELECT 1 FROM $items o WHERE o.product_id=i.product_id AND o.campaign_id<>i.campaign_id AND ((o.expected IS NOT NULL AND o.expected<>'null') OR (o.intent IS NOT NULL AND o.intent<>'null')))", $items, $posts, $lookup, $campaign['id'] );
		$total  = (int) $wpdb->get_var( 'SELECT COUNT(*)' . $source );
		if ( ! $total ) {
			$this->queries[ $key ] = array();
			return array(); }
		$size   = min( $total, 'count' === $p['selection_mode'] ? $p['selection_value'] : (int) ceil( $total * $p['selection_value'] / 100 ) );
		$random = 'auto_random' === $p['mode'] || ( 'auto_hybrid' === $p['mode'] && 'random' === $p['selector'] );
		$hash   = $wpdb->prepare( 'SHA2(CONCAT(%s,i.product_id),256)', $p['seed'] . ':' . ( $random ? $stage : 'rotation' ) . ':' );
		$order  = $random ? $hash . ',i.product_id' : 'i.featured DESC,i.priority ASC,' . $hash . ',i.product_id';
		// Actual rotations prefer the least-served eligible members, including after stock churn.
		// Future preview uses the equivalent circular order for an unchanged eligible catalog.
		$actual_rotation = ! $random && $stage <= DiscountPlan::index( $campaign, time() );
		if ( $actual_rotation ) {
			$turns = $wpdb->prefix . 'exin_bf_turns';
			$order = $wpdb->prepare( '(SELECT COUNT(*) FROM %i history WHERE history.campaign_id=i.campaign_id AND history.version=%d AND history.product_id=i.product_id AND history.stage<%d AND history.applied=1) ASC,', $turns, $campaign['version'], $stage ) . $order;
		}
		$offset  = $random || $actual_rotation ? 0 : ( $stage * $size ) % $total;
		$first   = min( $size, $total - $offset );
		$queries = array( "SELECT i.product_id $source ORDER BY $order LIMIT $first OFFSET $offset" );
		if ( $first < $size ) {
			$remaining = $size - $first;
			$queries[] = "SELECT i.product_id $source ORDER BY $order LIMIT $remaining"; }
		$this->queries[ $key ] = $queries;
		return $queries;
	}
}
