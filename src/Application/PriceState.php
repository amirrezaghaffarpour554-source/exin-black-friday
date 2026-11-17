<?php
/**
 * PriceState campaign module.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Application;

use Exin\BlackFriday\Domain\Money;

/** Canonical financial fingerprint. Never stores stock or customer information. */
final class PriceState {
	/**
	 * Read a canonical fingerprint of WooCommerce financial fields.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 *
	 * @return array Operation result.
	 */
	public static function capture( \WC_Product $product ): array {
		return array(
			'regular' => self::money( (string) $product->get_regular_price( 'edit' ) ),
			'sale'    => self::money( (string) $product->get_sale_price( 'edit' ) ),
			'price'   => self::money( (string) $product->get_price( 'edit' ) ),
			'from'    => $product->get_date_on_sale_from( 'edit' ) ? $product->get_date_on_sale_from( 'edit' )->getTimestamp() : null,
			'to'      => $product->get_date_on_sale_to( 'edit' ) ? $product->get_date_on_sale_to( 'edit' )->getTimestamp() : null,
		);
	}

	/**
	 * Normalize a price using the store currency precision.
	 *
	 * @param string $price Decimal price in store currency.
	 *
	 * @return string Operation result.
	 */
	public static function money( string $price ): string {
		return '' === $price ? '' : Money::format( Money::parse( $price, wc_get_price_decimals() ), wc_get_price_decimals() );
	}

	/**
	 * Compare canonical financial fields without considering snapshot metadata.
	 *
	 * @param array $a First financial fingerprint.
	 * @param array $b Second financial fingerprint.
	 *
	 * @return bool Operation result.
	 */
	public static function same( array $a, array $b ): bool {
		foreach ( array( 'regular', 'sale', 'price', 'from', 'to' ) as $key ) {
			if ( ( $a[ $key ] ?? null ) !== ( $b[ $key ] ?? null ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Detect filtered product prices or explicit dynamic pricing conflicts.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 *
	 * @return bool Operation result.
	 */
	public static function dynamic( \WC_Product $product ): bool {
		$different = self::money( (string) $product->get_price() ) !== self::money( (string) $product->get_price( 'edit' ) )
			|| self::money( (string) $product->get_regular_price() ) !== self::money( (string) $product->get_regular_price( 'edit' ) )
			|| self::money( (string) $product->get_sale_price() ) !== self::money( (string) $product->get_sale_price( 'edit' ) );
		return (bool) apply_filters( 'exin_bf_dynamic_pricing_conflict', $different, $product );
	}

	/**
	 * Write a canonical financial fingerprint through WooCommerce CRUD.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @param array       $state Operation input value.
	 *
	 * @return void Operation result.
	 */
	public static function write( \WC_Product $product, array $state ): void {
		$product->set_regular_price( $state['regular'] );
		$product->set_sale_price( $state['sale'] );
		$product->set_date_on_sale_from( $state['from'] );
		$product->set_date_on_sale_to( $state['to'] );
		$product->set_price( $state['price'] );
		$product->save();
	}
}
