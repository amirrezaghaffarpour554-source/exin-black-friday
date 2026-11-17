<?php
/**
 * Targeted private catalog-generation invalidation.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Integration;

/** Invalidates campaign ID indexes without clearing the site's object cache. */
final class CatalogCache {
	/**
	 * Register stock, catalog, selection and sales lifecycle invalidation.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_after_product_object_save', 'woocommerce_after_product_variation_object_save', 'exin_bf_item_changed', 'exin_bf_price_changed', 'woocommerce_order_status_changed', 'woocommerce_order_refunded', 'woocommerce_product_set_total_sales' ) as $hook ) {
			add_action( $hook, array( $this, 'invalidate' ), 30, 0 );
		}
		add_action( 'created_term', array( $this, 'taxonomy_changed' ), 30, 3 );
		add_action( 'edited_term', array( $this, 'taxonomy_changed' ), 30, 3 );
		add_action( 'delete_term', array( $this, 'taxonomy_changed' ), 30, 3 );
		add_action( 'set_object_terms', array( $this, 'relationships_changed' ), 30, 4 );
		foreach ( array( 'woocommerce_attribute_added', 'woocommerce_attribute_updated', 'woocommerce_attribute_deleted' ) as $hook ) {
			add_action( $hook, array( $this, 'invalidate' ), 30, 0 );
		}
		add_action( 'transition_post_status', array( $this, 'post_status' ), 30, 3 );
		add_action( 'before_delete_post', array( $this, 'delete_post' ), 30, 2 );
	}

	/**
	 * Advance only this plugin's private catalog generation.
	 *
	 * @return void
	 */
	public function invalidate(): void {
		update_option( 'exin_bf_catalog_generation', wp_generate_uuid4(), false );
	}

	/**
	 * Invalidate filter metadata only for product-related taxonomy mutations.
	 *
	 * @param int    $term_id Changed term identifier.
	 * @param int    $term_taxonomy_id Changed term taxonomy identifier.
	 * @param string $taxonomy Registered taxonomy name.
	 * @return void
	 */
	public function taxonomy_changed( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
		if ( $term_id > 0 && $term_taxonomy_id > 0 && $this->product_taxonomy( $taxonomy ) ) {
			$this->invalidate();
		}
	}

	/**
	 * Ignore ordinary post tags while refreshing selected product assignments.
	 *
	 * @param int          $object_id WordPress object receiving the assignment.
	 * @param array|string $terms Assigned term values.
	 * @param int[]        $term_taxonomy_ids Assigned taxonomy record identifiers.
	 * @param string       $taxonomy Registered taxonomy name.
	 * @return void
	 */
	public function relationships_changed( int $object_id, $terms, array $term_taxonomy_ids, string $taxonomy ): void {
		if ( $object_id > 0 && ( is_array( $terms ) || is_string( $terms ) ) && is_array( $term_taxonomy_ids ) && $this->product_taxonomy( $taxonomy ) ) {
			$this->invalidate();
		}
	}

	/**
	 * Recognize taxonomies attached to WooCommerce catalog objects.
	 *
	 * @param string $taxonomy Registered taxonomy name.
	 * @return bool Whether this taxonomy can affect the product index.
	 */
	private function product_taxonomy( string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );
		return $object && ( in_array( 'product', $object->object_type, true ) || in_array( 'product_variation', $object->object_type, true ) );
	}

	/**
	 * Refresh publication visibility only when a WooCommerce product changes.
	 *
	 * @param string   $new_status New WordPress publication status.
	 * @param string   $old_status Previous WordPress publication status.
	 * @param \WP_Post $post Product or unrelated WordPress post.
	 * @return void
	 */
	public function post_status( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status !== $old_status && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			$this->invalidate();
		}
	}

	/**
	 * Remove deleted WooCommerce products from private presentation indexes.
	 *
	 * @param int           $post_id Deleted WordPress post identifier.
	 * @param \WP_Post|null $post Product or unrelated WordPress post.
	 * @return void
	 */
	public function delete_post( int $post_id, ?\WP_Post $post = null ): void {
		$post = $post ?? get_post( $post_id );
		if ( $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			$this->invalidate();
		}
	}
}
