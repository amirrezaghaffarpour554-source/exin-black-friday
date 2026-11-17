<?php
/** Local fixture only: a div-based WooCommerce loop-start theme override. */
defined( 'ABSPATH' ) || exit;
echo '<div class="products exin-test-theme-grid" data-test-columns="' . esc_attr( (string) wc_get_loop_prop( 'columns' ) ) . '" data-test-loop="' . esc_attr( (string) wc_get_loop_prop( 'name' ) ) . '">';
