<?php
/**
 * Cache campaign module.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Infrastructure;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial state must be read fresh; not Woo entities.

/** Provides targeted price cache invalidation. */
final class Cache {
	/**
	 * Invalidate targeted WooCommerce product and variation caches.
	 *
	 * @param int $product_id Sellable product or variation identifier.
	 *
	 * @return void Operation result.
	 */
	public static function invalidate( int $product_id ): void {
		wc_delete_product_transients( $product_id );
		clean_post_cache( $product_id );
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) && $product->get_parent_id() ) {
			$parent_id = $product->get_parent_id();
			\WC_Product_Variable::sync( $parent_id );
			wc_delete_product_transients( $parent_id );
			clean_post_cache( $parent_id );
		}
		update_option( 'exin_bf_cache_version', wp_generate_uuid4(), false );
		/** External page-cache integrations can purge the product and campaign URLs here. */
		do_action( 'exin_bf_price_changed', $product_id );
	}
}
