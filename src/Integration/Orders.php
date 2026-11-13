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
		add_action( 'woocommerce_order_status_changed', array( $this, 'invalidate' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'invalidate' ) );
		add_action( 'woocommerce_before_delete_order', array( $this, 'invalidate' ) );
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
		$campaign = $this->repository->current();
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
	 * @return void
	 */
	public function invalidate( $order_id ): void {
		$order = $order_id instanceof \WC_Order ? $order_id : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$campaign_id = absint( $item->get_meta( '_exin_bf_campaign_id', true ) );
			if ( $campaign_id ) {
				wp_cache_delete( 'analytics:' . $campaign_id, 'exin_bf' );
				delete_transient( 'exin_bf_analytics_' . $campaign_id );
				update_option( 'exin_bf_report_generation_' . $campaign_id, wp_generate_uuid4(), false );
			}
		}
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
		$cache_key  = 'analytics:' . $campaign_id;
		$cached     = wp_cache_get( $cache_key, 'exin_bf' );
		if ( is_array( $cached ) && ! empty( $cached['complete'] ) && ( $cached['generation'] ?? null ) === $generation && ( $cached['campaign_version'] ?? null ) === (int) $campaign['version'] ) {
			return $cached;
		}
		$precision        = wc_get_price_decimals();
		$allowed_statuses = array_keys( wc_get_order_statuses() );
		$statuses         = array_map( static fn( $status ) => 'wc-' . preg_replace( '/^wc-/', '', (string) $status ), (array) ( $campaign['settings']['order_statuses'] ?? array( 'processing', 'completed' ) ) );
		$statuses         = array_values( array_intersect( $statuses, $allowed_statuses ) );
		if ( ! $statuses ) {
			$statuses = array( 'wc-processing', 'wc-completed' );
		}
		$checkpoint_key = 'exin_bf_analytics_' . $campaign_id;
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
					'orderby'    => 'date',
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
				$net        = 0;
				$quantity   = 0;
				$attributed = false;
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
						);
					}
					$products[ $product_id ]['units']        += $line_units;
					$products[ $product_id ]['revenue_minor'] = $this->add( $products[ $product_id ]['revenue_minor'], $line_net );
				}
				if ( ! $attributed ) {
					continue;
				}
				++$order_count;
				$units        += $quantity;
				$item_revenue  = $this->add( $item_revenue, $net );
				$order_net     = max( 0, $this->minor( $order->get_total(), $precision ) - $this->minor( $order->get_total_refunded(), $precision ) );
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
		usort( $products, static fn( $a, $b ) => $b['units'] <=> $a['units'] );
		$top = array_slice( $products, 0, 10 );
		foreach ( $top as &$product ) {
			$product['revenue'] = Money::format( $product['revenue_minor'], $precision );
			unset( $product['revenue_minor'] );
		}
		unset( $product );
		$target    = max( 1, (int) ( $campaign['settings']['target_orders'] ?? 500 ) );
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
			'target_orders'            => $target,
			'progress'                 => min( 100, round( $order_count * 100 / $target, 1 ) ),
			'top_products'             => $top,
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
		$key   = 'inventory_ids:' . (int) $campaign['id'] . ':' . (int) $campaign['version'] . ':' . get_option( 'exin_bf_cache_version', '1' );
		$ids   = wp_cache_get( $key, 'exin_bf' );
		if ( ! is_array( $ids ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- EXIN-owned indexed item table; cache invalidates on campaign/selection mutations.
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM %i WHERE campaign_id = %d AND status <> 'removed' ORDER BY product_id ASC", $table, (int) $campaign['id'] ) ) );
			wp_cache_set( $key, $ids, 'exin_bf', 300 );
		}
		if ( $ids ) {
			_prime_post_caches( $ids, true, true );
			$parents = array();
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( $post && $post->post_parent ) {
					$parents[] = (int) $post->post_parent;
				}
			}
			if ( $parents ) {
				_prime_post_caches( array_values( array_unique( $parents ) ), true, true );
			}
		}
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
		return array(
			'quantity'  => $quantity,
			'unmanaged' => $unmanaged,
			'items'     => $items,
		);
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
