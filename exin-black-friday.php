<?php
/**
 * Plugin Name: EXIN Black Friday
 * Description: مدیریت امن کمپین بلک فرایدی اکسین برای ووکامرس.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * Author: EXIN
 * Text Domain: exin-black-friday
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 *
 * @package ExinBlackFriday
 */

defined( 'ABSPATH' ) || exit;

define( 'EXIN_BF_VERSION', '1.0.0' );
define( 'EXIN_BF_FILE', __FILE__ );
define( 'EXIN_BF_PATH', plugin_dir_path( __FILE__ ) );
define( 'EXIN_BF_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'افزونه بلک فرایدی اکسین به PHP 8.1 یا بالاتر نیاز دارد.', 'exin-black-friday' ) . '</p></div>';
		}
	);
	return;
}

require_once EXIN_BF_PATH . 'autoload.php';

register_activation_hook( __FILE__, array( \Exin\BlackFriday\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Exin\BlackFriday\Plugin::class, 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EXIN_BF_FILE, true );
		}
	}
);
add_action(
	'plugins_loaded',
	function () {
		\Exin\BlackFriday\Plugin::boot();
	},
	30
);
