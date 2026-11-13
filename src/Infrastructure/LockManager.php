<?php
/**
 * LockManager campaign module.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Infrastructure;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial state must be read fresh; not Woo entities.

/** Connection-scoped MySQL advisory locks release on crash, with durable diagnostic records. */
final class LockManager {
	/**
	 * Connection-owned lock handles and local reentrant depths.
	 *
	 * @var array<string,array>
	 */
	private static array $held = array();

	/**
	 * Acquire a connection-scoped advisory lock and durable diagnostic record.
	 *
	 * @param string $lock_resource Logical advisory lock resource.
	 * @param int    $timeout Maximum lock acquisition wait in seconds.
	 *
	 * @return string Operation result.
	 * @throws \RuntimeException When exclusive database lock ownership cannot be established.
	 */
	public function acquire( string $lock_resource, int $timeout = 0 ): string {
		global $wpdb;
		$name = 'exin_bf_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $lock_resource ), 0, 48 );
		if ( isset( self::$held[ $name ] ) ) {
			$this->assert_held( $lock_resource );
			++self::$held[ $name ]['depth'];
			return $name;
		}
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $name, $timeout ) ) ) {
			throw new \RuntimeException( 'lock_busy' );
		}
		$token               = wp_generate_uuid4();
		self::$held[ $name ] = array(
			'depth'    => 1,
			'token'    => $token,
			'resource' => $lock_resource,
		);
		// GET_LOCK is authoritative. Replace only after acquiring its exclusive lock.
		if ( false === $wpdb->replace(
			$wpdb->prefix . 'exin_bf_locks',
			array(
				'resource'    => $lock_resource,
				'token'       => $token,
				'acquired_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		) ) {
			unset( self::$held[ $name ] );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			throw new \RuntimeException( 'lock_record_failed' );
		}
		return $name;
	}

	/**
	 * Fail closed if the current database connection no longer owns the lock.
	 *
	 * @param string $lock_resource Logical advisory lock resource.
	 *
	 * @return void Operation result.
	 * @throws \RuntimeException When exclusive database lock ownership cannot be established.
	 */
	public function assert_held( string $lock_resource ): void {
		global $wpdb;
		$name = 'exin_bf_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $lock_resource ), 0, 48 );
		if ( ! isset( self::$held[ $name ] ) || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)=CONNECTION_ID()', $name ) ) ) {
			unset( self::$held[ $name ] );
			throw new \RuntimeException( 'lock_connection_lost' );
		}
	}

	/**
	 * Release a reentrant advisory lock and its matching diagnostic record.
	 *
	 * @param string $name Plugin table suffix or advisory lock name.
	 *
	 * @return void Operation result.
	 */
	public function release( string $name ): void {
		global $wpdb;
		if ( ! isset( self::$held[ $name ] ) || --self::$held[ $name ]['depth'] > 0 ) {
			return;
		}
		$held = self::$held[ $name ];
		unset( self::$held[ $name ] );
		$wpdb->delete(
			$wpdb->prefix . 'exin_bf_locks',
			array(
				'resource' => $held['resource'],
				'token'    => $held['token'],
			)
		);
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Run a callback while holding an exclusive advisory lock.
	 *
	 * @param string   $lock_resource Logical advisory lock resource.
	 * @param callable $callback Callback invoked under exclusive locks.
	 * @param int      $timeout Maximum lock acquisition wait in seconds.
	 *
	 * @return mixed Operation result.
	 */
	public function run( string $lock_resource, callable $callback, int $timeout = 0 ): mixed {
		$name = $this->acquire( $lock_resource, $timeout );
		try {
			return $callback();
		} finally {
			$this->release( $name );
		}
	}
}
