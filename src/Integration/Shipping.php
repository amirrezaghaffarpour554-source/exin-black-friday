<?php
/**
 * Makes only explicitly authorized existing flat-rate instances free.
 *
 * @package Exin\BlackFriday
 */

namespace Exin\BlackFriday\Integration;

use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Infrastructure\CampaignRepository;

/**
 * Makes only explicitly authorized existing flat-rate instances free.
 */
final class Shipping {
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
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'packages' ), 20 );
		add_filter( 'woocommerce_package_rates', array( $this, 'rates' ), 100, 2 );
	}

	/**
	 * Version the normal WooCommerce shipping-package cache.
	 *
	 * @param array $packages WooCommerce shipping packages for the current cart.
	 * @return array Requested records or presentation data.
	 */
	public function packages( array $packages ): array {
		$campaign = $this->repository->current();
		$stamp    = $campaign ? hash( 'sha256', wp_json_encode( array( $campaign['id'], $campaign['status'], $campaign['version'], $campaign['settings']['free_shipping'] ?? false, $campaign['settings']['shipping_threshold'] ?? '', $campaign['settings']['shipping_instances'] ?? array(), get_option( 'exin_bf_cache_version', '1' ), CampaignContext::is_live( $campaign ) ) ) ) : 'none';
		foreach ( $packages as &$package ) {
			$package['exin_bf_rate_version'] = $stamp;
		}
		unset( $package );
		return $packages;
	}

	/**
	 * Adjust only explicitly authorized existing flat-rate instances.
	 *
	 * @param array $rates Existing WooCommerce shipping rates indexed by rate identifier.
	 * @param array $package One shipping package with post-coupon line totals.
	 * @return array Requested records or presentation data.
	 */
	public function rates( array $rates, array $package ): array {
		$campaign = $this->repository->current();
		if ( ! CampaignContext::is_live( $campaign ) || empty( $campaign['settings']['free_shipping'] ) ) {
			return $rates;
		}
		$allowed = array_filter( array_map( 'absint', (array) ( $campaign['settings']['shipping_instances'] ?? array() ) ) );
		if ( ! $allowed ) {
			return $rates;
		}
		try {
			$precision = wc_get_price_decimals();
			$threshold = Money::parse( (string) ( $campaign['settings']['shipping_threshold'] ?? '0' ), $precision );
			if ( $threshold <= 0 ) {
				return $rates;
			}
			$eligible          = 0;
			$has_campaign_item = false;
			foreach ( (array) ( $package['contents'] ?? array() ) as $line ) {
				$product_id = ! empty( $line['variation_id'] ) ? (int) $line['variation_id'] : (int) ( $line['product_id'] ?? 0 );
				$product    = isset( $line['data'] ) && $line['data'] instanceof \WC_Product ? $line['data'] : null;
				if ( (int) ( $line['quantity'] ?? 0 ) <= 0 || ! $this->eligibility->owns( $campaign, $product_id, $product ) ) {
					continue;
				}
				$has_campaign_item = true;
				// Woo's final post-coupon line total excludes tax; round each line to store precision.
				$line_total = Money::parse( wc_format_decimal( $line['line_total'] ?? '0', $precision ), $precision );
				if ( $line_total < 0 || $line_total > PHP_INT_MAX - $eligible ) {
					return $rates;
				}
				$eligible += $line_total;
			}
			if ( ! $has_campaign_item || $eligible < $threshold ) {
				return $rates;
			}
		} catch ( \Throwable $error ) {
			return $rates;
		}
		foreach ( $rates as $key => $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate || 'flat_rate' !== $rate->get_method_id() || ! in_array( (int) $rate->get_instance_id(), $allowed, true ) ) {
				continue;
			}
			// Clone the request's rate; never update zones, shipping methods or persisted rate options.
			$free = clone $rate;
			$free->set_cost( 0 );
			$free->set_taxes( array_map( static fn() => 0, $rate->get_taxes() ) );
			$rates[ $key ] = $free;
		}
		return $rates;
	}
}
