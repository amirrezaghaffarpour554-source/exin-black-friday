<?php
/**
 * Explicit new-product enrollment policy, using existing safe selection.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Integration;

use Exin\BlackFriday\Application\CatalogProductQuery;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\Scheduler;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Infrastructure\LockManager;

/** No upgrade sweep: only newly published products generate work. */
final class NewProducts {
	/**
	 * Receive the campaign repository.
	 *
	 * @param CampaignRepository $repo Repo.
	 */
	public function __construct( private CampaignRepository $repo ) {}
	/**
	 * Register the WooCommerce integration hooks.
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'published' ), 30, 3 );
		add_action( 'exin_bf_new_product', array( $this, 'process' ) );
	}
	/**
	 * Queue opted-in enrollment after first publication.
	 *
	 * @param string $new_status New.
	 * @param string $old Old.
	 * @param mixed  $post Post.
	 */
	public function published( string $new_status, string $old, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return; }
		foreach ( $this->repo->runnable() as $campaign ) {
			if ( $this->accepts_products( $campaign ) && 'exclude' !== ( $campaign['settings']['new_products'] ?? 'exclude' ) ) {
				as_enqueue_async_action( 'exin_bf_new_product', array( (int) $post->ID ), Scheduler::GROUP, true );
				break; }
		}
	}
	/**
	 * Read reusable enrollment rules without modifying legacy settings.
	 *
	 * @param array $campaign Campaign.
	 */
	public function rules( array $campaign ): ?array {
		$rules = $campaign['settings']['new_product_rules'] ?? ( $campaign['settings']['selection_rules'] ?? null );
		if ( ! $rules ) {
			$job = get_option( 'exin_bf_bulk_' . $campaign['id'], array() );
			if ( ! empty( $job['filters'] ) ) {
				$rules = array(
					'filters' => $job['filters'],
					'policy'  => $job['policy'],
				); }
		}
		return is_array( $rules ) ? $rules : null;
	}
	/**
	 * Process one new product using explicit campaign enrollment policies.
	 *
	 * @param int $product_id Product id.
	 */
	public function process( int $product_id ): void {
		foreach ( $this->repo->runnable() as $campaign ) {
			$mode = $campaign['settings']['new_products'] ?? 'exclude';
			if ( 'exclude' === $mode || ! $this->accepts_products( $campaign ) ) {
				continue; }
			$rules   = $this->rules( $campaign );
			$filters = $rules['filters'] ?? array( 'scope' => 'instock' );
			if ( ! ( new CatalogProductQuery() )->matches( $product_id, $filters ) || $this->repo->item( $campaign['id'], $product_id ) ) {
				continue; }
			if ( 'manual' === $mode ) {
				( new LockManager() )->run(
					'campaign:' . $campaign['id'],
					function () use ( $campaign, $product_id ): void {
						$current = $this->repo->get( $campaign['id'] );
						if ( $this->accepts_products( $current ) && 'manual' === ( $current['settings']['new_products'] ?? 'exclude' ) ) {
							add_option( $this->key( $current['id'], $product_id ), time(), '', false );
						}
					}
				); } elseif ( $rules ) {
				try {
					$this->enroll( $campaign, $product_id, $rules );
				} catch ( \Throwable $error ) {
					( new Logger() )->log(
						'error',
						'new_product_rejected',
						array(
							'campaign_id' => $campaign['id'],
							'product_id'  => $product_id,
							'error_code'  => 'new_product_enrollment_failed',
						)
					); }
				}
		}
	}
	/**
	 * Retrieve one bounded page of pending manager approvals.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $page Page.
	 */
	public function pending( int $campaign_id, int $page = 1 ): array {
		global $wpdb;
		$prefix = 'exin_bf_new_' . $campaign_id . '_';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned, indexed option-name prefix; only one small approval page is read.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 26 OFFSET %d', $wpdb->options, $wpdb->esc_like( $prefix ) . '%', ( max( 1, $page ) - 1 ) * 25 ) );
		$rows  = array();
		foreach ( array_slice( $names, 0, 25 ) as $name ) {
			$id      = (int) substr( $name, strlen( $prefix ) );
			$product = wc_get_product( $id );
			if ( $product ) {
				$rows[] = array(
					'id'           => $id,
					'name'         => $product->get_name(),
					'price'        => $product->get_price(),
					'stock_status' => $product->get_stock_status(),
				); }
		}
		return array(
			'products' => $rows,
			'page'     => $page,
			'has_more' => count( $names ) > 25,
		);
	}
	/**
	 * Approve or reject a bounded set of pending products.
	 *
	 * @throws \DomainException When enrollment is disabled or the campaign is not editable.
	 *
	 * @param int   $campaign_id Campaign id.
	 * @param array $ids Ids.
	 * @param bool  $accept Accept.
	 */
	public function approve( int $campaign_id, array $ids, bool $accept ): array {
		$campaign = $this->repo->get( $campaign_id );
		$rules    = $campaign ? $this->rules( $campaign ) : null;
		if ( $accept && $campaign && 'exclude' === ( $campaign['settings']['new_products'] ?? 'exclude' ) ) {
			throw new \DomainException( 'new_product_enrollment_disabled' ); }
		if ( ! $campaign || ! in_array( $campaign['status'], array( 'draft', 'scheduled', 'active', 'paused' ), true ) || ( $accept && ! $this->accepts_products( $campaign ) ) || count( $ids ) > 25 ) {
			throw new \DomainException( 'invalid_new_product_approval' ); }
		$results = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! get_option( $this->key( $campaign_id, $id ) ) ) {
				continue; }
			try {
				if ( $accept ) {
					if ( ! $rules ) {
						throw new \DomainException( 'selection_rules_required' );
					} $this->enroll( $campaign, $id, $rules ); }
				delete_option( $this->key( $campaign_id, $id ) );
				$results[] = array(
					'product_id' => $id,
					'ok'         => true,
				);
			} catch ( \Throwable $error ) {
				$results[] = array(
					'product_id' => $id,
					'ok'         => false,
				); }
		}
		return array( 'results' => $results );
	}
	/**
	 * Enroll safely under the campaign lock and queue existing price processing.
	 *
	 * @param array $campaign Campaign.
	 * @param int   $id Id.
	 * @param array $rules Rules.
	 */
	private function enroll( array $campaign, int $id, array $rules ): void {
		( new LockManager() )->run(
			'campaign:' . $campaign['id'],
			function () use ( $campaign, $id, $rules ): void {
				$matches = ( new CatalogProductQuery() )->matches( $id, $rules['filters'] );
				// Matching may cross the deadline; check fresh state immediately before enrollment.
				$current = $this->repo->get( $campaign['id'] );
				if ( ! $this->accepts_products( $current ) || 'exclude' === ( $current['settings']['new_products'] ?? 'exclude' ) || ! $matches ) {
					throw new \DomainException( 'invalid_new_product_approval' ); }
				if ( ! $this->repo->item( $current['id'], $id ) ) {
					( new ProductSelectionService( $this->repo ) )->add( $current['id'], $id, '0', array( 'floor_policy' => $rules['policy'] ) ); }
				if ( 'active' === $current['status'] && CampaignContext::is_live( $current ) ) {
					$current  = $this->repo->get( $current['id'] );
					$settings = $current['settings'];
					$run      = $settings['last_run'] ?? array();
					if ( (int) ( $run['version'] ?? -1 ) === $current['version'] && 'apply' === ( $run['mode'] ?? '' ) && ! isset( $run['pending_results'][ $id ] ) ) {
						$run['pending_results'][ $id ] = 'skipped';
						$run['skipped']                = (int) ( $run['skipped'] ?? 0 ) + 1;
						$settings['last_run']          = $run;
						$this->repo->save( $current['id'], array( 'settings' => $settings ) ); }
					if ( ! function_exists( 'as_enqueue_async_action' ) || ! as_enqueue_async_action( 'exin_bf_retry', array( $current['id'], $id, $current['version'], 'apply', 0 ), Scheduler::GROUP, true ) ) {
						( new Logger() )->log(
							'warning',
							'new_product_queue_deferred',
							array(
								'campaign_id' => $current['id'],
								'product_id'  => $id,
								'result'      => 'watchdog_recovery',
							)
						); }
				}
			}
		);
	}


	/**
	 * Fail closed for terminal, restoring, invalid-date or expired campaigns.
	 *
	 * @param array|null $campaign Fresh campaign state.
	 * @return bool Whether new membership may still be created.
	 */
	private function accepts_products( ?array $campaign ): bool {
		$end = $campaign ? strtotime( $campaign['end_utc'] . ' UTC' ) : false;
		return $campaign && in_array( $campaign['status'], array( 'draft', 'scheduled', 'active', 'paused' ), true )
			&& empty( $campaign['settings']['restoring'] ) && false !== $end && $end > time();
	}

	/**
	 * Return the private pending enrollment option key.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $product_id Product id.
	 */
	private function key( int $campaign_id, int $product_id ): string {
		return 'exin_bf_new_' . $campaign_id . '_' . $product_id; }
}
