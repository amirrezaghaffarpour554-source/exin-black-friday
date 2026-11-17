<?php
/** Isolate synthetic test traffic before plugins and queued actions can run. */
defined( 'ABSPATH' ) || exit;
if ( 'local' !== wp_get_environment_type() || 'exin_test' !== DB_NAME ) {
	return;
}
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'woocommerce_allow_tracking', '__return_false' );
