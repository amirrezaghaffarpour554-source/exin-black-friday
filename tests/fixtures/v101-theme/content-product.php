<?php
/** Local fixture only: a theme-owned div product card. */
defined( 'ABSPATH' ) || exit;
global $product;
if ( ! $product instanceof WC_Product ) {
	return;
}
echo '<div ';
wc_product_class( 'exin-test-theme-card', $product->get_id() );
echo ' data-test-product="' . esc_attr( (string) $product->get_id() ) . '"><h2>' . esc_html( $product->get_name() ) . '</h2></div>';
