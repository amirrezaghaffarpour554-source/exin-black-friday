<?php
/**
 * Migration campaign module.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Infrastructure;

use Exin\BlackFriday\Domain\CurrencyDisplay;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial state must be read fresh; not Woo entities.

/** Creates and upgrades durable campaign database tables. */
final class Migration {
	public const VERSION = '7';

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
            floor_override tinyint NOT NULL DEFAULT 1,
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
            before_price varchar(64) NULL,
            after_price varchar(64) NULL,
            currency varchar(12) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY campaign_created (campaign_id,created_at),
            KEY created_at (created_at)
        ) $charset;"
		);
		dbDelta(
			"CREATE TABLE {$prefix}turns (
            campaign_id bigint unsigned NOT NULL,
            version bigint unsigned NOT NULL,
            stage int unsigned NOT NULL,
            product_id bigint unsigned NOT NULL,
            applied tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY  (campaign_id,version,stage,product_id),
            KEY product_turns (campaign_id,version,product_id,stage)
        ) ENGINE=InnoDB $charset;"
		);

		dbDelta(
			"CREATE TABLE {$prefix}adaptive_decisions (
            campaign_id bigint unsigned NOT NULL,
            version bigint unsigned NOT NULL,
            product_id bigint unsigned NOT NULL,
            stage int unsigned NOT NULL,
            decision longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (campaign_id,version,product_id,stage)
        ) ENGINE=InnoDB $charset;"
		);
		dbDelta(
			"CREATE TABLE {$prefix}adaptive_sales (
            order_id bigint unsigned NOT NULL,
            campaign_id bigint unsigned NOT NULL,
            product_id bigint unsigned NOT NULL,
            created_at datetime NOT NULL,
            units int unsigned NOT NULL,
            PRIMARY KEY  (order_id,campaign_id,product_id),
            KEY recent_sales (campaign_id,product_id,created_at)
        ) ENGINE=InnoDB $charset;"
		);
		foreach ( array( 'campaigns', 'items', 'locks', 'audit', 'turns', 'adaptive_decisions', 'adaptive_sales' ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . $table ) ) ) !== $prefix . $table ) {
				throw new \RuntimeException( 'schema_creation_failed' );
			}
		}
		if ( 'retry_state' !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $prefix . 'items', 'retry_state' ) ) ) {
			throw new \RuntimeException( 'schema_retry_state_missing' );
		}
		foreach ( array(
			'items' => array( 'floor_override' ),
			'audit' => array( 'before_price', 'after_price', 'currency' ),
		) as $table => $columns ) {
			foreach ( $columns as $column ) {
				if ( $column !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $prefix . $table, $column ) ) ) {
					throw new \RuntimeException( 'schema_campaign_extension_missing' ); }
			}
		}
		$stage = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $prefix . 'turns', 'stage' ), ARRAY_A );
		if ( ! $stage || ! preg_match( '/^int(?:\(\d+\))? unsigned$/', $stage['Type'] ) ) {
			throw new \RuntimeException( 'schema_automatic_stage_capacity_missing' );
		}
		if ( version_compare( (string) get_option( 'exin_bf_schema_version', '0' ), '3', '<' ) ) {
			self::upgrade_settings( $prefix . 'campaigns' ); }
		update_option( 'exin_bf_schema_version', self::VERSION, false );
	}

	/**
	 * Remove retired presentation settings while preserving all financial state.
	 *
	 * @param string $table Plugin-owned campaign table.
	 * @return void
	 * @throws \RuntimeException When a stored settings document cannot be migrated.
	 */
	private static function upgrade_settings( string $table ): void {
		global $wpdb;
		$after = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, settings FROM %i WHERE id>%d ORDER BY id ASC LIMIT 100', $table, $after ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'settings_upgrade_read_failed' );
			}
			foreach ( $rows as $row ) {
				$after    = (int) $row['id'];
				$settings = json_decode( $row['settings'], true, 512, JSON_THROW_ON_ERROR );
				if ( ! is_array( $settings ) ) {
					throw new \RuntimeException( 'invalid_legacy_campaign_settings' );
				}
				unset( $settings['target_orders'], $settings['show_out_of_stock'] );
				if ( ! array_key_exists( 'shipping_subsidy_cap', $settings ) ) {
					$currency                         = (string) ( $settings['currency'] ?? ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ) );
					$precision                        = (int) ( $settings['precision'] ?? ( function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 ) );
					$settings['shipping_subsidy_cap'] = CurrencyDisplay::default_subsidy_cap( $currency, $precision );
				}
				if ( false === $wpdb->update( $table, array( 'settings' => wp_json_encode( $settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) ), array( 'id' => $after ) ) ) {
					throw new \RuntimeException( 'settings_upgrade_failed' );
				}
			}
			$count = count( $rows );
		} while ( 100 === $count );
	}
}
