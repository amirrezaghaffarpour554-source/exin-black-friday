<?php
/**
 * Dependency checks without loading classes from absent integrations.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday;

/** Dependency checks never load classes from absent integrations. */
final class Compatibility {
	/**
	 * Return stable codes without translating before the WordPress init hook.
	 *
	 * @return string[] Dependency error codes.
	 */
	public static function errors(): array {
		global $wp_version;
		$errors = array();
		if ( version_compare( (string) $wp_version, '6.5', '<' ) ) {
			$errors[] = 'wordpress_version';
		}
		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
			$errors[] = 'woocommerce_missing';
		} elseif ( version_compare( WC_VERSION, '8.2', '<' ) ) {
			$errors[] = 'woocommerce_version';
		}
		return $errors;
	}

	/**
	 * Display translated dependency errors to authorized administrators.
	 *
	 * @param string[] $errors Error codes or localized administrative messages.
	 * @return void
	 */
	public static function notice( array $errors ): void {
		add_action(
			'admin_notices',
			static function () use ( $errors ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				foreach ( $errors as $error ) {
					$messages = array(
						'wordpress_version'   => __( 'وردپرس 6.5 یا بالاتر لازم است.', 'exin-black-friday' ),
						'woocommerce_missing' => __( 'برای اجرای کمپین، ووکامرس را فعال کنید.', 'exin-black-friday' ),
						'woocommerce_version' => __( 'ووکامرس 8.2 یا بالاتر لازم است.', 'exin-black-friday' ),
						'schema_unavailable'  => __( 'ساختار داده کمپین آماده نیست؛ دسترسی دیتابیس و نصب افزونه را بررسی کنید.', 'exin-black-friday' ),
					);
					$error    = $messages[ $error ] ?? $error;
					echo '<div class="notice notice-error"><p><strong>EXIN Black Friday:</strong> ' . esc_html( $error ) . '</p></div>';
				}
			}
		);
	}
}
