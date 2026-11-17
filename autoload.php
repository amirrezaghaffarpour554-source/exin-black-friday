<?php
/**
 * Small runtime autoloader; production has no Composer dependency.
 *
 * @package ExinBlackFriday
 */

defined( 'ABSPATH' ) || defined( 'EXIN_BF_TESTING' ) || exit;

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'Exin\\BlackFriday\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/D', $relative ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);
