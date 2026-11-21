<?php
/**
 * Selected product retrieval. Never returns confidential campaign item records.
 *
 * @package Exin\BlackFriday
 */

namespace Exin\BlackFriday\Integration;

use Exin\BlackFriday\Infrastructure\CampaignRepository;

/**
 * Selected product retrieval. Never returns confidential campaign item records.
 */
final class PublicProductQuery {
	/**
	 * Receive the campaign repository.
	 *
	 * @param CampaignRepository $repository Campaign state repository.
	 */
	public function __construct( private CampaignRepository $repository ) {}

	/**
	 * Retrieve a bounded page of selected WooCommerce products.
	 *
	 * @param array $campaign Campaign configuration loaded from the repository.
	 * @param array $args Validated presentation query options.
	 * @return array Requested records or presentation data.
	 */
	public function query( array $campaign, array $args ): array {
		$campaigns = 'all' === ( $args['campaigns'] ?? ( $campaign['settings']['public_scope'] ?? 'single' ) ) ? $this->repository->live_campaigns() : array( $campaign );
		return ( new CatalogPage( $this->repository ) )->query( $campaigns, $args );
	}

	/**
	 * Retrieve version-cached selected IDs without confidential item data.
	 *
	 * @param array $campaign Campaign configuration loaded from the repository.
	 * @param bool  $featured Whether to restrict selection to featured items.
	 * @return array Requested records or presentation data.
	 */
	public function selected_ids( array $campaign, bool $featured = false ): array {
		global $wpdb;
		$key    = 'selected:' . (int) $campaign['id'] . ':' . (int) $campaign['version'] . ':' . get_option( 'exin_bf_cache_version', '1' ) . ':' . get_option( 'exin_bf_catalog_generation', '1' ) . ':' . (int) $featured;
		$cached = wp_cache_get( $key, 'exin_bf' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// Repository stores item state in an indexed plugin-owned table.
		$table = $wpdb->prefix . 'exin_bf_items';
		$sql   = "SELECT product_id FROM %i WHERE campaign_id = %d AND status IN ('included','pending','active','sold_out','paused')";
		if ( $featured ) {
			$sql .= ' AND featured = 1';
		}
		$sql .= ' ORDER BY priority ASC, product_id ASC';
		$ids  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $table, (int) $campaign['id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Indexed EXIN-owned item table; result cached with lifecycle/selection invalidation.
		wp_cache_set( $key, $ids, 'exin_bf', 300 );
		return $ids;
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Retained integration signature.
	/**
	 * Retrieve categories belonging to selected products and variation parents.
	 *
	 * @param array $campaign Campaign configuration loaded from the repository.
	 * @return array Requested records or presentation data.
	 */
	public function categories( array $campaign ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 100,
				'orderby'    => 'name',
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
	}
}
