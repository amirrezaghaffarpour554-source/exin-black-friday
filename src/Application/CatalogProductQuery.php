<?php
/**
 * Bounded administrator catalog discovery using WordPress query pagination.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Application;

/** Searches existing WooCommerce products without changing catalog data. */
final class CatalogProductQuery {
	private const PAGE_SIZE = 20;

	/**
	 * Search names and SKUs with inherited category/brand/attribute filters.
	 *
	 * @param string $term Optional product name or SKU fragment.
	 * @param int    $page One-based bounded page number.
	 * @param array  $filters Allowlisted catalog filters.
	 * @return array Private administrator product summaries and pagination.
	 */
	public function search( string $term, int $page = 1, array $filters = array() ): array {
		$page    = min( 10000, max( 1, $page ) );
		$term    = substr( sanitize_text_field( $term ), 0, 100 );
		$clauses = $this->conditions( $term, $filters );
		$where   = static function ( string $sql, \WP_Query $query ) use ( $clauses ): string {
			return $query->get( 'exin_bf_catalog_query' ) ? $sql . $clauses : $sql;
		};
		add_filter( 'posts_where', $where, 20, 2 );
		try {
			$query = new \WP_Query(
				array(
					'post_type'              => array( 'product', 'product_variation' ),
					'post_status'            => 'publish',
					'posts_per_page'         => self::PAGE_SIZE,
					'paged'                  => $page,
					'orderby'                => array(
						'title' => 'ASC',
						'ID'    => 'ASC',
					),
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
					'exin_bf_catalog_query'  => true,
				)
			);
		} finally {
			remove_filter( 'posts_where', $where, 20 );
		}
		$parents = array_values( array_unique( array_filter( array_map( static fn( $post ) => (int) $post->post_parent, $query->posts ) ) ) );
		if ( $parents ) {
			_prime_post_caches( $parents, true, true );
		}
		$products = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( $product && $product->is_type( array( 'simple', 'variable', 'variation' ) ) ) {
				$products[] = self::presentation( $product );
			}
		}
		return array(
			'products' => $products,
			'page'     => $page,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
			'has_more' => $page < (int) $query->max_num_pages,
		);
	}

	/**
	 * Return bounded registered category, brand and attribute choices.
	 *
	 * @return array Catalog filter metadata, without campaign financial data.
	 */
	public function filters(): array {
		$key    = 'catalog-filter-metadata:' . get_locale() . ':' . get_option( 'exin_bf_catalog_generation', '1' );
		$cached = wp_cache_get( $key, 'exin_bf' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$taxonomies = array();
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy ) {
			$kind = $this->taxonomy_kind( $taxonomy->name );
			if ( ! $kind || count( $taxonomies ) >= 20 ) {
				continue;
			}
			$count        = wp_count_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
				)
			);
			$taxonomies[] = array(
				'name'      => $taxonomy->name,
				'label'     => $taxonomy->labels->singular_name,
				'kind'      => $kind,
				'terms'     => $this->terms( $taxonomy->name ),
				'truncated' => ! is_wp_error( $count ) && (int) $count > 100,
			);
		}
		$data = array(
			'categories' => $this->terms( 'product_cat' ),
			'taxonomies' => $taxonomies,
		);
		wp_cache_set( $key, $data, 'exin_bf', 300 );
		return $data;
	}

	/**
	 * Build a product summary shared by preview and the product picker.
	 *
	 * @param \WC_Product $product Published WooCommerce product.
	 * @return array Allowlisted administrator presentation values.
	 */
	public static function presentation( \WC_Product $product ): array {
		$image  = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
		return array(
			'id'             => $product->get_id(),
			'product_id'     => $product->get_id(),
			'name'           => $product->get_name(),
			'sku'            => $product->get_sku(),
			'type'           => $product->get_type(),
			'price'          => $product->get_price( 'edit' ),
			'stock_status'   => $product->get_stock_status(),
			'stock_quantity' => $product->get_stock_quantity(),
			'in_stock'       => $product->is_in_stock(),
			'backorders'     => $product->backorders_allowed(),
			'image'          => $image ? $image : '',
			'categories'     => $parent ? $parent->get_category_ids() : $product->get_category_ids(),
			'status'         => $product->get_status(),
			'parent_id'      => $product->get_parent_id(),
			'selectable'     => $product->is_type( array( 'simple', 'variation' ) ) && '' !== $product->get_price( 'edit' ),
			'has_variations' => $product->is_type( 'variable' ),
		);
	}

	/**
	 * Construct prepared, request-scoped conditions against WordPress tables.
	 *
	 * @param string $term Sanitized optional name or SKU text.
	 * @param array  $filters Allowlisted input values.
	 * @return string Prepared SQL appended only to this product query.
	 */
	private function conditions( string $term, array $filters ): string {
		global $wpdb;
		$posts     = $wpdb->posts;
		$meta      = $wpdb->postmeta;
		$types     = array( 'simple', 'variable', 'variation' );
		$type      = in_array( $filters['type'] ?? '', $types, true ) ? $filters['type'] : 'all';
		$parent_id = absint( $filters['parent_id'] ?? 0 );
		// Identifiers are trusted wpdb table names and explicit columns; all request values use placeholders.
		$sql = $wpdb->prepare( ' AND (%i.post_type<>%s OR EXISTS (SELECT 1 FROM %i exin_parent WHERE exin_parent.ID=%i.post_parent AND exin_parent.post_type=%s AND exin_parent.post_status=%s))', $posts, 'product_variation', $posts, $posts, 'product', 'publish' );
		if ( $parent_id || 'variation' === $type ) {
			$sql .= $wpdb->prepare( ' AND %i.post_type=%s', $posts, 'product_variation' );
			if ( $parent_id ) {
				$sql .= $wpdb->prepare( ' AND %i.post_parent=%d', $posts, $parent_id );
			}
		} elseif ( 'variable' === $type ) {
				$sql .= $wpdb->prepare( ' AND %i.post_type=%s AND EXISTS (SELECT 1 FROM %i exin_type_rel INNER JOIN %i exin_type_tax ON exin_type_tax.term_taxonomy_id=exin_type_rel.term_taxonomy_id INNER JOIN %i exin_type_term ON exin_type_term.term_id=exin_type_tax.term_id WHERE exin_type_rel.object_id=%i.ID AND exin_type_tax.taxonomy=%s AND exin_type_term.slug=%s)', $posts, 'product', $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms, $posts, 'product_type', 'variable' );
		} elseif ( 'simple' === $type ) {
			$sql .= $wpdb->prepare( ' AND %i.post_type=%s AND NOT EXISTS (SELECT 1 FROM %i exin_type_rel INNER JOIN %i exin_type_tax ON exin_type_tax.term_taxonomy_id=exin_type_rel.term_taxonomy_id INNER JOIN %i exin_type_term ON exin_type_term.term_id=exin_type_tax.term_id WHERE exin_type_rel.object_id=%i.ID AND exin_type_tax.taxonomy=%s AND exin_type_term.slug<>%s)', $posts, 'product', $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms, $posts, 'product_type', 'simple' );
		} else {
			$sql .= $wpdb->prepare( ' AND (%i.post_type=%s OR NOT EXISTS (SELECT 1 FROM %i exin_type_rel INNER JOIN %i exin_type_tax ON exin_type_tax.term_taxonomy_id=exin_type_rel.term_taxonomy_id INNER JOIN %i exin_type_term ON exin_type_term.term_id=exin_type_tax.term_id WHERE exin_type_rel.object_id=%i.ID AND exin_type_tax.taxonomy=%s AND exin_type_term.slug NOT IN (%s,%s)))', $posts, 'product_variation', $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms, $posts, 'product_type', 'simple', 'variable' );
		}
		if ( '' !== $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$sql .= $wpdb->prepare( ' AND (%i.post_title LIKE %s OR EXISTS (SELECT 1 FROM %i exin_sku WHERE exin_sku.post_id=%i.ID AND exin_sku.meta_key=%s AND exin_sku.meta_value LIKE %s))', $posts, $like, $meta, $posts, '_sku', $like );
		}
		$stock = $filters['stock'] ?? 'all';
		if ( in_array( $stock, array( 'instock', 'outofstock' ), true ) ) {
			$sql .= $this->stock_condition( $stock );
		}
		if ( ! empty( $filters['category'] ) ) {
			$sql .= $this->taxonomy_condition( 'product_cat', $filters['category'], true );
		}
		$taxonomy = sanitize_key( $filters['taxonomy'] ?? '' );
		if ( $taxonomy && $this->taxonomy_kind( $taxonomy ) && ! empty( $filters['term'] ) ) {
			$sql .= $this->taxonomy_condition( $taxonomy, $filters['term'], false );
		}
		$tax_filters = $filters['tax_filters'] ?? array();
		if ( ! is_array( $tax_filters ) || count( $tax_filters ) > 5 ) {
			return ' AND 1=0';
		}
		foreach ( $tax_filters as $name => $value ) {
			$name = sanitize_key( (string) $name );
			if ( ! $this->taxonomy_kind( $name ) || ! is_scalar( $value ) || '' === (string) $value ) {
				return ' AND 1=0';
			}
			$sql .= $this->taxonomy_condition( $name, $value, false );
		}
		return $sql;
	}

	/**
	 * Match the stock status of a variation's actual WooCommerce stock owner.
	 *
	 * Child variations with independent stock override a managed parent. An
	 * inherited variation instead uses the parent's saved status before paging.
	 *
	 * @param string $stock Requested in-stock or out-of-stock filter.
	 * @return string Prepared stock-owner SQL condition.
	 */
	private function stock_condition( string $stock ): string {
		global $wpdb;
		$sql = $wpdb->prepare(
			' AND EXISTS (SELECT 1 FROM %i exin_stock WHERE exin_stock.post_id=CASE WHEN %i.post_type=%s AND NOT EXISTS (SELECT 1 FROM %i exin_child_manage WHERE exin_child_manage.post_id=%i.ID AND exin_child_manage.meta_key=%s AND exin_child_manage.meta_value=%s) AND EXISTS (SELECT 1 FROM %i exin_parent_manage WHERE exin_parent_manage.post_id=%i.post_parent AND exin_parent_manage.meta_key=%s AND exin_parent_manage.meta_value=%s) THEN %i.post_parent ELSE %i.ID END AND exin_stock.meta_key=%s',
			$wpdb->postmeta,
			$wpdb->posts,
			'product_variation',
			$wpdb->postmeta,
			$wpdb->posts,
			'_manage_stock',
			'yes',
			$wpdb->postmeta,
			$wpdb->posts,
			'_manage_stock',
			'yes',
			$wpdb->posts,
			$wpdb->posts,
			'_stock_status'
		);
		return $sql . ( 'instock' === $stock ? $wpdb->prepare( ' AND exin_stock.meta_value=%s)', 'instock' ) : $wpdb->prepare( ' AND exin_stock.meta_value<>%s)', 'instock' ) );
	}

	/**
	 * Build taxonomy matching against a variation's parent and exact attribute.
	 *
	 * @param string     $taxonomy Registered product taxonomy.
	 * @param string|int $value Term slug or identifier.
	 * @param bool       $children Include descendant category terms.
	 * @return string Prepared taxonomy condition or a guaranteed empty match.
	 */
	private function taxonomy_condition( string $taxonomy, $value, bool $children ): string {
		global $wpdb;
		$term = is_numeric( $value ) ? get_term( absint( $value ), $taxonomy ) : get_term_by( 'slug', sanitize_title( (string) $value ), $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return ' AND 1=0';
		}
		$ids = array( (int) $term->term_id );
		if ( $children ) {
			$descendants = get_term_children( $term->term_id, $taxonomy );
			if ( ! is_wp_error( $descendants ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $descendants ) );
			}
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = $wpdb->prepare( " AND EXISTS (SELECT 1 FROM %i exin_rel INNER JOIN %i exin_tax ON exin_tax.term_taxonomy_id=exin_rel.term_taxonomy_id WHERE exin_rel.object_id=IF(%i.post_type=%s,%i.post_parent,%i.ID) AND exin_tax.taxonomy=%s AND exin_tax.term_id IN ({$placeholders}))", array_merge( array( $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->posts, 'product_variation', $wpdb->posts, $wpdb->posts, $taxonomy ), $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN placeholders are generated exclusively from validated integer term IDs.
		if ( str_starts_with( $taxonomy, 'pa_' ) ) {
			$sql .= $wpdb->prepare( ' AND (%i.post_type<>%s OR EXISTS (SELECT 1 FROM %i exin_attr WHERE exin_attr.post_id=%i.ID AND exin_attr.meta_key=%s AND exin_attr.meta_value IN (%s,%s)))', $wpdb->posts, 'product_variation', $wpdb->postmeta, $wpdb->posts, 'attribute_' . $taxonomy, $term->slug, '' );
		}
		return $sql;
	}

	/**
	 * Identify registered brand taxonomies and WooCommerce global attributes.
	 *
	 * @param string $name Registered taxonomy name.
	 * @return string Brand, attribute, or empty for unsupported taxonomies.
	 */
	private function taxonomy_kind( string $name ): string {
		$taxonomy = get_taxonomy( $name );
		if ( ! $taxonomy || ! in_array( 'product', $taxonomy->object_type, true ) ) {
			return '';
		}
		if ( str_starts_with( $name, 'pa_' ) ) {
			return 'attribute';
		}
		return preg_match( '/brand|manufacturer|برند/i', $name . ' ' . $taxonomy->labels->singular_name ) ? 'brand' : '';
	}

	/**
	 * Read at most one hundred terms for one registered filter.
	 *
	 * @param string $taxonomy Registered product taxonomy.
	 * @return array Small allowlisted term summaries.
	 */
	private function terms( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 100,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			static fn( $term ) => array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
			),
			$terms
		);
	}
}
