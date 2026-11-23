<?php
/**
 * Administrative REST boundary.
 *
 * @package Exin\BlackFriday
 */

namespace Exin\BlackFriday\Rest;

use DateTimeImmutable;
use DateTimeZone;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\Scheduler;
use Exin\BlackFriday\Application\DraftCampaignService;
use Exin\BlackFriday\Application\BulkSelectionService;
use Exin\BlackFriday\Domain\DiscountPlan;
use Exin\BlackFriday\Domain\MoneyInput;
use Exin\BlackFriday\Domain\CurrencyDisplay;
use Exin\BlackFriday\Domain\Jalali;
use Exin\BlackFriday\Domain\PricingEngine;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Infrastructure\LockManager;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;

/** Validates privileged campaign requests before calling application services. */
final class AdminController {

	/**
	 * Campaign and enrollment persistence.
	 *
	 * @var CampaignRepository
	 */
	private CampaignRepository $repository;

	/**
	 * Validated product selection and conflict resolution.
	 *
	 * @var ProductSelectionService
	 */
	private ProductSelectionService $selection;

	/**
	 * Campaign lifecycle and background queue coordinator.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Operational audit service without customer details.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Connects the boundary to the application services.
	 *
	 * @param CampaignRepository      $repository Campaign persistence service.
	 * @param ProductSelectionService $selection  Product enrollment service.
	 * @param Scheduler               $scheduler  Lifecycle and queue service.
	 * @param Logger                  $logger     Operational audit service.
	 */
	public function __construct( CampaignRepository $repository, ProductSelectionService $selection, Scheduler $scheduler, Logger $logger ) {
		$this->repository = $repository;
		$this->selection  = $selection;
		$this->scheduler  = $scheduler;
		$this->logger     = $logger;
	}

