<?php
/**
 * Logger campaign module.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Infrastructure;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial state must be read fresh; not Woo entities.

/** Explicit whitelist deliberately excludes free-form messages and customer data. */
final class Logger {
	/**
	 * Persist a strictly allowlisted audit record without customer information.
	 *
	 * @param string $level Operational severity level.
	 * @param string $action Stable operation identifier.
	 * @param array  $context Allowlisted identifiers and diagnostic codes.
	 *
	 * @return void Operation result.
	 * @throws \RuntimeException When the audit record cannot be durably persisted.
	 */
	public function log( string $level, string $action, array $context = array() ): void {
		global $wpdb;
		$row = array(
			'level'       => in_array( $level, array( 'info', 'warning', 'error', 'critical' ), true ) ? $level : 'info',
			'action'      => substr( sanitize_key( $action ), 0, 64 ),
			'campaign_id' => absint( $context['campaign_id'] ?? 0 ),
			'product_id'  => absint( $context['product_id'] ?? 0 ),
			'job_id'      => substr( sanitize_key( (string) ( $context['job_id'] ?? '' ) ), 0, 64 ),
			'result'      => substr( sanitize_key( (string) ( $context['result'] ?? '' ) ), 0, 64 ),
			'error_code'  => substr( sanitize_key( (string) ( $context['error_code'] ?? '' ) ), 0, 100 ),
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( false === $wpdb->insert( $wpdb->prefix . 'exin_bf_audit', $row ) ) {
			throw new \RuntimeException( 'audit_write_failed' );
		}
	}

	/**
	 * Read a bounded page of recent operational audit entries.
	 *
	 * @param int $limit Maximum number of records.
	 *
	 * @return array Operation result.
	 */
	public function recent( int $limit = 30 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $wpdb->prefix . 'exin_bf_audit', min( 100, max( 1, $limit ) ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove expired audit records and enforce bounded log storage.
	 *
	 * @param int $days Audit retention period in days.
	 *
	 * @return void Operation result.
	 */
	public function prune( int $days = 90 ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'exin_bf_audit';
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at<%s LIMIT 1000', $table, gmdate( 'Y-m-d H:i:s', time() - min( 365, max( 7, $days ) ) * DAY_IN_SECONDS ) ) );
		// Bounded storage even if an administrator configures excessive retention.
		$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET 9999', $table ) );
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id<%d LIMIT 1000', $table, $cutoff ) );
		}
	}
}
