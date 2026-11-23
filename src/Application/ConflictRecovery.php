<?php
/** Administrator-approved recovery of unowned external price conflicts.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Application;

use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Cache;
use Exin\BlackFriday\Infrastructure\LockManager;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Integration\CampaignContext;

/** A signed preview, one transactional rebase, then the existing price worker. */
final class ConflictRecovery {

	public function __construct( private CampaignRepository $repo ) {}

	/** Preview is read-only apart from the existing short-lived lock records. */
	public function preview( int $campaign_id, int $product_id ): array {
		return $this->locked( $campaign_id, $product_id, function () use ( $campaign_id, $product_id ): array {
			$data = $this->inspect( $campaign_id, $product_id );
			$data['expires'] = time() + 900;
			$data['user'] = get_current_user_id();
			$payload = base64_encode( wp_json_encode( $data, JSON_THROW_ON_ERROR ) );
			return $data + array( 'token' => $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ) );
		} );
	}

	/** Commit the approved baseline and its durable retry atomically, never Woo prices. */
	public function approve( int $campaign_id, int $product_id, string $token ): array {
		return $this->locked( $campaign_id, $product_id, function () use ( $campaign_id, $product_id, $token ): array {
			$parts = explode( '.', $token );
			if ( strlen( $token ) > 8192 || count( $parts ) !== 2 || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) {
				throw new \DomainException( 'recovery_preview_changed' );
			}
			$approved = json_decode( (string) base64_decode( $parts[0], true ), true );
			if ( ! is_array( $approved ) || ( $approved['user'] ?? 0 ) !== get_current_user_id() || ( $approved['campaign_id'] ?? 0 ) !== $campaign_id || ( $approved['product_id'] ?? 0 ) !== $product_id ) {
				throw new \DomainException( 'recovery_preview_changed' );
			}
			$item = $this->repo->item( $campaign_id, $product_id );
			$receipt = hash( 'sha256', $token );
			if ( ( $item['snapshot']['recovery_receipt'] ?? '' ) === $receipt ) {
				return array( 'pricing' => 'already_approved', 'status' => $item['status'] );
			}
			$current = $this->inspect( $campaign_id, $product_id );
			if ( ( $approved['expires'] ?? 0 ) < time() || array_diff_assoc( $current, array_intersect_key( $approved, $current ) ) ) {
				throw new \DomainException( 'recovery_preview_changed' );
			}
			$c = $this->repo->get( $campaign_id );
			clean_post_cache( $product_id );
			$p = wc_get_product( $product_id );
			$snapshot = PriceState::capture( $p );
			if ( hash( 'sha256', wp_json_encode( $snapshot ) ) !== $current['state_hash'] || DiscountPlan::index( $c, time() ) !== $current['stage'] ) {
				throw new \DomainException( 'recovery_preview_changed' );
			}
			$snapshot += array( 'currency' => get_woocommerce_currency(), 'precision' => wc_get_price_decimals(), 'captured_at' => gmdate( 'Y-m-d H:i:s' ), 'recovery_receipt' => $receipt, 'recovery_approved_by' => get_current_user_id(), 'previous_campaign_state' => $item );
			$schedule = DiscountPlan::automatic( $c ) ? array() : DiscountPlan::schedule( $snapshot['regular'], $item['floor_price'], $c );
			$this->repo->transaction( function () use ( $campaign_id, $product_id, $c, $item, $snapshot, $schedule, $receipt ): void {
				$this->repo->put_item( $campaign_id, $product_id, array( 'base_price' => $snapshot['regular'], 'snapshot' => $snapshot, 'schedule' => $schedule, 'expected' => null, 'intent' => null, 'retry_state' => null, 'operation_id' => '', 'status' => 'included', 'conflict' => '' ) );
				$args = array( $campaign_id, $product_id, $c['version'], 'apply', 0 );
				if ( ! as_has_scheduled_action( 'exin_bf_retry', $args, Scheduler::GROUP ) && ! as_enqueue_async_action( 'exin_bf_retry', $args, Scheduler::GROUP, true ) ) {
					throw new \RuntimeException( 'recovery_queue_failed' );
				}
				( new Logger() )->log( 'warning', 'conflict_rebased', array( 'campaign_id' => $campaign_id, 'product_id' => $product_id, 'job_id' => $receipt, 'result' => 'approved', 'before_price' => $item['base_price'], 'after_price' => $snapshot['regular'] ) );
			} );
			try {
				Cache::invalidate( $product_id );
				$result = ( new PriceProcessor( $this->repo, new Logger() ) )->apply( $campaign_id, $product_id, $c['version'], $current['stage'] );
			} catch ( \Throwable $error ) {
				$result = 'pending';
			}
			$item = $this->repo->item( $campaign_id, $product_id );
			return array( 'pricing' => in_array( $result, array( 'changed', 'unchanged' ), true ) ? $result : ( 'conflict' === $item['status'] ? 'blocked' : 'pending' ), 'status' => $item['status'], 'conflict' => $item['conflict'] );
		} );
	}

	/** Fresh validation shared by preview and confirmation; no caller-supplied prices. */
	private function inspect( int $campaign_id, int $product_id ): array {
		$c = $this->repo->get( $campaign_id );
		$i = $this->repo->item( $campaign_id, $product_id );
		clean_post_cache( $product_id );
		$p = wc_get_product( $product_id );
		if ( ! CampaignContext::is_live( $c ) || 'active' !== $c['status'] || ! empty( $c['settings']['restoring'] ) ) {
			throw new \DomainException( 'recovery_campaign_not_live' );
		}
		if ( ! $i || 'conflict' !== $i['status'] || 'external_price_changed' !== $i['conflict'] || $i['expected'] || $i['intent'] || $i['retry_state'] || $this->repo->owned_elsewhere( $campaign_id, $product_id ) ) {
			throw new \DomainException( 'recovery_unsupported_conflict' );
		}
		$winner = $this->repo->winner_for_product( $product_id, time() );
		if ( ( $winner && (int) $winner['id'] !== $campaign_id ) || ! $p || ! CampaignContext::purchasable_product( $p ) || PriceState::dynamic( $p ) ) {
			throw new \DomainException( 'recovery_unsafe_product' );
		}
		if ( ( $c['settings']['currency'] ?? '' ) !== get_woocommerce_currency() || (int) ( $c['settings']['precision'] ?? -1 ) !== wc_get_price_decimals() || ( $i['snapshot']['currency'] ?? '' ) !== get_woocommerce_currency() || (int) ( $i['snapshot']['precision'] ?? -1 ) !== wc_get_price_decimals() ) {
			throw new \DomainException( 'store_currency_or_precision_changed' );
		}
		$state = PriceState::capture( $p );
		if ( ! FloorGuard::positive( $state['price'], wc_get_price_decimals() ) || ! in_array( $state['price'], array( $state['regular'], $state['sale'] ), true ) || ( '' !== $state['sale'] && ! FloorGuard::positive( $state['sale'], wc_get_price_decimals(), $state['regular'] ) ) ) {
			throw new \DomainException( 'recovery_unsafe_product' );
		}
		if ( ! FloorGuard::positive( $state['regular'], wc_get_price_decimals() ) || ! FloorGuard::positive( $i['floor_price'], wc_get_price_decimals(), $state['regular'] ) ) {
			throw new \DomainException( 'recovery_invalid_base_floor' );
		}
		$stage = DiscountPlan::index( $c, time() );
		if ( $stage < 0 || $stage >= DiscountPlan::stage_count( $c ) ) {
			throw new \DomainException( 'recovery_campaign_not_live' );
		}
		if ( DiscountPlan::automatic( $c ) ) {
			// Never change an existing cohort or invent one during an approval preview.
			if ( in_array( $c['settings']['discount_plan']['mode'], array( 'auto_rotation', 'auto_random', 'auto_hybrid' ), true ) ) {
				global $wpdb;
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE campaign_id=%d AND version=%d AND stage=%d AND product_id=0', $wpdb->prefix . 'exin_bf_turns', $campaign_id, $c['version'], $stage ) ) ) {
					throw new \DomainException( 'recovery_cohort_not_ready' );
				}
			}
			$selection = new AutomaticSelection( $this->repo );
			$price = DiscountPlan::automatic_price( $state['regular'], $i['floor_price'], $c, $stage, $product_id, $selection->selected( $c, $product_id, $stage, false ), 'remaining_floor' === $c['settings']['discount_plan']['last_day'] ? $selection->remaining( $c, $product_id, $stage ) : true, true );
		} else {
			$price = DiscountPlan::schedule( $state['regular'], $i['floor_price'], $c )[ $stage ];
		}
		// Routine worker progress must not invalidate an otherwise identical approval.
		$bound_campaign = $c;
		unset( $bound_campaign['updated_at'], $bound_campaign['settings']['last_run'] );
		return array( 'campaign_id' => $campaign_id, 'product_id' => $product_id, 'version' => $c['version'], 'stage' => $stage, 'old_base' => $i['base_price'], 'base' => $state['regular'], 'current_price' => $state['price'], 'floor' => $i['floor_price'], 'preview_price' => $price, 'reason' => $i['conflict'], 'state_hash' => hash( 'sha256', wp_json_encode( $state ) ), 'item_hash' => hash( 'sha256', wp_json_encode( $i ) ), 'campaign_hash' => hash( 'sha256', wp_json_encode( $bound_campaign ) ) );
	}

	/** Same lock ordering as the existing worker and Woo financial saves. */
	private function locked( int $campaign_id, int $product_id, callable $callback ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			throw new \DomainException( 'recovery_permission_denied' );
		}
		return ( new LockManager() )->run( 'campaign:' . $campaign_id, fn(): array => ( new LockManager() )->run( 'product:' . $product_id, $callback ) );
	}
}
