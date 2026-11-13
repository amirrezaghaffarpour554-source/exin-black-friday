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
		$limit = max( 1, min( 48, (int) ( $args['limit'] ?? 12 ) ) );
		$page  = max( 1, min( 10000, (int) ( $args['page'] ?? 1 ) ) );
		$empty = array(
			'products' => array(),
			'total'    => 0,
			'pages'    => 0,
			'page'     => $page,
		);
		if ( ! CampaignContext::is_live( $campaign ) ) {
			return $empty;
		}
		$ids = $this->selected_ids( $campaign, ! empty( $args['featured'] ) );
		if ( ! $ids ) {
			return $empty;
		}
		$category = absint( $args['category'] ?? 0 );
		if ( $category ) {
			// Scope category matching to selected products and their parents, never the whole catalog.
			$variation_parents = get_posts(
				array(
					'post_type'      => 'product_variation',
					'post_status'    => 'publish',
					'post__in'       => $ids,
					'posts_per_page' => -1,
					'fields'         => 'id=>parent',
				)
			);
			$candidates        = array_values( array_unique( array_merge( $ids, array_values( $variation_parents ) ) ) );
			$parents           = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'post__in'       => $candidates,
					'fields'         => 'ids',
					'posts_per_page' => -1,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Restricted to selected IDs and their parents; WordPress caches the term query.
					'tax_query'      => array(
						array(
							'taxonomy'         => 'product_cat',
							'field'            => 'term_id',
							'terms'            => $category,
							'include_children' => true,
						),
					),
				)
			);
			$children          = array_keys( array_filter( $variation_parents, static fn( $parent_id ) => in_array( (int) $parent_id, array_map( 'intval', $parents ), true ) ) );
			$ids               = array_values( array_intersect( $ids, array_merge( $parents, $children ) ) );
			if ( ! $ids ) {
				return $empty;
			}
		}

		$order = sanitize_key( $args['orderby'] ?? 'priority' );
		$query = array(
			'post_type'              => array( 'product', 'product_variation' ),
			'post_status'            => 'publish',
			'post__in'               => $ids,
			'posts_per_page'         => $limit,
			'paged'                  => $page,
			'orderby'                => 'post__in',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
		);
		if ( 'title' === $order ) {
			$query['orderby'] = 'title';
			$query['order']   = 'ASC';
		} elseif ( 'newest' === $order ) {
			$query['orderby'] = 'date';
			$query['order']   = 'DESC';
		} elseif ( in_array( $order, array( 'price', 'price-desc' ), true ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Standard Woo price metadata; query is bounded by selected IDs and pagination.
			$query['meta_key'] = '_price';
			$query['orderby']  = 'meta_value_num';
			$query['order']    = 'price' === $order ? 'ASC' : 'DESC';
		}
		$show_out = isset( $args['show_out_of_stock'] ) ? filter_var( $args['show_out_of_stock'], FILTER_VALIDATE_BOOLEAN ) : ! empty( $campaign['settings']['show_out_of_stock'] );
		if ( ! $show_out ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Stock visibility is scoped to selected IDs; WordPress caches the query.
			$query['meta_query'] = array(
				array(
					'key'     => '_stock_status',
					'value'   => 'outofstock',
					'compare' => '!=',
				),
			);
		}
		$posts      = new \WP_Query( $query );
		$parent_ids = array_filter( array_map( static fn( $post ) => (int) $post->post_parent, $posts->posts ) );
		if ( $parent_ids ) {
			_prime_post_caches( array_values( array_unique( $parent_ids ) ), true, true );
		}
		$products = array();
		foreach ( $posts->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product || ! $product->is_type( array( 'simple', 'variation' ) ) ) {
				continue;
			}
			if ( $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( ! $parent || 'publish' !== $parent->get_status() || ! $parent->is_visible() ) {
					continue;
				}
			} elseif ( ! $product->is_visible() ) {
				continue;
			}
			$products[] = $product;
		}
		return array(
			'products' => $products,
			'total'    => (int) $posts->found_posts,
			'pages'    => (int) $posts->max_num_pages,
			'page'     => $page,
		);
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
		$key    = 'selected:' . (int) $campaign['id'] . ':' . (int) $campaign['version'] . ':' . get_option( 'exin_bf_cache_version', '1' ) . ':' . (int) $featured;
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

	/**
	 * Retrieve categories belonging to selected products and variation parents.
	 *
	 * @param array $campaign Campaign configuration loaded from the repository.
	 * @return array Requested records or presentation data.
	 */
	public function categories( array $campaign ): array {
		$ids = $this->selected_ids( $campaign );
		if ( ! $ids ) {
			return array();
		}
		$parents = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => 'publish',
				'post__in'       => $ids,
				'posts_per_page' => -1,
				'fields'         => 'id=>parent',
			)
		);
		$ids     = array_unique( array_merge( $ids, array_values( $parents ) ) );
		$terms   = wp_get_object_terms( $ids, 'product_cat', array( 'orderby' => 'name' ) );
		return is_wp_error( $terms ) ? array() : $terms;
	}
}
