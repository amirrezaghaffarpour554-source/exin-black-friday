<?php
/**
 * Immutable checkout attribution and HPOS-compatible order reporting.
 *
 * @package Exin\BlackFriday
 */

namespace Exin\BlackFriday\Integration;

use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Infrastructure\CampaignRepository;

/**
 * Immutable checkout attribution and HPOS-compatible order reporting.
 */
final class Orders {
	/**
	 * Shared eligibility service.
	 *
	 * @var Eligibility
	 */
	private Eligibility $eligibility;
	/**
	 * Receive the campaign repository.
	 *
	 * @param CampaignRepository $repository Campaign state repository.
	 */
	public function __construct( private CampaignRepository $repository ) {
		$this->eligibility = new Eligibility( $repository );
	}

	/**
	 * Register the WordPress and WooCommerce integration hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'classic_item' ), 20, 4 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'blocks_order' ), 20, 1 );
		add_action( 'woocommerce_update_order', array( $this, 'invalidate' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'invalidate' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'invalidate' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'invalidate' ) );
		add_action( 'woocommerce_refund_deleted', array( $this, 'invalidate' ), 10, 2 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'invalidate' ) );
		add_action( 'woocommerce_before_delete_order', array( new \Exin\BlackFriday\Application\SmartAdaptive( $this->repository ), 'forget' ) );
		add_action( 'woocommerce_trash_order', array( new \Exin\BlackFriday\Application\SmartAdaptive( $this->repository ), 'forget' ) );
		add_action( 'woocommerce_untrash_order', array( $this, 'invalidate' ) );
	}

	/**
	 * Attribute a classic checkout line using server-owned campaign state.
	 *
	 * @param mixed  $item Campaign item record or order line item.
	 * @param string $cart_item_key WooCommerce cart-line identifier.
	 * @param array  $values WooCommerce cart-line values.
	 * @param mixed  $order WooCommerce order being submitted.
	 * @return void
	 */
	public function classic_item( $item, string $cart_item_key, array $values, $order ): void {
		if ( ! $item instanceof \WC_Order_Item_Product || ! $order instanceof \WC_Order ) {
			return;
		}
		$product_id = ! empty( $values['variation_id'] ) ? (int) $values['variation_id'] : (int) ( $values['product_id'] ?? 0 );
		$product    = isset( $values['data'] ) && $values['data'] instanceof \WC_Product ? $values['data'] : null;
		$this->attribute( $item, $order, $product_id, $product );
	}

