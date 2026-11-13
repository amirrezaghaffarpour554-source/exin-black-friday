<?php
/**
 * Migration campaign module.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Infrastructure;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial state must be read fresh; not Woo entities.

/** Creates and upgrades durable campaign database tables. */
final class Migration {
	public const VERSION = '2';

	/**
	 * Create the current schema and record its independent migration version.
	 *
	 * @return void Operation result.
	 * @throws \RuntimeException When the required plugin-owned schema cannot be created.
	 */
	public static function run(): void {
		global $wpdb;
		if ( get_option( 'exin_bf_schema_version' ) === self::VERSION ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix . 'exin_bf_';
		dbDelta(
			"CREATE TABLE {$prefix}campaigns (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(200) NOT NULL,
            start_utc datetime NOT NULL,
            end_utc datetime NOT NULL,
            timezone varchar(100) NOT NULL DEFAULT 'Asia/Tehran',
            days smallint unsigned NOT NULL DEFAULT 7,
            strategy varchar(20) NOT NULL DEFAULT 'linear',
            power smallint unsigned NOT NULL DEFAULT 2,
            rounding varchar(20) NOT NULL DEFAULT 'half_up',
            status varchar(20) NOT NULL DEFAULT 'draft',
            version bigint unsigned NOT NULL DEFAULT 1,
            settings longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            last_success datetime NULL,
            last_error varchar(100) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY status_start (status,start_utc)
        ) $charset;"
		);
		dbDelta(
			"CREATE TABLE {$prefix}items (
            campaign_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            base_price varchar(64) NOT NULL,
            floor_price varchar(64) NOT NULL,
            schedule longtext NOT NULL,
            snapshot longtext NOT NULL,
            expected longtext NULL,
            intent longtext NULL,
            retry_state longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'included',
            operation_id varchar(64) NOT NULL DEFAULT '',
            conflict varchar(100) NOT NULL DEFAULT '',
            last_changed datetime NOT NULL,
            priority int NOT NULL DEFAULT 0,
            featured tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY  (campaign_id,product_id),
            KEY campaign_status (campaign_id,status),
            KEY product_status (product_id,status),
            KEY display_order (campaign_id,priority,product_id)
        ) $charset;"
		);
		dbDelta(
			"CREATE TABLE {$prefix}locks (
            resource varchar(150) NOT NULL,
            token varchar(64) NOT NULL,
            acquired_at datetime NOT NULL,
            PRIMARY KEY  (resource)
        ) $charset;"
		);
		dbDelta(
			"CREATE TABLE {$prefix}audit (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(12) NOT NULL,
            action varchar(64) NOT NULL,
            campaign_id bigint unsigned NOT NULL DEFAULT 0,
            product_id bigint unsigned NOT NULL DEFAULT 0,
            job_id varchar(64) NOT NULL DEFAULT '',
            result varchar(64) NOT NULL DEFAULT '',
            error_code varchar(100) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY campaign_created (campaign_id,created_at),
            KEY created_at (created_at)
        ) $charset;"
		);
		foreach ( array( 'campaigns', 'items', 'locks', 'audit' ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . $table ) ) ) !== $prefix . $table ) {
				throw new \RuntimeException( 'schema_creation_failed' );
			}
		}
		if ( 'retry_state' !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $prefix . 'items', 'retry_state' ) ) ) {
			throw new \RuntimeException( 'schema_retry_state_missing' );
		}
		update_option( 'exin_bf_schema_version', self::VERSION, false );
	}
}
