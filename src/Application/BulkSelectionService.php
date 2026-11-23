<?php
/**
 * Resumable, bounded campaign selection with ownership-aware live enrollment.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Application;

use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Domain\IntegerMath;
use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\LockManager;

/** Stores only a scope, cursor and small progress record; never the full catalog. */
final class BulkSelectionService {
	/**
	 * Connect the existing campaign repository.
	 *
	 * @param CampaignRepository $repo Durable campaign and membership storage.
	 */
	public function __construct( private CampaignRepository $repo ) {}

	/**
	 * Start an explicitly requested draft or active campaign selection job.
	 *
	 * @param int    $campaign_id Editable campaign identifier.
	 * @param array  $filters Frozen product scope and catalog filters.
	 * @param array  $policy Canonical store-unit floor policy.
	 * @param string $action Add new live items, edit draft floors, or safely remove membership.
	 * @return array Small resumable progress document.
	 * @throws \DomainException When another job runs or the draft is not editable.
	 */
	public function start( int $campaign_id, array $filters, array $policy, string $action = 'add' ): array {
		return ( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $campaign_id, $filters, $policy, $action ): array {
				$campaign = $this->editable( $campaign_id );
				if ( ! in_array( $action, array( 'add', 'remove' ), true ) ) {
					throw new \InvalidArgumentException( 'invalid_selection_action' );
				}
				$old = get_option( $this->key( $campaign_id ), array() );
				if ( is_array( $old ) && ! empty( $old['token'] ) && empty( $old['complete'] ) ) {
					throw new \DomainException( 'selection_job_in_progress' );
				}
				$filters    = $this->filters( $filters );
				$remove_all = 'remove' === $action && ( ! empty( $filters['campaign_only'] ) || in_array( $filters['scope'], array( 'all', 'instock' ), true ) || array( 'scope' ) === array_keys( $filters ) );
				if ( $remove_all ) {
					$filters = array(
						'scope'              => 'filtered',
						'source_campaign_id' => $campaign_id,
						'campaign_only'      => true,
					);
				}
				$source = (int) ( $filters['source_campaign_id'] ?? 0 );
				if ( $source && ( ! $this->repo->get( $source ) || ( $source !== $campaign_id && $this->repo->has_ownership( $source ) ) ) ) {
					throw new \DomainException( 'source_campaign_prices_not_restored' );
				}
				$query = new CatalogProductQuery();
				$upper = $remove_all ? $this->repo->item_ceiling( $campaign_id ) : $query->ceiling();
				$total = $remove_all ? $this->repo->count_items( $campaign_id ) : $query->count_candidates( $filters, $upper );
				$job   = array(
					'token'           => wp_generate_uuid4(),
					'campaign_id'     => $campaign_id,
					'version'         => (int) $campaign['version'],
					'owner'           => get_current_user_id(),
					'action'          => $action,
					'filters'         => $filters,
					'policy'          => $this->policy( $policy ),
					'after'           => 0,
					'upper'           => $upper,
					'total'           => $total,
					'processed'       => 0,
					'added'           => 0,
					'updated'         => 0,
					'removed'         => 0,
					'skipped'         => 0,
					'failed'          => 0,
					'rejected'        => 0,
					'errors'          => array(),
					'complete'        => 0 === $total,
					'status'          => 0 === $total ? 'completed' : 'running',
					'started_at'      => time(),
					'live'            => 'active' === $campaign['status'],
					'pending_product' => 0,
				);
				if ( 'add' === $action && 'draft' === $campaign['status'] ) {
					$settings                    = $campaign['settings'];
					$settings['selection_rules'] = array(
						'filters' => $filters,
						'policy'  => $job['policy'],
					);
					$this->repo->save( $campaign_id, array( 'settings' => $settings ) );
				}
				$this->persist( $campaign_id, $job );
				return $this->summary( $job );
			}
		);
	}

	/**
	 * Process a bounded stable batch: ten live candidates or twenty-five draft candidates.
	 *
	 * @param int    $campaign_id Job's campaign identifier.
	 * @param string $token Unpredictable token returned by start/status.
	 * @return array Progress, bounded errors and completion state.
	 */
	public function step( int $campaign_id, string $token ): array {
		return ( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $campaign_id, $token ): array {
				$job = $this->load( $campaign_id, $token );
				if ( $job['complete'] ) {
					return $this->summary( $job );
				}
				$campaign = $this->editable( $campaign_id );
				if ( (int) $campaign['version'] !== (int) $job['version'] || (bool) ( $job['live'] ?? false ) !== ( 'active' === $campaign['status'] ) ) {
					$job['complete'] = true;
					$job['status']   = 'failed';
					$this->error( $job, 0, 'selection_campaign_changed' );
					$this->persist( $campaign_id, $job );
					return $this->summary( $job );
				}
				$limit           = ! empty( $job['live'] ) ? 10 : 25;
				$membership_only = 'remove' === $job['action'] && ! empty( $job['filters']['campaign_only'] );
				if ( $membership_only ) {
					$rows  = $this->repo->batch( $campaign_id, (int) $job['after'], $limit );
					$ids   = array_values( array_filter( array_map( static fn( $row ) => (int) $row['product_id'], $rows ), static fn( $id ) => $id <= (int) $job['upper'] ) );
					$batch = array(
						'ids'   => $ids,
						'after' => $ids ? max( $ids ) : (int) $job['upper'],
						'done'  => count( $rows ) < $limit || count( $ids ) !== count( $rows ) || ( $ids && max( $ids ) >= (int) $job['upper'] ),
					);
				} else {
					$batch = ( new CatalogProductQuery() )->scan( $job['filters'], (int) $job['after'], (int) $job['upper'], $limit );
				}
				$selection = new ProductSelectionService( $this->repo );
				foreach ( $batch['ids'] as $product_id ) {
					$item = $this->repo->item( $campaign_id, $product_id );
					if ( $membership_only && ( ! $item || 'removed' === $item['status'] ) ) {
						continue;
					}
					$live_new = ! empty( $job['live'] ) && 'add' === $job['action'] && ( ! $item || 'removed' === $item['status'] || (int) ( $job['pending_product'] ?? 0 ) === $product_id );
					if ( $live_new ) {
						$job['pending_product'] = $product_id;
						$this->persist( $campaign_id, $job ); }
					++$job['processed'];
					try {
						if ( 'remove' === $job['action'] ) {
							if ( ! $item || 'removed' === $item['status'] ) {
								++$job['skipped'];
							} else {
								$selection->remove( $campaign_id, $product_id );
								++$job['removed'];
							}
						} elseif ( $live_new && $item && 'removed' !== $item['status'] ) {
							$selection->apply_current( $campaign_id, $product_id );
							++$job['added'];
						} elseif ( $item && 'removed' !== $item['status'] ) {
							if ( ! empty( $job['live'] ) || 'none' === $job['policy']['mode'] || ( ! empty( $item['floor_override'] ) && ! array_key_exists( $product_id, $job['policy']['floors'] ?? array() ) ) ) {
								++$job['skipped'];
							} else {
								$selection->update(
									$campaign_id,
									$product_id,
									array(
										'floor_policy' => $job['policy'],
										'source_campaign_id' => (int) ( $job['filters']['source_campaign_id'] ?? 0 ),
									)
								);
								++$job['updated'];
							}
						} else {
							$selection->add(
								$campaign_id,
								$product_id,
								'0',
								array(
									'floor_policy'       => $job['policy'],
									'source_campaign_id' => (int) ( $job['filters']['source_campaign_id'] ?? 0 ),
								)
							);
							if ( ! empty( $job['live'] ) ) {
								$selection->apply_current( $campaign_id, $product_id ); }
							++$job['added'];
						}
					} catch ( \Throwable $error ) {
						$this->error( $job, $product_id, $error->getMessage(), $error instanceof \DomainException || $error instanceof \InvalidArgumentException );
						if ( $live_new && ! ( $error instanceof \DomainException ) && ! ( $error instanceof \InvalidArgumentException ) ) {
							--$job['processed'];
							$job['status'] = 'failed';
							$this->persist( $campaign_id, $job );
							return $this->summary( $job );
						}
					}
					if ( ! empty( $job['live'] ) ) {
						$job['pending_product'] = 0;
						$job['after']           = $product_id;
						$this->persist( $campaign_id, $job ); }
				}
				$job['after']    = $batch['after'];
				$job['complete'] = (bool) $batch['done'];
				$job['status']   = $job['complete'] ? 'completed' : 'running';
				$this->persist( $campaign_id, $job );
				return $this->summary( $job );
			}
		);
	}

	/**
	 * Read progress for activation checks; only the creator receives its token.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @return array Empty when no job exists, otherwise small progress state.
	 */
	public function status( int $campaign_id ): array {
		$job = get_option( $this->key( $campaign_id ), array() );
		if ( ! is_array( $job ) || empty( $job['token'] ) ) {
			return array();
		}
		$result               = $this->summary( $job );
		$result['can_resume'] = (int) ( $job['owner'] ?? -1 ) === get_current_user_id();
		if ( ! $result['can_resume'] ) {
			unset( $result['token'] );
		}
		return $result;
	}

	/**
	 * Cancel pending selection work without touching enrolled prices or snapshots.
	 *
	 * @param int    $campaign_id Draft campaign identifier.
	 * @param string $token Current owner's job token.
	 * @return array Completed cancellation state.
	 */
	public function cancel( int $campaign_id, string $token ): array {
		return ( new LockManager() )->run(
			'campaign:' . $campaign_id,
			function () use ( $campaign_id, $token ): array {
				$job             = $this->load( $campaign_id, $token );
				$job['complete'] = true;
				$job['status']   = 'cancelled';
				$this->persist( $campaign_id, $job );
				return $this->summary( $job );
			}
		);
	}

	/**
	 * Count the scope and simulate five representative rows without enrollment.
	 *
	 * @throws \DomainException When a live sample has no positive floor.
	 *
	 * @param int   $campaign_id Draft identifier.
	 * @param array $filters Product scope and filters.
	 * @param array $policy Canonical floor policy.
	 * @return array Counts, current timing, sample schedules and safe warnings.
	 */
	public function preview( int $campaign_id, array $filters, array $policy ): array {
		$campaign  = $this->editable( $campaign_id );
		$filters   = $this->filters( $filters );
		$policy    = $this->policy( $policy );
		$query     = new CatalogProductQuery();
		$upper     = $query->ceiling();
		$count     = $query->count_candidates( $filters, $upper );
		$available = array_replace(
			$filters,
			array(
				'scope' => 'filtered',
				'stock' => 'instock',
			)
		);
		$result    = array(
			'count'     => $count,
			'total'     => $count,
			'in_stock'  => $query->count_candidates( $available, $upper ),
			'start_utc' => $campaign['start_utc'],
			'end_utc'   => $campaign['end_utc'],
			'samples'   => array(),
			'warnings'  => array(),
		);
		$selection = new ProductSelectionService( $this->repo );
		foreach ( $query->scan( $filters, 0, $upper, 5 )['ids'] as $product_id ) {
			try {
				if ( 'active' === $campaign['status'] && $this->repo->item( $campaign_id, $product_id ) ) {
					continue; }
				$sample = $selection->preview(
					$campaign_id,
					$product_id,
					'0',
					array(
						'floor_policy'       => $policy,
						'source_campaign_id' => (int) ( $filters['source_campaign_id'] ?? 0 ),
					)
				);
				if ( 'active' === $campaign['status'] && Money::parse( $sample['floor_price'], wc_get_price_decimals() ) <= 0 ) {
					throw new \DomainException( 'product_floor_required' ); }
				$result['samples'][] = $sample;
			} catch ( \Throwable $error ) {
				$result['warnings'][] = array(
					'product_id' => $product_id,
					'code'       => $this->safe_code( $error->getMessage() ),
				);
			}
		}
		return $result;
	}

	/**
	 * Resolve a floor from the exact immutable base inside the product lock.
	 *
	 * @param CampaignRepository $repo Historical floor repository.
	 * @param array              $campaign Current validated campaign.
	 * @param int                $product_id Sellable product identifier.
	 * @param string             $base Captured immutable campaign base.
	 * @param array              $policy Validated canonical floor policy.
	 * @param int                $source_id Optional source campaign for an exact copied floor.
	 * @return string Exact floor in store currency units.
	 * @throws \DomainException When a floor is missing or incompatible with its base.
	 */
	public static function resolve_floor( CampaignRepository $repo, array $campaign, int $product_id, string $base, array $policy, int $source_id = 0 ): string {
		$precision  = (int) ( $campaign['settings']['precision'] ?? wc_get_price_decimals() );
		$base_minor = Money::parse( $base, $precision );
		$mode       = isset( $policy['floors'][ $product_id ] ) ? 'independent' : ( $policy['mode'] ?? 'none' );
		if ( ! empty( $campaign['settings']['manual_floor_catalog'] ) && ( 'independent' !== $mode || ! isset( $policy['floors'][ $product_id ] ) ) ) {
			throw new \DomainException( 'product_floor_required' );
		}
		$value = (string) ( $policy['value'] ?? '0' );
		switch ( $mode ) {
			case 'none':
				if ( empty( $campaign['settings']['discount_plan'] ) || 'linear' === ( $campaign['settings']['discount_plan']['mode'] ?? '' ) ) {
					throw new \DomainException( 'floor_policy_required' );
				}
				$floor = 0;
				break;
			case 'percent':
				$percent = Money::parse( $value, 2 );
				if ( $percent < 0 || $percent > 10000 ) {
					throw new \DomainException( 'invalid_floor_percent' );
				}
				[ $quotient, $remainder ] = IntegerMath::divide( IntegerMath::multiply( (string) $base_minor, (string) ( 10000 - $percent ) ), '10000' );
				$floor                    = (int) $quotient + ( (int) $remainder >= 5000 ? 1 : 0 );
				break;
			case 'amount':
				$amount = Money::parse( $value, $precision );
				if ( $amount < 0 ) {
					throw new \DomainException( 'invalid_floor_amount' );
				}
				$floor = max( 0, $base_minor - $amount );
				break;
			case 'shared':
				$floor = Money::parse( $value, $precision );
				break;
			case 'independent':
				if ( ! isset( $policy['floors'][ $product_id ] ) ) {
					throw new \DomainException( 'product_floor_required' );
				}
				$floor = Money::parse( (string) $policy['floors'][ $product_id ], $precision );
				break;
			case 'previous':
				$source = $source_id ? $repo->item( $source_id, $product_id ) : null;
				if ( $source && ( ( $source['snapshot']['currency'] ?? '' ) !== ( $campaign['settings']['currency'] ?? get_woocommerce_currency() ) || (int) ( $source['snapshot']['precision'] ?? -1 ) !== $precision ) ) {
					throw new \DomainException( 'previous_floor_currency_changed' );
				}
				$previous = $source ? $source['floor_price'] : $repo->previous_floor( $product_id );
				if ( null === $previous ) {
					throw new \DomainException( 'previous_floor_not_found' );
				}
				$floor = Money::parse( $previous, $precision );
				break;
			default:
				throw new \DomainException( 'invalid_floor_policy' );
		}
		if ( ! empty( $campaign['settings']['manual_floor_catalog'] ) && $floor <= 0 ) {
			throw new \DomainException( 'product_floor_required' );
		}
		if ( $floor < 0 || $floor > $base_minor ) {
			throw new \DomainException( 'floor_exceeds_base' );
		}
		return Money::format( $floor, $precision );
	}

	/**
	 * Load an unowned draft for membership changes.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @return array Durable editable campaign record.
	 * @throws \DomainException When the campaign is expired, restoring, or otherwise not editable.
	 */
	private function editable( int $campaign_id ): array {
		$campaign = $this->repo->get( $campaign_id );
		if ( ! $campaign || 'draft' !== $campaign['status'] || ! empty( $campaign['settings']['restoring'] ) || ! empty( $campaign['settings']['started_at'] ) || $this->repo->has_ownership( $campaign_id ) ) {
			throw new \DomainException( 'selection_requires_editable_campaign' );
		}
		if ( isset( $campaign['settings']['currency'] ) && ( get_woocommerce_currency() !== $campaign['settings']['currency'] || wc_get_price_decimals() !== (int) $campaign['settings']['precision'] ) ) {
			throw new \DomainException( 'store_currency_or_precision_changed' );
		}
		return $campaign;
	}

	/**
	 * Bound every input except the server-side candidate population itself.
	 *
	 * @param array $filters Request scope and catalog selectors.
	 * @return array Frozen bounded filter state.
	 * @throws \InvalidArgumentException When input shape exceeds documented limits.
	 */
	private function filters( array $filters ): array {
		$result = array( 'scope' => (string) ( $filters['scope'] ?? ( ! empty( $filters['ids'] ) ? 'selected' : 'filtered' ) ) );
		if ( ! in_array( $result['scope'], array( 'all', 'instock', 'filtered', 'selected' ), true ) ) {
			throw new \InvalidArgumentException( 'invalid_selection_scope' );
		}
		if ( in_array( $result['scope'], array( 'all', 'instock' ), true ) ) {
			return $result;
		}
		foreach ( array( 'search', 'category', 'stock', 'type', 'taxonomy', 'term', 'publication' ) as $name ) {
			if ( isset( $filters[ $name ] ) && is_scalar( $filters[ $name ] ) && '' !== (string) $filters[ $name ] ) {
				$result[ $name ] = substr( sanitize_text_field( (string) $filters[ $name ] ), 0, 100 );
			}
		}
		foreach ( array( 'parent_id', 'source_campaign_id' ) as $name ) {
			if ( ! empty( $filters[ $name ] ) ) {
				$result[ $name ] = absint( $filters[ $name ] );
			}
		}
		if ( isset( $filters['ids'] ) ) {
			if ( ! is_array( $filters['ids'] ) || count( $filters['ids'] ) > 1000 ) {
				throw new \InvalidArgumentException( 'selection_ids_limit' );
			}
			$result['ids'] = array_values( array_unique( array_filter( array_map( 'absint', $filters['ids'] ) ) ) );
			if ( ! $result['ids'] ) {
				$result['scope'] = 'selected';
			}
		}
		if ( isset( $filters['tax_filters'] ) ) {
			if ( ! is_array( $filters['tax_filters'] ) || count( $filters['tax_filters'] ) > 5 ) {
				throw new \InvalidArgumentException( 'invalid_selection_filters' );
			}
			$result['tax_filters'] = array();
			foreach ( $filters['tax_filters'] as $name => $value ) {
				if ( ! is_string( $name ) || ! is_scalar( $value ) ) {
					throw new \InvalidArgumentException( 'invalid_selection_filters' );
				}
				$result['tax_filters'][ sanitize_key( $name ) ] = substr( sanitize_text_field( (string) $value ), 0, 100 );
			}
		}
		if ( ! empty( $filters['campaign_only'] ) ) {
			$result['campaign_only'] = true;
		}
		return $result;
	}

	/**
	 * Validate only canonical amounts; display-unit conversion stays at REST.
	 *
	 * @param array $policy Floor mode, amount and optional individual floors.
	 * @return array Safe compact floor policy.
	 * @throws \InvalidArgumentException When floor policy is malformed.
	 */
	private function policy( array $policy ): array {
		$mode = $policy['mode'] ?? 'none';
		if ( ! in_array( $mode, array( 'none', 'independent', 'percent', 'amount', 'shared', 'previous' ), true ) ) {
			throw new \InvalidArgumentException( 'invalid_floor_policy' );
		}
		$result = array(
			'mode'   => $mode,
			'value'  => (string) ( $policy['value'] ?? '0' ),
			'floors' => array(),
		);
		if ( in_array( $mode, array( 'percent', 'amount', 'shared' ), true ) ) {
			$minor = Money::parse( $result['value'], 'percent' === $mode ? 2 : wc_get_price_decimals() );
			if ( $minor < 0 || ( 'percent' === $mode && $minor > 10000 ) ) {
				throw new \InvalidArgumentException( 'invalid_floor_policy' );
			}
		}
		if ( ! empty( $policy['floors'] ) ) {
			if ( ! is_array( $policy['floors'] ) || count( $policy['floors'] ) > 1000 ) {
				throw new \InvalidArgumentException( 'selection_ids_limit' );
			}
			foreach ( $policy['floors'] as $id => $value ) {
				if ( ! is_scalar( $value ) || Money::parse( (string) $value, wc_get_price_decimals() ) < 0 || absint( $id ) < 1 ) {
					throw new \InvalidArgumentException( 'invalid_floor_policy' );
				}
				$result['floors'][ absint( $id ) ] = (string) $value;
			}
		}
		return $result;
	}

	/**
	 * Load a job only for its creator, campaign and current unpredictable token.
	 *
	 * @param int    $campaign_id Campaign identifier.
	 * @param string $token Client's current job token.
	 * @return array Private job state.
	 * @throws \DomainException When the job token or owner does not match.
	 */
	private function load( int $campaign_id, string $token ): array {
		$job = get_option( $this->key( $campaign_id ), array() );
		if ( ! is_array( $job ) || empty( $job['token'] ) || ! hash_equals( $job['token'], $token ) || (int) ( $job['owner'] ?? -1 ) !== get_current_user_id() || (int) ( $job['campaign_id'] ?? 0 ) !== $campaign_id ) {
			throw new \DomainException( 'selection_job_not_found' );
		}
		return $job;
	}

	/**
	 * Persist progress and fail closed when the option write was not recorded.
	 *
	 * @param int   $campaign_id Campaign identifier.
	 * @param array $job New job state.
	 * @return void
	 * @throws \RuntimeException When progress storage fails.
	 */
	private function persist( int $campaign_id, array $job ): void {
		update_option( $this->key( $campaign_id ), $job, false );
		$saved = get_option( $this->key( $campaign_id ), array() );
		if ( $saved !== $job ) {
			throw new \RuntimeException( 'selection_progress_write_failed' );
		}
	}

	/**
	 * Expose only small progress state rather than frozen filters or all IDs.
	 *
	 * @param array $job Private job state.
	 * @return array Admin-only progress document.
	 */
	private function summary( array $job ): array {
		$data             = array_intersect_key( $job, array_flip( array( 'token', 'action', 'status', 'complete', 'total', 'processed', 'added', 'updated', 'removed', 'skipped', 'failed', 'rejected', 'errors' ) ) );
		$data['done']     = (bool) $job['complete'];
		$data['selected'] = $this->repo->count_items( (int) $job['campaign_id'] );
		return $data;
	}

	/**
	 * Keep only ten safe error examples while retaining the exact failure count.
	 *
	 * @param array  $job Mutable progress state.
	 * @param int    $product_id Product identifier or zero for a job-wide failure.
	 * @param string $code Internal exception code.
	 * @param bool   $rejected Whether validation rejected this product.
	 * @return void
	 */
	private function error( array &$job, int $product_id, string $code, bool $rejected = false ): void {
		$key         = $rejected ? 'rejected' : 'failed';
		$job[ $key ] = (int) ( $job[ $key ] ?? 0 ) + 1;
		if ( count( $job['errors'] ) < 10 ) {
			$job['errors'][] = array(
				'product_id' => $product_id,
				'code'       => $this->safe_code( $code ),
			);
		}
	}

	/**
	 * Strip arbitrary database/extension messages from job progress.
	 *
	 * @param string $code Exception message.
	 * @return string Stable safe diagnostic code.
	 */
	private function safe_code( string $code ): string {
		return preg_match( '/\A[a-z][a-z0-9_]{2,80}\z/D', $code ) ? $code : 'selection_operation_failed';
	}

	/**
	 * Build a private nonautoloaded progress key for one campaign.
	 *
	 * @param int $campaign_id Campaign identifier.
	 * @return string Option key.
	 */
	private function key( int $campaign_id ): string {
		return 'exin_bf_bulk_' . $campaign_id;
	}
}
