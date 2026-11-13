<?php
/** Browser workflow fixtures, exclusively for the local test database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'exin_test' !== DB_NAME ) {
	throw new RuntimeException( 'Browser fixtures require the isolated local database.' );
}
$repository = new \Exin\BlackFriday\Infrastructure\CampaignRepository();
$id = $repository->create( array( 'title' => 'EXIN Browser Workflow', 'status' => 'draft' ) );
$product = new WC_Product_Simple();
$product->set_name( 'EXIN UI Workflow Product' );
$product->set_sku( 'exin-ui-' . $id );
$product->set_status( 'publish' );
$product->set_regular_price( '100.00' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 15 );
$product->save();
echo wp_json_encode( array( 'campaign_id' => $id, 'product_id' => $product->get_id(), 'sku' => $product->get_sku() ) );