	/**
	 * Registers authenticated routes with a shared permission and error boundary.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'rest_api_init',
			function (): void {
				$routes = array(
					'/state'                               => array( 'GET', 'state' ),
					'/campaigns'                           => array( 'GET', 'campaigns' ),
					'/campaign/(?P<id>\d+)/copy'           => array( 'POST', 'copy_campaign' ),
					'/campaign/(?P<id>\d+)/presentation'   => array( 'POST', 'presentation' ),
					'/campaign/(?P<id>\d+)/history'        => array( 'GET', 'history' ),
					'/campaign/(?P<id>\d+)/new-products'   => array( 'GET,POST', 'new_products' ),
					'/campaign/(?P<id>\d+)/simulation'     => array( 'POST', 'simulation' ),
					'/campaign/(?P<id>\d+)/selection-jobs' => array( 'POST', 'selection_job' ),
					'/campaign/(?P<id>\d+)/selection-jobs/(?P<token>[a-zA-Z0-9_-]{16,80})' => array( 'POST', 'selection_step' ),
					'/products'                            => array( 'GET', 'products' ),
					'/product-filters'                     => array( 'GET', 'product_filters' ),
					'/campaign'                            => array( 'POST', 'campaign' ),
					'/campaign/(?P<id>\d+)/action'         => array( 'POST', 'action' ),
					'/campaign/(?P<id>\d+)/preview'        => array( 'POST', 'preview' ),
					'/campaign/(?P<id>\d+)/items'          => array( 'POST', 'items' ),
					'/campaign/(?P<id>\d+)/bulk'           => array( 'POST', 'bulk' ),
					'/campaign/(?P<id>\d+)/report-settings' => array( 'POST', 'report_settings' ),
					'/campaign/(?P<id>\d+)/reorder'        => array( 'POST', 'reorder' ),
					'/campaign/(?P<id>\d+)/item/(?P<product>\d+)' => array( 'POST', 'item' ),
				);
				foreach ( $routes as $route => $definition ) {
					register_rest_route(
						'exin-bf/v1',
						'/admin' . $route,
						array(
							'methods'             => $definition[0],
							'permission_callback' => array( $this, 'permission' ),
							'callback'            => function ( WP_REST_Request $request ) use ( $definition ) {
								try {
									return call_user_func( array( $this, $definition[1] ), $request );
								} catch ( InvalidArgumentException | \DomainException | \OverflowException $error ) {
									return new WP_Error( 'exin_bf_invalid', $this->domain_message( $error->getMessage() ), array( 'status' => 400 ) );
								} catch ( \Throwable $error ) {
									try {
										$this->logger->log( 'error', 'admin_request_failed', array( 'error_code' => 'admin_request_failed' ) );
									} catch ( \Throwable $audit_error ) {
										// Preserve the sanitized response even when diagnostic storage is unavailable.
										update_option( 'exin_bf_boot_error', 'admin_and_audit_failed', false );
									}
									return new WP_Error( 'exin_bf_operation_failed', __( 'عملیات انجام نشد. جزئیات ایمن را در عیب‌یابی بررسی کنید.', 'exin-black-friday' ), array( 'status' => 409 ) );
								}
							},
						)
					);
				}
			}
		);
	}

	/**
	 * Requires both WooCommerce management capability and a REST nonce.
	 *
	 * @param WP_REST_Request $request Incoming administrative request.
	 * @return true|WP_Error Whether the request may access campaign data.
	 */
	public function permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'exin_bf_forbidden', __( 'دسترسی مدیریت ووکامرس لازم است.', 'exin-black-friday' ), array( 'status' => 403 ) );
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'exin_bf_nonce', __( 'نشست معتبر نیست؛ صفحه را دوباره بارگذاری کنید.', 'exin-black-friday' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Reads a bounded enrollment page and optional private dashboard analytics.
	 *
	 * @param WP_REST_Request $request Request containing page and view selectors.
	 * @return array Private campaign state and diagnostics.
	 */
	public function state( WP_REST_Request $request ): array {
		$requested = $this->integer( $request->get_param( 'campaign_id' ) ?? 0, 0, PHP_INT_MAX );
		$campaign  = $requested ? $this->required_campaign( $requested ) : $this->repository->current();
		$page      = $this->integer( $request->get_param( 'page' ) ?? 1, 1, 100000 );
		$manager   = $campaign && 'products' === $request->get_param( 'view' ) ? $this->repository->manager_items( (int) $campaign['id'], $page, sanitize_text_field( (string) ( $request->get_param( 'search' ) ?? '' ) ), $this->choice( $request->get_param( 'stock' ) ?? 'all', array( 'all', 'instock', 'outofstock' ) ), sanitize_key( $this->text( $request->get_param( 'status' ) ?? '' ) ), $this->choice( $request->get_param( 'floor' ) ?? 'all', array( 'all', 'set', 'missing' ) ) ) : null;
		$items     = null !== $manager ? $manager['items'] : ( $campaign ? $this->repository->items( (int) $campaign['id'], 25, ( $page - 1 ) * 25 ) : array() );
		foreach ( $items as &$item ) {
			$product         = wc_get_product( (int) $item['product_id'] );
			$image           = $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : false;
			$item['product'] = $product ? array(
				'name'          => $product->get_name(),
				'sku'           => $product->get_sku(),
				'price'         => $product->get_price(),
				'regular_price' => $product->get_regular_price(),
				'stock'         => ( wc_get_product( $product->get_stock_managed_by_id() ) ?: $product )->get_stock_quantity(),
				'color'         => $product->get_attribute( 'pa_color' ) ?: $product->get_attribute( 'color' ),
				'stock_status'  => $product->get_stock_status(),
				'image'         => false !== $image ? $image : '',
				'type'          => $product->get_type(),
				'edit_url'      => get_edit_post_link( $product->get_id(), 'raw' ),
			) : null;
		}
		unset( $item );
		$analytics = array();
		if ( $campaign && 'dashboard' === $request->get_param( 'view' ) && class_exists( '\Exin\BlackFriday\Integration\Orders' ) ) {
			$orders    = new \Exin\BlackFriday\Integration\Orders( $this->repository );
			$analytics = $orders->analytics( (int) $campaign['id'], $this->integer( $request->get_param( 'report_page' ) ?? 1, 1, 100000 ) );
		}
		return array(
			'campaign'      => $campaign,
			'campaign_id'   => $campaign ? $campaign['id'] : null,
			'campaigns'     => $this->repository->campaigns( 1, 50 )['campaigns'],
			'selection_job' => $campaign ? ( new BulkSelectionService( $this->repository ) )->status( (int) $campaign['id'] ) : null,
			'items'         => $items,
			'count'         => null !== $manager ? $manager['count'] : ( $campaign ? $this->repository->count_items( (int) $campaign['id'] ) : 0 ),
			'page'          => $page,
			'has_more'      => count( $items ) === 25,
			'analytics'     => $analytics,
			'server_time'   => time(),
			'health'        => $this->health(),
			'logs'          => $this->logger->recent( 30 ),
		);
	}


	/**
	 * List independent campaigns without replacing their durable history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array Operation result.
	 */
	public function campaigns( WP_REST_Request $request ): array {
		return $this->repository->campaigns( $this->integer( $request->get_param( 'page' ) ?? 1, 1, 100000 ) );
	}

	/**
	 * Copy a restored campaign into a new draft; retain all old financial rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array Operation result.
	 */
	public function copy_campaign( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		return ( new LockManager() )->run(
			'campaign:' . $id,
			function () use ( $id, $request ): array {
				$source = $this->required_campaign( $id );
				if ( $this->repository->has_ownership( $id ) || ! empty( $source['settings']['restoring'] ) ) {
					throw new InvalidArgumentException( esc_html__( 'برای کپی و اجرای مجدد، ابتدا قیمت‌های کمپین قبلی را بازیابی کنید.', 'exin-black-friday' ) );
				}
				if ( ( $source['settings']['currency'] ?? '' ) !== get_woocommerce_currency() || (int) ( $source['settings']['precision'] ?? -1 ) !== wc_get_price_decimals() ) {
					throw new \DomainException( 'store_currency_or_precision_changed' );
				}
				$data  = $this->body( $request );
				$title = sanitize_text_field( $this->text( $data['title'] ?? $source['title'] . ' — ' . __( 'نسخه جدید', 'exin-black-friday' ) ) );
				if ( '' === $title || strlen( $title ) > 200 ) {
					throw new InvalidArgumentException( esc_html__( 'عنوان کمپین معتبر نیست.', 'exin-black-friday' ) ); }
				$settings = $source['settings'];
				unset( $settings['started_at'], $settings['restoring'], $settings['last_run'] );
				$settings['copied_from'] = $id;
				$copy                    = array_intersect_key( $source, array_flip( array( 'timezone', 'days', 'strategy', 'power', 'rounding' ) ) );
				$copy['title']           = $title;
				$copy['settings']        = $settings;
				$copy['start_utc']       = gmdate( 'Y-m-d H:i:00', time() + HOUR_IN_SECONDS );
				$copy['end_utc']         = DiscountPlan::end( $copy );
				$new_id                  = $this->repository->create( $copy );
				$job                     = ( new BulkSelectionService( $this->repository ) )->start( $new_id, array( 'source_campaign_id' => $id ), array( 'mode' => 'previous' ) );
				$this->logger->log(
					'info',
					'campaign_copied',
					array(
						'campaign_id' => $new_id,
						'result'      => 'draft_created',
					)
				);
				return array(
					'campaign' => $this->repository->get( $new_id ),
					'job'      => $job,
				);
			}
		);
	}

	/**
	 * Start a bounded resumable selection operation; live steps apply current prices.
	 *
	 * @throws InvalidArgumentException When removal is not explicitly confirmed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array Operation result.
	 */
	public function selection_job( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$this->required_campaign( $id );
		$data = $this->body( $request );
		if ( 'remove' === ( $data['action'] ?? '' ) && true !== ( $data['confirm_remove'] ?? false ) ) {
			throw new InvalidArgumentException( esc_html__( 'حذف و بازیابی قیمت محصول را تأیید کنید.', 'exin-black-friday' ) ); }
		$data['filters'] = $this->selection_filters( $data['filters'] ?? array() );
		if ( 'remove' !== ( $data['action'] ?? 'add' ) ) {
			$data['filters']['stock'] = 'instock';
			if ( in_array( $data['filters']['scope'] ?? '', array( 'all', 'instock' ), true ) ) {
				$data['filters']['scope'] = 'instock';
			}
		}
		return array( 'job' => ( new BulkSelectionService( $this->repository ) )->start( $id, $this->selection_filters( $data['filters'] ?? array() ), $this->floor_policy( $data['floor_policy'] ?? array( 'mode' => 'none' ), $this->amount_unit( $data ) ), $this->choice( $data['action'] ?? 'add', array( 'add', 'remove' ) ) ) );
	}

	/**
	 * Process only the next server-side batch belonging to the saved job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array Operation result.
	 */
	public function selection_step( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		return array( 'job' => ( new BulkSelectionService( $this->repository ) )->step( $id, $this->text( $request['token'] ) ) );
	}

	/**
	 * Read the exact selected plans or a bounded scope sample without price writes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array Operation result.
	 */
	public function simulation( WP_REST_Request $request ): array {
		$id       = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$campaign = $this->required_campaign( $id );
		$data     = $this->body( $request );
		if ( DiscountPlan::automatic( $campaign ) && ! isset( $data['filters'] ) ) {
			return ( new \Exin\BlackFriday\Application\AutomaticSelection( $this->repository ) )->preview( $campaign, $this->integer( $data['page'] ?? 1, 1, 100000 ), $this->integer( $data['stage_start'] ?? 0, 0, DiscountPlan::MAX_AUTO_STAGES - 1 ) ); }
		if ( isset( $data['filters'] ) ) {
			$result = ( new BulkSelectionService( $this->repository ) )->preview( $id, $this->selection_filters( $data['filters'] ), $this->floor_policy( $data['floor_policy'] ?? array( 'mode' => 'none' ), $this->amount_unit( $data ) ) );
		} else {
			$result = array(
				'count'    => 0,
				'in_stock' => 0,
				'samples'  => array(),
				'warnings' => array(),
			);
			$after  = 0;
			do {
				$rows = $this->repository->batch( $id, $after, 100 );
				foreach ( $rows as $item ) {
					$after = $item['product_id'];
					if ( 'removed' === $item['status'] ) {
						continue; }
					++$result['count'];
					$product = wc_get_product( $item['product_id'] );
					if ( $product && \Exin\BlackFriday\Integration\CampaignContext::purchasable_product( $product ) ) {
						++$result['in_stock']; }
					if ( count( $result['warnings'] ) < 10 && ( 'conflict' === $item['status'] || $this->repository->owned_elsewhere( $id, $item['product_id'] ) ) ) {
						$result['warnings'][] = __( 'برخی محصولات با کمپین یا تغییر قیمت دیگری تداخل دارند؛ تخفیف‌ها انباشته نمی‌شوند.', 'exin-black-friday' );
					}
					if ( count( $result['samples'] ) < 5 ) {
						$result['samples'][] = array(
							'product_id'  => $item['product_id'],
							'name'        => $product ? $product->get_name() : '',
							'base_price'  => $item['base_price'],
							'floor_price' => $item['floor_price'],
							'schedule'    => $item['schedule'],
						);
					}
				}
				$row_count = count( $rows );
			} while ( 100 === $row_count );
			$result['warnings'] = array_values( array_unique( $result['warnings'] ) );
		}
		if ( DiscountPlan::automatic( $campaign ) ) {
			$result['stage_start']  = 0;
			$result['total_stages'] = DiscountPlan::stage_count( $campaign );
			$stages                 = array();
			$stage_end              = min( 12, $result['total_stages'] );
			for ( $stage = 0; $stage < $stage_end; ++$stage ) {
				$stages[] = DiscountPlan::stage_start( $campaign, $stage );
			}
		} else {
			$stages = DiscountPlan::boundaries( $campaign );
		}
		return array_merge(
			$result,
			array(
				'start_utc' => $campaign['start_utc'],
				'end_utc'   => $campaign['end_utc'],
				'stages'    => $stages,
			)
		);
	}

	/**
	 * Validate only catalog filters, never accept arbitrary query arguments.
	 *
	 * @param mixed $value Value.
	 * @return array Operation result.
	 * @throws InvalidArgumentException When submitted values are invalid.
	 */
	private function selection_filters( $value ): array {
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( esc_html__( 'محدوده محصولات معتبر نیست.', 'exin-black-friday' ) ); }
		$filters = array(
			'scope'       => $this->choice( $value['scope'] ?? 'filtered', array( 'all', 'instock', 'filtered', 'selected' ) ),
			'search'      => sanitize_text_field( $this->text( $value['search'] ?? '' ) ),
			'category'    => sanitize_text_field( $this->text( $value['category'] ?? '' ) ),
			'publication' => $this->choice( $value['publication'] ?? 'publish', array( 'publish', 'draft', 'pending', 'private' ) ),
			'stock'       => $this->choice( $value['stock'] ?? 'all', array( 'all', 'instock', 'outofstock' ) ),
			'type'        => $this->choice( $value['type'] ?? 'all', array( 'all', 'simple', 'variable', 'variation' ) ),
			'taxonomy'    => sanitize_key( $this->text( $value['taxonomy'] ?? '' ) ),
			'term'        => sanitize_text_field( $this->text( $value['term'] ?? '' ) ),
			'tax_filters' => $this->catalog_tax_filters( $value['tax_filters'] ?? array() ),
		);
		if ( strlen( $filters['search'] ) > 100 || strlen( $filters['category'] ) > 100 ) {
			throw new InvalidArgumentException( esc_html__( 'فیلتر محصول بیش از حد طولانی است.', 'exin-black-friday' ) ); }
		if ( isset( $value['ids'] ) ) {
			if ( ! is_array( $value['ids'] ) || count( $value['ids'] ) > 1000 ) {
				throw new InvalidArgumentException( esc_html__( 'انتخاب دستی را در دسته‌های حداکثر ۱۰۰۰ شناسه ارسال کنید.', 'exin-black-friday' ) ); }
			$filters['ids'] = array_values( array_unique( array_map( fn( $id ) => $this->integer( $id, 1, PHP_INT_MAX ), $value['ids'] ) ) );
		}
		if ( isset( $value['source_campaign_id'] ) ) {
			$filters['source_campaign_id'] = $this->integer( $value['source_campaign_id'], 1, PHP_INT_MAX );
			$this->required_campaign( $filters['source_campaign_id'] ); }
		return $filters;
	}

	/**
	 * Normalize percentages separately from store-currency monetary floor values.
	 *
	 * @param mixed  $value Value.
	 * @param string $unit Unit.
	 * @return array Operation result.
	 * @throws InvalidArgumentException When submitted values are invalid.
	 */
	private function floor_policy( $value, string $unit ): array {
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( esc_html__( 'روش تعیین کف معتبر نیست.', 'exin-black-friday' ) ); }
		$mode   = $this->choice( $value['mode'] ?? 'none', array( 'none', 'independent', 'percent', 'amount', 'shared', 'previous' ) );
		$policy = array( 'mode' => $mode );
		if ( in_array( $mode, array( 'percent', 'amount', 'shared' ), true ) ) {
			$policy['value'] = 'percent' === $mode ? MoneyInput::normalize( $this->text( $value['value'] ?? '' ), 2 ) : $this->decimal( $value['value'] ?? '', $unit ); }
		if ( isset( $value['floors'] ) ) {
			if ( ! is_array( $value['floors'] ) || count( $value['floors'] ) > 1000 ) {
				throw new InvalidArgumentException( esc_html__( 'تعداد کف‌های مستقل این درخواست بیش از حد است.', 'exin-black-friday' ) ); }
			$policy['floors'] = array();
			foreach ( $value['floors'] as $id => $floor ) {
				$policy['floors'][ $this->integer( $id, 1, PHP_INT_MAX ) ] = $this->decimal( $floor, $unit ); }
		}
		return $policy;
	}

	/**
	 * Save operational settings without changing the financial campaign version.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function presentation( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		return ( new LockManager() )->run(
			'campaign:' . $id,
			function () use ( $id, $request ): array {
				$campaign = $this->required_campaign( $id );
				$data     = $this->body( $request );
				$updates  = array();
				foreach ( array( 'badge_enabled', 'discount_badge', 'cashback_message_enabled', 'include_store_products', 'show_filters', 'manual_floor_catalog' ) as $key ) {
					if ( array_key_exists( $key, $data ) ) {
								$updates[ $key ] = $this->boolean( $data[ $key ] ); }
				}
				foreach ( array(
					'badge_text'       => 120,
					'cashback_message' => 500,
				) as $key => $limit ) {
					if ( isset( $data[ $key ] ) ) {
						$value = sanitize_text_field( $this->text( $data[ $key ] ) );
						if ( strlen( $value ) > $limit ) {
										throw new InvalidArgumentException( esc_html__( 'متن بیش از حد طولانی است.', 'exin-black-friday' ) );
						} $updates[ $key ] = $value; }
				}
				if ( isset( $data['badge_color'] ) ) {
					$color = sanitize_hex_color( $this->text( $data['badge_color'] ) );
					if ( ! $color ) {
								throw new InvalidArgumentException( esc_html__( 'رنگ برچسب معتبر نیست.', 'exin-black-friday' ) );
					} $updates['badge_color'] = $color; }
				foreach ( array(
					'badge_position'      => array( 'inline', 'top-right', 'top-left' ),
					'countdown_target'    => array( 'campaign', 'stage' ),
					'public_scope'        => array( 'single', 'all' ),
					'coupon_floor_policy' => array( 'allow', 'protect' ),
					'new_products'        => array( 'exclude', 'manual', 'auto' ),
				) as $key => $choices ) {
					if ( isset( $data[ $key ] ) ) {
								$updates[ $key ] = $this->choice( $data[ $key ], $choices ); }
				}
				if ( isset( $data['notifications'] ) ) {
					if ( ! is_array( $data['notifications'] ) ) {
								throw new InvalidArgumentException( esc_html__( 'تنظیم اعلان معتبر نیست.', 'exin-black-friday' ) );
					} $updates['notifications'] = array();
					foreach ( array( 'enabled', 'start', 'stage', 'error', 'admin_email' ) as $key ) {
							$updates['notifications'][ $key ] = $this->boolean( $data['notifications'][ $key ] ?? false ); }
				}
				if ( isset( $data['new_product_rules'] ) ) {
					$value = $data['new_product_rules'];
					if ( ! is_array( $value ) ) {
								throw new InvalidArgumentException( esc_html__( 'قواعد ورود محصول معتبر نیست.', 'exin-black-friday' ) ); }
					$updates['new_product_rules'] = array(
						'filters' => $this->selection_filters( $value['filters'] ?? array( 'scope' => 'instock' ) ),
						'policy'  => $this->floor_policy( $value['policy'] ?? array( 'mode' => 'none' ), $this->amount_unit( $data ) ),
					);
				}
				$check             = $campaign;
				$check['settings'] = array_replace( $campaign['settings'], $updates );
				if ( 'auto' === ( $check['settings']['new_products'] ?? 'exclude' ) ) {
					$rules = ( new \Exin\BlackFriday\Integration\NewProducts( $this->repository ) )->rules( $check );
					if ( ! $rules || ! empty( $rules['filters']['ids'] ) || ! empty( $rules['filters']['source_campaign_id'] ) || in_array( $rules['policy']['mode'] ?? 'independent', array( 'independent', 'previous' ), true ) ) {
						throw new InvalidArgumentException( esc_html__( 'برای ورود خودکار، محدودهٔ قابل تکرار و یک قاعدهٔ کف گروهی تعیین کنید.', 'exin-black-friday' ) ); }
				}
				$this->repository->save( $id, array( 'settings' => $check['settings'] ) );
				update_option( 'exin_bf_cache_version', wp_generate_uuid4(), false );
				$this->logger->log(
					'info',
					'presentation_updated',
					array(
						'campaign_id' => $id,
						'result'      => 'success',
					)
				);
				return array( 'campaign' => $this->repository->get( $id ) );
			}
		);
	}

	/**
	 * Retrieve one manager-only audit page.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function history( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$this->required_campaign( $id );
		return $this->logger->history( $id, $this->integer( $request->get_param( 'page' ) ?? 1, 1, 100000 ) );
	}

	/**
	 * List or approve a bounded new-product selection.
	 *
	 * @throws InvalidArgumentException When selection or enrollment confirmation is invalid.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function new_products( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$this->required_campaign( $id );
		$service = new \Exin\BlackFriday\Integration\NewProducts( $this->repository );
		if ( 'GET' === $request->get_method() ) {
			return $service->pending( $id, $this->integer( $request->get_param( 'page' ) ?? 1, 1, 100000 ) ); }
		$data = $this->body( $request );
		$ids  = $data['ids'] ?? array();
		if ( ! is_array( $ids ) || ! $ids || count( $ids ) > 25 ) {
			throw new InvalidArgumentException( esc_html__( 'هر بار یک تا ۲۵ محصول قابل بررسی است.', 'exin-black-friday' ) ); }
		$accept = 'approve' === $this->choice( $data['action'] ?? '', array( 'approve', 'reject' ) );
		if ( $accept && true !== ( $data['confirm_enroll'] ?? false ) ) {
			throw new InvalidArgumentException( esc_html__( 'ورود با قوانین مرحلهٔ جاری به تأیید صریح نیاز دارد.', 'exin-black-friday' ) ); }
		return $service->approve( $id, array_map( fn( $value ) => $this->integer( $value, 1, PHP_INT_MAX ), $ids ), $accept );
	}

	/**
	 * Searches a bounded page of sellable products by a validated term.
	 *
	 * @param WP_REST_Request $request Product search and page parameters.
	 * @return array Matching products and pagination information.
	 * @throws InvalidArgumentException When the search term is outside its limits.
	 */
	public function products( WP_REST_Request $request ): array {
		$term = sanitize_text_field( $this->text( $request->get_param( 'search' ) ?? '' ) );
		if ( strlen( $term ) > 100 || ( '' !== $term && strlen( $term ) < 2 ) ) {
			throw new InvalidArgumentException( esc_html__( 'جست‌وجو را خالی بگذارید یا بین ۲ و ۱۰۰ نویسه وارد کنید.', 'exin-black-friday' ) );
		}
		$filters = array(
			'category'    => sanitize_text_field( $this->text( $request->get_param( 'category' ) ?? '' ) ),
			'stock'       => $this->choice( $request->get_param( 'stock' ) ?? 'all', array( 'all', 'instock', 'outofstock' ) ),
			'publication' => $this->choice( $request->get_param( 'publication' ) ?? 'publish', array( 'publish', 'draft', 'pending', 'private' ) ),
			'type'        => $this->choice( $request->get_param( 'type' ) ?? 'all', array( 'all', 'simple', 'variable', 'variation' ) ),
			'taxonomy'    => sanitize_key( $this->text( $request->get_param( 'taxonomy' ) ?? '' ) ),
			'term'        => sanitize_text_field( $this->text( $request->get_param( 'term' ) ?? '' ) ),
			'parent_id'   => $this->integer( $request->get_param( 'parent_id' ) ?? 0, 0, PHP_INT_MAX ),
			'tax_filters' => $this->catalog_tax_filters( $request->get_param( 'tax_filters' ) ?? array() ),
		);
		if ( in_array( (string) $request->get_param( 'missing_floor' ), array( '1', '2' ), true ) ) {
			$missing_id = $this->integer( $request->get_param( 'campaign_id' ), 1, PHP_INT_MAX );
			$this->required_campaign( $missing_id );
			$filters['missing_floor_campaign'] = $missing_id;
			$filters['floor_is_set']           = '2' === (string) $request->get_param( 'missing_floor' );
		}
		$result      = $this->selection->search( $term, $this->integer( $request->get_param( 'page' ) ?? 1, 1, 10000 ), $filters );
		$campaign_id = $this->integer( $request->get_param( 'campaign_id' ) ?? 0, 0, PHP_INT_MAX );
		if ( $campaign_id ) {
			$this->required_campaign( $campaign_id );
			$ids     = array_map( 'intval', array_column( $result['products'], 'id' ) );
			$members = $this->repository->member_product_ids( $campaign_id, $ids );
			foreach ( $result['products'] as &$product ) {
				$product['included'] = in_array( (int) $product['id'], $members, true );
			}
			unset( $product );
		}
		return $result;
	}

	/**
	 * Provides bounded registered WooCommerce taxonomy choices for the picker.
	 *
	 * @return array Categories, brands and product attributes.
	 */
	public function product_filters(): array {
		return $this->selection->filters();
	}

	/**
	 * Validate up to five combined registered brand and attribute filters.
	 *
	 * @param mixed $value Associative filter map or its JSON representation.
	 * @return array Sanitized taxonomy-to-term selections.
	 * @throws InvalidArgumentException When filter shape or registration is invalid.
	 */
	private function catalog_tax_filters( $value ): array {
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > 2000 ) {
				throw new InvalidArgumentException( esc_html__( 'فیلترهای محصول بیش از حد طولانی‌اند.', 'exin-black-friday' ) );
			}
			try {
				$value = json_decode( $value, true, 8, JSON_THROW_ON_ERROR );
			} catch ( \JsonException $error ) {
				throw new InvalidArgumentException( esc_html__( 'ساختار فیلترهای محصول معتبر نیست.', 'exin-black-friday' ) );
			}
		}
		if ( ! is_array( $value ) || count( $value ) > 5 || ( $value && array_is_list( $value ) ) ) {
			throw new InvalidArgumentException( esc_html__( 'حداکثر پنج فیلتر برند یا ویژگی مجاز است.', 'exin-black-friday' ) );
		}
		$filters = array();
		foreach ( $value as $name => $term ) {
			$taxonomy = is_string( $name ) ? get_taxonomy( sanitize_key( $name ) ) : false;
			if ( ! $taxonomy || ! in_array( 'product', $taxonomy->object_type, true )
				|| ( ! str_starts_with( $taxonomy->name, 'pa_' ) && ! preg_match( '/brand|manufacturer|برند/i', $taxonomy->name . ' ' . $taxonomy->labels->singular_name ) ) ) {
				throw new InvalidArgumentException( esc_html__( 'برند یا ویژگی انتخابی ثبت نشده است.', 'exin-black-friday' ) );
			}
			$term = sanitize_text_field( $this->text( $term ) );
			if ( strlen( $term ) > 100 ) {
				throw new InvalidArgumentException( esc_html__( 'مقدار فیلتر محصول معتبر نیست.', 'exin-black-friday' ) );
			}
			if ( '' !== $term ) {
				$filters[ $taxonomy->name ] = $term;
			}
		}
		return $filters;
	}

	/**
	 * Serializes campaign creation and editing against concurrent lifecycle jobs.
	 *
	 * @param WP_REST_Request $request Validated JSON campaign request.
	 * @return array Saved campaign state.
	 */
	public function campaign( WP_REST_Request $request ): array {
		$locks = new LockManager();
		return $locks->run(
			'campaign-create',
			function () use ( $request, $locks ): array {
				$data    = $this->body( $request );
				$id      = isset( $data['id'] ) ? $this->integer( $data['id'], 1, PHP_INT_MAX ) : 0;
				$current = $this->repository->current();
				$lock_id = $id ? $id : ( $current ? (int) $current['id'] : 0 );
				if ( $lock_id ) {
					return $locks->run( 'campaign:' . $lock_id, fn(): array => $this->save_campaign( $request ) );
				}
				return $this->save_campaign( $request );
			}
		);
	}

	/**
	 * Saves a draft while preserving pinned currency and existing schedules.
	 *
	 * @param WP_REST_Request $request Campaign JSON and optional existing ID.
	 * @return array Saved campaign state.
	 * @throws InvalidArgumentException When prior ownership or financial settings conflict.
	 */
	private function save_campaign( WP_REST_Request $request ): array {
		$data     = $this->body( $request );
		$id       = isset( $data['id'] ) ? $this->integer( $data['id'], 1, PHP_INT_MAX ) : 0;
		$existing = $id ? $this->required_campaign( $id ) : null;
		if ( $existing && 'draft' !== $existing['status'] ) {
			throw new InvalidArgumentException( esc_html__( 'تنظیمات کمپین شروع‌شده قابل تغییر نیست. برای کمپین بعدی پیش‌نویس بسازید.', 'exin-black-friday' ) );
		}
		$valid = $this->validate_campaign( $data );
		if ( $existing ) {
			$valid['settings']['order_statuses'] = $existing['settings']['order_statuses'] ?? array( 'processing', 'completed' );
			$valid['settings']['currency']       = $existing['settings']['currency'] ?? get_woocommerce_currency();
			$valid['settings']['precision']      = $existing['settings']['precision'] ?? wc_get_price_decimals();
			if ( get_woocommerce_currency() !== $valid['settings']['currency'] || wc_get_price_decimals() !== (int) $valid['settings']['precision'] ) {
				throw new InvalidArgumentException( esc_html__( 'واحد پول یا دقت فروشگاه تغییر کرده است؛ پیش از ادامه آن را بررسی کنید.', 'exin-black-friday' ) );
			}
		}
		if ( $id ) {
			( new DraftCampaignService( $this->repository, $this->logger ) )->save( $id, $valid );
		} else {
			$id = $this->repository->create( $valid );
		}
		update_option( 'exin_bf_cache_version', wp_generate_uuid4(), false );
		$this->logger->log( 'info', 'campaign_saved', array( 'campaign_id' => $id ) );
		return array( 'campaign' => $this->repository->get( $id ) );
	}

	/**
	 * Applies an allowed lifecycle action with explicit confirmation for restore.
	 *
	 * @param WP_REST_Request $request Campaign ID, action and confirmation.
	 * @return array Updated campaign state.
	 * @throws InvalidArgumentException When destructive confirmation is absent.
	 */
	public function action( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		return ( new LockManager() )->run( 'campaign:' . $id, fn(): array => $this->campaign_action( $request ) );
	}

	/**
	 * Validates readiness and changes lifecycle while holding the campaign lock.
	 *
	 * @param WP_REST_Request $request Campaign ID and explicitly confirmed action.
	 * @return array Updated campaign state.
	 * @throws InvalidArgumentException When readiness or confirmation is missing.
	 */
	private function campaign_action( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$this->required_campaign( $id );
		$data   = $this->body( $request );
		$action = $this->choice( $data['action'] ?? '', array( 'schedule', 'start', 'pause', 'resume', 'restore', 'recover', 'archive' ) );
		if ( 'archive' === $action ) {
			$campaign = $this->required_campaign( $id );
			if ( $this->repository->has_ownership( $id ) || ! empty( $campaign['settings']['restoring'] ) ) {
				throw new InvalidArgumentException( esc_html__( 'پیش از آرشیو، بازیابی قیمت‌های این کمپین را کامل کنید.', 'exin-black-friday' ) );
			}
			$this->repository->save(
				$id,
				array(
					'status'  => 'archived',
					'version' => $campaign['version'] + 1,
				)
			);
			$this->logger->log(
				'info',
				'campaign_archived',
				array(
					'campaign_id' => $id,
					'result'      => 'success',
				)
			);
			return array( 'campaign' => $this->repository->get( $id ) );
		}
		if ( in_array( $action, array( 'start', 'schedule' ), true ) ) {
			if ( true !== ( $data['confirm_activate'] ?? false ) ) {
				throw new InvalidArgumentException( esc_html__( 'فعال‌سازی فقط پس از بررسی نهایی و تأیید صریح مدیر مجاز است.', 'exin-black-friday' ) );
			}
			$this->assert_ready( $this->required_campaign( $id ) );
		}
		if ( 'restore' === $action && true !== ( $data['confirm_restore'] ?? false ) ) {
			throw new InvalidArgumentException( esc_html__( 'بازیابی قیمت‌ها به تأیید صریح نیاز دارد.', 'exin-black-friday' ) );
		}
		if ( 'recover' === $action ) {
			$this->scheduler->recover();
		} else {
			$this->scheduler->transition( $id, $action );
		}
		return array( 'campaign' => $this->repository->get( $id ) );
	}

	/**
	 * Calculates a private schedule preview without persisting an enrollment.
	 *
	 * @param WP_REST_Request $request Campaign, product and floor input.
	 * @return array Validated schedule preview.
	 */
	public function preview( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$this->required_campaign( $id );
		$data = $this->body( $request );
		return $this->selection->preview( $id, $this->integer( $data['product_id'] ?? 0, 1, PHP_INT_MAX ), $this->decimal( $data['floor_price'] ?? '', $this->amount_unit( $data ) ) );
	}

	/**
	 * Locks the campaign before enrolling a single product.
	 *
	 * @param WP_REST_Request $request Enrollment request.
	 * @return array Saved enrollment.
	 */
	public function items( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		return ( new LockManager() )->run( 'campaign:' . $id, fn(): array => $this->add_item( $request ) );
	}

	/**
	 * Enrolls a product using only the permitted financial and display fields.
	 *
	 * @param WP_REST_Request $request Enrollment JSON for an editable campaign.
	 * @return array Saved enrollment.
	 */
	private function add_item( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$this->editable_campaign( $id );
		$data = $this->body( $request );
		return $this->selection->add(
			$id,
			$this->integer( $data['product_id'] ?? 0, 1, PHP_INT_MAX ),
			$this->decimal( $data['floor_price'] ?? '', $this->amount_unit( $data ) ),
			array(
				'priority' => $this->integer( $data['priority'] ?? 0, 0, 999999 ),
				'featured' => $this->boolean( $data['featured'] ?? false ),
			)
		);
	}

	/**
	 * Applies a validated operation to an enrollment owned by the campaign.
	 *
	 * @param WP_REST_Request $request Campaign/product IDs and operation fields.
	 * @return array Updated enrollment state.
	 * @throws InvalidArgumentException When membership or explicit confirmation is missing.
	 */
	public function item( WP_REST_Request $request ): array {
		$id      = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$product = $this->integer( $request['product'], 1, PHP_INT_MAX );
		$this->required_campaign( $id );
		if ( ! $this->repository->item( $id, $product ) ) {
			throw new InvalidArgumentException( esc_html__( 'محصول عضو این کمپین نیست.', 'exin-black-friday' ) );
		}
		$data   = $this->body( $request );
		$action = $this->choice( $data['action'] ?? 'update', array( 'update', 'remove', 'retry', 'keep_external', 'pause', 'resume' ) );
		if ( 'remove' === $action ) {
			if ( true !== ( $data['confirm_remove'] ?? false ) ) {
				throw new InvalidArgumentException( esc_html__( 'حذف و بازیابی قیمت محصول را تأیید کنید.', 'exin-black-friday' ) );
			}
			$this->selection->remove( $id, $product );
		} elseif ( in_array( $action, array( 'pause', 'resume' ), true ) ) {
			$this->selection->update( $id, $product, array( 'status' => 'pause' === $action ? 'paused' : 'included' ) );
			if ( 'resume' === $action && 'active' === $this->required_campaign( $id )['status'] ) {
				$this->selection->apply_current( $id, $product ); }
		} elseif ( in_array( $action, array( 'retry', 'keep_external' ), true ) ) {
			if ( 'keep_external' === $action && true !== ( $data['confirm_release'] ?? false ) ) {
				throw new InvalidArgumentException( esc_html__( 'حفظ قیمت بیرونی و واگذاری کنترل را تأیید کنید.', 'exin-black-friday' ) );
			}
			if ( ! method_exists( $this->selection, 'resolve' ) ) {
				throw new InvalidArgumentException( esc_html__( 'برای بررسی تعارض، بازیابی ایمن کمپین را اجرا کنید.', 'exin-black-friday' ) );
			}
			$this->selection->resolve( $id, $product, $action );
		} else {
			$changes = array();
			if ( isset( $data['floor_override'] ) ) {
				$changes['floor_override'] = $this->boolean( $data['floor_override'] ); }
			if ( isset( $data['floor_price'] ) ) {
				$this->editable_campaign( $id, true );
				$changes['floor_price'] = $this->validated_floor( $this->required_campaign( $id ), $product, $data['floor_price'], $this->amount_unit( $data ) );
			}
			if ( isset( $data['priority'] ) ) {
				$changes['priority'] = $this->integer( $data['priority'], 0, 999999 );
			}
			if ( isset( $data['featured'] ) ) {
				$changes['featured'] = $this->boolean( $data['featured'] );
			}
			if ( ! $changes ) {
				throw new InvalidArgumentException( esc_html__( 'تغییری انتخاب نشده است.', 'exin-black-friday' ) );
			}
			if ( isset( $changes['floor_price'] ) && ! array_diff( array_keys( $changes ), array( 'floor_price', 'floor_override' ) ) ) {
				$options = array_key_exists( 'expected_floor_price', $data ) ? array( 'expected_floor_price' => $data['expected_floor_price'] ) : array();
				$this->selection->update_floor( $id, $product, $changes['floor_price'], $options );
			} else {
				$this->selection->update( $id, $product, $changes );
			}
		}
		return array( 'item' => $this->repository->item( $id, $product ) );
	}

	/**
	 * Serializes a bounded group of enrollment operations for one campaign.
	 *
	 * @param WP_REST_Request $request Bulk enrollment request.
	 * @return array Per-product results.
	 */
	public function bulk( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		return ( new LockManager() )->run( 'campaign:' . $id, fn(): array => $this->bulk_items( $request ) );
	}

	/**
	 * Processes at most twenty-five additions or explicitly confirmed removals.
	 *
	 * @param WP_REST_Request $request Allowed bulk action and product rows.
	 * @return array Independent per-product success results.
	 * @throws InvalidArgumentException When the batch or removal confirmation is invalid.
	 */
	private function bulk_items( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$this->required_campaign( $id );
		$data   = $this->body( $request );
		$action = $this->choice( $data['action'] ?? '', array( 'add', 'remove', 'floors', 'recovery_preview', 'recovery_approve' ) );
		$rows   = $data['items'] ?? null;
		if ( ! is_array( $rows ) || count( $rows ) < 1 || count( $rows ) > 25 ) {
			throw new InvalidArgumentException( esc_html__( 'هر نوبت بین ۱ تا ۲۵ محصول مجاز است.', 'exin-black-friday' ) );
		}
		if ( 'recovery_approve' === $action && true !== ( $data['confirm_recovery'] ?? false ) ) {
			throw new InvalidArgumentException( esc_html__( 'پذیرش قیمت فعلی به‌عنوان مبنای جدید و اعمال مرحلهٔ جاری را تأیید کنید.', 'exin-black-friday' ) );
		}
		if ( in_array( $action, array( 'add', 'floors' ), true ) ) {
			$this->editable_campaign( $id, 'floors' === $action );
		} elseif ( 'remove' === $action && true !== ( $data['confirm_remove'] ?? false ) ) {
			throw new InvalidArgumentException( esc_html__( 'حذف گروهی و بازیابی قیمت را تأیید کنید.', 'exin-black-friday' ) );
		}
		$results = array();
		foreach ( $rows as $row ) {
			$product = 0;
			try {
				if ( ! is_array( $row ) ) {
					throw new InvalidArgumentException( esc_html__( 'ساختار محصول معتبر نیست.', 'exin-black-friday' ) );
				}
				$product = $this->integer( $row['product_id'] ?? 0, 1, PHP_INT_MAX );
				if ( in_array( $action, array( 'recovery_preview', 'recovery_approve' ), true ) ) {
					$recovery = new \Exin\BlackFriday\Application\ConflictRecovery( $this->repository );
					$value = 'recovery_preview' === $action ? $recovery->preview( $id, $product ) : $recovery->approve( $id, $product, $this->text( $row['token'] ?? '' ) );
					$results[] = array( 'product_id' => $product, 'ok' => true ) + $value;
					continue;
				} elseif ( 'floors' === $action ) {
					$options = array_key_exists( 'expected_floor_price', $row ) ? array( 'expected_floor_price' => $row['expected_floor_price'] ) : array();
					$pricing = $this->selection->update_floor( $id, $product, $this->validated_floor( $this->required_campaign( $id ), $product, $row['floor_price'] ?? '', $this->amount_unit( $data ) ), $options );
				} elseif ( 'add' === $action ) {
					$this->selection->add( $id, $product, $this->decimal( $row['floor_price'] ?? '', $this->amount_unit( $data ) ) );
				} else {
					if ( ! $this->repository->item( $id, $product ) ) {
						throw new InvalidArgumentException( esc_html__( 'محصول عضو کمپین نیست.', 'exin-black-friday' ) );
					}
					$this->selection->remove( $id, $product );
				}
				$result = array(
					'product_id' => $product,
					'ok'         => true,
				);
				if ( 'floors' === $action ) {
					$saved   = $this->repository->item( $id, $product );
					$result += array(
						'floor_price'    => $saved['floor_price'],
						'floor_override' => $saved['floor_override'],
						'status'         => $saved['status'],
						'product_price'  => wc_get_product( $product )->get_price(),
						'pricing'        => $pricing,
					);
				}
				$results[] = $result;
			} catch ( \Throwable $error ) {
				$results[] = array(
					'product_id' => $product,
					'ok'         => false,
					'error'      => $this->domain_message( $error->getMessage() ),
				);
			}
		}
		return array( 'results' => $results );
	}

	/**
	 * Saves reporting policy separately without altering active financial settings.
	 *
	 * @param WP_REST_Request $request Campaign ID and registered order statuses.
	 * @return array Saved reporting settings.
	 */
	public function report_settings( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		return ( new LockManager() )->run(
			'campaign:' . $id,
			function () use ( $request, $id ): array {
				$campaign                   = $this->required_campaign( $id );
				$data                       = $this->body( $request );
				$statuses                   = $this->report_statuses( $data['order_statuses'] ?? array() );
				$settings                   = $campaign['settings'];
				$settings['order_statuses'] = $statuses;
				$this->repository->transaction(
					function () use ( $id, $settings ): void {
						$this->repository->save( $id, array( 'settings' => $settings ) );
						$this->logger->log( 'info', 'report_settings_saved', array( 'campaign_id' => $id ) );
					}
				);
				( new \Exin\BlackFriday\Integration\Orders( $this->repository ) )->invalidate_campaign( $id );
				return array( 'order_statuses' => $statuses );
			}
		);
	}

	/**
	 * Applies a bounded explicit manual order without touching product prices.
	 *
	 * @param WP_REST_Request $request Campaign ID and ordered enrollment rows.
	 * @return array Updated order acknowledgements.
	 * @throws InvalidArgumentException When rows or membership are invalid.
	 */
	public function reorder( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		return ( new LockManager() )->run(
			'campaign:' . $id,
			function () use ( $request, $id ): array {
				$this->required_campaign( $id );
				$data = $this->body( $request );
				$rows = $data['items'] ?? null;
				if ( ! is_array( $rows ) || ! $rows || count( $rows ) > 100 ) {
					throw new InvalidArgumentException( esc_html__( 'هر نوبت ترتیب حداکثر ۱۰۰ محصول قابل ذخیره است.', 'exin-black-friday' ) );
				}
				$valid = array();
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						throw new InvalidArgumentException( esc_html__( 'ساختار ترتیب محصولات معتبر نیست.', 'exin-black-friday' ) );
					}
					$product = $this->integer( $row['product_id'] ?? 0, 1, PHP_INT_MAX );
					$item    = $this->repository->item( $id, $product );
					if ( ! $item || 'removed' === $item['status'] || isset( $valid[ $product ] ) ) {
						throw new InvalidArgumentException( esc_html__( 'محصول تکراری یا خارج از کمپین در ترتیب وجود دارد.', 'exin-black-friday' ) );
					}
					$valid[ $product ] = array( 'priority' => $this->integer( $row['priority'] ?? 0, 0, 999999 ) );
					if ( isset( $row['featured'] ) ) {
						$valid[ $product ]['featured'] = $this->boolean( $row['featured'] );
					}
				}
				$this->repository->transaction(
					function () use ( $valid, $id ): void {
						foreach ( $valid as $product => $changes ) {
							$this->selection->update( $id, $product, $changes );
						}
					}
				);
				return array( 'updated' => count( $valid ) );
			}
		);
	}

	/**
	 * Checks every selected financial schedule before explicit activation.
	 *
	 * @param array $campaign Server-loaded draft configuration.
	 * @return void
	 * @throws InvalidArgumentException When enrollment data is incomplete or conflicted.
	 */
	private function assert_ready( array $campaign ): void {
		$job = ( new BulkSelectionService( $this->repository ) )->status( (int) $campaign['id'] );
		if ( $job && empty( $job['complete'] ) && 'running' === ( $job['status'] ?? '' ) ) {
			throw new InvalidArgumentException( esc_html__( 'ابتدا پردازش انتخاب محصولات را کامل کنید.', 'exin-black-friday' ) );
		}
		$after = 0;
		$count = 0;
		do {
			$rows = $this->repository->batch( (int) $campaign['id'], $after, 100 );
			foreach ( $rows as $item ) {
				$after = (int) $item['product_id'];
				if ( 'removed' === $item['status'] ) {
					continue;
				}
				++$count;
				if ( DiscountPlan::automatic( $campaign ) ) {
					DiscountPlan::automatic_price( $item['base_price'], $item['floor_price'], $campaign, 0 );
					$expected = $item['schedule']; } else {
					$expected = DiscountPlan::schedule( $item['base_price'], $item['floor_price'], $campaign ); }
					if ( 'conflict' === $item['status'] || $item['intent'] || $item['expected'] || $expected !== $item['schedule']
					|| ( $item['snapshot']['currency'] ?? '' ) !== get_woocommerce_currency()
					|| (int) ( $item['snapshot']['precision'] ?? -1 ) !== wc_get_price_decimals() ) {
						throw new InvalidArgumentException( esc_html__( 'برنامه یا مالکیت یک محصول کامل نیست؛ پیش از فعال‌سازی تعارض‌ها را بررسی کنید.', 'exin-black-friday' ) );
					}
			}
			$row_count = count( $rows );
		} while ( 100 === $row_count );
		if ( DiscountPlan::automatic( $campaign ) ) {
			( new \Exin\BlackFriday\Application\AutomaticSelection( $this->repository ) )->capacity( $campaign, $count ); }
		if ( 0 === $count ) {
			throw new InvalidArgumentException( esc_html__( 'برای فعال‌سازی، حداقل یک محصول با کف معتبر انتخاب کنید.', 'exin-black-friday' ) );
		}
	}

	/**
	 * Accepts only registered paid-order status choices for analytics.
	 *
	 * @param mixed $value Requested status list.
	 * @return array Registered safe status slugs.
	 * @throws InvalidArgumentException When no valid reporting policy is supplied.
	 */
	private function report_statuses( $value ): array {
		if ( ! is_array( $value ) || ! $value || count( $value ) > 100 ) {
			throw new InvalidArgumentException( esc_html__( 'حداقل یک وضعیت مجاز گزارش لازم است.', 'exin-black-friday' ) );
		}
		$allowed  = array_map( static fn( $status ) => preg_replace( '/^wc-/', '', $status ), array_keys( wc_get_order_statuses() ) );
		$statuses = array_values( array_unique( array_map( fn( $status ) => $this->choice( preg_replace( '/^wc-/', '', $this->text( $status ) ), $allowed ), $value ) ) );
		if ( array_intersect( $statuses, array( 'pending', 'failed', 'cancelled', 'checkout-draft', 'refunded' ) ) ) {
			throw new InvalidArgumentException( esc_html__( 'سفارش پرداخت‌نشده، لغوشده یا کاملاً مستردشده در آمار فروش مجاز نیست.', 'exin-black-friday' ) );
		}
		return $statuses;
	}

	/**
	 * Validates and allowlists campaign settings, dates and shipping instances.
	 *
	 * @param array $data Submitted JSON object.
	 * @return array Normalized campaign data without client-selected status or version.
	 * @throws InvalidArgumentException When dates, settings or monetary inputs are invalid.
	 */
	private function validate_campaign( array $data ): array {
		$title = sanitize_text_field( $this->text( $data['title'] ?? '' ) );
		if ( '' === $title || strlen( $title ) > 200 ) {
			throw new InvalidArgumentException( esc_html__( 'عنوان کمپین معتبر نیست.', 'exin-black-friday' ) );
		}
		$timezone = $this->text( $data['timezone'] ?? 'Asia/Tehran' );
		if ( ! in_array( $timezone, DateTimeZone::listIdentifiers(), true ) && 'UTC' !== $timezone ) {
			throw new InvalidArgumentException( esc_html__( 'منطقه زمانی معتبر نیست.', 'exin-black-friday' ) );
		}
		if ( isset( $data['start_jalali'] ) ) {
			$converted = Jalali::to_local( $this->text( $data['start_jalali'] ), $this->text( $data['start_time'] ?? '00:00' ) );
			$local     = str_replace( ' ', 'T', substr( $converted, 0, 16 ) );
		} else {
			$local = $this->text( $data['start_local'] ?? '' );
		}
		$zone   = new DateTimeZone( $timezone );
		$start  = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $local, $zone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $start || ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $start->format( 'Y-m-d\TH:i' ) !== $local ) {
			throw new InvalidArgumentException( esc_html__( 'تاریخ شمسی یا ساعت ۲۴ساعتهٔ شروع معتبر نیست.', 'exin-black-friday' ) );
		}
		$days     = $this->integer( $data['days'] ?? 7, 1, 366 );
		$settings = $data['settings'] ?? array();
		if ( ! is_array( $settings ) ) {
			throw new InvalidArgumentException( esc_html__( 'تنظیمات معتبر نیست.', 'exin-black-friday' ) );
		}
		$instances = $settings['shipping_instances'] ?? array();
		if ( ! is_array( $instances ) || count( $instances ) > 100 ) {
			throw new InvalidArgumentException( esc_html__( 'روش ارسال معتبر نیست.', 'exin-black-friday' ) );
		}
		$instances = array_values( array_unique( array_map( fn( $value ) => $this->integer( $value, 1, PHP_INT_MAX ), $instances ) ) );
		$unit      = $this->amount_unit( $data );
		$threshold = $this->decimal( $settings['shipping_threshold'] ?? '0', $unit );
		$cap       = isset( $settings['shipping_subsidy_cap'] ) && '' !== $settings['shipping_subsidy_cap']
			? $this->decimal( $settings['shipping_subsidy_cap'], $unit )
			: CurrencyDisplay::default_subsidy_cap( get_woocommerce_currency(), wc_get_price_decimals() );
		$free      = $this->boolean( $settings['free_shipping'] ?? false );
		if ( $free && ( ! $instances || null === $cap ) ) {
			throw new InvalidArgumentException( esc_html__( 'برای ارسال جشنواره، سقف یارانه و حداقل یک روش نرخ ثابت معتبر را تعیین کنید.', 'exin-black-friday' ) );
		}
		$allowed_instances = array_column( $this->shipping_methods(), 'id' );
		foreach ( $instances as $instance ) {
			if ( ! in_array( $instance, $allowed_instances, true ) ) {
				throw new InvalidArgumentException( esc_html__( 'روش ارسال انتخابی نرخ ثابت فعال نیست؛ روش ناسازگار تغییر نمی‌کند.', 'exin-black-friday' ) );
			}
		}
		$valid                              = array(
			'title'     => $title,
			'timezone'  => $timezone,
			'days'      => $days,
			'start_utc' => $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'end_utc'   => $start->modify( '+' . $days . ' days' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'strategy'  => $this->choice( $data['strategy'] ?? 'linear', array( 'linear', 'progressive' ) ),
			'power'     => $this->integer( $data['power'] ?? 2, 1, 8 ),
			'rounding'  => $this->choice( $data['rounding'] ?? 'half_up', array( 'half_up', 'floor', 'ceil' ) ),
			'settings'  => array(
				'currency'             => get_woocommerce_currency(),
				'precision'            => wc_get_price_decimals(),
				'free_shipping'        => $free,
				'shipping_threshold'   => $threshold,
				'shipping_subsidy_cap' => $cap,
				'shipping_instances'   => $instances,
				'inactive_output'      => $this->choice( $settings['inactive_output'] ?? 'message', array( 'message', 'empty' ) ),
				'retention_days'       => $this->integer( $settings['retention_days'] ?? 90, 7, 365 ),
			),
		);
		$valid['settings']['campaign_type'] = $this->choice( $settings['campaign_type'] ?? 'black_friday', array( 'black_friday', 'general' ) );
		$valid['settings']['priority']      = $this->integer( $settings['priority'] ?? 0, 0, 999999 );
		if ( isset( $settings['discount_plan'] ) ) {
			if ( ! is_array( $settings['discount_plan'] ) ) {
				throw new InvalidArgumentException( esc_html__( 'برنامه تخفیف معتبر نیست.', 'exin-black-friday' ) ); }
			$plan = $settings['discount_plan'];
			if ( DiscountPlan::automatic( array( 'settings' => array( 'discount_plan' => $plan ) ) ) && empty( $plan['seed'] ) ) {
				$existing     = isset( $data['id'] ) ? $this->repository->get( (int) $data['id'] ) : null;
				$plan['seed'] = $existing['settings']['discount_plan']['seed'] ?? wp_generate_uuid4(); }
			$plan['initial_discount'] = 'fixed_amount' === ( $plan['mode'] ?? '' ) ? $this->decimal( $plan['initial_discount'] ?? '0', $unit ) : MoneyInput::normalize( $this->text( $plan['initial_discount'] ?? '0' ), 2 );
			$plan['final_discount']   = MoneyInput::normalize( $this->text( $plan['final_discount'] ?? '15' ), 2 );
			if ( isset( $plan['points'] ) ) {
				if ( ! is_array( $plan['points'] ) || count( $plan['points'] ) > 366 ) {
					throw new InvalidArgumentException( esc_html__( 'حداکثر ۳۶۶ مرحله مجاز است.', 'exin-black-friday' ) ); }
				foreach ( $plan['points'] as &$point ) {
					if ( ! is_array( $point ) ) {
						throw new InvalidArgumentException( esc_html__( 'مرحله تخفیف معتبر نیست.', 'exin-black-friday' ) ); }
					$point['value'] = 'amount' === ( $point['kind'] ?? '' ) ? $this->decimal( $point['value'] ?? '', $unit ) : MoneyInput::normalize( $this->text( $point['value'] ?? '' ), 2 );
				}
				unset( $point );
			}
			if ( DiscountPlan::automatic( array( 'settings' => array( 'discount_plan' => $plan ) ) ) ) {
				if ( ! isset( $plan['duration_minutes'] ) && ! isset( $plan['duration_days'] ) ) {
					$plan['duration_minutes'] = $days * 1440;
				}
				foreach ( array( 'intensity_min', 'intensity_max', 'base_max' ) as $field ) {
					if ( isset( $plan[ $field ] ) ) {
						$plan[ $field ] = MoneyInput::normalize( $this->text( $plan[ $field ] ), 2 ); }
				}
				foreach ( array( 'steps', 'duration_minutes', 'duration_days', 'interval_minutes', 'selection_value', 'bands', 'wave_period' ) as $field ) {
					if ( isset( $plan[ $field ] ) ) {
						$plan[ $field ] = $this->integer( MoneyInput::normalize( $this->text( $plan[ $field ] ), 0 ), 1, 527040 ); }
				}
			}
			$valid['settings']['discount_plan']        = DiscountPlan::normalize( $plan, wc_get_price_decimals() );
			$valid['settings']['existing_sale_policy'] = $this->choice( $settings['existing_sale_policy'] ?? 'preserve', array( 'reject', 'preserve' ) );
			$valid['days']                             = DiscountPlan::automatic( $valid ) ? (int) ceil( $valid['settings']['discount_plan']['duration_minutes'] / 1440 ) : $valid['settings']['discount_plan']['steps'];
			$valid['end_utc']                          = DiscountPlan::end( $valid );
		}
		return $valid;
	}

	/**
	 * Reads environment diagnostics without changing WordPress or shipping setup.
	 *
	 * @return array Runtime versions, scheduler state and available configuration options.
	 */
	private function health(): array {
		return array(
			'php'                => PHP_VERSION,
			'wordpress'          => get_bloginfo( 'version' ),
			'woocommerce'        => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'action_scheduler'   => function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ),
			'cron_disabled'      => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'object_cache'       => wp_using_ext_object_cache(),
			'timezone'           => wp_timezone_string(),
			'elementor'          => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'last_tick'          => get_option( 'exin_bf_last_tick', '' ),
			'late_watchdog'      => ! get_option( 'exin_bf_last_tick' ) || strtotime( get_option( 'exin_bf_last_tick' ) . ' UTC' ) < time() - 180,
			'failed_jobs'        => function_exists( 'as_get_scheduled_actions' ) && (bool) as_get_scheduled_actions(
				array(
					'group'    => Scheduler::GROUP,
					'status'   => 'failed',
					'per_page' => 1,
				),
				'ids'
			),
			'notification_error' => get_option( 'exin_bf_notification_error', '' ),
			'next_watchdog'      => function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( 'exin_bf_tick', array(), 'exin-black-friday' ) : null,
			'shipping_methods'   => $this->shipping_methods(),
			'shipping_warnings'  => ( new \Exin\BlackFriday\Integration\Shipping( $this->repository ) )->warnings(),
			'order_statuses'     => wc_get_order_statuses(),
		);
	}

	/**
	 * Lists enabled flat-rate instances across configured zones and the fallback zone.
	 *
	 * @return array Flat-rate instance IDs and escaped-at-display labels.
	 */
	private function shipping_methods(): array {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return array();
		}
		$result  = array();
		$zones   = \WC_Shipping_Zones::get_zones();
		$zones[] = array(
			'zone_id'   => 0,
			'zone_name' => __( 'سایر مناطق', 'exin-black-friday' ),
		);
		foreach ( $zones as $zone ) {
			$object = new \WC_Shipping_Zone( (int) $zone['zone_id'] );
			foreach ( $object->get_shipping_methods( true ) as $method ) {
				if ( 'flat_rate' === $method->id ) {
					$result[] = array(
						'id'    => (int) $method->instance_id,
						'label' => $zone['zone_name'] . ' — ' . $method->get_title(),
					);
				}
			}
		}
		return $result;
	}

	/**
	 * Loads a campaign or rejects the requested resource ID.
	 *
	 * @param int $id Campaign ID.
	 * @return array Existing campaign data.
	 * @throws InvalidArgumentException When no campaign matches the ID.
	 */
	private function required_campaign( int $id ): array {
		$campaign = $this->repository->get( $id );
		if ( ! $campaign ) {
			throw new InvalidArgumentException( esc_html__( 'کمپین پیدا نشد.', 'exin-black-friday' ) );
		}
		return $campaign;
	}

	/**
	 * Require draft status, except floor-only requests validated under item locks.
	 *
	 * @param int  $id Campaign ID.
	 * @param bool $floor_only Permit scheduled/active floor requests under item locks.
	 * @return array Editable campaign.
	 * @throws InvalidArgumentException When the campaign has left draft status.
	 */
	private function editable_campaign( int $id, bool $floor_only = false ): array {
		$campaign = $this->required_campaign( $id );
		if ( ! empty( $campaign['settings']['restoring'] ) || ( 'draft' !== $campaign['status'] && ! ( $floor_only && in_array( $campaign['status'], array( 'scheduled', 'active' ), true ) ) ) ) {
			throw new InvalidArgumentException( esc_html__( 'ویرایش ساختار فقط در پیش‌نویس و ویرایش کف فقط در پیش‌نویس، زمان‌بندی‌شده یا فعال مجاز است.', 'exin-black-friday' ) );
		}
		return $campaign;
	}


	/**
	 * Converts known domain failures to store-manager messages without internal details.
	 *
	 * @param string $code Static domain code or locally authored validation message.
	 * @return string Localized administrative message.
	 */
	private function domain_message( string $code ): string {
		$messages = array(
			'recovery_preview_changed' => __( 'قیمت، کف، مرحله یا پیش‌نمایش تغییر کرده یا منقضی شده است؛ پیش‌نمایش تازه بگیرید. چیزی بازنویسی نشد.', 'exin-black-friday' ),
			'recovery_campaign_not_live' => __( 'بازیابی فقط در کمپین فعال و منقضی‌نشده مجاز است.', 'exin-black-friday' ),
			'recovery_unsupported_conflict' => __( 'فقط تعارض تغییر قیمت بیرونی، بدون مالکیت یا عملیات ناتمام قابل پذیرش است.', 'exin-black-friday' ),
			'recovery_unsafe_product' => __( 'وضعیت، موجودی، قیمت‌گذاری پویا یا مالکیت محصول برای بازیابی امن نیست.', 'exin-black-friday' ),
			'recovery_invalid_base_floor' => __( 'مبنای ووکامرس و کف باید مثبت و معتبر باشند؛ کف نمی‌تواند بیشتر از مبنای جدید باشد.', 'exin-black-friday' ),
			'recovery_cohort_not_ready' => __( 'گروه مرحلهٔ خودکار هنوز آماده نیست؛ اجرای زمان‌بند موجود را بررسی و سپس پیش‌نمایش را تکرار کنید.', 'exin-black-friday' ),
			'recovery_queue_failed' => __( 'ثبت صف بازیابی انجام نشد؛ اطلاعات قبلی حفظ شد.', 'exin-black-friday' ),
			'floor_price_changed'                         => __( 'کف توسط کاربر دیگری تغییر کرده است؛ جدول را تازه کنید و با Esc مقدار ثبت‌شده را برگردانید، سپس دوباره ویرایش کنید.', 'exin-black-friday' ),
			'floor_edit_conflict'                         => __( 'ویرایش کف به دلیل وضعیت محصول، مالکیت قیمت یا عملیات ناتمام ممکن نیست؛ مقدار قبلی حفظ شد.', 'exin-black-friday' ),
			'product_floor_required' => __( 'کف این محصول باید عددی بزرگ‌تر از صفر باشد؛ مقدار قبلی حفظ شد.', 'exin-black-friday' ),
			'floor_exceeds_base' => __( 'کف قیمت نباید از قیمت مبنا بیشتر باشد؛ مقدار قبلی حفظ شد.', 'exin-black-friday' ),			'invalid_automatic_base_floor_or_stage'       => __( 'روش خودکار برای هر محصول به قیمت اصلی معتبر و کف مثبت و کمتر یا مساوی قیمت اصلی نیاز دارد؛ ابتدا کف‌ها را تعیین کنید.', 'exin-black-friday' ),
			'automatic_flash_capacity_exceeded'           => __( 'فاصلهٔ Flash برای تعداد محصولات و بودجهٔ پردازش فعلی کوتاه است؛ فاصله را بیشتر کنید.', 'exin-black-friday' ),
			'automatic_cohort_capacity_exceeded'          => __( 'تعداد نوبت‌های ویژه بیش از بودجهٔ ایمن است؛ مراحل یا اندازهٔ گروه ویژه را کاهش دهید.', 'exin-black-friday' ),
			'selection_requires_editable_campaign'        => __( 'انتخاب گروهی فقط برای پیش‌نویس بدون مالکیت قیمت یا کمپین فعال و منقضی‌نشده مجاز است.', 'exin-black-friday' ),
			'invalid_money_input'                         => __( 'مبلغ معتبر با ارقام فارسی یا انگلیسی وارد کنید؛ گروه‌بندی هزارگان باید صحیح باشد.', 'exin-black-friday' ),
			'invalid_money_precision_or_length'           => __( 'طول مبلغ یا دقت ورودی معتبر نیست.', 'exin-black-friday' ),
			'money_precision_exceeded'                    => __( 'تعداد رقم اعشار مبلغ از دقت مجاز بیشتر است.', 'exin-black-friday' ),
			'money_input_overflow'                        => __( 'مبلغ از محدودهٔ امن محاسبات بیشتر است.', 'exin-black-friday' ),
			'unsupported_amount_unit'                     => __( 'واحد مبلغ قابل تبدیل نیست؛ مبلغ را در واحد پشتیبانی‌شدهٔ فروشگاه وارد کنید.', 'exin-black-friday' ),
			'campaign_not_draft'                          => __( 'تنظیمات مالی فقط در پیش‌نویس قابل تغییرند.', 'exin-black-friday' ),
			'draft_contains_owned_prices'                 => __( 'این پیش‌نویس مالکیت قیمت باز دارد؛ پیش از ویرایش، بازیابی یا تعارض را بررسی کنید.', 'exin-black-friday' ),
			'transactional_storage_required'              => __( 'ذخیرهٔ اتمیک تنظیمات به جدول‌های InnoDB نیاز دارد؛ وضعیت دیتابیس را بررسی کنید.', 'exin-black-friday' ),
			'Amount must be a plain decimal string.'      => __( 'مبلغ را با رقم و نقطهٔ اعشار وارد کنید؛ نماد علمی یا جداکنندهٔ هزارگان مجاز نیست.', 'exin-black-friday' ),
			'Amount exceeds currency precision.'          => __( 'تعداد رقم اعشار مبلغ از دقت مجاز ارز فروشگاه بیشتر است.', 'exin-black-friday' ),
			'Amount exceeds the supported integer range.' => __( 'مبلغ از محدودهٔ امن محاسبات بیشتر است؛ مقدار کوچک‌تری وارد کنید.', 'exin-black-friday' ),
			'Currency precision must be between zero and eight.' => __( 'دقت ارز فروشگاه باید بین صفر تا هشت رقم اعشار باشد.', 'exin-black-friday' ),
			'Base and floor must be positive and floor must not exceed base.' => __( 'قیمت پایه و کف باید مثبت باشند و کف نباید از قیمت پایه بیشتر باشد.', 'exin-black-friday' ),
			'Campaign days must be between one and 366.'  => __( 'مدت کمپین باید بین یک تا ۳۶۶ روز باشد.', 'exin-black-friday' ),
			'Unknown pricing strategy.'                   => __( 'الگوی قیمت‌گذاری معتبر نیست؛ خطی یا تصاعدی را انتخاب کنید.', 'exin-black-friday' ),
			'Progressive power must be an integer between one and eight.' => __( 'توان الگوی تصاعدی باید عدد صحیح یک تا هشت باشد.', 'exin-black-friday' ),
			'Unknown rounding policy.'                    => __( 'روش گردکردن معتبر نیست؛ یکی از روش‌های مجاز را انتخاب کنید.', 'exin-black-friday' ),
			'campaign_not_found'                          => __( 'کمپین پیدا نشد؛ صفحه را تازه کنید.', 'exin-black-friday' ),
			'campaign_not_editable'                       => __( 'کمپین در وضعیت قابل ویرایش نیست؛ تنظیمات مالی فقط در پیش‌نویس قابل تغییرند.', 'exin-black-friday' ),
			'campaign_cannot_schedule'                    => __( 'زمان‌بندی در وضعیت فعلی کمپین مجاز نیست.', 'exin-black-friday' ),
			'campaign_cannot_start'                       => __( 'شروع در وضعیت فعلی کمپین مجاز نیست.', 'exin-black-friday' ),
			'campaign_start_is_in_future'                 => __( 'زمان شروع کمپین هنوز نرسیده است؛ آن را زمان‌بندی کنید.', 'exin-black-friday' ),
			'campaign_already_ended'                      => __( 'بازهٔ زمانی کمپین پایان یافته است؛ نتیجهٔ بازیابی را بررسی کنید.', 'exin-black-friday' ),
			'campaign_cannot_pause'                       => __( 'فقط کمپین فعال یا زمان‌بندی‌شده را می‌توان متوقف کرد.', 'exin-black-friday' ),
			'campaign_cannot_resume'                      => __( 'ادامه در وضعیت فعلی کمپین مجاز نیست.', 'exin-black-friday' ),
			'invalid_campaign_transition'                 => __( 'تغییر وضعیت درخواست‌شده برای کمپین مجاز نیست.', 'exin-black-friday' ),
			'item_not_found'                              => __( 'محصول عضو این کمپین نیست یا حذف شده است.', 'exin-black-friday' ),
			'item_not_in_conflict'                        => __( 'محصول تعارض ثبت‌شده‌ای برای حل‌کردن ندارد؛ وضعیت را تازه کنید.', 'exin-black-friday' ),
			'invalid_item_transition'                     => __( 'تغییر وضعیت درخواست‌شده برای محصول مجاز نیست.', 'exin-black-friday' ),
			'invalid_resolution'                          => __( 'روش انتخاب‌شده برای حل تعارض مجاز نیست.', 'exin-black-friday' ),
			'existing_or_scheduled_sale_conflict'         => __( 'محصول تخفیف فعال یا زمان‌بندی‌شده دارد؛ ابتدا تداخل را خارج از کمپین بررسی کنید.', 'exin-black-friday' ),
			'dynamic_price_conflict'                      => __( 'قیمت پویا شناسایی شد؛ کنترل قیمت این محصول با کمپین سازگار نیست.', 'exin-black-friday' ),
			'regular_price_conflict'                      => __( 'قیمت جاری با قیمت اصلی معتبر یکسان نیست؛ محصول وارد کمپین نشد.', 'exin-black-friday' ),
			'product_already_included'                    => __( 'این محصول قبلاً به کمپین اضافه شده است.', 'exin-black-friday' ),
			'product_owned_by_another_campaign'           => __( 'قیمت این محصول هنوز تحت کنترل کمپین دیگری است.', 'exin-black-friday' ),
			'store_currency_or_precision_changed'         => __( 'واحد پول یا دقت قیمت فروشگاه تغییر کرده است.', 'exin-black-friday' ),
			'unsupported_or_missing_product'              => __( 'محصول پیدا نشد یا نوع آن پشتیبانی نمی‌شود؛ یک محصول ساده یا تنوع انتخاب کنید.', 'exin-black-friday' ),
			'product_not_published'                       => __( 'محصول و والد تنوع باید منتشر شده باشند.', 'exin-black-friday' ),
			'financial_settings_frozen'                   => __( 'پس از شروع کمپین، تغییر شرایط مالی مجاز نیست.', 'exin-black-friday' ),
			'restore_conflict_requires_resolution'        => __( 'قیمت بیرونی تغییر کرده است؛ پیش از حذف، تعارض را بررسی کنید.', 'exin-black-friday' ),
			'no_owned_price_to_restore'                   => __( 'قیمت تحت کنترلی برای بازیابی وجود ندارد.', 'exin-black-friday' ),
			'restore_in_progress'                         => __( 'بازیابی در حال اجراست؛ نتیجه را در عیب‌یابی بررسی کنید.', 'exin-black-friday' ),
			'campaign_has_no_products'                    => __( 'ابتدا حداقل یک محصول به کمپین اضافه کنید.', 'exin-black-friday' ),
		);
		if ( isset( $messages[ $code ] ) ) {
			return $messages[ $code ];
		}
		// Validation messages authored at this boundary contain no runtime details.
		if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $code ) ) {
			return sanitize_text_field( $code );
		}
		return __( 'ورودی یا وضعیت عملیات معتبر نیست؛ کف قیمت، زمان‌بندی و تعارض محصول را بررسی کنید.', 'exin-black-friday' );
	}

	/**
	 * Requires a JSON object or array before extracting allowed request fields.
	 *
	 * @param WP_REST_Request $request Incoming JSON request.
	 * @return array Parsed JSON structure.
	 * @throws InvalidArgumentException When the JSON body is missing or malformed.
	 */
	private function body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			throw new InvalidArgumentException( esc_html__( 'درخواست JSON معتبر لازم است.', 'exin-black-friday' ) );
		}
		return $body;
	}

	/**
	 * Validate an edited floor before any enrollment state is saved.
	 *
	 * @param array $campaign Campaign.
	 * @param int $product Product ID.
	 * @param mixed $value Submitted amount.
	 * @param string $unit Editing currency unit.
	 * @return string Validated store amount.
	 * @throws InvalidArgumentException When a required positive floor is missing.
	 */
	private function validated_floor( array $campaign, int $product, mixed $value, string $unit ): string {
		$floor = $this->decimal( $value, $unit );
		if ( ! \Exin\BlackFriday\Application\FloorGuard::positive( $floor, wc_get_price_decimals() ) ) {
			throw new InvalidArgumentException( 'product_floor_required' );
		}
		return $floor;
	}

	/**
	 * Normalizes a plain decimal using the store precision and checked integer units.
	 *
	 * @param mixed  $value Submitted monetary value.
	 * @param string $unit Explicit store or toman editing unit.
	 * @return string Canonical decimal amount.
	 */
	private function decimal( $value, string $unit = 'store' ): string {
		return CurrencyDisplay::to_store( $this->text( $value ), $unit, get_woocommerce_currency(), wc_get_price_decimals() );
	}

	/**
	 * Validate the explicitly declared editing unit without inferring foreign exchange.
	 *
	 * @param array $data Financial request body.
	 * @return string Store or supported toman editing unit.
	 */
	private function amount_unit( array $data ): string {
		return $this->choice( $data['amount_unit'] ?? 'store', array( 'store', 'toman' ) );
	}

	/**
	 * Accepts only textual or integer scalar input without implicit array coercion.
	 *
	 * @param mixed $value Submitted scalar.
	 * @return string Scalar string representation.
	 * @throws InvalidArgumentException When the value is not a string or integer.
	 */
	private function text( $value ): string {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			throw new InvalidArgumentException( esc_html__( 'مقدار ورودی معتبر نیست.', 'exin-black-friday' ) );
		}
		return (string) $value;
	}

	/**
	 * Validates a decimal integer and its inclusive bounds without overflow coercion.
	 *
	 * @param mixed $value   Submitted integer value.
	 * @param int   $minimum Minimum permitted value.
	 * @param int   $maximum Maximum permitted value.
	 * @return int Checked integer.
	 * @throws InvalidArgumentException When the value is malformed or outside bounds.
	 */
	private function integer( $value, int $minimum, int $maximum ): int {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/^\d+$/D', $value ) ) ) {
			throw new InvalidArgumentException( esc_html__( 'عدد صحیح معتبر لازم است.', 'exin-black-friday' ) );
		}
		$filtered = filter_var( $value, FILTER_VALIDATE_INT );
		if ( false === $filtered || $filtered < $minimum || $filtered > $maximum ) {
			throw new InvalidArgumentException( esc_html__( 'عدد خارج از بازه مجاز است.', 'exin-black-friday' ) );
		}
		return $filtered;
	}

	/**
	 * Requires an actual JSON boolean rather than a truthy string.
	 *
	 * @param mixed $value Submitted option value.
	 * @return bool Validated boolean.
	 * @throws InvalidArgumentException When the value is not boolean.
	 */
	private function boolean( $value ): bool {
		if ( ! is_bool( $value ) ) {
			throw new InvalidArgumentException( esc_html__( 'گزینه باید روشن یا خاموش باشد.', 'exin-black-friday' ) );
		}
		return $value;
	}

	/**
	 * Restricts a scalar choice to an explicit allowlist.
	 *
	 * @param mixed $value   Submitted option.
	 * @param array $allowed Permitted exact string values.
	 * @return string Allowed value.
	 * @throws InvalidArgumentException When the submitted value is not listed.
	 */
	private function choice( $value, array $allowed ): string {
		$value = $this->text( $value );
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( esc_html__( 'گزینه انتخابی معتبر نیست.', 'exin-black-friday' ) );
		}
		return $value;
	}
}
