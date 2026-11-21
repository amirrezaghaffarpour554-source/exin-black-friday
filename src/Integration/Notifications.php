<?php
/**
 * Optional, provider-independent campaign notifications.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Integration;

use Exin\BlackFriday\Infrastructure\CampaignRepository;

/** Claim each event before dispatch: at-most-once, no built-in SMS or cashback engine. */
final class Notifications {
	/**
	 * Receive the campaign repository.
	 *
	 * @param CampaignRepository $repo Repo.
	 */
	public function __construct( private CampaignRepository $repo ) {}
	/**
	 * Register the WooCommerce integration hooks.
	 */
	public function register(): void {
		add_action( 'exin_bf_stage_started', array( $this, 'stage' ), 10, 3 );
		add_action( 'exin_bf_operational_error', array( $this, 'error' ) );
	}
	/**
	 * Dispatch an optional deduplicated stage notification.
	 *
	 * @param int $id Id.
	 * @param int $version Version.
	 * @param int $stage Stage.
	 */
	public function stage( int $id, int $version, int $stage ): void {
		$campaign = $this->repo->get( $id );
		if ( ! $campaign || ! CampaignContext::is_live( $campaign ) || $campaign['version'] !== $version ) {
			return; }
		$this->send(
			$campaign,
			0 === $stage ? 'start' : 'stage',
			array(
				'stage'   => $stage,
				'version' => $version,
			),
			$version . '_' . $stage
		);
	}
	/**
	 * Dispatch an optional deduplicated operational error notification.
	 *
	 * @param array $row Row.
	 */
	public function error( array $row ): void {
		$campaign = $this->repo->get( (int) $row['campaign_id'] );
		if ( ! $campaign ) {
			return; }
		$this->send( $campaign, 'error', array_intersect_key( $row, array_flip( array( 'campaign_id', 'product_id', 'error_code', 'action' ) ) ), $campaign['version'] . '_' . $row['product_id'] . '_' . hash( 'sha256', $row['action'] . $row['error_code'] ) );
	}
	/**
	 * Claim and dispatch one configured provider notification.
	 *
	 * @param array  $campaign Campaign.
	 * @param string $event Event.
	 * @param array  $payload Payload.
	 * @param string $suffix Suffix.
	 */
	private function send( array $campaign, string $event, array $payload, string $suffix ): void {
		$config = $campaign['settings']['notifications'] ?? array();
		if ( empty( $config['enabled'] ) || empty( $config[ $event ] ) ) {
			return; }
		$ready = has_action( 'exin_bf_notification' ) && apply_filters( 'exin_bf_notification_provider_ready', false, $event, $campaign['id'] );
		if ( ! $ready && ! ( 'error' === $event && ! empty( $config['admin_email'] ) ) ) {
			return; }
		$key = 'exin_bf_notice_' . $campaign['id'] . '_' . $event . '_' . $suffix;
		if ( ! add_option( $key, time(), '', false ) ) {
			return; }
		$payload['campaign_id'] = $campaign['id'];
		$payload['event']       = $event;
		try {
			// An external service must explicitly subscribe and provide its own credentials.
			if ( $ready ) {
				do_action( 'exin_bf_notification', $event, $payload ); }
			if ( 'error' === $event && ! empty( $config['admin_email'] ) ) {
				// translators: 1: campaign ID, 2: product ID, 3: private error code.
				wp_mail( get_option( 'admin_email' ), __( 'خطای پردازش کمپین اکسین', 'exin-black-friday' ), sprintf( __( 'کمپین %1$d، محصول %2$d، کد پیگیری: %3$s', 'exin-black-friday' ), $campaign['id'], (int) ( $payload['product_id'] ?? 0 ), (string) ( $payload['error_code'] ?? '' ) ) );
			}
		} catch ( \Throwable $error ) {
			update_option( 'exin_bf_notification_error', 'provider_hook_failed', false ); }
	}
}
