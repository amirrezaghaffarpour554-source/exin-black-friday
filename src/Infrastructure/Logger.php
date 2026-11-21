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
			'level'        => in_array( $level, array( 'info', 'warning', 'error', 'critical' ), true ) ? $level : 'info',
			'action'       => substr( sanitize_key( $action ), 0, 64 ),
			'campaign_id'  => absint( $context['campaign_id'] ?? 0 ),
			'product_id'   => absint( $context['product_id'] ?? 0 ),
			'job_id'       => substr( sanitize_key( (string) ( $context['job_id'] ?? '' ) ), 0, 64 ),
			'result'       => substr( sanitize_key( (string) ( $context['result'] ?? '' ) ), 0, 64 ),
			'error_code'   => substr( sanitize_key( (string) ( $context['error_code'] ?? '' ) ), 0, 100 ),
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'before_price' => $this->price( $context['before_price'] ?? null ),
			'after_price'  => $this->price( $context['after_price'] ?? null ),
			'currency'     => isset( $context['before_price'] ) ? substr( get_woocommerce_currency(), 0, 12 ) : '',
		);
		if ( false === $wpdb->insert( $wpdb->prefix . 'exin_bf_audit', $row ) ) {
			throw new \RuntimeException( 'audit_write_failed' );
		}
		if ( ( in_array( $row['level'], array( 'error', 'critical' ), true ) || 'stage_late' === $row['error_code'] ) ) {
			try {
				do_action( 'exin_bf_operational_error', $row );
			} catch ( \Throwable $ignored ) {
				update_option( 'exin_bf_notification_error', 'provider_hook_failed', false ); }
		}
	}

	/**
	 * Validate an optional canonical audit price.
	 *
	 * @param mixed $value Value.
	 */
	private function price( $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]+(?:\.[0-9]+)?\z/D', $value ) || strlen( $value ) > 64 ) {
			return null; }
		return $value;
	}

	/**
	 * Retrieve one manager-only audit page.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $page Page.
	 */
	public function history( int $campaign_id, int $page = 1 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d ORDER BY id DESC LIMIT 26 OFFSET %d', $wpdb->prefix . 'exin_bf_audit', $campaign_id, ( max( 1, $page ) - 1 ) * 25 ), ARRAY_A );
		return array(
			'rows'     => array_slice( $rows, 0, 25 ),
			'page'     => $page,
			'has_more' => count( $rows ) > 25,
		);
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
		// Preserve claims until campaign end even for stages longer than log retention.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE (option_name LIKE %s OR option_name LIKE %s) AND CAST(option_value AS UNSIGNED)<%d AND NOT EXISTS (SELECT 1 FROM %i c WHERE c.id=CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(option_name,'_',4),'_',-1) AS UNSIGNED) AND c.end_utc>%s) LIMIT 1000", $wpdb->options, $wpdb->esc_like( 'exin_bf_notice_' ) . '%', $wpdb->esc_like( 'exin_bf_late_' ) . '%', time() - min( 365, max( 7, $days ) ) * DAY_IN_SECONDS, $wpdb->prefix . 'exin_bf_campaigns', gmdate( 'Y-m-d H:i:s' ) ) );
		// Bounded storage even if an administrator configures excessive retention.
		$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET 9999', $table ) );
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id<%d LIMIT 1000', $table, $cutoff ) );
		}
	}
}
