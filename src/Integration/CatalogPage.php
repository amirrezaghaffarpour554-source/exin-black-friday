<?php
/**
 * Bounded SQL pagination for festival and optional store products.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Integration;

use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Domain\MoneyInput;
use Exin\BlackFriday\Infrastructure\CampaignRepository;

/** Caches one page of identifiers, never all campaign products or snapshots. */
final class CatalogPage {
	/**
	 * Receive the campaign repository.
	 *
	 * @param CampaignRepository $repo Repo.
	 */
	public function __construct( private CampaignRepository $repo ) {}
	/**
	 * Retrieve one bounded page of eligible store products.
	 *
	 * @param array $campaigns Campaigns.
	 * @param array $args Args.
	 */
	public function query( array $campaigns, array $args ): array {
		global $wpdb;
		$limit     = max( 1, min( 48, (int) ( $args['limit'] ?? 12 ) ) );
		$page      = max( 1, min( 10000, (int) ( $args['page'] ?? 1 ) ) );
		$empty     = array(
			'products' => array(),
			'total'    => 0,
			'pages'    => 0,
			'page'     => $page,
		);
		$campaigns = array_values( array_filter( $campaigns, static fn( $c ) => CampaignContext::is_live( $c ) && ( $c['settings']['currency'] ?? '' ) === get_woocommerce_currency() && (int) ( $c['settings']['precision'] ?? -1 ) === wc_get_price_decimals() ) );
		if ( ! $campaigns ) {
			return $empty; }
		$key    = 'catalog-page-v121:' . hash( 'sha256', wp_json_encode( array( array_map( static fn( $c ) => array( $c['id'], $c['version'] ), $campaigns ), $args, get_option( 'exin_bf_catalog_generation', '1' ), get_option( 'exin_bf_cache_version', '1' ), get_current_user_id(), is_search(), get_option( 'woocommerce_hide_out_of_stock_items' ), get_woocommerce_currency(), wc_get_price_decimals() ) ) );
		$cached = wp_cache_get( $key, 'exin_bf' );
		if ( ! is_array( $cached ) ) {
			$ids      = implode( ',', array_map( 'intval', array_column( $campaigns, 'id' ) ) );
			$eligible = array_values( array_filter( $this->repo->runnable(), static fn( $c ) => in_array( $c['status'], array( 'active', 'scheduled' ), true ) && empty( $c['settings']['restoring'] ) && strtotime( $c['start_utc'] . ' UTC' ) <= time() && strtotime( $c['end_utc'] . ' UTC' ) > time() ) );
			usort(
				$eligible,
				static function ( $a, $b ) {
					$p = (int) ( $b['settings']['priority'] ?? 0 ) <=> (int) ( $a['settings']['priority'] ?? 0 );
					return $p ? $p : $a['id'] <=> $b['id'];
				}
			);
			$rank        = implode( ',', array_map( 'intval', array_column( $eligible, 'id' ) ) );
			$rank        = '' === $rank ? '0' : $rank;
			$items       = $wpdb->prefix . 'exin_bf_items';
			$lookup      = $wpdb->prefix . 'wc_product_meta_lookup';
			$owned       = "((exin_i.expected IS NOT NULL AND exin_i.expected<>'null') OR (exin_i.intent IS NOT NULL AND exin_i.intent<>'null'))";
			$other_owned = "((exin_o.expected IS NOT NULL AND exin_o.expected<>'null') OR (exin_o.intent IS NOT NULL AND exin_o.intent<>'null'))";
			$effective   = "exin_i.status IN ('included','pending','active','sold_out','paused') AND (($owned AND NOT EXISTS (SELECT 1 FROM $items exin_o WHERE exin_o.product_id=exin_i.product_id AND exin_o.campaign_id<>exin_i.campaign_id AND $other_owned)) OR (NOT $owned AND exin_i.campaign_id IN ($rank) AND NOT EXISTS (SELECT 1 FROM $items exin_o WHERE exin_o.product_id=exin_i.product_id AND $other_owned) AND NOT EXISTS (SELECT 1 FROM $items exin_w WHERE exin_w.product_id=exin_i.product_id AND exin_w.campaign_id IN ($rank) AND exin_w.status IN ('included','pending','active','sold_out') AND FIELD(exin_w.campaign_id,$rank)<FIELD(exin_i.campaign_id,$rank))))";
			$join        = " LEFT JOIN $items exin_i ON exin_i.product_id={$wpdb->posts}.ID AND exin_i.campaign_id IN ($ids) AND ($effective) INNER JOIN $lookup exin_l ON exin_l.product_id={$wpdb->posts}.ID ";
			$where       = " AND exin_l.stock_status='instock' AND exin_l.min_price IS NOT NULL AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} exin_price WHERE exin_price.post_id={$wpdb->posts}.ID AND exin_price.meta_key='_price' AND exin_price.meta_value<>'') AND ({$wpdb->posts}.post_type='product' OR EXISTS (SELECT 1 FROM {$wpdb->posts} exin_parent WHERE exin_parent.ID={$wpdb->posts}.post_parent AND exin_parent.post_status='publish'))";
			if ( ! empty( $args['product_ids'] ) && is_array( $args['product_ids'] ) ) {
				$only   = implode( ',', array_map( 'absint', array_slice( $args['product_ids'], 0, 100 ) ) );
				$where .= " AND {$wpdb->posts}.ID IN ($only)"; }
			if ( ! filter_var( $args['include_store'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
				$where .= ' AND exin_i.product_id IS NOT NULL'; }
			if ( filter_var( $args['featured'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
				$where .= ' AND exin_i.featured=1'; }
			$where     .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} exin_tr INNER JOIN {$wpdb->term_taxonomy} exin_tt ON exin_tt.term_taxonomy_id=exin_tr.term_taxonomy_id INNER JOIN {$wpdb->terms} exin_t ON exin_t.term_id=exin_tt.term_id WHERE exin_tr.object_id={$wpdb->posts}.ID AND exin_tt.taxonomy='product_type' AND exin_t.slug IN ('variable','grouped','external'))";
			$visibility = wc_get_product_visibility_term_ids();
			$hidden     = (int) ( $visibility[ is_search() ? 'exclude-from-search' : 'exclude-from-catalog' ] ?? 0 );
			if ( $hidden ) {
				$where .= $wpdb->prepare( " AND NOT EXISTS (SELECT 1 FROM %i exin_vis WHERE exin_vis.object_id IN ({$wpdb->posts}.ID,{$wpdb->posts}.post_parent) AND exin_vis.term_taxonomy_id=%d)", $wpdb->term_relationships, $hidden ); }
			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
				$where .= " AND ({$wpdb->posts}.post_type<>'product_variation' OR EXISTS (SELECT 1 FROM $lookup exin_parent_stock WHERE exin_parent_stock.product_id={$wpdb->posts}.post_parent AND exin_parent_stock.stock_status<>'outofstock'))";
			}

			$owner    = "CASE WHEN {$wpdb->posts}.post_type='product_variation' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} exin_cm WHERE exin_cm.post_id={$wpdb->posts}.ID AND exin_cm.meta_key='_manage_stock' AND exin_cm.meta_value='yes') AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} exin_pm WHERE exin_pm.post_id={$wpdb->posts}.post_parent AND exin_pm.meta_key='_manage_stock' AND exin_pm.meta_value='yes') THEN {$wpdb->posts}.post_parent ELSE {$wpdb->posts}.ID END";
			$where   .= " AND (NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} exin_m WHERE exin_m.post_id=($owner) AND exin_m.meta_key='_manage_stock' AND exin_m.meta_value='yes') OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} exin_q WHERE exin_q.post_id=($owner) AND exin_q.meta_key='_stock' AND CAST(exin_q.meta_value AS DECIMAL(30,8))>0))";
			$regular  = "CAST((SELECT exin_r.meta_value FROM {$wpdb->postmeta} exin_r WHERE exin_r.post_id={$wpdb->posts}.ID AND exin_r.meta_key='_regular_price' LIMIT 1) AS DECIMAL(30,8))";
			$discount = "CASE WHEN $regular>0 AND exin_l.onsale=1 THEN GREATEST(0,($regular-exin_l.min_price)*100/$regular) ELSE 0 END";
			foreach ( array(
				'min_discount' => '>=',
				'max_discount' => '<=',
				'min_price'    => '>=',
				'max_price'    => '<=',
			) as $field => $operator ) {
				if ( ! isset( $args[ $field ] ) || '' === (string) $args[ $field ] ) {
					continue; }
				$percentage = str_contains( $field, 'discount' );
				$value      = MoneyInput::normalize( (string) $args[ $field ], $percentage ? 2 : wc_get_price_decimals() );
				if ( $percentage && Money::parse( $value, 2 ) > 10000 ) {
					return $empty; }
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Expressions and operators are fixed above; visitor amounts use a placeholder.
				$where .= $wpdb->prepare( ' AND (' . ( $percentage ? $discount : 'exin_l.min_price' ) . ") $operator CAST(%s AS DECIMAL(30,8))", $value );
			}
			if ( ! empty( $args['category'] ) ) {
				$value = $args['category'];
				$term  = ctype_digit( (string) $value ) ? get_term( (int) $value, 'product_cat' ) : get_term_by( 'slug', sanitize_title( $value ), 'product_cat' );
				if ( ! $term || is_wp_error( $term ) ) {
					return $empty; }
				$children = get_term_children( $term->term_id, 'product_cat' );
				$terms    = array_merge( array( (int) $term->term_id ), is_wp_error( $children ) ? array() : array_map( 'intval', $children ) );
				$terms    = implode( ',', $terms );
				$where   .= " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} exin_cr INNER JOIN {$wpdb->term_taxonomy} exin_ct ON exin_ct.term_taxonomy_id=exin_cr.term_taxonomy_id WHERE exin_cr.object_id IN ({$wpdb->posts}.ID,{$wpdb->posts}.post_parent) AND exin_ct.taxonomy='product_cat' AND exin_ct.term_id IN ($terms))";
			}
			$order  = $args['orderby'] ?? 'campaign_priority';
			$sorts  = array(
				'price'      => 'exin_l.min_price ASC',
				'price-desc' => 'exin_l.min_price DESC',
				'title'      => "{$wpdb->posts}.post_title ASC",
				'newest'     => "{$wpdb->posts}.post_date DESC",
			);
			$sort   = $sorts[ $order ] ?? "COALESCE(exin_i.featured,0) DESC,COALESCE(exin_i.priority,999999) ASC,$discount DESC,exin_l.total_sales DESC,JSON_UNQUOTE(JSON_EXTRACT(exin_i.snapshot,'$.captured_at')) DESC";
			$filter = static function ( array $clauses, \WP_Query $query ) use ( $wpdb, $join, $where, $sort ): array {
				if ( $query->get( 'exin_bf_public_page' ) ) {
					$clauses['join']   .= $join;
					$clauses['where']  .= $where;
					$clauses['groupby'] = $wpdb->posts . '.ID';
					$clauses['orderby'] = $query->get( 'exin_bf_count_only' ) ? '' : $sort . ',' . $wpdb->posts . '.ID ASC'; }
				return $clauses;
			};
			add_filter( 'posts_clauses', $filter, 20, 2 );
			try {
				$query = new \WP_Query(
					array(
						'post_type'           => array( 'product', 'product_variation' ),
						'post_status'         => 'publish',
						'posts_per_page'      => $limit,
						'paged'               => $page,
						'fields'              => 'ids',
						'exin_bf_public_page' => true,
						'ignore_sticky_posts' => true,
					)
				);
				$total = (int) $query->found_posts;
				if ( ! $query->posts && $page > 1 ) {
					// WP_Query skips totals on empty pages. Count with one unsorted ID,
					// keeping out-of-range pagination accurate without loading all products.
					$count_query = new \WP_Query(
						array_replace(
							$query->query,
							array(
								'paged'              => 1,
								'posts_per_page'     => 1,
								'exin_bf_count_only' => true,
							)
						)
					);
					$total       = (int) $count_query->found_posts;
				}
			} finally {
				remove_filter( 'posts_clauses', $filter, 20 ); }
				$cached = array(
					'ids'   => array_map( 'intval', $query->posts ),
					'total' => $total,
				);
				wp_cache_set( $key, $cached, 'exin_bf', 300 );
		}
		$products = array();
		_prime_post_caches( $cached['ids'], true, true );
		foreach ( $cached['ids'] as $id ) {
			$product = wc_get_product( $id );
			if ( $product && CampaignContext::purchasable_product( $product ) ) {
				$products[] = $product; }
		}
		return array(
			'products' => $products,
			'total'    => $cached['total'],
			'pages'    => (int) ceil( $cached['total'] / $limit ),
			'page'     => $page,
		);
	}
}
