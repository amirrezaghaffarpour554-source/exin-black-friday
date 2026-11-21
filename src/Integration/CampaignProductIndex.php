<?php
/**
 * Private catalog ranking for available campaign products.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Integration;

use Exin\BlackFriday\Domain\IntegerMath;
use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Infrastructure\CampaignRepository;

/** Rebuilds a versioned ID index from current Woo data, never future prices. */
final class CampaignProductIndex {
	/**
	 * Return available IDs with deterministic current-price ranking.
	 *
	 * @param array  $campaign Server-loaded campaign configuration.
	 * @param bool   $featured Restrict to featured membership.
	 * @param string $order Allowlisted presentation sort.
	 * @return int[] Current purchasable campaign product IDs.
	 */
	public function ids( array $campaign, bool $featured = false, string $order = 'campaign_priority' ): array {
		global $wpdb;
		$order = 'priority' === $order ? 'campaign_priority' : $order;
		if ( ! in_array( $order, array( 'campaign_priority', 'title', 'newest', 'price', 'price-desc' ), true ) ) {
			$order = 'campaign_priority';
		}
		$context = array( $campaign['id'], $campaign['version'], get_option( 'exin_bf_catalog_generation', '1' ), get_option( 'exin_bf_cache_version', '1' ), get_woocommerce_currency(), wc_get_price_decimals(), get_current_user_id(), $featured, $order );
		$key     = 'available-rank:' . hash( 'sha256', wp_json_encode( $context ) );
		$cached  = wp_cache_get( $key, 'exin_bf' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$sql = "SELECT product_id,priority,featured,snapshot FROM %i WHERE campaign_id=%d AND status IN ('included','pending','active','sold_out','paused')";
		if ( $featured ) {
			$sql .= ' AND featured=1';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Indexed EXIN-owned data; values are prepared and only resulting IDs are cached.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $wpdb->prefix . 'exin_bf_items', (int) $campaign['id'] ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}
		$ids = ( new CampaignRepository() )->effective_product_ids( (int) $campaign['id'], array_map( 'intval', array_column( $rows, 'product_id' ) ) );
		if ( ! $ids ) {
			return array();
		}
		$effective = array_fill_keys( $ids, true );
		$rows      = array_values( array_filter( $rows, static fn( $row ) => isset( $effective[ (int) $row['product_id'] ] ) ) );
		_prime_post_caches( $ids, true, true );
		$parents    = $this->parents( $ids );
		$parent_ids = array_values( array_unique( array_filter( $parents ) ) );
		if ( $parent_ids ) {
			_prime_post_caches( $parent_ids, true, true );
		}
		$ranked = array();
		foreach ( $rows as $row ) {
			$product = wc_get_product( (int) $row['product_id'] );
			if ( ! $product || ! CampaignContext::purchasable_product( $product ) ) {
				continue;
			}
			$snapshot = json_decode( (string) $row['snapshot'], true );
			if ( ! is_array( $snapshot ) || ( $snapshot['currency'] ?? '' ) !== get_woocommerce_currency() || (int) ( $snapshot['precision'] ?? -1 ) !== wc_get_price_decimals() ) {
				continue;
			}
			try {
				$price   = Money::parse( (string) $product->get_price(), wc_get_price_decimals() );
				$regular = '' === $product->get_regular_price() ? 0 : Money::parse( (string) $product->get_regular_price(), wc_get_price_decimals() );
			} catch ( \Throwable $error ) {
				continue;
			}
			$parent   = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
			$ranked[] = array(
				'id'       => $product->get_id(),
				'featured' => (int) $row['featured'],
				'priority' => (int) $row['priority'],
				'price'    => $price,
				'name'     => $product->get_name(),
				'discount' => $product->is_on_sale() && $regular > 0 && $price < $regular ? $regular - $price : 0,
				'base'     => max( 1, $regular ),
				'sales'    => (int) ( $parent ? $parent->get_total_sales() : $product->get_total_sales() ),
				'added'    => strtotime( ( $snapshot['captured_at'] ?? '1970-01-01 00:00:00' ) . ' UTC' ),
			);
		}
		usort( $ranked, fn( $left, $right ) => $this->compare( $left, $right, $order ) );
		$ids = array_column( $ranked, 'id' );
		wp_cache_set( $key, $ids, 'exin_bf', 300 );
		return $ids;
	}

	/**
	 * Read selected variation parents in a single restricted query.
	 *
	 * @param int[] $ids Already selected product IDs.
	 * @return array Variation IDs mapped to parent IDs.
	 */
	public function parents( array $ids ): array {
		if ( ! $ids ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'product_variation',
					'post_status'    => 'publish',
					'post__in'       => $ids,
					'posts_per_page' => -1,
					'fields'         => 'id=>parent',
				)
			)
		);
	}

	/**
	 * Filter an available selection by category slug or ID, including descendants.
	 *
	 * @param int[]      $ids Available selected product IDs.
	 * @param string|int $value Category slug or ID.
	 * @return int[] Ordered IDs belonging to the requested category.
	 */
	public function category( array $ids, $value ): array {
		if ( ! $ids || ! is_scalar( $value ) ) {
			return array();
		}
		$key    = 'available-category:' . hash( 'sha256', wp_json_encode( array( $ids, (string) $value, get_option( 'exin_bf_catalog_generation', '1' ) ) ) );
		$cached = wp_cache_get( $key, 'exin_bf' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$term = ctype_digit( (string) $value ) ? get_term( absint( $value ), 'product_cat' ) : get_term_by( 'slug', sanitize_title( (string) $value ), 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return array();
		}
		$parents    = $this->parents( $ids );
		$candidates = array_values( array_unique( array_merge( $ids, array_values( $parents ) ) ) );
		$matches    = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'post__in'       => $candidates,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Matching is limited to selected IDs and their parents, never the catalog.
				'tax_query'      => array(
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => (int) $term->term_id,
						'include_children' => true,
					),
				),
			)
		);
		$matching   = array_fill_keys( array_map( 'intval', $matches ), true );
		$filtered   = array_values( array_filter( $ids, static fn( $id ) => isset( $matching[ $id ] ) || isset( $matching[ $parents[ $id ] ?? 0 ] ) ) );
		wp_cache_set( $key, $filtered, 'exin_bf', 300 );
		return $filtered;
	}

	/**
	 * Compare exact current discounts and stable manual/sales/added tie breakers.
	 *
	 * @param array  $left Current presentation rank record.
	 * @param array  $right Current presentation rank record.
	 * @param string $order Allowlisted requested sort mode.
	 * @return int Stable ordering comparison.
	 */
	private function compare( array $left, array $right, string $order ): int {
		if ( 'title' === $order ) {
			$result = strnatcasecmp( $left['name'], $right['name'] );
			return $result ? $result : $left['id'] <=> $right['id'];
		}
		if ( 'price' === $order || 'price-desc' === $order ) {
			$result = $left['price'] <=> $right['price'];
			$result = 'price-desc' === $order ? -$result : $result;
			return $result ? $result : $left['id'] <=> $right['id'];
		}
		if ( 'newest' !== $order ) {
			$featured = $right['featured'] <=> $left['featured'];
			if ( $featured ) {
				return $featured;
			}
			if ( $left['featured'] && $left['priority'] !== $right['priority'] ) {
				return $left['priority'] <=> $right['priority'];
			}
			$discount = IntegerMath::compare( IntegerMath::multiply( (string) $right['discount'], (string) $left['base'] ), IntegerMath::multiply( (string) $left['discount'], (string) $right['base'] ) );
			if ( $discount ) {
				return $discount;
			}
			if ( $left['sales'] !== $right['sales'] ) {
				return $right['sales'] <=> $left['sales'];
			}
		}
		$result = $right['added'] <=> $left['added'];
		return $result ? $result : $left['id'] <=> $right['id'];
	}
}
