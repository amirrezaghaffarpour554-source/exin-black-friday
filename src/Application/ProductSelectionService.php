<?php
/**
 * ProductSelectionService campaign module.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Application;

use Exin\BlackFriday\Domain\PricingEngine;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\LockManager;
use Exin\BlackFriday\Infrastructure\Logger;

/** Validates campaign selections and preserves financial ownership. */
final class ProductSelectionService {
	/**
	 * Connect the services required by this module.
	 *
	 * @param CampaignRepository $repo Campaign persistence service.
	 */
	public function __construct( private CampaignRepository $repo ) {}

	/**
	 * Search published sellable products and variations with bounded pagination.
	 *
	 * @param string $term Product name or SKU search text.
	 * @param int    $page One-based result page.
	 *
	 * @return array Operation result.
	 */
	public function search( string $term, int $page = 1 ): array {
		$page = min( 50, max( 1, $page ) );
		$term = sanitize_text_field( $term );
		if ( strlen( $term ) < 2 ) {
			return array(
				'products' => array(),
				'has_more' => false,
			);
		}
		$store    = \WC_Data_Store::load( 'product' );
		$ids      = $store->search_products( $term, '', true, false, $page * 20 + 1 );
		$products = array();
		foreach ( array_slice( $ids, ( $page - 1 ) * 20, 20 ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) ) {
				continue;
			}
			$products[] = $this->presentation( $product );
		}
		return array(
			'products' => $products,
			'has_more' => count( $ids ) > $page * 20,
		);
	}

	/**
	 * Calculate a private financial preview without saving changes.
	 *
	 * @param int    $campaign_id Campaign identifier.
	 * @param int    $product_id Sellable product or variation identifier.
	 * @param string $floor Requested positive floor price.
	 *
	 * @return array Operation result.
	 */
	public function preview( int $campaign_id, int $product_id, string $floor ): array {
		$campaign = $this->require_campaign( $campaign_id );
		$product  = $this->require_product( $product_id );
		$existing = $this->repo->item( $campaign_id, $product_id );
		$snapshot = $existing ? $existing['snapshot'] : PriceState::capture( $product );
		if ( ! $existing ) {
			$this->eligible( $product, $snapshot );
		}
		$base     = $existing ? $existing['base_price'] : $snapshot['price'];
		$schedule = ( new PricingEngine() )->schedule( $base, $floor, $campaign['days'], $campaign['strategy'], wc_get_price_decimals(), $campaign['power'], $campaign['rounding'] );
		return array_merge(
			$this->presentation( $product ),
			array(
				'base_price'  => $base,
				'floor_price' => PriceState::money( $floor ),
				'schedule'    => $schedule,
			)
		);
	}

	/**
	 * Enroll a sellable product using one coherent immutable snapshot.
	 *
	 * @param int    $campaign_id Campaign identifier.
	 * @param int    $product_id Sellable product or variation identifier.
	 * @param string $floor Requested positive floor price.
	 * @param array  $options Optional presentation controls.
	 *
	 * @return array Operation result.
	 */
	public function add( int $campaign_id, int $product_id, string $floor, array $options = array() ): array {
		return ( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $campaign_id, $product_id, $floor, $options ) {
				return ( new LockManager() )->run(
					'product:' . $product_id,
					function () use ( $campaign_id, $product_id, $floor, $options ) {
						$campaign = $this->require_campaign( $campaign_id );
						if ( ! in_array( $campaign['status'], array( 'draft', 'scheduled', 'active', 'paused' ), true ) ) {
							throw new \DomainException( 'campaign_not_editable' );
						}
						$previous = $this->repo->item( $campaign_id, $product_id );
						if ( $previous && ( 'removed' !== $previous['status'] || $previous['expected'] || $previous['intent'] ) ) {
							throw new \DomainException( 'product_already_included' );
						}
						if ( $this->repo->owned_elsewhere( $campaign_id, $product_id ) ) {
							throw new \DomainException( 'product_owned_by_another_campaign' );
						}
						$settings = $campaign['settings'];
						if ( isset( $settings['currency'] ) && ( get_woocommerce_currency() !== $settings['currency'] || wc_get_price_decimals() !== (int) $settings['precision'] ) ) {
							throw new \DomainException( 'store_currency_or_precision_changed' );
						}
						$settings['currency']  = get_woocommerce_currency();
						$settings['precision'] = wc_get_price_decimals();
						$this->repo->save( $campaign_id, array( 'settings' => $settings ) );
						$product  = $this->require_product( $product_id );
						$snapshot = PriceState::capture( $product );
						$this->eligible( $product, $snapshot );
						// Derive snapshot, base and schedule from the SAME captured object.
						$schedule                = ( new PricingEngine() )->schedule( $snapshot['price'], $floor, $campaign['days'], $campaign['strategy'], wc_get_price_decimals(), $campaign['power'], $campaign['rounding'] );
						$preview                 = array(
							'base_price'  => $snapshot['price'],
							'floor_price' => PriceState::money( $floor ),
							'schedule'    => $schedule,
						);
						$snapshot['precision']   = wc_get_price_decimals();
						$snapshot['currency']    = get_woocommerce_currency();
						$snapshot['captured_at'] = gmdate( 'Y-m-d H:i:s' );
						if ( $previous ) {
							$history                        = $previous['snapshot']['enrollment_history'] ?? array();
							$history[]                      = array_intersect_key( $previous['snapshot'], array_flip( array( 'regular', 'sale', 'price', 'from', 'to', 'precision', 'currency', 'captured_at' ) ) );
							$snapshot['enrollment_history'] = $history;
						}
						$this->repo->put_item(
							$campaign_id,
							$product_id,
							array(
								'base_price'   => $preview['base_price'],
								'floor_price'  => $preview['floor_price'],
								'schedule'     => $preview['schedule'],
								'snapshot'     => $snapshot,
								'expected'     => null,
								'intent'       => null,
								'retry_state'  => null,
								'operation_id' => '',
								'conflict'     => '',
								'status'       => 'included',
								'priority'     => (int) ( $options['priority'] ?? 0 ),
								'featured'     => empty( $options['featured'] ) ? 0 : 1,
							)
						);
						( new Logger() )->log(
							'info',
							$previous ? 'product_reenrolled' : 'product_included',
							array(
								'campaign_id' => $campaign_id,
								'product_id'  => $product_id,
								'result'      => 'success',
							)
						);
						return $this->repo->item( $campaign_id, $product_id );
					}
				);
			}
		);
	}

	/**
	 * Update allowed product controls and editable draft financial settings.
	 *
	 * @param int   $campaign_id Campaign identifier.
	 * @param int   $product_id Sellable product or variation identifier.
	 * @param array $data Allowlisted record fields.
	 *
	 * @return void Operation result.
	 */
	public function update( int $campaign_id, int $product_id, array $data ): void {
		( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $campaign_id, $product_id, $data ) {
				( new LockManager() )->run(
					'product:' . $product_id,
					function () use ( $campaign_id, $product_id, $data ) {
						$campaign = $this->require_campaign( $campaign_id );
						$item     = $this->repo->item( $campaign_id, $product_id );
						if ( ! $item || 'removed' === $item['status'] ) {
							throw new \DomainException( 'item_not_found' );
						}
						$changes = array_intersect_key( $data, array_flip( array( 'priority', 'featured', 'status' ) ) );
						if ( isset( $changes['status'] ) ) {
							if ( ! in_array( $changes['status'], array( 'paused', 'included' ), true ) || in_array( $item['status'], array( 'conflict', 'completed' ), true ) ) {
								throw new \DomainException( 'invalid_item_transition' );
							}
							if ( 'included' === $changes['status'] && $item['expected'] ) {
								$changes['status'] = 'active';
							}
						}
						if ( isset( $data['floor_price'] ) ) {
							if ( 'draft' !== $campaign['status'] || $item['expected'] || $item['intent'] ) {
								throw new \DomainException( 'financial_settings_frozen' );
							}
							$preview                = $this->preview( $campaign_id, $product_id, (string) $data['floor_price'] );
							$changes['floor_price'] = $preview['floor_price'];
							$changes['schedule']    = $preview['schedule'];
						}
						if ( isset( $changes['priority'] ) ) {
							$changes['priority'] = (int) $changes['priority'];
						}
						if ( isset( $changes['featured'] ) ) {
							$changes['featured'] = (int) (bool) $changes['featured'];
						}
						$this->repo->put_item( $campaign_id, $product_id, $changes );
						( new Logger() )->log(
							'info',
							'item_updated',
							array(
								'campaign_id' => $campaign_id,
								'product_id'  => $product_id,
								'result'      => 'success',
							)
						);
					}
				);
			}
		);
	}

	/**
	 * Restore an owned price before marking the product removed.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @param int $product_id Sellable product or variation identifier.
	 *
	 * @return void Operation result.
	 */
	public function remove( int $campaign_id, int $product_id ): void {
		( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $campaign_id, $product_id ) {
				( new LockManager() )->run(
					'product:' . $product_id,
					function () use ( $campaign_id, $product_id ) {
						$item = $this->repo->item( $campaign_id, $product_id );
						if ( ! $item ) {
							throw new \DomainException( 'item_not_found' );
						}
						if ( $item['expected'] || $item['intent'] ) {
							( new PriceProcessor( $this->repo, new Logger() ) )->restore( $campaign_id, $product_id );
							$item = $this->repo->item( $campaign_id, $product_id );
							if ( $item['expected'] || $item['intent'] ) {
								throw new \DomainException( 'restore_conflict_requires_resolution' );
							}
						}
						$this->repo->put_item( $campaign_id, $product_id, array( 'status' => 'removed' ) );
						( new Logger() )->log(
							'info',
							'product_removed',
							array(
								'campaign_id' => $campaign_id,
								'product_id'  => $product_id,
								'result'      => 'success',
							)
						);
					}
				);
			}
		);
	}

	/**
	 * Apply an explicit safe decision to a product ownership conflict.
	 *
	 * @param int    $campaign_id Campaign identifier.
	 * @param int    $product_id Sellable product or variation identifier.
	 * @param string $decision Explicit safe conflict resolution decision.
	 *
	 * @return void Operation result.
	 * @throws \DomainException When the campaign, product, or requested operation fails validation.
	 */
	public function resolve( int $campaign_id, int $product_id, string $decision ): void {
		if ( 'retry' === $decision ) {
			( new LockManager() )->run(
				'campaign:' . $campaign_id,
				function () use ( $campaign_id, $product_id ) {
					( new LockManager() )->run(
						'product:' . $product_id,
						function () use ( $campaign_id, $product_id ) {
							$item = $this->repo->item( $campaign_id, $product_id );
							if ( ! $item ) {
								throw new \DomainException( 'item_not_found' );
							}
							if ( ! $item['expected'] && ! $item['intent'] ) {
								throw new \DomainException( 'no_owned_price_to_restore' );
							}
							// Explicit administrator retry resets the budget, never the financial fingerprint.
							$this->repo->put_item( $campaign_id, $product_id, array( 'retry_state' => null ) );
							( new PriceProcessor( $this->repo, new Logger() ) )->restore( $campaign_id, $product_id );
						}
					);
				}
			);
			return;
		}
		if ( 'keep_external' !== $decision ) {
			throw new \DomainException( 'invalid_resolution' );
		}
		( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $campaign_id, $product_id ) {
				( new LockManager() )->run(
					'product:' . $product_id,
					function () use ( $campaign_id, $product_id ) {
						$item = $this->repo->item( $campaign_id, $product_id );
						if ( ! $item || 'conflict' !== $item['status'] ) {
							throw new \DomainException( 'item_not_in_conflict' );
						}
						// Explicitly relinquish ownership; never overwrite the external price.
						$this->repo->put_item(
							$campaign_id,
							$product_id,
							array(
								'expected'    => null,
								'intent'      => null,
								'retry_state' => null,
								'status'      => 'removed',
								'conflict'    => 'external_price_retained',
							)
						);
						( new Logger() )->log(
							'warning',
							'ownership_relinquished',
							array(
								'campaign_id' => $campaign_id,
								'product_id'  => $product_id,
								'result'      => 'external_price_retained',
							)
						);
					}
				);
			}
		);
	}

	/**
	 * Load a campaign or fail with a stable internal error code.
	 *
	 * @param int $id Record identifier.
	 *
	 * @return array Operation result.
	 * @throws \DomainException When the campaign, product, or requested operation fails validation.
	 */
	private function require_campaign( int $id ): array {
		$campaign = $this->repo->get( $id );
		if ( ! $campaign ) {
			throw new \DomainException( 'campaign_not_found' );
		}
		return $campaign;
	}

	/**
	 * Load a supported published sellable WooCommerce product.
	 *
	 * @param int $id Record identifier.
	 *
	 * @return \WC_Product Operation result.
	 * @throws \DomainException When the campaign, product, or requested operation fails validation.
	 */
	private function require_product( int $id ): \WC_Product {
		$product = wc_get_product( $id );
		if ( ! $product || ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) ) {
			throw new \DomainException( 'unsupported_or_missing_product' );
		}
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $parent || 'publish' !== $parent->get_status() || 'publish' !== $product->get_status() ) {
			throw new \DomainException( 'product_not_published' );
		}
		return $product;
	}

	/**
	 * Reject existing sales and incompatible dynamic pricing.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @param array       $snapshot Immutable original financial fingerprint.
	 *
	 * @return void Operation result.
	 * @throws \DomainException When the campaign, product, or requested operation fails validation.
	 */
	private function eligible( \WC_Product $product, array $snapshot ): void {
		if ( '' !== $snapshot['sale'] || $snapshot['from'] || $snapshot['to'] ) {
			throw new \DomainException( 'existing_or_scheduled_sale_conflict' );
		}
		if ( '' === $snapshot['regular'] || $snapshot['regular'] !== $snapshot['price'] ) {
			throw new \DomainException( 'regular_price_conflict' );
		}
		if ( PriceState::dynamic( $product ) ) {
			throw new \DomainException( 'dynamic_price_conflict' );
		}
	}

	/**
	 * Build the private administrator product selection summary.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 *
	 * @return array Operation result.
	 */
	private function presentation( \WC_Product $product ): array {
		$image = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
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
			'categories'     => $product->get_category_ids(),
			'status'         => $product->get_status(),
			'parent_id'      => $product->get_parent_id(),
		);
	}
}