	/**
	 * Attribute the final Store API checkout submission before payment.
	 *
	 * @param mixed $order WooCommerce order being submitted.
	 * @return void
	 */
	public function blocks_order( $order ): void {
		if ( ! $order instanceof \WC_Order || $order->is_paid() ) {
			return;
		}
		// Actual Store API checkout submission; do not tag transient draft-order recalculations.
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			if ( $this->attribute( $item, $order, (int) $product_id ) ) {
				$item->save();
			}
		}
		$order->save_meta_data();
	}

	/**
	 * Attach immutable minimal campaign metadata to an eligible order item.
	 *
	 * @param \WC_Order_Item_Product $item Campaign item record or order line item.
	 * @param \WC_Order              $order WooCommerce order being submitted.
	 * @param int                    $product_id Sellable simple-product or variation identifier.
	 * @param \WC_Product|null       $product Optional WooCommerce product object.
	 * @return bool Whether the condition holds.
	 */
	private function attribute( \WC_Order_Item_Product $item, \WC_Order $order, int $product_id, ?\WC_Product $product = null ): bool {
		if ( $item->meta_exists( '_exin_bf_campaign_id' ) ) {
			return false;
		}
		$campaign = $this->repository->campaign_for_product( $product_id );
		if ( ! $campaign || ! $this->eligibility->enrolled( $campaign, $product_id, $product ) ) {
			return false;
		}
		$item->add_meta_data( '_exin_bf_campaign_id', (int) $campaign['id'], true );
		$item->add_meta_data( '_exin_bf_product_id', $product_id, true );
		$order->update_meta_data( '_exin_bf_campaign_' . (int) $campaign['id'], '1' );
		return true;
	}

	/**
	 * Invalidate private report checkpoints after an attributed order changes.
	 *
	 * @param mixed $order_id WooCommerce order object or identifier.
	 * @param int   $parent_order_id Parent when invoked after a refund deletion.
	 * @return void
	 */
	public function invalidate( $order_id, $parent_order_id = 0 ): void {
		if ( $parent_order_id ) {
			$order_id = $parent_order_id; }
		$order = $order_id instanceof \WC_Order ? $order_id : wc_get_order( $order_id );
		if ( $order instanceof \WC_Order_Refund ) {
			$order = wc_get_order( $order->get_parent_id() ); }
		if ( ! $order ) {
			return;
		}
		try {
			( new \Exin\BlackFriday\Application\SmartAdaptive( $this->repository ) )->observe( $order );
		} catch ( \Throwable $error ) {
			update_option( 'exin_bf_adaptive_error_global', 'order_checkpoint_failed', false ); }
		$campaigns = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$campaign_id = absint( $item->get_meta( '_exin_bf_campaign_id', true ) );
			if ( $campaign_id ) {
				$campaigns[ $campaign_id ] = true;
			}
		}
		foreach ( array_keys( $campaigns ) as $campaign_id ) {
			$this->invalidate_campaign( $campaign_id );
		}
	}

	/**
	 * Invalidate reports after order changes or an authorized reporting-settings edit.
	 *
	 * @param int $campaign_id Campaign whose private reporting state changed.
	 * @return void
	 */
	public function invalidate_campaign( int $campaign_id ): void {
		if ( $campaign_id < 1 ) {
			return;
		}
		wp_cache_delete( 'analytics:' . $campaign_id, 'exin_bf' );
		wp_cache_delete( 'analytics:v120:' . $campaign_id, 'exin_bf' );
		wp_cache_delete( 'analytics:v101:' . $campaign_id, 'exin_bf' );
		delete_transient( 'exin_bf_analytics_v101_' . $campaign_id );
		delete_transient( 'exin_bf_analytics_' . $campaign_id );
		delete_transient( 'exin_bf_analytics_v120_' . $campaign_id );
		update_option( 'exin_bf_report_generation_' . $campaign_id, wp_generate_uuid4(), false );
	}

	/**
	 * Aggregate private order analytics with a bounded resumable checkpoint.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @param int $page Positive report request sequence; the private checkpoint determines the actual cursor.
	 * @return array Requested records or presentation data.
	 * @throws \InvalidArgumentException When the report request sequence is not positive.
	 */
	public function analytics( int $campaign_id, int $page = 1 ): array {
		if ( $page < 1 ) {
			throw new \InvalidArgumentException( 'invalid_report_page' );
		}
		$campaign = $this->repository->get( $campaign_id );
		if ( ! $campaign ) {
			return array();
		}
		$generation = get_option( 'exin_bf_report_generation_' . $campaign_id, '1' );
		$cache_key  = 'analytics:v120:' . $campaign_id;
		$cached     = wp_cache_get( $cache_key, 'exin_bf' );
		if ( is_array( $cached ) && ! empty( $cached['complete'] ) && ( $cached['generation'] ?? null ) === $generation && ( $cached['campaign_version'] ?? null ) === (int) $campaign['version'] ) {
			return $cached;
		}
		$precision        = wc_get_price_decimals();
		$allowed_statuses = array_keys( wc_get_order_statuses() );
		$statuses         = array_map( static fn( $status ) => 'wc-' . preg_replace( '/^wc-/', '', (string) $status ), (array) ( $campaign['settings']['order_statuses'] ?? array( 'processing', 'completed' ) ) );
		$statuses         = array_values( array_diff( array_intersect( $statuses, $allowed_statuses ), array( 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-checkout-draft', 'wc-refunded' ) ) );
		if ( ! $statuses ) {
			$statuses = array( 'wc-processing', 'wc-completed' );
		}
		$checkpoint_key = 'exin_bf_analytics_v120_' . $campaign_id;
		$signature      = hash( 'sha256', wp_json_encode( array( $campaign['version'], $statuses, get_woocommerce_currency(), $precision, $generation ) ) );
		$checkpoint     = get_transient( $checkpoint_key );
		if ( ! is_array( $checkpoint ) || ( $checkpoint['signature'] ?? '' ) !== $signature ) {
			$checkpoint = array();
		}
		$order_count            = (int) ( $checkpoint['orders'] ?? 0 );
		$units                  = (int) ( $checkpoint['units'] ?? 0 );
		$item_revenue           = (int) ( $checkpoint['item_revenue'] ?? 0 );
		$order_revenue          = (int) ( $checkpoint['order_revenue'] ?? 0 );
		$products               = $checkpoint['products'] ?? array();
		$recent                 = $checkpoint['recent'] ?? array();
		$currencies             = $checkpoint['currencies'] ?? array();
		$cursor                 = (int) ( $checkpoint['cursor'] ?? 1 );
		$max_pages              = (int) ( $checkpoint['max_pages'] ?? 1 );
		$processed_this_request = 0;
		do {
			$result    = wc_get_orders(
				array(
					'type'       => 'shop_order',
					'status'     => $statuses,
					'limit'      => 100,
					'page'       => $cursor,
					'paginate'   => true,
					'orderby'    => 'ID',
					'order'      => 'DESC',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Necessary HPOS-compatible CRUD attribution query; each batch is capped at 100 orders.
					'meta_key'   => '_exin_bf_campaign_' . $campaign_id,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Filter exact immutable attribution metadata through WooCommerce CRUD.
					'meta_value' => '1',
				)
			);
			$max_pages = max( 1, (int) $result->max_num_pages );
			foreach ( $result->orders as $order ) {
				$currency                = $order->get_currency();
				$currencies[ $currency ] = true;
				if ( get_woocommerce_currency() !== $currency ) {
					continue;
				}
				$total_minor    = $this->minor( $order->get_total(), $precision );
				$refunded_minor = $this->minor( $order->get_total_refunded(), $precision );
				if ( $total_minor > 0 && $refunded_minor >= $total_minor ) {
					continue;
				}
				$net           = 0;
				$quantity      = 0;
				$attributed    = false;
				$seen_products = array();
				foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
					if ( (int) $item->get_meta( '_exin_bf_campaign_id', true ) !== $campaign_id ) {
						continue;
					}
					$attributed = true;
					$product_id = (int) $item->get_meta( '_exin_bf_product_id', true );
					$line_net   = max( 0, $this->minor( $item->get_total(), $precision ) - $this->minor( $order->get_total_refunded_for_item( $item_id ), $precision ) );
					$line_units = max( 0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id ) );
					$net        = $this->add( $net, $line_net );
					$quantity  += $line_units;
					if ( ! isset( $products[ $product_id ] ) ) {
						$products[ $product_id ] = array(
							'product_id'    => $product_id,
							'name'          => $item->get_name(),
							'units'         => 0,
							'revenue_minor' => 0,
							'orders'        => 0,
						);
					}
					if ( ( $line_units > 0 || $line_net > 0 ) && ! isset( $seen_products[ $product_id ] ) ) {
						$products[ $product_id ]['orders'] = (int) ( $products[ $product_id ]['orders'] ?? 0 ) + 1;
						$seen_products[ $product_id ]      = true; }
					$products[ $product_id ]['units']        += $line_units;
					$products[ $product_id ]['revenue_minor'] = $this->add( $products[ $product_id ]['revenue_minor'], $line_net );
				}
				if ( ! $attributed || ( 0 === $quantity && 0 === $net ) ) {
					continue;
				}
				++$order_count;
				$units        += $quantity;
				$item_revenue  = $this->add( $item_revenue, $net );
				$order_net     = max( 0, $total_minor - $refunded_minor );
				$order_revenue = $this->add( $order_revenue, $order_net );
				if ( count( $recent ) < 10 ) {
					$recent[] = array(
						'order_id'       => $order->get_id(),
						'number'         => $order->get_order_number(),
						'status'         => $order->get_status(),
						'total'          => Money::format( $order_net, $precision ),
						'campaign_total' => Money::format( $net, $precision ),
						'date'           => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null,
					);
				}
			}
			++$cursor;
			++$processed_this_request;
		} while ( $cursor <= $max_pages && $processed_this_request < 5 );
		$invalidated = get_option( 'exin_bf_report_generation_' . $campaign_id, '1' ) !== $generation;
		$complete    = $cursor > $max_pages && ! $invalidated;
		if ( $complete || $invalidated ) {
			delete_transient( $checkpoint_key );
		} else {
			set_transient(
				$checkpoint_key,
				array(
					'signature'     => $signature,
					'orders'        => $order_count,
					'units'         => $units,
					'item_revenue'  => $item_revenue,
					'order_revenue' => $order_revenue,
					'products'      => $products,
					'recent'        => $recent,
					'currencies'    => $currencies,
					'cursor'        => $cursor,
					'max_pages'     => $max_pages,
				),
				600
			);
		}
		$least = $complete ? $this->least( $campaign_id, $products, $precision ) : array();
		usort( $products, static fn( $a, $b ) => $b['units'] <=> $a['units'] );
		$top = array_slice( $products, 0, 10 );
		foreach ( $top as &$product ) {
			$product['revenue'] = Money::format( $product['revenue_minor'], $precision );
			unset( $product['revenue_minor'] );
		}
		unset( $product );
		$inventory = $this->inventory( $campaign );
		$data      = array(
			'campaign_id'              => $campaign_id,
			'generation'               => $generation,
			'invalidated'              => $invalidated,
			'campaign_version'         => (int) $campaign['version'],
			'orders'                   => $order_count,
			'units'                    => $units,
			'item_revenue'             => Money::format( $item_revenue, $precision ),
			'order_revenue'            => Money::format( $order_revenue, $precision ),
			'top_products'             => $top,
			'least_products'           => $least,
			'recent_orders'            => $recent,
			'currency'                 => get_woocommerce_currency(),
			'excluded_currencies'      => array_values( array_diff( array_keys( $currencies ), array( get_woocommerce_currency() ) ) ),
			'remaining_stock'          => $inventory['quantity'],
			'unmanaged_stock_products' => $inventory['unmanaged'],
			'inventory'                => $inventory['items'],
			'allowed_statuses'         => $statuses,
			'updated_at'               => gmdate( DATE_ATOM ),
			'complete'                 => $complete,
			'processed_pages'          => $cursor - 1,
			'total_pages'              => $max_pages,
			'next_page'                => $complete ? null : ( $invalidated ? 1 : $cursor ),
		);
		if ( $complete ) {
			wp_cache_set( $cache_key, $data, 'exin_bf', 60 );
		}
		return $data;
	}

	/**
	 * Read current WooCommerce stock for all nonremoved historical campaign items.
	 *
	 * @param array $campaign Campaign configuration loaded from the repository.
	 * @return array Requested records or presentation data.
	 */
	private function inventory( array $campaign ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'exin_bf_items';
		// Bounded inventory detail; aggregate shared stock owners in SQL below.
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM %i WHERE campaign_id=%d AND status<>'removed' ORDER BY product_id ASC LIMIT 100", $table, (int) $campaign['id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own indexed table, bounded reporting page.
		_prime_post_caches( $ids, true, true );

		$quantity     = 0;
		$unmanaged    = 0;
		$items        = array();
		$stock_owners = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$owner_id = $product->get_stock_managed_by_id();
			$owner    = $owner_id === $id ? $product : wc_get_product( $owner_id );
			$stock    = $owner && $owner->managing_stock() ? $owner->get_stock_quantity() : null;
			if ( null === $stock ) {
				++$unmanaged;
			} elseif ( ! isset( $stock_owners[ $owner_id ] ) ) {
				$quantity                 += max( 0, (int) $stock );
				$stock_owners[ $owner_id ] = true;
			}
			if ( count( $items ) < 100 ) {
				$items[] = array(
					'product_id'   => $id,
					'quantity'     => $stock,
					'stock_status' => $product->get_stock_status(),
					'backorders'   => $product->backorders_allowed(),
				);
			}
		}
		$owner     = "CASE WHEN p.post_type='product_variation' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} cm WHERE cm.post_id=p.ID AND cm.meta_key='_manage_stock' AND cm.meta_value='yes') AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.post_parent AND pm.meta_key='_manage_stock' AND pm.meta_value='yes') THEN p.post_parent ELSE p.ID END";
		$source    = $wpdb->prepare( "SELECT $owner AS stock_owner,COUNT(*) AS members FROM %i i INNER JOIN %i p ON p.ID=i.product_id WHERE i.campaign_id=%d AND i.status<>'removed' GROUP BY stock_owner", $table, $wpdb->posts, (int) $campaign['id'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed stock-owner expression, identifiers and campaign are prepared.
		$sql       = "SELECT COALESCE(SUM(CASE WHEN m.meta_value='yes' AND q.meta_value IS NOT NULL THEN GREATEST(0,FLOOR(CAST(q.meta_value AS DECIMAL(30,8)))) ELSE 0 END),0) AS quantity,COALESCE(SUM(CASE WHEN m.meta_value='yes' AND q.meta_value IS NOT NULL THEN 0 ELSE o.members END),0) AS unmanaged FROM ($source) o LEFT JOIN {$wpdb->postmeta} m ON m.post_id=o.stock_owner AND m.meta_key='_manage_stock' LEFT JOIN {$wpdb->postmeta} q ON q.post_id=o.stock_owner AND q.meta_key='_stock'";
		$totals    = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Restricted EXIN membership aggregation, already-prepared source SQL and fixed WP table names.
		$quantity  = (int) ( $totals['quantity'] ?? 0 );
		$unmanaged = (int) ( $totals['unmanaged'] ?? 0 );
		return array(
			'quantity'  => $quantity,
			'unmanaged' => $unmanaged,
			'items'     => $items,
		);
	}

	/**
	 * Include unsold products after a complete real-order scan.
	 *
	 * @param int   $campaign_id Campaign id.
	 * @param array $products Products.
	 * @param int   $precision Precision.
	 */
	private function least( int $campaign_id, array $products, int $precision ): array {
		global $wpdb;
		$ids     = array_map( 'intval', array_keys( $products ) );
		$holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql     = "SELECT product_id FROM %i WHERE campaign_id=%d AND status<>'removed'" . ( $ids ? " AND product_id NOT IN ($holders)" : '' ) . ' ORDER BY product_id ASC LIMIT 10';
		$zero    = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $wpdb->prefix . 'exin_bf_items', $campaign_id ), $ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Private complete-report IDs, generated integer placeholders and a bounded own-table result.
		$rows    = array();
		foreach ( $zero as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$rows[] = array(
					'product_id' => (int) $id,
					'name'       => $product->get_name(),
					'units'      => 0,
					'orders'     => 0,
					'revenue'    => Money::format( 0, $precision ),
				); }
		}
		usort(
			$products,
			static function ( $a, $b ) {
				$r = $a['units'] <=> $b['units'];
				return $r ? $r : $a['product_id'] <=> $b['product_id'];
			}
		);
		foreach ( $products as $product ) {
			if ( count( $rows ) >= 10 ) {
				break;
			} $product['revenue'] = Money::format( $product['revenue_minor'], $precision );
			unset( $product['revenue_minor'] );
			$rows[] = $product; }
		return $rows;
	}

	/**
	 * Normalize a WooCommerce monetary total into integer minor units.
	 *
	 * @param mixed $amount WooCommerce decimal monetary amount.
	 * @param int   $precision Store currency precision.
	 * @return int Integer amount in the smallest currency unit.
	 */
	private function minor( $amount, int $precision ): int {
		return Money::parse( wc_format_decimal( $amount, $precision ), $precision );
	}
	/**
	 * Add nonnegative report amounts with overflow protection.
	 *
	 * @param int $a Current nonnegative aggregate in minor units.
	 * @param int $b Nonnegative amount to add in minor units.
	 * @return int Integer amount in the smallest currency unit.
	 * @throws \OverflowException When the aggregate exceeds the integer range.
	 */
	private function add( int $a, int $b ): int {
		if ( $b > PHP_INT_MAX - $a ) {
			throw new \OverflowException( 'analytics_total_overflow' );
		}
		return $a + $b;
	}
}
