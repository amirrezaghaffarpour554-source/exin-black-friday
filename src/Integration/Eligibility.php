<?php
/**
 * Eligibility is always based on server-owned campaign state and current Woo data.
 *
 * @package Exin\BlackFriday
 */

namespace Exin\BlackFriday\Integration;

use Exin\BlackFriday\Application\PriceState;
use Exin\BlackFriday\Infrastructure\CampaignRepository;

/**
 * Eligibility is always based on server-owned campaign state and current Woo data.
 */
final class Eligibility {
	/**
	 * Receive the campaign repository.
	 *
	 * @param CampaignRepository $repository Campaign state repository.
	 */
	public function __construct( private CampaignRepository $repository ) {}

	/**
	 * Identify a selected campaign item when an order line is actually created.
	 *
	 * A normal WooCommerce request may retain a cart product loaded before a daily
	 * price job. Selection, rather than its latest price fingerprint, determines
	 * attribution; WooCommerce remains responsible for the saved order amount.
	 *
	 * @param array            $campaign Server-loaded campaign configuration.
	 * @param int              $product_id Sellable product or variation identifier.
	 * @param \WC_Product|null $product Optional product already loaded by WooCommerce.
	 * @return bool Whether this selected item belongs to the live campaign.
	 */
	public function enrolled( array $campaign, int $product_id, ?\WC_Product $product = null ): bool {
		if ( ! CampaignContext::is_live( $campaign ) ) {
			return false;
		}
		$item = $this->repository->item( (int) $campaign['id'], $product_id );
		if ( ! CampaignContext::eligible_item( $item )
			|| ( $item['snapshot']['currency'] ?? '' ) !== get_woocommerce_currency()
			|| (int) ( $item['snapshot']['precision'] ?? -1 ) !== wc_get_price_decimals() ) {
			return false;
		}
		$product = $product ?? wc_get_product( $product_id );
		return $product && $product->is_type( array( 'simple', 'variation' ) ) && 'publish' === $product->get_status();
	}

	/**
	 * Verify campaign ownership against the current WooCommerce price fingerprint.
	 *
	 * @param array            $campaign Campaign configuration loaded from the repository.
	 * @param int              $product_id Sellable simple-product or variation identifier.
	 * @param \WC_Product|null $product Optional WooCommerce product object.
	 * @return bool Whether the condition holds.
	 */
	public function owns( array $campaign, int $product_id, ?\WC_Product $product = null ): bool {
		if ( ! CampaignContext::is_live( $campaign ) ) {
			return false;
		}
		$item = $this->repository->item( (int) $campaign['id'], $product_id );
		if ( ! CampaignContext::eligible_item( $item ) || ! empty( $item['intent'] )
			|| ( $item['snapshot']['currency'] ?? '' ) !== get_woocommerce_currency()
			|| (int) ( $item['snapshot']['precision'] ?? -1 ) !== wc_get_price_decimals() ) {
			return false;
		}
		$product = $product ?? wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( array( 'simple', 'variation' ) ) || 'publish' !== $product->get_status() ) {
			return false;
		}
		try {
			$expected = ! empty( $item['expected'] ) ? $item['expected'] : ( $item['snapshot'] ?? array() );
			return $expected && PriceState::same( PriceState::capture( $product ), $expected ) && ! PriceState::dynamic( $product );
		} catch ( \Throwable $error ) {
			return false;
		}
	}
}
